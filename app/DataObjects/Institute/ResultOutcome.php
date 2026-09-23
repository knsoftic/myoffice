<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

use App\Enums\ExamAttendanceStatus;

/**
 * What `ResultCalculator` worked out for one student (phase-19-23 §6.10, INV-20-2).
 *
 * **Every nullable field here means something specific, and none of them means zero.** A student who
 * did not sit the exam has no marks, no percentage, no grade and no verdict — four nulls that together
 * say "not assessed". Collapsing any of them to a zero or a false would hand somebody a grade for an
 * exam they never took, and `chk_er_absent` refuses to store it anyway.
 *
 * Returned as an object rather than written straight onto the row so the calculator stays pure: a test
 * can run it a hundred times without a database, which is what makes INV-20-2 checkable.
 */
final readonly class ResultOutcome
{
    public function __construct(
        public ExamAttendanceStatus $attendance,
        public ?string $obtainedMarks,
        public ?string $percentage,
        public ?string $grade,
        public ?string $gradePoint,
        public ?int $gradeScaleBandId,
        public ?bool $isPassed,
    ) {}

    /**
     * Absent, exempt or debarred: nothing was assessed, so nothing is claimed.
     *
     * `isPassed` is **false rather than null** for the two statuses the student is answerable for, and
     * null for an exemption — because "did not pass" and "was not assessed" are different answers, and
     * a report that treated an excused absence as a failure would be wrong about the student.
     */
    public static function notAssessed(ExamAttendanceStatus $attendance): self
    {
        return new self(
            attendance: $attendance,
            obtainedMarks: null,
            percentage: null,
            grade: null,
            gradePoint: null,
            gradeScaleBandId: null,
            isPassed: $attendance->countsAsFail() ? false : null,
        );
    }

    /**
     * The columns an entry writes. `position_in_batch` is absent on purpose: a rank is a property of
     * the whole sheet, not of one row, and is written when the exam publishes.
     *
     * @return array<string, mixed>
     */
    public function toColumns(): array
    {
        return [
            'attendance_status' => $this->attendance->value,
            'obtained_marks' => $this->obtainedMarks,
            'percentage' => $this->percentage,
            'grade' => $this->grade,
            'grade_point' => $this->gradePoint,
            'grade_scale_band_id' => $this->gradeScaleBandId,
            'is_passed' => $this->isPassed,
        ];
    }

    public function wasAssessed(): bool
    {
        return $this->obtainedMarks !== null;
    }

    /** Counted in the class figures — everything except an exemption (§2.12). */
    public function countsInDenominator(): bool
    {
        return $this->attendance->countsInDenominator();
    }

    /**
     * A result the calculator could not grade: it has a percentage but no band matched it.
     *
     * INV-20-3 makes this unreachable for a scale that passed validation, so the marking screen shows
     * it as a warning rather than swallowing it — a result with no grade means a scale has a hole, and
     * that is worth somebody's attention before thirty more rows are entered against it.
     */
    public function isUngraded(): bool
    {
        return $this->percentage !== null && $this->grade === null;
    }
}
