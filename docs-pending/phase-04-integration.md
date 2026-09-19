# Phase 4 integration list — ordered, copy-pasteable

Unit 04 · contract `docs/phases/phase-04.md` · first written 2026-09-13, **re-verified against the live tree and the
live `my_office` database on 2026-09-19** by the phase-04 integration-list role. Everything below was read from disk
or queried read-only; the four domain reports were checked against the files, not trusted.

---

## 0. STATUS — read this before applying anything

**§1 through §8 are ALREADY APPLIED and seeded. Do not paste them again.** A previous integration run moved the
migrations, ran them, edited the six registry/provider files, both route files, the seeder, and re-seeded. Re-applying
any of §1-§8 duplicates a table, a module row, a route or a registry entry. Their blocks are kept below **as the
record of what was applied and the diff to review**, not as work to do.

Evidence, all re-measured on 2026-09-19:

| Step | Claim | Verified how | Result |
|---|---|---|---|
| §1 | 15 migrations moved and run | `SHOW TABLES` + `SELECT migration FROM migrations` | 20/20 tables present; `database/migrations-staged/phase-04/` gone |
| §2 | PermissionRegistry | `PermissionRegistry::permissionNames()` / `moduleSlugs()` | **851** permissions, **86** modules; `modules` table has 86 rows incl. the 4 new slugs |
| §3 | SettingsRegistry | `SettingsRegistry::keys()` | **34** `website.*` keys declared; `settings` table has 34 `website` rows |
| §4 | providers, listeners, schedule | grep of the three files | `PublicFormRateLimits::register()` at `AppServiceProvider:242`; 7 sitemap providers at `:259-265`; `MarketingSectionTypes::definitions()` at `:174`; `DashboardRegistry::registerMany()` at `:245`; 4 `Schedule::command()` rows in `routes/console.php` |
| §5 | routes | `php artisan route:list --json` | **168** phase-4 routes (153 admin + 15 site) live; 0 unknown `can:`, 0 unknown module slug, 0 missing `Class@method`, 0 admin route without `auth+active+panel:admin+module:+can:`, 0 `can:` on a `site.*` route |
| §6 | Sidebar | `app/Support/Sidebar.php:705-800` | 15 Website entries, each `permission` equal to the linked route's `can:` |
| §7 | RoleSeeder + seed run | `database/seeders/RoleSeeder.php` + `permissions`/`modules` row counts | grants present; database seeded |
| §8 | packages | `composer.json` | none needed; unchanged |
| §9 | R-1 … R-12 | grep of each named file | **all 12 applied** (§9's "Applied where" table names the line of each) |
| — | `php -l` / `./vendor/bin/pint --test` | run over the tree | only **5 pre-existing** failures, none of them a Phase 4 file: `Auth/NewPasswordController.php`, `config/activitylog.php`, the three Phase-1 spatie activity-log migrations |

**What is actually left.** In this order:

| # | Work | Owner | Where |
|---|---|---|---|
| L-1 | Append Phase 4's rows to the four D60 manifests — **they hold 0 `owner_phase => 4` rows today**, so `tests/Feature/Cms/Marketing/Http/MarketingManifestTest` (7 methods) cannot pass | fix agent | §10.3 |
| L-2 | Add the 12 Phase 4 enums to `tests/Unit/Enums/EnumContractTest::enumProvider()` (it still lists only the 6 Phase 1 enums) | fix agent | §10.2 |
| L-3 | Write contract test **52** — the only one of the 65 with no test anywhere (`grep -rl "429\|RateLimiter" tests/` is empty) | fix agent | §10.1 |
| L-4 | Run the suite, then `php artisan permission:cache-reset && php artisan optimize:clear` | verify agent | §7 |
| L-5 | Commit; update `DEVELOPMENT_LOG.md` (tracker, change log, test results, D-rows for R-13 … R-25) | verify agent | — |
| L-6 | Decide the 8 `Route::has()`-guarded dead links (R-9, R-10, R-11) — leave, or take to the contract owner | human | §9 |

**How to read the rest.** §1-§8 are the applied record, each under an APPLIED banner naming the file and line where
it landed. §9 is the reconciliation: R-1 … R-12 all applied (with an "Applied where" table), R-13 … R-20 deliberate
deviations, and five **new** rows R-21 … R-25 found on 2026-09-19. §10 is the test inventory measured against the 26
test files that now exist, and §10.3 is L-1's copy-pasteable content. §11 is the current risk list. The **Appendix**
keeps the four domain agents' hand-off notes verbatim, for reference only — **where the Appendix disagrees with
§1-§9, §1-§9 wins; never paste from the Appendix, except S.8's index rows, which §10.3 (d) calls for.**

**Preconditions (both now satisfied).**
1. Phase 3 is integrated and committed (`7c61b1c`, `b861653`, `2462814`). Phase 4's own edits are **uncommitted** in
   the working tree, alongside Phase 5's — see §11 risk 1.
2. The Phase 3 artefacts Phase 4 builds on all exist: `routes/site-pages.php` loaded last by `bootstrap/app.php`;
   alias `site_module` → `App\Http\Middleware\EnsureSiteModuleEnabled`; `site`, `site.cache`;
   `App\Support\Cms\SectionRegistry::register()`; `App\Services\Cms\SitemapGenerator::extend()`;
   `App\Services\Cms\MediaService::usage()/recountUsage()`; the `website` settings group.

---

## 1. Migrations — move, then migrate

> **APPLIED 2026-09-19.** All 15 files are in `database/migrations/`, all 20 tables exist in `my_office`, and
> `database/migrations-staged/phase-04/` no longer exists. **Do not move or re-run anything here.** The list below is
> the record of what was moved, in the order it was moved.

Move these 15 files **unchanged** from `database/migrations-staged/phase-04/` to `database/migrations/`, in this order
(the timestamps already sort after Phase 3's last file `2026_09_12_071000_create_sitemap_generations_table.php`):

```
2026_09_12_080100_create_service_categories_table.php        service_categories
2026_09_12_080200_create_technologies_table.php              technologies
2026_09_12_080300_create_services_tables.php                 services, service_technology
2026_09_12_080400_create_portfolio_categories_table.php      portfolio_categories
2026_09_12_080500_create_portfolio_items_tables.php          portfolio_items, portfolio_item_technology, portfolio_item_media
2026_09_12_080600_create_team_members_table.php              team_members
2026_09_12_080700_create_testimonials_table.php              testimonials
2026_09_12_080800_create_student_reviews_table.php           student_reviews
2026_09_12_080900_create_success_stories_table.php           success_stories
2026_09_12_081000_create_blog_categories_and_tags_tables.php blog_categories, blog_tags
2026_09_12_081100_create_blog_posts_tables.php               blog_posts, blog_post_blog_tag
2026_09_12_081200_create_blog_post_views_table.php           blog_post_views (append-only, no deleted_at, D19)
2026_09_12_081300_create_job_openings_table.php              job_openings (asserts an existing `jobs` is the queue table, R1)
2026_09_12_081400_create_job_applications_table.php          job_applications
2026_09_12_081500_create_contact_inquiries_table.php         contact_inquiries
```

20 tables. They need only Phase 1 `users` and Phase 3 `media_assets`; the 14 deferred ids carry no FK (§2.1). Then,
by the verify agent only: `php artisan migrate --force` (forward only — never fresh/reset/rollback on `my_office`).
Then remove the empty `database/migrations-staged/phase-04/` directory. Phase 3's `InstallAndRollbackTest` rolls back
"Phase 3 and later" in a scratch schema, so these 15 are now part of that test — they must roll back in reverse.

---

## 2. `app/Support/PermissionRegistry.php`

> **APPLIED 2026-09-19.** The four new slugs and the widened abilities are live at
> `app/Support/PermissionRegistry.php:642-790`; `permissionNames()` returns 851 and `moduleSlugs()` 86, and the
> `modules` / `permissions` tables match. **Do not insert these blocks again.**

Additive only (D4): no ability is removed, no existing name/icon/sort changes. Result: **82 → 86 modules,
812 → 851 permissions (+39)**. `DEPENDS_ON` gains **no edge** (every Phase 4 category/tag/technology link is nullable,
so no module's rows are meaningless without another; the two existing website edges `blog_posts → blog_categories` and
`job_applications → jobs` stay).

**2.1 Insert** directly after the `'website_cta_blocks' => [ … ],` entry:

```php
            // phase-04 §4 (new slug). Sort sits in the Website range: the contract's 410 collides with
            // `collaborators` and PermissionRegistryTest::module_sort_orders_are_unique (§9 R-6).
            'service_categories' => [
                'name' => 'Service Categories',
                'group' => ModuleGroup::Website,
                'icon' => 'squares-2x2',
                'is_core' => false,
                'sort' => 838,
                'abilities' => self::merge(self::CRUD, self::STATUS),
            ],
```

**2.2 Replace** the `'services'`, `'portfolio'`, `'team'` entries' `abilities` lines, and insert the two new slugs
between them, so the block reads:

```php
            'services' => [
                'name' => 'Services',
                'group' => ModuleGroup::Website,
                'icon' => 'wrench-screwdriver',
                'is_core' => false,
                'sort' => 840,
                // phase-04 §4 pins CRUD_FULL + STATUS + FILES; export/print appended so existing rows keep their sort_order.
                'abilities' => self::merge(self::CRUD, self::STATUS, self::FILES, self::RESTORE, [Ability::Export, Ability::Print]),
            ],
            // phase-04 §4 (new slug).
            'technologies' => [
                'name' => 'Technologies',
                'group' => ModuleGroup::Website,
                'icon' => 'puzzle-piece',
                'is_core' => false,
                'sort' => 845,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::FILES),
            ],
            // phase-04 §4 (new slug).
            'portfolio_categories' => [
                'name' => 'Portfolio Categories',
                'group' => ModuleGroup::Website,
                'icon' => 'rectangle-stack',
                'is_core' => false,
                'sort' => 848,
                'abilities' => self::merge(self::CRUD, self::STATUS),
            ],
            'portfolio' => [
                'name' => 'Portfolio',
                'group' => ModuleGroup::Website,
                'icon' => 'photo',
                'is_core' => false,
                'sort' => 850,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::FILES, self::RESTORE, [Ability::Export, Ability::Print]),
            ],
            'team' => [
                'name' => 'Team',
                'group' => ModuleGroup::Website,
                'icon' => 'user-group',
                'is_core' => false,
                'sort' => 860,
                'abilities' => self::merge(self::CRUD, self::STATUS, self::FILES, self::RESTORE, [Ability::Export, Ability::Print]),
            ],
```

**2.3 Replace** the `abilities` line of `'testimonials'` and of `'student_reviews'` with:

```php
                // phase-04 §4: + FILES (the author / student photo).
                'abilities' => self::merge(self::CRUD, self::STATUS, self::APPROVE, self::RESTORE, self::FILES),
```

`'success_stories'`, `'blog_categories'`, `'jobs'`, `'job_applications'`: **no change** (they already hold every pinned
ability).

**2.4 Insert** directly after the `'blog_categories' => [ … ],` entry:

```php
            // phase-04 §4 (new slug).
            'blog_tags' => [
                'name' => 'Blog Tags',
                'group' => ModuleGroup::Website,
                'icon' => 'tag',
                'is_core' => false,
                'sort' => 915,
                'abilities' => self::merge(self::CRUD, self::STATUS),
            ],
```

**2.5 Replace** the `abilities` line of `'blog_posts'` with:

```php
                // phase-04 §4: + REPORTS (only view_reports is new; export and print were already held).
                'abilities' => self::merge(self::CRUD_FULL, self::STATUS, self::APPROVE, self::ASSIGN, self::FILES, self::RESTORE, self::REPORTS),
```

**2.6 Replace** the `abilities` line of `'contact_inquiries'` with:

```php
                // phase-04 §4: + print, + view_logs (F-12.4 — the only key to the technical / PII columns).
                'abilities' => self::merge(self::CRUD, self::STATUS, self::ASSIGN, [Ability::Export], self::RESTORE, [Ability::Print], self::LOGS),
```

The 39 new permission names: `service_categories.{view_any,view,create,edit,delete,change_status}`,
`technologies.{view_any,view,create,edit,delete,change_status,upload,download}`,
`portfolio_categories.{view_any,view,create,edit,delete,change_status}`,
`blog_tags.{view_any,view,create,edit,delete,change_status}`, `services.{export,print}`, `portfolio.{export,print}`,
`team.{export,print}`, `testimonials.{upload,download}`, `student_reviews.{upload,download}`, `blog_posts.view_reports`,
`contact_inquiries.{print,view_logs}`.

---

## 3. `app/Support/SettingsRegistry.php` — the 21 `website.*` keys

> **APPLIED 2026-09-19.** `SettingsRegistry::keys()` returns 34 `website.*` keys and the `settings` table holds 34
> `website` rows. Every `website.*` literal read anywhere under `app/` resolves to a declared key (0 undeclared), so
> `SettingsSplitTruthRegressionTest` is satisfied. **Do not add these fields again.**
>
> Six declared `website.*` keys are read only from Blade, never from `app/`: `faq_accordion_open_first`,
> `hero_video_enabled`, `image_lazy_loading`, `menu_max_depth`, `revision_keep`, `show_theme_toggle`. All six are
> Phase 3's, not Phase 4's — see §9 R-25.

**3.1** Add the import `use App\Models\User;` (beside `use App\Models\Branch;`).

**3.2** Add this method directly after `branchOptions()`:

```php
    /**
     * Active accounts for `website.inquiry_default_assignee_id` (phase-04 §5). Never throws: an install with no
     * users table yet gets an empty list.
     *
     * @return array<int|string, string>
     */
    public static function userOptions(): array
    {
        try {
            /** @var array<int|string, string> $options */
            $options = User::query()
                ->active()
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all();

            return $options;
        } catch (Throwable) {
            return [];
        }
    }
```

**3.3** In `websiteFields()`, append after the `'show_theme_toggle' => [ … ],` entry (sorts continue from 140; the
block was validated with `SettingsRegistry::` and is printed here with `self::`, as it must be inside the class):

```php
            // phase-04 §5 — the 21 keys Phase 4 contributes into this group (phase-03 §5.1b). 13 + 21 = 34.
            'services_per_page' => [
                'label' => 'Services per page',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:3', 'max:48'],
                'default' => 12,
                'public' => true,
                'span' => 4,
                'sort' => 140,
            ],
            'portfolio_per_page' => [
                'label' => 'Portfolio items per page',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:3', 'max:48'],
                'default' => 12,
                'public' => true,
                'span' => 4,
                'sort' => 150,
            ],
            'blog_per_page' => [
                'label' => 'Blog posts per page',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:3', 'max:48'],
                'default' => 9,
                'public' => true,
                'span' => 4,
                'sort' => 160,
            ],
            'blog_related_count' => [
                'label' => 'Related posts under an article',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:6'],
                'default' => 3,
                'help' => '0 hides the related-posts block.',
                'public' => true,
                'span' => 4,
                'sort' => 170,
            ],
            'blog_view_dedupe_minutes' => [
                'label' => 'Count a repeat view after',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:5', 'max:10080'],
                'default' => 1440,
                'suffix' => 'minutes',
                'help' => 'The same reader inside this window counts once. 1440 is one calendar day.',
                'span' => 4,
                'sort' => 180,
            ],
            'blog_view_prune_days' => [
                'label' => 'Keep daily view rows for',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:7', 'max:730'],
                'default' => 90,
                'suffix' => 'days',
                'help' => 'Older rows are pruned nightly. The lifetime view counter is never reduced.',
                'span' => 4,
                'sort' => 190,
            ],
            'reviews_per_page' => [
                'label' => 'Testimonials and reviews per page',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:3', 'max:48'],
                'default' => 12,
                'public' => true,
                'span' => 4,
                'sort' => 200,
            ],
            'team_page_enabled' => [
                'label' => 'Show the team page',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Off makes /team a 404.',
                'public' => true,
                'span' => 4,
                'sort' => 210,
            ],
            'portfolio_detail_enabled' => [
                'label' => 'Portfolio detail pages',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Off keeps the grid but links nowhere, and /portfolio/{slug} is a 404.',
                'public' => true,
                'span' => 4,
                'sort' => 220,
            ],
            'testimonial_auto_approve' => [
                'label' => 'Approve staff-entered testimonials automatically',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'Public and panel submissions always wait for approval, whatever this says.',
                'span' => 4,
                'sort' => 230,
            ],
            'careers_enabled' => [
                'label' => 'Careers page and applications',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Off makes /careers a 404 and refuses applications.',
                'public' => true,
                'span' => 4,
                'sort' => 240,
            ],
            'careers_notify_emails' => [
                'label' => 'Mail new applications to',
                'type' => self::TYPE_TEXTAREA,
                'rules' => ['nullable', 'string', 'max:2000', 'regex:/^[\s,]*(?:[^@\s,;]+@[^@\s,;]+\.[A-Za-z]{2,}[\s,]*)*$/'],
                'default' => null,
                'help' => 'One address per line. The CV is never attached.',
                'span' => 6,
                'sort' => 250,
            ],
            'cv_max_mb' => [
                'label' => 'Largest CV upload',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:10'],
                'default' => 5,
                'suffix' => 'MB',
                'help' => 'Never above Security → largest upload.',
                'span' => 3,
                'sort' => 260,
            ],
            'cv_allowed_types' => [
                'label' => 'Accepted CV formats',
                'type' => self::TYPE_MULTISELECT,
                'rules' => ['required', 'array', 'min:1'],
                'item_rules' => [
                    '*' => ['string', 'distinct', 'in:pdf,doc,docx'],
                ],
                'default' => ['pdf', 'doc', 'docx'],
                'options' => [
                    'pdf' => 'PDF',
                    'doc' => 'Word (.doc)',
                    'docx' => 'Word (.docx)',
                ],
                'span' => 3,
                'sort' => 270,
            ],
            'contact_notify_emails' => [
                'label' => 'Mail new inquiries to',
                'type' => self::TYPE_TEXTAREA,
                'rules' => ['nullable', 'string', 'max:2000', 'regex:/^[\s,]*(?:[^@\s,;]+@[^@\s,;]+\.[A-Za-z]{2,}[\s,]*)*$/'],
                'default' => null,
                'help' => 'One address per line, in addition to staff who can see every inquiry.',
                'span' => 6,
                'sort' => 280,
            ],
            'contact_budget_options' => [
                'label' => 'Budget choices on the contact form',
                'type' => self::TYPE_JSON,
                'rules' => ['nullable', 'array', 'max:12'],
                'item_rules' => [
                    '*' => ['string', 'max:100'],
                ],
                'default' => ['Under 50,000', '50,000 – 150,000', '150,000 – 500,000', '500,000 – 1,000,000', 'Above 1,000,000', 'Not sure yet'],
                'help' => 'A JSON list of labels. The chosen label is stored as typed, so editing the list never rewrites an inquiry.',
                'public' => true,
                'span' => 6,
                'sort' => 290,
            ],
            'contact_min_submit_seconds' => [
                'label' => 'Faster than this is a bot',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:60'],
                'default' => 3,
                'suffix' => 'seconds',
                'span' => 3,
                'sort' => 300,
            ],
            'contact_rate_per_hour' => [
                'label' => 'Contact submissions per hour per visitor',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:200'],
                'default' => 20,
                'span' => 3,
                'sort' => 310,
            ],
            'spam_blocklist' => [
                'label' => 'Spam words and domains',
                'type' => self::TYPE_TEXTAREA,
                'rules' => ['nullable', 'string', 'max:5000'],
                'default' => null,
                'help' => 'One word or domain per line, matched without regard to case against the subject and the message.',
                'span' => 6,
                'sort' => 320,
            ],
            'inquiry_auto_route' => [
                'label' => 'Route inquiries automatically',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Off leaves every inquiry waiting for a manual "Route now".',
                'span' => 3,
                'sort' => 330,
            ],
            'inquiry_default_assignee_id' => [
                'label' => 'Assign new inquiries to',
                'type' => self::TYPE_SELECT,
                'rules' => ['nullable', 'integer', 'exists:users,id'],
                'default' => null,
                'options' => [self::class, 'userOptions'],
                'help' => 'Optional. Gives every new inquiry an owner, so reviewers without the full queue still see it.',
                'span' => 3,
                'sort' => 340,
            ],
```

Change the method's docblock sentence "Phase 4 appends its 21 keys (§5.1b) to this same method" to "Phase 4's 21 keys
(§5.1b) follow them; 34 in all." Nothing else in the class changes. None of these keys is encrypted or read-only.

---

## 4. Providers, bindings, listeners, schedules, services

> **APPLIED 2026-09-19.** Verified live: `MarketingSectionTypes` exists and registers 10 section providers through
> `SectionRegistry::register()` (`AppServiceProvider:172-176`); the 7 sitemap providers are passed to
> `SitemapGenerator::extend()` (`:259-265`); `PublicFormRateLimits::register()` runs at `:242` and both named
> limiters `public-contact` / `public-apply` are the only `throttle:<name>` limiters any route uses;
> `DashboardRegistry::registerMany()` at `:245`; every `app/Events/Cms/*` and `app/Listeners/Cms/*` class appears in
> `EventListenerServiceProvider`; `Modules::MODEL_MODULES` carries the Cms models; `routes/console.php` holds the four
> `Schedule::command()` rows and the three commands are auto-discovered from `app/Console/Commands`;
> `MediaService` counts all nine Phase 4 image sources plus `portfolio_item_media`; `ComposesSite` resolves `is_live`
> providers at render (`liveProviderOutput()`). **Do not re-apply.**

No new middleware alias (`site_module` exists), no `bootstrap/**` change, no new service provider, no config file.

### 4.1 New file `app/Support/Cms/Sections/MarketingSectionTypes.php`

The nine section types Phase 4 declares into Phase 3's registry (phase-03 §6.1 table; E19 for `contact`). Verified to
normalise, `php -l` and `pint --test` clean.

```php
<?php

declare(strict_types=1);

namespace App\Support\Cms\Sections;

use App\Enums\Cms\ButtonStyle;
use App\Enums\Cms\SectionPlacement;
use App\Enums\TestimonialType;
use App\Support\Cms\SectionRegistry;

/**
 * The nine public section types phase-04 declares into Phase 3's section registry (phase-03 §6.1
 * "Section types declared by later phases", phase-04 §8.11, §13 Phase 3 row; E19 for `contact`).
 *
 * Pure arrays, like the registry itself. Registered from `AppServiceProvider::register()` through
 * `SectionRegistry::register()` — guarded by `exists()`, because the registry's runtime list is static
 * and survives from one test's application to the next.
 *
 * Every type is `is_live`: its provider is resolved at render time (a newly approved testimonial or a
 * newly published post appears without re-publishing the section) and caches itself under the D22 version
 * stamp. The option fields (`limit`, `featured_only`, `category`, `type`) are exactly the keys
 * `MarketingSectionProvider::options()` reads; `heading`, `description`, `view_all_link` and
 * `submit_label` are exactly what the partials under `site/sections/` read.
 */
final class MarketingSectionTypes
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            'services' => self::catalogue('Services', 'The published service catalogue as cards.', 'briefcase', 'business', 30, ServicesSectionProvider::class, 'What we build', 'View all services', '/services', category: true),
            'portfolio' => self::catalogue('Portfolio', 'Published case studies with their cover images.', 'photo', 'business', 50, PortfolioSectionProvider::class, 'Recent work', 'View the portfolio', '/portfolio', category: true),
            'team' => self::catalogue('Team', 'Published, public team members.', 'user-group', 'business', 60, TeamSectionProvider::class, 'Meet the team', 'Meet everyone', '/team', featured: false, limit: 8),
            'testimonials' => self::catalogue('Testimonials', 'Approved client and student testimonials only.', 'chat-bubble-left-right', 'engagement', 70, TestimonialsSectionProvider::class, 'What our clients say', null, null, testimonialType: true),
            'student_reviews' => self::catalogue('Student reviews', 'Approved student reviews only.', 'star', 'engagement', 80, StudentReviewsSectionProvider::class, 'What our students say', null, null),
            'success_stories' => self::catalogue('Success stories', 'Published student success stories.', 'trophy', 'engagement', 90, SuccessStoriesSectionProvider::class, 'Success stories', null, null),
            'blog' => self::catalogue('Blog teaser', 'The latest published blog posts.', 'newspaper', 'content', 100, BlogSectionProvider::class, 'From the blog', 'Read the blog', '/blog', category: true, limit: 3),
            'careers' => self::catalogue('Careers teaser', 'Open job openings whose deadline has not passed.', 'identification', 'business', 105, CareersSectionProvider::class, 'Join our team', 'See all openings', '/careers', limit: 4),
            'contact' => self::contact(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function catalogue(
        string $label,
        string $description,
        string $icon,
        string $group,
        int $homeSort,
        string $provider,
        string $heading,
        ?string $linkLabel,
        ?string $linkUrl,
        bool $featured = true,
        bool $category = false,
        bool $testimonialType = false,
        int $limit = 6,
    ): array {
        $fields = [
            'heading' => [
                'label' => 'Heading',
                'type' => SectionRegistry::TYPE_TEXT,
                'default' => $heading,
                'max_chars' => 120,
                'sort' => 10,
            ],
            'description' => [
                'label' => 'Short introduction',
                'type' => SectionRegistry::TYPE_TEXTAREA,
                'default' => null,
                'max_chars' => 300,
                'sort' => 20,
            ],
            'view_all_link' => [
                'label' => '"View all" button',
                'type' => SectionRegistry::TYPE_LINK,
                'default' => [
                    'label' => $linkLabel,
                    'url' => $linkUrl,
                    'style' => ButtonStyle::Outline->value,
                    'new_tab' => false,
                ],
                'help' => 'Shown only while the section has something to show.',
                'tab' => SectionRegistry::TAB_BUTTONS,
                'sort' => 30,
            ],
            'limit' => [
                'label' => 'How many to show',
                'type' => SectionRegistry::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:1', 'max:24'],
                'default' => $limit,
                'span' => 6,
                'tab' => SectionRegistry::TAB_ADVANCED,
                'sort' => 40,
            ],
        ];

        if ($featured) {
            $fields['featured_only'] = [
                'label' => 'Featured items only',
                'type' => SectionRegistry::TYPE_BOOLEAN,
                'default' => false,
                'span' => 6,
                'tab' => SectionRegistry::TAB_ADVANCED,
                'sort' => 50,
            ];
        }

        if ($category) {
            $fields['category'] = [
                'label' => 'Only this category (slug)',
                'type' => SectionRegistry::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
                'default' => null,
                'help' => 'Leave empty to show every active category.',
                'span' => 6,
                'tab' => SectionRegistry::TAB_ADVANCED,
                'sort' => 60,
            ];
        }

        if ($testimonialType) {
            $fields['type'] = [
                'label' => 'Only this kind of testimonial',
                'type' => SectionRegistry::TYPE_SELECT,
                'options' => TestimonialType::options(),
                'default' => null,
                'help' => 'Leave empty to mix clients and students.',
                'span' => 6,
                'tab' => SectionRegistry::TAB_ADVANCED,
                'sort' => 60,
            ];
        }

        return [
            'label' => $label,
            'description' => $description,
            'icon' => $icon,
            'group' => $group,
            'placements' => [
                SectionPlacement::Home->value => $homeSort,
                SectionPlacement::Page->value => $homeSort,
            ],
            'unique' => false,
            'required' => false,
            'is_live' => true,
            'provider' => $provider,
            'requirement' => 'phase-04 §8.11',
            'fields' => $fields,
            'repeaters' => [],
            'media' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function contact(): array
    {
        return [
            'label' => 'Contact form',
            'description' => 'The public inquiry form: stored in Contact inquiries and routed by type.',
            'icon' => 'envelope',
            'group' => 'engagement',
            'placements' => [
                SectionPlacement::Home->value => 130,
                SectionPlacement::Page->value => 130,
            ],
            'unique' => false,
            'required' => false,
            'is_live' => true,
            'provider' => ContactSectionProvider::class,
            'requirement' => '§17',
            'fields' => [
                'heading' => [
                    'label' => 'Heading',
                    'type' => SectionRegistry::TYPE_TEXT,
                    'default' => 'Tell us about your project',
                    'max_chars' => 120,
                    'sort' => 10,
                ],
                'description' => [
                    'label' => 'Short introduction',
                    'type' => SectionRegistry::TYPE_TEXTAREA,
                    'default' => null,
                    'max_chars' => 300,
                    'sort' => 20,
                ],
                'submit_label' => [
                    'label' => 'Button label',
                    'type' => SectionRegistry::TYPE_TEXT,
                    'default' => 'Send message',
                    'max_chars' => 40,
                    'sort' => 30,
                ],
            ],
            'repeaters' => [],
            'media' => [],
        ];
    }
}
```

### 4.2 `app/Support/Modules.php` — `MODEL_MODULES`

Imports — add:

```php
use App\Models\Cms\BlogPostBlogTag;
use App\Models\Cms\BlogPostView;
use App\Models\Cms\JobOpening;
use App\Models\Cms\PortfolioItem;
use App\Models\Cms\PortfolioItemMedia;
use App\Models\Cms\PortfolioItemTechnology;
use App\Models\Cms\ServiceTechnology;
use App\Models\Cms\TeamMember;
```

Append inside `MODEL_MODULES` after `SitemapGeneration::class => 'seo',` (verified: these eight resolve to `null` for a
class-string check today, so `Gate::before` rule 1 would never deny them while their module is off):

```php
        // phase-04: models whose class name does not pluralise into their module slug.
        PortfolioItem::class => 'portfolio',
        PortfolioItemMedia::class => 'portfolio',
        PortfolioItemTechnology::class => 'portfolio',
        ServiceTechnology::class => 'services',
        TeamMember::class => 'team',
        BlogPostView::class => 'blog_posts',
        BlogPostBlogTag::class => 'blog_posts',
        JobOpening::class => 'jobs',
```

### 4.3 `app/Providers/AppServiceProvider.php`

Imports — add (alphabetical into the existing list; `Throwable` is already imported):

```php
use App\Dashboard\Cms\BlogActivityWidget;
use App\Dashboard\Cms\InquiryRoutingBacklogWidget;
use App\Dashboard\Cms\NewApplicationsWidget;
use App\Dashboard\Cms\NewInquiriesWidget;
use App\Dashboard\Cms\OpenJobsWidget;
use App\Dashboard\Cms\PendingModerationWidget;
use App\Dashboard\Cms\TopViewedPostsWidget;
use App\Models\Cms\BlogCategory;
use App\Models\Cms\BlogPost;
use App\Models\Cms\BlogTag;
use App\Models\Cms\ContactInquiry;
use App\Models\Cms\JobApplication;
use App\Models\Cms\JobOpening;
use App\Models\Cms\PortfolioCategory;
use App\Models\Cms\PortfolioItem;
use App\Models\Cms\Service;
use App\Models\Cms\ServiceCategory;
use App\Models\Cms\StudentReview;
use App\Models\Cms\SuccessStory;
use App\Models\Cms\TeamMember;
use App\Models\Cms\Technology;
use App\Models\Cms\Testimonial;
use App\Policies\Cms\BlogCategoryPolicy;
use App\Policies\Cms\BlogPostPolicy;
use App\Policies\Cms\BlogTagPolicy;
use App\Policies\Cms\ContactInquiryPolicy;
use App\Policies\Cms\JobApplicationPolicy;
use App\Policies\Cms\JobOpeningPolicy;
use App\Policies\Cms\PortfolioCategoryPolicy;
use App\Policies\Cms\PortfolioItemPolicy;
use App\Policies\Cms\ServiceCategoryPolicy;
use App\Policies\Cms\ServicePolicy;
use App\Policies\Cms\StudentReviewPolicy;
use App\Policies\Cms\SuccessStoryPolicy;
use App\Policies\Cms\TeamMemberPolicy;
use App\Policies\Cms\TechnologyPolicy;
use App\Policies\Cms\TestimonialPolicy;
use App\Services\Cms\InquiryRouter;
use App\Services\Cms\SitemapGenerator;
use App\Support\Cms\PublicFormRateLimits;
use App\Support\Cms\SectionRegistry;
use App\Support\Cms\Sections\MarketingSectionTypes;
use App\Support\Cms\Sitemap\BlogCategorySitemapProvider;
use App\Support\Cms\Sitemap\BlogPostSitemapProvider;
use App\Support\Cms\Sitemap\BlogTagSitemapProvider;
use App\Support\Cms\Sitemap\JobOpeningSitemapProvider;
use App\Support\Cms\Sitemap\PortfolioSitemapProvider;
use App\Support\Cms\Sitemap\ServiceSitemapProvider;
use App\Support\Cms\Sitemap\TeamSitemapProvider;
use App\Support\DashboardRegistry;
```

`POLICIES` — append after `SitemapGeneration::class => SitemapGenerationPolicy::class,`:

```php

        // phase-04 §6.11: App\Models\Cms\X → App\Policies\Cms\XPolicy is not a discovery path, so all 15 are explicit.
        ServiceCategory::class => ServiceCategoryPolicy::class,
        Service::class => ServicePolicy::class,
        Technology::class => TechnologyPolicy::class,
        PortfolioCategory::class => PortfolioCategoryPolicy::class,
        PortfolioItem::class => PortfolioItemPolicy::class,
        TeamMember::class => TeamMemberPolicy::class,
        Testimonial::class => TestimonialPolicy::class,
        StudentReview::class => StudentReviewPolicy::class,
        SuccessStory::class => SuccessStoryPolicy::class,
        BlogCategory::class => BlogCategoryPolicy::class,
        BlogTag::class => BlogTagPolicy::class,
        BlogPost::class => BlogPostPolicy::class,
        JobOpening::class => JobOpeningPolicy::class,
        JobApplication::class => JobApplicationPolicy::class,
        ContactInquiry::class => ContactInquiryPolicy::class,
```

`register()` — append at the end of the method:

```php

        // phase-04 §6.10.1: one router per process, so a target Phase 5 / 14-17 registers is the one routing sees.
        $this->app->singleton(InquiryRouter::class);

        // phase-04 §8.11 / phase-03 §6.1: the nine public section types. Guarded, because the registry's runtime list
        // is static and outlives one application instance (every test boots a fresh one; a second register() throws).
        $knownSectionTypes = SectionRegistry::keys();

        foreach (MarketingSectionTypes::definitions() as $key => $definition) {
            if (! in_array($key, $knownSectionTypes, true)) {
                SectionRegistry::register($key, $definition);
            }
        }
```

`boot()` — insert `$this->registerPhase04();` on the line **before** `$this->configureFromSettings();`, and add the
method after `configureFromSettings()`:

```php
    /**
     * phase-04: the two public-form rate limiters (§6.9 — registered here, there is no RateLimitServiceProvider), the
     * seven content dashboard widgets (§8.12, explicit because they live in app/Dashboard/Cms, not the discovery path)
     * and one sitemap provider per public entity (§13 Phase 3 row, D23 — SitemapRegistry does not exist, extend() is
     * Phase 3's seam). Each piece degrades on its own.
     */
    private function registerPhase04(): void
    {
        PublicFormRateLimits::register();

        try {
            DashboardRegistry::registerMany([
                NewInquiriesWidget::class,
                InquiryRoutingBacklogWidget::class,
                PendingModerationWidget::class,
                NewApplicationsWidget::class,
                OpenJobsWidget::class,
                BlogActivityWidget::class,
                TopViewedPostsWidget::class,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }

        foreach ([
            new ServiceSitemapProvider,
            new PortfolioSitemapProvider,
            new BlogPostSitemapProvider,
            new BlogCategorySitemapProvider,
            new BlogTagSitemapProvider,
            new JobOpeningSitemapProvider,
            new TeamSitemapProvider,
        ] as $provider) {
            SitemapGenerator::extend($provider->key(), $provider);
        }
    }
```

### 4.4 `app/Providers/EventListenerServiceProvider.php` — listener map (event discovery is off)

Imports — add:

```php
use App\Events\Cms\BlogPostPublished;
use App\Events\Cms\ContactInquiryRouted;
use App\Events\Cms\ContactInquirySubmitted;
use App\Events\Cms\JobApplicationReceived;
use App\Events\Cms\JobApplicationStatusChanged;
use App\Events\Cms\StudentReviewApproved;
use App\Events\Cms\TestimonialApproved;
use App\Events\Cms\TestimonialSubmitted;
use App\Listeners\Cms\FlushPublicContentCache;
use App\Listeners\Cms\LogApplicationStage;
use App\Listeners\Cms\LogInquiryRouting;
use App\Listeners\Cms\NotifyAuthorOfPublication;
use App\Listeners\Cms\NotifyHrOfApplication;
use App\Listeners\Cms\NotifyStaffOfInquiry;
use App\Listeners\Cms\NotifyStaffOfPendingModeration;
use App\Listeners\Cms\PingSitemap;
use App\Listeners\Cms\RouteContactInquiry;
```

Append inside `LISTENERS` after `Logout::class => [RecordLogout::class],`:

```php

        // phase-04 §10.1. LogInquiryRouting and LogApplicationStage write the only "routed" / "stage changed"
        // activity entries (§11 tests 38, 44) — without this map both tests fail.
        ContactInquirySubmitted::class => [RouteContactInquiry::class, NotifyStaffOfInquiry::class],
        ContactInquiryRouted::class => [LogInquiryRouting::class],
        JobApplicationReceived::class => [NotifyHrOfApplication::class],
        JobApplicationStatusChanged::class => [LogApplicationStage::class],
        TestimonialApproved::class => [FlushPublicContentCache::class],
        StudentReviewApproved::class => [FlushPublicContentCache::class],
        TestimonialSubmitted::class => [NotifyStaffOfPendingModeration::class],
        BlogPostPublished::class => [NotifyAuthorOfPublication::class, FlushPublicContentCache::class, PingSitemap::class],
```

(Verified: these are exactly the nine files in `app/Listeners/Cms`; every event in `app/Events/Cms` has a listener.)

### 4.5 `routes/console.php` — scheduled tasks (§10.4)

Add `use App\Models\Cms\BlogPostView;` to the imports, and append at the end of the file:

```php

/*
|--------------------------------------------------------------------------
| Services, portfolio, blog, careers, inquiries (phase-04 §10.4)
|--------------------------------------------------------------------------
| The three commands are auto-discovered from app/Console/Commands. Times are UTC (D61).
*/

Schedule::command('blog:publish-scheduled')->everyMinute()->withoutOverlapping(5)->runInBackground();
Schedule::command('inquiries:route-pending')->hourly()->withoutOverlapping(10);
Schedule::command('careers:close-expired')->dailyAt('00:10');
Schedule::command('model:prune', ['--model' => [BlogPostView::class]])->dailyAt('02:30');
```

### 4.6 `app/Services/Cms/MediaService.php` — count the Phase 4 image sources (D24, §13 Phase 3 row; §11 test 13)

Add both constants at the top of the class body:

```php
    /**
     * phase-04 (D24): [table, media column, usage type, label column, detail]. Owners in the trash do not count.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private const PHASE4_MEDIA_SOURCES = [
        ['service_categories', 'image_media_id', 'service_category', 'name', 'image'],
        ['services', 'image_media_id', 'service', 'name', 'image'],
        ['technologies', 'logo_media_id', 'technology', 'name', 'logo'],
        ['portfolio_categories', 'image_media_id', 'portfolio_category', 'name', 'image'],
        ['portfolio_items', 'cover_media_id', 'portfolio_item', 'title', 'cover'],
        ['team_members', 'photo_media_id', 'team_member', 'name', 'photo'],
        ['testimonials', 'author_photo_media_id', 'testimonial', 'author_name', 'author photo'],
        ['student_reviews', 'student_photo_media_id', 'student_review', 'student_name', 'student photo'],
        ['success_stories', 'photo_media_id', 'success_story', 'student_name', 'photo'],
        ['blog_categories', 'image_media_id', 'blog_category', 'name', 'image'],
        ['blog_posts', 'featured_image_media_id', 'blog_post', 'title', 'featured image'],
    ];

    /**
     * phase-04 (D24): rich-text columns that may embed a library image. [table, columns, usage type, label column].
     *
     * @var list<array{0: string, 1: list<string>, 2: string, 3: string}>
     */
    private const PHASE4_RICH_TEXT = [
        ['services', ['full_description'], 'service', 'name'],
        ['portfolio_items', ['description'], 'portfolio_item', 'title'],
        ['blog_posts', ['content'], 'blog_post', 'title'],
        ['job_openings', ['description', 'requirements', 'responsibilities'], 'job_opening', 'title'],
        ['success_stories', ['story'], 'success_story', 'student_name'],
    ];
```

In `usage()`, directly after the `seo_meta` `foreach` (before `if ($token !== '' …`):

```php

        $schema = $connection->getSchemaBuilder();

        foreach (self::PHASE4_MEDIA_SOURCES as [$table, $column, $type, $labelColumn, $detail]) {
            if (! $schema->hasTable($table)) {
                continue;
            }

            foreach ($connection->table($table)->whereNull('deleted_at')->where($column, $id)->get(['id', $labelColumn]) as $row) {
                $add($type, (int) $row->id, (string) $row->{$labelColumn}, $detail);
            }
        }

        if ($schema->hasTable('portfolio_item_media')) {
            foreach ($connection->table('portfolio_item_media as m')
                ->join('portfolio_items as p', 'p.id', '=', 'm.portfolio_item_id')
                ->whereNull('p.deleted_at')
                ->where('m.media_asset_id', $id)
                ->get(['p.id', 'p.title']) as $row) {
                $add('portfolio_item', (int) $row->id, (string) $row->title, 'gallery');
            }
        }
```

In `usage()`, inside `if ($token !== '' && $token !== '.') {`, after the `faqs` block:

```php

            foreach (self::PHASE4_RICH_TEXT as [$table, $columns, $type, $labelColumn]) {
                if (! $connection->getSchemaBuilder()->hasTable($table)) {
                    continue;
                }

                foreach ($connection->table($table)->whereNull('deleted_at')
                    ->where(function ($query) use ($columns, $like): void {
                        foreach ($columns as $column) {
                            $query->orWhere($column, 'like', $like);
                        }
                    })
                    ->get(['id', $labelColumn]) as $row) {
                    $add($type, (int) $row->id, (string) $row->{$labelColumn}, 'embedded in the text');
                }
            }
```

In `recountUsage()`, directly after the `seo_meta` `foreach` (before `$byToken = …`):

```php

        $schema = $connection->getSchemaBuilder();

        foreach (self::PHASE4_MEDIA_SOURCES as [$table, $column, $type]) {
            if (! $schema->hasTable($table)) {
                continue;
            }

            foreach ($connection->table($table)->whereNull('deleted_at')->whereNotNull($column)->cursor(['id', $column]) as $row) {
                $mark((int) $row->{$column}, $type.':'.$row->id);
            }
        }

        if ($schema->hasTable('portfolio_item_media')) {
            foreach ($connection->table('portfolio_item_media as m')
                ->join('portfolio_items as p', 'p.id', '=', 'm.portfolio_item_id')
                ->whereNull('p.deleted_at')
                ->cursor(['m.media_asset_id', 'm.portfolio_item_id']) as $row) {
                $mark((int) $row->media_asset_id, 'portfolio_item:'.$row->portfolio_item_id);
            }
        }
```

In `recountUsage()`, after `$scan('faqs', ['answer'], 'faq');`:

```php

        foreach (self::PHASE4_RICH_TEXT as [$table, $columns, $type]) {
            $scan($table, $columns, $type);
        }
```

A cover that is also in the gallery, or an image embedded in the post it features, counts once (`type:id` is one
place — "usage counts places, not references").

### 4.7 `app/Http/Controllers/Site/Concerns/ComposesSite.php` — resolve `is_live` providers at render time

Phase 3 promised it (phase-03 §6.1: a provider runs "at render time for types marked `is_live => true`") but did not
build it: `SnapshotBuilder::provider()` skips live types and nothing on the render path calls them, so every Phase 4
section would render empty. In `usableSection()`, insert between the closing `]);` of `$section = array_merge(…)` and
the `try { View::make(…)` probe:

```php

        // phase-03 §6.1 [D-W3-11]: a live type's data is resolved per render (the provider caches itself under the
        // D22 version stamp). The provider reads its options from the snapshot's `fields`, so an unsaved model
        // carrying the snapshot is all it needs — no query here.
        if (SectionRegistry::isLive($key)) {
            $providerClass = SectionRegistry::provider($key);

            try {
                $provider = $providerClass !== null && class_exists($providerClass) ? app($providerClass) : null;
                $model = (new WebsiteSection)->forceFill(['id' => $id, 'section_key' => $key, 'published_content' => $snapshot]);
                $section['provider'] = $provider !== null && method_exists($provider, 'resolve') ? $provider->resolve($model) : null;
            } catch (Throwable $exception) {
                $this->warn($id, $key, 'live section provider threw', $exception);
                $section['provider'] = null;
            }
        }
```

(`SectionRegistry`, `WebsiteSection`, `Throwable` are already imported there.) This also covers the draft preview,
which goes through the same method. The provider-side half is §9 R-4.

---

## 5. Routes

> **APPLIED 2026-09-19.** All 168 rows are live. Re-measured from `php artisan route:list --json` against
> `PermissionRegistry::permissionNames()` and the classes on disk:
>
> | Cross-check | Result |
> |---|---|
> | phase-4 routes registered | 168 (153 `admin.*`, 15 `site.*`) |
> | every `can:<perm>` ∈ `PermissionRegistry::permissionNames()` | 0 unknown (whole app, 319 routes) |
> | every `module:` / `site_module:` slug ∈ `moduleSlugs()` | 0 unknown |
> | every action `Class@method` exists | 0 missing |
> | every `admin.*` phase-4 route carries `auth` + `active` + `panel:admin` + exactly one `module:` + one `can:` | 0 violations |
> | no `site.*` phase-4 route carries `can:` (INV-15) | 0 violations |
> | every `site.*` phase-4 route carries `site`; each carries `site_module:` except `site.contact.*` | only `site.blog.preview` has no `site_module:` — deliberate, see §9 R-21 |
> | `throttle:` limiters used anywhere | exactly `public-contact`, `public-apply` — both registered by §4 |
>
> **Do not paste these route blocks again.**

Every `can:` below is a permission of §2 (verified: 0 unknown). Every `controller@method` exists (verified). Restore
routes are deliberately absent (§9 R-9).

### 5.1 `routes/admin.php`

Imports — add:

```php
use App\Http\Controllers\Admin\Cms\BlogCategoryController;
use App\Http\Controllers\Admin\Cms\BlogPostController;
use App\Http\Controllers\Admin\Cms\BlogTagController;
use App\Http\Controllers\Admin\Cms\ContactInquiryController;
use App\Http\Controllers\Admin\Cms\JobApplicationController;
use App\Http\Controllers\Admin\Cms\JobOpeningController;
use App\Http\Controllers\Admin\Cms\PortfolioCategoryController;
use App\Http\Controllers\Admin\Cms\PortfolioImageController;
use App\Http\Controllers\Admin\Cms\PortfolioItemController;
use App\Http\Controllers\Admin\Cms\ServiceCategoryController;
use App\Http\Controllers\Admin\Cms\ServiceController;
use App\Http\Controllers\Admin\Cms\StudentReviewController;
use App\Http\Controllers\Admin\Cms\SuccessStoryController;
use App\Http\Controllers\Admin\Cms\TeamMemberController;
use App\Http\Controllers\Admin\Cms\TechnologyController;
use App\Http\Controllers\Admin\Cms\TestimonialController;
```

Paste at the end of the `Route::prefix('admin')->name('admin.')->middleware(['auth', 'active', 'panel:admin'])`
group body, after the `Route::prefix('website')…` block:

```php
        /*
        |------------------------------------------------------------------
        | phase-04 §7.2 — software-house marketing modules
        |------------------------------------------------------------------
        | One `module:` per block and exactly one `can:` per route. Literal segments (create, export,
        | reorder, calendar, bulk-approve, route-pending) are declared before their {parameter} sibling
        | and every parameter is whereNumber. No restore routes (§7.2 last paragraph).
        */

        // §8.1 Service categories
        Route::middleware('module:service_categories')->group(function (): void {
            Route::get('service-categories', [ServiceCategoryController::class, 'index'])->middleware('can:service_categories.view_any')->name('service-categories.index');
            Route::get('service-categories/create', [ServiceCategoryController::class, 'create'])->middleware('can:service_categories.create')->name('service-categories.create');
            Route::post('service-categories', [ServiceCategoryController::class, 'store'])->middleware('can:service_categories.create')->name('service-categories.store');
            Route::post('service-categories/reorder', [ServiceCategoryController::class, 'reorder'])->middleware('can:service_categories.edit')->name('service-categories.reorder');
            Route::get('service-categories/{term}', [ServiceCategoryController::class, 'show'])->whereNumber('term')->middleware('can:service_categories.view')->name('service-categories.show');
            Route::get('service-categories/{term}/edit', [ServiceCategoryController::class, 'edit'])->whereNumber('term')->middleware('can:service_categories.edit')->name('service-categories.edit');
            Route::put('service-categories/{term}', [ServiceCategoryController::class, 'update'])->whereNumber('term')->middleware('can:service_categories.edit')->name('service-categories.update');
            Route::post('service-categories/{term}/toggle', [ServiceCategoryController::class, 'toggle'])->whereNumber('term')->middleware('can:service_categories.change_status')->name('service-categories.toggle');
            Route::delete('service-categories/{term}', [ServiceCategoryController::class, 'destroy'])->whereNumber('term')->middleware('can:service_categories.delete')->name('service-categories.destroy');
        });

        // §8.1 Technologies
        Route::middleware('module:technologies')->group(function (): void {
            Route::get('technologies', [TechnologyController::class, 'index'])->middleware('can:technologies.view_any')->name('technologies.index');
            Route::get('technologies/create', [TechnologyController::class, 'create'])->middleware('can:technologies.create')->name('technologies.create');
            Route::post('technologies', [TechnologyController::class, 'store'])->middleware('can:technologies.create')->name('technologies.store');
            Route::get('technologies/{term}', [TechnologyController::class, 'show'])->whereNumber('term')->middleware('can:technologies.view')->name('technologies.show');
            Route::get('technologies/{term}/edit', [TechnologyController::class, 'edit'])->whereNumber('term')->middleware('can:technologies.edit')->name('technologies.edit');
            Route::put('technologies/{term}', [TechnologyController::class, 'update'])->whereNumber('term')->middleware('can:technologies.edit')->name('technologies.update');
            Route::post('technologies/{term}/toggle', [TechnologyController::class, 'toggle'])->whereNumber('term')->middleware('can:technologies.change_status')->name('technologies.toggle');
            Route::delete('technologies/{term}', [TechnologyController::class, 'destroy'])->whereNumber('term')->middleware('can:technologies.delete')->name('technologies.destroy');
        });

        // §8.1 Portfolio categories
        Route::middleware('module:portfolio_categories')->group(function (): void {
            Route::get('portfolio-categories', [PortfolioCategoryController::class, 'index'])->middleware('can:portfolio_categories.view_any')->name('portfolio-categories.index');
            Route::get('portfolio-categories/create', [PortfolioCategoryController::class, 'create'])->middleware('can:portfolio_categories.create')->name('portfolio-categories.create');
            Route::post('portfolio-categories', [PortfolioCategoryController::class, 'store'])->middleware('can:portfolio_categories.create')->name('portfolio-categories.store');
            Route::post('portfolio-categories/reorder', [PortfolioCategoryController::class, 'reorder'])->middleware('can:portfolio_categories.edit')->name('portfolio-categories.reorder');
            Route::get('portfolio-categories/{term}', [PortfolioCategoryController::class, 'show'])->whereNumber('term')->middleware('can:portfolio_categories.view')->name('portfolio-categories.show');
            Route::get('portfolio-categories/{term}/edit', [PortfolioCategoryController::class, 'edit'])->whereNumber('term')->middleware('can:portfolio_categories.edit')->name('portfolio-categories.edit');
            Route::put('portfolio-categories/{term}', [PortfolioCategoryController::class, 'update'])->whereNumber('term')->middleware('can:portfolio_categories.edit')->name('portfolio-categories.update');
            Route::post('portfolio-categories/{term}/toggle', [PortfolioCategoryController::class, 'toggle'])->whereNumber('term')->middleware('can:portfolio_categories.change_status')->name('portfolio-categories.toggle');
            Route::delete('portfolio-categories/{term}', [PortfolioCategoryController::class, 'destroy'])->whereNumber('term')->middleware('can:portfolio_categories.delete')->name('portfolio-categories.destroy');
        });

        // §8.1 Blog categories
        Route::middleware('module:blog_categories')->group(function (): void {
            Route::get('blog-categories', [BlogCategoryController::class, 'index'])->middleware('can:blog_categories.view_any')->name('blog-categories.index');
            Route::get('blog-categories/create', [BlogCategoryController::class, 'create'])->middleware('can:blog_categories.create')->name('blog-categories.create');
            Route::post('blog-categories', [BlogCategoryController::class, 'store'])->middleware('can:blog_categories.create')->name('blog-categories.store');
            Route::get('blog-categories/{term}', [BlogCategoryController::class, 'show'])->whereNumber('term')->middleware('can:blog_categories.view')->name('blog-categories.show');
            Route::get('blog-categories/{term}/edit', [BlogCategoryController::class, 'edit'])->whereNumber('term')->middleware('can:blog_categories.edit')->name('blog-categories.edit');
            Route::put('blog-categories/{term}', [BlogCategoryController::class, 'update'])->whereNumber('term')->middleware('can:blog_categories.edit')->name('blog-categories.update');
            Route::post('blog-categories/{term}/toggle', [BlogCategoryController::class, 'toggle'])->whereNumber('term')->middleware('can:blog_categories.change_status')->name('blog-categories.toggle');
            Route::delete('blog-categories/{term}', [BlogCategoryController::class, 'destroy'])->whereNumber('term')->middleware('can:blog_categories.delete')->name('blog-categories.destroy');
        });

        // §8.1 Blog tags
        Route::middleware('module:blog_tags')->group(function (): void {
            Route::get('blog-tags', [BlogTagController::class, 'index'])->middleware('can:blog_tags.view_any')->name('blog-tags.index');
            Route::get('blog-tags/create', [BlogTagController::class, 'create'])->middleware('can:blog_tags.create')->name('blog-tags.create');
            Route::post('blog-tags', [BlogTagController::class, 'store'])->middleware('can:blog_tags.create')->name('blog-tags.store');
            Route::get('blog-tags/{term}', [BlogTagController::class, 'show'])->whereNumber('term')->middleware('can:blog_tags.view')->name('blog-tags.show');
            Route::get('blog-tags/{term}/edit', [BlogTagController::class, 'edit'])->whereNumber('term')->middleware('can:blog_tags.edit')->name('blog-tags.edit');
            Route::put('blog-tags/{term}', [BlogTagController::class, 'update'])->whereNumber('term')->middleware('can:blog_tags.edit')->name('blog-tags.update');
            Route::post('blog-tags/{term}/toggle', [BlogTagController::class, 'toggle'])->whereNumber('term')->middleware('can:blog_tags.change_status')->name('blog-tags.toggle');
            Route::delete('blog-tags/{term}', [BlogTagController::class, 'destroy'])->whereNumber('term')->middleware('can:blog_tags.delete')->name('blog-tags.destroy');
        });

        // §8.2 Services
        Route::middleware('module:services')->group(function (): void {
            Route::get('services', [ServiceController::class, 'index'])->middleware('can:services.view_any')->name('services.index');
            Route::get('services/export', [ServiceController::class, 'export'])->middleware('can:services.export')->name('services.export');
            Route::get('services/create', [ServiceController::class, 'create'])->middleware('can:services.create')->name('services.create');
            Route::post('services', [ServiceController::class, 'store'])->middleware('can:services.create')->name('services.store');
            Route::post('services/reorder', [ServiceController::class, 'reorder'])->middleware('can:services.edit')->name('services.reorder');
            Route::get('services/{service}', [ServiceController::class, 'show'])->whereNumber('service')->middleware('can:services.view')->name('services.show');
            Route::get('services/{service}/edit', [ServiceController::class, 'edit'])->whereNumber('service')->middleware('can:services.edit')->name('services.edit');
            Route::put('services/{service}', [ServiceController::class, 'update'])->whereNumber('service')->middleware('can:services.edit')->name('services.update');
            Route::delete('services/{service}', [ServiceController::class, 'destroy'])->whereNumber('service')->middleware('can:services.delete')->name('services.destroy');
            Route::post('services/{service}/status', [ServiceController::class, 'status'])->whereNumber('service')->middleware('can:services.change_status')->name('services.status');
            Route::post('services/{service}/featured', [ServiceController::class, 'featured'])->whereNumber('service')->middleware('can:services.change_status')->name('services.featured');
        });

        // §8.3 Portfolio + gallery manager
        Route::middleware('module:portfolio')->group(function (): void {
            Route::get('portfolio', [PortfolioItemController::class, 'index'])->middleware('can:portfolio.view_any')->name('portfolio.index');
            Route::get('portfolio/create', [PortfolioItemController::class, 'create'])->middleware('can:portfolio.create')->name('portfolio.create');
            Route::post('portfolio', [PortfolioItemController::class, 'store'])->middleware('can:portfolio.create')->name('portfolio.store');
            Route::post('portfolio/reorder', [PortfolioItemController::class, 'reorder'])->middleware('can:portfolio.edit')->name('portfolio.reorder');
            Route::get('portfolio/{item}', [PortfolioItemController::class, 'show'])->whereNumber('item')->middleware('can:portfolio.view')->name('portfolio.show');
            Route::get('portfolio/{item}/edit', [PortfolioItemController::class, 'edit'])->whereNumber('item')->middleware('can:portfolio.edit')->name('portfolio.edit');
            Route::put('portfolio/{item}', [PortfolioItemController::class, 'update'])->whereNumber('item')->middleware('can:portfolio.edit')->name('portfolio.update');
            Route::delete('portfolio/{item}', [PortfolioItemController::class, 'destroy'])->whereNumber('item')->middleware('can:portfolio.delete')->name('portfolio.destroy');
            Route::post('portfolio/{item}/status', [PortfolioItemController::class, 'status'])->whereNumber('item')->middleware('can:portfolio.change_status')->name('portfolio.status');
            Route::post('portfolio/{item}/featured', [PortfolioItemController::class, 'featured'])->whereNumber('item')->middleware('can:portfolio.change_status')->name('portfolio.featured');
            Route::post('portfolio/{item}/images', [PortfolioImageController::class, 'store'])->whereNumber('item')->middleware('can:portfolio.upload')->name('portfolio.images.store');
            Route::post('portfolio/{item}/images/reorder', [PortfolioImageController::class, 'reorder'])->whereNumber('item')->middleware('can:portfolio.edit')->name('portfolio.images.reorder');
            Route::post('portfolio/{item}/images/{image}/cover', [PortfolioImageController::class, 'cover'])->whereNumber(['item', 'image'])->middleware('can:portfolio.edit')->name('portfolio.images.cover');
            Route::put('portfolio/{item}/images/{image}', [PortfolioImageController::class, 'caption'])->whereNumber(['item', 'image'])->middleware('can:portfolio.edit')->name('portfolio.images.update');
            Route::delete('portfolio/{item}/images/{image}', [PortfolioImageController::class, 'destroy'])->whereNumber(['item', 'image'])->middleware('can:portfolio.edit')->name('portfolio.images.destroy');
        });

        // §8.4 Team
        Route::middleware('module:team')->group(function (): void {
            Route::get('team', [TeamMemberController::class, 'index'])->middleware('can:team.view_any')->name('team.index');
            Route::get('team/create', [TeamMemberController::class, 'create'])->middleware('can:team.create')->name('team.create');
            Route::post('team', [TeamMemberController::class, 'store'])->middleware('can:team.create')->name('team.store');
            Route::post('team/reorder', [TeamMemberController::class, 'reorder'])->middleware('can:team.edit')->name('team.reorder');
            Route::get('team/{member}', [TeamMemberController::class, 'show'])->whereNumber('member')->middleware('can:team.view')->name('team.show');
            Route::get('team/{member}/edit', [TeamMemberController::class, 'edit'])->whereNumber('member')->middleware('can:team.edit')->name('team.edit');
            Route::put('team/{member}', [TeamMemberController::class, 'update'])->whereNumber('member')->middleware('can:team.edit')->name('team.update');
            Route::delete('team/{member}', [TeamMemberController::class, 'destroy'])->whereNumber('member')->middleware('can:team.delete')->name('team.destroy');
            Route::post('team/{member}/status', [TeamMemberController::class, 'status'])->whereNumber('member')->middleware('can:team.change_status')->name('team.status');
            Route::post('team/{member}/visibility', [TeamMemberController::class, 'visibility'])->whereNumber('member')->middleware('can:team.change_status')->name('team.visibility');
        });

        // §8.5 Testimonials (moderation queue)
        Route::middleware('module:testimonials')->group(function (): void {
            Route::get('testimonials', [TestimonialController::class, 'index'])->middleware('can:testimonials.view_any')->name('testimonials.index');
            Route::get('testimonials/create', [TestimonialController::class, 'create'])->middleware('can:testimonials.create')->name('testimonials.create');
            Route::post('testimonials', [TestimonialController::class, 'store'])->middleware('can:testimonials.create')->name('testimonials.store');
            Route::post('testimonials/bulk-approve', [TestimonialController::class, 'bulkApprove'])->middleware('can:testimonials.approve')->name('testimonials.bulk-approve');
            Route::get('testimonials/{testimonial}', [TestimonialController::class, 'show'])->whereNumber('testimonial')->middleware('can:testimonials.view')->name('testimonials.show');
            Route::get('testimonials/{testimonial}/edit', [TestimonialController::class, 'edit'])->whereNumber('testimonial')->middleware('can:testimonials.edit')->name('testimonials.edit');
            Route::put('testimonials/{testimonial}', [TestimonialController::class, 'update'])->whereNumber('testimonial')->middleware('can:testimonials.edit')->name('testimonials.update');
            Route::delete('testimonials/{testimonial}', [TestimonialController::class, 'destroy'])->whereNumber('testimonial')->middleware('can:testimonials.delete')->name('testimonials.destroy');
            Route::post('testimonials/{testimonial}/approve', [TestimonialController::class, 'approve'])->whereNumber('testimonial')->middleware('can:testimonials.approve')->name('testimonials.approve');
            Route::post('testimonials/{testimonial}/reject', [TestimonialController::class, 'reject'])->whereNumber('testimonial')->middleware('can:testimonials.reject')->name('testimonials.reject');
            Route::post('testimonials/{testimonial}/featured', [TestimonialController::class, 'featured'])->whereNumber('testimonial')->middleware('can:testimonials.change_status')->name('testimonials.featured');
        });

        // §8.5 Student reviews (moderation queue)
        Route::middleware('module:student_reviews')->group(function (): void {
            Route::get('student-reviews', [StudentReviewController::class, 'index'])->middleware('can:student_reviews.view_any')->name('student-reviews.index');
            Route::get('student-reviews/create', [StudentReviewController::class, 'create'])->middleware('can:student_reviews.create')->name('student-reviews.create');
            Route::post('student-reviews', [StudentReviewController::class, 'store'])->middleware('can:student_reviews.create')->name('student-reviews.store');
            Route::post('student-reviews/bulk-approve', [StudentReviewController::class, 'bulkApprove'])->middleware('can:student_reviews.approve')->name('student-reviews.bulk-approve');
            Route::get('student-reviews/{review}', [StudentReviewController::class, 'show'])->whereNumber('review')->middleware('can:student_reviews.view')->name('student-reviews.show');
            Route::get('student-reviews/{review}/edit', [StudentReviewController::class, 'edit'])->whereNumber('review')->middleware('can:student_reviews.edit')->name('student-reviews.edit');
            Route::put('student-reviews/{review}', [StudentReviewController::class, 'update'])->whereNumber('review')->middleware('can:student_reviews.edit')->name('student-reviews.update');
            Route::delete('student-reviews/{review}', [StudentReviewController::class, 'destroy'])->whereNumber('review')->middleware('can:student_reviews.delete')->name('student-reviews.destroy');
            Route::post('student-reviews/{review}/approve', [StudentReviewController::class, 'approve'])->whereNumber('review')->middleware('can:student_reviews.approve')->name('student-reviews.approve');
            Route::post('student-reviews/{review}/reject', [StudentReviewController::class, 'reject'])->whereNumber('review')->middleware('can:student_reviews.reject')->name('student-reviews.reject');
            Route::post('student-reviews/{review}/featured', [StudentReviewController::class, 'featured'])->whereNumber('review')->middleware('can:student_reviews.change_status')->name('student-reviews.featured');
        });

        // §8.6 Success stories
        Route::middleware('module:success_stories')->group(function (): void {
            Route::get('success-stories', [SuccessStoryController::class, 'index'])->middleware('can:success_stories.view_any')->name('success-stories.index');
            Route::get('success-stories/create', [SuccessStoryController::class, 'create'])->middleware('can:success_stories.create')->name('success-stories.create');
            Route::post('success-stories', [SuccessStoryController::class, 'store'])->middleware('can:success_stories.create')->name('success-stories.store');
            Route::post('success-stories/reorder', [SuccessStoryController::class, 'reorder'])->middleware('can:success_stories.edit')->name('success-stories.reorder');
            Route::get('success-stories/{story}', [SuccessStoryController::class, 'show'])->whereNumber('story')->middleware('can:success_stories.view')->name('success-stories.show');
            Route::get('success-stories/{story}/edit', [SuccessStoryController::class, 'edit'])->whereNumber('story')->middleware('can:success_stories.edit')->name('success-stories.edit');
            Route::put('success-stories/{story}', [SuccessStoryController::class, 'update'])->whereNumber('story')->middleware('can:success_stories.edit')->name('success-stories.update');
            Route::delete('success-stories/{story}', [SuccessStoryController::class, 'destroy'])->whereNumber('story')->middleware('can:success_stories.delete')->name('success-stories.destroy');
            Route::post('success-stories/{story}/status', [SuccessStoryController::class, 'status'])->whereNumber('story')->middleware('can:success_stories.change_status')->name('success-stories.status');
            Route::post('success-stories/{story}/featured', [SuccessStoryController::class, 'featured'])->whereNumber('story')->middleware('can:success_stories.change_status')->name('success-stories.featured');
        });

        // §8.7 Blog posts
        Route::middleware('module:blog_posts')->group(function (): void {
            Route::get('blog-posts', [BlogPostController::class, 'index'])->middleware('can:blog_posts.view_any')->name('blog-posts.index');
            Route::get('blog-posts/calendar', [BlogPostController::class, 'calendar'])->middleware('can:blog_posts.view_any')->name('blog-posts.calendar');
            Route::get('blog-posts/create', [BlogPostController::class, 'create'])->middleware('can:blog_posts.create')->name('blog-posts.create');
            Route::post('blog-posts', [BlogPostController::class, 'store'])->middleware('can:blog_posts.create')->name('blog-posts.store');
            Route::get('blog-posts/{post}', [BlogPostController::class, 'show'])->whereNumber('post')->middleware('can:blog_posts.view')->name('blog-posts.show');
            Route::get('blog-posts/{post}/edit', [BlogPostController::class, 'edit'])->whereNumber('post')->middleware('can:blog_posts.edit')->name('blog-posts.edit');
            Route::put('blog-posts/{post}', [BlogPostController::class, 'update'])->whereNumber('post')->middleware('can:blog_posts.edit')->name('blog-posts.update');
            Route::delete('blog-posts/{post}', [BlogPostController::class, 'destroy'])->whereNumber('post')->middleware('can:blog_posts.delete')->name('blog-posts.destroy');
            Route::post('blog-posts/{post}/publish', [BlogPostController::class, 'publish'])->whereNumber('post')->middleware('can:blog_posts.change_status')->name('blog-posts.publish');
            Route::post('blog-posts/{post}/schedule', [BlogPostController::class, 'schedule'])->whereNumber('post')->middleware('can:blog_posts.change_status')->name('blog-posts.schedule');
            Route::post('blog-posts/{post}/unpublish', [BlogPostController::class, 'unpublish'])->whereNumber('post')->middleware('can:blog_posts.change_status')->name('blog-posts.unpublish');
            Route::post('blog-posts/{post}/archive', [BlogPostController::class, 'archive'])->whereNumber('post')->middleware('can:blog_posts.change_status')->name('blog-posts.archive');
            Route::get('blog-posts/{post}/stats', [BlogPostController::class, 'stats'])->whereNumber('post')->middleware('can:blog_posts.view_reports')->name('blog-posts.stats');
            Route::get('blog-posts/{post}/preview-link', [BlogPostController::class, 'previewLink'])->whereNumber('post')->middleware('can:blog_posts.view')->name('blog-posts.preview-link');
        });

        // §8.8 Jobs (table job_openings — R1)
        Route::middleware('module:jobs')->group(function (): void {
            Route::get('jobs', [JobOpeningController::class, 'index'])->middleware('can:jobs.view_any')->name('jobs.index');
            Route::get('jobs/create', [JobOpeningController::class, 'create'])->middleware('can:jobs.create')->name('jobs.create');
            Route::post('jobs', [JobOpeningController::class, 'store'])->middleware('can:jobs.create')->name('jobs.store');
            Route::post('jobs/reorder', [JobOpeningController::class, 'reorder'])->middleware('can:jobs.edit')->name('jobs.reorder');
            Route::get('jobs/{job}', [JobOpeningController::class, 'show'])->whereNumber('job')->middleware('can:jobs.view')->name('jobs.show');
            Route::get('jobs/{job}/edit', [JobOpeningController::class, 'edit'])->whereNumber('job')->middleware('can:jobs.edit')->name('jobs.edit');
            Route::put('jobs/{job}', [JobOpeningController::class, 'update'])->whereNumber('job')->middleware('can:jobs.edit')->name('jobs.update');
            Route::delete('jobs/{job}', [JobOpeningController::class, 'destroy'])->whereNumber('job')->middleware('can:jobs.delete')->name('jobs.destroy');
            Route::post('jobs/{job}/status', [JobOpeningController::class, 'status'])->whereNumber('job')->middleware('can:jobs.change_status')->name('jobs.status');
        });

        // §8.9 Job applications — index is `view` (not view_any): §9.1.3 reviewers see their own slice
        Route::middleware('module:job_applications')->group(function (): void {
            Route::get('job-applications', [JobApplicationController::class, 'index'])->middleware('can:job_applications.view')->name('job-applications.index');
            Route::get('job-applications/export', [JobApplicationController::class, 'export'])->middleware('can:job_applications.export')->name('job-applications.export');
            Route::get('job-applications/{application}', [JobApplicationController::class, 'show'])->whereNumber('application')->middleware('can:job_applications.view')->name('job-applications.show');
            Route::put('job-applications/{application}', [JobApplicationController::class, 'update'])->whereNumber('application')->middleware('can:job_applications.edit')->name('job-applications.update');
            Route::delete('job-applications/{application}', [JobApplicationController::class, 'destroy'])->whereNumber('application')->middleware('can:job_applications.delete')->name('job-applications.destroy');
            Route::delete('job-applications/{application}/force', [JobApplicationController::class, 'forceDestroy'])->whereNumber('application')->middleware('can:job_applications.delete')->name('job-applications.force-destroy');
            Route::post('job-applications/{application}/status', [JobApplicationController::class, 'status'])->whereNumber('application')->middleware('can:job_applications.change_status')->name('job-applications.status');
            Route::post('job-applications/{application}/assign', [JobApplicationController::class, 'assign'])->whereNumber('application')->middleware('can:job_applications.assign')->name('job-applications.assign');
            Route::get('job-applications/{application}/cv', [JobApplicationController::class, 'cv'])->whereNumber('application')->middleware('can:job_applications.download')->name('job-applications.cv');
        });

        // §8.10 Contact inquiries — index is `view` (not view_any): §9.1.2 reviewers see what is assigned to them
        Route::middleware('module:contact_inquiries')->group(function (): void {
            Route::get('contact-inquiries', [ContactInquiryController::class, 'index'])->middleware('can:contact_inquiries.view')->name('contact-inquiries.index');
            Route::get('contact-inquiries/export', [ContactInquiryController::class, 'export'])->middleware('can:contact_inquiries.export')->name('contact-inquiries.export');
            Route::post('contact-inquiries/route-pending', [ContactInquiryController::class, 'routePending'])->middleware('can:contact_inquiries.change_status')->name('contact-inquiries.route-pending');
            Route::get('contact-inquiries/{inquiry}', [ContactInquiryController::class, 'show'])->whereNumber('inquiry')->middleware('can:contact_inquiries.view')->name('contact-inquiries.show');
            Route::put('contact-inquiries/{inquiry}', [ContactInquiryController::class, 'update'])->whereNumber('inquiry')->middleware('can:contact_inquiries.edit')->name('contact-inquiries.update');
            Route::delete('contact-inquiries/{inquiry}', [ContactInquiryController::class, 'destroy'])->whereNumber('inquiry')->middleware('can:contact_inquiries.delete')->name('contact-inquiries.destroy');
            Route::post('contact-inquiries/{inquiry}/status', [ContactInquiryController::class, 'status'])->whereNumber('inquiry')->middleware('can:contact_inquiries.change_status')->name('contact-inquiries.status');
            Route::post('contact-inquiries/{inquiry}/route', [ContactInquiryController::class, 'route'])->whereNumber('inquiry')->middleware('can:contact_inquiries.change_status')->name('contact-inquiries.route');
            Route::post('contact-inquiries/{inquiry}/assign', [ContactInquiryController::class, 'assign'])->whereNumber('inquiry')->middleware('can:contact_inquiries.assign')->name('contact-inquiries.assign');
            Route::post('contact-inquiries/{inquiry}/spam', [ContactInquiryController::class, 'spam'])->whereNumber('inquiry')->middleware('can:contact_inquiries.change_status')->name('contact-inquiries.spam');
            Route::post('contact-inquiries/{inquiry}/not-spam', [ContactInquiryController::class, 'notSpam'])->whereNumber('inquiry')->middleware('can:contact_inquiries.change_status')->name('contact-inquiries.not-spam');
        });
```

### 5.2 `routes/web.php`

Imports — add:

```php
use App\Http\Controllers\Site\BlogController;
use App\Http\Controllers\Site\CareerController;
use App\Http\Controllers\Site\ContactController;
use App\Http\Controllers\Site\PortfolioController;
use App\Http\Controllers\Site\ServiceController;
use App\Http\Controllers\Site\TeamController;
```

Paste after the `Route::prefix('preview')->name('site.preview.')…` group and before `require __DIR__.'/auth.php';`
(the `/{slug}` catch-all lives in `routes/site-pages.php`, loaded after every file, so ordering against it is already
guaranteed):

```php
/*
|--------------------------------------------------------------------------
| phase-04 §7.1 — services, portfolio, team, blog, careers, contact
|--------------------------------------------------------------------------
| `site` on every row; `site_module:<slug>` 404s a disabled module (D26) and sits before `site.cache`.
| No `can:` on any site.* route (phase-03 INV-15, CmsRouteContractTest): the blog preview authorises in
| the controller. `site.cache` is off the blog post (view counter), the careers detail + apply form and
| the contact page (per-request CSRF + SpamGuard token).
*/

Route::middleware(['site', 'site_module:services', 'site.cache'])->group(function (): void {
    Route::get('services', [ServiceController::class, 'index'])->name('site.services.index');
    Route::get('services/{service:slug}', [ServiceController::class, 'show'])->name('site.services.show');
});

Route::middleware(['site', 'site_module:portfolio', 'site.cache'])->group(function (): void {
    Route::get('portfolio', [PortfolioController::class, 'index'])->name('site.portfolio.index');
    Route::get('portfolio/{portfolioItem:slug}', [PortfolioController::class, 'show'])->name('site.portfolio.show');
});

Route::get('team', [TeamController::class, 'index'])->middleware(['site', 'site_module:team', 'site.cache'])->name('site.team.index');

Route::middleware(['site', 'site_module:blog_posts'])->group(function (): void {
    Route::get('blog', [BlogController::class, 'index'])->middleware('site.cache')->name('site.blog.index');
    Route::get('blog/category/{blogCategory:slug}', [BlogController::class, 'category'])->middleware('site.cache')->name('site.blog.category');
    Route::get('blog/tag/{blogTag:slug}', [BlogController::class, 'tag'])->middleware('site.cache')->name('site.blog.tag');
    Route::get('blog/{blogPost:slug}', [BlogController::class, 'show'])->name('site.blog.show');
});

Route::get('preview/blog/{blogPost}', [BlogController::class, 'preview'])->whereNumber('blogPost')->middleware(['site', 'auth', 'active'])->name('site.blog.preview');

Route::middleware(['site', 'site_module:jobs'])->group(function (): void {
    Route::get('careers', [CareerController::class, 'index'])->middleware('site.cache')->name('site.careers.index');
    Route::get('careers/{jobOpening:slug}', [CareerController::class, 'show'])->name('site.careers.show');
    Route::post('careers/{jobOpening:slug}/apply', [CareerController::class, 'apply'])->middleware('throttle:public-apply')->name('site.careers.apply');
});

Route::get('contact', [ContactController::class, 'index'])->middleware('site')->name('site.contact.index');
Route::post('contact', [ContactController::class, 'store'])->middleware(['site', 'throttle:public-contact'])->name('site.contact.store');
```

### 5.3 The route ↔ permission table (for review)

| Module (`module:`) | Routes | `can:` used |
|---|---|---|
| `service_categories`, `portfolio_categories` | index, create, store, reorder, show, edit, update, toggle, destroy | view_any, create, edit, view, change_status, delete |
| `technologies`, `blog_categories`, `blog_tags` | as above without reorder | view_any, create, view, edit, change_status, delete |
| `services` | index, export, create, store, reorder, show, edit, update, destroy, status, featured | view_any, export, create, edit, view, delete, change_status |
| `portfolio` | index, create, store, reorder, show, edit, update, destroy, status, featured, images.{store,reorder,cover,update,destroy} | view_any, create, edit, view, delete, change_status, upload |
| `team` | index, create, store, reorder, show, edit, update, destroy, status, visibility | view_any, create, edit, view, delete, change_status |
| `testimonials`, `student_reviews` | index, create, store, bulk-approve, show, edit, update, destroy, approve, reject, featured | view_any, create, approve, view, edit, delete, reject, change_status |
| `success_stories` | index, create, store, reorder, show, edit, update, destroy, status, featured | view_any, create, edit, view, delete, change_status |
| `blog_posts` | index, calendar, create, store, show, edit, update, destroy, publish, schedule, unpublish, archive, stats, preview-link | view_any, create, view, edit, delete, change_status, view_reports |
| `jobs` | index, create, store, reorder, show, edit, update, destroy, status | view_any, create, edit, view, delete, change_status |
| `job_applications` | **index (`view`)**, export, show, update, destroy, force-destroy, status, assign, cv | view, export, edit, delete, change_status, assign, download |
| `contact_inquiries` | **index (`view`)**, export, route-pending, show, update, destroy, status, route, assign, spam, not-spam | view, export, change_status, edit, delete, assign |
| public (`site`, `site_module:`) | services ×2, portfolio ×2, team, blog ×4, careers ×3, contact ×2 | none (INV-15) |
| public preview | `site.blog.preview` — `site`, `auth`, `active`; authorisation in the controller (§9 R-1) | none |

Two rows beyond §7.2's literal list, both needed by the delivered screens and behind existing permissions:
`admin.{taxonomy}.toggle` (§8.1 "the active toggle posts immediately") and `admin.portfolio.images.update` (§8.3
per-attachment caption).

---

## 6. `app/Support/Sidebar.php` — the Website group's Phase 4 entries

> **APPLIED 2026-09-19.** The 15 flat entries are live at `app/Support/Sidebar.php:705-800`, in this order: Services,
> Service Categories, Technologies, Portfolio, Portfolio Categories, Team, Testimonials, Student Reviews, Success
> Stories, Blog Posts, Blog Categories, Blog Tags, Jobs, Job Applications, Contact Inquiries. Each `permission`
> equals its route's `can:` (including the two deliberate `…view` entries, R-2), each `module` is a real slug and
> each `route` resolves. `tests/Feature/Modules/SidebarVisibilityTest` already carries the 15 new labels.
> **Do not re-apply.**

Replace everything from the comment line `// Business entities the site renders stay at the top level (Phase 4
onwards, unchanged).` down to and including the `Contact Inquiries` item (the last item of the `website` group) with
the block below. Why it changes (§9 R-5): a parent with children has no URL, which
`SidebarVisibilityTest::every_rendered_item_points_at_a_url_the_user_can_actually_open` refuses the moment the Blog /
Careers children get routes; the four taxonomies had no entry; and the two review queues must advertise `view`.

```php
                    // Business entities the site renders stay at the top level (phase-04 §8, F-6.7). Flat items only:
                    // a rendered parent has no URL. Every `permission` is exactly the can: of the route it links to.
                    [
                        'label' => 'Services',
                        'icon' => 'wrench-screwdriver',
                        'route' => 'admin.services.index',
                        'module' => 'services',
                        'permission' => 'services.view_any',
                    ],
                    [
                        'label' => 'Service Categories',
                        'icon' => 'squares-2x2',
                        'route' => 'admin.service-categories.index',
                        'module' => 'service_categories',
                        'permission' => 'service_categories.view_any',
                    ],
                    [
                        'label' => 'Technologies',
                        'icon' => 'puzzle-piece',
                        'route' => 'admin.technologies.index',
                        'module' => 'technologies',
                        'permission' => 'technologies.view_any',
                    ],
                    [
                        'label' => 'Portfolio',
                        'icon' => 'photo',
                        'route' => 'admin.portfolio.index',
                        'module' => 'portfolio',
                        'permission' => 'portfolio.view_any',
                    ],
                    [
                        'label' => 'Portfolio Categories',
                        'icon' => 'rectangle-stack',
                        'route' => 'admin.portfolio-categories.index',
                        'module' => 'portfolio_categories',
                        'permission' => 'portfolio_categories.view_any',
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
                        'label' => 'Blog Posts',
                        'icon' => 'newspaper',
                        'route' => 'admin.blog-posts.index',
                        'module' => 'blog_posts',
                        'permission' => 'blog_posts.view_any',
                    ],
                    [
                        'label' => 'Blog Categories',
                        'icon' => 'folder',
                        'route' => 'admin.blog-categories.index',
                        'module' => 'blog_categories',
                        'permission' => 'blog_categories.view_any',
                    ],
                    [
                        'label' => 'Blog Tags',
                        'icon' => 'tag',
                        'route' => 'admin.blog-tags.index',
                        'module' => 'blog_tags',
                        'permission' => 'blog_tags.view_any',
                    ],
                    [
                        'label' => 'Jobs',
                        'icon' => 'briefcase',
                        'route' => 'admin.jobs.index',
                        'module' => 'jobs',
                        'permission' => 'jobs.view_any',
                    ],
                    [
                        // `view`, not view_any: a hiring manager sees the applications of its own openings (§9.1.3).
                        'label' => 'Job Applications',
                        'icon' => 'inbox-stack',
                        'route' => 'admin.job-applications.index',
                        'module' => 'job_applications',
                        'permission' => 'job_applications.view',
                    ],
                    [
                        // `view`, not view_any: a reviewer sees what is assigned to it (§9.1.2).
                        'label' => 'Contact Inquiries',
                        'icon' => 'envelope',
                        'route' => 'admin.contact-inquiries.index',
                        'module' => 'contact_inquiries',
                        'permission' => 'contact_inquiries.view',
                    ],
```

No pending-moderation badge: `Sidebar` has no badge slot (the count shows on the queue tab and the widget).

---

## 7. Seeders — `database/seeders/RoleSeeder.php` grants, then the seed run

> **APPLIED 2026-09-19.** The grants are live in `database/seeders/RoleSeeder.php` (lines 219, 296-300, 319-322,
> 344, 361, 390) and the database is seeded: 86 `modules` rows, 851 `permissions` rows, 34 `website` `settings` rows.
> **Still outstanding (verify agent, L-4):** after the suite is green run
> `php artisan permission:cache-reset && php artisan optimize:clear` — nothing in this run did.

No new seeder and no content rows: modules, permissions and settings are registry-driven (§2, §3). **No
`website_sections` rows are seeded** — `InstallAndRollbackTest` asserts exactly 6 published sections after a fresh
seed; administrators place Phase 4 sections themselves.

**7.1 `HR`** — replace its `permissions` with (and its description with
`'Employees, departments, attendance, leave, payroll and hiring, with HR reporting.'`):

```php
                'permissions' => $this->merge(
                    $staffBase,
                    PermissionRegistry::permissionNamesFor(['employees', 'departments', 'attendance', 'leaves', 'payroll']),
                    // phase-04 §9.1 / §13 (H7): hiring sits with HR — every opening and every application, CVs included.
                    PermissionRegistry::permissionNamesFor(['jobs', 'job_applications']),
                    PermissionRegistry::permissionNamesFor('reports', self::REPORTING),
                ),
```

**7.2 `SEO Expert`** — replace the line `PermissionRegistry::permissionNamesFor(['blog_posts', 'blog_categories', 'seo']),` with:

```php
                    // phase-04 §9.1 / §13: an author, not an editor — never blog_posts.approve; the catalogue copy read/edit;
                    // never job_applications or contact_inquiries (F-12.4).
                    PermissionRegistry::permissionNamesFor('blog_posts', $this->abilitiesExcept('blog_posts', ['approve', 'reject'])),
                    PermissionRegistry::permissionNamesFor(['blog_categories', 'blog_tags', 'seo']),
                    PermissionRegistry::permissionNamesFor(['services', 'portfolio', 'team'], self::READ_EDIT),
```

**7.3 `Digital Marketer`** — replace the line
`PermissionRegistry::permissionNamesFor(['leads', 'blog_posts', 'blog_categories', 'course_inquiries']),` with:

```php
                    PermissionRegistry::permissionNamesFor(['leads', 'blog_categories', 'blog_tags', 'course_inquiries']),
                    // phase-04 §9.1: author-level blog; inquiries assigned to it only — no view_any, no view_logs (F-12.4).
                    PermissionRegistry::permissionNamesFor('blog_posts', $this->abilitiesExcept('blog_posts', ['approve', 'reject'])),
                    PermissionRegistry::permissionNamesFor('contact_inquiries', [Ability::View, Ability::Edit, Ability::ChangeStatus]),
```

**7.4 `Sales Executive`** — add to its `merge(…)`:

```php
                    // phase-04 §9.1.2: its own assigned inquiries, and it may hand one on.
                    PermissionRegistry::permissionNamesFor('contact_inquiries', [Ability::View, Ability::Assign]),
```

**7.5 `Receptionist`** — add to its `merge(…)`:

```php
                    // phase-04 §9.1.2: view the inquiries assigned to the front desk.
                    PermissionRegistry::permissionNamesFor('contact_inquiries', [Ability::View]),
```

**7.6 `Institute Manager`** — add to its `merge(…)` (phase-04 §13: "pin `blog_posts.approve` to Admin and Institute
Manager"; `approve` is useless without the rest of the module — owner decision, §11 R-3):

```php
                    // phase-04 §13 (Q2): the blog editor role beside Admin.
                    PermissionRegistry::permissionNamesFor(['blog_posts', 'blog_categories', 'blog_tags']),
```

Admin receives everything automatically (`everythingExcept`); Super Admin holds every name.

**7.7 Seed run (verify agent, after §1-§7; additive and idempotent, D65):**

```bash
php artisan db:seed --class=ModuleSeeder --force
php artisan db:seed --class=PermissionSeeder --force
php artisan db:seed --class=RoleSeeder --force
php artisan db:seed --class=SettingSeeder --force
php artisan permission:cache-reset
php artisan optimize:clear
```

Expected: 4 modules created, 39 permissions created, 21 settings rows created, missing grants added to existing roles,
**nothing revoked** (so on `my_office` SEO Expert and Digital Marketer keep the `blog_posts.approve` Phase 1 gave
them — §11 R-3).

---

## 8. Packages

> **NOTHING TO DO — confirmed 2026-09-19.** `composer.json` and `package.json` are unchanged by Phase 4.

None. `mews/purifier` (Phase 3, D25) is already required; images go through Phase 3's GD pipeline; the calendar and
gallery are Alpine; no npm change. PHP `fileinfo` (CV MIME detection) is already enabled on XAMPP.

---

## 9. Reconciliation — every mismatch found, with the exact fix

> **R-1 … R-12 are ALL APPLIED (verified 2026-09-19, each by grepping the named file).** R-13 … R-20 are deliberate
> deviations from the contract's literal text: no code change, but they still have to be recorded in
> `DEVELOPMENT_LOG.md` §4 (L-5). **R-21 … R-25 are new, found on 2026-09-19** and are listed after the table.
>
> | # | Applied where |
> |---|---|
> | R-1 | `Site/BlogController.php:157` signature check, `:161` `Gate::authorize('view', $blogPost)`; route `['site','auth','active']`, no `signed`, no `can:` |
> | R-2 | `ContactInquiryController.php:72,117` → `contact_inquiries.view`; `JobApplicationController.php:59,108` → `job_applications.view`; routes and sidebar match |
> | R-3 | `AssignRequest.php:62-63` holds both `$viewAny` and `$view` |
> | R-4 | (a) `MarketingSectionTypes` registered; (b) `ComposesSite::liveProviderOutput()`; (c) `MarketingSectionProvider.php:104` reads `published_content`; (d) `ServicesSectionProvider.php:45-48` emits `title`/`excerpt`/`media`/`meta` |
> | R-5 | 15 flat sidebar entries; `SidebarVisibilityTest` label list updated |
> | R-6 | sorts 838/840/845/848/850/860/870/880/890/910/915/920/930/940/950, all in the Website range, all unique |
> | R-7 | `BlogActivityWidget.php:100` `['tab' => 'draft']`; `PendingModerationWidget.php:61,79,89` `['tab' => 'pending']` |
> | R-8 | `website.testimonial_auto_approve` and `website.contact_budget_options` both declared; 0 undeclared `website.*` reads under `app/` |
> | R-9 / R-10 / R-11 | no route added — still open as dead UI, see R-22 |
> | R-12 | no `gray` left in `ContactInquiryStatus.php` or `InquirySource.php` |

Apply R-1 … R-12 as part of this integration (they touch Phase 4 files, except R-4b which is §4.7). R-13 … R-20
are deliberate deviations from the contract's literal text: no code change, record them in the tracker.

| # | Mismatch (evidence) | Fix | File |
|---|---|---|---|
| R-1 | Contract §7.1 gives `site.blog.preview` `auth, signed, can:view,blogPost`. On disk that breaks three things: `CmsRouteContractTest` forbids any `can:` on a `site.*` route (INV-15); `MiddlewareStackTest` requires `active` beside `auth`; `signed` answers 403 on a tampered signature where §11 test 25 wants 404. | Route is `['site', 'auth', 'active']` (§5.2). In `BlogController::preview()` add `Gate::authorize('view', $blogPost);` **directly after** the `hasValidSignature()` check (signature first, so an id is never probeable), import `use Illuminate\Support\Facades\Gate;`, and change the docblock "The route carries `auth` and `can:view,blogPost`" to "The route carries `auth` + `active`; the policy runs here". | `app/Http/Controllers/Site/BlogController.php` |
| R-2 | §7.2 guards both review queues' index with `view_any`, but §9.1.2/§9.1.3 and §11 tests 55-56 give reviewers (`view` only: Sales Executive, Receptionist, Digital Marketer, hiring managers) their own slice; `view_any` would 403 them. The policies already accept either. | Routes use `can:contact_inquiries.view` / `can:job_applications.view` (§5.1); sidebar the same (§6); in `ContactInquiryController::index()` change `$this->authorize('contact_inquiries.view_any');` to `$this->authorize('contact_inquiries.view');`; in `JobApplicationController::index()` change `$this->authorize('job_applications.view_any');` to `$this->authorize('job_applications.view');`. Rows stay scoped by `visibleTo()`. | the two controllers |
| R-3 | `AssignRequest` (§6.11 literal) requires the assignee to hold `view_any` — so nobody can ever be assigned to a reviewer who lacks it, which is the whole point of §9.1.2/§9.1.3. | In `AssignRequest::assigneeMayWork()` replace `$permission = $this->contentPermission('view_any');` … `! $user->can($permission)` with a check that passes when the user can `contentPermission('view_any')` **or** `contentPermission('view')`, and update its message/docblock. In `ContactInquiryController::assignees()` filter on `$candidate->can('contact_inquiries.view_any') \|\| $candidate->can('contact_inquiries.view')`; in `JobApplicationController::reviewerOptions()` the same for `job_applications`. | `app/Http/Requests/Cms/AssignRequest.php`, the two controllers |
| R-4 | Section data never reaches a page: (a) no Phase 4 type is registered; (b) Phase 3 never resolves `is_live` providers at render (§4.7); (c) `MarketingSectionProvider::options()` reads `published_content['limit']`, but a snapshot keeps fields under `published_content['fields']`, so every admin option is ignored once published; (d) Phase 3's `site/sections/services.blade.php` (teaser partial) renders cards by `title`/`excerpt`/`media`/`meta`, while `ServicesSectionProvider` emits `name`/`short_description`/`image`/`category` — the services section always shows its empty state. | (a) §4.1 + §4.3. (b) §4.7. (c) In `options()` replace the two `$published`/`$content` lines with: `$published = $section->getAttribute('published_content');` and `$content = is_array($published) && is_array($published['fields'] ?? null) ? $published['fields'] : (array) ($section->getAttribute('content') ?? []);`. (d) In `ServicesSectionProvider::build()` add to each item: `'title' => (string) $service->name, 'excerpt' => $service->short_description, 'media' => $this->image($service->image, ImageProfile::Card), 'meta' => $service->category instanceof ServiceCategory && $service->category->isActive() ? (string) $service->category->name : null,` (additive; keep the existing keys). | `app/Support/Cms/Sections/MarketingSectionProvider.php`, `…/ServicesSectionProvider.php` |
| R-5 | `Sidebar` nests Blog and Careers as parents; a parent has no URL, so `SidebarVisibilityTest::every_rendered_item_points_at_a_url…` fails once Phase 4 routes exist. §8 also wants the four taxonomies listed. | §6. Then update the expected label list in `tests/Feature/Modules/SidebarVisibilityTest::items_whose_routes_do_not_exist_yet_stay_hidden` (§10.2). | `app/Support/Sidebar.php`, the test |
| R-6 | §4's sorts (410-445) collide with the Collaborator group (410-460) → `PermissionRegistryTest::module_sort_orders_are_unique` and `permission_sort_orders_are_unique` fail; §4's icons `rectangle-group`, `cpu-chip`, `inbox-arrow-down` exist neither in `x-ui.icon` nor in `resources/data/icons.php`. | New slugs get Website-range sorts 838/845/848/915 and existing icons; the eleven Phase 1 website modules keep their names, icons and sorts (§2). | — (§2 already does it) |
| R-7 | `BlogActivityWidget` links drafts with `['tab' => 'drafts']`; `BlogPostController::TABS` spells it `draft` (lands on All). `PendingModerationWidget` links with `['status' => 'pending']`; the queues read `tab`. | `BlogActivityWidget.php` line with `'tab' => 'drafts'` → `'tab' => 'draft'`; `PendingModerationWidget.php` all three `['status' => 'pending']` → `['tab' => 'pending']`. | `app/Dashboard/Cms/` |
| R-8 | `SettingsSplitTruthRegressionTest::no_view_route_or_middleware_reads_a_key_the_registry_does_not_declare` fails **today** on the on-disk Phase 4 files: `website.testimonial_auto_approve` (`ModeratedContentController`) and `website.contact_budget_options` (`ContactController`, `PublicContactRequest`) are read under `app/Http` but not declared (verified with the test's own regexes). | §3 declares both. Until §3 lands, any suite run over this tree fails that test — see §11 R-1. | — |
| R-9 | The views render Restore buttons behind `Route::has('admin.{services,portfolio,team,success-stories,blog-posts,jobs}.restore')`; the registry holds `restore` for those modules (Phase 1), but §4's pins exclude it, §7.2 adds restore routes "only where the preset includes restore", and no controller has a `restore` action. | No restore routes in Phase 4; trashed tabs are read-only for `{module}.restore` holders. Do not "complete" the buttons without contract approval. | — |
| R-10 | `admin.portfolio.export` is linked (behind `Route::has`) and `portfolio.export` is now a permission, but §7.2 lists no portfolio export and `PortfolioItemController` has no `export()`. | None now; the button stays hidden. Raise with the contract owner. | — |
| R-11 | `site.forms.token` is used behind `Route::has()` by `<x-site.contact-form>` and the careers apply form (to fetch a CSRF + SpamGuard token on a cached page). Not in §7.1. | **Default: skip** — the `contact` section on a cached page renders a link to `/contact`; `/contact` and `/careers/{slug}` print both tokens inline. To enable, the Appendix's W.5 #2 has a controller + one `site`-gated route; it needs a contract decision first. | — |
| R-12 | `ContactInquiryStatus::Closed` and `InquirySource::Other` return colour `gray`, which is not in `EnumContractTest::COLOUR_TOKENS` (every other enum uses `slate` for neutral). The Phase 4 enum tests (§10.1) would fail. | Change both `'gray'` to `'slate'`. | `app/Enums/ContactInquiryStatus.php`, `app/Enums/InquirySource.php` |
| R-13 | §7.3 names the middleware class `EnsurePublicModuleEnabled`; Phase 3 shipped `EnsureSiteModuleEnabled` under the binding alias `site_module`. | Keep the existing class; routes use the alias only. | — |
| R-14 | §6.9 registers the limiters in `App\Providers\RateLimitServiceProvider` (does not exist). | `PublicFormRateLimits::register()` from `AppServiceProvider::boot()` (§4.3). | — |
| R-15 | §8.12 puts widgets in `app/Dashboard/Widgets/` (auto-discovered); they are in `app/Dashboard/Cms/`, registered explicitly. | §4.3. Consequence in §11 R-7. | — |
| R-16 | §13 names `App\Support\SitemapRegistry::register()`; it does not exist. | `SitemapGenerator::extend()` (§4.3); the domain V.3 `class_exists()` fallback is dropped as dead code. | — |
| R-17 | §9.1.1 says `BlogPostPolicy::view` needs `view_any`; route `admin.blog-posts.show`, `preview-link` and test 25 use `blog_posts.view`. | Policy checks `view` (as built). | — |
| R-18 | §5 rules are written `between:a,b`; the registry style is `min:a`,`max:b`. | Equivalent; §3 uses the registry style. The notify-address textareas carry a regex so "each line an email" is enforced server-side. | — |
| R-19 | Relation names in view docblocks (`updater`, `statusChangedBy`) vs models (`editor`, `statusChanger`). | Already reconciled by the views role: every thumbnail/save-bar lookup has the real relation names in its candidate list. No change. | — |
| R-20 | §7.2 "`{resource}` = the seven standard routes" — the taxonomies' `show`/`create`/`edit` answer JSON or redirect into the inline modal (§8.1 "a full page is wasteful"). | As built. | — |

### New rows found on 2026-09-19 (after §1-§8 were applied)

| # | Mismatch (evidence) | Fix | File |
|---|---|---|---|
| **R-21** | `site.blog.preview` is the one `site.*` route with no `site_module:` gate — every other public Phase 4 route carries one. Disabling the `blog_posts` module therefore 404s `/blog` and `/blog/{slug}` but leaves `/preview/blog/{id}` reachable to a signed-in holder of `blog_posts.view`. | **None — deliberate, record it.** Adding `site_module:blog_posts` would break contract test 25's "an editor can always preview"; the module gate already stops the editor through `Gate::before` step 1 on `blog_posts.view` (R-1's `Gate::authorize`), so the hole is closed at the policy, not the route. Assert it in `MarketingModuleGatingTest` (test 57) rather than changing the route. | — |
| **R-22** | **The four D60 manifests hold zero `owner_phase => 4` rows.** `tests/Support/{route-guard,screen,upload,index}-manifest.php` exist (Phase 3 created them: 85 / 35 / 1 rows and 14 index tables, all `owner_phase => 3`), and `tests/Feature/Cms/Marketing/Http/MarketingManifestTest` — 7 test methods, already written — asserts one row per Phase 4 route, GET screen, upload field and table. It cannot pass. | Append the rows of §10.3 under a `Phase 4` banner in each of the four files. Append-only; never edit a Phase 3 row. | `tests/Support/route-guard-manifest.php`, `screen-manifest.php`, `upload-manifest.php`, `index-manifest.php` |
| **R-23** | `tests/Unit/Enums/EnumContractTest::enumProvider()` still returns only the six Phase 1 enums (`UserStatus`, `ThemePreference`, `PanelType`, `ModuleGroup`, `Ability`, `LoginStatus`). The 12 Phase 4 enums are never checked for string-backing, `label()`, `color()` or `options()`. | Add the 12 entries listed in §10.2. Additive; do not weaken a single assertion. | `tests/Unit/Enums/EnumContractTest.php` |
| **R-24** | **Contract test 52 has no test.** `grep -rl "429\|RateLimiter" tests/` matches nothing, yet `resources/views/site/errors/429.blade.php` and `PublicFormRateLimits` (both limiters) exist and both public POST routes carry `throttle:`. 52 is the only one of the 65 with no coverage anywhere. | Write it — see §10.1's row for 50-53. | `tests/Feature/Cms/Marketing/Behaviour/` (new `PublicFormRateLimitTest`, or extend `SpamAndSanitisationTest`) |
| **R-25** | 25 Blade files call `setting()` / `site_setting()` directly, against the domain rule "a view receives settings as data from its controller". | **None for Phase 4** — every one of the 25 is a Phase 3 or Phase 1 file (`layouts/**`, `components/site/**`, `site/sections/{about,faq,footer,header,hero,rich_content}`, `site/{404,home}`, `admin/cms/pages/edit`). **Zero Phase 4 views call a settings helper.** Raise with Phase 3's owner; do not "fix" it inside Phase 4's integration. | — |

---

## 10. Acceptance tests — what exists, what is missing

> **Inventory re-measured 2026-09-19.** The tests were written after this file's first draft. On disk today:
> **26 files, 108 test methods**, all under `tests/Feature/Cms/Marketing/` — 19 in `Behaviour/`, 7 in `Http/`, plus
> `Behaviour/Concerns/MarketingBehaviourFixtures.php`, `Behaviour/Fixtures/{FakeInquiryTarget,FakeRoutedRecord}.php`
> and `Http/Concerns/MarketingHttpFixtures.php`. The `Http/` names differ from this file's first draft: they are
> `MarketingAuthorizationMatrixTest`, `MarketingFormValidationTest`, `MarketingManifestTest`,
> `MarketingModuleGatingTest`, `MarketingReviewerIsolationTest`, `MarketingRouteContractTest`,
> `MarketingScreensTest` — one file per concern rather than one per resource. The fake target landed at
> `tests/Feature/Cms/Marketing/Behaviour/Fixtures/FakeInquiryTarget.php`, not `tests/Support/`.
>
> **Coverage of the contract's 65.** Methods are named `test_<contract-number>_…`, so coverage is measurable:
>
> | | |
> |---|---|
> | covered by a numbered method | 1-6, 8, 9, 11-16, 18-24, 26, 27, 29-36, 38-51, 53-56, 60-64 |
> | covered inside a sweep test (number named in the docblock, not the method) | 7, 10, 17, 25, 28, 37, 57, 58, 59, 65 |
> | **not covered anywhere** | **52** — the two public-form rate limiters (R-24, L-3) |
>
> **Still to do, in order:** L-1 the manifest rows (§10.3) → L-2 the enum provider rows (§10.2) → L-3 test 52 →
> L-4 run the suite. Only a verify/fix agent writes and runs these; one runner at a time on the shared test database.
> Conventions: `Storage::fake('local')` + `Storage::fake('public')`, `Notification::fake()` where mail is asserted,
> `Carbon::setTestNow()` for dates, `RateLimiter::clear()` between limiter tests, `DashboardRegistry` re-registered in
> `setUp()` (Phase 2's tests reset it), and never a real `migrate:rollback` on the shared test database — the rollback
> test forks a scratch schema.
>
> **A CV fixture needs real bytes.** `UploadedFile::fake()->create('cv.pdf')` writes an empty file and
> `ApplicationCvService`'s `finfo` gate refuses it. Use a real PDF/DOCX byte string (X-5).

### 10.1 The contract's 65 tests, mapped

The "Must assert" column is the acceptance bar; the file names are the first draft's and are superseded by the
inventory above where they differ.

| Contract test(s) | Test file | Must assert (beyond the contract sentence) |
|---|---|---|
| 1 | `InstallAndRollbackTest` (Marketing) | Like Phase 3's: run in a **scratch schema via a child process**; all 15 roll back in reverse and re-migrate; `information_schema.KEY_COLUMN_USAGE` shows no FK on the 14 deferred columns; `jobs` untouched |
| 2-5 | `SlugTest` | `-2`/`-3` with a soft-deleted row; `admin`, `blog`, `0` refused 422 on `slug`; emoji title saves a non-empty slug |
| 6 | `SlugTest` | activity event `slug_changed` with old/new |
| 7 | `ServicesHttpTest` | the nine routes 403 with no permission; `services.view_any` alone → index 200, store 403 |
| 8 | `ServicesHttpTest` | `1234567.89` stored as the string `1234567.89`; rendered via `money()`; `price_visible=false` → value absent from `/services/{slug}` HTML, DB unchanged |
| 9 | `ServicesPublicTest` | draft 404 + absent from `/services`; published 200 (flush the public cache between) |
| 10 | `ServicesHttpTest` | `.svg`, PHP-renamed-`.jpg`, and an over-limit image refused 422. **The contract's "9 MB" passes today** (`security.max_upload_mb` defaults to 10): set it to 8 in the test, or use 11 MB — do not loosen |
| 11-13 | `PortfolioGalleryTest` | cover rules, 422 on foreign asset, `uq_pim` no duplicate, force delete removes pivot rows and **no** file; after `recountUsage()` `MediaPolicy::delete()` allows (needs §4.6) |
| 14 | `TeamTest` | hidden member absent from `/team`, present in admin; `social_links[myspace]` 422 |
| 15-20 | `ModerationTest` | approve stamps + activity; reject needs reason; `view_any` without `approve` → queue 200, approve/bulk 403; rating 0/6 refused, null accepted; bulk 5 with 2 approved → 3; feature pending 422 |
| 21-24 | `BlogViewCounterTest` | `views_count == COUNT(blog_post_views)` after each; 5 loads = 1 row; other UA +1; next day +1; `Sec-Purpose: prefetch` and an editor add nothing; duplicate insert (simulate the race by pre-inserting the row, then `record()`) returns false and does not increment |
| 25 | `BlogPreviewTest` | signed URL 200 for the author; 403 for a user without `blog_posts.view` (valid signature); 404 with a tampered signature; draft and scheduled 404 on `/blog/{slug}`; response has `X-Robots-Tag: noindex` and no view row (needs R-1) |
| 26-27 | `ScheduledPublishingTest` | due-1-min published, due-in-1-h untouched, second run publishes 0, `published_at` unchanged, `PostPublishedNotification` to the author; 4 overdue in one pass |
| 28 | `BlogHttpTest` | past `published_at` → 422 on the schedule route |
| 29 | `RelatedPostsTest` | tiers, never self/draft, limit from `website.blog_related_count`, empty on a one-post site |
| 30 | `BlogAuthorshipTest` | own 200 / other 403; with `approve` both 200 and index count grows |
| 31 | `BlogTagsTest` | `['Laravel','laravel',' LARAVEL ']` → one tag, one pivot row |
| 32 | `BlogQueryBudgetTest` | 30 posts × category × 5 tags: `/blog` query count bounded and not growing with rows (`DB::listen`), public cache disabled for the measurement |
| 33-35 | `CareersApplyTest` | CV on `local`, nothing on `public`, ULID filename; `.php`→`.pdf`, `.exe`, oversize refused with nothing written; duplicate email → error on `email`, one row |
| 36 | `CareersApplyTest` | draft/closed/past-deadline → 422, nothing stored; `website.careers_enabled=false` → `/careers` and the POST 404 |
| 37 | `CvDownloadTest` | 403 without `job_applications.download`; 200 with; `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`; `cv_downloaded` activity |
| 38 | `ApplicationPipelineTest` | `new→interview` 422; `new→reviewing` ok; reject without reason 422; interview without future slot 422; activity per accepted move (needs §4.4) |
| 39 | `JobOpeningTest` | min > max refused; decimal round-trip; `salary_visible=false` → "Negotiable" and neither number in HTML |
| 40 | `CloseExpiredTest` | yesterday's deadline closed + `closed_at`; no-deadline stays open |
| 41 | `ApplicationRetentionTest` | soft delete keeps the file; force delete removes it; `purge()` removes every application file |
| 42-49 | `InquiryRoutingTest` | general → `not_applicable`; no target → pending/`target_unregistered`, Route-now disabled, `failed_jobs` empty; `FakeInquiryTarget` + `inquiries:route-pending` (auto-route on by default; `--force` only when it is off) → routed + `ContactInquiryRouted` activity naming both; `route()` twice → one record; second inquiry claiming the same row stays pending; `isAvailable()=false` → `module_disabled`; throwing target → attempts++, `failed` on 3rd; `inquiry_auto_route=false` → pending, manual route works, 403 without `change_status`; course inquiry with `course_name` only routes to a fake course target |
| 50-53 | `SpamAndLimitsTest` | honeypot → same response, `is_spam`, no event (`Event::fake`), no notification, not routed; `too_fast`, `token`, `blocklist`; 6th contact POST/min → 429 **rendered in the site layout** (`site.errors.429`); 4th apply/hour → 429; not-spam re-routes |
| 54 | `SanitisationTest` | `<script>` stripped on store and escaped on the admin detail; `onerror=` and `<iframe>` gone from `full_description` on write |
| 55-56 | `ReviewerIsolationTest` | `contact_inquiries.view` only → index 200 listing exactly its assigned rows, another row's detail 404, spam only in the Spam tab; same for applications incl. the opening-owner scope; `internal_notes` absent from HTML without `edit`; technical columns absent without `view_logs` (needs R-2) |
| 57 | `ModuleGatingTest` (Marketing) | disable `blog_posts` **with a reason** (D63) → every `admin.blog-posts.*` 403 for Super Admin; `/blog`, `/blog/{slug}`, `/blog/category/{slug}` 404; re-enable restores; row counts unchanged |
| 58 | `PortalIsolationTest` | Student, Teacher, Client, Collaborator → 403 on every Phase 4 admin route (walk the route table by prefix) |
| 59 | `ScreensSmokeTest` | every index/form screen 200 for Super Admin with and without rows; public pages have one `h1`, `alt` on every `img`, meta title + description. "No console error" is **not** provable in PHPUnit — manual browser pass, recorded in the log |
| 60 | `TaxonomyTest` | delete with 5 services refused; with `reassign_to` moves 5 then soft-deletes; reorder writes 1..n and **one** `reordered` entry (do not count the CMS cache-bump row) |
| 61-64 | `ReferralSnapshotTest` | `?ref=COL-1001` stored verbatim, ids null, nothing thrown; posted `collaborator_id` discarded; no FK + indexed (manifest half: §11 R-8); a hand-written snapshot grants nothing in `visibleTo()` |
| 65 | `SeoDelegationTest` | no `meta_description` key in `StoreBlogPostRequest::rules()` itself; 300 chars accepted, 400 refused; static scan of `app/Http/Requests/Cms/**` finds no `seo_meta` column name |

### 10.2 Integration tests that pin §1-§9

| Test | Asserts | Status |
|---|---|---|
| `Http/MarketingRouteContractTest` | the 168 names of §5 exist; each admin route has `auth`,`active`,`panel:admin`, one `module:` and one `can:` from §2; `site.*` routes have `site` and no `can:`; `site.blog.preview` has `active` | **written** (6 methods) |
| `Http/MarketingScreensTest` | every index/form screen 200 for Super Admin, with and without rows; the sidebar's 15 entries; public pages' one `h1`, `alt` on every `img`, meta title + description | **written** (7 methods) |
| `Http/MarketingAuthorizationMatrixTest` | the per-route permission matrix, portal isolation (tests 7, 17, 25, 37, 58) | **written** (6 methods) |
| `Http/MarketingModuleGatingTest` | test 57 plus R-21: with `blog_posts` disabled, `/preview/blog/{id}` must 403 for everyone (the `Gate::before` module gate on `blog_posts.view`), not 200 | **written** (4 methods) — **add the R-21 assertion** |
| `Http/MarketingManifestTest` | the four manifests' Phase 4 rows equal the live routes, screens, uploads and indexes; no un-allowlisted raw echo in the Phase 4 view roots | **written** (7 methods), **fails until §10.3 lands** |
| `Http/MarketingFormValidationTest` | tests 10, 28, 65 and the hostile-query-string 422s | **written** (2 methods) |
| `Http/MarketingReviewerIsolationTest` | tests 55-56 at the HTTP layer | **written** (1 method) |
| section types / settings / widgets | a published `testimonials` section shows an approved testimonial **without re-publishing** (proves §4.7 + R-4c); services cards render titles (R-4d); `SettingsRegistry::fields('website')` has 34 keys and every default passes its rules; the 7 widget keys are registered after boot and each widget's query count is bounded | **not written** — fold into `MarketingScreensTest` or add one file |
| enum contract | the 12 Phase 4 enums | **L-2, not done** — see the block below |

**L-2 — `tests/Unit/Enums/EnumContractTest::enumProvider()`.** It returns only the six Phase 1 enums today. Add these
twelve entries inside the returned array (additive; the imports go at the top of the file):

```php
            'ApprovalStatus' => [ApprovalStatus::class],
            'TestimonialType' => [TestimonialType::class],
            'ContentSource' => [ContentSource::class],
            'SocialPlatform' => [SocialPlatform::class],
            'WorkMode' => [WorkMode::class],
            'EmploymentType' => [EmploymentType::class],
            'JobOpeningStatus' => [JobOpeningStatus::class],
            'JobApplicationStatus' => [JobApplicationStatus::class],
            'InquiryType' => [InquiryType::class],
            'ContactInquiryStatus' => [ContactInquiryStatus::class],
            'InquiryRoutingStatus' => [InquiryRoutingStatus::class],
            'InquirySource' => [InquirySource::class],
```

```php
use App\Enums\ApprovalStatus;
use App\Enums\ContactInquiryStatus;
use App\Enums\ContentSource;
use App\Enums\EmploymentType;
use App\Enums\InquiryRoutingStatus;
use App\Enums\InquirySource;
use App\Enums\InquiryType;
use App\Enums\JobApplicationStatus;
use App\Enums\JobOpeningStatus;
use App\Enums\SocialPlatform;
use App\Enums\TestimonialType;
use App\Enums\WorkMode;
```

All twelve pass today (R-12 removed the two `gray` colours). `EmploymentType` ships here but is owned semantically by
Phase 7 (E9) — Phase 7 must not redeclare it.

**Existing tests that changed (already applied, additive, nothing loosened):**
- `tests/Feature/Modules/SidebarVisibilityTest::items_whose_routes_do_not_exist_yet_stay_hidden` — the 15 Phase 4
  labels are in the list. **Done.**
- Nothing else: `PermissionStringConsistencyTest`, `CmsRouteContractTest`, `MiddlewareStackTest`,
  `MaintenanceModeTest`, `PermissionRegistryTest`, `SettingsRegistryTest`, `SettingsSplitTruthRegressionTest`,
  `InstallAndRollbackTest` need no edit (checked against their walkers; the suite has not been run by this role).

### 10.3 Manifest rows (E4, D60) — L-1, the one hard blocker

**No longer blocked: `tests/Support/` exists.** Phase 3 created all four manifests and filled them with
`owner_phase => 3` rows (route-guard 85, screen 35, upload 1, index 14 tables). **Phase 4 has added none**, and
`MarketingManifestTest`'s 7 methods assert they are there. Append-only: add a Phase 4 banner at the end of each
`return [ … ]`, never touch a Phase 3 row.

**(a) `tests/Support/route-guard-manifest.php` — 168 rows.** Do not hand-write them; generate them from the live
route table so they are equal to it by construction. Save this as `scratch/gen-guard.php`, run it once from the
project root, paste the output before the closing `];`, then delete the script:

```php
<?php

declare(strict_types=1);

// Emits the Phase 4 rows of tests/Support/route-guard-manifest.php from the live route table.
// php scratch/gen-guard.php >> rows.txt   — then paste and delete both files.

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$prefixes = [
    'admin.service-categories.', 'admin.technologies.', 'admin.services.', 'admin.portfolio-categories.',
    'admin.portfolio.', 'admin.team.', 'admin.testimonials.', 'admin.student-reviews.', 'admin.success-stories.',
    'admin.blog-categories.', 'admin.blog-tags.', 'admin.blog-posts.', 'admin.jobs.', 'admin.job-applications.',
    'admin.contact-inquiries.',
    'site.services.', 'site.portfolio.', 'site.team.', 'site.blog.', 'site.careers.', 'site.contact.',
];

$rows = [];
foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
    $name = $route->getName();
    if (! $name) {
        continue;
    }
    $match = false;
    foreach ($prefixes as $p) {
        if (str_starts_with($name, $p)) {
            $match = true;
            break;
        }
    }
    if (! $match) {
        continue;
    }

    $mw = array_values($route->gatherMiddleware());
    $permission = null;
    foreach ($mw as $m) {
        if (is_string($m) && str_starts_with($m, 'can:')) {
            $permission = explode(',', substr($m, 4))[0];
        }
    }
    $methods = array_values(array_diff($route->methods(), ['HEAD']));
    $rows[$name] = [
        'methods' => $route->methods(),
        'middleware' => array_map('strval', $mw),
        'permission' => $permission,
        'panel' => str_starts_with($name, 'admin.') ? 'admin' : 'public',
        'state_changing' => (bool) array_intersect($methods, ['POST', 'PUT', 'PATCH', 'DELETE']),
    ];
}
ksort($rows);

$q = static fn (?string $s): string => $s === null ? 'null' : "'".str_replace("'", "\\'", $s)."'";
$list = static fn (array $a): string => "['".implode("', '", $a)."']";

$publicRationale = 'phase-03 INV-15: a public website route is the published output, not a module UI. '
    .'Reachability is decided by `site` (maintenance) and `site_module:<slug>` (D26), and every query is '
    .'scoped by the public scopes of phase-04 §9.2.';

echo "\n    /*\n    |----------------------------------------------------------------------\n";
echo '    | Phase 4 — marketing modules ('.count($rows)." routes)\n";
echo "    |----------------------------------------------------------------------\n";
echo "    | tests/Feature/Cms/Marketing/Http/MarketingManifestTest keeps these rows equal to the live route table.\n    */\n";

foreach ($rows as $name => $r) {
    echo "    [\n";
    echo "        'route' => ".$q($name).",\n";
    echo "        'methods' => ".$list($r['methods']).",\n";
    echo "        'middleware' => ".$list($r['middleware']).",\n";
    echo "        'permission' => ".$q($r['permission']).",\n";
    echo "        'panel' => ".$q($r['panel']).",\n";
    echo "        'state_changing' => ".($r['state_changing'] ? 'true' : 'false').",\n";
    echo "        'rationale' => ".($r['permission'] === null ? $q($publicRationale) : 'null').",\n";
    echo "        'owner_phase' => 4,\n";
    echo "    ],\n";
}
```

Run on 2026-09-19 it emits exactly **168** rows: 153 `'panel' => 'admin'` with a non-null `permission` and
`'rationale' => null`, and 15 `'panel' => 'public'` with `'permission' => null` and the INV-15 rationale. The first
row reads:

```php
    [
        'route' => 'admin.blog-categories.create',
        'methods' => ['GET', 'HEAD'],
        'middleware' => ['web', 'auth', 'active', 'panel:admin', 'module:blog_categories', 'can:blog_categories.create'],
        'permission' => 'blog_categories.create',
        'panel' => 'admin',
        'state_changing' => false,
        'rationale' => null,
        'owner_phase' => 4,
    ],
```

**Read the output before pasting.** A generated row is only trustworthy because the route table itself was checked
(§5's cross-check table): if a route were missing its `can:`, the generator would faithfully write
`'permission' => null` and hide the hole behind a rationale. Confirm the count is 153 admin + 15 public and that no
`admin.*` row has a null permission.

**(b) `tests/Support/screen-manifest.php` — 76 rows, one per Phase 4 GET route.** These cannot be generated: `params`
is a closure and `query_budget` must be measured. The 76 names are the GET rows of (a). Shape them on Phase 3's rows:
`panel` is `admin` or `public`; `kind` is `index` for the 15 `.index` routes, `calendar` for
`admin.blog-posts.calendar`, `export` for `admin.{services,contact-inquiries,job-applications}.export`,
`form` for every `.create` / `.edit`, `show` for every `.show` plus `admin.blog-posts.{stats,preview-link}` and
`admin.job-applications.cv`, and `public` for the 13 `site.*` GET rows; `module` is the route's `module:` slug and
**null for every `site.*` row** (INV-15); `permissions` is the route's `can:` (null for `site.*`, except
`site.blog.preview`, whose alternative is a session holding `blog_posts.view` — R-1). `params` must resolve against a
fixture holding one row of each of the 20 models, which `MarketingHttpFixtures` already builds.

**(c) `tests/Support/upload-manifest.php` — 10 rows** (all `owner_phase => 4`):

| route | field | disk | allowed | max_mb | permission | public_reachable |
|---|---|---|---|---|---|---|
| `site.careers.apply` | `cv` | `local` | pdf, doc, docx (finfo) | min(`website.cv_max_mb`, `security.max_upload_mb`) | none — public form, rationale "public application; stored private, streamed only by `admin.job-applications.cv` behind `job_applications.download`" | false |
| `admin.services.store` / `.update` | `image` | `public` (MediaService) | MediaService image set, no SVG | `security.max_upload_mb` | `services.create` / `services.edit` | true |
| `admin.portfolio.store` / `admin.portfolio.images.store` | `images.*` | `public` | as above | as above | `portfolio.create` / `portfolio.upload` | true |
| `admin.team.store` / `.update` | `photo` | `public` | as above | as above | `team.create` / `team.edit` | true |
| `admin.testimonials.store` / `.update` | `author_photo` | `public` | as above | as above | `testimonials.create` / `.edit` | true |
| `admin.student-reviews.store` / `.update` | `student_photo` | `public` | as above | as above | `student_reviews.create` / `.edit` | true |
| `admin.success-stories.store` / `.update` | `photo` | `public` | as above | as above | `success_stories.create` / `.edit` | true |
| `admin.blog-posts.store` / `.update` | `featured_image` | `public` | as above | as above | `blog_posts.create` / `.edit` | true |
| `admin.{service,portfolio,blog}-categories.store` / `.update` | `image` | `public` | as above | as above | `{module}.create` / `.edit` | true |
| `admin.technologies.store` / `.update` | `logo` | `public` | as above | as above | `technologies.create` / `.edit` | true |

The CV row is the one that matters for D21: `ApplicationCvService` writes to the **private `local`** disk under a ULID
name, decides the type with `finfo` (never the client header or the extension), and the only way back out is
`admin.job-applications.cv` behind `job_applications.download`. Give it a `stored_as` string like Phase 3's media row.

**(d) `tests/Support/index-manifest.php` — 20 tables.** The rows are already written and correct: paste the block from
**Appendix S.8** verbatim under a Phase 4 banner. It carries every FK column, every deferred id of §2.1,
`contact_inquiries.referral_code`, each filtered status and each sorted column — which is what PRF-04 and
`MarketingManifestTest` check. S.8 is the one place in the Appendix that is *not* superseded.

**(e) `tests/Support/raw-output-allowlist.php` — no rows.** Correct as is: the Phase 4 views print no unescaped echo
(re-scanned 2026-09-19 — 0 `{!! !!}` and 0 `x-html` across the 105 files), and `MarketingManifestTest` asserts that.
If a later change needs one, it must name `RichText::sanitize()` as its sanitiser (SEC-05, D25).

---

## 11. Risks — blunt (re-stated 2026-09-19, after §1-§8 were applied)

1. **Phase 4 is applied but uncommitted, and Phase 5 is uncommitted in the same working tree.** `git status` shows
   Phase 4's 13 modified files (`PermissionRegistry`, `SettingsRegistry`, `Sidebar`, `Modules`, both providers, both
   route files, `RoleSeeder`, `routes/console.php`, `MediaService`, `PreviewController`, `SidebarVisibilityTest`)
   **interleaved with Phase 5's** untracked `Crm`/`Client`/`Lead` files, which edit several of the same registries.
   There is no clean `git add <phase-4 paths>` that separates them in those 13 shared files. Whoever commits must
   commit both units together or accept a commit that does not compile Phase 5 — decide before running `git add`, and
   never `git add -A`.
2. **The migrations have already run against the real `my_office` database and are uncommitted.** If anyone reverts
   the Phase 4 files, the 20 tables and the 86 module rows stay. Forward-only from here; a rollback is a hand-written
   reversing migration, not `migrate:rollback`.
3. **`MarketingManifestTest` fails today** (R-22). Its 7 methods are the Phase 4 definition of done for D60, and the
   four manifests hold 0 Phase 4 rows. This is the single blocker between here and a green suite; §10.3 has the rows
   and the generator.
4. **Contract test 52 is the only one of the 65 with no test at all** (R-24). Both limiters and the `429` view exist,
   so the gap is invisible from the code — only the test inventory shows it.
5. **The 12 Phase 4 enums are not in `EnumContractTest`** (R-23). They pass today, but nothing stops the next edit
   from adding a `gray` colour or dropping `options()` — exactly the drift R-12 caught by hand.
6. **Role grants on the live database do not match the contract.** D65 never revokes, so on `my_office` SEO Expert and
   Digital Marketer keep `blog_posts.approve` from Phase 1: §9.1.1's author/editor split is true only on a fresh
   install until a human removes it in the role editor. Institute Manager's blog grant (§7.6) still reads like a
   contract accident; confirm it.
7. **The contract text is wrong in eight places** (R-1, R-2, R-3, R-6, R-13 … R-17) and now in a ninth (R-21). The code
   follows behaviour and tests over literal route/sort/class names. A later "contract compliance" pass that re-applies
   §7.2's `view_any`, §7.1's `signed`/`can:`, or §4's 410-445 sorts will break tests 25, 55, 56 and three Phase 1-3
   walkers. `docs/phases/phase-04.md` should be edited to match — by its owner, not by a build agent.
8. **`config/filesystems.php` has `'serve' => true` on the private `local` disk** (Laravel 12 default), which exposes a
   signed-URL route to private files; D21 says "no signed URL". Nothing in Phase 4 mints one, but **CVs sit on that
   disk**. Needs a human security decision, and it is the highest-value unaddressed item in this file.
9. **`permission:cache-reset` has not been run** since the 39 new permissions and 4 new modules were seeded (L-4).
   Until it is, a cached spatie permission map can 403 a user who should pass, or the reverse.
10. **Widgets registered at boot cost every request a widget-directory scan plus one cache read**; on
    `CACHE_STORE=database` that is one query per request, public pages included. Phase 2's dashboard tests call
    `DashboardRegistry::reset()`, so `DashboardQueryBudgetTest` never measures the seven Phase 4 widgets.
11. **The site renders every section partial twice per request** (Phase 3's probe render in `usableSection()`), and the
    live-provider resolution adds a provider call per live section. The home page is behind `site.cache`, but a cache
    miss with several Phase 4 sections is heavy. Watch FT-27.
12. **`contact_inquiries` and `job_applications` assignee lists load every active user and call `can()` per user**
    (`assignees()`, `reviewerOptions()`): N permission lookups per render. Fine at tens of users, not at thousands.
13. **Scheduler times are UTC** (D61): `careers:close-expired` at 00:10 UTC is 05:10 PKT — correct for a
    display-timezone deadline, but anyone reading "00:10" as local time will think expired jobs stay open for 5 hours.
    `model:prune` shares 02:30 with `cms:sitemap-generate`.
14. **Eight `Route::has()`-guarded dead links ship in the UI** (R-9, R-10, R-11): six Restore buttons
    (`admin.{services,portfolio,team,success-stories,blog-posts,jobs}.restore`), `admin.portfolio.export` and
    `site.forms.token`. Harmless, but the `restore` **permissions exist** while the routes do not — a role editor will
    grant `services.restore` to someone who can never use it. Decide (L-6) rather than leave it implicit.
15. **Five pint failures are pre-existing and none is Phase 4's** — `Auth/NewPasswordController.php`,
    `config/activitylog.php` and the three spatie activity-log migrations. A fix agent tidying them will produce a diff
    that looks like Phase 4's; label it separately so Phase 4's review stays readable.


---

# Appendix — the domain agents' hand-off notes (verbatim, reference only)

> Kept as written by the schema, services, controllers and views roles. **Superseded wherever it differs from §1-§9
> above** — notably C.3's `view_any` indexes (§9 R-2), C.4's preview route (§9 R-1), V.3's sitemap fallback
> (§9 R-16), V.6's section-type note (§4.1, §4.7) and S.6 (§4.6 extends it). Never paste from this appendix —
> **with one exception: S.8's `index-manifest.php` block, which §10.3 (d) tells you to paste verbatim.**
>
> Everything these notes describe as work "to do" is already on disk and applied (§0). Read them as the record of
> what each role built, and for the reference material §1-§11 does not repeat: S.9 (the model write contract),
> V.8 (the service API the controllers call), C.6 / W.4 (the view-data contract) and W.5 (the views' open requests).

## Schema, enums, models, policies (phase-04 role: schema/enums/models/policies)

Verified before hand-off: `php -l` + `./vendor/bin/pint --test` clean on every file below. A disposable
scratch schema (`p4_scratch_schema_verify`, created and dropped by the check — never `my_office`, never
`my_office_test`) ran every committed migration, then the 15 staged files, then 85 assertions (FK / index
inventory, no FK on the 14 deferred columns, the four named unique guards, money round-trip, casts, scopes,
mass-assignment guards, append-only guards, isolation scopes, policy 403/404 paths), then
`migrate:rollback` of the phase-04 batch (all 20 tables gone, Phase 3 intact) and a clean re-run. No test
suite was run.

### S.1 Files delivered (all new; nothing existing edited)

```
database/migrations-staged/phase-04/   15 files, 2026_09_12_080100 … 2026_09_12_081500 (20 tables, dependency order)
app/Enums/                             ApprovalStatus TestimonialType ContentSource SocialPlatform WorkMode
                                       JobOpeningStatus JobApplicationStatus InquiryType ContactInquiryStatus
                                       InquiryRoutingStatus InquirySource EmploymentType (E9, phase-07 §3 verbatim)
app/Contracts/Cms/Moderatable.php
app/Models/Concerns/HasSlug.php        (calls App\Support\SlugGenerator::make() — shipped by the services role)
app/Models/Cms/Concerns/               SearchesContent OrdersBySortOrder
app/Models/Cms/                        ServiceCategory Service Technology ServiceTechnology PortfolioCategory
                                       PortfolioItem PortfolioItemTechnology PortfolioItemMedia TeamMember Testimonial
                                       StudentReview SuccessStory BlogCategory BlogTag BlogPostBlogTag BlogPost
                                       BlogPostView JobOpening JobApplication ContactInquiry
app/Policies/Cms/Concerns/             AuthorizesContentModule AuthorizesModeration
app/Policies/Cms/                      the 15 policies of §6.11
```

### S.2 Migrations — move, then migrate (integration stage)

Move every file in `database/migrations-staged/phase-04/` into `database/migrations/` unchanged (names already
sort after `2026_09_12_071000_create_sitemap_generations_table.php` and before any later phase). They need
Phase 1 `users` and Phase 3 `media_assets` only. `2026_09_12_081300_create_job_openings_table.php` asserts that
an existing `jobs` table is Laravel's queue table (R1) and throws, changing nothing, if it is not.

### S.3 `app/Providers/AppServiceProvider.php` — policy registrations

None of these follow Laravel's policy-discovery path (`App\Models\Cms\X` → `App\Policies\Cms\XPolicy` is not
guessed), so all 15 must be registered. Imports — add:

```php
use App\Models\Cms\BlogCategory;
use App\Models\Cms\BlogPost;
use App\Models\Cms\BlogTag;
use App\Models\Cms\ContactInquiry;
use App\Models\Cms\JobApplication;
use App\Models\Cms\JobOpening;
use App\Models\Cms\PortfolioCategory;
use App\Models\Cms\PortfolioItem;
use App\Models\Cms\Service;
use App\Models\Cms\ServiceCategory;
use App\Models\Cms\StudentReview;
use App\Models\Cms\SuccessStory;
use App\Models\Cms\TeamMember;
use App\Models\Cms\Technology;
use App\Models\Cms\Testimonial;
use App\Policies\Cms\BlogCategoryPolicy;
use App\Policies\Cms\BlogPostPolicy;
use App\Policies\Cms\BlogTagPolicy;
use App\Policies\Cms\ContactInquiryPolicy;
use App\Policies\Cms\JobApplicationPolicy;
use App\Policies\Cms\JobOpeningPolicy;
use App\Policies\Cms\PortfolioCategoryPolicy;
use App\Policies\Cms\PortfolioItemPolicy;
use App\Policies\Cms\ServiceCategoryPolicy;
use App\Policies\Cms\ServicePolicy;
use App\Policies\Cms\StudentReviewPolicy;
use App\Policies\Cms\SuccessStoryPolicy;
use App\Policies\Cms\TeamMemberPolicy;
use App\Policies\Cms\TechnologyPolicy;
use App\Policies\Cms\TestimonialPolicy;
```

`POLICIES` — append after the last existing entry (after the phase-03 block if it has been applied, otherwise
after `Module::class => ModulePolicy::class,`):

```php
        // phase-04 (§6.11): one policy per model; explicit because App\Models\Cms is not a discovery path.
        ServiceCategory::class => ServiceCategoryPolicy::class,
        Service::class => ServicePolicy::class,
        Technology::class => TechnologyPolicy::class,
        PortfolioCategory::class => PortfolioCategoryPolicy::class,
        PortfolioItem::class => PortfolioItemPolicy::class,
        TeamMember::class => TeamMemberPolicy::class,
        Testimonial::class => TestimonialPolicy::class,
        StudentReview::class => StudentReviewPolicy::class,
        SuccessStory::class => SuccessStoryPolicy::class,
        BlogCategory::class => BlogCategoryPolicy::class,
        BlogTag::class => BlogTagPolicy::class,
        BlogPost::class => BlogPostPolicy::class,
        JobOpening::class => JobOpeningPolicy::class,
        JobApplication::class => JobApplicationPolicy::class,
        ContactInquiry::class => ContactInquiryPolicy::class,
```

### S.4 `app/Support/Modules.php` — `MODEL_MODULES`

Every model instance answers through `moduleSlug()`. A **class-string** check (`can('viewAny', TeamMember::class)`)
falls back to the naming convention, which is wrong for these eight. Imports — add:

```php
use App\Models\Cms\BlogPostBlogTag;
use App\Models\Cms\BlogPostView;
use App\Models\Cms\JobOpening;
use App\Models\Cms\PortfolioItem;
use App\Models\Cms\PortfolioItemMedia;
use App\Models\Cms\PortfolioItemTechnology;
use App\Models\Cms\ServiceTechnology;
use App\Models\Cms\TeamMember;
```

Append inside `MODEL_MODULES`, after the last existing entry:

```php
        // phase-04: models whose class name does not pluralise into their module slug.
        PortfolioItem::class => 'portfolio',
        PortfolioItemMedia::class => 'portfolio',
        PortfolioItemTechnology::class => 'portfolio',
        ServiceTechnology::class => 'services',
        TeamMember::class => 'team',
        BlogPostView::class => 'blog_posts',
        BlogPostBlogTag::class => 'blog_posts',
        JobOpening::class => 'jobs',
```

(`ServiceCategory`, `Service`, `Technology`, `PortfolioCategory`, `Testimonial`, `StudentReview`, `SuccessStory`,
`BlogCategory`, `BlogTag`, `BlogPost`, `JobApplication`, `ContactInquiry` resolve by convention once their slugs
are in `PermissionRegistry`.)

### S.5 Permissions the policies check (verification list for the §4 `PermissionRegistry` block)

The policies name no permission string; they build `{MODULE}.{Ability}`. The §4 registry block (owned by the
permissions/integration role) must register at least these, or the action is simply denied:

| Module | Abilities checked | Not in today's Phase 1 registry |
|---|---|---|
| `service_categories`, `portfolio_categories`, `blog_categories`, `blog_tags` | view_any view create edit delete restore change_status | the three new slugs (all abilities) |
| `technologies` | + upload download | new slug |
| `services`, `portfolio`, `team` | view_any view create edit delete restore change_status upload download export print | export, print |
| `success_stories` | view_any view create edit delete restore change_status upload download | — |
| `testimonials`, `student_reviews` | + approve reject | upload, download |
| `blog_posts` | view_any view create edit delete restore change_status approve view_reports upload export print | view_reports |
| `jobs` | view_any view create edit delete restore change_status export print | — |
| `job_applications` | view_any view edit delete restore change_status assign download export print | — |
| `contact_inquiries` | view_any view edit delete restore change_status assign view_logs export print | view_logs, print |

`restore()` checks `{module}.restore`. §4's pinned sets carry no `restore`, while the Phase 1 registry does; if
the pin removes it, `restore()` is denied everywhere except Super Admin (and §7.2 then registers no restore
route) — a consistent outcome, noted so nobody "fixes" the policy.

**Route/policy note (§9.1.2, §9.1.3).** `ContactInquiryPolicy::viewAny()` and `JobApplicationPolicy::viewAny()`
accept `view_any` **or** `view`, because a reviewer holding only `view` must see its own slice through
`visibleTo()` (§11 tests 55-56, §9.1 Sales Executive / hiring manager). §7.2's index rows say
`can:contact_inquiries.view_any` / `can:job_applications.view_any`, which would 403 those users; the routes
should use `can:viewAny,App\Models\Cms\ContactInquiry` / `can:viewAny,App\Models\Cms\JobApplication` (the policy
still requires one of the two permissions and `module:` still gates first).

**Failure codes.** Missing permission → 403 everywhere. Holding the permission but not the row → **404**
(`Response::denyAsNotFound()`) for `ContactInquiry` and `JobApplication`; `BlogPost` returns 403 for another
author's post, as §11 test 30 asserts.

### S.6 `app/Services/Cms/MediaService.php` — count the Phase 4 image sources (§13 Phase 3 row, D24)

`usage()` and `recountUsage()` list their FK sources by hand, so an image used only by a service, a post or a
portfolio gallery reads as unused and `MediaPolicy::delete()` would allow its deletion. Skip this block if
another phase-04 section already carries it. Add the constant at the top of the class:

```php
    /**
     * phase-04 (D24): [table, media column, usage type, label column, detail]. Owners in the trash do not
     * count, as for sections and pages above.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private const PHASE4_MEDIA_SOURCES = [
        ['service_categories', 'image_media_id', 'service_category', 'name', 'image'],
        ['services', 'image_media_id', 'service', 'name', 'image'],
        ['technologies', 'logo_media_id', 'technology', 'name', 'logo'],
        ['portfolio_categories', 'image_media_id', 'portfolio_category', 'name', 'image'],
        ['portfolio_items', 'cover_media_id', 'portfolio_item', 'title', 'cover'],
        ['team_members', 'photo_media_id', 'team_member', 'name', 'photo'],
        ['testimonials', 'author_photo_media_id', 'testimonial', 'author_name', 'author photo'],
        ['student_reviews', 'student_photo_media_id', 'student_review', 'student_name', 'student photo'],
        ['success_stories', 'photo_media_id', 'success_story', 'student_name', 'photo'],
        ['blog_categories', 'image_media_id', 'blog_category', 'name', 'image'],
        ['blog_posts', 'featured_image_media_id', 'blog_post', 'title', 'featured image'],
    ];
```

In `usage()`, directly after the `seo_meta` loop:

```php
        $schema = $connection->getSchemaBuilder();

        foreach (self::PHASE4_MEDIA_SOURCES as [$table, $column, $type, $labelColumn, $detail]) {
            if (! $schema->hasTable($table)) {
                continue;
            }

            foreach ($connection->table($table)->whereNull('deleted_at')->where($column, $id)->get(['id', $labelColumn]) as $row) {
                $add($type, (int) $row->id, (string) $row->{$labelColumn}, $detail);
            }
        }

        if ($schema->hasTable('portfolio_item_media')) {
            foreach ($connection->table('portfolio_item_media as m')
                ->join('portfolio_items as p', 'p.id', '=', 'm.portfolio_item_id')
                ->whereNull('p.deleted_at')
                ->where('m.media_asset_id', $id)
                ->get(['p.id', 'p.title']) as $row) {
                $add('portfolio_item', (int) $row->id, (string) $row->title, 'gallery');
            }
        }
```

In `recountUsage()`, directly after the `seo_meta` loop:

```php
        $schema = $connection->getSchemaBuilder();

        foreach (self::PHASE4_MEDIA_SOURCES as [$table, $column, $type]) {
            if (! $schema->hasTable($table)) {
                continue;
            }

            foreach ($connection->table($table)->whereNull('deleted_at')->whereNotNull($column)->cursor(['id', $column]) as $row) {
                $mark((int) $row->{$column}, $type.':'.$row->id);
            }
        }

        if ($schema->hasTable('portfolio_item_media')) {
            foreach ($connection->table('portfolio_item_media as m')
                ->join('portfolio_items as p', 'p.id', '=', 'm.portfolio_item_id')
                ->whereNull('p.deleted_at')
                ->cursor(['m.media_asset_id', 'm.portfolio_item_id']) as $row) {
                $mark((int) $row->media_asset_id, 'portfolio_item:'.$row->portfolio_item_id);
            }
        }
```

A cover that is also in the gallery counts once (`portfolio_item:{id}` is one place), matching "usage counts
places, not references".

### S.7 `routes/console.php` — the view-log prune (§10.4)

`BlogPostView` is `MassPrunable` (a per-row `Prunable` would call `delete()`, which the D19 guard refuses):

```php
Schedule::command('model:prune', ['--model' => [\App\Models\Cms\BlogPostView::class]])->dailyAt('02:30');
```

### S.8 `tests/Support/index-manifest.php` — phase-04 rows (PRF-04, §13 Phase 24 row)

Append when the file exists (E4). Every FK column, every deferred id, `contact_inquiries.referral_code`, every
filtered status and every sorted column:

```php
    // phase-04. contact_inquiries carries more than 12 indexes on purpose: §2.20 names 14 plus three
    // column-level ones (course_id, email, source) and the two blameable foreign keys.
    'service_categories'        => [['slug'], ['is_active', 'sort_order'], ['sort_order'], ['image_media_id'], ['created_by'], ['updated_by']],
    'services'                  => [['slug'], ['status', 'is_featured', 'sort_order'], ['service_category_id', 'status'], ['image_media_id'], ['is_featured'], ['created_by'], ['updated_by']],
    'technologies'              => [['slug'], ['is_active', 'sort_order'], ['logo_media_id'], ['created_by'], ['updated_by']],
    'service_technology'        => [['service_id', 'technology_id'], ['technology_id']],
    'portfolio_categories'      => [['slug'], ['is_active', 'sort_order'], ['sort_order'], ['image_media_id'], ['created_by'], ['updated_by']],
    'portfolio_items'           => [['slug'], ['status', 'is_featured', 'sort_order'], ['portfolio_category_id', 'status'], ['completion_date'], ['cover_media_id'], ['client_id'], ['is_featured'], ['created_by'], ['updated_by']],
    'portfolio_item_technology' => [['portfolio_item_id', 'technology_id'], ['technology_id']],
    'portfolio_item_media'      => [['portfolio_item_id', 'media_asset_id'], ['portfolio_item_id', 'sort_order'], ['media_asset_id'], ['created_by']],
    'team_members'              => [['slug'], ['status', 'is_public', 'sort_order'], ['photo_media_id'], ['is_public'], ['department_id'], ['employee_id'], ['created_by'], ['updated_by']],
    'testimonials'              => [['status', 'type', 'sort_order'], ['status', 'is_featured'], ['type', 'client_id'], ['type', 'student_id'], ['approved_by'], ['submitted_by_user_id'], ['author_photo_media_id'], ['client_id'], ['student_id'], ['is_featured'], ['source'], ['created_by'], ['updated_by']],
    'student_reviews'           => [['status', 'is_featured', 'sort_order'], ['course_id', 'status'], ['student_id'], ['approved_by'], ['submitted_by_user_id'], ['student_photo_media_id'], ['is_featured'], ['source'], ['created_by'], ['updated_by']],
    'success_stories'           => [['status', 'is_featured', 'sort_order'], ['photo_media_id'], ['student_id'], ['course_id'], ['is_featured'], ['created_by'], ['updated_by']],
    'blog_categories'           => [['slug'], ['is_active', 'sort_order'], ['image_media_id'], ['created_by'], ['updated_by']],
    'blog_tags'                 => [['slug'], ['is_active'], ['created_by'], ['updated_by']],
    'blog_post_blog_tag'        => [['blog_post_id', 'blog_tag_id'], ['blog_tag_id']],
    'blog_posts'                => [['slug'], ['status', 'published_at'], ['blog_category_id', 'status', 'published_at'], ['author_id', 'status'], ['is_featured', 'status'], ['featured_image_media_id'], ['views_count'], ['published_at'], ['created_by'], ['updated_by']],
    'blog_post_views'           => [['blog_post_id', 'visitor_hash', 'viewed_on'], ['blog_post_id', 'viewed_on'], ['user_id']],
    'job_openings'              => [['slug'], ['status', 'deadline'], ['status', 'is_featured', 'sort_order'], ['employment_type', 'status'], ['created_by'], ['department_id'], ['work_mode'], ['deadline'], ['updated_by']],
    'job_applications'          => [['job_opening_id', 'email'], ['status', 'created_at'], ['assigned_to', 'status'], ['job_opening_id', 'status'], ['status_changed_by'], ['employee_id'], ['email'], ['source'], ['created_by'], ['updated_by']],
    'contact_inquiries'         => [['routed_type', 'routed_id'], ['inquiry_type', 'status'], ['routing_status', 'created_at'], ['is_spam', 'created_at'], ['status', 'created_at'], ['routing_target', 'routing_status'], ['service_id'], ['assigned_to'], ['read_by'], ['collaborator_id'], ['referral_code'], ['referral_visit_id'], ['course_id'], ['email'], ['source'], ['created_by'], ['updated_by']],
```

### S.9 Model contract other roles build on (no file to edit — read before writing services/controllers)

- **Not mass assignable, written with `forceFill()` by the owning service only:** `testimonials` / `student_reviews`
  `status`, `approved_by`, `approved_at`, `rejection_reason`, `is_featured` (ModerationService);
  `portfolio_items.cover_media_id` (PortfolioService); `blog_posts.views_count` and
  `job_openings.applications_count` (atomic `increment()` only); `job_openings.opened_at` / `closed_at`;
  `job_applications` pipeline state (`status`, `status_changed_*`, `rating`, `assigned_to`, `employee_id`,
  `internal_notes`, `interview_*`, `rejection_reason`); `contact_inquiries` handling + routing state and the
  **three referral snapshots** (`collaborator_id`, `referral_code`, `referral_visit_id` — a browser-posted value can
  never arrive through `fill()`, §11 test 62).
- `HasSlug` fills `slug` on `creating` only when empty (`SlugGenerator::make($source, $table, null, 'slug', 180|200)`)
  and never on update.
- `JobApplication` deletes its CV from the `local` disk in a `forceDeleted` model hook (the §2.19 observer).
  **Do not register a second observer** for it. A soft delete keeps the file.
- `BlogPostView` refuses Eloquent delete and update (D19); a duplicate `(blog_post_id, visitor_hash, viewed_on)`
  raises `UniqueConstraintViolationException` on `uq_blog_post_view_daily`.
- `ContactInquiry::scopeSelectVisibleColumns($user)` omits `TECHNICAL_COLUMNS` (IP, user agent, UTM, referrer,
  fill time, `spam_reason`) unless the user holds `contact_inquiries.view_logs` (F-12.4); `is_spam` stays
  selectable because the tabs filter on it. `ContactInquiry::allColumns()` must gain any column a later phase
  adds. `scopeTab()` keeps spam out of every tab but `spam`; `scopeAwaitingRouting()` is `routePending()`'s walk.
- Isolation scopes: `BlogPost::visibleTo()`, `ContactInquiry::visibleTo()` (never reads the snapshot, D37),
  `JobApplication::visibleTo()` (assigned **or** opening `created_by`). Public scopes: `X::public()` on every
  public model (§9.2); `JobOpening::businessToday()` is the display-timezone calendar day the deadline compares to.
- Enum constants: `InquiryType::TARGET_CRM_LEAD` / `TARGET_COURSE_INQUIRY`; closed string lists
  `JobOpening::SALARY_PERIODS`, `JobApplication::INTERVIEW_MODES`, `ContactInquiry::SPAM_REASONS`.

## Services, events, listeners, jobs, commands, DTOs and support classes (phase-04 role: services)

Verified before hand-off: `php -l` and `./vendor/bin/pint --test` clean on all 78 files below; a read-only smoke
script (booted the app, cache forced to `array`, no table touched) loaded every class, resolved every service from
the container, proved `InquiryRouter` resolves as one shared instance, found the three artisan commands registered,
and exercised `SlugGenerator`, `VideoUrl`, `SpamGuard` (token / honeypot / too_fast / duplicate / score),
`BlogService::readingMinutes()` / `allowedNext()` and the exception classes. A second script ran **153 behavioural
checks** against a disposable schema (`p4_scratch_services_verify`, created, migrated with every committed migration
plus the 15 staged files, then dropped — never `my_office` or `my_office_test`), with the V.2 listener map applied in
process: slugs incl. trashed and emoji fallback, reserved/numeric slug refusals, money round-trip, taxonomy
reassign-then-delete (categories and tags), technology detach, one `reordered` entry, the moderation transitions and
bulk approve (3 of 5), `review_edited`, blog publish/unpublish/re-publish keeping the first moment, one event per
transition, `publishDue()` idempotence, one-tag `syncTags`, view de-duplication (5 loads = 1 row, other UA, next day,
prefetch, author), inquiry routing (general, `target_unregistered`, `module_disabled`, `routePending`, idempotent
re-route, claim collision staying pending, throw → `failed` on attempt 3 with the target rolled back, honeypot spam,
not-spam re-route, `?ref=` verbatim, posted `collaborator_id` discarded), careers (open stamps, salary range, CV on the
private disk under a ULID name, duplicate email, `.php` renamed `.pdf`, pipeline refusals, interview slot,
`closeExpired`, `purge` removing files, soft vs force delete of an application, spam application storing nothing),
the gallery (cover rules, foreign-asset refusals, double attach, alt-text publish gate, force delete keeping the
asset), team/story/inquiry admin acts, the three commands, all seven widgets, all nine section providers and the
sitemap providers. No test suite was run.

### V.1 Files delivered (all new; nothing existing edited)

```
app/Contracts/Inquiry/InquiryTarget.php            §6.10.1 (ND-4 namespace)
app/Support/SlugGenerator.php                      §6.1 (RESERVED, PATTERN, make/isReserved/normalise; numeric = reserved)
app/Support/Cms/VideoUrl.php                       §6.9 YouTube/Vimeo allowlist + embed URL from the parsed id
app/Support/Cms/PublicFormRateLimits.php           §6.9 the public-contact / public-apply named limiters
app/Support/Cms/Sitemap/                           EntitySitemapProvider (abstract) + Service, Portfolio, BlogPost,
                                                   BlogCategory, BlogTag, JobOpening, Team providers (§13 Phase 3 row)
app/Support/Cms/Sections/                          MarketingSectionProvider (abstract) + Services, Portfolio, Team,
                                                   Testimonials, StudentReviews, SuccessStories, Blog, Careers, Contact
app/Services/Cms/Support/ContentHelper.php         shared write plumbing (media, sanitising, slugs, SEO, audit, cache)
app/Services/Cms/Data/SpamVerdict.php              readonly DTO (isSpam, reason, score, filledInSeconds)
app/Services/Cms/Exceptions/ContentRuleException.php   422 domain refusal — extends ValidationException
app/Services/Cms/Exceptions/JobClosedException.php     §6.8 apply() invariant 1
app/Services/Cms/TaxonomyService.php  ContentOrderService.php  PortfolioService.php  ServiceContentService.php
app/Services/Cms/TeamService.php  SuccessStoryService.php  ModerationService.php  ReviewContentService.php
app/Services/Cms/BlogService.php  BlogViewCounter.php  TestimonialFeed.php
app/Services/Cms/JobOpeningService.php  JobApplicationService.php  ApplicationCvService.php
app/Services/Cms/SpamGuard.php  InquiryRouter.php (#[Singleton])  ContactInquiryService.php
app/Events/Cms/          ContactInquirySubmitted ContactInquiryRouted JobApplicationReceived JobApplicationStatusChanged
                         TestimonialApproved StudentReviewApproved TestimonialSubmitted BlogPostPublished
                         (all ShouldDispatchAfterCommit)
app/Listeners/Cms/       RouteContactInquiry LogInquiryRouting LogApplicationStage FlushPublicContentCache (sync)
                         NotifyStaffOfInquiry NotifyHrOfApplication NotifyStaffOfPendingModeration
                         NotifyAuthorOfPublication PingSitemap (queued) + Concerns/NotifiesStaff
app/Jobs/Cms/            RouteContactInquiry (tries 3, backoff 60/300/900)  RecordBlogPostView (tries 1)
app/Notifications/Cms/   NewContactInquiryNotification NewJobApplicationNotification PendingModerationNotification
                         PostPublishedNotification + Concerns/BuildsCmsNotification
app/Console/Commands/    PublishScheduledPosts (blog:publish-scheduled) RoutePendingInquiries (inquiries:route-pending)
                         CloseExpiredJobOpenings (careers:close-expired) — auto-discovered from app/Console/Commands
app/Dashboard/Cms/       NewInquiries InquiryRoutingBacklog PendingModeration NewApplications OpenJobs BlogActivity
                         TopViewedPosts widgets — deliberately NOT under app/Dashboard/Widgets (see V.7)
```

`HasSlug` (schema role) and `JobApplication`'s `forceDeleted` CV hook already exist; no observer is added here.

### V.2 `app/Providers/EventListenerServiceProvider.php` — listener map (event discovery is off; every listener must be listed)

Imports — add:

```php
use App\Events\Cms\BlogPostPublished;
use App\Events\Cms\ContactInquiryRouted;
use App\Events\Cms\ContactInquirySubmitted;
use App\Events\Cms\JobApplicationReceived;
use App\Events\Cms\JobApplicationStatusChanged;
use App\Events\Cms\StudentReviewApproved;
use App\Events\Cms\TestimonialApproved;
use App\Events\Cms\TestimonialSubmitted;
use App\Listeners\Cms\FlushPublicContentCache;
use App\Listeners\Cms\LogApplicationStage;
use App\Listeners\Cms\LogInquiryRouting;
use App\Listeners\Cms\NotifyAuthorOfPublication;
use App\Listeners\Cms\NotifyHrOfApplication;
use App\Listeners\Cms\NotifyStaffOfInquiry;
use App\Listeners\Cms\NotifyStaffOfPendingModeration;
use App\Listeners\Cms\PingSitemap;
use App\Listeners\Cms\RouteContactInquiry;
```

Append inside `LISTENERS` (after `Logout::class => [RecordLogout::class],` or the last existing entry):

```php
        // phase-04 §10.1
        ContactInquirySubmitted::class => [RouteContactInquiry::class, NotifyStaffOfInquiry::class],
        ContactInquiryRouted::class => [LogInquiryRouting::class],
        JobApplicationReceived::class => [NotifyHrOfApplication::class],
        JobApplicationStatusChanged::class => [LogApplicationStage::class],
        TestimonialApproved::class => [FlushPublicContentCache::class],
        StudentReviewApproved::class => [FlushPublicContentCache::class],
        TestimonialSubmitted::class => [NotifyStaffOfPendingModeration::class],
        BlogPostPublished::class => [NotifyAuthorOfPublication::class, FlushPublicContentCache::class, PingSitemap::class],
```

**Load-bearing for the acceptance tests:** `LogInquiryRouting` writes the only "routed" activity entry (§11 test 44) and
`LogApplicationStage` writes the only stage-change entry (§11 test 38) — the services fire the events and do not write
those two entries themselves (§10.1 names the listeners as the writers). Without this map both tests fail.

### V.3 `app/Providers/AppServiceProvider.php` — bindings, limiters, widgets, sitemap providers

Imports — add:

```php
use App\Dashboard\Cms\BlogActivityWidget;
use App\Dashboard\Cms\InquiryRoutingBacklogWidget;
use App\Dashboard\Cms\NewApplicationsWidget;
use App\Dashboard\Cms\NewInquiriesWidget;
use App\Dashboard\Cms\OpenJobsWidget;
use App\Dashboard\Cms\PendingModerationWidget;
use App\Dashboard\Cms\TopViewedPostsWidget;
use App\Services\Cms\InquiryRouter;
use App\Support\Cms\PublicFormRateLimits;
use App\Support\Cms\Sitemap\BlogCategorySitemapProvider;
use App\Support\Cms\Sitemap\BlogPostSitemapProvider;
use App\Support\Cms\Sitemap\BlogTagSitemapProvider;
use App\Support\Cms\Sitemap\JobOpeningSitemapProvider;
use App\Support\Cms\Sitemap\PortfolioSitemapProvider;
use App\Support\Cms\Sitemap\ServiceSitemapProvider;
use App\Support\Cms\Sitemap\TeamSitemapProvider;
use App\Support\DashboardRegistry;
```

In `register()`, after the `SettingsRepository` singleton:

```php
        // phase-04 §6.10.1: one router per process, so a target registered by Phase 5 / 14-17 is the one routing
        // sees. (The class also carries #[Singleton]; the explicit binding documents it.)
        $this->app->singleton(InquiryRouter::class);
```

In `boot()`, before `$this->configureFromSettings();`:

```php
        $this->registerPhase04();
```

and add the method:

```php
    /**
     * phase-04: the public-form rate limiters (§6.9), the content dashboard widgets (§8.12) and one sitemap
     * provider per public entity (§13 Phase 3 row, D23). Each piece degrades on its own.
     */
    private function registerPhase04(): void
    {
        PublicFormRateLimits::register();

        try {
            DashboardRegistry::registerMany([
                NewInquiriesWidget::class,
                InquiryRoutingBacklogWidget::class,
                PendingModerationWidget::class,
                NewApplicationsWidget::class,
                OpenJobsWidget::class,
                BlogActivityWidget::class,
                TopViewedPostsWidget::class,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }

        $providers = [
            new ServiceSitemapProvider,
            new PortfolioSitemapProvider,
            new BlogPostSitemapProvider,
            new BlogCategorySitemapProvider,
            new BlogTagSitemapProvider,
            new JobOpeningSitemapProvider,
            new TeamSitemapProvider,
        ];

        foreach ($providers as $provider) {
            if (class_exists(\App\Support\SitemapRegistry::class) && method_exists(\App\Support\SitemapRegistry::class, 'register')) {
                \App\Support\SitemapRegistry::register($provider->key(), $provider);
            } else {
                \App\Services\Cms\SitemapGenerator::extend($provider->key(), $provider);
            }
        }
    }
```

(`Throwable` is already imported in `AppServiceProvider`. If the Phase 3 integration created `App\Support\SitemapRegistry`
with a typed `SitemapUrlProvider` parameter, the providers already expose the exact shape — `key(): string`,
`urls(): iterable` — so adding `implements \App\Contracts\Cms\SitemapUrlProvider` to `EntitySitemapProvider` and
`TeamSitemapProvider` is the only change.)

### V.4 `routes/console.php` — scheduled tasks (§10.4; the `model:prune` row is S.7)

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('blog:publish-scheduled')->everyMinute()->withoutOverlapping(5)->runInBackground();
Schedule::command('inquiries:route-pending')->hourly()->withoutOverlapping(10);
Schedule::command('careers:close-expired')->dailyAt('00:10');
```

`inquiries:route-pending` honours `website.inquiry_auto_route = false` (reports and exits) unless `--force` is given.

### V.5 Settings keys read (all declared by the §5 `SettingsRegistry` block; every read has a safe default)

`website.contact_min_submit_seconds`, `website.spam_blocklist`, `website.contact_rate_per_hour`,
`website.inquiry_auto_route`, `website.inquiry_default_assignee_id`, `website.contact_notify_emails`,
`website.careers_notify_emails`, `website.careers_enabled`, `website.cv_max_mb`, `website.cv_allowed_types`,
`website.testimonial_auto_approve`, `website.blog_related_count`, `website.blog_view_dedupe_minutes`,
`website.reviews_per_page`, `website.team_page_enabled`, `website.portfolio_detail_enabled`,
`website.contact_budget_options`, plus existing `security.max_upload_mb`, `maintenance.contact_form_enabled`.
None is read from `app/Http`, `app/Dashboard` or a view, so the settings-truth scans are unaffected before §5 lands.

Optional, `config/cms.php` (only if the Phase 3 integration creates that file): `'bot_user_agents' => [...]`.
`BlogViewCounter::DEFAULT_BOT_PATTERNS` applies when the key is absent — nothing breaks without it.

### V.6 Public section types (§8.11, §13 Phase 3 row) — provider classes for `SectionRegistry::register()`

The registry entries themselves (placements, fields, partials) belong to the registries/wiring role. Each Phase 4
type should be registered with `'is_live' => true` and the provider below (they cache themselves under the CMS
version stamp for 10 minutes). Options read from the section content: `limit` (1-24), `featured_only`, and
`category` (services / portfolio / blog, a category slug), `type` (testimonials), `course_id` (reviews / stories).

| section key | provider | data returned (besides `available`) |
|---|---|---|
| `services` | `App\Support\Cms\Sections\ServicesSectionProvider` | `items[]` {id,name,slug,url,short_description,icon,image,starting_price (null when hidden),price_note,is_featured,category{name,slug},technologies[]}, `index_url` |
| `portfolio` | `…\PortfolioSectionProvider` | `items[]` {id,title,slug,url (null when detail pages off),client_name,summary,cover,completion_date,is_featured,category,technologies[]}, `index_url` |
| `team` | `…\TeamSectionProvider` | `items[]` {id,name,slug,designation,department,bio,skills[],experience_years,experience_label,photo,portfolio_url,social_links[{platform,label,icon,url}]}, `index_url` |
| `testimonials` | `…\TestimonialsSectionProvider` | `items[]` {id,type,author_name,author_designation,author_company,course_name,rating,review,review_date,photo,is_featured} |
| `student_reviews` | `…\StudentReviewsSectionProvider` | `items[]` {id,student_name,course_name,rating,review,video_embed_url,photo,is_featured} |
| `success_stories` | `…\SuccessStoriesSectionProvider` | `items[]` {id,student_name,course_name,headline,story (sanitised HTML),achievement,company_name,platform,video_embed_url,photo,is_featured} |
| `blog` | `…\BlogSectionProvider` | `items[]` {id,title,slug,url,excerpt,published_at (ISO-8601),reading_minutes,image,image_alt,author,category{name,slug,url}}, `index_url` |
| `careers` | `…\CareersSectionProvider` | `items[]` {id,title,slug,url,department,location,work_mode{value,label},employment_type{value,label},deadline,salary{min,max,period} or null}, `index_url` |
| `contact` | `…\ContactSectionProvider` | `form_enabled`, `action_url`, `inquiry_types[]`, `services[]` {id,name}, `budget_options[]` — **no** spam token (the form component mints `SpamGuard::signedTimestamp()` per request) |

Images are `MediaService::toSnapshot()` arrays; dates are strings for `app_date()`; money is the stored decimal string for `money()`.

### V.7 Dashboard widgets (§8.12) — registered explicitly, views owned by the views role

They live in `app/Dashboard/Cms/`, **not** `app/Dashboard/Widgets/`: the registry auto-discovers that folder, and a
discovered widget would render (its permission already exists in Phase 1) before its view and its tables exist —
breaking the Phase 2 dashboard tests. Registered in V.3. Each `view()` defaults to
`admin.dashboard.widgets.<kebab key>`; every `data()` returns `available: false` (and an honest empty shape) if its
table cannot be read.

| key → view | permission / module | `data()` keys |
|---|---|---|
| `new_inquiries` → `…widgets.new-inquiries` | `contact_inquiries.view_any` | available, total, types[] {value,label,color,count,href}, delta, range_label, previous_label |
| `inquiry_routing_backlog` → `…inquiry-routing-backlog` | `contact_inquiries.view_any` | available, total, failed, targets[] {key,label,count}, reasons[] {reason,count} |
| `pending_moderation` → `…pending-moderation` | `testimonials.view_any` | available, total, queues[] {key,label,count,href} |
| `new_applications` → `…new-applications` | `job_applications.view_any` | available, total, funnel[] {value,label,color,count,href}, delta, range_label, previous_label |
| `open_jobs` → `…open-jobs` | `jobs.view_any` | available, open, closing_soon, expired_still_open |
| `blog_activity` → `…blog-activity` | `blog_posts.view_any` | available, published, scheduled, drafts, range_label, links{published,scheduled,drafts} |
| `top_viewed_posts` → `…top-viewed-posts` | `blog_posts.view_reports` | available, range_label, posts[] {id,title,views,status,stats_url} |

### V.8 Service API the controllers call (reconciliation reference)

| Route / screen | Call |
|---|---|
| taxonomy CRUD, toggle, delete-with-reassign, "12 services" column | `TaxonomyService::store/update/toggleActive/delete($term, ?$reassignTo)`, `childrenCount($term)` |
| every `…reorder` | `ContentOrderService::reorder(Model::class, $ids)` |
| services | `ServiceContentService::store($data, ?$image, $technologyIds)`, `update`, `changeStatus`, `toggleFeatured`, `delete` |
| portfolio + gallery | `PortfolioService::store($data, $images, $technologyIds)`, `update`, `changeStatus`, `toggleFeatured`, `delete`, `forceDelete`, `addImages`, `attachAssets($item, $mediaAssetIds)`, `detachImage`, `reorderImages`, `setCover`, `updateCaption` |
| team | `TeamService::store/update($member, $data, ?$photo)`, `changeStatus`, `togglePublic`, `delete` |
| success stories | `SuccessStoryService::store/update`, `changeStatus`, `toggleFeatured`, `delete` |
| testimonial / student review CRUD | `ReviewContentService::storeTestimonial($data, ?$photo, ContentSource $source = Admin, ?Request)`, `updateTestimonial`, `storeStudentReview`, `updateStudentReview`, `delete` |
| approve / reject / reset / feature / bulk | `ModerationService::approve($r, ?$note)`, `reject($r, $reason)`, `reset($r, $reason)`, `toggleFeatured`, `bulkApprove(Model::class, $ids): int` |
| blog editor + buttons | `BlogService::store($data, ?$image, $tagNames)`, `update`, `publish`, `schedule($post, CarbonInterface $at)`, `unpublish`, `archive`, `delete`, `allowedNext`, `related` (public) |
| public post page | `BlogViewCounter::track($post, $request)` (never on the preview route); stats screen `dailyTotals($post, DateRange)` |
| jobs | `JobOpeningService::store/update/changeStatus($job, $status, ?$reason)/delete/purge`, `closeExpired()` |
| applications | `JobApplicationService::apply($job, $data, $cv, $request)` (public), `changeStatus($a, $to, ['reason','interview_at','interview_mode','interview_location'])`, `assign($a, ?User)`, `saveNotes($a, ?$notes, ?$rating)`, `delete`, `forceDelete` |
| CV download / form hints | `ApplicationCvService::download($a)` (writes the `cv_downloaded` §107 entry), `maxKilobytes()`, `maxLabel()`, `allowedExtensions()` |
| public contact form | `ContactInquiryService::submit($data, $request)` — returns the row for spam too; answer identically |
| inquiry queue | `ContactInquiryService::markRead`, `assign($i, ?User)`, `changeStatus($i, $to, ?$note)`, `saveNotes`, `markSpam($i, $reason)`, `markNotSpam`, `routeNow($i)`, `delete`; `InquiryRouter::routePending()`, `canRoute($i)`, `waitingReason($i)` |
| contact form component | `SpamGuard::honeypotField()` (`website_url`), `timestampField()` (`form_token`), `signedTimestamp()` |

**Refusals** are `App\Services\Cms\Exceptions\ContentRuleException` (and `JobClosedException`), both
`ValidationException` subclasses: uncaught they already answer 422 JSON / redirect-back-with-errors; a controller that
wants the toast catches `ValidationException` and adds it. `UnsupportedUploadException` from `MediaService` is converted
to a 422 on the form field (`image`, `photo`, `images.N`) inside the services.

Methods that go beyond the literal §6 signatures (flag for the contract review, all additive): `TaxonomyService::childrenCount`;
`PortfolioService::attachAssets/updateCaption/changeStatus/toggleFeatured`; `TeamService::changeStatus`;
`SuccessStoryService::delete`; `JobOpeningService::delete`; `JobApplicationService::delete/forceDelete`;
`ContactInquiryService::saveNotes/routeNow/delete`; `BlogService::publishDue` (the command's body);
`BlogViewCounter::track/recordCaptured`; `InquiryRouter::canRoute/waitingReason`; the whole `ReviewContentService`
(the contract names no class for testimonial/review CRUD, yet §5 auto-approve, §10.1 `TestimonialSubmitted` and §10.5
"review text edited" need one home) and `TestimonialFeed` (named in §8.11 without a signature).

### V.9 Hooks for later phases (no action now)

- **Phase 5 / 14-17**: `app(InquiryRouter::class)->register(new CrmLeadInquiryTarget)` from their provider, then
  `php artisan inquiries:route-pending`.
- **Phase 9**: bind `ContactInquiryService::REFERRAL_RESOLVER` (`'cms.inquiry.referral_resolver'`) to a
  `callable(Request $request, ?string $code): array{collaborator_id?: int|null, referral_visit_id?: int|null}` to fill
  the two id snapshots at submission; unbound, the `?ref=` code is stored verbatim and both ids stay null, and a
  browser-posted `collaborator_id` is never read (§11 tests 61-62).
- **Phase 22**: the four notifications use the `database` channel automatically once a `notifications` table exists
  (checked once per process); until then they mail only and never fail the listener.

### V.10 Risks and notes for verify / review

1. **Every visible content change bumps the public cache** (`CacheVersion::bumpAfterCommit`), and Phase 3's bump
   writes its own activity row. A test that counts *all* activity rows after a reorder or an approval will see that
   extra row; §11 test 60's "one activity entry" is the `reordered` entry (event `reordered`), not the cache row.
2. **Status acts are logged once, by name.** Status moves, moderation, featuring, cover changes, spam marking and
   blog transitions save the model with its generic logging suppressed and write one named entry (`status_changed`,
   `approved`, `rejected`, `moderation_reset`, `featured`, `published`, `scheduled`, `rescheduled`, `unpublished`,
   `archived`, `marked_spam`, `slug_changed`, `reordered`, `review_edited`, `cv_downloaded`, `routing_failed`, …).
   Plain create / update / delete keep the models' `LogsActivityWithContext` rows.
3. **`SpamGuard` duplicate detection uses the default cache store** (`Cache::add`, 10 minutes). On the database
   cache store it writes a `cache` row per genuine submission; with the `array` store (tests) it is per process.
4. **`config/filesystems.php` has `'serve' => true` on the private `local` disk** (Laravel 12 default), which exposes a
   signed-URL route for private files. Nothing in Phase 4 mints such a URL and CVs are streamed only by
   `admin.job-applications.cv`, but D21 says "no signed URL": the security review should decide whether to set it to
   `false` (config is outside this role).
5. **S.6 (`MediaService` counting Phase 4 sources) is required for §11 test 13's "`MediaPolicy::delete()` refuses while
   in use"** — the services already call `recountUsage()` after every attach/detach/force delete.
6. `RecordBlogPostView` runs through `dispatchAfterResponse()` — synchronously after the response, not on a worker;
   layer 3 (the session flag) is set in `track()` during the request because the session is already saved by then.
7. `InquiryRouter` rethrows a deadlock (InnoDB rolls back the whole transaction, so nothing can be recorded) and lets
   the queued job retry; every other target exception is recorded on the inquiry and never reaches `failed_jobs`.


## Controllers, Form Requests and middleware (phase-04 role: controllers/requests/middleware)

Verified before hand-off: `php -l` and `./vendor/bin/pint --test` clean on all 80 files below; a read-only reflection
script loaded every class (trait composition and inheritance compile), and a second one confirmed that **every** service
method, model scope/relation, policy method, enum case and constant the controllers and requests call exists on disk
(`missing: 0`). No route was registered, no test was run, nothing touched the database.

### C.1 Files delivered (all new; nothing existing edited)

```
app/Http/Controllers/Admin/Cms/Concerns/RespondsForContent.php   extends Phase 3's RespondsForCms: DomainException → 422,
                                                                  withAvailable()/loadAvailable()/withAvailableCounts(),
                                                                  countBy(), maxUploadMb(), wantsTrashed(), 404 helpers
app/Http/Controllers/Admin/Cms/TaxonomyController.php            abstract — the five §8.1 lists
app/Http/Controllers/Admin/Cms/{ServiceCategory,PortfolioCategory,BlogCategory,BlogTag,Technology}Controller.php
app/Http/Controllers/Admin/Cms/ServiceController.php              §8.2
app/Http/Controllers/Admin/Cms/PortfolioItemController.php        §8.3
app/Http/Controllers/Admin/Cms/PortfolioImageController.php       §8.3 gallery manager
app/Http/Controllers/Admin/Cms/TeamMemberController.php           §8.4
app/Http/Controllers/Admin/Cms/ModeratedContentController.php     abstract — the §8.5 queues
app/Http/Controllers/Admin/Cms/{Testimonial,StudentReview}Controller.php
app/Http/Controllers/Admin/Cms/SuccessStoryController.php         §8.6
app/Http/Controllers/Admin/Cms/BlogPostController.php             §8.7 list, editor, calendar, stats, preview link
app/Http/Controllers/Admin/Cms/JobOpeningController.php           §8.8
app/Http/Controllers/Admin/Cms/JobApplicationController.php       §8.9
app/Http/Controllers/Admin/Cms/ContactInquiryController.php       §8.10
app/Http/Controllers/Site/Concerns/ComposesContentPages.php       $site payload, SEO, spam token, withheld amounts
app/Http/Controllers/Site/{Service,Portfolio,Team,Blog,Career,Contact}Controller.php   §7.1 / §8.11
app/Http/Requests/Cms/ContentListRequest.php                      every admin list/export query string (hostile arrays → 422)
app/Http/Requests/Cms/SiteListRequest.php                         public listings' query string
app/Http/Requests/Cms/TaxonomyDefinition.php                      the five taxonomies declared once (fields, image slot, SEO, children)
app/Http/Requests/Cms/{Store,Update,Delete}TaxonomyRequest.php
app/Http/Requests/Cms/{Store,Update}{Service,PortfolioItem,TeamMember,Testimonial,StudentReview,SuccessStory,BlogPost,JobOpening}Request.php
app/Http/Requests/Cms/StorePortfolioImagesRequest.php  UpdatePortfolioImageCaptionRequest.php  ReorderRequest.php
app/Http/Requests/Cms/UpdateContentStatusRequest.php  ModerationRequest.php  BulkModerationRequest.php
app/Http/Requests/Cms/ScheduleBlogPostRequest.php  ChangeJobOpeningStatusRequest.php  ChangeApplicationStatusRequest.php
app/Http/Requests/Cms/UpdateJobApplicationRequest.php  AssignRequest.php  UpdateContactInquiryRequest.php
app/Http/Requests/Cms/ChangeInquiryStatusRequest.php  MarkInquirySpamRequest.php
app/Http/Requests/Cms/PublicContactRequest.php  PublicJobApplicationRequest.php
app/Http/Requests/Cms/Concerns/  ResolvesContentModule ValidatesContentSlug ValidatesContentImage DelegatesSeoRules
                                 NormalisesContentInput ValidatesDeferredLinks ValidatesVideoUrl ValidatesTaxonomy
                                 ValidatesService ValidatesPortfolioItem ValidatesTeamMember ValidatesTestimonial
                                 ValidatesStudentReview ValidatesSuccessStory ValidatesBlogPost ValidatesJobOpening
```

Every write request extends Phase 3's `CmsFormRequest` (its `authorize()` repeats the route's `can:`); shared requests
(`ReorderRequest`, `AssignRequest`, `ModerationRequest`, …) derive that permission from the **matched route name**, never
from a form field. No request under `app/Http/Requests/Cms/` restates a `seo_meta` rule: the SEO block is
`array_merge(…, SeoService::rules())` through `DelegatesSeoRules` (ND-13, §11 test 65).

### C.2 Middleware — nothing new to register

§7.3's `site_module` already exists as `App\Http\Middleware\EnsureSiteModuleEnabled` (Phase 3 shipped the class, and
`bootstrap/app.php` already aliases it). No other middleware is needed: the four setting gates (`website.team_page_enabled`,
`website.portfolio_detail_enabled`, `website.careers_enabled`, `maintenance.contact_form_enabled`) are checked in the
controllers and answer the branded 404. The two named limiters used below (`throttle:public-contact`,
`throttle:public-apply`, with the 429 rendered in the site layout) are **V.3**'s `PublicFormRateLimits::register()` —
without it both POST routes throw `MissingRateLimiterException`.

### C.3 `routes/admin.php` — inside the existing `Route::prefix('admin')->name('admin.')->middleware(['auth', 'active', 'panel:admin'])` group

Imports — add:

```php
use App\Http\Controllers\Admin\Cms\BlogCategoryController;
use App\Http\Controllers\Admin\Cms\BlogPostController;
use App\Http\Controllers\Admin\Cms\BlogTagController;
use App\Http\Controllers\Admin\Cms\ContactInquiryController;
use App\Http\Controllers\Admin\Cms\JobApplicationController;
use App\Http\Controllers\Admin\Cms\JobOpeningController;
use App\Http\Controllers\Admin\Cms\PortfolioCategoryController;
use App\Http\Controllers\Admin\Cms\PortfolioImageController;
use App\Http\Controllers\Admin\Cms\PortfolioItemController;
use App\Http\Controllers\Admin\Cms\ServiceCategoryController;
use App\Http\Controllers\Admin\Cms\ServiceController;
use App\Http\Controllers\Admin\Cms\StudentReviewController;
use App\Http\Controllers\Admin\Cms\SuccessStoryController;
use App\Http\Controllers\Admin\Cms\TeamMemberController;
use App\Http\Controllers\Admin\Cms\TechnologyController;
use App\Http\Controllers\Admin\Cms\TestimonialController;
```

Paste at the end of the group body (every literal segment — `create`, `export`, `reorder`, `calendar`, `bulk-approve`,
`route-pending` — is declared before its `{parameter}` sibling, and every parameter is `whereNumber`):

```php
        /*
        |------------------------------------------------------------------
        | phase-04 §7.2 — software-house marketing modules
        |------------------------------------------------------------------
        | One `module:` per block and exactly one `can:` per route, naming a permission of that module.
        | Controllers repeat the same `authorize()` and add the policy's row rule. Restore routes are not
        | registered: §4's pinned ability sets carry no `restore` (§7.2 last paragraph).
        */

        // §8.1 — the five taxonomies share one controller shape; `{term}` is resolved per list.
        $taxonomy = static function (string $uri, string $module, string $controller, bool $reorder): void {
            Route::middleware('module:'.$module)->group(static function () use ($uri, $module, $controller, $reorder): void {
                Route::get($uri, [$controller, 'index'])->middleware("can:{$module}.view_any")->name("{$uri}.index");
                Route::get("{$uri}/create", [$controller, 'create'])->middleware("can:{$module}.create")->name("{$uri}.create");
                Route::post($uri, [$controller, 'store'])->middleware("can:{$module}.create")->name("{$uri}.store");

                if ($reorder) {
                    Route::post("{$uri}/reorder", [$controller, 'reorder'])->middleware("can:{$module}.edit")->name("{$uri}.reorder");
                }

                Route::get("{$uri}/{term}", [$controller, 'show'])->whereNumber('term')->middleware("can:{$module}.view")->name("{$uri}.show");
                Route::get("{$uri}/{term}/edit", [$controller, 'edit'])->whereNumber('term')->middleware("can:{$module}.edit")->name("{$uri}.edit");
                Route::put("{$uri}/{term}", [$controller, 'update'])->whereNumber('term')->middleware("can:{$module}.edit")->name("{$uri}.update");
                // Not in §7.2 (see C.5 #2): the list's immediate active switch; `update` with `is_active` alone does the same.
                Route::post("{$uri}/{term}/toggle", [$controller, 'toggle'])->whereNumber('term')->middleware("can:{$module}.change_status")->name("{$uri}.toggle");
                Route::delete("{$uri}/{term}", [$controller, 'destroy'])->whereNumber('term')->middleware("can:{$module}.delete")->name("{$uri}.destroy");
            });
        };

        $taxonomy('service-categories', 'service_categories', ServiceCategoryController::class, true);
        $taxonomy('technologies', 'technologies', TechnologyController::class, false);
        $taxonomy('portfolio-categories', 'portfolio_categories', PortfolioCategoryController::class, true);
        $taxonomy('blog-categories', 'blog_categories', BlogCategoryController::class, false);
        $taxonomy('blog-tags', 'blog_tags', BlogTagController::class, false);

        // §8.2 Services
        Route::middleware('module:services')->group(static function (): void {
            Route::get('services', [ServiceController::class, 'index'])->middleware('can:services.view_any')->name('services.index');
            Route::get('services/export', [ServiceController::class, 'export'])->middleware('can:services.export')->name('services.export');
            Route::get('services/create', [ServiceController::class, 'create'])->middleware('can:services.create')->name('services.create');
            Route::post('services', [ServiceController::class, 'store'])->middleware('can:services.create')->name('services.store');
            Route::post('services/reorder', [ServiceController::class, 'reorder'])->middleware('can:services.edit')->name('services.reorder');
            Route::get('services/{service}', [ServiceController::class, 'show'])->whereNumber('service')->middleware('can:services.view')->name('services.show');
            Route::get('services/{service}/edit', [ServiceController::class, 'edit'])->whereNumber('service')->middleware('can:services.edit')->name('services.edit');
            Route::put('services/{service}', [ServiceController::class, 'update'])->whereNumber('service')->middleware('can:services.edit')->name('services.update');
            Route::delete('services/{service}', [ServiceController::class, 'destroy'])->whereNumber('service')->middleware('can:services.delete')->name('services.destroy');
            Route::post('services/{service}/status', [ServiceController::class, 'status'])->whereNumber('service')->middleware('can:services.change_status')->name('services.status');
            Route::post('services/{service}/featured', [ServiceController::class, 'featured'])->whereNumber('service')->middleware('can:services.change_status')->name('services.featured');
        });

        // §8.3 Portfolio + gallery manager
        Route::middleware('module:portfolio')->group(static function (): void {
            Route::get('portfolio', [PortfolioItemController::class, 'index'])->middleware('can:portfolio.view_any')->name('portfolio.index');
            Route::get('portfolio/create', [PortfolioItemController::class, 'create'])->middleware('can:portfolio.create')->name('portfolio.create');
            Route::post('portfolio', [PortfolioItemController::class, 'store'])->middleware('can:portfolio.create')->name('portfolio.store');
            Route::post('portfolio/reorder', [PortfolioItemController::class, 'reorder'])->middleware('can:portfolio.edit')->name('portfolio.reorder');
            Route::get('portfolio/{item}', [PortfolioItemController::class, 'show'])->whereNumber('item')->middleware('can:portfolio.view')->name('portfolio.show');
            Route::get('portfolio/{item}/edit', [PortfolioItemController::class, 'edit'])->whereNumber('item')->middleware('can:portfolio.edit')->name('portfolio.edit');
            Route::put('portfolio/{item}', [PortfolioItemController::class, 'update'])->whereNumber('item')->middleware('can:portfolio.edit')->name('portfolio.update');
            Route::delete('portfolio/{item}', [PortfolioItemController::class, 'destroy'])->whereNumber('item')->middleware('can:portfolio.delete')->name('portfolio.destroy');
            Route::post('portfolio/{item}/status', [PortfolioItemController::class, 'status'])->whereNumber('item')->middleware('can:portfolio.change_status')->name('portfolio.status');
            Route::post('portfolio/{item}/featured', [PortfolioItemController::class, 'featured'])->whereNumber('item')->middleware('can:portfolio.change_status')->name('portfolio.featured');

            Route::post('portfolio/{item}/images', [PortfolioImageController::class, 'store'])->whereNumber('item')->middleware('can:portfolio.upload')->name('portfolio.images.store');
            Route::post('portfolio/{item}/images/reorder', [PortfolioImageController::class, 'reorder'])->whereNumber('item')->middleware('can:portfolio.edit')->name('portfolio.images.reorder');
            Route::post('portfolio/{item}/images/{image}/cover', [PortfolioImageController::class, 'cover'])->whereNumber(['item', 'image'])->middleware('can:portfolio.edit')->name('portfolio.images.cover');
            // Not in §7.2 (see C.5 #2): the per-attachment caption of §8.3.
            Route::put('portfolio/{item}/images/{image}', [PortfolioImageController::class, 'caption'])->whereNumber(['item', 'image'])->middleware('can:portfolio.edit')->name('portfolio.images.update');
            Route::delete('portfolio/{item}/images/{image}', [PortfolioImageController::class, 'destroy'])->whereNumber(['item', 'image'])->middleware('can:portfolio.edit')->name('portfolio.images.destroy');
        });

        // §8.4 Team
        Route::middleware('module:team')->group(static function (): void {
            Route::get('team', [TeamMemberController::class, 'index'])->middleware('can:team.view_any')->name('team.index');
            Route::get('team/create', [TeamMemberController::class, 'create'])->middleware('can:team.create')->name('team.create');
            Route::post('team', [TeamMemberController::class, 'store'])->middleware('can:team.create')->name('team.store');
            Route::post('team/reorder', [TeamMemberController::class, 'reorder'])->middleware('can:team.edit')->name('team.reorder');
            Route::get('team/{member}', [TeamMemberController::class, 'show'])->whereNumber('member')->middleware('can:team.view')->name('team.show');
            Route::get('team/{member}/edit', [TeamMemberController::class, 'edit'])->whereNumber('member')->middleware('can:team.edit')->name('team.edit');
            Route::put('team/{member}', [TeamMemberController::class, 'update'])->whereNumber('member')->middleware('can:team.edit')->name('team.update');
            Route::delete('team/{member}', [TeamMemberController::class, 'destroy'])->whereNumber('member')->middleware('can:team.delete')->name('team.destroy');
            Route::post('team/{member}/status', [TeamMemberController::class, 'status'])->whereNumber('member')->middleware('can:team.change_status')->name('team.status');
            Route::post('team/{member}/visibility', [TeamMemberController::class, 'visibility'])->whereNumber('member')->middleware('can:team.change_status')->name('team.visibility');
        });

        // §8.5 Testimonials (moderation queue)
        Route::middleware('module:testimonials')->group(static function (): void {
            Route::get('testimonials', [TestimonialController::class, 'index'])->middleware('can:testimonials.view_any')->name('testimonials.index');
            Route::get('testimonials/create', [TestimonialController::class, 'create'])->middleware('can:testimonials.create')->name('testimonials.create');
            Route::post('testimonials', [TestimonialController::class, 'store'])->middleware('can:testimonials.create')->name('testimonials.store');
            Route::post('testimonials/bulk-approve', [TestimonialController::class, 'bulkApprove'])->middleware('can:testimonials.approve')->name('testimonials.bulk-approve');
            Route::get('testimonials/{testimonial}', [TestimonialController::class, 'show'])->whereNumber('testimonial')->middleware('can:testimonials.view')->name('testimonials.show');
            Route::get('testimonials/{testimonial}/edit', [TestimonialController::class, 'edit'])->whereNumber('testimonial')->middleware('can:testimonials.edit')->name('testimonials.edit');
            Route::put('testimonials/{testimonial}', [TestimonialController::class, 'update'])->whereNumber('testimonial')->middleware('can:testimonials.edit')->name('testimonials.update');
            Route::delete('testimonials/{testimonial}', [TestimonialController::class, 'destroy'])->whereNumber('testimonial')->middleware('can:testimonials.delete')->name('testimonials.destroy');
            Route::post('testimonials/{testimonial}/approve', [TestimonialController::class, 'approve'])->whereNumber('testimonial')->middleware('can:testimonials.approve')->name('testimonials.approve');
            Route::post('testimonials/{testimonial}/reject', [TestimonialController::class, 'reject'])->whereNumber('testimonial')->middleware('can:testimonials.reject')->name('testimonials.reject');
            Route::post('testimonials/{testimonial}/featured', [TestimonialController::class, 'featured'])->whereNumber('testimonial')->middleware('can:testimonials.change_status')->name('testimonials.featured');
        });

        // §8.5 Student reviews (moderation queue)
        Route::middleware('module:student_reviews')->group(static function (): void {
            Route::get('student-reviews', [StudentReviewController::class, 'index'])->middleware('can:student_reviews.view_any')->name('student-reviews.index');
            Route::get('student-reviews/create', [StudentReviewController::class, 'create'])->middleware('can:student_reviews.create')->name('student-reviews.create');
            Route::post('student-reviews', [StudentReviewController::class, 'store'])->middleware('can:student_reviews.create')->name('student-reviews.store');
            Route::post('student-reviews/bulk-approve', [StudentReviewController::class, 'bulkApprove'])->middleware('can:student_reviews.approve')->name('student-reviews.bulk-approve');
            Route::get('student-reviews/{review}', [StudentReviewController::class, 'show'])->whereNumber('review')->middleware('can:student_reviews.view')->name('student-reviews.show');
            Route::get('student-reviews/{review}/edit', [StudentReviewController::class, 'edit'])->whereNumber('review')->middleware('can:student_reviews.edit')->name('student-reviews.edit');
            Route::put('student-reviews/{review}', [StudentReviewController::class, 'update'])->whereNumber('review')->middleware('can:student_reviews.edit')->name('student-reviews.update');
            Route::delete('student-reviews/{review}', [StudentReviewController::class, 'destroy'])->whereNumber('review')->middleware('can:student_reviews.delete')->name('student-reviews.destroy');
            Route::post('student-reviews/{review}/approve', [StudentReviewController::class, 'approve'])->whereNumber('review')->middleware('can:student_reviews.approve')->name('student-reviews.approve');
            Route::post('student-reviews/{review}/reject', [StudentReviewController::class, 'reject'])->whereNumber('review')->middleware('can:student_reviews.reject')->name('student-reviews.reject');
            Route::post('student-reviews/{review}/featured', [StudentReviewController::class, 'featured'])->whereNumber('review')->middleware('can:student_reviews.change_status')->name('student-reviews.featured');
        });

        // §8.6 Success stories
        Route::middleware('module:success_stories')->group(static function (): void {
            Route::get('success-stories', [SuccessStoryController::class, 'index'])->middleware('can:success_stories.view_any')->name('success-stories.index');
            Route::get('success-stories/create', [SuccessStoryController::class, 'create'])->middleware('can:success_stories.create')->name('success-stories.create');
            Route::post('success-stories', [SuccessStoryController::class, 'store'])->middleware('can:success_stories.create')->name('success-stories.store');
            Route::post('success-stories/reorder', [SuccessStoryController::class, 'reorder'])->middleware('can:success_stories.edit')->name('success-stories.reorder');
            Route::get('success-stories/{story}', [SuccessStoryController::class, 'show'])->whereNumber('story')->middleware('can:success_stories.view')->name('success-stories.show');
            Route::get('success-stories/{story}/edit', [SuccessStoryController::class, 'edit'])->whereNumber('story')->middleware('can:success_stories.edit')->name('success-stories.edit');
            Route::put('success-stories/{story}', [SuccessStoryController::class, 'update'])->whereNumber('story')->middleware('can:success_stories.edit')->name('success-stories.update');
            Route::delete('success-stories/{story}', [SuccessStoryController::class, 'destroy'])->whereNumber('story')->middleware('can:success_stories.delete')->name('success-stories.destroy');
            Route::post('success-stories/{story}/status', [SuccessStoryController::class, 'status'])->whereNumber('story')->middleware('can:success_stories.change_status')->name('success-stories.status');
            Route::post('success-stories/{story}/featured', [SuccessStoryController::class, 'featured'])->whereNumber('story')->middleware('can:success_stories.change_status')->name('success-stories.featured');
        });

        // §8.7 Blog posts
        Route::middleware('module:blog_posts')->group(static function (): void {
            Route::get('blog-posts', [BlogPostController::class, 'index'])->middleware('can:blog_posts.view_any')->name('blog-posts.index');
            Route::get('blog-posts/calendar', [BlogPostController::class, 'calendar'])->middleware('can:blog_posts.view_any')->name('blog-posts.calendar');
            Route::get('blog-posts/create', [BlogPostController::class, 'create'])->middleware('can:blog_posts.create')->name('blog-posts.create');
            Route::post('blog-posts', [BlogPostController::class, 'store'])->middleware('can:blog_posts.create')->name('blog-posts.store');
            Route::get('blog-posts/{post}', [BlogPostController::class, 'show'])->whereNumber('post')->middleware('can:blog_posts.view')->name('blog-posts.show');
            Route::get('blog-posts/{post}/edit', [BlogPostController::class, 'edit'])->whereNumber('post')->middleware('can:blog_posts.edit')->name('blog-posts.edit');
            Route::put('blog-posts/{post}', [BlogPostController::class, 'update'])->whereNumber('post')->middleware('can:blog_posts.edit')->name('blog-posts.update');
            Route::delete('blog-posts/{post}', [BlogPostController::class, 'destroy'])->whereNumber('post')->middleware('can:blog_posts.delete')->name('blog-posts.destroy');
            Route::post('blog-posts/{post}/publish', [BlogPostController::class, 'publish'])->whereNumber('post')->middleware('can:blog_posts.change_status')->name('blog-posts.publish');
            Route::post('blog-posts/{post}/schedule', [BlogPostController::class, 'schedule'])->whereNumber('post')->middleware('can:blog_posts.change_status')->name('blog-posts.schedule');
            Route::post('blog-posts/{post}/unpublish', [BlogPostController::class, 'unpublish'])->whereNumber('post')->middleware('can:blog_posts.change_status')->name('blog-posts.unpublish');
            Route::post('blog-posts/{post}/archive', [BlogPostController::class, 'archive'])->whereNumber('post')->middleware('can:blog_posts.change_status')->name('blog-posts.archive');
            Route::get('blog-posts/{post}/stats', [BlogPostController::class, 'stats'])->whereNumber('post')->middleware('can:blog_posts.view_reports')->name('blog-posts.stats');
            Route::get('blog-posts/{post}/preview-link', [BlogPostController::class, 'previewLink'])->whereNumber('post')->middleware('can:blog_posts.view')->name('blog-posts.preview-link');
        });

        // §8.8 Jobs (table job_openings — R1)
        Route::middleware('module:jobs')->group(static function (): void {
            Route::get('jobs', [JobOpeningController::class, 'index'])->middleware('can:jobs.view_any')->name('jobs.index');
            Route::get('jobs/create', [JobOpeningController::class, 'create'])->middleware('can:jobs.create')->name('jobs.create');
            Route::post('jobs', [JobOpeningController::class, 'store'])->middleware('can:jobs.create')->name('jobs.store');
            Route::post('jobs/reorder', [JobOpeningController::class, 'reorder'])->middleware('can:jobs.edit')->name('jobs.reorder');
            Route::get('jobs/{job}', [JobOpeningController::class, 'show'])->whereNumber('job')->middleware('can:jobs.view')->name('jobs.show');
            Route::get('jobs/{job}/edit', [JobOpeningController::class, 'edit'])->whereNumber('job')->middleware('can:jobs.edit')->name('jobs.edit');
            Route::put('jobs/{job}', [JobOpeningController::class, 'update'])->whereNumber('job')->middleware('can:jobs.edit')->name('jobs.update');
            Route::delete('jobs/{job}', [JobOpeningController::class, 'destroy'])->whereNumber('job')->middleware('can:jobs.delete')->name('jobs.destroy');
            Route::post('jobs/{job}/status', [JobOpeningController::class, 'status'])->whereNumber('job')->middleware('can:jobs.change_status')->name('jobs.status');
        });

        // §8.9 Job applications (row scope §9.1.3 in the controller)
        Route::middleware('module:job_applications')->group(static function (): void {
            Route::get('job-applications', [JobApplicationController::class, 'index'])->middleware('can:job_applications.view_any')->name('job-applications.index');
            Route::get('job-applications/export', [JobApplicationController::class, 'export'])->middleware('can:job_applications.export')->name('job-applications.export');
            Route::get('job-applications/{application}', [JobApplicationController::class, 'show'])->whereNumber('application')->middleware('can:job_applications.view')->name('job-applications.show');
            Route::put('job-applications/{application}', [JobApplicationController::class, 'update'])->whereNumber('application')->middleware('can:job_applications.edit')->name('job-applications.update');
            Route::delete('job-applications/{application}', [JobApplicationController::class, 'destroy'])->whereNumber('application')->middleware('can:job_applications.delete')->name('job-applications.destroy');
            Route::delete('job-applications/{application}/force', [JobApplicationController::class, 'forceDestroy'])->whereNumber('application')->middleware('can:job_applications.delete')->name('job-applications.force-destroy');
            Route::post('job-applications/{application}/status', [JobApplicationController::class, 'status'])->whereNumber('application')->middleware('can:job_applications.change_status')->name('job-applications.status');
            Route::post('job-applications/{application}/assign', [JobApplicationController::class, 'assign'])->whereNumber('application')->middleware('can:job_applications.assign')->name('job-applications.assign');
            Route::get('job-applications/{application}/cv', [JobApplicationController::class, 'cv'])->whereNumber('application')->middleware('can:job_applications.download')->name('job-applications.cv');
        });

        // §8.10 Contact inquiries (row scope §9.1.2 and the technical-column gate in the controller)
        Route::middleware('module:contact_inquiries')->group(static function (): void {
            Route::get('contact-inquiries', [ContactInquiryController::class, 'index'])->middleware('can:contact_inquiries.view_any')->name('contact-inquiries.index');
            Route::get('contact-inquiries/export', [ContactInquiryController::class, 'export'])->middleware('can:contact_inquiries.export')->name('contact-inquiries.export');
            Route::post('contact-inquiries/route-pending', [ContactInquiryController::class, 'routePending'])->middleware('can:contact_inquiries.change_status')->name('contact-inquiries.route-pending');
            Route::get('contact-inquiries/{inquiry}', [ContactInquiryController::class, 'show'])->whereNumber('inquiry')->middleware('can:contact_inquiries.view')->name('contact-inquiries.show');
            Route::put('contact-inquiries/{inquiry}', [ContactInquiryController::class, 'update'])->whereNumber('inquiry')->middleware('can:contact_inquiries.edit')->name('contact-inquiries.update');
            Route::delete('contact-inquiries/{inquiry}', [ContactInquiryController::class, 'destroy'])->whereNumber('inquiry')->middleware('can:contact_inquiries.delete')->name('contact-inquiries.destroy');
            Route::post('contact-inquiries/{inquiry}/status', [ContactInquiryController::class, 'status'])->whereNumber('inquiry')->middleware('can:contact_inquiries.change_status')->name('contact-inquiries.status');
            Route::post('contact-inquiries/{inquiry}/route', [ContactInquiryController::class, 'route'])->whereNumber('inquiry')->middleware('can:contact_inquiries.change_status')->name('contact-inquiries.route');
            Route::post('contact-inquiries/{inquiry}/assign', [ContactInquiryController::class, 'assign'])->whereNumber('inquiry')->middleware('can:contact_inquiries.assign')->name('contact-inquiries.assign');
            Route::post('contact-inquiries/{inquiry}/spam', [ContactInquiryController::class, 'spam'])->whereNumber('inquiry')->middleware('can:contact_inquiries.change_status')->name('contact-inquiries.spam');
            Route::post('contact-inquiries/{inquiry}/not-spam', [ContactInquiryController::class, 'notSpam'])->whereNumber('inquiry')->middleware('can:contact_inquiries.change_status')->name('contact-inquiries.not-spam');
        });
```

Route-parameter ↔ controller-argument names (all match): `term` (string, resolved per list), `service`, `item`, `image`
(string, resolved `withTrashed()`), `member`, `testimonial`, `review`, `story`, `post`, `job`, `application` (bound; string
on `force-destroy`), `inquiry` (bound on writes; string on `show`, which selects the column-restricted row itself).

### C.4 `routes/web.php` — public rows, before `require __DIR__.'/auth.php';` (so before the `/{slug}` catch-all)

Imports — add:

```php
use App\Http\Controllers\Site\BlogController;
use App\Http\Controllers\Site\CareerController;
use App\Http\Controllers\Site\ContactController;
use App\Http\Controllers\Site\PortfolioController;
use App\Http\Controllers\Site\ServiceController;
use App\Http\Controllers\Site\TeamController;
```

```php
/*
|--------------------------------------------------------------------------
| phase-04 §7.1 — services, portfolio, team, blog, careers, contact
|--------------------------------------------------------------------------
| `site` (maintenance gate) on every row; `site_module:<slug>` 404s a disabled module (D26), and runs before
| `site.cache` so a cached page is never served for a switched-off module. `site.cache` is left off the three
| pages that must run per visitor: a blog post (the view counter) and the two form pages (a fresh SpamGuard
| render token and the CSRF token). Published-only filtering lives in the controllers (`X::public()`, §9.2).
*/

Route::middleware(['site', 'site_module:services', 'site.cache'])->group(static function (): void {
    Route::get('services', [ServiceController::class, 'index'])->name('site.services.index');
    Route::get('services/{service:slug}', [ServiceController::class, 'show'])->name('site.services.show');
});

Route::middleware(['site', 'site_module:portfolio', 'site.cache'])->group(static function (): void {
    Route::get('portfolio', [PortfolioController::class, 'index'])->name('site.portfolio.index');
    Route::get('portfolio/{portfolioItem:slug}', [PortfolioController::class, 'show'])->name('site.portfolio.show');
});

Route::get('team', [TeamController::class, 'index'])
    ->middleware(['site', 'site_module:team', 'site.cache'])
    ->name('site.team.index');

Route::middleware(['site', 'site_module:blog_posts'])->group(static function (): void {
    Route::middleware('site.cache')->group(static function (): void {
        Route::get('blog', [BlogController::class, 'index'])->name('site.blog.index');
        Route::get('blog/category/{blogCategory:slug}', [BlogController::class, 'category'])->name('site.blog.category');
        Route::get('blog/tag/{blogTag:slug}', [BlogController::class, 'tag'])->name('site.blog.tag');
    });

    Route::get('blog/{blogPost:slug}', [BlogController::class, 'show'])->name('site.blog.show');
});

// The only way to see a draft or scheduled post. Deliberately NOT `signed`: that middleware answers 403 on a
// tampered signature, §11 test 25 requires 404 — the controller checks the signature after `can:`.
Route::get('preview/blog/{blogPost}', [BlogController::class, 'preview'])
    ->whereNumber('blogPost')
    ->middleware(['site', 'auth', 'can:view,blogPost'])
    ->name('site.blog.preview');

Route::middleware(['site', 'site_module:jobs'])->group(static function (): void {
    Route::get('careers', [CareerController::class, 'index'])->middleware('site.cache')->name('site.careers.index');
    Route::get('careers/{jobOpening:slug}', [CareerController::class, 'show'])->name('site.careers.show');
    Route::post('careers/{jobOpening:slug}/apply', [CareerController::class, 'apply'])
        ->middleware('throttle:public-apply')
        ->name('site.careers.apply');
});

// Phase 3 declares no /contact route (its catch-all reserves the slug), so Phase 4 adds it (§7.1 last row, §13).
Route::get('contact', [ContactController::class, 'index'])->middleware('site')->name('site.contact.index');
Route::post('contact', [ContactController::class, 'store'])->middleware(['site', 'throttle:public-contact'])->name('site.contact.store');
```

### C.5 Decisions and contract gaps an owner may want to overrule (each is one edit)

1. **Index routes use the contract's `can:…view_any`** for `contact-inquiries` and `job-applications` (§7.2 verbatim; it also
   keeps `PermissionStringConsistencyTest`'s sidebar ↔ route equality). A reviewer holding only `view` therefore has no
   list, only deep links (the rows are still scoped by `visibleTo()`). S.5 proposes `can:viewAny,App\Models\Cms\ContactInquiry`
   (the policy accepts `view_any` **or** `view`); if adopted, change the two route middlewares **and** the first line of both
   `index()` actions to `$this->authorize('viewAny', ContactInquiry::class)` / `JobApplication::class` — and the two Sidebar
   items lose their route/permission equality check (the walker skips policy-style `can:`).
2. **Two routes not in §7.2, both needed by screens §8 specifies and by delivered views:** `admin.{taxonomy}.toggle`
   (§8.1 "the active toggle posts immediately", `{module}.change_status` — the permission the taxonomy policies'
   `toggleActive()` checks) and `admin.portfolio.images.update` (§8.3 "per-attachment caption inline",
   `PortfolioService::updateCaption()`). Both are behind existing permissions; drop the two rows to stay literal — the
   views render those controls only `Route::has()`.
3. **No restore routes** (§4 pinned sets carry no `restore`; §7.2 last paragraph). Lists still show a *Trashed* view to
   users holding `{module}.restore` (read-only), exactly as S.5 notes for the policies.
4. **`AssignRequest` follows §6.11 literally** — the assignee must hold `{module}.view_any`. That contradicts §9.1.2 / §9.1.3,
   whose point is assigning rows to reviewers **without** `view_any` (Sales Executive, hiring manager). To follow §9.1,
   change `contentPermission('view_any')` in `AssignRequest::assigneeMayWork()` to accept `view_any` or `view`, and the
   two `…reviewerOptions()/assignees()` filters in the controllers the same way. Needs a contract owner's call.
5. **Status on the editor forms.** §8.4/§8.6/§8.8 put `status` (and `is_featured`) on the team, story and job forms, and the
   delivered service/portfolio forms post them too. Every request validates them but excludes them from the save payload;
   the controller demands `{module}.change_status` (403) before a change and applies it through the service's
   `changeStatus()` / `toggleFeatured()` / `togglePublic()` inside the same transaction.
6. **Blog intent without the ability.** `intent=publish|schedule` from a user lacking `blog_posts.change_status` (or the
   policy row rule) saves the post and answers a *warning* toast instead of 403, as the delivered editor expects; nothing
   is published. A direct POST to `admin.blog-posts.publish` / `.schedule` still 403s.
7. **Closed opening on apply.** `JobClosedException` answers **422**: JSON `{message, errors: {job}}`, or `abort(422)` for a
   browser post — never the detail page, which could be a draft's. A duplicate address stays an ordinary validation
   error on `email` (redirect back with errors).
8. **Public counting.** `site.blog.show` calls `BlogViewCounter::track()` (layers 1-3 now, `RecordBlogPostView` after the
   response); the preview never counts. `site.cache` is therefore off that row (C.4).
9. **Withheld money.** A service with `price_visible = false` and an opening with `salary_visible = false` have the amounts
   set to null on the display instance before any public view receives it (§11 tests 8, 39); the admin screens get the
   stored values.
10. **Technical block trail.** Opening an inquiry as a `contact_inquiries.view_logs` holder writes a
    `technical_block_viewed` entry through `CmsAuditor::record()` (§10.5, F-12.4 — `properties.sensitive = true`), mirroring
    `ApplicationCvService`'s `cv_downloaded`.

### C.6 View-data contract (reconciled with the views on disk; site views not written yet)

Every admin screen also receives `can` (array of booleans) and, where a form has an image slot, `mediaLibrary`
(`RespondsForCms::mediaLibrary()`) and `maxUploadMb`. Relation names the controllers eager-load are the **models'**:
`image`, `logo`, `cover`, `media`, `photo`, `authorPhoto`, `studentPhoto`, `featuredImage`, `approver`, `submitter`,
`jobOpening`, `assignee`, `statusChanger`, `editor` (Blameable's updater), `service`, `reader`.

**Mismatch for the views role (views on disk name relations the models do not declare):** `imageAsset`, `coverAsset`,
`photoAsset`, `authorPhotoAsset`, `studentPhotoAsset`, `featuredImageAsset`, `logoAsset` (the thumb partial's candidate
lists already include the real names — fine), but `updater` / `updatedBy` (save bar: add `editor`) and
`statusChangedBy` (application detail: use `statusChanger`) are not candidates anywhere — fix in the views.

| View | Variables |
|---|---|
| `admin.{taxonomy}.index` (5) | `terms` (withCount aliases `services_count`, `portfolio_items_count`, `blog_posts_count`; `image`/`logo` loaded), `filters`, `sort`, `direction`, `trashed`, `counts` {all,active,inactive}, `reassignOptions`, `mediaLibrary`, `maxUploadMb`, `canReorder`, `creating`, `editingId`, `can` |
| `admin.{service,portfolio,blog}-categories.edit` | `term`, `seoMeta`, `seoInherited`, `seoCompleteness`, `publicUrl`, `mediaLibrary`, `maxUploadMb`, `can`. Tags / technologies: `edit`/`show`/`create` answer JSON `{term, taxonomy}` or redirect to `index?edit={id}` / `?create=1` |
| `admin.services.index` | `services`, `filters`, `sort`, `direction`, `trashed`, `categoryOptions`, `technologyOptions`, `statusOptions`, `counts` {all,published,draft,archived,featured,trashed}, `canReorder`, `can` |
| `admin.services.create` / `.edit` | `categoryOptions`, `technologyOptions`, `statusOptions`, `reservedSlugs`, `mediaLibrary`, `maxUploadMb`, `canChangeStatus`, `canCreateTechnology`, `seoMeta`, `seoInherited`, `seoCompleteness`, `publicUrl`; edit adds `service`, `can` |
| `admin.portfolio.index` | `items` (+ `images_count`), `filters`, `sort`, `direction`, `trashed`, `categoryOptions`, `technologyOptions`, `yearOptions`, `statusOptions`, `counts`, `canReorder`, `can` |
| `admin.portfolio.create` / `.edit` | as services, plus `gallery` (Collection<MediaAsset> with `pivot.caption` / `pivot.sort_order`, trashed assets included), `maxImages`; edit adds `item`, `can` |
| `admin.team.index` | `members`, `filters`, `sort`, `direction`, `trashed`, `departmentOptions`, `statusOptions`, `socialPlatforms` (value ⇒ {label, icon}), `counts` {all,public,hidden,draft,trashed}, `canReorder`, `can` |
| `admin.team.create` / `.edit` | `statusOptions`, `socialPlatforms`, `departmentOptions`, `reservedSlugs`, `mediaLibrary`, `maxUploadMb`, `canChangeStatus`; edit adds `member`, `can` |
| `admin.testimonials.index` / `admin.student-reviews.index` | `testimonials` / `reviews` (also `records`), `tab`, `counts` {pending,approved,rejected,featured,all[,trashed]}, `filters`, `sort` (`name` = author/student name), `direction`, `statusOptions`, `sourceOptions`, `maxBulk`, `can`, plus `typeOptions` / `courseOptions` (value ⇒ label) |
| `…create` / `…edit` | `typeOptions` or `courseOptions`, `mediaLibrary`, `maxUploadMb`; create adds `autoApprove`; edit adds `testimonial` / `review`, `can` |
| `admin.success-stories.index` / `.create` / `.edit` | `stories`, `filters`, `sort`, `direction`, `trashed`, `courseOptions`, `platformOptions` (value ⇒ label), `statusOptions`, `counts`, `canReorder`, `can` / `statusOptions`, `courseOptions`, `platformOptions`, `mediaLibrary`, `maxUploadMb`, `canChangeStatus`; edit adds `story`, `can` |
| `admin.blog-posts.index` | `posts`, `tab`, `counts` (per tab), `filters`, `sort`, `direction`, `categoryOptions`, `tagOptions`, `authorOptions` (editors only), `statusOptions`, `can` |
| `admin.blog-posts.calendar` | `month` ('Y-m'), `posts` (Collection<BlogPost>, `author` loaded, grid window), `weekStartsOn`, `statusOptions`, `canChangeStatus` |
| `admin.blog-posts.create` / `.edit` | `categoryOptions`, `tagSuggestions`, `authorOptions` (editors only), `statusOptions`, `reservedSlugs`, `mediaLibrary`, `maxUploadMb`, `timezone`, `minScheduleAt`, `seoMeta`, `seoInherited`, `seoCompleteness`, `publicUrl`, `canChangeStatus`, `canDelete`; edit adds `post`, `allowedNext` (values), `canViewReports` |
| `admin.blog-posts.stats` | `post`, `range`, `rangePresets`, `daily` ('Y-m-d' ⇒ int), `totals` {views, unique_visitors, lifetime}, `referrers` list {host (null = direct), views} |
| `admin.jobs.index` / `.create` / `.edit` | `jobs` (+ `new_applications_count`), `filters`, `sort`, `direction`, `trashed`, `today`, `statusOptions`, `employmentTypeOptions`, `workModeOptions`, `departmentOptions`, `counts` {all,open,draft,closed,filled,trashed}, `canReorder`, `can` / `statusOptions`, `employmentTypeOptions`, `workModeOptions`, `salaryPeriodOptions`, `departmentOptions`, `reservedSlugs`, `today`, `mediaLibrary`, `maxUploadMb`, `canChangeStatus`, `seoMeta`, `seoInherited`, `seoCompleteness`; edit adds `job` (+ `new_applications_count`), `publicUrl`, `applicationsUrl`, `can` |
| `admin.job-applications.index` | `applications` (notes/rating hidden without `edit`), `stage` (`?stage=`), `stages` list {value,label,color}, `counts` (per stage + all, scoped to `?job=`), `filters`, `sort`, `direction`, `statusOptions`, `jobOptions`, `reviewerOptions`, `interviewModes`, `selectedJob`, `can` |
| `admin.job-applications.show` | `application` (notes/rating hidden unless `canUpdate`), `status`, `allowedNext`, `statusOptions`, `interviewModes`, `timeline` (Collection<Activity> with `causer`), `reviewerOptions`, `cv` {name,mime,size}, `canUpdate`, `canDownload`, `canDelete`, `canChangeStatus`, `canAssign` |
| `admin.contact-inquiries.index` (view not written) | `inquiries` (column-restricted), `routing` (id ⇒ {canRoute, waitingReason}), `tab` (`ContactInquiry::TABS`), `counts` (per tab), `filters`, `sort`, `direction`, `typeOptions`, `statusOptions`, `routingOptions`, `sourceOptions`, `services`, `assignees`, `targets` (key ⇒ {label, registered, available}), `contactUrl`, `showTechnical`, `can` |
| `admin.contact-inquiries.show` (view not written) | `inquiry` (technical columns absent unless `showTechnical`), `showTechnical`, `routedRecord`, `canRoute`, `waitingReason`, `targets`, `history` (list {id,event,description,causer,at}), `statusOptions`, `assignees`, `can` |
| `site.services.index` | `site`, `page`, `services` (paginator; `category`, `image`, public `technologies`; hidden prices nulled), `categories`, `activeCategory`, `hasServices` |
| `site.services.show` | `site`, `page`, `service`, `relatedServices`, `relatedPortfolio`, `portfolioDetailEnabled`, `inquiryUrl` |
| `site.portfolio.index` / `.show` | `site`, `page`, `items`, `categories`, `technologies`, `activeCategory`, `activeTechnology`, `detailEnabled` / `site`, `page`, `item`, `gallery` (list {asset, caption}, cover first), `previous` / `next` ({id, slug} or null), `related` |
| `site.team.index` | `site`, `page`, `members`, `groups` (department ⇒ members; `''` = no department, last) |
| `site.blog.index` / `.category` / `.tag` | `site`, `page`, `posts` (paginator; `category`, `author`, `featuredImage`, public `tags`), `sidebarCategories` (+ `public_posts_count`), `tagCloud` (+ `public_posts_count`); index adds `featuredPost`, `search`; category adds `category`; tag adds `tag`, `tagPath` |
| `site.blog.show` | `site`, `page`, `post`, `content` (sanitised again), `related`, `shareUrl`, `jsonLd` (array, null on preview), `isPreview` |
| `site.careers.index` / `.show` | `site`, `page`, `openings`, `groups`, `contactUrl` / `site`, `page`, `job` (hidden salaries nulled), `acceptsApplications`, `form` (null when closed: {action, cvMaxKb, cvMaxLabel, cvExtensions, cvAccept, spam {honeypot, token_field, token}}), `submitted` |
| `site.contact` | `site`, `page`, `form` {action, enabled, types, selectedType, services, selectedService, courseField ('text'), budgetOptions, spam {honeypot, token_field, token}}, `submitted` |

Flash keys: `toast` (`['type' => success|warning|error|info, 'message' => …]`) on every write; `application_submitted`
and `contact_submitted` for the public confirmation blocks (identical for spam and non-spam).

### C.7 Route verification and the permissions the routes need

A scratch script registered C.3 and C.4 verbatim under a throw-away prefix in a booted app (array cache, nothing written,
nothing persisted): **168 routes, 0 problems** — every action exists, every admin route carries exactly one `module:` and
one `can:` whose permission belongs to that module. Against **today's** `PermissionRegistry` only these `can:` strings are
missing, all of them added by the §4 registry block (S.5 lists the same set): every ability of the four new slugs
`service_categories`, `portfolio_categories`, `technologies`, `blog_tags`, plus `services.export` and
`blog_posts.view_reports`. Checked in controllers only (no route): `contact_inquiries.view_logs`, `portfolio.upload`
(create form images), `{module}.restore` (read-only trashed views), `website_media.view_any` (library link).
Without the §4 block those routes 403 everyone but Super Admin and `PermissionStringConsistencyTest` fails.

## Blade views (phase-04 role: views)

Every §8 screen, the four §8.13 components, the §8.11 public pages and section partials and the seven §8.12 widget
bodies. Nothing outside `resources/views/` was written and no existing (tracked) view was edited; `x-ui.*`, `x-site.*`,
the layouts and Phase 3's `admin.cms.partials.*` are only consumed.

### W.1 Files delivered (105 Blade files, all new)

```
resources/views/admin/service-categories/{index,edit}          resources/views/admin/portfolio-categories/{index,edit}
resources/views/admin/blog-categories/{index,edit}             resources/views/admin/blog-tags/index
resources/views/admin/technologies/index                       (§8.1 — the five lists share admin/marketing/partials/taxonomy-manager)
resources/views/admin/services/{index,create,edit,partials/form}                                      §8.2
resources/views/admin/portfolio/{index,create,edit,partials/form,partials/gallery}                    §8.3
resources/views/admin/team/{index,create,edit,partials/form}                                          §8.4
resources/views/admin/testimonials/{index,create,edit,partials/form}                                  §8.5
resources/views/admin/student-reviews/{index,create,edit,partials/form}                               §8.5
resources/views/admin/success-stories/{index,create,edit,partials/form}                               §8.6
resources/views/admin/blog-posts/{index,create,edit,calendar,stats,partials/form}                     §8.7
resources/views/admin/jobs/{index,create,edit,partials/form}                                          §8.8
resources/views/admin/job-applications/{index,show,partials/dialogs}                                  §8.9
resources/views/admin/contact-inquiries/{index,show,partials/dialogs,partials/routing-cell}           §8.10
resources/views/admin/marketing/partials/  enum-badge form-errors list-input moderation-queue save-bar slug-field
                                           sortable-cell stars taxonomy-edit taxonomy-fields taxonomy-manager
                                           technology-picker thumb video-url
resources/views/admin/dashboard/widgets/   new-inquiries inquiry-routing-backlog pending-moderation new-applications
                                           open-jobs blog-activity top-viewed-posts                   §8.12
resources/views/components/cms/{seo-fields,image-field,moderation-actions}                            §8.13
resources/views/components/site/contact-form                                                          §8.13
resources/views/site/services/{index,show}  site/portfolio/{index,show}  site/team/index
resources/views/site/blog/{index,category,tag,show,partials/card,partials/listing}
resources/views/site/careers/{index,show,partials/apply-form}  site/contact
resources/views/site/marketing/partials/   form-guard page-hero pagination social-glyph stars video-embed
resources/views/site/sections/             testimonials student_reviews success_stories team portfolio blog careers contact
resources/views/site/errors/429                                                                       §6.9 (see W.5 #1)
```

Kanban is deliberately not built (§8.9); the calendar (§8.7) is Alpine only; no print or wizard screen is in the contract.

### W.2 Verification (read-only; nothing registered, migrated, seeded or tested)

- `php artisan view:cache` then `php artisan view:clear`, both with `VIEW_COMPILED_PATH` pointed at a scratch folder
  (the shared `storage/framework/views` a parallel test run uses was never touched): 379 templates compiled, cleared.
- The suite's static view scans applied to the 105 files — `NoHardcodedFormatsTest` (no `->format(`, `number_format(`,
  `date(`), FT-37 (public `{!! !!}` only on `RichText::sanitize()`, no PHP tag, no `echo`), FT-42 (no `setting(` /
  `config(` under `site/`), plus the domain rule (no `setting()`, `settings_repo()`, `site_setting()` in any view): **0 hits**.
- A render harness with the controllers' **exact** view data (the arrays in C.3's controllers, C.6, the widget `data()`
  and the section providers' `build()` shapes), real model classes with their casts, and C.3 + C.4 registered in memory
  verbatim: **122 renders, 0 failures** with an allow-all gate and again with a deny-all gate — every list with rows,
  empty, filtered-empty and trashed; every form create/edit; the application and inquiry detail in each routing state
  (pending, failed, routed, general, spam); every public page including hidden salary/price, closed job, submitted,
  preview, disabled form; every section partial with and without items; every widget available/empty/unavailable; the 429.
- On every rendered page: no printed Blade directive or error leak, every `bg-white`/`bg-slate-50..200` and
  `text-slate-700..950` has its `dark:` counterpart (FT-51 rule), every `<table>` sits in an `overflow-x-auto` container,
  no inline width over 375 px, every `<img>` has `alt`, every `target="_blank"` has `rel="noopener"`, every icon-only
  button has an accessible name. JSON-LD is `@json` (a `</script>` in a title arrives as `</script>`); hidden prices and
  salaries never reach the HTML; `javascript:` links in team social URLs are not rendered as hrefs.

### W.3 Route names the views use

All of them exist in C.3 / C.4 or in Phases 1–3, except the five marked *optional*, which are only ever called behind
`Route::has()` (the control is simply not rendered while the route is absent).

```
admin.{service-categories,portfolio-categories,blog-categories,blog-tags,technologies}.{index,store,edit,update,toggle,destroy}
admin.{service-categories,portfolio-categories}.reorder                 (built as 'admin.'.$resource.'.'.$action; reorder/toggle/store/destroy behind Route::has)
admin.services.{index,create,store,edit,update,destroy,status,featured,reorder,export}
admin.portfolio.{index,create,store,edit,update,destroy,status,featured,reorder}
admin.portfolio.images.{store,reorder,cover,update,destroy}
admin.team.{index,create,store,edit,update,destroy,status,visibility,reorder}
admin.testimonials.{index,create,store,edit,update,destroy,approve,reject,featured,bulk-approve}
admin.student-reviews.{index,create,store,edit,update,destroy,approve,reject,featured,bulk-approve}
admin.success-stories.{index,create,store,edit,update,destroy,status,featured,reorder}
admin.blog-posts.{index,calendar,create,store,edit,update,destroy,publish,schedule,unpublish,archive,stats,preview-link}
admin.jobs.{index,create,store,edit,update,destroy,status,reorder}
admin.job-applications.{index,show,update,destroy,force-destroy,status,assign,cv,export}
admin.contact-inquiries.{index,show,update,destroy,status,route,route-pending,assign,spam,not-spam,export}
admin.technologies.store                    (inline "create technology" in the service / portfolio editor, JSON)
admin.website.media.show                    (Phase 3 — "Edit alt text" in the gallery)
site.home  site.services.{index,show}  site.portfolio.{index,show}  site.team.index
site.blog.{index,category,tag,show}  site.careers.{index,show,apply}  site.contact.{index,store}

optional, guarded:  admin.{services,portfolio,team,success-stories,blog-posts,jobs,testimonials,student-reviews}.restore
                    (no restore routes — C.5 #3; trashed views stay read-only)
                    admin.portfolio.export  (not in §7.2; the Export button stays hidden)
                    site.forms.token        (W.5 #2)
```

### W.4 View-data contract (confirmed against the controller source; C.6 holds, with these precisions)

Every variable a view needs is exactly what C.6 lists; views read all of them defensively (`??`), so a missing optional
key degrades rather than 500s. Precisions and additions:

| View | Reads |
|---|---|
| `admin.contact-inquiries.index` | the per-tab counts under **`tabs`** (the controller's key; C.6 says `counts` — both are accepted), `routing`, `targets` (the "waiting for a module" banner shows while **no** target is `registered`), `services`, `assignees`, `showTechnical`, `can`; optional `routedLinks` (id ⇒ {label, url, missing}) for a later phase's record links |
| `admin.contact-inquiries.show` | `inquiry`, `showTechnical`, `routedRecord`, `canRoute`, `waitingReason`, `targets`, `history` (list {id, event, description, causer: ?string, at}), `statusOptions`, `assignees`, `can` |
| `admin.testimonials.index` / `admin.student-reviews.index` | `testimonials` / `reviews` or `records`, `tab`, `counts`, `filters`, `sort`, `direction`, `statusOptions`, `sourceOptions`, `maxBulk`, `can` {create, edit, delete, approve, reject, feature, restore}, `typeOptions` / `courseOptions` |
| `admin.job-applications.show` | as C.6; relation names read: `jobOpening`, `assignee`, `statusChanger` |
| every editor with a save bar | `record->updated_at` and the `editor` relation ("Last saved by X at …"); the candidate lists also accept `image` / `cover` / `photo` / `authorPhoto` / `studentPhoto` / `featuredImage` / `logo` |
| `admin.dashboard.widgets.*` | `$data` exactly as each `App\Dashboard\Cms\*Widget::data()` returns it (keys: new-inquiries `available,total,types,delta,range_label,previous_label`; inquiry-routing-backlog `available,total,failed,targets,reasons`; pending-moderation `available,total,queues`; new-applications `available,total,funnel,delta,range_label,previous_label`; open-jobs `available,open,closing_soon,expired_still_open`; blog-activity `available,published,scheduled,drafts,range_label,links`; top-viewed-posts `available,range_label,posts`) |
| `site.sections.{testimonials,student_reviews,success_stories,team,portfolio,blog,careers,contact}` | Phase 3's standard section contract; the data from `$section['provider']` exactly as the matching `App\Support\Cms\Sections\*SectionProvider::resolve()` returns it; `$content` fields `heading`, `description`, `view_all_link` (contact: `submit_label`). Each hides itself when its `items` are empty |
| `site.blog.show` | as C.6; optional `companyName` (byline when a post has no author), `lazyImages` |
| `site.careers.index` / `.show` | as C.6; optional `noOpeningsMessage` (index), `consentText` (show) |
| `site.errors.429` | `message`, `retryAfterSeconds`, `retryAfterMinutes` (what `PublicFormRateLimits::responder()` passes); optional `backUrl` |
| `<x-site.contact-form>` | `:form` (the contact page's `form` array) **or** the section's props (`action`, `enabled`, `types`, `services`, `budget-options`); `:submitted` |

Flash keys read: `toast` (layout), `application_submitted` and `contact_submitted` through the controllers' `submitted`.

### W.5 Notes and requests for the integrator / other roles

1. **429 view name.** V.3's `PublicFormRateLimits::responder()` looks up `site.errors.429` then `errors.429`; the throttle
   page therefore lives at `resources/views/site/errors/429.blade.php` (an earlier `site/throttled.blade.php` of this role
   was removed, nothing referenced it). No action.
2. **Contract gap — `site.forms.token` (needed for the `contact` section on a cacheable CMS page).** A page served from
   the public cache may not carry a CSRF token (phase-03 R-4, `CachePublicResponse`), so `<x-site.contact-form>` in fetch
   mode GETs `route('site.forms.token')` for `{token, form_token}` just before submit. §7.1 declares no such route, so
   today the section renders a link to `/contact` instead of a form (the contact page and the careers form are unaffected:
   they are rendered per request and print both tokens inline). To enable the in-section form, the controllers role adds
   an invokable controller and the integrator one route — before the CMS catch-all:

   ```php
   <?php

   declare(strict_types=1);

   // app/Http/Controllers/Site/FormTokenController.php (controllers role)
   namespace App\Http\Controllers\Site;

   use App\Http\Controllers\Controller;
   use App\Services\Cms\SpamGuard;
   use Illuminate\Http\JsonResponse;
   use Illuminate\Http\Request;

   final class FormTokenController extends Controller
   {
       public function __invoke(Request $request, SpamGuard $guard): JsonResponse
       {
           return (new JsonResponse(['token' => $request->session()->token(), 'form_token' => $guard->signedTimestamp()]))
               ->header('Cache-Control', 'no-store, private');
       }
   }
   ```

   ```php
   // routes/web.php, beside C.4 (use App\Http\Controllers\Site\FormTokenController;)
   Route::get('forms/token', FormTokenController::class)->middleware(['site', 'throttle:60,1'])->name('site.forms.token');
   ```

   Dropping this keeps every page working; only the section falls back to the link.
3. **Widget link keys (services role, one word each).** `BlogActivityWidget` links the drafts count with `['tab' => 'drafts']`,
   but `BlogPostController::TABS` spells it `draft` — the link lands on *All*. `PendingModerationWidget` links with
   `['status' => 'pending']`; the queue reads `tab` (pending is its default, so it lands right, but `['tab' => 'pending']` is the
   real key).
4. **Sidebar pending badge.** §8.5 mirrors the pending count "in the sidebar"; `Support\Sidebar` has no badge slot, so the
   count is shown on the queue's Pending tab (rose) and in `PendingModerationWidget`. Wiring a badge is a Sidebar change.
5. **Views consume, never edit:** `layouts.admin`, `site.layouts.public`, `x-ui.*`, `x-site.{section,heading,button,image,prose}`,
   `admin.cms.partials.{scripts,media-library-json,media-picker,richtext,seo-fields,length-meter}`,
   `admin.dashboard.partials.{delta,range}` — all tracked files today.

## Reconciliation applied (phase-04 role: reconcile, domain files only)

§9 items that touch unit 04's own files are applied: **R-1** (`Site\BlogController::preview()` runs
`Gate::authorize('view', …)` after the signature check), **R-2** (both queue `index()` actions authorise `view`),
**R-3** (`AssignRequest` and both assignee lists accept `view_any` **or** `view`), **R-4c/R-4d**
(`MarketingSectionProvider::options()` reads the snapshot's `fields`; `ServicesSectionProvider` adds
`title`/`excerpt`/`media`/`meta`), **R-7** (widget link keys), **R-12** (`gray` → `slate`), and §4.1's
`app/Support/Cms/Sections/MarketingSectionTypes.php` exists verbatim. Further mismatches found and fixed:

| # | Mismatch | Fix |
|---|---|---|
| X-1 | Phase 3's media picker posts `{field}_media_id=''` when the admin clears an image; `imageColumnPayload()` ignored an empty id, so no editor could ever remove an image | a posted-but-empty `*_media_id` clears the column; a request that omits the key leaves it alone (`ValidatesContentImage`) |
| X-2 | A service-level upload refusal landed on `image`/`photo` while the forms post `featured_image`, `author_photo`, `student_photo`, `logo` | `BlogService`, `ReviewContentService`, `TaxonomyService` pass the real field name |
| X-3 | `POST /careers/{slug}/apply` and `POST /contact` validated the body before the on/off switch, so a switched-off form answered 422/302 instead of 404 (test 36) | `PublicJobApplicationRequest` / `PublicContactRequest::authorize()` read the switch and `failedAuthorization()` is a 404 (controllers keep their check) |
| X-4 | `SpamGuard` fingerprinted the cover letter, so re-submitting an application within 10 minutes was a silent "thank you" instead of §6.8 invariant 4's 422 on `email` (test 35), and a corrected re-submission after a service-side CV refusal was silently dropped | the `duplicate` signal is `sha256(email + message)` of the contact form only (§6.9 verbatim); applications rely on `uq_job_application_per_job` |
| X-5 | `ApplicationCvService`'s second MIME gate trusted `UploadedFile::getMimeType()`, which a test fake (and any non-Symfony upload object) reports from the file name, so a PHP file named `.pdf` passed (test 34) | the gate reads `finfo` on the bytes, like `MediaService` (INV-11). **Test note:** CV fixtures must carry real PDF/Word bytes (e.g. `UploadedFile::fake()->createWithContent('cv.pdf', "%PDF-1.4 …")`); `fake()->create('cv.pdf')` is empty and is refused |
| X-6 | The inquiries index offered *Route all pending* to any `change_status` holder, but `ContactInquiryPolicy::routePending()` also needs `view_any` (403 for a Digital Marketer) | `abilities()['routePending']`, read by the view |
| X-7 | `AssignRequest` accepted an inactive or suspended account although both pickers list active accounts only | the assignee must be active |

Verified on a throw-away schema (`p4_scratch_reconcile`, created, migrated, seeded and dropped; `my_office` and
`my_office_test` never opened) through the real routes, middleware, requests, controllers, services and views: all 63
Phase 4 admin GET screens (plus list query/trashed variants and every tab) 200 for Super Admin; the public pages; seven
Phase 4 sections (services, testimonials, team, blog, careers, success stories, contact) placed on the home page render
their data (§4.7 + R-4); taxonomy/service/portfolio/gallery/team/
testimonial/review/story/blog/job/application/inquiry writes; the signed preview (200 / tampered 404 / no `view` 403);
Receptionist queue 200 with detail 404 until assigned; a `job_applications.view`-only opening owner sees only its own
applications without notes; hidden price and salary absent; `blog_posts` disabled → admin 403, public 404; the four
scheduled commands; the dashboard. No test suite was run.
