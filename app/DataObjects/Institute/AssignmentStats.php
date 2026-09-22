<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

use App\Support\Money;

/**
 * One assignment's numbers (phase-19-23 §6.7).
 *
 * **One definition, read by the teacher screen and by Phase 23's report.** Two screens each computing
 * "how many handed in" is how they end up disagreeing in front of the person who has to explain the
 * difference — which is the D107 shape, and the reason this is an object rather than a query written
 * twice.
 *
 * `outstanding` is derived rather than stored: it is whoever was expected and has neither submitted nor
 * been marked missed, which is the number a teacher chasing work actually wants.
 */
final readonly class AssignmentStats
{
    public function __construct(
        public int $expected,
        public int $submitted,
        public int $late,
        public int $graded,
        public int $missed,
        public ?string $averageMarks,
        public ?string $highestMarks,
        public string $totalMarks,
    ) {}

    /** Expected, minus those who handed in, minus those already marked missed. Never negative. */
    public function outstanding(): int
    {
        return max(0, $this->expected - $this->submitted - $this->missed);
    }

    /** Still to mark. */
    public function ungraded(): int
    {
        return max(0, $this->submitted - $this->graded);
    }

    /** What proportion of the roster handed something in, as a `decimal(8,2)` string. */
    public function submissionRate(): ?string
    {
        return $this->expected < 1 ? null : Money::percentageOf((string) $this->submitted, (string) $this->expected, 2);
    }

    /** The class average as a percentage of the total — comparable across assignments, unlike the mark. */
    public function averagePercentage(): ?string
    {
        if ($this->averageMarks === null || Money::compare($this->totalMarks, '0.00') <= 0) {
            return null;
        }

        return Money::percentageOf($this->averageMarks, $this->totalMarks, 2);
    }

    public function anythingToShow(): bool
    {
        return $this->expected > 0 || $this->submitted > 0;
    }
}
