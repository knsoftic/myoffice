<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\DataObjects\Institute\EligibilityReport;
use App\DataObjects\Institute\EligibilityRule;
use App\Enums\CertificateStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Institute\Certificate;
use App\Models\Institute\StudentBatchEnrollment;
use App\Support\Money;

/**
 * Whether a student may be given a certificate, and if not, exactly why (phase-19-23 §6.14, §84).
 *
 * **Every rule reads the service that owns the figure — never its own SQL.** INV-23-1 makes that a
 * review failure rather than a style preference: a `SUM()` here would be a second opinion on the fee
 * balance, and the one printed on a certificate is the one nobody can correct afterwards. So the fee
 * rule asks `StudentFeeService`, the exam rule asks `ExamStatisticsService`, attendance reads the
 * cache `AttendanceService` maintains, and progress reads the row `CourseProgressService` owns.
 *
 * **Each rule carries its actual value.** "Not eligible" sends a coordinator to find a developer;
 * "attendance 68.50%, needs 75.00%" is answered by the screen they are already looking at. That is
 * the entire reason `check()` returns a list of rows rather than a boolean.
 *
 * **A rule switched off in settings is reported as `skipped`, not silently omitted.** A year later,
 * "attendance passed" and "attendance was not being checked" are very different answers to why a
 * certificate was issued, and the snapshot has to be able to tell them apart.
 *
 * **`certificate_available` is the one rule with no setting.** A course that does not offer a
 * certificate cannot have one issued against it whatever the institute has configured — it is a fact
 * about the course, not a policy about students.
 */
final class CertificateEligibilityService
{
    public function __construct(
        private readonly StudentFeeService $fees,
        private readonly ExamStatisticsService $exams,
        private readonly CourseProgressService $progress,
    ) {}

    /**
     * Run every rule and report each one's verdict.
     *
     * The order is the order a screen shows them, and it runs cheapest-first only incidentally —
     * **every rule is evaluated even after one fails**, because a coordinator fixing three problems
     * wants to see all three rather than discovering them one deploy at a time.
     */
    public function check(StudentBatchEnrollment $enrollment): EligibilityReport
    {
        return new EligibilityReport([
            $this->certificateAvailable($enrollment),
            $this->enrollmentCompleted($enrollment),
            $this->minAttendance($enrollment),
            $this->minProgress($enrollment),
            $this->examsPassed($enrollment),
            $this->feeCleared($enrollment),
            $this->noLiveCertificate($enrollment),
        ]);
    }

    // ===========================================================================================

    /** A fact about the course, not a policy — so there is no setting to switch it off. */
    private function certificateAvailable(StudentBatchEnrollment $enrollment): EligibilityRule
    {
        $available = (bool) $enrollment->batch?->course?->getAttribute('certificate_available');

        return $available
            ? EligibilityRule::pass('certificate_available', 'This course awards a certificate.')
            : EligibilityRule::fail(
                'certificate_available',
                'This course is not set up to award a certificate. Turn that on in the course first.',
            );
    }

    private function enrollmentCompleted(StudentBatchEnrollment $enrollment): EligibilityRule
    {
        if (! (bool) setting('institute.certificate_require_enrollment_completed', true)) {
            return EligibilityRule::skip('enrollment_completed', 'The enrolment status is not being checked.');
        }

        $status = $enrollment->status;

        return $status === EnrollmentStatus::Completed
            ? EligibilityRule::pass('enrollment_completed', 'The enrolment is marked completed.', 'completed', $status->value)
            : EligibilityRule::fail(
                'enrollment_completed',
                sprintf('The enrolment is %s, not completed.', mb_strtolower($status->label())),
                'completed',
                $status->value,
            );
    }

    /**
     * Against `institute.attendance_minimum_percentage` — the key Phase 17 declared.
     *
     * The seeder carries a stored orphan called `minimum_attendance_percentage`; reading that one
     * would return its default and silently ignore whatever the institute actually configured.
     */
    private function minAttendance(StudentBatchEnrollment $enrollment): EligibilityRule
    {
        if (! (bool) setting('institute.certificate_require_min_attendance', true)) {
            return EligibilityRule::skip('min_attendance', 'Attendance is not being checked.');
        }

        $required = Money::round((string) setting('institute.attendance_minimum_percentage', '75'), 4);
        $actual = $enrollment->getAttribute('attendance_percentage');

        if ($actual === null) {
            return EligibilityRule::fail(
                'min_attendance',
                'No attendance has been recorded for this enrolment yet.',
                $required.'%',
            );
        }

        $actual = Money::round((string) $actual, 4);

        return Money::compare($actual, $required) >= 0
            ? EligibilityRule::pass('min_attendance', sprintf('Attendance is %s%%.', $actual), $required.'%', $actual.'%')
            : EligibilityRule::fail(
                'min_attendance',
                sprintf('Attendance is %s%%, and %s%% is required.', $actual, $required),
                $required.'%',
                $actual.'%',
            );
    }

    /** Zero switches the rule off, which is what the setting's help text promises. */
    private function minProgress(StudentBatchEnrollment $enrollment): EligibilityRule
    {
        $required = Money::round((string) setting('institute.certificate_require_min_progress', '100'), 4);

        if (Money::compare($required, '0.0000') <= 0) {
            return EligibilityRule::skip('min_progress', 'Syllabus progress is not being checked.');
        }

        $row = $this->progress->openFor($enrollment);
        $actual = Money::round((string) ($row->getAttribute('completion_percentage') ?? '0'), 4);

        return Money::compare($actual, $required) >= 0
            ? EligibilityRule::pass('min_progress', sprintf('%s%% of the syllabus is covered.', $actual), $required.'%', $actual.'%')
            : EligibilityRule::fail(
                'min_progress',
                sprintf('%s%% of the syllabus is covered, and %s%% is required.', $actual, $required),
                $required.'%',
                $actual.'%',
            );
    }

    /**
     * Every `isMajor()` published exam passed — midterms and finals, never a weekly test.
     *
     * `StudentExamStats::passedEveryMajor()` is deliberately true for a course with no major exams:
     * a short practical course may legitimately have none, and refusing every certificate on it would
     * make the rule impossible to satisfy rather than merely strict. The message says which case it
     * is, because "0 of 0 passed" reads like a failure.
     */
    private function examsPassed(StudentBatchEnrollment $enrollment): EligibilityRule
    {
        if (! (bool) setting('institute.certificate_require_pass', true)) {
            return EligibilityRule::skip('exams_passed', 'Exam results are not being checked.');
        }

        $stats = $this->exams->forStudent($enrollment);

        return $stats->passedEveryMajor()
            ? EligibilityRule::pass(
                'exams_passed',
                $stats->describe(),
                (string) $stats->majorsTotal,
                (string) $stats->majorsPassed,
            )
            : EligibilityRule::fail(
                'exams_passed',
                $stats->describe(),
                (string) $stats->majorsTotal,
                (string) $stats->majorsPassed,
            );
    }

    /**
     * Asked of `StudentFeeService`, never summed here (INV-23-1).
     *
     * The balance is per **admission**, which is the grain fees are charged at — an enrolment is one
     * batch of one admission, and a student who owes on a different course is not owing on this one.
     */
    private function feeCleared(StudentBatchEnrollment $enrollment): EligibilityRule
    {
        if (! (bool) setting('institute.certificate_require_fee_cleared', true)) {
            return EligibilityRule::skip('fee_cleared', 'Fees are not being checked.');
        }

        $student = $enrollment->student;

        if ($student === null) {
            return EligibilityRule::fail('fee_cleared', 'This enrolment has no student behind it.');
        }

        $outstanding = $this->fees->outstandingFor($student);

        return Money::compare($outstanding, '0.00') <= 0
            ? EligibilityRule::pass('fee_cleared', 'Nothing outstanding.', '0.00', $outstanding)
            : EligibilityRule::fail(
                'fee_cleared',
                sprintf('%s is still outstanding.', Money::format($outstanding)),
                '0.00',
                $outstanding,
            );
    }

    /**
     * `uq_ce_live` permits one issued certificate per enrolment, so this asks the same question the
     * index would answer — before the transaction, and with a sentence rather than a 1062.
     */
    private function noLiveCertificate(StudentBatchEnrollment $enrollment): EligibilityRule
    {
        $existing = Certificate::query()
            ->where('student_batch_enrollment_id', $enrollment->getKey())
            ->where('status', CertificateStatus::Issued->value)
            ->first(['id', 'certificate_number']);

        return $existing === null
            ? EligibilityRule::pass('no_live_certificate', 'No certificate has been issued for this enrolment.')
            : EligibilityRule::fail(
                'no_live_certificate',
                sprintf(
                    'Certificate %s has already been issued for this enrolment. Revoke it before issuing another.',
                    (string) $existing->getAttribute('certificate_number'),
                ),
            );
    }
}
