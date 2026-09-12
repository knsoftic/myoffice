# PHASE 4 CONTRACT — Software-House Marketing Modules (services, portfolio, team, testimonials, reviews, blog, careers, inquiries)

**Goal.** Everything a visitor reads about the software house — and every lead that comes back from it —
becomes admin-managed data: a categorised service catalogue with prices and technologies, a portfolio
with image galleries, the public team page, an approval-gated testimonial/review/success-story wall for
both clients and students, a full blog (categories, tags, authors, drafts, scheduler-driven scheduled
publishing, spam-resistant view counting, related posts), a careers board with CV uploads and a
six-stage hiring pipeline, and one public contact form whose submissions are permanently recorded and
then **routed by type** — a service inquiry to the CRM lead module (Phase 5), a course inquiry to the
institute course-inquiry module (Phase 15) — with defined, non-failing behaviour for the period before
those phases exist.

This file is the **authoritative spec** for Phase 4. Table, column, class, enum, permission and route
names below are binding. Conventions live in [`../../CLAUDE.md`](../../CLAUDE.md); it never contradicts
[`phase-01.md`](phase-01.md) or [`phase-02.md`](phase-02.md) — where this phase needs something those
phases own, it is listed in §13, not silently redesigned.

---

## 1. Goal and dependencies

**After this phase the business can**: publish and reorder services with prices, technologies and SEO;
publish portfolio case studies with multi-image galleries; publish the team page with per-member public
visibility; collect, moderate and feature client testimonials, student reviews and success stories;
run a blog with drafts, scheduled posts, tags, authors, per-post SEO and honest view counts; advertise
jobs and receive applications with CVs, then move each candidate through New → Reviewing →
Shortlisted → Interview → Selected/Rejected; and receive public inquiries that land in an admin queue
and hand themselves to the CRM or the institute automatically once those modules exist.

| Depends on | What is used |
|---|---|
| **Phase 1** | `users`, spatie roles/permissions + `PermissionRegistry`, `modules` + `module:` middleware + `Gate::before`, `activity_log`, `Blameable`, `LogsActivityWithContext`, `Money`, `Sidebar`, `layouts/admin`, the `x-ui.*` component set, `routes/admin.php` + `routes/web.php`, `Ability` / `ModuleGroup` enums |
| **Phase 2** | `SettingsRegistry` + `SettingsService` (a new `website` group is added here, §5), `settings.is_public` for site-readable keys, `maintenance.contact_form_enabled` / `maintenance.public_site_enabled`, `security.max_upload_mb`, `DashboardRegistry` + `DashboardWidget`, `DateRange`, `Format` (`money()`, `app_date()`), `x-ui.chart` |
| **Phase 3** | `layouts/site.blade.php`, the public-site middleware stack, the `website_sections` on/off toggles, the SEO meta component, the sitemap registry, and the CMS catch-all page route (§13 lists the exact hand-offs) |
| Tables it needs | `users`, `modules`, `settings`, `activity_log` only. Phase 4 creates no dependency on any unbuilt table — links to `clients`, `students`, `courses`, `departments` are deferred (§2.1). |
| Blocked by | Nothing. Phase 4 may start as soon as Phase 3 is ticked in [`../../DEVELOPMENT_LOG.md`](../../DEVELOPMENT_LOG.md). It shares `routes/web.php` and the public layout with Phase 3, so the two must not run concurrently. |

---

## 2. Schema

20 new tables. Every business table: InnoDB, utf8mb4, `timestamps`, `softDeletes`, `created_by`,
`updated_by` (nullable FK `users.id`, `nullOnDelete`, filled by `Blameable`). The four link tables
(`service_technology`, `portfolio_item_technology`, `portfolio_item_media`, `blog_post_blog_tag`) and the
append-only `blog_post_views` table carry **no** soft deletes — they are history pivots and a log, which is
the category rule **D19** (`CLAUDE.md` §3 block A), not a local exception. This contract claims no decision
number of its own; it **cites D19, D21, D23, D24, D25 and D26**.

Money is `decimal(15,2)` and only ever compared/formatted through `App\Support\Money` — Phase 4
performs no money arithmetic. Ratings are `unsignedTinyInteger` 1–5, not percentages. All slug columns
are `string(180)` (200 for blog posts) with a **single-column unique index** (§6.1 explains why the
uniqueness check must include soft-deleted rows).

**Two cross-cutting rules this phase obeys rather than re-invents:**

1. **One SEO store (D23).** No Phase-4 table carries an SEO column. Every public-facing entity
   (`Service`, `ServiceCategory`, `PortfolioItem`, `PortfolioCategory`, `BlogCategory`, `BlogPost`,
   `JobOpening` — seven models) declares `morphOne(App\Models\Cms\SeoMeta::class, 'seoable')` and registers
   one `SitemapUrlProvider` in Phase 3's `SitemapRegistry`. SEO values are written **only** through
   `App\Services\Cms\SeoService::save()`; the OG image is `seo_meta.og_image_media_id`.
2. **Two media tiers (D24).** Anything rendered on the public website is a `media_assets` row created by
   Phase 3's `App\Services\Cms\MediaService` and referenced by a nullable, indexed `*_media_id` FK
   (`nullOnDelete`) or a `*_media` pivot. A bare `*_path` string column survives **only** for a private
   artefact: `job_applications.cv_path` (private `local` disk, D21). Phase 4 ships **no** uploader of its
   own (§6.6).

### 2.1 Deferred foreign keys — read this before writing any migration

Twelve Phase-4 columns point at tables owned by later phases (`clients` → Phase 5, `departments` and
`employees` → Phase 7, `courses` → Phase 14, `students` → Phase 15). Phase 4 creates each of them as
`unsignedBigInteger`, **nullable, indexed, with no foreign-key constraint** — the target table does not
exist yet — and always beside a denormalised snapshot column (`client_name`, `course_name`,
`student_name`, `department`) that the public site actually renders. Consequences, all binding:

1. The public site and the admin list **never** join to the missing table; they read the snapshot.
2. Until the owning phase ships, the admin form offers a free-text field, not a record picker.
3. The owning phase adds the constraint plus the picker in its own additive migration (§13).
4. Every read path treats the link as optional (`?->`), so a later hard-deleted client or student can
   never break a published page.

Deferred columns: `portfolio_items.client_id`, `testimonials.client_id`, `testimonials.student_id`,
`student_reviews.student_id`, `student_reviews.course_id`, `success_stories.student_id`,
`success_stories.course_id`, `contact_inquiries.course_id`, `team_members.department_id`,
`job_openings.department_id`, **`team_members.employee_id`** (F-3.13), **`job_applications.employee_id`**
(F-3.12) — the last two point at Phase 7's `employees` and follow the identical deferred pattern.

### 2.2 `service_categories`

| Column | Type | Null / Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | — | |
| `name` | string(150) | not null | |
| `slug` | string(180) | not null | **unique**; generated per §6.1 |
| `description` | text | null | plain text, rendered on the category strip |
| `icon` | string(64) | null | icon identifier (a class/name string — never an uploaded SVG) |
| `image_media_id` | unsignedBigInteger | null, index, FK `media_assets.id` **nullOnDelete** | D24 — a `media_assets` row created by `MediaService`, profile `ImageProfile::Card`. Replaces the former `image_path` |
| `sort_order` | integer | 0, index | drag-and-drop display order |
| `is_active` | boolean | true, index | inactive = hidden from the public site, kept in admin |
| `created_at` `updated_at` `deleted_at` | timestamps + softDeletes | | |
| `created_by` `updated_by` | bigInt unsigned | null, FK `users.id` nullOnDelete | |

Indexes: `unique(slug)`, `index(is_active, sort_order)`, `index(image_media_id)`.
Relationships: `hasMany(Service::class)` (`services.service_category_id`, `nullOnDelete`);
`belongsTo(MediaAsset::class, 'image_media_id')`; `morphOne(SeoMeta::class, 'seoable')` — SEO lives in
`seo_meta`, never here (D23).

### 2.3 `services`

| Column | Type | Null / Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | — | |
| `service_category_id` | foreignId | null, index, **nullOnDelete** | §6.2 refuses the delete in the service layer unless a `reassign_to` category is supplied |
| `name` | string(150) | not null | §11 field *name* |
| `slug` | string(180) | not null | **unique** |
| `short_description` | string(500) | null | list cards, meta fallback |
| `full_description` | longText | null | rich text (sanitised on write, §6.9) |
| `icon` | string(64) | null | |
| `image_media_id` | unsignedBigInteger | null, index, FK `media_assets.id` **nullOnDelete** | D24 — replaces `image_path`; derivatives (including the card thumbnail) come from `media_assets.variants`, so there is no `thumbnail_path` |
| `starting_price` | decimal(15,2) | null | **money** — rendered with `money()`; null = "on request" |
| `price_note` | string(100) | null | e.g. "starting from / per project" |
| `price_visible` | boolean | true | false hides the price publicly without losing it |
| `features` | json | null | ordered array of strings (§11 *features*) |
| `status` | string(32) | `draft`, index | cast `ContentStatus` |
| `is_featured` | boolean | false, index | §11 *featured* |
| `sort_order` | integer | 0 | §11 *display order* |
| timestamps · softDeletes · `created_by` · `updated_by` | | | |

§11's *SEO title*, *SEO description*, keywords, OG image and index/noindex are **`seo_meta` columns**
(`title`, `meta_description`, `meta_keywords`, `og_image_media_id`, `robots`), reached through
`morphOne` + `SeoService` — this table has no SEO column (**D23**).

Indexes: `unique(slug)`, `index(status, is_featured, sort_order)`, `index(service_category_id, status)`,
`index(image_media_id)`.
Relationships: `belongsTo(ServiceCategory)`; `belongsTo(MediaAsset::class, 'image_media_id')`;
`belongsToMany(Technology)` through **`service_technology`**;
`hasMany(ContactInquiry)` (`contact_inquiries.service_id`, `nullOnDelete`);
`morphOne(SeoMeta::class, 'seoable')`.

### 2.4 `technologies`

| Column | Type | Null / Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | — | |
| `name` | string(100) | not null | "Laravel", "Flutter" |
| `slug` | string(180) | not null | **unique**; used as the public filter value |
| `logo_media_id` | unsignedBigInteger | null, index, FK `media_assets.id` **nullOnDelete** | D24 — replaces `logo_path`; profile `ImageProfile::Logo` (transparency preserved) |
| `icon` | string(64) | null | |
| `color` | string(16) | null | hex, validated `regex:/^#[0-9a-fA-F]{6}$/` |
| `sort_order` | integer | 0 | |
| `is_active` | boolean | true, index | |
| timestamps · softDeletes · blameable | | | |

Indexes: `unique(slug)`, `index(is_active, sort_order)`, `index(logo_media_id)`.
Relationships: `belongsToMany(Service)` via `service_technology`; `belongsToMany(PortfolioItem)` via
`portfolio_item_technology`.

### 2.5 `service_technology` (pivot)

| Column | Type | Null / Default | Notes |
|---|---|---|---|
| `service_id` | foreignId | not null, **cascadeOnDelete** | |
| `technology_id` | foreignId | not null, **cascadeOnDelete** | |
| `sort_order` | unsignedSmallInteger | 0 | chip order on the service page |

Composite **primary key** (`service_id`, `technology_id`); no timestamps, no soft deletes.

### 2.6 `portfolio_categories`

Identical shape to `service_categories` (§2.2): `id`, `name`, `slug` unique, `description`, `icon`,
`image_media_id` (nullable indexed FK → `media_assets.id`, `nullOnDelete`, D24), `sort_order` index,
`is_active` index, timestamps, softDeletes, blameable. **No SEO column** — `morphOne(SeoMeta, 'seoable')`
(D23). `hasMany(PortfolioItem)`, `belongsTo(MediaAsset::class, 'image_media_id')`.

### 2.7 `portfolio_items`

| Column | Type | Null / Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | — | |
| `portfolio_category_id` | foreignId | null, index, **nullOnDelete** | §12 *category* |
| `title` | string(180) | not null | §12 *project name* |
| `slug` | string(180) | not null | **unique** |
| `client_name` | string(150) | null | §12 *client* — the snapshot the site renders |
| `client_id` | unsignedBigInteger | null, index, **no FK (deferred, Phase 5)** | §2.1 |
| `summary` | string(500) | null | card text |
| `description` | longText | null | sanitised rich text |
| `technologies_note` | string(255) | null | free text for one-off tech not worth a row |
| `cover_media_id` | unsignedBigInteger | null, index, FK `media_assets.id` **nullOnDelete** | D24 — the cover image; must be one of the item's `portfolio_item_media` rows (§6.3). Replaces `cover_image_path` |
| `project_url` | string(255) | null | §12 *project URL*, `url` validated, rendered `rel="nofollow noopener"` |
| `completion_date` | date | null, index | §12 *completion date* |
| `status` | string(32) | `draft`, index | cast `ContentStatus` |
| `is_featured` | boolean | false, index | |
| `sort_order` | integer | 0 | |
| timestamps · softDeletes · blameable | | | |

**No SEO column** — SEO is `seo_meta` via `morphOne` + `SeoService` (**D23**).

Indexes: `unique(slug)`, `index(status, is_featured, sort_order)`,
`index(portfolio_category_id, status)`, `index(completion_date)`, `index(cover_media_id)`.
Relationships: `belongsTo(PortfolioCategory)`; `belongsTo(MediaAsset::class, 'cover_media_id')`;
`belongsToMany(MediaAsset)` through **`portfolio_item_media`** (§2.8) ordered by pivot `sort_order`;
`belongsToMany(Technology)` via **`portfolio_item_technology`** — identical in shape to
§2.5: `portfolio_item_id` + `technology_id` (both foreignId, `cascadeOnDelete`), `sort_order`
unsignedSmallInteger default 0, composite primary key, no timestamps, no soft deletes;
`morphOne(SeoMeta::class, 'seoable')`.

### 2.8 `portfolio_item_media` (pivot) — there is no `portfolio_images` table

**F-2.4 / D24.** A portfolio gallery image is a `media_assets` row (Phase 3) attached through this pivot.
The former `portfolio_images` table is **deleted**: it was a second image store with its own path, alt text,
dimensions and derivative story, all of which `media_assets` already owns.

| Column | Type | Null / Default | Notes |
|---|---|---|---|
| `portfolio_item_id` | foreignId | not null, **cascadeOnDelete** | |
| `media_asset_id` | foreignId | not null, **restrictOnDelete** | an image in use cannot be deleted out from under an item; `MediaPolicy::delete()` refuses while `usage_count > 0` |
| `sort_order` | unsignedSmallInteger | 0 | gallery order |
| `caption` | string(255) | null | per-placement caption; `alt_text`, `width`, `height` and `size_bytes` live on `media_assets` and are never duplicated here |
| `created_at` `updated_at` | timestamps | | **no** `deleted_at` — history pivot, D19 |
| `created_by` | bigInt unsigned | null, FK `users.id` nullOnDelete | who attached it; no `updated_by` (F-9.4) |

Indexes: `UNIQUE uq_pim(portfolio_item_id, media_asset_id)` (one attachment per image per item),
`index(portfolio_item_id, sort_order)`, `index(media_asset_id)` (usage counting),
`index(created_by)` (F-9.2).
Detaching a row never deletes the binary: the file belongs to the media library, and
`MediaService::recountUsage()` must count `portfolio_item_media` among its FK sources (§13, Phase 3).

### 2.9 `team_members`

| Column | Type | Null / Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | — | |
| `name` | string(150) | not null | §13 *name* |
| `slug` | string(180) | not null | **unique**; anchor id on the public team page |
| `photo_media_id` | unsignedBigInteger | null, index, FK `media_assets.id` **nullOnDelete** | D24 — the team photo **is** public website content, so it is a `media_assets` row (profile `ImageProfile::Thumbnail`), not a bare path. Replaces `photo_path` |
| `designation` | string(150) | not null | §13 *designation* |
| `department` | string(100) | null | §13 *department* — snapshot label, used as the group heading |
| `department_id` | unsignedBigInteger | null, index, **no FK (deferred, Phase 7)** | §2.1 |
| `employee_id` | unsignedBigInteger | null, index, **no FK (deferred, Phase 7's guarded migration)** | **F-3.13** — the HR post this public profile corresponds to, a **source of defaults only**: Phase 7 may pre-fill name, designation and department from `employees` when an admin creates the card. It is **never** a publication trigger and **never** an authority — a team member is website content, published by an explicit CMS act |
| `bio` | text | null | sanitised light rich text |
| `skills` | json | null | array of strings, max 20 entries |
| `experience_years` | unsignedTinyInteger | null | 0–60 |
| `experience_label` | string(100) | null | free text when years alone is wrong ("8+ years in fintech") |
| `social_links` | json | null | map keyed by `SocialPlatform` value → URL; unknown keys rejected (§6.4) |
| `portfolio_url` | string(255) | null | §13 *portfolio URL* |
| `is_public` | boolean | true, index | §13 *public visibility* — hides the member without unpublishing |
| `status` | string(32) | `draft`, index | cast `ContentStatus` |
| `sort_order` | integer | 0 | §13 *display order* |
| timestamps · softDeletes · blameable | | | |

Indexes: `unique(slug)`, `index(status, is_public, sort_order)`, `index(photo_media_id)`.
Relationships: `belongsTo(MediaAsset::class, 'photo_media_id')`; `morphOne(SeoMeta::class, 'seoable')` is
**not** declared (the team page has no per-member route; its SEO is the `site.team.index` `route_key` row).
`department_id` and `employee_id` are resolved by Phase 7 (§2.1). **No `user_id` link** — a team member is
website content, not an account, and assignment of work points at `users.id` while a duty points at
`employees.id` (D32).

### 2.10 `testimonials` (clients **and** students, §14)

| Column | Type | Null / Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | — | |
| `type` | string(32) | `client`, index | cast `TestimonialType` (client / student / other) |
| `author_name` | string(150) | not null | §14 *name* |
| `author_photo_media_id` | unsignedBigInteger | null, index, FK `media_assets.id` **nullOnDelete** | D24 — rendered on the public testimonial wall, so a `media_assets` row (profile `ImageProfile::Thumbnail`). Replaces `author_photo_path` |
| `author_designation` | string(150) | null | shown for type `client` |
| `author_company` | string(150) | null | §14 *company* |
| `course_name` | string(150) | null | §14 *course* (student type) — snapshot, free text |
| `rating` | unsignedTinyInteger | null | 1–5, validated; null renders no stars |
| `review` | text | not null | HTML stripped on write, escaped on render |
| `review_date` | date | null | the date the author gave it (display only) |
| `client_id` | unsignedBigInteger | null, index, **no FK (deferred, Phase 5)** | §2.1 |
| `student_id` | unsignedBigInteger | null, index, **no FK (deferred, Phase 15)** | §2.1 |
| `status` | string(32) | `pending`, index | cast `ApprovalStatus` — **public only when approved** |
| `approved_by` | foreignId `users` | null, nullOnDelete | stamped by `ModerationService` |
| `approved_at` | timestamp | null | |
| `rejection_reason` | string(255) | null | required when rejecting |
| `is_featured` | boolean | false, index | homepage slider |
| `sort_order` | integer | 0 | |
| `source` | string(32) | `admin`, index | cast `ContentSource` (admin / public_form / client_panel / student_panel / import) |
| `submitted_by_user_id` | foreignId `users` | null, nullOnDelete | set when a logged-in panel user submits |
| `ip_address` | string(45) | null | captured only for non-admin sources |
| timestamps · softDeletes · blameable | | | |

Indexes: `index(status, type, sort_order)`, `index(status, is_featured)`, `index(type, client_id)`,
`index(type, student_id)`, plus **every FK gets its own index (F-9.2)**: `index(approved_by)`,
`index(submitted_by_user_id)`, `index(author_photo_media_id)`.
Relationships: `belongsTo(User::class, approved_by)`, `belongsTo(User::class, submitted_by_user_id)`,
`belongsTo(MediaAsset::class, 'author_photo_media_id')`.
Implements `App\Contracts\Cms\Moderatable` (§6.5).

### 2.11 `student_reviews` (§91)

| Column | Type | Null / Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | — | |
| `student_id` | unsignedBigInteger | null, index, **no FK (deferred, Phase 15)** | §2.1 |
| `student_name` | string(150) | not null | §91 *student* snapshot |
| `student_photo_media_id` | unsignedBigInteger | null, index, FK `media_assets.id` **nullOnDelete** | D24 — public-rendered, so a `media_assets` row. Replaces `student_photo_path` |
| `course_id` | unsignedBigInteger | null, index, **no FK (deferred, Phase 14)** | §2.1 |
| `course_name` | string(150) | null | §91 *course* snapshot |
| `rating` | unsignedTinyInteger | null | 1–5 |
| `review` | text | not null | sanitised plain text |
| `video_url` | string(255) | null | §91 *video URL*; only YouTube/Vimeo hosts accepted (§6.9) |
| `status` | string(32) | `pending`, index | cast `ApprovalStatus` |
| `approved_by` · `approved_at` · `rejection_reason` | as §2.10 | | |
| `is_featured` | boolean | false, index | |
| `sort_order` | integer | 0 | |
| `source` | string(32) | `admin`, index | cast `ContentSource`; `student_panel` reserved for the portal hook (§12 Q4) |
| `submitted_by_user_id` | foreignId `users` | null, nullOnDelete | |
| `ip_address` | string(45) | null | |
| timestamps · softDeletes · blameable | | | |

Indexes: `index(status, is_featured, sort_order)`, `index(course_id, status)`, `index(student_id)`, plus the
FK indexes of **F-9.2**: `index(approved_by)`, `index(submitted_by_user_id)`,
`index(student_photo_media_id)`.
Relationships: `belongsTo(User::class, approved_by)`, `belongsTo(User::class, submitted_by_user_id)`,
`belongsTo(MediaAsset::class, 'student_photo_media_id')`.
Implements `Moderatable`.

### 2.12 `success_stories` (§92)

| Column | Type | Null / Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | — | |
| `student_id` | unsignedBigInteger | null, index, **no FK (deferred, Phase 15)** | §2.1 |
| `student_name` | string(150) | not null | §92 |
| `photo_media_id` | unsignedBigInteger | null, index, FK `media_assets.id` **nullOnDelete** | D24 — public-rendered, so a `media_assets` row. Replaces `photo_path` |
| `course_id` | unsignedBigInteger | null, index, **no FK (deferred, Phase 14)** | §2.1 |
| `course_name` | string(150) | null | |
| `headline` | string(180) | null | one-line hook |
| `story` | longText | not null | sanitised rich text |
| `achievement` | string(255) | null | §92 *achievement* |
| `company_name` | string(150) | null | §92 *company* |
| `platform` | string(100) | null | §92 *platform* (Upwork, Fiverr, …) |
| `video_url` | string(255) | null | host-allowlisted as §2.11 |
| `status` | string(32) | `draft`, index | cast `ContentStatus` — staff-authored, so **no** approval queue |
| `is_featured` | boolean | false, index | §92 *featured* |
| `sort_order` | integer | 0 | |
| timestamps · softDeletes · blameable | | | |

Indexes: `index(status, is_featured, sort_order)`, `index(photo_media_id)`.
Relationships: `belongsTo(MediaAsset::class, 'photo_media_id')`.
No slug and no detail route: the requirement defines no success-story page, so Phase 4 renders them in a
section plus a modal and invents nothing.

### 2.13 `blog_categories`

| Column | Type | Null / Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | — | |
| `name` | string(150) | not null | |
| `slug` | string(180) | not null | **unique**; drives `/blog/category/{slug}` |
| `description` | text | null | |
| `icon` | string(64) | null | |
| `image_media_id` | unsignedBigInteger | null, index, FK `media_assets.id` **nullOnDelete** | D24 — replaces `image_path` |
| `sort_order` | integer | 0 | |
| `is_active` | boolean | true, index | |
| timestamps · softDeletes · blameable | | | |

Indexes: `unique(slug)`, `index(is_active, sort_order)`, `index(image_media_id)`. **No SEO column** —
`morphOne(SeoMeta, 'seoable')` (D23). Flat list — the requirement asks for no nesting,
so there is no `parent_id`. `hasMany(BlogPost)`, `belongsTo(MediaAsset::class, 'image_media_id')`.

### 2.14 `blog_tags`

| Column | Type | Null / Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | — | |
| `name` | string(100) | not null | stored as typed, matched case-insensitively |
| `slug` | string(180) | not null | **unique** — the de-duplication key used by `syncTags()` |
| `is_active` | boolean | true, index | an inactive tag keeps its posts but leaves the public tag cloud |
| timestamps · softDeletes · blameable | | | |

Indexes: `unique(slug)`. `belongsToMany(BlogPost)` via `blog_post_blog_tag`.

### 2.15 `blog_post_blog_tag` (pivot)

`blog_post_id` foreignId cascadeOnDelete · `blog_tag_id` foreignId cascadeOnDelete · composite
**primary key** (`blog_post_id`, `blog_tag_id`) · no timestamps. The name follows the CLAUDE §3
alphabetical singular_singular rule.

### 2.16 `blog_posts`

| Column | Type | Null / Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | — | |
| `blog_category_id` | foreignId | null, index, **nullOnDelete** | deleting a category that has posts is refused in the service unless `reassign_to` is supplied (§6.2) |
| `author_id` | foreignId `users` | null, index, **nullOnDelete** | §15 *authors*; null renders as the company name |
| `title` | string(200) | not null | |
| `slug` | string(200) | not null | **unique** |
| `excerpt` | string(500) | null | auto-filled from the first 160 chars of stripped content when blank |
| `content` | longText | not null | sanitised rich text (§6.9) |
| `featured_image_media_id` | unsignedBigInteger | null, index, FK `media_assets.id` **nullOnDelete** | D24 — replaces `featured_image_path`; the `card` / `banner` derivatives come from `media_assets.variants` |
| `featured_image_alt` | string(180) | null | optional per-post override of `media_assets.alt_text`; when empty the asset's own alt text is rendered (the asset remains the default source) |
| `status` | string(32) | `draft`, index | cast **`ContentStatus`** (`draft` / `scheduled` / `published` / `archived`) — the shared enum declared by phase-03 §3 (F-5.1); `PostStatus` does not exist |
| `published_at` | timestamp | null, index | for `scheduled` the **future** go-live moment; for `published` the live moment |
| `is_featured` | boolean | false, index | |
| `reading_minutes` | unsignedTinyInteger | null | computed on save at 200 wpm, minimum 1 |
| `views_count` | unsignedBigInteger | 0 | **cached counter**, always re-derivable as `count(blog_post_views)` |
| timestamps · softDeletes · blameable | | | |

**No SEO column** (**D23**): §105's title, description, keywords, canonical URL, OG image and noindex are
`seo_meta` columns (`title`, `meta_description`, `meta_keywords`, `canonical_url`,
`og_image_media_id`, `robots`) reached through `morphOne` + `SeoService`. The OG-image fallback chain
(`seo_meta.og_image_media_id` → the post's `featured_image_media_id` → `seo.og_image` →
`branding.og_image`) is `SeoService`'s, not this table's.

Indexes: `unique(slug)`, `index(status, published_at)`, `index(blog_category_id, status, published_at)`,
`index(author_id, status)`, `index(is_featured, status)`, `index(featured_image_media_id)`,
**`index(views_count)`** (F-9.3 — the "top viewed" sort and the `TopViewedPostsWidget` filter on it).
Relationships: `belongsTo(BlogCategory)`, `belongsTo(User::class, author_id)`,
`belongsTo(MediaAsset::class, 'featured_image_media_id')`,
`belongsToMany(BlogTag)` via `blog_post_blog_tag`, `hasMany(BlogPostView)`,
`morphOne(SeoMeta::class, 'seoable')`.

### 2.17 `blog_post_views` — append-only, privacy-preserving

| Column | Type | Null / Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | — | |
| `blog_post_id` | foreignId | not null, **cascadeOnDelete** | |
| `visitor_hash` | char(64) | not null | HMAC-SHA256 of (IP + user agent) keyed by `APP_KEY` — the raw IP is **never** stored here |
| `viewed_on` | date | not null | the dedupe bucket (§6.7) |
| `user_id` | foreignId `users` | null, nullOnDelete | set when the reader is logged in |
| `referrer_host` | string(120) | null | host only, never the query string |
| `created_at` | timestamp | null | **no** `updated_at`, no soft deletes, no blameable |

Indexes: **`unique(blog_post_id, visitor_hash, viewed_on)` named `uq_blog_post_view_daily`** — this index
*is* the refresh-spam defence; plus `index(blog_post_id, viewed_on)` for the per-day chart.
The model is `Prunable`: rows older than `website.blog_view_prune_days` (default 90) are pruned daily.

### 2.18 `job_openings` (module slug `jobs`, §16)

> **Naming warning — do not call this table `jobs`.** Laravel 12 uses `jobs` (and `job_batches`,
> `failed_jobs`) for the database queue, which this project runs. The table is `job_openings`, the model
> is `App\Models\Cms\JobOpening`, the module slug stays `jobs` (Phase 1 reserved it), the admin URI is
> `/admin/jobs` and the route names are `admin.jobs.*`.

| Column | Type | Null / Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | — | |
| `title` | string(180) | not null | §16 *title* |
| `slug` | string(180) | not null | **unique**; `/careers/{slug}` |
| `department` | string(100) | null | §16 *department* snapshot label |
| `department_id` | unsignedBigInteger | null, index, **no FK (deferred, Phase 7)** | §2.1 |
| `location` | string(150) | null | §16 *location* (city / office) |
| `work_mode` | string(32) | `onsite`, index | cast `WorkMode` — the only way "remote" can be expressed |
| `employment_type` | string(32) | not null, index | cast **`App\Enums\EmploymentType`** — the shared HR enum whose canonical seven cases are declared at phase-07 §3 (F-5.2). Phase 4 declares no enum of its own (§3) |
| `experience_min_years` | unsignedTinyInteger | null | §16 *experience* (0–40) |
| `experience_note` | string(150) | null | free text where a number is wrong |
| `openings_count` | unsignedTinyInteger | 1 | how many seats |
| `salary_min` | decimal(15,2) | null | **money**; §16 *salary range* |
| `salary_max` | decimal(15,2) | null | **money**; must satisfy `Money::compare(min, max) <= 0` |
| `salary_period` | string(16) | `monthly` | `monthly` / `yearly` / `hourly` / `project` |
| `salary_visible` | boolean | true | false renders "Negotiable" and never leaks the numbers |
| `description` | longText | not null | §16 *description*, sanitised rich text |
| `requirements` | longText | null | §16 *requirements* |
| `responsibilities` | longText | null | |
| `skills` | json | null | array of strings, max 30 |
| `deadline` | date | null, index | §16 *deadline*; a passed deadline closes applications (§6.8) |
| `status` | string(32) | `draft`, index | cast `JobOpeningStatus` (draft / open / closed / filled) |
| `is_featured` | boolean | false | |
| `opened_at` | timestamp | null | stamped the first time the status becomes `open` |
| `closed_at` | timestamp | null | stamped when it becomes `closed` or `filled` |
| `applications_count` | unsignedInteger | 0 | cached counter, re-derivable as `count(job_applications)` |
| `sort_order` | integer | 0 | |
| timestamps · softDeletes · blameable | | | |

**No SEO column** — `morphOne(SeoMeta, 'seoable')` + `SeoService` (**D23**).

Indexes: `unique(slug)`, `index(status, deadline)`, `index(status, is_featured, sort_order)`,
`index(employment_type, status)`, `index(created_by)` (F-9.2 — it is also the "openings I own" scope of
§9.1.3).
Relationships: `hasMany(JobApplication)` (`cascadeOnDelete`, see §2.19);
`morphOne(SeoMeta::class, 'seoable')`.

### 2.19 `job_applications` (§16 candidate)

| Column | Type | Null / Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | — | |
| `job_opening_id` | foreignId | not null, index, **cascadeOnDelete** | soft-deleting the opening never touches applications; a force-delete must run through `JobOpeningService::purge()` so CV files are removed first — the DB cascade is only the last-resort net |
| `applicant_name` | string(150) | not null | §16 *name* |
| `email` | string(150) | not null, index | stored lower-cased |
| `phone` | string(32) | not null | §16 *phone* |
| `whatsapp` | string(32) | null | |
| `city` | string(100) | null | |
| `experience_years` | unsignedTinyInteger | null | 0–40 |
| `expected_salary` | decimal(15,2) | null | **money**, optional |
| `cover_letter` | text | null | §16 *cover letter*, plain text |
| `portfolio_url` | string(255) | null | §16 *portfolio* |
| `linkedin_url` | string(255) | null | |
| `cv_path` | string(255) | not null | **private `local` disk** — never the `public` disk (§6.8) |
| `cv_original_name` | string(255) | not null | sanitised, used only as the download filename |
| `cv_mime` | string(100) | not null | the **detected** MIME, not the browser-supplied one |
| `cv_size` | unsignedInteger | not null | bytes |
| `status` | string(32) | `new`, index | cast `JobApplicationStatus` — the six stages |
| `status_changed_at` | timestamp | null | |
| `status_changed_by` | foreignId `users` | null, nullOnDelete | |
| `rating` | unsignedTinyInteger | null | internal 1–5 screening score |
| `assigned_to` | foreignId `users` | null, index, nullOnDelete | the reviewer; drives row scoping (§9). A **user**, because being assigned work points at `users.id` (D32) |
| `employee_id` | unsignedBigInteger | null, index, **no FK (deferred, Phase 7's guarded migration)** | **F-3.12** — set when a `selected` candidate is hired and Phase 7 creates the `employees` row, so HR can trace an employee back to the application and the CV. Follows the same deferred pattern as `department_id` (§2.1); null for every candidate who is not hired |
| `internal_notes` | text | null | staff-only, never rendered publicly |
| `interview_at` | dateTime | null | set when the status becomes `interview` |
| `interview_mode` | string(32) | null | `onsite` / `online` / `phone` |
| `interview_location` | string(255) | null | address or meeting URL |
| `rejection_reason` | string(255) | null | required when the status becomes `rejected` |
| `source` | string(32) | `website`, index | cast `InquirySource` |
| `ip_address` | string(45) | null | abuse tracing |
| `user_agent` | text | null | |
| timestamps · softDeletes · blameable | | | |

Indexes: **`unique(job_opening_id, email)` named `uq_job_application_per_job`** (one application per
address per opening; the service translates the 1062 violation into a friendly validation error),
`index(status, created_at)`, `index(assigned_to, status)`, `index(job_opening_id, status)`,
`index(status_changed_by)` and `index(employee_id)` (F-9.2).
Relationships: `belongsTo(JobOpening)`, `belongsTo(User::class, assigned_to)`,
`belongsTo(User::class, status_changed_by)`.
**Per-opening scope (F-12.4).** `job_opening_id` also carries the hiring-manager scope of §9.1.3: a
reviewer without `job_applications.view_any` sees only applications whose opening it owns
(`job_openings.created_by = $user->id`) or which are assigned to it. `cv_path` stays on the private `local`
disk and is only ever streamed by `admin.job-applications.cv` (D21).
A `JobApplication` observer deletes the CV from the private disk on `forceDeleted` **only** — a soft
delete keeps the file, so a restore is lossless.

### 2.20 `contact_inquiries` (§17) — the public form record plus its routing state

**Phase 4 owns this table** (F-2.1): it is the only contract that defines its schema. Phases 3, 5 and 8-9
reference it and may request **additive** columns only; none of them creates it. Phase 4 also owns the
`contact` section type, the `ContactInquirySubmitted` event and the §6.10 routing (`InquiryRouter` plus one
`InquiryTarget` class per target, registered by the owning phase).

| Column | Type | Null / Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | — | |
| `inquiry_type` | string(32) | `general`, index | cast `InquiryType` (service / course / general) — **decides the routing target** (§6.10) |
| `name` | string(150) | not null | §17 |
| `email` | string(150) | not null, index | lower-cased |
| `phone` | string(32) | null | |
| `whatsapp` | string(32) | null | §17 |
| `company` | string(150) | null | §17 |
| `service_id` | foreignId `services` | null, index, **nullOnDelete** | §17 *service* — a real FK, services exist in this phase |
| `course_id` | unsignedBigInteger | null, index, **no FK (deferred, Phase 14)** | §2.1 |
| `course_name` | string(150) | null | what the visitor typed/selected before `courses` exists; kept forever as the snapshot |
| `budget` | string(100) | null | §17 *budget*; the public select is fed by `website.contact_budget_options` |
| `subject` | string(200) | null | §17 |
| `message` | text | not null | §17; HTML stripped on write |
| `source` | string(32) | `website`, index | cast `InquirySource` |
| `status` | string(32) | `new`, index | cast `ContactInquiryStatus` (new / read / in_progress / responded / closed) |
| `is_spam` | boolean | false, index | set by `SpamGuard`; spam is stored, never routed, never notified |
| `spam_reason` | string(100) | null | `honeypot` / `too_fast` / `token` / `duplicate` / `blocklist` / `score` |
| `routing_status` | string(32) | `not_applicable`, index | cast `InquiryRoutingStatus` (not_applicable / pending / routed / failed) |
| `routing_target` | string(32) | null | the intended target key: `crm_lead` / `course_inquiry` |
| `routed_type` | string(255) | null | the created record class (e.g. the Phase 5 Lead model) |
| `routed_id` | unsignedBigInteger | null | the created record id — no FK, the target lives in a later phase |
| `routed_at` | timestamp | null | |
| `routing_attempts` | unsignedTinyInteger | 0 | incremented per attempt; 3 failures move it to `failed` |
| `routing_error` | string(255) | null | `target_unregistered`, `module_disabled`, or the exception message |
| `assigned_to` | foreignId `users` | null, index, nullOnDelete | drives row scoping (§9) |
| `read_at` | timestamp | null | |
| `read_by` | foreignId `users` | null, nullOnDelete | |
| `responded_at` | timestamp | null | |
| `response_notes` | text | null | internal |
| `page_url` | string(255) | null | which page the form was on |
| `referrer_url` | string(255) | null | |
| `utm_source` `utm_medium` `utm_campaign` | string(100) | null | used to map `source` when routing to a lead (§6.10) |
| `filled_in_seconds` | unsignedSmallInteger | null | render→submit elapsed time, the anti-bot signal |
| `ip_address` | string(45) | null | |
| `user_agent` | text | null | |
| timestamps · softDeletes · blameable | | | |

Indexes: **`unique(routed_type, routed_id)` named `uq_contact_inquiry_routed_target`** (two inquiries can
never claim the same lead/course-inquiry row — the second insert fails rather than duplicating a lead),
`index(inquiry_type, status)`, `index(routing_status, created_at)`, `index(is_spam, created_at)`,
`index(status, created_at)`, **`index(routing_target, routing_status)`** (F-9.3 — the "Awaiting CRM" /
"Awaiting Institute" tabs and `routePending()` filter on exactly this pair), plus the FK indexes
`index(service_id)`, `index(assigned_to)`, `index(read_by)` (F-9.2).
Relationships: `belongsTo(Service)`, `belongsTo(User::class, assigned_to)`, plus a **nullable morph-like
pointer** (`routed_type` + `routed_id`) resolved lazily: `routedRecord()` returns null when the class does
not exist, so the admin row renders "target record removed / module not installed" instead of throwing.

---

## 3. Enums to add — `app/Enums/`

All string-backed, all implementing `label(): string` + `color(): string` and exposing
`static options(): array`, exactly as Phase 1 §2 requires.

**Enums this phase does NOT declare** (F-5.1, F-5.2 — one class, one name; a second declaration of an
`app/Enums` name is a merge conflict, never a style question):

| Enum | Declared by | What Phase 4 does |
|---|---|---|
| `ContentStatus` (`draft`, `scheduled`, `published`, `archived`) | **phase-03 §3** | casts only. `services.status`, `portfolio_items.status`, `team_members.status`, `success_stories.status` and **`blog_posts.status`** all cast to it; `blog_posts` uses the `scheduled` case. **`PostStatus` does not exist** — every former `PostStatus::*` reference is `ContentStatus::*` 1:1 (the values are identical strings, so there is no data migration). The blog's transition map moves to **`BlogService::allowedNext(ContentStatus $from): array`** (draft→scheduled/published; scheduled→draft/published; published→draft/archived; archived→draft), enforced by `BlogService` on every publish / schedule / unpublish / archive call — phase-03's enum gains no member |
| `EmploymentType` (`full_time`, `part_time`, `contract`, `internship`, `temporary`, `consultant`, `freelance` + `isSalaried()`, `leaveEligibleByDefault()`) | **phase-07 §3** | casts only, on `job_openings.employment_type`. Phase 4 migrates first, so the class file lands with Phase 4 carrying **phase-07 §3's case list and members verbatim**; Phase 7 reuses it unchanged and is the only phase that may extend it |

**Enums this phase declares** (eleven):

| Enum | Cases (name = value) | Extra members |
|---|---|---|
| `ApprovalStatus` | `Pending` = `pending`, `Approved` = `approved`, `Rejected` = `rejected` | `isPublic(): bool` (only `Approved`); colours amber / emerald / rose |
| `TestimonialType` | `Client` = `client`, `Student` = `student`, `Other` = `other` | `label()` drives the form field set (company vs course) |
| `ContentSource` | `Admin` = `admin`, `PublicForm` = `public_form`, `ClientPanel` = `client_panel`, `StudentPanel` = `student_panel`, `Import` = `import` | `requiresModeration(): bool` (false only for `Admin`/`Import`) |
| `SocialPlatform` | `Facebook`, `Instagram`, `LinkedIn` = `linkedin`, `X` = `x_twitter`, `GitHub` = `github`, `YouTube` = `youtube`, `TikTok` = `tiktok`, `Behance`, `Dribbble`, `Website` = `website` | `icon(): string`, `urlPattern(): ?string` — the allowlist for `team_members.social_links` keys |
| `WorkMode` | `Onsite` = `onsite`, `Remote` = `remote`, `Hybrid` = `hybrid` | |
| `JobOpeningStatus` | `Draft` = `draft`, `Open` = `open`, `Closed` = `closed`, `Filled` = `filled` | `acceptsApplications(): bool` (true only for `Open`), `isPublic(): bool` (open + filled? **no** — only `Open` lists publicly) |
| `JobApplicationStatus` | `New` = `new`, `Reviewing` = `reviewing`, `Shortlisted` = `shortlisted`, `Interview` = `interview`, `Selected` = `selected`, `Rejected` = `rejected` | `allowedNext(): array`, `isTerminal(): bool` (selected / rejected), `requiresReason(): bool` (rejected), `requiresInterviewSlot(): bool` (interview) |
| `InquiryType` | `Service` = `service`, `Course` = `course`, `General` = `general` | **`routingTarget(): ?string`** → `crm_lead` / `course_inquiry` / `null`. This single method is the routing contract (§6.10) |
| `ContactInquiryStatus` | `New` = `new`, `Read` = `read`, `InProgress` = `in_progress`, `Responded` = `responded`, `Closed` = `closed` | `isOpen(): bool` |
| `InquiryRoutingStatus` | `NotApplicable` = `not_applicable`, `Pending` = `pending`, `Routed` = `routed`, `Failed` = `failed` | `needsRetry(): bool` (pending / failed) |
| `InquirySource` | `Website` = `website`, `Facebook`, `Instagram`, `TikTok` = `tiktok`, `Google`, `WhatsApp` = `whatsapp`, `Referral`, `WalkIn` = `walk_in`, `Call` = `call`, `Email` = `email`, `Other` = `other` | **Phase 4 is the owner** of these eleven cases (F-5.3, resolved — no longer a request). `LeadSource` and `CourseInquirySource` are **deleted**: phase-05 casts `leads.source` and `clients.source` to this enum and phase-14-17 casts `course_inquiries.source`, with no data migration because the values are already identical strings |

`JobApplicationStatus::allowedNext()` — the six-stage pipeline, binding:

| From | Allowed next |
|---|---|
| `new` | `reviewing`, `shortlisted`, `rejected` |
| `reviewing` | `shortlisted`, `interview`, `rejected` |
| `shortlisted` | `interview`, `selected`, `rejected` |
| `interview` | `selected`, `rejected`, `shortlisted` (second round) |
| `selected` | `rejected` (offer declined/withdrawn) |
| `rejected` | `reviewing` (re-opened by an admin) |

Any other transition is a `422` from `ChangeApplicationStatusRequest`; every transition writes an
activity entry with old and new values plus the reason (§10).

---

## 4. `PermissionRegistry` additions

Phase 1 reserved most of these slugs under `ModuleGroup::Website` and seeded their permissions; Phase 4
**pins the exact ability set** for each and adds four new slugs (`service_categories`,
`portfolio_categories`, `technologies`, `blog_tags`). Presets are Phase 1 §4 constants
(`READ`, `CRUD`, `CRUD_FULL`, `APPROVE`, `STATUS`, `ASSIGN`, `FILES`, `REPORTS`). Every module below is
`ModuleGroup::Website`, **`is_core = false`** (all of them are disableable, and a disabled module 403s its
admin routes and 404s its public routes — §7.3). Re-running the seeder only adds the missing permission
rows; it never edits a role grant.

| Slug | Name | New in Phase 4 | Icon | Abilities | Sort |
|---|---|---|---|---|---|
| `service_categories` | Service Categories | **yes** | `rectangle-group` | `CRUD` + `STATUS` | 410 |
| `services` | Services | no (Phase 1) | `wrench-screwdriver` | `CRUD_FULL` + `STATUS` + `FILES` | 411 |
| `technologies` | Technologies | **yes** | `cpu-chip` | `CRUD` + `STATUS` + `FILES` | 412 |
| `portfolio_categories` | Portfolio Categories | **yes** | `rectangle-stack` | `CRUD` + `STATUS` | 415 |
| `portfolio` | Portfolio | no (Phase 1) | `photo` | `CRUD_FULL` + `STATUS` + `FILES` | 416 |
| `team` | Team Members | no (Phase 1) | `user-group` | `CRUD_FULL` + `STATUS` + `FILES` | 420 |
| `testimonials` | Testimonials | no (Phase 1) | `chat-bubble-left-right` | `CRUD` + `STATUS` + `APPROVE` + `FILES` | 425 |
| `student_reviews` | Student Reviews | no (Phase 1) | `star` | `CRUD` + `STATUS` + `APPROVE` + `FILES` | 426 |
| `success_stories` | Success Stories | no (Phase 1) | `trophy` | `CRUD` + `STATUS` + `FILES` | 427 |
| `blog_categories` | Blog Categories | no (Phase 1) | `folder` | `CRUD` + `STATUS` | 430 |
| `blog_tags` | Blog Tags | **yes** | `tag` | `CRUD` + `STATUS` | 431 |
| `blog_posts` | Blog Posts | no (Phase 1) | `newspaper` | `CRUD_FULL` + `STATUS` + `APPROVE` + `FILES` + `REPORTS` | 432 |
| `jobs` | Careers / Jobs | no (Phase 1) | `briefcase` | `CRUD_FULL` + `STATUS` | 440 |
| `job_applications` | Job Applications | no (Phase 1) | `document-text` | `READ` + `edit` + `delete` + `STATUS` + `ASSIGN` + `download` + `export` + `print` | 441 |
| `contact_inquiries` | Contact Inquiries | no (Phase 1) | `inbox-arrow-down` | `READ` + `edit` + `delete` + `STATUS` + `ASSIGN` + `export` + `print` + **`view_logs`** | 445 |

**Ability semantics that are not obvious** (binding — the tests in §11 assert them):

| Permission | Means |
|---|---|
| `blog_posts.create` | create a post; it is owned by the creator (`author_id`) |
| `blog_posts.edit` | edit **your own** posts, any status |
| `blog_posts.approve` | the *editor* ability: edit, schedule, publish, unpublish and delete **any author's** post |
| `blog_posts.change_status` | publish / schedule / unpublish / archive (your own with `edit`, anyone's with `approve`) |
| `blog_posts.view_reports` | the per-post views screen and the 30-day chart |
| `testimonials.approve` / `.reject` | run the moderation queue; `approve` also grants bulk approve |
| `testimonials.edit` | correct a typo in a review — audited with old/new values, never silent |
| `job_applications.download` | download a CV (the only path to the private file) |
| `job_applications.change_status` | move a candidate along the six-stage pipeline |
| `job_applications.assign` | set `assigned_to` |
| `contact_inquiries.change_status` | includes the **Route now** action, because routing changes `routing_status`. No new `Ability` case is invented (§12 Q1) |
| `contact_inquiries.assign` | set `assigned_to`, the basis of row scoping for reviewers |
| `contact_inquiries.view_logs` | **F-12.4** — the only way to see the technical / PII block of a submission: `ip_address`, `user_agent`, `utm_source`, `utm_medium`, `utm_campaign`, `referrer_url`, `filled_in_seconds` and the spam verdict. Without it those columns are **absent from the query and the response body**, not merely hidden in Blade (§9.1) |

No new portal permissions: none of these modules is reachable from the collaborator, student, teacher or
client panel in Phase 4 (§9).

---

## 5. `SettingsRegistry` additions — 21 keys contributed into Phase 3's `website` group

**Phase 4 declares no settings group (F-6.3).** The `website` group is declared **once**, by phase-03 §5.1
(`['label' => 'Website & Forms', 'icon' => 'globe-alt', 'sort' => 75, 'permission' => 'settings.edit']`).
Phase 4 appends the 21 keys below into that existing group — they are listed for reference at phase-03
§5.1b, giving the group 34 keys with no collisions. Rules, defaults and behaviour below remain Phase 4's.
Two groups with the slug `website` would mean one silently overwriting the other's label and sort, so this
is a hard rule, not a preference.

Existing keys are **reused, never redefined**: `maintenance.contact_form_enabled` gates
`POST /contact`, `maintenance.public_site_enabled` gates every public route (Phase 3 middleware),
`security.max_upload_mb` is the hard ceiling for every upload here, `seo.*` supplies the fallback meta,
`localization.*` drives `money()` on prices and salaries.

| group.key | Type | Default | Public | Rules / notes |
|---|---|---|---|---|
| `website.services_per_page` | number | `12` | yes | `integer, between:3,48` |
| `website.portfolio_per_page` | number | `12` | yes | `integer, between:3,48` |
| `website.blog_per_page` | number | `9` | yes | `integer, between:3,48` |
| `website.blog_related_count` | number | `3` | yes | `integer, between:0,6`; 0 hides the related block |
| `website.blog_view_dedupe_minutes` | number | `1440` | no | `integer, between:5,10080` — the repeat-view window (§6.7) |
| `website.blog_view_prune_days` | number | `90` | no | `integer, between:7,730` — `blog_post_views` retention |
| `website.reviews_per_page` | number | `12` | yes | testimonials + student reviews feeds |
| `website.team_page_enabled` | boolean | `true` | yes | false → `/team` 404s |
| `website.portfolio_detail_enabled` | boolean | `true` | yes | false → the portfolio grid links to nothing, `/portfolio/{slug}` 404s |
| `website.testimonial_auto_approve` | boolean | `false` | no | true → **staff-entered** testimonials/reviews are created `approved`; a public or panel submission is **always** `pending` regardless |
| `website.careers_enabled` | boolean | `true` | yes | false → `/careers` 404s and `apply` is refused |
| `website.careers_notify_emails` | textarea | *(empty)* | no | `nullable` + each line `email`; who is mailed on a new application |
| `website.cv_max_mb` | number | `5` | no | `integer, between:1,10`; the effective limit is `min(this, security.max_upload_mb)` |
| `website.cv_allowed_types` | multiselect | `pdf,doc,docx` | no | options `pdf,doc,docx`; an empty set is rejected (§6.8) |
| `website.contact_notify_emails` | textarea | *(empty)* | no | as above, for inquiries |
| `website.contact_budget_options` | json | `["Under 50,000","50,000 – 150,000","150,000 – 500,000","500,000 – 1,000,000","Above 1,000,000","Not sure yet"]` | yes | array of strings, max 12; feeds the public budget select; the chosen label is stored verbatim in `contact_inquiries.budget` |
| `website.contact_min_submit_seconds` | number | `3` | no | `integer, between:0,60`; faster than this = spam (§6.9) |
| `website.contact_rate_per_hour` | number | `20` | no | `integer, between:1,200`; per-IP hourly cap fed to the named limiter |
| `website.spam_blocklist` | textarea | *(empty)* | no | one word or domain per line, matched case-insensitively against subject + message |
| `website.inquiry_auto_route` | boolean | `true` | no | false → every inquiry waits for a manual **Route now**, nothing is auto-created |
| `website.inquiry_default_assignee_id` | select | *(null)* | no | `nullable, exists:users,id`; pre-assigns new inquiries so the §9 reviewer scoping has an owner |

Encrypted fields: none — nothing in this group is a secret. Every key is `is_readonly = false`.

---

## 6. Services and support classes

Namespaces: models `App\Models\Cms\*`, services `App\Services\Cms\*`, contracts
`App\Contracts\Cms\*`, support `App\Support\*`. Controllers stay thin: validate in the Form Request
(§6.11), authorize with the policy (§9), call exactly one service method, flash a toast.

### 6.1 `App\Support\SlugGenerator` + `App\Models\Concerns\HasSlug`

```php
final class SlugGenerator
{
    public const RESERVED = ['admin','login','logout','password','dashboard','api','storage','livewire',
        'blog','services','service','portfolio','team','careers','career','contact','courses','course',
        'admission','student','teacher','client','collaborator','preview','sitemap','sitemap.xml',
        'robots.txt','feed','search','privacy-policy','terms'];

    public static function make(string $source, string $table, ?int $ignoreId = null,
                               string $column = 'slug', int $max = 180): string;
    public static function isReserved(string $slug): bool;
    public static function normalise(string $value): string;   // ASCII transliteration + Str::slug
}
```

Invariants:

1. `normalise()` transliterates to ASCII, lower-cases, collapses separators and truncates to `$max`
   **before** any numeric suffix is added, so the suffixed slug still fits the column.
2. `make()` checks uniqueness **including soft-deleted rows** (`withTrashed()`); a trashed post keeps its
   permalink, so the generator must walk past it (`-2`, `-3`, …).
3. A source that normalises to an empty string (e.g. a title of only emoji) falls back to the model name
   plus the next id (`service-17`).
4. A result that is reserved, or is purely numeric, gets a suffix until it is neither.
5. The caller runs inside a transaction; on a `QueryException` 1062 the owning service retries `make()` up
   to 3 times before failing — two concurrent publishes can never produce duplicate slugs.

`HasSlug` trait: declares `sluggableSource(): string`; on `creating` fills `slug` when the attribute is
empty; on `updating` **never** changes the slug by itself. An admin may edit the slug through the form
(validated `regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/`, `unique` ignoring self, not reserved); when a record that
has ever been published has its slug changed, the owning service writes an activity entry
(`slug_changed`, old → new) and the UI warns that existing links will break (§12 R2).

### 6.2 Taxonomy and ordering

```php
final class TaxonomyService          // service_categories, portfolio_categories, blog_categories, blog_tags, technologies
{
    public function store(string $modelClass, array $data, ?UploadedFile $image = null): Model;
    public function update(Model $term, array $data, ?UploadedFile $image = null): Model;
    public function delete(Model $term, ?int $reassignTo = null): void;
    public function toggleActive(Model $term): Model;
}

final class ContentOrderService
{
    public function reorder(string $modelClass, array $orderedIds): void;
}
```

Invariants: `delete()` **refuses** (domain exception → 422 toast) when the term still has children and no
`$reassignTo` is given; with `$reassignTo` it moves every child in one transaction, then soft-deletes the
term. `$reassignTo` must be a different, existing term of the same model. `reorder()` asserts every id
belongs to `$modelClass`, then writes `sort_order` 1..n in one transaction and one activity entry (not one
per row). Deleting a `Technology` only detaches pivot rows; it never touches a service or a portfolio item.

### 6.3 `PortfolioService`

```php
final class PortfolioService
{
    public function store(array $data, array $images = [], array $technologyIds = []): PortfolioItem;
    public function update(PortfolioItem $item, array $data, array $technologyIds = []): PortfolioItem;
    public function addImages(PortfolioItem $item, array $files): Collection;   // UploadedFile[] -> MediaAsset[]
    public function detachImage(PortfolioItem $item, MediaAsset $asset): void;
    public function reorderImages(PortfolioItem $item, array $orderedMediaAssetIds): void;
    public function setCover(PortfolioItem $item, MediaAsset $asset): void;
    public function delete(PortfolioItem $item): void;
    public function forceDelete(PortfolioItem $item): void;
}
```

Invariants (rewritten for `portfolio_item_media` + `MediaService`, F-2.4):

1. **Exactly one cover, and it is a column not a flag.** `setCover()` writes
   `portfolio_items.cover_media_id` inside one transaction and refuses an asset that is not attached to that
   item. The first image attached to an item with no cover becomes the cover automatically; detaching the
   cover promotes the next attached asset by pivot `sort_order` (or nulls `cover_media_id` when the gallery
   is empty). There is no `is_cover` boolean and therefore no "two covers" state to repair.
2. Each file goes through Phase 3's `MediaService::store($file, MediaCollection::Pages, ImageProfile::Card)`
   (§6.6), which validates by file content, strips EXIF, generates the derivative set and records
   `width` / `height` / `size_bytes` / `alt_text` on `media_assets` — so the public `<x-site.image>` declares
   intrinsic dimensions with no CLS. `alt_text` is **required** before an item may be published.
3. `addImages()` enforces a maximum of 20 attached assets per item and rejects the whole batch (nothing
   stored, nothing attached) if any file fails validation — no half-uploaded gallery. Re-uploading an
   identical file reuses the existing `media_assets` row (checksum), and `uq_pim` makes a double attach a
   no-op rather than a duplicate thumbnail.
4. `delete()` (soft) keeps every attachment and every binary. `forceDelete()` detaches the pivot rows and
   then removes the item; **it deletes no file** — a `media_assets` row is library content that may be in
   use elsewhere, and `MediaPolicy::delete()` is the only place a binary is ever removed.
5. `reorderImages()` is id-checked per item — a `media_asset_id` not attached to this item is rejected
   (422), never silently ignored; the pivot `sort_order` is rewritten 1..n in one transaction.

### 6.4 Content services — services, team, success stories

```php
final class ServiceContentService
{
    public function store(array $data, ?UploadedFile $image, array $technologyIds = []): Service;
    public function update(Service $service, array $data, ?UploadedFile $image, array $technologyIds = []): Service;
    public function changeStatus(Service $service, ContentStatus $status): Service;
    public function toggleFeatured(Service $service): Service;
    public function delete(Service $service): void;
}

final class TeamService
{
    public function store(array $data, ?UploadedFile $photo): TeamMember;
    public function update(TeamMember $member, array $data, ?UploadedFile $photo): TeamMember;
    public function togglePublic(TeamMember $member): TeamMember;
    public function delete(TeamMember $member): void;
}

final class SuccessStoryService
{
    public function store(array $data, ?UploadedFile $photo): SuccessStory;
    public function update(SuccessStory $story, array $data, ?UploadedFile $photo): SuccessStory;
    public function changeStatus(SuccessStory $story, ContentStatus $status): SuccessStory;
    public function toggleFeatured(SuccessStory $story): SuccessStory;
}
```

Invariants:

1. `starting_price` is written straight from the validated `decimal:2` string — never cast to float, never
   multiplied. `price_visible = false` means the column keeps its value and the public view omits it.
2. `features` and `skills` are stored as a re-indexed array of trimmed, non-empty strings (max 20/30
   entries, each ≤ 150 chars); an empty array is stored as `null`.
3. `social_links` keys must be `SocialPlatform` values (unknown key → 422) and each value must be
   `https?` with a host; the link renders with `rel="nofollow noopener"` and `target="_blank"`.
4. `changeStatus()` to `Published` requires a non-empty name/title and slug, and writes an activity entry
   with old and new status. Publishing never alters `sort_order`.
5. Every service method runs in a transaction; the old image is deleted only **after** the new row saves.

### 6.5 Moderation — one code path for testimonials and student reviews

```php
interface Moderatable            // App\Contracts\Cms\Moderatable
{
    public function moderationStatus(): ApprovalStatus;
    public function isPubliclyVisible(): bool;
    public function moderationLabel(): string;     // for the toast + activity description
}

final class ModerationService
{
    public function approve(Moderatable&Model $record, ?string $note = null): void;
    public function reject(Moderatable&Model $record, string $reason): void;
    public function reset(Moderatable&Model $record, string $reason): void;        // back to Pending
    public function bulkApprove(string $modelClass, array $ids): int;              // returns approved count
    public function toggleFeatured(Moderatable&Model $record): void;               // Approved records only
}
```

Invariants:

1. Allowed transitions only: Pending → Approved, Pending → Rejected, Approved → Rejected,
   Rejected → Approved, any → Pending via `reset()`. Anything else is a domain exception (422).
2. `approve()` stamps `approved_by = auth()->id()` and `approved_at = now()`, clears
   `rejection_reason`, and fires `TestimonialApproved` / `StudentReviewApproved`.
3. `reject()` requires a non-empty reason (stored in `rejection_reason`), nulls `approved_by`/`approved_at`.
4. **The review body is never modified by moderation.** Editing the text is a separate `edit` action that
   logs old and new values.
5. `toggleFeatured()` refuses to feature a record that is not `Approved`.
6. `bulkApprove()` is one transaction, skips ids already `Approved` (idempotent), ignores ids the user
   cannot see, and writes one activity entry per record plus a summary entry.
7. Nothing that is not `Approved` can ever appear in a public query — enforced centrally by
   `scopePublic()` (§9.2), not by each Blade file.

### 6.6 Images — Phase 4 ships no uploader; it calls Phase 3's `MediaService`

**`App\Services\Cms\ImageUploadService` is deleted (F-2.4, D24).** A second uploader with its own disk
path, its own MIME map and its own thumbnail story is a second security control and a second derivative
pipeline. Every image this phase stores goes through Phase 3's single pipeline:

```php
App\Services\Cms\MediaService::store(UploadedFile $f, MediaCollection $c, ImageProfile $p, array $meta = []): MediaAsset
App\Services\Cms\MediaService::url(MediaAsset $a, ?int $width = null): string
App\Services\Cms\MediaService::srcset(MediaAsset $a): string
App\Services\Cms\MediaService::delete(MediaAsset $a): void          // refuses while usage_count > 0
App\Services\Cms\MediaService::recountUsage(?MediaAsset $a = null): void
```

What Phase 4 relies on, all guaranteed by phase-03 §6.8 rather than restated here: the real MIME read with
`finfo` (the client header and the extension are both ignored), **SVG rejected**, EXIF stripped, the
original downscaled to `website.image_max_width` and never upscaled, a ULID path so a user filename can
never become a path, `checksum` de-duplication, and the WebP + original derivative set per `ImageProfile`.

| Phase 4 column | `MediaCollection` | `ImageProfile` |
|---|---|---|
| `services.image_media_id`, `service_categories.image_media_id`, `portfolio_categories.image_media_id`, `blog_categories.image_media_id` | `pages` | `Card` |
| `portfolio_item_media.media_asset_id`, `portfolio_items.cover_media_id` | `pages` | `Card` |
| `blog_posts.featured_image_media_id` | `pages` | `Banner` |
| `team_members.photo_media_id`, `testimonials.author_photo_media_id`, `student_reviews.student_photo_media_id`, `success_stories.photo_media_id` | `general` | `Thumbnail` |
| `technologies.logo_media_id` | `general` | `Logo` |
| every OG image (`seo_meta.og_image_media_id`) | `seo` | `Og` |

Rendering is `<x-site.image :asset="$asset" profile="card" />` — **no bare `<img>` in `site/` views**, and
no Phase-4 view builds a `storage/` URL by hand. The only remaining bare path column in this phase is
`job_applications.cv_path`, a private artefact on the `local` disk (§6.8, D21).

### 6.7 Blog — `BlogService`, `BlogViewCounter`, scheduled publishing

```php
final class BlogService
{
    public function store(array $data, ?UploadedFile $image, array $tagNames = []): BlogPost;
    public function update(BlogPost $post, array $data, ?UploadedFile $image, array $tagNames = []): BlogPost;
    public function publish(BlogPost $post): BlogPost;
    public function schedule(BlogPost $post, CarbonInterface $at): BlogPost;
    public function unpublish(BlogPost $post): BlogPost;      // back to Draft
    public function archive(BlogPost $post): BlogPost;
    public function syncTags(BlogPost $post, array $tagNames): void;
    public function related(BlogPost $post, ?int $limit = null): Collection;
    public function readingMinutes(string $html): int;
    public function allowedNext(ContentStatus $from): array;   // the blog transition map (F-5.1)
    public function delete(BlogPost $post): void;
}
```

Every status name below is a **`ContentStatus`** case (F-5.1): `ContentStatus::Draft`,
`ContentStatus::Scheduled`, `ContentStatus::Published`, `ContentStatus::Archived`. `allowedNext()` lives on
this service, not on the shared enum, because the four-status transition map is blog policy and phase-03
owns the enum.

Invariants:

1. **Scheduling.** `schedule()` requires `$at` strictly in the future (otherwise it delegates to
   `publish()`), sets `status = Scheduled` and `published_at = $at`. A scheduled post is invisible to the
   public (§9.2) and visible in admin with a countdown badge.
2. **Publishing.** `publish()` sets `status = Published` and `published_at = now()` **only when
   `published_at` is null or in the future**; a re-published post keeps its original first-publish
   timestamp, so permalinks and sitemap `lastmod` stay stable. Fires `BlogPostPublished` exactly once per
   transition (never on a plain edit of an already-published post).
3. `unpublish()` sets `Draft` and leaves `published_at` untouched; `archive()` sets `Archived` and is the
   only non-destructive way to retire a post (no deletion needed).
4. `syncTags()` matches existing tags by **slug** (case-insensitive, `withTrashed()` — a trashed tag is
   restored rather than duplicated), creates the missing ones, and detaches the rest; tag names are trimmed,
   max 40 per post.
5. `readingMinutes()` counts words of `strip_tags($content)` at 200 wpm, minimum 1, stored on save.
6. `related()` (§6.7.2) never returns unpublished posts, never returns `$post`, and issues at most three
   queries.
7. Deleting a post soft-deletes it, keeps its `blog_post_views` rows (the FK cascade only fires on a force
   delete), and keeps the slug reserved.

**6.7.1 `BlogViewCounter` — view counting that resists refresh spam**

```php
final class BlogViewCounter
{
    public function record(BlogPost $post, Request $request): bool;   // true = a new view was counted
    public function visitorHash(Request $request): string;
    public function dailyTotals(BlogPost $post, DateRange $range): array;   // ['2026-09-01' => 12, ...]
}
```

The defence is four layers, in this order — the first one that matches stops the count:

| # | Layer | Rule |
|---|---|---|
| 1 | Request shape | Only `GET`, only a non-prefetch request (`Purpose: prefetch`, `Sec-Purpose` containing `prefetch`, `X-Moz: prefetch` are skipped), user agent not matching the bot pattern list in `config/cms.php` |
| 2 | Viewer | A user who can `update` this post (author or editor) is never counted — previews must not inflate numbers |
| 3 | Session fast-path | `session("bp_viewed.{id}")` set → return false with **no DB query** |
| 4 | Unique row | Insert into `blog_post_views` (`blog_post_id`, `visitor_hash`, `viewed_on`); a 1062 duplicate is caught and returns false |

Invariants: `views_count` is incremented with an atomic `increment()` **only** when layer 4 actually
inserts, so `views_count == count(blog_post_views)` always holds (§11 test 21). `visitorHash()` is
`hash_hmac('sha256', ip . '|' . userAgent, config('app.key'))` — the raw IP is never written to
`blog_post_views`. `viewed_on` is `now()` floored to the `website.blog_view_dedupe_minutes` bucket (default
1440 = one calendar day), so the same reader refreshing 100 times in a day counts once. Recording happens
through the queued `RecordBlogPostView` job dispatched after the response (`dispatchAfterResponse`), so the
page never waits; the counter is idempotent, so a retried job counts nothing twice.

**6.7.2 Related posts — deterministic, no randomness**

`related($post, $limit = setting('website.blog_related_count'))` fills the list in tiers, de-duplicating by
id and stopping as soon as `$limit` is reached:

| Tier | Query |
|---|---|
| 1 | Published posts in the same `blog_category_id`, excluding `$post`, newest `published_at` first |
| 2 | Published posts sharing at least one tag with `$post`, ordered by shared-tag count desc then `published_at` desc |
| 3 | Published `is_featured` posts, then the newest published posts, to pad out the remainder |

Every tier eager-loads `category` and `tags`; the result is returned as a collection of at most `$limit`
posts (possibly empty — the Blade hides the block rather than printing a heading with nothing under it).

**6.7.3 Scheduled publishing — `blog:publish-scheduled`**

`App\Console\Commands\PublishScheduledPosts`, scheduled **every minute** in `routes/console.php` with
`->withoutOverlapping(5)->runInBackground()`.

Invariants: selects `status = scheduled AND published_at <= now()` in chunks of 50 inside
`DB::transaction()` with `lockForUpdate()`; flips each to `Published` **without** changing `published_at`
(the scheduled moment *is* the publish moment); fires `BlogPostPublished` per post; is safe to run
concurrently and on a machine whose scheduler was down for hours (a backlog is published in one pass, each
post exactly once); never touches `draft` or `archived` rows; prints `published N post(s)` and logs a
single activity entry per post with the actor recorded as the system.

### 6.8 Careers — job openings, applications, CV storage

```php
final class JobOpeningService
{
    public function store(array $data): JobOpening;
    public function update(JobOpening $job, array $data): JobOpening;
    public function changeStatus(JobOpening $job, JobOpeningStatus $status, ?string $reason = null): JobOpening;
    public function closeExpired(): int;                 // used by the scheduled command
    public function purge(JobOpening $job): void;        // force delete + CV cleanup
}

final class JobApplicationService
{
    public function apply(JobOpening $job, array $data, UploadedFile $cv, Request $request): JobApplication;
    public function changeStatus(JobApplication $a, JobApplicationStatus $to, array $context = []): JobApplication;
    public function assign(JobApplication $a, ?User $reviewer): JobApplication;
    public function saveNotes(JobApplication $a, ?string $notes, ?int $rating): JobApplication;
}

final class ApplicationCvService
{
    public function store(UploadedFile $file, JobOpening $job): array;   // ['path','original_name','mime','size']
    public function download(JobApplication $a): StreamedResponse;
    public function delete(JobApplication $a): void;
}
```

`apply()` invariants:

1. Refuses unless `website.careers_enabled` is true, the module `jobs` is enabled, the status
   `acceptsApplications()` (only `Open`) and `deadline` is null or ≥ today — a `JobClosedException`
   rendered as 422 with a clear message, never a silent success.
2. Runs `SpamGuard` (§6.9) first; a spam verdict returns the same success response and stores **nothing**
   (an application is not a queue the business wants spam in, unlike an inquiry).
3. Stores the CV **before** the insert; if the transaction rolls back, the stored file is deleted in the
   `catch` block — no orphan files.
4. A duplicate `(job_opening_id, email)` raises 1062, is caught and returned as a validation error on the
   `email` field ("You have already applied for this position."). An admin must force-delete the old
   application to let the same address re-apply (§12 R4).
5. `applications_count` on the opening is bumped with an atomic `increment()` in the same transaction.
6. Status starts `New`; `source` defaults to `InquirySource::Website`; IP and user agent are recorded.
7. Fires `JobApplicationReceived` after commit.

`changeStatus()` invariants: the target must be in `allowedNext()` (else 422); `rejected` requires
`$context['reason']`; `interview` requires `$context['interview_at']` in the future plus a mode; stamps
`status_changed_at`/`status_changed_by`; writes one activity entry with old and new status plus the reason;
fires `JobApplicationStatusChanged`; **never** deletes or overwrites the CV.

**CV upload validation — the whole rule set, binding:**

| Check | Rule |
|---|---|
| Required | `required`, `file` |
| Extension allowlist | `mimes:pdf,doc,docx` intersected with `website.cv_allowed_types` |
| Real MIME | `mimetypes:application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document` — validated from the file contents (finfo), not the browser header |
| Second gate in the service | `ApplicationCvService::store()` re-checks `$file->getMimeType()` against the same map and **throws** if it disagrees with the extension, so a `cv.php` renamed `cv.pdf` is rejected even if validation was bypassed |
| Size | `max:` = `min(website.cv_max_mb, security.max_upload_mb)` × 1024 KB |
| Name | never trusted: the stored name is `{ulid}.{ext from detected MIME}`; `cv_original_name` is sanitised (`basename`, control chars stripped, 255 chars) and used only as the download filename |
| Location | disk `local` (private), `careers/applications/{Y}/{job_opening_id}/` — **never** the `public` disk, so no URL can reach it |
| Serving | only `GET /admin/job-applications/{application}/cv` behind `can:job_applications.download`, responding with `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, and the stored MIME — never inline, never a redirect to storage |
| Retention | a soft-deleted application keeps its file (restore is lossless); `forceDeleted` removes it |

`closeExpired()`: sets `Open` openings whose `deadline < today` to `Closed`, stamps `closed_at`, writes one
activity entry each, and returns the count. Run daily at 00:10 (§10).

### 6.9 Input sanitisation and spam protection (no external captcha)

```php
final class SpamGuard
{
    public function verdict(array $input, Request $request): SpamVerdict;  // readonly DTO: isSpam, reason, score
    public function honeypotField(): string;       // 'website_url'  — a plausible, never-used field name
    public function timestampField(): string;      // 'form_token'
    public function signedTimestamp(): string;     // encrypted now() put into the form by the Blade component
}
```

| Signal | Verdict |
|---|---|
| Honeypot input non-empty | spam, reason `honeypot` |
| `form_token` missing, undecryptable or older than 12 hours | spam, reason `token` |
| Elapsed time (now − token) < `website.contact_min_submit_seconds` | spam, reason `too_fast` |
| Identical `sha256(email + message)` already submitted in the last 10 minutes | spam, reason `duplicate` |
| Subject or message matches a `website.spam_blocklist` entry | spam, reason `blocklist` |
| More than 2 URLs in `message` | score +2 |
| `message` shorter than 15 chars, or no spaces at all | score +1 |
| Name contains a URL or a `[url]`/BBCode marker | score +2 |
| **score ≥ 3** | spam, reason `score` |

Rate limiting (named limiters registered in `App\Providers\RateLimitServiceProvider`, applied as route
middleware — no external captcha service, per the requirement):

| Limiter | Limit |
|---|---|
| `public-contact` | 5 per minute per IP **and** `website.contact_rate_per_hour` (default 20) per hour per IP **and** 3 per hour per submitted email |
| `public-apply` | 3 per hour per IP and 2 per day per submitted email |

A limiter hit returns 429 rendered inside the site layout ("Too many submissions, please try again in X
minutes") — never a raw framework error page.

**Sanitisation — one sanitiser, `App\Support\RichText::sanitize()` (F-2.5, D25).**
`App\Support\HtmlSanitizer` is **deleted**; Phase 4 ships no sanitiser of its own and every reference to
`HtmlSanitizer` in this contract means `RichText::sanitize()`. Phase 3 owns the class: it wraps
`mews/purifier` with the committed `cms` profile (`config/purifier.php`) and is applied **on write and again
on render** — the database is not a trust boundary. Q7 is answered "adopt the package" and R7 is closed:
a duplicated security control is a security defect, because SEC-05 must have exactly one answer.

| Field kind | Rule |
|---|---|
| Plain-text fields (`review`, `message`, `cover_letter`, `internal_notes`, `bio`) | stored as `strip_tags()`-cleaned text with control characters removed, escaped by Blade on render |
| Rich-text fields (`content`, `full_description`, `description`, `requirements`, `responsibilities`, `story`) | `RichText::sanitize()` on write, `RichText::sanitize()` again at render, and only then `{!! !!}`. The tag, attribute and iframe-host allowlist is phase-03 §6.6's `cms` profile — Phase 4 does not define a second allowlist |
| `video_url` | hosts `youtube.com`, `youtu.be`, `vimeo.com` only; rendered as an embed built from the parsed id, never as raw user HTML |

### 6.10 The inquiry routing contract (§17) — service → CRM lead, course → institute inquiry

This is the part of Phase 4 that other phases plug into, so it is specified down to the field mapping.

**Principle.** `contact_inquiries` is the permanent record of what the visitor submitted. Routing creates
an *additional* working record in another module; it never moves, edits or deletes the inquiry. If routing
is impossible the inquiry is still fully usable in the admin queue — **a missing future phase can never
lose a lead**.

**6.10.1 The contract**

```php
interface InquiryTarget                       // App\Contracts\Cms\InquiryTarget
{
    public function key(): string;            // 'crm_lead' | 'course_inquiry'
    public function label(): string;          // shown in the admin UI ("CRM Lead")
    public function isAvailable(): bool;      // target module exists AND Modules::enabled(slug)
    public function handles(InquiryType $type): bool;
    public function handle(ContactInquiry $inquiry): Model;   // creates and returns the target record
}

final class InquiryRouter                     // App\Services\Cms\InquiryRouter, singleton
{
    public function register(InquiryTarget $target): void;        // from the owning phase's ServiceProvider
    public function targets(): array;                             // key => InquiryTarget
    public function target(string $key): ?InquiryTarget;
    public function route(ContactInquiry $inquiry, bool $manual = false): InquiryRoutingStatus;
    public function routePending(int $limit = 200): array;        // ['routed'=>int,'pending'=>int,'failed'=>int]
}
```

`InquiryType::routingTarget()` is the only mapping from form input to target key:
`Service → 'crm_lead'`, `Course → 'course_inquiry'`, `General → null`.

**6.10.2 `route()` — the exact algorithm**

1. Reload the inquiry `lockForUpdate()` inside `DB::transaction()`.
2. If `is_spam` → return `NotApplicable`, touch nothing. **Spam is never routed.**
3. If `routed_id` is already set → return `Routed` (idempotent; a second call creates nothing).
4. `$key = $inquiry->inquiry_type->routingTarget()`; if null → set `routing_status = NotApplicable`, return.
5. Set `routing_target = $key`. Resolve `$target = InquiryRouter::target($key)`.
   - Not registered → `routing_status = Pending`, `routing_error = 'target_unregistered'`, increment
     `routing_attempts`, return `Pending`.
   - Registered but `isAvailable() === false` → `routing_status = Pending`,
     `routing_error = 'module_disabled'`, return `Pending`.
6. Call `$target->handle($inquiry)`. On success store `routed_type = get_class($record)`,
   `routed_id = $record->getKey()`, `routed_at = now()`, `routing_status = Routed`, clear `routing_error`,
   fire `ContactInquiryRouted`, write an activity entry naming both records.
7. On any exception: increment `routing_attempts`, store the exception message (truncated to 255) in
   `routing_error`, set `routing_status = Failed` when `routing_attempts >= 3` else `Pending`, **roll back
   only the target record** (the inquiry's own state is saved in a separate statement), and log it. The
   queued job's retry/backoff is 3 tries, 1/5/15 minutes.
8. The `uq_contact_inquiry_routed_target` unique index is the last line of defence: if two workers race,
   the loser's insert fails and its inquiry stays `Pending` rather than creating a duplicate lead.

`routePending()` walks `routing_status IN (pending, failed)`, oldest first, `is_spam = false`, and calls
`route()` on each — safe to run repeatedly.

**6.10.3 Behaviour before Phase 5 and Phase 15 exist (binding)**

| Situation | Stored state | Admin UI | Recovery |
|---|---|---|---|
| `service` inquiry, CRM not built | `routing_target = crm_lead`, `routing_status = pending`, `routing_error = target_unregistered` | row badge "Awaiting CRM"; the **Route now** button is disabled with the tooltip "Lead module not installed yet" | Phase 5 registers its target, then `php artisan inquiries:route-pending` (also run hourly by the scheduler) routes the whole backlog |
| `course` inquiry, institute not built | same with `course_inquiry` | badge "Awaiting Institute" | Phase 15, same command |
| target module exists but is **disabled** in `modules` | `pending`, `routing_error = module_disabled` | badge "Module disabled" | re-enable the module; the hourly command picks it up |
| `website.inquiry_auto_route = false` | `pending`, `routing_error = null` | **Route now** enabled | manual click per row, or a bulk action |
| `general` inquiry | `not_applicable` | no routing badge, handled in the queue | none needed |
| spam | `not_applicable`, `is_spam = true` | Spam tab only | "Not spam" re-queues routing |

Nothing in this table throws, 500s, or writes a failed job. Phase 4 ships with **no** target registered,
and its own tests register a fake target (`tests/Support/FakeInquiryTarget.php`) to prove the pipeline.

**6.10.4 Field mapping the owning phases must implement**

Phase 5 `App\Support\Inquiry\CrmLeadInquiryTarget implements InquiryTarget` (key `crm_lead`, module
`leads`), registered into **this phase's** `InquiryRouter` from Phase 5's service provider. Phase 5 ships
**no** `CreateLeadFromContactInquiry` listener — there is one routing path, and it is the router (F-2.1).

| `contact_inquiries` | → Lead field |
|---|---|
| `name` | `name` |
| `company` | `company` |
| `email`, `phone`, `whatsapp` | same names |
| `service_id` | **`leads.service_id`** (the existing phase-05 §2.1 column pointing at `services.id`) — there is no `leads.interested_service_id` (F-3.6) |
| `budget` | `budget` |
| `subject` + `message` | `notes` (subject as the first line) |
| `source` (`InquirySource`) | **`leads.source`**, cast to the same `App\Enums\InquirySource` (F-5.3); when `source = website` and `utm_source` is set, map the UTM value onto the matching `InquirySource` case, else keep `Website` |
| `id` | `leads.contact_inquiry_id` (nullable FK with **`UNIQUE uq_leads_inquiry(contact_inquiry_id)`** — the 1062 *is* the idempotency guard, F-3.7; a second routing attempt returns the existing lead) |
| — | `status = New`, `assigned_to = website.inquiry_default_assignee_id` |

Phase 14-17 `CourseInquiryTarget` (key `course_inquiry`, module `course_inquiries`):

| `contact_inquiries` | → CourseInquiry field |
|---|---|
| `name`, `email`, `phone`, `whatsapp` | same names |
| `course_id` | `course_id` when set |
| `course_name` | `course_name` / `interest_note` when `course_id` is null |
| `message` | `notes` |
| `source` | `course_inquiries.source`, cast to the same `App\Enums\InquirySource` (F-5.3) |
| `id` | `course_inquiries.contact_inquiry_id` (nullable FK → `contact_inquiries.id`, `nullOnDelete`, deferred guarded FK, with **`UNIQUE uq_ci_inquiry(contact_inquiry_id)`** — F-3.8; `course_inquiries.idempotency_key` stays, it guarantees something different) |
| — | `status = New` |

**6.10.5 `ContactInquiryService`**

```php
final class ContactInquiryService
{
    public function submit(array $data, Request $request): ContactInquiry;   // public form entry point
    public function markRead(ContactInquiry $i): void;
    public function assign(ContactInquiry $i, ?User $user): void;
    public function changeStatus(ContactInquiry $i, ContactInquiryStatus $to, ?string $note = null): void;
    public function markSpam(ContactInquiry $i, string $reason): void;
    public function markNotSpam(ContactInquiry $i): void;
}
```

Invariants: `submit()` **always** persists a row (spam included), lower-cases the email, strips HTML from
`message`/`subject`, records `ip_address`, `user_agent`, `page_url`, `referrer_url`, `utm_*` and
`filled_in_seconds`, defaults `assigned_to` from `website.inquiry_default_assignee_id`, and returns the
same response shape for spam and non-spam so a bot learns nothing. A non-spam row fires
`ContactInquirySubmitted`; the queued listener calls `InquiryRouter::route()` when
`website.inquiry_auto_route` is true. `markNotSpam()` clears `is_spam`/`spam_reason` and re-queues routing.
`markSpam()` sets `routing_status = NotApplicable` but never deletes an already-created target record —
it records the fact in the activity log instead.

### 6.11 Form Requests and policies

| Form Request | Covers | Notable rules |
|---|---|---|
| `StoreServiceRequest` / `UpdateServiceRequest` | services | `slug` regex + unique ignoring self + not reserved; `starting_price` `nullable, decimal:0,2, min:0, max:9999999999999.99`; `features.*` max 150; `technology_ids.*` `exists:technologies,id`; image rules from §6.6 |
| `StoreTaxonomyRequest` / `UpdateTaxonomyRequest` | the five taxonomies | `name` required unique per model (case-insensitive), `color` hex |
| `StorePortfolioItemRequest` / `UpdatePortfolioItemRequest` | portfolio | `completion_date` `nullable, date, before_or_equal:today`; `project_url` `nullable, url, max:255`; `images.*` image rules, max 20 |
| `StorePortfolioImagesRequest` | gallery upload / attach | `images` array max 20 (new uploads, rules from `MediaService`) **or** `media_asset_ids.*` `exists:media_assets,id` when picking from the library; at least one of the two is required, and the attached total may not exceed 20 |
| `ReorderRequest` | every reorder endpoint | `ids` required array, `ids.*` integer distinct |
| `StoreTeamMemberRequest` / `UpdateTeamMemberRequest` | team | `social_links` array with keys in `SocialPlatform::values()`, each value `url`; `skills.*` max 60; `experience_years` `integer, between:0,60` |
| `StoreTestimonialRequest` / `UpdateTestimonialRequest` | testimonials | `type` enum; `rating` `nullable, integer, between:1,5`; `review` required max 2000; `author_company` required when type is client; `course_name` required when type is student |
| `StoreStudentReviewRequest` / `UpdateStudentReviewRequest` | student reviews | as above plus `video_url` host allowlist |
| `StoreSuccessStoryRequest` / `UpdateSuccessStoryRequest` | success stories | `story` required max 20000 |
| `ModerationRequest` | approve / reject / reset | `reason` `required_if:action,reject,reset, max:255` |
| `BulkModerationRequest` | bulk approve | `ids` array max 200 |
| `StoreBlogPostRequest` / `UpdateBlogPostRequest` | posts | `title` required max 200; `slug` as services; `blog_category_id` `nullable, exists`; `tags.*` string max 40, max 40 items; `content` required; `meta_description` max 255; `published_at` `nullable, date` |
| `ScheduleBlogPostRequest` | schedule | `published_at` `required, date, after:now` |
| `StoreJobOpeningRequest` / `UpdateJobOpeningRequest` | jobs | `employment_type`/`work_mode`/`salary_period` enums; `salary_min`/`salary_max` `nullable, decimal:0,2, min:0` + a closure asserting `Money::compare(min, max) <= 0`; `deadline` `nullable, date, after_or_equal:today` |
| `ChangeJobOpeningStatusRequest` | status | target in `JobOpeningStatus` |
| `PublicJobApplicationRequest` | **public** apply form | the CV rule set (§6.8) verbatim; honeypot + `form_token` present; `email` `required, email:rfc,dns, max:150`; `cover_letter` max 5000 |
| `ChangeApplicationStatusRequest` | pipeline | target in `allowedNext()` of the current status; `reason` required when rejecting; `interview_at` `required_if` interview + `after:now` |
| `AssignRequest` | assign endpoints | `user_id` `nullable, exists:users,id` and the user must hold the module's `view_any` permission |
| `PublicContactRequest` | **public** contact form | `inquiry_type` enum; `service_id` `nullable, exists:services,id` + required when type is service; `course_name` required when type is course and `course_id` is null; `budget` `nullable, in:` the configured options; `message` `required, min:15, max:5000`; honeypot + token |
| `UpdateContactInquiryRequest` | admin notes | `response_notes` max 5000, `status` enum |

Policies (one per model, registered in `AppServiceProvider`): `ServicePolicy`, `ServiceCategoryPolicy`,
`TechnologyPolicy`, `PortfolioItemPolicy`, `PortfolioCategoryPolicy`, `TeamMemberPolicy`,
`TestimonialPolicy`, `StudentReviewPolicy`, `SuccessStoryPolicy`, `BlogCategoryPolicy`, `BlogTagPolicy`,
`BlogPostPolicy`, `JobOpeningPolicy`, `JobApplicationPolicy`, `ContactInquiryPolicy`. Their row rules are
in §9; each one maps `viewAny/view/create/update/delete/restore` onto `{module}.{ability}` and adds the
row conditions — never the reverse.

---

## 7. Routes

### 7.1 Public — `routes/web.php`, controllers in `App\Http\Controllers\Site\`

Every row additionally carries whatever public-site middleware Phase 3 defines (maintenance mode /
`maintenance.public_site_enabled`). `site_module:<slug>` is the Phase-4 public twin of Phase 1's
`module:` middleware: it aborts **404** (not 403) so a disabled module leaves no trace on the public site.

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/services` | `site.services.index` | `site_module:services` |
| GET `/services/{service:slug}` | `site.services.show` | `site_module:services` |
| GET `/portfolio` | `site.portfolio.index` | `site_module:portfolio` |
| GET `/portfolio/{portfolioItem:slug}` | `site.portfolio.show` | `site_module:portfolio` (404 also when `website.portfolio_detail_enabled` is false) |
| GET `/team` | `site.team.index` | `site_module:team` (404 when `website.team_page_enabled` is false) |
| GET `/blog` | `site.blog.index` | `site_module:blog_posts` |
| GET `/blog/category/{blogCategory:slug}` | `site.blog.category` | `site_module:blog_posts` |
| GET `/blog/tag/{blogTag:slug}` | `site.blog.tag` | `site_module:blog_posts` |
| GET `/blog/{blogPost:slug}` | `site.blog.show` | `site_module:blog_posts` |
| GET `/preview/blog/{blogPost}` | `site.blog.preview` | `auth`, `signed`, `can:view,blogPost` — the only way to see a draft or scheduled post, via a signed URL from the admin editor |
| GET `/careers` | `site.careers.index` | `site_module:jobs` (404 when `website.careers_enabled` is false) |
| GET `/careers/{jobOpening:slug}` | `site.careers.show` | `site_module:jobs` |
| POST `/careers/{jobOpening:slug}/apply` | `site.careers.apply` | `site_module:jobs`, `throttle:public-apply` |
| GET `/contact` | `site.contact.index` | — (Phase 3 owns the page content; Phase 4 only adds this route if Phase 3 did not, §13) |
| POST `/contact` | `site.contact.store` | `throttle:public-contact` (+ 404 when `maintenance.contact_form_enabled` is false) |

**Route ordering is binding**: all fifteen rows are registered **before** Phase 3's CMS catch-all
(`/{page:slug}`), and `SlugGenerator::RESERVED` stops a CMS page from ever claiming `blog`, `services`,
`portfolio`, `team`, `careers` or `contact`. Model binding is by `slug` for public routes; the *published*
filter lives in the controller/scope (§9.2), not in the binding, so an admin preview can still resolve the
record.

### 7.2 Admin — `routes/admin.php`, controllers in `App\Http\Controllers\Admin\`

The group already applies `auth`, `active`, `panel:admin` (Phase 1 §8). Each row below adds
`module:<slug>` plus the exact `can:` shown. `{resource}` rows mean the seven standard
`Route::resource` routes with `index → view_any`, `create/store → create`, `show → view`,
`edit/update → edit`, `destroy → delete`.

| Method + URI | Route name | Middleware (module + can) |
|---|---|---|
| `{resource}` `/admin/service-categories` | `admin.service-categories.*` | `module:service_categories`, `can:service_categories.<ability>` |
| POST `/admin/service-categories/reorder` | `admin.service-categories.reorder` | `can:service_categories.edit` |
| `{resource}` `/admin/services` | `admin.services.*` | `module:services`, `can:services.<ability>` |
| POST `/admin/services/reorder` | `admin.services.reorder` | `can:services.edit` |
| POST `/admin/services/{service}/status` | `admin.services.status` | `can:services.change_status` |
| POST `/admin/services/{service}/featured` | `admin.services.featured` | `can:services.change_status` |
| GET `/admin/services/export` | `admin.services.export` | `can:services.export` |
| `{resource}` `/admin/technologies` | `admin.technologies.*` | `module:technologies`, `can:technologies.<ability>` |
| `{resource}` `/admin/portfolio-categories` | `admin.portfolio-categories.*` | `module:portfolio_categories`, `can:portfolio_categories.<ability>` |
| POST `/admin/portfolio-categories/reorder` | `admin.portfolio-categories.reorder` | `can:portfolio_categories.edit` |
| `{resource}` `/admin/portfolio` | `admin.portfolio.*` | `module:portfolio`, `can:portfolio.<ability>` |
| POST `/admin/portfolio/reorder` | `admin.portfolio.reorder` | `can:portfolio.edit` |
| POST `/admin/portfolio/{item}/status` | `admin.portfolio.status` | `can:portfolio.change_status` |
| POST `/admin/portfolio/{item}/featured` | `admin.portfolio.featured` | `can:portfolio.change_status` |
| POST `/admin/portfolio/{item}/images` | `admin.portfolio.images.store` | `can:portfolio.upload` |
| DELETE `/admin/portfolio/{item}/images/{image}` | `admin.portfolio.images.destroy` | `can:portfolio.edit` |
| POST `/admin/portfolio/{item}/images/reorder` | `admin.portfolio.images.reorder` | `can:portfolio.edit` |
| POST `/admin/portfolio/{item}/images/{image}/cover` | `admin.portfolio.images.cover` | `can:portfolio.edit` |
| `{resource}` `/admin/team` | `admin.team.*` | `module:team`, `can:team.<ability>` |
| POST `/admin/team/reorder` | `admin.team.reorder` | `can:team.edit` |
| POST `/admin/team/{member}/status` | `admin.team.status` | `can:team.change_status` |
| POST `/admin/team/{member}/visibility` | `admin.team.visibility` | `can:team.change_status` |
| `{resource}` `/admin/testimonials` | `admin.testimonials.*` | `module:testimonials`, `can:testimonials.<ability>` |
| POST `/admin/testimonials/{testimonial}/approve` | `admin.testimonials.approve` | `can:testimonials.approve` |
| POST `/admin/testimonials/{testimonial}/reject` | `admin.testimonials.reject` | `can:testimonials.reject` |
| POST `/admin/testimonials/bulk-approve` | `admin.testimonials.bulk-approve` | `can:testimonials.approve` |
| POST `/admin/testimonials/{testimonial}/featured` | `admin.testimonials.featured` | `can:testimonials.change_status` |
| `{resource}` `/admin/student-reviews` | `admin.student-reviews.*` | `module:student_reviews`, `can:student_reviews.<ability>` |
| POST `/admin/student-reviews/{review}/approve` · `/reject` · `/featured` · `bulk-approve` | `admin.student-reviews.approve` · `.reject` · `.featured` · `.bulk-approve` | as testimonials, on `student_reviews.*` |
| `{resource}` `/admin/success-stories` | `admin.success-stories.*` | `module:success_stories`, `can:success_stories.<ability>` |
| POST `/admin/success-stories/reorder` · `/{story}/status` · `/{story}/featured` | `admin.success-stories.reorder` · `.status` · `.featured` | `can:success_stories.edit` / `.change_status` |
| `{resource}` `/admin/blog-categories` | `admin.blog-categories.*` | `module:blog_categories`, `can:blog_categories.<ability>` |
| `{resource}` `/admin/blog-tags` | `admin.blog-tags.*` | `module:blog_tags`, `can:blog_tags.<ability>` |
| `{resource}` `/admin/blog-posts` | `admin.blog-posts.*` | `module:blog_posts`, `can:blog_posts.<ability>` + `BlogPostPolicy` row rules |
| GET `/admin/blog-posts/calendar` | `admin.blog-posts.calendar` | `can:blog_posts.view_any` |
| POST `/admin/blog-posts/{post}/publish` | `admin.blog-posts.publish` | `can:blog_posts.change_status` + policy |
| POST `/admin/blog-posts/{post}/schedule` | `admin.blog-posts.schedule` | `can:blog_posts.change_status` + policy |
| POST `/admin/blog-posts/{post}/unpublish` | `admin.blog-posts.unpublish` | `can:blog_posts.change_status` + policy |
| POST `/admin/blog-posts/{post}/archive` | `admin.blog-posts.archive` | `can:blog_posts.change_status` + policy |
| GET `/admin/blog-posts/{post}/stats` | `admin.blog-posts.stats` | `can:blog_posts.view_reports` |
| GET `/admin/blog-posts/{post}/preview-link` | `admin.blog-posts.preview-link` | `can:blog_posts.view` — mints the signed preview URL |
| `{resource}` `/admin/jobs` | `admin.jobs.*` | `module:jobs`, `can:jobs.<ability>` (table `job_openings`, model `JobOpening`) |
| POST `/admin/jobs/{job}/status` | `admin.jobs.status` | `can:jobs.change_status` |
| POST `/admin/jobs/reorder` | `admin.jobs.reorder` | `can:jobs.edit` |
| GET `/admin/job-applications` | `admin.job-applications.index` | `module:job_applications`, `can:job_applications.view_any` |
| GET `/admin/job-applications/{application}` | `admin.job-applications.show` | `can:job_applications.view` + policy |
| PUT `/admin/job-applications/{application}` | `admin.job-applications.update` | `can:job_applications.edit` (internal notes + rating only) |
| DELETE `/admin/job-applications/{application}` | `admin.job-applications.destroy` | `can:job_applications.delete` |
| DELETE `/admin/job-applications/{application}/force` | `admin.job-applications.force-destroy` | `can:job_applications.delete` — removes the CV file too |
| POST `/admin/job-applications/{application}/status` | `admin.job-applications.status` | `can:job_applications.change_status` |
| POST `/admin/job-applications/{application}/assign` | `admin.job-applications.assign` | `can:job_applications.assign` |
| GET `/admin/job-applications/{application}/cv` | `admin.job-applications.cv` | `can:job_applications.download` |
| GET `/admin/job-applications/export` | `admin.job-applications.export` | `can:job_applications.export` |
| GET `/admin/contact-inquiries` | `admin.contact-inquiries.index` | `module:contact_inquiries`, `can:contact_inquiries.view_any` |
| GET `/admin/contact-inquiries/{inquiry}` | `admin.contact-inquiries.show` | `can:contact_inquiries.view` + policy |
| PUT `/admin/contact-inquiries/{inquiry}` | `admin.contact-inquiries.update` | `can:contact_inquiries.edit` |
| DELETE `/admin/contact-inquiries/{inquiry}` | `admin.contact-inquiries.destroy` | `can:contact_inquiries.delete` |
| POST `/admin/contact-inquiries/{inquiry}/status` | `admin.contact-inquiries.status` | `can:contact_inquiries.change_status` |
| POST `/admin/contact-inquiries/{inquiry}/route` | `admin.contact-inquiries.route` | `can:contact_inquiries.change_status` |
| POST `/admin/contact-inquiries/route-pending` | `admin.contact-inquiries.route-pending` | `can:contact_inquiries.change_status` |
| POST `/admin/contact-inquiries/{inquiry}/assign` | `admin.contact-inquiries.assign` | `can:contact_inquiries.assign` |
| POST `/admin/contact-inquiries/{inquiry}/spam` | `admin.contact-inquiries.spam` | `can:contact_inquiries.change_status` |
| POST `/admin/contact-inquiries/{inquiry}/not-spam` | `admin.contact-inquiries.not-spam` | `can:contact_inquiries.change_status` |
| GET `/admin/contact-inquiries/export` | `admin.contact-inquiries.export` | `can:contact_inquiries.export` |

Note: every `export` route is registered **before** the matching `{resource}` show route so `export` is
never swallowed as an id. Soft-delete restore routes (`POST /admin/{resource}/{id}/restore`,
`can:<module>.restore`) are added only for the modules whose preset includes `restore`.

### 7.3 New middleware

| Alias | Class | Behaviour |
|---|---|---|
| `site_module` | `App\Http\Middleware\EnsurePublicModuleEnabled` | `site_module:blog_posts` → `abort(404)` when the module row is disabled; core modules always pass. Identical resolution logic to Phase 1's `EnsureModuleEnabled`, different failure code, because a public 403 tells a visitor the feature exists. **This is decision D26** (F-6.6): a content module may gate **its own** public routes this way, and it never contradicts phase-03 INV-15 — no public route carries `module:` or `can:`, and disabling `website_sections` still cannot take the public site down |

Registered in `bootstrap/app.php` alongside the Phase 1 aliases (additive, §13).

---

## 8. UI screens

All admin views `@extends('layouts.admin')` and live under `resources/views/admin/<kebab-resource>/`;
public views `@extends('layouts.site')` under `resources/views/site/`. Only the Phase 1 `x-ui.*` set plus
the Phase 2 `x-ui.chart` are used — Phase 4 adds **no** new base UI component, only four CMS-specific
wrappers (§8.13). Every list screen carries, without exception: search, the filters listed below, sortable
headers (`x-ui.th-sortable`), pagination with `x-ui.pagination-summary`, skeleton rows while loading, an
`x-ui.empty-state` with a primary action, `x-ui.confirm` on every destructive button, and a toast on every
write. Every screen is responsive (tables scroll in their own container) and correct in light and dark.

**Sidebar — one Website group (F-6.7).** Phase 4's entries are appended to the **single** `Support\Sidebar`
**Website** group that phase-03 §8 declares; Phase 4 creates no second group and **renames no route**. The
placement rule: content *about* the site lives under `/admin/website` (Phase 3's sections, menus, pages, CTA
blocks, FAQs, SEO, media); business entities the site renders live at the top level (`/admin/services`,
`/admin/portfolio`, `/admin/team`, `/admin/testimonials`, `/admin/student-reviews`,
`/admin/success-stories`, `/admin/blog-posts`, `/admin/jobs`, `/admin/job-applications`,
`/admin/contact-inquiries` and the four taxonomies).

### 8.1 Taxonomies — service categories, portfolio categories, blog categories, blog tags, technologies

- **Purpose**: maintain the five small lists that everything else hangs off.
- **Components**: `x-ui.page-header`, `x-ui.card`, `x-ui.table`, `x-ui.badge` (active/inactive),
  `x-ui.modal` (create/edit inline — these are 4-field forms, a full page is wasteful), `x-ui.form.*`,
  `x-ui.confirm`, `x-ui.empty-state`.
- **Filters**: search by name/slug, state (all / active / inactive), trashed toggle where `restore` exists.
- **Columns**: drag handle, image/icon, name, slug, children count (e.g. "12 services"), sort order,
  active toggle, updated at, row actions.
- **Behaviour**: rows are drag-reorderable (Alpine + a single `reorder` POST of the id order); the active
  toggle posts immediately and toasts; **delete opens a reassign dialog** when the term has children,
  listing the count and a required target term (§6.2) — never a silent orphan.
- **Empty state**: "No service categories yet" + *Add category*.

### 8.2 Services

- **Purpose**: the public service catalogue (§11) with prices, technologies, features and SEO.
- **Components**: `x-ui.page-header` (with *New service*), `x-ui.filter-bar`, `x-ui.table`, `x-ui.badge`,
  `x-ui.tabs` on the form, `x-ui.form.*`, `x-ui.modal` for the reorder helper, `x-ui.empty-state`.
- **Filters**: search (name, slug, short description), category, status (`ContentStatus`), featured
  yes/no, technology, trashed.
- **Columns**: drag handle, image thumb, name + slug, category, starting price (`money()`, right-aligned,
  `tabular-nums`, "On request" when null, a lock icon when `price_visible` is false), technologies (up to 3
  chips + "+n"), featured star (toggles inline), status badge, sort order, updated at, actions
  (edit / view public / duplicate-free delete).
- **Form**: four tabs — *Content* (name, slug with an *Edit permalink* toggle, category, short + full
  description, icon, image with preview and remove), *Commercial* (starting price, price note, price
  visible, features repeater with drag sort), *Technologies* (multi-select combobox with inline create
  when the user holds `technologies.create`), *SEO* (seo title/description/keywords, OG image, noindex,
  plus a live Google-style snippet preview and a character counter). Sticky save bar, dirty-state
  navigate-away warning (same Alpine pattern as Phase 2 settings).
- **Empty state**: "Your service catalogue is empty — the website Services section will be hidden until
  you add one" + *Add service*.

### 8.3 Portfolio + gallery manager

- **Purpose**: case studies (§12) with a real gallery.
- **Components**: as services, plus a gallery grid built from `x-ui.card` + `x-ui.icon-button`.
- **Filters**: search (title, client name), category, status, featured, technology, completion year.
- **Columns**: drag handle, cover thumb, title + slug, client name, category, technologies chips,
  completion date (`app_date()`), images count, featured, status badge, actions.
- **Gallery manager** (on the edit screen): drop-zone multi-upload through `MediaService` with per-file
  progress (plus a *Choose from library* action, since the assets are `media_assets` rows), thumbnails in a
  drag-sortable grid, per-attachment `caption` inline and an *Edit alt text* link that writes
  `media_assets.alt_text` (one alt text per image, wherever it is used), a radio-style *Set cover* writing
  `cover_media_id` (exactly one, §6.3), **Detach** behind `x-ui.confirm` (worded as "remove from this
  project" — the file stays in the library), a counter "7 / 20 images", and a clear error list when a batch
  is rejected.
- **Empty state**: "No projects published yet" + *Add project*; the gallery's own empty state is a
  drop-zone with "Drag images here or browse".

### 8.4 Team

- **Purpose**: the public team page (§13) with per-member visibility.
- **Filters**: search (name, designation), department, status, public/hidden, trashed.
- **Columns**: drag handle, avatar (`x-ui.avatar`), name + designation, department, experience, skills (3
  chips + "+n"), social icons row, public toggle, status badge, actions.
- **Form**: *Profile* (name, slug, designation, department, photo, bio, experience years/label),
  *Skills* (tag input), *Links* (one row per `SocialPlatform` with its icon and a URL input, plus portfolio
  URL), *Visibility* (status, is_public, sort order). Hidden members render with a muted row and a
  "Hidden from website" badge.
- **Empty state**: "No team members yet — the About page team section stays hidden" + *Add member*.

### 8.5 Testimonials and student reviews — the moderation queues

- **Purpose**: approve/reject client testimonials (§14) and student reviews (§91) before anything reaches
  the website.
- **Components**: `x-ui.tabs` for the queue states, `x-ui.table`, `x-ui.badge`, `x-ui.modal` (read the full
  review + approve/reject), `x-ui.confirm`, `x-ui.empty-state`, bulk-select checkboxes in the table header.
- **Tabs (with live counts)**: **Pending** (default landing tab, count shown as a rose badge and mirrored
  in the sidebar), Approved, Rejected, Featured, All, Trashed.
- **Filters**: search (author/student name, review text), type (client/student/other), rating (1–5),
  source (admin / public form / panel), course (student reviews), date range, featured.
- **Columns**: bulk checkbox, avatar, author name + company/course, rating stars, review excerpt (2 lines,
  clamped, full text in a modal), source, submitted at, status badge, approved by + approved at, actions
  (Approve, Reject, Feature, Edit, Delete).
- **Behaviour**: Approve is one click + toast; Reject opens a modal **requiring** a reason; bulk approve
  acts on the selection and reports "12 approved, 2 already approved" honestly; the Feature toggle is
  disabled with a tooltip on anything not Approved; a rejected row shows its reason on hover. Each row
  links to the public page anchor once approved.
- **Empty state**: Pending tab → "Nothing waiting for approval" (a calm, positive empty state, no action
  button); All tab → "No testimonials yet" + *Add testimonial*.

### 8.6 Success stories

- **Purpose**: §92 — staff-authored, no approval queue.
- **Filters**: search (student name, headline, company), course, platform, status, featured.
- **Columns**: drag handle, photo, student name + headline, course, achievement, company/platform, video
  icon when `video_url` is set, featured, status badge, actions.
- **Form**: single page with *Story* (student name, photo, course, headline, story rich text, achievement),
  *Outcome* (company, platform, video URL with an inline embed preview), *Publishing* (status, featured,
  sort order).
- **Empty state**: "No success stories yet" + *Add story*.

### 8.7 Blog posts — list, editor, calendar, stats

**List** (`admin/blog-posts/index`)

- **Tabs with counts**: All, Published, **Scheduled**, Drafts, Archived, Mine, Trashed.
- **Filters**: search (title, excerpt, content), category, tag, author, status, featured, date range
  (published_at), has featured image yes/no.
- **Columns**: featured-image thumb, title + slug (with a "Scheduled for 14 Oct, 09:00 — in 3 days"
  sub-line on scheduled rows), category, tags chips, author (`x-ui.avatar` + name), status badge,
  `published_at` (`app_datetime()`), views (right-aligned `tabular-nums`, links to stats), updated at,
  actions (Edit, Preview, Publish/Unpublish, Schedule, Archive, Delete).
- **Empty state**: "No posts yet — write the first one" + *New post*.

**Editor** (`admin/blog-posts/create|edit`) — a two-column layout, not a wizard (the requirement asks for
drafts and scheduling, not a multi-step flow):

- Left: title, slug (locked behind *Edit permalink* once published, with a warning that links break),
  excerpt (counter), content in a rich-text editor, featured image with preview/remove/alt text.
- Right rail (`x-ui.card` stack): **Publish box** — current status badge, author select (only with
  `blog_posts.approve`), *Save draft* / *Publish now* / *Schedule…* (a datetime picker, minimum now + 5
  minutes, timezone label from settings, showing the resolved local time in words), *Unpublish*,
  *Archive*, and a *Copy preview link* button (signed URL). **Taxonomy box** — category select, tag
  combobox with create-on-type. **SEO box** — seo title, meta description, keywords, canonical URL, OG
  image, noindex toggle, snippet preview, and a reading-time + word-count readout.
- Sticky save bar, dirty warning, and a visible "Last saved by X at HH:MM" line.

**Calendar** (`admin/blog-posts/calendar`) — the scheduling surface the requirement's "scheduled posts"
needs: a month grid (Alpine, no new library) with one chip per post on its `published_at` day, coloured by
status (scheduled = amber, published = emerald, draft = slate on its updated day); clicking a chip opens the
post; dragging a **scheduled** chip to another day re-schedules it through `admin.blog-posts.schedule`
(drag disabled without `change_status`, and dropping on a past day is refused with a toast). Month
navigation, a "today" marker, and a legend.

**Stats** (`admin/blog-posts/stats`) — behind `blog_posts.view_reports`: total views, unique-visitor count,
a 30-day `x-ui.chart` line from `BlogViewCounter::dailyTotals()` honouring the Phase 2 date-range selector,
top referrer hosts, and a plain-language note that views are de-duplicated per visitor per day so the
numbers are lower and truer than a raw hit counter.

### 8.8 Careers — jobs

- **Purpose**: §16 job openings.
- **Filters**: search (title, department, location), status, employment type, work mode, department,
  deadline (expired / this week / this month), featured.
- **Columns**: drag handle, title + slug, department, location + work-mode badge, employment type,
  salary range (`money()` min–max, or "Negotiable" when `salary_visible` is false), deadline (rose when
  passed), status badge, **applications count** (links to the filtered application list, with a "3 new"
  rose sub-badge), actions.
- **Form**: *Details* (title, slug, department, location, work mode, employment type, openings count,
  experience), *Compensation* (salary min/max/period/visible), *Content* (description, responsibilities,
  requirements, skills tag input), *Publishing* (status, deadline, featured, sort order, seo title/meta).
  Saving with `status = open` and a past deadline is refused by validation.
- **Empty state**: "No openings — the Careers page will show your 'no current openings' message" +
  *Post a job*.

### 8.9 Careers — applications (the six-stage pipeline)

- **Purpose**: screen candidates (§16) without ever exposing the CV publicly.
- **Components**: `x-ui.tabs` (the pipeline), `x-ui.table`, `x-ui.badge`, `x-ui.modal` (status change),
  `x-ui.avatar`, `x-ui.confirm`, `x-ui.empty-state`, `x-ui.stat-card` row for the funnel.
- **Pipeline tabs with counts**: All · **New** · Reviewing · Shortlisted · Interview · Selected · Rejected
  — in pipeline order, each badge coloured from `JobApplicationStatus::color()`. Above them, a six
  `x-ui.stat-card` funnel for the selected job (or all jobs).
- **Filters**: search (name, email, phone), job opening, status, assigned reviewer, rating, applied-date
  range, "unassigned only".
- **Columns**: avatar initials, applicant name + email, job title, experience, expected salary (`money()`),
  rating stars (inline editable), assigned reviewer, status badge, applied at, actions (View, Download CV,
  Change stage, Assign, Delete).
- **Detail screen**: left = applicant card (contact rows with `tel:`/`mailto:`/WhatsApp links, portfolio
  and LinkedIn as `rel="nofollow noopener"` external links, cover letter in a bordered block), right =
  **pipeline timeline** built from the activity log (who moved the candidate, when, why), internal notes
  textarea with autosave-on-blur, rating, assign control, and a prominent **Download CV** button showing
  the file name, type and size — rendered disabled with a tooltip when the user lacks
  `job_applications.download`.
- **Status change modal**: target stage select limited to `allowedNext()`, a reason field that becomes
  required for Rejected, and interview fields (datetime, mode, location/meeting URL) that appear for
  Interview. The modal states plainly that the candidate is **not** emailed automatically in this phase.
- **Empty state**: per tab — "No applications in Reviewing" ; overall — "No applications yet" with a link
  to share the public job URL (copy-to-clipboard).
- A **Kanban board is deliberately not built here**: the requirement asks for Kanban only for leads (§18)
  and tasks (§22). The stage tabs plus the funnel cover the pipeline without inventing scope.

### 8.10 Contact inquiries — the routing queue

- **Purpose**: §17 — one queue, visibly routed by type.
- **Components**: `x-ui.tabs`, `x-ui.table`, `x-ui.badge`, `x-ui.modal`, `x-ui.stat-card`,
  `x-ui.empty-state`, `x-ui.confirm`.
- **Tabs with counts**: All · **New** · Service · Course · General · Awaiting routing · Routed · Spam ·
  Trashed.
- **Filters**: search (name, email, phone, subject, message), type, status, routing status, assigned user,
  service, source, date range, spam yes/no.
- **Columns**: bulk checkbox, name + email/phone, type badge, subject (excerpt), service or course name,
  budget, source badge, **routing cell** (see below), status badge, assigned avatar, received at, actions.
- **The routing cell is the heart of the screen** — exactly one of:
  *"Lead #123"* (a link, once Phase 5 exists) · *"Course inquiry #45"* · an amber **"Awaiting CRM"** /
  **"Awaiting Institute"** chip with the reason on hover (`target_unregistered` → "Lead module not
  installed yet", `module_disabled` → "Leads module is disabled") · a rose **"Routing failed (2 tries)"**
  chip with the stored error and a *Retry* button · a slate **"—"** for General.
- **Actions**: *Route now* (disabled with a tooltip while the target is unavailable), *Retry routing*,
  *Assign*, *Mark read/responded/closed*, *Mark spam* / *Not spam*, *Delete*, plus a header-level
  **Route all pending** button (`route-pending`) that reports "7 routed, 3 still waiting".
- **Detail screen**: the submission exactly as received (message rendered escaped, never as HTML), a
  metadata panel (IP, user agent, page URL, referrer, UTM, fill time, spam verdict), the routing panel with
  its full history from the activity log, internal response notes, and the assign/status controls.
- **Empty state**: New tab → "No new inquiries"; Spam tab → "No spam caught — the honeypot is doing its
  job"; All → "No inquiries yet" with the public contact URL.

### 8.11 Public screens (`resources/views/site/`)

| Screen | Content and behaviour |
|---|---|
| `site/services/index` | Category filter strip (chips, one active), card grid (`website.services_per_page`), each card = icon/image, name, short description, starting price via `money()` when `price_visible`, technology chips, *Learn more*. Featured services first, then `sort_order`. Empty → the section is hidden entirely rather than printing an empty heading |
| `site/services/show` | Hero (name, category, price, CTA to `/contact?type=service&service={id}` which pre-selects the service in the form), full description, features list, technologies, related portfolio items in the same category, related services, sticky inquiry CTA. Meta tags from the row, falling back to `seo.*` |
| `site/portfolio/index` | Category chips + technology filter, masonry-ish card grid with cover image, title, client name, category, year; lazy-loaded images with width/height set |
| `site/portfolio/show` | Gallery (cover first, click-to-lightbox built with Alpine, keyboard escapable), description, technologies, client name, completion date, project URL button, prev/next project, related items |
| `site/team/index` | Grouped by `department` (ungrouped members last), card per member with photo, name, designation, experience, skills chips and social icon row; bio opens in an Alpine modal. Only `status = published AND is_public = true` |
| `site/blog/index` | Featured post hero, then the card grid (`website.blog_per_page`), sidebar with category list (post counts), tag cloud (active tags only), and a search box; pagination preserves the query string |
| `site/blog/category` · `site/blog/tag` | The same grid with the term name in an H1 and the term's SEO meta; a 404 when the term is inactive |
| `site/blog/show` | Title, author (name + avatar) and `app_date()`, reading time, category/tag links, featured image, sanitised content, share links (no third-party script), and the related-posts block (§6.7.2) — hidden when empty. Fires the view counter (§6.7.1). JSON-LD `BlogPosting` built from the row |
| `site/careers/index` | Openings grouped by department, each row = title, location + work-mode badge, employment type, salary (or "Negotiable"), deadline, *Apply*; when there are no open roles, the configured "no current openings" message plus a speculative-application CTA pointing at `/contact` |
| `site/careers/show` | Full description, responsibilities, requirements, skills, salary, deadline countdown, and the **application form** inline: name, email, phone, whatsapp, city, experience, expected salary, portfolio URL, LinkedIn, cover letter, **CV upload** (accept attribute + a visible "PDF or Word, max N MB" hint), honeypot + token, consent text, submit. Client-side file-size/type feedback is a courtesy only — the server rules (§6.8) are authoritative. A closed/expired job renders the detail read-only with "Applications are closed" |
| `site/contact` (form partial) | Name, email, phone, whatsapp, company, **inquiry type** (Service / Course / General), service select (shown for Service, populated from published services, pre-selectable via `?service=`), course field (shown for Course — a select once Phase 14 exists, a text input until then), budget select from `website.contact_budget_options`, subject, message, honeypot, token, submit. After POST: redirect back with a success toast plus an on-page confirmation block — **identical for spam and non-spam** |
| Testimonials / reviews / success stories | No dedicated routes: they are rendered as sections through the Phase 3 section system, reading `TestimonialFeed` (§8.13). Each section hides itself when its feed is empty |

### 8.12 Dashboard widget additions (Phase 2 `DashboardRegistry`)

Each is a class in `app/Dashboard/Widgets/` with its module and permission declared, so it disappears when
the module is disabled or the user lacks the permission — no edit to the dashboard controller.

| Widget | Module / permission | Shows |
|---|---|---|
| `NewInquiriesWidget` | `contact_inquiries` / `.view_any` | count of `status = new` in range, split by type, link to the queue |
| `InquiryRoutingBacklogWidget` | `contact_inquiries` / `.view_any` | count of `routing_status IN (pending, failed)` with the reason breakdown — the early warning that leads are piling up |
| `PendingModerationWidget` | `testimonials` / `.view_any` | pending testimonials + pending student reviews, each a link to its queue |
| `NewApplicationsWidget` | `job_applications` / `.view_any` | applications in range, plus the per-stage funnel counts |
| `OpenJobsWidget` | `jobs` / `.view_any` | open openings, and how many close within 7 days |
| `BlogActivityWidget` | `blog_posts` / `.view_any` | published in range, scheduled ahead, drafts |
| `TopViewedPostsWidget` | `blog_posts` / `.view_reports` | top 5 posts by views in range (from `blog_post_views`, not the cached counter) |

### 8.13 The four CMS Blade components Phase 4 adds

| Component | Purpose |
|---|---|
| `<x-cms.seo-fields :model="$model">` | The identical SEO field block used by services, portfolio, blog, jobs and categories — one implementation, one validation contract, with the snippet preview and counters. **It declares no entity column** (F-2.3, D23): it reads through `SeoService::for($model)` and writes **only** through `App\Services\Cms\SeoService::save($model, $data)` into `seo_meta`. The OG-image control is a media picker writing `seo_meta.og_image_media_id` (profile `ImageProfile::Og`); the noindex toggle writes `seo_meta.robots` |
| `<x-cms.image-field name="image" :asset="$model?->imageAsset">` | A **thin wrapper over `MediaService` + Phase 3's media picker** (F-2.4, D24): choose from the library or upload, preview, replace, remove. It posts to `MediaService::store()` and sets a `*_media_id`; it never writes a path column and owns no validation rules of its own |
| `<x-cms.moderation-actions :record="$record">` | Approve / Reject / Feature buttons with the permission checks and the reason modal, shared by testimonials and student reviews |
| `<x-site.contact-form :type="null" :service="null">` | The public form including honeypot, signed token and the type-dependent fields — so Phase 3 can drop the same form onto any CMS page without duplicating the spam protection |

---

## 9. Data isolation

### 9.1 Admin-panel roles

No Phase-4 table is owned by a client, student, teacher or collaborator, so the rule is permission-first
with two genuine row-level scopes. "Global" below still means module-enabled **and** permission-held.

| Role (Phase 1) | Reach | Exact query scoping |
|---|---|---|
| Super Admin | everything | `Gate::before` allows; no scoping (module-disabled still denies) |
| Admin | everything in this phase | global |
| SEO Expert | `blog_*`, `services`, `portfolio`, `team` (read/edit per its grant) | global for content; **neither** `job_applications` **nor** `contact_inquiries` — not even `view` (F-12.4): the two PII modules are outside its grant entirely |
| Digital Marketer | `blog_*`, `contact_inquiries` **without `view_any`** | blog: `BlogPost::visibleTo()` (own posts unless it holds `blog_posts.approve`); inquiries: `contact_inquiries.view` + `.edit` + `.change_status` **only** — so 9.1.2 narrows it to the rows assigned to it, and it never holds `view_logs`, so `ip_address`, `user_agent`, `utm_*` and `filled_in_seconds` are absent from its responses (F-12.4). No `job_applications.*` |
| Sales Executive | `contact_inquiries` | 9.1.2 — in practice it is granted `view` + `assign`, so it sees its own assigned inquiries; `view_logs` is not part of the grant |
| Receptionist | `contact_inquiries` | 9.1.2, `view` only |
| HR | `jobs`, `job_applications` (requested in §13) | **in full**, including `job_applications.view_any` and `.download` — HR is the one role that may see every CV (F-12.4, H7). Jobs: global; applications: 9.1.3 |
| Hiring manager (any role holding `job_applications.view` without `view_any`) | the openings it owns | 9.1.3's per-opening scope: `job_openings.created_by = $user->id`, plus anything assigned to it |
| Support Agent | none of this phase | 403 everywhere here |
| Any custom role | whatever it is granted | the same three rules below — there is no role name anywhere in the code |

**9.1.1 `BlogPost::scopeVisibleTo(User $u)`**

```
if ($u->can('blog_posts.approve')) return $query;          // editor: every post
return $query->where('author_id', $u->id);                  // author: own posts only
```

`BlogPostPolicy`: `view` = `view_any` permission **and** (`approve` ability, or own row, or the post is
published); `update`/`delete` = `blog_posts.edit`/`.delete` **and** (`approve` ability or own row);
`changeStatus` = `blog_posts.change_status` **and** the same ownership test. The index, the calendar and
the stats screen all go through `visibleTo()`, so an author never even counts another author's drafts.

**9.1.2 `ContactInquiry::scopeVisibleTo(User $u)`**

```
if ($u->can('contact_inquiries.view_any')) return $query;   // queue manager: all rows
return $query->where('assigned_to', $u->id);                 // reviewer: only what was assigned
```

A user with neither permission never reaches the route (403 from `can:`). Spam rows are excluded from every
tab except the Spam tab, in the scope, not the Blade. No hidden form field ever carries the owner id.

**The technical / PII block is a third gate (F-12.4).** `ip_address`, `user_agent`, `utm_source`,
`utm_medium`, `utm_campaign`, `referrer_url`, `filled_in_seconds` and the spam verdict are selected **only**
when the user holds `contact_inquiries.view_logs`; for everyone else those columns are left out of the query
and are therefore absent from the response body, not merely hidden in Blade (§8.10's metadata panel
disappears with them). `view_logs` is not in the Digital Marketer, Sales Executive or Receptionist grant.

**9.1.3 `JobApplication::scopeVisibleTo(User $u)`** — the same shape as 9.1.2, widened to a
**per-opening scope** so a hiring manager can work without a blanket grant (F-12.4, H7):

```
if ($u->can('job_applications.view_any')) return $query;              // HR: every application
return $query->where(fn ($q) => $q
    ->where('assigned_to', $u->id)                                    // assigned to me
    ->orWhereHas('jobOpening', fn ($j) => $j->where('created_by', $u->id)));   // openings I own
```

"Openings I own" is `job_openings.created_by` — the column already exists and is indexed (§2.18); no new
column and no new ability is introduced. Additionally: `internal_notes`, `rating` and the CV link are
rendered only to users who pass `JobApplicationPolicy::update()` / `::download()`; a reviewer with `view`
only sees the applicant data and the stage, never the notes of others. CV bytes are served exclusively by
`admin.job-applications.cv`, which re-checks the policy on every request — the file is on the private
`local` disk with no public URL at all (**D21**), so a leaked path is worthless, and every download is
logged as a §107 sensitive access (§10.5).

### 9.2 Public (guest) scopes — the only filter the website is allowed to use

Each model exposes one `scopePublic()`; public controllers may not hand-roll a `where` on status. All of
them exclude soft-deleted rows automatically.

| Model | `scopePublic()` |
|---|---|
| `Service`, `PortfolioItem`, `SuccessStory` | `status = published` |
| `TeamMember` | `status = published AND is_public = true` |
| `Testimonial`, `StudentReview` | `status = approved` (pending and rejected are invisible — no exceptions) |
| `BlogPost` | `status = published AND published_at <= now()` — a `scheduled` row with a past `published_at` is **not** public until the scheduler flips it, so the two sources of truth can never disagree |
| `JobOpening` | `status = open AND (deadline IS NULL OR deadline >= today)` |
| `ServiceCategory`, `PortfolioCategory`, `BlogCategory`, `BlogTag`, `Technology` | `is_active = true` |
| `ContactInquiry`, `JobApplication`, `BlogPostView` | **no public scope exists** — these are never readable from the public site in any way |

Draft and scheduled blog posts are reachable only through `site.blog.preview`, which requires `auth` + a
signed URL + `BlogPostPolicy::view`, and which sends `X-Robots-Tag: noindex` and never counts a view.

### 9.3 Other panels

`routes/collaborator.php`, `student.php`, `teacher.php` and `client.php` gain **nothing** in Phase 4. A
student or client hitting any `/admin/...` route from this phase gets the Phase 1 `panel:admin` 403. The
`student_panel` value of `ContentSource` and `student_reviews.submitted_by_user_id` exist so a later phase
can add self-submission **without a migration** — until then no panel can write to these tables.

---

## 10. Events, notifications, jobs, scheduled tasks

### 10.1 Events (`app/Events/Cms/`)

| Event | Fired by | Payload | Listeners |
|---|---|---|---|
| `ContactInquirySubmitted` | `ContactInquiryService::submit()` after commit, **non-spam only** | `ContactInquiry` | `RouteContactInquiry` (queued), `NotifyStaffOfInquiry` (queued) |
| `ContactInquiryRouted` | `InquiryRouter::route()` on success | `ContactInquiry`, created `Model` | `LogInquiryRouting` (writes the activity entry naming both records) |
| `JobApplicationReceived` | `JobApplicationService::apply()` after commit | `JobApplication` | `NotifyHrOfApplication` (queued) |
| `JobApplicationStatusChanged` | `JobApplicationService::changeStatus()` | application, from, to, reason | `LogApplicationStage` |
| `TestimonialApproved` / `StudentReviewApproved` | `ModerationService::approve()` | record | `FlushPublicContentCache` |
| `TestimonialSubmitted` | `ContentSource` other than `admin`/`import` | record | `NotifyStaffOfPendingModeration` (queued) |
| `BlogPostPublished` | `BlogService::publish()` and `PublishScheduledPosts` | `BlogPost`, `bool $viaScheduler` | `NotifyAuthorOfPublication` (queued, only when `$viaScheduler`), `FlushPublicContentCache`, `PingSitemap` (a no-op until Phase 3 provides the sitemap registry) |

### 10.2 Notifications (`app/Notifications/Cms/`) — `database` + `mail`

Phase 22 owns the notification centre UI; Phase 4 only writes rows through the standard Laravel
notification system so they appear there for free (§97). Every mail view uses the branded layout and the
saved SMTP settings from Phase 2.

| Notification | Channels | Recipients | Content |
|---|---|---|---|
| `NewContactInquiryNotification` | database, mail | users holding `contact_inquiries.view_any`, plus every address in `website.contact_notify_emails` (mail only, via `Notification::route`) | type, name, subject, the first 200 chars, and a deep link to the inquiry |
| `NewJobApplicationNotification` | database, mail | users holding `job_applications.view_any`, plus `website.careers_notify_emails` | job title, applicant name, experience, deep link. **The CV is never attached to an email** |
| `PendingModerationNotification` | database | users holding `testimonials.approve` / `student_reviews.approve` | what is waiting, with a link to the queue |
| `PostPublishedNotification` | database, mail | the post's `author_id` | "Your scheduled post went live" + the public URL |

No notification is ever sent to the member of the public who submitted a form in this phase (no
auto-responder is specified in the requirement — §12 Q5 raises it).

### 10.3 Queued jobs (`app/Jobs/Cms/`)

| Job | Queue | Behaviour |
|---|---|---|
| `RouteContactInquiry` | `default`, `tries = 3`, backoff 60/300/900 | calls `InquiryRouter::route()`; a `Pending` outcome is a **success**, not a failure, so nothing lands in `failed_jobs` just because a later phase is missing |
| `RecordBlogPostView` | `default`, `tries = 1`, `dispatchAfterResponse()` | one `BlogViewCounter::record()` call; idempotent, swallows the duplicate-key case |
| `DeliverCmsNotification` | handled by Laravel's own notification queueing | — |

### 10.4 Scheduled tasks (`routes/console.php`)

| Command | Schedule | Guarantees |
|---|---|---|
| `blog:publish-scheduled` | `everyMinute()->withoutOverlapping(5)->runInBackground()` | publishes every due `scheduled` post exactly once, clears a backlog in one pass, touches nothing else (§6.7.3) |
| `inquiries:route-pending` | `hourly()->withoutOverlapping(10)` | retries `pending`/`failed` routing; this is what drains the backlog the moment Phase 5 or Phase 15 registers its target |
| `careers:close-expired` | `dailyAt('00:10')` | closes `open` openings past their deadline and stamps `closed_at` |
| `model:prune --model=BlogPostView` | `dailyAt('02:30')` | enforces `website.blog_view_prune_days`; `views_count` is never reduced by pruning (the counter is the lifetime total, documented in the stats screen) |

### 10.5 Activity-log entries this phase must write (§106, §107)

| Action | Carries |
|---|---|
| content created / updated / deleted / restored (every model here) | old and new values for changed attributes, module slug, IP, device |
| `slug_changed` | old slug → new slug, and whether the record was already published |
| status change (service, portfolio, team, story, post, job) | old → new status, plus the reason when one was given |
| moderation approve / reject / reset | old → new status, the reason, the actor |
| review text edited | old → new review body (a moderator silently rewriting a testimonial must be traceable) |
| application stage change | old → new stage, reason, interview slot |
| CV downloaded | application id, actor, IP, device — a PII access trail. This row is part of the **§107 sensitive-access set** and stays there (F-12.4); the file itself is never attached to an email and never leaves the private disk (D21) |
| inquiry technical block viewed | when a `contact_inquiries.view_logs` holder opens the metadata panel: inquiry id, actor, IP — the same PII trail as a CV download (F-12.4) |
| inquiry routed / routing failed | target key, created record class + id, or the error |
| inquiry marked spam / not spam | reason |
| bulk approve / reorder | one summary entry with the affected count and ids |

---

## 11. Acceptance tests

`tests/Feature/Cms/...` unless stated. Phase 4 is not done until every one passes.

**Schema and slugs**

1. `migrate` runs clean and **every** Phase-4 migration rolls back cleanly in reverse order; no FK is
   created against a table that does not exist (asserts §2.1).
2. Creating two services named "Web Development" yields slugs `web-development` and `web-development-2`.
3. Soft-deleting `web-development` and creating a third service with that name yields
   `web-development-3` — the trashed slug is still reserved and the unique index never fires.
4. A submitted slug of `admin`, `blog` or `0` is rejected by validation with a field error.
5. A title of only emoji produces a non-empty fallback slug and saves.
6. Changing a published post's slug writes a `slug_changed` activity entry holding the old and new value.

**Services, portfolio, team**

7. A user with no `services.*` permission gets 403 on index, create, store, edit, update, destroy,
   reorder, status and export; granting exactly `services.view_any` makes index 200 and store still 403.
8. `starting_price` of `1234567.89` round-trips from the form to the DB to the rendered page with no
   precision loss, is stored in a `decimal(15,2)` column, and renders through `money()` with the
   configured currency and position; `price_visible = false` hides it from the public page while the DB
   keeps the value.
9. A draft service is 404 on `/services/{slug}` and absent from `/services`; publishing makes both 200.
10. Uploading a `.svg` as a service image is rejected; so is a `.php` file renamed `.jpg` (detected MIME),
    and a 9 MB image.
11. Attaching 3 images to a portfolio item creates 3 `portfolio_item_media` rows and sets the first as
    `cover_media_id`; `setCover()` on the third rewrites `cover_media_id` and leaves exactly one cover;
    `setCover()` with an asset that is not attached is 422; detaching the cover promotes the next attached
    asset by pivot `sort_order`, and detaching the last one nulls `cover_media_id`.
12. Reordering gallery images with a `media_asset_id` not attached to this item is refused (422) and changes
    nothing; attaching the same asset twice hits `uq_pim` and creates no second row.
13. Force-deleting a portfolio item detaches every `portfolio_item_media` row and deletes **no** file from
    disk (the binaries are library assets); `MediaService::recountUsage()` then drops their `usage_count`,
    and only then does `MediaPolicy::delete()` allow a human to remove one.
14. A team member with `is_public = false` is absent from `/team` but present in the admin list;
    `social_links` with an unknown key (`myspace`) is rejected 422.

**Moderation**

15. A `pending` testimonial is absent from the public feed; approving it makes it appear and stamps
    `approved_by`/`approved_at`; an activity entry records pending → approved.
16. Rejecting without a reason is 422; rejecting with one stores `rejection_reason` and keeps the row out
    of every public query.
17. `testimonials.view_any` without `testimonials.approve` renders the queue but 403s the approve and
    bulk-approve endpoints.
18. `rating` of `0` and of `6` are both rejected; `null` is accepted and renders no stars.
19. Bulk-approving 5 ids of which 2 are already approved reports 3 newly approved and leaves the other 2
    untouched (idempotent).
20. Featuring a `pending` review is refused 422.

**Blog**

21. `views_count` equals `count(blog_post_views)` after every scenario in tests 22–24.
22. The same visitor (same IP + UA) loading a post 5 times in one day produces **one** view row and
    `views_count = 1`; a different UA produces a second; the same visitor the next day produces a third.
23. A request carrying `Sec-Purpose: prefetch`, and a request from a user who can edit the post, each add
    no view row at all.
24. Two concurrent `RecordBlogPostView` jobs for the same visitor/post/day insert one row; the loser does
    not throw and does not increment the counter.
25. A `draft` and a `scheduled` post both 404 publicly; the signed preview URL renders for a permitted
    user, 403s for a user without `blog_posts.view`, and 404s once the signature is tampered with.
26. `blog:publish-scheduled` publishes a post due 1 minute ago, leaves a post due in 1 hour alone, and
    running it twice in a row publishes nothing twice; `published_at` is not rewritten; the author
    receives a `PostPublishedNotification`.
27. A scheduler outage of 3 hours, then one command run, publishes all 4 overdue posts in that pass.
28. `schedule()` with a past datetime is rejected 422 by `ScheduleBlogPostRequest`.
29. `related()` returns same-category published posts first, never the post itself, never a draft, fills
    from shared tags when the category is thin, respects `website.blog_related_count`, and returns an
    empty collection (rendering no block) on a site with one post.
30. An author holding `blog_posts.create` + `.edit` can edit its own post and gets 403 on another
    author's; granting `blog_posts.approve` makes both 200 and the index count rises accordingly.
31. `syncTags(['Laravel','laravel',' LARAVEL '])` creates exactly one tag and attaches it once.
32. The blog index issues a bounded number of queries with 30 posts, each having a category and 5 tags
    (assert with `DB::listen`) — no N+1.

**Careers**

33. Applying stores the row, puts the CV on the `local` disk, and `Storage::disk('public')` contains no
    copy; the stored filename is a ULID, not the uploaded name.
34. A `.php` renamed `.pdf`, a `.exe`, and a file larger than the effective limit are each rejected with a
    field error and nothing is written to disk or the DB.
35. A second application with the same email to the same opening returns a validation error on `email`
    (not a 500) and creates no second row.
36. Applying to a `draft`, `closed` or past-deadline opening is 422 and creates nothing;
    `website.careers_enabled = false` makes `/careers` and the POST 404.
37. `GET /admin/job-applications/{id}/cv` 403s without `job_applications.download`, 200s with it, sends
    `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`, and writes a CV-download
    activity entry; no other route can reach the file.
38. Stage transitions: `new → interview` is refused 422 (not in `allowedNext()`), `new → reviewing` is
    allowed, `→ rejected` without a reason is refused, `→ interview` without a future `interview_at` is
    refused; every accepted transition writes an activity entry with old and new values.
39. `salary_max` lower than `salary_min` is rejected; both round-trip as `decimal(15,2)`;
    `salary_visible = false` renders "Negotiable" publicly and never emits the numbers in the HTML.
40. `careers:close-expired` closes an `open` opening whose deadline was yesterday, stamps `closed_at`, and
    leaves a deadline-less opening open.
41. Soft-deleting an application keeps the CV file; force-deleting removes it; force-deleting the opening
    through `purge()` removes every application file.

**Contact routing**

42. A `general` inquiry is stored with `routing_status = not_applicable` and no target is attempted.
43. With **no** target registered (the Phase-4 shipping state), a `service` inquiry is stored with
    `routing_target = crm_lead`, `routing_status = pending`, `routing_error = target_unregistered`, the
    admin queue lists it, the **Route now** button is disabled, **no exception is thrown and
    `failed_jobs` stays empty**.
44. Registering `FakeInquiryTarget` and running `inquiries:route-pending` routes the backlog: the inquiry
    becomes `routed` with `routed_type`/`routed_id`/`routed_at` set and a `ContactInquiryRouted` activity
    entry naming both records.
45. Calling `route()` twice on the same inquiry creates exactly **one** target record (idempotency), and a
    second inquiry cannot claim the same target row (the `uq_contact_inquiry_routed_target` insert fails
    and that inquiry stays `pending`).
46. A target whose `isAvailable()` is false (module disabled) leaves the inquiry `pending` with
    `routing_error = module_disabled`; re-enabling the module and re-running the command routes it.
47. A target that throws leaves the inquiry intact, increments `routing_attempts`, stores the message, and
    flips to `failed` on the third attempt; the inquiry is still fully visible and retryable.
48. `website.inquiry_auto_route = false` leaves a new inquiry `pending` with no target attempt; the manual
    **Route now** endpoint then routes it, and 403s for a user without `contact_inquiries.change_status`.
49. A `course` inquiry submitted before Phase 14 stores `course_name` free text with `course_id` null and
    still routes (to the fake course target) carrying the name.

**Spam, rate limiting, isolation, UI**

50. A filled honeypot returns the normal success response, stores the row with `is_spam = true` and
    `spam_reason = honeypot`, fires **no** event, sends **no** notification and is never routed.
51. Submitting faster than `website.contact_min_submit_seconds`, with a missing/forged `form_token`, and
    with a blocklisted word each produce the matching `spam_reason`.
52. The 6th contact submission within a minute from one IP returns 429 rendered in the site layout; the
    4th application from one IP within an hour returns 429.
53. "Not spam" on a spam row clears the flags and re-queues routing, which then succeeds.
54. A message containing `<script>alert(1)</script>` is stored without tags and rendered escaped on the
    admin detail screen; a rich-text service description containing `onerror=` and an `<iframe>` loses both
    on write.
55. `ContactInquiry::visibleTo()`: a user with only `contact_inquiries.view` sees exactly the rows assigned
    to it and 404/403s on another row's detail; spam rows never appear outside the Spam tab.
56. `JobApplication::visibleTo()`: the same assertion on `assigned_to`, and `internal_notes` are absent
    from the HTML for a reviewer without `edit`.
57. Disabling the `blog_posts` module 403s every admin blog route (Super Admin included) and makes
    `/blog`, `/blog/{slug}`, `/blog/category/{slug}` return **404**; re-enabling restores them with row
    counts unchanged.
58. A Student, Teacher, Client and Collaborator each get 403 on every Phase-4 admin route.
59. Every index and form screen renders in light and dark with no console error, and the public pages pass
    an HTML-structure smoke test (one `h1`, `alt` on every image, meta title/description present).
60. Deleting a category with 5 services is refused; with `reassign_to` it moves all 5 and then
    soft-deletes the category; reordering writes `sort_order` 1..n and one activity entry.

---

## 12. Risks and open questions

### 12.1 Risks and their mitigations

| # | Risk | Mitigation in this contract |
|---|---|---|
| R1 | **A table named `jobs` would collide with Laravel's database queue** (`jobs`, `job_batches`, `failed_jobs`), which this project uses — a migration would appear to work and then corrupt queue processing | The table is `job_openings` (§2.18); only the module slug, URI and route names say "jobs". The first migration must assert `Schema::hasTable('jobs')` belongs to the queue and never touch it |
| R2 | Changing a slug after publication breaks every external link and search result | The slug is auto-derived only before first publish; editing it afterwards is behind an explicit toggle, warned in the UI and written to the activity log. A proper 301 redirect table is requested from Phase 3 (§13) rather than improvised here |
| R3 | Phase 3 and Phase 4 both touch `routes/web.php`, the public layout and the SEO/sitemap surface | §7.1 fixes route ordering and the reserved-slug list; §13 lists every hand-off. The two phases must not be built concurrently |
| R4 | `unique(job_opening_id, email)` also blocks a **soft-deleted** application, so a rejected candidate cannot re-apply to the same opening | Documented behaviour, surfaced as a friendly validation message; an admin with `job_applications.delete` can force-delete to free the address. Revisit only if the client asks for re-application |
| R5 | A deferred link id (`client_id`, `student_id`, `course_id`) can point at a row a later phase hard-deletes | Every displayed field is a snapshot column; all reads use `?->`; no public page joins the missing table (§2.1) |
| R6 | `views_count` can drift from `blog_post_views` if rows are pruned or deleted by hand | The counter is defined as the **lifetime** total and labelled as such on the stats screen; pruning never decrements it; test 21 asserts equality within the retention window |
| R7 | ~~Hand-written HTML sanitisation is the classic source of stored XSS~~ **Closed (F-2.5, D25).** Phase 4 writes no sanitiser: it calls Phase 3's `App\Support\RichText::sanitize()` over `mews/purifier`, on write **and** on render (§6.9). `HtmlSanitizer` is deleted, SVG uploads stay banned by `MediaService`, and SEC-05 has exactly one answer | — |
| R8 | Honeypot + rate limiting will not stop a determined, targeted bot | Layered signals with a score (§6.9), everything recorded for tuning (`spam_reason`, `filled_in_seconds`), and spam stored rather than dropped so a false positive is recoverable. Adding a captcha later needs no schema change |
| R9 | A CMS page slug could shadow `/blog` or `/services` | `SlugGenerator::RESERVED` plus Phase 4 routes registered before the catch-all (§7.1) |
| R10 | The calendar drag-to-reschedule is the only non-trivial JS in this phase | It is additive: the Schedule button in the editor does the same job, so a JS failure degrades rather than blocks |
| R11 | `applications_count` / `views_count` are caches, like the collaborator wallet | Both are re-derivable by a single count query; a `php artisan cms:recount` style fix can be added in Phase 24 if drift is ever observed. No money depends on either |

### 12.2 Open questions (defaults assumed, nothing blocking)

| # | Question | Default assumed |
|---|---|---|
| Q1 | The Phase 1 `Ability` enum has no `publish` or `route` case. Phase 4 maps **publish/schedule** and **Route now** onto `change_status`. Confirm, or add the cases in Phase 1? | Use `change_status` (no Phase-1 edit) |
| Q2 | Role grants: the Phase 1 seeder gives HR no `jobs.*` / `job_applications.*`, and does not say whether SEO Expert or Digital Marketer is an **editor** (`blog_posts.approve`) or only an author | HR gets careers; Digital Marketer and SEO Expert get author-level blog access (no `approve`); Admin/Institute Manager get editor level. Requested in §13 |
| Q3 | Should service and portfolio categories be one shared taxonomy? | Two separate tables, consistent with `course_categories` / `blog_categories`. Merging later would be a data migration, so it is worth answering before build |
| Q4 | May a student submit their own review from the student panel (§91 does not say)? | No self-submission in Phase 4: staff entry only. The columns (`source`, `submitted_by_user_id`) are ready so a later phase needs no migration |
| Q5 | Should the public receive an auto-reply email after a contact submission or a job application? | No auto-reply (not in the requirement). If wanted, it is one notification class plus two settings keys |
| Q6 | Blog comments? | Not built — §15 lists categories, tags, posts, authors, drafts, scheduling, featured image, SEO, views and related posts, and no comments |
| Q7 | ~~Adopt a maintained HTML purifier package, or keep the in-house allowlist?~~ **Answered: adopt the package.** | `App\Support\RichText::sanitize()` over `mews/purifier` (Phase 3, D25). No in-house `HtmlSanitizer` — F-2.5 |
| Q8 | ~~Will Phases 5 and 15 reuse `InquirySource` instead of declaring `LeadSource`?~~ **Answered: reused.** | Phase 4 owns the eleven cases; `LeadSource` and `CourseInquirySource` are deleted and phases 5 and 14-17 cast to `App\Enums\InquirySource` (F-5.3). No longer a request |
| Q9 | Which rich-text editor? Phase 3 presumably picks one for CMS page content; Phase 4 must use the same one | Whatever Phase 3 bundles via npm (no CDN, per the asset pipeline); if Phase 3 chose none, Phase 4 uses a minimal bundled editor and documents it |
| Q10 | No virus scanning is available for CV uploads on the target stack | Accepted: private disk, never executed, never served inline, strict MIME + extension allowlist |
| Q11 | `website.contact_budget_options` defaults are PKR-shaped strings ("Under 50,000") | Keep, editable in settings; the chosen label is stored verbatim so changing the list never rewrites history |

---

## 13. Requests to other phases

Phase 4 owns every column it creates. The items below are what it needs **from** tables other phases own.

**Phase 1 — foundation (additive, no redesign)**

| Request | Why |
|---|---|
| `bootstrap/app.php` middleware alias `site_module` → `EnsurePublicModuleEnabled` | a disabled module must 404 on the public site, not 403 (§7.3) |
| `RoleSeeder`: HR gains `jobs.*` and `job_applications.*` | the requirement puts hiring with HR; Phase 1 §5 currently grants HR neither |
| `RoleSeeder`: pin `blog_posts.approve` to Admin and Institute Manager only; SEO Expert and Digital Marketer stay authors | makes the §9.1.1 editor/author split real instead of accidental (Q2) |
| `PermissionRegistry`: accept the four new slugs and the pinned ability sets of §4 | the registry is the single source of truth and lives in Phase 1 |

**Phase 2 — settings**

| Request | Why |
|---|---|
| `SettingsRegistry`: the new `website` group exactly as §5 | definitions live in code in the Phase 2 file; Phase 4 only supplies the field list |

**Phase 3 — public website CMS**

Everything in this block already exists in phase-03: Phase 4 **reuses** it and creates no alternative.

| Request | Why |
|---|---|
| CMS catch-all page route registered **after** the Phase 4 public routes, and `SlugGenerator::RESERVED` honoured by the page slug validator | otherwise a page slug can shadow `/blog` or `/services` (R9) |
| `App\Support\SitemapRegistry::register(string $key, SitemapUrlProvider $p)` — Phase 4 registers **one provider per entity** (`services`, `portfolio`, `blog`, `blog_categories`, `blog_tags`, `jobs`, `team`) and **never edits `SitemapService`** (D23) | §105 sitemap support, with the per-provider counts in `sitemap_generations.providers` |
| `seo_meta` + `App\Services\Cms\SeoService` (`for()`, `save()`, `completeness()`) as the **only** SEO store — Phase 4 adds no SEO column to any of its 20 tables and its seven public entities declare `morphOne(SeoMeta::class, 'seoable')` (**D23**, F-2.3) | §105 is one feature, not seven; the SEO screen and the sitemap must be able to see the whole site |
| `media_assets` + `App\Services\Cms\MediaService` + `ImageProfile` as the **only** image pipeline; `MediaService::recountUsage()` must count `portfolio_item_media` and the eleven Phase-4 `*_media_id` columns among its FK sources (**D24**, F-2.4) | one library, one derivative pipeline, one usage count, one delete guard — Phase 4 ships no uploader (§6.6) |
| `App\Support\RichText::sanitize()` over `mews/purifier`, applied on write **and** on render (**D25**, F-2.5) | Phase 4 deletes `HtmlSanitizer`; one security control, one answer for SEC-05 |
| `website_sections` rows for services, portfolio, team, testimonials, student reviews, success stories, blog teaser, careers teaser and **contact** — Phase 4 registers the `contact` section type itself (it owns the form, the table and the routing, F-2.1) | §100 wants each section toggleable and reorderable; Phase 4 supplies the data, Phase 3 owns the toggles |
| A `redirects` table (from path → to path, 301, hit count) in the SEO module | the clean fix for a changed slug (R2) — raised as phase-03 §12.2 Q5, not built in this release |
| Ownership of `GET /contact` page content + a slot for `<x-site.contact-form>` | avoids two competing contact pages (§7.1) |
| `intervention/image` installed (phase-03 §13.4 already requires it) | it is `MediaService`'s derivative engine; Phase 4 depends on it only through that service |
| The chosen rich-text editor bundle (`trix`, phase-03 §8.1) | one editor across the CMS (Q9) |

**Phase 5 — CRM**

| Request | Why |
|---|---|
| `leads.contact_inquiry_id` — nullable FK → `contact_inquiries.id`, `nullOnDelete`, with **`UNIQUE uq_leads_inquiry(contact_inquiry_id)`** | provenance of a website lead, and the index *is* the idempotency guard: the 1062 stops a second lead from the same inquiry (F-3.7). MariaDB treats NULLs as distinct, so manually created leads still stack |
| `leads.service_id` — the existing phase-05 §2.1 column; **no `leads.interested_service_id` is created** (F-3.6) | the service the visitor picked (§17 *service* → §18) maps straight onto the column Phase 5 already has |
| `testimonials.client_id` — add the FK constraint (`nullOnDelete`) | the deferred FK of §2.1 |
| `portfolio_items.client_id` — add the FK constraint (`nullOnDelete`) + the client picker in the portfolio form | §12 *client* becomes a real link |
| Register `App\Support\Inquiry\CrmLeadInquiryTarget implements InquiryTarget` (key `crm_lead`, module `leads`) into **this phase's** `InquiryRouter` using the §6.10.4 mapping, then run `inquiries:route-pending`. Phase 5 ships **no** `CreateLeadFromContactInquiry` listener — one routing path only (F-2.1) | turns every waiting website inquiry into a lead with no data entry, and keeps `crm:import-pending-inquiries` as a duplicate-proof safety net |
| Cast `leads.source` and `clients.source` to `App\Enums\InquirySource`; `LeadSource` is **deleted** (F-5.3 — resolved, not a request) | one source vocabulary across §17, §18 and §86; the values are already identical strings, so no data migration |

**Phase 7 — HR**

| Request | Why |
|---|---|
| `team_members.department_id` — add the FK constraint (`nullOnDelete`) and backfill from the `department` label | §13 *department* becomes a real link |
| `job_openings.department_id` — same | §16 *department*, and it makes department-wise hiring reports possible |
| `team_members.employee_id` — add the FK constraint (`nullOnDelete`) in Phase 7's guarded migration. It is a **source of defaults only** (F-3.13): Phase 7 may pre-fill name, designation and department from `employees`, but publishing a team card stays an explicit CMS act and there is still **no `user_id`** on `team_members` | a public profile and an HR post are two different records (D32); Phase 7's request is satisfied by the column, not by a publish hook |
| `job_applications.employee_id` — add the FK constraint (`nullOnDelete`) in the same guarded migration, and set it when a `selected` candidate becomes an employee (F-3.12) | HR can trace an employee back to the application, the interview trail and the CV |
| `App\Enums\EmploymentType` — Phase 7 is the **owner** of the canonical seven cases (`full_time`, `part_time`, `contract`, `internship`, `temporary`, `consultant`, `freelance`) plus `isSalaried()` and `leaveEligibleByDefault()`; Phase 4 only casts `job_openings.employment_type` to it (F-5.2). Because Phase 4 migrates first, the class file lands with Phase 4 carrying phase-07 §3's list verbatim, and Phase 7 reuses it unchanged | two declarations of one `app/Enums` name is a merge conflict, never a style question (R3) |

**Phase 14 — courses**

| Request | Why |
|---|---|
| `student_reviews.course_id`, `success_stories.course_id`, `contact_inquiries.course_id` — add the FK constraints (`nullOnDelete`) and swap the free-text course field for a picker | §91, §92, §17 |
| Course landing pages consume `StudentReview::public()` and `SuccessStory::public()` filtered by `course_id` | §89–90 asks for reviews and success stories on the course page; Phase 4 supplies the scopes, Phase 14 renders them |

**Phase 15 — admissions and course inquiries**

| Request | Why |
|---|---|
| `course_inquiries.contact_inquiry_id` — nullable FK → `contact_inquiries.id`, `nullOnDelete` (deferred guarded FK) with **`UNIQUE uq_ci_inquiry(contact_inquiry_id)`**; `course_inquiries.idempotency_key` stays as well, because it guarantees something different (F-3.8) | provenance + idempotency, exactly as the lead (§6.10.4) |
| Register `CourseInquiryTarget implements InquiryTarget` (key `course_inquiry`, module `course_inquiries`) into this phase's `InquiryRouter` | completes the §17 routing contract |
| `testimonials.student_id`, `student_reviews.student_id`, `success_stories.student_id` — add the FK constraints (`nullOnDelete`) | the deferred FKs of §2.1 |
| Cast `course_inquiries.source` to `App\Enums\InquirySource`; `CourseInquirySource` is **deleted** (F-5.3 — resolved, not a request) | §86 sources are a subset of this enum |
| Course FAQs are `faqs` rows with `faqable_type = App\Models\Institute\Course`, written through `FaqService::save()` — **no `course_faqs` table** (F-2.2) | Phase 3 owns `faqs`; Phase 4 notes it here because it is the phase that ships the FAQ section type's data contract |
| If self-service reviews are wanted (Q4): a `student_portal.reviews` permission and a panel form writing `source = student_panel`, `status = pending` | no schema change needed — the columns already exist |

**Phase 22 — notifications**

| Request | Why |
|---|---|
| The notification centre must render the four Phase-4 `database` notifications (§10.2) | they are written in the standard format from day one; no column needed |

**Phase 23 — reports and exports**

| Request | Why |
|---|---|
| Content reports read `blog_post_views` (daily totals), `contact_inquiries` (conversion by type and source, routing backlog) and `job_applications` (funnel by stage) | these are the only tables holding that history; no new columns are required |

**Phase 24 — audit manifests**

| Request | Why |
|---|---|
| **Every FK index named in §2 has a row in `tests/Support/index-manifest.php`** — including the ones added by F-9.2 (`testimonials.approved_by`, `testimonials.submitted_by_user_id`, `student_reviews.approved_by`, `student_reviews.submitted_by_user_id`, `portfolio_item_media.created_by`, `job_openings.created_by`, `job_applications.status_changed_by`, `job_applications.employee_id`, `contact_inquiries.service_id`, `contact_inquiries.assigned_to`, `contact_inquiries.read_by`) and the eleven `*_media_id` columns | `audit:manifest --check` is part of this phase's definition of done (D60); an unindexed FK is a table scan on every delete |
| `upload-manifest.php` carries one row per Phase-4 upload field with its disk and permission: every image field is `media_assets` on the `public` disk through `MediaService`, and `job_applications.cv_path` is the private `local` disk behind `job_applications.download` (D21) | one rule per upload field, recorded where the sweep can see it |

---

## Convergence log (2026-09-12)

Applied from [`../design/resolutions.md`](../design/resolutions.md) §7 (apply map row `docs/phases/phase-04.md`).

| Finding | Change made |
|---|---|
| F-2.1 | §2.20 states Phase 4 **owns** `contact_inquiries`, the `contact` section type, `ContactInquirySubmitted` and the §6.10 routing; §6.10.4 names `App\Support\Inquiry\CrmLeadInquiryTarget` and records that Phase 5 ships no `CreateLeadFromContactInquiry` listener; §13 Phase 5 row rewritten to match |
| F-2.3 | SEO columns **deleted** from §2.2, §2.3, §2.6, §2.7, §2.13, §2.16, §2.18; each of the seven public entities gains `morphOne(SeoMeta::class, 'seoable')` + one `SitemapUrlProvider`; §2 preamble rule 1 and §13 Phase 3 row state D23; §8.13 keeps `<x-cms.seo-fields>` but it declares no entity column and writes only through `SeoService::save()`, OG image = `seo_meta.og_image_media_id` |
| F-2.4 | `ImageUploadService` **deleted** (§6.6 now calls Phase 3's `MediaService` with a column→collection→profile table); `portfolio_images` **deleted** and replaced by the pivot **`portfolio_item_media`** (§2.8) with `uq_pim`, `index(media_asset_id)`, `index(created_by)`, no `deleted_at`; eleven public-rendered `*_path` columns became nullable indexed `*_media_id` FKs (`nullOnDelete`); `thumbnail_path` dropped in favour of `media_assets.variants`; §6.3 `PortfolioService` rewritten for the pivot + `cover_media_id`; `<x-cms.image-field>` is now a thin `MediaService` wrapper; §8.3 gallery manager and tests 11-13 follow. `job_applications.cv_path` stays a bare path (private tier) |
| F-2.5 | §6.9 `HtmlSanitizer` **deleted** → `App\Support\RichText::sanitize()` on write **and** on render (D25); R7 closed, Q7 answered "adopt the package"; §13 Phase 3 row added |
| F-3.6 | §6.10.4 maps `contact_inquiries.service_id` → **`leads.service_id`**; the `leads.interested_service_id` request is deleted from §13 |
| F-3.12 | §2.19 adds `employee_id` (unsignedBigInteger, nullable, indexed, deferred FK), §2.1 lists it, §13 Phase 7 row added |
| F-3.13 | §2.9 adds `employee_id` as a **source of defaults only**, keeping "no `user_id` link" and explicit CMS publication; §2.1 and §13 Phase 7 updated |
| F-5.1 | §3 **deletes** the `ContentStatus` declaration (phase-03 owns it) and **deletes `PostStatus` entirely**; `blog_posts.status` casts `ContentStatus` and keeps `scheduled`; the transition map moves to `BlogService::allowedNext(ContentStatus $from)` so phase-03's enum gains no member |
| F-5.2 | §3 **deletes** the `EmploymentType` declaration; `job_openings.employment_type` casts to `App\Enums\EmploymentType` (phase-07 §3, seven cases). Because Phase 4 migrates first the file lands here carrying phase-07's list verbatim — recorded in §3 and §13 Phase 7 |
| F-5.3 | §3 marks Phase 4 the **owner** of `InquirySource`'s eleven cases; `LeadSource` / `CourseInquirySource` deleted; §13 rows and Q8 restated as resolved rather than requested |
| F-6.3 | §5 declares **no group**: its 21 keys are contributed into phase-03's single `website` group ("Website & Forms", sort 75), listed for reference at phase-03 §5.1b |
| F-6.6 | §7.1 / §7.3 and test 57 unchanged; §7.3 now cites **D26** and states why `site_module` does not contradict phase-03 INV-15 |
| F-6.7 | §8 gains the one-Website-sidebar-group rule with the `/admin/website` vs top-level split; no route renamed |
| F-9.1 | §2 preamble cites **D19** for the four link tables and `blog_post_views` instead of describing a local exception |
| F-9.2 | FK indexes added to §2.10 and §2.11 (`approved_by`, `submitted_by_user_id`), `portfolio_item_media.created_by` (§2.8), `job_openings.created_by`, `job_applications.status_changed_by` / `employee_id`, `contact_inquiries.service_id` / `assigned_to` / `read_by`, plus every `*_media_id`; a §13 "Phase 24" block requires a row per index in `tests/Support/index-manifest.php` |
| F-9.3 | §2.20 adds `INDEX (routing_target, routing_status)`; §2.16 adds `INDEX (views_count)` |
| F-12.4 | §4 adds **`contact_inquiries.view_logs`** and the ability semantics for it; §9.1 states the grant per role (Digital Marketer: no `view_any`, no `view_logs`; HR: `jobs.*` + `job_applications.*` in full; SEO Expert: neither module); §9.1.2 makes the technical/PII block query-level; §9.1.3 gains the per-opening scope (`job_openings.created_by`, no new column, no new ability); §10.5 keeps CV download in the §107 sensitive set and adds the metadata-panel trail |
| F-10.1 | This contract claims **no** decision number; §2 preamble cites **D19, D21, D23, D24, D25, D26** and each section cites the relevant one |
