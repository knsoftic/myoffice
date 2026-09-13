# Phase 3 integration list

Rewritten 2026-09-13 (afternoon) against the code that is now on disk. It supersedes the 09:40 version,
which was written before any Phase 3 controller, request, policy or view existed. **Nothing below has been
applied.** Where this list and `docs/phases/phase-03.md` or `docs-pending/phase-03-handover.md` disagree,
the code was trusted and the disagreement is named in K or M.

**How the blocks were verified (read-only).** Every edit below was applied to a *scratch copy* of its target
file, never to the project. Every patched copy and every new file passes `php -l` and
`./vendor/bin/pint --test`. The scratch copies were then loaded into the booted application (array cache,
array session, a guard that aborts any INSERT/UPDATE/DELETE/DDL):

- A fresh router was built exactly as `bootstrap/app.php` will build it (health, web + auth, the five panels,
  then the catch-all). All **85** Phase 3 routes resolve to an existing `Controller@method`. Every `can:` is
  declared by the patched `PermissionRegistry`. Each admin route has one `module:` whose slug owns its
  `can:`. The first `authorize('x.y')` in every controller action equals the route's `can:`. Every Form
  Request's `permission()` equals it too, and every route parameter name matches its controller argument.
- Route order was matched: `/admin`, `/login`, the panels and `/up` are never shadowed. With a stand-in
  `PageService::RESERVED_SLUGS`, no reserved slug reaches `site.page` and `POST /register` is a 404.
- Every Website sidebar entry states the same permission and module as its route, and builds a URL.
- Every `route('admin.website.*' | 'site.*')` in Phase 3 code and views exists, with the parameters it needs.
- Patched `SettingsRegistry`: the Phase 2 unit-test rules replayed on it pass (definition shape, defaults pass
  their own rules). Every `site_setting()` key a public view reads is public.
- Every policy ability a controller asks for exists on the registered policy.
- The seeder's section and item payloads pass `SectionValidator` in publish mode, reference targets aside.
- 65 Blade views (every view a Phase 3 controller renders, plus the partials they include) were compiled and
  parsed against the controller data. No view requires a variable that its controller does not pass. The
  flagged reads were all guarded (`isset` / `??`).
- The dev database was only SELECTed: CHECK constraints, generated columns, unique guards, role grants.

No test was run, nothing was migrated or seeded, and no project file other than this one was written.

---

## 0. State of the tree (verified 2026-09-13)

### 0.1 Present

| Area | On disk |
|---|---|
| Schema | 10 migrations `2026_09_12_07*`, applied (batch 4). All 14 tables empty. 7 CHECK constraints, 2 STORED generated `has_unpublished_changes`, 10 `uq_*` guards (SELECT-verified) |
| Enums / support | 18 enums in `app/Enums/Cms`; `App\Support\Cms\{SectionRegistry,ImageProfile,ImageDerivative}`; `App\Support\RichText` (engine `dom`, purifier absent) |
| Services | `CacheVersion, CmsAuditor, ContentHasher, ContentPublisher, MediaService (+ GdImageProcessor), RevisionRecorder, SectionService, SectionValidator, SeoService, SitemapGenerator, SnapshotBuilder`, `Data\{SeoPayload,SitemapEntry}`, 6 exceptions |
| Models / policies | 14 models + 3 concerns in `app/Models/Cms`; 12 policies + 1 concern in `app/Policies/Cms` |
| HTTP | 16 controllers `Admin\Cms\*` + 3 concerns; 5 controllers `Site\*` + 2 concerns; 36 Form Requests + 10 concerns + `PageTemplate`; `EnsureSiteModuleEnabled` |
| Views | 43 files `admin/cms/**`; 27 files `site/**` (layout `site.layouts.public`); 15 components `components/site/*` |
| Registries today | `PermissionRegistry`: 79 modules / 788 permissions, no Phase 3 additions. `SettingsRegistry`: 13 groups, no `website` group. `Sidebar`: Website entries point at route names that will never exist (`admin.website-sections.index`, `admin.menus.index`, `admin.pages.index`, `admin.faqs.index`, `admin.seo.index`) |
| Routes today | `routes/web.php` = the Phase 1 placeholder `home` closure; no Phase 3 route registered; `public/robots.txt` (skeleton, 24 bytes) exists; `public/storage` link missing |
| Runtime | `APP_URL=http://localhost:8000`, cache/session/queue = `database`, `app.timezone=UTC`, no `app.schedule_timezone`; GD with JPEG/PNG/WebP/GIF/AVIF; `bcmath`, `exif`, `fileinfo` loaded |

### 0.2 Missing, with who breaks without it

| Missing | Breaks | Gate |
|---|---|---|
| `App\Services\Cms\PageService` (+ `RESERVED_SLUGS`) | `Admin\Cms\PageController`, `Site\PageController` (every `/{slug}` 500s), `ValidatesPage` slug rule | **A.1 hard** |
| `App\Services\Cms\MenuService` | `MenuController`, `MenuItemController` | **A.1 hard** |
| `App\Services\Cms\CtaBlockService` | `CtaBlockController` | **A.1 hard** |
| `App\Services\Cms\FaqService` | `FaqController`, `FaqCategoryController` | **A.1 hard** |
| `App\Services\Cms\StatisticsProvider` | `StatisticController`; `SectionController@edit` for hero/about. `<x-site.stats>` degrades by itself | **A.1 hard** |
| `App\Http\Middleware\CachePublicResponse`, `ResolvePreviewMode` | the `site.cache` / `site.preview` aliases (a route naming an unregistered alias 500s) | **A.2** — or apply F.2-interim |
| `App\Support\SiteSettings`, `NonPublicSettingException`, `site_setting()` | every normal site view calls `site_setting()` unguarded → `/` 500s | written in **E.1/E.2** |
| `resources/data/icons.php` | icon fields fall back to a name-pattern check; the picker falls back to a short list | written in **E.4** |
| `database/seeders/WebsiteCmsSeeder.php` | a blank public site | written in **I.2** |
| `app/Jobs/Cms/*`, events, listeners, notifications, dashboard widgets, `SitemapRegistry`, `PublicPageService`, `PreviewService`, `tests/Feature/Cms/**` | nothing breaks: the services guard with `class_exists()` and the controllers compose pages themselves. FT tests cannot pass until they exist (L.9) | deferred, see M |

No Phase 3 path owner holds `app/Services/Cms/{Page,Menu,CtaBlock,Faq}Service.php`,
`StatisticsProvider.php`, `app/Http/Middleware/*`, `app/Jobs/Cms`, or `tests/Feature/Cms`. Each one must be
**assigned**, or A never passes.

### 0.3 Tests that are red today because Phase 3 files landed before integration

| Test | Why | Turns green at |
|---|---|---|
| `Settings\SettingsTruthTest::every_setting_a_view_reads_is_declared_by_the_registry` | its `setting\(` regex also matches `site_setting('website.*')` in site views | D |
| `Settings\SettingsSplitTruthRegressionTest::no_view_route_or_middleware_reads_a_key_the_registry_does_not_declare` | `website.cache_ttl_minutes` (ComposesSite), `website.preview_ttl_minutes` (Admin PageController), `seo.robots_txt_mode` (SeoController) | D |
| `Views\NoHardcodedFormatsTest::no_view_formats_a_date_or_a_number_by_hand` | 4 hand-rolled formats in Phase 3 views | K-3 |

---

## Application order

Each step references only what an earlier step created or what A proved exists.

| Step | Brief item | What | Needs |
|---|---|---|---|
| **A** | — | Prerequisite gate (script) | — |
| **B** | 5 | Packages, storage link, front-end build | — |
| **C** | 1 | `PermissionRegistry`: modules, abilities, `depends_on` | — |
| **D** | 6 | `SettingsRegistry`: `website` group + 4 `seo` keys | — |
| **E** | 4 (part 1) | `SiteSettings` + helper, policies, bindings, model map, icon allowlist, middleware aliases, the extended `site` gate | C, D |
| **F** | 2 | Routes: admin block, public file, catch-all file and loader, delete `public/robots.txt` | A, C, E |
| **G** | 3 | Sidebar | C, F |
| **H** | 4 (part 2) | Scheduler | — |
| **I** | 7 | Seeders (RoleSeeder, WebsiteCmsSeeder, DatabaseSeeder), then the dev database | C, D, F |
| **J** | — | Existing tests that change (none loosened) | D, F, G |
| **K** | 8 | Reconciliation: exact fixes to Phase 3 files (their owners) | — (must land before L) |
| **L** | 9 | Verification, ending with the contract's acceptance tests | all |
| **M** | 10 | Risks | — |

The scripts in A and L (PHP and SQL) are files. **Write each one with a file-writing tool, then run it from
the project root** (`php <file>`, or `mysql … < <file>`). Do not paste them into a shell heredoc: the agent
shell in this environment strips backslashes from heredocs, which silently breaks class names.

---

## A. Prerequisite gate

### A.1 The services the controllers inject — exact contract

These signatures are read off every call site in the controllers, requests and views on disk. They are not
invented; each call site is listed in K-7. Every refusal must be thrown as
`App\Services\Cms\Exceptions\ContentActionNotAllowedException` (business rule) or
`InvalidSectionContentException` (bad input). The controllers already turn those into a 422 on the named
field, or a 403 on destroy routes. Every write bumps the cache the way the existing services do
(`CacheVersion::bumpAfterCommit()`), and audits through `CmsAuditor`.

| Class | Methods (all public) | Must also |
|---|---|---|
| `PageService` | `public const RESERVED_SLUGS` (list below) · `reservedSlugs(): array` · `create(array $data): Page` · `saveDraft(Page $page, array $data): Page` · `duplicate(Page $page): Page` · `delete(Page $page): void` · `restore(Page $page): Page` | hash drafts with `ContentHasher::pageHash()` (or `has_unpublished_changes` lies, INV-4); validate `template` against `PageTemplate::ALLOWED`; refuse `is_system` delete; on delete disable (not delete) menu items pointing at the page; `$data` carries title, slug, layout, excerpt, content, show_banner, banner_media_id, banner_heading, banner_subheading, template, sort_order — never SEO, never publish columns |
| `MenuService` | `storeItem(Menu $menu, array $data): MenuItem` · `updateItem(MenuItem $item, array $data): MenuItem` · `deleteItem(MenuItem $item): void` · `updateMenu(Menu $menu, array $data): Menu` · `reorder(Menu $menu, array $tree): void` · `resolveUrl(MenuItem $item): ?string` | accept the partial payload `['is_enabled' => bool]` in `updateItem()` (the toggle route); refuse depth 2, cycles, a foreign parent, an unknown route, an unsafe URL (FT-19, FT-20); `reorder()` asserts the exact id set (INV-5); `resolveUrl()` never throws for a vanished route |
| `CtaBlockService` | `save(array $data, ?CtaBlock $block = null): CtaBlock` · `usage(CtaBlock $block): Collection` · `delete(CtaBlock $block): void` | accept the partial payload `['status' => value]` (the toggle route); refuse a key change while in use; refuse delete while used |
| `FaqService` | `save(array $data, ?Faq $faq = null): Faq` · `toggle(Faq $faq, ContentStatus $status): Faq` · `reorder(?FaqCategory $category, array $orderedIds): void` · `delete(Faq $faq): void` · `saveCategory(array $data, ?FaqCategory $category = null): FaqCategory` · `reorderCategories(array $orderedIds): void` · `deleteCategory(FaqCategory $category): void` | sanitise answers with `RichText::sanitize()`; resolve a section's category **by slug** (the registry stores `faq_category_ref` as a slug) |
| `StatisticsProvider` | `all(): array` (metric value ⇒ `?string`) · `valueFor(WebsiteSectionItem $item): ?string` · `resolve(StatisticMetric $metric): ?string` · recommended `valueForSnapshot(array $item): ?string` (`<x-site.stats>` prefers it) | decimal strings only, never floats; null when the module is off or the table is absent (INV-12); memoise 15 minutes under the `CacheVersion` stamp |

`PageService::RESERVED_SLUGS` — the contract's §6.4 list plus five first segments that the contract omits
(`account`, `email`, `confirm-password` are used by `routes/auth.php`; `team` is Phase 4; `verify` is
Phases 19-23):

```php
<?php

// The PageService::RESERVED_SLUGS list proposed in the integration document (prerequisite A.1).
return [
    // phase-03 §6.4, verbatim
    'admin', 'login', 'logout', 'register', 'password', 'forgot-password', 'reset-password', 'verify-email',
    'collaborator', 'student', 'teacher', 'client', 'api', 'storage', 'preview', 'sitemap.xml', 'robots.txt', 'up',
    'courses', 'services', 'portfolio', 'blog', 'careers', 'contact', 'admission', 'certificate',
    // first segments routes/auth.php already uses that §6.4 omits
    'account', 'email', 'confirm-password',
    // first segments later contracts declare that §6.4 omits: phase-04 /team, phase-19-23 /verify
    'team', 'verify',
];
```

### A.2 The two public middleware — behaviour required (their owner writes them)

- **`ResolvePreviewMode`** (alias `site.preview`). The controllers already decide who may see drafts and
  already send `Cache-Control: no-store, private` plus `X-Robots-Tag: noindex, nofollow` (handover P-1/P-2).
  This middleware only marks the request as a preview (`$request->attributes->set('cms_preview', true)`)
  when `?preview=1`, a `signature` parameter, or the `site.preview.*` route is present, so the cache skips
  it. It never renders drafts on its own.
- **`CachePublicResponse`** (alias `site.cache`, §6.7). Key: `CacheVersion::key('page', [scheme, host,
  path, whitelisted query: page, category, ref])`, with `ref` **keyed**. TTL: `website.cache_ttl_minutes`.
  It **bypasses** in these cases: not GET, an authenticated user, a preview, `website.cache_enabled` off, a
  non-200 response, a flashed session, or a query key outside the whitelist. It **stores the body,
  status, `Content-Type`, `X-Robots-Tag` and an `ETag` only — never a `Set-Cookie`.** The session and XSRF
  cookies are added later by the outer `web` middleware. A cached replay therefore passes back through
  them and carries that visitor's own cookies. Do not "bypass when a cookie is set": that would never
  cache anything.

### A.3 Run the gate

It must print `A.1 OK`, and either `A.2 OK` or a decision to use F.2-interim. It prints nothing else.

```php
<?php

// Step A — prerequisite gate. Run from the project root: php <this file>
// Prints nothing but "A.1 OK" / "A.2 OK" when the tree may be integrated.
require getcwd().'/vendor/autoload.php';

$a1 = [
    // A.1 hard prerequisites: controllers constructor-inject these, so a route to them 500s without them.
    'App\Services\Cms\PageService' => ['reservedSlugs', 'create', 'saveDraft', 'duplicate', 'delete', 'restore'],
    'App\Services\Cms\MenuService' => ['storeItem', 'updateItem', 'deleteItem', 'updateMenu', 'reorder', 'resolveUrl'],
    'App\Services\Cms\CtaBlockService' => ['save', 'usage', 'delete'],
    'App\Services\Cms\FaqService' => ['save', 'toggle', 'reorder', 'delete', 'saveCategory', 'reorderCategories', 'deleteCategory'],
    'App\Services\Cms\StatisticsProvider' => ['all', 'valueFor', 'resolve'],
];
$a2 = [
    // A.2 needed for F.2 as written; without them apply F.2-interim.
    'App\Http\Middleware\CachePublicResponse' => ['handle'],
    'App\Http\Middleware\ResolvePreviewMode' => ['handle'],
];
$reserved = [
    'admin', 'login', 'logout', 'register', 'password', 'forgot-password', 'reset-password', 'verify-email',
    'collaborator', 'student', 'teacher', 'client', 'api', 'storage', 'preview', 'sitemap.xml', 'robots.txt', 'up',
    'courses', 'services', 'portfolio', 'blog', 'careers', 'contact', 'admission', 'certificate',
    'account', 'email', 'confirm-password', 'team', 'verify',
];

foreach (['A.1' => $a1, 'A.2' => $a2] as $step => $classes) {
    $missing = [];
    foreach ($classes as $class => $methods) {
        if (! class_exists($class)) {
            $missing[] = "class $class";

            continue;
        }
        foreach ($methods as $method) {
            if (! method_exists($class, $method)) {
                $missing[] = "$class::$method()";
            }
        }
    }
    if ($step === 'A.1' && class_exists('App\Services\Cms\PageService')) {
        $list = defined('App\Services\Cms\PageService::RESERVED_SLUGS') ? constant('App\Services\Cms\PageService::RESERVED_SLUGS') : null;
        if (! is_array($list)) {
            $missing[] = 'App\Services\Cms\PageService::RESERVED_SLUGS (public const array)';
        } elseif (($gap = array_diff($reserved, $list)) !== []) {
            $missing[] = 'PageService::RESERVED_SLUGS lacks: '.implode(', ', $gap);
        }
    }
    echo $missing === [] ? "$step OK".PHP_EOL : "$step MISSING".PHP_EOL.'  '.implode(PHP_EOL.'  ', $missing).PHP_EOL;
}

// Things that exist today; re-checked so a revert elsewhere is caught before integration starts.
$present = array_merge(
    glob(getcwd().'/app/Http/Controllers/Admin/Cms/*Controller.php') ?: [],
    glob(getcwd().'/app/Http/Controllers/Site/*Controller.php') ?: [],
    glob(getcwd().'/app/Policies/Cms/*Policy.php') ?: [],
);
$expected = ['Admin/Cms' => 16, 'Site' => 5, 'Policies/Cms' => 12];
foreach ($expected as $dir => $n) {
    $found = count(array_filter($present, static fn (string $p): bool => str_contains(str_replace('\\', '/', $p), '/'.$dir.'/')));
    if ($found !== $n) {
        echo "A.3 expected $n files in $dir, found $found".PHP_EOL;
    }
}
foreach (['site/home', 'site/holding', 'site/maintenance', 'site/404', 'site/layouts/public', 'admin/cms/overview'] as $view) {
    if (! is_file(getcwd().'/resources/views/'.$view.'.blade.php')) {
        echo "A.3 missing view $view".PHP_EOL;
    }
}
```

**Partial integration is not supported.** `SidebarVisibilityTest::every_rendered_item_points_at_a_url_the_user_can_actually_open`
opens every Website screen as Super Admin and expects 200, so a route block whose service is missing turns
the suite red at once.

---

## B. Packages (brief item 5)

| # | Command | Why | If not done |
|---|---|---|---|
| B.1 | `composer require mews/purifier:^3.4`, then create (or replace, if published) `config/purifier.php` with the file below, and `mkdir -p storage/app/purifier` | contract §13.4; FT-36b greps for exactly two purifier profiles | `RichText` keeps working on its DOM walker (verified: script, `on*`, `javascript:`, iframe host, Blade/PHP stripped). **FT-36b fails as written**; do not delete the assertion |
| B.2 | *(skip)* `intervention/image` | §13.4 lists it; **no Phase 3 code calls it** (`MediaService` uses GD directly) | nothing changes. Record the deviation in `DEVELOPMENT_LOG.md` |
| B.3 | `php artisan storage:link` | media URLs are `…/storage/cms/...` on the `public` disk | every CMS image 404s |
| B.4 | `npm run build` | the Phase 3 views use Tailwind classes that `public/build` does not contain yet | unstyled public site and CMS screens |
| B.5 | *(optional)* `npm i -D trix@^2.1` + `resources/js/cms.js` + `vite.config.js` input | rich-text editing in the CMS. `partials/richtext` switches to Trix only when `resources/js/cms.js` is in the build manifest | editors type HTML into a textarea; the server sanitises either way (INV-13). **Recommended default: skip** until the manual Trix test in L.8 is done, see M-11 |
| B.6 | *(skip)* `sortablejs` | the views implement drag, move-up/down and `aria-live` natively (`partials/scripts`, `cmsSortable`, `cmsMenuTree`) | nothing |

`config/purifier.php` (B.1). It only mirrors `RichText::PROFILES`; `RichText` never reads it:

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

B.5, if taken — `resources/js/cms.js`:

```js
import Trix from 'trix';
import 'trix/dist/trix.css';

// RichText's `cms` profile keeps <p> and h2-h4 only and unwraps <div>/<h1>. Verified: Trix's default
// "<div>Line</div><h1>Title</h1>" is saved as "LineTitle" — structure lost. Make Trix emit what survives.
Trix.config.blockAttributes.default.tagName = 'p';
Trix.config.blockAttributes.heading1.tagName = 'h2';

// No inline attachments: images go through the media library (D24).
document.addEventListener('trix-file-accept', (event) => event.preventDefault());
```

and in `vite.config.js`:
`input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/charts.js', 'resources/js/cms.js'],`

---

## C. PermissionRegistry (brief item 1)

File: `app/Support/PermissionRegistry.php`. The module array shape is
`name, group, icon, is_core, sort, abilities`. `depends_on` is **not typed into the array**:
`withDependencies()` attaches it from the `DEPENDS_ON` constant in the same file, and `ModuleSeeder`
projects it onto `modules.depends_on`.

**Additive only.** The contract's §4.2 table would *drop* `website_sections.upload/download/restore`,
`pages.upload/download` and `seo.import`. Those permissions are seeded and granted today, and a removal
would revoke them from every role on the next `RoleSeeder` run. So the edits below add `LOGS` and remove
nothing. Result (verified): 788 → **812** permissions, 24 added, 0 removed. Module and permission sort
orders stay unique, and the dependency graph is unchanged.

### C.1 `website_sections` — find

```php
                'sort' => 810,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::FILES, self::RESTORE),
            ],
```

replace with

```php
                'sort' => 810,
                // phase-03 §4.2: + LOGS (revision history is read under website_sections.view_logs). FILES and
                // RESTORE are Phase 1 grants that are already seeded; the registry only ever adds (D4).
                'abilities' => self::merge(self::CRUD, self::STATUS, self::FILES, self::RESTORE, self::LOGS),
            ],
```

### C.2 `pages`, and the new `website_cta_blocks` right after it — find

```php
                'sort' => 830,
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::FILES, self::RESTORE),
            ],
```

replace with

```php
                'sort' => 830,
                // phase-03 §4.2: + LOGS. FILES and RESTORE are kept (additive, D4); `restore` backs
                // admin.website.pages.restore.
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::FILES, self::RESTORE, self::LOGS),
            ],
            'website_cta_blocks' => [
                'name' => 'CTA Blocks',
                'group' => ModuleGroup::Website,
                'icon' => 'megaphone',
                'is_core' => false,
                'sort' => 835,
                // phase-03 §4.1. `delete` is refused by CtaBlockPolicy while usage_count > 0.
                'abilities' => self::merge(self::CRUD, self::STATUS, self::LOGS),
            ],
```

### C.3 The new `faq_categories` after `faqs` — find

```php
                'sort' => 900,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::RESTORE),
            ],
```

replace with

```php
                'sort' => 900,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::RESTORE),
            ],
            'faq_categories' => [
                'name' => 'FAQ Categories',
                'group' => ModuleGroup::Website,
                'icon' => 'rectangle-stack',
                'is_core' => false,
                'sort' => 905,
                // phase-03 §4.1: the same split as blog_categories / blog_posts.
                'abilities' => self::merge(self::CRUD, self::STATUS),
            ],
```

### C.4 `seo`, and the new `website_media` right after it — find

```php
                'sort' => 960,
                'abilities' => self::merge(self::READ, self::EDIT_ONLY, self::IMPORT, [Ability::Export]),
            ],
```

replace with

```php
                'sort' => 960,
                // phase-03 §4.2: READ + edit + export + LOGS. No create/delete: a seo_meta row is an attribute
                // of its target. IMPORT is a Phase 1 grant that is already seeded (additive, D4).
                'abilities' => self::merge(self::READ, self::EDIT_ONLY, self::IMPORT, [Ability::Export], self::LOGS),
            ],
            'website_media' => [
                'name' => 'Media Library',
                'group' => ModuleGroup::Website,
                'icon' => 'photo',
                'is_core' => false,
                'sort' => 970,
                // phase-03 §4.1: the shared CMS image library (D24). `edit` = alt text, title and caption only.
                'abilities' => self::merge(self::READ, [Ability::Create, Ability::Edit, Ability::Delete], self::FILES, self::LOGS),
            ],
```

### C.5 `DEPENDS_ON` — find

```php
        // Website — phase-03, phase-04.
        'blog_posts' => ['blog_categories'],
```

replace with

```php
        // Website — phase-03, phase-04. Phase 3 adds no edge: faqs.faq_category_id is nullable (the
        // uncategorised bucket is supported), and a section's menu, CTA block and images are optional
        // references whose published snapshot keeps rendering while that module is off (INV-15).
        'blog_posts' => ['blog_categories'],
```

The resulting definitions, as `PermissionRegistry::modules()` returns them (verified):

| slug | name | icon | core | sort | abilities | depends_on |
|---|---|---|---|---|---|---|
| `website_sections` | Website Sections | view-columns | no | 810 | view_any, view, create, edit, delete, change_status, upload, download, restore, **view_logs** | [] |
| `menus` | Menus | bars-3 | no | 820 | *(unchanged)* view_any, view, create, edit, delete, change_status, restore | [] |
| `pages` | Pages | document | no | 830 | view_any, view, create, edit, delete, export, print, change_status, upload, download, restore, **view_logs** | [] |
| **`website_cta_blocks`** | CTA Blocks | megaphone | no | 835 | view_any, view, create, edit, delete, change_status, view_logs | [] |
| `faqs` | FAQs | question-mark-circle | no | 900 | *(unchanged)* view_any, view, create, edit, delete, change_status, restore | [] |
| **`faq_categories`** | FAQ Categories | rectangle-stack | no | 905 | view_any, view, create, edit, delete, change_status | [] |
| `seo` | SEO | globe-alt | no | 960 | view_any, view, edit, import, export, **view_logs** | [] |
| **`website_media`** | Media Library | photo | no | 970 | view_any, view, create, edit, delete, upload, download, view_logs | [] |

Names and sorts of the three new modules are not in the contract (§4.1 fixes slug, icon, group, core and
abilities only); they are chosen here. `ModuleSeeder` creates them **enabled**.

---

## D. SettingsRegistry (brief item 6)

File: `app/Support/SettingsRegistry.php`. The raw declaration shape is
`label, type, rules, item_rules, default, options, help, placeholder, suffix, encrypted, public, readonly, scale, span, sort`,
which `normalise()` completes. Only the 13 Phase 3 keys of §5.1a are added. Phase 4's 21 keys (§5.1b) go into
the same `websiteFields()` method when Phase 4 ships (M-24). Verified: the defaults pass their own rules.
`robots_txt_mode` and `sitemap_changefreq_default` gain `in:` automatically. `menu_max_depth` is readonly
(D62 handling). The five public keys are exactly the §5.1a "yes" rows.

### D.1 Import — find

```php
use App\Enums\ThemePreference;
```

replace with

```php
use App\Enums\Cms\SitemapChangeFrequency;
use App\Enums\ThemePreference;
```

### D.2 `groups()` — the group sits between `mail` (70) and `collaborator` (80). Find

```php
                'description' => 'Outgoing mail transport. Saved here and used instead of the environment file.',
                'sort' => 70,
            ],
```

replace with

```php
                'description' => 'Outgoing mail transport. Saved here and used instead of the environment file.',
                'sort' => 70,
            ],
            // phase-03 §5.1: declared once here; Phase 4 appends its 21 keys to websiteFields() (§5.1b).
            'website' => [
                'label' => 'Website & Forms',
                'icon' => 'globe-alt',
                'description' => 'Public-site caching, preview links, image processing, revision history and what the public pages show.',
                'sort' => 75,
            ],
```

### D.3 `definitions()` — find

```php
            'mail' => self::mailFields(),
```

replace with

```php
            'mail' => self::mailFields(),
            'website' => self::websiteFields(),
```

### D.4 The four `seo` keys (§5.2) and the new `websiteFields()` method — find the end of `seoFields()`

```php
            'google_site_verification' => [
                'label' => 'Google site verification',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:190'],
                'default' => null,
                'help' => 'The content value of the verification meta tag.',
                'public' => true,
                'span' => 6,
                'sort' => 110,
            ],
        ];
    }
```

replace with

```php
            'google_site_verification' => [
                'label' => 'Google site verification',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:190'],
                'default' => null,
                'help' => 'The content value of the verification meta tag.',
                'public' => true,
                'span' => 6,
                'sort' => 110,
            ],

            // phase-03 §5.2 — read server-side by SeoService, never by a public view (all non-public).
            'robots_txt_mode' => [
                'label' => 'robots.txt',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string'],
                'options' => ['auto' => 'Generated automatically', 'custom' => 'Custom text'],
                'default' => 'auto',
                'help' => 'Whichever is chosen, crawlers are told to stay away while the site is closed or not indexable.',
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
                'default' => SitemapChangeFrequency::Weekly->value,
                'help' => 'Stamped on each new SEO record.',
                'span' => 6,
                'sort' => 140,
            ],
            'sitemap_priority_default' => [
                'label' => 'Default sitemap priority',
                'type' => self::TYPE_DECIMAL,
                // decimal(2,1) in seo_meta.sitemap_priority: one decimal, 0.0 to 1.0, never '1e0'.
                'rules' => ['required', 'numeric', 'decimal:0,1', 'min:0', 'max:1'],
                'scale' => 1,
                'default' => '0.5',
                'help' => 'Stamped on each new SEO record.',
                'span' => 6,
                'sort' => 150,
            ],
        ];
    }

    /**
     * phase-03 §5.1a — the 13 keys Phase 3 declares. Phase 4 appends its 21 keys (§5.1b) to this same
     * method and declares no second `website` group (F-6.3).
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
                'help' => 'Publishing clears the cache immediately, whatever this is set to.',
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
                'help' => '60 to 95. Applies to image sizes generated from now on.',
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
                'help' => 'Fixed at two levels by a database constraint (INV-6). Shown for information; it cannot be raised.',
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
                'label' => 'Allow a hero background video',
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
                'label' => 'Show the light/dark switch on the public site',
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

Applying D turns the two settings tests of 0.3 green, and makes 2 tests fail until J.2/J.3 are applied
(`SettingsRegistryTest` and `SettingsFormRoundTripTest` each pin the list of 13 groups).

---

## E. Wiring (brief item 4, part 1)

### E.1 New file `app/Support/Exceptions/NonPublicSettingException.php`

```php
<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use RuntimeException;

/**
 * A public view asked for a setting whose registry definition is not `public => true` (phase-03 INV-10).
 *
 * The message names the key and nothing else: never its value.
 */
final class NonPublicSettingException extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf('The setting [%s] is not public and cannot be read by the public website.', $key));
    }
}
```

### E.2 New file `app/Support/SiteSettings.php`, then the helper

```php
<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Exceptions\NonPublicSettingException;

/**
 * The only way a public (`site.*`) view or component reads a setting (phase-03 §5.3, INV-10, FT-42).
 *
 *   site_setting('company.name', '');
 *
 * The registry decides, never the `settings.is_public` column: a hand edit to that column cannot open a
 * secret to the website, and a key the registry does not declare is refused as well. The value comes
 * from the request-scoped SettingsRepository payload, so a public page costs no query per key.
 */
final class SiteSettings
{
    public function __construct(
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * The typed value of a public setting, the caller's default, or the registry default.
     *
     * @throws NonPublicSettingException when the key is undeclared or not `public => true`
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $field = SettingsRegistry::field($key);

        if ($field === null || $field['public'] !== true) {
            throw NonPublicSettingException::forKey($key);
        }

        return $this->settings->get($key, $default ?? $field['default']);
    }

    /**
     * Is this key readable by the public website?
     */
    public function isPublic(string $key): bool
    {
        return (SettingsRegistry::field($key)['public'] ?? false) === true;
    }
}
```

`app/Support/helpers.php` — find

```php
use App\Support\Sidebar;
```

replace with

```php
use App\Support\Sidebar;
use App\Support\SiteSettings;
```

then find

```php
if (! function_exists('money')) {
```

replace with

```php
if (! function_exists('site_setting')) {
    /**
     * The ONLY way a public view reads a setting (phase-03 §5.3, INV-10). Throws
     * App\Support\Exceptions\NonPublicSettingException for any key whose registry definition is not
     * `public => true`, so a secret can never be printed into a public page by mistake.
     *
     *   site_setting('company.name', '');
     */
    function site_setting(string $key, mixed $default = null): mixed
    {
        return app(SiteSettings::class)->get($key, $default);
    }
}

if (! function_exists('money')) {
```

### E.3 `app/Providers/AppServiceProvider.php` — policies and bindings

Imports — find

```php
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use App\Policies\ModulePolicy;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use App\Support\ConfigureFromSettings;
use App\Support\Modules;
use App\Support\SettingsRepository;
```

replace with

```php
use App\Models\Cms\CmsRevision;
use App\Models\Cms\CtaBlock;
use App\Models\Cms\Faq;
use App\Models\Cms\FaqCategory;
use App\Models\Cms\MediaAsset;
use App\Models\Cms\Menu;
use App\Models\Cms\MenuItem;
use App\Models\Cms\Page;
use App\Models\Cms\SeoMeta;
use App\Models\Cms\SitemapGeneration;
use App\Models\Cms\WebsiteSection;
use App\Models\Cms\WebsiteSectionItem;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use App\Policies\Cms\CmsRevisionPolicy;
use App\Policies\Cms\CtaBlockPolicy;
use App\Policies\Cms\FaqCategoryPolicy;
use App\Policies\Cms\FaqPolicy;
use App\Policies\Cms\MediaPolicy;
use App\Policies\Cms\MenuItemPolicy;
use App\Policies\Cms\MenuPolicy;
use App\Policies\Cms\PagePolicy;
use App\Policies\Cms\SeoMetaPolicy;
use App\Policies\Cms\SitemapGenerationPolicy;
use App\Policies\Cms\WebsiteSectionItemPolicy;
use App\Policies\Cms\WebsiteSectionPolicy;
use App\Policies\ModulePolicy;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use App\Services\Cms\CacheVersion;
use App\Support\ConfigureFromSettings;
use App\Support\Modules;
use App\Support\SettingsRepository;
use App\Support\SiteSettings;
```

`POLICIES` — find

```php
        Module::class => ModulePolicy::class,
    ];
```

replace with

```php
        Module::class => ModulePolicy::class,

        // phase-03. MediaPolicy does not follow the {Model}Policy naming convention, so explicit
        // registration is required; the rest are listed here for the same one-place reason.
        WebsiteSection::class => WebsiteSectionPolicy::class,
        WebsiteSectionItem::class => WebsiteSectionItemPolicy::class,
        Menu::class => MenuPolicy::class,
        MenuItem::class => MenuItemPolicy::class,
        Page::class => PagePolicy::class,
        CtaBlock::class => CtaBlockPolicy::class,
        Faq::class => FaqPolicy::class,
        FaqCategory::class => FaqCategoryPolicy::class,
        SeoMeta::class => SeoMetaPolicy::class,
        MediaAsset::class => MediaPolicy::class,
        CmsRevision::class => CmsRevisionPolicy::class,
        SitemapGeneration::class => SitemapGenerationPolicy::class,
    ];
```

`register()` — find

```php
        $this->app->singleton(SettingsRepository::class);
```

replace with

```php
        $this->app->singleton(SettingsRepository::class);

        // phase-03 (D22): one cache version stamp and one bump batch per request or queued job.
        $this->app->scoped(CacheVersion::class);

        // phase-03 §5.3: the public views' only settings reader (stateless, so a singleton).
        $this->app->singleton(SiteSettings::class);
```

No listener, event or job is registered. The services bump the cache version themselves after commit
(handover decision 12). Adding §10.1's `BumpPublicCacheVersion` on top would double-bump, and FT-25
asserts exactly +1 per publish. Bind `StatisticsProvider` / `PreviewService` as `scoped` only if their author
gives them per-request state.

### E.4 `app/Support/Modules.php` and `resources/data/icons.php`

`MODEL_MODULES` makes class-string checks (`can('viewAny', CtaBlock::class)`) module-gated. Instances
already answer through `moduleSlug()`. Imports — find

```php
use App\Models\Activity;
use App\Models\LoginHistory;
```

replace with

```php
use App\Models\Activity;
use App\Models\Cms\CtaBlock;
use App\Models\Cms\MediaAsset;
use App\Models\Cms\MenuItem;
use App\Models\Cms\SeoMeta;
use App\Models\Cms\SitemapGeneration;
use App\Models\Cms\WebsiteSectionItem;
use App\Models\LoginHistory;
```

then find

```php
        User::class => 'users',
    ];
```

replace with

```php
        User::class => 'users',

        // phase-03: models whose class name does not pluralise into their module slug. An instance
        // answers through moduleSlug(); a class string (`can('viewAny', CtaBlock::class)`) needs this map.
        MenuItem::class => 'menus',
        WebsiteSectionItem::class => 'website_sections',
        CtaBlock::class => 'website_cta_blocks',
        MediaAsset::class => 'website_media',
        SeoMeta::class => 'seo',
        SitemapGeneration::class => 'seo',
    ];
```

New file `resources/data/icons.php`. It lists 96 of the 110 names `x-ui.icon` can draw, and excludes pure
interface glyphs. Verified: `SectionValidator::icons()` and the icon picker both read this grouped shape.

```php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CMS icon allowlist (phase-03 §6.6)
|--------------------------------------------------------------------------
|
| Every `icon` field of a section, a repeater item, a menu item or a FAQ category validates `in:` this
| list (SectionValidator::icons(), CmsFormRequest::iconRule()), and the admin icon picker offers it,
| grouped as below. Only names `<x-ui.icon>` can draw are listed, so a stored icon never renders the
| placeholder square. Pure interface glyphs (chevrons, close, edit, delete, more) are left out.
|
| A custom icon is an `image` field with ImageProfile::Icon, never raw SVG markup. Adding a name here
| requires its path in resources/views/components/ui/icon.blade.php first.
|
*/

return [
    'Navigation & layout' => ['home', 'squares-2x2', 'bars-3', 'view-columns', 'rectangle-stack', 'table-cells', 'queue-list', 'list-bullet'],
    'People' => ['users', 'user', 'user-circle', 'user-group', 'user-plus', 'identification', 'academic-cap'],
    'Security' => ['shield-check', 'key', 'lock-closed', 'lock-open', 'finger-print', 'puzzle-piece'],
    'Tools' => ['cog-6-tooth', 'adjustments-horizontal', 'wrench-screwdriver', 'server-stack', 'arrow-path'],
    'Work & documents' => [
        'briefcase', 'folder', 'folder-open', 'document', 'document-text', 'document-chart-bar', 'clipboard',
        'clipboard-document-list', 'clipboard-document-check', 'clipboard-document', 'newspaper', 'book-open',
        'paper-clip', 'photo', 'printer', 'qr-code', 'presentation-chart-bar',
    ],
    'Money & growth' => ['banknotes', 'credit-card', 'wallet', 'calculator', 'receipt-percent', 'arrow-trending-up', 'arrow-trending-down', 'chart-bar', 'chart-pie'],
    'Communication' => ['envelope', 'phone', 'whatsapp', 'chat-bubble-left-right', 'chat-bubble-left-ellipsis', 'megaphone', 'bell', 'inbox-stack', 'video-camera', 'share', 'lifebuoy'],
    'Status & recognition' => [
        'check', 'check-circle', 'check-badge', 'x-circle', 'exclamation-triangle', 'exclamation-circle',
        'information-circle', 'question-mark-circle', 'sparkles', 'star', 'trophy', 'flag', 'tag', 'ticket',
    ],
    'Actions' => [
        'eye', 'magnifying-glass', 'arrow-up-tray', 'arrow-down-tray', 'arrow-right-on-rectangle',
        'arrow-left-on-rectangle', 'arrow-top-right-on-square', 'arrow-left', 'arrow-right', 'link',
    ],
    'Time' => ['clock', 'calendar', 'calendar-days'],
    'Theme & devices' => ['sun', 'moon', 'computer-desktop'],
    'Places' => ['building-office', 'building-office-2', 'globe-alt'],
];
```

### E.5 `bootstrap/app.php` — the aliases that exist today

Find

```php
use App\Http\Middleware\EnsurePublicSiteAvailable;
```

replace with

```php
use App\Http\Middleware\EnsurePublicSiteAvailable;
use App\Http\Middleware\EnsureSiteModuleEnabled;
```

then find

```php
            'public_site' => EnsurePublicSiteAvailable::class,
```

replace with

```php
            'public_site' => EnsurePublicSiteAvailable::class,
            // phase-03 §6.10 names the same gate `site`, and phases 14-23 use that name on their public
            // routes. One class, two aliases: MaintenanceModeTest recognises the gate by class, not alias.
            'site' => EnsurePublicSiteAvailable::class,
            // phase-03 INV-15 / D26: a content module gates ITS OWN public routes with a plain 404.
            'site_module' => EnsureSiteModuleEnabled::class,
```

**Why `site` is added rather than reusing `public_site` only.** The contract names the gate `site`, and phases
14-17 and 19-23 put `site` on their public routes. `MaintenanceModeTest` now recognises the gate by the class
its middleware resolves to, not by a literal alias, so one class under two aliases is one gate. `public_site`
stays because the Phase 2 test's stand-in router uses it.

### E.6 `bootstrap/app.php` — only if A.2 printed `A.2 OK`

Find

```php
use App\Http\Middleware\EnsureModuleEnabled;
```

replace with

```php
use App\Http\Middleware\CachePublicResponse;
use App\Http\Middleware\EnsureModuleEnabled;
```

then find

```php
use App\Http\Middleware\EnsureUserIsActive;
```

replace with

```php
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ResolvePreviewMode;
```

then find (the line E.5 added)

```php
            'site_module' => EnsureSiteModuleEnabled::class,
```

replace with

```php
            'site_module' => EnsureSiteModuleEnabled::class,
            // phase-03 §6.7 full-page cache and §6.12 preview flag (CachePublicResponse skips a preview).
            'site.cache' => CachePublicResponse::class,
            'site.preview' => ResolvePreviewMode::class,
```

### E.7 Replace `app/Http/Middleware/EnsurePublicSiteAvailable.php` (Phase 2 file — extend, never a second gate)

Changes against today's class: `public_site_enabled` off renders `site.holding`, not `site.maintenance`.
Both 503s carry `X-Robots-Tag: noindex`. A user holding `website_sections.view` browses the real site with
the `site_state` attribute the layout's amber ribbon reads, and that response is `no-store, private` and
`noindex`. The heading strings, the `company/heading/message` variables, `Retry-After` and the inline
fallback are unchanged, and `MaintenanceModeTest` asserts on them.

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aliases: `site` (phase-03 §6.10) and `public_site` (phase-02 §6 "Maintenance") — one gate, two names.
 *
 *   Route::get('/', …)->middleware('site');
 *
 * Two settings, one gate:
 *
 *   · `maintenance.public_site_enabled` off  — the public website is not published at all
 *                                              (`site.holding`).
 *   · `maintenance.maintenance_mode` on      — the website is published but temporarily closed,
 *                                              showing `maintenance.maintenance_message`
 *                                              (`site.maintenance`).
 *
 * Both answer **503 Service Unavailable** with `Retry-After` and `X-Robots-Tag: noindex`, which is what
 * tells a crawler the absence is temporary; a 200 would invite it to index the holding page in place of
 * the real site.
 *
 * **Staff bypass (phase-03 §6.10).** A signed-in user holding `website_sections.view` sees the real site
 * with an amber ribbon naming the state (request attribute `site_state`, read by `site.layouts.public`).
 * The permission decides, never the login: a signed-in student or client is an ordinary visitor (§9).
 * That response is personal, so it is marked `private, no-store` and `noindex`.
 *
 * The admin panel and the four portals can never be blocked by this — not because the middleware checks
 * the path, but because it is attached only to public routes. A gate that decides "is this request
 * public?" from the URL is one rename away from locking every administrator out of the screen that
 * turns it off again. robots.txt never carries it ([D-W3-13]).
 *
 * `site_module` (D26) is a different gate: it 404s one content area. This one closes the site.
 */
final class EnsurePublicSiteAvailable
{
    /** How long a crawler should wait before asking again (seconds). */
    private const RETRY_AFTER = 3600;

    /** Who may browse a closed site (phase-03 §6.10). */
    private const BYPASS_PERMISSION = 'website_sections.view';

    public function handle(Request $request, Closure $next): Response
    {
        $state = match (true) {
            ! setting('maintenance.public_site_enabled', true) => 'disabled',
            (bool) setting('maintenance.maintenance_mode', false) => 'maintenance',
            default => null,
        };

        if ($state === null) {
            return $next($request);
        }

        if ($request->user()?->can(self::BYPASS_PERMISSION) === true) {
            $request->attributes->set('site_state', $state);

            $response = $next($request);
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

            return $response;
        }

        return $state === 'disabled'
            ? $this->holdingPage(
                'site.holding',
                'This website is currently unavailable.',
                (string) (setting('maintenance.maintenance_message') ?: 'The public website has been switched off. Please try again later.'),
            )
            : $this->holdingPage(
                'site.maintenance',
                'Scheduled maintenance',
                (string) (setting('maintenance.maintenance_message') ?: 'We are performing scheduled maintenance. Please check back shortly.'),
            );
    }

    /**
     * The holding page, as a 503: the state's own view, else `site.maintenance`, else the inline page.
     */
    private function holdingPage(string $view, string $heading, string $message): Response
    {
        $company = (string) (setting('company.name') ?: config('app.name', 'My Office'));

        foreach ([$view, 'site.maintenance'] as $candidate) {
            if (View::exists($candidate)) {
                return response()
                    ->view($candidate, [
                        'company' => $company,
                        'heading' => $heading,
                        'message' => $message,
                    ], 503)
                    ->header('Retry-After', (string) self::RETRY_AFTER)
                    ->header('X-Robots-Tag', 'noindex');
            }
        }

        $name = e($company);
        $title = e($heading);
        $body = e($message);
        $year = date('Y');

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <meta name="robots" content="noindex, nofollow">
                <title>{$title} — {$name}</title>
                <style>
                    :root { --bg:#f1f5f9; --card:#fff; --fg:#0f172a; --muted:#64748b; --border:#e2e8f0; }
                    @media (prefers-color-scheme: dark) {
                        :root { --bg:#020617; --card:#0f172a; --fg:#e2e8f0; --muted:#94a3b8; --border:#1e293b; }
                    }
                    * { box-sizing: border-box; }
                    html, body { height: 100%; }
                    body {
                        margin: 0; display: flex; align-items: center; justify-content: center; padding: 1.5rem;
                        background: var(--bg); color: var(--fg); line-height: 1.6;
                        font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
                    }
                    .card {
                        width: 100%; max-width: 30rem; padding: 2.5rem 2rem; text-align: center;
                        border: 1px solid var(--border); border-radius: 1rem; background: var(--card);
                        box-shadow: 0 12px 32px -20px rgba(15, 23, 42, .35);
                    }
                    h1 { margin: 0 0 .75rem; font-size: 1.375rem; font-weight: 700; letter-spacing: -.015em; }
                    p { margin: 0; color: var(--muted); }
                    footer { margin-top: 2rem; color: var(--muted); font-size: .8125rem; }
                </style>
            </head>
            <body>
                <main class="card">
                    <h1>{$title}</h1>
                    <p>{$body}</p>
                    <footer>&copy; {$year} {$name}</footer>
                </main>
            </body>
            </html>
            HTML;

        return response($html, 503)
            ->header('Retry-After', (string) self::RETRY_AFTER)
            ->header('X-Robots-Tag', 'noindex');
    }
}
```

`withExceptions` needs **no** Phase 3 change. The CMS controllers map the CMS exceptions themselves
(`RespondsForCms::attempt()`), and today's body (`dontFlash`) stays as it is. Handover §5 of the services
block and the old E.4 are obsolete.

---

## F. Routes (brief item 2)

### F.1 `routes/admin.php`

Imports — find

```php
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\DashboardController;
```

replace with

```php
use App\Enums\Cms\SectionPlacement;
use App\Http\Controllers\Admin\ActivityLogController;
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
use App\Http\Controllers\Admin\DashboardController;
```

Then paste the CMS block at the end of the admin group. Find the last route of the file

```php
        Route::get('login-history/export', [LoginHistoryController::class, 'export'])
            ->middleware('can:login_history.export')
            ->name('login-history.export');
```

replace with

```php
        Route::get('login-history/export', [LoginHistoryController::class, 'export'])
            ->middleware('can:login_history.export')
            ->name('login-history.export');

        /*
        |------------------------------------------------------------------
        | Website CMS (phase-03 §7.1-§7.5)
        |------------------------------------------------------------------
        | Every route: auth + active + panel:admin (this group), module:<slug> (its block), and the
        | exact can:<permission> of the contract; each controller repeats that permission and then asks
        | the policy for the record rule. Literal segments are declared before the parameter routes that
        | could swallow them. {placement} is limited to SectionPlacement::values() and enum-bound by the
        | controller type-hint; {section} {item} {revision} {page} {menu} {ctaBlock} {faq} {category}
        | {asset} are implicit model bindings (the parameter names are the controllers' argument names).
        | A revision that belongs to another target is a 404 (ListsRevisions::assertRevisionOf).
        | Throttles carry their own limiter prefix, as the Phase 2 routes above do.
        */
        Route::prefix('website')->name('website.')->group(function (): void {

            // §7.1 Sections
            Route::middleware('module:website_sections')->group(function (): void {
                Route::get('/', [WebsiteOverviewController::class, 'index'])
                    ->middleware('can:website_sections.view_any')->name('index');

                Route::get('statistics', [StatisticController::class, 'index'])
                    ->middleware('can:website_sections.view_any')->name('statistics.index');

                Route::post('cache/flush', [PublicCacheController::class, 'flush'])
                    ->middleware(['can:website_sections.change_status', 'throttle:6,1,cms-cache-flush'])->name('cache.flush');

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
                    ->whereNumber('section')->where('group', '[a-z][a-z0-9_]*')
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

                Route::post('menus/{menu}/items', [MenuItemController::class, 'store'])
                    ->whereNumber('menu')->middleware('can:menus.create')->name('menus.items.store');

                Route::post('menus/{menu}/reorder', [MenuController::class, 'reorder'])
                    ->whereNumber('menu')->middleware('can:menus.edit')->name('menus.reorder');

                Route::get('menus/{menu}/link-check', [MenuController::class, 'linkCheck'])
                    ->whereNumber('menu')->middleware('can:menus.view')->name('menus.link-check');

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
                    ->middleware(['can:seo.edit', 'throttle:6,1,cms-sitemap-regenerate'])->name('seo.sitemap.regenerate');

                Route::get('seo/sitemap/history', [SitemapController::class, 'history'])
                    ->middleware('can:seo.view')->name('seo.sitemap.history');
            });

            // §7.5 Media library
            Route::middleware('module:website_media')->group(function (): void {
                Route::get('media', [MediaController::class, 'index'])
                    ->middleware('can:website_media.view_any')->name('media.index');

                Route::post('media', [MediaController::class, 'store'])
                    ->middleware(['can:website_media.upload', 'throttle:60,1,cms-media-upload'])->name('media.store');

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

### F.2 Replace `routes/web.php`

The placeholder route named `home` goes. Nothing in `app/`, `resources/`, `routes/` or `tests/` references
that name (grep), and `SeoService::HOME_ROUTE` is already `site.home`.

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
| module's UI, and disabling website_sections never takes it down. A later content module gates its
| OWN public routes with `site_module` (D26), never these.
|
| `site` is the one maintenance gate (EnsurePublicSiteAvailable, also aliased `public_site` since
| phase-02). It is on every public GET route except robots.txt, which must stay readable while the
| site is closed ([D-W3-13]).
|
| Preview authorisation (a valid signature, or a session holding pages.view / website_sections.view;
| a bad signature is 403, none is 404) lives in Site\PreviewController, so no `signed` or `auth`
| middleware is attached here.
|
| `site.page` (/{slug}) is NOT declared here: routes/site-pages.php is loaded by bootstrap/app.php
| after every panel file, because routes match in registration order.
|
*/

// The phase-03 §7.6 stacks. Until App\Http\Middleware\CachePublicResponse and
// App\Http\Middleware\ResolvePreviewMode exist, set all three to ['site'] (integration step F.2 interim).
$pageStack = ['site', 'site.preview', 'site.cache'];
$feedStack = ['site', 'site.cache'];
$previewStack = ['site', 'site.preview'];

Route::get('robots.txt', RobotsController::class)->name('site.robots');

Route::get('/', HomeController::class)
    ->middleware($pageStack)
    ->name('site.home');

Route::middleware($feedStack)->group(function (): void {
    Route::get('sitemap.xml', [SitemapController::class, 'index'])->name('site.sitemap');

    Route::get('sitemap-{index}.xml', [SitemapController::class, 'chunk'])
        ->whereNumber('index')
        ->name('site.sitemap.chunk');
});

Route::prefix('preview')
    ->name('site.preview.')
    ->middleware($previewStack)
    ->group(function (): void {
        Route::get('page/{page}', [PreviewController::class, 'page'])->whereNumber('page')->name('page');
        Route::get('section/{section}', [PreviewController::class, 'section'])->whereNumber('section')->name('section');
    });

require __DIR__.'/auth.php';
```

**F.2-interim** (A.2 not passed): change the three stack lines to `$pageStack = ['site'];`,
`$feedStack = ['site'];`, `$previewStack = ['site'];`. Verified: with those lines, L.3-L.5 pass on 85
routes. FT-22, FT-24, FT-25 and FT-26 then fail until the middleware lands.

Two stricter-than-contract gates, both required by `MaintenanceModeTest`'s "every public GET route is
gated" scan: the sitemap routes carry `site` (while closed, robots.txt already says `Disallow: /`), and the
preview routes carry `site`. Staff holding `website_sections.view` pass the gate; a signed guest link gets
the 503 while the site is closed (M-26).

### F.3 Delete `public/robots.txt`

It is the Laravel skeleton file (`User-agent: *` / `Disallow:`). Apache (`.htaccess` `!-f`) and `artisan serve`
serve an existing file before Laravel, so `site.robots` would never be reached outside tests. Delete it in
the same commit as F.2.

### F.4 New file `routes/site-pages.php` (the catch-all, loaded last)

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Site\PageController;
use App\Services\Cms\PageService;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The public page catch-all (phase-03 §7.6 `site.page`) — MUST be the last route file loaded
|--------------------------------------------------------------------------
|
| bootstrap/app.php loads this after routes/web.php (and auth.php) and after the five panel files.
|
| The negative lookahead makes a reserved first segment not match at all. Without it GET /register
| would reach PageController and POST /register would answer 405 instead of 404 (a GET route would
| exist for that URI), which SmokeTest refuses. PageController also 404s a reserved slug, so a later
| phase's /courses can never be shadowed even if this pattern is loosened.
|
| `defined()` autoloads PageService: while that class is absent the list is empty (nothing reserved,
| and every /{slug} request fails in the container) instead of the whole application failing to boot.
| Integration step A refuses to start without it.
|
*/

$reserved = implode('|', array_map(
    static fn (string $slug): string => preg_quote($slug, '#'),
    array_values(array_filter(
        defined(PageService::class.'::RESERVED_SLUGS') ? PageService::RESERVED_SLUGS : [],
        static fn (mixed $slug): bool => is_string($slug) && preg_match('/^[a-z0-9-]+$/', $slug) === 1,
    )),
));

Route::get('{slug}', PageController::class)
    ->where('slug', ($reserved === '' ? '' : '(?!(?:'.$reserved.')$)').'[a-z0-9](?:[a-z0-9-]*[a-z0-9])?')
    // phase-03 §7.6 stack. Until CachePublicResponse and ResolvePreviewMode exist: ['site'] (step F.2 interim).
    ->middleware(['site', 'site.preview', 'site.cache'])
    ->name('site.page');
```

(F.2-interim: its middleware line becomes `->middleware(['site'])`.)

### F.5 `bootstrap/app.php` — load the catch-all after the panels

Find

```php
                if (realpath($file) !== false) {
                    Route::middleware('web')->group($file);
                }
            }
```

replace with

```php
                if (realpath($file) !== false) {
                    Route::middleware('web')->group($file);
                }
            }

            // phase-03 §7.6: the /{slug} catch-all is registered after every other route file.
            $pages = __DIR__.'/../routes/site-pages.php';

            if (realpath($pages) !== false) {
                Route::middleware('web')->group($pages);
            }
```

### F.6 Cross-check results (brief item 2)

- **Permissions.** Every `can:` above is declared after C, and **21 routes would 403 everyone but Super Admin
  without C**: 17 strings, namely `website_sections.view_logs`, `pages.view_logs` and all of
  `website_cta_blocks.*`, `faq_categories.*` and `website_media.*`. Each admin route has exactly one
  `module:` and its `can:` belongs to that module. No public route carries `can:` or `module:`.
- **Controller@method.** 85/85 actions exist, and every route parameter matches its controller argument by
  name (`placement, section, item, revision, group, menu, page, ctaBlock, faq, category, asset, index, slug`).
  The first `authorize('x.y')` of every admin action equals its route's `can:`, and so does every Form
  Request's `permission()`, including the dynamic ones: `TargetsPublishable`,
  `ChangeContentStatusRequest`, `ToggleEnabledRequest` (it tells `section-items/{item}` apart from
  `menu-items/{item}` by model class).
- **Mismatches found: none** between routes, controllers, Form Requests, policies and the sidebar. The
  remaining mismatches are in K.
- **Route names nothing links to** (all legitimate): `site.robots`, `site.sitemap.chunk`,
  `admin.website.sections.available` (JSON endpoint; the add-section modal renders `addable` inline),
  `admin.website.{sections,pages}.revisions.revert` (built from a variable in `partials/revisions-table`),
  `admin.website.media.usage` (the detail drawer shows usage inline).
- **Throttles** carry their own limiter prefix (`cms-cache-flush`, `cms-sitemap-regenerate`,
  `cms-media-upload`), as `routes/admin.php` requires. An unnamed `throttle:x,y` shares one bucket per user.
- **Contract gaps, no route invented.** G-1: no `menus.store`, so the `mobile` slot cannot be created
  (I.2 seeds the other four). G-2: no FAQ-category toggle route; enable/disable goes through `PUT
  faq-categories/{category}` under `faq_categories.edit`. G-3: robots.txt text is a settings key, so its
  editor is `admin.settings.index?group=seo` under `settings.edit`, never `seo.edit`. G-4: the page
  placement uses `?page_id=`, not `?page=` (pagination).

---

## G. Sidebar (brief item 3)

File: `app/Support/Sidebar.php`. Item shape: `label, icon, route, params, module, permission, match,
children`. Import — find

```php
use App\Enums\PanelType;
```

replace with

```php
use App\Enums\Cms\SectionPlacement;
use App\Enums\PanelType;
```

Then replace the **whole** `website` group: the array that starts at `'key' => 'website',` and ends just
before the `[ 'key' => 'workspace',` group. The Phase 4 entries are carried over unchanged. Replacement:

```php
            [
                'key' => 'website',
                'label' => 'Website',
                'icon' => 'globe-alt',
                'items' => [
                    // phase-03 §8 (F-6.7): content ABOUT the site lives under /admin/website. Every
                    // `permission` below is exactly the can: of the route it links to.
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
                        // Required: the route has a {placement} parameter; without it the item renders as a non-link.
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
                    // Two flat items, not a parent with children: a rendered parent has no URL of its
                    // own, which SidebarVisibilityTest::every_rendered_item_points_at_a_url… refuses.
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

                    // Business entities the site renders stay at the top level (Phase 4 onwards, unchanged).
                    [
                        'label' => 'Services',
                        'icon' => 'wrench-screwdriver',
                        'route' => 'admin.services.index',
                        'module' => 'services',
                        'permission' => 'services.view_any',
                    ],
                    [
                        'label' => 'Portfolio',
                        'icon' => 'photo',
                        'route' => 'admin.portfolio.index',
                        'module' => 'portfolio',
                        'permission' => 'portfolio.view_any',
                    ],
                    [
                        'label' => 'Team',
                        'icon' => 'user-group',
                        'route' => 'admin.team.index',
                        'module' => 'team',
                        'permission' => 'team.view_any',
                    ],
                    [
                        'label' => 'Testimonials',
                        'icon' => 'chat-bubble-left-right',
                        'route' => 'admin.testimonials.index',
                        'module' => 'testimonials',
                        'permission' => 'testimonials.view_any',
                    ],
                    [
                        'label' => 'Student Reviews',
                        'icon' => 'star',
                        'route' => 'admin.student-reviews.index',
                        'module' => 'student_reviews',
                        'permission' => 'student_reviews.view_any',
                    ],
                    [
                        'label' => 'Success Stories',
                        'icon' => 'sparkles',
                        'route' => 'admin.success-stories.index',
                        'module' => 'success_stories',
                        'permission' => 'success_stories.view_any',
                    ],
                    [
                        'label' => 'Blog',
                        'icon' => 'newspaper',
                        'children' => [
                            [
                                'label' => 'Posts',
                                'icon' => 'newspaper',
                                'route' => 'admin.blog-posts.index',
                                'module' => 'blog_posts',
                                'permission' => 'blog_posts.view_any',
                            ],
                            [
                                'label' => 'Categories',
                                'icon' => 'tag',
                                'route' => 'admin.blog-categories.index',
                                'module' => 'blog_categories',
                                'permission' => 'blog_categories.view_any',
                            ],
                        ],
                    ],
                    [
                        'label' => 'Careers',
                        'icon' => 'briefcase',
                        'children' => [
                            [
                                'label' => 'Jobs',
                                'icon' => 'briefcase',
                                'route' => 'admin.jobs.index',
                                'module' => 'jobs',
                                'permission' => 'jobs.view_any',
                            ],
                            [
                                'label' => 'Applications',
                                'icon' => 'inbox-stack',
                                'route' => 'admin.job-applications.index',
                                'module' => 'job_applications',
                                'permission' => 'job_applications.view_any',
                            ],
                        ],
                    ],
                    [
                        'label' => 'Contact Inquiries',
                        'icon' => 'envelope',
                        'route' => 'admin.contact-inquiries.index',
                        'module' => 'contact_inquiries',
                        'permission' => 'contact_inquiries.view_any',
                    ],
                ],
            ],
```

Verified: every entry's `permission` and `module` equal its route's `can:` and `module:`, every URL builds
(`Sections` needs its `params`), and every icon is drawable. FAQ Categories is a flat item on purpose (see
the comment in the block).

---

## H. Scheduler (brief item 4, part 2)

### H.1 `routes/console.php` — find

```php
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
```

replace with

```php
use App\Enums\Cms\ContentStatus;
use App\Models\Cms\WebsiteSection;
use App\Services\Cms\ContentPublisher;
use App\Services\Cms\MediaService;
use App\Services\Cms\SitemapGenerator;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Website CMS (phase-03 §10.4)
|--------------------------------------------------------------------------
| Times are in app.schedule_timezone, which falls back to app.timezone = UTC (D61). The contract's
| night-time slots are meant in business hours' terms: see integration step H.2.
*/

Artisan::command('cms:publish-scheduled', function (ContentPublisher $publisher) {
    $this->info(count($publisher->publishDue()).' scheduled page(s) published.');
})->purpose('Promote scheduled pages whose publish time has come (phase-03 §10.4)');

Artisan::command('cms:sitemap-generate', function (SitemapGenerator $sitemap) {
    $generation = $sitemap->regenerate('scheduled');
    $this->info(sprintf('Sitemap: %d URLs, status %s.', (int) $generation->url_count, (string) $generation->status));
})->purpose('Rebuild sitemap.xml (phase-03 §10.4)');

Artisan::command('cms:media-recount', function (MediaService $media) {
    $media->recountUsage();
    $this->info('Media usage counts refreshed.');
})->purpose('Recount media_assets.usage_count from every reference (phase-03 §10.4)');

Artisan::command('cms:verify-published-snapshots', function (ContentPublisher $publisher) {
    $failures = 0;

    WebsiteSection::query()
        ->where('status', ContentStatus::Published->value)
        ->where('is_enabled', true)
        ->each(function (WebsiteSection $section) use ($publisher, &$failures): void {
            foreach ($publisher->verify($section) as $problem) {
                $failures++;
                $this->error(sprintf('Section #%d: %s', (int) $section->getKey(), $problem));
            }
        });

    if ($failures === 0) {
        $this->info('Every live section has a valid published snapshot.');
    }

    return $failures === 0 ? 0 : 1;
})->purpose('Assert every live section has a valid published snapshot (phase-03 §10.4)');

Schedule::command('cms:publish-scheduled')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('cms:sitemap-generate')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('cms:media-recount')->dailyAt('04:00')->withoutOverlapping();
Schedule::command('cms:verify-published-snapshots')->dailyAt('04:30');
```

Every method called exists (reflection): `ContentPublisher::publishDue()/verify()`,
`SitemapGenerator::regenerate(string, ?User)`, `MediaService::recountUsage()`. **Not registered**, because a
command that silently does nothing is worse than none: `cms:warm-cache` (needs `WarmPublicPageCache`),
`cms:prune-revisions` (needs `PruneCmsRevisions`, which must delete through the query builder because an
Eloquent delete on `CmsRevision` throws), `cms:check-links` (needs `BrokenMenuLinksDetected`). Add them
with the contract's times (03:00, weekly Sunday 03:30, 05:00) once those classes exist.

### H.2 Timezone (operations decision, optional)

The times run in `app.schedule_timezone`. That key does not exist, so they run in UTC (D61): 02:30 UTC is
07:30 in Pakistan, inside working hours. To run them at night locally, add
`'schedule_timezone' => env('SCHEDULE_TIMEZONE', 'UTC'),` to `config/app.php` under `'timezone'`, and set
`SCHEDULE_TIMEZONE=Asia/Karachi` in `.env`. Storage stays UTC. Nothing runs at all without
`php artisan schedule:work` (dev) or a Task Scheduler entry calling `php artisan schedule:run` every minute.

---

## I. Seeders (brief item 7)

### I.1 `database/seeders/RoleSeeder.php` (§9, §13.1)

SEO Expert — find

```php
                'permissions' => $this->merge(
                    $staffBase,
                    PermissionRegistry::permissionNamesFor(['blog_posts', 'blog_categories', 'seo']),
                    PermissionRegistry::permissionNamesFor('website_sections', self::READ_EDIT),
                    PermissionRegistry::permissionNamesFor('tasks', self::WORK_ON),
                ),
```

replace with

```php
                'permissions' => $this->merge(
                    $staffBase,
                    PermissionRegistry::permissionNamesFor(['blog_posts', 'blog_categories', 'seo']),
                    PermissionRegistry::permissionNamesFor('website_sections', self::READ_EDIT),
                    // phase-03 §9: page copy and the whole media library. Never *.change_status: an SEO
                    // edit goes live when someone with publish rights publishes it.
                    PermissionRegistry::permissionNamesFor('pages', self::READ_EDIT),
                    PermissionRegistry::permissionNamesFor('website_media'),
                    PermissionRegistry::permissionNamesFor('tasks', self::WORK_ON),
                ),
```

Digital Marketer — find

```php
                'permissions' => $this->merge(
                    $staffBase,
                    PermissionRegistry::permissionNamesFor(['leads', 'blog_posts', 'blog_categories', 'course_inquiries']),
                    PermissionRegistry::permissionNamesFor('website_sections', self::READ_EDIT),
                ),
```

replace with

```php
                'permissions' => $this->merge(
                    $staffBase,
                    PermissionRegistry::permissionNamesFor(['leads', 'blog_posts', 'blog_categories', 'course_inquiries']),
                    PermissionRegistry::permissionNamesFor('website_sections', self::READ_EDIT),
                    // phase-03 §9: CTA blocks and FAQs in full; media view + upload only. No pages, no
                    // seo.edit, no publish.
                    PermissionRegistry::permissionNamesFor(['website_cta_blocks', 'faqs']),
                    PermissionRegistry::permissionNamesFor('website_media', [Ability::ViewAny, Ability::View, Ability::Upload]),
                ),
```

Verified against the patched registry: SEO Expert gains `pages.view_any/view/edit` and all of
`website_media.*` (and `seo.view_logs` through `seo`), with no `*.change_status`. Digital Marketer gains
`website_cta_blocks.*`, `faqs.*` and `website_media.view_any/view/upload`, with no `pages.*`, no `seo.edit` and
no publish. Admin receives every new permission through `everythingExcept()`, and Super Admin through
`permissionNames()`. Every other role gets nothing (§9).

### I.2 New file `database/seeders/WebsiteCmsSeeder.php` (§6.14)

Insert-only and idempotent. Trashed rows count as "exists", so nothing an admin removed or edited is
re-created. Sections and system pages go through `SectionService` / `ContentPublisher`; lookup rows use
the query builder. Deliberate deviations from §6.14, each visible in the code:

1. The six hero statistics are `auto` with `manual_value = null`, so each renders nothing until live data or
   an admin's number exists. §6.14.5 asks for a "conservative manual fallback", which on a real company's
   site is an invented number (INV-12).
2. The three about history entries and three about statistics are seeded **disabled**.
3. The header's Contact and CTA buttons, the hero's secondary button and the FAQ "See all" link are
   switched off. Their registry defaults point at `#contact`, `#admission`, `#courses` and `/faqs`, which
   nothing serves before Phases 4/14 (K-5).
4. The home sort orders come out 10/20/30/40 (`place()` appends max + 10), not §6.14's 10/20/110/120.
   INV-5's contiguous reorder rewrites them anyway.

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
 * phase-03 §6.14 — the day-one public site: four menus, the four system pages, the header, hero, about,
 * FAQ, CTA and footer sections, one CTA block, three FAQ categories with six questions, and the home
 * page's SEO record.
 *
 * **Insert-only and idempotent.** Every existence check reads trashed rows too (plain query builder), so
 * an edited heading, a removed section or a deleted menu item is never re-created or overwritten
 * (FT-17, FT-50). Sections and pages are written through SectionService / ContentPublisher, so hashes,
 * snapshots, revisions and seo_meta are exactly what the admin would produce (INV-4).
 *
 * **No invented facts on a real company's website.** Statistics are `auto` with no manual fallback, so
 * each renders nothing until live data or an administrator's number exists (INV-12); the history and
 * about-statistics entries are seeded disabled; buttons that would point at sections later phases own
 * (#contact, #courses) are switched off. See docs-pending/phase-03-integration.md §I.2.
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
        // One cache bump for the whole run, however many publishes happen inside it.
        $cache->batch(function () use ($sections, $publisher, $seo): void {
            $menus = $this->menus();
            $pages = $this->systemPages($publisher);
            $this->menuItems($menus, $pages);
            $ctaId = $this->ctaBlock();
            $this->faqs();
            $this->sections($sections, $publisher, $menus, $ctaId);
            $seo->ensure(SeoService::HOME_ROUTE);
        }, 'Website CMS seeded');

        $this->seedInfo('Website CMS: menus, system pages, home sections, CTA block and FAQs ensured (insert-only).');
    }

    /** @return array<string, int> location => menu id */
    private function menus(): array
    {
        $ids = [];
        $now = Carbon::now();

        foreach (self::MENUS as [$location, $slug, $name]) {
            // uq_menus_location is a plain unique index: a trashed menu still owns its slot.
            $id = DB::table('menus')->where('location', $location->value)->value('id');

            $ids[$location->value] = $id !== null ? (int) $id : (int) DB::table('menus')->insertGetId([
                'name' => $name,
                'slug' => $slug,
                'location' => $location->value,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
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
                'excerpt' => sprintf('Placeholder %s. Replace it before the website goes live.', mb_strtolower($title)),
                'content' => RichText::sanitize(sprintf(
                    '<h2>%1$s</h2><p>This is placeholder text shipped with the website so that the link is not dead. It is not a legal document. Replace it with your own %2$s before the website goes live.</p>',
                    e($title),
                    e(mb_strtolower($title)),
                )),
                'show_banner' => true,
                'template' => 'site.pages.legal',
                'status' => ContentStatus::Draft->value,
                'is_system' => true,
                'sort_order' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Hashes, published_content, a `published` revision and the seo_meta row (index_follow).
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
                // Disabled until the phase that owns the target ships, so the navigation is never a dead link (§6.14.3).
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

            // Any item ever created in this menu (trashed included) means an administrator owns it now.
            if (DB::table('menu_items')->where('menu_id', $menuId)->exists()) {
                continue;
            }

            foreach (array_values($items) as $index => $item) {
                DB::table('menu_items')->insert($item + [
                    'menu_id' => $menuId,
                    'parent_id' => null,
                    'depth' => 0,
                    'sort_order' => ($index + 1) * 10,
                    'visibility' => MenuVisibility::All->value,
                    'open_new_tab' => false,
                    'rel_nofollow' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
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
                ['How do I contact you?', '<p>Our phone number, email address and office address are listed at the bottom of every page.</p>', true],
                ['Where are you located?', '<p>Our address is shown in the footer of this website.</p>', true],
            ]],
            ['courses', 'Courses', 20, [
                ['Which courses are running?', '<p>Courses are published on this website as batches open. Until then, contact us for the current schedule.</p>', false],
                ['Do courses have fixed start dates?', '<p>Courses run in batches, each with its own start date. Ask us for the next batch of the course you want.</p>', false],
            ]],
            ['admissions', 'Admissions', 30, [
                ['How do I apply for admission?', '<p>Contact us to start your admission. We will explain the steps and the documents required.</p>', false],
                ['What payment options are there?', '<p>Ask our admissions team about the payment options for your course.</p>', false],
            ]],
        ];

        foreach ($catalogue as [$slug, $name, $sort, $questions]) {
            if (DB::table('faq_categories')->where('slug', $slug)->exists()) {
                continue; // the category and its questions belong to the administrator now
            }

            $categoryId = (int) DB::table('faq_categories')->insertGetId([
                'name' => $name,
                'slug' => $slug,
                'is_enabled' => true,
                'sort_order' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($questions as $index => [$question, $answer, $featured]) {
                DB::table('faqs')->insert([
                    'faq_category_id' => $categoryId,
                    'question' => $question,
                    'answer' => RichText::sanitize($answer),
                    'status' => ContentStatus::Published->value,
                    'is_featured' => $featured,
                    'sort_order' => ($index + 1) * 10,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /** @param  array<string, int>  $menus */
    private function sections(SectionService $sections, ContentPublisher $publisher, array $menus, int $ctaId): void
    {
        $company = trim((string) setting('company.name', config('app.name'))) ?: 'Welcome';
        $tagline = trim((string) setting('company.tagline', ''));
        $about = trim((string) setting('company.short_description', ''));

        $this->placeOnce($sections, $publisher, 'header', SectionPlacement::GlobalHeader, [
            'menu_ref' => $menus[MenuLocation::Header->value],
            // Both default to #contact, which no section provides before Phase 4.
            'contact_button_enabled' => false,
            'cta_button_enabled' => false,
        ]);

        $this->placeOnce($sections, $publisher, 'hero', SectionPlacement::Home, [
            'heading' => $company,
            'subtitle' => $tagline !== '' ? $tagline : null,
            // The registry defaults point at #contact and #courses, which do not exist before Phase 4 / 14.
            'primary_button' => ['label' => 'About us', 'url' => '#about', 'style' => ButtonStyle::Primary->value, 'new_tab' => false],
            'secondary_button' => ['label' => null, 'url' => null, 'style' => ButtonStyle::Outline->value, 'new_tab' => false],
        ], items: function (WebsiteSection $hero) use ($sections): void {
            foreach (StatisticMetric::heroDefaults() as $metric) {
                $sections->upsertItem($hero, 'statistic', [
                    'label' => $metric->defaultLabel(),
                    'value_mode' => StatisticValueMode::Auto->value,
                    'metric' => $metric->value,
                    'manual_value' => null, // no invented number: unresolvable renders nothing (INV-12)
                    'suffix' => $metric->defaultSuffix(),
                    'is_enabled' => true,
                ]);
            }
        });

        $this->placeOnce($sections, $publisher, 'about', SectionPlacement::Home, [
            'company_intro' => $about !== '' ? '<p>'.e($about).'</p>' : null,
        ], anchor: 'about', items: function (WebsiteSection $section) use ($sections): void {
            foreach (['Software development and IT training under one roof', 'Practical, project-based learning', 'One team from the first call to delivery'] as $title) {
                $sections->upsertItem($section, 'why_choose_us', ['title' => $title, 'is_enabled' => true]);
            }

            $founded = setting('company.founded_year');
            $year = is_numeric($founded) && (int) $founded >= 1900 && (int) $founded <= (int) Carbon::now()->year
                ? (int) $founded
                : (int) Carbon::now()->year;

            foreach (range(1, 3) as $n) {
                $sections->upsertItem($section, 'history', ['year' => $year, 'title' => "Milestone {$n}: replace with your own", 'is_enabled' => false]);
            }

            foreach ([StatisticMetric::ProjectsCompleted, StatisticMetric::StudentsTrained, StatisticMetric::YearsExperience] as $metric) {
                $sections->upsertItem($section, 'statistic', [
                    'label' => $metric->defaultLabel(),
                    'value_mode' => StatisticValueMode::Auto->value,
                    'metric' => $metric->value,
                    'manual_value' => null,
                    'suffix' => $metric->defaultSuffix(),
                    'is_enabled' => false,
                ]);
            }
        });

        $this->placeOnce($sections, $publisher, 'faq', SectionPlacement::Home, [
            'source' => FaqSource::Category->value,
            'faq_category_ref' => 'general',
            // The default "See all" link points at /faqs, which no route serves.
            'show_all_link' => ['label' => null, 'url' => null, 'style' => ButtonStyle::Link->value, 'new_tab' => false],
        ]);

        $this->placeOnce($sections, $publisher, 'cta', SectionPlacement::Home, ['cta_ref' => $ctaId]);

        $this->placeOnce($sections, $publisher, 'footer', SectionPlacement::GlobalFooter, [
            'menu_ref' => $menus[MenuLocation::FooterPrimary->value],
        ]);
    }

    /**
     * Place, draft, (anchor), (items), publish — once. A type is keyed by section_key + placement + no page,
     * trashed rows included, so a section an administrator removed stays removed.
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

Run order matters: **after F**. `SnapshotBuilder` resolves menu links through `route('site.page')` at publish
time, and `SeoService::ensure('site.home')` needs the route. Seeded before F, the footer's legal links are
frozen into the snapshot without URLs.

### I.3 `database/seeders/DatabaseSeeder.php`

Docblock — find

```php
 *   7. DemoUserSeeder    one demo account per remaining role (needs the roles)
```

replace with

```php
 *   7. DemoUserSeeder    one demo account per remaining role (needs the roles)
 *   8. WebsiteCmsSeeder  the day-one public site: menus, system pages, home sections (needs settings)
```

`call([...])` — find

```php
            DemoUserSeeder::class,
        ]);
```

replace with

```php
            DemoUserSeeder::class,
            WebsiteCmsSeeder::class,
        ]);
```

### I.4 Applying to the dev database `my_office` (integrator only)

Forward only: never `migrate:fresh/reset/rollback`, never a DELETE. Re-checked today (read-only): re-running
`RoleSeeder` would revoke **0** grants on all 18 roles. There are no custom roles and no direct user
permissions. Re-check immediately before running, because that can change.

```bash
cd "/c/xampp/htdocs/my office"
/c/xampp/mysql/bin/mysqldump.exe -uroot my_office modules permissions role_has_permissions model_has_permissions settings > "$TEMP/my_office_before_phase3.sql"
php artisan db:seed --class=ModuleSeeder --force        # +3 modules, enabled
php artisan db:seed --class=PermissionSeeder --force    # +24 permissions
php artisan db:seed --class=RoleSeeder --force          # Super Admin 788->812, Admin 778->802, SEO Expert 39->51, Digital Marketer 50->67, others unchanged
php artisan db:seed --class=SettingSeeder --force       # +17 settings rows (13 website, 4 seo)
php artisan permission:cache-reset && php artisan optimize:clear
php artisan db:seed --class=WebsiteCmsSeeder --force    # only after F; see I.2
php artisan cms:verify-published-snapshots              # must end "Every live section has a valid published snapshot."
```

`APP_URL` is `http://localhost:8000`, and the seeder publishes from the console. The media and page URLs
inside the seeded snapshots are therefore absolute `http://localhost:8000/...`. Serve on that origin, or fix
`APP_URL` before seeding (M-10).

---

## J. Existing tests that must change

None is loosened; each change follows from the contract. Apply with the step named.

| # | File | Change | With |
|---|---|---|---|
| J.1 | `tests/Feature/Settings/MaintenanceModeTest.php` | exempt `/robots.txt` from the "every public GET is gated" scan (block below) | F |
| J.2 | `tests/Unit/Support/SettingsRegistryTest.php` | `GROUPS` gains `'website'` after `'mail'`; the method is renamed to the fourteen groups (blocks below) | D |
| J.3 | `tests/Feature/Settings/SettingsFormRoundTripTest.php` | the pinned list gains `'website'`; renamed (block below). The data-provider now also round-trips the `website` group through the real form — a genuine new check | D |
| J.4 | `tests/Feature/Modules/SidebarVisibilityTest.php` | Super Admin's label list gains the nine Website entries (block below) | G |
| J.5 | `tests/Unit/Support/PermissionRegistryTest.php` | *(strengthening, optional)* the website contract list gains the three new slugs | C |
| J.6 | `tests/Feature/Dashboard/DashboardWidgetRegistryTest.php` | **later**: when `WebsiteContentWidget` / `SeoHealthWidget` land, drop `website_content` and `seo_health` from `FOREIGN_KEYS` (they become Phase 3's) | deferred |

J.1 — find

```php
            if ($uri === '/up' || str_starts_with($uri, '/storage') || str_starts_with($uri, '/_') || str_starts_with($uri, '/sanctum')) {
```

replace with

```php
            // phase-03 §6.5 [D-W3-13], FT-47: robots.txt must answer while the site is closed.
            if ($uri === '/up' || $uri === '/robots.txt' || str_starts_with($uri, '/storage') || str_starts_with($uri, '/_') || str_starts_with($uri, '/sanctum')) {
```

J.2 — find

```php
        'company', 'branding', 'appearance', 'localization', 'contact', 'social', 'seo',
        'mail', 'collaborator', 'institute', 'finance', 'security', 'maintenance',
    ];
```

replace with

```php
        'company', 'branding', 'appearance', 'localization', 'contact', 'social', 'seo',
        'mail', 'website', 'collaborator', 'institute', 'finance', 'security', 'maintenance',
    ];
```

and find

```php
    public function the_thirteen_groups_are_declared_with_their_metadata(): void
```

replace with

```php
    public function the_fourteen_groups_are_declared_with_their_metadata(): void
```

J.3 — find

```php
    public function the_registry_declares_the_thirteen_groups_this_suite_walks(): void
    {
        $this->assertSame(
            ['company', 'branding', 'appearance', 'localization', 'contact', 'social', 'seo', 'mail', 'collaborator', 'institute', 'finance', 'security', 'maintenance'],
```

replace with

```php
    public function the_registry_declares_the_fourteen_groups_this_suite_walks(): void
    {
        $this->assertSame(
            ['company', 'branding', 'appearance', 'localization', 'contact', 'social', 'seo', 'mail', 'website', 'collaborator', 'institute', 'finance', 'security', 'maintenance'],
```

J.4 — find

```php
                'Login History',
                'Settings',
            ],
            $labels,
            'Only the screens whose phase has actually shipped its routes may appear — Phase 1 plus Phase 2\'s Settings.'
```

replace with

```php
                'Login History',
                'Settings',
                // phase-03 §7-§8: the nine Website CMS entries whose routes now exist.
                'Website Overview',
                'Sections',
                'Menus',
                'Pages',
                'CTA Blocks',
                'FAQs',
                'FAQ Categories',
                'Media Library',
                'SEO',
            ],
            $labels,
            'Only the screens whose phase has actually shipped its routes may appear — Phase 1, Phase 2\'s Settings and Phase 3\'s Website CMS.'
```

J.5 — find

```php
                'student_reviews', 'success_stories', 'faqs', 'blog_categories', 'blog_posts', 'jobs',
                'job_applications', 'contact_inquiries', 'seo',
```

replace with

```php
                'student_reviews', 'success_stories', 'faqs', 'blog_categories', 'blog_posts', 'jobs',
                'job_applications', 'contact_inquiries', 'seo',
                // phase-03 §4.1
                'website_cta_blocks', 'website_media', 'faq_categories',
```

These must keep passing **unchanged**, and the integration was shaped around them:
- `SmokeTest`: `GET /` 200, `/login` 200, `GET` and `POST /register` 404 (F.4 lookahead), `/admin` redirects
  to login (F.5 order).
- `SidebarVisibilityTest::every_rendered_item_points_at_a_url_the_user_can_actually_open` opens all nine CMS
  screens as Super Admin against the seeded site. It is the first real render test of the admin CMS, and
  it is why A.1 is hard.
- `PermissionStringConsistencyTest` (every route/sidebar permission exists and agrees) and
  `MiddlewareStackTest::every_authenticated_route_carries_the_active_middleware`. Both were verified in F.6.
- `SettingsSplitTruthRegressionTest::saving_the_maintenance_message_changes_the_holding_page` and all of
  `MaintenanceModeTest`, which depend on E.7 keeping the strings and variables.
- Tests counting `activity_log` rows use a before/after delta, so seeding CMS content in `DatabaseSeeder`
  does not shift them (grep; unverified by a run).

---

## K. Reconciliation (brief item 8)

### K.1 What was reconciled, and how

- **Routes ↔ controllers ↔ Form Requests ↔ policies ↔ sidebar:** mechanically, see F.6. No mismatch.
- **`route()` names ↔ routes:** every `route()`, `to_route()`, `redirect()->route()` and
  `URL::temporarySignedRoute()` in `app/Http/Controllers/{Admin/Cms,Site}`, `app/Services/Cms`,
  `resources/views/{admin/cms,site,components/site}` resolves to an F route. Where an array is passed, it
  carries every required parameter. Other names used: `admin.settings.index` (`['group' => …]`, exists) and
  `login`, always behind `Route::has()`. No mismatch.
- **View variables ↔ controllers:** every `view()` / `response()->view()` call site was paired with the
  compiled view and its `@include` tree, including `PageTemplate::resolve()` →
  `site.pages.{default,wide,legal}`, `ComposesSite::partialVariables()` → `site.sections.*`, `<x-site.cta>` →
  `site.cta.*`, and the proposed `EnsurePublicSiteAvailable` → `site.{holding,maintenance}`. **No view
  requires a variable its controller does not pass.** The only gaps are optional reads, listed as K-1.
- **Settings keys ↔ registry:** every literal key Phase 3 reads is declared after D. Every `site_setting()`
  key in `site/**` and `components/site/**` is public, including the whole `social` group read through a
  loop.
- **Service calls ↔ services:** every method called on an existing service exists with the arity used. The
  calls on the five absent classes are A.1's contract, listed in K-7.

### K.2 Mismatches, each with the exact fix (owner in brackets)

| # | Mismatch | Evidence | Exact fix |
|---|---|---|---|
| K-1 | The page banner, OG image and CTA background pickers get no media library, so they can only keep or clear the stored image [controllers] | views read `$mediaLibrary ?? []`; only `SectionController@edit` passes it | Apply the three `RespondsForCms` edits below (verified: `php -l`, pint). Delete `SectionController`'s private `mediaLibrary()` (lines 500-523, docblock included); the trait's method replaces it, and its `MediaService $media` constructor argument becomes unused. Then add `'mediaLibrary' => $this->mediaLibrary(),` to the `view()` arrays of `PageController@create` (line 111), `PageController@edit` (152), `CtaBlockController@index` (61), `CtaBlockController@edit` (101) and to `SeoController@edit`'s `$data` array (line 111) |
| K-2 | Reason inputs allow 3 characters; the server requires 5 (`CmsFormRequest::REASON_MIN`), so a 3-4 character reason passes the browser and comes back 422 [views] | `sections/index.blade.php:388`, `:413`; `sections/edit.blade.php:200`; `pages/edit.blade.php:151`; `partials/revisions-table.blade.php:97` | replace `minlength="3"` with `minlength="{{ \App\Http\Requests\Cms\CmsFormRequest::REASON_MIN }}"` in those five inputs |
| K-3 | `NoHardcodedFormatsTest` is red [views] | `admin/cms/media/index.blade.php:40`, `admin/cms/media/show.blade.php:42`, `admin/cms/sections/edit.blade.php:322` use `number_format(`; `site/partials/holding-page.blade.php:40` uses `date('Y')` | index:40 → `app_number(round($bytes / 1048576, 1), 1).' MB'`; show:42 → `app_number(round($bytes / 1048576, 2), 2).' MB'`; edit:322 → `{{ app_number(round($large->size_bytes / 1048576, 1), 1) }}`; holding-page:40 → `$year = rescue(static fn () => app_date(now(), 'Y'), (string) now()->year, false);` |
| K-4 | The footer section's type icon `bars-3-bottom-left` is not drawable by `x-ui.icon` (a placeholder square in the add-section modal) [registry] | `app/Support/Cms/SectionRegistry.php:1442`; the icon map has no such key | change it to `'icon' => 'queue-list',` |
| K-5 | Registry link defaults point at targets nothing serves, so a freshly placed section links nowhere [registry] | `SectionRegistry.php:927` `#contact`, `:940` `#admission`, `:952` `#contact`, `:1039` `#contact`, `:1043` `#courses`, `:1389` `/faqs` | I.2 overrides them for the seeded sections. For sections placed by an admin, set those `url`s to `null` until the owning phase ships (a link with no URL renders nothing) |
| K-6 | `SitemapGenerator::cached(0)` uses the index's cache key [services] | `SitemapGenerator.php:178` `key('sitemap', [$chunk ?? 0])` | key the index as `[$chunk ?? 'index']`; `Site\SitemapController@chunk` already refuses 0 |
| K-7 | Five classes the controllers inject do not exist [unassigned] | `PageController` (Admin, Site), `ValidatesPage`, `MenuController`, `MenuItemController`, `CtaBlockController`, `FaqController`, `FaqCategoryController`, `SectionController@edit`, `StatisticController` | write them to A.1's table; the calls in use are `PageService::{reservedSlugs,create,saveDraft,duplicate,delete,restore}`, `MenuService::{updateMenu,reorder,resolveUrl,storeItem,updateItem,deleteItem}`, `CtaBlockService::{save,usage,delete}`, `FaqService::{save,toggle,reorder,delete,saveCategory,reorderCategories,deleteCategory}`, `StatisticsProvider::{all,valueFor}` |
| K-8 | Snapshots freeze absolute URLs (menu links and media) [services] | `SnapshotBuilder.php:502, 516` `$this->url->route(...)` (absolute); `MediaService::url()` → the `public` disk's absolute `APP_URL/storage` URL. `SeoService` already passes `false` | pass `false` as the third argument of both `route()` calls in `SnapshotBuilder`; keep media absolute but set `APP_URL` correctly before anything is published (M-10) |
| K-9 | The layout is `site.layouts.public`; the contract's path is `resources/views/layouts/site.blade.php` [integrator, optional] | handover views §1 | optional one-line file `@extends('site.layouts.public')`; nothing references `layouts.site` |
| K-10 | A FAQ section with `source = selected` has no write path for its picks [services] | `SectionService` has no `faq_website_section` sync; no request accepts picks | until it exists, the FAQ editor should offer only `category` / `featured` (the view already omits the picker) |
| K-11 | `restore` on CTA blocks, FAQ categories and media checks `{module}.restore`, which C does not declare, so only Super Admin could restore [policies] | `CtaBlockPolicy`, `FaqCategoryPolicy`, `MediaPolicy` | no route restores them, so nothing is reachable today. Decide when a restore route is added: declare `RESTORE` on those modules, or drop the method |

K-1 edits, `app/Http/Controllers/Admin/Cms/Concerns/RespondsForCms.php`. Find

```php
use App\Models\User;
```

replace with

```php
use App\Models\Cms\MediaAsset;
use App\Models\User;
```

find

```php
use App\Services\Cms\Exceptions\UnsupportedUploadException;
```

replace with

```php
use App\Services\Cms\Exceptions\UnsupportedUploadException;
use App\Services\Cms\MediaService;
```

find

```php
    /**
     * A LIKE pattern with the wildcards of the term itself escaped.
     */
```

replace with

```php
    /**
     * The media picker's library (`admin.cms.partials.media-picker`): images and videos, newest first,
     * bounded. Shared by every screen with an image slot (sections, page banner, CTA background, OG image).
     *
     * @return list<array<string, mixed>>
     */
    protected function mediaLibrary(): array
    {
        $media = app(MediaService::class);

        return MediaAsset::query()
            ->latest('id')
            ->limit(200)
            ->get()
            ->map(static fn (MediaAsset $asset): array => [
                'id' => (int) $asset->getKey(),
                'name' => (string) ($asset->title ?: $asset->original_name),
                'alt_text' => $asset->alt_text,
                'mime_type' => $asset->mime_type,
                'kind' => $asset->isVideo() ? 'video' : 'image',
                'url' => $media->url($asset),
                'width' => $asset->width,
                'height' => $asset->height,
            ])
            ->values()
            ->all();
    }

    /**
     * A LIKE pattern with the wildcards of the term itself escaped.
     */
```

---

## L. Verification (brief item 9)

Run by the integrator only: L.9 runs the suite, and the test database is shared. From the project root, in
this order. Phase 3 is not done until L.1-L.8 print no `FAIL`/`MISSING`, a human has looked at the `CHECK`
lines, and both commands of L.9 are green.

### L.1 Gate

Run A.3 again. `A.1 OK`, and `A.2 OK` unless F.2-interim was chosen.

### L.2 Syntax and style

```bash
cd "/c/xampp/htdocs/my office"
for f in app/Support/PermissionRegistry.php app/Support/SettingsRegistry.php app/Support/Sidebar.php app/Support/SiteSettings.php \
         app/Support/Exceptions/NonPublicSettingException.php app/Support/helpers.php app/Support/Modules.php \
         app/Providers/AppServiceProvider.php app/Http/Middleware/EnsurePublicSiteAvailable.php bootstrap/app.php \
         routes/web.php routes/admin.php routes/site-pages.php routes/console.php resources/data/icons.php \
         database/seeders/RoleSeeder.php database/seeders/DatabaseSeeder.php database/seeders/WebsiteCmsSeeder.php; do
  php -l "$f" | grep -v '^No syntax errors'
done
./vendor/bin/pint --test app bootstrap routes database resources/data config tests
php artisan permission:cache-reset && php artisan optimize:clear
php artisan route:list --name=website --json | grep -o '"name":"admin.website[^"]*"' | wc -l   # 78
php artisan route:list --name=site. --json | grep -o '"name":"site\.[^"]*"' | wc -l             # 7
```

### L.3-L.5 Routes, route order, sidebar (a PHP file)

Proven on the scratch integration: it prints `L.3-L.5 OK (85 Phase 3 routes)` for F.2-interim, and it names
the two missing middleware classes for the full F.2 while A.2 is unmet.

```php
<?php

// L.3-L.5 — routes, route order, sidebar. Run from the project root: php <this file>
// Boots the real application (reads settings; the cache store may write its usual rows).
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// Building the HTTP kernel copies the middleware aliases and groups onto the router.
$app->make(Illuminate\Contracts\Http\Kernel::class);
$router = $app['router'];

$fqcn = static fn (string $dotted): string => str_replace('.', chr(92), $dotted);
$permissions = App\Support\PermissionRegistry::permissionNames();
$modules = App\Support\PermissionRegistry::modules();
$gate = $fqcn('App.Http.Middleware.EnsurePublicSiteAvailable');
$formRequest = $fqcn('App.Http.Requests.Cms.CmsFormRequest');
$model = $fqcn('Illuminate.Database.Eloquent.Model');
$bad = [];
$count = 0;
$byName = [];

foreach ($router->getRoutes() as $route) {
    $name = (string) $route->getName();
    $admin = str_starts_with($name, 'admin.website.');
    $public = str_starts_with($name, 'site.');
    if (! $admin && ! $public) {
        continue;
    }
    $count++;
    $byName[$name] = $route;
    $mw = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
    $can = array_values(array_filter($mw, static fn ($m) => str_starts_with($m, 'can:')));
    $mod = array_values(array_filter($mw, static fn ($m) => str_starts_with($m, 'module:')));
    [$class, $method] = array_pad(explode('@', $route->getActionName(), 2), 2, '__invoke');

    if (! class_exists($class) || ! method_exists($class, $method)) {
        $bad[] = "$name: missing action {$route->getActionName()}";

        continue;
    }
    foreach ($can as $c) {
        if (! in_array(substr($c, 4), $permissions, true)) {
            $bad[] = "$name: $c is not declared in PermissionRegistry";
        }
    }
    if ($admin && (count($can) !== 1 || count($mod) !== 1 || ! isset($modules[substr($mod[0], 7)]) || explode('.', substr($can[0], 4))[0] !== substr($mod[0], 7))) {
        $bad[] = "$name: needs exactly one module: and one can: of that module, has ".json_encode(array_merge($mod, $can));
    }
    if ($admin && array_diff(['auth', 'active', 'panel:admin'], $mw) !== []) {
        $bad[] = "$name: missing auth/active/panel:admin";
    }
    if ($public && ($can !== [] || $mod !== [])) {
        $bad[] = "$name: a public route carries can:/module: (INV-15)";
    }
    $gated = collect($router->gatherRouteMiddleware($route))->contains(static fn ($r) => is_string($r) && explode(':', $r, 2)[0] === $gate);
    if ($public && ($name === 'site.robots') === $gated) {
        $bad[] = $name === 'site.robots' ? 'site.robots must not carry the site gate' : "$name: not behind the site gate";
    }
    foreach ($mw as $m) {
        $alias = explode(':', $m, 2)[0];
        $resolved = $router->getMiddleware()[$alias] ?? (class_exists($alias) ? $alias : null);
        if ($resolved === null && ! array_key_exists($alias, $router->getMiddlewareGroups())) {
            $bad[] = "$name: middleware alias [$alias] is not registered";
        } elseif (is_string($resolved) && ! class_exists($resolved)) {
            $bad[] = "$name: middleware [$alias] points at a missing class $resolved";
        }
    }

    $ref = new ReflectionMethod($class, $method);
    foreach ($ref->getParameters() as $p) {
        $t = $p->getType();
        $tn = $t instanceof ReflectionNamedType && ! $t->isBuiltin() ? $t->getName() : null;
        if (($tn === null || is_subclass_of($tn, $model) || enum_exists($tn)) && ! in_array($p->getName(), $route->parameterNames(), true) && ($tn !== null || $t !== null)) {
            if ($tn === null || is_subclass_of($tn, $model) || enum_exists($tn)) {
                $bad[] = "$name: argument \${$p->getName()} has no route parameter of that name";
            }
        }
        if ($admin && $tn !== null && is_subclass_of($tn, $formRequest)) {
            $request = new $tn;
            $bound = clone $route;
            (function () {
                $this->parameters = [];
            })->call($bound);
            foreach ($ref->getParameters() as $q) {
                $qt = $q->getType();
                $qn = $qt instanceof ReflectionNamedType && ! $qt->isBuiltin() ? $qt->getName() : null;
                if ($qn !== null && in_array($q->getName(), $route->parameterNames(), true)) {
                    $bound->setParameter($q->getName(), enum_exists($qn) ? $qn::cases()[0] : new $qn);
                }
            }
            $request->setRouteResolver(static fn () => $bound);
            $perm = (fn () => $this->permission())->call($request);
            if ($can !== [] && $perm !== substr($can[0], 4)) {
                $bad[] = "$name: $tn::permission() is ".var_export($perm, true).", the route demands {$can[0]}";
            }
        }
    }
}
if ($count !== 85) {
    $bad[] = "expected 85 Phase 3 routes, found $count";
}

// Route order and reserved first segments.
$expect = [
    ['GET', '/admin', 'admin.dashboard'], ['GET', '/login', 'login'], ['GET', '/student', 'student.dashboard'],
    ['GET', '/teacher', 'teacher.dashboard'], ['GET', '/client', 'client.dashboard'], ['GET', '/collaborator', 'collaborator.dashboard'],
    ['GET', '/', 'site.home'], ['GET', '/robots.txt', 'site.robots'], ['GET', '/sitemap.xml', 'site.sitemap'],
    ['GET', '/sitemap-2.xml', 'site.sitemap.chunk'], ['GET', '/preview/page/5', 'site.preview.page'], ['GET', '/privacy-policy', 'site.page'],
    ['GET', '/admin/website/sections/home', 'admin.website.sections.index'], ['POST', '/admin/website/sections/reorder', 'admin.website.sections.reorder'],
    ['GET', '/admin/website/pages/export', 'admin.website.pages.export'], ['GET', '/admin/website/sections/nope', 404],
    ['GET', '/register', 404], ['POST', '/register', 404], ['GET', '/courses', 404], ['GET', '/team', 404], ['GET', '/verify', 404],
    ['GET', '/account', 404], ['GET', '/foo/bar', 404], ['GET', '/Upper', 404],
];
foreach ($expect as [$verb, $uri, $want]) {
    try {
        $got = $router->getRoutes()->match(Illuminate\Http\Request::create($uri, $verb))->getName() ?? '(unnamed)';
    } catch (Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
        $got = 404;
    } catch (Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException) {
        $got = 405;
    }
    if ($got !== $want) {
        $bad[] = "route order: $verb $uri -> ".var_export($got, true).' (want '.var_export($want, true).')';
    }
}

// Sidebar entries vs their routes.
$walk = static function (array $items) use (&$walk, &$bad, $byName): void {
    foreach ($items as $item) {
        $walk($item['children'] ?? []);
        if (! str_starts_with((string) ($item['route'] ?? ''), 'admin.website.')) {
            continue;
        }
        $route = $byName[$item['route']] ?? null;
        if ($route === null) {
            $bad[] = "sidebar: {$item['route']} is not registered";

            continue;
        }
        if (! in_array('can:'.$item['permission'], $route->gatherMiddleware(), true) || ! in_array('module:'.$item['module'], $route->gatherMiddleware(), true)) {
            $bad[] = "sidebar: {$item['route']} states a different permission or module than its route";
        }
    }
};
foreach (App\Support\Sidebar::tree('admin') as $group) {
    $walk($group['items']);
}

echo $bad === [] ? "L.3-L.5 OK ($count Phase 3 routes)\n" : "FAIL\n  ".implode("\n  ", $bad)."\n";
```

### L.6 Views compile; static scans (a PHP file)

```bash
php artisan view:cache && php artisan view:clear
```

```php
<?php

// L.6 — static scans over the Phase 3 views (FT-37, FT-42, CLAUDE.md §13.4 rules, NoHardcodedFormatsTest).
// Run from the project root: php <this file>
$root = getcwd().'/resources/views/';
$fail = [];
$formats = [
    '->format(' => '/->\s*format\s*\(/',
    '->isoFormat(' => '/->\s*isoFormat\s*\(/',
    '->translatedFormat(' => '/->\s*translatedFormat\s*\(/',
    'number_format(' => '/(?<![\w$>:])number_format\s*\(/',
    'date(' => '/(?<![\w$>:])date\(\s*[\'"$]/',
];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    if (! str_ends_with($file->getFilename(), '.blade.php')) {
        continue;
    }
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root)));
    $public = str_starts_with($rel, 'site/') || str_starts_with($rel, 'components/site/');
    if (! $public && ! str_starts_with($rel, 'admin/cms/')) {
        continue;
    }
    $code = (string) preg_replace_callback('/\{\{--.*?--\}\}/s', static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")), (string) file_get_contents($file->getPathname()));

    foreach (preg_split('/\r\n|\r|\n/', $code) ?: [] as $i => $line) {
        $at = $rel.':'.($i + 1).'  ';
        foreach ($formats as $name => $pattern) {
            if (preg_match($pattern, $line) === 1) {
                $fail[] = $at."hand-rolled format $name (NoHardcodedFormatsTest)";
            }
        }
        if ($public && str_contains($line, '{!!') && ! str_contains($line, 'RichText::sanitize')) {
            $fail[] = $at.'unescaped output not on RichText::sanitize() (FT-37)';
        }
        if (str_starts_with($rel, 'site/') && preg_match('/<img\b/i', $line) === 1) {
            $fail[] = $at.'bare <img> in site/ (use <x-site.image>)';
        }
        if (str_starts_with($rel, 'site/') && preg_match('/(?<![\w$>])(?<!site_)setting\(|(?<![\w$>])config\(/', $line) === 1) {
            $fail[] = $at.'setting()/config() in a site view (FT-42)';
        }
        if ($public && preg_match('/csrf_token\(|@csrf|name="csrf-token"/', $line) === 1) {
            $fail[] = $at.'request-forgery token printed into a cacheable public page';
        }
    }
}

if (is_file(getcwd().'/public/robots.txt')) {
    $fail[] = 'public/robots.txt still exists and shadows the site.robots route (F.3)';
}
if (! is_dir(getcwd().'/public/storage') && ! is_link(getcwd().'/public/storage')) {
    $fail[] = 'public/storage is missing: run php artisan storage:link (media URLs 404 without it)';
}

echo $fail === [] ? 'L.6 OK'.PHP_EOL : 'FAIL'.PHP_EOL.'  '.implode(PHP_EOL.'  ', $fail).PHP_EOL;
```

Today, before K-3, F.3 and B.3, it prints exactly those six failures.

### L.7 Database guarantees and the seeded site (read-only SQL)

```bash
/c/xampp/mysql/bin/mysql.exe -uroot my_office < "$TEMP/phase3-L7.sql"    # the SQL below, saved with a file tool
```

```sql
-- L.7 read-only: the database guarantees phase-03 FT-50 names, and the seeded day-one site.
SELECT COUNT(*) AS check_constraints_expect_7 FROM information_schema.CHECK_CONSTRAINTS
 WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME IN ('chk_media_size','chk_mi_depth','chk_mi_parent','chk_seo_priority','chk_seo_target','chk_ws_page_placement','chk_wsi_value');
SELECT COUNT(*) AS stored_generated_expect_2 FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'has_unpublished_changes' AND EXTRA = 'STORED GENERATED';
SELECT COUNT(DISTINCT INDEX_NAME) AS unique_guards_expect_10 FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0 AND INDEX_NAME IN ('uq_cta_key','uq_faqcat_slug','uq_media_checksum','uq_menus_location','uq_menus_slug','uq_pages_slug','uq_seo_route','uq_seo_target','uq_ws_anchor','uq_ws_instance');
SELECT
  (SELECT COUNT(*) FROM modules WHERE slug IN ('website_cta_blocks','website_media','faq_categories')) AS new_modules_expect_3,
  (SELECT COUNT(*) FROM permissions WHERE name IN ('website_sections.view_logs','pages.view_logs','seo.view_logs') OR name LIKE 'website\_cta\_blocks.%' OR name LIKE 'website\_media.%' OR name LIKE 'faq\_categories.%') AS new_permissions_expect_24,
  (SELECT COUNT(*) FROM settings WHERE `group` = 'website') AS website_settings_expect_13,
  (SELECT COUNT(*) FROM settings WHERE `group` = 'seo' AND `key` IN ('robots_txt_mode','robots_txt_custom','sitemap_changefreq_default','sitemap_priority_default')) AS seo_settings_expect_4;
SELECT
  (SELECT COUNT(*) FROM website_sections WHERE status = 'published' AND is_enabled = 1 AND deleted_at IS NULL) AS live_sections_expect_6,
  (SELECT COUNT(*) FROM website_section_items WHERE deleted_at IS NULL) AS section_items_expect_15,
  (SELECT COUNT(*) FROM pages WHERE is_system = 1 AND status = 'published') AS system_pages_expect_4,
  (SELECT COUNT(*) FROM menus) AS menus_expect_4,
  (SELECT COUNT(*) FROM menu_items) AS menu_items_expect_9,
  (SELECT COUNT(*) FROM cta_blocks) AS cta_blocks_expect_1,
  (SELECT COUNT(*) FROM faq_categories) AS faq_categories_expect_3,
  (SELECT COUNT(*) FROM faqs) AS faqs_expect_6,
  (SELECT COUNT(*) FROM seo_meta) AS seo_meta_expect_5;
SELECT COUNT(*) AS live_rows_with_drift_expect_0 FROM (
  SELECT id FROM website_sections WHERE status = 'published' AND deleted_at IS NULL AND (published_content IS NULL OR has_unpublished_changes = 1)
  UNION ALL
  SELECT id FROM pages WHERE status = 'published' AND deleted_at IS NULL AND (published_content IS NULL OR has_unpublished_changes = 1)
) AS drift;
SELECT COUNT(*) AS absolute_localhost_urls_in_snapshots FROM website_sections WHERE published_content LIKE '%localhost%';
```

Expected after I.4: 7 / 2 / 10 / 3 / 24 / 13 / 4 / 6 / 15 / 4 / 4 / 9 / 1 / 3 / 6 / 5 / 0.
`absolute_localhost_urls_in_snapshots` is a **CHECK**: non-zero means the snapshots carry
`http://localhost:8000` (K-8, M-10). Verified today: the structural half already reads 7 / 2 / 10.

### L.8 HTTP smoke and the manual browser pass

```bash
# L.8 — HTTP smoke through a real server. In a second terminal: php artisan serve --port=8010
B=http://127.0.0.1:8010
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
for p in / /privacy-policy /terms-of-service /sitemap.xml /robots.txt; do echo "$(code "$B$p") $p (want 200)"; done
echo "$(code "$B/admin") /admin (want 302)"
echo "$(code "$B/register") /register (want 404)"
echo "$(code -X POST "$B/register") POST /register (want 404 or 419, never 405)"
echo "$(code "$B/no-such-page") /no-such-page (want 404, branded)"
echo "$(code "$B/preview/page/1") /preview/page/1 unsigned guest (want 404)"
echo "$(code "$B/preview/page/1?signature=forged") /preview/page/1 forged signature (want 403)"
curl -s "$B/robots.txt" | grep -q '^Disallow: /admin' && echo "ok   robots.txt is the generated file" || echo "FAIL robots.txt is not the generated file"
curl -s "$B/" | grep -qi 'name="robots" content="index' && echo "ok   home is indexable" || echo "CHECK home robots meta (seo.robots_indexable?)"
curl -s "$B/" | grep -q 'csrf-token' && echo "FAIL a CSRF token is printed into the cacheable home page" || echo "ok   no CSRF token in the home page"
curl -sI "$B/" | grep -i '^x-robots-tag' || echo "ok   no X-Robots-Tag on an indexable live page"
curl -s "$B/" | grep -o 'http://localhost:8000[^"]*' | head -3
```

Manual, in a browser at 375 / 768 / 1280 px, light and dark:
1. Home and a policy page have no horizontal overflow. The mobile drawer traps focus and closes on Esc.
2. Edit the hero heading as Super Admin. The public page still shows the old heading. Publish, and the
   public page shows the new one within one request (FT-06/FT-25).
3. Unpublish the privacy page (reason required). The footer link disappears and `/privacy-policy` is a 404
   (FT-28). Publish it again.
4. Maintenance on: a guest gets the 503 holding page; Super Admin sees the real site with the amber ribbon;
   `/robots.txt` still answers 200 with `Disallow: /`.
5. Upload a JPEG to the media library, then a `.php` file renamed `.jpg` (refused with a reason). Place the
   JPEG in the hero with alt text; publish.
6. If B.5 was taken, type two paragraphs and a heading in a Trix field, save, and view the page source.
   The markup must be `<p>`, `<p>`, `<h2>`. Run-together text with no `<p>` means B.5 is broken, so revert
   to the textarea.
7. As a user holding only `website_sections.edit`: saving a draft works, and Publish is disabled and 403s
   if forced (FT-48).

### L.9 Acceptance tests (phase-03 §11) — every row must exist, then pass

No `tests/Feature/Cms` directory exists today (unassigned, 0.2).

```bash
cd "/c/xampp/htdocs/my office"
for t in \
  unknown_section_type_cannot_be_placed unique_section_type_cannot_be_placed_twice repeatable_section_type_can_be_placed_many_times \
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
  stored_file_is_safe_and_deterministic derivatives_match_the_profile_and_never_upscale media_in_use_cannot_be_deleted_and_reupload_deduplicates \
  seo_fallback_chain_per_field noindex_is_the_strictest_wins sitemap_contents_are_exactly_right robots_txt_in_each_mode \
  module_gating_affects_the_admin_cms_only public_views_cannot_read_non_public_settings every_cms_write_is_audited \
  authorization_matrix rich_text_is_sanitized_on_write_and_on_render rich_text_profiles_are_a_closed_map \
  no_unescaped_output_in_site_views maintenance_and_public_site_gates install_and_rollback public_site_is_responsive_and_dark_mode_clean \
  menu_visibility_is_applied_per_request_after_the_cache disabled_statistic_item_is_excluded_from_the_published_snapshot; do
  grep -rqE "function (test_)?${t}\(" tests/Feature/Cms 2>/dev/null || echo "MISSING acceptance test: ${t}"
done

php artisan test tests/Feature/Cms     # 52 rows: FT-01..FT-51 + FT-36b
php artisan test                       # full suite; Phase 2 checkpoint was 1020 green
```

Which rows cannot pass until a deferred piece exists: FT-22/24/25/26 (A.2 middleware). FT-32 needs the
`ScheduledPagePublished` notification. FT-36b needs B.1. FT-38 needs a JSON `deleteJson` call: the
HTML branch redirects with a toast (M-20). FT-43's "device" column comes from `CmsAuditor`; confirm it.
FT-50's `migrate:fresh --seed` must run on the **test** database only.

---

## M. Risks (brief item 10)

### Blocking or security-relevant

- **M-1 Five services and two middleware have no owner.** A.1/A.2 cannot pass until someone is assigned
  `app/Services/Cms/{Page,Menu,CtaBlock,Faq}Service.php`, `StatisticsProvider.php` and
  `app/Http/Middleware/{CachePublicResponse,ResolvePreviewMode}.php`. Until then Phase 3 is not integrable,
  only inspectable.
- **M-2 Full-page cache vs sessions.** Every public route sits in the `web` group, so every anonymous hit,
  crawlers included, starts a session. With `SESSION_DRIVER=database` that is a `sessions` row per new
  visitor, cached page or not. A.2's "never store `Set-Cookie`" rule keeps visitors' sessions apart. It does
  nothing for the write load. The only fix is a lighter middleware group for public GETs, a Phase 25
  decision.
- **M-3 Catch-all correctness hangs on one constant.** `site-pages.php` reads
  `PageService::RESERVED_SLUGS` through `defined()`. If the constant is missing or incomplete, nothing is
  reserved: `GET /register` renders the branded 404, `POST /register` answers 405, and `SmokeTest` fails. A
  page an admin creates at `/team` or `/verify` is later shadowed by Phase 4 or 19. A.3 checks the list.
- **M-4 `public/robots.txt` shadows `site.robots`** on Apache and `artisan serve`, while FT-47 passes
  in-kernel. F.3 deletes it, and L.6 catches it.
- **M-5 The staff bypass of the maintenance gate is a permission check.** `website_sections.view` is held
  by Admin, SEO Expert and Digital Marketer. If `website_sections` is disabled, Gate::before denies it, and
  staff get the holding page too. That is acceptable but surprising.
- **M-6 Rich text is the trust path.** `mews/purifier` is absent, so `RichText`'s own DOM walker is the only
  sanitiser. It passed its adversarial checks, but no second parser normalises first. B.1 adds that pass.
- **M-7 Super Admin bypasses every policy rule.** Only the services' `ContentActionNotAllowedException`
  stops a Super Admin deleting a required section, a system page or an image in use. A.1's services must
  refuse the same acts, or FT-16/17/38 pass for editors and fail for Super Admin.

### Correctness, likely to bite during integration

- **M-8 Partial integration turns the suite red.** `SidebarVisibilityTest` renders every CMS screen. Apply
  C-J in one change, after A.
- **M-9 Seeding order.** The WebsiteCmsSeeder before F freezes URL-less menu links into the header and
  footer snapshots, and `SeoService::ensure('site.home')` then has no route. I.2/I.4 say "after F".
- **M-10 Absolute URLs in snapshots.** `APP_URL=http://localhost:8000`. Content published from the console
  (the seeder) or under XAMPP's `/my%20office/public` carries that origin in menu and media URLs, and they
  break on any other host. K-8 fixes menus; media needs a correct `APP_URL` before the first publish.
  L.7 counts the damage.
- **M-11 Trix vs the `cms` profile.** Verified: unconfigured Trix output loses paragraphs and headings on
  save (`<div>`/`<h1>` are unwrapped). B.5's `tagName` overrides are the known fix, but Trix's `p` mode has
  quirks (nested blocks, pasted Word HTML). The textarea fallback is safe, so B.5 is optional.
- **M-12 Contract ability table vs data.** §4.2 would revoke 6 seeded permissions. C keeps them; amend the
  contract, not the data.
- **M-13 RoleSeeder is authoritative (`syncPermissions`).** It is a no-op on `my_office` today (verified).
  Any role grant edited by hand before I.4 is revoked. Back up first (I.4).
- **M-14 Seed content is deliberately thinner than §6.14** (I.2 deviations 1-4). The owner decides whether
  the invented-number rule wins.
- **M-15 Guessed metadata.** Module names and sorts (835/905/970), setting labels, help text and bounds
  (for example `image_max_width` 320-8000, `revision_keep` 1-500) are not in the contract. The bounds match
  what `MediaService` and `PageController` already clamp to.
- **M-16 Menu freshness is a render-time patch.** Header and footer snapshots freeze the menu tree (D-W3-8).
  `<x-site.menu>` drops a page link whose slug is no longer published, with one query per request, which is
  what makes FT-28 pass without republishing the header. A renamed slug still shows the old URL until the
  header is republished.
- **M-17 Statistics freeze inside cached pages.** `<x-site.stats>` resolves live counts at render time, and
  the rendered page is then cached for `website.cache_ttl_minutes` (1440). A "live" number can be a day old.
- **M-18 Media and scheduler need processes.** `QUEUE_CONNECTION=database`: with no worker, the
  derivative jobs would stay pending. The job classes do not exist, so `MediaService` generates
  synchronously after commit, which makes uploads slow. Nothing in H runs without `schedule:work` or a
  Task Scheduler entry.
- **M-19 Revision ownership is checked by hand.** There is no `scopeBindings()`.
  `ListsRevisions::assertRevisionOf()` 404s a foreign revision before the service runs. Keep it when editing
  those controllers.
- **M-20 FT-38 test shape.** A non-JSON DELETE on a used asset redirects with a toast and the usage list; it
  is not a 403 page. The test must use `deleteJson`.
- **M-21 SEO writes under `pages.edit`.** The page editor saves `seo_meta` through `SeoService::save()` when
  the user holds `pages.edit` (`PageController@update`). Such a user can set a page to noindex without
  `seo.edit`. It is consistent with §8.10's inline SEO tab, and D23 still holds (one writer), but the
  permission is wider than the SEO screen's.
- **M-22 Contract gaps G-1 to G-4** (F.6) and K-10 (no FAQ picks) ship as known omissions.
- **M-23 FT-36b and packages.** The test presumes `mews/purifier` plus `config/purifier.php` (B.1).
  `intervention/image` is listed by §13.4 and used by nothing.
- **M-24 Phase 4's 21 `website.*` keys are deferred.** The Settings tab shows 13 of the contract's 34 keys
  until Phase 4.
- **M-25 Dashboard widgets are unbuilt** (§13.2 `WebsiteContentWidget`, `SeoHealthWidget`). J.6 applies
  when they land.
- **M-26 Stricter gates than §7.6.** The sitemap and preview routes carry `site`, so a signed reviewer link
  returns 503 while the site is closed.

### Low, recorded so nobody rediscovers them

- **M-27 Cache stamp overflow.** `CacheVersion::STAMP_TTL_SECONDS` is ten years, and `cache.expiration` is a
  signed INT. From **2028-01-21** a fresh stamp overflows it and `bump()` fails (reported, not thrown). Use a
  shorter TTL, or a `bigInteger` migration, before then.
- **M-28 405 for other verbs on unknown single-segment paths.** `POST /anything-not-reserved` answers 405,
  because the GET catch-all matches the URI. It is harmless and reveals nothing.
- **M-29 Schedule times are UTC** unless H.2 is applied: 02:30-04:30 UTC is 07:30-09:30 in Pakistan.
- **M-30 Naming deviations from the contract, kept on purpose.** `Admin\Cms` rather than
  `Admin\Website`, `admin/cms` views, `site.layouts.public`, `SectionRegistry` (contract
  `WebsiteSectionRegistry`), `CacheVersion` (`PublicCache`), `SitemapGenerator` (`SitemapService`),
  `SeoService::robotsTxt()` (`RobotsService`), DTOs under `App\Services\Cms\Data`, and no
  `EnsurePreviewAuthorised` (the controller decides). Record them in `DEVELOPMENT_LOG.md`.
- **M-31 External font.** `site.layouts.public` loads Inter from `fonts.bunny.net`, non-blocking. That is a
  third-party request on every public page, relevant to a consent decision (§12.2 Q8).
- **M-32 Nothing here has run against MariaDB.** No Phase 3 publish, lock, `afterCommit` path or
  generated-column write has executed. L.7-L.9 are the first time.
