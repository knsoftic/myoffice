# Phase 3 integration list

Produced 2026-09-13 09:40 by the Phase 3 integration-list agent. **Nothing below has been applied or
executed.** Every statement about the tree was checked against the files on disk and read-only SELECTs
on `my_office` at 09:38. Where the handover or the contract disagrees with the code, the code is quoted.
Where they disagree with each other and no code exists, the disagreement is flagged in section M with a
recommendation, never silently resolved.

Inputs read in full: `CLAUDE.md`, `docs/phases/phase-03.md` (1,788 lines), `docs-pending/phase-03-handover.md`,
the 10 migrations `2026_09_12_07*`, `app/Enums/Cms/**`, `app/Support/Cms/**`, `app/Services/Cms/**`,
`app/Support/RichText.php`, `app/Models/Cms/**`, `resources/views/components/site/**`, and the existing
files each step edits (`PermissionRegistry`, `SettingsRegistry`, `Sidebar`, `ModuleService`, `Modules`,
`AppServiceProvider`, `bootstrap/app.php`, `routes/*.php`, `RoleSeeder`, `ModuleSeeder`, `DatabaseSeeder`,
`EnsurePublicSiteAvailable`, and the Phase 1/2 tests that touch those surfaces). The view agents filed no
reports (REPORTS was empty), so section K reconciles against the contract and the code instead.

---

## 0. State of the tree (verified 09:38)

### 0.1 Present

| Area | Files | Notes |
|---|---|---|
| Migrations | 10 files, applied to `my_office` in batch 4 | all 14 tables empty (SELECT COUNT = 0). The 7 CHECK constraints, both STORED generated `has_unpublished_changes` columns and all 10 `uq_*` keys exist (information_schema checked) |
| Enums | 18 in `app/Enums/Cms` | 16 contract enums + `StatisticValueMode`, `FaqSource` |
| Support | `app/Support/Cms/{SectionRegistry,ImageProfile,ImageDerivative}.php`, `app/Support/RichText.php` | |
| Services | `app/Services/Cms/*` (13 classes + `Data/` 2 + `Exceptions/` 6 + `Media/GdImageProcessor`) | |
| Models | `app/Models/Cms/{Page,Menu,MenuItem,CtaBlock,MediaAsset}.php` | |
| Components | `resources/views/components/site/{accordion,button,heading,image,prose,section,stat}.blade.php` | |

### 0.2 Missing: hard prerequisites (integration must not start until they exist)

| Kind | Missing | Who references it today |
|---|---|---|
| Models | `WebsiteSection`, `WebsiteSectionItem`, `SeoMeta`, `CmsRevision`, `SitemapGeneration`, `Faq`, `FaqCategory` | the first five are imported by 6 service files (`ContentPublisher`, `RevisionRecorder`, `SectionService`, `SeoService`, `SitemapGenerator`, `SnapshotBuilder`) and would fatal on first use |
| Services | `PageService` (+ `RESERVED_SLUGS` const, see F.4), `MenuService`, `CtaBlockService`, `FaqService`, `StatisticsProvider`, `PreviewService`, `PublicPageService` | controllers, routes (F.4), seeders |
| Support | `App\Support\SiteSettings`, `NonPublicSettingException`, `site_setting()` helper | INV-10, FT-42 |
| Middleware | `CachePublicResponse`, `ResolvePreviewMode`, `EnsurePreviewAuthorised` | aliases in E.3 |
| Controllers | all 21 classes named in F (16 admin + 5 public; 0 exist in `app/Http/Controllers/Admin/Cms`, `Admin/Website` or `Site`) | every route in F |
| Requests, Policies | 0 exist in `app/Http/Requests/Cms`, `app/Policies/Cms` | E.2, controllers |
| Views | `layouts/site.blade.php`; `site/{home,holding,maintenance,404}`; `site/pages/{default,wide,legal}`; the 7 `site/sections/*`; the 5 `site/cta/*`; components `site/{seo,menu,cta,social-links,preview-ribbon,theme-toggle}`; every `components/cms/*`; every admin CMS screen | controllers, SectionRegistry::view(), CtaVariant::view() |
| Jobs | `app/Jobs/Cms/{GenerateImageDerivatives,RegenerateSitemap,WarmPublicPageCache,RecountMediaUsage,PruneCmsRevisions}` | services guard with `class_exists()` (safe to be absent) |
| Data | `resources/data/icons.php` | `SectionValidator::icons()`, Form Requests |
| Seeder, tests | `database/seeders/WebsiteCmsSeeder.php`, `tests/Feature/Cms/**` | I, L |

### 0.3 Paths no Phase 3 agent owns (someone must be assigned, or these never get written)

The Phase 3 path list covers `app/Enums/Cms`, `app/Support/Cms`, `app/Services/Cms`, `app/Models/Cms`,
`app/Policies/Cms`, `app/Http/Controllers/{Site,Admin/Cms}`, `app/Http/Requests/Cms`,
`resources/views/{site,admin/cms,components/site}`. It does **not** cover, yet the contract needs:
`app/Http/Middleware/*` (3 new classes + the `EnsurePublicSiteAvailable` extension), `app/Jobs/Cms`,
`app/Support/SiteSettings.php`, `resources/data/icons.php`, `resources/views/layouts/site.blade.php`
(and Phase 3 agents are forbidden `layouts/**`), `resources/views/components/cms/*`, `resources/js/cms.js`,
`database/seeders/WebsiteCmsSeeder.php`, `tests/Feature/Cms/**`, `routes/site-pages.php`.

### 0.4 Phase 2 is still moving

`SettingsRegistry`, `SettingsService`, `ModuleService`, `ConfigureFromSettings` and several tests are being
edited right now for D61/D62/D63. Steps C, D and I edit those files or their neighbours. **Apply this list
only after the Phase 2 pass is committed**, then re-read each target file before pasting.

---

## Application order

Each step only references things created by an earlier step or listed in 0.2 as a prerequisite.

| Step | Brief item | What |
|---|---|---|
| A | - | Prerequisite gate (script) |
| B | 5 | Composer / npm packages, Vite entry |
| C | 1 | PermissionRegistry, dependency graph, model-to-module map |
| D | 6 | SettingsRegistry keys |
| E | 4 | Helper, container bindings, policies, middleware aliases + gate extension, exception mapping |
| F | 2 | Routes (admin, public, catch-all, bootstrap) with the permission / controller cross-check |
| G | 3 | Sidebar |
| H | 4 | Scheduler |
| I | 7 | Seeders (RoleSeeder, WebsiteCmsSeeder, DatabaseSeeder) and the dev-DB run |
| J | - | Existing tests that must change (contract-backed, not loosened) |
| K | 8 | Reconciliation |
| L | 9 | Verification script, ending with the acceptance tests |
| M | 10 | Risks |

---

## A. Prerequisite gate

Run from Git Bash. It must print nothing before any later step is applied.

```bash
cd "/c/xampp/htdocs/my office"
for f in \
  app/Models/Cms/{WebsiteSection,WebsiteSectionItem,SeoMeta,CmsRevision,SitemapGeneration,Faq,FaqCategory}.php \
  app/Services/Cms/{PageService,MenuService,CtaBlockService,FaqService,StatisticsProvider,PreviewService,PublicPageService}.php \
  app/Support/SiteSettings.php resources/data/icons.php \
  app/Http/Middleware/{CachePublicResponse,ResolvePreviewMode,EnsurePreviewAuthorised}.php \
  app/Http/Controllers/Admin/Cms/{WebsiteOverviewController,SectionController,SectionItemController,SectionRevisionController,PublicCacheController,StatisticController,MenuController,MenuItemController,PageController,PageRevisionController,CtaBlockController,FaqController,FaqCategoryController,SeoController,SitemapController,MediaController}.php \
  app/Http/Controllers/Site/{HomeController,PageController,PreviewController,RobotsController,SitemapController}.php \
  app/Policies/Cms/{WebsiteSectionPolicy,WebsiteSectionItemPolicy,MenuPolicy,MenuItemPolicy,PagePolicy,CtaBlockPolicy,FaqPolicy,FaqCategoryPolicy,SeoMetaPolicy,MediaPolicy,CmsRevisionPolicy}.php \
  resources/views/layouts/site.blade.php \
  resources/views/site/{home,holding,maintenance,404}.blade.php \
  resources/views/site/pages/{default,wide,legal}.blade.php \
  resources/views/site/sections/{header,hero,about,rich_content,faq,cta,footer}.blade.php \
  resources/views/site/cta/{banner,card,inline,split,full_width}.blade.php \
  database/seeders/WebsiteCmsSeeder.php ; do
  test -f "$f" || echo "MISSING $f"
done
grep -q "RESERVED_SLUGS" app/Services/Cms/PageService.php 2>/dev/null || echo "MISSING PageService::RESERVED_SLUGS"
test -e public/storage || echo "MISSING storage link: run php artisan storage:link"
```

If the controllers land under `Admin/Website` instead of `Admin/Cms` (the contract's namespace, see M-2),
change the one directory in the loop above and the `use` lines in F.1. Nothing else changes.

---

## B. Packages (brief item 5)

### B.1 Composer

```bash
composer require mews/purifier:^3.4
```

After install, **replace** `config/purifier.php` with exactly the file in `docs-pending/phase-03-handover.md`
("Phase 3 services" section 1). It mirrors `RichText::PROFILES` (`cms`, `material`) and nothing else.
Then `mkdir -p storage/app/purifier`.

- Fallback when not installed: `RichText::engine()` returns `dom` and the DOM walker alone enforces the
  allowlist. Output is equally safe (handover: verified against script, on*, javascript:, iframe host, Blade,
  data:, CSS cases). **But FT-36b asserts "exactly two purifier profiles" in `config/`**. Without the
  package and the file, FT-36b fails as written. Install it, or have the owner amend FT-36b. Do not
  delete the assertion.

```bash
composer require intervention/image:^3
```

- Contract §13.4 lists it. **No Phase 3 code calls it**: `MediaService` uses GD directly
  (`GdImageProcessor`). Recommendation: do not install in Phase 3 and record the deviation in
  `DEVELOPMENT_LOG.md`. Fallback if installed: nothing uses it, nothing changes.
- Check GD before relying on uploads: `php -r "print_r(gd_info());"`. You need JPEG, PNG, WebP, and AVIF if
  AVIF uploads are wanted. Without an encoder, that format is refused at upload with a readable reason.

### B.2 npm

```bash
npm i -D sortablejs@^1.15 trix@^2.1
```

`-D` matches `package.json`, where every front-end dependency is a devDependency bundled by Vite.

### B.3 Vite entry, loaded only on admin CMS screens

New file `resources/js/cms.js`:

```js
import Sortable from 'sortablejs';
import Trix from 'trix';
import 'trix/dist/trix.css';

// RichText's `cms` profile allows <p> and h2-h4 only. Trix defaults to <div> blocks and <h1>
// headings, which the sanitiser strips, so saved text would lose its structure (M-11).
Trix.config.blockAttributes.default.tagName = 'p';
Trix.config.blockAttributes.heading1.tagName = 'h2';

// No inline attachments: images go through the media library (D24), never data:/blob uploads.
document.addEventListener('trix-file-accept', (event) => event.preventDefault());

// <x-cms.sortable> is the single drag implementation (section 8.2) and reads it from here.
window.Sortable = Sortable;
```

`vite.config.js`, in `input`:

```js
input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/charts.js', 'resources/js/cms.js'],
```

Each admin CMS view (or `<x-cms.sortable>` / `<x-cms.richtext>`) includes it with `@once @vite('resources/js/cms.js') @endonce`.
This is the same pattern `<x-ui.chart>` uses for `charts.js`. Then run `npm run build`.

---

## C. PermissionRegistry, dependency graph, model-to-module map (brief item 1)

### C.1 `app/Support/PermissionRegistry.php`: replace three Website entries

The contract §4.2 table would drop `FILES`/`RESTORE` from `website_sections`/`pages` and `IMPORT` from
`seo`. Those permissions are already seeded (verified: `website_sections.upload/download/restore`,
`pages.upload/download`, `seo.import` exist in `permissions`). §4.2 is headed "Additive", and a removal
would strip them from every role on the next `RoleSeeder` run. So: **add `LOGS`, remove nothing.**

```php
            'website_sections' => [
                'name' => 'Website Sections',
                'group' => ModuleGroup::Website,
                'icon' => 'view-columns',
                'is_core' => false,
                'sort' => 810,
                // phase-03 §4.2: + LOGS (revisions are read under view_logs). FILES/RESTORE are Phase 1
                // grants kept on purpose: the registry is additive (D4).
                'abilities' => self::merge(self::CRUD, self::STATUS, self::FILES, self::RESTORE, self::LOGS),
            ],
```

```php
            'pages' => [
                'name' => 'Pages',
                'group' => ModuleGroup::Website,
                'icon' => 'document',
                'is_core' => false,
                'sort' => 830,
                // phase-03 §4.2: + LOGS. FILES/RESTORE kept (additive, D4); `restore` backs admin.website.pages.restore.
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::FILES, self::RESTORE, self::LOGS),
            ],
```

```php
            'seo' => [
                'name' => 'SEO',
                'group' => ModuleGroup::Website,
                'icon' => 'globe-alt',
                'is_core' => false,
                'sort' => 960,
                // phase-03 §4.2: READ + edit + export + LOGS. No create/delete: a seo_meta row is an
                // attribute of its target. IMPORT is a Phase 1 grant kept on purpose (additive, D4).
                'abilities' => self::merge(self::READ, self::EDIT_ONLY, self::IMPORT, [Ability::Export], self::LOGS),
            ],
```

`menus` (CRUD + STATUS + RESTORE) and `faqs` (CRUD + STATUS + RESTORE) already satisfy §4.2. Leave them unchanged.

### C.2 Add three modules (§4.1) in the Website block

The contract fixes group, icon, `is_core` and abilities. **It gives no `sort` and no name**, so both
below are guesses (M-15). Each sort is unique within the registry, so the `sort * 100 + position`
permission ordering cannot collide.

```php
            'website_cta_blocks' => [
                'name' => 'CTA Blocks',
                'group' => ModuleGroup::Website,
                'icon' => 'megaphone',
                'is_core' => false,
                'sort' => 835,
                // phase-03 §4.1: delete is policy-blocked while usage_count > 0.
                'abilities' => self::merge(self::CRUD, self::STATUS, self::LOGS),
            ],
```

```php
            'faq_categories' => [
                'name' => 'FAQ Categories',
                'group' => ModuleGroup::Website,
                'icon' => 'rectangle-stack',
                'is_core' => false,
                'sort' => 905,
                // phase-03 §4.1: mirrors the blog_categories / blog_posts split.
                'abilities' => self::merge(self::CRUD, self::STATUS),
            ],
```

```php
            'website_media' => [
                'name' => 'Media Library',
                'group' => ModuleGroup::Website,
                'icon' => 'photo',
                'is_core' => false,
                'sort' => 970,
                // phase-03 §4.1: the shared CMS image library (D24). `edit` = alt text, title, caption only.
                'abilities' => self::merge(self::READ, [Ability::Create, Ability::Edit, Ability::Delete], self::FILES, self::LOGS),
            ],
```

### C.3 `depends_on`

The array shape above has no `depends_on`. Dependencies live in
`App\Services\Core\ModuleService::DEPENDENCY_GRAPH` and are projected onto `modules.depends_on` by
`ModuleSeeder`. **The contract declares no edge for any Phase 3 module, and none is added.** The graph's
own rule: a dependency means "the dependent's own rows point at the other module's rows, so its screens
are meaningless while it is off". The only candidate, `'faqs' => ['faq_categories']`, fails that rule
because `faqs.faq_category_id` is nullable and uncategorised FAQs are a supported bucket (§6.13).
`website_sections` referencing menus, CTA blocks and media is optional per field, and INV-15 keeps the
public site serving regardless.

### C.4 Model-to-module resolution for policy-style checks

`Gate::before` rule 1 resolves the module of `$user->can('delete', $model)` through
`Modules::moduleForSubject()`: first `moduleSlug()`, then `MODEL_MODULES`, then the plural of the class
name. By convention `Page`, `Menu`, `Faq`, `FaqCategory` and `WebsiteSection` resolve correctly. These
do not, so a disabled module would not deny a policy-style check on them:

| Model | Convention guess | Must be |
|---|---|---|
| `MenuItem` | `menu_items` (not a module) | `menus` |
| `WebsiteSectionItem` | `website_section_items` | `website_sections` |
| `CtaBlock` | `cta_blocks` | `website_cta_blocks` |
| `MediaAsset` | `media_assets` | `website_media` |
| `SeoMeta` | `seo_metas` | `seo` |
| `SitemapGeneration` | none | `seo` |
| `CmsRevision` | none | the revisionable's module (`website_sections` or `pages`) |

Preferred fix (Phase 3 model files, local to the owner). On each model:

```php
    /** The module that owns this model, for Gate::before's module rule (App\Support\Modules::SUBJECT_MODULE_METHOD). */
    public function moduleSlug(): string
    {
        return 'website_media'; // per the table above
    }
```

For `CmsRevision`:
`return $this->revisionable_type === \App\Models\Cms\Page::class ? 'pages' : 'website_sections';`.
Route-level `module:` and `can:` middleware already close every route, so this matters for checks made
outside routes (views, services).

---

## D. SettingsRegistry (brief item 6)

`app/Support/SettingsRegistry.php` (Phase 2 file). Import at the top:
`use App\Enums\Cms\SitemapChangeFrequency;`.

### D.1 `groups()`: insert between `mail` (70) and `collaborator` (80)

```php
            'website' => [
                'label' => 'Website & Forms',
                'icon' => 'globe-alt',
                'description' => 'Public-site caching, preview links, image processing, menu depth, revision history and public display toggles.',
                'sort' => 75,
            ],
```

### D.2 `definitions()`: insert after `'mail' => self::mailFields(),`

```php
            'website' => self::websiteFields(),
```

### D.3 New method: the 13 Phase 3 keys of §5.1a

```php
    /**
     * phase-03 §5.1a — the 13 keys Phase 3 declares. Phase 4 appends its 21 (§5.1b) to this same
     * method; it declares no second `website` group (F-6.3).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function websiteFields(): array
    {
        return [
            'cache_enabled' => [
                'label' => 'Cache public pages',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Anonymous visitors are served a stored copy. Publishing anything invalidates every copy at once.',
                'span' => 6,
                'sort' => 10,
            ],
            'cache_ttl_minutes' => [
                'label' => 'Cached page lifetime',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:10080'],
                'default' => 1440,
                'suffix' => 'minutes',
                'help' => 'Publishing clears the cache immediately whatever this is set to.',
                'span' => 6,
                'sort' => 20,
            ],
            'cache_warm_enabled' => [
                'label' => 'Re-render pages after a publish',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'span' => 6,
                'sort' => 30,
            ],
            'preview_ttl_minutes' => [
                'label' => 'Shareable preview link lifetime',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:5', 'max:10080'],
                'default' => 120,
                'suffix' => 'minutes',
                'span' => 6,
                'sort' => 40,
            ],
            'image_quality' => [
                'label' => 'Image quality',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:60', 'max:95'],
                'default' => 82,
                'help' => '60-95. Applies to newly generated image sizes.',
                'span' => 6,
                'sort' => 50,
            ],
            'image_max_width' => [
                'label' => 'Largest stored image width',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:320', 'max:8000'],
                'default' => 2560,
                'suffix' => 'px',
                'help' => 'Wider uploads are scaled down. Nothing is ever scaled up.',
                'span' => 6,
                'sort' => 60,
            ],
            'image_webp_enabled' => [
                'label' => 'Also generate WebP images',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'public' => true,
                'span' => 6,
                'sort' => 70,
            ],
            'image_lazy_loading' => [
                'label' => 'Lazy-load images below the hero',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'public' => true,
                'span' => 6,
                'sort' => 80,
            ],
            'menu_max_depth' => [
                'label' => 'Menu depth',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'in:2'],
                'default' => 2,
                'help' => 'Fixed at two levels by a database constraint (INV-6). Shown for information; raising it is not possible.',
                'readonly' => true,
                'span' => 6,
                'sort' => 90,
            ],
            'revision_keep' => [
                'label' => 'Draft revisions kept per item',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:500'],
                'default' => 20,
                'help' => 'Published versions are always kept.',
                'span' => 6,
                'sort' => 100,
            ],
            'hero_video_enabled' => [
                'label' => 'Allow hero background video',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'public' => true,
                'help' => 'Off shows the poster image everywhere, which saves visitors mobile data.',
                'span' => 6,
                'sort' => 110,
            ],
            'faq_accordion_open_first' => [
                'label' => 'Open the first FAQ answer',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'public' => true,
                'span' => 6,
                'sort' => 120,
            ],
            'show_theme_toggle' => [
                'label' => 'Show the light/dark toggle on the public site',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'public' => true,
                'span' => 6,
                'sort' => 130,
            ],
        ];
    }
```

### D.4 Append to `seoFields()`: the four keys of §5.2

```php
            'robots_txt_mode' => [
                'label' => 'robots.txt',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string'],
                'options' => ['auto' => 'Generated automatically', 'custom' => 'Custom text'],
                'default' => 'auto',
                'help' => 'Whatever is chosen, search engines are told to stay away while the site is closed or not indexable.',
                'span' => 6,
                'sort' => 120,
            ],
            'robots_txt_custom' => [
                'label' => 'Custom robots.txt',
                'type' => self::TYPE_TEXTAREA,
                'rules' => ['nullable', 'string', 'max:5000'],
                'default' => null,
                'help' => 'Used only in custom mode. A Sitemap: line pointing at another domain is dropped when the file is served.',
                'span' => 12,
                'sort' => 130,
            ],
            'sitemap_changefreq_default' => [
                'label' => 'Default change frequency',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string'],
                'options' => SitemapChangeFrequency::options(),
                'default' => 'weekly',
                'span' => 6,
                'sort' => 140,
            ],
            'sitemap_priority_default' => [
                'label' => 'Default sitemap priority',
                'type' => self::TYPE_DECIMAL,
                'rules' => ['required', 'numeric', 'min:0', 'max:1', 'decimal:0,1'],
                'default' => '0.5',
                'span' => 6,
                'sort' => 150,
            ],
```

Notes:
- `SettingsRegistry` stays database-free: `SitemapChangeFrequency::options()` is a pure array.
- The four `seo.*` keys are `public => false` (§5.2), and the services read them through
  `SettingsRepository` server-side. `security.max_upload_mb` stays non-public for the same reason.
- **Phase 4's 21 keys (§5.1b) are not added here**: they have no reader until Phase 4 ships. This is M-24.
- D62's readonly handling covers `website.menu_max_depth`: never posted, refused by `SettingsService`.
- After the edit, run `php artisan db:seed --class=SettingSeeder` (idempotent: inserts values once,
  refreshes metadata). Integrator stage only.

---

## E. Helper, bindings, policies, middleware, exceptions (brief item 4, part 1)

### E.1 `app/Support/helpers.php`: append

```php
if (! function_exists('site_setting')) {
    /**
     * The ONLY way a public view reads a setting (phase-03 §5.3, INV-10). Throws
     * NonPublicSettingException for any key whose registry definition is not `public => true`.
     *
     *   site_setting('company.name');
     */
    function site_setting(string $key, mixed $default = null): mixed
    {
        return app(\App\Support\SiteSettings::class)->get($key, $default);
    }
}
```

`App\Support\SiteSettings::get()` must check `SettingsRegistry::field($key)['public'] === true` (the
registry, **not** the `settings.is_public` column, which a hand edit could flip) and then delegate to
`SettingsRepository::get($key, $default)`.

### E.2 `app/Providers/AppServiceProvider.php`

`register()`, after the `SettingsRepository` singleton:

```php
        // Phase 3 (D22): one version stamp and one bump batch per request / queued job.
        $this->app->scoped(\App\Services\Cms\CacheVersion::class);
        // Phase 3: per-request preview state and the 15-minute statistics memo.
        $this->app->scoped(\App\Services\Cms\PreviewService::class);
        $this->app->scoped(\App\Services\Cms\StatisticsProvider::class);
        $this->app->singleton(\App\Support\SiteSettings::class);
```

Only the `CacheVersion` line is required by existing code (handover). The other three are there for
classes not yet written; drop any line whose class holds no per-request state.

`POLICIES` constant: append.

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
```

`MediaPolicy` is the contract's name (§2.13) and does not follow Laravel's `{Model}Policy` convention,
so explicit registration is mandatory for it. The rest would be auto-discovered from `App\Policies\Cms`,
but the project registers explicitly. Rules the policies must hold, each backed by a test:

- `WebsiteSectionPolicy::delete`: false for `SectionRegistry::isRequired()` (INV-7, FT-16).
- `PagePolicy::delete`: false for `is_system` (FT-17).
- `CtaBlockPolicy::delete`: false while `usage_count > 0`.
- `MediaPolicy::delete`: false while `usage_count > 0` (FT-38).
- `CmsRevisionPolicy::view`: `website_sections.view_logs` or `pages.view_logs`, depending on the revisionable.

No listener is registered. The services bump the cache themselves after commit, so adding §10.1's
`BumpPublicCacheVersion` on top would double-bump and break FT-25's "+1 exactly" (handover decision 12).

Dashboard widgets (§13.2) need no registration. `DashboardRegistry` auto-discovers
`app/Dashboard/Widgets/**` and views in `resources/views/admin/dashboard/widgets/`.

### E.3 `bootstrap/app.php`: middleware aliases

Inside `$middleware->alias([...])`, next to `'public_site'`:

```php
            // phase-03 §6.10. The contract's `site` alias is deliberately NOT added: Phase 2 already ships
            // the one maintenance gate as `public_site` (§12.2 Q2 "extend, never a second gate"), and
            // MaintenanceModeTest asserts that literal alias on every public GET route.
            'site.cache' => \App\Http\Middleware\CachePublicResponse::class,
            'site.preview' => \App\Http\Middleware\ResolvePreviewMode::class,
            'site.preview.auth' => \App\Http\Middleware\EnsurePreviewAuthorised::class,
```

The `site.preview.auth` alias name is a guess. §7.6 names only the class.

**Extend `App\Http\Middleware\EnsurePublicSiteAvailable`** (Phase 2 file) as follows. Do not add a second class.

1. `public_site_enabled = false` renders `site.holding`; `maintenance_mode = true` renders `site.maintenance`.
   Keep both `View::exists()` fallbacks and the variables `company`, `heading`, `message`: tests assert
   "Scheduled maintenance", "This website is currently unavailable." and the admin's message.
2. Add `X-Robots-Tag: noindex` to both 503 responses. Keep `Retry-After: 3600`.
3. Bypass rule: `$request->user()?->can('website_sections.view') === true` passes the request through and
   sets `$request->attributes->set('site_state', 'maintenance'|'disabled')` for the layout's amber ribbon.
   The check is the permission, never the login (§9: a student is a visitor).

### E.4 `bootstrap/app.php`: exception mapping (replaces the empty `withExceptions` body)

Top of file:

```php
use App\Services\Cms\Exceptions\ContentActionNotAllowedException;
use App\Services\Cms\Exceptions\InvalidSectionContentException;
use App\Services\Cms\Exceptions\MediaInUseException;
use App\Services\Cms\Exceptions\UnknownSectionTypeException;
use App\Services\Cms\Exceptions\UnsupportedUploadException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
```

```php
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
        | Phase 3: CMS refusals read as 422 (fix the input) or 403 (protected), never a stack trace.
        | `map()` rather than throwing inside `render()`: the mapped ValidationException then renders
        | through the framework's own 422 / redirect-with-errors path.
        */
        $exceptions->map(fn (InvalidSectionContentException $e) => $e->toValidationException());
        $exceptions->map(fn (UnsupportedUploadException $e) => $e->toValidationException('file'));
        $exceptions->map(fn (UnknownSectionTypeException $e) => ValidationException::withMessages([
            'section_key' => [$e->getMessage()],
        ]));

        // Needs ContentActionNotAllowedException::status(): 422 | 403 and ::field(): ?string (M-7).
        $exceptions->map(fn (ContentActionNotAllowedException $e) => $e->status() === 422
            ? ValidationException::withMessages([($e->field() ?? 'action') => [$e->getMessage()]])
            : $e);

        $exceptions->render(function (ContentActionNotAllowedException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 403)
                : back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        });

        $exceptions->render(function (MediaInUseException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage(), 'usage' => $e->usage], 403)
                : back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()])->with('media_usage', $e->usage);
        });
    })->create();
```

---

## F. Routes (brief item 2)

### F.1 `routes/admin.php`

`use` lines to add:

```php
use App\Enums\Cms\SectionPlacement;
use App\Http\Controllers\Admin\Cms\CtaBlockController;
use App\Http\Controllers\Admin\Cms\FaqCategoryController;
use App\Http\Controllers\Admin\Cms\FaqController;
use App\Http\Controllers\Admin\Cms\MediaController;
use App\Http\Controllers\Admin\Cms\MenuController;
use App\Http\Controllers\Admin\Cms\MenuItemController;
use App\Http\Controllers\Admin\Cms\PageController;
use App\Http\Controllers\Admin\Cms\PageRevisionController;
use App\Http\Controllers\Admin\Cms\PublicCacheController;
use App\Http\Controllers\Admin\Cms\SectionController;
use App\Http\Controllers\Admin\Cms\SectionItemController;
use App\Http\Controllers\Admin\Cms\SectionRevisionController;
use App\Http\Controllers\Admin\Cms\SeoController;
use App\Http\Controllers\Admin\Cms\SitemapController;
use App\Http\Controllers\Admin\Cms\StatisticController;
use App\Http\Controllers\Admin\Cms\WebsiteOverviewController;
```

Paste this block inside the existing `Route::prefix('admin')->name('admin.')->middleware(['auth', 'active', 'panel:admin'])`
group, after the login-history block:

```php
        /*
        |------------------------------------------------------------------
        | Website CMS (phase-03 §7.1-§7.5)
        |------------------------------------------------------------------
        | Every route: auth + active + panel:admin (group), module:<slug> (block), can:<permission>.
        | Literal segments are declared before the parameter routes that could swallow them.
        | {placement} is constrained to SectionPlacement::values() and implicitly enum-bound (an unknown
        | value 404s); {section} {item} {revision} {page} {menu} {ctaBlock} {faq} {category} {asset} are
        | implicit model bindings by controller type-hint. A revision's ownership is checked in the
        | controller with RevisionRecorder::assertBelongsTo() (no scopeBindings: M-19).
        */
        Route::prefix('website')->name('website.')->group(function (): void {

            // §7.1 Sections
            Route::middleware('module:website_sections')->group(function (): void {
                Route::get('/', [WebsiteOverviewController::class, 'index'])
                    ->middleware('can:website_sections.view_any')->name('index');

                Route::get('statistics', [StatisticController::class, 'index'])
                    ->middleware('can:website_sections.view_any')->name('statistics.index');

                Route::post('cache/flush', [PublicCacheController::class, 'flush'])
                    ->middleware(['can:website_sections.change_status', 'throttle:6,1'])->name('cache.flush');

                Route::post('sections/reorder', [SectionController::class, 'reorder'])
                    ->middleware('can:website_sections.edit')->name('sections.reorder');

                Route::get('sections/{placement}', [SectionController::class, 'index'])
                    ->whereIn('placement', SectionPlacement::values())
                    ->middleware('can:website_sections.view_any')->name('sections.index');

                Route::get('sections/{placement}/available', [SectionController::class, 'available'])
                    ->whereIn('placement', SectionPlacement::values())
                    ->middleware('can:website_sections.create')->name('sections.available');

                Route::post('sections/{placement}', [SectionController::class, 'store'])
                    ->whereIn('placement', SectionPlacement::values())
                    ->middleware('can:website_sections.create')->name('sections.store');

                Route::get('sections/{section}/edit', [SectionController::class, 'edit'])
                    ->whereNumber('section')->middleware('can:website_sections.view')->name('sections.edit');

                Route::put('sections/{section}', [SectionController::class, 'update'])
                    ->whereNumber('section')->middleware('can:website_sections.edit')->name('sections.update');

                Route::post('sections/{section}/publish', [SectionController::class, 'publish'])
                    ->whereNumber('section')->middleware('can:website_sections.change_status')->name('sections.publish');

                Route::post('sections/{section}/unpublish', [SectionController::class, 'unpublish'])
                    ->whereNumber('section')->middleware('can:website_sections.change_status')->name('sections.unpublish');

                Route::post('sections/{section}/toggle', [SectionController::class, 'toggle'])
                    ->whereNumber('section')->middleware('can:website_sections.change_status')->name('sections.toggle');

                Route::post('sections/{section}/duplicate', [SectionController::class, 'duplicate'])
                    ->whereNumber('section')->middleware('can:website_sections.create')->name('sections.duplicate');

                Route::delete('sections/{section}', [SectionController::class, 'destroy'])
                    ->whereNumber('section')->middleware('can:website_sections.delete')->name('sections.destroy');

                Route::get('sections/{section}/revisions', [SectionRevisionController::class, 'index'])
                    ->whereNumber('section')->middleware('can:website_sections.view_logs')->name('sections.revisions.index');

                Route::post('sections/{section}/revisions/{revision}/revert', [SectionRevisionController::class, 'revert'])
                    ->whereNumber(['section', 'revision'])->middleware('can:website_sections.change_status')->name('sections.revisions.revert');

                Route::post('sections/{section}/items', [SectionItemController::class, 'store'])
                    ->whereNumber('section')->middleware('can:website_sections.edit')->name('sections.items.store');

                Route::post('sections/{section}/items/{group}/reorder', [SectionItemController::class, 'reorder'])
                    ->whereNumber('section')->where('group', '[a-z_]+')
                    ->middleware('can:website_sections.edit')->name('sections.items.reorder');

                Route::put('section-items/{item}', [SectionItemController::class, 'update'])
                    ->whereNumber('item')->middleware('can:website_sections.edit')->name('section-items.update');

                Route::post('section-items/{item}/toggle', [SectionItemController::class, 'toggle'])
                    ->whereNumber('item')->middleware('can:website_sections.edit')->name('section-items.toggle');

                Route::delete('section-items/{item}', [SectionItemController::class, 'destroy'])
                    ->whereNumber('item')->middleware('can:website_sections.edit')->name('section-items.destroy');
            });

            // §7.2 Menus
            Route::middleware('module:menus')->group(function (): void {
                Route::get('menus', [MenuController::class, 'index'])
                    ->middleware('can:menus.view_any')->name('menus.index');

                Route::get('menus/{menu}', [MenuController::class, 'show'])
                    ->whereNumber('menu')->middleware('can:menus.view')->name('menus.show');

                Route::put('menus/{menu}', [MenuController::class, 'update'])
                    ->whereNumber('menu')->middleware('can:menus.edit')->name('menus.update');

                Route::post('menus/{menu}/reorder', [MenuController::class, 'reorder'])
                    ->whereNumber('menu')->middleware('can:menus.edit')->name('menus.reorder');

                Route::get('menus/{menu}/link-check', [MenuController::class, 'linkCheck'])
                    ->whereNumber('menu')->middleware('can:menus.view')->name('menus.link-check');

                Route::post('menus/{menu}/items', [MenuItemController::class, 'store'])
                    ->whereNumber('menu')->middleware('can:menus.create')->name('menus.items.store');

                Route::put('menu-items/{item}', [MenuItemController::class, 'update'])
                    ->whereNumber('item')->middleware('can:menus.edit')->name('menu-items.update');

                Route::post('menu-items/{item}/toggle', [MenuItemController::class, 'toggle'])
                    ->whereNumber('item')->middleware('can:menus.change_status')->name('menu-items.toggle');

                Route::delete('menu-items/{item}', [MenuItemController::class, 'destroy'])
                    ->whereNumber('item')->middleware('can:menus.delete')->name('menu-items.destroy');
            });

            // §7.3 Pages
            Route::middleware('module:pages')->group(function (): void {
                Route::get('pages', [PageController::class, 'index'])
                    ->middleware('can:pages.view_any')->name('pages.index');

                Route::get('pages/create', [PageController::class, 'create'])
                    ->middleware('can:pages.create')->name('pages.create');

                Route::get('pages/export', [PageController::class, 'export'])
                    ->middleware('can:pages.export')->name('pages.export');

                Route::post('pages', [PageController::class, 'store'])
                    ->middleware('can:pages.create')->name('pages.store');

                Route::get('pages/{page}/edit', [PageController::class, 'edit'])
                    ->whereNumber('page')->middleware('can:pages.view')->name('pages.edit');

                Route::put('pages/{page}', [PageController::class, 'update'])
                    ->whereNumber('page')->middleware('can:pages.edit')->name('pages.update');

                Route::post('pages/{page}/publish', [PageController::class, 'publish'])
                    ->whereNumber('page')->middleware('can:pages.change_status')->name('pages.publish');

                Route::post('pages/{page}/schedule', [PageController::class, 'schedule'])
                    ->whereNumber('page')->middleware('can:pages.change_status')->name('pages.schedule');

                Route::post('pages/{page}/unpublish', [PageController::class, 'unpublish'])
                    ->whereNumber('page')->middleware('can:pages.change_status')->name('pages.unpublish');

                Route::post('pages/{page}/duplicate', [PageController::class, 'duplicate'])
                    ->whereNumber('page')->middleware('can:pages.create')->name('pages.duplicate');

                Route::delete('pages/{page}', [PageController::class, 'destroy'])
                    ->whereNumber('page')->middleware('can:pages.delete')->name('pages.destroy');

                Route::post('pages/{page}/restore', [PageController::class, 'restore'])
                    ->whereNumber('page')->withTrashed()->middleware('can:pages.restore')->name('pages.restore');

                Route::get('pages/{page}/revisions', [PageRevisionController::class, 'index'])
                    ->whereNumber('page')->middleware('can:pages.view_logs')->name('pages.revisions.index');

                Route::post('pages/{page}/revisions/{revision}/revert', [PageRevisionController::class, 'revert'])
                    ->whereNumber(['page', 'revision'])->middleware('can:pages.change_status')->name('pages.revisions.revert');

                Route::get('pages/{page}/preview-link', [PageController::class, 'previewLink'])
                    ->whereNumber('page')->middleware('can:pages.view')->name('pages.preview-link');
            });

            // §7.4 CTA blocks
            Route::middleware('module:website_cta_blocks')->group(function (): void {
                Route::get('cta-blocks', [CtaBlockController::class, 'index'])
                    ->middleware('can:website_cta_blocks.view_any')->name('cta-blocks.index');

                Route::post('cta-blocks', [CtaBlockController::class, 'store'])
                    ->middleware('can:website_cta_blocks.create')->name('cta-blocks.store');

                Route::get('cta-blocks/{ctaBlock}/edit', [CtaBlockController::class, 'edit'])
                    ->whereNumber('ctaBlock')->middleware('can:website_cta_blocks.view')->name('cta-blocks.edit');

                Route::put('cta-blocks/{ctaBlock}', [CtaBlockController::class, 'update'])
                    ->whereNumber('ctaBlock')->middleware('can:website_cta_blocks.edit')->name('cta-blocks.update');

                Route::post('cta-blocks/{ctaBlock}/toggle', [CtaBlockController::class, 'toggle'])
                    ->whereNumber('ctaBlock')->middleware('can:website_cta_blocks.change_status')->name('cta-blocks.toggle');

                Route::get('cta-blocks/{ctaBlock}/usage', [CtaBlockController::class, 'usage'])
                    ->whereNumber('ctaBlock')->middleware('can:website_cta_blocks.view')->name('cta-blocks.usage');

                Route::delete('cta-blocks/{ctaBlock}', [CtaBlockController::class, 'destroy'])
                    ->whereNumber('ctaBlock')->middleware('can:website_cta_blocks.delete')->name('cta-blocks.destroy');
            });

            // §7.4 FAQs
            Route::middleware('module:faqs')->group(function (): void {
                Route::get('faqs', [FaqController::class, 'index'])
                    ->middleware('can:faqs.view_any')->name('faqs.index');

                Route::post('faqs/reorder', [FaqController::class, 'reorder'])
                    ->middleware('can:faqs.edit')->name('faqs.reorder');

                Route::post('faqs', [FaqController::class, 'store'])
                    ->middleware('can:faqs.create')->name('faqs.store');

                Route::put('faqs/{faq}', [FaqController::class, 'update'])
                    ->whereNumber('faq')->middleware('can:faqs.edit')->name('faqs.update');

                Route::post('faqs/{faq}/toggle', [FaqController::class, 'toggle'])
                    ->whereNumber('faq')->middleware('can:faqs.change_status')->name('faqs.toggle');

                Route::delete('faqs/{faq}', [FaqController::class, 'destroy'])
                    ->whereNumber('faq')->middleware('can:faqs.delete')->name('faqs.destroy');
            });

            // §7.4 FAQ categories
            Route::middleware('module:faq_categories')->group(function (): void {
                Route::get('faq-categories', [FaqCategoryController::class, 'index'])
                    ->middleware('can:faq_categories.view_any')->name('faq-categories.index');

                Route::post('faq-categories/reorder', [FaqCategoryController::class, 'reorder'])
                    ->middleware('can:faq_categories.edit')->name('faq-categories.reorder');

                Route::post('faq-categories', [FaqCategoryController::class, 'store'])
                    ->middleware('can:faq_categories.create')->name('faq-categories.store');

                Route::put('faq-categories/{category}', [FaqCategoryController::class, 'update'])
                    ->whereNumber('category')->middleware('can:faq_categories.edit')->name('faq-categories.update');

                Route::delete('faq-categories/{category}', [FaqCategoryController::class, 'destroy'])
                    ->whereNumber('category')->middleware('can:faq_categories.delete')->name('faq-categories.destroy');
            });

            // §7.5 SEO
            Route::middleware('module:seo')->group(function (): void {
                Route::get('seo', [SeoController::class, 'index'])
                    ->middleware('can:seo.view_any')->name('seo.index');

                Route::get('seo/edit', [SeoController::class, 'edit'])
                    ->middleware('can:seo.view')->name('seo.edit');

                Route::get('seo/export', [SeoController::class, 'export'])
                    ->middleware('can:seo.export')->name('seo.export');

                Route::get('seo/robots/preview', [SeoController::class, 'robotsPreview'])
                    ->middleware('can:seo.view')->name('seo.robots.preview');

                Route::put('seo', [SeoController::class, 'update'])
                    ->middleware('can:seo.edit')->name('seo.update');

                Route::post('seo/bulk-robots', [SeoController::class, 'bulkRobots'])
                    ->middleware('can:seo.edit')->name('seo.bulk-robots');

                Route::post('seo/sitemap/regenerate', [SitemapController::class, 'regenerate'])
                    ->middleware(['can:seo.edit', 'throttle:6,1'])->name('seo.sitemap.regenerate');

                Route::get('seo/sitemap/history', [SitemapController::class, 'history'])
                    ->middleware('can:seo.view')->name('seo.sitemap.history');
            });

            // §7.5 Media library
            Route::middleware('module:website_media')->group(function (): void {
                Route::get('media', [MediaController::class, 'index'])
                    ->middleware('can:website_media.view_any')->name('media.index');

                Route::post('media', [MediaController::class, 'store'])
                    ->middleware(['can:website_media.upload', 'throttle:60,1'])->name('media.store');

                Route::get('media/{asset}', [MediaController::class, 'show'])
                    ->whereNumber('asset')->middleware('can:website_media.view')->name('media.show');

                Route::put('media/{asset}', [MediaController::class, 'update'])
                    ->whereNumber('asset')->middleware('can:website_media.edit')->name('media.update');

                Route::get('media/{asset}/usage', [MediaController::class, 'usage'])
                    ->whereNumber('asset')->middleware('can:website_media.view')->name('media.usage');

                Route::post('media/{asset}/regenerate', [MediaController::class, 'regenerate'])
                    ->whereNumber('asset')->middleware('can:website_media.edit')->name('media.regenerate');

                Route::delete('media/{asset}', [MediaController::class, 'destroy'])
                    ->whereNumber('asset')->middleware('can:website_media.delete')->name('media.destroy');
            });
        });
```

### F.2 `routes/web.php`: replace the whole file

The placeholder closure named `home` is removed. A grep of the whole repository (tests included) found
**zero** references to the route name `home`. `SeoService::HOME_ROUTE` is already `site.home`.

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\PreviewController;
use App\Http\Controllers\Site\RobotsController;
use App\Http\Controllers\Site\SitemapController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public website (phase-03 §7.6)
|--------------------------------------------------------------------------
|
| No public route carries `module:` or `can:` (INV-15): the site is the published output, not a
| module's UI, and disabling website_sections never takes it down.
|
| `public_site` is the single maintenance gate (phase-02 §6, phase-03 §12.2 Q2), on every public GET
| route except robots.txt, which must stay readable while the site is closed (§6.5 [D-W3-13]).
|
| `site.page` (/{slug}) is NOT declared here. It lives in routes/site-pages.php, which
| bootstrap/app.php loads after every panel file. Laravel matches in registration order, and from this
| file the catch-all would answer /admin, /student and /teacher before their own routes were reached.
|
*/

Route::get('robots.txt', RobotsController::class)->name('site.robots');

Route::middleware(['public_site', 'site.preview', 'site.cache'])->group(function (): void {
    Route::get('/', HomeController::class)->name('site.home');
});

Route::middleware(['public_site', 'site.cache'])->group(function (): void {
    Route::get('sitemap.xml', [SitemapController::class, 'index'])->name('site.sitemap');

    Route::get('sitemap-{index}.xml', [SitemapController::class, 'chunk'])
        ->whereNumber('index')
        ->name('site.sitemap.chunk');
});

Route::prefix('preview')
    ->name('site.preview.')
    ->middleware(['public_site', 'site.preview.auth', 'site.preview'])
    ->group(function (): void {
        Route::get('page/{page}', [PreviewController::class, 'page'])->whereNumber('page')->name('page');
        Route::get('section/{section}', [PreviewController::class, 'section'])->whereNumber('section')->name('section');
    });

require __DIR__.'/auth.php';
```

Two deviations from the §7.6 middleware column, both stricter. Each exists because
`MaintenanceModeTest::every_public_page_route_carries_the_public_site_gate` requires it:
- The sitemap routes gain `public_site`. During maintenance, robots.txt already says `Disallow: /` with no Sitemap line.
- The preview routes gain `public_site`. Staff holding `website_sections.view` bypass the gate (E.3). A
  signed guest link gets the 503 while the site is closed. This is M-26.

### F.3 **Delete `public/robots.txt`**

The Laravel skeleton ships `public/robots.txt` (24 bytes: `User-agent: *` / `Disallow:`). Apache's
`.htaccess` (`!-f`) and `php artisan serve` serve an existing static file **before** Laravel. The
`site.robots` route would never be reached in a browser or by a crawler, while FT-47 still passes
in-kernel. Remove it in the same commit as F.2.

### F.4 New file `routes/site-pages.php`

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Site\PageController;
use App\Services\Cms\PageService;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The public page catch-all (phase-03 §7.6 `site.page`). MUST be the last route file loaded.
|--------------------------------------------------------------------------
|
| The negative lookahead makes a reserved first segment not match at all. Without it, GET /register
| reaches PageController (only a 404 if the controller remembers to check) and POST /register answers
| 405 instead of 404, because a GET route exists for the URI. SmokeTest asserts 404 for both. The
| controller still 404s a reserved slug (§7.6), so a regex edit alone can never shadow a later phase's
| /courses.
|
*/

$reserved = implode('|', array_map(
    static fn (string $slug): string => preg_quote($slug, '#'),
    array_values(array_filter(
        PageService::RESERVED_SLUGS,
        static fn (string $slug): bool => preg_match('/^[a-z0-9-]+$/', $slug) === 1,
    )),
));

Route::get('{slug}', PageController::class)
    ->where('slug', '(?!(?:'.$reserved.')$)[a-z0-9](?:[a-z0-9-]*[a-z0-9])?')
    ->middleware(['public_site', 'site.preview', 'site.cache'])
    ->name('site.page');
```

`PageService::RESERVED_SLUGS` must be a `public const array` with the §6.4 list, and
`reservedSlugs()` returns it. Also add `confirm-password`, `account`, `email`, `reset-password` and
`forgot-password`: they are first segments `routes/auth.php` already uses (M-21).

### F.5 `bootstrap/app.php`: load the catch-all last

In `withRouting(... then: function () use ($panels): void { ... })`, after the `foreach`:

```php
            // phase-03 §7.6: /{slug} is registered after every other route file (see routes/site-pages.php).
            $pages = __DIR__.'/../routes/site-pages.php';

            if (realpath($pages) !== false) {
                Route::middleware('web')->group($pages);
            }
```

### F.6 Cross-check (brief item 2)

**Permissions.** 85 routes: 78 admin + 7 public. After step C, every `can:` string above exists in
`PermissionRegistry`. **Before step C, 21 routes use a permission that does not exist** (17 distinct
strings): `website_sections.view_logs`, `pages.view_logs`, and all of `website_cta_blocks.*`,
`faq_categories.*`, `website_media.*`. The middleware would answer 403 to everyone but Super Admin. No
public route carries `can:` or `module:`.

**Controllers.** 0 of the 21 controller classes exist on disk. Every `Controller@method` above is a name
chosen here, because the contract fixes route names and URIs but no controller or method names (M-1).
L.3 fails loudly on any route whose action does not exist.

**Contract gaps found while writing the routes.** No route was invented for any of these. They need decisions:

| # | Gap | Recommendation |
|---|---|---|
| G-1 | §8.9 offers to "create" a menu for an empty `MenuLocation`, but §7.2 has no store route | seed all four locations (I.2). `mobile` stays uncreatable until a route `POST menus -> admin.website.menus.store` (`can:menus.create`) is approved |
| G-2 | §8.11 has an enable toggle on FAQ categories and §4.1 grants `faq_categories.change_status`, but §7.4 has no toggle route | toggle through `PUT faq-categories/{category}` (`is_enabled`) under `faq_categories.edit`, or approve `POST faq-categories/{category}/toggle` |
| G-3 | §8.12 has a robots.txt editor on the SEO screen under `seo.edit`, but the text is a `seo` **settings** key (`settings.edit`) and §7.5 has no route for it | show it read-only on the SEO screen with a link to Settings -> SEO & analytics. Editing a setting under `seo.edit` would widen `seo.edit` into a settings write |
| G-4 | `sections/{placement}` for `placement = page` needs a page id, and `?page=` collides with pagination | use `?page_id=` |

---

## G. Sidebar (brief item 3)

`app/Support/Sidebar.php` (Phase 1 file). Add `use App\Enums\Cms\SectionPlacement;`. **Replace the whole
`website` group** in `adminTree()`. The existing entries point at route names that will never exist
(`admin.website-sections.index`, `admin.menus.index`, `admin.pages.index`, `admin.faqs.index`,
`admin.seo.index`). The Phase 4 entries below are copied unchanged.

```php
            [
                'key' => 'website',
                'label' => 'Website',
                'icon' => 'globe-alt',
                'items' => [
                    // phase-03 §8 (F-6.7): content ABOUT the site lives under /admin/website.
                    [
                        'label' => 'Website Overview',
                        'icon' => 'globe-alt',
                        'route' => 'admin.website.index',
                        'module' => 'website_sections',
                        'permission' => 'website_sections.view_any',
                        // Explicit: the default "admin.website.*" would light this item on every CMS screen.
                        'match' => 'admin.website.index',
                    ],
                    [
                        'label' => 'Sections',
                        'icon' => 'view-columns',
                        'route' => 'admin.website.sections.index',
                        'params' => ['placement' => SectionPlacement::Home->value],
                        'module' => 'website_sections',
                        'permission' => 'website_sections.view_any',
                        'match' => ['admin.website.sections.*', 'admin.website.section-items.*', 'admin.website.statistics.*'],
                    ],
                    [
                        'label' => 'Menus',
                        'icon' => 'bars-3',
                        'route' => 'admin.website.menus.index',
                        'module' => 'menus',
                        'permission' => 'menus.view_any',
                        'match' => ['admin.website.menus.*', 'admin.website.menu-items.*'],
                    ],
                    [
                        'label' => 'Pages',
                        'icon' => 'document',
                        'route' => 'admin.website.pages.index',
                        'module' => 'pages',
                        'permission' => 'pages.view_any',
                    ],
                    [
                        'label' => 'CTA Blocks',
                        'icon' => 'megaphone',
                        'route' => 'admin.website.cta-blocks.index',
                        'module' => 'website_cta_blocks',
                        'permission' => 'website_cta_blocks.view_any',
                    ],
                    [
                        'label' => 'FAQs',
                        'icon' => 'question-mark-circle',
                        'route' => 'admin.website.faqs.index',
                        'module' => 'faqs',
                        'permission' => 'faqs.view_any',
                    ],
                    [
                        'label' => 'FAQ Categories',
                        'icon' => 'rectangle-stack',
                        'route' => 'admin.website.faq-categories.index',
                        'module' => 'faq_categories',
                        'permission' => 'faq_categories.view_any',
                    ],
                    [
                        'label' => 'Media Library',
                        'icon' => 'photo',
                        'route' => 'admin.website.media.index',
                        'module' => 'website_media',
                        'permission' => 'website_media.view_any',
                    ],
                    [
                        'label' => 'SEO',
                        'icon' => 'magnifying-glass',
                        'route' => 'admin.website.seo.index',
                        'module' => 'seo',
                        'permission' => 'seo.view_any',
                    ],

                    // Phase 4: business entities the site renders, at the top level (unchanged).
                    ['label' => 'Services', 'icon' => 'wrench-screwdriver', 'route' => 'admin.services.index', 'module' => 'services', 'permission' => 'services.view_any'],
                    ['label' => 'Portfolio', 'icon' => 'photo', 'route' => 'admin.portfolio.index', 'module' => 'portfolio', 'permission' => 'portfolio.view_any'],
                    ['label' => 'Team', 'icon' => 'user-group', 'route' => 'admin.team.index', 'module' => 'team', 'permission' => 'team.view_any'],
                    ['label' => 'Testimonials', 'icon' => 'chat-bubble-left-right', 'route' => 'admin.testimonials.index', 'module' => 'testimonials', 'permission' => 'testimonials.view_any'],
                    ['label' => 'Student Reviews', 'icon' => 'star', 'route' => 'admin.student-reviews.index', 'module' => 'student_reviews', 'permission' => 'student_reviews.view_any'],
                    ['label' => 'Success Stories', 'icon' => 'sparkles', 'route' => 'admin.success-stories.index', 'module' => 'success_stories', 'permission' => 'success_stories.view_any'],
                    [
                        'label' => 'Blog',
                        'icon' => 'newspaper',
                        'children' => [
                            ['label' => 'Posts', 'icon' => 'newspaper', 'route' => 'admin.blog-posts.index', 'module' => 'blog_posts', 'permission' => 'blog_posts.view_any'],
                            ['label' => 'Categories', 'icon' => 'tag', 'route' => 'admin.blog-categories.index', 'module' => 'blog_categories', 'permission' => 'blog_categories.view_any'],
                        ],
                    ],
                    [
                        'label' => 'Careers',
                        'icon' => 'briefcase',
                        'children' => [
                            ['label' => 'Jobs', 'icon' => 'briefcase', 'route' => 'admin.jobs.index', 'module' => 'jobs', 'permission' => 'jobs.view_any'],
                            ['label' => 'Applications', 'icon' => 'inbox-stack', 'route' => 'admin.job-applications.index', 'module' => 'job_applications', 'permission' => 'job_applications.view_any'],
                        ],
                    ],
                    ['label' => 'Contact Inquiries', 'icon' => 'envelope', 'route' => 'admin.contact-inquiries.index', 'module' => 'contact_inquiries', 'permission' => 'contact_inquiries.view_any'],
                ],
            ],
```

- Every icon above was checked against `x-ui.icon`'s map and exists.
- `params` is required on Sections: `route('admin.website.sections.index')` without a placement throws,
  and `Sidebar::url()` would render a non-link.
- Every `permission` above equals the `can:` of the route it links to.

---

## H. Scheduler (brief item 4, part 2)

`routes/console.php`: append the code, and move the `use` lines up beside the file's existing imports.

```php
use App\Models\Cms\WebsiteSection;
use App\Services\Cms\ContentPublisher;
use App\Services\Cms\MediaService;
use App\Services\Cms\SitemapGenerator;
use Illuminate\Support\Facades\Schedule;

Artisan::command('cms:publish-scheduled', function (ContentPublisher $publisher) {
    $this->info(count($publisher->publishDue()).' scheduled page(s) published.');
})->purpose('Promote scheduled pages whose publish time has come (phase-03 §10.4)');

Artisan::command('cms:sitemap-generate', function (SitemapGenerator $sitemap) {
    $row = $sitemap->regenerate('scheduled');
    $this->info("Sitemap: {$row->url_count} URLs, status {$row->status}.");
})->purpose('Rebuild sitemap.xml (phase-03 §10.4)');

Artisan::command('cms:media-recount', function (MediaService $media) {
    $media->recountUsage();
    $this->info('Media usage counts refreshed.');
})->purpose('Recount media_assets.usage_count from every reference (phase-03 §10.4)');

Artisan::command('cms:verify-published-snapshots', function (ContentPublisher $publisher) {
    $failures = 0;

    WebsiteSection::query()->where('status', \App\Enums\Cms\ContentStatus::Published->value)->where('is_enabled', true)
        ->each(function (WebsiteSection $section) use ($publisher, &$failures): void {
            foreach ($publisher->verify($section) as $problem) {
                $failures++;
                $this->error("Section #{$section->getKey()}: {$problem}");
            }
        });

    return $failures === 0 ? 0 : 1;
})->purpose('Assert every live section has a valid published snapshot (phase-03 §10.4)');

Schedule::command('cms:publish-scheduled')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('cms:sitemap-generate')->dailyAt('02:30');
Schedule::command('cms:media-recount')->dailyAt('04:00');
Schedule::command('cms:verify-published-snapshots')->dailyAt('04:30');

// Add when their classes exist (§10.2 / §10.4). They are not registered now, because a command that
// silently does nothing is worse than no command:
//   cms:warm-cache       dailyAt('03:00')        -> WarmPublicPageCache
//   cms:prune-revisions  weeklyOn(0, '03:30')    -> PruneCmsRevisions
//   cms:check-links      dailyAt('05:00')        -> MenuService + BrokenMenuLinksDetected
```

Differences from the handover: the status comparison uses the enum (CLAUDE.md §1.8), and
`Illuminate\Support\Facades\Artisan` is already imported by the file. The schedule times are UTC (D61):
02:30 UTC is 07:30 in Asia/Karachi. If the contract meant local night-time, convert. Operations: nothing
runs without `php artisan schedule:work` in dev, or a Windows Task Scheduler entry calling
`php artisan schedule:run` every minute.

---

## I. Seeders (brief item 7)

### I.1 `database/seeders/RoleSeeder.php` (§9, §13.1)

In the **SEO Expert** definition, replace the `permissions` merge with:

```php
                'permissions' => $this->merge(
                    $staffBase,
                    PermissionRegistry::permissionNamesFor(['blog_posts', 'blog_categories', 'seo']),
                    PermissionRegistry::permissionNamesFor('website_sections', self::READ_EDIT),
                    // phase-03 §9: pages read/edit and the whole media library. Never *.change_status:
                    // an SEO edit goes live when someone with publish rights publishes it.
                    PermissionRegistry::permissionNamesFor('pages', self::READ_EDIT),
                    PermissionRegistry::permissionNamesFor('website_media'),
                    PermissionRegistry::permissionNamesFor('tasks', self::WORK_ON),
                ),
```

In the **Digital Marketer** definition, replace the `permissions` merge with:

```php
                'permissions' => $this->merge(
                    $staffBase,
                    PermissionRegistry::permissionNamesFor(['leads', 'blog_posts', 'blog_categories', 'course_inquiries']),
                    PermissionRegistry::permissionNamesFor('website_sections', self::READ_EDIT),
                    // phase-03 §9: CTA blocks and FAQs in full; media view + upload only. No pages, no seo.edit, no publish.
                    PermissionRegistry::permissionNamesFor(['website_cta_blocks', 'faqs']),
                    PermissionRegistry::permissionNamesFor('website_media', [Ability::ViewAny, Ability::View, Ability::Upload]),
                ),
```

Admin receives every new permission automatically through `everythingExcept()`, and Super Admin through
`permissionNames()`. Every other role gets no CMS permission (§9).

### I.2 New `database/seeders/WebsiteCmsSeeder.php` (§6.14)

This is a draft. It has never been executed, and it needs the prerequisites of 0.2. Design decisions:
- **Insert-only.** Every lookup deliberately includes trashed rows (plain query builder, no
  `deleted_at` filter), so an edited heading, a removed section or a deleted menu item is never
  re-created. This meets "never overwrites a value an admin has edited" (FT-17, FT-50) more strictly
  than the contract's "fill still-default columns".
- **Sections and pages go through the services**, so hashes, snapshots, revisions and `seo_meta` are
  exactly what `ContentPublisher` writes (INV-4). Simple lookup tables (menus, menu items, CTA, FAQs) use
  the query builder, because their services do not exist yet.
- **No invented facts on a real company's public site.** Statistics use `value_mode = auto` with
  `manual_value = null`, so each renders nothing until live data or an admin's number exists (INV-12).
  About history and statistics are seeded **disabled**. Both deviate from §6.14 (M-14).

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Cms\ButtonStyle;
use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\CtaVariant;
use App\Enums\Cms\FaqSource;
use App\Enums\Cms\MenuItemLinkType;
use App\Enums\Cms\MenuLocation;
use App\Enums\Cms\MenuVisibility;
use App\Enums\Cms\PageLayout;
use App\Enums\Cms\SectionPlacement;
use App\Enums\Cms\StatisticMetric;
use App\Enums\Cms\StatisticValueMode;
use App\Models\Cms\Page;
use App\Models\Cms\WebsiteSection;
use App\Services\Cms\CacheVersion;
use App\Services\Cms\ContentPublisher;
use App\Services\Cms\SectionService;
use App\Services\Cms\SeoService;
use App\Support\RichText;
use Closure;
use Database\Seeders\Concerns\WritesToConsole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * phase-03 §6.14 — the day-one public site. Insert-only and idempotent; see
 * docs-pending/phase-03-integration.md §I.2 for the three deliberate deviations.
 */
class WebsiteCmsSeeder extends Seeder
{
    use WritesToConsole;

    /** @var list<array{0: MenuLocation, 1: string, 2: string}> location, slug, name */
    private const MENUS = [
        [MenuLocation::Header, 'header', 'Main navigation'],
        [MenuLocation::FooterPrimary, 'footer-primary', 'Footer: company'],
        [MenuLocation::FooterSecondary, 'footer-secondary', 'Footer: explore'],
        [MenuLocation::FooterLegal, 'footer-legal', 'Footer: legal'],
    ];

    /** @var list<array{0: string, 1: string, 2: int}> slug, title, sort */
    private const SYSTEM_PAGES = [
        ['privacy-policy', 'Privacy Policy', 10],
        ['terms-of-service', 'Terms of Service', 20],
        ['refund-policy', 'Refund Policy', 30],
        ['course-policy', 'Course Policy', 40],
    ];

    public function run(SectionService $sections, ContentPublisher $publisher, SeoService $seo, CacheVersion $cache): void
    {
        // One cache bump for the whole run, however many publishes happen inside.
        $cache->batch(function () use ($sections, $publisher, $seo): void {
            $menus = $this->menus();
            $pages = $this->systemPages($publisher);
            $this->menuItems($menus, $pages);
            $ctaId = $this->ctaBlock();
            $this->faqs();
            $this->sections($sections, $publisher, $menus, $ctaId);
            $seo->ensure(SeoService::HOME_ROUTE);
        }, 'Website CMS seeded');

        $this->seedInfo('Website CMS: menus, system pages, home sections, CTA and FAQs ensured (insert-only).');
    }

    /** @return array<string, int> location => menu id */
    private function menus(): array
    {
        $ids = [];
        $now = Carbon::now();

        foreach (self::MENUS as [$location, $slug, $name]) {
            // uq_menus_location is a plain unique: a trashed menu still owns its slot.
            $id = DB::table('menus')->where('location', $location->value)->value('id');

            $ids[$location->value] = $id !== null ? (int) $id : (int) DB::table('menus')->insertGetId([
                'name' => $name, 'slug' => $slug, 'location' => $location->value,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return $ids;
    }

    /** @return array<string, int> slug => page id */
    private function systemPages(ContentPublisher $publisher): array
    {
        $ids = [];

        foreach (self::SYSTEM_PAGES as [$slug, $title, $sort]) {
            $existing = DB::table('pages')->where('slug', $slug)->value('id');

            if ($existing !== null) {
                $ids[$slug] = (int) $existing;

                continue;
            }

            $now = Carbon::now();
            $id = (int) DB::table('pages')->insertGetId([
                'title' => $title,
                'slug' => $slug,
                'layout' => PageLayout::Content->value,
                'excerpt' => sprintf('Placeholder %s. Replace before the site goes live.', strtolower($title)),
                'content' => RichText::sanitize(sprintf(
                    '<h2>%1$s</h2><p>This is placeholder text shipped with the website so the link is not dead. It is not a legal document. Replace it with your own %2$s before the site goes live.</p>',
                    e($title),
                    e(strtolower($title)),
                )),
                'show_banner' => true,
                'template' => 'site.pages.legal',
                'status' => ContentStatus::Draft->value,
                'is_system' => true,
                'sort_order' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Hashes, published_content, a `published` revision and the seo_meta row (robots index_follow).
            $publisher->publish(Page::query()->findOrFail($id));
            $ids[$slug] = $id;
        }

        return $ids;
    }

    /**
     * @param  array<string, int>  $menus
     * @param  array<string, int>  $pages
     */
    private function menuItems(array $menus, array $pages): void
    {
        $rows = [
            MenuLocation::Header->value => [
                ['label' => 'Home', 'link_type' => MenuItemLinkType::Route->value, 'route_name' => 'site.home', 'is_enabled' => true],
                ['label' => 'About', 'link_type' => MenuItemLinkType::SectionAnchor->value, 'anchor' => 'about', 'is_enabled' => true],
                // Disabled until the owning phase ships, so the nav is never a dead link (§6.14.3).
                ['label' => 'Courses', 'link_type' => MenuItemLinkType::Url->value, 'url' => '/courses', 'is_enabled' => false],
                ['label' => 'Services', 'link_type' => MenuItemLinkType::Url->value, 'url' => '/services', 'is_enabled' => false],
                ['label' => 'Contact', 'link_type' => MenuItemLinkType::SectionAnchor->value, 'anchor' => 'contact', 'is_enabled' => false],
            ],
            MenuLocation::FooterLegal->value => array_map(
                static fn (array $page): array => ['label' => $page[1], 'link_type' => MenuItemLinkType::Page->value, 'page_id' => $pages[$page[0]], 'is_enabled' => true],
                self::SYSTEM_PAGES,
            ),
        ];

        $now = Carbon::now();

        foreach ($rows as $location => $items) {
            $menuId = $menus[$location];

            // Any item ever created (trashed included) means an admin owns this menu now.
            if (DB::table('menu_items')->where('menu_id', $menuId)->exists()) {
                continue;
            }

            foreach (array_values($items) as $index => $item) {
                DB::table('menu_items')->insert($item + [
                    'menu_id' => $menuId, 'parent_id' => null, 'depth' => 0, 'sort_order' => ($index + 1) * 10,
                    'visibility' => MenuVisibility::All->value, 'open_new_tab' => false, 'rel_nofollow' => false,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    private function ctaBlock(): int
    {
        $id = DB::table('cta_blocks')->where('key', 'primary')->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        $email = trim((string) setting('contact.email', ''));
        $now = Carbon::now();

        return (int) DB::table('cta_blocks')->insertGetId([
            'key' => 'primary',
            'name' => 'Primary call to action',
            'variant' => CtaVariant::Banner->value,
            'heading' => 'Talk to us about your project or your next course',
            'primary_label' => $email !== '' ? 'Email us' : null,
            'primary_url' => $email !== '' ? 'mailto:'.$email : null,
            'primary_style' => ButtonStyle::Primary->value,
            'secondary_style' => ButtonStyle::Outline->value,
            'status' => ContentStatus::Published->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function faqs(): void
    {
        $now = Carbon::now();
        $catalogue = [
            ['general', 'General', 10, [
                ['How do I contact you?', '<p>The phone number, email address and office address are listed at the bottom of every page.</p>', true],
                ['Where are you located?', '<p>Our address is shown in the footer of this website, with a map where one is available.</p>', true],
            ]],
            ['courses', 'Courses', 20, [
                ['Which courses are running?', '<p>Course listings are published on this website as batches open. Until then, contact us for the current schedule.</p>', false],
                ['Do courses have fixed start dates?', '<p>Courses run in batches, each with its own start date. Ask us for the next batch of the course you want.</p>', false],
            ]],
            ['admissions', 'Admissions', 30, [
                ['How do I apply for admission?', '<p>Contact us to start your admission. We will explain the steps and the documents required.</p>', false],
                ['What payment options are there?', '<p>Ask our admissions team about the payment options for your course.</p>', false],
            ]],
        ];

        foreach ($catalogue as [$slug, $name, $sort, $questions]) {
            if (DB::table('faq_categories')->where('slug', $slug)->exists()) {
                continue; // the category and its questions belong to the admin now
            }

            $categoryId = (int) DB::table('faq_categories')->insertGetId([
                'name' => $name, 'slug' => $slug, 'is_enabled' => true, 'sort_order' => $sort,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            foreach ($questions as $index => [$question, $answer, $featured]) {
                DB::table('faqs')->insert([
                    'faq_category_id' => $categoryId, 'question' => $question, 'answer' => RichText::sanitize($answer),
                    'status' => ContentStatus::Published->value, 'is_featured' => $featured,
                    'sort_order' => ($index + 1) * 10, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    /** @param  array<string, int>  $menus */
    private function sections(SectionService $sections, ContentPublisher $publisher, array $menus, int $ctaId): void
    {
        $company = trim((string) setting('company.name', config('app.name')));

        $this->placeOnce($sections, $publisher, 'header', SectionPlacement::GlobalHeader, [
            'menu_ref' => $menus[MenuLocation::Header->value],
        ]);

        $this->placeOnce($sections, $publisher, 'hero', SectionPlacement::Home, [
            'heading' => $company,
            'subtitle' => (string) setting('company.tagline', ''),
            // The registry defaults point at #contact / #courses, which do not exist before Phase 4 / 14.
            'primary_button' => ['label' => 'About us', 'url' => '#about', 'style' => ButtonStyle::Primary->value, 'new_tab' => false],
            'secondary_button' => ['label' => null, 'url' => null, 'style' => ButtonStyle::Outline->value, 'new_tab' => false],
        ], items: function (WebsiteSection $hero) use ($sections): void {
            foreach (StatisticMetric::heroDefaults() as $metric) {
                $sections->upsertItem($hero, 'statistic', [
                    'label' => $metric->defaultLabel(),
                    'value_mode' => StatisticValueMode::Auto->value,
                    'metric' => $metric->value,
                    'manual_value' => null, // I.2: no invented numbers; unresolvable renders nothing (INV-12)
                    'suffix' => $metric->defaultSuffix(),
                    'is_enabled' => true,
                ]);
            }
        });

        $this->placeOnce($sections, $publisher, 'about', SectionPlacement::Home, [
            'company_intro' => '<p>'.e((string) setting('company.short_description', '')).'</p>',
        ], anchor: 'about', items: function (WebsiteSection $about) use ($sections): void {
            foreach (['Software and training under one roof', 'Practical, project-based learning', 'One team from first call to delivery'] as $title) {
                $sections->upsertItem($about, 'why_choose_us', ['title' => $title, 'is_enabled' => true]);
            }

            $year = (int) (setting('company.founded_year') ?: Carbon::now()->year);

            foreach (range(1, 3) as $n) {
                $sections->upsertItem($about, 'history', ['year' => $year, 'title' => "Milestone {$n}: replace with your own", 'is_enabled' => false]);
            }

            foreach ([StatisticMetric::ProjectsCompleted, StatisticMetric::StudentsTrained, StatisticMetric::YearsExperience] as $metric) {
                $sections->upsertItem($about, 'statistic', [
                    'label' => $metric->defaultLabel(), 'value_mode' => StatisticValueMode::Auto->value,
                    'metric' => $metric->value, 'manual_value' => null, 'suffix' => $metric->defaultSuffix(), 'is_enabled' => false,
                ]);
            }
        });

        $this->placeOnce($sections, $publisher, 'faq', SectionPlacement::Home, [
            'source' => FaqSource::Category->value,
            'faq_category_ref' => 'general',
        ]);

        $this->placeOnce($sections, $publisher, 'cta', SectionPlacement::Home, ['cta_ref' => $ctaId]);

        $this->placeOnce($sections, $publisher, 'footer', SectionPlacement::GlobalFooter, [
            'menu_ref' => $menus[MenuLocation::FooterPrimary->value],
        ]);
    }

    /**
     * Place, draft, (anchor), (items), publish — once. Unique and repeatable types alike are keyed by
     * type + placement + no page, trashed rows included, so a section an admin removed stays removed.
     *
     * @param  array<string, mixed>  $content
     * @param  (Closure(WebsiteSection): void)|null  $items
     */
    private function placeOnce(SectionService $sections, ContentPublisher $publisher, string $key, SectionPlacement $placement, array $content, ?string $anchor = null, ?Closure $items = null): void
    {
        if (DB::table('website_sections')->where('section_key', $key)->where('placement', $placement->value)->whereNull('page_id')->exists()) {
            return;
        }

        $section = $sections->place($key, $placement);
        $section = $sections->saveDraft($section, $content);

        if ($anchor !== null) {
            $section = $sections->rename($section, null, $anchor);
        }

        if ($items !== null) {
            $items($section);
        }

        $publisher->publish($section);
    }
}
```

The draft's sort order comes out as 10/20/30/40 for hero/about/faq/cta, because
`SectionService::place()` appends `max + 10`. §6.14 says 10/20/110/120. INV-5 rewrites any placement to
10, 20, 30 on its first reorder anyway, so the gap numbers carry no meaning. Accept the service's
numbering (M-25).

### I.3 `database/seeders/DatabaseSeeder.php`

Append to the `call([...])` list, **last**, so modules, permissions, roles and settings exist first:

```php
            WebsiteCmsSeeder::class,
```

and add to the docblock:
`8. WebsiteCmsSeeder  the day-one public site: menus, system pages, home sections (needs settings)`.

### I.4 Applying to the dev database `my_office` (integrator only)

The real data there is protected by these rules: never `migrate:fresh`/`reset`/`rollback`, and no DELETE.

```bash
cd "/c/xampp/htdocs/my office"
# RoleSeeder uses syncPermissions(): the seeded grant is authoritative, so any grant an admin added
# by hand to one of the 18 system roles is REVOKED. Back up first, then compare after.
/c/xampp/mysql/bin/mysqldump.exe -uroot my_office role_has_permissions model_has_permissions > "$TEMP/role_grants_before_phase3.sql"
php artisan db:seed --class=ModuleSeeder --force
php artisan db:seed --class=PermissionSeeder --force
php artisan db:seed --class=RoleSeeder --force
php artisan db:seed --class=SettingSeeder --force
php artisan db:seed --class=WebsiteCmsSeeder --force
php artisan permission:cache-reset && php artisan optimize:clear
php artisan storage:link
```

---

## J. Existing tests that must change

Each change below follows from the contract. None loosens an assertion.

| File | Change | Why it is not a loosening |
|---|---|---|
| `tests/Feature/Settings/SettingsFormRoundTripTest.php` (method at line 60) | rename `..._thirteen_groups_...` to `..._fourteen_groups_...`; the expected list gains `'website'` between `'mail'` and `'collaborator'` | phase-03 §5.1 declares the 14th group. The test still pins the exact list |
| `tests/Feature/Settings/MaintenanceModeTest.php` (line 143) | add `|| $uri === '/robots.txt'` to the framework-plumbing exemption, with the comment `// phase-03 §6.5 [D-W3-13], FT-47: robots.txt must answer while the site is closed.` | the contract requires robots.txt to be ungated. Every other public GET route must still carry `public_site` |
| any test that disables `website_sections` (FT-41) | must post a `reason` (D63) | stricter server rule |

Tests that must keep passing unchanged, and that the integration above was shaped around:
- `SmokeTest`: `GET /` 200, `GET /login` 200, `GET/POST /register` 404. This depends on F.4's lookahead.
- `AuthenticationTest::a_guest_cannot_reach_the_admin_panel`: `/admin` redirects to login. This depends on F.5's ordering.
- `MaintenanceModeTest` (all methods): depends on E.3, which keeps the Phase 2 heading and message strings.
- `PermissionRegistryTest::contractModuleProvider` checks a subset, so the new modules do not break it.

---

## K. Reconciliation (brief item 8)

### K.1 What could be reconciled

No view agent filed a report, and **no Phase 3 controller or view exists on disk**. Nothing can yet be
matched against controller output. The integrator must re-run L.3 to L.6 once they land. What *can* be
reconciled now is the existing code against itself, the contract and the Phase 1/2 tree. Every mismatch
found is below, with the exact fix.

### K.2 Mismatches found

| # | Mismatch | Evidence | Exact fix |
|---|---|---|---|
| K-1 | Public home route is named `home` in code, `site.home` in the contract and in `SeoService::HOME_ROUTE` | `routes/web.php:188`; `SeoService.php:71` | F.2 (0 references to `home` in the repo) |
| K-2 | Sidebar Website entries use route names the contract never defines | `Sidebar.php:620,627,634,683,737` | G |
| K-3 | Gate alias: the contract says `site`, code and a Phase 2 test say `public_site` | `bootstrap/app.php:70`; `MaintenanceModeTest.php:147` | use `public_site` (F.2, E.3) |
| K-4 | `x-site.stat` renders nothing when `value` is null, and the snapshot stores `value = null` for every `auto` item. Passing a snapshot row straight to the component hides every live statistic **and its manual fallback** | `stat.blade.php:37-40,64`; `SnapshotBuilder.php:245-246` | in `site/sections/hero` and `about`, resolve before rendering: `@php $stat['value'] = $stat['is_live'] ? (app(\App\Services\Cms\StatisticsProvider::class)->resolve(\App\Enums\Cms\StatisticMetric::from($stat['metric'])) ?? $stat['manual_value']) : $stat['value']; @endphp`. Or give `StatisticsProvider` a `valueForSnapshot(array $item): ?string`, because the contract's `valueFor(WebsiteSectionItem)` takes a model and a partial holds arrays (§8.14) |
| K-5 | Components' examples read `$content[...]` / `$media[...]`, but the snapshot key is `fields` | `button.blade.php:16`, `image.blade.php:16`; `SnapshotBuilder.php:36-45` | fix the variable contract in the renderer (K.3) |
| K-6 | `accordion` and `prose` docblocks call `$siteSetting(...)`, a variable nothing defines. The contract helper is `site_setting()` | `accordion.blade.php:11`, `prose.blade.php:11` | replace `$siteSetting(` with `site_setting(` in both comments (component owner). E.1 creates the helper |
| K-7 | Handover exception rendering gives every `ContentActionNotAllowedException` a 403, but FT-02/14/19/20 expect 422 and FT-16/17 expect 403 | handover §5; contract §11 | E.4 plus M-7 (`status()` / `field()` on the exception) |
| K-8 | `SectionRegistry` names the footer icon `bars-3-bottom-left`, which `x-ui.icon` does not have (renders the placeholder square) | `SectionRegistry.php:1442`; `icon.blade.php` map | change the registry icon to `bars-3` or `rectangle-stack` (registry owner), or add the path to `x-ui.icon` (Phase 1 file, contract asks for no change). `resources/data/icons.php` must list only names `x-ui.icon` can render (about 167 map entries today) |
| K-9 | `faq` type default "See all" link is `/faqs`, which is neither a route nor a reserved slug, so it 404s | `SectionRegistry.php:1389` | default `url` to `null` (the button then renders nothing) until a FAQ page exists |
| K-10 | Hero defaults link `#contact` and `#courses`, anchors that do not exist before Phase 4/14 | `SectionRegistry.php:1039,1043` | I.2 overrides them on seed. Consider null defaults |
| K-11 | View namespace: the contract and SectionRegistry's `editView` example say `admin.website.*`, the Phase 3 path list says `resources/views/admin/cms` | `SectionRegistry.php` docblock; brief | pick one before the view agents write (M-2) |
| K-12 | Header and footer snapshots store the menu tree resolved **at publish time**. FT-28 requires a link to a page unpublished *afterwards* to vanish | `SnapshotBuilder.php:405-412`; FT-28 | in `x-site.menu`, drop `link_type = page` nodes whose target slug is no longer published (one cached `pluck('slug')` under `CacheVersion`), or make `PageService::unpublish/delete` republish the header and footer. The first keeps D-W3-8 intact |

### K.3 The variable contract the views and controllers must share

These names are chosen here; the contract fixes only `SitePayload`'s fields. Controllers must pass
exactly these, and L.6 greps for them.

| View | Receives |
|---|---|
| `layouts/site.blade.php` | `$site` (`App\Support\SitePayload`: `header`, `footer`, `sections`, `seo`, `page`, `isPreview`, `bodyClass`), plus `request()->attributes->get('site_state')` for the maintenance ribbon |
| `site/home.blade.php` | `$site` |
| `site/pages/{default,wide,legal}.blade.php` | `$site`, `$page` (array: `title`, `slug`, `banner_heading`, `banner_subheading`, `show_banner`, `banner` media array or null, `body` = sanitised `published_content`) |
| each `site/sections/{key}.blade.php` | `$section` (full snapshot), `$content` = `$section['fields']`, `$items` = `$section['items']`, `$media` = `$section['media']`, `$cta` = `$section['cta']`, `$menus` = `$section['menus']`, `$faqs` = `$section['faqs'] ?? []` |
| each `site/cta/{variant}.blade.php` | `$cta` (snapshot keys: `id key variant heading subheading description primary secondary background background_color`) |
| `site/holding`, `site/maintenance` | `$company`, `$heading`, `$message` (the names `EnsurePublicSiteAvailable` already passes) |
| `site/404` | `$site` with empty `sections` |

The renderer loop, the only place a section partial is chosen (INV-2: an orphaned type is skipped and
logged, never a 500):

```blade
@foreach ($site->sections as $section)
    @continue(! \App\Support\Cms\SectionRegistry::exists($section['section_key']))
    @include(\App\Support\Cms\SectionRegistry::view($section['section_key']), [
        'section' => $section,
        'content' => $section['fields'],
        'items' => $section['items'],
        'media' => $section['media'],
        'cta' => $section['cta'],
        'menus' => $section['menus'],
        'faqs' => $section['faqs'] ?? [],
    ])
@endforeach
```

The warning itself belongs in `PublicPageService::sections()`, which should never hand an orphan to the view.

Every public URL in a view comes from a snapshot (`url` keys) or one of `route('site.home')`,
`route('site.page', ['slug' => ...])`, `route('login')`. Every admin screen links only to route names
defined in F.1. L.5 greps for any other `route('admin.website.` or `route('site.` string.

---

## L. Verification script (brief item 9)

Run by the integrator only: it runs the test suite, which the shared test database allows one runner
at a time. Git Bash, from the project root.

```bash
cd "/c/xampp/htdocs/my office"
set -u

# L.1 Prerequisites: re-run section A. It must print nothing.

# L.2 Syntax and style on everything Phase 3 touched
find app/Enums/Cms app/Support/Cms app/Services/Cms app/Models/Cms app/Policies/Cms \
     app/Http/Controllers/Admin/Cms app/Http/Controllers/Site app/Http/Requests/Cms app/Http/Middleware \
     app/Support/RichText.php app/Support/SiteSettings.php database/seeders routes bootstrap \
     -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors' || true
./vendor/bin/pint --test app routes bootstrap database
php artisan permission:cache-reset && php artisan optimize:clear

# L.3 Routes: every Phase 3 action exists, every can: is registered, admin routes are gated, public are not
php artisan tinker --execute="$(cat <<'PHP'
$perms = \App\Support\PermissionRegistry::permissionNames();
$problems = [];
$count = 0;
foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
    $name = (string) $route->getName();
    $admin = str_starts_with($name, 'admin.website.');
    $public = str_starts_with($name, 'site.');
    if (! $admin && ! $public) { continue; }
    $count++;
    [$class, $method] = array_pad(explode('@', $route->getActionName(), 2), 2, null);
    if ($method === null || ! method_exists($class, $method)) { $problems[] = "$name: missing action ".$route->getActionName(); }
    $mw = collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m));
    foreach ($mw as $m) {
        if (str_starts_with($m, 'can:') && ! in_array(substr($m, 4), $perms, true)) { $problems[] = "$name: unknown permission $m"; }
    }
    if ($admin && ! $mw->contains(fn ($m) => str_starts_with($m, 'can:'))) { $problems[] = "$name: no can:"; }
    if ($admin && ! $mw->contains(fn ($m) => str_starts_with($m, 'module:'))) { $problems[] = "$name: no module:"; }
    if ($public && $mw->contains(fn ($m) => str_starts_with($m, 'can:') || str_starts_with($m, 'module:'))) { $problems[] = "$name: public route carries can:/module: (INV-15)"; }
    if ($public && $name !== 'site.robots' && ! $mw->contains('public_site')) { $problems[] = "$name: missing public_site"; }
}
if ($count !== 85) { $problems[] = "expected 85 Phase 3 routes, found $count"; }
echo $problems === [] ? "L.3 OK ($count routes)\n" : implode("\n", $problems)."\n";
PHP
)"

# L.4 Route ORDER: the catch-all must not shadow panels, auth or health; reserved slugs must 404
php artisan tinker --execute="$(cat <<'PHP'
$routes = app('router')->getRoutes();
$expect = ['/admin' => 'admin.dashboard', '/login' => 'login', '/student' => 'student.dashboard', '/teacher' => 'teacher.dashboard',
           '/client' => 'client.dashboard', '/collaborator' => 'collaborator.dashboard', '/' => 'site.home', '/robots.txt' => 'site.robots',
           '/sitemap.xml' => 'site.sitemap', '/privacy-policy' => 'site.page', '/register' => 404, '/courses' => 404];
foreach ($expect as $uri => $want) {
    try { $got = $routes->match(\Illuminate\Http\Request::create($uri))->getName() ?? '(unnamed)'; }
    catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) { $got = 404; }
    catch (\Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException) { $got = 405; }
    echo ($got === $want ? 'ok   ' : 'FAIL ')."$uri -> $got (want $want)\n";
}
try { $routes->match(\Illuminate\Http\Request::create('/register', 'POST')); echo "FAIL POST /register matched\n"; }
catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) { echo "ok   POST /register -> 404\n"; }
catch (\Throwable $e) { echo 'FAIL POST /register -> '.class_basename($e)."\n"; }
PHP
)"

# L.5 Sidebar: every Website entry resolves to a registered route with the same permission as its route
php artisan tinker --execute="$(cat <<'PHP'
$routes = app('router')->getRoutes();
$bad = [];
$walk = function (array $items) use (&$walk, &$bad, $routes) {
    foreach ($items as $i) {
        if (! empty($i['children'])) { $walk($i['children']); }
        if (! isset($i['route']) || ! str_starts_with($i['route'], 'admin.website.')) { continue; }
        $r = $routes->getByName($i['route']);
        if ($r === null) { $bad[] = "missing route {$i['route']}"; continue; }
        if (! in_array('can:'.$i['permission'], $r->gatherMiddleware(), true)) { $bad[] = "{$i['route']}: sidebar permission {$i['permission']} differs from the route's can:"; }
    }
};
foreach (\App\Support\Sidebar::tree('admin') as $group) { $walk($group['items']); }
echo $bad === [] ? "L.5 OK\n" : implode("\n", $bad)."\n";
PHP
)"
grep -rhoE "route\('(admin\.website|site)\.[a-z.-]+'" resources/views app | sort -u | sed -E "s/route\('//; s/'$//" | while read -r n; do
  php artisan route:list --name="$n" --json 2>/dev/null | grep -q "\"name\":\"$n\"" || echo "UNDEFINED ROUTE NAME $n"
done

# L.6 Views compile; static security scans (FT-37, FT-42, CLAUDE.md §13.4 rules)
php artisan view:cache && php artisan view:clear
grep -rn '{!!' resources/views/site resources/views/components/site | grep -v 'RichText::sanitize' && echo "FT-37 FAIL: unsanitised {!! !!}"
grep -rnP '(?<!site_)\bsetting\(|\bconfig\(' resources/views/site && echo "FT-42 FAIL: setting()/config() in a site view"
grep -rn '<img' resources/views/site && echo "FAIL: bare <img> in site/ (use <x-site.image>)"
grep -rn 'csrf' resources/views/layouts/site.blade.php resources/views/site && echo "CHECK M-6: CSRF token inside a cacheable public page"
test -e public/robots.txt && echo "F.3 FAIL: static public/robots.txt still shadows site.robots"

# L.7 Database guarantees (read-only; FT-50's structural half) against the dev database
/c/xampp/mysql/bin/mysql.exe -uroot my_office -e "
SELECT COUNT(*) AS check_constraints_expect_7 FROM information_schema.CHECK_CONSTRAINTS
 WHERE CONSTRAINT_SCHEMA='my_office' AND CONSTRAINT_NAME IN ('chk_media_size','chk_mi_depth','chk_mi_parent','chk_seo_priority','chk_seo_target','chk_ws_page_placement','chk_wsi_value');
SELECT COUNT(*) AS generated_expect_2 FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA='my_office' AND COLUMN_NAME='has_unpublished_changes' AND EXTRA='STORED GENERATED';
SELECT COUNT(*) AS published_sections_with_hash_drift_expect_0 FROM website_sections
 WHERE status='published' AND deleted_at IS NULL AND (published_content IS NULL OR has_unpublished_changes=1);"

# L.8 HTTP smoke through a real server (start it in another terminal: php artisan serve --port=8010)
B=http://127.0.0.1:8010
for p in / /privacy-policy /sitemap.xml /robots.txt; do printf '%s %s\n' "$(curl -s -o /dev/null -w '%{http_code}' "$B$p")" "$p   (want 200)"; done
printf '%s /admin (want 302)\n' "$(curl -s -o /dev/null -w '%{http_code}' "$B/admin")"
printf '%s /register (want 404)\n' "$(curl -s -o /dev/null -w '%{http_code}' "$B/register")"
printf '%s /no-such-page (want 404)\n' "$(curl -s -o /dev/null -w '%{http_code}' "$B/no-such-page")"
curl -s "$B/robots.txt" | grep -q '^Disallow: /admin' && echo "ok robots.txt is generated" || echo "FAIL robots.txt is not the generated file"
curl -sI "$B/" | grep -i '^set-cookie' && echo "CHECK M-6: first anonymous response sets cookies; confirm they are never stored in the page cache"

# L.9 Acceptance tests (phase-03 §11): every row must exist, then pass
for t in unknown_section_type_cannot_be_placed unique_section_type_cannot_be_placed_twice repeatable_section_type_can_be_placed_many_times \
  orphaned_section_type_does_not_break_the_public_page section_type_not_allowed_in_placement_is_rejected \
  draft_edit_is_invisible_to_the_public_until_published unpublished_and_disabled_sections_are_absent_from_the_public_html \
  has_unpublished_changes_is_derived_not_set publish_snapshot_contains_items_media_and_resolved_references \
  unpublish_keeps_the_snapshot_and_requires_a_reason revert_restores_the_draft_not_the_live_version \
  deleting_a_referenced_entity_cannot_orphan_json publish_refuses_incomplete_content slug_uniqueness_reserved_words_and_trashed_conflicts \
  reorder_writes_contiguous_order_and_rejects_a_stale_set required_section_cannot_be_deleted_only_disabled system_pages_cannot_be_deleted \
  repeater_min_and_max_are_enforced menu_cannot_exceed_two_levels menu_cycle_is_rejected preview_shows_draft_content_to_an_authorised_user \
  preview_is_never_cached_and_never_indexed signed_preview_link_works_and_expires second_anonymous_request_is_served_from_cache \
  publishing_invalidates_every_public_page_at_once cache_is_bypassed_where_it_must_be home_page_query_count_is_bounded \
  menu_item_pointing_at_unpublished_target_is_hidden menu_urls_are_resolved_not_stored live_statistic_falls_back_then_disappears \
  statistic_values_are_decimal_strings_and_format_correctly scheduled_page_publishes_itself upload_validates_by_content_not_extension \
  stored_file_is_safe_and_deterministic derivatives_match_the_profile_and_never_upscale rich_text_is_sanitized_on_write_and_on_render \
  rich_text_profiles_are_a_closed_map no_unescaped_output_in_site_views media_in_use_cannot_be_deleted_and_reupload_deduplicates \
  seo_fallback_chain_per_field noindex_is_the_strictest_wins module_gating_affects_the_admin_cms_only \
  public_views_cannot_read_non_public_settings every_cms_write_is_audited menu_visibility_is_applied_per_request_after_the_cache \
  disabled_statistic_item_is_excluded_from_the_published_snapshot sitemap_contents_are_exactly_right robots_txt_in_each_mode \
  authorization_matrix maintenance_and_public_site_gates install_and_rollback public_site_is_responsive_and_dark_mode_clean; do
  grep -rqE "function (test_)?${t}\(" tests/Feature/Cms || echo "MISSING acceptance test: ${t} (phase-03 §11)"
done

php artisan test tests/Feature/Cms            # all 52 rows (FT-01..FT-51 + FT-36b) green
php artisan test                              # full suite; Phase 2 baseline was 631 tests, none may go red
```

Phase 3 is not done until L.3 to L.8 print no `FAIL`/`MISSING`/`UNDEFINED`, the `CHECK` lines have been
looked at by a human, and both test commands are green.

---

## M. Risks (brief item 10)

Grouped by severity. Each item names the guess or disagreement plainly.

### Blocking or security-relevant

- **M-1 This list is ahead of the code.** 0 controllers, requests, policies, public middleware or views
  exist, and 7 models and 7 services are missing. All 21 controller class names, every method name,
  `site.preview.auth`, `PageService::RESERVED_SLUGS`, `ContentActionNotAllowedException::status()/field()`
  and the view variable names in K.3 are **choices made here**, not facts. If the parallel agents chose
  differently, the lists in F, G and K change; the logic does not.
- **M-2 Namespace disagreement.** The contract (§6, "file paths fixed") says
  `App\Http\Controllers\Admin\Website\`. The orchestrator's Phase 3 path list says `Admin/Cms` (and
  `admin/cms` views), and the handover's `editView` example says `admin.website.*`. F.1 uses `Admin\Cms`.
  Decide before the controller and view agents write.
- **M-3 Catch-all ordering.** From `web.php`, `/{slug}` answers `GET /admin`, `/student`, `/teacher`,
  `/client` and `/collaborator` (all match the slug regex and are registered earlier), and
  `POST /register` becomes 405. F.4 and F.5 fix both; L.4 proves it. Skipping either breaks
  `SmokeTest` and `AuthenticationTest`.
- **M-4 `public/robots.txt` shadows `site.robots`** on Apache and `artisan serve` while tests pass
  in-kernel. Delete it (F.3).
- **M-5 Alias `site` vs `public_site`.** Following the contract's alias literally fails
  `MaintenanceModeTest` and creates a second gate name for one class. F uses `public_site` and exempts
  only `/robots.txt` in that test (J).
- **M-6 Full-page cache leaks or breaks sessions.** The `web` group sets a session cookie and an
  `XSRF-TOKEN` cookie on every response, and the head partial prints a CSRF meta tag. If
  `CachePublicResponse` stores the whole Response, visitor A's `Set-Cookie` (a session id) is replayed to
  visitor B. If it bypasses whenever cookies exist, as §6.7 literally says, it never caches anything.
  Required: cache the body plus `Content-Type`/`ETag` only; never store or replay `Set-Cookie`; ignore
  the session and XSRF cookies in the bypass test; and `layouts/site` must not print a CSRF token into
  cacheable HTML (Phase 4 forms fetch one). FT-24/26 do not catch the replay; add an assertion.
- **M-7 Exception status ambiguity.** `ContentActionNotAllowedException` carries no status. FT-02/14/19/20
  want 422 and FT-16/17 want 403, and a Super Admin bypasses policies, so the exception is the only
  refusal they get. The services owner must add a status (403: `requiredSection`, `systemPage`,
  `ctaInUse`, `notDuplicable`, `archived`; 422: the rest) and an optional field name. E.4 assumes them.
- **M-8 Live statistics invisible** unless partials resolve `value` (K-4). As shipped, every `auto` stat
  and its manual fallback render nothing.
- **M-9 Menu freshness vs FT-28** (K-12). D-W3-8 freezes the tree in the header snapshot, while FT-28
  needs an unpublished page's link gone without republishing the header. One of them must bend; the
  render-time filter is the cheap fix.
- **M-10 Absolute URLs frozen into snapshots.** `SnapshotBuilder` stores
  `route('site.page', ..., absolute)`. Published from the console or seeder, links carry `APP_URL`
  (`http://localhost:8000`). Published under XAMPP's `/my office/public`, they carry that path. Store
  relative paths, as `SeoService` already does with `false`.

### Correctness, likely to bite during integration

- **M-11 Trix vs the `cms` profile.** Trix emits `<div>` blocks and `<h1>`; `cms` strips `<div>` (FT-36b)
  and allows only h2-h4. B.3 reconfigures Trix, but Trix's `p` block mode has known quirks with nested
  blocks. Test paste-from-Word and multi-paragraph edits; if it misbehaves, the fallback is a reviewed
  `div` to `p` rewrite inside `RichText` (security owner).
- **M-12 Permission table vs data.** §4.2's "abilities after this phase" would silently revoke 6 seeded
  permissions. C keeps them; the contract table should be amended, not the data.
- **M-13 RoleSeeder on `my_office` revokes hand-made grants** on the 18 system roles (`syncPermissions`).
  Back up (I.4) and diff before and after.
- **M-14 Seed content honesty (deviation).** §6.14 asks for "conservative manual_value fallbacks" and 3
  history entries. On a real company's public site those are invented facts. I.2 seeds them `null` or
  disabled. The owner decides.
- **M-15 Guessed registry metadata.** Module names and sorts for the three new modules (835, 905, 970),
  setting labels, rules, help text and `max` bounds in D are not in the contract.
- **M-16 Phase 2 in flux.** C, D, E, I and J edit or sit beside files the Phase 2 pass is changing now
  (0.4). Applying early guarantees a merge conflict.
- **M-17 Policy-style module gating hole** for `MenuItem`, `WebsiteSectionItem`, `CtaBlock`,
  `MediaAsset`, `SeoMeta`, `SitemapGeneration` and `CmsRevision` until `moduleSlug()` is added (C.4).
- **M-18 Media never works in dev without two operational steps.** `public/storage` is missing
  (`storage:link`), and `QUEUE_CONNECTION=database` leaves derivatives `pending` until a worker runs
  (`composer dev` runs `queue:listen`; plain `artisan serve` does not). The scheduler likewise needs
  `schedule:work` or Task Scheduler.
- **M-19 No `scopeBindings()`** on `{section}/revisions/{revision}` and `{page}/revisions/{revision}`,
  because the needed relationship on the unwritten `WebsiteSection` model is unconfirmed. The controller
  must call `RevisionRecorder::assertBelongsTo()`, or revision #7 of page A can be reverted through page B.
- **M-20 FT-38 test shape.** A non-JSON DELETE on a used asset gets a redirect with a toast, not a 403
  (E.4). The test must use `deleteJson`, or the policy must deny first. For a Super Admin, it does not.
- **M-21 Reserved slugs incomplete.** §6.4's list omits `confirm-password`, `account`, `email`,
  `forgot-password`, `reset-password` (partially listed), which `routes/auth.php` uses. A page with one of
  these slugs is silently shadowed.
- **M-22 Contract gaps G-1 to G-4** (F.6): no menu store route, no FAQ-category toggle route, robots.txt
  editing permission, and the `?page=` collision.
- **M-23 FT-36b and packages.** The test presumes `mews/purifier` plus `config/purifier.php`.
  `intervention/image` is required by §13.4 and used by nothing (B.1).
- **M-24 Phase 4's 21 `website.*` keys are deferred.** The Settings tab shows 13 of the contract's 34 keys
  until Phase 4.
- **M-25 Seeded sort order** 10/20/30/40 vs §6.14's 10/20/110/120 (I.2).
- **M-26 Two stricter-than-contract gates:** `public_site` on the sitemap and preview routes (F.2). A
  signed guest preview link returns 503 during maintenance.

### Low, recorded so nobody rediscovers them

- **M-27 Cache stamp TTL.** `CacheVersion::STAMP_TTL_SECONDS` is 315,360,000 seconds, and the DB cache
  `expiration` column is a signed INT. A fresh stamp written after **2028-01-21** overflows it (strict
  mode) and `bump()` fails, reported but not thrown. Laravel's own `forever()` on the database store has
  the same limit. Use a shorter TTL, or a `bigInteger` expiration migration, before then.
- **M-28 Seeding through services** writes `activity_log` and `cms_revisions` rows during every test run's
  `DatabaseSeeder`. Any existing test that counts activity rows from zero, rather than "since id", would
  shift. None was found by grep, but this is unverified by a run.
- **M-29 Schedule times are UTC** (D61). 02:30 to 05:00 UTC is 07:30 to 10:00 in Pakistan: working hours.
- **M-30 Untested assumptions carried from the handover.** No Phase 3 query, lock, `afterCommit` path,
  generated-column write or publish has ever run against MariaDB. The optional HTMLPurifier pass is
  untested. `SeoService::for()` derives canonical paths for `Page` and route keys only.
