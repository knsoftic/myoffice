<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\RobotsDirective;
use App\Enums\Cms\SitemapChangeFrequency;
use App\Models\Cms\MediaAsset;
use App\Models\Cms\Page;
use App\Models\Cms\SeoMeta;
use App\Services\Cms\Data\SeoPayload;
use App\Services\Cms\Data\SitemapEntry;
use App\Support\SettingsRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * The one SEO store — decision **D23** (phase-03 §2.12, §6.5, §105, INV-9, INV-16).
 *
 * Every public target gets its SEO through `seo_meta`, addressed either by a model
 * (`seoable_type` / `seoable_id`: a `Page`, and later a service, course or post) or by a route name
 * (`route_key`: `site.home`, and later `site.courses.index`). No other table carries SEO columns and no
 * other class writes this one. One public entry point per operation:
 *
 *   meta()          the stored row, or null
 *   for()           the resolved `SeoPayload` a public response renders
 *   save()          create-or-update a target's row (validated, audited, cache bumped)
 *   ensure()        create the row with defaults if it is absent (publishing a page does this)
 *   copy()          duplicate a target's SEO onto another target (page duplication)
 *   rules()         THE validation rule set an entity Form Request merges (ND-13)
 *   editorRules()   rules() plus the Open Graph text and sitemap fields of the SEO screen
 *   completeness()  0-100 for the SEO manager's meter
 *   auditRows()     every target and its gaps, for the SEO table and the CSV export
 *   sitemapEntries() the Phase 3 URLs of `sitemap.xml` (published, indexable, included pages + routes)
 *   robotsTxt()     the effective `robots.txt`
 *
 * Invariants:
 *
 *   · **Fallback chain per field** (§6.5), first non-empty wins: title -> target title ->
 *     `seo.meta_title` -> `company.name`, suffixed " | company" unless already present; description ->
 *     target excerpt -> `seo.meta_description` -> `company.short_description`; keywords ->
 *     `seo.meta_keywords`; canonical -> `seo.canonical_base_url` + path -> the current URL; OG image ->
 *     the target's banner or hero -> `seo.og_image` -> `branding.og_image`.
 *   · **Robots: the strictest wins, never the loosest.** A preview, `seo.robots_indexable = false`,
 *     maintenance mode or a disabled public site force `noindex_nofollow` whatever the row says.
 *   · **One rule set.** `rules()` takes every max from the real column width (title 180, description
 *     320, keywords 500, canonical 500); `save()` validates with the same rules, so a service call with
 *     no Form Request is held to exactly what a form is.
 *   · **Closed keys.** `save()` refuses a key that is not a writable `seo_meta` column.
 *   · SEO is live-on-save (§2.15): a change bumps the public cache after commit and is audited with
 *     old and new values.
 */
final class SeoService
{
    public const HOME_ROUTE = 'site.home';

    public const DEFAULT_PREFIX = 'seo';

    /** @var list<string> */
    public const OG_TYPES = ['website', 'article', 'product', 'profile', 'book', 'video.other'];

    /** @var array<string, int> writable column => max length (null-limit columns are typed) */
    public const TEXT_LIMITS = [
        'title' => 180,
        'meta_description' => 320,
        'meta_keywords' => 500,
        'canonical_url' => 500,
        'og_title' => 180,
        'og_description' => 320,
    ];

    /** @var list<string> */
    public const WRITABLE = [
        'title', 'meta_description', 'meta_keywords', 'canonical_url', 'robots', 'og_title',
        'og_description', 'og_image_media_id', 'og_type', 'sitemap_include', 'sitemap_priority',
        'sitemap_changefreq',
    ];

    /** The `Disallow:` lines of the generated robots.txt (§6.5), in order. */
    public const ROBOTS_DISALLOW = [
        '/admin', '/collaborator', '/student', '/teacher', '/client', '/login', '/preview', '/storage/cms/originals',
    ];

    private const MODULE = 'seo';

    private const ROUTE_KEY_PATTERN = '/^[A-Za-z0-9_.\-]{1,100}$/';

    private const SITEMAP_JOB = 'App\\Jobs\\Cms\\RegenerateSitemap';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SettingsRepository $settings,
        private readonly ValidationFactory $validator,
        private readonly MediaService $media,
        private readonly CacheVersion $cache,
        private readonly CmsAuditor $auditor,
        private readonly Router $router,
        private readonly UrlGenerator $url,
        private readonly FilesystemFactory $storage,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    public function meta(Model|string $target): ?SeoMeta
    {
        $row = $this->targetQuery($target)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * The resolved SEO for a public response.
     *
     * @param  string|null  $path  the public path being rendered (`/privacy-policy`); derived from the
     *                             target when null, then from the current request
     */
    public function for(Model|string $target, bool $preview = false, ?string $path = null): SeoPayload
    {
        $row = $this->targetQuery($target)->first();
        $model = $target instanceof Model ? $target : null;
        $company = $this->text($this->settings->get('company.name')) ?? (string) config('app.name', '');

        $title = $this->firstFilled(
            $row?->title,
            $this->modelAttribute($model, ['title', 'name']),
            $this->settings->get('seo.meta_title'),
            $company
        ) ?? $company;

        if ($company !== '' && ! str_contains(mb_strtolower($title), mb_strtolower($company))) {
            $title .= ' | '.$company;
        }

        $description = $this->firstFilled(
            $row?->meta_description,
            $this->modelAttribute($model, ['excerpt', 'short_description', 'summary']),
            $this->settings->get('seo.meta_description'),
            $this->settings->get('company.short_description')
        );

        $keywords = $this->firstFilled($row?->meta_keywords, $this->settings->get('seo.meta_keywords'));

        $canonical = $this->text($row?->canonical_url);

        if ($canonical === null || preg_match('~^https?://~i', $canonical) !== 1) {
            $path ??= $this->pathFor($target);
            $base = $this->configuredBaseUrl();

            $canonical = $base !== null && $path !== null
                ? $base.'/'.ltrim($path, '/')
                : ($path !== null ? $this->url->to($path) : $this->url->current());
        }

        [$imageUrl, $width, $height] = $this->ogImage($row, $model, $target);

        return new SeoPayload(
            title: $this->limit($title, 180),
            metaDescription: $description === null ? null : $this->limit($description, 320),
            metaKeywords: $keywords === null ? null : $this->limit($keywords, 500),
            canonicalUrl: $canonical,
            robots: $this->robotsDirective($row?->robots, $preview),
            ogTitle: $this->limit($this->firstFilled($row?->og_title, $title) ?? $title, 180),
            ogDescription: $this->firstFilled($row?->og_description, $description),
            ogImageUrl: $imageUrl,
            ogType: in_array((string) ($row?->og_type), self::OG_TYPES, true) ? (string) $row->og_type : 'website',
            siteName: $company,
            locale: (string) ($this->text($this->settings->get('localization.locale')) ?? 'en'),
            imageWidth: $width,
            imageHeight: $height,
        );
    }

    /**
     * The effective directive: the stored one, forced to `noindex_nofollow` when anything stricter
     * applies (INV-9, FT-40).
     */
    public function robotsDirective(RobotsDirective|string|null $stored, bool $preview = false): RobotsDirective
    {
        if ($preview || $this->siteLockedDown()) {
            return RobotsDirective::NoindexNofollow;
        }

        if ($stored instanceof RobotsDirective) {
            return $stored;
        }

        return RobotsDirective::tryFrom((string) $stored) ?? RobotsDirective::IndexFollow;
    }

    /*
    |--------------------------------------------------------------------------
    | Writing
    |--------------------------------------------------------------------------
    */

    /**
     * Create or update a target's SEO row.
     *
     * @param  array<string, mixed>  $data  writable `seo_meta` columns only
     *
     * @throws ValidationException
     */
    public function save(Model|string $target, array $data, ?string $reason = null): SeoMeta
    {
        $address = $this->address($target);

        $unknown = array_values(array_diff(array_map('strval', array_keys($data)), self::WRITABLE));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                self::DEFAULT_PREFIX => [sprintf('Unknown SEO fields: %s.', implode(', ', $unknown))],
            ]);
        }

        $validator = $this->validator->make($data, $this->editorRules(''));

        if ($validator->fails()) {
            throw ValidationException::withMessages($this->prefixErrors($validator->errors()->toArray()));
        }

        $values = $this->normalise($data);

        if (isset($values['og_image_media_id']) && $values['og_image_media_id'] !== null) {
            $mime = (string) $this->connection()->table('media_assets')
                ->where('id', $values['og_image_media_id'])->whereNull('deleted_at')->value('mime_type');

            if (! str_starts_with($mime, 'image/')) {
                throw ValidationException::withMessages([self::DEFAULT_PREFIX.'.og_image_media_id' => ['The social image must be an image.']]);
            }
        }

        try {
            $id = $this->write($target, $address, $values, $reason);
        } catch (UniqueConstraintViolationException) {
            // A concurrent first save created the row; apply this one as an update.
            $id = $this->write($target, $address, $values, $reason);
        }

        return $this->hydrate($this->connection()->table('seo_meta')->where('id', $id)->first());
    }

    /**
     * The row, created with the defaults when absent. No audit row for an untouched existing record.
     */
    public function ensure(Model|string $target): SeoMeta
    {
        $existing = $this->meta($target);

        return $existing ?? $this->save($target, []);
    }

    /**
     * Copy one target's SEO onto another (§6.4 `duplicate()`: the copy starts excluded from the sitemap).
     *
     * @param  array<string, mixed>  $overrides
     */
    public function copy(Model|string $from, Model|string $to, array $overrides = ['sitemap_include' => false]): SeoMeta
    {
        $source = $this->targetQuery($from)->first();
        $data = [];

        if ($source !== null) {
            foreach (self::WRITABLE as $column) {
                $data[$column] = $source->{$column};
            }
        }

        return $this->save($to, array_merge($data, $overrides));
    }

    /**
     * THE SEO validation contract (D23, ND-13): the six writable `seo_meta` columns an entity Form
     * Request merges — `array_merge($own, $seo->rules())` — and never restates.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(string $prefix = self::DEFAULT_PREFIX): array
    {
        $p = $this->prefix($prefix);

        return [
            $p.'title' => ['nullable', 'string', 'max:180'],
            $p.'meta_description' => ['nullable', 'string', 'max:320'],
            $p.'meta_keywords' => ['nullable', 'string', 'max:500'],
            $p.'canonical_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            $p.'robots' => ['nullable', Rule::enum(RobotsDirective::class)],
            $p.'og_image_media_id' => ['nullable', 'integer', Rule::exists('media_assets', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * `rules()` plus the fields only the SEO manager edits (§8.12): Open Graph text and type, and the
     * sitemap three.
     *
     * @return array<string, list<mixed>>
     */
    public function editorRules(string $prefix = self::DEFAULT_PREFIX): array
    {
        $p = $this->prefix($prefix);

        return array_merge($this->rules($prefix), [
            $p.'og_title' => ['nullable', 'string', 'max:180'],
            $p.'og_description' => ['nullable', 'string', 'max:320'],
            $p.'og_type' => ['nullable', 'string', Rule::in(self::OG_TYPES)],
            $p.'sitemap_include' => ['nullable', 'boolean'],
            $p.'sitemap_priority' => ['nullable', 'numeric', 'min:0', 'max:1'],
            $p.'sitemap_changefreq' => ['nullable', Rule::enum(SitemapChangeFrequency::class)],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Reporting
    |--------------------------------------------------------------------------
    */

    /**
     * 0-100: title 25, description 25, OG image 20, social text 10, indexable 10, in sitemap 10. A
     * title or description outside the ideal length scores 15 instead of 25.
     */
    public function completeness(SeoMeta|Model|string $target): int
    {
        $row = $target instanceof SeoMeta ? (object) $target->getAttributes() : $this->targetQuery($target)->first();

        if ($row === null) {
            return 20; // defaults: indexable and included, nothing written yet
        }

        $score = 0;
        $title = mb_strlen(trim((string) $row->title));
        $description = mb_strlen(trim((string) $row->meta_description));

        $score += $title === 0 ? 0 : ($title >= 10 && $title <= 70 ? 25 : 15);
        $score += $description === 0 ? 0 : ($description >= 50 && $description <= 170 ? 25 : 15);
        $score += $row->og_image_media_id !== null ? 20 : 0;
        $score += trim((string) $row->og_title) !== '' || trim((string) $row->og_description) !== '' ? 10 : 0;
        $score += (RobotsDirective::tryFrom((string) $row->robots) ?? RobotsDirective::IndexFollow)->isIndexable() ? 10 : 0;
        $score += (bool) ($row->sitemap_include ?? true) ? 10 : 0;

        return min(100, $score);
    }

    /**
     * Every SEO target — every live page, every route row (with `site.home` always present) and every
     * later-phase model row — with its gaps (§8.12 table, the CSV export).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function auditRows(): Collection
    {
        $connection = $this->connection();
        $rows = collect();
        $pageType = (new Page)->getMorphClass();

        $pages = $connection->table('pages as p')
            ->leftJoin('seo_meta as s', static function ($join) use ($pageType): void {
                $join->on('s.seoable_id', '=', 'p.id')->where('s.seoable_type', '=', $pageType);
            })
            ->whereNull('p.deleted_at')
            ->orderBy('p.sort_order')->orderBy('p.title')
            ->get(['p.id as target_id', 'p.title as target_name', 'p.slug', 'p.status as target_status', 'p.updated_at as target_updated_at', 's.*']);

        foreach ($pages as $page) {
            $rows->push($this->auditRow('page', (int) $page->target_id, (string) $page->target_name, $this->pageUrl((string) $page->slug), (string) $page->target_status, $page));
        }

        $routes = $connection->table('seo_meta')->whereNotNull('route_key')->orderBy('route_key')->get();
        $seenHome = false;

        foreach ($routes as $route) {
            $seenHome = $seenHome || $route->route_key === self::HOME_ROUTE;
            $rows->push($this->auditRow('route', (int) $route->id, (string) $route->route_key, $this->routeUrl((string) $route->route_key), null, $route));
        }

        if (! $seenHome) {
            $rows->prepend($this->auditRow('route', 0, self::HOME_ROUTE, $this->routeUrl(self::HOME_ROUTE), null, null));
        }

        foreach ($connection->table('seo_meta')->whereNotNull('seoable_type')->where('seoable_type', '!=', $pageType)->get() as $other) {
            $rows->push($this->auditRow(
                class_basename((string) $other->seoable_type),
                (int) $other->seoable_id,
                class_basename((string) $other->seoable_type).' #'.$other->seoable_id,
                null,
                null,
                $other
            ));
        }

        return $rows->values();
    }

    /*
    |--------------------------------------------------------------------------
    | Sitemap and robots
    |--------------------------------------------------------------------------
    */

    /**
     * The URLs Phase 3 contributes to `sitemap.xml` (§6.5): every published, non-trashed page whose
     * SEO is indexable and included, plus every included, indexable route row whose route exists and
     * `site.home`. Drafts, scheduled and trashed pages, noindex targets and excluded rows never appear.
     * A site-wide `seo.robots_indexable = false` empties the list.
     *
     * @return Collection<int, SitemapEntry>
     */
    public function sitemapEntries(): Collection
    {
        $entries = collect();

        if (! $this->settingBool('seo.robots_indexable', true)) {
            return $entries;
        }

        $connection = $this->connection();
        $pageType = (new Page)->getMorphClass();
        $indexable = array_map(
            static fn (RobotsDirective $directive): string => $directive->value,
            array_values(array_filter(RobotsDirective::cases(), static fn (RobotsDirective $directive): bool => $directive->isIndexable()))
        );
        $defaultFrequency = SitemapChangeFrequency::tryFrom((string) $this->settings->get('seo.sitemap_changefreq_default', 'weekly')) ?? SitemapChangeFrequency::Weekly;
        $defaultPriority = (string) $this->settings->get('seo.sitemap_priority_default', '0.5');

        $pages = $connection->table('pages as p')
            ->leftJoin('seo_meta as s', static function ($join) use ($pageType): void {
                $join->on('s.seoable_id', '=', 'p.id')->where('s.seoable_type', '=', $pageType);
            })
            ->where('p.status', ContentStatus::Published->value)
            ->whereNull('p.deleted_at')
            ->where(static function ($query) use ($indexable): void {
                $query->whereNull('s.id')->orWhere(static function ($included) use ($indexable): void {
                    $included->where('s.sitemap_include', true)->whereIn('s.robots', $indexable);
                });
            })
            ->orderBy('p.sort_order')->orderBy('p.id')
            ->get(['p.slug', 'p.published_at', 'p.updated_at', 's.canonical_url', 's.sitemap_changefreq', 's.sitemap_priority']);

        foreach ($pages as $page) {
            $loc = $this->text($page->canonical_url);
            $loc = $loc !== null && preg_match('~^https?://~i', $loc) === 1 ? $loc : $this->absolute($this->pagePath((string) $page->slug));

            $entries->push(new SitemapEntry(
                loc: $loc,
                lastmod: $this->latest($page->published_at, $page->updated_at),
                changefreq: SitemapChangeFrequency::tryFrom((string) $page->sitemap_changefreq) ?? $defaultFrequency,
                priority: $page->sitemap_priority === null ? $defaultPriority : (string) $page->sitemap_priority,
                provider: 'pages',
            ));
        }

        $routes = $connection->table('seo_meta')->whereNotNull('route_key')->get()->keyBy('route_key');

        if (! $routes->has(self::HOME_ROUTE)) {
            $routes->put(self::HOME_ROUTE, (object) [
                'route_key' => self::HOME_ROUTE, 'sitemap_include' => true, 'robots' => RobotsDirective::IndexFollow->value,
                'canonical_url' => null, 'sitemap_changefreq' => SitemapChangeFrequency::Daily->value, 'sitemap_priority' => '1.0',
                'updated_at' => null,
            ]);
        }

        foreach ($routes as $route) {
            $name = (string) $route->route_key;

            if (! (bool) $route->sitemap_include || ! in_array((string) $route->robots, $indexable, true)) {
                continue;
            }

            $path = $this->routePath($name);

            if ($path === null) {
                continue; // a route that does not exist (yet) contributes nothing
            }

            $loc = $this->text($route->canonical_url);

            $entries->push(new SitemapEntry(
                loc: $loc !== null && preg_match('~^https?://~i', $loc) === 1 ? $loc : $this->absolute($path),
                lastmod: $this->latest($route->updated_at, null),
                changefreq: SitemapChangeFrequency::tryFrom((string) $route->sitemap_changefreq) ?? $defaultFrequency,
                priority: $route->sitemap_priority === null ? $defaultPriority : (string) $route->sitemap_priority,
                provider: 'static',
            ));
        }

        return $entries->unique(static fn (SitemapEntry $entry): string => $entry->loc)->values();
    }

    /**
     * The effective `robots.txt` (§6.5, FT-47). A locked-down site (not indexable, maintenance, public
     * site off) always gets `Disallow: /` with no sitemap line — that wins over custom text, because
     * the strictest rule wins. Otherwise the custom text verbatim (with off-site `Sitemap:` lines
     * dropped and ours appended if absent), or the generated file.
     */
    public function robotsTxt(): string
    {
        if ($this->siteLockedDown()) {
            return "User-agent: *\nDisallow: /\n";
        }

        $sitemapEnabled = $this->settingBool('seo.sitemap_enabled', true);
        $sitemapLine = 'Sitemap: '.$this->baseUrl().'/sitemap.xml';

        if ((string) $this->settings->get('seo.robots_txt_mode', 'auto') === 'custom') {
            $text = str_replace("\r\n", "\n", (string) $this->settings->get('seo.robots_txt_custom', ''));
            $host = strtolower((string) parse_url($this->baseUrl(), PHP_URL_HOST));
            $hasSitemap = false;
            $lines = [];

            foreach (explode("\n", $text) as $line) {
                if (preg_match('~^\s*sitemap\s*:\s*(\S+)~i', $line, $match) === 1) {
                    if (strtolower((string) parse_url($match[1], PHP_URL_HOST)) !== $host) {
                        continue;
                    }

                    $hasSitemap = true;
                }

                $lines[] = $line;
            }

            $text = rtrim(implode("\n", $lines));

            if ($sitemapEnabled && ! $hasSitemap) {
                $text .= ($text === '' ? '' : "\n\n").$sitemapLine;
            }

            return $text."\n";
        }

        $lines = ['User-agent: *', 'Allow: /'];

        foreach (self::ROBOTS_DISALLOW as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        if ($sitemapEnabled) {
            $lines[] = '';
            $lines[] = $sitemapLine;
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * `seo.canonical_base_url` without a trailing slash, or the application URL.
     */
    public function baseUrl(): string
    {
        return $this->configuredBaseUrl() ?? rtrim($this->url->to('/'), '/');
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array{type: string|null, id: int|null, route: string|null}  $address
     * @param  array<string, mixed>  $values
     */
    private function write(Model|string $target, array $address, array $values, ?string $reason): int
    {
        return (int) $this->connection()->transaction(function () use ($target, $address, $values, $reason): int {
            $existing = $this->targetQuery($target)->lockForUpdate()->first();
            $now = Carbon::now();
            $actor = $this->auditor->actorId();

            if ($existing === null) {
                $row = array_merge([
                    'robots' => RobotsDirective::IndexFollow->value,
                    'og_type' => 'website',
                    'sitemap_include' => true,
                    'sitemap_priority' => SitemapEntry::priority((string) $this->settings->get('seo.sitemap_priority_default', '0.5')),
                    'sitemap_changefreq' => (SitemapChangeFrequency::tryFrom((string) $this->settings->get('seo.sitemap_changefreq_default', 'weekly')) ?? SitemapChangeFrequency::Weekly)->value,
                ], array_filter($values, static fn (mixed $value, string $key): bool => $value !== null || ! in_array($key, ['robots', 'og_type', 'sitemap_include', 'sitemap_priority', 'sitemap_changefreq'], true), ARRAY_FILTER_USE_BOTH), [
                    'seoable_type' => $address['type'],
                    'seoable_id' => $address['id'],
                    'route_key' => $address['route'],
                    'created_at' => $now,
                    'updated_at' => $now,
                    'created_by' => $actor,
                    'updated_by' => $actor,
                ]);

                $id = (int) $this->connection()->table('seo_meta')->insertGetId($row);
                $old = [];
                $new = array_intersect_key($row, array_flip(self::WRITABLE));
            } else {
                $id = (int) $existing->id;
                $changes = [];

                foreach ($values as $column => $value) {
                    if ($value === null && in_array($column, ['robots', 'og_type', 'sitemap_include', 'sitemap_priority', 'sitemap_changefreq'], true)) {
                        continue; // not-null columns: an empty input keeps the stored value
                    }

                    if ((string) $existing->{$column} !== (string) ($value === true ? 1 : ($value === false ? 0 : $value))) {
                        $changes[$column] = $value;
                    }
                }

                if ($changes === []) {
                    return $id;
                }

                $this->connection()->table('seo_meta')->where('id', $id)->update(array_merge($changes, [
                    'updated_at' => $now,
                    'updated_by' => $actor,
                ]));

                $old = array_intersect_key((array) $existing, $changes);
                $new = $changes;
            }

            $this->auditor->record(
                module: self::MODULE,
                description: sprintf('SEO %s: %s', $existing === null ? 'created' : 'updated', $this->targetLabel($target)),
                subject: $target instanceof Model ? $target : null,
                properties: array_merge($this->auditor->diff($old, $new), ['target' => $address]),
                reason: $reason,
                event: $existing === null ? 'created' : 'updated',
            );

            $this->cache->bumpAfterCommit(sprintf('SEO changed: %s', $this->targetLabel($target)));
            $this->queueSitemap();

            return $id;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        $values = [];

        foreach ($data as $column => $value) {
            $values[$column] = match ($column) {
                'title', 'meta_description', 'meta_keywords', 'og_title', 'og_description' => $this->plainLimited($value, self::TEXT_LIMITS[$column]),
                'canonical_url' => $this->text($value) === null ? null : mb_substr((string) $this->text($value), 0, 500),
                'robots' => $value instanceof RobotsDirective ? $value->value : (RobotsDirective::tryFrom((string) $value)?->value),
                'og_image_media_id' => $value === null || $value === '' ? null : (int) $value,
                'og_type' => in_array((string) $value, self::OG_TYPES, true) ? (string) $value : null,
                'sitemap_include' => $value === null || $value === '' ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'sitemap_priority' => $value === null || $value === '' ? null : SitemapEntry::priority(is_float($value) ? sprintf('%.2F', $value) : (string) $value),
                'sitemap_changefreq' => $value instanceof SitemapChangeFrequency ? $value->value : (SitemapChangeFrequency::tryFrom((string) $value)?->value),
                default => throw new InvalidArgumentException(sprintf('Unknown SEO column [%s].', $column)),
            };
        }

        return $values;
    }

    /**
     * @return array{type: string|null, id: int|null, route: string|null}
     */
    private function address(Model|string $target): array
    {
        if ($target instanceof Model) {
            if (! $target->exists || $target->getKey() === null) {
                throw new InvalidArgumentException('SEO can only be attached to a saved record.');
            }

            return ['type' => $target->getMorphClass(), 'id' => (int) $target->getKey(), 'route' => null];
        }

        $route = trim($target);

        if (preg_match(self::ROUTE_KEY_PATTERN, $route) !== 1) {
            throw new InvalidArgumentException(sprintf('[%s] is not a valid route key for SEO.', $target));
        }

        return ['type' => null, 'id' => null, 'route' => $route];
    }

    private function targetQuery(Model|string $target): QueryBuilder
    {
        $address = $this->address($target);
        $query = $this->connection()->table('seo_meta');

        return $address['route'] !== null
            ? $query->where('route_key', $address['route'])
            : $query->where('seoable_type', $address['type'])->where('seoable_id', $address['id']);
    }

    /**
     * @return array{0: string|null, 1: int|null, 2: int|null}
     */
    private function ogImage(?object $row, ?Model $model, Model|string $target): array
    {
        $candidates = [
            $row?->og_image_media_id,
            $model?->getAttribute('banner_media_id'),
            $model?->getAttribute('og_image_media_id'),
            $model?->getAttribute('featured_media_id'),
        ];

        foreach ($candidates as $id) {
            if ($id === null || $id === '') {
                continue;
            }

            /** @var MediaAsset|null $asset */
            $asset = MediaAsset::query()->whereKey((int) $id)->first();

            if ($asset instanceof MediaAsset && $asset->isImage()) {
                $variant = $asset->isUsable() ? $this->closestVariant($asset, 1200) : null;

                return [
                    $this->media->url($asset, 1200),
                    $variant['width'] ?? $asset->width,
                    $variant['height'] ?? $asset->height,
                ];
            }
        }

        if ($target === self::HOME_ROUTE) {
            $hero = $this->heroImage();

            if ($hero !== null) {
                return $hero;
            }
        }

        foreach (['seo.og_image', 'branding.og_image'] as $key) {
            $url = $this->fileUrl($this->settings->get($key));

            if ($url !== null) {
                return [$url, null, null];
            }
        }

        return [null, null, null];
    }

    /**
     * The published hero image of the home page, from its snapshot — no join, no draft.
     *
     * @return array{0: string, 1: int|null, 2: int|null}|null
     */
    private function heroImage(): ?array
    {
        $published = $this->connection()->table('website_sections')
            ->where('placement', 'home')->where('section_key', 'hero')
            ->where('status', ContentStatus::Published->value)->where('is_enabled', true)
            ->whereNull('deleted_at')
            ->value('published_content');

        $snapshot = is_string($published) ? json_decode($published, true) : null;

        foreach (['hero_image', 'background_image', 'video_poster'] as $role) {
            $media = $snapshot['media'][$role] ?? null;

            if (is_array($media) && is_string($media['url'] ?? null) && ! ($media['is_video'] ?? false)) {
                return [(string) $media['url'], isset($media['width']) ? (int) $media['width'] : null, isset($media['height']) ? (int) $media['height'] : null];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function closestVariant(MediaAsset $asset, int $width): ?array
    {
        $variants = (array) ($asset->variants ?? []);
        ksort($variants);
        $chosen = null;

        foreach ($variants as $variantWidth => $variant) {
            if (is_array($variant) && (int) $variantWidth >= $width) {
                return $variant;
            }

            $chosen = is_array($variant) ? $variant : $chosen;
        }

        return $chosen;
    }

    private function fileUrl(mixed $value): ?string
    {
        $value = $this->text(is_string($value) ? $value : null);

        if ($value === null) {
            return null;
        }

        if (preg_match('~^https?://~i', $value) === 1) {
            return $value;
        }

        try {
            /** @var FilesystemAdapter $disk */
            $disk = $this->storage->disk('public');

            return $disk->url(ltrim($value, '/'));
        } catch (Throwable) {
            return null;
        }
    }

    private function pathFor(Model|string $target): ?string
    {
        if ($target instanceof Page) {
            return $this->pagePath((string) $target->getAttribute('slug'));
        }

        if (is_string($target)) {
            return $this->routePath($target);
        }

        return null;
    }

    private function pagePath(string $slug): string
    {
        if ($this->router->has('site.page')) {
            try {
                return $this->url->route('site.page', ['slug' => $slug], false);
            } catch (Throwable) {
                // fall through to the literal path
            }
        }

        return '/'.ltrim($slug, '/');
    }

    private function routePath(string $name): ?string
    {
        if (! $this->router->has($name)) {
            return $name === self::HOME_ROUTE ? '/' : null;
        }

        try {
            return $this->url->route($name, [], false);
        } catch (Throwable) {
            return null; // a route with required parameters has no single URL
        }
    }

    private function pageUrl(string $slug): string
    {
        return $this->absolute($this->pagePath($slug));
    }

    private function routeUrl(string $name): ?string
    {
        $path = $this->routePath($name);

        return $path === null ? null : $this->absolute($path);
    }

    private function absolute(string $path): string
    {
        return $path === '/' ? $this->baseUrl().'/' : $this->baseUrl().'/'.ltrim($path, '/');
    }

    private function configuredBaseUrl(): ?string
    {
        $base = $this->text($this->settings->get('seo.canonical_base_url'));

        return $base === null || preg_match('~^https?://~i', $base) !== 1 ? null : rtrim($base, '/');
    }

    private function siteLockedDown(): bool
    {
        return ! $this->settingBool('seo.robots_indexable', true)
            || $this->settingBool('maintenance.maintenance_mode', false)
            || ! $this->settingBool('maintenance.public_site_enabled', true);
    }

    private function settingBool(string $key, bool $default): bool
    {
        $value = $this->settings->get($key, $default);

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<int, string>  $attributes
     */
    private function modelAttribute(?Model $model, array $attributes): ?string
    {
        if ($model === null) {
            return null;
        }

        foreach ($attributes as $attribute) {
            $value = $model->getAttribute($attribute);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private function firstFilled(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $text = is_string($value) ? $this->text(strip_tags($value)) : null;

            if ($text !== null) {
                return (string) preg_replace('~\s+~u', ' ', $text);
            }
        }

        return null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function plainLimited(mixed $value, int $limit): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        // Plain text: Blade escapes it on output, so only markup and control characters are removed.
        $text = (string) preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~u', '', strip_tags((string) $value));
        $text = trim((string) preg_replace('~\s+~u', ' ', $text));

        return $text === '' ? null : mb_substr($text, 0, $limit);
    }

    private function limit(string $value, int $limit): string
    {
        return mb_substr($value, 0, $limit);
    }

    private function latest(mixed $first, mixed $second): ?Carbon
    {
        $dates = array_filter([
            $first === null ? null : Carbon::parse((string) $first),
            $second === null ? null : Carbon::parse((string) $second),
        ]);

        if ($dates === []) {
            return null;
        }

        usort($dates, static fn (Carbon $a, Carbon $b): int => $b <=> $a);

        return $dates[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditRow(string $type, int $id, string $name, ?string $url, ?string $status, ?object $row): array
    {
        $robots = RobotsDirective::tryFrom((string) ($row?->robots)) ?? RobotsDirective::IndexFollow;
        $gaps = [];

        if (trim((string) ($row?->title)) === '') {
            $gaps[] = 'missing_title';
        }

        if (trim((string) ($row?->meta_description)) === '') {
            $gaps[] = 'missing_description';
        }

        if (($row?->og_image_media_id) === null) {
            $gaps[] = 'missing_og_image';
        }

        if (! $robots->isIndexable()) {
            $gaps[] = 'noindex';
        }

        if ($row !== null && isset($row->sitemap_include) && ! (bool) $row->sitemap_include) {
            $gaps[] = 'excluded_from_sitemap';
        }

        $hasRow = $row !== null && isset($row->id) && $row->id !== null;

        return [
            'type' => $type,
            'id' => $id,
            'name' => $name,
            'url' => $url,
            'status' => $status,
            'seo_meta_id' => $hasRow ? (int) $row->id : null,
            'title' => $row?->title,
            'meta_description' => $row?->meta_description,
            'canonical_url' => $row?->canonical_url,
            'robots' => $robots->value,
            'og_image_media_id' => $row?->og_image_media_id,
            'sitemap_include' => $hasRow ? (bool) $row->sitemap_include : true,
            'completeness' => $hasRow ? $this->completenessOf($row) : 20,
            'gaps' => $gaps,
            'updated_at' => $row?->updated_at,
        ];
    }

    private function completenessOf(object $row): int
    {
        $meta = SeoMeta::query()->hydrate([(array) $row])->first();

        return $meta instanceof SeoMeta ? $this->completeness($meta) : 0;
    }

    private function targetLabel(Model|string $target): string
    {
        if (is_string($target)) {
            return $target;
        }

        $title = $this->modelAttribute($target, ['title', 'name']);

        return $title ?? class_basename($target).' #'.$target->getKey();
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @return array<string, array<int, string>>
     */
    private function prefixErrors(array $errors): array
    {
        $prefixed = [];

        foreach ($errors as $field => $messages) {
            $prefixed[self::DEFAULT_PREFIX.'.'.$field] = $messages;
        }

        return $prefixed;
    }

    private function prefix(string $prefix): string
    {
        return $prefix === '' ? '' : rtrim($prefix, '.').'.';
    }

    private function queueSitemap(): void
    {
        $this->connection()->afterCommit(static function (): void {
            $job = self::SITEMAP_JOB;

            if (! class_exists($job)) {
                return;
            }

            try {
                dispatch(new $job('publish'));
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    private function hydrate(object $row): SeoMeta
    {
        /** @var SeoMeta */
        return SeoMeta::query()->hydrate([(array) $row])->first();
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }
}
