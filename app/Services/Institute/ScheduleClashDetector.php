<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\DataObjects\Institute\ClashReport;
use App\DataObjects\Institute\SlotCandidate;
use App\DataObjects\Institute\SlotConflict;
use App\Enums\ClassroomType;
use App\Enums\ClassSessionStatus;
use App\Enums\DemoClassStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single clash authority (phase-14-17 §6.7, D47, F-4.7).
 *
 * **Nothing else implements an overlap test.** Timetable entries, one-off classes, reschedules,
 * substitutions and demo bookings all arrive here; Phase 19-23's exams and room-bearing meetings join
 * by calling `register()` with one declaration each, and the predicates, the locks and the report do
 * not change. That is what makes "a room is never double-booked" true for tables this phase has never
 * heard of, instead of true four times over in four slightly different ways.
 *
 * **Overlap is a range condition, and MariaDB cannot express one as a unique index.** So the guard is
 * a lock, not a constraint: the calling service opens a transaction, locks the parent rows through
 * `lockParents()` in a fixed order, then checks, then writes. Two coordinators booking the same
 * teacher therefore queue on the teacher row rather than both reading "free". The three exact-
 * duplicate unique indexes per table are the cheap backstop for an identical form submitted twice,
 * and `timetable:verify-clashes` reports anything a seeder or raw SQL ever slipped past both.
 *
 * **Time is half-open**: 09:00-10:00 and 10:00-11:00 do not clash. `institute.timetable_slot_gap_minutes`
 * widens the candidate's window for the teacher and room dimensions only — a person needs to walk
 * between rooms, a batch does not.
 *
 * **[D-IN-14] A batch clash is always an error**; a teacher or room clash can be accepted by somebody
 * holding `timetable.change_status` who gives a reason. Nothing is ever silently allowed.
 */
class ScheduleClashDetector
{
    /**
     * The tables that can hold a slot, and where each one keeps the six things a check needs.
     *
     * A later phase adds its own with `register()`; it never edits this list.
     *
     * @var array<string, array<string, mixed>>
     */
    private const OCCUPANTS = [
        SlotCandidate::TYPE_TIMETABLE_ENTRY => [
            'table' => 'timetable_entries',
            'recurring' => true,
            'teacher' => 'teacher_id',
            'classroom' => 'classroom_id',
            'batch' => 'batch_id',
            'day' => 'day_of_week',
            'from' => 'effective_from',
            'to' => 'effective_to',
            'date' => null,
            'start' => 'start_time',
            'end' => 'end_time',
            'live' => [['is_active', '=', 1]],
            'soft_deletes' => true,
        ],
        SlotCandidate::TYPE_CLASS_SESSION => [
            'table' => 'class_sessions',
            'recurring' => false,
            'teacher' => 'teacher_id',
            'classroom' => 'classroom_id',
            'batch' => 'batch_id',
            'day' => null,
            'from' => null,
            'to' => null,
            'date' => 'session_date',
            'start' => 'start_time',
            'end' => 'end_time',
            'live' => [['status', 'in', [ClassSessionStatus::Scheduled->value, ClassSessionStatus::Held->value]]],
            'soft_deletes' => true,
            // Which column says "this class came from that weekly rule".
            'generated_by' => 'timetable_entry_id',
        ],
        SlotCandidate::TYPE_DEMO_CLASS => [
            'table' => 'demo_classes',
            'recurring' => false,
            'teacher' => 'teacher_id',
            'classroom' => 'classroom_id',
            // A demo names the batch it is sitting in on (§2.16), which is the opposite of occupying
            // that batch's hour — so it is not an occupant of the batch dimension.
            'batch' => null,
            'day' => null,
            'from' => null,
            'to' => null,
            'date' => 'scheduled_on',
            'start' => 'start_time',
            'end' => 'end_time',
            'live' => [['status', '=', DemoClassStatus::Scheduled->value]],
            'soft_deletes' => true,
        ],
    ];

    /**
     * Declarations added at runtime by later phases (exams, meetings).
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $registered = [];

    /**
     * @param  array<string, mixed>  $declaration
     */
    public static function register(string $type, array $declaration): void
    {
        self::$registered[$type] = $declaration + [
            'recurring' => false,
            'teacher' => null,
            'classroom' => null,
            'batch' => null,
            'day' => null,
            'from' => null,
            'to' => null,
            'date' => null,
            'start' => 'start_time',
            'end' => 'end_time',
            'live' => [],
            'soft_deletes' => true,
            'generated_by' => null,
        ];
    }

    /** Only for a test that registered one — production never unregisters a table. */
    public static function forget(string $type): void
    {
        unset(self::$registered[$type]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function occupants(): array
    {
        return self::OCCUPANTS + self::$registered;
    }

    /*
    |--------------------------------------------------------------------------
    | The published entry point — the only one any phase calls
    |--------------------------------------------------------------------------
    */

    public function check(SlotCandidate $candidate): ClashReport
    {
        if ($candidate->startsAt === null || $candidate->endsAt === null) {
            return ClashReport::clean();
        }

        $gap = max(0, (int) setting('institute.timetable_slot_gap_minutes', 0));

        $conflicts = [];

        // 1 — the teacher. Skipped only when the candidate names nobody.
        if ($candidate->teacherId !== null) {
            $conflicts = array_merge($conflicts, $this->scan(
                $candidate,
                SlotConflict::TEACHER,
                'teacher',
                $candidate->teacherId,
                $gap,
            ));
        }

        // 2 — the room. A virtual room holds infinite classes, and an online class occupies none.
        if ($candidate->occupiesAClassroom() && $this->roomIsBookable($candidate->classroomId)) {
            $conflicts = array_merge($conflicts, $this->scan(
                $candidate,
                SlotConflict::CLASSROOM,
                'classroom',
                $candidate->classroomId,
                $gap,
            ));
        }

        // 3 — the batch. Never skipped: students cannot be in two places, so this one is always an
        // error. No gap is applied — a batch does not have to walk anywhere.
        if ($candidate->batchId !== null) {
            $conflicts = array_merge($conflicts, $this->scan(
                $candidate,
                SlotConflict::BATCH,
                'batch',
                $candidate->batchId,
                0,
            ));
        }

        return ClashReport::of($this->dropTheBatchBeingJoined($candidate, $conflicts));
    }

    /**
     * A sit-in demo is not competing with the class it is sitting in on (§2.16).
     *
     * @param  list<SlotConflict>  $conflicts
     * @return list<SlotConflict>
     */
    private function dropTheBatchBeingJoined(SlotCandidate $candidate, array $conflicts): array
    {
        if ($candidate->joiningBatchId === null) {
            return $conflicts;
        }

        return array_values(array_filter(
            $conflicts,
            static fn (SlotConflict $c): bool => $c->batchId !== $candidate->joiningBatchId,
        ));
    }

    /**
     * Lock the parent rows this candidate touches, in `batches` → `classrooms` → `teachers` order.
     *
     * One fixed order for every caller is the whole point: two transactions that lock the same two
     * rows in opposite orders deadlock, and a deadlock at midnight in the session generator is a
     * timetable that silently stopped being generated.
     */
    public function lockParents(SlotCandidate $candidate): void
    {
        if (! DB::transactionLevel()) {
            return;
        }

        if ($candidate->batchId !== null) {
            DB::table('batches')->where('id', $candidate->batchId)->lockForUpdate()->first();
        }

        if ($candidate->classroomId !== null) {
            DB::table('classrooms')->where('id', $candidate->classroomId)->lockForUpdate()->first();
        }

        if ($candidate->teacherId !== null) {
            DB::table('teachers')->where('id', $candidate->teacherId)->lockForUpdate()->first();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | One dimension, across every occupant table
    |--------------------------------------------------------------------------
    */

    /**
     * @return list<SlotConflict>
     */
    private function scan(SlotCandidate $candidate, string $dimension, string $key, int $value, int $gap): array
    {
        $conflicts = [];

        foreach (self::occupants() as $type => $spec) {
            $column = $spec[$key] ?? null;

            if ($column === null) {
                continue;
            }

            $query = DB::table($spec['table'])->where($column, $value);

            if ($spec['soft_deletes'] ?? true) {
                $query->whereNull($spec['table'].'.deleted_at');
            }

            foreach ((array) ($spec['live'] ?? []) as [$liveColumn, $operator, $liveValue]) {
                $operator === 'in'
                    ? $query->whereIn($liveColumn, (array) $liveValue)
                    : $query->where($liveColumn, $operator, $liveValue);
            }

            $ignored = $candidate->ignoredIdsOf($type);

            if ($ignored !== []) {
                $query->whereNotIn($spec['table'].'.id', $ignored);
            }

            // Every class one weekly rule produced, in one condition — there are as many of them as
            // the generation horizon is long, so they cannot be listed by id.
            if ($candidate->ignoreGeneratedBy !== null && ($spec['generated_by'] ?? null) !== null) {
                $query->where(function (Builder $q) use ($spec, $candidate): void {
                    $q->whereNull($spec['generated_by'])
                        ->orWhere($spec['generated_by'], '!=', $candidate->ignoreGeneratedBy);
                });
            }

            $this->applyTimeOverlap($query, $spec, $candidate, $gap);
            $this->applyCalendarOverlap($query, $spec, $candidate);

            foreach ($query->get() as $row) {
                $conflicts[] = $this->describe($dimension, $type, $spec, $row);
            }
        }

        return $conflicts;
    }

    /**
     * Half-open, and widened by the configured gap for the two dimensions a gap means something for.
     *
     * `start < candidateEnd AND candidateStart < end` — so back-to-back classes do not clash, which
     * is the whole reason the comparison is strict on both sides.
     *
     * @param  array<string, mixed>  $spec
     */
    private function applyTimeOverlap(Builder $query, array $spec, SlotCandidate $candidate, int $gap): void
    {
        $start = Carbon::parse($candidate->startsAt);
        $end = Carbon::parse($candidate->endsAt);

        if ($gap > 0) {
            $start = $start->copy()->subMinutes($gap);
            $end = $end->copy()->addMinutes($gap);
        }

        // A widened window that fell off either end of the day stays inside it: the comparison is on
        // a TIME column, and 23:50 + 30 minutes is not 00:20 of the same row's day.
        $startTime = $start->isSameDay(Carbon::parse($candidate->startsAt)) ? $start->format('H:i:s') : '00:00:00';
        $endTime = $end->isSameDay(Carbon::parse($candidate->endsAt)) ? $end->format('H:i:s') : '23:59:59';

        $query->where($spec['start'], '<', $endTime)
            ->where($spec['end'], '>', $startTime);
    }

    /**
     * The day-or-date half, in the four combinations §6.7 tabulates.
     *
     * @param  array<string, mixed>  $spec
     */
    private function applyCalendarOverlap(Builder $query, array $spec, SlotCandidate $candidate): void
    {
        $occupantRecurs = (bool) ($spec['recurring'] ?? false);

        if ($candidate->isRecurring()) {
            $from = $candidate->effectiveFromDate();
            $to = $candidate->effectiveToDate();

            if ($occupantRecurs) {
                // Recurring vs recurring: same weekday, and the two windows overlap.
                $query->where($spec['day'], $candidate->dayOfWeek->value)
                    ->whereDate($spec['from'], '<=', $to)
                    ->where(function (Builder $q) use ($spec, $from): void {
                        $q->whereNull($spec['to'])->orWhereDate($spec['to'], '>=', $from);
                    });

                return;
            }

            // Recurring vs dated: the dated row falls inside the window AND lands on that weekday.
            $query->whereDate($spec['date'], '>=', $from)
                ->whereDate($spec['date'], '<=', $to)
                ->whereRaw('LOWER(DAYNAME('.$spec['date'].')) = ?', [$candidate->dayOfWeek->value]);

            return;
        }

        $date = $candidate->date();

        if ($occupantRecurs) {
            // Dated vs recurring: the rule is in force that day and it is that weekday.
            $query->where($spec['day'], strtolower(Carbon::parse($date)->format('l')))
                ->whereDate($spec['from'], '<=', $date)
                ->where(function (Builder $q) use ($spec, $date): void {
                    $q->whereNull($spec['to'])->orWhereDate($spec['to'], '>=', $date);
                });

            return;
        }

        // Dated vs dated: the same day.
        $query->whereDate($spec['date'], '=', $date);
    }

    /**
     * A virtual room is not a place, so two classes can be "in" it at once.
     */
    private function roomIsBookable(?int $classroomId): bool
    {
        if ($classroomId === null) {
            return false;
        }

        $type = DB::table('classrooms')->where('id', $classroomId)->value('type');

        if ($type === null) {
            return true;
        }

        return (ClassroomType::tryFrom((string) $type) ?? ClassroomType::Classroom)->isBookable();
    }

    /**
     * Turn a row into the sentence a coordinator can act on.
     *
     * @param  array<string, mixed>  $spec
     */
    private function describe(string $dimension, string $type, array $spec, object $row): SlotConflict
    {
        $batchId = $spec['batch'] !== null ? ($row->{$spec['batch']} ?? null) : ($row->batch_id ?? null);

        $subject = $batchId !== null
            ? (string) (DB::table('batches')->where('id', $batchId)->value('code') ?? ('Batch #'.$batchId))
            : $this->fallbackSubject($type, $row);

        $when = $spec['recurring'] ?? false
            ? ucfirst((string) $row->{$spec['day']})
            : Carbon::parse($row->{$spec['date']})->format('d M Y');

        $window = $when.' '
            .Carbon::parse($row->{$spec['start']})->format('H:i').'–'
            .Carbon::parse($row->{$spec['end']})->format('H:i');

        return new SlotConflict(
            dimension: $dimension,
            type: $type,
            id: (int) $row->id,
            subject: $subject,
            window: $window,
            batchId: $batchId !== null ? (int) $batchId : null,
        );
    }

    private function fallbackSubject(string $type, object $row): string
    {
        if ($type === SlotCandidate::TYPE_DEMO_CLASS) {
            return 'Demo for '.((string) ($row->attendee_name ?? 'a visitor'));
        }

        return ucfirst(str_replace('_', ' ', $type)).' #'.$row->id;
    }
}
