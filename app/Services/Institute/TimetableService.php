<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\DataObjects\Institute\SlotCandidate;
use App\Enums\ClassroomType;
use App\Enums\ClassSessionStatus;
use App\Enums\DeliveryMode;
use App\Enums\Weekday;
use App\Models\Institute\Batch;
use App\Models\Institute\Classroom;
use App\Models\Institute\Teacher;
use App\Models\Institute\TimetableEntry;
use App\Models\User;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Services\Institute\Exceptions\ScheduleClashException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The weekly pattern (§71, phase-14-17 §6.7).
 *
 * **Every write goes: transaction → lock the parents → check the clash → insert.** In that order, and
 * never any other. Overlap is a range condition MariaDB cannot hold as a constraint, so the lock is
 * what makes two coordinators booking the same teacher queue instead of both reading "free"; the
 * three unique indexes underneath only catch the same form submitted twice.
 *
 * **Editing a rule regenerates the future and never touches the past.** Classes already `held` keep
 * the hour they were actually taught at — rewriting them to match a rule that changed afterwards
 * would make the register disagree with what happened in the room.
 *
 * **`seedFromBatch()` is all or nothing.** A batch's four weekly slots either all get created or none
 * do: a half-seeded timetable is worse than an empty one, because it looks finished.
 */
final class TimetableService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ScheduleClashDetector $detector,
        private readonly ClassSessionService $sessions,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Batch $batch, array $data, ?User $actor = null): TimetableEntry
    {
        return $this->db->transaction(function () use ($batch, $data, $actor): TimetableEntry {
            $entry = $this->fill(new TimetableEntry, $batch, $data);

            $this->assertSlotIsSane($entry);
            $this->assertTeacherCanTeach($entry);
            $this->assertRoomSuitsTheMode($entry);

            $candidate = SlotCandidate::forTimetableEntry($entry);

            $this->detector->lockParents($candidate);
            $this->assertClean($candidate, $data, $actor);

            $entry->forceFill([
                'branch_id' => $batch->branch_id,
                'course_id' => $batch->course_id,
                'is_active' => true,
                'created_by' => $actor?->getKey(),
                'updated_by' => $actor?->getKey(),
            ])->save();

            // The rule exists; now the dated classes it produces, out to the horizon.
            $this->sessions->generate($batch, Carbon::today(), $this->horizon(), $actor);

            return $entry->refresh();
        }, 3);
    }

    /**
     * §70's `days` + `start_time` + `end_time`, expanded into one rule per weekday.
     */
    public function seedFromBatch(Batch $batch, ?User $actor = null): Collection
    {
        $days = $batch->weekdays();

        if ($days === []) {
            throw CourseRuleException::refuse('days',
                'This batch does not say which days it meets, so there is nothing to expand. Set the '
                .'days on the batch, or add the slots one at a time.');
        }

        if ($batch->start_time === null || $batch->end_time === null) {
            throw CourseRuleException::refuse('start_time',
                'This batch does not say what time it meets. Set the class times on the batch first.');
        }

        // One transaction for the lot: a half-seeded timetable looks finished and is not.
        return $this->db->transaction(function () use ($batch, $days, $actor): Collection {
            $created = collect();

            foreach ($days as $day) {
                $created->push($this->create($batch, [
                    'day_of_week' => $day->value,
                    'start_time' => $batch->start_time,
                    'end_time' => $batch->end_time,
                    'teacher_id' => $batch->teacher_id,
                    'classroom_id' => $batch->classroom_id,
                    'delivery_mode' => $batch->delivery_mode->value,
                    'meeting_url' => $batch->meeting_url,
                    'effective_from' => $batch->start_date->toDateString(),
                    'effective_to' => $batch->end_date?->toDateString(),
                ], $actor));
            }

            return $created;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(TimetableEntry $entry, array $data, ?User $actor = null): TimetableEntry
    {
        return $this->db->transaction(function () use ($entry, $data, $actor): TimetableEntry {
            $batch = $entry->batch;

            $this->fill($entry, $batch, $data);

            $this->assertSlotIsSane($entry);
            $this->assertTeacherCanTeach($entry);
            $this->assertRoomSuitsTheMode($entry);

            // Ignoring itself: without this, saving a slot unchanged would report it as its own clash.
            $candidate = SlotCandidate::forTimetableEntry($entry)
                ->ignoring(SlotCandidate::TYPE_TIMETABLE_ENTRY, $entry->getKey());

            $this->detector->lockParents($candidate);
            $this->assertClean($candidate, $data, $actor);

            $entry->updated_by = $actor?->getKey();
            $entry->save();

            // Future `scheduled` classes are rebuilt from the new rule; `held` ones are left exactly
            // as they were taught.
            $this->sessions->regenerateFor($entry, $actor);

            return $entry->refresh();
        }, 3);
    }

    /**
     * End a rule: it stops applying, and the classes it had not yet produced are called off.
     */
    public function end(TimetableEntry $entry, Carbon $on, string $reason, ?User $actor = null): TimetableEntry
    {
        if (trim($reason) === '') {
            throw CourseRuleException::reasonRequired('reason',
                'Ending a timetable slot cancels the classes it was going to produce, and the roster '
                .'is told. Say what to tell them.');
        }

        // A window that runs backwards is refused by a CHECK, which reports a constraint name rather
        // than a sentence. Say it here, where the caller can act on it.
        if ($on->lt($entry->effective_from)) {
            throw CourseRuleException::refuse('effective_to', sprintf(
                'This slot does not start until %s, so it cannot end on %s. Remove it instead — a '
                .'rule that never applied has nothing to end.',
                $entry->effective_from->format('d M Y'),
                $on->format('d M Y'),
            ));
        }

        return $this->db->transaction(function () use ($entry, $on, $reason, $actor): TimetableEntry {
            $entry->forceFill([
                'effective_to' => $on->toDateString(),
                'is_active' => false,
                'updated_by' => $actor?->getKey(),
            ])->save();

            DB::table('class_sessions')
                ->whereNull('deleted_at')
                ->where('timetable_entry_id', $entry->getKey())
                ->where('status', ClassSessionStatus::Scheduled->value)
                ->whereDate('session_date', '>', $on->toDateString())
                ->update([
                    'status' => ClassSessionStatus::Cancelled->value,
                    'cancellation_reason' => 'other',
                    'cancellation_detail' => mb_substr('Timetable slot ended: '.$reason, 0, 255),
                    'updated_by' => $actor?->getKey(),
                    'updated_at' => Carbon::now(),
                ]);

            return $entry->refresh();
        }, 3);
    }

    public function delete(TimetableEntry $entry, ?User $actor = null): void
    {
        $this->db->transaction(function () use ($entry, $actor): void {
            // The classes it produced survive it (`nullOnDelete`), so they are called off first —
            // otherwise a class with no rule behind it sits on the calendar with nobody owning it.
            DB::table('class_sessions')
                ->whereNull('deleted_at')
                ->where('timetable_entry_id', $entry->getKey())
                ->where('status', ClassSessionStatus::Scheduled->value)
                ->whereDate('session_date', '>=', Carbon::today()->toDateString())
                ->update([
                    'status' => ClassSessionStatus::Cancelled->value,
                    'cancellation_reason' => 'other',
                    'cancellation_detail' => 'The timetable slot this class came from was removed.',
                    'updated_by' => $actor?->getKey(),
                    'updated_at' => Carbon::now(),
                ]);

            $entry->delete();
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | The five views of §71
    |--------------------------------------------------------------------------
    */

    /**
     * One query behind all five views: daily, weekly, by teacher, by batch, by room.
     *
     * The axis decides what the rows are grouped under; the filters decide what is in them. Five
     * separate queries would drift apart the first time somebody added a filter to one of them.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function views(string $axis, array $filters = []): array
    {
        $entries = TimetableEntry::query()
            ->with(['batch:id,code,name,course_id', 'teacher:id,name', 'classroom:id,code,name'])
            ->active()
            ->when(isset($filters['batch_id']), fn ($q) => $q->where('batch_id', $filters['batch_id']))
            ->when(isset($filters['teacher_id']), fn ($q) => $q->where('teacher_id', $filters['teacher_id']))
            ->when(isset($filters['classroom_id']), fn ($q) => $q->where('classroom_id', $filters['classroom_id']))
            ->when(isset($filters['course_id']), fn ($q) => $q->where('course_id', $filters['course_id']))
            ->when(isset($filters['branch_id']), fn ($q) => $q->forBranch((int) $filters['branch_id']))
            ->when(isset($filters['day']), fn ($q) => $q->where('day_of_week', $filters['day']))
            ->orderBy('start_time')
            ->get();

        // Only the days the institute actually teaches on get a column.
        $working = (array) setting('institute.timetable_working_days', []);
        $days = array_values(array_filter(
            Weekday::ordered(),
            static fn (Weekday $d): bool => $working === [] || in_array($d->value, $working, true),
        ));

        $grouped = match ($axis) {
            'teacher' => $entries->groupBy(fn (TimetableEntry $e): string => $e->teacher?->name ?? 'Unassigned'),
            'batch' => $entries->groupBy(fn (TimetableEntry $e): string => $e->batch?->code ?? '—'),
            'classroom' => $entries->groupBy(fn (TimetableEntry $e): string => $e->classroom?->code ?? 'No room'),
            default => $entries->groupBy(fn (TimetableEntry $e): string => $e->day_of_week->value),
        };

        return [
            'axis' => $axis,
            'days' => $days,
            'entries' => $entries,
            'grouped' => $grouped,
            'day_start' => (string) setting('institute.timetable_day_start', '08:00'),
            'day_end' => (string) setting('institute.timetable_day_end', '22:00'),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $data
     */
    private function fill(TimetableEntry $entry, Batch $batch, array $data): TimetableEntry
    {
        $entry->fill(array_intersect_key($data, array_flip([
            'day_of_week', 'start_time', 'end_time', 'classroom_id', 'delivery_mode',
            'meeting_url', 'notes', 'effective_from', 'effective_to',
        ])));

        $entry->batch_id = $batch->getKey();
        $entry->course_id = $batch->course_id;
        $entry->branch_id = $batch->branch_id;

        // Null means "whoever teaches the batch", resolved now so the clash check has somebody to
        // check and the generated classes name the right person.
        $entry->teacher_id = $data['teacher_id'] ?? $entry->teacher_id ?? $batch->teacher_id;

        if ($entry->effective_from === null) {
            $entry->effective_from = $batch->start_date;
        }

        if ($entry->delivery_mode === null) {
            $entry->delivery_mode = $batch->delivery_mode ?? DeliveryMode::Physical;
        }

        return $entry;
    }

    /**
     * The slot has to end after it starts, fall inside the teaching day, and be on a day the
     * institute opens — three things a CHECK cannot know about because two of them are settings.
     */
    private function assertSlotIsSane(TimetableEntry $entry): void
    {
        $start = Carbon::parse($entry->start_time);
        $end = Carbon::parse($entry->end_time);

        if ($end->lessThanOrEqualTo($start)) {
            throw CourseRuleException::refuse('end_time', 'A class has to end after it starts.');
        }

        $dayStart = Carbon::parse((string) setting('institute.timetable_day_start', '08:00'));
        $dayEnd = Carbon::parse((string) setting('institute.timetable_day_end', '22:00'));

        if ($start->format('H:i:s') < $dayStart->format('H:i:s')
            || $end->format('H:i:s') > $dayEnd->format('H:i:s')) {
            throw CourseRuleException::refuse('start_time', sprintf(
                'The institute teaches between %s and %s. Move the slot, or change the teaching day '
                .'in Settings.',
                $dayStart->format('H:i'),
                $dayEnd->format('H:i'),
            ));
        }

        $working = (array) setting('institute.timetable_working_days', []);

        if ($working !== [] && ! in_array($entry->day_of_week->value, $working, true)) {
            throw CourseRuleException::refuse('day_of_week', sprintf(
                'The institute does not teach on %s.',
                $entry->day_of_week->label(),
            ));
        }
    }

    /**
     * A virtual room is a meeting link, not a place, so only an online class can be "in" one — and a
     * physical or hybrid class needs somewhere it can physically be.
     *
     * This is what keeps `uq_tte_room` and `ScheduleClashDetector` exempting the same rows: both now
     * turn on the delivery mode alone, because a virtual room implies one.
     */
    private function assertRoomSuitsTheMode(TimetableEntry $entry): void
    {
        if ($entry->classroom_id === null) {
            return;
        }

        $room = Classroom::query()->find($entry->classroom_id);

        if (! $room instanceof Classroom) {
            return;
        }

        if ($room->type === ClassroomType::Virtual && $entry->delivery_mode !== DeliveryMode::Online) {
            throw CourseRuleException::refuse('classroom_id', sprintf(
                '%s is a virtual room — a meeting link, not a place. A %s class needs somewhere it '
                .'can physically be.',
                $room->label(),
                mb_strtolower($entry->delivery_mode->label()),
            ));
        }

        if ($room->type !== ClassroomType::Virtual && $entry->delivery_mode === DeliveryMode::Online) {
            throw CourseRuleException::refuse('classroom_id', sprintf(
                'An online class does not occupy %s. Leave the room empty, or use a virtual room.',
                $room->label(),
            ));
        }
    }

    private function assertTeacherCanTeach(TimetableEntry $entry): void
    {
        if ($entry->teacher_id === null) {
            return;
        }

        $teacher = Teacher::query()->find($entry->teacher_id);

        if ($teacher === null || $teacher->canTeach()) {
            return;
        }

        throw CourseRuleException::refuse('teacher_id', sprintf(
            '%s is %s, so they cannot be put on a timetable.',
            $teacher->name,
            $teacher->status->label(),
        ));
    }

    /**
     * [D-IN-14]: a batch clash is always an error; a teacher or room clash can be accepted by
     * somebody holding `timetable.change_status` who gives a reason, which goes on the record.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertClean(SlotCandidate $candidate, array $data, ?User $actor): void
    {
        $report = $this->detector->check($candidate);

        if ($report->clean) {
            return;
        }

        if ($report->hasBlocking()) {
            throw ScheduleClashException::from($report, 'start_time');
        }

        $reason = trim((string) ($data['clash_override_reason'] ?? ''));
        $mayOverride = $actor === null || $actor->can('timetable.change_status');

        if ($reason === '' || ! $mayOverride) {
            throw ScheduleClashException::needsOverride($report, 'start_time');
        }

        activity('timetable')
            ->causedBy($actor)
            ->withProperties([
                'reason' => $reason,
                'conflicts' => $report->toArray()['conflicts'],
            ])
            ->log('timetable.clash_overridden');
    }

    private function horizon(): Carbon
    {
        return Carbon::today()->addWeeks(max(1, (int) setting('institute.session_generation_weeks_ahead', 8)));
    }
}
