<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\DataObjects\Institute\AttendanceResult;
use App\Enums\AttendanceMarkSource;
use App\Enums\ClassSessionStatus;
use App\Enums\StudentAttendanceStatus;
use App\Models\Institute\ClassSession;
use App\Models\Institute\Student;
use App\Models\Institute\StudentAttendance;
use App\Models\Institute\StudentBatchEnrollment;
use App\Models\User;
use App\Services\Institute\Exceptions\AttendanceRuleException;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The register (§75, phase-14-17 §6.9).
 *
 * **A mark exists only against a dated class, for a student who was on that roster that day
 * (INV-I9).** Both halves matter: attendance hung off a weekly rule could not say which Tuesday, and
 * a student enrolled on the 10th marked absent for the 3rd is a number that punishes somebody for a
 * class that was not theirs to attend. `roster()` answers "who was in this batch on that date", and
 * every mark is checked against it rather than against today's roster.
 *
 * **Nothing is ever deleted (INV-I10).** A register is a record of what somebody observed; removing a
 * row moves a percentage with no trace of why. Inside `institute.attendance_lock_hours` anybody who
 * may mark may correct freely; after it, `amend()` requires the edit permission and a reason, keeps
 * the old value in the activity log, and stamps who changed it and when.
 *
 * **The percentage is defined once (INV-I11).** `recountEnrollment()` is the only writer of the five
 * counters and `attendance_percentage`, it goes through bcmath, and `attendance:recount` reproduces
 * every stored value. Cancelled and rescheduled classes enter neither side — a student is not marked
 * down for the institute's own decisions.
 */
final class AttendanceService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly BatchEnrollmentService $enrollments,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Who is expected, and what is already recorded
    |--------------------------------------------------------------------------
    */

    /**
     * The roster of one class, each member carrying the attendance row they already have.
     *
     * Re-opening the screen therefore shows what was marked rather than a blank register, which is
     * what stops a second submission from being a fresh guess.
     */
    public function roster(ClassSession $session): Collection
    {
        $on = Carbon::parse($session->session_date->toDateString());

        $existing = StudentAttendance::query()
            ->where('class_session_id', $session->getKey())
            ->get()
            ->keyBy('student_id');

        return $this->enrollments->roster($session->batch, $on)
            ->map(function (StudentBatchEnrollment $enrollment) use ($existing): StudentBatchEnrollment {
                $enrollment->setRelation('attendanceForSession', $existing->get($enrollment->student_id));

                return $enrollment;
            });
    }

    /*
    |--------------------------------------------------------------------------
    | Marking
    |--------------------------------------------------------------------------
    */

    /**
     * Record a register.
     *
     * `$marks` is `[student_id => ['status' => ..., 'check_in_time' => ..., 'remarks' => ...]]`.
     *
     * @param  array<int, array<string, mixed>>  $marks
     * @param  array<string, mixed>  $meta
     */
    public function mark(ClassSession $session, array $marks, array $meta = [], ?User $actor = null): AttendanceResult
    {
        $this->assertSessionCanBeMarked($session);

        $source = $meta['marked_via'] ?? AttendanceMarkSource::Manual;
        $source = $source instanceof AttendanceMarkSource
            ? $source
            : (AttendanceMarkSource::tryFrom((string) $source) ?? AttendanceMarkSource::Manual);

        return $this->db->transaction(function () use ($session, $marks, $source, $actor): AttendanceResult {
            $roster = $this->enrollments
                ->roster($session->batch, Carbon::parse($session->session_date->toDateString()))
                ->keyBy('student_id');

            $created = 0;
            $updated = 0;
            $promoted = [];
            $touched = [];

            foreach ($marks as $studentId => $mark) {
                $studentId = (int) $studentId;

                /** @var StudentBatchEnrollment|null $enrollment */
                $enrollment = $roster->get($studentId);

                if ($enrollment === null) {
                    // INV-I9. Named, not counted: whoever submitted this needs to know which student.
                    throw AttendanceRuleException::notOnTheRoster(
                        (string) (Student::query()->whereKey($studentId)->value('name') ?? ('Student #'.$studentId)),
                        app_date($session->session_date),
                    );
                }

                [$status, $minutesLate] = $this->resolveStatus($session, $mark);

                if (($mark['status'] ?? null) === StudentAttendanceStatus::Present->value
                    && $status === StudentAttendanceStatus::Late) {
                    $promoted[] = (string) ($enrollment->student?->name ?? ('Student #'.$studentId));
                }

                $existing = StudentAttendance::query()
                    ->where('class_session_id', $session->getKey())
                    ->where('student_id', $studentId)
                    ->first();

                $row = $existing ?? new StudentAttendance;

                $row->forceFill([
                    'class_session_id' => $session->getKey(),
                    'student_id' => $studentId,
                    'student_batch_enrollment_id' => $enrollment->getKey(),
                    'batch_id' => $session->batch_id,
                    'status' => $status->value,
                    'check_in_time' => $mark['check_in_time'] ?? null,
                    'minutes_late' => $minutesLate,
                    'remarks' => $mark['remarks'] ?? null,
                    'marked_via' => $source->value,
                    'marked_by' => $actor?->getKey(),
                    'marked_at' => Carbon::now(),
                    'updated_by' => $actor?->getKey(),
                ]);

                if ($existing === null) {
                    $row->created_by = $actor?->getKey();
                    $created++;
                } else {
                    $updated++;
                }

                $row->save();

                $touched[$enrollment->getKey()] = $enrollment;
            }

            $counts = $this->stampSession($session, $actor);

            foreach ($touched as $enrollment) {
                $this->recountEnrollment($enrollment);
            }

            return new AttendanceResult(
                created: $created,
                updated: $updated,
                counts: $counts,
                percentage: $this->sessionPercentage($counts),
                promoted: $promoted,
            );
        }, 3);
    }

    /**
     * "Everybody present" / "everybody absent", before the individual overrides.
     */
    public function bulk(ClassSession $session, StudentAttendanceStatus $status, ?User $actor = null): AttendanceResult
    {
        $marks = $this->enrollments
            ->roster($session->batch, Carbon::parse($session->session_date->toDateString()))
            ->mapWithKeys(static fn (StudentBatchEnrollment $e): array => [
                (int) $e->student_id => ['status' => $status->value],
            ])
            ->all();

        return $this->mark($session, $marks, ['marked_via' => AttendanceMarkSource::Bulk], $actor);
    }

    /**
     * Fill whoever is still unmarked as absent, when the institute has asked for that.
     *
     * Called as a class is closed. The rows are stamped `system`, because an absence nobody decided
     * is still an absence on somebody's record and the difference has to stay visible.
     */
    public function fillUnmarkedAsAbsent(ClassSession $session, ?User $actor = null): int
    {
        if (! (bool) setting('institute.attendance_auto_absent_on_close', false)) {
            return 0;
        }

        $marked = StudentAttendance::query()
            ->where('class_session_id', $session->getKey())
            ->pluck('student_id')
            ->all();

        $missing = $this->enrollments
            ->roster($session->batch, Carbon::parse($session->session_date->toDateString()))
            ->reject(static fn (StudentBatchEnrollment $e): bool => in_array((int) $e->student_id, array_map('intval', $marked), true))
            ->mapWithKeys(static fn (StudentBatchEnrollment $e): array => [
                (int) $e->student_id => ['status' => StudentAttendanceStatus::Absent->value],
            ])
            ->all();

        if ($missing === []) {
            return 0;
        }

        return $this->mark($session, $missing, ['marked_via' => AttendanceMarkSource::System], $actor)->created;
    }

    /*
    |--------------------------------------------------------------------------
    | Correcting (INV-I10)
    |--------------------------------------------------------------------------
    */

    public function amend(
        StudentAttendance $attendance,
        StudentAttendanceStatus $to,
        ?string $reason = null,
        ?User $actor = null,
    ): StudentAttendance {
        $reason = trim((string) $reason);
        $locked = ! $attendance->isWithinLockWindow();

        if ($locked) {
            if ($actor !== null && ! $actor->can('student_attendance.edit')) {
                throw AttendanceRuleException::amendmentNeedsThePermission();
            }

            if ($reason === '') {
                throw AttendanceRuleException::amendmentNeedsAReason();
            }
        }

        if ($attendance->status === $to && $reason === '') {
            return $attendance;
        }

        return $this->db->transaction(function () use ($attendance, $to, $reason, $actor, $locked): StudentAttendance {
            $from = $attendance->status;

            $attendance->forceFill([
                'status' => $to->value,
                // Only a change made after the window is an amendment. Inside it, the register is
                // still being taken, and stamping every keystroke would make the audit meaningless.
                'amended_at' => $locked ? Carbon::now() : $attendance->amended_at,
                'amended_by' => $locked ? $actor?->getKey() : $attendance->amended_by,
                'amendment_reason' => $locked ? mb_substr($reason, 0, 255) : $attendance->amendment_reason,
                'updated_by' => $actor?->getKey(),
            ])->save();

            activity('student_attendance')
                ->performedOn($attendance)
                ->causedBy($actor)
                ->withProperties([
                    'from' => $from->value,
                    'to' => $to->value,
                    'reason' => $reason !== '' ? $reason : null,
                    'after_lock_window' => $locked,
                ])
                ->log('attendance.amended');

            $session = $attendance->session;

            if ($session instanceof ClassSession) {
                $this->stampSession($session->refresh(), $actor);
            }

            if ($attendance->enrollment instanceof StudentBatchEnrollment) {
                $this->recountEnrollment($attendance->enrollment);
            }

            return $attendance->refresh();
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | The percentage, defined once (INV-I11)
    |--------------------------------------------------------------------------
    */

    /**
     * The authority for one enrolment's five counters and its attendance percentage.
     *
     *   denominator = the student's rows against HELD classes of this batch
     *                 (leave rows only when the institute counts them)
     *   numerator   = present + late
     *
     * Cancelled and rescheduled classes are in neither side, because they did not happen.
     */
    public function recountEnrollment(StudentBatchEnrollment $enrollment): void
    {
        $leaveCounts = (bool) setting('institute.attendance_leave_counts_in_denominator', false);

        $rows = DB::table('student_attendances as sa')
            ->join('class_sessions as cs', 'cs.id', '=', 'sa.class_session_id')
            ->whereNull('sa.deleted_at')
            ->whereNull('cs.deleted_at')
            ->where('sa.student_batch_enrollment_id', $enrollment->getKey())
            ->where('cs.status', ClassSessionStatus::Held->value)
            ->selectRaw("
                SUM(sa.status = 'present') AS present_count,
                SUM(sa.status = 'absent')  AS absent_count,
                SUM(sa.status = 'leave')   AS leave_count,
                SUM(sa.status = 'late')    AS late_count,
                COUNT(*) AS total
            ")
            ->first();

        $present = (int) ($rows->present_count ?? 0);
        $absent = (int) ($rows->absent_count ?? 0);
        $leave = (int) ($rows->leave_count ?? 0);
        $late = (int) ($rows->late_count ?? 0);

        $numerator = $present + $late;
        $denominator = $leaveCounts ? ($present + $absent + $leave + $late) : ($present + $absent + $late);

        // Half-up at TWO, into a four-decimal column: 2/3 stores 66.6700, not 66.6667 (FT-43).
        // A percentage people read and quote is a two-decimal figure; the extra column width is there
        // so `decimal(8,4)` is the one shape every percentage in the system has (CLAUDE.md §3), not
        // so attendance can claim a precision nobody means.
        $percentage = $denominator === 0
            ? '0.00'
            : Money::percentageOf((string) $numerator, (string) $denominator, 2);

        DB::table('student_batch_enrollments')
            ->where('id', $enrollment->getKey())
            ->update([
                'sessions_expected_count' => $denominator,
                'present_count' => $present,
                'absent_count' => $absent,
                'leave_count' => $leave,
                'late_count' => $late,
                'attendance_percentage' => $percentage,
                'updated_at' => Carbon::now(),
            ]);

        $enrollment->forceFill([
            'sessions_expected_count' => $denominator,
            'present_count' => $present,
            'absent_count' => $absent,
            'leave_count' => $leave,
            'late_count' => $late,
            'attendance_percentage' => $percentage,
        ])->syncOriginal();
    }

    /**
     * Is this enrolment below the institute's line? The flag every report shows.
     */
    public function isBelowMinimum(StudentBatchEnrollment $enrollment): bool
    {
        $minimum = (string) setting('institute.attendance_minimum_percentage', 75);

        if ((int) $enrollment->sessions_expected_count === 0) {
            return false;
        }

        return Money::compare((string) $enrollment->attendance_percentage, $minimum) < 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function assertSessionCanBeMarked(ClassSession $session): void
    {
        if (in_array($session->status, [ClassSessionStatus::Cancelled, ClassSessionStatus::Rescheduled], true)) {
            throw AttendanceRuleException::sessionIsNotTeachable($session->status->label());
        }
    }

    /**
     * The status a mark really is, and how late they were.
     *
     * A `present` whose check-in is past `institute.attendance_grace_minutes` becomes `late` — it is
     * the same fact stated accurately, and §8.15 says the screen must say so rather than let somebody
     * discover it in a report.
     *
     * @param  array<string, mixed>  $mark
     * @return array{0: StudentAttendanceStatus, 1: int|null}
     */
    private function resolveStatus(ClassSession $session, array $mark): array
    {
        $status = $mark['status'] ?? StudentAttendanceStatus::Present->value;
        $status = $status instanceof StudentAttendanceStatus
            ? $status
            : (StudentAttendanceStatus::tryFrom((string) $status) ?? StudentAttendanceStatus::Present);

        $checkIn = $mark['check_in_time'] ?? null;

        if ($checkIn === null || ! $status->countsAsPresent()) {
            return [$status, null];
        }

        $grace = max(0, (int) setting('institute.attendance_grace_minutes', 15));

        $start = Carbon::parse('2000-01-01 '.Carbon::parse((string) $session->start_time)->format('H:i:s'));
        $arrived = Carbon::parse('2000-01-01 '.Carbon::parse((string) $checkIn)->format('H:i:s'));

        $minutes = $start->diffInMinutes($arrived, false);

        if ($minutes <= $grace) {
            return [$status, $minutes > 0 ? (int) $minutes : null];
        }

        return [StudentAttendanceStatus::Late, (int) $minutes];
    }

    /**
     * The session's five counters, `attendance_marked_at`, and the move to `held`.
     *
     * @return array<string, int>
     */
    private function stampSession(ClassSession $session, ?User $actor): array
    {
        $counts = [];

        foreach (StudentAttendanceStatus::cases() as $status) {
            $counts[$status->value] = (int) StudentAttendance::query()
                ->where('class_session_id', $session->getKey())
                ->where('status', $status->value)
                ->count();
        }

        $expected = $this->enrollments
            ->roster($session->batch, Carbon::parse($session->session_date->toDateString()))
            ->count();

        $session->forceFill([
            'expected_count' => $expected,
            'present_count' => $counts[StudentAttendanceStatus::Present->value],
            'absent_count' => $counts[StudentAttendanceStatus::Absent->value],
            'leave_count' => $counts[StudentAttendanceStatus::Leave->value],
            'late_count' => $counts[StudentAttendanceStatus::Late->value],
            'attendance_marked_at' => Carbon::now(),
            'attendance_marked_by' => $actor?->getKey(),
            // A class with a register is a class that happened.
            'status' => $session->status === ClassSessionStatus::Scheduled
                ? ClassSessionStatus::Held->value
                : $session->status->value,
            'updated_by' => $actor?->getKey(),
        ])->save();

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function sessionPercentage(array $counts): string
    {
        $leaveCounts = (bool) setting('institute.attendance_leave_counts_in_denominator', false);

        $present = ($counts[StudentAttendanceStatus::Present->value] ?? 0)
            + ($counts[StudentAttendanceStatus::Late->value] ?? 0);

        $denominator = $present + ($counts[StudentAttendanceStatus::Absent->value] ?? 0)
            + ($leaveCounts ? ($counts[StudentAttendanceStatus::Leave->value] ?? 0) : 0);

        return $denominator === 0 ? '0.00' : Money::percentageOf((string) $present, (string) $denominator, 2);
    }
}
