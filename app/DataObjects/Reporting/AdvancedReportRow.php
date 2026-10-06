<?php

declare(strict_types=1);

namespace App\DataObjects\Reporting;

use App\Enums\AdmissionStage;
use App\Enums\ReportPaymentStatus;
use App\Enums\ReportStudentStatus;
use App\Support\Money;

/**
 * One Advanced Reports row: one admission — a student on one course (D172, D177).
 *
 * A student on two courses is two rows, because the course, the batch, the dates, the progress and
 * every fee figure belong to the admission, not to the person; a per-student row would have to pick
 * one course and quietly drop the other.
 *
 * Built only by `AdvancedStudentReportService`, from the one query that also feeds the summary and
 * every export, so the screen, the CSV and the PDF read the same values. Dates are `Y-m-d` calendar
 * strings (render with `app_date()`); money is a `Money` string, and **the four money properties are
 * null — not zero — when the viewer may not see money**, because the query never selected them
 * (INV-23-2: a withheld column is absent, never blank).
 */
final readonly class AdvancedReportRow
{
    public function __construct(
        public int $admissionId,
        public string $admissionNumber,
        public int $studentId,
        public string $studentCode,
        public string $studentName,
        public ?string $registrationNumber,
        public ?string $phone,
        public ?string $email,
        public int $courseId,
        public string $courseName,
        public ?string $courseCode,
        /** `Course::durationLabel()` — "12 weeks" — or null when the course states no duration. */
        public ?string $courseDuration,
        /** `COALESCE(admission.batch_id, latest enrolment's batch)`. */
        public ?int $batchId,
        public ?string $batchCode,
        public ?string $batchName,
        /** `student_admissions.admission_date` — the date the period filter reads. */
        public string $joiningDate,
        /** Actual completion when there is one, otherwise the batch's planned end. */
        public ?string $completionDate,
        /** True when `completionDate` is the batch's planned end rather than a recorded completion. */
        public bool $completionIsExpected,
        /** Course progress, '0.00'–'100.00'; a completed admission is 100 whatever the tracker says. */
        public string $progress,
        public AdmissionStage $stage,
        public ReportStudentStatus $status,
        public ReportPaymentStatus $payment,
        /** How many live charges are stored as overdue — a count, so it is shown to everybody. */
        public int $overdueCharges,
        /** Was money selected at all? False means the four figures below are withheld. */
        public bool $moneyVisible,
        /** Σ net of live charges (`charged_amount`): the final fee after discounts. */
        public ?string $totalFees = null,
        /** Net received: paid less refunded. */
        public ?string $paid = null,
        /** Signed balance; negative is an advance. Total − Paid = Remaining. */
        public ?string $remaining = null,
        /** Σ balance of overdue charges still owing. */
        public ?string $overdueAmount = null,
    ) {}

    /**
     * "CODE — Name", the shape `Batch::label()` writes; just the one that exists when the other is
     * missing; null with no batch.
     *
     * Joined rather than trimmed: `trim()` works on bytes, and stripping the em dash's bytes off the
     * ends of a label would cut the last byte off a name ending in, say, the Urdu full stop.
     */
    public function batchLabel(): ?string
    {
        $parts = array_filter([$this->batchCode, $this->batchName], static fn (?string $part): bool => $part !== null && $part !== '');

        return $parts === [] ? null : implode(' — ', $parts);
    }

    /** A negative remaining balance is money held in advance, never a debt with a minus sign. */
    public function isInAdvance(): bool
    {
        return $this->remaining !== null && Money::isNegative($this->remaining);
    }
}
