<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\Concerns;

use App\Enums\Cms\SectionPlacement;
use App\Models\Cms\Page;
use App\Models\Cms\WebsiteSection;
use App\Services\Cms\CacheVersion;
use App\Services\Cms\Data\SeoPayload;
use App\Services\Cms\SectionService;
use App\Support\Cms\SectionRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * How a public controller composes a page out of section snapshots (phase-03 §6.9, §8.14, D22).
 *
 *   · **Published reads only (INV-1).** The live path is `WebsiteSection::forPublic()` — enabled,
 *     `status = published`, `published_content` present, not trashed, snapshot columns only, ordered —
 *     one query per placement. The draft column is never selected.
 *   · **Version-stamped cache (INV-8).** The rows of a placement are remembered under
 *     `CacheVersion::remember()`, so any publish makes every entry unreachable at once. Preview never
 *     reads or writes this cache (INV-9).
 *   · **Never a 500 from content (INV-2).** A row is omitted — with one `Log::warning` per section per
 *     request — when its `section_key` is not in the registry, its snapshot is not the shape
 *     `SnapshotBuilder` writes, its partial does not exist, or the partial throws while rendering. The
 *     render is probed here so the page view can `@include` the partials it is handed without risk.
 *   · **Preview is explicit and authorised.** `?preview=1` renders drafts only for a user holding the
 *     area's `view` permission; anyone else gets the live page. Preview responses are `no-store` and
 *     `noindex, nofollow` (INV-9).
 *
 * The page view receives `$site` (the §6.9 `SitePayload` fields: `header`, `footer`, `sections`, `seo`,
 * `page`, `isPreview`, `bodyClass`). Each section entry is the snapshot array plus the partial's
 * variables of integration K.3: `fields`, `items`, `media`, `cta`, `menus`, `faqs`, and `view`.
 */
trait ComposesSite
{
    /** @var array<string, true> sections already warned about in this request */
    private array $warned = [];

    /**
     * The live sections of one placement, validated and render-probed.
     *
     * @return list<array<string, mixed>>
     */
    protected function liveSections(SectionPlacement $placement, ?Page $page = null): array
    {
        $rows = app(CacheVersion::class)->remember(
            'sections',
            [$placement->value, $page?->getKey() ?? 0],
            $this->cacheSeconds(),
            static fn (): array => WebsiteSection::query()
                ->forPublic($placement, $page)
                ->get()
                ->map(static fn (WebsiteSection $section): array => [
                    'id' => (int) $section->getKey(),
                    'section_key' => (string) $section->section_key,
                    'anchor' => $section->anchor,
                    'sort_order' => (int) $section->sort_order,
                    'snapshot' => $section->published_content,
                ])
                ->values()
                ->all(),
        );

        $sections = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $entry = is_array($row) ? $this->usableSection($row) : null;

            if ($entry !== null) {
                $sections[] = $entry;
            }
        }

        return $sections;
    }

    /**
     * The draft payloads of one placement, for an authorised preview (§6.12). Enabled, non-trashed
     * sections of any status, in order; never cached.
     *
     * @return list<array<string, mixed>>
     */
    protected function draftSections(SectionPlacement $placement, ?Page $page = null): array
    {
        $sections = [];

        $models = WebsiteSection::query()
            ->forPlacement($placement, $page)
            ->enabled()
            ->ordered()
            ->get();

        foreach ($models as $model) {
            $entry = $this->draftSection($model);

            if ($entry !== null) {
                $sections[] = $entry;
            }
        }

        return $sections;
    }

    /**
     * One section's draft payload, or null when it cannot be rendered.
     *
     * @return array<string, mixed>|null
     */
    protected function draftSection(WebsiteSection $section): ?array
    {
        try {
            $payload = app(SectionService::class)->draftPayload($section);
        } catch (Throwable $exception) {
            $this->warn((int) $section->getKey(), (string) $section->section_key, 'draft payload could not be built', $exception);

            return null;
        }

        return $this->usableSection([
            'id' => (int) $section->getKey(),
            'section_key' => (string) $section->section_key,
            'anchor' => $section->anchor,
            'sort_order' => (int) $section->sort_order,
            'snapshot' => $payload,
        ]);
    }

    /**
     * The global header and footer (§6.9 `header()` / `footer()`): live, or drafts in preview.
     *
     * @return array{header: array<string, mixed>|null, footer: array<string, mixed>|null}
     */
    protected function chrome(bool $draft = false): array
    {
        if ($draft) {
            return [
                'header' => $this->draftSections(SectionPlacement::GlobalHeader)[0] ?? null,
                'footer' => $this->draftSections(SectionPlacement::GlobalFooter)[0] ?? null,
            ];
        }

        // One read for both global placements (FT-27: a cold page stays within its query budget).
        $rows = app(CacheVersion::class)->remember(
            'sections',
            ['chrome', 0],
            $this->cacheSeconds(),
            static fn (): array => WebsiteSection::query()
                ->where(static function ($query): void {
                    $query->where(static fn ($header) => $header->forPlacement(SectionPlacement::GlobalHeader))
                        ->orWhere(static fn ($footer) => $footer->forPlacement(SectionPlacement::GlobalFooter));
                })
                ->visible()
                ->ordered()
                ->publishedSnapshot()
                ->get()
                ->map(static fn (WebsiteSection $section): array => [
                    'id' => (int) $section->getKey(),
                    'placement' => $section->placement instanceof SectionPlacement ? $section->placement->value : (string) $section->placement,
                    'section_key' => (string) $section->section_key,
                    'anchor' => $section->anchor,
                    'sort_order' => (int) $section->sort_order,
                    'snapshot' => $section->published_content,
                ])
                ->values()
                ->all(),
        );

        $chrome = ['header' => null, 'footer' => null];
        $slots = [SectionPlacement::GlobalHeader->value => 'header', SectionPlacement::GlobalFooter->value => 'footer'];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $slot = is_array($row) ? ($slots[$row['placement'] ?? ''] ?? null) : null;

            if ($slot === null || $chrome[$slot] !== null) {
                continue;
            }

            $chrome[$slot] = $this->usableSection($row);
        }

        return $chrome;
    }

    /**
     * The `$site` object every public view receives.
     *
     * @param  array{header: array<string, mixed>|null, footer: array<string, mixed>|null}  $chrome
     * @param  list<array<string, mixed>>  $sections
     * @param  array<string, mixed>|null  $page
     */
    protected function sitePayload(array $chrome, array $sections, SeoPayload $seo, ?array $page, bool $preview, string $bodyClass): stdClass
    {
        return (object) [
            'header' => $chrome['header'],
            'footer' => $chrome['footer'],
            'sections' => $sections,
            'seo' => $seo,
            'page' => $page,
            'isPreview' => $preview,
            'bodyClass' => $bodyClass,
        ];
    }

    /**
     * Does this request ask for — and may it see — draft content? (`?preview=1`, §6.12.)
     *
     * The permission, never the login: a student or client signed in is an ordinary visitor (§9).
     */
    protected function previewRequested(Request $request, string $permission): bool
    {
        return $request->query('preview') === '1'
            && $request->user()?->can($permission) === true;
    }

    /**
     * The robots header on every public page, and the no-store / noindex pair on a preview (INV-9).
     */
    protected function withSiteHeaders(Response $response, SeoPayload $seo, bool $preview): Response
    {
        if ($preview) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

            return $response;
        }

        if (! $seo->isIndexable()) {
            $response->headers->set('X-Robots-Tag', $seo->robotsHeader());
        }

        return $response;
    }

    /**
     * Validate a stored snapshot against the shape `SnapshotBuilder` writes, and probe its partial.
     *
     * @param  array<string, mixed>  $row  id, section_key, anchor, sort_order, snapshot
     * @return array<string, mixed>|null
     */
    private function usableSection(array $row): ?array
    {
        $id = (int) ($row['id'] ?? 0);
        $key = (string) ($row['section_key'] ?? '');

        if (! SectionRegistry::exists($key)) {
            $this->warn($id, $key, 'section type is not registered (orphaned)');

            return null;
        }

        $snapshot = $row['snapshot'] ?? null;

        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true);
        }

        if (! is_array($snapshot) || ! is_array($snapshot['fields'] ?? null)) {
            $this->warn($id, $key, 'published snapshot is missing or malformed');

            return null;
        }

        foreach (['items', 'media', 'menus'] as $list) {
            if (isset($snapshot[$list]) && ! is_array($snapshot[$list])) {
                $this->warn($id, $key, sprintf('snapshot key [%s] is not a list', $list));

                return null;
            }
        }

        foreach (['cta', 'faqs'] as $optional) {
            if (isset($snapshot[$optional]) && ! is_array($snapshot[$optional])) {
                $this->warn($id, $key, sprintf('snapshot key [%s] is malformed', $optional));

                return null;
            }
        }

        $view = SectionRegistry::view($key);

        if (! View::exists($view)) {
            $this->warn($id, $key, sprintf('partial [%s] does not exist', $view));

            return null;
        }

        $section = array_merge($snapshot, [
            'id' => $id,
            'section_key' => $key,
            // The anchor is a live column (a rename bumps the cache), so the row's value wins.
            'anchor' => $row['anchor'] ?? ($snapshot['anchor'] ?? null),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'items' => $snapshot['items'] ?? [],
            'media' => $snapshot['media'] ?? [],
            'menus' => $snapshot['menus'] ?? [],
            'cta' => $snapshot['cta'] ?? null,
            'faqs' => $snapshot['faqs'] ?? null,
            'view' => $view,
        ]);

        try {
            View::make($view, $this->partialVariables($section))->render();
        } catch (Throwable $exception) {
            $this->warn($id, $key, 'partial threw while rendering', $exception);

            return null;
        }

        return $section;
    }

    /**
     * The variables a section partial is rendered with (integration K.3).
     *
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    protected function partialVariables(array $section): array
    {
        return [
            'section' => $section,
            'content' => $section['fields'],
            'items' => $section['items'],
            'media' => $section['media'],
            'cta' => $section['cta'],
            'menus' => $section['menus'],
            'faqs' => $section['faqs'] ?? [],
        ];
    }

    private function warn(int $id, string $key, string $problem, ?Throwable $exception = null): void
    {
        $signature = $id.'|'.$problem;

        if (isset($this->warned[$signature])) {
            return;
        }

        $this->warned[$signature] = true;

        Log::warning('Public site: a section was omitted from the page.', array_filter([
            'section_id' => $id,
            'section_key' => $key,
            'problem' => $problem,
            'exception' => $exception === null ? null : $exception::class.': '.$exception->getMessage(),
        ]));
    }

    private function cacheSeconds(): int
    {
        $minutes = setting('website.cache_ttl_minutes', 1440);
        $minutes = is_numeric($minutes) ? (int) $minutes : 1440;

        return max(1, min(10_080, $minutes)) * 60;
    }
}
