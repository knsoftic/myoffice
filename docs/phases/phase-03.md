# PHASE 3 CONTRACT - Dynamic public website CMS (sections, header/footer, menus, pages, SEO)

**Status: binding.** Column names, class names, enum cases, permission strings, route names and file paths
below are fixed - do not invent alternatives. Requirement source: [`../requirements.md`](../requirements.md)
sections **7, 8, 9, 10, 100, 101, 102, 105**. Conventions: [`../../CLAUDE.md`](../../CLAUDE.md).
Where this document would conflict with [`phase-01.md`](phase-01.md) or [`phase-02.md`](phase-02.md),
**those win** and the conflict is recorded in §12.2 as an open question, never silently redesigned.

Decisions are labelled **[D-W3-n]** so a code review can cite them.

---

## Contents

| § | Contents |
|---|---|
| 1 | Goal, dependencies, ownership, invariants |
| 2 | Schema (12 tables, 2 pivots), draft/publish model, relationship map |
| 3 | Enums |
| 4 | PermissionRegistry additions |
| 5 | SettingsRegistry additions |
| 6 | Registry + services (section registry, publish, cache, preview, images, SEO, sitemap) |
| 7 | Routes (admin + public) |
| 8 | UI screens (admin CMS + public site layout) |
| 9 | Data isolation |
| 10 | Events, notifications, jobs, scheduled tasks |
| 11 | Acceptance tests |
| 12 | Risks and open questions |
| 13 | Requests to other phases |

---

## 1. Goal, dependencies, ownership, invariants

### 1.1 Goal

After this phase the business can run its entire public face from `/admin/website` with no code change:
turn sections on and off and drag them into any order; edit every heading, paragraph, image, icon, button
label and URL; manage the header (logo, company name, navigation, login / contact / admission / CTA
buttons) and the footer; edit the hero with its background image or video and its six statistics (each
either a typed-in number or a live count); edit the about area (company, software-house and institute
introductions, mission, vision, history, why-choose-us, images, statistics); build nested header and
footer menus; publish custom pages at their own slugs (privacy policy, terms, refund policy, course
policy and anything else); manage reusable CTA blocks and FAQs; set per-page SEO (title, description,
keywords, canonical, OG image, index/noindex) and have `sitemap.xml` and `robots.txt` generated from it.
Every edit is a **draft** until it is published, previewable before it goes live, cached for anonymous
visitors with invalidation on publish, and the whole site is gated by Phase 2's `maintenance_mode` and
`public_site_enabled`.

### 1.2 Dependencies

| Phase | What this phase needs from it |
|---|---|
| 1 | `users`, RBAC + `PermissionRegistry` + `Gate::before` module gating, `modules`, `settings`, `activity_log` with old/new values, `Blameable`, `LogsActivityWithContext`, the `x-ui.*` component set, `Support\Sidebar`, the `storage:link` public disk |
| 2 | `SettingsRegistry` + `SettingsService` (groups `company`, `branding`, `contact`, `social`, `seo`, `localization`, `maintenance`), `settings.is_public`, `ConfigureFromSettings` (brand colour CSS variables), `Format` helpers (`app_date()`), `Setting::typedValue()` |
| - | No other phase. Phase 3 depends on **nothing** that does not exist yet; every later-phase section type, sitemap provider and statistic metric degrades to "absent" (§6.9, §6.11). |

### 1.3 Ownership - tables no later phase may re-create

Phase 3 creates five tables that phases 4, 5, 14 and 15 would otherwise each invent a private version of.
They are **shared CMS infrastructure**: **[D-W3-1]**

| Table | Who reuses it | How |
|---|---|---|
| `seo_meta` | every public-facing entity (pages, services, portfolio items, blog posts, courses, static routes) | `morphOne` + the `route_key` form. **No phase adds SEO columns to its own table** (D23) |
| `media_assets` | every CMS image anywhere in the system | `MediaService::store()`; FK column or the `*_media` pivot pattern. Mandatory for anything rendered on the public website (D24) |
| `cms_revisions` | sections, pages, and any later draft/publish entity | `morphMany`, append-only |
| `faqs` / `faq_categories` | course FAQs (§90, Phase 14-17) | the nullable `faqable_type`/`faqable_id` morph. **No `course_faqs` table exists** (F-2.2) |
| `cta_blocks` | any section or page that needs a call to action | FK from `website_sections.cta_block_id` |

Phase 3 does **not** create: `services`, `portfolio_items`, `team_members`, `testimonials`,
`student_reviews`, `success_stories`, `blog_*`, `jobs`, `contact_inquiries`. It only declares the
**contract** they plug into (§6.1, §6.10). **`contact_inquiries` is owned by Phase 4**, which also owns
the `contact` section type and the §17 routing (F-2.1).

### 1.4 Invariants

| # | Invariant | Enforced by |
|---|---|---|
| INV-1 | The public site renders **only** from `published_content` / `published_*` columns. A draft edit can never appear to an anonymous visitor, not even for one request. | `PublicPageService` reads published columns only; FT-06, FT-07 |
| INV-2 | A section type that is not in `WebsiteSectionRegistry` never renders and never 500s - the renderer skips it and the admin list flags it as orphaned. | `WebsiteSectionRegistry::exists()` guard; `cms:verify-published-snapshots`; FT-04 |
| INV-3 | **JSON never holds a foreign key.** Every reference that must survive a delete is a real FK column or a pivot row (`cta_block_id`, `menu_id`, `banner_media_id`, `og_image_media_id`, `website_section_media`, `faq_website_section`). `content` JSON holds scalars and composites only. | Schema §2; FT-12 |
| INV-4 | "Unpublished changes" can never lie: it is a **stored generated column** derived from `content_hash <> published_hash`, not a flag someone forgot to set. | `website_sections`, `pages` generated columns; FT-08 |
| INV-5 | Reordering writes a **contiguous** `sort_order` sequence for the whole placement inside one transaction, from an explicit id list - never `sort_order + 1` arithmetic on a subset. | `WebsiteSectionService::reorder()`; FT-15 |
| INV-6 | A menu is at most **two levels** deep (parent + child). Enforced in the DB, not only in the form. | CHECK `chk_mi_depth`; FT-19 |
| INV-7 | A required section type (`header`, `hero`, `footer`) can be **disabled but never deleted**. | Registry `is_required` + `WebsiteSectionPolicy::delete()`; FT-16 |
| INV-8 | Public responses are cached under a **version-stamped key**; any publish increments the version, so invalidation is O(1) and works on the database cache driver, which has no tag support. | `PublicCache::bump()`; FT-24, FT-25 |
| INV-9 | Preview responses are **never cached, never indexed**: `Cache-Control: no-store`, `X-Robots-Tag: noindex, nofollow`, and the cache middleware short-circuits. | `ResolvePreviewMode` + `CachePublicResponse`; FT-22 |
| INV-10 | A public view may read only settings whose registry definition has `public => true`. Requesting any other key from the `site.*` namespace throws. | `SiteSettings::get()`; FT-42 |
| INV-11 | Every uploaded image is validated by **file content**, not by extension or the client's MIME header, stored outside any executable path, and served from the `public` disk only. | `MediaService::store()`; FT-33, FT-34 |
| INV-12 | Statistic values are **decimal strings**, never floats, and a metric that cannot be resolved renders **nothing** - never `0`. | `StatisticsProvider`; FT-30, FT-31 |
| INV-13 | Rich text is sanitized against an allowlist **on write and again on render**, by `RichText::sanitize($html, $profile)` and nothing else. A `<script>`, an `on*` attribute, a `javascript:` URL, a Blade construct and an `<iframe>` from a non-allowlisted host never reach the rendered HTML **under any profile** - a profile may widen the tag/attribute allowlist, never the common core of §6.6. | `RichText::sanitize()`; FT-36, FT-37 |
| INV-14 | No CMS row is ever hard-deleted from the UI; every CMS table carries `deleted_at` except the two recorded exceptions in §2.14. | softDeletes + policies; FT-17 |
| INV-15 | Disabling the `website_sections` module 403s the admin CMS for everyone (Super Admin included) but **never takes the public site down** - the last published snapshot keeps serving. **No public route carries `module:` or `can:`**; a content module may gate **its own** public routes through `site_module`, which **404s** (D26). | `module:` middleware on admin routes only, `site_module` on a content module's own public routes; FT-41 |
| INV-16 | Every publish, unpublish, reorder, toggle, slug change and SEO change writes an `activity_log` row with old and new values, the actor, the IP and - where the act is discretionary - a reason. | `LogsActivityWithContext`; FT-43 |

---

## 2. Schema

All tables InnoDB / utf8mb4. Every table carries `timestamps`, `deleted_at` (softDeletes), `created_by`
and `updated_by` (nullable FK `users.id`, `nullOnDelete`, filled by `Blameable`) **except** the append-only
tables documented in §2.14, which carry no `deleted_at` under the category rule **D19**
(`CLAUDE.md` §3). No CMS table carries `branch_id`: D11 applies to institute tables, and the public
website is one site for the whole company. **[D-W3-2]**

### 2.1 The 12 tables and 2 pivots

| # | Table | One row is |
|---|---|---|
| 1 | `website_sections` | one placed section instance (a hero on the home page, the global header) |
| 2 | `website_section_items` | one repeater item inside a section (a statistic, a why-choose-us point, a history entry) |
| 3 | `website_section_media` | **pivot** - one image/video slot of a section |
| 4 | `menus` | one navigation container bound to one layout slot |
| 5 | `menu_items` | one link, optionally the child of another |
| 6 | `pages` | one custom page at its own slug |
| 7 | `cta_blocks` | one reusable call-to-action block |
| 8 | `faq_categories` | one FAQ group |
| 9 | `faqs` | one question and answer, optionally attached to another model |
| 10 | `faq_website_section` | **pivot** - the FAQs hand-picked into a FAQ section |
| 11 | `seo_meta` | the SEO record of one public target (a model or a named route) |
| 12 | `media_assets` | one uploaded original plus its generated derivatives |
| 13 | `cms_revisions` | one append-only content snapshot |
| 14 | `sitemap_generations` | one sitemap build (url count, bytes, duration, outcome) |

No `preview_tokens` table exists: preview uses Laravel signed URLs and the session ([D-W3-12]).

### 2.2 `website_sections`

A placed instance of a registry-declared section type. Holds the working draft **and** the published
snapshot, so the public renderer is one indexed read per page with no joins.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `section_key` | string(64) | not null | the `WebsiteSectionRegistry` key (`hero`, `about`, `faq`, ...). Never cast to an enum - later phases add types without a migration |
| `placement` | string(32) | `home` | cast `SectionPlacement` |
| `page_id` | FK `pages.id` | nullable, null | `cascadeOnDelete`; set only when `placement = page` |
| `instance_key` | string(96) | nullable, null | `"{placement}|{page_id or 0}|{section_key}"` for registry types marked `is_unique`, **NULL** for repeatable types. The unique index below is what makes "one hero per page" a database fact |
| `name` | string(150) | nullable | admin-facing label override; falls back to the registry label |
| `anchor` | string(64) | nullable | `#about`, so a menu item can link to a section (§102) |
| `cta_block_id` | FK `cta_blocks.id` | nullable | `nullOnDelete`; used by the `cta` type (INV-3) |
| `menu_id` | FK `menus.id` | nullable | `nullOnDelete`; used by `header` / `footer` (INV-3) |
| `content` | json | nullable | the **draft** field values, keys validated against `WebsiteSectionRegistry::fields()` |
| `published_content` | json | nullable | the snapshot the public site reads: the section's own fields **plus** its enabled items, media URLs + srcsets, resolved CTA and resolved menu tree at publish time |
| `content_hash` | char(40) | nullable | sha1 of the canonical draft payload (fields + items + media pivots), written by `CmsHasher` |
| `published_hash` | char(40) | nullable | the `content_hash` that was published |
| `has_unpublished_changes` | boolean | **generated, STORED** | `CASE WHEN published_hash IS NULL OR content_hash <> published_hash THEN 1 ELSE 0 END` (INV-4) |
| `is_enabled` | boolean | true | §7/§100 enable-disable. Independent of `status` |
| `status` | string(16) | `draft` | cast `ContentStatus` |
| `sort_order` | int unsigned | 0 | §7/§100 reorder; contiguous per placement (INV-5) |
| `published_at` | timestamp | nullable | |
| `published_by` | FK `users.id` | nullable | `nullOnDelete` |
| `unpublished_reason` | string(255) | nullable | mandatory when unpublishing a published section |
| `draft_updated_at` | timestamp | nullable | shown as "draft saved <time>" |
| `created_at` / `updated_at` | timestamps | nullable | |
| `deleted_at` | timestamp | nullable | softDeletes |
| `created_by` / `updated_by` | FK `users.id` | nullable | `nullOnDelete`, Blameable |

**Keys.** `UNIQUE uq_ws_instance(instance_key)` - MariaDB treats NULLs as distinct, so repeatable types
are unconstrained and unique types cannot be placed twice. `UNIQUE uq_ws_anchor(placement, page_id, anchor)`.
`INDEX idx_ws_public(placement, page_id, is_enabled, status, sort_order)` - the only index the public read
uses. `INDEX (section_key)` orphan report; `INDEX (has_unpublished_changes)` the admin badge;
`INDEX (cta_block_id)`, `INDEX (menu_id)`.
**CHECK** `chk_ws_page_placement`: `(placement = 'page' AND page_id IS NOT NULL) OR (placement <> 'page' AND page_id IS NULL)`.
**Relationships.** belongsTo `Page`, `CtaBlock`, `Menu`, `User` (`publisher`, `creator`, `editor`);
hasMany `WebsiteSectionItem`; belongsToMany `MediaAsset` through pivot **`website_section_media`**
(`withPivot('role','sort_order')`); belongsToMany `Faq` through pivot **`faq_website_section`**
(`withPivot('sort_order')`); morphMany `CmsRevision`.

### 2.3 `website_section_items`

Every repeater in every section type uses this one table, discriminated by `group`. The six hero
statistics (§9), the about statistics, the why-choose-us points and the history timeline (§10) are all
rows here. **[D-W3-3]**

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `website_section_id` | FK `website_sections.id` | not null | `cascadeOnDelete` |
| `group` | string(32) | not null | the repeater key declared by the registry: `statistic`, `why_choose_us`, `history`, `highlight`, `link` |
| `content` | json | nullable | the item's field values (label, title, text, icon, prefix, suffix, url) per `WebsiteSectionRegistry::itemFields()` |
| `metric` | string(48) | nullable | cast `StatisticMetric`; only meaningful when `group = statistic` |
| `value_mode` | string(16) | `manual` | `manual` or `auto` - §9 statistics may be typed in or counted live |
| `manual_value` | decimal(15,2) | nullable | the typed number. **decimal, never float** (CLAUDE.md §1.4 applies to every number, not only money) |
| `media_asset_id` | FK `media_assets.id` | nullable | `nullOnDelete`; the item's image or custom icon |
| `is_enabled` | boolean | true | an item can be hidden without deleting it |
| `sort_order` | int unsigned | 0 | drag to reorder within its group |
| timestamps / `deleted_at` / `created_by` / `updated_by` | | | |

**Keys.** `INDEX idx_wsi_group(website_section_id, group, is_enabled, sort_order)`;
`INDEX (metric, value_mode)` - the statistics screen of §100 lists every statistic item in the system.
**CHECK** `chk_wsi_value`: `value_mode IN ('manual','auto')` AND `(value_mode = 'manual' OR metric IS NOT NULL)`.
**Relationships.** belongsTo `WebsiteSection`, `MediaAsset`.
**Why `metric` / `value_mode` / `manual_value` are real columns** while every other repeater field lives
in `content`: they are the only repeater values a service resolves, validates and reports on (§6.11,
§8.11), so they earn a column. Everything else is presentation.

### 2.4 `website_section_media` (pivot)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `website_section_id` | FK `website_sections.id` | not null | `cascadeOnDelete` |
| `media_asset_id` | FK `media_assets.id` | not null | `restrictOnDelete` - an image in use cannot be deleted out from under a section |
| `role` | string(32) | not null | the registry slot name: `hero_image`, `background_image`, `background_video`, `video_poster`, `image_1`, `image_2`, `gallery` |
| `sort_order` | int unsigned | 0 | only meaningful for multi-slot roles (`gallery`) |
| `created_at` / `updated_at` | timestamps | nullable | no soft delete, no blameable - it is a pivot |

**Keys.** PK (`website_section_id`,`role`,`media_asset_id`); `INDEX (media_asset_id)` for usage counting.
Single-slot roles are enforced by `WebsiteSectionService` from the registry's `multiple => false`, not by
the DB (a pivot cannot express it without a second unique index that would block galleries).

### 2.5 `menus`

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `name` | string(100) | not null | "Main navigation" |
| `slug` | string(64) | not null | stable key used by `menu('header')` in Blade |
| `location` | string(32) | not null | cast `MenuLocation` - the layout slot it fills |
| `description` | string(255) | nullable | |
| `is_active` | boolean | true | |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_menus_slug(slug)`; `UNIQUE uq_menus_location(location)` - one menu per slot, which is
why the footer has three separate locations rather than one menu with columns. **[D-W3-4]**
**Relationships.** hasMany `MenuItem`; hasMany `WebsiteSection` (the header/footer sections that point at it).

### 2.6 `menu_items`

§102 verbatim (label, parent, URL, icon, display order, open new tab, status) plus a typed link so an
internal link is never a hardcoded string.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `menu_id` | FK `menus.id` | not null | `cascadeOnDelete` |
| `parent_id` | FK `menu_items.id` | nullable | `cascadeOnDelete`; NULL = top level |
| `label` | string(100) | not null | |
| `link_type` | string(24) | `url` | cast `MenuItemLinkType` |
| `page_id` | FK `pages.id` | nullable | `nullOnDelete`; `link_type = page`. A draft or trashed target hides the item (§6.3) |
| `route_name` | string(100) | nullable | `link_type = route`; validated against `Route::has()` |
| `route_params` | json | nullable | |
| `url` | string(500) | nullable | `link_type = url`; validated `http`/`https`/`mailto`/`tel` only (INV-13) |
| `anchor` | string(64) | nullable | `link_type = section_anchor`; matches `website_sections.anchor` |
| `linkable_type` / `linkable_id` | nullableMorphs | null | the hook later phases use for course / service / blog-category links. Phase 3 writes nothing here |
| `icon` | string(64) | nullable | allowlisted icon name (§6.6) |
| `open_new_tab` | boolean | false | §102; renders `target="_blank" rel="noopener noreferrer"` |
| `rel_nofollow` | boolean | false | for sponsored/external links |
| `visibility` | string(16) | `all` | cast `MenuVisibility` - `all` / `guest` / `auth`, so the header's Login button can disappear once signed in (§8) |
| `is_enabled` | boolean | true | §102 status |
| `sort_order` | int unsigned | 0 | §102 display order |
| `depth` | tinyint unsigned | 0 | denormalised by `MenuService`; 0 = top, 1 = child |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `INDEX idx_mi_tree(menu_id, parent_id, is_enabled, sort_order)`; `INDEX (page_id)`;
`INDEX (linkable_type, linkable_id)`.
**CHECK** `chk_mi_depth`: `depth <= 1` (INV-6). **CHECK** `chk_mi_parent`: `parent_id IS NULL OR depth = 1`.
**Relationships.** belongsTo `Menu`, `Page`, `parent` (`MenuItem`); hasMany `children` (`MenuItem`,
ordered by `sort_order`); morphTo `linkable`.

### 2.7 `pages`

§101: about, privacy policy, terms, refund policy, course policy and arbitrary pages.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `title` | string(200) | not null | |
| `slug` | string(200) | not null | lowercase, `[a-z0-9-]`, validated against `PageService::reservedSlugs()` |
| `layout` | string(16) | `content` | cast `PageLayout` - `content` (rich text) or `sections` (hosts `website_sections` with `placement = page`) |
| `excerpt` | string(500) | nullable | used as the OG/meta-description fallback |
| `content` | longText | nullable | **draft** rich text, sanitized HTML (INV-13) |
| `published_content` | longText | nullable | the snapshot the public site reads |
| `content_hash` / `published_hash` | char(40) | nullable | as §2.2 |
| `has_unpublished_changes` | boolean | **generated, STORED** | same expression as §2.2 (INV-4) |
| `show_banner` | boolean | true | |
| `banner_media_id` | FK `media_assets.id` | nullable | `nullOnDelete` (INV-3) |
| `banner_heading` | string(200) | nullable | defaults to `title` |
| `banner_subheading` | string(300) | nullable | |
| `template` | string(64) | nullable | an allowlisted Blade view (`site.pages.default`, `site.pages.wide`, `site.pages.legal`); anything else is rejected |
| `status` | string(16) | `draft` | cast `ContentStatus` |
| `published_at` | timestamp | nullable | a future value with `status = scheduled` publishes itself (§10.4) |
| `published_by` | FK `users.id` | nullable | `nullOnDelete` |
| `unpublished_reason` | string(255) | nullable | |
| `is_system` | boolean | false | the four policy pages: content editable, **never deletable** |
| `sort_order` | int unsigned | 0 | the admin list and the legal-menu seeder |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_pages_slug(slug)` - a plain unique index, **not** `(slug, deleted_at)`: MariaDB
treats NULLs as distinct, so the composite form would silently allow two live pages on one slug. The cost
is that a trashed page keeps its slug; the validator says so by name and offers restore or purge
([D-W3-5], R-5). `INDEX (status, published_at)`; `INDEX (is_system)`; `INDEX (layout)`.
**Relationships.** hasMany `WebsiteSection` (`placement = page`); belongsTo `MediaAsset` (`banner`);
morphOne `SeoMeta`; morphMany `CmsRevision`; hasMany `MenuItem`.

### 2.8 `cta_blocks`

Reusable CTA content (§100). Referenced, never copied, so changing the button once changes it everywhere.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `key` | string(64) | not null | stable reference (`primary`, `admission`, `free_consultation`) |
| `name` | string(150) | not null | admin label |
| `variant` | string(24) | `banner` | cast `CtaVariant` |
| `heading` | string(200) | not null | |
| `subheading` | string(300) | nullable | |
| `description` | text | nullable | plain text, not rich text |
| `primary_label` | string(60) | nullable | |
| `primary_url` | string(500) | nullable | scheme-allowlisted (INV-13) |
| `primary_style` | string(16) | `primary` | cast `ButtonStyle` |
| `primary_new_tab` | boolean | false | |
| `secondary_label` / `secondary_url` / `secondary_style` / `secondary_new_tab` | same types | nullable / `outline` / false | |
| `background_media_id` | FK `media_assets.id` | nullable | `nullOnDelete` |
| `background_color` | string(16) | nullable | hex, validated `/^#[0-9a-f]{6}$/i`; ignored when a background image is set |
| `status` | string(16) | `draft` | cast `ContentStatus`. CTA blocks are **not** snapshot-published (§2.15) |
| `usage_count` | int unsigned | 0 | cache of the sections + pages referencing it, recomputed by `CtaBlockService::recount()` |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_cta_key(key)`; `INDEX (status)`.
**Relationships.** hasMany `WebsiteSection`; belongsTo `MediaAsset` (`background`).

### 2.9 `faq_categories`

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `name` | string(150) | not null | |
| `slug` | string(150) | not null | |
| `description` | string(300) | nullable | |
| `icon` | string(64) | nullable | allowlisted icon name |
| `is_enabled` | boolean | true | |
| `sort_order` | int unsigned | 0 | drag to reorder |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_faqcat_slug(slug)`; `INDEX (is_enabled, sort_order)`.
**Relationships.** hasMany `Faq`.

### 2.10 `faqs`

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `faq_category_id` | FK `faq_categories.id` | nullable | `nullOnDelete` |
| `faqable_type` / `faqable_id` | nullableMorphs | null | the hook for course FAQs (§90). Phase 3 leaves it NULL; **Phase 14 must use it instead of a `course_faqs` table** |
| `question` | string(300) | not null | |
| `answer` | longText | not null | sanitized rich text (INV-13) |
| `status` | string(16) | `draft` | cast `ContentStatus` |
| `is_featured` | boolean | false | the home-page FAQ section can show featured only |
| `sort_order` | int unsigned | 0 | |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `INDEX idx_faq_public(faq_category_id, status, sort_order)`; `INDEX (faqable_type, faqable_id)`;
`INDEX (is_featured, status)`.
**Relationships.** belongsTo `FaqCategory`; morphTo `faqable`; belongsToMany `WebsiteSection` through
pivot **`faq_website_section`**.

### 2.11 `faq_website_section` (pivot)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `faq_id` | FK `faqs.id` | not null | `cascadeOnDelete` |
| `website_section_id` | FK `website_sections.id` | not null | `cascadeOnDelete` |
| `sort_order` | int unsigned | 0 | the curated order inside that section |
| timestamps | | nullable | |

**Keys.** PK (`faq_id`,`website_section_id`); `INDEX (website_section_id, sort_order)`.
Used only when the FAQ section's `content.source = selected`; `source = category` reads by
`faq_category_id` and this pivot stays empty.

### 2.12 `seo_meta`

§105 for every public target. Two addressing forms in one table: a **model** (`seoable_*`) or a **named
route** (`route_key`) for pages that have no row of their own (`site.home`, and later `site.courses.index`).

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `seoable_type` / `seoable_id` | nullableMorphs | null | `Page`, and later `Service`, `Course`, `BlogPost`, ... |
| `route_key` | string(100) | nullable | a route name; mutually exclusive with the morph |
| `title` | string(180) | nullable | §105 SEO title; falls back to `seo.meta_title` then `company.name` |
| `meta_description` | string(320) | nullable | §105 |
| `meta_keywords` | string(500) | nullable | §105 |
| `canonical_url` | string(500) | nullable | §105; absolute, validated; empty = self-canonical from `seo.canonical_base_url` |
| `robots` | string(24) | `index_follow` | cast `RobotsDirective` - §105 index/noindex |
| `og_title` | string(180) | nullable | falls back to `title` |
| `og_description` | string(320) | nullable | falls back to `meta_description` |
| `og_image_media_id` | FK `media_assets.id` | nullable | `nullOnDelete`; §105 OG image. Falls back to `seo.og_image` then `branding.og_image` |
| `og_type` | string(32) | `website` | |
| `sitemap_include` | boolean | true | |
| `sitemap_priority` | decimal(2,1) | 0.5 | |
| `sitemap_changefreq` | string(16) | `weekly` | cast `SitemapChangeFrequency` |
| `last_checked_at` | timestamp | nullable | stamped by the SEO screen's completeness run |
| timestamps / blameable | | | **no `deleted_at`** - see §2.14 |

**Keys.** `UNIQUE uq_seo_target(seoable_type, seoable_id)`; `UNIQUE uq_seo_route(route_key)`;
`INDEX (robots, sitemap_include)` - the sitemap query.
**CHECK** `chk_seo_priority`: `sitemap_priority >= 0.0 AND sitemap_priority <= 1.0`.
**CHECK** `chk_seo_target`: `(seoable_id IS NOT NULL AND route_key IS NULL) OR (seoable_id IS NULL AND route_key IS NOT NULL)`.
**Relationships.** morphTo `seoable`; belongsTo `MediaAsset` (`ogImage`).

### 2.13 `media_assets`

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `disk` | string(32) | `public` | |
| `directory` | string(191) | not null | `cms/2026/09/01H9Z...` |
| `filename` | string(191) | not null | the stored original file name (ULID based, never the user's) |
| `original_name` | string(191) | not null | what the user uploaded, for the library UI |
| `mime_type` | string(100) | not null | from `finfo`, not from the request (INV-11) |
| `extension` | string(12) | not null | derived from the detected MIME |
| `size_bytes` | unsignedBigInteger | not null | |
| `width` / `height` | int unsigned | nullable | null for non-images (video) |
| `duration_seconds` | int unsigned | nullable | background video (§9) |
| `checksum` | char(64) | not null | sha256 of the original - re-uploading the same file reuses the row |
| `collection` | string(32) | `general` | cast `MediaCollection` |
| `profile` | string(24) | nullable | cast `ImageProfile` - which derivative set was generated |
| `variants` | json | nullable | `{ "960": {"path": "...", "format": "webp", "size_bytes": 1234}, ... }` |
| `alt_text` | string(255) | nullable | **required for every image placed in a section** (validated at section save, not at upload) |
| `title` | string(191) | nullable | |
| `caption` | string(500) | nullable | |
| `derivatives_status` | string(16) | `pending` | cast `MediaProcessingStatus` |
| `derivatives_generated_at` | timestamp | nullable | |
| `failure_reason` | string(255) | nullable | why derivative generation failed |
| `usage_count` | int unsigned | 0 | cache recomputed by `MediaService::recountUsage()` |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_media_checksum(checksum)`; `INDEX (collection, created_at)`;
`INDEX (derivatives_status)`; `INDEX (usage_count)`; `INDEX (mime_type)`.
**CHECK** `chk_media_size`: `size_bytes > 0`.
**Relationships.** belongsToMany `WebsiteSection` through `website_section_media`; hasMany
`WebsiteSectionItem`, `Page` (`banner`), `CtaBlock` (`background`), `SeoMeta` (`ogImage`).
`MediaPolicy::delete()` returns false while `usage_count > 0`.

### 2.14 `cms_revisions` and `sitemap_generations` - the two tables without soft deletes

`cms_revisions` is append-only; `sitemap_generations` and `seo_meta` gain nothing from a nullable
`deleted_at`. This is not a deviation but the **category rule**: revision, snapshot and log tables carry no
`deleted_at`. Cite **D19** (`CLAUDE.md` §3 block A) - no local decision number is claimed. **[D-W3-6]**

`cms_revisions`

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `revisionable_type` / `revisionable_id` | morphs | not null | `WebsiteSection`, `Page` (later: any draft/publish entity) |
| `event` | string(24) | not null | cast `RevisionEvent` |
| `snapshot` | longText | not null | the canonical payload JSON at that moment (fields + items + media roles + pivots) |
| `content_hash` | char(40) | not null | lets the UI say "identical to the live version" |
| `is_published_snapshot` | boolean | false | published snapshots are **never pruned** |
| `label` | string(150) | nullable | "Before Ramadan campaign" |
| `reason` | string(255) | nullable | mandatory on `unpublished` and `reverted` |
| `created_by` | FK `users.id` | nullable | `nullOnDelete` |
| `created_at` | timestamp | nullable | **no `updated_at`** - a revision is never edited |

**Keys.** `INDEX idx_rev_target(revisionable_type, revisionable_id, id)`;
`INDEX (is_published_snapshot)`.

`sitemap_generations`

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `url_count` | int unsigned | 0 | |
| `byte_size` | int unsigned | 0 | |
| `duration_ms` | int unsigned | 0 | |
| `trigger` | string(24) | `manual` | `manual`, `publish`, `scheduled` |
| `status` | string(16) | `ok` | `ok` / `failed` |
| `failure_reason` | string(500) | nullable | |
| `providers` | json | nullable | `{ "pages": 12, "static": 4 }` - so a reviewer sees which phase contributed what |
| `created_by` | FK `users.id` | nullable | `nullOnDelete` |
| `created_at` | timestamp | nullable | |

**Keys.** `INDEX (created_at)`.

### 2.15 What "draft vs published" means per entity - the boundary

Two content copies are expensive. They are worth it only where a half-finished edit would be visible on
the home page mid-sentence. Snapshot publishing plus the cache **version stamp** is decision **D22**.
**[D-W3-7]**

| Entity | Model | Why |
|---|---|---|
| `website_sections`, `pages` | **snapshot publish**: `content` -> `published_content`, hash-compared, revisioned, revertable | These are the page body. An editor rewriting the hero must not ship it word by word |
| `website_section_items`, `website_section_media`, `faq_website_section` | **no snapshot of their own** - they are folded into their parent section's `published_content` at publish time | Reordering six statistics must not go live until the hero does |
| `menus`, `menu_items` | **status-gated, live**: `is_enabled` + the target's publish state; published on cache flush | A menu is navigation, not prose. Phase 3 publishes menus by flushing the cache; the header section's snapshot stores the **resolved tree** so a half-built menu cannot appear ([D-W3-8], R-2) |
| `cta_blocks`, `faqs`, `faq_categories` | **status-gated, live** (`ContentStatus`) | Small referenced entities; `draft` simply never renders |
| `seo_meta`, `media_assets` | live | Metadata and binaries have no "half-written" state worth hiding |

### 2.16 Relationship map (one line per edge that matters)

```
Page            1---*  WebsiteSection        (placement = page, cascadeOnDelete)
Page            1---1  SeoMeta               (morphOne)
Page            *---1  MediaAsset            (banner_media_id)
Page            1---*  MenuItem              (page_id, nullOnDelete)
Page            1---*  CmsRevision           (morphMany)
WebsiteSection  1---*  WebsiteSectionItem    (cascadeOnDelete)
WebsiteSection  *---*  MediaAsset            (pivot website_section_media, role + sort_order)
WebsiteSection  *---*  Faq                   (pivot faq_website_section, sort_order)
WebsiteSection  *---1  CtaBlock              (cta_block_id, nullOnDelete)
WebsiteSection  *---1  Menu                  (menu_id, nullOnDelete)
WebsiteSection  1---*  CmsRevision           (morphMany)
Menu            1---*  MenuItem              (cascadeOnDelete)
MenuItem        1---*  MenuItem              (parent_id, cascadeOnDelete, depth <= 1)
MenuItem        *---1  any                   (morphTo linkable - unused in Phase 3)
FaqCategory     1---*  Faq                   (nullOnDelete)
Faq             *---1  any                   (morphTo faqable - Phase 14 courses)
CtaBlock        *---1  MediaAsset            (background_media_id)
SeoMeta         *---1  MediaAsset            (og_image_media_id)
SeoMeta         *---1  any                   (morphTo seoable)
MediaAsset      1---*  WebsiteSectionItem    (media_asset_id, nullOnDelete)
User            1---*  everything             (created_by / updated_by / published_by, nullOnDelete)
```

---

## 3. Enums to add

All in `app/Enums/`, string-backed, implementing `label(): string` and `color(): string` and exposing
`static options(): array`, exactly as Phase 1 §2 requires.

| Enum | Cases (values) | Extra members |
|---|---|---|
| `ContentStatus` | `draft`, `scheduled`, `published`, `archived` | `isPublic(): bool` (true only for `published`), `isEditable(): bool` |
| `SectionPlacement` | `home`, `global_header`, `global_footer`, `page` | `isGlobal(): bool`, `allowsPage(): bool` (true only for `page`). **Later phases add cases** (`courses_index`, `services_index`) - a new placement is a new enum case plus a registry entry, never a migration |
| `MenuLocation` | `header`, `footer_primary`, `footer_secondary`, `footer_legal`, `mobile` | `isFooter(): bool`. `mobile` is optional: when no `mobile` menu exists the drawer renders the `header` menu |
| `MenuItemLinkType` | `page`, `route`, `section_anchor`, `url`, `none` | `requiresTarget(): bool`; `none` = a dropdown parent that is a label only. Later phases add `course`, `service`, `blog_category` and fill `linkable_*` |
| `MenuVisibility` | `all`, `guest`, `auth` | `matches(?User $user): bool` - §8's Login button is `guest`, a "My panel" link is `auth` |
| `PageLayout` | `content`, `sections` | `usesSections(): bool` |
| `RobotsDirective` | `index_follow`, `index_nofollow`, `noindex_follow`, `noindex_nofollow` | `isIndexable(): bool`, `toHeader(): string` (e.g. "noindex, nofollow") - §105 index/noindex |
| `SitemapChangeFrequency` | `always`, `hourly`, `daily`, `weekly`, `monthly`, `yearly`, `never` | - |
| `CtaVariant` | `banner`, `card`, `inline`, `split`, `full_width` | `view(): string` (the Blade partial) |
| `ButtonStyle` | `primary`, `secondary`, `outline`, `ghost`, `link` | `classes(): string` - the only place button Tailwind classes are written |
| `StatisticMetric` | `manual`, `projects_completed`, `happy_clients`, `students_trained`, `active_courses`, `team_members`, `years_experience` | `module(): ?string` (the module slug that must be enabled), `table(): ?string` (checked with `Schema::hasTable`), `isLive(): bool`, `defaultLabel(): string`, `defaultSuffix(): ?string` - §9 verbatim, resolved by `StatisticsProvider` (§6.11) |
| `MediaCollection` | `sections`, `pages`, `cta`, `seo`, `faq`, `general` | `diskPath(): string`, `defaultProfile(): ImageProfile` |
| `ImageProfile` | `hero`, `banner`, `card`, `thumbnail`, `logo`, `icon`, `og`, `video_poster` | `widths(): array`, `maxWidth(): int`, `quality(): int`, `crop(): bool`, `aspect(): ?string`, `sizesAttribute(): string`, `acceptsTransparency(): bool` - §6.8 |
| `MediaProcessingStatus` | `pending`, `processing`, `ready`, `failed`, `skipped` | `isUsable(): bool` (`ready` or `skipped` - a non-image is `skipped`, not `failed`) |
| `RevisionEvent` | `created`, `draft_saved`, `published`, `unpublished`, `reverted`, `restored` | `isPublishSnapshot(): bool` |
| `PreviewScope` | `section`, `page`, `placement` | - |

**No enum is created for `section_key`.** Section types live in `WebsiteSectionRegistry` (code, §6.1) so
phases 4, 5, 14 and 15 add a type by dropping in a class - adding a case to an enum every phase touches
would create exactly the merge conflict the Registry pattern exists to avoid. **[D-W3-9]**

---

## 4. PermissionRegistry additions

Ability presets are Phase 1's: `READ`, `CRUD`, `CRUD_FULL`, `APPROVE`, `STATUS`, `ASSIGN`, `FILES`,
`MONEY`, `REPORTS`, `LOGS`.

**Publishing is `change_status`. Previewing is `view`. Reordering is `edit`.** Phase 1's `Ability` enum is
a closed list and this phase invents no case, exactly as the financial spine used `change_status` for "run
a reconciliation". The split is useful rather than a compromise: a content editor holding
`website_sections.edit` can write drafts all day and cannot put anything live. **[D-W3-10]**

### 4.1 New module slugs (all `is_core = false`, all `ModuleGroup::Website`)

| slug | icon | Abilities | Why these |
|---|---|---|---|
| `website_cta_blocks` | `megaphone` | `CRUD` + `STATUS` + `LOGS` | §100 "CTAs"; `delete` is policy-blocked while `usage_count > 0` |
| `website_media` | `photo` | `READ` + `create` + `edit` + `delete` + `FILES` + `LOGS` | the shared image library of §1.3; `edit` = alt text and caption only |
| `faq_categories` | `rectangle-stack` | `CRUD` + `STATUS` | mirrors Phase 1's split of `blog_categories` from `blog_posts` |

`cms_revisions`, `sitemap_generations` and `website_section_items` deliberately get **no module of their
own**: revisions are read under `website_sections.view_logs`, sitemap history under `seo.view`, and items
are edited inside the section form under `website_sections.edit`. A module per child table would add
permission surface for no gain.

### 4.2 Abilities added to Phase 1's existing Website slugs

Additive - `PermissionRegistry` stays the only place a permission name is declared (D4).

| slug | Abilities after this phase | Notes |
|---|---|---|
| `website_sections` | `CRUD` + `STATUS` + `LOGS` | `create` = place a section, `delete` = remove a placed section (blocked for required types, INV-7), `change_status` = publish / unpublish / enable / disable / revert / flush the public cache |
| `menus` | `CRUD` + `STATUS` | `change_status` = enable/disable an item |
| `pages` | `CRUD_FULL` + `STATUS` + `LOGS` | `print` and `export` come from `CRUD_FULL`; `change_status` = publish / schedule / unpublish; `delete` blocked for `is_system` |
| `faqs` | `CRUD` + `STATUS` | |
| `seo` | `READ` + `edit` + `export` + `LOGS` | `edit` covers per-target SEO, robots.txt content and "regenerate sitemap"; `export` downloads the SEO audit as CSV. **No `create`/`delete`**: a `seo_meta` row is an attribute of something else and is created implicitly |

### 4.3 Portal permissions

**None.** The public website is anonymous; the student, teacher, client and collaborator panels get no CMS
permission at all (§9). No `*_portal.*` permission is added or changed by this phase.

---

## 5. SettingsRegistry additions

Phase 2 owns the registry. Phase 3 adds **one new group** (`website`) and **four keys to the existing
`seo` group**. The `website` group is declared **exactly once, here** (F-6.3): Phase 4 declares no group of
its own and contributes its 21 keys into this one, so the group carries **34 keys** in total - 13 from
Phase 3 (§5.1a) and 21 from Phase 4 (§5.1b), with no key collisions.

> **The count is 34, verified key by key (ND-9).** §5.1a lists 13 rows; §5.1b lists 21 rows; phase-04 §5
> lists the same 21 key names, so the two contracts agree and nothing was lost in the merge.
> `docs/design/resolutions.md` §2.4 reads "phase-03's 13 keys **and** phase-04's 22 keys merged (35 keys)" -
> that **22 / 35 is one high and is the error**; the canonical figures are **13 + 21 = 34**. Do not "restore"
> a 22nd Phase-4 key: there is no missing key to restore. A later phase that genuinely needs another
> `website.*` key adds it here and in phase-04 §5 in the same edit, and bumps both totals together.

Every Phase 2 key this phase consumes (`company.name`, `company.tagline`,
`company.founded_year`, `company.copyright_text`, `branding.*`, `contact.*`, `social.*`,
`seo.meta_title`, `seo.meta_description`, `seo.meta_keywords`, `seo.canonical_base_url`, `seo.og_image`,
`seo.robots_indexable`, `seo.sitemap_enabled`, `seo.google_analytics_id`, `seo.google_tag_manager_id`,
`seo.facebook_pixel_id`, `seo.google_site_verification`, `localization.*`, `maintenance.maintenance_mode`,
`maintenance.maintenance_message`, `maintenance.public_site_enabled`) is used **exactly as defined and is
not redefined here**.

### 5.1 New group `website` - "Website & Forms", declared once

`['label' => 'Website & Forms', 'icon' => 'globe-alt', 'sort' => 75, 'permission' => 'settings.edit']`

The label is **"Website & Forms"** and the sort is **75** (F-6.3). This single declaration serves Phase 3
and Phase 4; the `projects` group moves to sort 86 in phase-06 so nothing collides.

#### 5.1a Keys declared by Phase 3 (13)

| group.key | type | default | public | Meaning |
|---|---|---|---|---|
| `website.cache_enabled` | boolean | `true` | no | master switch for public response caching (§6.7) |
| `website.cache_ttl_minutes` | number | `1440` | no | TTL of a cached public response; the version stamp makes publishes instant regardless |
| `website.cache_warm_enabled` | boolean | `true` | no | re-render the published pages after a flush so the first visitor never pays for it |
| `website.preview_ttl_minutes` | number | `120` | no | lifetime of a shareable signed preview link |
| `website.image_quality` | number | `82` | no | derivative quality, 60-95 |
| `website.image_max_width` | number | `2560` | no | originals wider than this are downscaled on upload; never upscaled |
| `website.image_webp_enabled` | boolean | `true` | yes | emit a WebP source alongside the original format |
| `website.image_lazy_loading` | boolean | `true` | yes | `loading="lazy"` on everything except the hero |
| `website.menu_max_depth` | number | `2` | no | `readonly => true`: the DB CHECK enforces 2 (INV-6); the key exists so the limit is visible, not so it can be raised |
| `website.revision_keep` | number | `20` | no | non-published revisions kept per target; published snapshots are never pruned |
| `website.hero_video_enabled` | boolean | `true` | yes | a global off switch for background video (bandwidth, R-7) |
| `website.faq_accordion_open_first` | boolean | `true` | yes | |
| `website.show_theme_toggle` | boolean | `true` | yes | the public site honours Light/Dark/System like the panels (requirement §2) |

#### 5.1b Keys contributed by Phase 4 into this group (21)

Phase 4 owns the behaviour and the validation rules of these keys (phase-04 §5); they are listed here
because the group is declared once and `SettingsRegistry` must show all 34 keys under one tab. Phase 4
**adds no second `website` group**.

| group.key | type | default | public | Meaning |
|---|---|---|---|---|
| `website.services_per_page` | number | `12` | yes | service cards per page, 3-48 |
| `website.portfolio_per_page` | number | `12` | yes | portfolio cards per page, 3-48 |
| `website.blog_per_page` | number | `9` | yes | blog cards per page, 3-48 |
| `website.blog_related_count` | number | `3` | yes | 0 hides the related-posts block |
| `website.blog_view_dedupe_minutes` | number | `1440` | no | the repeat-view window |
| `website.blog_view_prune_days` | number | `90` | no | `blog_post_views` retention |
| `website.reviews_per_page` | number | `12` | yes | testimonial + student-review feeds |
| `website.team_page_enabled` | boolean | `true` | yes | false -> `/team` 404s |
| `website.portfolio_detail_enabled` | boolean | `true` | yes | false -> `/portfolio/{slug}` 404s |
| `website.testimonial_auto_approve` | boolean | `false` | no | staff-entered reviews only; a public submission is always `pending` |
| `website.careers_enabled` | boolean | `true` | yes | false -> `/careers` 404s |
| `website.careers_notify_emails` | textarea | *(empty)* | no | one email per line, notified on a new application |
| `website.cv_max_mb` | number | `5` | no | effective limit is `min(this, security.max_upload_mb)` |
| `website.cv_allowed_types` | multiselect | `pdf,doc,docx` | no | an empty set is rejected |
| `website.contact_notify_emails` | textarea | *(empty)* | no | as above, for inquiries |
| `website.contact_budget_options` | json | six PKR-shaped labels | yes | feeds the public budget select |
| `website.contact_min_submit_seconds` | number | `3` | no | faster than this is spam |
| `website.contact_rate_per_hour` | number | `20` | no | per-IP hourly cap |
| `website.spam_blocklist` | textarea | *(empty)* | no | one word or domain per line |
| `website.inquiry_auto_route` | boolean | `true` | no | false -> every inquiry waits for a manual **Route now** |
| `website.inquiry_default_assignee_id` | select | *(null)* | no | pre-assigns new inquiries |

### 5.2 Keys added to the existing `seo` group

| group.key | type | default | public | Meaning |
|---|---|---|---|---|
| `seo.robots_txt_mode` | select `auto` / `custom` | `auto` | no | `auto` generates from §6.5; `custom` serves the stored text verbatim |
| `seo.robots_txt_custom` | textarea | empty | no | used only when the mode is `custom`; validated to contain no `Sitemap:` line pointing off-domain |
| `seo.sitemap_changefreq_default` | select (`SitemapChangeFrequency`) | `weekly` | no | stamped on new `seo_meta` rows |
| `seo.sitemap_priority_default` | decimal | `0.5` | no | 0.0-1.0 |

### 5.3 Public settings contract

`App\Support\SiteSettings` is the **only** way a `site.*` view or component reads a setting. It wraps
`SettingsRepository`, returns the typed value, and **throws `NonPublicSettingException`** when the
requested key's registry definition is not `public => true` (INV-10). The Blade helper is
`site_setting('company.name')`. `mail.password`, `security.*` and every collaborator or finance key is
therefore unreachable from the public site by construction, not by review.

---

## 6. Registry and services

Namespaces: `App\Support\` for the registries and value objects, `App\Services\Cms\` for behaviour,
`App\Http\Controllers\Admin\Website\` and `App\Http\Controllers\Site\` for controllers. Controllers
orchestrate only; every write below is a service method (CLAUDE.md §1.9).

### 6.1 `App\Support\WebsiteSectionRegistry` - section types in code, content in the DB

The third instance of the Registry pattern, identical in spirit to `PermissionRegistry` (Phase 1 §4) and
`SettingsRegistry` (Phase 2 §2): **pure arrays, no DB access**, driving the seeder, the "add section"
list, the editor form, and server-side validation from one place.

```
placements(): array                                  // SectionPlacement => ['label','label_plural','allows_custom_order']
types(): array                                       // key => definition
type(string $key): ?array
exists(string $key): bool
forPlacement(SectionPlacement $p): array             // types allowed in that placement, in default sort order
fields(string $key): array                           // key => FieldDefinition
repeaters(string $key): array                        // group => ['label','min','max','item_label_field','fields']
itemFields(string $key, string $group): array
mediaRoles(string $key): array                       // role => ['label','profile','multiple','required']
rulesFor(string $key): array                         // Laravel rules for the draft form
itemRulesFor(string $key, string $group): array
defaults(string $key): array                         // seeded content for a freshly placed section
isUnique(string $key): bool                          // one instance per placement
isRequired(string $key): bool                         // disable-only, never delete (INV-7)
view(string $key): string                            // the public Blade partial
editView(string $key): ?string                       // an optional custom editor panel; null = generic field loop
provider(string $key): ?string                       // a SectionDataProvider class for dynamic content
icon(string $key): string
label(string $key): string
group(string $key): string                           // the add-section modal's grouping
```

**Field definition keys** (the same vocabulary as Phase 2's `SettingsRegistry`, extended):
`label`, `type`, `rules`, `default`, `options`, `help`, `placeholder`, `span` (1-12), `sort`, `required`,
`max_chars` (drives the character counter in the editor).

**Field types**: `text` `textarea` `richtext` `url` `email` `tel` `number` `decimal` `boolean` `select`
`multiselect` `color` `icon` `link` (composite: label + url + style + new_tab) `image` `video`
`cta_ref` `menu_ref` `faq_category_ref` `page_ref`. `image` / `video` fields are **not stored in
`content`** - they declare a `mediaRoles()` slot and resolve to a `website_section_media` pivot row
(INV-3). `cta_ref` / `menu_ref` resolve to the real FK columns of §2.2.

#### Section types shipped by Phase 3

| key | Placement | unique | required | Fields (beyond the obvious) | Repeaters | Media roles | Requirement |
|---|---|---|---|---|---|---|---|
| `header` | `global_header` | yes | yes | `show_company_name`, `company_name_override`, `sticky`, `transparent_over_hero`, `menu_ref`, four `link` fields `login_button` / `contact_button` / `admission_button` / `cta_button` each with its own `*_enabled` boolean | `link` (extra top-bar links, max 3) | `logo_override_light`, `logo_override_dark` (both optional; default comes from `branding.logo_light` / `logo_dark`) | §8 |
| `hero` | `home` | yes | yes | `heading`, `subtitle`, `description` (richtext), `primary_button` (link), `secondary_button` (link), `alignment` (select left/center), `overlay_opacity` (number 0-80), `video_autoplay` (readonly true), `show_statistics` | **`statistic`** (min 0, max 8 - §9 names six) | `hero_image`, `background_image`, `background_video`, `video_poster` | §9 |
| `about` | `home`, `page` | yes | no | `heading`, `company_intro` (richtext), `software_house_intro` (richtext), `institute_intro` (richtext), `mission` (richtext), `vision` (richtext), `history_intro` (richtext), `why_choose_us_heading`, `tabs_enabled` (render the three intros as tabs rather than stacked) | `why_choose_us` (icon + title + text), `history` (year + title + text), `statistic` | `image_1`, `image_2` | §10 |
| `cta` | `home`, `page` | no | no | `cta_ref` (required) | - | - | §100 |
| `faq` | `home`, `page` | no | no | `heading`, `description`, `source` (select `category` / `selected` / `featured`), `faq_category_ref`, `columns` (1-2), `show_all_link` (link) | - | - | §100 |
| `rich_content` | `home`, `page` | no | no | `heading`, `subheading`, `body` (richtext), `layout` (select `full` / `text_left` / `text_right`), `background` (select `surface` / `muted` / `brand`) | `highlight` (icon + title + text) | `image_1` | §7 ("edit headings and descriptions"), §101 |
| `footer` | `global_footer` | yes | yes | `about_text`, `show_contact`, `show_social`, `show_newsletter` (readonly false until a newsletter module exists), `column_1_heading` + `menu_ref`, `column_2_heading` + `menu_ref_2`, `legal_menu_ref`, `copyright_override` | `link` (payment/partner badges, max 6) | `logo_override`, `badge_1`, `badge_2` | §8, §100 |

#### Section types declared by later phases - the contract they implement

A later phase adds a type by adding one registry entry plus one Blade partial under
`resources/views/site/sections/`. It adds **no table, no permission and no route**. Phase 3 ships none of
these and the admin "add section" list simply does not offer them yet.

| key | Owner phase | Default home sort | Dynamic data via |
|---|---|---|---|
| `services` | 4 | 30 | `SectionDataProvider` reading `services` |
| `portfolio` | 4 | 50 | `portfolio_items` |
| `team` | 4 | 60 | `team_members` |
| `testimonials` | 4 | 70 | `testimonials` (approved only) |
| `blog` | 4 | 100 | `blog_posts` (published only) |
| `careers` | 4 | 105 | `jobs` (open only) |
| `contact` | **4** | 130 | posts to `contact_inquiries` (**Phase 4 owns the table, the form handling and the §17 routing**, F-2.1); `InquiryRouter` routes software inquiries to CRM and course inquiries to the Institute inquiry module |
| `courses`, `upcoming_batches`, `trainers`, `facilities`, `admission_open` | 14 / 16 | 40, 45, 65, 115, 25 | `courses`, `batches`, `teachers` (§89-90) |
| `student_reviews`, `success_stories` | 14 / 15 | 80, 90 | approved rows only (§91, §92) |

`interface App\Contracts\Cms\SectionDataProvider { public function resolve(WebsiteSection $section): array; }`
A provider is called **at publish time** (its output is folded into `published_content`) **and** at render
time for types marked `is_live => true` (a "latest 3 blog posts" strip must not freeze at publish).
`is_live` providers are the only reason a public page issues a query beyond the section read, and each one
must be cached by the provider itself. **[D-W3-11]**

### 6.2 `WebsiteSectionService`

| Method | Guarantees |
|---|---|
| `place(string $key, SectionPlacement $p, ?Page $page = null): WebsiteSection` | rejects an unknown key (`UnknownSectionTypeException`), rejects a type not allowed in that placement, rejects a second instance of a `is_unique` type via `uq_ws_instance` (the INSERT decides, never a SELECT-then-INSERT), seeds `content` from `defaults()`, appends `sort_order = max + 10`, sets `status = draft`, writes a `created` revision |
| `saveDraft(WebsiteSection $s, array $content, array $media = []): WebsiteSection` | validates against `rulesFor()`, sanitizes every `richtext` field (INV-13), rejects any key not in `fields()`, syncs `website_section_media` roles (single-slot roles replaced, gallery roles ordered), recomputes `content_hash`, stamps `draft_updated_at`, writes a `draft_saved` revision, **never touches `published_content`** |
| `publish(WebsiteSection $s): WebsiteSection` | one transaction: builds the snapshot (fields + enabled items in `sort_order` + media URLs and srcsets + resolved CTA + resolved menu tree + provider output for non-live providers), writes `published_content`, copies `content_hash` into `published_hash`, sets `status = published` and `published_at` / `published_by`, writes a `published` revision with `is_published_snapshot = true`, and on commit bumps the public cache version. Refuses when a `required => true` field is empty, when an image role marked `required` is empty, or when any placed image has no `alt_text` |
| `unpublish(WebsiteSection $s, string $reason): WebsiteSection` | reason mandatory; `status = draft`, `published_content` **kept** (so the revision history and a re-publish are lossless), section stops rendering, cache bumped, activity row with the reason |
| `toggle(WebsiteSection $s, bool $enabled): WebsiteSection` | flips `is_enabled` only; never changes `status`; cache bumped |
| `reorder(SectionPlacement $p, ?Page $page, array $orderedIds): void` | one transaction; asserts the id set is **exactly** the placement's current set (no additions, no omissions - a stale browser tab cannot drop a section); writes `sort_order` 10, 20, 30 ... contiguously (INV-5); one activity row carrying the old and new order; cache bumped |
| `duplicate(WebsiteSection $s): WebsiteSection` | only for non-unique types; copies content, items and media pivots; `status = draft`, `name` suffixed "(copy)", `anchor` cleared |
| `remove(WebsiteSection $s, string $reason): void` | refuses `isRequired()` types (INV-7); soft delete; items cascade logically (their rows stay, the parent is trashed); media `usage_count` recounted; cache bumped |
| `revertToRevision(WebsiteSection $s, CmsRevision $r, string $reason): WebsiteSection` | restores `content`, items and media pivots from the snapshot into the **draft**, never straight to live; writes a `reverted` revision |
| `upsertItem(WebsiteSection $s, string $group, array $data, ?WebsiteSectionItem $item = null)` | validates against `itemRulesFor()`, enforces the repeater's `max`, requires `metric` when `value_mode = auto`, stores `manual_value` as a decimal string, recomputes the parent `content_hash` |
| `reorderItems(WebsiteSection $s, string $group, array $orderedIds): void` | same contiguity and exact-set guarantees as `reorder()`, scoped to one group |
| `deleteItem(WebsiteSectionItem $item): void` | refuses when it would drop the repeater below `min`; soft delete; parent hash recomputed |
| `draftPayload(WebsiteSection $s): array` / `publishedPayload(WebsiteSection $s): array` | the two inputs the renderer accepts; `draftPayload` is what preview renders (§6.12) |

`App\Support\CmsHasher::hash(array $canonical): string` - sha1 over a canonical array: field keys sorted,
items as `[group => [[sort_order, content, metric, value_mode, manual_value, media_asset_id, is_enabled]]]`,
media as `[role => [ids in sort order]]`. Deterministic across PHP versions (no `serialize`, no float
formatting).

### 6.3 `MenuService`

| Method | Guarantees |
|---|---|
| `storeItem(Menu $m, array $data): MenuItem` | validates `link_type` against its required target; `parent_id` must belong to the same menu and be top level; sets `depth` = 0 or 1; rejects depth 2 before the CHECK has to (INV-6); URL scheme allowlist `http`, `https`, `mailto`, `tel`, or a leading `/` (INV-13); `route_name` must satisfy `Route::has()` |
| `updateItem(MenuItem $i, array $data): MenuItem` | additionally rejects making an item its own parent or the parent of its own parent (cycle), and rejects re-parenting an item that has children (that would create depth 2) |
| `reorder(Menu $m, array $tree): void` | one transaction; `$tree` is `[['id' => 4, 'children' => [['id' => 9]]]]`; asserts the flattened id set equals the menu's current set; rewrites `parent_id`, `depth` and contiguous `sort_order` per level; one activity row with the old and new tree |
| `tree(MenuLocation $location): Collection` | cached under the public cache version; returns **enabled** items whose target resolves: a `page` item whose page is draft, trashed or missing is **omitted, not rendered dead** (§12.1 R-3); visibility is applied per request, **after** the cache, by the Blade component |
| `resolveUrl(MenuItem $i): ?string` | `page` -> `route('site.page', $page->slug)`, `route` -> `route($name, $params)`, `section_anchor` -> `url('/') . '#' . $anchor`, `url` -> verbatim, `none` -> null. Never stored; always computed, so renaming a slug fixes every menu at once |
| `flushCache(): void` | called by the publish/bump listener, not by controllers |

### 6.4 `PageService`

| Method | Guarantees |
|---|---|
| `create(array $data): Page` | slug from `slugFor()`, `status = draft`, `layout` validated, `template` validated against the allowlist, rich text sanitized |
| `saveDraft(Page $p, array $data): Page` | writes `content` only, recomputes `content_hash`, revision `draft_saved`; refuses a slug change on an `is_system` page without `pages.change_status` and records the old slug in the activity row |
| `publish(Page $p): Page` | `content` -> `published_content`, hashes copied, `status = published`, `published_at = now()` unless a future `published_at` is set (then `status = scheduled`), revision `published`, cache bumped, `seo_meta` row created with defaults if absent, sitemap regeneration queued |
| `schedule(Page $p, CarbonInterface $at): Page` | `status = scheduled`, `published_at = $at` (must be future); `cms:publish-scheduled` promotes it |
| `unpublish(Page $p, string $reason): Page` | reason mandatory; page returns 404 to the public; menu items pointing at it disappear (§6.3); cache bumped |
| `duplicate(Page $p): Page` | new slug `{slug}-copy`, `status = draft`, `is_system = false`, sections copied when `layout = sections`, SEO copied with `sitemap_include = false` |
| `delete(Page $p): void` | refuses `is_system` (FT-17); soft delete; menu items pointing at it are disabled (not deleted) and the admin is told which; sections of that page are soft-deleted with it; cache bumped |
| `slugFor(string $title, ?Page $except = null): string` | `Str::slug`, uniqueness against **all** rows including trashed, suffix `-2`, `-3`; rejects anything in `reservedSlugs()` |
| `reservedSlugs(): array` | `admin`, `login`, `logout`, `register`, `password`, `forgot-password`, `reset-password`, `verify-email`, `collaborator`, `student`, `teacher`, `client`, `api`, `storage`, `preview`, `sitemap.xml`, `robots.txt`, `up`, plus every first segment reserved for later phases: `courses`, `services`, `portfolio`, `blog`, `careers`, `contact`, `admission`, `certificate`. A reserved slug is a **validation error naming the conflict**, never a silent rename |

### 6.5 SEO, sitemap, robots

```
App\Support\SeoPayload        // readonly DTO
  title, metaDescription, metaKeywords, canonicalUrl, robots (RobotsDirective),
  ogTitle, ogDescription, ogImageUrl, ogType, siteName, locale, imageWidth, imageHeight

App\Services\Cms\SeoService
  for(Model|string $target): SeoPayload
  save(Model|string $target, array $data): SeoMeta
  completeness(SeoMeta $m): int                 // 0-100, drives the SEO screen meter
  auditRows(): Collection                       // every indexable target + its gaps, for the table and the CSV export
  rules(string $prefix = 'seo'): array          // the ONE validation rule set for SEO input (D23)
```

**`rules()` is the single SEO validation contract (D23, ND-13).** Because `seo_meta` is the only SEO store,
its **validation** lives with it too: `rules()` returns the Laravel rule array for the six writable
`seo_meta` columns, keyed under `$prefix` so it can be merged into any entity Form Request - and **each max
is taken from §2.12's column width, not guessed**: `seo.title` `nullable|string|max:180`,
`seo.meta_description` `nullable|string|max:320`, `seo.meta_keywords` `nullable|string|max:500`,
`seo.canonical_url` `nullable|url|max:500`, `seo.robots` `nullable|Enum(RobotsDirective)`,
`seo.og_image_media_id` `nullable|integer|exists:media_assets,id`. (A duplicated rule is also a *wrong*
rule: the copy that ND-13 removed from phase-04 §6.11 said `max:255` against a `string(320)` column.)
`<x-cms.seo-fields>` posts under that prefix and an entity Form Request does
`array_merge($own, app(SeoService::class)->rules())` - it **never restates a `seo_meta` column's rule**
(phase-04 §6.11 does exactly this). One store, one writer, one rule set: a Form Request that declares its
own `meta_description` rule is how a second SEO write path starts, so there is none.

**Fallback chain, applied per field** (the first non-empty wins):

| Field | Chain |
|---|---|
| `title` | `seo_meta.title` -> target title (`Page::title`) -> `seo.meta_title` -> `company.name` |
| suffix | `" | " . company.name` appended unless the title already contains it |
| `meta_description` | `seo_meta.meta_description` -> `Page::excerpt` -> `seo.meta_description` -> `company.short_description` |
| `meta_keywords` | `seo_meta.meta_keywords` -> `seo.meta_keywords` |
| `canonical_url` | `seo_meta.canonical_url` -> `seo.canonical_base_url` + the current path -> `url()->current()` |
| `robots` | `seo_meta.robots`, then **forced to `noindex_nofollow`** when `seo.robots_indexable` is false, when `maintenance.maintenance_mode` is on, or when the response is a preview (INV-9) - the strictest wins, never the loosest |
| `og_image` | `seo_meta.og_image_media_id` -> the target's banner/hero image -> `seo.og_image` -> `branding.og_image` |

`SitemapService`

```
urls(): Collection<SitemapUrl>     // loc, lastmod, changefreq, priority
generate(): string                 // <urlset> XML, UTF-8, no trailing whitespace
cached(): string                   // version-stamped cache, TTL 24h
regenerate(string $trigger, ?User $by = null): SitemapGeneration
```
Included: every `Page` with `status = published`, `seo_meta.sitemap_include = true` and a `robots` that
`isIndexable()`; plus the static routes registered by `SitemapRegistry` (Phase 3 registers `site.home`).
`lastmod` = `published_at`, or `updated_at` when later. Excluded with no exception: drafts, scheduled
pages, trashed pages, noindex targets, preview URLs, anything behind auth. Returns **404** when
`seo.sitemap_enabled` is false. Above 40 000 URLs the service emits a `<sitemapindex>` with 40 000-URL
chunks at `/sitemap-{n}.xml` - stated now so the contract does not have to change later; with the page
counts this business will have it never triggers.

`App\Support\SitemapRegistry` - `register(string $key, SitemapUrlProvider $p)` /  `providers(): array`;
`interface SitemapUrlProvider { public function urls(): iterable; public function key(): string; }`.
Phases 4 and 14 register `services`, `portfolio`, `blog`, `courses` here and **must not edit
`SitemapService`**. The provider key and its URL count are recorded in `sitemap_generations.providers`.

`RobotsService::render(): string`

| Condition | Output |
|---|---|
| `seo.robots_txt_mode = custom` | `seo.robots_txt_custom` verbatim, with a `Sitemap:` line appended if absent and `seo.sitemap_enabled` is on |
| `seo.robots_indexable = false` **or** `maintenance.maintenance_mode = true` **or** `public_site_enabled = false` | `User-agent: *` + `Disallow: /` (and no `Sitemap:` line) |
| otherwise (`auto`) | `User-agent: *`, `Allow: /`, then `Disallow:` for `/admin`, `/collaborator`, `/student`, `/teacher`, `/client`, `/login`, `/preview`, `/storage/cms/originals`, then a blank line and `Sitemap: {canonical_base_url}/sitemap.xml` |

`robots.txt` is served **outside** the maintenance gate: a crawler must be able to read "stay away"
while the site is down. **[D-W3-13]**

### 6.6 Icons, link validation, rich text

| Concern | Rule |
|---|---|
| Icons | `resources/data/icons.php` returns an allowlisted Heroicons name list grouped for the picker. An `icon` field validates `in:` that list; `x-ui.icon` renders it. A custom icon is an `image` field with `ImageProfile::Icon`, never raw SVG markup |
| Link fields | `url` must match `^(https?://|mailto:|tel:|/|#)` - `javascript:`, `data:` and `vbscript:` are rejected by validation **and** by `RichText` (INV-13). This is a rule about **link hrefs** and holds under every profile; the `material` profile's `data:` concession is for an `<img src>` of an allowlisted image type only, never for an `href` (see the map below). `open_new_tab` always emits `rel="noopener noreferrer"` |
| Rich text | `App\Support\RichText::sanitize(string $html, string $profile = 'cms'): string` is the **only** HTML sanitiser in the system (D25 - no phase ships a second one, and no phase adds a `mews/purifier` profile of its own). See the profile map below |
| Plain text fields | escaped by Blade as normal; no `{!! !!}` anywhere in `site/` except the two sanitized rich-text and map-embed outputs, which are grepped for by FT-37 |

**The `RichText` profile map (ND-5).** `$profile` is a **name**, resolved through a closed `PROFILES`
constant declared inside `RichText` itself; the caller's string is **never** forwarded to
`mews/purifier` as a config key. An unrecognised name throws `UnknownRichTextProfileException`, so a later
phase cannot invent a profile by passing one, and adding a third is an edit to this class (plus a reviewed
addition to `config/purifier.php`, §13.4) treated as a security change. Two profiles are committed; there
is no third, and there is still exactly **one** sanitiser class and **one** code path (D25).

| Profile | Who uses it | Allowed on top of the common core |
|---|---|---|
| **`cms`** *(the default)* | every Phase 3 field (§2.2 `content`, §2.10 `answer`, pages, CTA blocks) and every Phase 4 rich-text field | tags `p br strong em u s h2 h3 h4 ul ol li blockquote a img figure figcaption table thead tbody tr th td hr span`; attributes `href title target rel src alt width height class` (`class` restricted to a fixed allowlist); `src` must be `https:` or site-relative; `<iframe>` allowed **only** for `www.youtube.com/embed`, `player.vimeo.com/video`, `www.google.com/maps/embed` (§12.2 Q3's map embed) |
| **`material`** | phase-19-23's `PrintTemplateService` (`print_templates.body_html` / `custom_css`, INV-21-5, D52/D53) and its course-material HTML - the **wider document/layout set** those screens need | everything in `cms` **minus** the iframe hosts, **plus** tags `div h1 h5 h6 small b i sub sup`; **plus** attributes `style align`; `src` additionally accepts `data:` images (`image/png`, `image/jpeg`, `image/gif`, `image/webp` only) and relative paths; CSS in `style` and in a supplied stylesheet is sanitised - `@import`, `expression(` and any external `url()` are stripped |

Common core, enforced for **both** profiles and not weakenable by a profile: `script`, `object`, `embed`,
`link`, `meta`, `form`, `input`, `base` and `applet` are removed; every `on*` attribute is removed; every
`javascript:` / `vbscript:` / `file:` URL is removed; every Blade and PHP construct (`{{`, `{!!`, `@php`,
`@include`, `@extends`, `<?php`) is removed; any `<iframe>` not on the calling profile's host allowlist is
removed. **One class, one code path, one security control - per-context allowlists, never per-phase
sanitisers** (F-2.5, D25). Every profile sanitises on save **and** again on render, because the DB is not
a trust boundary.

### 6.7 Public cache

```
App\Services\Cms\PublicCache
  version(): int                                  // cache key cms.version, falls back to 1
  bump(string $reason): int                       // increment + activity row + optional warm dispatch
  key(Request $r): string                         // "cms:v{version}:" . sha1(scheme|host|path|locale|whitelisted query)
  remember(Request $r, Closure $render): Response
  flush(): void                                   // alias of bump(); there is nothing to delete
```

**Why a version stamp and not tags**: the project runs the **database** cache driver (no Redis - see the
environment snapshot in `DEVELOPMENT_LOG.md` §2) and Laravel's database store does not support tagged
flushing. Incrementing one integer invalidates every public page at once in O(1); orphaned entries die of
their own TTL. This is the second half of decision **D22**. **[D-W3-14]**

`CachePublicResponse` (terminable middleware, alias `site.cache`) stores the rendered response and serves
it with an `ETag` of the cache key plus `Cache-Control: public, max-age=300`. It **bypasses** entirely
when any of these is true: the request is not a `GET`, the user is authenticated, preview mode is active,
`website.cache_enabled` is false, the response status is not 200, the response carries a `Set-Cookie` other
than the session cookie, the session holds a flash message, or the query string contains a key outside the
whitelist (`page`, `category`, `ref`). **`ref` is whitelisted but keyed into the cache key**, so the §38
referral parameter can never be served from another visitor's cached page.

### 6.8 `MediaService` and responsive images

```
store(UploadedFile $f, MediaCollection $c, ImageProfile $p, array $meta = []): MediaAsset
generateDerivatives(MediaAsset $a): void
url(MediaAsset $a, ?int $width = null): string
srcset(MediaAsset $a): string
sizes(MediaAsset $a): string
delete(MediaAsset $a): void
recountUsage(?MediaAsset $a = null): void
```

`store()` guarantees, in order: size checked against `security.max_upload_mb` (Phase 2); the **real** MIME
read with `finfo` from the temp file and matched against `image/jpeg|png|webp|gif|avif` or
`video/mp4|webm` (the client's `Content-Type` and the extension are both ignored, INV-11); **SVG is
rejected** ([D-W3-15], R-6); a second pass asserts `getimagesize()` succeeds for images; EXIF stripped
(orientation applied first); the original downscaled to `website.image_max_width` if wider, **never
upscaled**; stored as `cms/{Y}/{m}/{ulid}/{ulid}.{ext}` on the `public` disk with a ULID name so the
user's filename can never become a path; `checksum` computed and an existing row returned when it matches
(re-uploading the same picture does not duplicate it); `derivatives_status = pending` and
`GenerateImageDerivatives` dispatched `afterCommit`. A video is stored with
`derivatives_status = skipped` and requires a `video_poster` image.

`ImageProfile` widths, quality 82 by default (`website.image_quality`), WebP plus the original format:

| Profile | Widths | Crop | `sizes` attribute | Used by |
|---|---|---|---|---|
| `hero` | 640, 960, 1280, 1920, 2560 | no | `100vw` | `hero_image`, `background_image` |
| `banner` | 640, 960, 1280, 1920 | 21:9 | `100vw` | page banners |
| `card` | 320, 480, 640, 960 | 16:9 | `(min-width:1024px) 33vw, (min-width:640px) 50vw, 100vw` | section items, later-phase cards |
| `thumbnail` | 96, 192, 384 | 1:1 | `96px` | media library, avatars in testimonials |
| `logo` | 120, 240, 480 | no | `240px` | header/footer logo overrides; transparency preserved, never converted to JPEG |
| `icon` | 48, 96, 144 | 1:1 | `48px` | custom item icons |
| `og` | 1200 | 1200x630 | - | `seo_meta.og_image` |
| `video_poster` | 640, 1280, 1920 | 16:9 | `100vw` | hero video poster |

`<x-site.image :asset="$asset" profile="hero" :eager="true" />` emits a `<picture>` with a WebP `<source
srcset>`, a fallback `<img srcset>` in the original format, the profile's `sizes`, the intrinsic `width`
and `height` (so there is no layout shift), `alt` from `alt_text`, `loading="lazy"` unless `eager`, and
`decoding="async"`. When `derivatives_status` is not `isUsable()` it renders the original with a
`width`/`height` and logs once - it never renders a broken `srcset`.

`recountUsage()` counts `website_section_media` + `website_section_items.media_asset_id` +
`pages.banner_media_id` + `cta_blocks.background_media_id` + `seo_meta.og_image_media_id` + a `LIKE` scan
of rich-text bodies for the asset's directory (inline editor images, the one reference that is a URL
rather than a FK - stated as the single exception to INV-3).

### 6.9 `PublicPageService` - composing a page

```
home(): SitePayload
page(Page $p): SitePayload
preview(PreviewScope $scope, Model $target): SitePayload
header(): array          // the global_header section's published snapshot + resolved menu
footer(): array          // the global_footer section's published snapshot + resolved menus
sections(SectionPlacement $p, ?Page $page, bool $draft = false): Collection
```

`App\Support\SitePayload` (readonly): `header`, `footer`, `sections`, `seo` (`SeoPayload`), `page`,
`isPreview`, `bodyClass`.

The published read is **one query**: `website_sections` where placement + page matches, `is_enabled`,
`status = published`, ordered by `sort_order`, selecting `section_key`, `anchor`, `published_content`.
Header, footer and menu trees come from the version-stamped cache. A section whose `section_key` is not in
the registry is skipped with a single `Log::warning` (INV-2). A section whose `published_content` is null
is skipped. Only `is_live` providers add queries (§6.1).

### 6.10 Middleware

| Alias | Class | Behaviour |
|---|---|---|
| `site` | `EnsurePublicSiteAvailable` | `public_site_enabled = false` -> `site.holding` view, **HTTP 503**, `Retry-After: 3600`, `X-Robots-Tag: noindex`. `maintenance_mode = true` -> `site.maintenance` view with `maintenance.maintenance_message`, same status and headers. **Bypassed** for an authenticated user holding `website_sections.view`, who instead sees the real site with a fixed amber ribbon naming the state. Never applied to `/admin` or any panel route, never to `robots.txt` |
| `site.cache` | `CachePublicResponse` | §6.7 |
| `site.preview` | `ResolvePreviewMode` | §6.12 |

If Phase 2 already shipped a maintenance gate for its placeholder home page, Phase 3 **extends that class
rather than adding a second one** (§12.2 Q2, §13).

### 6.11 `StatisticsProvider` - the six hero statistics

```
resolve(StatisticMetric $m): ?string      // a decimal string, or null when unresolvable
all(): array                              // metric => ?string, cached 15 minutes
valueFor(WebsiteSectionItem $item): ?string
```

| Metric | Source | Resolves to null when |
|---|---|---|
| `projects_completed` | `projects` where status = completed | module `projects` disabled or the table does not exist |
| `happy_clients` | `clients` where active | module `clients` disabled or absent |
| `students_trained` | `students` where status in (completed) | module `students` disabled or absent |
| `active_courses` | `courses` where active/published | module `courses` disabled or absent |
| `team_members` | `team_members` where publicly visible | module `team` disabled or absent |
| `years_experience` | `now()->year - company.founded_year` | `founded_year` empty, non-numeric, or in the future |
| `manual` | the item's `manual_value` | - |

`valueFor()` is the single rule the renderer uses: `value_mode = auto` -> `resolve()`, falling back to
`manual_value` when null; `value_mode = manual` -> `manual_value`. **When the result is null the item is
not rendered at all** - a statistics strip never shows "0 Students Trained" because a table is missing
(INV-12). Every lookup is a `Schema::hasTable()` + `Modules::enabled()` guard followed by a `COUNT(*)`, all
of it behind one 15-minute cache entry keyed by the public cache version.

### 6.12 `PreviewService`

```
isPreview(): bool
enter(Request $r): void
signedUrlFor(WebsiteSection|Page $target, ?int $minutes = null): string
```

Two ways in, both read-only:

| Way | Authorisation | Lifetime |
|---|---|---|
| An authenticated staff member appends `?preview=1`, or opens `/preview/page/{page}` or `/preview/section/{section}` | `pages.view` / `website_sections.view` via the policy | the session |
| A **signed** shareable link (`URL::temporarySignedRoute`) for a client or reviewer with no login | Laravel's `signed` middleware | `website.preview_ttl_minutes` (default 120) |

In preview mode: the renderer uses `draftPayload()`, the cache middleware short-circuits, the response
carries `Cache-Control: no-store, private` and `X-Robots-Tag: noindex, nofollow`, `SeoService` forces
`noindex_nofollow` (INV-9), and `layouts/site` renders a fixed ribbon - "PREVIEW - draft content, not
live" - with the target name and an exit link. No preview route performs a write of any kind; a POST to a
preview URL is a 405. **No table is needed** ([D-W3-12]).

### 6.13 `CtaBlockService` and `FaqService`

| Method | Guarantees |
|---|---|
| `CtaBlockService::save(array $data, ?CtaBlock $b = null): CtaBlock` | `key` immutable once any section references it; background image and background colour are mutually exclusive (the later-set one clears the other, and the form says so); URLs scheme-allowlisted (§6.6); `description` stored as plain text, not rich text |
| `CtaBlockService::recount(?CtaBlock $b = null): void` | `usage_count` = `COUNT(website_sections WHERE cta_block_id = id AND deleted_at IS NULL)`; run after every section save and by `cms:media-recount` |
| `CtaBlockService::delete(CtaBlock $b): void` | refuses while `usage_count > 0` and returns the usage list for the refusal message; soft delete otherwise |
| `CtaBlockService::usage(CtaBlock $b): Collection` | the sections and pages referencing it, for the popover of §8.11 |
| `FaqService::save(array $data, ?Faq $f = null): Faq` | answer sanitized (INV-13); `faq_category_id` must be enabled or null; `faqable_*` writable only by the owning phase's service, never by this form. **Allowed `faqable_type` values:** `App\Models\Institute\Course` - course FAQs (§90) are `faqs` rows written through this method by Phase 14-17 under `courses.edit`, and **no `course_faqs` table is ever created** (F-2.2). A later phase that needs another `faqable_type` adds it to this list and nowhere else |
| `FaqService::reorder(?FaqCategory $c, array $orderedIds): void` | same exact-set + contiguity guarantees as `WebsiteSectionService::reorder()`, scoped to one category (or to the uncategorised bucket) |
| `FaqService::reorderCategories(array $orderedIds): void` | as above, across `faq_categories` |
| `FaqService::toggle(Faq $f, ContentStatus $s): Faq` | status change only; cache bumped |
| `FaqService::forSection(WebsiteSection $s): Collection` | resolves the FAQ section's three sources: `category` (published FAQs of the selected category in `sort_order`), `selected` (the `faq_website_section` pivot in its pivot order), `featured` (published + `is_featured`); used at publish time to fold the answers into the snapshot |

Both services bump the public cache through their events (§10.1), never directly.

### 6.14 Seeders

`WebsiteCmsSeeder` (idempotent, `firstOrCreate` keyed on `instance_key` / `slug` / `key`, **never
overwrites a value an admin has edited** - it only fills columns that are still at their seeded default):

1. Menus: `header`, `footer_primary`, `footer_secondary`, `footer_legal` (one per `MenuLocation`).
2. Pages (`is_system = true`, `status = published`, `template = site.pages.legal`): `privacy-policy`,
   `terms-of-service`, `refund-policy`, `course-policy` - each with honest placeholder copy that names
   itself as placeholder and must be replaced, plus a `seo_meta` row with `robots = index_follow`.
3. Menu items: header -> Home, About (`section_anchor` `about`), Courses, Services, Contact (the last
   three disabled until the phase that owns them ships, so the nav is never a dead link); footer_legal ->
   the four policy pages.
4. Sections: `header` (global_header), `hero` + `about` + `faq` + `cta` (home, sorted 10/20/110/120),
   `footer` (global_footer) - all `status = published`, `is_enabled = true`, so the site is presentable on
   day one rather than blank.
5. Hero statistic items: the six of §9 (`projects_completed`, `happy_clients`, `students_trained`,
   `active_courses`, `team_members`, `years_experience`), all `value_mode = auto` with a conservative
   `manual_value` fallback, `sort_order` 10-60.
6. About items: 3 `why_choose_us`, 3 `history`, 3 `statistic`.
7. One `cta_blocks` row `key = primary`; three `faq_categories` (General, Courses, Admissions) and six
   `faqs`, `status = published`.
8. `seo_meta` for `route_key = site.home`.
9. `ModuleSeeder` and `PermissionSeeder` re-run for the three new slugs of §4.1.

No demo images are seeded: the hero renders its gradient background when no media is attached, which is
also the empty state a real admin first sees.

---

## 7. Routes

Every admin route additionally carries `auth`, `active`, `panel:admin` from the `routes/admin.php` group
(Phase 1 §8). `module:*` is stated once per block where it applies to the whole block. Controllers live in
`App\Http\Controllers\Admin\Website\`.

### 7.1 Admin - sections (`module:website_sections`)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/website` | `admin.website.index` | `can:website_sections.view_any` |
| GET `/admin/website/sections/{placement}` | `admin.website.sections.index` | `can:website_sections.view_any` |
| GET `/admin/website/sections/{placement}/available` | `admin.website.sections.available` | `can:website_sections.create` |
| POST `/admin/website/sections/{placement}` | `admin.website.sections.store` | `can:website_sections.create` |
| GET `/admin/website/sections/{section}/edit` | `admin.website.sections.edit` | `can:website_sections.view` |
| PUT `/admin/website/sections/{section}` | `admin.website.sections.update` | `can:website_sections.edit` |
| POST `/admin/website/sections/reorder` | `admin.website.sections.reorder` | `can:website_sections.edit` |
| POST `/admin/website/sections/{section}/publish` | `admin.website.sections.publish` | `can:website_sections.change_status` |
| POST `/admin/website/sections/{section}/unpublish` | `admin.website.sections.unpublish` | `can:website_sections.change_status` |
| POST `/admin/website/sections/{section}/toggle` | `admin.website.sections.toggle` | `can:website_sections.change_status` |
| POST `/admin/website/sections/{section}/duplicate` | `admin.website.sections.duplicate` | `can:website_sections.create` |
| DELETE `/admin/website/sections/{section}` | `admin.website.sections.destroy` | `can:website_sections.delete` |
| GET `/admin/website/sections/{section}/revisions` | `admin.website.sections.revisions.index` | `can:website_sections.view_logs` |
| POST `/admin/website/sections/{section}/revisions/{revision}/revert` | `admin.website.sections.revisions.revert` | `can:website_sections.change_status` |
| POST `/admin/website/sections/{section}/items` | `admin.website.sections.items.store` | `can:website_sections.edit` |
| PUT `/admin/website/section-items/{item}` | `admin.website.section-items.update` | `can:website_sections.edit` |
| POST `/admin/website/section-items/{item}/toggle` | `admin.website.section-items.toggle` | `can:website_sections.edit` |
| DELETE `/admin/website/section-items/{item}` | `admin.website.section-items.destroy` | `can:website_sections.edit` |
| POST `/admin/website/sections/{section}/items/{group}/reorder` | `admin.website.sections.items.reorder` | `can:website_sections.edit` |
| POST `/admin/website/cache/flush` | `admin.website.cache.flush` | `can:website_sections.change_status` + `throttle:6,1` |
| GET `/admin/website/statistics` | `admin.website.statistics.index` | `can:website_sections.view_any` |

`{placement}` is bound by `SectionPlacement::tryFrom()` and 404s on an unknown value; `{section}`,
`{item}` and `{revision}` use route-model binding with policy checks.

### 7.2 Admin - menus (`module:menus`)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/website/menus` | `admin.website.menus.index` | `can:menus.view_any` |
| GET `/admin/website/menus/{menu}` | `admin.website.menus.show` | `can:menus.view` |
| PUT `/admin/website/menus/{menu}` | `admin.website.menus.update` | `can:menus.edit` |
| POST `/admin/website/menus/{menu}/items` | `admin.website.menus.items.store` | `can:menus.create` |
| PUT `/admin/website/menu-items/{item}` | `admin.website.menu-items.update` | `can:menus.edit` |
| POST `/admin/website/menu-items/{item}/toggle` | `admin.website.menu-items.toggle` | `can:menus.change_status` |
| DELETE `/admin/website/menu-items/{item}` | `admin.website.menu-items.destroy` | `can:menus.delete` |
| POST `/admin/website/menus/{menu}/reorder` | `admin.website.menus.reorder` | `can:menus.edit` |
| GET `/admin/website/menus/{menu}/link-check` | `admin.website.menus.link-check` | `can:menus.view` |

### 7.3 Admin - pages (`module:pages`)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/website/pages` | `admin.website.pages.index` | `can:pages.view_any` |
| GET `/admin/website/pages/create` | `admin.website.pages.create` | `can:pages.create` |
| POST `/admin/website/pages` | `admin.website.pages.store` | `can:pages.create` |
| GET `/admin/website/pages/{page}/edit` | `admin.website.pages.edit` | `can:pages.view` |
| PUT `/admin/website/pages/{page}` | `admin.website.pages.update` | `can:pages.edit` |
| POST `/admin/website/pages/{page}/publish` | `admin.website.pages.publish` | `can:pages.change_status` |
| POST `/admin/website/pages/{page}/schedule` | `admin.website.pages.schedule` | `can:pages.change_status` |
| POST `/admin/website/pages/{page}/unpublish` | `admin.website.pages.unpublish` | `can:pages.change_status` |
| POST `/admin/website/pages/{page}/duplicate` | `admin.website.pages.duplicate` | `can:pages.create` |
| DELETE `/admin/website/pages/{page}` | `admin.website.pages.destroy` | `can:pages.delete` |
| POST `/admin/website/pages/{page}/restore` | `admin.website.pages.restore` | `can:pages.restore` |
| GET `/admin/website/pages/{page}/revisions` | `admin.website.pages.revisions.index` | `can:pages.view_logs` |
| POST `/admin/website/pages/{page}/revisions/{revision}/revert` | `admin.website.pages.revisions.revert` | `can:pages.change_status` |
| GET `/admin/website/pages/{page}/preview-link` | `admin.website.pages.preview-link` | `can:pages.view` |
| GET `/admin/website/pages/export` | `admin.website.pages.export` | `can:pages.export` |

### 7.4 Admin - CTA blocks, FAQs, FAQ categories

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/website/cta-blocks` | `admin.website.cta-blocks.index` | `module:website_cta_blocks`, `can:website_cta_blocks.view_any` |
| POST `/admin/website/cta-blocks` | `admin.website.cta-blocks.store` | `can:website_cta_blocks.create` |
| GET `/admin/website/cta-blocks/{ctaBlock}/edit` | `admin.website.cta-blocks.edit` | `can:website_cta_blocks.view` |
| PUT `/admin/website/cta-blocks/{ctaBlock}` | `admin.website.cta-blocks.update` | `can:website_cta_blocks.edit` |
| POST `/admin/website/cta-blocks/{ctaBlock}/toggle` | `admin.website.cta-blocks.toggle` | `can:website_cta_blocks.change_status` |
| GET `/admin/website/cta-blocks/{ctaBlock}/usage` | `admin.website.cta-blocks.usage` | `can:website_cta_blocks.view` |
| DELETE `/admin/website/cta-blocks/{ctaBlock}` | `admin.website.cta-blocks.destroy` | `can:website_cta_blocks.delete` |
| GET `/admin/website/faqs` | `admin.website.faqs.index` | `module:faqs`, `can:faqs.view_any` |
| POST `/admin/website/faqs` | `admin.website.faqs.store` | `can:faqs.create` |
| PUT `/admin/website/faqs/{faq}` | `admin.website.faqs.update` | `can:faqs.edit` |
| POST `/admin/website/faqs/{faq}/toggle` | `admin.website.faqs.toggle` | `can:faqs.change_status` |
| POST `/admin/website/faqs/reorder` | `admin.website.faqs.reorder` | `can:faqs.edit` |
| DELETE `/admin/website/faqs/{faq}` | `admin.website.faqs.destroy` | `can:faqs.delete` |
| GET `/admin/website/faq-categories` | `admin.website.faq-categories.index` | `module:faq_categories`, `can:faq_categories.view_any` |
| POST `/admin/website/faq-categories` | `admin.website.faq-categories.store` | `can:faq_categories.create` |
| PUT `/admin/website/faq-categories/{category}` | `admin.website.faq-categories.update` | `can:faq_categories.edit` |
| POST `/admin/website/faq-categories/reorder` | `admin.website.faq-categories.reorder` | `can:faq_categories.edit` |
| DELETE `/admin/website/faq-categories/{category}` | `admin.website.faq-categories.destroy` | `can:faq_categories.delete` |

### 7.5 Admin - SEO and media

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/website/seo` | `admin.website.seo.index` | `module:seo`, `can:seo.view_any` |
| GET `/admin/website/seo/edit` | `admin.website.seo.edit` | `can:seo.view` (query: `target` = `page:{id}` or `route:{name}`) |
| PUT `/admin/website/seo` | `admin.website.seo.update` | `can:seo.edit` |
| POST `/admin/website/seo/bulk-robots` | `admin.website.seo.bulk-robots` | `can:seo.edit` |
| POST `/admin/website/seo/sitemap/regenerate` | `admin.website.seo.sitemap.regenerate` | `can:seo.edit` + `throttle:6,1` |
| GET `/admin/website/seo/sitemap/history` | `admin.website.seo.sitemap.history` | `can:seo.view` |
| GET `/admin/website/seo/robots/preview` | `admin.website.seo.robots.preview` | `can:seo.view` |
| GET `/admin/website/seo/export` | `admin.website.seo.export` | `can:seo.export` |
| GET `/admin/website/media` | `admin.website.media.index` | `module:website_media`, `can:website_media.view_any` |
| POST `/admin/website/media` | `admin.website.media.store` | `can:website_media.upload` + `throttle:60,1` |
| GET `/admin/website/media/{asset}` | `admin.website.media.show` | `can:website_media.view` |
| PUT `/admin/website/media/{asset}` | `admin.website.media.update` | `can:website_media.edit` (alt text, title, caption) |
| GET `/admin/website/media/{asset}/usage` | `admin.website.media.usage` | `can:website_media.view` |
| POST `/admin/website/media/{asset}/regenerate` | `admin.website.media.regenerate` | `can:website_media.edit` |
| DELETE `/admin/website/media/{asset}` | `admin.website.media.destroy` | `can:website_media.delete` |

### 7.6 Public (`routes/web.php`)

This replaces Phase 1's placeholder home route. Controllers in `App\Http\Controllers\Site\`.

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/` | `site.home` | `site`, `site.preview`, `site.cache` |
| GET `/robots.txt` | `site.robots` | none (INV: answerable while the site is down, §6.5) |
| GET `/sitemap.xml` | `site.sitemap` | `site.cache` (404 when `seo.sitemap_enabled` is false) |
| GET `/sitemap-{index}.xml` | `site.sitemap.chunk` | `site.cache`, `whereNumber('index')` |
| GET `/preview/page/{page}` | `site.preview.page` | `site.preview` + (`signed`) **or** (`auth`, `active`, `can:pages.view`) |
| GET `/preview/section/{section}` | `site.preview.section` | `site.preview` + (`signed`) **or** (`auth`, `active`, `can:website_sections.view`) |
| GET `/{slug}` | `site.page` | `site`, `site.preview`, `site.cache`, `where('slug', '[a-z0-9]([a-z0-9-]*[a-z0-9])?')`, **registered last**, and the controller 404s on a reserved slug so a later phase's `/courses` can never be shadowed |

The preview routes resolve the one-or-other middleware with a single `EnsurePreviewAuthorised` middleware
that accepts a valid signature **or** an authorised session, so the two forms cannot drift apart.

---

## 8. UI screens

House rules from Phase 1 §9 and `CLAUDE.md` §6 apply everywhere: `x-ui.*` components, search + filters +
sortable headers + pagination + empty state + skeleton loader on every list, toast on every write,
`x-ui.confirm` on every destructive or irreversible action, responsive (tables inside `overflow-x-auto`),
light and dark.

**Sidebar - one Website group (F-6.7).** There is exactly **one** `Support\Sidebar` group named
**Website**, declared here and appended to (never duplicated) by Phase 4. The placement rule is:
**content *about* the site lives under `/admin/website`** (sections, menus, pages, CTA blocks, FAQs, FAQ
categories, SEO, media); **business entities the site renders live at the top level** (`/admin/services`,
`/admin/portfolio`, `/admin/team`, `/admin/blog-posts`, `/admin/jobs`, `/admin/contact-inquiries`, ...).
Both sets appear in the one Website group, each entry module- and permission-gated. **No route is renamed**
to satisfy the grouping.

**There is no Kanban and no calendar in this phase** - sections 7-10 and 100-105 never ask for one (§18
asks for a lead Kanban, §22 a task Kanban, §71 a timetable calendar), so a content board would be invented
scope. There is **no wizard**: every CMS object is a single form. The only novel interaction is **drag to
reorder**, which appears in four places (sections, repeater items, menu tree, FAQs) and is implemented
once, in §8.2. **[D-W3-16]**

### 8.1 New components Phase 3 adds

Admin, under `resources/views/components/cms/`: `field` (switches on the registry field type, exactly as
Phase 2's `<x-settings.field>` does), `repeater`, `sortable`, `image-picker`, `icon-picker`, `link-field`,
`richtext`, `status-badge`, `preview-frame`, `serp-preview`, `social-preview`, `length-meter`,
`publish-bar`.
Public, under `resources/views/components/site/`: `image`, `seo`, `menu`, `button`, `section`, `stat`,
`accordion`, `cta`, `heading`, `prose`, `social-links`, `preview-ribbon`, `theme-toggle`.
npm additions: `sortablejs` (drag), `trix` (rich text). No other package.

### 8.2 Drag-to-reorder - one implementation, four users

`<x-cms.sortable>` wraps SortableJS in an Alpine component and is the only place drag logic exists.
Contract: a `handle` element (never the whole row, so text stays selectable); `ghost` and `chosen` classes
from the brand palette; on drop it POSTs the **full ordered id array** to the given route (INV-5) and shows
an inline saving indicator; on failure it **restores the previous order** and raises an error toast rather
than leaving the UI lying. Accessibility is not optional: every row also has "Move up" / "Move down"
`x-ui.icon-button`s that post the same payload, the list is an `aria-live="polite"` region announcing
"Hero moved to position 2 of 6", and the drag handle is keyboard focusable with `aria-roledescription`.
Touch: `delay: 120ms` so a scroll gesture is not read as a drag.

### 8.3 Website CMS overview - `admin.website.index`

**Purpose.** One screen that answers "what is live, what is waiting, what is broken".
**Components.** `x-ui.page-header`, `x-ui.card`, `x-ui.stat-card`, `x-ui.badge`, `x-ui.empty-state`.
**Content.** A card per area (Header, Home sections, Footer, Menus, Pages, CTA blocks, FAQs, SEO, Media)
showing its count, its **unpublished-changes count** as an amber badge, and a primary action. A top strip
shows: site state (live / maintenance / disabled, read from Phase 2's settings with a link to that
settings group), last publish (who, when), public cache version and a Flush button, last sitemap build
(URL count + time), and the orphaned-section-type count if any. A "View site" button and a "Share preview"
button that copies a signed link.
**Empty state.** Cannot be empty - the seeder guarantees a header, hero, about, faq, cta and footer.

### 8.4 Section manager - `admin.website.sections.index`

**Purpose.** §7 and §100: enable, disable, reorder, edit, status - all on one list.
**Layout.** A placement switcher (`x-ui.tabs`: Home, Header, Footer, and one tab per `layout = sections`
page) above an `x-cms.sortable` list.
**Row.** drag handle - type icon - name (registry label, or the override) - type chip - `anchor` chip -
`x-ui.form.toggle` for `is_enabled` - `x-cms.status-badge` (Published / Draft / **Unpublished changes** /
Disabled / **Orphaned type**) - "published <time> by X" - row actions dropdown (Edit, Preview, Publish,
Unpublish, Duplicate, Revisions, Remove).
**Filters** (`x-ui.filter-bar`): status, enabled/disabled, has unpublished changes, free text on name and
type.
**Add section.** `x-ui.modal` listing `forPlacement()` grouped by registry group, each with its icon,
label and one-line description. A `is_unique` type already placed renders **disabled with the reason**
("only one hero per page - edit the existing one") rather than vanishing.
**Bulk.** Select rows -> Publish / Enable / Disable, with a confirm naming the count.
**Empty state.** Only reachable for a new `sections` page: "This page has no sections yet" + Add section.

### 8.5 Section editor - `admin.website.sections.edit`

**Purpose.** Edit one section's draft and decide when it goes live.
**Layout.** Two panes on `lg` and up, stacked below: left = the form, right = `x-cms.preview-frame` (an
iframe on `site.preview.section`, reloaded on a 600 ms debounce after a draft autosave, with a
device-width switcher - mobile 375 / tablet 768 / desktop 100% - and an "open in new tab" action).
**Form.** Fields rendered by `<x-cms.field>` in registry `sort` order, grouped into `x-ui.tabs` when the
type declares more than eight fields (Content / Media / Buttons / Advanced). Each field shows its help
text, an inline error, and - where `max_chars` is declared - an `x-cms.length-meter` that turns amber at
90% and rose past the limit.
**Repeaters.** `<x-cms.repeater>`: a sortable item list with a collapsed summary line (the
`item_label_field` value), inline add, per-item enable toggle, per-item delete behind `x-ui.confirm`, and
the repeater's `min` / `max` enforced in the UI and again in the service. An empty repeater shows "No
statistics yet - add up to 8".
**Publish bar.** `<x-cms.publish-bar>`: sticky, appears only when dirty (Phase 2's pattern), warns on
navigate-away, and offers **Save draft** always plus **Save & publish** only when the user holds
`change_status` - otherwise a disabled button with the tooltip "you can save drafts; publishing needs the
publish permission". Shows "Draft saved <time>" and "Live version published <time>".
**Validation.** Publishing with a missing required field or an image without `alt_text` fails with the
field scrolled into view and named - it never half-publishes.

### 8.6 Header editor (the `header` section)

Fields grouped as: **Brand** (logo light/dark override with a preview against both backgrounds, falling
back to `branding.logo_light` / `logo_dark` with a "from settings" chip and a link there;
`show_company_name`, `company_name_override` defaulting to `company.name`), **Navigation**
(`menu_ref` select + an "edit this menu" link + a live count of enabled items), **Buttons** (the four
`link` fields of §8 - Login, Contact, Admission, CTA - each a row of: enabled toggle, label, target,
style select, new-tab toggle, and for Login a note that it is `MenuVisibility::Guest` by default),
**Behaviour** (`sticky`, `transparent_over_hero`).
A mock header strip at the top of the form renders the current choices at desktop and mobile width.

### 8.7 Hero editor (the `hero` section) - §9

Fields: heading, subtitle, description (rich text, limited profile - no tables, no images), primary and
secondary buttons (`link`), alignment, overlay opacity slider with a live swatch.
**Media.** `hero_image`, `background_image`, `background_video` (+ required `video_poster` when a video is
set). The editor states the rules it enforces: video is muted, looped and autoplayed with no controls;
**the poster image is used instead of the video below `md`, when `website.hero_video_enabled` is off, and
whenever the browser reports `prefers-reduced-motion: reduce`**; a video over 8 MB raises a warning with
its size and the advice to host it on a CDN (it is allowed, not blocked).
**Statistics repeater** (the six of §9): each row is label - value mode (Manual / Live count) - metric
select (only when Live) - manual value - prefix - suffix - icon - enabled. A Live row shows the **resolved
value inline** with a "live" chip and the source ("1,248 from Students"), or an amber note when it cannot
resolve ("module Students is not installed yet - the manual value 500 will be shown, and nothing at all if
you clear it", INV-12). Max 8 rows, and the UI says so.

### 8.8 About editor (the `about` section) - §10

Tabs: **Introductions** (company, software house, institute - three rich-text fields, plus `tabs_enabled`
to render them as tabs on the public page), **Mission & Vision**, **History** (a `history` repeater: year,
title, text - shown in the editor as the same vertical timeline the public page renders),
**Why choose us** (repeater: icon, title, text), **Statistics** (the same repeater component as the hero),
**Media** (`image_1`, `image_2` with `ImageProfile::Card`).

### 8.9 Menus - `admin.website.menus.index` / `.show`

**Index.** One card per `MenuLocation` with its item count, depth, enabled count and a "not created yet"
empty state offering to create it.
**Show.** A two-level `x-cms.sortable` tree (nesting is allowed exactly one level; dropping a parent onto
another parent is refused with a toast naming the limit, INV-6). Each node shows label, link-type chip,
the **resolved URL**, icon, new-tab and visibility chips, an enable toggle and edit/delete actions. A
rose chip marks a broken target ("page is a draft", "page is in the trash", "route no longer exists") and
the **Link check** action lists every broken item at once.
**Item modal.** Label, link type (switching the target control: page select / route select / anchor select
populated from `website_sections.anchor` / free URL), icon picker, open-new-tab, nofollow, visibility,
enabled, parent select (top-level items only).
**Empty state.** "This menu has no items yet" + Add item.

### 8.10 Pages - index and editor

**Index columns.** Title (with the `is_system` lock icon) - slug as a copyable `/slug` link that opens the
live page - layout chip - status badge + unpublished-changes badge - SEO completeness meter
(`x-cms.length-meter` in compact mode) - in-menu count - updated by + when - actions (Edit, Preview,
Publish, Duplicate, Revisions, Delete).
**Filters.** Status, layout, system/custom, "has unpublished changes", "missing SEO", free text on title,
slug and body. Sortable on title, slug, status, updated_at. Trash view behind `pages.restore`.
**Editor tabs.** **Content** (title, slug field with a live `/{slug}` preview, an auto-from-title toggle,
and a validation message that names a reserved or trashed-page conflict; `x-cms.richtext` body for
`layout = content`), **Banner** (show toggle, image, heading, subheading), **Sections** (for
`layout = sections`: the §8.4 manager scoped to this page), **SEO** (the §8.12 drawer inline), **Settings**
(template select, sort order, publish date/time for scheduling).
**Empty state (index).** "No custom pages yet" + Create page; never shown in practice because the seeder
creates the four policy pages.

### 8.11 CTA blocks, FAQs, and the statistics screen

**CTA blocks.** A grid of preview cards rendering the real public variant at small scale, each with
status, `usage_count` ("used in 3 places" -> a popover listing them) and actions. The editor is a modal:
heading, subheading, description, two button rows, variant select with a live preview, background image or
colour (mutually exclusive, stated). Delete is blocked while used, with the usage list shown in the refusal.
**FAQs.** Two panes: left an `x-cms.sortable` category rail (drag to reorder, inline add, enable toggle,
question count); right the selected category's questions as a sortable accordion list - question, status
chip, featured star, expand to edit the answer in `x-cms.richtext`. Search across question and answer.
Filters: status, featured, uncategorised, attached-to-a-course (the `faqable` hook, empty until Phase 14).
Empty state per pane.
**Statistics screen** (`admin.website.statistics.index`) - §100 lists "statistics" as its own CMS area:
a read-mostly table of **every** `statistic` item in the system (hero, about, and any later section),
showing its section, label, mode, metric, manual value, **currently resolved value**, enabled state, and a
direct link into the owning section editor. This is the one place an admin can see all six numbers of §9
at once and notice that one is stale.

### 8.12 SEO manager - `admin.website.seo.index`

**Purpose.** §105 for every public target in one table, including the targets later phases add.
**Columns.** Target (type chip + name + the URL) - title with a **length meter** (ideal 50-60 chars) -
meta description with a length meter (ideal 120-160) - robots chip (`index` green / `noindex` slate) -
canonical (a chip saying "self" or showing the override) - OG image thumbnail or a "missing" chip -
completeness % - last updated.
**Filters.** Type (page / static route / later-phase types), robots, "missing title", "missing
description", "missing OG image", "excluded from sitemap", free text. Sortable on completeness.
**Edit drawer.** The fields of §105 plus the sitemap three, with two live previews: an
`x-cms.serp-preview` (Google-style result using the resolved values, truncating exactly as a SERP does)
and an `x-cms.social-preview` (OG card at Facebook/LinkedIn proportions). Each field shows the value it
**inherits** in grey when empty, so an editor can see that leaving it blank is already correct.
**Bulk.** Select rows -> set robots, include/exclude from sitemap, behind a confirm naming the count.
**Sitemap panel.** URL count, last build (trigger, duration, by whom), the per-provider breakdown from
`sitemap_generations.providers`, a "Regenerate now" button (throttled), a link to `/sitemap.xml`, and a
plain warning when `seo.sitemap_enabled` is off.
**robots.txt panel.** A read-only rendering of the generated file in `auto` mode with an explanation of
each line, or an editable textarea in `custom` mode; both show the effective file exactly as a crawler
will receive it, including the override when `robots_indexable` is off or maintenance is on.
**Empty state.** Not possible - `site.home` always exists.

### 8.13 Media library - `admin.website.media.index`

A responsive grid of thumbnails with an upload dropzone (multiple files, per-file progress, per-file error
naming the reason - "that is a .php file renamed to .jpg"), filters by collection, type, derivative status
and **unused only**, and a search on original name, alt text and caption. The detail drawer shows the
preview, dimensions, size, every generated variant with its width and weight, alt text and caption
(editable), `usage_count` with the **list of places it is used**, a Regenerate derivatives action, and a
Delete action that is **disabled with the usage list** while the count is above zero.
Empty state: "No images yet" + the dropzone.

### 8.14 The public site - `layouts/site.blade.php`

**Structure.** `<x-site.seo>` in the head (title, meta, canonical, robots, OG/Twitter, the favicon and the
analytics snippets from Phase 2's `seo.*` keys, each rendered only when its key is set) - a skip-to-content
link - the header section - `<main id="content">` with the ordered sections - the footer section - a
back-to-top button - `<x-site.preview-ribbon>` when previewing.
**Theming.** Brand colour and accent come from `branding.brand_color` / `accent_color` through the CSS
variables Phase 2 already publishes (`--brand-500` etc.), so the public site restyles with the panels and
**no colour is hardcoded in a site view**. Logo from `branding.logo_light` / `logo_dark` (the header
override wins), favicon from `branding.favicon`, fonts from the Phase 1 Tailwind config. Light/Dark/System
works exactly as in the panels (`darkMode: 'class'`, the pre-paint script from Phase 1, the toggle hidden
when `website.show_theme_toggle` is off); every colour utility has a `dark:` counterpart.
**Header behaviour.** Sticky when the section says so, shrinking on scroll, transparent over the hero when
configured and opaque once scrolled; an off-canvas drawer under `lg` with a focus trap, `Esc` to close and
`aria-expanded`; child items render as a hover/focus dropdown on desktop and an expanding group in the
drawer; the Login / Contact / Admission / CTA buttons render per their enabled flags and
`MenuVisibility`.
**Footer.** Up to two menu columns plus a legal menu, the contact block (address, phone, WhatsApp, email,
business hours) from Phase 2's `contact.*`, social icons from `social.*` (only the ones that are set), the
copyright line from `company.copyright_text` with the year interpolated, and the map embed rendered only
through the sanitizer (§6.6, and §12.2 Q3).
**Sections.** `<x-site.section>` gives every section its `id` (from `anchor`), its vertical rhythm and its
container, so no section partial repeats layout chrome. Each partial receives the
**`published_content` array only** - never a model - which is why a missing relation can never N+1 or
explode in a view.
**Performance.** One query for the sections, cached header/footer/menus, `<x-site.image>` everywhere (no
bare `<img>` in `site/`), the hero image `eager` with `fetchpriority="high"` and everything else lazy,
Vite-bundled CSS/JS with no render-blocking third-party asset, and no JS framework beyond Alpine.
**Holding and maintenance views.** `site/holding.blade.php` (public site disabled: logo, company name, a
neutral sentence, contact details) and `site/maintenance.blade.php` (the admin's
`maintenance.maintenance_message`, rendered as plain text, plus contact details). Both are brand-styled,
both return 503, neither exposes a stack trace or a login link.
**404.** `site/404.blade.php` with the header and footer intact, a search-free but menu-rich dead end, and
a link home - so an unknown slug still looks like the company's site.

---

## 9. Data isolation

Every rule is a query scope or a policy check on the backend (CLAUDE.md §1.7), never a hidden field, and
each has a test asserting both the HTTP status **and** the absence of the forbidden content from the body.

| Role / audience | Exact scoping rule |
|---|---|
| **Anonymous visitor** | Sees only: `website_sections` where `is_enabled = 1 AND status = 'published' AND deleted_at IS NULL` **and** `published_content IS NOT NULL`, for the requested placement; `pages` where `status = 'published' AND deleted_at IS NULL` (anything else is a **404**, never a 403 - the existence of a draft page is not public information); `menu_items` where `is_enabled = 1` and the target resolves; `faqs` / `faq_categories` / `cta_blocks` where `status = 'published'` / `is_enabled = 1`. Reads settings only through `SiteSettings` (INV-10). **Never** reads `content`, `content_hash`, `created_by`, `updated_by`, `unpublished_reason` or any revision |
| **Super Admin** | Unrestricted, still subject to module gating: disabling `website_sections` 403s the admin CMS for Super Admin too (Phase 1 `Gate::before`), while the public site keeps serving the last published snapshot (INV-15) |
| **Admin** | Unrestricted within Phase 1 §5's grant |
| **SEO Expert** | Phase 1 §5 grants "blog, seo, website_sections (read/edit)". Concretely: `seo.*` in full (including `seo.edit`, so robots.txt and sitemap regeneration are in reach), `website_sections.view_any/view/edit`, `pages.view_any/view/edit`, `website_media.*`. **Not** granted `website_sections.change_status` or `pages.change_status`: an SEO edit goes live when someone with publish rights publishes it. Per-target SEO itself is live-on-save (§2.15), which is what makes that workable |
| **Digital Marketer** | Phase 1 §5 grants "leads, blog, website_sections, course_inquiries". Concretely: `website_sections.view_any/view/edit`, `website_cta_blocks.*`, `faqs.*`, `website_media.view_any/view/upload`. No `pages.*`, no `seo.edit`, no publish |
| **Every other admin role** (HR, Accountant, Project Manager, Developer, Designer, Sales Executive, Receptionist, Support Agent, Institute Manager, Course Coordinator) | **403 on every route in §7.1-7.5.** No CMS permission is seeded for them, and there is a test per role |
| **Student / Teacher / Client / Collaborator panels** | 403 on every admin CMS route (they also fail `panel:admin`). They browse the public site as ordinary visitors with no extra visibility; an authenticated non-staff user is **not** a preview bypass (`EnsurePublicSiteAvailable` checks the permission, not the login) |
| **Preview** | `site.preview.*` requires a valid signature **or** a session holding `pages.view` / `website_sections.view` (policy-checked per model). An expired or tampered signature is a **403 from Laravel's `signed` middleware**; an unauthenticated hit with no signature is a 404 so draft ids cannot be probed. Preview never writes |
| **Branch (D11)** | Not applicable: no CMS table carries `branch_id` ([D-W3-2]). One company, one public website. If branch-specific landing pages are ever wanted, they are `pages` with their own slug, not a scoped site |
| **Module gating** | `module:website_sections` / `menus` / `pages` / `faqs` / `faq_categories` / `seo` / `website_cta_blocks` / `website_media` apply to **admin routes only**. **No public route carries a `module:` or `can:` middleware**, because the public site is not a module's UI - it is the published output, and **disabling `website_sections` never takes the public site down** (INV-15). A later content module may gate **its own** public routes (`/services`, `/blog`, `/careers`, ...) with **`site_module`**, which **404s** so a disabled feature leaves no trace - that is legal and is decision **D26** (phase-04 §7.3); it never touches the section-driven home page |

---

## 10. Events, notifications, jobs, scheduled tasks

### 10.1 Events (all dispatched through `DB::afterCommit()` - a rolled-back save must never flush a cache or notify anyone)

`SectionPlaced`, `SectionDraftSaved`, `SectionPublished`, `SectionUnpublished`, `SectionsReordered`,
`SectionToggled`, `SectionRemoved`, `SectionReverted`, `PageCreated`, `PageDraftSaved`, `PagePublished`,
`PageScheduled`, `PageUnpublished`, `PageDeleted`, `MenuItemSaved`, `MenuReordered`, `CtaBlockSaved`,
`FaqSaved`, `FaqCategorySaved`, `SeoMetaUpdated`, `MediaUploaded`, `MediaDerivativesGenerated`,
`MediaDeleted`, `PublicCacheBumped`, `SitemapRegenerated`.

**One listener does the invalidation**: `BumpPublicCacheVersion` subscribes to every content event above
(except `MediaUploaded`, which changes nothing public until it is placed), calls `PublicCache::bump()`
once per request via a dedupe guard, and - when `website.cache_warm_enabled` is on - dispatches
`WarmPublicPageCache`. `QueueSitemapRegeneration` subscribes to `PagePublished`, `PageUnpublished`,
`PageDeleted` and `SeoMetaUpdated` only. No controller ever calls the cache or the sitemap directly.

### 10.2 Queued jobs

| Job | Key properties |
|---|---|
| `GenerateImageDerivatives` | `ShouldBeUnique` (`media:{id}`), `$afterCommit = true`, `tries` 3, `backoff [10, 30, 120]`; sets `derivatives_status` `processing` -> `ready`; `failed()` writes `failed` + `failure_reason` and notifies the uploader. Memory-guarded: images over 40 MP are marked `failed` with a readable reason rather than exhausting PHP |
| `RegenerateSitemap` | `ShouldBeUnique` (`sitemap`, `uniqueFor` 300), `delay(60)` so publishing ten pages builds one sitemap, writes a `sitemap_generations` row either way |
| `WarmPublicPageCache` | `ShouldBeUnique` (`warm-cache`), iterates `site.home` plus every published page, bounded to 200 URLs, internal sub-requests with a `X-Cache-Warm` header; failures are logged, never retried forever |
| `RecountMediaUsage` | `ShouldBeUnique` (`media-usage`), `delay(120)`; one pass over the five FK sources plus the rich-text scan |
| `PruneCmsRevisions` | keeps `website.revision_keep` non-published revisions per target and **every** published snapshot |

### 10.3 Notifications (database channel, mail-ready per §97)

| Notification | To |
|---|---|
| `ScheduledPagePublished` | the page's `created_by` and every holder of `pages.change_status` |
| `ImageProcessingFailed` | the uploader |
| `SitemapGenerationFailed` | holders of `seo.view_any` |
| `BrokenMenuLinksDetected` | holders of `menus.edit`, at most once per day, listing the items |
| `OrphanedSectionTypeDetected` | holders of `website_sections.view_any`, at most once per day |

No public-facing notification exists in this phase: the contact form (§17) belongs to **Phase 4** (F-2.1).

### 10.4 Scheduler

| Command | Cadence | Purpose |
|---|---|---|
| `cms:publish-scheduled` | every 5 minutes | promotes `pages` with `status = scheduled` and `published_at <= now()`; one transaction per page; bumps the cache once for the batch |
| `cms:sitemap-generate` | daily 02:30 | a floor under the event-driven build, so a lost queue job cannot leave a stale sitemap for ever |
| `cms:warm-cache` | daily 03:00 | after the nightly flush, keeps the first real visitor off a cold render |
| `cms:prune-revisions` | weekly Sunday 03:30 | `PruneCmsRevisions` for every target |
| `cms:media-recount` | daily 04:00 | `RecountMediaUsage`, so the "unused" filter and the delete guard stay honest |
| `cms:verify-published-snapshots` | daily 04:30 | asserts that every enabled + published section has non-empty `published_content`, that its `section_key` still exists in the registry, that every `published_hash` matches its snapshot, and that every media asset referenced by a published snapshot still exists on disk. Notifies loudly on any failure - this is the command that catches a half-finished deploy before a visitor does |
| `cms:check-links` | daily 05:00 | menu items whose target is draft, trashed or routeless -> `BrokenMenuLinksDetected` |

**No `cms:media-prune` deletes files.** An orphaned upload is surfaced by the library's "unused only"
filter and deleted by a human. Automatic deletion of binaries the system merely *believes* are unreferenced
is how a live page loses its hero image. **[D-W3-17]**

---

## 11. Acceptance tests

`tests/Feature/Cms/`. Phase 3 is not done until every row passes. Each authorization test asserts the HTTP
status **and** that nothing was written (`assertDatabaseCount` before and after).

### 11.1 Registry, placement and ordering

| # | Test | Asserted |
|---|---|---|
| FT-01 | `test_unknown_section_type_cannot_be_placed` | `place('nope', Home)` throws `UnknownSectionTypeException`; zero rows written; the store route returns 422 |
| FT-02 | `test_unique_section_type_cannot_be_placed_twice` | a second `hero` on `home` fails on `uq_ws_instance` (a raw INSERT throws `UniqueConstraintViolationException`, the service returns a validation error naming the existing section); exactly one row |
| FT-03 | `test_repeatable_section_type_can_be_placed_many_times` | three `cta` sections coexist, all with `instance_key IS NULL` |
| FT-04 | `test_orphaned_section_type_does_not_break_the_public_page` | a row whose `section_key` is not in the registry: the home page still returns 200, the section is absent from the HTML, one warning is logged, and the admin list shows the "Orphaned type" badge |
| FT-05 | `test_section_type_not_allowed_in_placement_is_rejected` | placing `header` on `home` returns 422 |
| FT-15 | `test_reorder_writes_contiguous_order_and_rejects_a_stale_set` | after reordering six sections, `sort_order` is exactly 10..60 with no gaps and no duplicates; posting an id list missing one section, or containing a foreign id, returns 422 and **changes nothing**; one activity row holds the old and new order |
| FT-16 | `test_required_section_cannot_be_deleted_only_disabled` | DELETE on `hero` returns 403 from the policy and the row survives; `toggle(false)` succeeds and the hero vanishes from the public page while `status` stays `published` |
| FT-18 | `test_repeater_min_and_max_are_enforced` | a 9th hero statistic returns 422; deleting the last item of a repeater with `min = 1` returns 422 |

### 11.2 Draft, publish, preview - the heart of the phase

| # | Test | Asserted |
|---|---|---|
| FT-06 | `test_draft_edit_is_invisible_to_the_public_until_published` | publish a hero with heading "A", then `saveDraft` heading "B": a guest GET `/` contains "A" and **not** "B"; after `publish()` it contains "B" (INV-1) |
| FT-07 | `test_unpublished_and_disabled_sections_are_absent_from_the_public_html` | `status = draft`, `is_enabled = false`, and `published_content = null` each independently remove the section; the page is still 200 and the remaining order is unchanged |
| FT-08 | `test_has_unpublished_changes_is_derived_not_set` | after `publish()` it is 0; after `saveDraft()` with a real change it is 1; after `saveDraft()` with identical data it stays 0 (the hash is equal); a raw `UPDATE` cannot set it - the column is generated (INV-4) |
| FT-09 | `test_publish_snapshot_contains_items_media_and_resolved_references` | `published_content` holds the enabled items in `sort_order` (disabled items excluded), the media URLs and srcsets, the resolved CTA block content and the resolved menu tree; a later change to a **disabled** item does not change the snapshot |
| FT-10 | `test_unpublish_keeps_the_snapshot_and_requires_a_reason` | no reason -> 422; with a reason -> `status = draft`, `published_content` still present, the section gone from the public page, one activity row carrying the reason |
| FT-11 | `test_revert_restores_the_draft_not_the_live_version` | reverting to revision 1 changes `content` and leaves `published_content` untouched; the public page is unchanged until a publish; a `reverted` revision exists with a reason |
| FT-13 | `test_publish_refuses_incomplete_content` | a required field empty, a required media role empty, or a placed image with no `alt_text` each return 422 and leave `published_hash` unchanged |
| FT-21 | `test_preview_shows_draft_content_to_an_authorised_user` | a user with `website_sections.view` sees "B" on `site.preview.section` while the live page shows "A"; a guest with no signature gets 404; a collaborator/student/teacher/client gets 404 |
| FT-22 | `test_preview_is_never_cached_and_never_indexed` | the response carries `Cache-Control: no-store`, `X-Robots-Tag: noindex, nofollow`, the `<meta name="robots">` tag is `noindex, nofollow`, and no cache entry is created (INV-9) |
| FT-23 | `test_signed_preview_link_works_and_expires` | a fresh signed URL returns 200 for a guest; the same URL after travelling past `website.preview_ttl_minutes` returns 403; a URL with one character of the signature changed returns 403; a POST to it returns 405 |

### 11.3 Caching and invalidation

| # | Test | Asserted |
|---|---|---|
| FT-24 | `test_second_anonymous_request_is_served_from_cache` | request 1 renders and stores; request 2 issues **zero** queries against `website_sections` (asserted with `DB::listen`) and returns identical HTML with the same `ETag` |
| FT-25 | `test_publishing_invalidates_every_public_page_at_once` | after `publish()` the cache version has incremented by exactly 1, the next request is a miss and shows the new content; the old entry is never served again (INV-8) |
| FT-26 | `test_cache_is_bypassed_where_it_must_be` | authenticated request, preview request, `website.cache_enabled = false`, a non-200 response, and a request carrying a flash message each bypass the cache; a request with `?ref=COL-1024` is cached under its own key and never served to a visitor without that parameter |
| FT-27 | `test_home_page_query_count_is_bounded` | a cold anonymous home page with a header, hero, about, faq, cta and footer issues no more than 8 queries and no N+1 on items, media or menus (`DB::listen` count asserted) |

### 11.4 Menus

| # | Test | Asserted |
|---|---|---|
| FT-19 | `test_menu_cannot_exceed_two_levels` | the service returns 422 for a grandchild; a raw INSERT with `depth = 2` is rejected by `chk_mi_depth`; re-parenting an item that has children returns 422 |
| FT-20 | `test_menu_cycle_is_rejected` | making an item its own parent, or the parent of its own parent, returns 422 and changes nothing |
| FT-28 | `test_menu_item_pointing_at_unpublished_target_is_hidden` | an item whose page is draft, scheduled, trashed or deleted is absent from the public header, the page itself 404s, and the admin list shows the rose "broken target" chip; deleting the page **disables** the items and the response names them |
| FT-29 | `test_menu_urls_are_resolved_not_stored` | renaming a page slug changes every menu link with no menu write; a `route` item whose route disappears is hidden rather than throwing `RouteNotFoundException`; `javascript:alert(1)` as a URL is rejected by validation |
| FT-44 | `test_menu_visibility_is_applied_per_request_after_the_cache` | a `guest` item is present for a visitor and absent for a logged-in user **on the same cached body** (the component filters after the cache, so the two cases are not cross-served) |

### 11.5 Pages and slugs

| # | Test | Asserted |
|---|---|---|
| FT-14 | `test_slug_uniqueness_reserved_words_and_trashed_conflicts` | a duplicate slug returns 422; `admin`, `login`, `student`, `courses`, `sitemap.xml`, `robots.txt` each return 422 naming the conflict; a slug held by a trashed page returns 422 telling the user it is in the trash (not a silent `-2` rename) |
| FT-17 | `test_system_pages_cannot_be_deleted` | DELETE on `privacy-policy` returns 403; the row survives; its content **is** editable; the seeder re-run does not overwrite the edited content |
| FT-12 | `test_deleting_a_referenced_entity_cannot_orphan_json` | deleting a CTA block sets `website_sections.cta_block_id` to NULL (`nullOnDelete`) and the section renders without the CTA instead of 500ing; deleting a media asset that a section uses is **refused** by `restrictOnDelete` and by the policy (INV-3) |
| FT-32 | `test_scheduled_page_publishes_itself` | a page with `status = scheduled` and a past `published_at` becomes `published` when `cms:publish-scheduled` runs, the cache is bumped once, the notification is sent, and a future `published_at` stays scheduled |

### 11.6 Statistics and numeric edge cases

| # | Test | Asserted |
|---|---|---|
| FT-30 | `test_live_statistic_falls_back_then_disappears` | with the `students` module absent, an `auto` item renders its `manual_value`; with `manual_value` cleared it renders **nothing at all** - the HTML contains neither the label nor a `0` (INV-12); with the module present it renders the real count |
| FT-31 | `test_statistic_values_are_decimal_strings_and_format_correctly` | `manual_value` round-trips as `"1500.00"` and renders as `1,500` with the prefix/suffix applied; `1250000` renders per the `localization` separators; a negative value is rejected by validation; `years_experience` with `founded_year = 2015` renders `11` in 2026, and with a future or non-numeric `founded_year` the item disappears |
| FT-45 | `test_disabled_statistic_item_is_excluded_from_the_published_snapshot` | toggling an item off and publishing removes it from `published_content`; toggling it on without publishing does **not** bring it back to the public page |

### 11.7 Images and uploads

| # | Test | Asserted |
|---|---|---|
| FT-33 | `test_upload_validates_by_content_not_extension` | a PHP file renamed `.jpg` is refused (422, nothing stored on disk, no row); a `.txt` renamed `.png` is refused; an SVG is refused with a stated reason; a 50 MB file is refused against `security.max_upload_mb`; a genuine JPEG is accepted |
| FT-34 | `test_stored_file_is_safe_and_deterministic` | the stored path is `cms/{Y}/{m}/{ulid}/{ulid}.jpg` on the `public` disk, the user's filename appears **only** in `original_name`, a filename containing `../` cannot escape the directory, and EXIF (including GPS) is stripped from the stored original |
| FT-35 | `test_derivatives_match_the_profile_and_never_upscale` | a 3000 px wide hero upload produces exactly the `hero` widths up to 2560 plus WebP siblings; a 500 px upload produces only 320 and 480 (no upscaled 960); `variants` records each path and size; the `<picture>` output carries `srcset`, `sizes`, intrinsic `width`/`height` and `alt`; a `failed` asset renders the original with no broken `srcset` |
| FT-38 | `test_media_in_use_cannot_be_deleted_and_reupload_deduplicates` | delete returns 403 while `usage_count > 0`, with the usage list in the response; after the section stops using it and `RecountMediaUsage` runs, delete succeeds; re-uploading the identical file returns the **existing** row (checksum) and creates no second directory |

### 11.8 SEO, sitemap, robots

| # | Test | Asserted |
|---|---|---|
| FT-39 | `test_seo_fallback_chain_per_field` | with an empty `seo_meta`, the home page title is `seo.meta_title` and then `company.name`; a page with only an `excerpt` uses it as the description; the OG image falls through page banner -> `seo.og_image` -> `branding.og_image`; canonical defaults to the absolute current URL built from `seo.canonical_base_url` |
| FT-40 | `test_noindex_is_the_strictest_wins` | `robots = index_follow` on a page while `seo.robots_indexable = false` renders `noindex, nofollow` in both the meta tag and the `X-Robots-Tag` header; the same during maintenance; the same in preview |
| FT-46 | `test_sitemap_contents_are_exactly_right` | `/sitemap.xml` is valid XML against the sitemap schema, includes every published indexable page with `sitemap_include = true`, and **excludes** drafts, scheduled pages, trashed pages, `noindex` targets and `sitemap_include = false`; `lastmod` equals `published_at` or the later `updated_at`; `seo.sitemap_enabled = false` returns 404; a `sitemap_generations` row records the URL count and the per-provider breakdown |
| FT-47 | `test_robots_txt_in_each_mode` | `auto` disallows `/admin`, `/collaborator`, `/student`, `/teacher`, `/client`, `/login` and `/preview` and carries the `Sitemap:` line; `robots_indexable = false` returns `Disallow: /` with **no** sitemap line; maintenance mode does the same; `custom` returns the stored text verbatim with the sitemap line appended; `/robots.txt` answers **200 even while the site is in maintenance** |

### 11.9 Gating, authorization, security

| # | Test | Asserted |
|---|---|---|
| FT-41 | `test_module_gating_affects_the_admin_cms_only` | disabling `website_sections` 403s every route of §7.1 for Super Admin too and hides the sidebar items, while `GET /` still returns 200 with the last published content; re-enabling restores the admin with identical row counts (no data touched) |
| FT-42 | `test_public_views_cannot_read_non_public_settings` | `site_setting('mail.password')` throws `NonPublicSettingException`; a grep of `resources/views/site/**` finds no `setting(` call and no `config(` call; the rendered home page HTML contains none of the SMTP, security or finance values |
| FT-43 | `test_every_cms_write_is_audited` | publish, unpublish, reorder, toggle, slug change, SEO change and a media delete each write an `activity_log` row with the module, the actor, the IP, the device and - for unpublish and revert - the reason; old and new values are recorded for the slug and the SEO change |
| FT-48 | `test_authorization_matrix` | for each of the 18 seeded roles x the 9 route groups of §7: the expected status. Specifically - a user with `website_sections.edit` but not `change_status` gets 200 on update and **403 on publish** with `published_hash` unchanged; `seo.view` without `seo.edit` renders read-only and 403s the update; HR, Accountant, Receptionist, Teacher, Student, Client and Collaborator get 403 everywhere; a permission-less user gets 403 on all of them |
| FT-36 | `test_rich_text_is_sanitized_on_write_and_on_render` | `<script>alert(1)</script>`, `<img onerror=...>`, `<a href="javascript:...">` and `<iframe src="https://evil.test">` are stripped on save; a row hand-written into the DB with a `<script>` tag still renders sanitized (the DB is not a trust boundary); a YouTube iframe survives |
| FT-36b | `test_rich_text_profiles_are_a_closed_map` | **the `cms` profile** strips `<div>`, `style=`, `align=` and a `data:` image; **the `material` profile** keeps all four (a `data:image/png` survives, a `data:text/html` does not) yet still strips `<script>`, `on*`, `javascript:`, `{{ 7*7 }}`, `@php`, `<?php`, `@import`, `expression(` and an external `url()`; `sanitize($html, 'print')` - any name outside the committed map - throws `UnknownRichTextProfileException` and never reaches `mews/purifier`; a grep of `app/` and `config/` finds exactly one sanitiser class and exactly two purifier profiles (ND-5, D25) |
| FT-37 | `test_no_unescaped_output_in_site_views` | a static scan of `resources/views/site/**` allows `{!! !!}` only on `RichText::sanitize()` output and the sanitized map embed; any other occurrence fails the test |
| FT-49 | `test_maintenance_and_public_site_gates` | `maintenance_mode = true` -> `GET /` is 503 with the admin's message, `Retry-After` and `noindex`, while `/admin` is unaffected and a user holding `website_sections.view` sees the real site with the ribbon; `public_site_enabled = false` -> the holding page, 503, and `/admin` still fine; neither leaks a stack trace or a login form |
| FT-50 | `test_install_and_rollback` | `migrate:fresh --seed` runs clean and the home page renders; every Phase 3 migration rolls back cleanly in reverse; re-running `WebsiteCmsSeeder` twice changes no row count and does not overwrite an edited heading; the generated columns, the three CHECK constraints and the two unique guards are asserted to exist (a silently skipped constraint fails CI, not production) |
| FT-51 | `test_public_site_is_responsive_and_dark_mode_clean` | the home page and a custom page render at 375 / 768 / 1280 with no horizontal overflow, every section has a `dark:` counterpart for its background and text, and the pages produce no console error (smoke test, Dusk or a rendered-HTML assertion where Dusk is unavailable) |

---

## 12. Risks and open questions

### 12.1 Risks accepted, with the mitigation

| # | Risk | Mitigation / why it is accepted |
|---|---|---|
| R-1 | **Content exists twice** (`content` and `published_content`), and a direct DB edit or a half-finished deploy can make them disagree. Two copies of the truth is the thing this design is most likely to be criticised for. | It is the only way to satisfy "a draft edit must never be visible" (INV-1) without a second table per entity, and it makes the public read one indexed row with no joins. The hash columns make divergence *detectable*, `cms:verify-published-snapshots` checks it nightly, and only `WebsiteSectionService` / `PageService` may write either column |
| R-2 | **Menus are not snapshot-published** (§2.15), so an editor rebuilding the header can briefly show a half-finished menu after a cache flush. | The header section's `published_content` stores the **resolved tree**, so the live header only changes when the header section is published; the live `menus` tables feed preview and the footer's legal column, where a transient state is harmless. Recorded as [D-W3-8] rather than hidden |
| R-3 | A menu item whose target is unpublished **silently disappears** from the navigation. An editor may not notice their Contact link is gone. | Better than a dead 404 link for a visitor. The admin list marks it in rose, the Link-check action lists every one, and `cms:check-links` notifies daily |
| R-4 | **Full-page caching assumes the public site has no per-visitor content.** The day someone adds "Welcome back, {name}" or a cart, the cache will serve one visitor's page to another. | The bypass list is explicit (§6.7), the one personalised parameter that exists today (`?ref=`) is in the cache key, and a loud comment plus FT-26 mark the boundary. Any future personalised public element must either bypass or be keyed - this must be stated in `CLAUDE.md` (§13) |
| R-5 | **A trashed page keeps its slug**, and a slug change silently breaks every external link and search result pointing at the old one. No redirect table exists. | The plain `UNIQUE(slug)` is deliberate (a composite with `deleted_at` would let two live pages share a slug - MariaDB treats NULLs as distinct). The validator explains the trash conflict by name. Slug changes are audited with old and new values and the editor is warned. A `website_redirects` table is offered in §12.2 Q5, not smuggled in |
| R-6 | **SVG uploads are refused**, which will annoy whoever has the logo only as an SVG. | An SVG is executable XML; sanitizing it properly is its own project. Logos come from Phase 2's `branding.*` settings, and a PNG/WebP at `logo` widths is indistinguishable at display size. Revisit only with a dedicated sanitizer |
| R-7 | **A background video is a bandwidth trap** on a Pakistani mobile connection, and the admin who uploads a 60 MB MP4 will not feel it on office wifi. | `website.hero_video_enabled` is a global off switch, the poster image is used below `md` and under `prefers-reduced-motion`, a size warning is shown at upload, and the video is never a required field. Transcoding is explicitly out of scope |
| R-8 | **The section registry is a coupling point for five later phases.** If Phase 4 needs a field type or a repeater shape that does not exist, it will be tempted to bypass the registry and store JSON of its own. | The field-type list is deliberately generous, `editView()` lets a phase ship a bespoke editor panel without touching the generic loop, and `SectionDataProvider` covers dynamic content. Any new field type must be added to `<x-cms.field>` and documented here - that is the review rule |
| R-9 | **Rich text lets an editor paste anything.** The sanitizer is now a security control, and `mews/purifier` is a new dependency in the trust path. | Sanitize on write *and* on render (INV-13), a narrow tag/attribute allowlist, an iframe host allowlist, FT-36 and the static scan FT-37. The alternative - trusting staff input - is how a CMS becomes a stored-XSS vector |
| R-10 | **The database cache driver makes full-page caching slower than it should be** (a DB round trip to avoid a DB round trip), and stale version-stamped entries linger until TTL, growing the cache table. | Still a large win: one keyed read instead of rendering a tree of partials plus the settings and menu lookups. Phase 25 should recommend the `file` or `redis` store in production; `cache:prune-stale` style cleanup is not needed because entries carry a TTL |
| R-11 | **Content is single-locale.** `localization.locale` exists, but sections, pages, menus and SEO have no locale column. | Urdu/English switching is not in sections 7-10 or 100-105. The shape is forward-compatible: a nullable `locale` column on `website_sections`, `pages`, `menu_items`, `faqs` and `seo_meta` plus the locale in the cache key is an additive change, not a redesign. Raised as Q4 |
| R-12 | **12 tables, 16 enums, ~16 services and a dozen screens before any of the business modules exist.** The real risk is partial implementation: the generated columns, the CHECK constraints, the sanitizer or the snapshot verifier "left for later", leaving this document's guarantees stated but untrue. | FT-50 asserts the DB-level guarantees exist; FT-36/37 assert the sanitizer runs; FT-06/07 assert the draft boundary. Those four are the ones most likely to be deferred and **must not be** |
| R-13 | **`x-cms.field` will grow into a god component** if every phase adds a type. | It switches on a type and includes a partial per type; the partials live in `components/cms/fields/`. A type with real logic ships its own partial, not a branch in the switch |

### 12.2 Open questions for the client (defaults assumed, nothing blocked)

| # | Question | Default assumed |
|---|---|---|
| Q1 | ~~Does the client accept a new decision number for omitting `deleted_at`?~~ **Answered and closed by D19.** `cms_revisions`, `sitemap_generations` and `seo_meta` omit `deleted_at` under the general category rule, not as a local exception. | **Yes, omit it.** A revision is append-only, a sitemap build is a log line, and a `seo_meta` row is a 1:1 snapshot attribute whose history lives in `activity_log`. The rule lives in `CLAUDE.md` §3 block A and `DEVELOPMENT_LOG.md` §4 **D19**; this contract cites it and claims no number of its own ([D-W3-6]) |
| Q2 | Did Phase 2 already ship a maintenance-gate middleware for its placeholder home page (its §6 test row "Maintenance" implies one)? | Phase 3 **extends that class** and does not add a second gate. If it does not exist, Phase 3 creates `EnsurePublicSiteAvailable` with the alias `site`. Either way there is exactly one place the two settings are checked |
| Q3 | Phase 2 stores `contact.map_embed` as **raw HTML from a settings field**. Phase 3 has to render it on the public site. | Rendered through `RichText::sanitize()` with an iframe allowlist of `www.google.com/maps/embed` only, so a pasted `<script>` cannot reach a visitor. This is a Phase 2 field and Phase 2's contract is not being rewritten - if the client prefers, the better long-term shape is to store only the place/coordinates and build the embed in code |
| Q4 | Will the public site need **Urdu** (or any second language) content? | No - single locale (`en`), with the additive migration path of R-11 documented. Deciding this later costs one migration; deciding it wrongly now costs a locale column on every CMS table and a doubled editor |
| Q5 | Should a **slug change leave a redirect** behind (a `website_redirects` table with `from`, `to`, `status_code`, `hits`)? | Not in this phase - out of sections 7-10 / 100-105. Offered as a small follow-up; until then a slug change is audited and the editor is warned (R-5) |
| Q6 | §10 lists "about" content and §101 lists an "about" **page**. One or both? | Both, with no duplication: the `about` section type is placeable on `home` (condensed) **and** on a page with `layout = sections` (full), each instance holding its own content. The seeder creates only the home one |
| Q7 | Should **publishing** be a separate permission from editing, so junior staff can draft but not go live? | **Yes** - `change_status` is the publish ability ([D-W3-10]). If the client prefers one-step editing, grant both abilities to the same role; no code changes |
| Q8 | Is a cookie/consent banner needed (the site loads Google Analytics, GTM and the Facebook Pixel from Phase 2's `seo.*` keys)? | **Not built** - not asked for in sections 7-10 or 100-105. Flagged because loading a pixel with no consent UI is a legal exposure in some markets; it is a small section type if wanted |
| Q9 | Should the public site expose a **sitemap for images** and structured data (JSON-LD for Organization, FAQPage, Course)? | Not built. §105 asks for "sitemap support" only. JSON-LD would be a genuine SEO win and is one Blade component away; raised for a future phase rather than invented here |
| Q10 | How many **footer menu columns** does the client want? | Three locations (`footer_primary`, `footer_secondary`, `footer_legal`). A fourth is a new `MenuLocation` case plus a field on the footer section - no migration |

---

## 13. Requests to other phases

### 13.1 Phase 1 (foundation) - additions to files Phase 1 owns

| Request | Why |
|---|---|
| `App\Support\PermissionRegistry`: the three module slugs of §4.1 and the ability changes of §4.2 | the registry is the only place a permission name exists (D4) |
| `RoleSeeder`: **SEO Expert** gets `seo.*`, `website_sections.view_any/view/edit`, `pages.view_any/view/edit`, `website_media.*` and **not** `*.change_status`; **Digital Marketer** gets `website_sections.view_any/view/edit`, `website_cta_blocks.*`, `faqs.*`, `website_media.view_any/view/upload` | Phase 1 §5 describes both roles in prose ("website_sections (read/edit)"); §9 of this contract is the exact grant |
| `bootstrap/app.php`: middleware aliases `site`, `site.cache`, `site.preview` | §6.10 |
| `routes/web.php`: replace the placeholder public home route with §7.6 (Phase 1 §8 already says "real CMS in Phase 3") | - |
| `resources/views/layouts/site.blade.php`: the real public layout replaces any placeholder | CLAUDE.md §2 already reserves this file |
| `App\Support\Sidebar`: **one** **Website** group with the nine CMS entries, each module- and permission-gated. Phase 4 appends its entries to this same group and declares no second one (§8, F-6.7) | Phase 1's builder already does the gating; only the entries are new |
| `resources/views/components/ui/`: no change requested. Phase 3 adds `components/cms/*` and `components/site/*` **beside** the Phase 1 set and does not modify a single `x-ui.*` component | the shared component contract stays stable |
| `App\Support\Device` / `LogsActivityWithContext`: used as-is, with `module` set to the CMS module slug | §106 |

### 13.2 Phase 2 (settings, modules, dashboard)

| Request | Why |
|---|---|
| `App\Support\SettingsRegistry`: the new `website` group (§5.1) and the four `seo` keys (§5.2) | settings definitions live in code, values in the DB |
| Confirm `public => true` on every key the site reads: `company.name`, `company.legal_name`, `company.tagline`, `company.short_description`, `company.founded_year`, `company.copyright_text`, all `branding.*`, all `contact.*`, all `social.*`, `seo.*` except the verification ids, `localization.*`, `maintenance.maintenance_message` | `SiteSettings` **throws** on a non-public key (INV-10), so a missing flag is a white screen, not a silent leak |
| Confirm the ownership of the maintenance gate (Q2) | one gate, not two |
| `ConfigureFromSettings` must publish the brand CSS variables for **guest** requests too, not only inside the admin shell | the public site is themed from `branding.brand_color` / `accent_color` with no hardcoded colour (§8.14) |
| `DashboardRegistry`: Phase 3 registers `WebsiteContentWidget` (sections live / draft / unpublished changes, pages by status) and `SeoHealthWidget` (indexable pages, missing titles, missing descriptions, last sitemap build) | §98's dashboard is assembled from widgets; these are the two this phase can fill with real data |
| `SettingsService` file uploads stay on `settings/`; CMS images live under `cms/` through `MediaService` | two upload paths with one disk, no collision |

### 13.3 Phases 4, 5, 14, 15, 16 - the contract they must implement rather than duplicate

| Request | Why |
|---|---|
| **Do not create** a per-entity SEO table or SEO columns - use `seo_meta` via `morphOne(SeoMeta::class, 'seoable')`, write only through `SeoService::save()`, register one `SitemapUrlProvider` per entity in `SitemapRegistry`, and use `seo_meta.og_image_media_id` for the OG image (**D23**) | §105 is one feature, not six |
| **Do not create** a second media/image table or a second uploader - use `media_assets` + `MediaService` + `ImageProfile`. **Two media tiers (D24)**: `media_assets` + `MediaService` + `ImageProfile` is **mandatory for anything rendered on the public website**; a bare `*_path` column is allowed **only** for a private profile photo or document (`employees.photo_path`, `students.photo_path`, `collaborators.photo_path`, the teacher photo, `clients.logo_path`, `job_applications.cv_path`). A public section that wants to show client logos stores its own `website_section_media` ids and never reads `clients.logo_path` | one library, one derivative pipeline, one usage count |
| **Do not create** `course_faqs` - use `faqs.faqable_type` / `faqable_id` with `faqable_type = App\Models\Institute\Course` (§90 course FAQs), written through `FaqService::save()` (§6.13) | the hook already exists |
| **Do not create** a second HTML sanitiser - `App\Support\RichText::sanitize(string $html, string $profile = 'cms'): string` over `mews/purifier` is the only one, applied on write **and** on render (**D25**). No phase ships an `HtmlSanitizer` of its own, and **no phase adds a purifier profile of its own**: a context that needs a wider tag set passes a profile name from §6.6's closed map (`cms`, `material`), and a third profile is an edit to `RichText` reviewed as a security change - never a second class, a second config profile or a post-hoc re-widening of the output | a duplicated security control is a security defect; one code path with per-context allowlists keeps the proof in one place (ND-5) |
| Register every new public section as a `WebsiteSectionRegistry` type with a partial under `site/sections/`, and a `SectionDataProvider` when it needs data - **never** a hand-written route returning its own Blade page | otherwise §7's "enable, disable, reorder" stops being true for half the home page |
| Register every public URL set with `SitemapRegistry` (`services`, `portfolio`, `blog`, `courses`, `careers`) and **never edit `SitemapService`** | §105 sitemap support, with the per-provider counts in `sitemap_generations.providers` |
| Reuse `ContentStatus`, `CmsRevision`, `RichText::sanitize()`, `<x-site.image>`, `<x-site.section>`, `<x-cms.field>` and `PublicCache::bump()` (via an event + the existing listener) | a second publish model or a second cache strategy would make invalidation unprovable |
| **Phase 4** owns the `contact` section type, `contact_inquiries` and the §17 routing (`InquiryRouter` + one `InquiryTarget` class per target: Phase 5 registers `crm_lead`, Phase 14-17 registers `course_inquiry`). Phase 3 ships neither the form nor the table (F-2.1) | `contact_inquiries` has exactly one schema, and it is in phase-04 §2.20 |
| **Phase 14/15** must preserve `?ref=` through the course and admission flows (§38, §67). Phase 3 guarantees only that the parameter survives **page caching** (it is in the cache key, §6.7) | the referral chain is a commercial invariant and it starts on a cached public page |
| Columns this phase reads through `StatisticMetric` (all optional, all module- and `Schema::hasTable`-guarded): `projects.status` (Phase 6), `clients.status` (Phase 5), `students.status` (Phase 15), `courses.status` (Phase 14), `team_members.is_public` (Phase 4), and Phase 2's `company.founded_year` | §9's six statistics; a missing source renders nothing, never a zero (INV-12) |

### 13.4 Packages and documentation

| Request | Why |
|---|---|
| `composer require intervention/image ^3` | already planned for Phase 3 in `DEVELOPMENT_LOG.md` §2; the derivative pipeline of §6.8 |
| `composer require mews/purifier ^3.4` | `RichText::sanitize()` (INV-13); the only new trust-path dependency. **Both** committed profiles live in `config/purifier.php` - `cms` (the default) and `material` (§6.6) - and `RichText` is the only class that may name one; later phases select a profile, they do not add one (D25, ND-5) |
| `npm i sortablejs trix` | the one drag implementation (§8.2) and the rich-text editor |
| `DEVELOPMENT_LOG.md` §4: **cite D19** - append-only tables (`cms_revisions`, `sitemap_generations`, `seo_meta`) carry no soft deletes. This contract claims **no** decision number of its own (Q1, F-10.1) | one category rule in `CLAUDE.md` §3 beats fifty local exceptions |
| `DEVELOPMENT_LOG.md` §4: **cite D22** - public website content is published by snapshot (`content` -> `published_content`) and invalidated by a cache **version stamp**, because the database cache driver has no tag support | the two decisions every later CMS phase must inherit ([D-W3-7], [D-W3-14]) |
| `DEVELOPMENT_LOG.md` §4: this contract also **cites D23** (one SEO store), **D24** (two media tiers) and **D25** (one sanitiser) - all three are Phase 3 artefacts that Phase 4 onwards must reuse rather than re-create | the numbers are allocated in `docs/design/resolutions.md` §4 |
| `CLAUDE.md` §6: three new rules - (1) no bare `<img>` in `site/` views, use `<x-site.image>`; (2) a public view reads settings only through `site_setting()`; (3) anything personalised on a public page must bypass or be keyed into the public cache (R-4) | the three mistakes most likely to be made by the next developer to touch the site |
| `DEVELOPMENT_LOG.md` §8: note that the `website.menu_max_depth` setting is **readonly** - the depth limit is a DB CHECK, and raising the setting alone would start failing inserts | an honest setting beats a lying one |
| Phase 25: recommend the `file` or `redis` cache store in production, document `storage:link` as mandatory for the media library, and add `/sitemap.xml` to the deployment smoke test | R-10 |

---

## Convergence log (2026-09-12)

Applied from [`../design/resolutions.md`](../design/resolutions.md) §7 (apply map row `docs/phases/phase-03.md`).

| Finding | Change made |
|---|---|
| F-2.1 | §1.3 names **Phase 4** as the owner of `contact_inquiries`; §6.1 later-phase table moves the `contact` section type from Phase 5 to **Phase 4**; §10.3 note retargeted; §13.3 row rewritten to "Phase 4 owns the `contact` type, `contact_inquiries` and the §17 routing (`InquiryRouter` + `InquiryTarget`)" |
| F-2.2 | §6.13 `FaqService::save()` now lists `App\Models\Institute\Course` as the allowed `faqable_type` and states no `course_faqs` table exists; §1.3 `faqs` row and §13.3 row say the same |
| F-2.3 | §1.3 `seo_meta` row cites **D23**; §13.3 row names `morphOne(SeoMeta, 'seoable')`, `SeoService::save()`, one `SitemapUrlProvider` per entity and `seo_meta.og_image_media_id`. §8.12 unchanged (already correct) |
| F-2.4 | §1.3 `media_assets` row and §13.3 row keep the ban and append D24's private-photo exception verbatim, naming the six private-tier `*_path` columns |
| F-2.5 | §6.6 names `RichText::sanitize()` as the **only** sanitiser (D25); §13.3 gains the "no second sanitiser, no `HtmlSanitizer`" row |
| F-6.3 | §5 preamble + §5.1: label **"Website & Forms"**, sort **75**, group declared once, split into §5.1a (13 Phase 3 keys) and §5.1b (21 Phase 4 keys contributed here) |
| F-6.6 | INV-15 and §9's module-gating row restated: no public route carries `module:`/`can:`, disabling `website_sections` never takes the site down, a content module may gate its own public routes with `site_module` (404) - **D26** |
| F-6.7 | §8 gains the one-Website-sidebar-group rule ("content *about* the site under `/admin/website`, business entities the site renders at the top level", no route renames); §13.1 Sidebar row says Phase 4 appends to the same group |
| F-9.1 | §2 preamble and §2.14 cite **D19** instead of claiming a local decision; §12.2 Q1 closed as answered by D19 |
| F-10.1 | §13.4: D17 -> **cite D19**, D18 -> **cite D22**, plus a row citing **D23, D24, D25**. Cache/snapshot decision marked D22 at §2.15 and §6.7. No contract invents a number |

---

## Drift fixes (round 2)

Applied from [`../design/consistency-audit-round-2.md`](../design/consistency-audit-round-2.md) §4. These
close contradictions the convergence pass itself introduced. Nothing here weakens a guarantee: D25 still
names **one** sanitiser class and D23 still names **one** SEO store - each simply gained the argument that
makes the single implementation serve every caller that was about to fork it.

| ND | Change made |
|---|---|
| ND-5 | §6.6's published signature becomes `App\Support\RichText::sanitize(string $html, string $profile = 'cms'): string`, and the section gains **the profile map**: a closed, class-level allowlist of exactly two committed profiles - `cms` (Phase 3 + Phase 4, unchanged from the previously committed allowlist) and `material` (the wider document/layout set phase-19-23's print templates and course-material HTML need: `div h1 h5 h6 small b i sub sup`, `style`, `align`, `data:` images, sanitised CSS). An unknown profile name throws `UnknownRichTextProfileException` and is never passed through as a purifier config key. A **common core** that no profile may weaken is stated explicitly (`script`/`object`/`embed`/`link`/`meta`/`form`/`input`/`base`, every `on*`, `javascript:`/`vbscript:`/`file:`, every Blade and PHP construct, every non-allowlisted `<iframe>`). INV-13 restated to bind under *any* profile; §13.3's "no second sanitiser" row extended with "**and no phase adds a purifier profile of its own**"; §13.4's `mews/purifier` row now commits both profiles; new test **FT-36b** asserts the two allowlists differ in exactly the intended way, that `material` still strips everything in the core, and that an unknown profile throws. **D25 is unchanged and unweakened** - one class, one code path, one security control, per-context allowlists |
| ND-9 | §5 preamble keeps **34** and adds the verified derivation: §5.1a = 13 rows, §5.1b = 21 rows, phase-04 §5 = the same 21 key names (diffed name by name), so the two contracts agree and nothing was lost in the merge. It records that `resolutions.md` §2.4's "22 keys / 35 keys" is **one high and is the error**, and forbids "restoring" a non-existent 22nd key; a future `website.*` key is added to §5.1b and phase-04 §5 in one edit with both totals bumped together |
| ND-13 *(phase-03 half)* | §6.5 publishes `SeoService::rules(string $prefix = 'seo'): array` - the **one** SEO validation rule set, with each `max` taken from §2.12's real column width (`title` 180, `meta_description` **320**, `meta_keywords` 500, `canonical_url` 500, `robots` = `RobotsDirective`, `og_image_media_id` = `exists:media_assets,id`). `<x-cms.seo-fields>` posts under that prefix and an entity Form Request merges `rules()` instead of restating a `seo_meta` column (D23). This is what phase-04 §6.11 now delegates to after dropping its own `meta_description max:255` - a duplicated rule that was also a *wrong* rule against a `string(320)` column |
