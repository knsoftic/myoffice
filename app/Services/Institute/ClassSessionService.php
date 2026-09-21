<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\DataObjects\Institute\SlotCandidate;
use App\Enums\ClassCancellationReason;
use App\Enums\ClassSessionStatus;
use App\Enums\Weekday;
use App\Models\Institute\Batch;
use App\Models\Institute\ClassSession;
use App\Models\Institute\Teacher;
use App\Models\Institute\TimetableEntry;
use App\Models\User;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Services\Institute\Exceptions\ScheduleClashException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The dated classes a timetable produces (phase-14-17 §6.7).
 *
 * **Generation is idempotent, and the unique index is how.** `uq_cs_generated(timetable_entry_id,
 * session_date, active_guard)` means the nightly job and a manual run can overlap, or the job can be
 * retried, and the second insert is a 1062 this service swallows rather than a duplicate class on
 * somebody's timetable. The alternative — checking before inserting — has a window between the check
 * and the insert, which is exactly the window a retry lands in.
 *
 * **Cancelling frees the slot but keeps the class visible.** `active_guard` goes NULL, so a
 * replacement can be generated for the same rule and date, and the cancelled row stays on the
 * calendar — a class that disappears when it is called off is one the students are never told about.
 *
 * **A substitution stores who was supposed to teach it.** `original_teacher_id` is filled the first
 * time somebody is replaced and never overwritten afterwards, so "how many classes did this teacher
 * miss" stays answerable (§99).
 */
final class ClassSessionService
{
    /** §2.30.9, verbatim. */
    private const TRANSITIONS = [
        'scheduled' => ['held', 'cancelled', 'rescheduled'],
        'held' => ['cancelled'],
        'cancelled' => [],
        'rescheduled' => [],
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ScheduleClashDetector $detector,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Generation
    |--------------------------------------------------------------------------
    */

    /**
     * Materialise the dated classes every active rule produces between two dates.
     *
     * @return int how many were actually created
     */
    public function generate(?Batch $batch, Carbon $from, Carbon $to, ?User $actor = null): int
    {
        $entries = TimetableEntry::query()
            ->with('batch')
            ->active()
            ->when($batch !== null, static fn ($q) => $q->where('batch_id', $batch->getKey()))
            ->effectiveBetween($from, $to)
            ->get();

        $created = 0;

        foreach ($entries as $entry) {
            $created += $this->generateForEntry($entry, $from, $to, $actor);
        }

        return $created;
    }

    /**
     * Rebuild the future of one rule after it was edited: the classes still to come take the new
     * hour, the ones already held keep the hour they were actually taught at.
     */
    public function regenerateFor(TimetableEntry $entry, ?User $actor = null): int
    {
        $from = Carbon::today();
        $to = $this->horizon();

        DB::table('class_sessions')
            ->whereNull('deleted_at')
            ->where('timetable_entry_id', $entry->getKey())
            ->where('status', ClassSessionStatus::Scheduled->value)
            ->whereDate('session_date', '>=', $from->toDateString())
            ->delete();

        return $this->generateForEntry($entry->refresh(), $from, $to, $actor);
    }

    private function generateForEntry(TimetableEntry $entry, Carbon $from, Carbon $to, ?User $actor): int
    {
        $batch = $entry->batch;

        if (! $batch instanceof Batch || ! $batch->status->generatesSessions()) {
            return 0;
        }

        // Never before the batch starts, never after it ends, never past the rule's own window.
        $start = $from->copy()->max(Carbon::parse($batch->start_date->toDateString()))
            ->max(Carbon::parse($entry->effective_from->toDateString()));

        $end = $to->copy()->min(Carbon::parse($entry->effectiveToOrForever()->toDateString()));

        if ($batch->end_date !== null) {
            $end = $end->min(Carbon::parse($batch->end_date->toDateString()));
        }

        if ($start->gt($end)) {
            return 0;
        }

        $created = 0;
        $cursor = $start->copy();

        // Walk forward to the first matching weekday, then step a week at a time.
        while (Weekday::fromDate($cursor) !== $entry->day_of_week && $cursor->lte($end)) {
            $cursor->addDay();
        }

        while ($cursor->lte($end)) {
            if ($this->insertGenerated($entry, $batch, $cursor->copy(), $actor)) {
                $created++;
            }

            $cursor->addWeek();
        }

        return $created;
    }

    /**
     * One insert, with the unique index as the concurrency guard.
     */
    private function insertGenerated(TimetableEntry $entry, Batch $batch, Carbon $date, ?User $actor): bool
    {
        try {
            $session = new ClassSession;

            $session->forceFill([
                'branch_id' => $entry->branch_id,
                'timetable_entry_id' => $entry->getKey(),
                'batch_id' => $batch->getKey(),
                'course_id' => $batch->course_id,
                'teacher_id' => $entry->teacher_id ?? $batch->teacher_id,
                'classroom_id' => $entry->classroom_id,
                'session_date' => $date->toDateString(),
                'start_time' => $entry->start_time,
                'end_time' => $entry->end_time,
                'sequence_no' => $this->nextSequenceNumber($batch),
                'delivery_mode' => $entry->delivery_mode->value,
                'meeting_url' => $entry->meeting_url,
                'status' => ClassSessionStatus::Scheduled->value,
                'created_by' => $actor?->getKey(),
                'updated_by' => $actor?->getKey(),
            ])->save();

            return true;
        } catch (UniqueConstraintViolationException) {
            // Already generated — by the nightly job, by a manual run, or by a retry of this one.
            // That is the whole point of `uq_cs_generated`.
            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | One-offs, and the four moves of §2.30.9
    |--------------------------------------------------------------------------
    */

    /**
     * An extra class that belongs to no weekly rule — a make-up, a revision, a guest lecture.
     *
     * @param  array<string, mixed>  $data
     */
    public function createOneOff(Batch $batch, array $data, ?User $actor = null): ClassSession
    {
        return $this->db->transaction(function () use ($batch, $data, $actor): ClassSession {
            $session = new ClassSession;

            $session->fill(array_intersect_key($data, array_flip([
                'teacher_id', 'classroom_id', 'session_date', 'start_time', 'end_time',
                'delivery_mode', 'meeting_url', 'title', 'course_topic_id', 'course_lecture_id', 'notes',
            ])));

            $session->forceFill([
                'branch_id' => $batch->branch_id,
                'timetable_entry_id' => null,
                'batch_id' => $batch->getKey(),
                'course_id' => $batch->course_id,
                'teacher_id' => $data['teacher_id'] ?? $batch->teacher_id,
                'delivery_mode' => $data['delivery_mode'] ?? $batch->delivery_mode->value,
                'status' => ClassSessionStatus::Scheduled->value,
                'sequence_no' => $this->nextSequenceNumber($batch),
                'created_by' => $actor?->getKey(),
                'updated_by' => $actor?->getKey(),
            ]);

            $this->assertTeacherCanTeach($session->teacher_id);

            $candidate = new SlotCandidate(
                teacherId: $session->teacher_id,
                classroomId: $session->classroom_id,
                batchId: $session->batch_id,
                startsAt: $this->moment($session->session_date, $session->start_time),
                endsAt: $this->moment($session->session_date, $session->end_time),
                deliveryMode: $session->delivery_mode,
            );

            $this->detector->lockParents($candidate);
            $this->assertClean($candidate, $data, $actor);

            $session->save();

            return $session->refresh();
        }, 3);
    }

    public function cancel(
        ClassSession $session,
        ClassCancellationReason $reason,
        string $detail,
        ?User $actor = null,
    ): ClassSession {
        $this->assertTransition($session, ClassSessionStatus::Cancelled);

        if (trim($detail) === '') {
            throw CourseRuleException::reasonRequired('cancellation_detail',
                'Cancelling a class tells the whole roster. Say what to tell them — "cancelled" on its '
                .'own is what makes people turn up anyway.');
        }

        $this->assertNoAttendanceYet($session);

        return $this->db->transaction(function () use ($session, $reason, $detail, $actor): ClassSession {
            $session->forceFill([
                'status' => ClassSessionStatus::Cancelled->value,
                'cancellation_reason' => $reason->value,
                'cancellation_detail' => mb_substr($detail, 0, 255),
                'updated_by' => $actor?->getKey(),
            ])->save();

            return $session->refresh();
        }, 3);
    }

    /**
     * Move a class: the successor is a new row, and the two are linked both ways.
     *
     * @param  array<string, mixed>  $slot
     */
    public function reschedule(ClassSession $session, array $slot, string $reason, ?User $actor = null): ClassSession
    {
        $this->assertTransition($session, ClassSessionStatus::Rescheduled);

        if (trim($reason) === '') {
            throw CourseRuleException::reasonRequired('reason',
                'Moving a class takes a reason — the roster is told the new time and why it moved.');
        }

        return $this->db->transaction(function () use ($session, $slot, $reason, $actor): ClassSession {
            $successor = new ClassSession;

            $successor->forceFill([
                'branch_id' => $session->branch_id,
                // The successor stands alone: it is not what the rule produces for that date.
                'timetable_entry_id' => null,
                'batch_id' => $session->batch_id,
                'course_id' => $session->course_id,
                'teacher_id' => $slot['teacher_id'] ?? $session->teacher_id,
                'classroom_id' => $slot['classroom_id'] ?? $session->classroom_id,
                'session_date' => $slot['session_date'],
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time'],
                'sequence_no' => $session->sequence_no,
                'delivery_mode' => $slot['delivery_mode'] ?? $session->delivery_mode->value,
                'meeting_url' => $slot['meeting_url'] ?? $session->meeting_url,
                'title' => $session->title,
                'course_topic_id' => $session->course_topic_id,
                'course_lecture_id' => $session->course_lecture_id,
                'status' => ClassSessionStatus::Scheduled->value,
                'rescheduled_from_id' => $session->getKey(),
                'created_by' => $actor?->getKey(),
                'updated_by' => $actor?->getKey(),
            ]);

            $candidate = new SlotCandidate(
                teacherId: $successor->teacher_id,
                classroomId: $successor->classroom_id,
                batchId: $successor->batch_id,
                startsAt: $this->moment($successor->session_date, $successor->start_time),
                endsAt: $this->moment($successor->session_date, $successor->end_time),
                // The class being moved is not a conflict with itself.
                ignoreType: SlotCandidate::TYPE_CLASS_SESSION,
                ignoreId: $session->getKey(),
                deliveryMode: $successor->delivery_mode,
            );

            $this->detector->lockParents($candidate);
            $this->assertClean($candidate, $slot, $actor);

            $successor->save();

            $session->forceFill([
                'status' => ClassSessionStatus::Rescheduled->value,
                'rescheduled_to_id' => $successor->getKey(),
                'cancellation_detail' => mb_substr($reason, 0, 255),
                'updated_by' => $actor?->getKey(),
            ])->save();

            return $successor->refresh();
        }, 3);
    }

    public function substituteTeacher(
        ClassSession $session,
        Teacher $substitute,
        string $reason,
        ?User $actor = null,
    ): ClassSession {
        if ($session->status !== ClassSessionStatus::Scheduled) {
            throw CourseRuleException::refuse('teacher_id', sprintf(
                'Only a scheduled class can be handed to somebody else; this one is %s.',
                $session->status->label(),
            ));
        }

        if ((int) $session->teacher_id === (int) $substitute->getKey()) {
            throw CourseRuleException::refuse('teacher_id', 'That is already the teacher for this class.');
        }

        if (! $substitute->canTeach()) {
            throw CourseRuleException::refuse('teacher_id', sprintf(
                '%s is %s, so they cannot take a class.',
                $substitute->name,
                $substitute->status->label(),
            ));
        }

        if (trim($reason) === '') {
            throw CourseRuleException::reasonRequired('reason',
                'A substitution takes a reason — both teachers are told, and the missed-class report '
                .'is built from these.');
        }

        return $this->db->transaction(function () use ($session, $substitute, $reason, $actor): ClassSession {
            $candidate = (new SlotCandidate(
                teacherId: $substitute->getKey(),
                classroomId: $session->classroom_id,
                batchId: null,
                startsAt: $session->startsAt(),
                endsAt: $session->endsAt(),
                ignoreType: SlotCandidate::TYPE_CLASS_SESSION,
                ignoreId: $session->getKey(),
                deliveryMode: $session->delivery_mode,
            ))
                // The rule that produced this class holds the same room at the same hour, because
                // this class is what holding it looks like.
                ->alsoIgnoring(SlotCandidate::TYPE_TIMETABLE_ENTRY, $session->timetable_entry_id);

            $this->detector->lockParents($candidate);
            $this->assertClean($candidate, [], $actor);

            $session->forceFill([
                // Filled once and never overwritten: who was SUPPOSED to teach it is the question
                // §99 asks, and a second substitution must not erase the first answer.
                'original_teacher_id' => $session->original_teacher_id ?? $session->teacher_id,
                'teacher_id' => $substitute->getKey(),
                'notes' => mb_substr(trim(($session->notes ?? '').' Substitute: '.$reason), 0, 500),
                'updated_by' => $actor?->getKey(),
            ])->save();

            return $session->refresh();
        }, 3);
    }

    public function markHeld(ClassSession $session, ?User $actor = null): ClassSession
    {
        $this->assertTransition($session, ClassSessionStatus::Held);

        return $this->db->transaction(function () use ($session, $actor): ClassSession {
            $session->forceFill([
                'status' => ClassSessionStatus::Held->value,
                'updated_by' => $actor?->getKey(),
            ])->save();

            $batch = $session->batch;

            if ($batch instanceof Batch) {
                app(BatchService::class)->recountSessions($batch);
            }

            // `attendance_auto_absent_on_close` is Phase 17's: the register belongs to the attendance
            // service, and filling one from here would be a second writer of the same rows.

            return $session->refresh();
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function assertTransition(ClassSession $session, ClassSessionStatus $to): void
    {
        $allowed = self::TRANSITIONS[$session->status->value] ?? [];

        if (in_array($to->value, $allowed, true)) {
            return;
        }

        throw CourseRuleException::refuse('status', sprintf(
            'A class cannot go from %s to %s. %s',
            $session->status->label(),
            $to->label(),
            $allowed === []
                ? sprintf('%s is where a class ends.', $session->status->label())
                : 'From here: '.implode(', ', $allowed).'.',
        ));
    }

    /**
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
            ->withProperties(['reason' => $reason, 'conflicts' => $report->toArray()['conflicts']])
            ->log('class_session.clash_overridden');
    }

    private function assertTeacherCanTeach(?int $teacherId): void
    {
        if ($teacherId === null) {
            return;
        }

        $teacher = Teacher::query()->find($teacherId);

        if ($teacher === null || $teacher->canTeach()) {
            return;
        }

        throw CourseRuleException::refuse('teacher_id', sprintf(
            '%s is %s, so they cannot take a class.',
            $teacher->name,
            $teacher->status->label(),
        ));
    }

    /**
     * A class that has a register cannot be called off: it happened, and the register proves it.
     */
    private function assertNoAttendanceYet(ClassSession $session): void
    {
        if (! DB::getSchemaBuilder()->hasTable('student_attendances')) {
            return;
        }

        $marked = DB::table('student_attendances')
            ->where('class_session_id', $session->getKey())
            ->exists();

        if ($marked) {
            throw CourseRuleException::refuse('status',
                'Attendance has been taken for this class, so it cannot be cancelled — the register '
                .'says people were in the room.');
        }
    }

    /** "Class 12 of 40" — counted from the batch's prior classes, not stored on the rule. */
    private function nextSequenceNumber(Batch $batch): int
    {
        return 1 + (int) DB::table('class_sessions')
            ->whereNull('deleted_at')
            ->where('batch_id', $batch->getKey())
            ->whereIn('status', [ClassSessionStatus::Scheduled->value, ClassSessionStatus::Held->value])
            ->count();
    }

    /**
     * A date column and a time column, joined into the one moment the detector compares.
     *
     * The date arrives as a Carbon once the cast has run and as a string before it has, and the two
     * stringify differently — which is the kind of difference that produces a clash check against
     * midnight and nobody noticing.
     */
    private function moment(mixed $date, mixed $time): Carbon
    {
        $day = $date instanceof \DateTimeInterface
            ? Carbon::parse($date)->toDateString()
            : Carbon::parse((string) $date)->toDateString();

        return Carbon::parse($day.' '.Carbon::parse((string) $time)->format('H:i:s'));
    }

    private function horizon(): Carbon
    {
        return Carbon::today()->addWeeks(max(1, (int) setting('institute.session_generation_weeks_ahead', 8)));
    }
}
