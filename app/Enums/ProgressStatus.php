<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How far along one thing is (§83, phase-14-17 §2.30.10).
 *
 * One enum across four tables — batch coverage, and the topic, module and course rows of a student's
 * progress — because "half covered" means the same thing at every level, and four enums saying it
 * would drift the first time somebody added a state to one of them.
 *
 * **`skipped` is not `completed`, and it is not `pending` either.** A coordinator who drops a topic
 * from a batch is saying it will never be taught, so its weight leaves the denominator entirely: the
 * percentage *rises*, which is what a person expects when they remove work from a syllabus. A skipped
 * topic counted as done would inflate the number; counted as outstanding it would stall it for ever.
 */
enum ProgressStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Not started',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Skipped => 'Skipped',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'slate',
            self::InProgress => 'amber',
            self::Completed => 'emerald',
            self::Skipped => 'violet',
        };
    }

    /** The glyph the progress matrix draws, so a cell reads without colour (§8.17). */
    public function glyph(): string
    {
        return match ($this) {
            self::Pending => '·',
            self::InProgress => '◐',
            self::Completed => '●',
            self::Skipped => '—',
        };
    }

    public function isDone(): bool
    {
        return $this === self::Completed;
    }

    /**
     * Does this topic's weight belong in the denominator? A skipped one does not — that is the whole
     * point of skipping it, and the single place that rule lives.
     */
    public function countsInDenominator(): bool
    {
        return $this !== self::Skipped;
    }

    /**
     * The status a percentage implies. §2.30.10's transitions are exactly this function, so a stored
     * status can never disagree with the number beside it.
     */
    public static function forPercentage(string $percentage): self
    {
        if (bccomp($percentage, '0', 4) <= 0) {
            return self::Pending;
        }

        return bccomp($percentage, '100', 4) >= 0 ? self::Completed : self::InProgress;
    }
}
