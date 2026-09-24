<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\DataObjects\Search\SearchHit;
use App\DataObjects\Search\SearchResults;
use App\Enums\SearchEntityType;
use App\Models\User;
use App\Search\Contracts\SearchProvider;
use App\Support\GlobalSearchRegistry;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The §108 palette (phase-19-23 §6.23).
 *
 * **One broken provider must not blank the palette.** Each is wrapped in its own try/catch: a
 * provider that throws is logged, reported to the caller as *unavailable by name*, and the other
 * ten still return their hits. The alternative — one exception taking the whole search down — turns
 * a missing column in an unrelated module into a search box that does nothing, and nobody
 * diagnosing that would think to look at invoices.
 *
 * Naming the unavailable entity matters as much as surviving it. A palette that quietly omitted a
 * whole kind of record would have somebody concluding their student does not exist.
 *
 * **Results are never cached across users** (§6.23). Two people searching the same word are running
 * eleven different scoped queries, and a shared cache entry would hand the narrower viewer the
 * wider answer. There is no cache here at all — the per-entity limit keeps each query small, and
 * the endpoint is throttled instead.
 *
 * **`recentFor()` reads `users.preferences`** — no new table, no server-side search history. What
 * somebody searched for is their business; the only thing kept is which records they opened, on
 * their own row, and it is capped at five.
 */
final class GlobalSearchService
{
    /** How many recent hits are remembered per user. */
    private const RECENT_LIMIT = 5;

    /** Where they live on `users.preferences`. */
    private const RECENT_KEY = 'recent_search_hits';

    /**
     * Search everything this person may search.
     *
     * @param  list<string>  $types  narrow to these entity values; empty means all of them
     */
    public function search(string $term, User $viewer, array $types = [], ?int $limit = null): SearchResults
    {
        $term = trim($term);
        $minimum = max(1, (int) setting('reports.global_search_min_chars', 2));

        // Not a search that found nothing — a search that has not started. The palette says so.
        if (mb_strlen($term) < $minimum) {
            return SearchResults::tooShort($term, $minimum);
        }

        $limit ??= max(1, (int) setting('reports.global_search_per_entity_limit', 5));

        $providers = GlobalSearchRegistry::availableTo($viewer);

        if ($types !== []) {
            $providers = $providers->filter(
                static fn (SearchProvider $p): bool => in_array($p->type()->value, $types, true),
            );
        }

        $groups = [];
        $unavailable = [];

        foreach ($providers as $provider) {
            try {
                // One query per provider. A provider that lazy-loads inside present() breaks that
                // promise eleven times a keystroke, which is why present() is handed an
                // already-eager-loaded row.
                $models = $provider->query($term, $viewer, $limit);

                if ($models->isEmpty()) {
                    continue;
                }

                $hits = [];

                foreach ($models as $model) {
                    $hits[] = $provider->present($model, $viewer);
                }

                $groups[$provider->type()->value] = $hits;
            } catch (Throwable $exception) {
                // Logged, named, survived — see the class note.
                Log::warning('A global search provider failed and was skipped.', [
                    'provider' => $provider::class,
                    'entity' => $provider->type()->value,
                    'exception' => $exception->getMessage(),
                ]);

                report($exception);

                $unavailable[] = $provider->type()->label();
            }
        }

        return new SearchResults(
            term: $term,
            groups: $groups,
            unavailable: $unavailable,
            minimumCharacters: $minimum,
        );
    }

    /**
     * The document-number fast path: paste a number, land on the record.
     *
     * **It searches only providers this viewer may already search**, and each provider's exact
     * lookup runs inside that provider's own scope. Pasting a number must never reach a record the
     * same person could not have found by typing a name — otherwise a document number becomes a
     * capability, and they are printed on things that leave the building.
     */
    public function exactMatch(string $term, User $viewer): ?SearchHit
    {
        $term = trim($term);

        if ($term === '') {
            return null;
        }

        foreach (GlobalSearchRegistry::availableTo($viewer) as $provider) {
            if ($provider->exactColumns() === []) {
                continue;
            }

            try {
                $hit = method_exists($provider, 'exact') ? $provider->exact($term, $viewer) : null;
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * The last five records this person opened from the palette.
     *
     * @return list<array<string, mixed>>
     */
    public function recentFor(User $viewer): array
    {
        $preferences = $viewer->getAttribute('preferences');
        $recent = is_array($preferences) ? ($preferences[self::RECENT_KEY] ?? []) : [];

        if (! is_array($recent)) {
            return [];
        }

        return array_values(array_slice(array_filter($recent, 'is_array'), 0, self::RECENT_LIMIT));
    }

    /**
     * Remember that this person opened this record.
     *
     * Stored on the user's own row, capped, and de-duplicated by type+id so opening the same record
     * twice moves it to the front rather than filling the list with it.
     */
    public function remember(User $viewer, SearchHit $hit): void
    {
        $preferences = $viewer->getAttribute('preferences');
        $preferences = is_array($preferences) ? $preferences : [];

        $entry = [
            'type' => $hit->type->value,
            'id' => $hit->id,
            'title' => $hit->title,
            'subtitle' => $hit->subtitle,
            'url' => $hit->url,
        ];

        $existing = array_values(array_filter(
            (array) ($preferences[self::RECENT_KEY] ?? []),
            static fn (mixed $row): bool => is_array($row)
                && ! (($row['type'] ?? null) === $entry['type'] && (string) ($row['id'] ?? '') === (string) $entry['id']),
        ));

        $preferences[self::RECENT_KEY] = array_slice([$entry, ...$existing], 0, self::RECENT_LIMIT);

        // `saveQuietly`: opening a search result is not an event anybody needs an activity row for,
        // and logging it would write one line per click to the table §106 has to stay readable.
        $viewer->forceFill(['preferences' => $preferences])->saveQuietly();
    }

    /**
     * Clear this person's recent list. Theirs to clear, so no permission is involved.
     */
    public function forgetRecent(User $viewer): void
    {
        $preferences = $viewer->getAttribute('preferences');
        $preferences = is_array($preferences) ? $preferences : [];

        unset($preferences[self::RECENT_KEY]);

        $viewer->forceFill(['preferences' => $preferences])->saveQuietly();
    }

    /**
     * What the palette tells somebody it is searching — the entity names and their columns.
     *
     * Rendered as a hint, so a person who typed a phone number and got nothing can see whether
     * phone was ever among the columns rather than concluding the record is missing.
     *
     * @return list<array<string, mixed>>
     */
    public function describeFor(User $viewer): array
    {
        return GlobalSearchRegistry::availableTo($viewer)
            ->map(static fn (SearchProvider $p): array => [
                'type' => $p->type()->value,
                'label' => $p->type()->label(),
                'icon' => $p->type()->icon(),
                'color' => $p->type()->color(),
                'columns' => array_map(
                    static fn (string $c): string => ucfirst(str_replace('_', ' ', last(explode('.', $c)))),
                    $p->columns(),
                ),
            ])
            ->values()
            ->all();
    }

    /** Every entity type, for the settings screen and the filter chips. */
    public function entityTypes(): array
    {
        return SearchEntityType::cases();
    }
}
