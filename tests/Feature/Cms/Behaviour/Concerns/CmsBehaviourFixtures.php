<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour\Concerns;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\MenuLocation;
use App\Enums\Cms\PageLayout;
use App\Enums\Cms\SectionPlacement;
use App\Models\Cms\MediaAsset;
use App\Models\Cms\Menu;
use App\Models\Cms\Page;
use App\Models\Cms\WebsiteSection;
use App\Services\Cms\CacheVersion;
use App\Services\Cms\ContentPublisher;
use App\Services\Cms\MediaService;
use App\Services\Cms\MenuService;
use App\Services\Cms\PageService;
use App\Services\Cms\SectionService;
use App\Services\Cms\SeoService;
use App\Support\Cms\SectionRegistry;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionProperty;

/**
 * Fixture helpers shared by the phase-03 behaviour, invariant and data-isolation acceptance tests
 * (`tests/Feature/Cms/Behaviour`).
 *
 * Everything is built on the **real seeded site** (`WebsiteCmsSeeder`: header, hero, about, faq, cta and
 * footer published; four system pages; four menus) and written through the real services, so a test
 * observes exactly what an administrator's click would produce: hashes, snapshots, revisions, audit rows
 * and cache bumps included. Raw SQL is used only where a test must prove that the *database* refuses
 * something, or must fabricate a state the services would never produce (an orphaned section type, a
 * hand-edited row).
 *
 * The public cache is the array store under test (`CACHE_STORE=array`), so a publish, a raw edit and a
 * page render can be observed key by key.
 *
 * Not named `*Test.php`, so PHPUnit does not try to run it.
 */
trait CmsBehaviourFixtures
{
    /** Section types a test registered at runtime, removed again in tearDown. */
    private array $registeredTestTypes = [];

    /*
    |--------------------------------------------------------------------------
    | Services
    |--------------------------------------------------------------------------
    */

    protected function sections(): SectionService
    {
        return app(SectionService::class);
    }

    protected function publisher(): ContentPublisher
    {
        return app(ContentPublisher::class);
    }

    protected function pages(): PageService
    {
        return app(PageService::class);
    }

    protected function menus(): MenuService
    {
        return app(MenuService::class);
    }

    protected function seo(): SeoService
    {
        return app(SeoService::class);
    }

    protected function media(): MediaService
    {
        return app(MediaService::class);
    }

    protected function cacheVersion(): CacheVersion
    {
        return app(CacheVersion::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Seeded site
    |--------------------------------------------------------------------------
    */

    /**
     * A section the seeder placed (home, header or footer — never a page section).
     */
    protected function seededSection(string $key, SectionPlacement $placement = SectionPlacement::Home): WebsiteSection
    {
        /** @var WebsiteSection */
        return WebsiteSection::query()
            ->where('section_key', $key)
            ->where('placement', $placement->value)
            ->whereNull('page_id')
            ->orderBy('id')
            ->firstOrFail();
    }

    protected function menuAt(MenuLocation $location): Menu
    {
        /** @var Menu */
        return Menu::query()->where('location', $location->value)->firstOrFail();
    }

    /**
     * Give the seeded hero a unique heading and put it live.
     */
    protected function publishHeroHeading(string $heading): WebsiteSection
    {
        $hero = $this->sections()->saveDraft($this->seededSection('hero'), ['heading' => $heading]);

        return $this->publisher()->publish($hero);
    }

    /**
     * Place a repeatable `rich_content` section with a unique heading, optionally published.
     */
    protected function placeRichContent(
        string $heading,
        SectionPlacement $placement = SectionPlacement::Home,
        ?Page $page = null,
        bool $publish = true,
    ): WebsiteSection {
        $section = $this->sections()->place('rich_content', $placement, $page);
        $section = $this->sections()->saveDraft($section, ['heading' => $heading]);

        return $publish ? $this->publisher()->publish($section) : $section;
    }

    /**
     * A custom page written through PageService (and, when asked, ContentPublisher).
     *
     * @param  array<string, mixed>  $extra
     */
    protected function makePage(string $slug, string $title, ?string $body = null, bool $publish = true, array $extra = []): Page
    {
        $page = $this->pages()->create(array_merge([
            'title' => $title,
            'slug' => $slug,
            'layout' => PageLayout::Content->value,
            'content' => $body ?? '<p>'.e($title).' body.</p>',
        ], $extra));

        return $publish ? $this->publisher()->publish($page) : $page;
    }

    /**
     * A page composed of sections (the only placement that may hold a second `about`).
     */
    protected function makeSectionsPage(string $slug, string $title, bool $publish = true): Page
    {
        return $this->makePage($slug, $title, null, $publish, ['layout' => PageLayout::Sections->value, 'content' => null]);
    }

    /**
     * The raw database row of a section — never the model, whose casts would hide what is stored.
     */
    protected function sectionRow(WebsiteSection|int $section): object
    {
        $id = $section instanceof WebsiteSection ? (int) $section->getKey() : $section;

        return DB::table('website_sections')->where('id', $id)->first();
    }

    /**
     * The decoded `published_content` snapshot of a section, or null.
     *
     * @return array<string, mixed>|null
     */
    protected function snapshotOf(WebsiteSection|int $section): ?array
    {
        $raw = $this->sectionRow($section)->published_content;

        return is_string($raw) ? json_decode($raw, true) : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Settings and cache
    |--------------------------------------------------------------------------
    */

    /**
     * Write a setting the low-level way the test suite may (SettingsRepository allows it only under
     * `runningUnitTests()`), then drop every memo so the next read — in this process or in a request —
     * sees it.
     */
    protected function setSetting(string $key, mixed $value): void
    {
        settings_repo()->set($key, $value);
        settings_repo()->flush();
    }

    /**
     * Invalidate the public cache the way a publish does. Used after a raw edit or a settings change,
     * neither of which is a CMS publish.
     */
    protected function bumpPublicCache(string $reason = 'test fixture'): int
    {
        return $this->cacheVersion()->bump($reason);
    }

    /**
     * Every key currently stored in the array cache store.
     *
     * @return list<string>
     */
    protected function cacheKeys(): array
    {
        $store = app(CacheRepository::class)->getStore();

        if ($store instanceof ArrayStore) {
            return array_map('strval', array_keys($store->all(false)));
        }

        $property = new ReflectionProperty($store, 'storage');

        return array_map('strval', array_keys((array) $property->getValue($store)));
    }

    /**
     * The full-page cache entries (`CachePublicResponse`, namespace `page`).
     *
     * @return list<string>
     */
    protected function pageCacheKeys(): array
    {
        return array_values(array_filter(
            $this->cacheKeys(),
            static fn (string $key): bool => str_starts_with($key, CacheVersion::PREFIX.':v') && str_contains($key, ':page:'),
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    */

    /**
     * A library row with no file behind it — enough for snapshots, usage counts and delete guards.
     * Derivatives are recorded as ready at the hero widths, WebP included, unless overridden.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeImageAsset(array $overrides = []): MediaAsset
    {
        $ulid = (string) Str::ulid();
        $directory = sprintf('cms/%s/%s', Carbon::now()->format('Y/m'), $ulid);
        $variants = [];

        foreach ([640, 960, 1280] as $width) {
            $variants[(string) $width] = [
                'path' => sprintf('%s/%s-%d.jpg', $directory, $ulid, $width),
                'format' => 'jpg',
                'mime_type' => 'image/jpeg',
                'width' => $width,
                'height' => (int) round($width / 2),
                'size_bytes' => 1000 + $width,
                'webp' => [
                    'path' => sprintf('%s/%s-%d.webp', $directory, $ulid, $width),
                    'format' => 'webp',
                    'mime_type' => 'image/webp',
                    'size_bytes' => 800 + $width,
                ],
            ];
        }

        $now = Carbon::now();

        $id = DB::table('media_assets')->insertGetId(array_merge([
            'disk' => 'public',
            'directory' => $directory,
            'filename' => $ulid.'.jpg',
            'original_name' => 'fixture-'.Str::lower(Str::random(6)).'.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size_bytes' => 4096,
            'width' => 1280,
            'height' => 640,
            'checksum' => hash('sha256', $ulid.Str::random(16)),
            'collection' => 'sections',
            'profile' => 'hero',
            'variants' => json_encode($variants, JSON_UNESCAPED_SLASHES),
            'alt_text' => 'A fixture image',
            'derivatives_status' => 'ready',
            'derivatives_generated_at' => $now,
            'usage_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        /** @var MediaAsset */
        return MediaAsset::query()->findOrFail($id);
    }

    /**
     * Genuine JPEG bytes drawn with GD (never a fake header).
     */
    protected function jpegBytes(int $width, int $height, int $red = 40, int $green = 90, int $blue = 160): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, $red, $green, $blue));
        imagefilledrectangle($image, 0, 0, (int) ($width / 3), (int) ($height / 3), (int) imagecolorallocate($image, 250, 200, 20));

        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();

        return $bytes;
    }

    /*
    |--------------------------------------------------------------------------
    | Runtime section types
    |--------------------------------------------------------------------------
    */

    /**
     * Register a section type for one test. Phase 3 ships no type with a required media role or a
     * repeater minimum above zero, but the service guarantees both (§6.2); a later phase's type is
     * exactly this kind of registration, so the rule is proven through the public seam.
     *
     * @param  array<string, mixed>  $definition
     */
    protected function registerTestSectionType(string $key, array $definition): void
    {
        if (! SectionRegistry::exists($key)) {
            SectionRegistry::register($key, $definition);
        }

        $this->registeredTestTypes[] = $key;
    }

    /**
     * Remove only the types this test registered, leaving any other runtime registration intact.
     */
    protected function forgetTestSectionTypes(): void
    {
        if ($this->registeredTestTypes === []) {
            return;
        }

        $registered = new ReflectionProperty(SectionRegistry::class, 'registered');
        $types = (array) $registered->getValue();

        foreach ($this->registeredTestTypes as $key) {
            unset($types[$key]);
        }

        $registered->setValue(null, $types);
        (new ReflectionProperty(SectionRegistry::class, 'normalised'))->setValue(null, null);

        $this->registeredTestTypes = [];
    }

    /*
    |--------------------------------------------------------------------------
    | Sessions
    |--------------------------------------------------------------------------
    */

    /**
     * Become an anonymous visitor again (the harness keeps the last `actingAs()` user otherwise).
     */
    protected function becomeGuest(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    /**
     * Collapse an HTML document to its visible text with no whitespace at all, so an assertion about
     * "PKR1,500+" does not depend on how a partial splits the number and its affixes across elements.
     */
    protected function squashedText(string $html): string
    {
        $html = (string) preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', ' ', $html);

        return (string) preg_replace('~\s+~u', '', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Every section status as `ContentStatus`, whatever the cast hands back.
     */
    protected function statusOf(WebsiteSection|Page $model): ContentStatus
    {
        $status = $model->fresh()?->getAttribute('status');

        return $status instanceof ContentStatus ? $status : ContentStatus::from((string) $status);
    }
}
