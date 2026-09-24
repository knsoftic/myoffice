<?php

declare(strict_types=1);

namespace App\DataObjects\Search;

use App\Enums\SearchEntityType;

/**
 * Everything one search turned up (phase-19-23 §6.23).
 *
 * **`unavailable` is not an error list, it is part of the answer.** A provider that threw is
 * caught, logged and named here rather than allowed to blank the palette — so a search that could
 * not reach the invoices table still returns the students it found, and says that invoices were not
 * searched. The alternative, a palette that silently omits a whole entity, is a person concluding
 * their record does not exist.
 *
 * **`tooShort` is likewise an answer, not a failure.** Typing one character is not a search that
 * found nothing; it is a search that has not started. The palette says so rather than showing
 * "no results", which would be a lie the moment the second character arrives.
 */
final readonly class SearchResults
{
    /**
     * @param  array<string, list<SearchHit>>  $groups  entity value => its hits, already in weight order
     * @param  list<string>  $unavailable  labels of entities that could not be searched
     */
    public function __construct(
        public string $term,
        public array $groups = [],
        public array $unavailable = [],
        public bool $tooShort = false,
        public int $minimumCharacters = 2,
    ) {}

    public static function tooShort(string $term, int $minimum): self
    {
        return new self(term: $term, tooShort: true, minimumCharacters: $minimum);
    }

    /** How many hits in total, across every entity. */
    public function count(): int
    {
        return array_sum(array_map('count', $this->groups));
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /**
     * Every hit, flattened, still in group order.
     *
     * @return list<SearchHit>
     */
    public function all(): array
    {
        return array_merge(...array_values($this->groups)) ?: [];
    }

    /**
     * The first hit, or null — what the palette highlights so Enter opens something sensible.
     */
    public function first(): ?SearchHit
    {
        foreach ($this->groups as $hits) {
            if ($hits !== []) {
                return $hits[0];
            }
        }

        return null;
    }

    /** Did anything match that the viewer cannot actually open? Used to explain a link-less row. */
    public function hasUnlinkedHits(): bool
    {
        foreach ($this->all() as $hit) {
            if (! $hit->isLinked()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $groups = [];

        foreach ($this->groups as $type => $hits) {
            $entity = SearchEntityType::tryFrom((string) $type);

            $groups[] = [
                'type' => $type,
                'label' => $entity?->label() ?? (string) $type,
                'icon' => $entity?->icon(),
                'color' => $entity?->color(),
                'count' => count($hits),
                'hits' => array_map(static fn (SearchHit $h): array => $h->toArray(), $hits),
            ];
        }

        return [
            'term' => $this->term,
            'too_short' => $this->tooShort,
            'minimum_characters' => $this->minimumCharacters,
            'count' => $this->count(),
            'groups' => $groups,
            'unavailable' => $this->unavailable,
        ];
    }
}
