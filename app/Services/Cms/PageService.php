<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\PageLayout;
use App\Enums\Cms\RevisionEvent;
use App\Enums\Cms\SectionPlacement;
use App\Http\Requests\Cms\PageTemplate;
use App\Models\Cms\Page;
use App\Models\Cms\WebsiteSection;
use App\Services\Cms\Exceptions\ContentActionNotAllowedException;
use App\Services\Cms\Exceptions\InvalidSectionContentException;
use App\Support\Cms\SectionRegistry;
use App\Support\RichText;
use BackedEnum;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Custom pages at their own slugs — everything **except** the published columns (phase-03 §2.7, §6.4
 * `PageService`, §8.10, §101). Publishing, scheduling, unpublishing and reverting are
 * `ContentPublisher`'s, the only writer of `published_content` / `published_hash` / `published_at`
 * (D22); SEO is `SeoService`'s (D23).
 *
 * One public entry point per operation:
 *
 *   reservedSlugs()  the first URL segments no page may take
 *   slugFor()        a free slug derived from a title
 *   create()         a new draft page
 *   saveDraft()      title, slug, layout, banner, template and the draft body
 *   duplicate()      a draft copy, its sections and its SEO (excluded from the sitemap)
 *   delete()         soft delete; linked menu items disabled, the page's sections trashed with it
 *   restore()        bring a trashed page (and the sections trashed with it) back as a draft
 *
 * Invariants:
 *
 *   · **INV-1 — a draft write never touches the live copy.** Nothing here writes `published_content`,
 *     `published_hash`, `published_at` or `published_by` on an existing page.
 *   · **INV-4 — the hash cannot lie.** `content_hash` is always `ContentHasher::pageHash()` of the stored
 *     (sanitised) body, so `has_unpublished_changes` agrees with what `ContentPublisher` writes.
 *   · **INV-13.** The body is sanitised with `RichText::sanitize()` on write (and again on render).
 *   · **Slugs (§6.4, R-5, FT-14).** Lowercase `[a-z0-9-]`, never a reserved first segment, unique against
 *     every row including trashed ones. A taken or reserved slug is a refusal naming the conflict, never
 *     a silent rename; only a slug derived from a title is suffixed `-2`, `-3`.
 *   · **`is_system` pages** are never deleted (FT-17, for a Super Admin too — M-7), and their slug changes
 *     only for someone holding `pages.change_status`.
 *   · **Closed keys.** `$data` carries page columns only (`WRITABLE`) — never SEO, status or publish
 *     columns; `template` is validated against `PageTemplate::ALLOWED`.
 *   · **INV-14 / INV-16.** Deletes are soft; every effective change is audited with old and new values
 *     (the slug change included). A change a visitor can see bumps the public cache after commit.
 */
final class PageService
{
    /**
     * The first URL segments no page may take: phase-03 §6.4 verbatim, plus the first segments
     * `routes/auth.php` already uses and those later contracts declare (integration A.1).
     *
     * @var list<string>
     */
    public const RESERVED_SLUGS = [
        // phase-03 §6.4
        'admin', 'login', 'logout', 'register', 'password', 'forgot-password', 'reset-password', 'verify-email',
        'collaborator', 'student', 'teacher', 'client', 'api', 'storage', 'preview', 'sitemap.xml', 'robots.txt', 'up',
        'courses', 'services', 'portfolio', 'blog', 'careers', 'contact', 'admission', 'certificate',
        // first segments routes/auth.php uses
        'account', 'email', 'confirm-password',
        // first segments later contracts declare: phase-04 /team, phase-19-23 /verify
        'team', 'verify',
    ];

    /** The `site.page` route constraint. */
    public const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/';

    public const SLUG_MAX = 200;

    /** @var list<string> the page columns `create()` / `saveDraft()` accept */
    public const WRITABLE = [
        'title', 'slug', 'layout', 'excerpt', 'content', 'show_banner', 'banner_media_id',
        'banner_heading', 'banner_subheading', 'template', 'sort_order',
    ];

    /** Columns the public page reads directly (ContentHasher: the body is the only snapshot column). */
    private const LIVE_COLUMNS = [
        'title', 'slug', 'layout', 'excerpt', 'show_banner', 'banner_media_id', 'banner_heading',
        'banner_subheading', 'template',
    ];

    /** @var array<string, int> */
    private const LIMITS = [
        'title' => 200,
        'excerpt' => 500,
        'content' => 1_000_000,
        'banner_heading' => 200,
        'banner_subheading' => 300,
    ];

    private const SORT_MAX = 65_535;

    private const MODULE = 'pages';

    /** Queued after a visible address change (§10.1 `QueueSitemapRegeneration`). */
    private const SITEMAP_JOB = 'App\\Jobs\\Cms\\RegenerateSitemap';

    /** @var array<string, bool> */
    private array $tables = [];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ContentHasher $hasher,
        private readonly RevisionRecorder $revisions,
        private readonly SectionService $sections,
        private readonly SeoService $seo,
        private readonly MediaService $media,
        private readonly CtaBlockService $ctaBlocks,
        private readonly CacheVersion $cache,
        private readonly CmsAuditor $auditor,
        private readonly AuthFactory $auth,
    ) {}

    /**
     * @return list<string>
     */
    public function reservedSlugs(): array
    {
        return self::RESERVED_SLUGS;
    }

    /**
     * A free slug derived from a title (§6.4 `slugFor()`): `Str::slug`, unique against every row
     * including trashed ones, suffixed `-2`, `-3`. A title whose slug is reserved is refused.
     *
     * @throws ContentActionNotAllowedException for a reserved slug
     */
    public function slugFor(string $title, ?Page $except = null): string
    {
        $base = trim(mb_substr(Str::slug($title), 0, self::SLUG_MAX - 8), '-');

        if ($base === '' || preg_match(self::SLUG_PATTERN, $base) !== 1) {
            $base = 'page';
        }

        if ($this->isReserved($base)) {
            throw $this->reserved($base);
        }

        $exceptId = $except?->getKey() === null ? null : (int) $except->getKey();
        $candidate = $base;

        for ($suffix = 2; $this->isReserved($candidate) || $this->slugHolder($candidate, $exceptId) !== null; $suffix++) {
            $candidate = $base.'-'.$suffix;
        }

        return $candidate;
    }

    /**
     * Create a draft page (§6.4 `create()`). A blank slug is derived from the title.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidSectionContentException for malformed input
     * @throws ContentActionNotAllowedException for a reserved or taken slug
     */
    public function create(array $data): Page
    {
        $this->assertKnownKeys($data);

        $assets = [];

        $id = $this->connection()->transaction(function () use ($data, &$assets): int {
            $values = $this->clean($data, creating: true);
            $slug = $values['slug'] ?? null;

            if ($slug === null) {
                $slug = $this->slugFor((string) $values['title']);
            } else {
                $this->assertSlugAvailable($slug, null);
            }

            $content = $values['content'] ?? null;
            $now = Carbon::now();
            $actor = $this->auditor->actorId();

            $row = array_merge([
                'layout' => PageLayout::Content->value,
                'excerpt' => null,
                'show_banner' => true,
                'banner_media_id' => null,
                'banner_heading' => null,
                'banner_subheading' => null,
                'template' => null,
            ], $values, [
                'slug' => $slug,
                'content' => $content,
                'content_hash' => $this->hasher->pageHash($content),
                'published_content' => null,
                'published_hash' => null,
                'status' => ContentStatus::Draft->value,
                'published_at' => null,
                'published_by' => null,
                'unpublished_reason' => null,
                'is_system' => false,
                'sort_order' => $values['sort_order'] ?? $this->nextSort(),
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => $actor,
                'updated_by' => $actor,
            ]);

            try {
                $id = (int) $this->connection()->table('pages')->insertGetId($row);
            } catch (UniqueConstraintViolationException) {
                throw $this->slugTaken($slug);
            }

            $page = $this->find($id);

            $this->revisions->record($page, RevisionEvent::Created, $this->hasher->pageCanonical($content));

            $this->auditor->record(
                module: self::MODULE,
                description: sprintf('Page created: %s', $row['title']),
                subject: $page,
                properties: ['attributes' => $this->auditable($row)],
                event: 'created',
            );

            $assets = array_filter([$row['banner_media_id']]);

            return $id;
        });

        $this->recountAssets($assets);

        return $this->find($id);
    }

    /**
     * Save the draft (§6.4 `saveDraft()`): only the keys present change. The body goes to `content` and
     * its hash; the live columns change at once. Identical data writes nothing and no revision.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidSectionContentException for malformed input, or a page in the trash
     * @throws ContentActionNotAllowedException for a reserved or taken slug, a system page's slug changed
     *                                          without `pages.change_status`, or an archived page
     */
    public function saveDraft(Page $page, array $data): Page
    {
        $this->assertKnownKeys($data);

        $assets = [];

        $this->connection()->transaction(function () use ($page, $data, &$assets): void {
            $row = $this->lock((int) $page->getKey());
            $this->assertWritable($row);

            $values = $this->clean($data, creating: false);
            $changes = [];

            foreach ($values as $column => $value) {
                if ($column === 'slug' && $value === null) {
                    continue; // a blank slug on an existing page keeps its address
                }

                if ($column === 'sort_order' && $value === null) {
                    continue;
                }

                if ($this->comparable($row->{$column} ?? null) !== $this->comparable($value)) {
                    $changes[$column] = $value;
                }
            }

            if (array_key_exists('slug', $changes)) {
                $this->assertSystemSlugChangeAllowed($row);
                $this->assertSlugAvailable((string) $changes['slug'], (int) $row->id);
            }

            $content = array_key_exists('content', $changes) ? $changes['content'] : $row->content;
            $hash = $this->hasher->pageHash($content);

            if ($hash !== $row->content_hash) {
                $changes['content_hash'] = $hash;
            }

            if ($changes === []) {
                return;
            }

            try {
                $this->connection()->table('pages')->where('id', $row->id)->update(array_merge($changes, [
                    'updated_at' => Carbon::now(),
                    'updated_by' => $this->auditor->actorId(),
                ]));
            } catch (UniqueConstraintViolationException) {
                throw $this->slugTaken((string) $changes['slug']);
            }

            if (array_key_exists('content_hash', $changes)) {
                $this->revisions->record($page, RevisionEvent::DraftSaved, $this->hasher->pageCanonical($content));
            }

            $this->auditor->record(
                module: self::MODULE,
                description: array_key_exists('slug', $changes)
                    ? sprintf('Page address changed: /%s to /%s', $row->slug, $changes['slug'])
                    : sprintf('Page draft saved: %s', $changes['title'] ?? $row->title),
                subject: $page,
                properties: $this->auditor->diff(
                    $this->auditable(array_intersect_key((array) $row, $changes)),
                    $this->auditable($changes)
                ),
                event: array_key_exists('slug', $changes) ? 'slug_changed' : 'draft_saved',
            );

            $isLive = (string) $row->status === ContentStatus::Published->value;

            if ($isLive && array_intersect(array_keys($changes), self::LIVE_COLUMNS) !== []) {
                $this->cache->bumpAfterCommit(sprintf('Page #%d changed', $row->id));
            }

            if ($isLive && array_key_exists('slug', $changes)) {
                $this->queueSitemap('publish');
            }

            if (array_key_exists('banner_media_id', $changes)) {
                $assets = [$row->banner_media_id, $changes['banner_media_id']];
            }
        });

        $this->recountAssets($assets);

        return $this->find((int) $page->getKey());
    }

    /**
     * A draft copy at `{slug}-copy` (§6.4 `duplicate()`): never a system page, its sections copied as
     * drafts when the layout is `sections`, its SEO copied with `sitemap_include = false`.
     */
    public function duplicate(Page $page): Page
    {
        $assets = [];
        $ctas = [];

        $id = $this->connection()->transaction(function () use ($page, &$assets, &$ctas): int {
            $row = $this->lock((int) $page->getKey());

            if ($row->deleted_at !== null) {
                throw InvalidSectionContentException::withErrors('That page is in the trash.', ['page' => ['Restore it before duplicating.']]);
            }

            $now = Carbon::now();
            $actor = $this->auditor->actorId();
            $slug = $this->copySlug((string) $row->slug);
            $title = mb_substr(trim((string) $row->title).' (copy)', 0, self::LIMITS['title']);

            try {
                $copyId = (int) $this->connection()->table('pages')->insertGetId([
                    'title' => $title,
                    'slug' => $slug,
                    'layout' => $row->layout,
                    'excerpt' => $row->excerpt,
                    'content' => $row->content,
                    'published_content' => null,
                    'content_hash' => $this->hasher->pageHash($row->content),
                    'published_hash' => null,
                    'show_banner' => (bool) $row->show_banner,
                    'banner_media_id' => $row->banner_media_id,
                    'banner_heading' => $row->banner_heading,
                    'banner_subheading' => $row->banner_subheading,
                    'template' => $row->template,
                    'status' => ContentStatus::Draft->value,
                    'published_at' => null,
                    'published_by' => null,
                    'unpublished_reason' => null,
                    'is_system' => false,
                    'sort_order' => $this->nextSort(),
                    'created_at' => $now,
                    'updated_at' => $now,
                    'created_by' => $actor,
                    'updated_by' => $actor,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw $this->slugTaken($slug);
            }

            $copy = $this->find($copyId);
            $copied = [];

            if ((string) $row->layout === PageLayout::Sections->value) {
                [$copied, $assets, $ctas] = $this->copySections((int) $row->id, $copyId, $now, $actor);
            }

            // §6.4: SEO copied, starting outside the sitemap. A social image that has since gone to the
            // trash is not carried over (it would fail SeoService's own `exists` rule).
            $source = $this->find((int) $row->id);
            $overrides = ['sitemap_include' => false];
            $ogImage = $this->seo->meta($source)?->getAttribute('og_image_media_id');

            if ($ogImage !== null && ! $this->connection()->table('media_assets')->where('id', $ogImage)->whereNull('deleted_at')->exists()) {
                $overrides['og_image_media_id'] = null;
            }

            $this->seo->copy($source, $copy, $overrides);

            $this->revisions->record($copy, RevisionEvent::Created, $this->hasher->pageCanonical($row->content), label: sprintf('Copied from page #%d', $row->id));

            $this->auditor->record(
                module: self::MODULE,
                description: sprintf('Page duplicated: %s', $row->title),
                subject: $copy,
                properties: ['attributes' => ['copied_from' => (int) $row->id, 'slug' => $slug, 'title' => $title, 'sections' => $copied]],
                event: 'duplicated',
            );

            $assets[] = $row->banner_media_id;

            return $copyId;
        });

        $this->recountAssets($assets);
        $this->ctaBlocks->recount(array_values(array_filter($ctas)));

        return $this->find($id);
    }

    /**
     * Soft-delete a page (§6.4 `delete()`). Menu items pointing at it are **disabled**, not deleted
     * (FT-28), and its sections are trashed with it — at the same instant, so `restore()` can bring back
     * exactly those.
     *
     * @throws ContentActionNotAllowedException for a system page (FT-17), whoever asks
     */
    public function delete(Page $page): void
    {
        $assets = [];
        $ctas = [];

        $this->connection()->transaction(function () use ($page, &$assets, &$ctas): void {
            $row = $this->lock((int) $page->getKey());

            if ((bool) $row->is_system) {
                throw ContentActionNotAllowedException::systemPage((string) $row->title);
            }

            if ($row->deleted_at !== null) {
                return;
            }

            $now = Carbon::now();
            $actor = $this->auditor->actorId();

            $this->connection()->table('pages')->where('id', $row->id)->update([
                'deleted_at' => $now,
                'updated_at' => $now,
                'updated_by' => $actor,
            ]);

            $menuItems = $this->connection()->table('menu_items')
                ->where('page_id', $row->id)->whereNull('deleted_at')->where('is_enabled', true)
                ->lockForUpdate()
                ->get(['id', 'menu_id', 'label']);

            if ($menuItems->isNotEmpty()) {
                $this->connection()->table('menu_items')
                    ->whereIn('id', $menuItems->pluck('id')->all())
                    ->update(['is_enabled' => false, 'updated_at' => $now, 'updated_by' => $actor]);
            }

            $sections = $this->connection()->table('website_sections')
                ->where('placement', SectionPlacement::Page->value)
                ->where('page_id', $row->id)->whereNull('deleted_at')
                ->lockForUpdate()
                ->get(['id', 'cta_block_id']);

            if ($sections->isNotEmpty()) {
                $sectionIds = $sections->pluck('id')->all();

                // Release the unique slots, as SectionService::remove() does; restore() reclaims them.
                $this->connection()->table('website_sections')->whereIn('id', $sectionIds)->update([
                    'deleted_at' => $now,
                    'instance_key' => null,
                    'updated_at' => $now,
                    'updated_by' => $actor,
                ]);

                $assets = array_merge(
                    $this->connection()->table('website_section_media')->whereIn('website_section_id', $sectionIds)->pluck('media_asset_id')->all(),
                    $this->connection()->table('website_section_items')->whereIn('website_section_id', $sectionIds)->whereNotNull('media_asset_id')->pluck('media_asset_id')->all(),
                );
                $ctas = $sections->pluck('cta_block_id')->filter()->all();
            }

            $this->auditor->record(
                module: self::MODULE,
                description: sprintf('Page moved to the trash: %s', $row->title),
                subject: $page,
                properties: [
                    'old' => ['deleted_at' => null, 'status' => (string) $row->status],
                    'attributes' => ['deleted_at' => $now->toDateTimeString()],
                    'disabled_menu_items' => $menuItems->map(static fn (object $item): array => ['id' => (int) $item->id, 'menu_id' => (int) $item->menu_id, 'label' => (string) $item->label])->values()->all(),
                    'trashed_sections' => $sections->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all(),
                ],
                event: 'deleted',
            );

            $this->cache->bumpAfterCommit(sprintf('Page #%d deleted', $row->id));

            if ((string) $row->status === ContentStatus::Published->value) {
                $this->queueSitemap('publish');
            }

            $assets[] = $row->banner_media_id;
        });

        $this->recountAssets($assets);
        $this->ctaBlocks->recount(array_values(array_filter($ctas)));
    }

    /**
     * Bring a trashed page back **as a draft** (its last snapshot is kept, so republishing is lossless),
     * with the sections that were trashed together with it. Menu items disabled by the delete stay
     * disabled: the audit row of the delete names them.
     */
    public function restore(Page $page): Page
    {
        $assets = [];
        $ctas = [];

        $this->connection()->transaction(function () use ($page, &$assets, &$ctas): void {
            $row = $this->lock((int) $page->getKey());

            if ($row->deleted_at === null) {
                return;
            }

            $now = Carbon::now();
            $actor = $this->auditor->actorId();
            $status = (string) $row->status;
            $newStatus = in_array($status, [ContentStatus::Published->value, ContentStatus::Scheduled->value], true)
                ? ContentStatus::Draft->value
                : $status;

            $this->connection()->table('pages')->where('id', $row->id)->update([
                'deleted_at' => null,
                'status' => $newStatus,
                'updated_at' => $now,
                'updated_by' => $actor,
            ]);

            $sections = $this->connection()->table('website_sections')
                ->where('placement', SectionPlacement::Page->value)
                ->where('page_id', $row->id)
                ->where('deleted_at', $row->deleted_at)
                ->lockForUpdate()
                ->get(['id', 'section_key', 'cta_block_id']);

            $restored = [];

            foreach ($sections as $section) {
                $key = (string) $section->section_key;

                try {
                    $this->connection()->table('website_sections')->where('id', $section->id)->update([
                        'deleted_at' => null,
                        'instance_key' => SectionRegistry::exists($key) ? SectionRegistry::instanceKey($key, SectionPlacement::Page, (int) $row->id) : null,
                        'updated_at' => $now,
                        'updated_by' => $actor,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    continue; // another instance of a unique type holds the slot; this one stays in the trash
                }

                $restored[] = (int) $section->id;

                if ($section->cta_block_id !== null) {
                    $ctas[] = (int) $section->cta_block_id;
                }
            }

            $this->revisions->record($page, RevisionEvent::Restored, $this->hasher->pageCanonical($row->content));

            $this->auditor->record(
                module: self::MODULE,
                description: sprintf('Page restored: %s', $row->title),
                subject: $page,
                properties: [
                    'old' => ['deleted_at' => (string) $row->deleted_at, 'status' => $status],
                    'attributes' => ['deleted_at' => null, 'status' => $newStatus],
                    'restored_sections' => $restored,
                ],
                event: 'restored',
            );

            if ($restored !== []) {
                $assets = array_merge(
                    $this->connection()->table('website_section_media')->whereIn('website_section_id', $restored)->pluck('media_asset_id')->all(),
                    $this->connection()->table('website_section_items')->whereIn('website_section_id', $restored)->whereNull('deleted_at')->whereNotNull('media_asset_id')->pluck('media_asset_id')->all(),
                );
            }

            $assets[] = $row->banner_media_id;
        });

        $this->recountAssets($assets);
        $this->ctaBlocks->recount(array_values(array_filter($ctas)));

        return $this->find((int) $page->getKey());
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Validate and normalise the keys present. Creating requires `title`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data, bool $creating): array
    {
        $errors = [];
        $values = [];

        if ($creating && ! array_key_exists('title', $data)) {
            $errors['title'][] = 'Enter a title.';
        }

        foreach ($data as $column => $value) {
            switch ($column) {
                case 'title':
                    $title = $this->text($value);

                    if ($title === null || mb_strlen($title) > self::LIMITS['title']) {
                        $errors[$column][] = sprintf('Enter a title of at most %d characters.', self::LIMITS['title']);
                    } else {
                        $values[$column] = $title;
                    }

                    break;

                case 'slug':
                    if ($value !== null && ! is_string($value)) {
                        $errors[$column][] = 'Use lowercase letters, digits and hyphens.';

                        break;
                    }

                    $slug = $this->text($value);

                    if ($slug !== null && (strlen($slug) > self::SLUG_MAX || preg_match(self::SLUG_PATTERN, $slug) !== 1)) {
                        $errors[$column][] = sprintf('Use lowercase letters, digits and inner hyphens (at most %d characters).', self::SLUG_MAX);
                    } else {
                        $values[$column] = $slug;
                    }

                    break;

                case 'layout':
                    $layout = $value instanceof PageLayout ? $value : PageLayout::tryFrom($this->scalar($value));

                    if ($layout === null) {
                        $errors[$column][] = 'Choose one of the listed layouts.';
                    } else {
                        $values[$column] = $layout->value;
                    }

                    break;

                case 'excerpt':
                case 'banner_heading':
                case 'banner_subheading':
                    if ($value !== null && ! is_string($value)) {
                        $errors[$column][] = 'This must be text.';

                        break;
                    }

                    $text = $this->text($value);

                    if ($text !== null && mb_strlen($text) > self::LIMITS[$column]) {
                        $errors[$column][] = sprintf('At most %d characters.', self::LIMITS[$column]);
                    } else {
                        $values[$column] = $text;
                    }

                    break;

                case 'content':
                    if ($value !== null && (! is_string($value) || strlen($value) > self::LIMITS['content'])) {
                        $errors[$column][] = 'The page body is not valid text, or is too long.';

                        break;
                    }

                    $clean = $value === null ? '' : RichText::sanitize($value);
                    $values[$column] = trim($clean) === '' ? null : $clean;

                    break;

                case 'show_banner':
                    $flag = $this->bool($value);

                    if ($flag === null) {
                        $errors[$column][] = 'This must be true or false.';
                    } else {
                        $values[$column] = $flag;
                    }

                    break;

                case 'banner_media_id':
                    if ($value === null || $value === '') {
                        $values[$column] = null;

                        break;
                    }

                    $assetId = $this->positiveInt($value);
                    $mime = $assetId === null ? null : $this->connection()->table('media_assets')
                        ->where('id', $assetId)->whereNull('deleted_at')->value('mime_type');

                    if (! is_string($mime) || ! str_starts_with($mime, 'image/')) {
                        $errors[$column][] = 'Choose an image from the media library.';
                    } else {
                        $values[$column] = $assetId;
                    }

                    break;

                case 'template':
                    if ($value === null || $value === '') {
                        $values[$column] = null;
                    } elseif (! is_string($value) || ! in_array($value, PageTemplate::ALLOWED, true)) {
                        $errors[$column][] = 'Choose one of the listed templates.';
                    } else {
                        $values[$column] = $value;
                    }

                    break;

                case 'sort_order':
                    if ($value === null || $value === '') {
                        $values[$column] = null;

                        break;
                    }

                    $sort = is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : -1);

                    if ($sort < 0 || $sort > self::SORT_MAX) {
                        $errors[$column][] = sprintf('Use a whole number from 0 to %d.', self::SORT_MAX);
                    } else {
                        $values[$column] = $sort;
                    }

                    break;
            }
        }

        if ($errors !== []) {
            throw InvalidSectionContentException::withErrors('The page could not be saved.', $errors);
        }

        return $values;
    }

    /**
     * Copy the live sections of one page onto another, as drafts: items, media slots and hand-picked
     * FAQs included; unique slots re-keyed to the new page; a type no longer registered is not copied.
     *
     * @return array{0: list<int>, 1: list<int>, 2: list<int>} copied section ids, asset ids, CTA ids
     */
    private function copySections(int $fromPageId, int $toPageId, Carbon $now, ?int $actor): array
    {
        $copied = [];
        $assets = [];
        $ctas = [];

        $rows = $this->connection()->table('website_sections')
            ->where('placement', SectionPlacement::Page->value)
            ->where('page_id', $fromPageId)->whereNull('deleted_at')
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        foreach ($rows as $section) {
            $key = (string) $section->section_key;

            if (! SectionRegistry::exists($key)) {
                continue;
            }

            $copyId = (int) $this->connection()->table('website_sections')->insertGetId([
                'section_key' => $key,
                'placement' => SectionPlacement::Page->value,
                'page_id' => $toPageId,
                'instance_key' => SectionRegistry::instanceKey($key, SectionPlacement::Page, $toPageId),
                'name' => $section->name,
                'anchor' => $section->anchor,
                'cta_block_id' => $section->cta_block_id,
                'menu_id' => $section->menu_id,
                'content' => $section->content,
                'is_enabled' => (bool) $section->is_enabled,
                'status' => ContentStatus::Draft->value,
                'sort_order' => (int) $section->sort_order,
                'draft_updated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => $actor,
                'updated_by' => $actor,
            ]);

            $items = $this->connection()->table('website_section_items')
                ->where('website_section_id', $section->id)->whereNull('deleted_at')
                ->orderBy('id')->get();

            if ($items->isNotEmpty()) {
                $this->connection()->table('website_section_items')->insert($items->map(static fn (object $item): array => [
                    'website_section_id' => $copyId,
                    'group' => $item->group,
                    'content' => $item->content,
                    'metric' => $item->metric,
                    'value_mode' => $item->value_mode,
                    'manual_value' => $item->manual_value,
                    'media_asset_id' => $item->media_asset_id,
                    'is_enabled' => $item->is_enabled,
                    'sort_order' => $item->sort_order,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'created_by' => $actor,
                    'updated_by' => $actor,
                ])->all());
            }

            $media = $this->connection()->table('website_section_media')->where('website_section_id', $section->id)->get();

            if ($media->isNotEmpty()) {
                $this->connection()->table('website_section_media')->insert($media->map(static fn (object $pivot): array => [
                    'website_section_id' => $copyId,
                    'media_asset_id' => $pivot->media_asset_id,
                    'role' => $pivot->role,
                    'sort_order' => $pivot->sort_order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            }

            if ($this->hasTable('faq_website_section')) {
                $faqs = $this->connection()->table('faq_website_section')->where('website_section_id', $section->id)->get();

                if ($faqs->isNotEmpty()) {
                    $this->connection()->table('faq_website_section')->insert($faqs->map(static fn (object $faq): array => [
                        'faq_id' => $faq->faq_id,
                        'website_section_id' => $copyId,
                        'sort_order' => $faq->sort_order,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all());
                }
            }

            /** @var WebsiteSection $copy */
            $copy = WebsiteSection::query()->withoutGlobalScopes()->findOrFail($copyId);

            $this->sections->refreshHash($copy);
            $this->revisions->record($copy, RevisionEvent::Created, $this->sections->canonicalPayload($copy), label: sprintf('Copied from section #%d', $section->id));

            $copied[] = $copyId;
            $assets = array_merge($assets, $media->pluck('media_asset_id')->all(), $items->pluck('media_asset_id')->filter()->all());

            if ($section->cta_block_id !== null) {
                $ctas[] = (int) $section->cta_block_id;
            }
        }

        return [$copied, array_values(array_map('intval', $assets)), $ctas];
    }

    private function copySlug(string $slug): string
    {
        $stem = trim(mb_substr($slug, 0, self::SLUG_MAX - 12), '-').'-copy';
        $candidate = $stem;

        for ($suffix = 2; $this->isReserved($candidate) || $this->slugHolder($candidate, null) !== null; $suffix++) {
            $candidate = $stem.'-'.$suffix;
        }

        return $candidate;
    }

    /**
     * @throws InvalidSectionContentException for a malformed slug
     * @throws ContentActionNotAllowedException for a reserved or taken slug
     */
    private function assertSlugAvailable(string $slug, ?int $exceptId): void
    {
        if (strlen($slug) > self::SLUG_MAX || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw InvalidSectionContentException::withErrors('The address is not valid.', [
                'slug' => ['Use lowercase letters, digits and inner hyphens.'],
            ]);
        }

        if ($this->isReserved($slug)) {
            throw $this->reserved($slug);
        }

        if ($this->slugHolder($slug, $exceptId) !== null) {
            throw $this->slugTaken($slug, $exceptId);
        }
    }

    /**
     * §6.4: the address of a system page changes only for someone who may publish pages. Console
     * context (seeders, commands) has no user and is trusted code.
     */
    private function assertSystemSlugChangeAllowed(object $row): void
    {
        if (! (bool) $row->is_system) {
            return;
        }

        try {
            $user = $this->auth->guard()->user();
        } catch (Throwable) {
            $user = null;
        }

        $allowed = $user === null
            ? app()->runningInConsole()
            : $user instanceof Authorizable && $user->can('pages.change_status');

        if (! $allowed) {
            throw new ContentActionNotAllowedException(sprintf(
                'The address of "%s" is part of the site\'s legal pages: only someone who can publish pages may change it.',
                $row->title
            ));
        }
    }

    private function assertWritable(object $row): void
    {
        if ($row->deleted_at !== null) {
            throw InvalidSectionContentException::withErrors('That page is in the trash.', ['page' => ['Restore it before editing.']]);
        }

        if ((string) $row->status === ContentStatus::Archived->value) {
            throw ContentActionNotAllowedException::archived((string) $row->title);
        }
    }

    private function isReserved(string $slug): bool
    {
        return in_array($slug, self::RESERVED_SLUGS, true);
    }

    private function reserved(string $slug): ContentActionNotAllowedException
    {
        return ContentActionNotAllowedException::reservedSlug($slug, 'another part of the site uses that address. Choose another.');
    }

    private function slugTaken(string $slug, ?int $exceptId = null): ContentActionNotAllowedException
    {
        $holder = $this->slugHolder($slug, $exceptId);

        return ContentActionNotAllowedException::slugTaken($slug, $holder !== null && $holder->deleted_at !== null);
    }

    private function slugHolder(string $slug, ?int $exceptId): ?object
    {
        return $this->connection()->table('pages')
            ->where('slug', $slug)
            ->when($exceptId !== null, static fn ($query) => $query->where('id', '!=', $exceptId))
            ->first(['id', 'title', 'deleted_at']);
    }

    private function nextSort(): int
    {
        return min(self::SORT_MAX, (int) $this->connection()->table('pages')->max('sort_order') + 10);
    }

    /**
     * A page row for the audit trail: the body is represented by its hash, never by its HTML (the
     * revision holds the prose).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function auditable(array $values): array
    {
        if (array_key_exists('content', $values)) {
            unset($values['content']);
        }

        unset($values['published_content']);

        return $values;
    }

    /**
     * @param  array<int, mixed>  $assets
     */
    private function recountAssets(array $assets): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $assets))));

        if ($ids !== []) {
            $this->media->recountUsage($ids);
        }
    }

    private function queueSitemap(string $trigger): void
    {
        $this->connection()->afterCommit(static function () use ($trigger): void {
            $job = self::SITEMAP_JOB;

            if (! class_exists($job)) {
                return; // the sitemap is version-stamped; the bump already invalidated it
            }

            try {
                dispatch(new $job($trigger));
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertKnownKeys(array $data): void
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($data)), self::WRITABLE));

        if ($unknown !== []) {
            throw InvalidSectionContentException::withErrors(
                sprintf('Unknown page fields: %s.', implode(', ', $unknown)),
                ['page' => [sprintf('These fields cannot be written here: %s.', implode(', ', $unknown))]]
            );
        }
    }

    private function lock(int $id): object
    {
        $row = $this->connection()->table('pages')->where('id', $id)->lockForUpdate()->first();

        if ($row === null) {
            throw InvalidSectionContentException::withErrors('That page no longer exists.', ['page' => ['It may have been deleted in another tab.']]);
        }

        return $row;
    }

    private function find(int $id): Page
    {
        /** @var Page */
        return Page::query()->withoutGlobalScopes()->findOrFail($id);
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = str_replace("\r\n", "\n", trim($value));

        return $value === '' ? null : $value;
    }

    private function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function scalar(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function comparable(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_string($value)) {
            return str_replace("\r\n", "\n", $value);
        }

        return is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value);
    }

    private function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= $this->connection()->getSchemaBuilder()->hasTable($table);
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }
}
