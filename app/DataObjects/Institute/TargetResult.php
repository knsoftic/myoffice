<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

use App\Enums\MaterialTargetType;

/**
 * What `CourseMaterialService::setTargets()` changed (phase-19-23 §6.5).
 *
 * Returned rather than inferred, because the toast has to say what happened and "targets updated" is
 * not what a teacher who just removed a batch needs to read. `addedNotYetNotified` is separate from
 * `added` for the same reason `notified_at` exists: re-aiming a material tells the new audience and
 * leaves the old one alone.
 */
final readonly class TargetResult
{
    /**
     * @param  list<string>  $skipped  human-readable notes about targets that were left as they were
     */
    public function __construct(
        public int $added = 0,
        public int $removed = 0,
        public int $unchanged = 0,
        public int $addedNotYetNotified = 0,
        public ?MaterialTargetType $scope = null,
        public array $skipped = [],
    ) {}

    public function changed(): bool
    {
        return $this->added > 0 || $this->removed > 0;
    }

    /** One sentence for the toast. */
    public function summary(): string
    {
        if (! $this->changed()) {
            return 'The audience is unchanged.';
        }

        $parts = [];

        if ($this->added > 0) {
            $parts[] = $this->added.' added';
        }

        if ($this->removed > 0) {
            $parts[] = $this->removed.' removed';
        }

        return 'Audience updated — '.implode(', ', $parts).'.';
    }
}
