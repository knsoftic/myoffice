### Phase 3 database schema (migrations)

The ten migration files in `database/migrations/2026_09_12_07*.php` need **no** change to any file I
may not touch: they create new tables only, reference only `users`, `pages`, `menus`, `cta_blocks`,
`media_assets`, `website_sections`, `faqs` and `faq_categories`, and add no permission, route, sidebar
entry, seeder row or config key.

**Composer packages** (phase-03 §13.4). Not required by the migrations themselves — required by the
services and views that read these tables. Run from the project root:

```bash
composer require intervention/image:^3
composer require mews/purifier:^3.4
```

`mews/purifier` must ship **both** committed profiles in `config/purifier.php` — `cms` (default) and
`material` — because `App\Support\RichText` is the only class allowed to name one (D25, ND-5).

**npm packages** (phase-03 §13.4). Run from the project root:

```bash
npm i sortablejs trix
```

`sortablejs` is the one drag implementation used by section/item/menu reordering (§8.2, INV-5);
`trix` is the rich-text editor for the `richtext` field type and for `pages.content`.

**Nothing else.** No entry is requested in `PermissionRegistry`, `SettingsRegistry`, `Sidebar`,
`routes/**`, `bootstrap/**`, `config/**` (beyond the `config/purifier.php` that `mews/purifier`
publishes itself), `database/seeders/**` or `docs/**` by the schema work.

---

### Phase 3 enums and shared CMS value objects

**Delivered (all new files, nothing existing edited).**

```
app/Enums/Cms/ContentStatus.php           app/Enums/Cms/MediaCollection.php
app/Enums/Cms/SectionPlacement.php        app/Enums/Cms/ImageProfile.php
app/Enums/Cms/MenuLocation.php            app/Enums/Cms/MediaProcessingStatus.php
app/Enums/Cms/MenuItemLinkType.php        app/Enums/Cms/RevisionEvent.php
app/Enums/Cms/MenuVisibility.php          app/Enums/Cms/PreviewScope.php
app/Enums/Cms/PageLayout.php              app/Enums/Cms/StatisticValueMode.php   (additive)
app/Enums/Cms/RobotsDirective.php         app/Enums/Cms/FaqSource.php            (additive)
app/Enums/Cms/SitemapChangeFrequency.php
app/Enums/Cms/CtaVariant.php              app/Support/Cms/ImageProfile.php       (D24 definitions)
app/Enums/Cms/ButtonStyle.php             app/Support/Cms/ImageDerivative.php    (one derivative)
app/Enums/Cms/StatisticMetric.php         app/Support/Cms/SectionRegistry.php    (§6.1 registry)
```

All 16 enums of §3 are declared, all string-backed with snake_case values, all using
`App\Enums\Concerns\HasOptions` (so `label()`, `color()`, `static options()`, `static values()`), all
with the extra members §3 names. `php -l` clean; `./vendor/bin/pint --test app/Enums/Cms app/Support/Cms`
passes.

#### 1. Two naming deviations — decide once, then these `use` lines are final

| Contract says | Delivered as | Why | What to do |
|---|---|---|---|
| §3: "All in `app/Enums/`" | `App\Enums\Cms\*` | the build split Phase 3 onto new paths only, so nothing could collide with the existing flat `app/Enums` | keep the sub-namespace (recommended — it is where phase-04's CMS enums will want to live too) and use the `use` lines below; the alternative is `git mv app/Enums/Cms/*.php app/Enums/` plus a `namespace` edit in 18 files |
| §6.1: `App\Support\WebsiteSectionRegistry` | `App\Support\Cms\SectionRegistry` | same reason; the class is deliberately **not `final`** so the contract name can be carried by a subclass | either reference the FQCN, or create the one-line file below (a file I may not create, since `app/Support/*.php` outside `Cms/` is not mine) |

```php
// OPTIONAL new file: app/Support/WebsiteSectionRegistry.php
<?php

declare(strict_types=1);

namespace App\Support;

/** The phase-03 §6.1 name for App\Support\Cms\SectionRegistry. */
class WebsiteSectionRegistry extends \App\Support\Cms\SectionRegistry {}
```

**Phase 4 (and 14, 15, 19-23) must import `ContentStatus` from here** — one enum, `PostStatus` does not
exist (F-5.1):

```php
use App\Enums\Cms\ContentStatus;   // services, portfolio_items, team_members, success_stories, blog_posts
```

#### 2. Model casts — copy-paste per table

```php
// App\Models\Cms\WebsiteSection
protected $casts = [
    'placement' => \App\Enums\Cms\SectionPlacement::class,
    'status' => \App\Enums\Cms\ContentStatus::class,
    'content' => 'array',
    'published_content' => 'array',
    'is_enabled' => 'boolean',
    'has_unpublished_changes' => 'boolean',
    'published_at' => 'datetime',
    'draft_updated_at' => 'datetime',
];
// NOTE: section_key is NEVER cast — it is a registry key, not an enum ([D-W3-9]).

// App\Models\Cms\WebsiteSectionItem
'content' => 'array',
'metric' => \App\Enums\Cms\StatisticMetric::class,
'value_mode' => \App\Enums\Cms\StatisticValueMode::class,
'manual_value' => 'string',            // decimal(15,2) as a string — CLAUDE.md §1.4, INV-12
'is_enabled' => 'boolean',

// App\Models\Cms\Menu
'location' => \App\Enums\Cms\MenuLocation::class,
'is_active' => 'boolean',

// App\Models\Cms\MenuItem
'link_type' => \App\Enums\Cms\MenuItemLinkType::class,
'visibility' => \App\Enums\Cms\MenuVisibility::class,
'route_params' => 'array',
'open_new_tab' => 'boolean', 'rel_nofollow' => 'boolean', 'is_enabled' => 'boolean',
'depth' => 'integer',

// App\Models\Cms\Page
'layout' => \App\Enums\Cms\PageLayout::class,
'status' => \App\Enums\Cms\ContentStatus::class,
'show_banner' => 'boolean', 'is_system' => 'boolean',
'has_unpublished_changes' => 'boolean', 'published_at' => 'datetime',

// App\Models\Cms\CtaBlock
'variant' => \App\Enums\Cms\CtaVariant::class,
'primary_style' => \App\Enums\Cms\ButtonStyle::class,
'secondary_style' => \App\Enums\Cms\ButtonStyle::class,
'status' => \App\Enums\Cms\ContentStatus::class,
'primary_new_tab' => 'boolean', 'secondary_new_tab' => 'boolean', 'usage_count' => 'integer',

// App\Models\Cms\Faq
'status' => \App\Enums\Cms\ContentStatus::class,
'is_featured' => 'boolean',

// App\Models\Cms\FaqCategory
'is_enabled' => 'boolean',

// App\Models\Cms\SeoMeta
'robots' => \App\Enums\Cms\RobotsDirective::class,
'sitemap_changefreq' => \App\Enums\Cms\SitemapChangeFrequency::class,
'sitemap_include' => 'boolean',
'sitemap_priority' => 'decimal:1',
'last_checked_at' => 'datetime',

// App\Models\Cms\MediaAsset
'collection' => \App\Enums\Cms\MediaCollection::class,
'profile' => \App\Enums\Cms\ImageProfile::class,
'derivatives_status' => \App\Enums\Cms\MediaProcessingStatus::class,
'variants' => 'array',
'derivatives_generated_at' => 'datetime',

// App\Models\Cms\CmsRevision
'event' => \App\Enums\Cms\RevisionEvent::class,
'is_published_snapshot' => 'boolean',
'created_at' => 'datetime',            // no updated_at — a revision is never edited
```

`sitemap_generations.trigger` and `.status` are left as **plain strings on purpose**: §2.14 declares no
cast for them, unlike every other status column in the contract. If a later reviewer wants enums there,
they are two new files, not a change to anything above.

#### 3. `resources/data/icons.php` — the allowlist must contain these seven names

The section-type icons the registry ships (`SectionRegistry::icons()` returns exactly this list, so a
test can assert it rather than a human):

```
bars-3            (header)
sparkles          (hero)
information-circle (about)
document-text     (rich_content)
question-mark-circle (faq)
megaphone         (cta)
bars-3-bottom-left (footer)
```

The registry deliberately does **not** carry the `in:` rule for an `icon` **field** — loading a data file
would stop it being pure arrays. The Form Request adds it:

```php
// StoreWebsiteSectionDraftRequest / UpdateWebsiteSectionRequest
$rules = SectionRegistry::rulesFor($section->section_key, 'content');
$icons = collect(require resource_path('data/icons.php'))->flatten()->all();   // the grouped allowlist of §6.6

foreach (SectionRegistry::fields($section->section_key) as $key => $field) {
    if ($field['type'] === SectionRegistry::TYPE_ICON) {
        $rules['content.'.$key][] = Rule::in($icons);
    }
}
// The same loop applies to itemRulesFor() — the statistic, why_choose_us and highlight
// repeaters each declare an `icon` field.
```

#### 4. Blade views the registry and the enums name (none of them mine to create)

```
resources/views/site/sections/header.blade.php         (SectionRegistry::view('header'))
resources/views/site/sections/hero.blade.php
resources/views/site/sections/about.blade.php
resources/views/site/sections/rich_content.blade.php
resources/views/site/sections/faq.blade.php
resources/views/site/sections/cta.blade.php
resources/views/site/sections/footer.blade.php
resources/views/site/cta/banner.blade.php              (CtaVariant::view())
resources/views/site/cta/card.blade.php
resources/views/site/cta/inline.blade.php
resources/views/site/cta/split.blade.php
resources/views/site/cta/full_width.blade.php
```

`SectionRegistry::editView()` returns **null** for all seven types: §8.5's generic `<x-cms.field>` loop
plus the `tab` key below covers §8.6-§8.8's grouping, so no custom editor Blade file is required. A phase
that wants one sets `'edit_view' => 'admin.website.sections.editors.hero'` on that entry — one line.

#### 5. Settings keys these classes assume exist (all already in §5.1a — no registry change requested)

| key | read by | note |
|---|---|---|
| `website.image_quality` | `ImageProfile::derivatives($quality)` | the default **82** is `App\Support\Cms\ImageProfile::DEFAULT_QUALITY`; the class clamps anything outside 60-95 |
| `website.image_webp_enabled` | `ImageProfile::derivatives(webp: false)` drops the WebP half of the set | |
| `website.image_max_width` | `MediaService` (not this class) | no profile declares a width above 2560 |
| `company.founded_year` | `StatisticsProvider` for `StatisticMetric::YearsExperience` | null / non-numeric / future ⇒ the statistic renders nothing (INV-12) |

`MediaCollection::ROOT` is `'cms'`, matching §6.8's `cms/{Y}/{m}/{ulid}/{ulid}.{ext}` and the
`Disallow: /storage/cms/originals` line of §6.5.

#### 6. Decisions taken that another owner may want to overrule (each is one edit, in one place)

1. **Footer menu references.** §6.1's `footer` type declares three menu references
   (`menu_ref`, `menu_ref_2`, `legal_menu_ref`) but §2.2 gives `website_sections` **one** `menu_id`, and
   INV-3 forbids a foreign key in JSON. Delivered: `menu_ref` → the real `menu_id` column
   (`'ref_by' => 'id'`); `menu_ref_2` and `legal_menu_ref` → **by layout slot**
   (`'ref_by' => 'location'`, defaults `footer_secondary` / `footer_legal`), which is lossless because
   `UNIQUE uq_menus_location` allows one menu per slot and delete-safe because a missing menu leaves an
   empty column instead of a dangling id. The alternative is two more FK columns
   (`menu_id_2`, `legal_menu_id`) in the `website_sections` migration; if that is chosen, set
   `'column' => 'menu_id_2'` / `'legal_menu_id'` and `'ref_by' => 'id'` on those two fields.
2. **`faq_category_ref` is stored as the category slug**, not its id — a scalar rather than a foreign key
   in JSON (INV-3); `faq_categories.slug` is uniquely indexed, so `exists:faq_categories,slug` is exact.
   `FaqService::forSection()` must therefore resolve the category **by slug**.
3. **Two additive enums** the contract's §3 table does not list, both because CLAUDE.md §1.8 forbids a
   status as a string and both already database facts: `StatisticValueMode`
   (`website_section_items.value_mode`, the exact `IN` list of CHECK `chk_wsi_value`) and `FaqSource`
   (the `faq` type's `source` field, the three branches of `FaqService::forSection()`). Delete either and
   the only change needed is the corresponding registry `options()` call.
4. **Four additive registry keys** beyond §6.1's field vocabulary, each because nothing else can supply
   it: `tab` (§8.5's Content/Media/Buttons/Advanced grouping), `column` (INV-3 — which fields are real
   columns, not `content` keys), `ref_by` (id / location / slug, see 1 and 2), `kind` on a media role
   (`image` vs `video` — a `background_video` has no `ImageProfile`). `is_live` and `provider` are §6.1's
   own keys and are `false` / `null` for all seven Phase 3 types.
5. **Two classes named `ImageProfile`** — `App\Enums\Cms\ImageProfile` (the column cast, the type hint)
   and `App\Support\Cms\ImageProfile` (D24's definition table, which the enum delegates to). When a file
   needs both, the convention in the docblocks is
   `use App\Support\Cms\ImageProfile as ImageProfileSpec;`.
6. **`rich_content` default home sort is 95** (between `success_stories` 90 and `blog` 100), the one sort
   value §6.1 does not fix. It is a repeatable type, so `place()`'s `max + 10` governs in practice.
7. **`ButtonStyle::classes()`** writes the public buttons' Tailwind classes with the `brand-*` tokens
   Phase 2 publishes and a `dark:` counterpart for every utility. No site view may spell out a button
   colour (§3, §8.14). `uiVariant()` maps `outline` → the admin shell's `secondary`, which is the only
   case `x-ui.button` has no equivalent for.

#### 7. Useful entry points for the services, controllers and the seeder of §6.14

```php
use App\Enums\Cms\SectionPlacement;
use App\Enums\Cms\StatisticMetric;
use App\Support\Cms\SectionRegistry;

// place() — everything a fresh row needs, from the registry
$attributes = array_merge(SectionRegistry::columnDefaults($key), [
    'section_key' => $key,
    'placement' => $placement->value,
    'instance_key' => SectionRegistry::instanceKey($key, $placement, $page?->id),   // null for repeatable types
    'content' => SectionRegistry::defaults($key),
    'sort_order' => SectionRegistry::defaultSort($key, $placement),                 // seeder order: 10/20/95/110/120
]);

// a fresh repeater item
$item = array_merge(SectionRegistry::itemColumnDefaults($key, 'statistic'), [
    'group' => 'statistic',
    'content' => SectionRegistry::itemDefaults($key, 'statistic'),
]);

// the six statistics of §9, in seeder order 10..60
foreach (StatisticMetric::heroDefaults() as $i => $metric) {
    // value_mode = auto, metric = $metric, manual_value = a conservative fallback,
    // content = ['label' => $metric->defaultLabel(), 'suffix' => $metric->defaultSuffix()]
}

// renderer guard (INV-2) — never throws on an unknown key
if (! SectionRegistry::exists($section->section_key)) { /* log once, skip */ }

// the add-section modal (§8.4), grouped and ordered
SectionRegistry::forPlacement(SectionPlacement::Home);   // hero, about, rich_content, faq, cta
SectionRegistry::groups();                               // layout / content / engagement / business
SectionRegistry::isUnique($key), SectionRegistry::isRequired($key);   // disable-only types (INV-7)
```

`SectionRegistry::register($key, $definition)` exists so phases 4, 5, 14 and 15 add a type from their own
service provider instead of all editing one array ([D-W3-9]); `flush()` resets it for tests. Registering a
key twice throws.

**Nothing is requested in `PermissionRegistry`, `SettingsRegistry`, `Sidebar`, `routes/**`,
`bootstrap/**`, `config/**`, `database/seeders/**`, `tests/**` or `docs/**` by the enum and value-object
work** — beyond the optional one-line `app/Support/WebsiteSectionRegistry.php` of §1 and the
`resources/data/icons.php` entries of §3.

---

### Phase 3 services (app/Services/Cms/**, app/Support/RichText.php)

**Delivered (all new files; nothing existing edited).** `php -l` clean on every file;
`./vendor/bin/pint --test app/Services/Cms app/Support/RichText.php` passes. Nothing was run against the
database and no test was run (both owned by the Phase 2 workflow) — see §10 for what is unverified.

```
app/Support/RichText.php                         D25 sanitiser, closed profile map (cms, material)
app/Services/Cms/CacheVersion.php                D22 version stamp (contract: PublicCache version/bump/flush)
app/Services/Cms/CmsAuditor.php                  activity_log writer for query-builder writes (INV-16)
app/Services/Cms/ContentHasher.php               canonical payload + sha1 (contract: CmsHasher, INV-4)
app/Services/Cms/RevisionRecorder.php            the only writer of cms_revisions (append-only)
app/Services/Cms/SectionValidator.php            content / item / media validation against SectionRegistry
app/Services/Cms/SnapshotBuilder.php             published_content and preview payload builder
app/Services/Cms/SectionService.php              place, saveDraft, rename, enable/disable/toggle, reorder,
                                                 duplicate, remove, restore, items, assertPublishable
                                                 (contract: WebsiteSectionService minus publish/unpublish/revert)
app/Services/Cms/ContentPublisher.php            publish, unpublish, revert, schedule, publishDue, verify
                                                 (sections AND pages)
app/Services/Cms/MediaService.php                D24 store, derivatives, url/srcset/sizes/toSnapshot,
                                                 usage, recountUsage, delete guard
app/Services/Cms/Media/GdImageProcessor.php      GD pixel work (orientation, EXIF strip, resize, crop)
app/Services/Cms/SeoService.php                  D23 for/save/ensure/copy/rules/editorRules/completeness/
                                                 auditRows/sitemapEntries/robotsTxt
app/Services/Cms/SitemapGenerator.php            urls/generate/cached/regenerate/extend (contract: SitemapService)
app/Services/Cms/Data/SeoPayload.php             readonly DTO (contract: App\Support\SeoPayload)
app/Services/Cms/Data/SitemapEntry.php           readonly DTO (contract: SitemapUrl)
```

The six exception classes already present in `app/Services/Cms/Exceptions/` (written before this run)
are used as they are; none was modified.

#### 1. Composer

```bash
composer require mews/purifier:^3.4
```

**Optional hardening, not a dependency.** `RichText` enforces the allowlist with its own DOM walker,
which always runs. When `ezyang/htmlpurifier` (pulled in by `mews/purifier`) is installed it runs first
as a parser-normalisation pass configured **from `RichText::PROFILES`**, never from a config key named by
a caller; if that pass throws for any reason `RichText` falls back to the walker alone. Output obeys the
same allowlist either way (verified here without the package: 0 failures across script, event-handler,
`javascript:`, iframe-host, Blade/PHP, `data:` and CSS cases, idempotency included).

```bash
composer require intervention/image:^3
```

**Not required by `MediaService`.** The pipeline uses PHP's bundled GD directly (present here with
JPEG, PNG, WebP, GIF and AVIF support), because re-encoding through GD *is* the EXIF strip. The package
may still be installed for later phases; nothing in Phase 3 calls it. If GD is absent or cannot encode a
format, that upload is refused with a readable reason instead of being stored with its metadata.

`config/purifier.php` — only if `mews/purifier` is installed. `RichText` does not read it; it exists so
FT-36b's grep finds exactly the two committed profiles and so the package's default file (which carries
extra profiles) is not published. Replace the published file with exactly this:

```php
<?php

// Mirrors App\Support\RichText::PROFILES (D25, ND-5). RichText builds its engine config from that
// constant and never reads this file; do not add a profile here — add it to RichText, reviewed as a
// security change.
return [
    'encoding' => 'UTF-8',
    'finalize' => true,
    'ignoreNonStrings' => false,
    'cachePath' => storage_path('app/purifier'),
    'cacheFileMode' => 0755,
    'settings' => [
        'cms' => [
            'HTML.Doctype' => 'HTML 4.01 Transitional',
            'HTML.Allowed' => 'p[title|class],br,strong,em,u,s,h2,h3,h4,ul,ol,li,blockquote,a[href|title|target|rel|class],img[src|alt|width|height|title|class],figure,figcaption,table[width|height],thead,tbody,tr,th[width|height],td[width|height],hr,span[title|class],iframe[src|width|height|title|class]',
            'HTML.SafeIframe' => true,
            'URI.SafeIframeRegexp' => '%^(https://www\.youtube\.com/embed/|https://player\.vimeo\.com/video/|https://www\.google\.com/maps/embed)%',
            'URI.AllowedSchemes' => ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true],
            'Attr.AllowedFrameTargets' => ['_blank', '_self'],
        ],
        'material' => [
            'HTML.Doctype' => 'HTML 4.01 Transitional',
            'HTML.Allowed' => 'p,br,strong,em,u,s,h1,h2,h3,h4,h5,h6,ul,ol,li,blockquote,a[href|target|rel],img[src|alt|width|height],figure,figcaption,table[width|height],thead,tbody,tr,th[width|height],td[width|height],hr,span,div,small,b,i,sub,sup,*[title|class|style|align]',
            'URI.AllowedSchemes' => ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true, 'data' => true],
            'Attr.AllowedFrameTargets' => ['_blank', '_self'],
        ],
    ],
];
```

No npm package is needed by the services.

#### 2. Container binding — `app/Providers/AppServiceProvider.php`, inside `register()`

```php
// Phase 3: one version stamp and one batch state per request / queued job (D22).
$this->app->scoped(\App\Services\Cms\CacheVersion::class);
```

Every other service autowires (constructor injection of `DatabaseManager`, the cache `Repository`, the
validation `Factory`, the filesystem `Factory`, `UrlGenerator`, `Router`, `Container`, the auth `Factory`
and `SettingsRepository`). Without the `scoped` line everything stays correct; only the memoised stamp
and cross-service bump collapsing in `CacheVersion::batch()` need the shared instance.

#### 3. Models the services require — `app/Models/Cms/` (the models owner's files; not written by me)

Five models the services type-hint do not exist yet. The services persist through the query builder and
only **read** through these models (`findOrFail`, `hydrate`, `withoutGlobalScopes`), so the requirements
are small but exact:

| Class | Must have |
|---|---|
| `App\Models\Cms\WebsiteSection` | `$table = 'website_sections'`; `SoftDeletes`; casts `content` / `published_content` => `array`, `placement` => `SectionPlacement`, `status` => `ContentStatus`; `section_key` never cast; `has_unpublished_changes` not fillable |
| `App\Models\Cms\WebsiteSectionItem` | `$table = 'website_section_items'`; `SoftDeletes`; `content` => `array`, `manual_value` => `string` (enums block §2) |
| `App\Models\Cms\SeoMeta` | `$table = 'seo_meta'` (**explicit** — the default would be `seo_metas`); **no** `SoftDeletes`; casts per the enums block §2 |
| `App\Models\Cms\CmsRevision` | `$table = 'cms_revisions'`; **no** `SoftDeletes`; `const UPDATED_AT = null;`; `event` => `RevisionEvent`; `snapshot` may be `array` or uncast (both handled) |
| `App\Models\Cms\SitemapGeneration` | `$table = 'sitemap_generations'`; **no** `SoftDeletes`; `const UPDATED_AT = null;`; `providers` => `array` |

Also used and already present: `MediaAsset::path()`, `isImage()`, `isVideo()`, `isUsable()`, `isInUse()`
and `LogsActivityWithContext::withReason()`.

#### 4. Queued jobs the services dispatch when the classes exist (`app/Jobs/Cms/`, not mine)

The services check `class_exists()` and fall back safely when a job is absent. Constructors must be
exactly these, because the services instantiate them:

```php
// app/Jobs/Cms/GenerateImageDerivatives.php — dispatched after commit by MediaService::store()/regenerate().
// Absent: MediaService generates synchronously after commit (a failure is recorded on the row, never thrown).
final class GenerateImageDerivatives implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 120];

    public bool $afterCommit = true;

    public function __construct(public MediaAsset $asset) {}

    public function uniqueId(): string
    {
        return 'media:'.$this->asset->getKey();
    }

    public function handle(MediaService $media): void
    {
        $media->generateDerivatives($this->asset);
    }
}

// app/Jobs/Cms/RegenerateSitemap.php — dispatched after commit by ContentPublisher (page publish,
// unpublish, scheduled promotion) and SeoService::save(). Absent: nothing is lost, the sitemap cache is
// version-stamped and the bump already invalidated it.
final class RegenerateSitemap implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $uniqueFor = 300;

    public function __construct(public string $trigger = 'publish')
    {
        $this->delay(60);
    }

    public function uniqueId(): string
    {
        return 'sitemap';
    }

    public function handle(SitemapGenerator $sitemap): void
    {
        $sitemap->regenerate($this->trigger);
    }
}

// app/Jobs/Cms/WarmPublicPageCache.php — dispatched by CacheVersion::bump() when
// website.cache_warm_enabled is on. It must take NO constructor arguments.
```

#### 5. Exception rendering — `bootstrap/app.php`, replace the empty `withExceptions` body

```php
->withExceptions(function (Exceptions $exceptions): void {
    // Phase 3 CMS refusals read as 422 / 403 with the reason, never a stack trace.
    $exceptions->render(function (\App\Services\Cms\Exceptions\InvalidSectionContentException $e) {
        throw $e->toValidationException();
    });
    $exceptions->render(function (\App\Services\Cms\Exceptions\UnsupportedUploadException $e) {
        throw $e->toValidationException('file');
    });
    $exceptions->render(function (\App\Services\Cms\Exceptions\UnknownSectionTypeException $e) {
        throw \Illuminate\Validation\ValidationException::withMessages(['section_key' => [$e->getMessage()]]);
    });
    $exceptions->render(function (\App\Services\Cms\Exceptions\MediaInUseException $e, \Illuminate\Http\Request $request) {
        return $request->expectsJson()
            ? response()->json(['message' => $e->getMessage(), 'usage' => $e->usage], 403)
            : back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()])->with('media_usage', $e->usage);
    });
    $exceptions->render(function (\App\Services\Cms\Exceptions\ContentActionNotAllowedException $e, \Illuminate\Http\Request $request) {
        return $request->expectsJson()
            ? response()->json(['message' => $e->getMessage()], 403)
            : back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
    });
})->create();
```

(FT-16's "DELETE on hero returns 403 from the policy" stays the policy's job; `SectionService::remove()`
refuses a required type too, so a policy gap still cannot delete one. FT-38 wants 403 for a media item in
use: the JSON branch gives it; a form request should be answered by the policy reading `usage_count`.)

#### 6. How the other Phase 3 pieces call the services

```php
// Admin controllers — one service call each.
$section = $sections->place($key, $placement, $page);
$section = $sections->saveDraft($section, $request->validated('content', []), $request->validated('media', []));
$section = $sections->rename($section, $request->input('name'), $request->input('anchor'));
$section = $sections->toggle($section, $request->boolean('enabled'), $request->input('reason'));
$sections->reorder($placement, $page, $request->input('order', []));      // the FULL ordered id list
$copy    = $sections->duplicate($section);
$sections->remove($section, (string) $request->input('reason'));
$item    = $sections->upsertItem($section, $group, $request->validated(), $item);   // $item null = add
$sections->toggleItem($item, $request->boolean('enabled'));
$sections->reorderItems($section, $group, $request->input('order', []));
$sections->deleteItem($item);

$publisher->publish($sectionOrPage, $request->input('label'));            // change_status
$publisher->unpublish($sectionOrPage, (string) $request->input('reason'));
$publisher->revert($sectionOrPage, $revision, (string) $request->input('reason'));
$publisher->schedule($page, Carbon::parse($request->input('publish_at')));

$asset = $media->store($request->file('file'), MediaCollection::from($request->input('collection', 'general')), null, $request->only('alt_text', 'title', 'caption'));
$media->updateDetails($asset, $request->safe()->only('alt_text', 'title', 'caption'));
$media->regenerate($asset);
$media->delete($asset, $request->input('reason'));                         // MediaInUseException while used
$usage = $media->usage($asset);                                            // the usage popover

$seo->save($pageOrRouteKey, $request->validated('seo', []));
$cache->flush('Flushed from the Website overview');                        // admin.website.cache.flush

// Form Requests (D23): never restate a seo_meta rule.
return array_merge($own, app(\App\Services\Cms\SeoService::class)->rules());     // entity forms
return app(\App\Services\Cms\SeoService::class)->editorRules();                   // the SEO screen

// Public side
$live    = $sections->publishedPayload($section);     // or read published_content in the one query of §6.9
$draft   = $sections->draftPayload($section);         // preview only (§6.12)
$seoData = $seo->for($page /* or 'site.home' */, preview: $isPreview);
return response($seo->robotsTxt(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);        // site.robots
$xml = $sitemap->cached(); abort_if($xml === null, 404);                                        // site.sitemap
$xml = $sitemap->cached((int) $index); abort_if($xml === null, 404);                            // site.sitemap.chunk
return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
```

`MediaService::toSnapshot()` produces exactly the keys the existing
`resources/views/components/site/image.blade.php` reads (`url`, `srcset`, `webp_srcset`, `sizes`, `width`,
`height`, `alt`, `is_video`, `poster`, `mime_type`). A statistic item in a snapshot is exactly what
`components/site/stat.blade.php` reads (`content`, `value`, `metric`, `value_mode`); an `auto` item carries
`value = null` and `is_live = true`, and the renderer resolves it through `StatisticsProvider::valueFor()`
with `manual_value` as the fallback. `components/site/prose.blade.php` already calls
`RichText::sanitize($value, $profile)` statically, which is the signature delivered.

A `PageService` written elsewhere must hash drafts with `app(ContentHasher::class)->pageHash($content)`,
or `pages.has_unpublished_changes` will disagree with what `ContentPublisher` writes.

#### 7. Scheduler — `routes/console.php`

```php
use App\Models\Cms\WebsiteSection;
use App\Services\Cms\ContentPublisher;
use App\Services\Cms\MediaService;
use App\Services\Cms\SitemapGenerator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('cms:publish-scheduled', function (ContentPublisher $publisher) {
    $this->info(count($publisher->publishDue()).' scheduled page(s) published.');
})->purpose('Promote scheduled pages whose publish time has come');

Artisan::command('cms:sitemap-generate', function (SitemapGenerator $sitemap) {
    $row = $sitemap->regenerate('scheduled');
    $this->info("Sitemap: {$row->url_count} URLs, status {$row->status}.");
})->purpose('Rebuild sitemap.xml');

Artisan::command('cms:media-recount', function (MediaService $media) {
    $media->recountUsage();
    $this->info('Media usage counts refreshed.');
})->purpose('Recount media_assets.usage_count from every reference');

Artisan::command('cms:verify-published-snapshots', function (ContentPublisher $publisher) {
    $failures = 0;

    WebsiteSection::query()->where('status', 'published')->where('is_enabled', true)
        ->each(function (WebsiteSection $section) use ($publisher, &$failures): void {
            foreach ($publisher->verify($section) as $problem) {
                $failures++;
                $this->error("Section #{$section->getKey()}: {$problem}");
            }
        });

    return $failures === 0 ? 0 : 1;
})->purpose('Assert every live section has a valid published snapshot');

Schedule::command('cms:publish-scheduled')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('cms:sitemap-generate')->dailyAt('02:30');
Schedule::command('cms:media-recount')->dailyAt('04:00');
Schedule::command('cms:verify-published-snapshots')->dailyAt('04:30');
```

#### 8. Settings keys read (all declared by §5.1a / §5.2 or Phase 2; each read passes the contract default)

`SettingsRepository::get()` returns the caller's default when a row is not seeded, so every read passes
the §5 default explicitly: `website.image_quality` (82, clamped 60-95), `website.image_max_width` (2560,
clamped 320-8000), `website.image_webp_enabled` (true), `website.cache_warm_enabled` (true),
`seo.robots_txt_mode` (`auto`), `seo.robots_txt_custom` (''), `seo.sitemap_changefreq_default`
(`weekly`), `seo.sitemap_priority_default` (`0.5`), and the Phase 2 keys `security.max_upload_mb` (10),
`seo.meta_title`, `seo.meta_description`, `seo.meta_keywords`, `seo.canonical_base_url`, `seo.og_image`,
`branding.og_image`, `seo.robots_indexable` (true), `seo.sitemap_enabled` (true),
`maintenance.maintenance_mode` (false), `maintenance.public_site_enabled` (true), `company.name`,
`company.short_description`, `localization.locale` (`en`). **No `SettingsRegistry` change is requested
beyond §5.1a / §5.2 themselves.**

#### 9. Decisions taken that an owner may want to overrule (each is local to one file)

1. **Service names follow the build plan, not §6 verbatim**: `SectionService` + `ContentPublisher`
   (contract `WebsiteSectionService` and the publish half of `PageService`), `CacheVersion` (contract
   `PublicCache::version/bump/flush`; the request-keyed half — `key(Request)` / `remember()` — belongs with
   the `CachePublicResponse` middleware, which can build its key with `CacheVersion::key('page', [...])`),
   `SitemapGenerator` (contract `SitemapService`), `SeoService::robotsTxt()` (contract
   `RobotsService::render()`), DTOs under `App\Services\Cms\Data` (contract `App\Support`). Later phases add
   sitemap URLs with `SitemapGenerator::extend($key, $provider)` or through
   `App\Support\SitemapRegistry::providers()` once that class exists — the generator reads both.
2. **Drafts may be incomplete, never malformed.** `saveDraft()` relaxes `required` to `nullable` so
   autosave works mid-edit; `publish()` enforces every `required` field and names the first empty one
   (FT-13). Repeater items always enforce `required` (they are saved one at a time).
3. **The robots lockdown beats custom robots.txt.** With `robots_indexable` off, maintenance on or the
   public site off, `robotsTxt()` returns `Disallow: /` even in `custom` mode (§6.5's table lists custom
   first; FT-47 and "the strictest wins" say otherwise). Off-domain `Sitemap:` lines in custom text are
   also dropped at render time.
4. **A live page cannot be scheduled** (`schedule()` refuses): with one `status` column, scheduling a
   published page would take it off the site until the date. A scheduled page is snapshotted **when it is
   promoted**, so the draft at that moment goes live. `publish()` on a non-live page whose `published_at`
   is in the future marks it `scheduled` instead (§6.4).
5. **`pages.content_hash` covers `content` only** — title, banner and template are live columns (§2.7).
6. **Removing a unique section releases `instance_key`** (NULL with the soft delete) so the type can be
   placed again; `restore()` reclaims it and is refused if another instance exists meanwhile.
7. **Anchor uniqueness is also checked in PHP**, because `uq_ws_anchor(placement, page_id, anchor)` cannot
   protect the home page (MariaDB treats its NULL `page_id` as distinct).
8. **An item image may fall back to the item's `label` as alt text** (the registry's badge help says so);
   section media slots require `alt_text` strictly.
9. **Iframes kept by the `cms` profile get sanitiser-owned attributes** never read from input:
   `loading="lazy"`, `allowfullscreen`, `sandbox="allow-scripts allow-same-origin allow-popups allow-presentation"`
   (the embed cannot navigate the page). `class` is restricted to `RichText::CLASSES`; a quote or angle
   bracket is removed from `title` / `alt`. Content after a literal `</body>` in pasted HTML is dropped.
10. **Media storage details.** `variants`: `{"960": {"path", "format", "mime_type", "width", "height",
    "size_bytes", "webp": {"path", "format", "mime_type", "size_bytes"}}}`. AVIF and GIF sources get a PNG
    (transparent profiles, GIF) or JPEG fallback in the "original format" slot. An animated GIF within the
    width ceiling is stored byte for byte (GIF carries no EXIF). Images above 40 MP are refused at upload.
    `checksum` is the sha256 of the uploaded bytes; a re-upload restores a trashed row. A delete is a soft
    delete and the files stay (INV-14, [D-W3-17]).
11. **Usage counts places, not references**: a section that both places an image and still shows it in
    its published snapshot counts once, and an image still visible in a published snapshot stays
    undeletable until the section is republished without it.
12. **The services bump the cache version themselves, after commit.** If the §10.1 events and the
    `BumpPublicCacheVersion` listener are added later, do not bump again for the acts these services
    already cover — FT-25 asserts exactly +1 per publish.

#### 10. Not done / unverified — for the integration stage

- **No database or test run.** Every query, lock, `afterCommit` path and the generated-column behaviour
  is unexercised. A reflection check confirms every class, enum method, registry member and exception
  factory the services call exists — except the five models of §3. `RichText` (sanitiser, CSS) and
  `GdImageProcessor` (resize, crop, never-upscale, alpha, pixel guard) were exercised with standalone
  PHP scripts; `ContentHasher` and `SitemapEntry::priority()` likewise.
- Not in this role and not written: `StatisticsProvider`, `MenuService`, `PageService`
  (create / saveDraft / slugFor / reservedSlugs / duplicate / delete), `CtaBlockService`, `FaqService`,
  `PreviewService`, `PublicPageService`, middleware, events, listeners, jobs, notifications, policies.
  `SnapshotBuilder` resolves menus, CTA blocks and FAQs itself (read-only) so publishing does not wait on
  those services.
- `SeoService::for()` derives the canonical path for `Page` and route-key targets only; a later-phase
  model must pass `$path`.
- The optional `mews/purifier` pass is untested (the package is not installed); any failure in it falls
  back to the tested walker.

**Nothing is requested in `PermissionRegistry`, `Sidebar`, `routes/web.php`, `routes/admin.php`,
`database/seeders/**`, `tests/**` or `docs/**` by the services** — only §1 (composer and the optional
`config/purifier.php`), §2 (one `scoped` binding), §3 (the five models), §4 (jobs), §5 (exception
rendering in `bootstrap/app.php`) and §7 (`routes/console.php`).
