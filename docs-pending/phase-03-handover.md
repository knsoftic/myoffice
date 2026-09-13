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

---

### Phase 3 models and policies (app/Models/Cms/**, app/Policies/Cms/**)

**Delivered.** `php -l` clean, `./vendor/bin/pint --test app/Models/Cms app/Policies/Cms` passes. Verified
with two scratch scripts that compile every scope with `toSql()` and exercise helpers, guards and every
policy rule in memory inside `DB::pretend()` (zero statements reached MariaDB). No test or migration run.

```
app/Models/Cms/WebsiteSection.php        NEW   website_sections
app/Models/Cms/WebsiteSectionItem.php    NEW   website_section_items
app/Models/Cms/WebsiteSectionMedia.php   NEW   website_section_media (Pivot, used by ->using())
app/Models/Cms/Faq.php                   NEW   faqs
app/Models/Cms/FaqCategory.php           NEW   faq_categories
app/Models/Cms/FaqWebsiteSection.php     NEW   faq_website_section (Pivot, used by ->using())
app/Models/Cms/SeoMeta.php               NEW   seo_meta            (no SoftDeletes, delete throws)
app/Models/Cms/CmsRevision.php           NEW   cms_revisions       (no SoftDeletes, write-once)
app/Models/Cms/SitemapGeneration.php     NEW   sitemap_generations (no SoftDeletes, write-once)
app/Models/Cms/Concerns/{PublishesSnapshots,ForbidsDeletion,ForbidsUpdates}.php   NEW
app/Models/Cms/{Page,Menu,MenuItem,CtaBlock,MediaAsset}.php                        COMPLETED (see 4)
app/Policies/Cms/{WebsiteSection,WebsiteSectionItem,Menu,MenuItem,Page,CtaBlock,Faq,FaqCategory,
                  SeoMeta,Media,CmsRevision,SitemapGeneration}Policy.php + Concerns/ChecksCmsPermissions.php
```

#### 1. `app/Providers/AppServiceProvider.php` — append to the `POLICIES` constant

Integration list E.2 plus one row (`SitemapGenerationPolicy`, for the sitemap history / regenerate screen).
`MediaPolicy` is the contract's name, so explicit registration is mandatory for it.

```php
        \App\Models\Cms\WebsiteSection::class => \App\Policies\Cms\WebsiteSectionPolicy::class,
        \App\Models\Cms\WebsiteSectionItem::class => \App\Policies\Cms\WebsiteSectionItemPolicy::class,
        \App\Models\Cms\Menu::class => \App\Policies\Cms\MenuPolicy::class,
        \App\Models\Cms\MenuItem::class => \App\Policies\Cms\MenuItemPolicy::class,
        \App\Models\Cms\Page::class => \App\Policies\Cms\PagePolicy::class,
        \App\Models\Cms\CtaBlock::class => \App\Policies\Cms\CtaBlockPolicy::class,
        \App\Models\Cms\Faq::class => \App\Policies\Cms\FaqPolicy::class,
        \App\Models\Cms\FaqCategory::class => \App\Policies\Cms\FaqCategoryPolicy::class,
        \App\Models\Cms\SeoMeta::class => \App\Policies\Cms\SeoMetaPolicy::class,
        \App\Models\Cms\MediaAsset::class => \App\Policies\Cms\MediaPolicy::class,
        \App\Models\Cms\CmsRevision::class => \App\Policies\Cms\CmsRevisionPolicy::class,
        \App\Models\Cms\SitemapGeneration::class => \App\Policies\Cms\SitemapGenerationPolicy::class,
```

#### 2. `app/Support/Modules.php` — append to `MODEL_MODULES` (closes integration M-17 for class-level checks)

Every Phase 3 model now declares `moduleSlug()` (integration C.4), but `Modules::moduleForSubject()` calls
it on **objects only**. A class-string check — `can('viewAny', CtaBlock::class)`, `can('create',
MediaAsset::class)` — falls back to the map / naming convention, which guesses `cta_blocks`,
`media_assets`, `menu_items`, `website_section_items`, `seo_metas`, so rule 1 of `Gate::before` does not fire
and a Super Admin passes a disabled module. Apply **after** integration C.2 has registered
`website_cta_blocks` and `website_media`:

```php
        \App\Models\Cms\MenuItem::class => 'menus',
        \App\Models\Cms\WebsiteSectionItem::class => 'website_sections',
        \App\Models\Cms\CtaBlock::class => 'website_cta_blocks',
        \App\Models\Cms\MediaAsset::class => 'website_media',
        \App\Models\Cms\SeoMeta::class => 'seo',
        \App\Models\Cms\SitemapGeneration::class => 'seo',
```

(`Page`, `Menu`, `Faq`, `FaqCategory`, `WebsiteSection` resolve by convention. `CmsRevision` has no fixed
module: its `moduleSlug()` returns the revisionable's module per instance, and `CmsRevisionPolicy::viewAny`
checks `website_sections.view_logs` / `pages.view_logs`, which are module-gated themselves. Every policy
checks through `$user->can('<module>.<ability>')`, so non-Super-Admin users are module-gated either way.)

#### 3. Policy methods the controllers call (`$this->authorize()` / `Gate::authorize()`)

| Policy | Methods beyond viewAny / view / create / update / delete / restore / forceDelete (always false) |
|---|---|
| `WebsiteSectionPolicy` | `manageItems`, `reorder` (class), `publish`, `unpublish`, `toggle`, `duplicate`, `viewRevisions`, `revert`, `preview`, `flushCache` (class). `delete` false for a required type (INV-7, FT-16); `update`/`publish`/`revert`/`duplicate` false for an orphaned type; `duplicate` false for a unique type; `update` false when `archived` |
| `WebsiteSectionItemPolicy` | `create(User, ?WebsiteSection)`, `toggle`, `reorder(User, ?WebsiteSection)` — all `website_sections.edit` |
| `MenuPolicy` | `reorder`, `toggle`, `linkCheck` |
| `MenuItemPolicy` | `create(User, ?Menu)` (`menus.create`), `toggle` (`menus.change_status`) |
| `PagePolicy` | `changeSlug` (system page also needs `pages.change_status`), `publish`, `schedule`, `unpublish`, `duplicate`, `viewRevisions`, `revert`, `preview`, `manageSections` (+ `website_sections.edit`), `export` (class), `print` (class). `delete` false for `is_system` (FT-17) |
| `CtaBlockPolicy` | `changeKey` (false while in use), `toggle`, `usage`, `viewLogs` (class). `delete` false while `usage_count > 0` |
| `FaqPolicy` / `FaqCategoryPolicy` | `toggle`, `reorder` (class) |
| `SeoMetaPolicy` | `bulkUpdate`, `editRobots`, `regenerateSitemap`, `export`, `viewLogs` (all class). `create` = `seo.edit`; `delete`/`restore` always false |
| `MediaPolicy` | `upload` (class; `create` delegates to it), `regenerate` (images only), `usage`, `download`, `viewLogs` (class). `delete` false while `usage_count > 0` (FT-38) |
| `CmsRevisionPolicy` | `revert` (revisionable module's `change_status`); `view` = its `view_logs`; create/update/delete false |
| `SitemapGenerationPolicy` | `viewAny`/`view` = `seo.view`, `create` = `seo.edit` (a rebuild); update/delete false |

A trashed row is read-only in every policy (writes false, `restore` true only when trashed). Policies
never query: a parent is consulted only when passed as an argument or already eager-loaded.

#### 4. Model contract the controllers, views and remaining services rely on

- **`WebsiteSection::published()` / `Page::published()` read the snapshot (D22):** they filter
  `status = published` (sections also `published_content IS NOT NULL`) **and restrict the SELECT to
  `PUBLIC_COLUMNS` unless the query already selects columns** (so `whereHas`/`withCount` constraints keep
  `*` / `count(*)`). Admin lists and badges must use `withStatus()`, which keeps every column. The public
  reads are `WebsiteSection::forPublic($placement, $page)` (the one query of §6.9) and
  `Page::forPublic($slug)`. `draftContent()` / `draftBody()` throw on a row loaded that way.
- **Pages changed from the partial file:** `scopePublished()` now reads the snapshot as above (previously
  status only); `hasUnpublishedChanges()` and `scopePublishedSnapshot()` moved into
  `Concerns\PublishesSnapshots` (columns now table-qualified); added `moduleSlug()`, `withStatus()`.
  `Menu` gained `moduleSlug()` and `withTree(bool $enabledOnly)`; `MenuItem` gained `moduleSlug()` and a
  saving guard (depth > 1, depth/parent mismatch, self-parent throw `LogicException`); `CtaBlock` gained
  `moduleSlug()` and `withStatus()`; `MediaAsset` gained `moduleSlug()` and `sections()->using(WebsiteSectionMedia)`.
- **`has_unpublished_changes` is never written:** not fillable, and a dirty value is stripped in `saving`.
- **Other scopes:** `enabled()` (WebsiteSection, WebsiteSectionItem, FaqCategory, MenuItem), `ordered()`
  (all content models), `forPlacement()` (WebsiteSection; `page` without a page matches nothing),
  `forLocation()` (Menu, MenuItem), `visible()` (anonymous rule of §9 on every public-facing model),
  `WebsiteSection::orphaned()/ofType()/withUnpublishedChanges()`, `WebsiteSectionItem::statistics()/inGroup()/usingMetric()/auto()`,
  `Faq::inCategory(?cat)/inCategorySlug()/featured()/standalone()/attached()/forFaqable()`,
  `SeoMeta::forRoute()/forTarget()/indexable()/inSitemap()`, `CmsRevision::forTarget()/publishedSnapshots()/prunable()/latestFirst()`,
  `SitemapGeneration::successful()/failures()/latestFirst()`.
- **Casts:** `website_section_items.manual_value` is `decimal:2` (a string, `"1500.00"`; the enums block
  §2 suggested `string` — same type, normalised scale). `seo_meta.sitemap_priority` is `decimal:1`.
  `cms_revisions.snapshot` is **uncast** on purpose (byte-exact canonical JSON the hash was computed from);
  read it with `$revision->snapshotPayload()`.
- **Append-only guards:** an Eloquent `delete()` on `SeoMeta`, `CmsRevision` or `SitemapGeneration` throws;
  an Eloquent update of `CmsRevision` or `SitemapGeneration` throws; both stamp `created_by` on insert.
  The future `PruneCmsRevisions` job must prune through the query builder and never touch a published
  snapshot, e.g.
  `DB::table('cms_revisions')->where('revisionable_type', $type)->where('revisionable_id', $id)->where('is_published_snapshot', false)->whereNotIn('id', $keepIds)->delete();`
- **`SitemapGeneration`** declares `STATUS_OK`, `STATUS_FAILED`, `TRIGGER_MANUAL`, `TRIGGER_PUBLISH`,
  `TRIGGER_SCHEDULED` (§2.14 gives these columns no enum); use the constants, not literals.
- **Revision ownership (integration M-19):** `$revision->belongsToTarget($sectionOrPage)` (bool) or
  `RevisionRecorder::assertBelongsTo()` (throws) before `authorize('revert', $revision)`.

#### 5. Decisions an owner may want to overrule

1. **Attached FAQs** (`faqable_*` set): `FaqPolicy` update / toggle / delete / restore additionally need
   `{owner module}.edit` resolved through `Modules::moduleForSubject(new $faqable_type)`; an unresolvable
   owner is refused. This keeps the CMS FAQ screen from editing a Phase 14 course FAQ (§6.13).
2. **`restore` on `website_cta_blocks`, `faq_categories`, `website_media`** checks `{module}.restore`, which
   §4.1 does not declare — so only a Super Admin can restore those until a `RESTORE` preset is added.
3. **`WebsiteSectionPolicy::toggle` is allowed for required types** (disabling is their only off switch)
   and `unpublish` / `delete` are allowed for orphaned types, so an admin can clean an orphan up.
4. **Super Admin bypasses every structural rule above** through `Gate::before`; the services already refuse
   the same acts (`ContentActionNotAllowedException`), which is the only thing that stops a Super Admin.

---

### Phase 3 controllers, Form Requests and the D26 middleware (app/Http/Controllers/{Site,Admin/Cms}/**, app/Http/Requests/Cms/**, app/Http/Middleware/EnsureSiteModuleEnabled.php)

**Delivered (all new files; nothing existing edited).** `php -l` clean on all 74 files;
`./vendor/bin/pint --test app/Http/Controllers/Site app/Http/Controllers/Admin/Cms app/Http/Requests/Cms app/Http/Middleware/EnsureSiteModuleEnabled.php`
passes. A scratch reflection script confirmed every class loads (no signature fatal), every `use` resolves and
every service / model-scope / policy method the controllers call exists — **except the seven services of §4
below, which are not on disk yet**. No route is registered, no test was run, nothing touched the database.

```
app/Http/Middleware/EnsureSiteModuleEnabled.php            D26 `site_module` (404, no message)
app/Http/Controllers/Admin/Cms/                            16 controllers (integration F.1 names, verified)
  WebsiteOverviewController SectionController SectionItemController SectionRevisionController
  PublicCacheController StatisticController MenuController MenuItemController PageController
  PageRevisionController CtaBlockController FaqController FaqCategoryController SeoController
  SitemapController MediaController
  Concerns/{RespondsForCms,ListsRevisions,StreamsCsv}.php
app/Http/Controllers/Site/                                 HomeController PageController PreviewController
                                                           RobotsController SitemapController
  Concerns/{ComposesSite,RendersPages}.php
app/Http/Requests/Cms/                                     36 requests (incl. CmsFormRequest base, CmsListRequest) + 10 concerns + PageTemplate
```

#### 1. `bootstrap/app.php` — middleware alias (inside `$middleware->alias([...])`)

```php
            // phase-03 INV-15 / D26: a content module gates ITS OWN public routes with a 404.
            'site_module' => \App\Http\Middleware\EnsureSiteModuleEnabled::class,
```

phase-04 §7.3 names the class `EnsurePublicModuleEnabled`; the Phase 3 path list fixed
`EnsureSiteModuleEnabled`, which is the file that exists. Phase 4 uses the alias, never the class name.
No Phase 3 route carries `site_module`.

#### 2. Routes — every route, and the controller action that serves it

`routes/admin.php`: **paste integration F.1 verbatim** — every `Controller@method`, `{parameter}` name,
`whereNumber`/`whereIn` constraint and `withTrashed()` in it was checked against these controllers.
`routes/web.php` + `routes/site-pages.php` + the `then:` loader: integration F.2 / F.4 / F.5, with one
simplification allowed in F.2 (see note P-1). Group middleware on every admin route: `web`, `auth`, `active`,
`panel:admin`. `{placement}` is bound to `SectionPlacement` by the controller type-hint (an unknown value 404s).

| # | Method | URI | Name | Controller@method | Middleware (beyond the group) |
|---|---|---|---|---|---|
| 1 | GET | /admin/website | admin.website.index | Admin\Cms\WebsiteOverviewController@index | module:website_sections, can:website_sections.view_any |
| 2 | GET | /admin/website/sections/{placement} | admin.website.sections.index | Admin\Cms\SectionController@index | module:website_sections, can:website_sections.view_any |
| 3 | GET | /admin/website/sections/{placement}/available | admin.website.sections.available | Admin\Cms\SectionController@available | module:website_sections, can:website_sections.create |
| 4 | POST | /admin/website/sections/{placement} | admin.website.sections.store | Admin\Cms\SectionController@store | module:website_sections, can:website_sections.create |
| 5 | GET | /admin/website/sections/{section}/edit | admin.website.sections.edit | Admin\Cms\SectionController@edit | module:website_sections, can:website_sections.view |
| 6 | PUT | /admin/website/sections/{section} | admin.website.sections.update | Admin\Cms\SectionController@update | module:website_sections, can:website_sections.edit |
| 7 | POST | /admin/website/sections/reorder | admin.website.sections.reorder | Admin\Cms\SectionController@reorder | module:website_sections, can:website_sections.edit |
| 8 | POST | /admin/website/sections/{section}/publish | admin.website.sections.publish | Admin\Cms\SectionController@publish | module:website_sections, can:website_sections.change_status |
| 9 | POST | /admin/website/sections/{section}/unpublish | admin.website.sections.unpublish | Admin\Cms\SectionController@unpublish | module:website_sections, can:website_sections.change_status |
| 10 | POST | /admin/website/sections/{section}/toggle | admin.website.sections.toggle | Admin\Cms\SectionController@toggle | module:website_sections, can:website_sections.change_status |
| 11 | POST | /admin/website/sections/{section}/duplicate | admin.website.sections.duplicate | Admin\Cms\SectionController@duplicate | module:website_sections, can:website_sections.create |
| 12 | DELETE | /admin/website/sections/{section} | admin.website.sections.destroy | Admin\Cms\SectionController@destroy | module:website_sections, can:website_sections.delete |
| 13 | GET | /admin/website/sections/{section}/revisions | admin.website.sections.revisions.index | Admin\Cms\SectionRevisionController@index | module:website_sections, can:website_sections.view_logs |
| 14 | POST | /admin/website/sections/{section}/revisions/{revision}/revert | admin.website.sections.revisions.revert | Admin\Cms\SectionRevisionController@revert | module:website_sections, can:website_sections.change_status |
| 15 | POST | /admin/website/sections/{section}/items | admin.website.sections.items.store | Admin\Cms\SectionItemController@store | module:website_sections, can:website_sections.edit |
| 16 | PUT | /admin/website/section-items/{item} | admin.website.section-items.update | Admin\Cms\SectionItemController@update | module:website_sections, can:website_sections.edit |
| 17 | POST | /admin/website/section-items/{item}/toggle | admin.website.section-items.toggle | Admin\Cms\SectionItemController@toggle | module:website_sections, can:website_sections.edit |
| 18 | DELETE | /admin/website/section-items/{item} | admin.website.section-items.destroy | Admin\Cms\SectionItemController@destroy | module:website_sections, can:website_sections.edit |
| 19 | POST | /admin/website/sections/{section}/items/{group}/reorder | admin.website.sections.items.reorder | Admin\Cms\SectionItemController@reorder | module:website_sections, can:website_sections.edit |
| 20 | POST | /admin/website/cache/flush | admin.website.cache.flush | Admin\Cms\PublicCacheController@flush | module:website_sections, can:website_sections.change_status, throttle:6,1 |
| 21 | GET | /admin/website/statistics | admin.website.statistics.index | Admin\Cms\StatisticController@index | module:website_sections, can:website_sections.view_any |
| 22 | GET | /admin/website/menus | admin.website.menus.index | Admin\Cms\MenuController@index | module:menus, can:menus.view_any |
| 23 | GET | /admin/website/menus/{menu} | admin.website.menus.show | Admin\Cms\MenuController@show | module:menus, can:menus.view |
| 24 | PUT | /admin/website/menus/{menu} | admin.website.menus.update | Admin\Cms\MenuController@update | module:menus, can:menus.edit |
| 25 | POST | /admin/website/menus/{menu}/items | admin.website.menus.items.store | Admin\Cms\MenuItemController@store | module:menus, can:menus.create |
| 26 | PUT | /admin/website/menu-items/{item} | admin.website.menu-items.update | Admin\Cms\MenuItemController@update | module:menus, can:menus.edit |
| 27 | POST | /admin/website/menu-items/{item}/toggle | admin.website.menu-items.toggle | Admin\Cms\MenuItemController@toggle | module:menus, can:menus.change_status |
| 28 | DELETE | /admin/website/menu-items/{item} | admin.website.menu-items.destroy | Admin\Cms\MenuItemController@destroy | module:menus, can:menus.delete |
| 29 | POST | /admin/website/menus/{menu}/reorder | admin.website.menus.reorder | Admin\Cms\MenuController@reorder | module:menus, can:menus.edit |
| 30 | GET | /admin/website/menus/{menu}/link-check | admin.website.menus.link-check | Admin\Cms\MenuController@linkCheck | module:menus, can:menus.view |
| 31 | GET | /admin/website/pages | admin.website.pages.index | Admin\Cms\PageController@index | module:pages, can:pages.view_any |
| 32 | GET | /admin/website/pages/create | admin.website.pages.create | Admin\Cms\PageController@create | module:pages, can:pages.create |
| 33 | POST | /admin/website/pages | admin.website.pages.store | Admin\Cms\PageController@store | module:pages, can:pages.create |
| 34 | GET | /admin/website/pages/{page}/edit | admin.website.pages.edit | Admin\Cms\PageController@edit | module:pages, can:pages.view |
| 35 | PUT | /admin/website/pages/{page} | admin.website.pages.update | Admin\Cms\PageController@update | module:pages, can:pages.edit |
| 36 | POST | /admin/website/pages/{page}/publish | admin.website.pages.publish | Admin\Cms\PageController@publish | module:pages, can:pages.change_status |
| 37 | POST | /admin/website/pages/{page}/schedule | admin.website.pages.schedule | Admin\Cms\PageController@schedule | module:pages, can:pages.change_status |
| 38 | POST | /admin/website/pages/{page}/unpublish | admin.website.pages.unpublish | Admin\Cms\PageController@unpublish | module:pages, can:pages.change_status |
| 39 | POST | /admin/website/pages/{page}/duplicate | admin.website.pages.duplicate | Admin\Cms\PageController@duplicate | module:pages, can:pages.create |
| 40 | DELETE | /admin/website/pages/{page} | admin.website.pages.destroy | Admin\Cms\PageController@destroy | module:pages, can:pages.delete |
| 41 | POST | /admin/website/pages/{page}/restore | admin.website.pages.restore | Admin\Cms\PageController@restore | module:pages, can:pages.restore, route `->withTrashed()` |
| 42 | GET | /admin/website/pages/{page}/revisions | admin.website.pages.revisions.index | Admin\Cms\PageRevisionController@index | module:pages, can:pages.view_logs |
| 43 | POST | /admin/website/pages/{page}/revisions/{revision}/revert | admin.website.pages.revisions.revert | Admin\Cms\PageRevisionController@revert | module:pages, can:pages.change_status |
| 44 | GET | /admin/website/pages/{page}/preview-link | admin.website.pages.preview-link | Admin\Cms\PageController@previewLink | module:pages, can:pages.view |
| 45 | GET | /admin/website/pages/export | admin.website.pages.export | Admin\Cms\PageController@export | module:pages, can:pages.export (declare before `pages/{page}`) |
| 46 | GET | /admin/website/cta-blocks | admin.website.cta-blocks.index | Admin\Cms\CtaBlockController@index | module:website_cta_blocks, can:website_cta_blocks.view_any |
| 47 | POST | /admin/website/cta-blocks | admin.website.cta-blocks.store | Admin\Cms\CtaBlockController@store | module:website_cta_blocks, can:website_cta_blocks.create |
| 48 | GET | /admin/website/cta-blocks/{ctaBlock}/edit | admin.website.cta-blocks.edit | Admin\Cms\CtaBlockController@edit | module:website_cta_blocks, can:website_cta_blocks.view |
| 49 | PUT | /admin/website/cta-blocks/{ctaBlock} | admin.website.cta-blocks.update | Admin\Cms\CtaBlockController@update | module:website_cta_blocks, can:website_cta_blocks.edit |
| 50 | POST | /admin/website/cta-blocks/{ctaBlock}/toggle | admin.website.cta-blocks.toggle | Admin\Cms\CtaBlockController@toggle | module:website_cta_blocks, can:website_cta_blocks.change_status |
| 51 | GET | /admin/website/cta-blocks/{ctaBlock}/usage | admin.website.cta-blocks.usage | Admin\Cms\CtaBlockController@usage | module:website_cta_blocks, can:website_cta_blocks.view |
| 52 | DELETE | /admin/website/cta-blocks/{ctaBlock} | admin.website.cta-blocks.destroy | Admin\Cms\CtaBlockController@destroy | module:website_cta_blocks, can:website_cta_blocks.delete |
| 53 | GET | /admin/website/faqs | admin.website.faqs.index | Admin\Cms\FaqController@index | module:faqs, can:faqs.view_any |
| 54 | POST | /admin/website/faqs | admin.website.faqs.store | Admin\Cms\FaqController@store | module:faqs, can:faqs.create |
| 55 | PUT | /admin/website/faqs/{faq} | admin.website.faqs.update | Admin\Cms\FaqController@update | module:faqs, can:faqs.edit |
| 56 | POST | /admin/website/faqs/{faq}/toggle | admin.website.faqs.toggle | Admin\Cms\FaqController@toggle | module:faqs, can:faqs.change_status |
| 57 | POST | /admin/website/faqs/reorder | admin.website.faqs.reorder | Admin\Cms\FaqController@reorder | module:faqs, can:faqs.edit |
| 58 | DELETE | /admin/website/faqs/{faq} | admin.website.faqs.destroy | Admin\Cms\FaqController@destroy | module:faqs, can:faqs.delete |
| 59 | GET | /admin/website/faq-categories | admin.website.faq-categories.index | Admin\Cms\FaqCategoryController@index | module:faq_categories, can:faq_categories.view_any |
| 60 | POST | /admin/website/faq-categories | admin.website.faq-categories.store | Admin\Cms\FaqCategoryController@store | module:faq_categories, can:faq_categories.create |
| 61 | PUT | /admin/website/faq-categories/{category} | admin.website.faq-categories.update | Admin\Cms\FaqCategoryController@update | module:faq_categories, can:faq_categories.edit |
| 62 | POST | /admin/website/faq-categories/reorder | admin.website.faq-categories.reorder | Admin\Cms\FaqCategoryController@reorder | module:faq_categories, can:faq_categories.edit |
| 63 | DELETE | /admin/website/faq-categories/{category} | admin.website.faq-categories.destroy | Admin\Cms\FaqCategoryController@destroy | module:faq_categories, can:faq_categories.delete |
| 64 | GET | /admin/website/seo | admin.website.seo.index | Admin\Cms\SeoController@index | module:seo, can:seo.view_any |
| 65 | GET | /admin/website/seo/edit | admin.website.seo.edit | Admin\Cms\SeoController@edit | module:seo, can:seo.view (query `target=page:{id}` or `route:{name}`) |
| 66 | PUT | /admin/website/seo | admin.website.seo.update | Admin\Cms\SeoController@update | module:seo, can:seo.edit |
| 67 | POST | /admin/website/seo/bulk-robots | admin.website.seo.bulk-robots | Admin\Cms\SeoController@bulkRobots | module:seo, can:seo.edit |
| 68 | POST | /admin/website/seo/sitemap/regenerate | admin.website.seo.sitemap.regenerate | Admin\Cms\SitemapController@regenerate | module:seo, can:seo.edit, throttle:6,1 |
| 69 | GET | /admin/website/seo/sitemap/history | admin.website.seo.sitemap.history | Admin\Cms\SitemapController@history | module:seo, can:seo.view |
| 70 | GET | /admin/website/seo/robots/preview | admin.website.seo.robots.preview | Admin\Cms\SeoController@robotsPreview | module:seo, can:seo.view |
| 71 | GET | /admin/website/seo/export | admin.website.seo.export | Admin\Cms\SeoController@export | module:seo, can:seo.export |
| 72 | GET | /admin/website/media | admin.website.media.index | Admin\Cms\MediaController@index | module:website_media, can:website_media.view_any |
| 73 | POST | /admin/website/media | admin.website.media.store | Admin\Cms\MediaController@store | module:website_media, can:website_media.upload, throttle:60,1 |
| 74 | GET | /admin/website/media/{asset} | admin.website.media.show | Admin\Cms\MediaController@show | module:website_media, can:website_media.view |
| 75 | PUT | /admin/website/media/{asset} | admin.website.media.update | Admin\Cms\MediaController@update | module:website_media, can:website_media.edit |
| 76 | GET | /admin/website/media/{asset}/usage | admin.website.media.usage | Admin\Cms\MediaController@usage | module:website_media, can:website_media.view |
| 77 | POST | /admin/website/media/{asset}/regenerate | admin.website.media.regenerate | Admin\Cms\MediaController@regenerate | module:website_media, can:website_media.edit |
| 78 | DELETE | /admin/website/media/{asset} | admin.website.media.destroy | Admin\Cms\MediaController@destroy | module:website_media, can:website_media.delete |
| 79 | GET | / | site.home | Site\HomeController (invokable) | public_site, site.preview, site.cache |
| 80 | GET | /robots.txt | site.robots | Site\RobotsController (invokable) | none (answers while the site is down) |
| 81 | GET | /sitemap.xml | site.sitemap | Site\SitemapController@index | site.cache (+ public_site per integration F.2) |
| 82 | GET | /sitemap-{index}.xml | site.sitemap.chunk | Site\SitemapController@chunk | site.cache (+ public_site), `whereNumber('index')` |
| 83 | GET | /preview/page/{page} | site.preview.page | Site\PreviewController@page | public_site, site.preview (signature **or** session is checked inside the controller) |
| 84 | GET | /preview/section/{section} | site.preview.section | Site\PreviewController@section | public_site, site.preview (as above) |
| 85 | GET | /{slug} | site.page | Site\PageController (invokable) | public_site, site.preview, site.cache, slug regex of F.4, **registered last** |

Contract aliases: the contract's `site` gate is the existing `public_site` (integration K-3). No public route
carries `module:` or `can:` (INV-15).

- **P-1 Preview authorisation lives in `PreviewController`.** A valid signature passes; otherwise the user must
  hold `pages.view` / `website_sections.view`; a request carrying a bad `signature` is a 403 and one with none
  is a 404 (FT-21, FT-23). `EnsurePreviewAuthorised` / `site.preview.auth` is therefore optional; if it is
  written it must apply exactly this rule, never `signed` alone (that would 403 an authorised session).
- **P-2 `?preview=1` is also resolved inside `HomeController` / `PageController`** (drafts only for a user
  holding the area's `view` permission; everyone else gets the live page). `ResolvePreviewMode` must not render
  drafts on its own; it only has to make `CachePublicResponse` skip the request. Preview responses already carry
  `Cache-Control: no-store, private` and `X-Robots-Tag: noindex, nofollow`.

#### 3. What the controllers need from files other owners write

- **Policies are called by name** (the `can:` permission first, then the record rule): WebsiteSection
  `view update publish unpublish toggle duplicate delete viewRevisions revert`; WebsiteSectionItem
  `create(class, section) update toggle delete reorder(class, section)`; Menu `view update reorder linkCheck`;
  MenuItem `create(class, menu) update toggle delete`; Page `view update changeSlug publish schedule unpublish
  duplicate delete restore preview viewRevisions revert export(class)`; CtaBlock `view update changeKey toggle
  usage delete`; Faq `update toggle delete`; FaqCategory `update delete`; MediaAsset `view update usage
  regenerate delete upload(class)`; SeoMeta `bulkUpdate(class) export(class)`; SitemapGeneration
  `viewAny(class) create(class)`. All exist in `app/Policies/Cms`. **Until the `POLICIES` rows of the models
  handover §1 are registered, every record-level check denies everyone but Super Admin.**
- **Views** (the view agents' paths; `admin/cms` per the Phase 3 path list, integration K-11):

| View | Variables |
|---|---|
| `admin.cms.overview` | `siteState` (live/maintenance/disabled), `sections` (placement => label,total,enabled,published,unpublished), `orphanedCount`, `areas` (key => total,attention,route), `lastPublish` (type,label,at,by or null), `cacheVersion`, `canFlush`, `lastSitemap` |
| `admin.cms.sections.index` | `placement`, `page`, `sections` (paginator, 100/page), `types` (key => label), `publishers` (id => name), `placementTabs` (placement,page_id,label), `addable` (see available), `canReorder`, `statusOptions`, `filters`, `can` (create,edit,publish,delete,revisions) |
| `admin.cms.sections.available` | `placement`, `groups`, `types` (key,label,description,icon,group,unique,required,disabled,reason,existing_url); JSON: `{placement, groups, types[]}` |
| `admin.cms.sections.edit` | `section`, `placement`, `orphaned`, `type`, `fields`, `repeaters`, `mediaRoles`, `tabs`, `draft` (`SectionService::canonicalPayload()`: fields, columns, items incl. disabled, media role => ids, faqs), `assets` (id => MediaAsset), `mediaLibrary` (id,name,alt_text,mime_type,kind,url,width,height — for `cmsMediaPicker`'s JSON), `options` (cta_blocks, menus, faq_categories, pages), `statistics` (metric => ?string), `publisher`, `revisionCount`, `previewUrl`, `can` (edit,publish,duplicate,delete,revisions) |
| `admin.cms.sections.revisions` / `admin.cms.pages.revisions` | `section` or `page`, `revisions` (paginator), `authors`, `currentHash`, `publishedHash`, `canRevert` |
| `admin.cms.statistics.index` | `items`, `sections` (id => WebsiteSection), `sectionLabels`, `resolved` (item id => ?string), `modeOptions`, `metricOptions`, `filters`, `canEdit` |
| `admin.cms.menus.index` | `menus` (with items_count, enabled_items_count, child_items_count), `missingLocations` (MenuLocation[]), `filters` |
| `admin.cms.menus.show` | `menu`, `tree` (root items with children, page), `urls` (item id => ?resolved url), `broken` (item id => reason), `options` (link_types, visibility, pages, anchors, routes, icons, parents), `can` (create,edit,toggle,delete) |
| `admin.cms.menus.link-check` | `menu`, `items` (id,label,link_type,is_enabled,reason); JSON `{menu, items[]}` |
| `admin.cms.pages.index` | `pages`, `completeness` (id => ?int), `trashed`, `sort`, `direction`, `filters`, `statusOptions`, `layoutOptions`, `counts` (status => n), `can` |
| `admin.cms.pages.create` / `.edit` | `page`, `layoutOptions`, `templateOptions` (`PageTemplate::options()`), `reservedSlugs`, `seoMeta`, `seoInherited` (SeoPayload); edit adds `banner`, `sections`, `seoCompleteness`, `menuItems`, `revisionCount`, `can` (edit,changeSlug,publish,delete,duplicate,revisions,seo) |
| `admin.cms.cta-blocks.index` / `.edit` / `.usage` | `blocks`, `backgrounds`, `statusOptions`, `variantOptions`, `styleOptions`, `filters`, `can` / `block`, `background`, `usage`, `variantOptions`, `styleOptions`, `canEdit`, `canChangeKey` / `block`, `usage` |
| `admin.cms.faqs.index` | `questions`, `categories` (with `faqs_count`), `uncategorisedCount`, `selectedCategory`, `category` (id / `uncategorised` / null), `statusOptions`, `filters`, `canReorder`, `can` |
| `admin.cms.faq-categories.index` | `categories` (with `faqs_count`), `filters`, `canReorder`, `can` |
| `admin.cms.seo.index` / `.edit` / `.robots` | `rows` (paginator of `auditRows()` arrays), `ogImages`, `filters`, `sort`, `direction`, `robotsOptions`, `types`, `sitemap` (enabled,last,url), `robots` (mode,indexable), `can` / `target`, `targetKey`, `targetName`, `meta`, `inherited`, `completeness`, `ogImage`, `robotsOptions`, `changefreqOptions`, `ogTypes`, `canEdit` / `mode`, `body`, `indexable`, `maintenance`, `publicSite`, `canEditSettings` |
| `admin.cms.sitemap.history` | `generations`, `authors`, `enabled`, `filters`, `canRegenerate` |
| `admin.cms.media.index` / `.show` / `.usage` | `assets`, `cards` (id => card), `collectionOptions`, `derivativeOptions`, `profileOptions`, `maxUploadMb`, `filters`, `can` / `asset`, `card`, `variants`, `usage`, `can` / `asset`, `usage` |
| `site.home` | `$site` (see below); a section preview adds `previewSection`, `previewOmitted` |
| `site.pages.default` / `wide` / `legal` | `$site`, `$page` (id,title,slug,layout,excerpt,show_banner,banner_heading,banner_subheading,banner,body = sanitised,published_at) |
| `site.404` | `$site` with empty `sections` |

  `$site` is an object with the §6.9 `SitePayload` fields: `header`, `footer` (a section entry or null),
  `sections` (list of entries), `seo` (`App\Services\Cms\Data\SeoPayload`), `page`, `isPreview`, `bodyClass`.
  A section entry is the `SnapshotBuilder` array plus `view`; render it exactly as integration K.3's loop does
  (`@include($section['view'], ['section' => $section, 'content' => $section['fields'], 'items' => …])`).
  Every entry was already render-probed with those variables, so an orphaned, malformed or throwing section
  never reaches the view (logged once, never a 500). When `App\Support\SitePayload` / `PublicPageService`
  land, `ComposesSite::sitePayload()` / `liveSections()` are the two methods to swap.
- **Responses.** Every write answers JSON (`{message, ...}`, 422 `{message, errors}`, 403 `{message}` or
  `{message, usage}`) when `Accept: application/json`, else a redirect with `session('toast')`
  (`['type' => success|error, 'message' => ...]`); an in-use refusal also flashes `cms_usage`. The CMS
  exceptions are mapped inside the controllers, so integration E.4 is no longer required for them (harmless
  if applied). `ContentActionNotAllowedException` is a 422 on the named field, a 403 on destroy routes
  (integration M-7 solved at the call site; no `status()` needed on the exception).

#### 4. Service methods the controllers call that do not exist yet — list, not invented

| Class (not on disk) | Contract methods called (§6) |
|---|---|
| `App\Services\Cms\PageService` | `reservedSlugs(): array`, `create(array): Page`, `saveDraft(Page, array): Page` (receives title, slug, layout, excerpt, content, banner and template fields; never SEO), `duplicate(Page): Page`, `delete(Page): void` |
| `App\Services\Cms\MenuService` | `storeItem(Menu, array): MenuItem`, `updateItem(MenuItem, array): MenuItem`, `reorder(Menu, array $tree): void`, `resolveUrl(MenuItem): ?string` |
| `App\Services\Cms\CtaBlockService` | `save(array, ?CtaBlock): CtaBlock`, `usage(CtaBlock): Collection`, `delete(CtaBlock): void` |
| `App\Services\Cms\FaqService` | `save(array, ?Faq): Faq`, `toggle(Faq, ContentStatus): Faq`, `reorder(?FaqCategory, array): void`, `reorderCategories(array): void` |
| `App\Services\Cms\StatisticsProvider` | `all(): array` (metric => ?string), `valueFor(WebsiteSectionItem): ?string` |

**Not in the contract, but a §7 route needs them** (names chosen to mirror the neighbouring contract methods;
the owner may rename — each is one call site):

| Needed | Called from | Why |
|---|---|---|
| `MenuService::updateMenu(Menu, array $data): Menu` (name, description, is_active) | `MenuController@update` | §7.2 `PUT menus/{menu}`; §6.3 has no menu write |
| `MenuService::deleteItem(MenuItem): void` | `MenuItemController@destroy` | §7.2 `DELETE menu-items/{item}` |
| `FaqService::delete(Faq): void` | `FaqController@destroy` | §7.4 `DELETE faqs/{faq}` |
| `FaqService::saveCategory(array, ?FaqCategory): FaqCategory` (name, slug, description, icon, is_enabled) | `FaqCategoryController@store/update` | §7.4 category store/update |
| `FaqService::deleteCategory(FaqCategory): void` | `FaqCategoryController@destroy` | §7.4 category destroy |
| `PageService::restore(Page): Page` | `PageController@restore` | §7.3 `POST pages/{page}/restore` |

Two contract methods are called with a **partial payload** and must accept it: `MenuService::updateItem($item,
['is_enabled' => bool])` (the toggle route) and `CtaBlockService::save(['status' => value], $block)` (the
toggle route). `SectionService` has no write for the `faq_website_section` picks of a `source = selected` FAQ
section, so no request accepts picks yet.

#### 5. Decisions and findings an owner may want to overrule

1. **Publishing a page goes through `ContentPublisher`** (`publish`, `schedule`, `unpublish`, `revert`), the
   real writer of the published columns, not `PageService::publish()` of §6.4.
2. **The signed preview link is built with `URL::temporarySignedRoute('site.preview.page', …)`** in
   `PageController@previewLink` (TTL `website.preview_ttl_minutes`, clamped 5 min–7 days); there is no
   `PreviewService` yet. JSON `{url, expires_at}` is what `cmsCopy` reads.
3. **Section and FAQ lists are sortable only when unfiltered and on one page** (`canReorder`): reorder must post
   the exact current set (INV-5). The section list pages at 100.
4. **`PageTemplate` (`app/Http/Requests/Cms/PageTemplate.php`) is the one allowlist** of `pages.template`
   (`site.pages.default|wide|legal`); an unknown or missing template renders the default. `PageService` should
   validate against `PageTemplate::ALLOWED` rather than restate the list.
5. **Found in `SitemapGenerator` (services owner):** `cached(0)` uses the same cache key as `cached(null)`
   (`[$chunk ?? 0]`), so `/sitemap-0.xml` would serve the index. `Site\SitemapController@chunk` refuses 0; the
   generator should key the index differently.
6. **A page's slug change and a CTA key change** are authorised with `PagePolicy::changeSlug` /
   `CtaBlockPolicy::changeKey` before the service runs; the Form Requests also refuse a system-page slug change
   without `pages.change_status`.
7. **Bulk SEO** (`seo.bulk-robots`) writes each target through `SeoService::save()` inside one transaction —
   all rows or none; the service bumps the cache once per row after commit.

---

### Phase 3 public website views (resources/views/site/**, resources/views/components/site/**)

**Delivered.** 42 Blade files compile (`view:cache` then `view:clear`; each compiled file `php -l` clean). A
read-only render script (array cache and session, fake snapshots, no DB write) rendered every page and every
section partial, including each partial alone with only the K.3 variables exactly as
`ComposesSite::usableSection()` probes it. No test was run. Static scans on the tree: FT-37 (no raw echo except
`RichText::sanitize()`), FT-42 (no settings or config helper call in `site/`), no bare image tag in `site/`, no
request-forgery token anywhere in a cacheable page (M-6). Checked in a browser at 375 and 1280 px, light and
dark: no horizontal overflow, drawer focus trap / Escape / focus return, dropdown ArrowDown / ArrowUp / Escape.

```
resources/views/site/layouts/public.blade.php        the public layout (contract name layouts/site — see 1)
resources/views/site/home.blade.php                   section-driven home (+ honest empty state, section-preview states)
resources/views/site/pages/{default,wide,legal}.blade.php   the three PageTemplate::ALLOWED templates
resources/views/site/pages/partials/page.blade.php   banner + body or page sections, shared by the three
resources/views/site/404.blade.php                    branded 404, header/footer intact, menu-rich
resources/views/site/{holding,maintenance}.blade.php  503 pages (both include site/partials/holding-page)
resources/views/site/partials/sections.blade.php     THE renderer loop (INV-2)
resources/views/site/partials/theme-script.blade.php pre-paint theme (appearance.default_theme)
resources/views/site/partials/holding-page.blade.php standalone 503 document
resources/views/site/sections/{header,hero,about,rich_content,faq,cta,footer}.blade.php   SectionRegistry::view()
resources/views/site/sections/{services,courses}.blade.php + sections/partials/teaser.blade.php   Phase 4 / 14 types
resources/views/site/cta/{banner,card,inline,split,full_width}.blade.php   CtaVariant::view()
resources/views/components/site/{brand,cta,menu,preview-ribbon,seo,social-links,stats,theme-toggle}.blade.php   new
resources/views/components/site/{accordion,prose,button}.blade.php   edited (see 4)
```

#### 1. Layout name

The brief placed the layout at `site.layouts.public` because `resources/views/layouts/**` is not a Phase 3
path. Every site view extends it; nothing references `layouts.site`. If the contract name is wanted, the
integrator may create `resources/views/layouts/site.blade.php` containing exactly one line:

```blade
@extends('site.layouts.public')
```

The layout reuses `layouts.partials.brand-theme` (the admin shell's runtime `--brand-*` palette) and does **not**
include `layouts.partials.head` (that partial prints a token meta tag and `noindex`, both wrong for a cached
public page).

#### 2. Prerequisites the views rely on (integration items; none are mine to write)

1. **`site_setting()` helper** (integration E.1). Every normal site view calls it. The components and the
   holding / maintenance pages read settings through a guarded closure (`function_exists` + `rescue`), because
   Phase 2's `EnsurePublicSiteAvailable` already renders `site.maintenance` today — verified to render with the
   helper absent, keeping `MaintenanceModeTest`'s "Scheduled maintenance", the admin's message, `noindex` and no
   sign-in link.
2. **Settings read, all must be `public => true` in `SettingsRegistry`** (`SiteSettings` throws otherwise):
   `company.name company.tagline company.copyright_text branding.logo_light branding.logo_dark branding.favicon
   appearance.default_theme contact.email contact.phone contact.whatsapp contact.address contact.city
   contact.country contact.business_hours social.facebook social.instagram social.linkedin social.youtube
   social.tiktok social.x_twitter social.github social.whatsapp_link seo.google_analytics_id
   seo.google_tag_manager_id seo.facebook_pixel_id seo.google_site_verification` (all public today) and the four
   Phase 3 keys of §5.1a that integration D must add: `website.show_theme_toggle website.hero_video_enabled
   website.faq_accordion_open_first website.image_lazy_loading`. Until D lands those four reads are wrapped in
   `rescue()`: an undeclared key logs the exception and falls back to its §5.1a default (`true`) instead of
   taking the page down. Every other key is read unguarded, so a missing `public` flag fails loudly (INV-10).
3. **Front-end build.** The views use Tailwind classes the current `public/build` CSS does not contain. Run
   `npm run build` (or `npm run dev`) after integration; no package is added. Alpine 3.17's `x-teleport` and the
   project's own `x-trap` directive are used; nothing else.
4. **`StatisticsProvider`** (not on disk). `<x-site.stats>` resolves every live statistic (K-4 / M-8): it calls
   `valueForSnapshot(array $item)` when the provider has it, otherwise `resolve(StatisticMetric)`, then falls
   back to `manual_value`, and drops the item when still null (INV-12). With the class absent a live item shows
   its manual value. Recommended: add `valueForSnapshot(array): ?string` beside the contract's
   `valueFor(WebsiteSectionItem)`, since a partial holds arrays (§8.14).
5. **404 from the exception handler.** `Site\PageController` already renders `site.404` itself. For a 404 thrown
   anywhere else on a public URL, the handler may render it with no `$site` at all — the layout then falls back
   to the brand, theme toggle and a settings-built footer. Optional, `bootstrap/app.php`:

```php
$exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, \Illuminate\Http\Request $request) {
    if ($request->expectsJson() || $request->is('admin', 'admin/*', 'student*', 'teacher*', 'client*', 'collaborator*') || ! view()->exists('site.404')) {
        return null;
    }

    return response()->view('site.404', [], 404)->header('X-Robots-Tag', 'noindex, nofollow');
});
```

6. **Holding page view.** `EnsurePublicSiteAvailable` renders `site.maintenance` for both states today. Integration
   E.3 step 1 (`site.holding` when `public_site_enabled` is off) gives the switched-off state its own icon; both
   views accept exactly `$company`, `$heading`, `$message`. E.3 step 3's request attribute `site_state`
   (`maintenance` | `disabled`) turns on the layout's amber staff ribbon.

#### 3. Variable contract (matches `Site\Concerns\ComposesSite` / `RendersPages` / `PreviewController` as on disk)

| View | Receives |
|---|---|
| `site.layouts.public` | `$site` object or array: `header`, `footer` (section entry or null), `sections`, `seo` (`SeoPayload`), `page`, `isPreview`, `bodyClass`; optional `$page` (title, slug), optional `$previewSection`; request attribute `site_state`. Sections: `title` (explicit title, wins over the payload), `robots` (explicit robots, also drops canonical), `content`; stacks `head`, `scripts` |
| `site.home` | `$site`; optional `$previewSection` (int), `$previewOmitted` (bool) — header/footer-only and "cannot be previewed" states |
| `site.pages.default` / `wide` / `legal` | `$site`, `$page` array: `title slug layout(content|sections, string or enum) show_banner banner_heading banner_subheading banner(media array|null) body(sanitised; published_content accepted as fallback) published_at` |
| `site.404` | `$site` optional (empty `sections`) |
| `site.holding` / `site.maintenance` | `$company`, `$heading`, `$message` |
| `site.partials.sections` | `$sections` (entries = snapshot + id, section_key, anchor, view; a row with `published_content` also works), `$site` optional |
| every `site.sections.{key}` | `$section`, `$content`, `$items`, `$media`, `$cta`, `$menus`, `$faqs`; optional `$index`, `$overlayHeader` (hero only) |
| `site.sections.header` / `footer` | as above; header also optional `$overlay` (bool). Both render with `$section = null` (brand + theme toggle; footer from settings) |
| `site.sections.services` / `courses` | as above; cards from `$section['provider']` (`list` or `['items' => list]` of `title excerpt url media icon meta`) |
| `site.cta.{variant}` | from `<x-site.cta>`: `$cta $heading $subheading $description $buttons $background $color $tone $headingTag` |

Components: `<x-site.brand logo-light logo-dark name show-name href size prefer-dark>`,
`<x-site.menu menu variant=desktop|drawer|footer|legal label id-prefix>`, `<x-site.stats items tone variant>`,
`<x-site.cta cta heading-level>`, `<x-site.seo seo title preview robots analytics>`,
`<x-site.preview-ribbon mode=preview|maintenance|disabled target exit-url editor-url>`,
`<x-site.theme-toggle variant=icon|segmented>`, `<x-site.social-links size>`.

#### 4. Route names used (every one behind `Route::has()`)

`site.home`, `site.page` (`['slug' => …]`, the preview ribbon's exit link), `admin.website.index` (the ribbon's
"Back to the editor", signed-in users only). Every other public URL comes from a snapshot `url`. No `login` route
is referenced: the header's Login button is the snapshot link, shown to guests only; the holding, maintenance
and 404 pages carry no sign-in link.

#### 5. Decisions and fixes an owner may want to overrule

1. **Existing component bugs fixed (my path).** `components/site/prose.blade.php` did not compile: a nested
   comment inside its docblock closed the comment early (PHP parse error on every render). `button.blade.php`
   appended the `sm` / `lg` size after `ButtonStyle::SIZE`, so `sm` lost to the default `h-11` by stylesheet
   order; a size now replaces the style's size. K-6: `$siteSetting(` in the accordion / prose examples is now
   `site_setting(`.
2. **K-12 / FT-28 (menu freshness):** `<x-site.menu>` drops a `page` link whose slug is no longer published —
   one `Page::query()->visible()->pluck('slug')` per request, memoised on the request, only when the tree holds a
   page link. D-W3-8 (the frozen tree) is otherwise untouched. Visibility is applied per request after the cache.
3. **Forced-dark scopes.** Surfaces that are dark in both themes (hero over an image, dark CTA panels, the
   footer, a scrolled-to-top header over a hero) wrap their content in `class="dark"`, so every `dark:` utility and
   every `ButtonStyle::classes()` read correctly with no second class list. A light custom CTA colour yields to
   slate in the dark theme.
4. **Hero video** is inserted by Alpine only at `md`+ without `prefers-reduced-motion` and while
   `website.hero_video_enabled` is on; the poster / background image paints underneath (R-7).
5. **Business hours** are printed as the stored `HH:MM` strings, grouped by identical consecutive days — not
   through `app_time()`, which would shift a wall-clock time through the display timezone (D61). The copyright
   year uses `app_date(now(), 'Y')`; `{year}` and `{company}` are interpolated.
6. **Services / courses teasers** only render once Phase 4 / 14 register those types; until they have data they
   show an honest empty state with the real contact email and phone, never invented offerings.
7. **SEO component:** analytics ids must match the vendor format (`GTM-…`, `G-…`, digits) or nothing renders;
   never in preview; `@json` for every id.

---

### Phase 3 admin CMS screens (resources/views/admin/cms/**)

**Delivered (new files only; no Phase 1/2 file, layout or `x-ui.*` component edited).** 43 Blade files. `view:cache`
then `view:clear`; every compiled file `php -l` clean. A read-only render script (rolled-back transaction, array
cache and session, contract routes registered in memory only, fake models shaped exactly like the Admin\Cms
controllers pass them) rendered all 23 screens with 0 failures, no unrendered component and no leaked Blade
syntax, and proved the repeater item-error isolation below. No route, test, seeder, npm or composer command was run.

```
admin/cms/overview.blade.php                       admin.website.index
admin/cms/sections/{index,available,edit,revisions}.blade.php
admin/cms/sections/partials/{add-form,repeater,item-form}.blade.php
admin/cms/statistics/index.blade.php
admin/cms/menus/{index,show,link-check}.blade.php   menus/partials/node.blade.php
admin/cms/pages/{index,create,edit,revisions}.blade.php   pages/partials/form.blade.php
admin/cms/cta-blocks/{index,edit,usage}.blade.php  cta-blocks/partials/form.blade.php
admin/cms/faqs/index.blade.php                     admin/cms/faq-categories/index.blade.php
admin/cms/media/{index,show,usage}.blade.php
admin/cms/seo/{index,edit,robots}.blade.php        admin/cms/sitemap/history.blade.php
admin/cms/partials/  scripts (the Alpine components), field (registry field switch), media-picker,
                     media-library-json, richtext, link-field, icon-picker, length-meter, seo-fields,
                     status-badge, revisions-table, usage-list
```

#### 1. View names and variables — they match the controllers on disk

The views consume exactly the variables of the "Phase 3 controllers" block §3 table (checked against every
`return view(...)` in `app/Http/Controllers/Admin/Cms`). Every variable is read defensively (`?? default`), so a
missing optional one degrades instead of throwing. Nothing else is required, with **one request**:

- **Pass `mediaLibrary` to five more screens** — same shape as `SectionController::mediaLibrary()` (list of
  `id, name, alt_text, mime_type, kind, url, width, height`): `PageController@create`, `PageController@edit`,
  `CtaBlockController@index` (create dialog), `CtaBlockController@edit`, `SeoController@edit`. Without it those
  image pickers (page banner, OG image, CTA background) show "nothing in the library" and can only keep or clear
  the current image — a stored id is always kept as a placeholder chip, so saving never clears an image silently.
  Copy-paste per action: `'mediaLibrary' => $this->mediaLibrary(),` plus the private method from `SectionController`
  (inject `MediaService` where the controller does not have it).

#### 2. Every route name the views call (all declared in the controllers block §2 table)

`admin.website.index`, `admin.website.cache.flush`, `admin.website.statistics.index`,
`admin.website.sections.{index,store,edit,update,reorder,publish,unpublish,toggle,duplicate,destroy}`,
`admin.website.sections.revisions.{index,revert}`, `admin.website.sections.items.{store,reorder}`,
`admin.website.section-items.{update,toggle,destroy}`,
`admin.website.menus.{index,show,update,reorder,link-check}`, `admin.website.menus.items.store`,
`admin.website.menu-items.{update,toggle,destroy}`,
`admin.website.pages.{index,create,store,edit,update,publish,schedule,unpublish,duplicate,destroy,restore,export,preview-link}`,
`admin.website.pages.revisions.{index,revert}`,
`admin.website.cta-blocks.{index,store,edit,update,toggle,usage,destroy}`,
`admin.website.faqs.{index,store,update,toggle,reorder,destroy}`,
`admin.website.faq-categories.{index,store,update,reorder,destroy}`,
`admin.website.seo.{index,edit,update,bulk-robots,export,robots.preview}`, `admin.website.seo.sitemap.{regenerate,history}`,
`admin.website.media.{index,store,show,update,regenerate,destroy}`,
`site.preview.section`, `site.preview.page`, `site.page`, `site.home`, `admin.settings.index` (`['group' => …]`).
The `site.*` and `admin.settings.index` links and a few optional buttons sit behind `Route::has()`.

#### 3. What the forms post (each matches its Form Request)

| Screen | Posts |
|---|---|
| section editor | `content[field]…`, `media[role]` (id or `''`) / `media[role][]`, `name`, `anchor`, `publish` 0/1 |
| repeater item | `item[field]…` (+ `group` on store), and `_item` = item id or `new_{group}` — see §4 |
| reorders (JSON) | sections `placement`, `page_id` (page placement only), `order[]`; items / FAQ categories `order[]`; FAQs `faq_category_id` (id or null) + `order[]`; menus `tree` = `[{id, children: [{id, children: []}]}]` |
| toggles | sections / items / menu items `enabled` 0/1; CTA blocks and FAQs `status` (draft / published) |
| destructive / reasoned | unpublish, remove section, revert `reason` (required, `x-ui.confirm`); media delete `reason` (optional); page schedule `publish_at` (datetime-local, display timezone) |
| page form | `title, slug, layout, excerpt, content, show_banner, banner_media_id, banner_heading, banner_subheading, template, sort_order`, `seo[title|meta_description|meta_keywords|canonical_url|robots|og_image_media_id]`, `publish` 0/1 (`auto_slug` is UI-only, ignored) |
| CTA form | the `ValidatesCtaBlock` fields, **no `status`** (prohibited) |
| FAQ dialog | `question, answer, faq_category_id, is_featured`, **no `status`** (prohibited); `_faq` UI-only |
| menu item dialog | the `ValidatesMenuItem` fields (inactive target controls are disabled, so not posted); `_item_id` UI-only |
| SEO editor / bulk | `target`, `seo[...]` (editorRules keys), `reason` / `targets[]`, `robots`, `sitemap_include` |
| media upload | one XHR per file: `file`, `collection`, `profile`, `Accept: application/json`; a 422 `errors.file[0]` is printed beside the file |
| filters (GET) | only `CmsListRequest` keys: `search status layout system unpublished missing_seo trashed enabled variant unused collection type derivatives category featured attached robots gap mode metric page_id sort direction page` |

Every fetch-based action (drag reorder, bulk publish/enable/disable, usage popover, share-preview link) sends
`Accept: application/json` + `X-CSRF-TOKEN` and reads `RespondsForCms`' JSON (`message`, `errors`, `url`, `usage`).

#### 4. Decisions an owner may want to overrule

1. **Drag-to-reorder without SortableJS.** `partials/scripts` registers the Alpine components on `alpine:init`
   (`cmsSortable`, `cmsMenuTree`, `cmsMediaPicker`, `cmsUploader`, `cmsSlug`, `cmsLengthMeter`, `cmsBulk`, `cmsDirty`,
   `cmsFetchList`, `cmsCopy`) using native HTML5 drag from a handle plus Move up / Move down buttons, an `aria-live`
   announcement, restore-on-failure and an error toast (§8.2). `sortablejs` is not installed and is not needed by
   these views; `cmsMenuTree` refuses a third level client-side before the server does.
2. **Rich text.** `partials/richtext` renders `<trix-editor>` only when a `resources/js/cms.js` Vite entry exists and
   is built (or the dev server is hot) — integration B.3 — and otherwise a plain HTML textarea. The server sanitises
   either way (INV-13). The FAQ dialog uses the textarea always (a Trix editor cannot be re-seeded per question).
3. **Repeater item errors stay in their form.** All item forms share `item[...]` names; while the others render,
   the repeater hides the flashed input and the error bag (restored straight after), keyed by the posted `_item`.
   Render-verified: the refused value appears once, only in the failed form.
4. **No draft autosave.** The section editor saves on submit; the preview iframe reloads on demand (§8.5 asks for a
   600 ms autosave — it needs a JSON update round-trip, not added).
5. **Not built, no route or service exists:** a menu "create" action (G-1, missing slots show an empty state), hand
   picking FAQs for a `source = selected` FAQ section, a robots.txt editor under `seo.edit` (G-3: read-only + a link
   to Settings → SEO for `settings.edit` holders), the metric-health cards on the statistics screen (no per-metric
   reason is passed).
6. **CTA previews are admin approximations** of the public variants (no `site.cta.*` partial is included in the admin).
7. **Tailwind:** the views use new arbitrary utilities (`has-[:checked]:`, `aspect-[1200/630]`, `min-h-[10rem]`…);
   `resources/views/**` is already in the content glob, so the next `npm run build` picks them up — nothing to edit.
