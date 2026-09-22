<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\Enums\EnrollmentStatus;
use App\Models\Institute\Batch;
use App\Models\Institute\Classroom;
use App\Models\Institute\Student;
use App\Models\Institute\StudentAdmission;
use App\Models\Institute\StudentBatchEnrollment;
use App\Models\User;
use App\Services\Institute\Exceptions\BatchCapacityExceeded;
use App\Services\Institute\Exceptions\CourseRuleException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Seats in batches (phase-14-17 §6.6, INV-I6, INV-I7).
 *
 * **Capacity is enforced here and nowhere else.** Every seat is given out inside one transaction that
 * locks the batch row first and then *recounts* — `batches.current_students` is never read to make
 * the decision, because it is a cache with no authority (D48). Two receptionists enrolling the last
 * student at the same moment therefore queue on the batch row, and the second one is told the batch
 * is full rather than both being told it is not.
 *
 * **Overbooking takes three separate yeses**: the institute setting, a caller passing `overbook`, and
 * a reason. Any one missing and the seat is refused. `uq_sbe_active` underneath is the backstop, and
 * a 1062 from it is read as "already enrolled" rather than surfaced as a crash.
 *
 * **A transfer keeps the past where it happened.** The old row goes to `transferred_out` with its
 * attendance intact and the new row starts empty, linked both ways. Moving the attendance across
 * would say the student attended classes they were not enrolled for; deleting it would say they never
 * attended at all.
 *
 * **This service writes nothing financial (INV-I1).** A transfer hands the fee side to Phase 18, which
 * owns both the repoint and the carried receipt; until that phase lands, the handover is recorded and
 * the caller is told.
 */
final class BatchEnrollmentService
{
    /** §2.30.8, verbatim. */
    private const TRANSITIONS = [
        'active' => ['transferred_out', 'completed', 'dropped', 'suspended', 'cancelled'],
        'suspended' => ['active', 'cancelled'],
        'transferred_out' => [],
        'completed' => [],
        'dropped' => [],
        'cancelled' => [],
    ];

    private const REASON_REQUIRED = ['dropped', 'suspended', 'transferred_out'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly BatchService $batches,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Enrol
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $options
     */
    public function enroll(
        Student $student,
        Batch $batch,
        ?StudentAdmission $admission = null,
        array $options = [],
        ?User $actor = null,
    ): StudentBatchEnrollment {
        return $this->db->transaction(function () use ($student, $batch, $admission, $options, $actor): StudentBatchEnrollment {
            // (1) The lock. Everything after this is serialised for this batch.
            $locked = Batch::query()->lockForUpdate()->findOrFail($batch->getKey());

            // (2)–(4) the guards that do not need a count.
            $this->assertBatchAcceptsEnrollment($locked);
            $this->assertCourseMatches($locked, $admission);
            $this->assertStudentIsEnrollable($student);

            // (5) The recount. Never `current_students`.
            $active = (int) DB::table('student_batch_enrollments')
                ->whereNull('deleted_at')
                ->where('batch_id', $locked->getKey())
                ->where('status', EnrollmentStatus::Active->value)
                ->count();

            // (6) The two ceilings, and the three yeses overbooking takes.
            $overbooked = $this->resolveCapacity($locked, $active, $options, $actor);

            // (7) The insert, with the unique index read as a sentence rather than a crash.
            try {
                $enrollment = new StudentBatchEnrollment;
                $enrollment->fill([
                    'student_id' => $student->getKey(),
                    'batch_id' => $locked->getKey(),
                    'course_id' => $locked->course_id,
                    'student_admission_id' => $admission?->getKey(),
                    'enrolled_on' => $options['enrolled_on'] ?? Carbon::today()->toDateString(),
                    'notes' => $options['notes'] ?? null,
                ]);

                $enrollment->forceFill([
                    'status' => EnrollmentStatus::Active->value,
                    'is_overbooked' => $overbooked !== null,
                    'overbook_reason' => $overbooked,
                    // (9) The next free roll number in this batch.
                    'roll_number' => $options['roll_number'] ?? $this->nextRollNumber($locked),
                    'transferred_from_id' => $options['transferred_from_id'] ?? null,
                    'created_by' => $actor?->getKey(),
                    'updated_by' => $actor?->getKey(),
                ])->save();
            } catch (UniqueConstraintViolationException) {
                throw CourseRuleException::refuse('student_id', sprintf(
                    '%s is already enrolled in %s.',
                    $student->name,
                    $locked->label(),
                ));
            }

            // (8) The cache, recounted — never incremented.
            $this->batches->recountStudents($locked);

            // (10) A seat comes with a syllabus to get through: one course row, one module row per
            // active module and one topic row per active topic, so a student's progress screen has
            // something to show on day one rather than after the first topic is covered.
            app(CourseProgressService::class)->openFor($enrollment, $actor);

            return $enrollment->refresh();
        }, 3);
    }

    /**
     * (11) Warn, never block: the student's OTHER active batches whose hours overlap this one's.
     *
     * A student with two clashing batches is usually a mistake and occasionally deliberate — an
     * evening make-up batch that overlaps a morning one they have stopped attending. So the enrolment
     * screen shows this and lets a person decide, rather than the service deciding for them.
     *
     * @return list<array<string, mixed>>
     */
    public function overlapsWithOtherBatches(Student $student, Batch $batch): array
    {
        $others = DB::table('student_batch_enrollments')
            ->whereNull('deleted_at')
            ->where('student_id', $student->getKey())
            ->where('status', EnrollmentStatus::Active->value)
            ->where('batch_id', '!=', $batch->getKey())
            ->pluck('batch_id');

        if ($others->isEmpty()) {
            return [];
        }

        $mine = DB::table('timetable_entries')
            ->whereNull('deleted_at')
            ->where('batch_id', $batch->getKey())
            ->where('is_active', true)
            ->get(['id', 'day_of_week', 'start_time', 'end_time']);

        if ($mine->isEmpty()) {
            return [];
        }

        $clashes = [];

        foreach ($mine as $slot) {
            $found = DB::table('timetable_entries')
                ->join('batches', 'batches.id', '=', 'timetable_entries.batch_id')
                ->whereNull('timetable_entries.deleted_at')
                ->whereIn('timetable_entries.batch_id', $others)
                ->where('timetable_entries.is_active', true)
                ->where('timetable_entries.day_of_week', $slot->day_of_week)
                ->where('timetable_entries.start_time', '<', $slot->end_time)
                ->where('timetable_entries.end_time', '>', $slot->start_time)
                ->get(['batches.code', 'timetable_entries.day_of_week', 'timetable_entries.start_time', 'timetable_entries.end_time']);

            foreach ($found as $row) {
                $clashes[] = [
                    'batch' => $row->code,
                    'day' => ucfirst((string) $row->day_of_week),
                    'window' => Carbon::parse($row->start_time)->format('H:i').'–'
                        .Carbon::parse($row->end_time)->format('H:i'),
                ];
            }
        }

        return $clashes;
    }

    /*
    |--------------------------------------------------------------------------
    | Transfer
    |--------------------------------------------------------------------------
    */

    public function transfer(
        StudentBatchEnrollment $enrollment,
        Batch $target,
        string $reason,
        ?User $actor = null,
    ): StudentBatchEnrollment {
        if ($enrollment->status !== EnrollmentStatus::Active) {
            throw CourseRuleException::refuse('status', sprintf(
                'Only an active enrolment can be transferred; this one is %s.',
                $enrollment->status->label(),
            ));
        }

        if ((int) $enrollment->batch_id === (int) $target->getKey()) {
            throw CourseRuleException::refuse('batch_id', 'That is the batch the student is already in.');
        }

        if (trim($reason) === '') {
            throw CourseRuleException::reasonRequired('reason',
                'A transfer takes a reason. It goes on both enrolments, and it is what explains the '
                .'gap in the first batch\'s register.');
        }

        return $this->db->transaction(function () use ($enrollment, $target, $reason, $actor): StudentBatchEnrollment {
            // Both batches locked in ascending id order — two transfers in opposite directions would
            // otherwise take the two rows in opposite orders and deadlock.
            $ids = [(int) $enrollment->batch_id, (int) $target->getKey()];
            sort($ids);

            $locked = [];

            foreach ($ids as $id) {
                $locked[$id] = Batch::query()->lockForUpdate()->findOrFail($id);
            }

            $from = $locked[(int) $enrollment->batch_id];
            $to = $locked[(int) $target->getKey()];

            $student = $enrollment->student;
            $sameCourse = (int) $from->course_id === (int) $to->course_id;

            $successor = $this->enroll($student, $to, $enrollment->admission, [
                'transferred_from_id' => $enrollment->getKey(),
                'notes' => $enrollment->notes,
            ], $actor);

            // The old row keeps its attendance: the student really did attend those classes, and
            // moving the history would say they attended ones they were not enrolled for.
            $enrollment->forceFill([
                'status' => EnrollmentStatus::TransferredOut->value,
                'left_on' => Carbon::today()->toDateString(),
                'leave_reason' => mb_substr($reason, 0, 255),
                'transfer_reason' => mb_substr($reason, 0, 255),
                'transferred_to_id' => $successor->getKey(),
                'updated_by' => $actor?->getKey(),
            ])->save();

            if ($enrollment->admission instanceof StudentAdmission) {
                $enrollment->admission->forceFill([
                    'batch_id' => $to->getKey(),
                    'updated_by' => $actor?->getKey(),
                ])->save();
            }

            $this->batches->recountStudents($from);
            $this->batches->recountStudents($to);

            activity('batches')
                ->performedOn($successor)
                ->causedBy($actor)
                ->withProperties([
                    'from_batch' => $from->code,
                    'to_batch' => $to->code,
                    'same_course' => $sameCourse,
                    'reason' => $reason,
                    // Phase 18 owns both the repoint and the carried receipt (§6.8, §13.3). This
                    // phase records the handover rather than writing a fee row it does not own.
                    'fee_handover' => $sameCourse
                        ? 'StudentFeeService::reassignBatch()'
                        : 'StudentFeeService::transferPayment()',
                ])
                ->log('enrollment.transferred');

            return $successor->refresh();
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    */

    public function changeStatus(
        StudentBatchEnrollment $enrollment,
        EnrollmentStatus $to,
        ?string $reason = null,
        ?User $actor = null,
    ): StudentBatchEnrollment {
        $from = $enrollment->status;

        if ($from === $to) {
            return $enrollment;
        }

        $allowed = self::TRANSITIONS[$from->value] ?? [];

        if (! in_array($to->value, $allowed, true)) {
            throw CourseRuleException::refuse('status', sprintf(
                'An enrolment cannot go from %s to %s. %s',
                $from->label(),
                $to->label(),
                $allowed === []
                    ? sprintf('%s is where an enrolment ends.', $from->label())
                    : 'From here: '.implode(', ', $allowed).'.',
            ));
        }

        $reason = trim((string) $reason);

        if (in_array($to->value, self::REASON_REQUIRED, true) && $reason === '') {
            throw CourseRuleException::reasonRequired('status', sprintf(
                'Moving an enrolment to %s takes a reason.',
                $to->label(),
            ));
        }

        if ($to === EnrollmentStatus::Cancelled) {
            $this->assertNoAttendanceYet($enrollment);
        }

        return $this->db->transaction(function () use ($enrollment, $to, $reason, $actor): StudentBatchEnrollment {
            $leaves = in_array($to, [
                EnrollmentStatus::Dropped,
                EnrollmentStatus::Completed,
                EnrollmentStatus::Cancelled,
            ], true);

            $enrollment->forceFill([
                'status' => $to->value,
                'left_on' => $leaves ? Carbon::today()->toDateString() : $enrollment->left_on,
                'completed_on' => $to === EnrollmentStatus::Completed
                    ? Carbon::today()->toDateString()
                    : $enrollment->completed_on,
                'leave_reason' => $reason !== '' ? mb_substr($reason, 0, 255) : $enrollment->leave_reason,
                'updated_by' => $actor?->getKey(),
            ])->save();

            $batch = $enrollment->batch;

            if ($batch instanceof Batch) {
                $this->batches->recountStudents($batch);
            }

            return $enrollment->refresh();
        }, 3);
    }

    public function drop(StudentBatchEnrollment $e, string $reason, ?User $actor = null): StudentBatchEnrollment
    {
        return $this->changeStatus($e, EnrollmentStatus::Dropped, $reason, $actor);
    }

    public function suspend(StudentBatchEnrollment $e, string $reason, ?User $actor = null): StudentBatchEnrollment
    {
        return $this->changeStatus($e, EnrollmentStatus::Suspended, $reason, $actor);
    }

    public function reinstate(StudentBatchEnrollment $e, ?User $actor = null): StudentBatchEnrollment
    {
        return $this->changeStatus($e, EnrollmentStatus::Active, null, $actor);
    }

    public function complete(StudentBatchEnrollment $e, ?User $actor = null): StudentBatchEnrollment
    {
        return $this->changeStatus($e, EnrollmentStatus::Completed, null, $actor);
    }

    /*
    |--------------------------------------------------------------------------
    | The roster
    |--------------------------------------------------------------------------
    */

    /**
     * Who was in this batch on a given date — the function attendance marking and `expected_count`
     * both read, so a student who joined in week six is never marked absent for week two.
     */
    public function roster(Batch $batch, ?Carbon $on = null): Collection
    {
        $query = StudentBatchEnrollment::query()
            ->with('student')
            ->where('batch_id', $batch->getKey());

        $on === null
            ? $query->where('status', EnrollmentStatus::Active->value)
            : $query->activeOn($on);

        return $query
            ->orderByRaw('CAST(roll_number AS UNSIGNED) ASC')
            ->orderBy('id')
            ->get();
    }

    public function recount(Batch $batch): int
    {
        return $this->batches->recountStudents($batch);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function assertBatchAcceptsEnrollment(Batch $batch): void
    {
        if ($batch->status->acceptsEnrollment()) {
            return;
        }

        throw CourseRuleException::refuse('batch_id', sprintf(
            '%s is %s, so it is not taking students. Only a planned or enrolling batch is.',
            $batch->label(),
            $batch->status->label(),
        ));
    }

    private function assertCourseMatches(Batch $batch, ?StudentAdmission $admission): void
    {
        if ($admission === null || (int) $admission->course_id === (int) $batch->course_id) {
            return;
        }

        throw CourseRuleException::refuse('batch_id',
            'That batch teaches a different course from the one this admission is for. Seating the '
            .'student there would mean they paid for one course and are attending another.');
    }

    private function assertStudentIsEnrollable(Student $student): void
    {
        if ($student->status->isEnrollable()) {
            return;
        }

        throw CourseRuleException::refuse('student_id', sprintf(
            '%s is %s. A student takes a seat once they are registered.',
            $student->name,
            $student->status->label(),
        ));
    }

    /**
     * The two ceilings, and the three separate yeses overbooking takes.
     *
     * @param  array<string, mixed>  $options
     * @return string|null the overbooking reason when the seat is an overbooked one
     */
    private function resolveCapacity(Batch $batch, int $active, array $options, ?User $actor): ?string
    {
        $capacity = (int) $batch->student_capacity;

        $room = $batch->classroom_id !== null ? Classroom::query()->find($batch->classroom_id) : null;
        $roomCapacity = $room instanceof Classroom
            && $room->capacityLimits()
            && $batch->delivery_mode->needsClassroom()
                ? (int) $room->capacity
                : null;

        $overBatch = $active >= $capacity;
        $overRoom = $roomCapacity !== null && $active >= $roomCapacity;

        if (! $overBatch && ! $overRoom) {
            return null;
        }

        if (($options['overbook'] ?? false) !== true) {
            throw $overBatch
                ? BatchCapacityExceeded::batchIsFull($batch->label(), $active, $capacity)
                : BatchCapacityExceeded::roomIsFull($batch->label(), $active, (int) $roomCapacity, $room->label());
        }

        if (! (bool) setting('institute.batch_allow_overbooking', false)) {
            throw BatchCapacityExceeded::overbookingIsOff($batch->label());
        }

        if ($actor !== null && ! $actor->can('batches.assign')) {
            throw BatchCapacityExceeded::batchIsFull($batch->label(), $active, $capacity);
        }

        $reason = trim((string) ($options['overbook_reason'] ?? ''));

        if ($reason === '') {
            throw BatchCapacityExceeded::overbookingNeedsAReason();
        }

        return mb_substr($reason, 0, 255);
    }

    /**
     * Roll numbers are per batch and are not reused: a dropped student's number stays theirs, because
     * it is printed on a register somebody may still be holding.
     */
    private function nextRollNumber(Batch $batch): string
    {
        $highest = (int) DB::table('student_batch_enrollments')
            ->where('batch_id', $batch->getKey())
            ->selectRaw('COALESCE(MAX(CAST(roll_number AS UNSIGNED)), 0) AS n')
            ->value('n');

        return (string) ($highest + 1);
    }

    /**
     * "Enrolled in error" only holds while nothing has happened yet. Once there is a register with
     * this student on it, the seat was real and the way out is `dropped`, which keeps the history.
     */
    private function assertNoAttendanceYet(StudentBatchEnrollment $enrollment): void
    {
        if (! DB::getSchemaBuilder()->hasTable('student_attendances')) {
            return;
        }

        $marked = DB::table('student_attendances')
            ->where('student_batch_enrollment_id', $enrollment->getKey())
            ->exists();

        if ($marked) {
            throw CourseRuleException::refuse('status',
                'This student has been marked present or absent in this batch, so the enrolment was '
                .'not a mistake. Drop it instead — cancelling would erase a register that was taken.');
        }
    }
}
