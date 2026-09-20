<?php

declare(strict_types=1);

namespace App\DataObjects\Collaborator;

/**
 * What a bulk approve or reject did, and — as importantly — what it declined to do
 * ([D-IMP-6], phase-10-12 §6.3).
 *
 * A row whose status moved between the page loading and the button being pressed is **skipped and
 * reported**, never forced. The alternative is a bulk action that quietly overrides a decision
 * somebody else made thirty seconds earlier, which is the one thing a bulk action must not do.
 *
 * `$skipped` maps entry id to the sentence explaining it, so the toast can name the rows rather than
 * saying "3 were skipped".
 */
final readonly class BulkResult
{
    /**
     * @param  list<int>  $changed
     * @param  array<int, string>  $skipped
     */
    public function __construct(
        public array $changed,
        public array $skipped,
        public string $total,
    ) {}

    public function count(): int
    {
        return count($this->changed);
    }

    public function skippedCount(): int
    {
        return count($this->skipped);
    }
}
