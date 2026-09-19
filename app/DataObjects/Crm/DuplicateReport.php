<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\Enums\LeadDuplicateMatchType;

/**
 * What `LeadDuplicateDetector` found (phase-05 §6.2): at most ten matches, newest first.
 *
 * Duplicates are warned about, never blocked by a constraint (D29). `hasExactMatch()` drives the optional
 * `crm.duplicate_block_on_exact` refusal; a trashed lead never blocks (test 25).
 */
final readonly class DuplicateReport
{
    /**
     * @param  list<DuplicateMatch>  $matches
     */
    public function __construct(
        public array $matches = [],
        public bool $enabled = true,
    ) {}

    public static function none(bool $enabled = true): self
    {
        return new self([], $enabled);
    }

    public function isEmpty(): bool
    {
        return $this->matches === [];
    }

    public function count(): int
    {
        return count($this->matches);
    }

    /**
     * An exact match on a live (non-trashed) record.
     */
    public function hasExactMatch(): bool
    {
        foreach ($this->matches as $match) {
            if ($match->isExact() && ! $match->isTrashed) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first visible, live lead match — what an import's `import_and_flag` links to.
     */
    public function firstVisibleLead(): ?DuplicateMatch
    {
        foreach ($this->matches as $match) {
            if ($match->recordType === DuplicateMatch::RECORD_LEAD && ! $match->restricted && ! $match->isTrashed && $match->id !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * The first live lead match, visible or not — for system callers (an import) that act on the id and never
     * render it.
     */
    public function firstLeadMatch(): ?DuplicateMatch
    {
        foreach ($this->matches as $match) {
            if ($match->recordType === DuplicateMatch::RECORD_LEAD && ! $match->isTrashed && $match->id !== null) {
                return $match;
            }
        }

        return null;
    }

    public function firstMatchType(): ?LeadDuplicateMatchType
    {
        return $this->matches[0]->matchType ?? null;
    }

    /**
     * @return list<DuplicateMatch>
     */
    public function clientMatches(): array
    {
        return array_values(array_filter(
            $this->matches,
            static fn (DuplicateMatch $match): bool => $match->recordType !== DuplicateMatch::RECORD_LEAD,
        ));
    }

    /**
     * @return array{enabled: bool, count: int, has_exact: bool, matches: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'count' => $this->count(),
            'has_exact' => $this->hasExactMatch(),
            'matches' => array_map(static fn (DuplicateMatch $match): array => $match->toArray(), $this->matches),
        ];
    }
}
