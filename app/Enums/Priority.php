<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * One priority scale for the whole system (phase-06 §3).
 *
 * §20's project priority, §22's task priority and Phase 22's ticket priority all cast to **this** enum —
 * a second declaration would be a merge conflict, not a style choice (§1.3).
 *
 * {@see weight()} is the sort key: higher is more urgent, so a board or list sorts `weight()` descending.
 */
enum Priority: string
{
    use HasOptions;

    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
            self::Urgent => 'Urgent',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Low => 'slate',
            self::Medium => 'sky',
            self::High => 'amber',
            self::Urgent => 'rose',
        };
    }

    /**
     * Sort key — higher is more urgent.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Urgent => 4,
        };
    }

    /**
     * Does this priority get called out in a list or a digest?
     */
    public function isElevated(): bool
    {
        return $this->weight() >= self::High->weight();
    }

    /**
     * Every priority, most urgent first.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        $cases = self::cases();

        usort($cases, static fn (self $left, self $right): int => $right->weight() <=> $left->weight());

        return $cases;
    }
}
