<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

/**
 * What `AssignmentGradeCalculator` worked out (phase-19-23 §6.8).
 *
 * Returned as one object rather than written straight onto the model, so the calculator stays pure and
 * PH19-33 can compare its answer with the database's generated column without a row existing at all.
 *
 * `percentage` and `isPassed` are both legitimately null and mean different things: no percentage
 * because the total was zero, and no verdict because the assignment has no pass line. Neither is "0"
 * and neither is "false".
 */
final readonly class GradeOutcome
{
    public function __construct(
        public string $obtainedMarks,
        public string $penaltyMarks,
        public string $finalMarks,
        public ?string $percentage,
        public ?bool $isPassed,
    ) {}

    /**
     * The columns a grading write sets. `final_marks` is deliberately absent: it is a generated STORED
     * column, and an UPDATE naming it is rejected by the server.
     *
     * @return array<string, string|bool|null>
     */
    public function toColumns(): array
    {
        return [
            'obtained_marks' => $this->obtainedMarks,
            'penalty_marks' => $this->penaltyMarks,
            'percentage' => $this->percentage,
            'is_passed' => $this->isPassed,
        ];
    }

    /** Whether lateness actually cost anything — the grading screen says so only when it did. */
    public function wasPenalised(): bool
    {
        return bccomp($this->penaltyMarks, '0.00', 2) > 0;
    }
}
