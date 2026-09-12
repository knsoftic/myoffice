# Master data model — 02. Public website & CMS

> Slice owner: this file. Sibling slices own every other domain.
> Sources read **after** the 2026-09-12 convergence/apply stage, so every name below is canonical.

---

## 1. Scope

This slice covers the public website and its content-management back office: the **34 tables** created by
[`../phases/phase-03.md`](../phases/phase-03.md) (14 tables — website sections, section items, menus,
pages, CTA blocks, FAQs, SEO meta, media assets, revisions, sitemap builds) and
[`../phases/phase-04.md`](../phases/phase-04.md) (20 tables — services, technologies, portfolio, team,
testimonials, student reviews, success stories, blog, job openings, job applications, contact inquiries).
It is bound by `CLAUDE.md`, [`../phases/phase-01.md`](../phases/phase-01.md) (RBAC, `PermissionRegistry`,
`Blameable`, `activity_log`), phase-02 (`SettingsRegistry`), the cross-contract decisions in
[`../design/resolutions.md`](../design/resolutions.md) — **D19** (soft-delete categories), **D21** (no
private artefact on the public disk), **D22** (snapshot publish + cache version stamp), **D23** (one SEO
store), **D24** (two media tiers), **D25** (one sanitiser), **D26** (`site_module` gating), **D32**
(work → `users.id`, duty → `employees.id`) — and the findings they close (F-2.1 … F-2.5, F-3.6, F-3.12,
F-3.13, F-5.1 … F-5.3, F-6.3, F-6.6, F-6.7, F-9.1 … F-9.3, F-12.4). No table in this domain touches money
arithmetic, so [`../design/finance-commission-spine.md`](../design/finance-commission-spine.md) binds it
only through the `decimal(15,2)` + `App\Support\Money` rule on the four money columns listed in §2.
**No table in this domain carries `branch_id`** (D11 applies to institute tables; the public website is one
site for the whole company — phase-03 §2 `[D-W3-2]`).

---

## 2. Table inventory

Conventions used in the table below:
`SD` = soft deletes (`deleted_at`) + `created_by` / `updated_by` (nullable FK `users.id`, `nullOnDelete`,
filled by `Blameable`). `append-only` = **no** `deleted_at`, by the category rule **D19** (`CLAUDE.md` §3
block A) — no local decision number is claimed by either contract.
**MONEY** marks a `decimal(15,2)` column read/compared only through `App\Support\Money`.
**DEC** marks a non-money decimal. **No percentage (`decimal(8,4)`) column exists in this domain.**
`deferred FK` = `unsignedBigInteger`, nullable, indexed, **no constraint** — the target table belongs to a
later phase and the constraint arrives in that phase's guarded migration (phase-04 §2.1).

### 2.1 Phase 3 — shared CMS infrastructure (14)

| Table | Owning phase | Purpose | Key columns | Key relationships |
|---|---|---|---|---|
| `website_sections` | 3 | one placed section instance; holds the working draft **and** the published snapshot so the public read is one indexed row, no joins | `section_key` string(64) (registry key, never an enum), `placement` → `SectionPlacement`, `page_id` FK `pages` cascade, `instance_key` string(96) nullable (`UNIQUE uq_ws_instance`), `anchor`, `cta_block_id` FK `cta_blocks` nullOnDelete, `menu_id` FK `menus` nullOnDelete, `content` json (draft), `published_content` json, `content_hash` / `published_hash` char(40), `has_unpublished_changes` **boolean generated STORED**, `is_enabled`, `status` → `ContentStatus`, `sort_order`, `published_at`, `published_by`, `unpublished_reason`, `draft_updated_at`. **SD** | belongsTo `Page`, `CtaBlock`, `Menu`, `User` (publisher/creator/editor); hasMany `WebsiteSectionItem`; belongsToMany `MediaAsset` via `website_section_media` (`role`, `sort_order`); belongsToMany `Faq` via `faq_website_section`; morphMany `CmsRevision` |
| `website_section_items` | 3 | the single repeater table for every section type, discriminated by `group` (hero/about statistics, why-choose-us, history, highlight, link) — `[D-W3-3]` | `website_section_id` FK cascade, `group` string(32), `content` json, `metric` → `StatisticMetric`, `value_mode` (`manual`/`auto`), `manual_value` **DEC decimal(15,2)** (a statistic value, never a float, never money), `media_asset_id` FK nullOnDelete, `is_enabled`, `sort_order`. **SD** | belongsTo `WebsiteSection`, `MediaAsset` |
| `website_section_media` | 3 | **pivot** — one image/video slot of a section; the only store for section imagery (INV-3: JSON never holds a FK) | PK (`website_section_id`, `role`, `media_asset_id`); `media_asset_id` **restrictOnDelete**; `role` string(32) (`hero_image`, `background_image`, `background_video`, `video_poster`, `image_1`, `image_2`, `gallery`), `sort_order`, timestamps. **append-only (history pivot, D19)** | `WebsiteSection` ↔ `MediaAsset` |
| `menus` | 3 | one navigation container bound to one layout slot | `name`, `slug` (`UNIQUE`), `location` → `MenuLocation` (`UNIQUE uq_menus_location` — one menu per slot, `[D-W3-4]`), `description`, `is_active`. **SD** | hasMany `MenuItem`; hasMany `WebsiteSection` |
| `menu_items` | 3 | one navigation link, optionally the child of another; max two levels (INV-6) | `menu_id` FK cascade, `parent_id` self-FK cascade, `label`, `link_type` → `MenuItemLinkType`, `page_id` FK nullOnDelete, `route_name`, `route_params` json, `url` string(500), `anchor`, `linkable_type`/`linkable_id` nullableMorphs (unused in Phase 3), `icon`, `open_new_tab`, `rel_nofollow`, `visibility` → `MenuVisibility`, `is_enabled`, `sort_order`, `depth` tinyint (CHECK `depth <= 1`). **SD** | belongsTo `Menu`, `Page`, `parent`; hasMany `children`; morphTo `linkable` |
| `pages` | 3 | one custom page at its own slug (§101: about, privacy, terms, refund, course policy, arbitrary) | `title`, `slug` string(200) plain `UNIQUE` (**not** composite with `deleted_at` — `[D-W3-5]`, R-5), `layout` → `PageLayout`, `excerpt`, `content` longText (draft), `published_content` longText, `content_hash` / `published_hash`, `has_unpublished_changes` **generated STORED**, `show_banner`, `banner_media_id` FK nullOnDelete, `banner_heading`, `banner_subheading`, `template` (allowlisted Blade view), `status` → `ContentStatus`, `published_at`, `published_by`, `unpublished_reason`, `is_system`, `sort_order`. **SD** | hasMany `WebsiteSection` (placement `page`); belongsTo `MediaAsset` (banner); morphOne `SeoMeta`; morphMany `CmsRevision`; hasMany `MenuItem` |
| `cta_blocks` | 3 | one reusable call-to-action block — referenced, never copied | `key` (`UNIQUE`), `name`, `variant` → `CtaVariant`, `heading`, `subheading`, `description`, `primary_label`/`primary_url`/`primary_style` → `ButtonStyle`/`primary_new_tab`, the same four `secondary_*`, `background_media_id` FK nullOnDelete, `background_color` hex, `status` → `ContentStatus`, `usage_count` cache. **SD** | hasMany `WebsiteSection`; belongsTo `MediaAsset` (background) |
| `faq_categories` | 3 | one FAQ group | `name`, `slug` (`UNIQUE`), `description`, `icon`, `is_enabled`, `sort_order`. **SD** | hasMany `Faq` |
| `faqs` | 3 | one question/answer; **the only FAQ table in the system** — course FAQs are rows here (F-2.2, no `course_faqs`) | `faq_category_id` FK nullOnDelete, `faqable_type`/`faqable_id` nullableMorphs (Phase 14-17 writes `App\Models\Institute\Course`), `question`, `answer` longText (sanitised), `status` → `ContentStatus`, `is_featured`, `sort_order`. **SD** | belongsTo `FaqCategory`; morphTo `faqable`; belongsToMany `WebsiteSection` via `faq_website_section` |
| `faq_website_section` | 3 | **pivot** — the FAQs hand-picked into a FAQ section (used only when the section's `content.source = selected`) | PK (`faq_id`, `website_section_id`), both cascade; `sort_order`, timestamps. **append-only (D19)** | `Faq` ↔ `WebsiteSection` |
| `seo_meta` | 3 | the **one** SEO store for every public target in the whole system (D23); two addressing forms in one table | `seoable_type`/`seoable_id` nullableMorphs (`UNIQUE uq_seo_target`), `route_key` string(100) (`UNIQUE uq_seo_route`, mutually exclusive with the morph via CHECK `chk_seo_target`), `title`, `meta_description`, `meta_keywords`, `canonical_url`, `robots` → `RobotsDirective`, `og_title`, `og_description`, `og_image_media_id` FK nullOnDelete, `og_type`, `sitemap_include`, `sitemap_priority` **DEC decimal(2,1)** (CHECK 0.0–1.0), `sitemap_changefreq` → `SitemapChangeFrequency`, `last_checked_at`. **append-only — no `deleted_at`** (1:1 attribute, history lives in `activity_log`; D19) | morphTo `seoable`; belongsTo `MediaAsset` (ogImage) |
| `media_assets` | 3 | one uploaded original plus its generated derivatives — the **one** public image pipeline (D24) | `disk`, `directory`, `filename` (ULID-based), `original_name`, `mime_type` (from `finfo`, never the request), `extension`, `size_bytes` (CHECK > 0), `width`/`height`, `duration_seconds`, `checksum` char(64) (`UNIQUE`), `collection` → `MediaCollection`, `profile` → `ImageProfile`, `variants` json, `alt_text`, `title`, `caption`, `derivatives_status` → `MediaProcessingStatus`, `derivatives_generated_at`, `failure_reason`, `usage_count` cache. **SD** | belongsToMany `WebsiteSection` (`website_section_media`), `PortfolioItem` (`portfolio_item_media`); hasMany `WebsiteSectionItem`, `Page` (banner), `CtaBlock` (background), `SeoMeta` (ogImage) + the eleven Phase-4 `*_media_id` columns. `MediaPolicy::delete()` refuses while `usage_count > 0` |
| `cms_revisions` | 3 | one content snapshot of any draft/publish entity | `revisionable_type`/`revisionable_id` morphs, `event` → `RevisionEvent`, `snapshot` longText, `content_hash`, `is_published_snapshot` (never pruned), `label`, `reason`, `created_by`, `created_at` only. **append-only — no `deleted_at`, no `updated_at`** (D19) | morphTo `revisionable` (`WebsiteSection`, `Page`) |
| `sitemap_generations` | 3 | one sitemap build (count, bytes, duration, outcome, per-provider counts) | `url_count`, `byte_size`, `duration_ms`, `trigger` string(24) (`manual`/`publish`/`scheduled`), `status` string(16) (`ok`/`failed`), `failure_reason`, `providers` json, `created_by`, `created_at` only. **append-only (D19)** | belongsTo `User` (creator) |

No `preview_tokens` table exists — preview uses Laravel signed URLs plus the session (`[D-W3-12]`).
No `course_faqs`, no `portfolio_images`, no second media or SEO table anywhere in the system.

### 2.2 Phase 4 — the business entities the website renders (20)

| Table | Owning phase | Purpose | Key columns | Key relationships |
|---|---|---|---|---|
| `service_categories` | 4 | taxonomy for services (§11) | `name`, `slug` string(180) `UNIQUE`, `description`, `icon`, `image_media_id` deferred-free FK `media_assets` nullOnDelete, `sort_order`, `is_active`. **SD** | hasMany `Service`; belongsTo `MediaAsset`; morphOne `SeoMeta` |
| `services` | 4 | one sellable service (§11) | `service_category_id` FK nullOnDelete, `name`, `slug` `UNIQUE`, `short_description`, `full_description` longText (sanitised), `icon`, `image_media_id` FK, `starting_price` **MONEY decimal(15,2)** (null = "on request"), `price_note`, `price_visible`, `features` json, `status` → `ContentStatus`, `is_featured`, `sort_order`. **No SEO column (D23).** **SD** | belongsTo `ServiceCategory`, `MediaAsset`; belongsToMany `Technology` via `service_technology`; hasMany `ContactInquiry`; morphOne `SeoMeta` |
| `technologies` | 4 | the tech-stack chip used by services and portfolio | `name`, `slug` `UNIQUE`, `logo_media_id` FK nullOnDelete (`ImageProfile::Logo`), `icon`, `color` hex, `sort_order`, `is_active`. **SD** | belongsToMany `Service`, `PortfolioItem` |
| `service_technology` | 4 | **pivot** | PK (`service_id`, `technology_id`) both cascade; `sort_order`. No timestamps. **append-only (D19)** | `Service` ↔ `Technology` |
| `portfolio_categories` | 4 | taxonomy for portfolio items; identical shape to `service_categories` | `name`, `slug` `UNIQUE`, `description`, `icon`, `image_media_id` FK, `sort_order`, `is_active`. **No SEO column (D23).** **SD** | hasMany `PortfolioItem`; belongsTo `MediaAsset`; morphOne `SeoMeta` |
| `portfolio_items` | 4 | one case study (§12) | `portfolio_category_id` FK nullOnDelete, `title`, `slug` `UNIQUE`, `client_name` (snapshot), `client_id` **deferred FK → Phase 5 `clients`**, `summary`, `description` longText, `technologies_note`, `cover_media_id` FK nullOnDelete (must be one of the item's pivot rows), `project_url`, `completion_date`, `status` → `ContentStatus`, `is_featured`, `sort_order`. **SD** | belongsTo `PortfolioCategory`, `MediaAsset` (cover); belongsToMany `MediaAsset` via `portfolio_item_media`; belongsToMany `Technology` via `portfolio_item_technology`; morphOne `SeoMeta` |
| `portfolio_item_technology` | 4 | **pivot** | PK (`portfolio_item_id`, `technology_id`) both cascade; `sort_order`. No timestamps. **append-only (D19)** | `PortfolioItem` ↔ `Technology` |
| `portfolio_item_media` | 4 | **pivot** — the gallery; replaces the deleted `portfolio_images` (F-2.4) | `portfolio_item_id` cascade, `media_asset_id` **restrictOnDelete**, `sort_order`, `caption` (per-placement only; `alt_text`/`width`/`height`/`size_bytes` stay on `media_assets`), `created_by` only (no `updated_by`, F-9.4); `UNIQUE uq_pim(portfolio_item_id, media_asset_id)`. **append-only (D19)** | `PortfolioItem` ↔ `MediaAsset` |
| `team_members` | 4 | one public team profile (§13) — website content, **not** an account | `name`, `slug` `UNIQUE`, `photo_media_id` FK nullOnDelete, `designation`, `department` (snapshot label), `department_id` **deferred FK → Phase 7 `departments`**, `employee_id` **deferred FK → Phase 7 `employees`, a source of defaults only** (F-3.13), `bio`, `skills` json (max 20), `experience_years` tinyint, `experience_label`, `social_links` json keyed by `SocialPlatform`, `portfolio_url`, `is_public`, `status` → `ContentStatus`, `sort_order`. **No `user_id` (D32).** **SD** | belongsTo `MediaAsset`. **No `morphOne SeoMeta`** — the team page's SEO is the `site.team.index` `route_key` row |
| `testimonials` | 4 | client **and** student testimonials (§14), moderated | `type` → `TestimonialType`, `author_name`, `author_photo_media_id` FK, `author_designation`, `author_company`, `course_name` (snapshot), `rating` tinyint 1–5 (**not a percentage**), `review` text, `review_date`, `client_id` **deferred FK → Phase 5**, `student_id` **deferred FK → Phase 15**, `status` → `ApprovalStatus` (public only when `approved`), `approved_by`, `approved_at`, `rejection_reason`, `is_featured`, `sort_order`, `source` → `ContentSource`, `submitted_by_user_id`, `ip_address`. **SD** | belongsTo `User` ×2, `MediaAsset`; implements `App\Contracts\Cms\Moderatable` |
| `student_reviews` | 4 | institute student reviews (§91), moderated | `student_id` **deferred FK → Phase 15**, `student_name`, `student_photo_media_id` FK, `course_id` **deferred FK → Phase 14**, `course_name`, `rating` tinyint 1–5, `review` text, `video_url` (YouTube/Vimeo hosts only), `status` → `ApprovalStatus`, `approved_by`, `approved_at`, `rejection_reason`, `is_featured`, `sort_order`, `source` → `ContentSource`, `submitted_by_user_id`, `ip_address`. **SD** | belongsTo `User` ×2, `MediaAsset`; implements `Moderatable` |
| `success_stories` | 4 | staff-authored student success stories (§92) — no approval queue | `student_id` **deferred FK → Phase 15**, `student_name`, `photo_media_id` FK, `course_id` **deferred FK → Phase 14**, `course_name`, `headline`, `story` longText, `achievement`, `company_name`, `platform`, `video_url`, `status` → `ContentStatus`, `is_featured`, `sort_order`. **No slug, no detail route.** **SD** | belongsTo `MediaAsset` |
| `blog_categories` | 4 | flat blog taxonomy (no `parent_id` — the requirement asks for no nesting) | `name`, `slug` `UNIQUE`, `description`, `icon`, `image_media_id` FK, `sort_order`, `is_active`. **No SEO column (D23).** **SD** | hasMany `BlogPost`; belongsTo `MediaAsset`; morphOne `SeoMeta` |
| `blog_tags` | 4 | blog tag; `slug` is the de-duplication key used by `syncTags()` | `name`, `slug` `UNIQUE`, `is_active`. **SD** | belongsToMany `BlogPost` |
| `blog_post_blog_tag` | 4 | **pivot** (alphabetical `singular_singular`, `CLAUDE.md` §3) | PK (`blog_post_id`, `blog_tag_id`) both cascade. No timestamps. **append-only (D19)** | `BlogPost` ↔ `BlogTag` |
| `blog_posts` | 4 | one blog post (§15) | `blog_category_id` FK nullOnDelete, `author_id` FK `users` nullOnDelete, `title`, `slug` string(200) `UNIQUE`, `excerpt`, `content` longText (sanitised), `featured_image_media_id` FK, `featured_image_alt` (per-post override of the asset's alt text), `status` → **`ContentStatus`** (`PostStatus` does not exist, F-5.1), `published_at` (future = scheduled go-live), `is_featured`, `reading_minutes`, `views_count` cache (`INDEX`, F-9.3). **No SEO column (D23).** **SD** | belongsTo `BlogCategory`, `User` (author), `MediaAsset`; belongsToMany `BlogTag`; hasMany `BlogPostView`; morphOne `SeoMeta` |
| `blog_post_views` | 4 | one de-duplicated, privacy-preserving view | `blog_post_id` cascade, `visitor_hash` char(64) (HMAC-SHA256 of IP + UA keyed by `APP_KEY` — **the raw IP is never stored**), `viewed_on` date, `user_id` FK nullOnDelete, `referrer_host` (host only), `created_at` only; `UNIQUE uq_blog_post_view_daily(blog_post_id, visitor_hash, viewed_on)`. `Prunable` by `website.blog_view_prune_days`. **append-only (D19)** | belongsTo `BlogPost`, `User` |
| `job_openings` | 4 | one advertised role (§16). **Never named `jobs`** — Laravel's database queue owns that table; the module slug, URI and route names still say `jobs` | `title`, `slug` `UNIQUE`, `department` (snapshot), `department_id` **deferred FK → Phase 7**, `location`, `work_mode` → `WorkMode`, `employment_type` → **`App\Enums\EmploymentType`** (owned by phase-07 §3, F-5.2), `experience_min_years`, `experience_note`, `openings_count`, `salary_min` **MONEY**, `salary_max` **MONEY** (`Money::compare(min,max) <= 0`), `salary_period` string(16) (`monthly`/`yearly`/`hourly`/`project`), `salary_visible`, `description`/`requirements`/`responsibilities` longText, `skills` json (max 30), `deadline`, `status` → `JobOpeningStatus`, `is_featured`, `opened_at`, `closed_at`, `applications_count` cache, `sort_order`. **No SEO column (D23).** **SD** | hasMany `JobApplication` (cascade as last-resort net only — `JobOpeningService::purge()` removes CVs first); morphOne `SeoMeta` |
| `job_applications` | 4 | one candidate application + its six-stage pipeline state (§16) | `job_opening_id` FK **not null** cascade, `applicant_name`, `email` (lower-cased), `phone`, `whatsapp`, `city`, `experience_years`, `expected_salary` **MONEY**, `cover_letter`, `portfolio_url`, `linkedin_url`, `cv_path` **bare path on the private `local` disk — the only bare path in this domain (D21/D24)**, `cv_original_name`, `cv_mime` (detected), `cv_size`, `status` → `JobApplicationStatus`, `status_changed_at`, `status_changed_by`, `rating` tinyint 1–5, `assigned_to` FK `users` (D32 — assignment of work), `employee_id` **deferred FK → Phase 7** (F-3.12), `internal_notes`, `interview_at`, `interview_mode` string(32), `interview_location`, `rejection_reason`, `source` → `InquirySource`, `ip_address`, `user_agent`; `UNIQUE uq_job_application_per_job(job_opening_id, email)`. **SD** | belongsTo `JobOpening`, `User` (`assigned_to`, `status_changed_by`). Observer deletes the CV on `forceDeleted` **only** — a soft delete keeps the file so a restore is lossless |
| `contact_inquiries` | 4 | the §17 public-form record **plus its routing state**. **Phase 4 owns this table** (F-2.1); phases 3, 5, 8-9 and 14-17 reference it and may request additive columns only | `inquiry_type` → `InquiryType` (decides the routing target), `name`, `email`, `phone`, `whatsapp`, `company`, `service_id` **real FK → `services`** nullOnDelete, `course_id` **deferred FK → Phase 14**, `course_name` (snapshot kept forever), `budget` (the chosen label verbatim), `subject`, `message`, `source` → `InquirySource`, `status` → `ContactInquiryStatus`, `is_spam`, `spam_reason`, `routing_status` → `InquiryRoutingStatus`, `routing_target` string(32) (`crm_lead`/`course_inquiry`), `routed_type`/`routed_id` (a lazy morph-like pointer, **no FK** — the target lives in a later phase), `routed_at`, `routing_attempts`, `routing_error`, `assigned_to` FK `users`, `read_at`, `read_by`, `responded_at`, `response_notes`, `page_url`, `referrer_url`, `utm_source`/`utm_medium`/`utm_campaign`, `filled_in_seconds`, `ip_address`, `user_agent`; `UNIQUE uq_contact_inquiry_routed_target(routed_type, routed_id)`. **SD** | belongsTo `Service`, `User` (`assigned_to`, `read_by`); `routedRecord()` returns null when the class does not exist. Referenced by `leads.contact_inquiry_id` (+`uq_leads_inquiry`) and `course_inquiries.contact_inquiry_id` (+`uq_ci_inquiry`), both guarded deferred FKs added by the owning phase |

### 2.3 Cross-cutting facts a reviewer should be able to check in one pass

| Fact | Where it is fixed |
|---|---|
| 34 tables: **24 carry `deleted_at` + `created_by`/`updated_by`; 10 carry none** — `seo_meta`, `cms_revisions`, `sitemap_generations`, `blog_post_views` and the six pivots `website_section_media`, `faq_website_section`, `service_technology`, `portfolio_item_technology`, `portfolio_item_media`, `blog_post_blog_tag`. Of those ten, `cms_revisions`, `sitemap_generations`, `blog_post_views` keep `created_at` only (no `updated_at`) and `portfolio_item_media` keeps `created_by` but no `updated_by` (F-9.4) | D19 / `CLAUDE.md` §3 block A |
| **4 money columns only**: `services.starting_price`, `job_openings.salary_min`, `job_openings.salary_max`, `job_applications.expected_salary` — all `decimal(15,2)`, all formatted through `Money` / `money()`. This domain performs **no** money arithmetic | phase-04 §2 preamble |
| **2 non-money decimals**: `website_section_items.manual_value` `decimal(15,2)` (a statistic, never a float), `seo_meta.sitemap_priority` `decimal(2,1)` (CHECK 0.0–1.0) | phase-03 §2.3, §2.12 |
| **0 percentage columns** — ratings are `unsignedTinyInteger` 1–5, not percentages | phase-04 §2 preamble |
| **2 stored generated columns**: `website_sections.has_unpublished_changes`, `pages.has_unpublished_changes` | INV-4 |
| **7 DB CHECK constraints**, all in Phase 3: `chk_ws_page_placement`, `chk_wsi_value`, `chk_mi_depth`, `chk_mi_parent`, `chk_seo_priority`, `chk_seo_target`, `chk_media_size`. Phase 4 adds none — its equivalents are unique indexes (`uq_pim`, `uq_blog_post_view_daily`, `uq_job_application_per_job`, `uq_contact_inquiry_routed_target`) | phase-03 §2; phase-04 §2 |
| **11 `*_media_id` FK columns in Phase 4** + 4 in Phase 3 (`website_section_items.media_asset_id`, `pages.banner_media_id`, `cta_blocks.background_media_id`, `seo_meta.og_image_media_id`) + 2 pivots — every one of them `nullOnDelete` except the two pivots, which are `restrictOnDelete` | D24, F-2.4 |
| **1 bare path column**: `job_applications.cv_path`, private `local` disk, streamed only by `admin.job-applications.cv` under `job_applications.download` | D21, D24 |
| **12 deferred FK columns** (no constraint until the owning phase's guarded migration), each beside a snapshot column the public site actually renders | phase-04 §2.1 |
| Slug columns are `string(180)`, except `pages.slug` and `blog_posts.slug` at `string(200)`; every slug has a **single-column** `UNIQUE` so a trashed row keeps its slug and the validator names the conflict | phase-03 §2.7 `[D-W3-5]`, phase-04 §2/§6.1 |

---

## 3. Relationship map

```
Page
 |-- WebsiteSection            1-* (page_id, cascadeOnDelete, only when placement = page)
 |-- SeoMeta                   1-1 (morphOne seoable)
 |-- CmsRevision               1-* (morphMany revisionable)
 |-- MenuItem                  1-* (page_id, nullOnDelete; a draft/trashed page hides the item)
 '-- MediaAsset                *-1 (banner_media_id, nullOnDelete)

WebsiteSection
 |-- WebsiteSectionItem        1-* (cascadeOnDelete; group = statistic | why_choose_us | history | highlight | link)
 |    '-- MediaAsset           *-1 (media_asset_id, nullOnDelete)
 |-- MediaAsset                *-* (pivot website_section_media: role, sort_order; restrictOnDelete)
 |-- Faq                       *-* (pivot faq_website_section: sort_order)
 |-- CtaBlock                  *-1 (cta_block_id, nullOnDelete)
 |-- Menu                      *-1 (menu_id, nullOnDelete)
 |-- Page                      *-1 (page_id, cascadeOnDelete)
 '-- CmsRevision               1-* (morphMany revisionable)

Menu
 '-- MenuItem                  1-* (cascadeOnDelete)
      |-- MenuItem             1-* (parent_id, cascadeOnDelete, depth <= 1 enforced by CHECK)
      |-- Page                 *-1 (page_id, nullOnDelete)
      '-- linkable             *-1 (morphTo; unused in Phase 3 - later phases point at Course/Service/BlogCategory)

FaqCategory
 '-- Faq                       1-* (faq_category_id, nullOnDelete)
      |-- faqable              *-1 (morphTo; Phase 14-17 writes App\Models\Institute\Course - no course_faqs table)
      '-- WebsiteSection       *-* (pivot faq_website_section)

CtaBlock
 |-- WebsiteSection            1-* (cta_block_id)
 '-- MediaAsset                *-1 (background_media_id, nullOnDelete)

SeoMeta                        (exactly one row per target: morph OR route_key, never both)
 |-- seoable                   *-1 (morphTo: Page, Service, ServiceCategory, PortfolioItem, PortfolioCategory,
 |                                  BlogCategory, BlogPost, JobOpening, + later Course, Batch, ...)
 '-- MediaAsset                *-1 (og_image_media_id, nullOnDelete)

MediaAsset                     (usage_count is a cache recomputed from every source below)
 |-- WebsiteSection            *-* (website_section_media, restrictOnDelete)
 |-- PortfolioItem             *-* (portfolio_item_media, restrictOnDelete)
 |-- WebsiteSectionItem        1-* (media_asset_id)
 |-- Page                      1-* (banner_media_id)
 |-- CtaBlock                  1-* (background_media_id)
 |-- SeoMeta                   1-* (og_image_media_id)
 '-- the 11 Phase-4 *_media_id columns
      service_categories.image_media_id, services.image_media_id,
      portfolio_categories.image_media_id, portfolio_items.cover_media_id,
      blog_categories.image_media_id, blog_posts.featured_image_media_id,
      technologies.logo_media_id, team_members.photo_media_id,
      testimonials.author_photo_media_id, student_reviews.student_photo_media_id,
      success_stories.photo_media_id

CmsRevision
 '-- revisionable              *-1 (morphTo: WebsiteSection, Page)

ServiceCategory
 |-- Service                   1-* (service_category_id, nullOnDelete; delete refused without reassign_to)
 |-- SeoMeta                   1-1 (morphOne)
 '-- MediaAsset                *-1 (image_media_id)

Service
 |-- Technology                *-* (pivot service_technology, sort_order)
 |-- ContactInquiry            1-* (service_id, nullOnDelete)
 |-- SeoMeta                   1-1 (morphOne)
 '-- MediaAsset                *-1 (image_media_id)

PortfolioCategory
 |-- PortfolioItem             1-* (portfolio_category_id, nullOnDelete)
 '-- SeoMeta                   1-1 (morphOne)

PortfolioItem
 |-- MediaAsset                *-* (pivot portfolio_item_media: sort_order, caption, created_by)
 |-- MediaAsset                *-1 (cover_media_id; must also be one of the pivot rows)
 |-- Technology                *-* (pivot portfolio_item_technology, sort_order)
 |-- SeoMeta                   1-1 (morphOne)
 '-- clients.id                *-1 (client_id - DEFERRED, Phase 5; client_name is the rendered snapshot)

Technology
 |-- Service                   *-* (service_technology)
 |-- PortfolioItem             *-* (portfolio_item_technology)
 '-- MediaAsset                *-1 (logo_media_id)

TeamMember                     (website content; no user_id, no morphOne SeoMeta)
 |-- MediaAsset                *-1 (photo_media_id)
 |-- departments.id            *-1 (department_id - DEFERRED, Phase 7; department is the snapshot label)
 '-- employees.id              *-1 (employee_id - DEFERRED, Phase 7; SOURCE OF DEFAULTS ONLY, never a publish trigger)

Testimonial                    (Moderatable)
 |-- User                      *-1 (approved_by, submitted_by_user_id, both nullOnDelete)
 |-- MediaAsset                *-1 (author_photo_media_id)
 |-- clients.id                *-1 (client_id - DEFERRED, Phase 5)
 '-- students.id               *-1 (student_id - DEFERRED, Phase 15)

StudentReview                  (Moderatable)
 |-- User                      *-1 (approved_by, submitted_by_user_id)
 |-- MediaAsset                *-1 (student_photo_media_id)
 |-- students.id               *-1 (student_id - DEFERRED, Phase 15)
 '-- courses.id                *-1 (course_id - DEFERRED, Phase 14)

SuccessStory                   (staff-authored, no approval queue, no slug, no detail route)
 |-- MediaAsset                *-1 (photo_media_id)
 |-- students.id               *-1 (student_id - DEFERRED, Phase 15)
 '-- courses.id                *-1 (course_id - DEFERRED, Phase 14)

BlogCategory
 |-- BlogPost                  1-* (blog_category_id, nullOnDelete; delete refused without reassign_to)
 |-- SeoMeta                   1-1 (morphOne)
 '-- MediaAsset                *-1 (image_media_id)

BlogPost
 |-- BlogTag                   *-* (pivot blog_post_blog_tag)
 |-- BlogPostView              1-* (cascadeOnDelete; views_count is a cache of count(*))
 |-- User                      *-1 (author_id, nullOnDelete; null renders the company name)
 |-- MediaAsset                *-1 (featured_image_media_id)
 '-- SeoMeta                   1-1 (morphOne)

BlogPostView                   (append-only; unique per post per visitor_hash per day)
 |-- BlogPost                  *-1
 '-- User                      *-1 (user_id, nullOnDelete)

JobOpening
 |-- JobApplication            1-* (cascadeOnDelete - last-resort net; purge() removes CVs first)
 |-- SeoMeta                   1-1 (morphOne)
 '-- departments.id            *-1 (department_id - DEFERRED, Phase 7)

JobApplication
 |-- JobOpening                *-1 (job_opening_id, NOT NULL; also the hiring-manager row scope)
 |-- User                      *-1 (assigned_to, status_changed_by - D32: assignment points at users.id)
 '-- employees.id              *-1 (employee_id - DEFERRED, Phase 7; set when a selected candidate is hired)

ContactInquiry                 (Phase 4 owns it; F-2.1)
 |-- Service                   *-1 (service_id, real FK, nullOnDelete)
 |-- User                      *-1 (assigned_to, read_by)
 |-- courses.id                *-1 (course_id - DEFERRED, Phase 14; course_name is the snapshot)
 |-- routed record             *-1 (routed_type + routed_id, NO FK, UNIQUE - resolved lazily, null-safe)
 '-- referenced BY
      leads.contact_inquiry_id            (Phase 5,     + UNIQUE uq_leads_inquiry  - the idempotency key)
      course_inquiries.contact_inquiry_id (Phase 14-17,  + UNIQUE uq_ci_inquiry)

User
 '-- every table above          1-* (created_by / updated_by / published_by / approved_by /
                                    submitted_by_user_id / status_changed_by / assigned_to / read_by /
                                    author_id - all nullOnDelete)
```

---

## 4. Enums in this domain

All string-backed, all implementing `label(): string` + `color(): string` with a static `options(): array`
(Phase 1 §2). **One declaration per name** — a second declaration is a merge conflict, not a style choice.

### 4.1 Declared by Phase 3 (16)

| Enum | Cases | Cast on |
|---|---|---|
| `ContentStatus` | `draft`, `scheduled`, `published`, `archived` | `website_sections.status`, `pages.status`, `cta_blocks.status`, `faqs.status`, **and (Phase 4, cast only)** `services.status`, `portfolio_items.status`, `team_members.status`, `success_stories.status`, `blog_posts.status` — F-5.1, `PostStatus` does not exist |
| `SectionPlacement` | `home`, `global_header`, `global_footer`, `page` (later phases add cases, never a migration) | `website_sections.placement` |
| `MenuLocation` | `header`, `footer_primary`, `footer_secondary`, `footer_legal`, `mobile` | `menus.location` (one menu per location, `UNIQUE`) |
| `MenuItemLinkType` | `page`, `route`, `section_anchor`, `url`, `none` | `menu_items.link_type` |
| `MenuVisibility` | `all`, `guest`, `auth` | `menu_items.visibility` |
| `PageLayout` | `content`, `sections` | `pages.layout` |
| `RobotsDirective` | `index_follow`, `index_nofollow`, `noindex_follow`, `noindex_nofollow` | `seo_meta.robots` |
| `SitemapChangeFrequency` | `always`, `hourly`, `daily`, `weekly`, `monthly`, `yearly`, `never` | `seo_meta.sitemap_changefreq`; also validates `seo.sitemap_changefreq_default` |
| `CtaVariant` | `banner`, `card`, `inline`, `split`, `full_width` | `cta_blocks.variant` |
| `ButtonStyle` | `primary`, `secondary`, `outline`, `ghost`, `link` | `cta_blocks.primary_style`, `cta_blocks.secondary_style` (also the `link` composite field type) |
| `StatisticMetric` | `manual`, `projects_completed`, `happy_clients`, `students_trained`, `active_courses`, `team_members`, `years_experience` | `website_section_items.metric`; each case declares `module()` + `table()` so an absent module resolves to **null, never 0** (INV-12) |
| `MediaCollection` | `sections`, `pages`, `cta`, `seo`, `faq`, `general` | `media_assets.collection`. Phase 4 maps all of its columns onto `pages`, `general` and `seo` — **it adds no case** |
| `ImageProfile` | `hero`, `banner`, `card`, `thumbnail`, `logo`, `icon`, `og`, `video_poster` | `media_assets.profile`; also the `profile` argument of `MediaService::store()` |
| `MediaProcessingStatus` | `pending`, `processing`, `ready`, `failed`, `skipped` (a non-image is `skipped`, not `failed`) | `media_assets.derivatives_status` |
| `RevisionEvent` | `created`, `draft_saved`, `published`, `unpublished`, `reverted`, `restored` | `cms_revisions.event` |
| `PreviewScope` | `section`, `page`, `placement` | no column — the signed-URL preview parameter (`[D-W3-12]`) |

**No enum exists for `website_sections.section_key`**: section types live in
`App\Support\WebsiteSectionRegistry` (code), so a later phase adds a type with one array entry and one
Blade partial — `[D-W3-9]`.

### 4.2 Declared by Phase 4 (11)

| Enum | Cases | Cast on |
|---|---|---|
| `ApprovalStatus` | `pending`, `approved`, `rejected` (`isPublic()` true only for `approved`) | `testimonials.status`, `student_reviews.status` |
| `TestimonialType` | `client`, `student`, `other` | `testimonials.type` |
| `ContentSource` | `admin`, `public_form`, `client_panel`, `student_panel`, `import` (`requiresModeration()` false only for `admin`/`import`) | `testimonials.source`, `student_reviews.source` |
| `SocialPlatform` | `facebook`, `instagram`, `linkedin`, `x_twitter`, `github`, `youtube`, `tiktok`, `behance`, `dribbble`, `website` | the **allowlist for the keys** of `team_members.social_links` json (unknown keys rejected); no column casts to it directly |
| `WorkMode` | `onsite`, `remote`, `hybrid` | `job_openings.work_mode` |
| `JobOpeningStatus` | `draft`, `open`, `closed`, `filled` (`acceptsApplications()` / `isPublic()` true only for `open`) | `job_openings.status` |
| `JobApplicationStatus` | `new`, `reviewing`, `shortlisted`, `interview`, `selected`, `rejected` + `allowedNext()`, `isTerminal()`, `requiresReason()`, `requiresInterviewSlot()` | `job_applications.status` |
| `InquiryType` | `service`, `course`, `general` + **`routingTarget()`** → `crm_lead` / `course_inquiry` / `null` — the whole §17 routing contract in one method | `contact_inquiries.inquiry_type` |
| `ContactInquiryStatus` | `new`, `read`, `in_progress`, `responded`, `closed` | `contact_inquiries.status` |
| `InquiryRoutingStatus` | `not_applicable`, `pending`, `routed`, `failed` (`needsRetry()` = pending/failed) | `contact_inquiries.routing_status` |
| `InquirySource` | `website`, `facebook`, `instagram`, `tiktok`, `google`, `whatsapp`, `referral`, `walk_in`, `call`, `email`, `other` — **Phase 4 owns all eleven** (F-5.3) | `contact_inquiries.source`, `job_applications.source`; **cast only** by phase-05 (`leads.source`, `clients.source`) and phase-14-17 (`course_inquiries.source`). `LeadSource` and `CourseInquirySource` are deleted |

### 4.3 Cast here, declared elsewhere

| Enum | Owner | Cast on |
|---|---|---|
| `EmploymentType` | **phase-07 §3** — seven cases `full_time`, `part_time`, `contract`, `internship`, `temporary`, `consultant`, `freelance` + `isSalaried()`, `leaveEligibleByDefault()` (F-5.2) | `job_openings.employment_type`. Phase 4 migrates first, so the class file **lands with Phase 4 carrying phase-07's list verbatim**; Phase 7 reuses it unchanged and is the only phase that may extend it |

### 4.4 Deliberately plain strings, not enums

`job_openings.salary_period`, `job_applications.interview_mode`, `contact_inquiries.spam_reason`,
`contact_inquiries.routing_target`, `sitemap_generations.trigger`, `sitemap_generations.status`,
`website_section_items.group`, `website_section_media.role`, `website_sections.section_key`,
`pages.template`, `media_assets.disk`. Each is either a registry-driven key (extended by later phases
without a migration) or a short closed list validated in the Form Request.

---

## 5. Modules, permissions and settings

### 5.1 Module slugs (all `ModuleGroup::Website`, all `is_core = false`)

Permission counts are the **set union** of Phase 1 §4's presets
(`READ`=2, `CRUD`=5, `CRUD_FULL`=7, `APPROVE`=2, `STATUS`=1, `ASSIGN`=1, `FILES`=2, `REPORTS`=3, `LOGS`=1);
duplicates across presets collapse, so `blog_posts` is 13 and not 15.

| Module slug | Declared | Abilities | Permissions | Tables it gates |
|---|---|---|---|---|
| `website_sections` | 1, pinned by 3 | `CRUD` + `STATUS` + `LOGS` | **7** | `website_sections`, `website_section_items`, `website_section_media`, `cms_revisions` (read under `.view_logs`) |
| `menus` | 1, pinned by 3 | `CRUD` + `STATUS` | **6** | `menus`, `menu_items` |
| `pages` | 1, pinned by 3 | `CRUD_FULL` + `STATUS` + `LOGS` | **9** | `pages` (`delete` blocked for `is_system`) |
| `faqs` | 1, pinned by 3 | `CRUD` + `STATUS` | **6** | `faqs`, `faq_website_section` |
| `faq_categories` | **new in 3** | `CRUD` + `STATUS` | **6** | `faq_categories` |
| `seo` | 1, pinned by 3 | `READ` + `edit` + `export` + `LOGS` | **5** | `seo_meta`, `sitemap_generations` (history under `seo.view`). **No `create`/`delete`** — a `seo_meta` row is an attribute of something else |
| `website_cta_blocks` | **new in 3** | `CRUD` + `STATUS` + `LOGS` | **7** | `cta_blocks` (`delete` policy-blocked while `usage_count > 0`) |
| `website_media` | **new in 3** | `READ` + `create` + `edit` + `delete` + `FILES` + `LOGS` | **8** | `media_assets` (`edit` = alt text and caption only) |
| `service_categories` | **new in 4** | `CRUD` + `STATUS` | **6** | `service_categories` |
| `services` | 1, pinned by 4 | `CRUD_FULL` + `STATUS` + `FILES` | **10** | `services` |
| `technologies` | **new in 4** | `CRUD` + `STATUS` + `FILES` | **8** | `technologies`, `service_technology`, `portfolio_item_technology` |
| `portfolio_categories` | **new in 4** | `CRUD` + `STATUS` | **6** | `portfolio_categories` |
| `portfolio` | 1, pinned by 4 | `CRUD_FULL` + `STATUS` + `FILES` | **10** | `portfolio_items`, `portfolio_item_media` |
| `team` | 1, pinned by 4 | `CRUD_FULL` + `STATUS` + `FILES` | **10** | `team_members` |
| `testimonials` | 1, pinned by 4 | `CRUD` + `STATUS` + `APPROVE` + `FILES` | **10** | `testimonials` |
| `student_reviews` | 1, pinned by 4 | `CRUD` + `STATUS` + `APPROVE` + `FILES` | **10** | `student_reviews` |
| `success_stories` | 1, pinned by 4 | `CRUD` + `STATUS` + `FILES` | **8** | `success_stories` |
| `blog_categories` | 1, pinned by 4 | `CRUD` + `STATUS` | **6** | `blog_categories` |
| `blog_tags` | **new in 4** | `CRUD` + `STATUS` | **6** | `blog_tags`, `blog_post_blog_tag` |
| `blog_posts` | 1, pinned by 4 | `CRUD_FULL` + `STATUS` + `APPROVE` + `FILES` + `REPORTS` | **13** | `blog_posts`, `blog_post_views` (`.view_reports`). `.approve` is the *editor* ability — edit/publish **any** author's post |
| `jobs` | 1, pinned by 4 | `CRUD_FULL` + `STATUS` | **8** | `job_openings` (slug/URI/route names say `jobs`; the table never does) |
| `job_applications` | 1, pinned by 4 | `READ` + `edit` + `delete` + `STATUS` + `ASSIGN` + `download` + `export` + `print` | **9** | `job_applications`; `.download` is the **only** path to a CV (D21) |
| `contact_inquiries` | 1, pinned by 4 | `READ` + `edit` + `delete` + `STATUS` + `ASSIGN` + `export` + `print` + `view_logs` | **9** | `contact_inquiries`; `.change_status` includes **Route now**; `.view_logs` (F-12.4) is the only way to see `ip_address`, `user_agent`, `utm_*`, `referrer_url`, `filled_in_seconds` and the spam verdict — without it those columns are **absent from the query**, not merely hidden in Blade |

**23 modules, 183 permissions.** `cms_revisions`, `sitemap_generations`, `website_section_items` and every
pivot get **no module of their own** by design. Sidebar: **one Website group** (F-6.7) — content *about*
the site under `/admin/website`, business entities the site renders at the top level. No route is renamed.

**Portal permissions: none.** The public website is anonymous; the student, teacher, client and
collaborator panels receive no CMS permission in either phase, and `routes/{client,student,teacher,collaborator}.php`
gain nothing.

**Public-route gating:** no public route carries `module:` or `can:`. Disabling `website_sections` 403s the
admin CMS for everyone (Super Admin included) and **never takes the public site down** — the last published
snapshot keeps serving (INV-15). A content module may gate **its own** public routes through `site_module`,
which **404s** (D26, F-6.6).

### 5.2 Settings that change this domain's behaviour

**One group, declared exactly once** by phase-03 §5.1:
`website` → `['label' => 'Website & Forms', 'icon' => 'globe-alt', 'sort' => 75, 'permission' => 'settings.edit']`.
Phase 4 declares **no group** and contributes its keys into this one (F-6.3). **34 keys total** — 13 from
Phase 3, 21 from Phase 4 (but see open point OP-1). Nothing in the group is encrypted; every key is
`is_readonly = false` except `website.menu_max_depth`.

| Keys (13, Phase 3) | Effect |
|---|---|
| `cache_enabled` (bool, true), `cache_ttl_minutes` (1440), `cache_warm_enabled` (true) | public response caching; the version stamp makes a publish invalidate in O(1) regardless of TTL (D22) |
| `preview_ttl_minutes` (120) | lifetime of a signed preview link |
| `image_quality` (82), `image_max_width` (2560), `image_webp_enabled` (true, public), `image_lazy_loading` (true, public) | `MediaService` derivative generation and rendering |
| `menu_max_depth` (2, **readonly**) | visible only; the CHECK `chk_mi_depth` is the enforcement (INV-6) |
| `revision_keep` (20) | non-published `cms_revisions` kept per target; published snapshots are never pruned |
| `hero_video_enabled` (true, public) | global off switch for `background_video` (R-7) |
| `faq_accordion_open_first` (true, public), `show_theme_toggle` (true, public) | public rendering |

| Keys (21, Phase 4) | Effect |
|---|---|
| `services_per_page` (12), `portfolio_per_page` (12), `blog_per_page` (9), `reviews_per_page` (12) — all public, 3–48 | public pagination |
| `blog_related_count` (3, public, 0–6) | 0 hides the related-posts block |
| `blog_view_dedupe_minutes` (1440), `blog_view_prune_days` (90) | `blog_post_views` dedupe window and `Prunable` retention |
| `team_page_enabled`, `portfolio_detail_enabled`, `careers_enabled` (all true, public) | false → that public route **404s** |
| `testimonial_auto_approve` (false) | true creates **staff-entered** rows `approved`; a public or panel submission is **always** `pending` regardless |
| `careers_notify_emails`, `contact_notify_emails` (textarea, empty) | who is mailed on a new application / inquiry |
| `cv_max_mb` (5), `cv_allowed_types` (`pdf,doc,docx`) | the effective CV limit is `min(cv_max_mb, security.max_upload_mb)`; an empty type set is rejected |
| `contact_budget_options` (json, six PKR-shaped labels, public) | feeds the public budget select; the chosen label is stored verbatim in `contact_inquiries.budget` so editing the list never rewrites history |
| `contact_min_submit_seconds` (3), `contact_rate_per_hour` (20), `spam_blocklist` (empty) | `SpamGuard` signals written to `is_spam` / `spam_reason` / `filled_in_seconds`; spam is **stored, never routed, never notified** |
| `inquiry_auto_route` (true), `inquiry_default_assignee_id` (null) | false → every inquiry waits for a manual **Route now**; the assignee gives §9 row scoping an owner |

**4 keys added to the existing `seo` group** (phase-03 §5.2): `seo.robots_txt_mode` (`auto`/`custom`),
`seo.robots_txt_custom`, `seo.sitemap_changefreq_default` (`weekly`), `seo.sitemap_priority_default` (0.5).

**Phase-2 keys consumed, never redefined:** `maintenance.public_site_enabled` (gates every public route),
`maintenance.maintenance_mode` + `maintenance.maintenance_message`, `maintenance.contact_form_enabled`
(gates `POST /contact`), `security.max_upload_mb` (the hard ceiling for every upload here),
`seo.meta_title` / `meta_description` / `meta_keywords` / `canonical_base_url` / `og_image` /
`robots_indexable` / `sitemap_enabled` / `google_analytics_id` / `google_tag_manager_id` /
`facebook_pixel_id` / `google_site_verification`, `company.name` / `tagline` / `founded_year`
(the `years_experience` statistic) / `copyright_text`, `branding.*` (logos, OG image fallback, brand
colours), `contact.*` (incl. `contact.map_embed`, rendered through `RichText::sanitize()` with a
maps-only iframe allowlist), `social.*`, `localization.*` (drives `money()` on prices and salaries).

A public view reads settings **only** through `App\Support\SiteSettings` / `site_setting()`, which throws
`NonPublicSettingException` for any key whose registry definition is not `public => true` (INV-10).

---

## 6. Open points

Nothing below is invented here; each row names the contract section or finding it comes from.

| # | Open point | Source | Status |
|---|---|---|---|
| OP-1 | **Key count disagreement in the `website` settings group.** `resolutions.md` §2.4 and §3.5 (F-6.3) both say **22 Phase-4 keys / 35 total**; phase-03 §5.1b and phase-04 §5 (both post-apply) list and count **21 / 34**. This file states 34 and names the 21. One key is either missing from the phase contracts or over-counted in the resolution | resolutions §2.4, §3.5 F-6.3 vs phase-03 §5.1b, phase-04 §5 | **deferred** — a count, not a name; needs the owner to say which |
| OP-2 | **`contact_inquiries` collaborator-attribution columns are requested but not defined.** phase-08-09 §13.1 (retargeted by F-2.1) asks **Phase 4** for `contact_inquiries.collaborator_id`, `.referral_code`, `.referral_visit_id`; phase-04 §2.20 defines none of them and phase-04 §13 has **no Phase 8-9 block**. Under D37 these would be display snapshots only (no engine reads them, no scope uses them) | phase-08-09 §13.1, F-2.1; phase-04 §2.20 / §13 | **deferred** — not marked as existing in §2 above |
| OP-3 | **Section-type ownership vs table ownership for reviews and stories.** phase-03 §6.1 assigns the `student_reviews` and `success_stories` **section types** to phases 14 / 15, while phase-04 owns the tables, the `public()` scopes, the `TestimonialFeed` and (§8.11, §13) renders them as sections in Phase 4 | phase-03 §6.1 vs phase-04 §8.11, §13 | **deferred** — no table consequence; one of the two must own the registry entry |
| OP-4 | **phase-03 §6.1 names the careers section's data source `jobs`.** The table is `job_openings` (phase-04 §2.18, R1: `jobs` is Laravel's queue table). A registry row reading `jobs` is a naming hazard in exactly the place the warning exists for | phase-03 §6.1 vs phase-04 §2.18 | **deferred** — wording, not schema |
| OP-5 | **`job_applications.job_opening_id` nullability.** resolutions §3.7 F-12.4 describes it as "a nullable `job_applications.job_opening_id` already exists"; phase-04 §2.19 defines it **not null, cascadeOnDelete**. The phase contract is canonical here and §2 above follows it; the per-opening scope works either way | resolutions F-12.4 vs phase-04 §2.19 | **deferred** — resolution prose, not a schema change |
| OP-6 | **Single locale.** No `locale` column on `website_sections`, `pages`, `menu_items`, `faqs` or `seo_meta`. The additive path (a nullable `locale` on those five + the locale in the cache key) is documented, not built | phase-03 R-11, §12.2 Q4 | open question, default "English only" |
| OP-7 | **No redirect table.** A slug change breaks external links; there is no `website_redirects` / `redirects` table. Requested by phase-04 §13 (Phase 3 block) and offered as a follow-up, **not built in this release** | phase-03 §12.2 Q5, phase-04 R2 / §13 | open question, default "not built" |
| OP-8 | **No JSON-LD / image sitemap and no cookie-consent section type**, although the site loads GA, GTM and the Facebook Pixel from `seo.*` | phase-03 §12.2 Q8, Q9 | open question, default "not built" |
| OP-9 | **No blog comments, no public auto-reply** after a contact submission or job application | phase-04 §12.2 Q5, Q6 | open question, default "not built" |
| OP-10 | **Student self-service reviews are not built.** `student_reviews.source` already has the `student_panel` case and `submitted_by_user_id` exists, so enabling it needs a permission and a form, no migration | phase-04 §12.2 Q4, §13 Phase 15 | open question, default "staff entry only" |
| OP-11 | **Service vs portfolio taxonomy stays split** (`service_categories` + `portfolio_categories`), consistent with `blog_categories` / `course_categories`. Merging later is a data migration, so the client should answer before build | phase-04 §12.2 Q3 | open question, default "two tables" |
| OP-12 | **`unique(job_opening_id, email)` also blocks a soft-deleted application**, so a rejected candidate cannot re-apply to the same opening without a force-delete. Accepted and surfaced as a validation message | phase-04 R4 | accepted risk |
| OP-13 | **`media_assets.checksum` is a plain `UNIQUE` on a soft-deleting table**, so a trashed asset keeps the checksum and blocks a re-upload of the identical file until it is restored or purged — the same trade-off that `pages.slug` records explicitly in `[D-W3-5]`, but phase-03 §2.13 does not state the resolution path | phase-03 §2.13 vs §2.7 `[D-W3-5]` | **deferred** — needs one sentence in phase-03 §2.13 |
| OP-14 | **Two caches with no recount command named.** `job_openings.applications_count`, `blog_posts.views_count`, `cta_blocks.usage_count` and `media_assets.usage_count` are re-derivable, and `CtaBlockService::recount()` / `MediaService::recountUsage()` exist — but no artisan command is specified for the first two (phase-04 R11 offers "a `php artisan cms:recount` style fix can be added in Phase 24") | phase-04 R11 | open, no money depends on it |
| OP-15 | **`contact.map_embed` is raw HTML stored in a Phase-2 settings field** and rendered on the public site. Phase 3 sanitises it through `RichText::sanitize()` with a `www.google.com/maps/embed` iframe allowlist; the better long-term shape (store coordinates, build the embed in code) is raised but not adopted | phase-03 §12.2 Q3 | accepted with mitigation |
