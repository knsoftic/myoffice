<?php

declare(strict_types=1);

namespace App\Support\Cms\Sitemap;

use App\Enums\Cms\RobotsDirective;
use App\Enums\Cms\SitemapChangeFrequency;
use App\Services\Cms\Data\SitemapEntry;
use App\Support\Format;
use App\Support\Modules;
use App\Support\SettingsRepository;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * One phase-04 entity's contribution to `sitemap.xml` (phase-04 §2 rule 1, §13 Phase 3 row, D23).
 *
 * Phase 3's `SitemapGenerator` accepts any object with `urls(): iterable` and `key(): string` (the
 * `SitemapUrlProvider` shape of phase-03 §6.5), registered through `SitemapRegistry::register()` — or
 * `SitemapGenerator::extend()` until the registry class exists. A provider never edits the generator.
 *
 * Every provider applies the same rules Phase 3 applies to its own pages:
 *
 *   · only rows the public site would render (each subclass constrains the query exactly like the
 *     model's `scopePublic()`), never trashed;
 *   · a row whose `seo_meta` says `sitemap_include = false` or a non-indexable `robots` is left out; a row
 *     with no `seo_meta` yet is included with the defaults;
 *   · `canonical_url` wins over the route URL; `sitemap_changefreq` / `sitemap_priority` override the
 *     provider's defaults;
 *   · nothing at all while the owning module is disabled or the public route is not registered — a
 *     disabled module leaves no trace, and a missing route never throws.
 */
abstract class EntitySitemapProvider
{
    abstract public function key(): string;

    /** The module whose switch governs these URLs. */
    abstract protected function module(): string;

    abstract protected function table(): string;

    /** The `seoable_type` of the rows, or null when the entity has no `seo_meta` (tags). */
    abstract protected function morphClass(): ?string;

    abstract protected function routeName(): string;

    abstract protected function routeParameter(): string;

    /**
     * Narrow `$query` (the entity aliased `e`) to publicly visible rows.
     */
    abstract protected function constrain(Builder $query): void;

    protected function changefreq(): SitemapChangeFrequency
    {
        return SitemapChangeFrequency::Weekly;
    }

    protected function priority(): string
    {
        return '0.6';
    }

    /**
     * A second timestamp that counts as "last modified" besides `updated_at` (e.g. `published_at`).
     */
    protected function lastmodColumn(): ?string
    {
        return null;
    }

    /**
     * Extra switches beyond the module (e.g. `website.careers_enabled`).
     */
    protected function enabled(): bool
    {
        return true;
    }

    /**
     * @return iterable<int, SitemapEntry>
     */
    public function urls(): iterable
    {
        if (! Modules::enabled($this->module()) || ! $this->enabled() || ! Route::has($this->routeName())) {
            return [];
        }

        $query = DB::table($this->table().' as e')->whereNull('e.deleted_at');
        $columns = ['e.id', 'e.slug', 'e.updated_at'];

        if ($this->lastmodColumn() !== null) {
            $columns[] = 'e.'.$this->lastmodColumn().' as lastmod_extra';
        }

        $morph = $this->morphClass();

        if ($morph !== null) {
            $indexable = array_values(array_map(
                static fn (RobotsDirective $directive): string => $directive->value,
                array_filter(RobotsDirective::cases(), static fn (RobotsDirective $directive): bool => $directive->isIndexable()),
            ));

            $query->leftJoin('seo_meta as s', static function ($join) use ($morph): void {
                $join->on('s.seoable_id', '=', 'e.id')->where('s.seoable_type', '=', $morph);
            })->where(static function ($where) use ($indexable): void {
                $where->whereNull('s.id')->orWhere(static function ($included) use ($indexable): void {
                    $included->where('s.sitemap_include', true)->whereIn('s.robots', $indexable);
                });
            });

            array_push($columns, 's.canonical_url', 's.sitemap_changefreq', 's.sitemap_priority');
        }

        $this->constrain($query);

        $entries = [];

        foreach ($query->orderBy('e.id')->select($columns)->cursor() as $row) {
            $entry = $this->entry($row);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * The business "today" as a date string (the public scopes compare deadlines against it).
     */
    protected function today(): string
    {
        try {
            return Carbon::now(Format::timezone())->toDateString();
        } catch (Throwable) {
            return Carbon::now()->toDateString();
        }
    }

    protected function settingEnabled(string $key, bool $default = true): bool
    {
        try {
            $value = app(SettingsRepository::class)->get($key, $default);
        } catch (Throwable) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private function entry(object $row): ?SitemapEntry
    {
        $slug = trim((string) $row->slug);

        if ($slug === '') {
            return null;
        }

        try {
            $canonical = trim((string) ($row->canonical_url ?? ''));
            $loc = $canonical !== '' && preg_match('~^https?://~i', $canonical) === 1
                ? $canonical
                : route($this->routeName(), [$this->routeParameter() => $slug]);

            return new SitemapEntry(
                loc: $loc,
                lastmod: $this->latest($row->updated_at ?? null, $row->lastmod_extra ?? null),
                changefreq: SitemapChangeFrequency::tryFrom((string) ($row->sitemap_changefreq ?? '')) ?? $this->changefreq(),
                priority: isset($row->sitemap_priority) && $row->sitemap_priority !== null ? (string) $row->sitemap_priority : $this->priority(),
                provider: $this->key(),
            );
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function latest(mixed $first, mixed $second): ?Carbon
    {
        $moments = [];

        foreach ([$first, $second] as $value) {
            if ($value !== null && $value !== '') {
                try {
                    $moments[] = Carbon::parse((string) $value, 'UTC');
                } catch (Throwable) {
                    continue;
                }
            }
        }

        if ($moments === []) {
            return null;
        }

        usort($moments, static fn (Carbon $a, Carbon $b): int => $b->getTimestamp() <=> $a->getTimestamp());

        return $moments[0]->isFuture() ? Carbon::now() : $moments[0];
    }
}
