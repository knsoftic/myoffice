# RESOLUTIONS — the canonical decisions

**Status: binding.** This file converges the thirteen contracts. Where this file and any phase contract
disagree, **this file wins** and the contract is edited to match. Where this file and
[`finance-commission-spine.md`](finance-commission-spine.md) disagree on anything touching money, the
**spine's guarantee** wins and this file records only the edit that makes the spine's guarantee reachable.
`CLAUDE.md` conventions stay intact everywhere.

Input: [`consistency-audit.md`](consistency-audit.md) (98 findings). Every critical, high and medium
finding that names a table, column, enum, service, permission, module slug or route has exactly one row in
§3 below. Apply agents read **only the rows listed for their file in §7 (Apply map)** plus §2 and §8.

How to read a row: `Edits required` is mechanical. `file §section -> change` means: open that file, find
that section, make exactly that change. "delete", "rename", "retarget" and "add" are literal.
Nothing in §3 is optional and nothing in §3 is a discussion.

Owner-applied files (no agent may edit them): `CLAUDE.md` (text in §5), `DEVELOPMENT_LOG.md` (text in §4
and §6.3), and any file under `app/`, `routes/`, `database/`, `tests/`, `config/`.

---

## Contents

| § | Contents |
|---|---|
| 1 | Global precedence and ownership rules |
| 2 | Ownership maps — tables, enums, services, settings groups, widget keys |
| 3 | The instruction list (one row per finding) |
| 4 | Decision-number registry D17–D60 + the `DEVELOPMENT_LOG.md` §4 paste text |
| 5 | The `CLAUDE.md` §3 paste text (soft deletes, the `files` slug, percentages) |
| 6 | Needs-human items — recommended default + the decision to implement now |
| 7 | Apply map — which findings touch which file |
| 8 | Do not change |
| 9 | Drift fixes (round 2) — the corrections this file took from `consistency-audit-round-2.md` |
| 10 | Drift fixes (round 3) — the corrections this file took from `consistency-audit-round-3.md` |

---

## 1. Global precedence and ownership rules

| # | Rule | Consequence |
|---|---|---|
| R1 | **Earliest need owns.** The earliest phase in the tracker that needs a table, enum, service or support class **creates** it. Later phases reference it and may only request **additive** columns/methods. | F-2.1, F-4.1, F-5.4, F-5.5, F-11.4 |
| R2 | A later phase that needs an artefact **before** its nominal owner ships **moves ownership earlier**; the nominal owner's contract is edited to "reuse, do not create". | F-4.1, F-5.4, F-5.5 |
| R3 | **One class, one name.** Two declarations of one `app/Enums` or `app/Services` name is a merge conflict, never a style question. The owner declares; everyone else casts/calls. | F-5.1 … F-5.7 |
| R4 | A column a contract depends on is either **added by the owning table's phase with an exact type, nullability and index**, or the **dependent contract is changed**. Every row in §3 says which. | §3 rows F-3.x |
| R5 | **A snapshot is never an authority and never a scope.** `*.collaborator_id` / `*.referral_code` on a subject table is display only; every scope and every engine read goes through `collaborator_referrals`. | F-8.2, F-12.2, D37 |
| R6 | **No private artefact on the public disk.** Anything that is not published website content is streamed by a controller that re-runs the permission chain. | F-12.5, D21 |
| R7 | Safer-with-money wins. A DTO beats adjacent same-typed positionals; an INSERT guard beats a SELECT guard; a derived figure beats an incremented cache. | F-4.2, F-3.7, F-3.15 |
| R8 | A permission string is whatever Phase 1's `PermissionRegistry` pattern produces: `{module_slug}.{ability}`, slug `snake_case` = `modules.slug`. A route name is `panel.resource.action`. No exceptions are granted below. | F-6.1, F-6.2, F-6.9 |

---

## 2. Ownership maps

### 2.1 Contested tables — one owner each

| Table | Owner (creates it) | Everyone else | Finding |
|---|---|---|---|
| `contact_inquiries` | **Phase 4** (§2.20, the only contract with the schema) | Phases 3, 5, 8-9 reference and may request additive columns only | F-2.1 |
| `faqs` / `faq_categories` (incl. course FAQs via `faqable_*`) | **Phase 3** | Phase 14-17 writes rows, creates no table; `course_faqs` does not exist | F-2.2 |
| `seo_meta` | **Phase 3** | no phase adds SEO columns to its own table | F-2.3 |
| `media_assets`, `website_section_media` | **Phase 3** | Phase 4 uses them; `portfolio_images` does not exist | F-2.4 |
| `attachments` | **Phase 6** | Phase 19-23 extends the morph map only; no `files` table ever | F-2.6, F-2.8, F-13.2 |
| `project_members` | **Phase 6** | `project_collaborator` does not exist | F-2.7 |
| `student_fees`, `student_fee_installments`, `student_fee_discounts`, `student_fee_payments`, `project_payments`, `payment_reversals` + the 9 collaborator tables (spine §2.1, 15 tables) | **Phase 10** (spine migration set, shipped in the same release as Phase 8) | Phase 18 ships screens/services + `student_fee_reminders` only; Phase 13 ships invoices/expenses/incomes | F-11.2, F-11.3 |
| `course_inquiries`, `student_applications`, `student_admissions`, `students`, `teachers`, `batches` | **Phase 14-17** | Phase 18/19-23 reference only | F-3.8, F-3.14 |
| `expenses`, `incomes`, `invoices`, `invoice_items`, `finance_reversals` | **Phase 13** | Phase 7 requests additive `source_type`/`source_id` only | F-3.9 |

### 2.2 Contested enums — one declaration each

| Enum | Declared by | Canonical cases | Callers that must only cast | Finding |
|---|---|---|---|---|
| `ContentStatus` | phase-03 §3 | `draft`, `scheduled`, `published`, `archived` | phase-04 (`services`, `portfolio_items`, `team_members`, `success_stories`, `blog_posts`) | F-5.1 |
| `PostStatus` | **deleted** | — | map `PostStatus::*` → `ContentStatus::*` 1:1 | F-5.1 |
| `EmploymentType` | phase-07 §3 | `full_time`, `part_time`, `contract`, `internship`, `temporary`, `consultant`, `freelance` + `isSalaried()`, `leaveEligibleByDefault()` | phase-04 (`job_openings.employment_type`) | F-5.2 |
| `InquirySource` | phase-04 §3 | `website`, `facebook`, `instagram`, `tiktok`, `google`, `whatsapp`, `referral`, `walk_in`, `call`, `email`, `other` | phase-05 (`leads.source`, `clients.source`), phase-14-17 (`course_inquiries.source`) | F-5.3 |
| `LeadSource`, `CourseInquirySource` | **deleted** | — | values are already identical strings: no data migration | F-5.3 |
| `PaymentMethod`, `LedgerEntryType` | phase-07 §3 | verbatim the spine §3 cases | spine, phase-10-12, phase-13, phase-14-17, phase-18 reuse | F-5.4 |
| `CommissionCalculationType` | phase-06 §3 | `percentage`, `fixed`, `manual` | spine, phase-10-12 reuse | F-5.5 |
| `CommissionRuleSource` | spine §3 | `collaborator_rule`, `project_override`, `manual` (**no** `global_default`) | all | F-5.6 |
| `Priority` | phase-06 §3 | `low`, `medium`, `high`, `urgent` | phase-19-23 (`support_tickets.priority`); `TicketPriority` deleted | F-5.7 |
| `Gender` | phase-14-17 §3 | `male`, `female`, `other` | nobody else (Phase 7 has no gender column) | F-5.8 |
| `AttendanceStatus` (HR, phase-07) / `StudentAttendanceStatus` (phase-14-17) | two deliberately different names | unchanged | — | F-5.9 |
| `AttachmentVisibility` | phase-06 §3 | `internal`, `team`, `client` | phase-05 §9.2, phase-19-23 | F-2.6 |
| `CommentVisibility` | phase-06 §3 | `internal`, `team` (**no** `client` case) | clients see no comments in this release | F-3.2 |

### 2.3 Contested services and support classes — one owner each

| Class | Owner | Signature notes | Later phases | Finding |
|---|---|---|---|---|
| `App\Services\Finance\DocumentNumberService` | **Phase 5** | `next(string $prefixKey, string $counterKey, string $pad = '%06d'): string` + `reserve(string $counterKey, ?string $periodKey = null, ?string $periodValue = null): int`; **every caller passes its own pad explicitly** | 6, 7, 8, 10, 14-17 reuse; none re-creates | F-4.1, F-4.12, F-11.4 |
| `App\Support\Money` | **Phase 1** | the full canonical method list in F-4.11 | everyone calls | F-4.11 |
| `App\Support\RichText` (`mews/purifier`) | **Phase 3** | `sanitize(string $html, string $profile = 'cms'): string` — **two parameters, and the second one is part of the signature (ND-5, RD-2):** nobody may "restore" the single-argument `sanitize(string $html): string`, because phase-19-23 §6.13 calls `sanitize($html, 'material')` and a one-parameter signature makes that call a `TypeError`. Sanitises on write **and** on render; **one class, one code path, one allowlist per named profile**. The profile set is a **closed class-level `PROFILES` map with exactly two entries** — `cms` and `material` (phase-03 §6.6) — anything else throws `UnknownRichTextProfileException`, and the caller's string is **never** forwarded to `mews/purifier` as a config key. `material` = everything in `cms`, minus the iframe hosts, plus `div h1 h5 h6 small b i sub sup`, `style`, `align` and `data:` images; the non-weakenable common core (`script`, `object`, `embed`, `link`, `meta`, `form`, `input`, `base`, `applet`, every `on*`, `javascript:` / `vbscript:` / `file:`, every Blade and PHP construct, every non-allowlisted `<iframe>`) is blocked under **both**. A profile is an argument, never a second purifier profile, never a third profile without a phase-03 §6.6 edit, and never a second sanitiser class (D25 unweakened) | phase-04 `HtmlSanitizer` deleted; phase-21 `PrintTemplateService` calls `RichText::sanitize($html, 'material')` (its own `PrintTemplateService::sanitize(string $html): string` is a different method on a different class and delegates here) | F-2.5, ND-5, RD-2 |
| `App\Services\Cms\MediaService` + `ImageProfile` | **Phase 3** | `store()`, 8 profiles, WebP derivatives, `srcset` | phase-04 `ImageUploadService` deleted | F-2.4 |
| `App\Services\Cms\SeoService` | **Phase 3** | `save(Model\|string $target, array $data): SeoMeta`, `completeness()` | phase-04's `<x-cms.seo-fields>` writes through it | F-2.3 |
| `App\Services\Institute\StudentFeeService` | **Phase 18** | + the four methods of F-4.6 | 14-17, 19-23 call | F-4.6 |
| `App\Services\Institute\ScheduleClashDetector` | **Phase 14-17** | + generic `check(SlotCandidate): ClashReport` | phase-19-23 calls it for exams and meetings | F-4.7 |
| `App\Services\Hr\PayslipService` | **Phase 7** | `render(PayrollRunItem): View`, `export(PayrollRun, string $format)` — **it already exists at phase-07 §6; the audit's premise is wrong** | phase-19-23 calls this signature | F-4.13 |
| `App\Support\ReportResult`, `App\Services\Reporting\ReportExporter`, `resources/views/layouts/print.blade.php` | **Phase 13** | `export(ReportResult, ExportFormat): StreamedResponse` | 18, 19-23 call | F-4.14, F-11.5 |
| `PaymentService`, `ReferralService`, `LedgerWriter`, `CommissionRuleService`, `CollaboratorWalletService`, `CollaboratorStatementService`, `PayoutService` | **Phase 10** (spine) | the only money write paths | everyone calls, nobody re-sums | F-4.2 … F-4.9 |
| DTO namespaces | — | `App\DataObjects\Finance\` (`RefundData`), `App\DataObjects\Collaborator\` (`ReferralContext`), `App\DataObjects\Institute\` (`SlotCandidate`, `ClashReport`) | — | F-4.2, F-4.3, F-4.7 |

### 2.4 Settings groups and dashboard widget keys

| Thing | Canonical | Finding |
|---|---|---|
| `website` settings group | slug `website`, label **"Website & Forms"**, icon `globe-alt`, sort **75**, permission `settings.edit`, declared **once** with phase-03's **13** keys **and** phase-04's **21** keys merged — **34 keys**, no collisions. **Corrected from "22 / 35" (RD-6):** both contracts recounted the rows and diffed the key *names* — phase-03 §5.1a = 13 rows, phase-03 §5.1b = 21 rows, phase-04 §5 = the same 21 names, so 13 + 21 = **34** and nothing was lost in the merge. The earlier 22 / 35 in this row was the error and must not be re-applied: there is **no 22nd Phase-4 key to restore**. A future `website.*` key is added to **phase-03 §5 (§5.1b) and phase-04 §5 in one edit**, with both totals bumped together | F-6.3, RD-6 |
| `projects` settings group | sort **86** (was 85) | F-6.3 |
| `FeeCollectedTodayWidget`, `PendingFeesWidget`, `OverdueFeesWidget` | **Phase 18** registers them | F-8.3 |
| the eight commission/wallet widgets | spine / phase-10-12 keep them | F-8.3 |

---

## 3. The instruction list

### 3.1 Table ownership (§2 of the audit)

| Finding | Decision (one sentence) | Edits required (file -> exact change) |
|---|---|---|
| **F-2.1** crit | **Phase 4 owns `contact_inquiries`**, fires `ContactInquirySubmitted`, and routing is done by `InquiryRouter` + per-target `InquiryTarget` classes. | phase-03 §13.3 -> replace "**Phase 5** owns the contact section's form handling…" with "**Phase 4** owns the `contact` section type, `contact_inquiries` and the §17 routing"; phase-03 §1.3 -> keep the "does not create" line but name Phase 4 as owner. phase-05 §1.2 dependency row -> change source phase **3 -> 4**; §13.1 -> change "`contact_inquiries.id` plus an event - Phase 3" to "- **Phase 4**"; §6/§10.2 -> **delete** the `CreateLeadFromContactInquiry` listener and ship `App\Support\Inquiry\CrmLeadInquiryTarget implements InquiryTarget` registered into Phase 4's `InquiryRouter` (phase-04 §6.10.4); keep `crm:import-pending-inquiries` as a 10-minute safety net made duplicate-proof by F-3.7's unique index. phase-08-09 §13.1 -> retarget the three `contact_inquiries.*` asks from Phase 3 to **Phase 4**. phase-04 §2.20 / §6.10 -> unchanged (owner). |
| **F-2.2** high | `course_faqs` does not exist; course FAQs are `faqs` rows with `faqable_type = Course`. | phase-14-17 §2.1 -> **delete** row 8 (`course_faqs`); §2.10 -> delete the table definition and replace with "course FAQs are `faqs` rows (`faqable_type = App\Models\Institute\Course`, `faqable_id = courses.id`) written by `FaqService::save()` under `courses.edit`"; delete any `course_faqs` index/permission/test row. phase-03 §6.13 -> add `Course` to the list of types whose `faqable_*` the owning phase may write. |
| **F-2.3** high | **One SEO store: `seo_meta`.** No phase adds SEO columns to its own table. | phase-04 -> **delete** `seo_title`, `seo_description` from §2.2, §2.6, §2.13, §2.18; `seo_title`, `seo_description`, `seo_keywords`, `og_image_path`, `noindex` from §2.3, §2.7; `seo_title`, `meta_description`, `meta_keywords`, `canonical_url`, `og_image_path`, `noindex` from §2.16. Each of the 7 models gains `morphOne(App\Models\Cms\SeoMeta::class, 'seoable')` + one `SitemapUrlProvider` registered in `SitemapRegistry`. §8.13 -> `<x-cms.seo-fields>` is **kept** but writes only through `App\Services\Cms\SeoService::save()`; it declares no entity column. OG images use `seo_meta.og_image_media_id`. phase-03 §8.12 -> unchanged. |
| **F-2.4** high | **Two tiers, written down:** `media_assets` is mandatory for anything rendered on the public website; a bare `*_path` is allowed only for a **private** profile photo/document. | phase-04 -> **delete** `App\Services\Cms\ImageUploadService` (§6.6) and the `portfolio_images` table (§2.8); add pivot `portfolio_item_media` (`portfolio_item_id`, `media_asset_id`, `sort_order`, `caption` nullable, `UNIQUE uq_pim(portfolio_item_id, media_asset_id)`, `INDEX (media_asset_id)`, no `deleted_at`, `created_by` only); replace every public-rendered `*_path` string column with a nullable indexed `*_media_id` FK -> `media_assets.id` (`nullOnDelete`); `<x-cms.image-field>` becomes a thin wrapper over `MediaService` + the media picker. phase-03 §13.3 -> keep the ban, append the private-photo exception verbatim from D24. phase-05 `clients.logo_path`, phase-07 `employees.photo_path`, phase-08-09 `collaborators.photo_path`, phase-14-17 `students.photo_path` / teacher photo -> **unchanged** (private tier); if a public section renders client logos it stores its own `website_section_media` ids and never reads `clients.logo_path`. |
| **F-2.5** high | **One sanitiser: `App\Support\RichText::sanitize()`** over `mews/purifier`, on write and again on render. | phase-04 §6.9 -> **delete** `App\Support\HtmlSanitizer` and every reference; Q7 answered "adopt the package"; R7 closed. phase-19-23 §6 (`PrintTemplateService`, INV-21-5) -> name `RichText::sanitize()` as the sanitiser. phase-24-25 §1.2 -> remove the `HtmlSanitizer` dependency row; SEC-05 names `RichText::sanitize()` for every `{!! !!}`. |
| **F-2.6** med | `attachments.visibility` (enum) is the only client-visibility mechanism. | phase-19-23 §2.27 -> **delete** the `add_client_visibility_to_attachments_table` migration; replace every `is_client_visible` reference with `visibility = AttachmentVisibility::Client`; §13.1 -> delete the ask to Phase 6. phase-05 §9.2 Files rule -> `attachments.visibility = 'client'`. |
| **F-2.7** med | `project_members` is the only project-people table. | phase-08-09 §2.1 -> `belongsToMany(Project::class, 'project_members')->wherePivotNull('deleted_at')->wherePivotNotNull('collaborator_id')` as `assignedProjects`; §9 -> scope reads `project_members`; §13.2 -> **delete** the `project_collaborator` request. |
| **F-2.8** med | The `files` **module slug** governs the `attachments` **table**; no `files` table is ever created. | `CLAUDE.md` §3 -> paste §5 block B (owner). phase-05 §9.2 / §13.1 -> retarget `files.is_client_visible` to `attachments.visibility`; delete the "from Phase 22" ask. phase-19-23 [D-22-3] -> keep the ban, add the slug-to-table sentence. |

### 3.2 Undefined columns (§3 of the audit)

| Finding | Decision (one sentence) | Edits required (file -> exact change) |
|---|---|---|
| **F-3.1** crit | Phase 6 adds `tasks.is_client_visible`; client task visibility is the **AND** of the project setting and the column. | phase-06 §2.5 -> add `is_client_visible` boolean not null default **true**; `INDEX (project_id, is_client_visible)`; §9 -> client task rule becomes `projects.client_can_see_tasks = 1 AND tasks.is_client_visible = 1`; §7.7 + policy -> same clause. phase-05 §9.2 / §13.1 -> unchanged (request satisfied); test 74 stands. |
| **F-3.2** high | **Withdraw** the request: clients see no task comments in this release; `CommentVisibility` gains no `client` case. | phase-05 §13.1 -> **delete** the `task_comments.is_client_visible` row. phase-06 §9 -> unchanged (Client 403 on `task_comments`). |
| **F-3.3** high | The column is `clients.account_manager_id`. | phase-06 §9 -> `clients.account_manager_id = $user->id`; §13.1 -> **delete** the `clients.assigned_to` request. |
| **F-3.4** high | Phase 5's design wins: `clients.referral_code_captured` + `referral_recorded_at`; no `clients.collaborator_id`. | phase-08-09 §13.1 -> retarget to `clients.referral_code_captured`, **delete** the `clients.collaborator_id` ask. phase-05 test 55 stands. |
| **F-3.5** high | Phase 5 **adds `leads.referral_visit_id`** (evidence is worth keeping); still no `leads.collaborator_id`. | phase-05 §2.1 -> add `referral_visit_id` unsignedBigInteger nullable, `INDEX (referral_visit_id)`, FK -> `collaborator_referral_visits.id` `nullOnDelete` **deferred to Phase 9's guarded migration**. phase-08-09 §13.1 -> keep the `referral_visit_id` ask, retarget `referral_code` to `referral_code_captured`, **delete** the `collaborator_id` ask; INV-R6's prune guard also checks `leads.referral_visit_id`. |
| **F-3.6** high | The column is `leads.service_id`. | phase-04 §6.10.4 + §13 -> map `contact_inquiries.service_id -> leads.service_id` and **delete** the `leads.interested_service_id` request. phase-05 §2.1 unchanged. |
| **F-3.7** high | The idempotency key is a DB index, not a SELECT. | phase-05 §2.1 Keys -> add `UNIQUE uq_leads_inquiry(contact_inquiry_id)` (MariaDB ignores NULLs, so manual leads stack); §10.2 -> the listener and `crm:import-pending-inquiries` both rely on the 1062, returning the existing lead. |
| **F-3.8** high | `course_inquiries` gets the same provenance key as `leads`. | phase-14-17 §2.11 -> add `contact_inquiry_id` unsignedBigInteger nullable, FK -> `contact_inquiries.id` `nullOnDelete` (deferred guarded FK), `UNIQUE uq_ci_inquiry(contact_inquiry_id)`; keep `idempotency_key` (different guarantee). phase-04 §13 -> request satisfied, name the exact column. |
| **F-3.9** high | Phase 13 adds the generic source columns and the payroll listener, so §99's P&L includes salaries. | phase-13 §2.6 -> add `source_type` string(32) nullable, `source_id` unsignedBigInteger nullable, `UNIQUE uq_exp_source(source_type, source_id)`, `INDEX (source_type, source_id)`; §6.4 -> add listener `RecordPayrollExpense` on phase-07's `PayrollRunPaid` writing **one** `approved` expense, `context = general`, reserved category `salaries` (seeded, undeletable), `source_type = payroll_run`, `source_id = run.id`, amount = the run's net paid total via `Money`; §6.7.3 -> P&L block B gains the salary line from that category; R-4 note: the expense is the **only** salary cost row, never double-counted. phase-07 §13.1 + Q14 -> answered, request satisfied. |
| **F-3.10** high | The column is `project_milestones.name`. | phase-13 §1.2, §8.2, §13.1 -> replace `project_milestones.title` with `project_milestones.name` (3 places). |
| **F-3.11** high | `projects.project_manager_id` is a **`users.id`**. | phase-13 §9 `ProjectManagerScope` -> `->where('project_manager_id', $user->id)`. phase-06 §2.1 unchanged. |
| **F-3.12** med | Phase 4 adds `job_applications.employee_id`. | phase-04 §2.19 -> add `employee_id` unsignedBigInteger nullable, `INDEX (employee_id)`, FK deferred to Phase 7's guarded migration (same pattern as `department_id`). phase-07 §13.1 -> satisfied. |
| **F-3.13** med | Phase 4 adds `team_members.employee_id` as a **source of defaults only**. | phase-04 §2.9 -> add `employee_id` unsignedBigInteger nullable, `INDEX (employee_id)`, deferred FK; keep "no `user_id` link" and keep publication an explicit CMS act. phase-07 §13.1 + Q15 -> satisfied. |
| **F-3.14** med | Symmetry: `course_inquiries.referral_visit_id` is added. | phase-14-17 §2.11 -> add `referral_visit_id` unsignedBigInteger nullable, `INDEX`, deferred FK -> `collaborator_referral_visits.id` `nullOnDelete` (identical to §2.13/§2.14). phase-08-09 §13.1 -> satisfied. |
| **F-3.15** med | The INSERT is the check: `student_fees.generation_key` is added to the spine's own table. | spine §2.2 -> add `generation_key` string(64) nullable and `UNIQUE uq_sf_generation(generation_key)` (composed by the generator, e.g. `structure:{admission}:{head}` / `monthly:{admission}:{YYYY-MM}`). phase-10-12 §2.2 migration **01** -> add the column + index. phase-18 §2.4/§6/§13.1/Q1 -> **delete** [D18-2]'s SELECT-under-row-lock guard; both generators INSERT and treat 1062 as "already generated". |

### 3.3 Services and signatures (§4 of the audit)

| Finding | Decision (one sentence) | Edits required (file -> exact change) |
|---|---|---|
| **F-4.1** crit | **Phase 5 ships `App\Services\Finance\DocumentNumberService`**, default pad `'%06d'`, every caller passes its own pad. | phase-07 [D-HR-14] + §5 + §13.1 -> "**Phase 5 ships it; Phase 7 reuses it unchanged**"; delete "Phase 7 is the first phase that needs this class"; HR callers pass `'%05d'` explicitly. phase-10-12 §1.3 + §6.3 -> move the class from "Ships" to "Reuses, must not re-create". phase-06 §6.1 + §13.1 -> `ProjectNumberService` delegates from day one (the class exists from Phase 5), passing `'%05d'`; retarget the request Phase 10 -> Phase 5. phase-08-09 §2.1/§6 -> retarget to Phase 5, pad `'%04d'`. phase-14-17 §13.1 -> retarget Phase 10 -> Phase 5. spine §6.2 -> keep the row, note the owner is Phase 5. |
| **F-4.2** high | **One form: the DTO.** `refund(StudentFeePayment\|ProjectPayment $p, RefundData $data): PaymentReversal` — two adjacent `string` positionals are swappable, which is a silent money bug. | spine §6.2 -> replace the `refund(...)` signature with the DTO form and add `App\DataObjects\Finance\RefundData` (readonly: `string $amount`, `string $reason`, `ReversalType $type`, `?string $method = null`, `?string $idempotencyKey = null`, `?CarbonInterface $refundedOn = null`) to spine §13.2. phase-18 §6.10.5 -> call `refund($p, new RefundData(amount: …, reason: …, type: ReversalType::PartialRefund, method: …))`. phase-13 §6.5 -> same form. phase-10-12 §6.3 -> unchanged (already the DTO). |
| **F-4.3** high | `ReferralService::attach()` takes a sixth parameter `?ReferralContext $context = null` and fills the six evidence columns. | spine §6.2 -> extend `attach()`; §13.2 -> add `App\DataObjects\Collaborator\ReferralContext` (readonly: `?int $referralVisitId`, `?string $landingUrl`, `?string $ipAddress`, `?string $userAgent`, `?CarbonInterface $referralDate`, `?string $notes`). phase-10-12 §6.3 -> same signature. phase-08-09 §13.2 + phase-14-17 §13.1 -> satisfied; both pass a `ReferralContext`. |
| **F-4.4** high | The losing candidate is written by the spine, through a published method. | spine §6.2 `ReferralService` block -> add `recordLosingCandidate(CollaboratorReferral $winner, Collaborator $loser, ReferralContext $ctx, string $reason): CollaboratorReferral` (status `superseded`, `superseded_by_id = $winner->id`, `commission_eligible = false`, reason mandatory). phase-10-12 §6.3 -> add the same row. phase-08-09 §6.3 modifier 7 + INV-R3 -> call it. |
| **F-4.5** high | **No new spine method.** Phase 5's `convert()` calls `attach()` with the lead's referral date and records the evidence id. | phase-05 §6.4 step (7) -> `ReferralService::attach($client, $collaborator, ReferralSource::ManualSelection, $leadReferral->referral_code, $leadReferral->referral_date, $ctx)` then write `lead_conversions.collaborator_referral_id = $leadReferral->id` and `referral_code`; §13.1 -> **delete** the `copyAttribution` request. Test 53 unchanged (it asserts the outcome). |
| **F-4.6** high | All four methods are published by Phase 18. | phase-18 §6.1 -> add `summaryFor(Student\|StudentAdmission $subject): FeeSummary`; `reassignBatch(StudentBatchEnrollment $from, Batch $to): int` (repoints `student_fees.batch_id` only, writes no money); `withinServiceContext(): bool`; `outstandingFor(StudentAdmission\|StudentBatchEnrollment $subject): string`. Add `App\DataObjects\Institute\FeeSummary` (readonly: gross, discount, scholarship, net, paid, refunded, balance, status, nextDueDate) to phase-18 §13.2. phase-14-17 §13.1 / §2.15 and phase-19-23 §13.1 -> satisfied; delete the "not yet in Phase 18's list" notes. |
| **F-4.7** med | The generic clash check is published; the three subject checks become wrappers. | phase-14-17 §6.7 -> add `check(SlotCandidate $c): ClashReport` and define `App\DataObjects\Institute\SlotCandidate` (readonly: `?int $teacherId`, `?int $classroomId`, `?int $batchId`, `CarbonInterface $startsAt`, `CarbonInterface $endsAt`, `?string $ignoreType`, `?int $ignoreId`) and `ClashReport` (readonly: `bool $clean`, `array $conflicts`); the three existing methods call it; INV-I8 restated to cover exams and meetings. phase-19-23 §13.1 -> satisfied; §20/§22 call `check()`. |
| **F-4.8** med | Both totals are published by the spine; no phase sums money itself. | spine §6.2 -> add `CollaboratorWalletService::payoutsPaidTotal(Collaborator $c, ?DateRange $r = null): string` and `CollaboratorStatementService::commissionAccruedTotal(Collaborator $c, ?DateRange $r = null): string`, both derived from the §6.5.1 canonical SQL. phase-10-12 §6.3 -> add both rows. phase-13 §13.1 + §6.7.3 and phase-19-23 §13.1 + §6.20 -> satisfied (INV-26 intact). |
| **F-4.9** med | The rejection leg exists — **as the cache and notification leg only.** The money rollback is **not** the listener's job: a rejection rolls `refunded_amount` back inside `rejectReversal()`'s own transaction, under the payment's row lock. | spine §10.1 -> add `PaymentReversalRejected` (dispatched `afterCommit`). phase-10-12 §10.1 -> add it plus a listener that **only recomputes caches and notifies** (`RecomputeCachesAfterReversalRejected` re-deriving phase-13's `invoices.paid_amount` / `refunded_amount` / `balance_amount` from the one canonical SQL under **D40**, never `increment()`, and the charge's five `student_fees` caches; plus `NotifyReversalRequester`). **The payment's own `refunded_amount` rollback and status restore stay inside `PaymentService::rejectReversal()`'s transaction** (phase-10-12 §6.3, spine §2.18's `pending -> rejected` row, phase-18 §6.10.5) and **no listener may repeat them**: a second `afterCommit` decrement would decrement twice, drive `refunded_amount` below zero and below `SUM(payment_reversals.amount)`, and break INV-9's ceiling and the derivability of every cache above it. Safer-with-money wins (R1/R7) — a later agent must **not** "restore" a listener that rolls `refunded_amount` back. phase-13 §13.1 -> satisfied; invoice caches recompute on it. |
| **F-4.10** med | **One concession to INV-8**, recorded as **D43**: `project_payments.invoice_id` may move NULL -> value -> NULL, by `InvoiceService` only, gated by the **dedicated narrow ability `project_payments.link_invoice`** plus `invoices.edit` — **never** `project_payments.edit`. | spine INV-8 -> lifecycle whitelist becomes `notes` / `reference_no` / `receipt_path` / **`invoice_id`**, with the four guards: only `InvoiceService`, only while the payment is not `voided`, reason mandatory and audited (§107), gated by **`project_payments.link_invoice`** **and** `invoices.edit`, zero commission effect (no ledger read or write). **Corrected from `project_payments.edit` (RD-2, ND-1):** the spine's "a received payment is never editable" rule (INV-8, INV-5) must stay **literally** true, so the concession gets its own narrow ability instead of a registered `edit` on a money module — `project_payments` and `student_fee_payments` are never granted `edit`, now or later (spine §4.1's seven-row property table, §1.4 guard 4, FT-52). spine §4.1 -> the `project_payments` slug row ends `+ link_invoice`; phase-10-12 §4.1 -> the same slug row registers it (that table is the build artefact); phase-01 -> `Ability::LinkInvoice`; `RoleSeeder` -> Accountant only. phase-13 §6.3 rules 4-5 + §7.1 -> cite D43 and check the **pair**; holding one alone is a 403. |
| **F-4.11** med | One canonical `Money` surface, published in Phase 1's contract. | phase-01 §3 -> replace the `Money.php` row with: `add`, `sub`, `mul`, `div`, `percentage($base,$rate)`, `percentageOf($part,$whole)`, `compare`, `isZero`, `isNegative`, `abs`, `min`, `max`, `sum(array)`, `round($v,$scale=2)`, `roundTo($amount,int $nearest)`, `prorate($amount,$part,$total)`, `distribute($amount,int $parts,RemainderPlacement $r = RemainderPlacement::First): array`, `toMinor`, `fromMinor`, `format` — **bcmath only, intermediate scale 6, final half-up at 2, strings in and out, never a float**; add `App\Enums\RemainderPlacement` (`first`, `last`, `largest`). spine §13.2, phase-07 §13.1, phase-10-12 §13.1, phase-13 §13.1, phase-14-17 §13.2, phase-18 §13.2, phase-24-25 §13.1 -> replace each ask with "satisfied by phase-01 §3". *(The class change itself is Phase-1 code: owner's remediation team, tested by FIN-12.)* |
| **F-4.12** med | `reserve()` lives on the same class (see F-4.1); no second `FOR UPDATE` counter. | phase-05 §6.10 -> add `reserve(string $counterKey, ?string $periodKey = null, ?string $periodValue = null): int` (period reset; the period row is a settings key). phase-14-17 §6.5 -> `StudentNumberService` calls it; **delete** the local-duplication fallback and the tech-debt note. |
| **F-4.13** med | **The audit is wrong: `PayslipService` already exists** (phase-07 §6: `render(PayrollRunItem): View`, `export(PayrollRun, string $format)`). Nobody adds a second one. | phase-19-23 §1.2 + §13.1 -> cite `PayslipService::export(PayrollRun, string $format)` exactly; delete "if the names differ". phase-07 -> **no change**. |
| **F-4.14** med | Phase 13 ships the three reporting artefacts four phases assume. | phase-13 §6.9 -> add `App\Support\ReportResult` (readonly: `array $rows`, `array $groups`, `array $totals`, `array $meta`), `App\Services\Reporting\ReportExporter::export(ReportResult $r, ExportFormat $f): StreamedResponse` (csv/xlsx/pdf, streamed, never `->get()` into memory), and `resources/views/layouts/print.blade.php`; `FinanceReportService::report()` returns a `ReportResult`. phase-19-23 §1.2/§13.1 and phase-18 §13.2 -> satisfied, delete the "or Phase 18 if 13 has not shipped it" clause. |

### 3.4 Enums (§5 of the audit)

| Finding | Decision (one sentence) | Edits required (file -> exact change) |
|---|---|---|
| **F-5.1** crit | One `ContentStatus` with four cases; `PostStatus` deleted. | phase-04 §3 -> **delete** `ContentStatus` from "Enums to add" and **delete** `PostStatus` entirely; every `PostStatus` reference becomes `ContentStatus` (`blog_posts.status` keeps `scheduled`); §2/§6/§8/§11 -> one cast. phase-03 §3 -> owner, unchanged. |
| **F-5.2** crit | One `EmploymentType` owned by Phase 7 with the seven-case union. | phase-07 §3 -> add `freelance` (keep `isSalaried()`, `leaveEligibleByDefault()`; `freelance` returns false/false). phase-04 §3 -> **delete** the declaration; `job_openings.employment_type` casts to `App\Enums\EmploymentType`. |
| **F-5.3** high | One `InquirySource` (11 cases), owned by Phase 4. | phase-05 §3 -> **delete** `LeadSource`; cast `leads.source` and `clients.source` to `InquirySource`; replace every `LeadSource::` reference; `crm.default_lead_source` validates against `InquirySource`. phase-14-17 §3 -> **delete** `CourseInquirySource`; cast `course_inquiries.source` to `InquirySource`. phase-04 §3/§13/Q8 -> owner, answered "reused". |
| **F-5.4** high | Phase 7 ships `PaymentMethod` and `LedgerEntryType` (it migrates first); cases verbatim from the spine. | phase-10-12 §3 -> mark both **"reused, not created"**. spine §3 -> note "declared by Phase 7, cases defined here". phase-07 §3 -> owner, unchanged. |
| **F-5.5** high | Phase 6 ships `CommissionCalculationType`. | phase-10-12 §3 -> mark **"reused, not created"**. spine §3 -> note "declared by Phase 6 with these exact cases". |
| **F-5.6** med | Three cases only; there is no silent fallback to a global default rate. | spine §2.10 -> delete `global_default` from the `rule_source` column note. |
| **F-5.7** med | `support_tickets.priority` casts to the shared `Priority`; the SLA multiplier is support policy. | phase-19-23 §3.4 -> **delete** `TicketPriority`; cast to `App\Enums\Priority`; move `slaMultiplier()` to `TicketSlaService::multiplier(Priority $p): float`. |
| **F-5.8** med | `Gender` stays in phase-14-17; the Phase 7 note is deleted. | phase-14-17 §3 -> delete "Phase 7 should reuse it rather than declare a second one". |
| **F-5.9** med | Two names on purpose. | **No change.** Recorded in §8. |
| **F-5.10** low | One name: `obtained_marks`. | phase-19-23 §2.7 -> rename `assignment_submissions.marks_obtained` to `obtained_marks` (plus its CHECK, index, service, Blade, export and test references). |

### 3.5 Permissions, modules, routes (§6 of the audit)

| Finding | Decision (one sentence) | Edits required (file -> exact change) |
|---|---|---|
| **F-6.1** high | **`module:project_payments` on every project-payment route, admin and client**; `payments` stays the umbrella for phase-13's cross-source register only. | spine §7.2 -> middleware `['auth','active','module:project_payments','can:project_payments.view_any']`; §7.6 -> the two client payment routes gain `module:project_payments` (and `client.context`, F-12.3). phase-13 §4.2 -> keep `payments` for the cross-source register; `project_payments.view_reports` stays on the `project_payments` slug. phase-05 §7 -> unchanged (already correct). phase-10-12 §9 -> both slugs listed, unchanged. |
| **F-6.2** high | **phase-05 owns `routes/client.php` and every `client.*` route name**; later phases append screens, never redeclare names. | phase-06 §7.7 -> **delete** the redeclarations of `client.projects.index`/`.show` and `client.tasks.index`; contribute the project/task screens into Phase 5's routes via `ClientPortalRegistry`; rename its file route to Phase 5's `client.files.index` at `/client/files` with a `?project=` filter; `client.attachments.download` -> **`client.files.download`**. spine §7.6 -> do not declare `client.payments.index`; append to Phase 5's. phase-05 §7 -> keep `client.milestones.index` and `client.progress.show`; phase-06 §8.11 folds the **views** into `client.projects.show` but the two names stay owned by Phase 5 and resolve to that screen. phase-13 §7.7 -> append, never redeclare. |
| **F-6.3** med | One `website` settings group: label "Website & Forms", sort 75, **34** merged keys; `projects` moves to sort 86. | phase-04 §5 -> declare **no** group; contribute its **21** keys into phase-03's `website` group. phase-03 §5.1 -> label becomes "Website & Forms", keep sort 75, list all **34** keys (**13** in §5.1a + **21** in §5.1b). phase-06 §5 -> `projects` group sort **86**. **Corrected from 22 / 35 (RD-6):** both contracts recounted and name-diffed the keys (13 + 21 = 34) and both name this row as the error. Applied literally, the old wording told phase-03 §5.1 to list a 22nd Phase-4 key **that does not exist** — do not invent one. A future `website.*` key is added to **phase-03 §5 and phase-04 §5 in one edit**, bumping both totals together. |
| **F-6.4** med | Phase 1 seeds the modules **known at Phase 1**; later phases append idempotently. | phase-01 §4 -> replace "(permissions for all of them are seeded now so later phases only add UI…)" with "(these are the modules known at Phase 1; later phases append to `PermissionRegistry`, `ModuleSeeder` and `RoleSeeder`, always idempotently and never destructively — the final count is ~112 modules)". |
| **F-6.5** med | `ModuleGroup::System` is a **grouping**; `is_core` is per module. | phase-01 §4 -> add "`ModuleGroup` is a grouping for the sidebar and the module screen; `is_core` is a per-module flag. The ten Phase 1 System modules are `is_core = true`; a later System-group module may be `is_core = false` (e.g. `audit_trail`, `system_health`, `integrity_checks`)." phase-19-23 §4.1 and phase-24-25 §4.1 -> unchanged. |
| **F-6.6** med | Public routes carry no `module:`/`can:`, but a Website module may gate **its own** public routes with `site_module` (404). | phase-03 §9 + INV-15 -> restate as "disabling `website_sections` never takes the public site down; no public route carries `module:` or `can:`; a content module may gate its own public routes through `site_module`, which 404s". phase-04 §7.1/§7.3 + test 57 -> unchanged. |
| **F-6.7** low | One **Website** sidebar group; no route renames. | phase-04 §8 (sidebar) + phase-03 §8 -> one Website group listing both sets, with the rule "content *about* the site lives under `/admin/website`; business entities the site renders live at the top level". |
| **F-6.8** low | The alias is `site`. | phase-19-23 §1.2 + §13.1 -> replace `site.enabled` with `site` (Phase 3 §6.10 ships `site`, `site.cache`, `site.preview`). |
| **F-6.9** low | `student_fee_payments.approve` exists, added by Phase 18. | phase-18 §4.2 -> keep the registry append (matches spine §6.6 row 6 / FT-39). No other change. |

### 3.6 Decimal typing, commission path, soft deletes (§7–§9 of the audit)

| Finding | Decision (one sentence) | Edits required (file -> exact change) |
|---|---|---|
| **F-7.1** high | **One rule: every `*_rate` / `*_percentage` column is `decimal(8,4)`.** | phase-14-17 -> INV-I11 rewritten to `decimal(8,4)`; change `batches.syllabus_completion_percentage`, `student_batch_enrollments.attendance_percentage`, `.progress_percentage`, `batch_topic_coverage.completion_percentage`, `student_course_progress.completion_percentage`, `student_module_progress.completion_percentage`, `student_topic_progress.completion_percentage`. phase-19-23 -> change `assignments.late_penalty_percentage`, `assignment_submissions.percentage`, `grade_scales.pass_percentage`, `grade_scale_bands.min_percentage`, `.max_percentage`, `exams.weight_percentage`, `exams.average_percentage`, `exam_results.percentage`, `certificates.percentage`, `certificates.attendance_percentage`. phase-24-25 FIN-18 -> allowlist keeps only `progress` (tinyint) and `rating` (tinyint). |
| **F-7.2** med | Marks are not money. | phase-24-25 FIN-18 -> add `*_marks` to the allowlist with the reason "marks are `decimal(8,2)`; the ceiling is a per-row CHECK against a snapshotted total" (covers `assignments.total_marks`, `assignment_submissions.total_marks`, `exams.total_marks`, `course_topic_assignments.estimated_marks`). |
| **F-7.3** low | One width: `decimal(10,2)`. | phase-14-17 §2.9 -> `course_topic_assignments.estimated_hours` becomes `decimal(10,2)`. |
| **F-8.2 / F-12.2** high | A collaborator's project scope is an **active `collaborator_referrals` row or an active `project_members` row** — never a snapshot column. | phase-06 §9 (Collaborator row) -> "projects where an active `project_members` row exists for my `collaborator_id`, **or** a `collaborator_referrals` row exists with `status = ReferralStatus::Active`, `subject_type = ReferralSubject::Project`, **`project_id` = `projects.id`** and `collaborator_id = mine`"; delete `projects.collaborator_id = my id`; add the isolation test asserting a hand-written snapshot grants nothing. **There is no `collaborator_referrals.subject_id`** (ND-8): spine §2.8 carries `subject_type` **plus four explicit nullable subject FKs** — `student_id`, `project_id`, `client_id`, `lead_id`, with CHECK `chk_cr_one_subject` asserting exactly one is non-null — so a project scope joins on `project_id` and a student scope on `student_id`. phase-06 §9 already applied it this way; the earlier `subject_id` wording in this row was the error and must not be re-applied literally. |
| **F-8.3** med | Phase 18 owns the three fee widget keys. | spine §8.12 and phase-10-12 §8.12 -> **delete** `FeeCollectedTodayWidget`, `PendingFeesWidget`, `OverdueFeesWidget`; keep the eight commission/wallet widgets. phase-18 §13.2 -> it registers the three. |
| **F-9.1** high | **Category rule (D19):** append-only tables carry **no** `deleted_at`; mutable business tables carry it. | `CLAUDE.md` §3 -> paste §5 block A (owner). Every contract with a deviation -> replace its local decision number with a citation of **D19** (financial tables additionally cite **D16**): spine §2.1 [D-FS-3], phase-03 §2.14 [D-W3-6], phase-04 §2, phase-05 §2.4/§2.6, phase-06 §2.2/§2.8/§2.11, phase-07 §2.1 [D-HR-3], phase-08-09 §2.2-2.4, phase-13 §2 + Q3, phase-14-17 §2.1 [D-IN-2], phase-19-23 §2.1 [D-19-0], phase-24-25 §2. |
| **F-9.2** med | Every FK column gets an index, listed in the owning contract's `Keys` block **and** in `tests/Support/index-manifest.php`. | phase-13 §2.4 -> `INDEX` on `replaces_invoice_id`, `payment_method_id`, `issued_by`, `cancelled_by`; §2.6 -> `approved_by`, `rejected_by`, `voided_by`, `corrects_expense_id`. phase-04 §2.10 -> `approved_by`, `submitted_by_user_id`; §2.11 -> same two; §2.8 -> n/a (`portfolio_images` deleted by F-2.4; index `portfolio_item_media.created_by`). phase-14-17 §2.11 -> `converted_application_id`, `converted_student_id`; §2.15 -> `student_application_id`, `course_inquiry_id`. Every contract -> "each FK index has a row in `index-manifest.php`" added to its §13.2. |
| **F-9.3** med | Filtered columns get indexes. | phase-04 §2.20 -> add `INDEX (routing_target, routing_status)`; §2.16 -> add `INDEX (views_count)`. |
| **F-9.4** low | Blameable asymmetries are legal under D19. | **No change** beyond the `CLAUDE.md` paragraph. Recorded in §8. |

### 3.7 Decision numbers, ordering, isolation, coverage (§10–§13 of the audit)

| Finding | Decision (one sentence) | Edits required (file -> exact change) |
|---|---|---|
| **F-10.1** crit | **§4 of this file is the only decision-number authority**; D1–D16 are frozen, D17–D60 are allocated there, and every contract cites a number instead of claiming one. | Owner pastes §4's block into `DEVELOPMENT_LOG.md` §4 (replacing the `D17+` placeholder row). Then renumber each contract's §13 documentation rows exactly per §4.2. No other text changes. |
| **F-11.1** high | **Assignment of work -> `users.id`. Organisational duty -> `employees.id`. The bridge is `employees.user_id` (nullable, unique).** (D32) | phase-07 §13.1 -> **delete** the `tasks.assigned_employee_id` request and the "`project_user`-style staff links must reference `employees.id`" clause; [D-HR-2] narrowed to "a **duty** (department head, reporting line, leave approver, course coordinator) is held by a post = `employees.id`; who performed an act, who is assigned work, who holds a timer = `users.id`"; §13.2's D21 ask -> cite D32. phase-06 [D-P6-2] -> unchanged, cite D32. phase-13 §9 -> F-3.11's fix. phase-14-17 §2.17 -> `teachers.employee_id` unchanged (a duty). |
| **F-11.2** high | The spine's migration set ships in the **same release**, immediately after Phase 8's migrations. | Owner pastes the §6.3 tracker line under Phase 8 in `DEVELOPMENT_LOG.md` §5. spine §12.2 Q10 and phase-10-12 Q-H -> answered. phase-08-09 §1.4 [D-P8-1] -> unchanged. |
| **F-11.3** high | **Accepted:** Phase 10 ships the four fee tables; Phase 18 ships the screens, services and `student_fee_reminders`. | Owner pastes the §6.3 tracker line under Phase 18 in `DEVELOPMENT_LOG.md` §5. phase-18 §2 -> unchanged ("this phase creates no financial table"); its §1.2 dependency table names Phase 10 as the owner of all four. See §2.1 for the full ownership map. |
| **F-11.4** med | Resolved by F-4.1 (Phase 5 owns the counter service). | No separate edit. |
| **F-11.5** med | Resolved by F-4.14 (Phase 13 adds the three) and F-4.13 (`PayslipService` already exists). | phase-19-23 §1.2 -> keep all four as required, now that each has a named owner. |
| **F-11.6** med | A legitimate cycle broken by ordering, not a defect. | **No change.** Recorded in §8: 3↔4, 6↔8, 14-17↔18 are never built concurrently. |
| **F-11.7** low | The clause is false. | phase-24-25 §13.2 -> delete "Phases 19-23, whose contracts are not yet written,". |
| **F-12.1** high | `permissionModuleMap()` returns **null** for a permission whose module slug is not registered, and `Gate::before` step 1 falls through on null; the four `*_portal` prefixes are permission namespaces, **not** modules. (D20) | phase-01 §3 -> the `Modules.php` row gains "`permissionModuleMap()` returns `null` when the permission's prefix is not a registered module slug; a null is 'not module-gated', never 'disabled'"; §6 step 1 -> "if the map returns a slug **and** that module is disabled and not core -> false; on null, fall through". Add Phase 1 test: "a Collaborator, Student, Teacher and Client each reach their own panel with every module disabled except their own". phase-05 §12.2 Q1 -> answered. *(Code change belongs to the Phase 1 remediation team.)* |
| **F-12.3** med | **Every** client route carries `client.context`. (D31) | phase-06 §7.7 -> add `client.context` to all five rows. spine §7.6 -> add it to both client rows. phase-13 §7.7 -> add it to every client row. |
| **F-12.4** med | PII is gated explicitly: no blanket `view_any`, a per-opening scope, and the technical block behind `view_logs`. | phase-04 §9.1 -> state the grant per role: **Digital Marketer: `contact_inquiries` without `view_any`** (own assigned rows only); HR: `jobs.*` + `job_applications.*` in full; SEO Expert: neither. §2.19/§9.1.3 -> add an optional `job_opening_id` scope for a hiring manager (a nullable `job_applications.job_opening_id` already exists; the scope is "openings I own"). §4 -> add `view_logs` to the `contact_inquiries` module and render `ip_address`, `user_agent`, `utm_*`, `filled_in_seconds` only for its holders. §10.5 -> CV download stays in the §107 sensitive set. |
| **F-12.5** med | **No money artefact on the public disk.** (D21) | phase-13 §2.6 -> `expenses.receipt_path` moves to the private `local` disk under `expenses/`, served by `admin.expenses.receipt` with a policy check; §2.4 -> `invoices.pdf_path` explicitly private `local` under `invoices/`, served by `admin.invoices.pdf`. phase-24-25 `upload-manifest.php` -> both rows (disk + permission). |
| **F-12.6** med | `ProjectManagerScope` covers `expenses` too. | phase-13 §9 -> extend the scope to `expenses`: `whereHas('project', …)`; `project_id IS NULL` rows are invisible to a PM (same rule as invoices). |
| **F-12.7** low | An allowlist, not a judgement. | phase-08-09 §6.6 -> "the feed renders only the `properties` keys returned by `CollaboratorActivityEvent::visibleProperties()` and never `reason`"; add the enum/class to its §13.2 and a test asserting an unlisted key is absent from the response. |
| **F-13.1** med | **No REST API in this release** (see §6, needs_human). | spine §2.19 -> "the idempotency key is generated server-side per submission; if an API is ever added it supplies one through an `Idempotency-Key` header". phase-08-09 §3.2 -> keep `ReferralSource::api` (reserved for a future import). No `routes/api.php` in any contract. |
| **F-13.2** med | Fragmentation is accepted and written down; the two real holes are closed. | phase-06 §2.9 -> add `Collaborator` and `Invoice` to the `attachments` morph map (both with a policy). phase-19-23 [D-22-3] -> unchanged plus the morph note. `CLAUDE.md` §3 -> paste §5 block B (owner). phase-08-09 -> §59's `files_upload`/`files_download` resolve to `attachments` rows owned by the collaborator. |
| **F-13.3** med | Implemented reading stands: a collaborator payout is money **out** (see §6, needs_human). | phase-13 §6.7.3 -> label P&L block C "Collaborator commission (cost)" and add the legend line "payments *to* collaborators; §29's phrase is a cost, never income". No schema change. |
| **F-13.5** med | Ticket SLA is **built behind a switch** (see §6). | phase-19-23 §2.18 -> every SLA clock column nullable; §5 -> add `support.sla_enabled` boolean default **true**; §6.16 -> when false the sweeper is a no-op, no breach badge renders, and `TicketSlaService::multiplier()` is never called. |
| **F-13.6 … F-13.13** low | All are **built** as designed, recorded as deliberate scope. | Owner pastes the §6.2 scope table into `DEVELOPMENT_LOG.md` §9. No contract edit except F-13.12: phase-08-09 §2.4 -> expose the retention as `collaborator.referral_visit_retention_days` (default 365) so the client can shorten it without a migration. |

---

## 4. Decision-number registry

### 4.1 The numbers

`DEVELOPMENT_LOG.md` §4 holds **D1–D16 frozen** (D16 = append-only financial tables carry no `deleted_at`,
approved 2026-09-12). This table is the only source of D17 onwards. One number = one distinct decision.
No contract may invent a number; it cites one from here.

| # | Phase that needs it | Decision (one line) |
|---|---|---|
| D17 | spine (10) | DB CHECK constraints, STORED generated columns and `BEFORE DELETE` triggers are part of the contract: expect raw SQL errors on an illegal DELETE, and factories never delete money rows. |
| D18 | spine (10) | A payout allocates **named ledger entries with partial amounts**, never a running balance; "paid commission" is always derived from allocations. |
| D19 | 3 onwards | **Soft-delete categories:** append-only money, audit, log, snapshot, revision, counter and history-pivot tables carry **no** `deleted_at`; mutable business tables carry it. The full rule is in `CLAUDE.md` §3. |
| D20 | 1 (before 3) | `Modules::permissionModuleMap()` returns **null** for an unregistered prefix and `Gate::before` falls through on null; `client_portal.*`, `student_portal.*`, `teacher_portal.*`, `collaborator_portal.*` are permission namespaces, not modules. |
| D21 | 4 onwards | **No private artefact on the public disk**: CVs, documents, receipts, invoice PDFs, materials, submissions, certificates, ID cards and exports are streamed by a controller that re-runs the full permission chain; no signed URL, no public path. |
| D22 | 3 | Public content is published by **snapshot** (`content` -> `published_content`) and invalidated by a cache **version stamp** (the database cache driver has no tags). |
| D23 | 3 / 4 | **One SEO store**: `seo_meta` via `morphOne` + `SeoService`; no phase adds SEO columns to its own table. |
| D24 | 3 / 4 | **Two media tiers**: `media_assets` + `MediaService` is mandatory for anything rendered on the public website; a bare `*_path` is allowed only for a private profile photo or document. |
| D25 | 3 / 4 | **One HTML sanitiser**: `App\Support\RichText::sanitize()` over `mews/purifier`, applied on write **and** on render. |
| D26 | 4 | A content module may gate **its own** public routes with `site_module` (404, no trace); disabling `website_sections` can never take the public site down. |
| D27 | 5 | `App\Services\Finance\DocumentNumberService` is the **single** numbering implementation, shipped by Phase 5; every caller passes its own pad; `reserve()` adds the period reset. |
| D28 | 5 | Later-phase data reaches an earlier phase's screens only through a capability contract or a registry; an unavailable capability renders an empty state or 404s, never a fabricated number. |
| D29 | 5 | Duplicate contacts are **warned**, never blocked by a DB constraint; normalisation happens in `ContactNormalizer` into plain indexed columns. |
| D30 | 5 | `leads.view_any` means the whole pipeline, `leads.view` means own records only; no new ability and no visibility setting. |
| D31 | 5 | Phase 5 owns `routes/client.php` and every `client.*` route name; later phases append screens through `ClientPortalRegistry`, and **every** client route carries `client.context`. |
| D32 | 6 (binds 7, 13, 14-17) | **Assignment of work -> `users.id`; organisational duty -> `employees.id`; the bridge is `employees.user_id` (nullable, unique).** |
| D33 | 6 | Elapsed time is append-only `time_entry_segments`; every duration is a recomputed cache and "one running timer per worker" is a unique index. |
| D34 | 6 | Progress is derived by `ProjectProgressService`, the only writer of `progress_percent`; a manual override is reasoned, attributed and revocable. |
| D35 | 6 | `project_value_revisions` is append-only with a `BEFORE DELETE` trigger; the five value/override columns on `projects` are writable only by `ProjectValueService`. |
| D36 | 7 | A payroll run is immutable from `lock()`: there is no unlock, and a correction is a new item on a `correction` run referencing the original. |
| D37 | 8 / 9 | `collaborator_referrals` is the single truth of attribution; every `*.collaborator_id` / `*.referral_code` on a subject table is a display snapshot, read by no engine and **used by no scope**. |
| D38 | 9 | A referral decision follows a documented six-rank precedence ladder in which an authenticated staff selection wins, the losing candidate is preserved as a superseded row, and nothing the browser posts is trusted as a code. |
| D39 | 10-12 | Exactly one calculation site per commission side, reached only through an `afterCommit` event -> unique job chain; payments and ledger rows are inserted only through `PaymentService` / `LedgerWriter`. |
| D40 | 13 | `invoices.paid_amount` / `refunded_amount` / `balance_amount` are a cache of one canonical SQL over `project_payments`; nothing may `increment()` them. |
| D41 | 13 | `finance_reversals` is the append-only expense / other-income counterpart of `payment_reversals`; the two are never merged and neither is ever deleted. |
| D42 | 13 | An invoice number is assigned once at issue, never to a draft and never reused; a cancelled invoice keeps its number. |
| D43 | 13 | **The single concession to INV-8**: `project_payments.invoice_id` may move NULL -> value -> NULL, by `InvoiceService` alone, reason-mandatory, audited, gated by **`project_payments.link_invoice` + `invoices.edit`** (a dedicated narrow ability, **not** `project_payments.edit`, which does not exist and never will — so the spine's "a received payment is never editable" rule stays literally true), with zero commission effect. |
| D44 | 13 | One `approved` expense row per **paid** payroll run (`expenses.source_type` / `source_id`, unique), so §99's profit and loss includes salaries exactly once. |
| D45 | 14-17 | The §68 pipeline is carried by `student_admissions.stage`; `students.status` and `course_inquiries.status` advance in lockstep, written only by `AdmissionService`. |
| D46 | 14-17 | Attendance and syllabus coverage attach to a dated `class_sessions` row, never to a recurring timetable rule. |
| D47 | 14-17 | Schedule overlap is enforced by `ScheduleClashDetector` (one generic `check(SlotCandidate)`) under parent row locks plus a nightly verifier, because MariaDB cannot express a range constraint. |
| D48 | 14-17 | Capacity is enforced by a locked recount; `batches.current_students` is a cache with no authority. |
| D49 | 18 | An installment plan's live lines minus waivers always equal the charge's net fee; every discount redistributes the plan in reverse due-date order. |
| D50 | 18 | Installment numbers are never reused and never renumbered; a rebuild continues from `MAX + 1`. |
| D51 | 19-23 | A mark's ceiling is enforced three times — Form Request, service, and a DB CHECK made possible by snapshotting `total_marks` onto the row. |
| D52 | 19-23 | An issued certificate and an issued ID card are **snapshots**; a wrong one is revoked and reissued, never edited or deleted. |
| D53 | 19-23 | A print template is sanitised HTML with `{{tokens}}` rendered by a token replacer — never Blade, never `eval`. |
| D54 | 19-23 | §94's six role pairs live in `App\Support\MessagingMatrix` plus one multiselect setting, re-checked on every send. |
| D55 | 19-23 | Every notification is declared in `NotificationRegistry` and delivered by `NotificationService`; a disabled `notifications` module is a logged no-op that never rolls back a business write. |
| D56 | 19-23 | A report is a declaration in `ReportRegistry` that delegates every figure to the owning service; a `SUM()` in a report class is a review failure and a withheld column is absent from the query and the file. |
| D57 | 24-25 | Release deploys are directory swaps with a rename rollback. |
| D58 | 24-25 | Three separated MySQL users: runtime DML, migration DDL, backup read-only. |
| D59 | 24-25 | `Model::shouldBeStrict()` outside production is the N+1 audit. |
| D60 | 24-25 | The four manifests are the authority for every sweep, and `audit:manifest --check` is part of every phase's definition of done. |

### 4.2 Per-contract renumbering

| Contract | Old claim -> new number |
|---|---|
| spine | D16 -> D16 (unchanged) · D17 -> **D17** · D18 -> **D18** |
| phase-03 | D17 (soft deletes) -> **cite D19** · D18 (snapshot publish) -> **D22** |
| phase-04 | *(claimed none)* -> cites **D19, D23, D24, D25, D26** |
| phase-05 | D19 -> **D28** · D20 -> **D29** · D21 -> **D30** · *(adds D27, D31)* |
| phase-06 | D19 -> **D33** · D20 -> **D34** · D21 -> **D32** · D22 -> **D35** |
| phase-07 | D19 (HR soft deletes) -> **cite D19** · D20 -> **D36** · D21 -> **cite D32** (narrowed to duties) |
| phase-08-09 | D19 -> **D37** · D20 -> **D38** |
| phase-10-12 | D16/D17/D18 -> unchanged citations · D19 -> **D39** |
| phase-13 | D19 -> **D40** · D20 -> **D41** · D21 -> **D42** · Q3 -> **cite D19** · *(adds D43, D44)* |
| phase-14-17 | D19 -> **D45** · D20 -> **D46** · D21 -> **D47** · D22 -> **D48** |
| phase-18 | D19 -> **D49** · D20 -> **D50** |
| phase-19-23 | D22 (file streaming) -> **cite D21** · D23 -> **D51** · D24 -> **D52** · D25 -> **D53** · D26 -> **D54** · D27 -> **D55** · D28 -> **D56** |
| phase-24-25 | D16 -> cite D16 · D17 -> **D57** · D18 -> **D58** · D19 -> **D59** · D20 -> **D60** |

### 4.3 Paste into `DEVELOPMENT_LOG.md` §4 (owner only)

Delete the placeholder row that begins `| D17+ | Further decision numbers are assigned…` and paste these
rows in its place, after D16:

> **Already applied — do not re-paste (RD-2).** The owner has pasted this block into `DEVELOPMENT_LOG.md`
> §4: D17 … D60 are present there as individual rows, and the **D43 row there already carries the
> corrected wording** — the gate is `project_payments.link_invoice` + `invoices.edit`, with the note that
> the narrow ability exists precisely so that "a received payment is never editable" stays literally true.
> A reader arriving here later must **not** re-paste an earlier copy of this block over it: the version
> below is the corrected one, and anything quoting `project_payments.edit` is superseded text. If the log
> and this block ever disagree on D43, the spine wins (§1) and the reading is
> `project_payments.link_invoice` + `invoices.edit` (spine §1.4 guard 4, §4.1, §13.3; phase-13 §13.3).

```markdown
| D17 | **DB CHECK constraints, STORED generated columns and `BEFORE DELETE` triggers are part of the contract.** Developers must expect raw SQL errors on an illegal DELETE; factories and seeders never delete money rows. | The guarantee has to live where a future developer cannot forget it. Spine §2.1, §2.19. |
| D18 | **A payout allocates named ledger entries with partial amounts**, never a running balance; "paid commission" is always derived from `collaborator_payout_allocations`. | Requirement §120.9 needs a 20,000 payout against a single 50,000 entry without a manual split. |
| D19 | **Soft deletes are the default and are deliberately omitted on append-only tables.** Append-only = money, audit, log, snapshot/revision, view-counter, numbering and history-pivot tables. Mutable business tables (documents, profiles, catalogues, configuration) keep `deleted_at`. The full category rule is in `CLAUDE.md` §3. | Eleven contracts deviate from "every business table" for the same reason; one rule beats fifty exceptions. Audit F-9.1. |
| D20 | **`Modules::permissionModuleMap()` returns `null` for a permission whose prefix is not a registered module slug, and `Gate::before` falls through on null.** `client_portal.*`, `student_portal.*`, `teacher_portal.*` and `collaborator_portal.*` are permission namespaces, not modules. | Without it an unknown prefix reads as "disabled" and all four non-admin panels are dead for everyone, Super Admin included. Audit F-12.1. |
| D21 | **No private artefact is ever on the public disk.** CVs, client and employee documents, expense receipts, invoice PDFs, course materials, submissions, certificates, ID cards and exports are streamed by a controller that re-runs the full permission chain; no signed URL, no guessable path. | §111. One rule for every upload field, recorded in `upload-manifest.php`. |
| D22 | **Public website content is published by snapshot** (`content` -> `published_content`) and invalidated by a cache **version stamp**. | The database cache driver has no tag support. Phase 3 [D-W3-7], [D-W3-14]. |
| D23 | **One SEO store**: every public-facing entity gets SEO through the `seo_meta` morph and `SeoService`; no phase adds SEO columns to its own table. | Otherwise the SEO screen, the sitemap and the fallback chain cannot see half the site. Audit F-2.3. |
| D24 | **Two media tiers**: `media_assets` + `MediaService` + `ImageProfile` is mandatory for anything rendered on the public website; a bare `*_path` column is allowed only for a private profile photo or document. | One public image pipeline (derivatives, alt text, usage counting, delete guard); no second uploader. Audit F-2.4. |
| D25 | **One HTML sanitiser**: `App\Support\RichText::sanitize()` over `mews/purifier`, applied on write and again on render. | A duplicated security control is a security defect: SEC-05 must have one answer. Audit F-2.5. |
| D26 | **A content module may gate its own public routes with `site_module`** (404, leaving no trace); disabling `website_sections` can never take the public site down. | Keeps Phase 3's INV-15 guarantee and legalises Phase 4's middleware. Audit F-6.6. |
| D27 | **`App\Services\Finance\DocumentNumberService` is the single numbering implementation, shipped by Phase 5** (the earliest consumer), default pad `'%06d'`, every caller passing its own pad; `reserve()` adds a period reset. | Three phases each claimed to create it and four need it before Phase 10. Audit F-4.1, F-4.12. |
| D28 | **Later-phase data reaches an earlier phase's screens only through a capability contract or a registry**; an unavailable capability renders an empty state or 404s, never a fabricated number. | Phase 5 [D-P5-1]. |
| D29 | **Duplicate contacts are detected and warned, never blocked by a database constraint**; normalisation happens in `ContactNormalizer`, in PHP, into plain indexed columns. | Phase 5 [D-P5-5]. |
| D30 | **`leads.view_any` means the whole pipeline and `leads.view` means own records only**; no new ability and no visibility setting is introduced. | Phase 5 [D-P5-8]. |
| D31 | **Phase 5 owns `routes/client.php` and every `client.*` route name**; later phases append screens through `ClientPortalRegistry` and never redeclare a name, and every client route carries `client.context`. | Laravel's last registration wins silently: two URIs under one name is a live bug. Revocation must also be immediate. Audit F-6.2, F-12.3. |
| D32 | **Assignment of work points at `users.id`; an organisational duty points at `employees.id`; the bridge is `employees.user_id` (nullable, unique).** | Assignment drives login, permissions, notifications and timers; a duty is a post. Phase 6 [D-P6-2] and Phase 7 [D-HR-2] were each right about one half. Audit F-11.1. |
| D33 | **Elapsed time is stored only as append-only `time_entry_segments`**; every duration is a recomputed cache and "one running timer per worker" is a unique index. | Phase 6 INV-P4…P6. |
| D34 | **Progress is derived by `ProjectProgressService`**, the only writer of `progress_percent`; a per-project manual override is reasoned, attributed and revocable. | Phase 6 [D-P6-3]. |
| D35 | **`project_value_revisions` is append-only with a `BEFORE DELETE` trigger**, and the five value/override columns on `projects` are writable only by `ProjectValueService`. | Project value feeds commission. Phase 6 INV-P1, INV-P3. |
| D36 | **A payroll run is immutable from `lock()`**: there is no unlock, and a correction is a new item on a `correction` run referencing the original. | Phase 7 HR-15, HR-17. |
| D37 | **`collaborator_referrals` is the single truth of attribution.** Every `*.collaborator_id` / `*.referral_code` on a subject table is a display snapshot: no engine reads it and **no access scope may use it**. | A stale snapshot would otherwise grant one partner sight of another's project and contract value (§112). Audit F-8.2. |
| D38 | **A referral decision follows a documented six-rank precedence ladder** in which an authenticated staff selection always wins, the losing candidate is preserved as a superseded row with its reason, and nothing the browser posts is trusted as a code. | Phase 9 INV-R2, INV-R3. |
| D39 | **Exactly one calculation site per commission side**, reached only through an `afterCommit` event -> unique job chain; payments and ledger rows may be inserted only through `PaymentService` / `LedgerWriter`. | Phase 10 [D-IMP-2], [D-IMP-3] — the rule a future phase is most likely to break. |
| D40 | **`invoices.paid_amount` / `refunded_amount` / `balance_amount` are a cache of one canonical SQL** over `project_payments`; nothing may `increment()` them. | Phase 13 R-1. |
| D41 | **`finance_reversals` is the append-only expense / other-income counterpart of `payment_reversals`**; the two are never merged and neither is ever deleted. | Phase 13 §2.6. |
| D42 | **An invoice number is assigned once at issue**, never to a draft and never reused; a cancelled invoice keeps its number. | Phase 13 §2.9. |
| D43 | **The single concession to INV-8**: `project_payments.invoice_id` may move NULL -> value -> NULL, by `InvoiceService` alone, reason mandatory, audited, gated by **`project_payments.link_invoice`** + `invoices.edit`, with zero commission effect. `link_invoice` is a **dedicated narrow ability** (spine §4.1, in no Phase 1 preset, seeded to Accountant only) authorising exactly that one column move and nothing else — **not** `project_payments.edit`, which does not exist and never will. | An advance received before its invoice existed must be attachable or `invoices.paid_amount` can never be right. The narrow ability is what keeps the spine's rule "a received payment is never editable" **literally** true (INV-8, INV-5): no `edit` is ever registered on `project_payments` or `student_fee_payments`, where a registered `edit` could later be reused for a real edit. Nobody may later "tighten" INV-8 back, and nobody may widen `link_invoice` into a general `edit`. Audit F-4.10, ND-1. |
| D44 | **One `approved` expense row per paid payroll run** (`expenses.source_type` / `source_id`, unique), written by a listener on `PayrollRunPaid`. | Without it §99's profit and loss is wrong by the whole payroll. Audit F-3.9. |
| D45 | **The §68 admission pipeline is carried by `student_admissions.stage`**, with `students.status` and `course_inquiries.status` advanced in lockstep by `AdmissionService` only. | Three status columns, one writer. Phase 15 R-10. |
| D46 | **Attendance and syllabus coverage attach to a dated `class_sessions` row**, never to a recurring timetable rule. | Every later phase (exams, certificates, reports) depends on it. Phase 16 [D-IN-10]. |
| D47 | **Schedule overlap is enforced by `ScheduleClashDetector`** (one generic `check(SlotCandidate)`) under parent row locks plus a nightly verifier. | MariaDB cannot express a range constraint, so nobody should "fix" this with a unique index. Phase 16 R-1. |
| D48 | **Capacity is enforced by a locked recount**; `batches.current_students` is a cache with no authority. | Phase 16 INV-I6, INV-I7. |
| D49 | **An installment plan's live lines minus waivers always equal the charge's net fee**, and every discount redistributes the plan in reverse due-date order. | Phase 18 PI-1. |
| D50 | **Installment numbers are never reused and never renumbered**; a rebuild continues from `MAX + 1`. | Fee disputes quote a number. Phase 18 R-5. |
| D51 | **A mark's ceiling is enforced three times** — Form Request, service, and a database CHECK made possible by snapshotting `total_marks` onto the result / submission row. | Phase 19/20 INV-19-6, INV-20-1. |
| D52 | **An issued certificate and an issued ID card are snapshots**; a later rename, template edit or settings change alters neither their content nor their QR target. A wrong certificate is revoked and reissued. | Phase 21 INV-21-1, INV-21-4. |
| D53 | **A print template is sanitised HTML with `{{tokens}}`**, rendered by a token replacer — never Blade, never `eval`. | Phase 21 INV-21-5. |
| D54 | **§94's six role pairs live in `App\Support\MessagingMatrix`** plus one multiselect setting, re-checked on every send. | It is the reason a student cannot message a client. Phase 22 INV-22-4. |
| D55 | **Every notification is declared in `NotificationRegistry`** and delivered by `NotificationService`; a disabled `notifications` module is a logged no-op that never rolls back a business write. | Phase 22 INV-22-7, INV-22-8. |
| D56 | **A report is a declaration in `ReportRegistry` that delegates every figure to the service owning the table**; a `SUM()` in a report class is a review failure, and a withheld column is absent from the query and the file. | Phase 23 INV-23-1, INV-23-2. |
| D57 | **Release deploys are directory swaps with a rename rollback.** | Phase 25. |
| D58 | **Three separated MySQL users**: runtime DML, migration DDL, backup read-only. | Phase 25; closes tech debt T3. |
| D59 | **`Model::shouldBeStrict()` outside production is the N+1 audit.** | Phase 24. |
| D60 | **The four manifests are the authority for every sweep**, and `audit:manifest --check` is part of every phase's definition of done. | Phase 24 §13.2. |
```

---

## 5. Paste into `CLAUDE.md` §3 (owner only)

Replace the closing sentence of §3 ("Every business table carries: `created_at`, `updated_at`,
`deleted_at` (soft deletes), `created_by`, `updated_by` …") with **block A**, then append **blocks B and
C**.

**Block A — soft deletes (F-9.1, D16, D19)**

```markdown
Every business table carries `created_at`, `updated_at`, `created_by`, `updated_by` (nullable FK to
`users`, filled by the `Blameable` trait).

**Soft deletes are the default, and are deliberately omitted on append-only tables.** A table is
append-only — and therefore carries **no `deleted_at`** — when it belongs to one of these categories:

| Category | Examples |
|---|---|
| Money received, returned or paid out | `student_fee_payments`, `project_payments`, `payment_reversals`, `finance_reversals`, `collaborator_payouts`, `collaborator_payout_allocations` |
| Money authorised or promised | `student_fee_discounts`, `collaborator_commission_settings` (rule versions), `collaborator_commission_entitlements`, `collaborator_commission_ledger_entries` |
| Attribution and other evidence | `collaborator_referrals`, `collaborator_referral_visits`, `lead_conversions`, `project_value_revisions` |
| Audit, log and run history | `activity_log`, `login_history`, `sitemap_generations`, `lead_import_rows`, `collaborator_wallet_reconciliations`, `blog_post_views`, `course_material_downloads`, the eleven append-only HR tables |
| Snapshots and revisions | `cms_revisions`, `seo_meta`, certificates, ID cards, payroll run items |
| Append-only children and history pivots | `invoice_items`, `time_entry_segments`, `task_comment_mentions`, `collaborator_skills`, `collaborator_service` |

Everything else — documents, profiles, catalogues, configuration rows, mutable pivots — keeps
`deleted_at`. A table without `deleted_at` is protected by a model `deleting` hook and, where its
contract says so, a `BEFORE DELETE` trigger. **Never add `deleted_at` back to one of these tables:** a
nullable `deleted_at` on an immutable ledger lets one `->delete()` hide a row from every aggregate while
the wallet cache keeps the money, and on a NULL-tolerant unique guard it silently permits a duplicate.
See `DEVELOPMENT_LOG.md` §4 **D16** (the nine financial tables, approved) and **D19** (the general rule).
```

**Block B — the `files` module slug (F-2.8, F-13.2)**

```markdown
**The `files` module slug governs the `attachments` table.** There is no `files` table. `attachments` is
the general store (projects, tasks, comments, milestones, tickets, replies, messages, meetings,
assignments, collaborators, invoices); five specialised stores are named exceptions because each carries
behaviour a generic table cannot — `client_documents`, `employee_documents`, `course_materials` (+
`course_material_targets`), `assignment_submission_files`, and `media_assets` for CMS images. Client
visibility is `attachments.visibility` (`internal` / `team` / `client`), never a boolean on another table.
```

**Block C — percentages and money artefacts (F-7.1, F-12.5, D21)**

```markdown
Every `*_rate` and `*_percentage` column is `decimal(8,4)` — there is no "reported percentage" exception
(marks are `decimal(8,2)` and are not percentages). No private artefact is ever written to the `public`
disk: uploads land on a private disk and are served by a controller that re-runs the permission chain
(`DEVELOPMENT_LOG.md` §4 D21).
```

---

## 6. Needs human

### 6.1 Decisions the business owner must make

| # | Question | Recommended default | What the applying agents implement **now** (keeps both options open) |
|---|---|---|---|
| H1 | **REST API** (§1 "REST API where required", §6 "blocks API access") — in this release or not? (F-13.1) | **No REST API in this release.** | No `routes/api.php`, no token guard. The idempotency key is generated server-side per submission; the spine's sentence becomes "if an API is ever added it supplies the key through an `Idempotency-Key` header". `ReferralSource::api` stays as a reserved case. Adding an API later needs no schema change. |
| H2 | **§29 "collaborator payments" as tracked income** — money *to* collaborators (a cost) or *from* them? (F-13.3) | **To** collaborators: a cost. | P&L block C is labelled "Collaborator commission (cost)" with a legend line. If the client means money *from* collaborators, it becomes an `incomes` category row later — `incomes` already has categories, so no schema change. |
| H3 | **Ticket SLA** — §93 never asks for it; it is four settings, two clock columns, a pause rule, a sweeper and five tests. (F-13.5) | **Build it.** | Ship behind `support.sla_enabled` (boolean, default **true**); every clock column nullable. Off = no sweeper, no breach badge, no multiplier call. Dropping it later is a settings flip, not a migration. |
| H4 | **Commission approval mode** — §120.2 says "one commission of PKR 1,000" without a status; FIN-03 asserts `pending`. Should a new commission be immediately `available`? | **Keep `manual`.** Money should not become payable without a human. | `collaborator.commission_approval_mode` already exists (`manual` default); FIN-03 asserts `pending`. Switching to `auto` is a settings change and FIN-03 gains one branch. |
| H5 | **Referral-visit retention** — 365 days of IP and user-agent per click. (F-13.12) | **365 days**, shortenable. | Expose `collaborator.referral_visit_retention_days` (default 365) so the client can shorten it without a migration; the prune command reads the setting and never prunes a visit a `collaborator_referrals.referral_visit_id` or `leads.referral_visit_id` points at. |
| H6 | **Public signed invoice link** (`invoices.public_token`) for clients with no login. (F-13.9) | **Build it**, off by default. | `finance.invoice_public_link_enabled` default **false**, `finance.invoice_public_link_ttl_days` default 14; the guest route 404s when disabled. |
| H7 | **CV / applicant visibility** — who may see every CV in the system? (F-12.4) | HR full; hiring managers per opening; Digital Marketer none. | Implemented as stated in F-12.4 — no blanket `view_any` outside HR, an optional `job_opening_id` scope, the technical/PII block behind `contact_inquiries.view_logs`. Widening later is a role grant, not a code change. |

### 6.2 Scope table to paste into `DEVELOPMENT_LOG.md` §9 (owner only)

| Finding | Feature | Contract | Assumed default | Note |
|---|---|---|---|---|
| F-13.5 | Ticket SLA clocks and breach sweeps | phase-19-23 | **build** (behind `support.sla_enabled`) | H3 |
| F-13.6 | `notification_preferences` per user/event | phase-19-23 | build | mandatory events stay locked |
| F-13.7 | `cms_revisions` + revert, signed preview links, `sitemap_generations` | phase-03 | build | operationally necessary |
| F-13.8 | CSV lead import (wizard, chunked jobs, error CSV) | phase-05 | build | `leads.import` gates it |
| F-13.9 | Public signed invoice link | phase-13 | build, **disabled by default** | H6 |
| F-13.10 | `technologies` table + two pivots | phase-04 | build | §11/§12 call it a field; a table gives filters |
| F-13.11 | Backup restore wizard + scratch verification | phase-24-25 | build | "a backup is not a backup until it has been restored" |
| F-13.12 | `collaborator_referral_visits` funnel report + retention | phase-08-09 | build | H5 |
| F-13.13 | `grade_scales` / `grade_scale_bands` | phase-19-23 | build | §82 needs a grade; a scale makes it configurable |

### 6.3 Tracker lines to paste into `DEVELOPMENT_LOG.md` §5 (owner only)

```markdown
PHASE 8 — note: the financial spine's migration set (spine §1.3, 15 tables) is applied in the same
release, immediately after Phase 8's own migrations; the spine-dependent screens stay hidden behind their
module switches until then, and `collaborators:backfill-wallets` + `collaborators:seed-initial-rules` run
once afterwards (phase-08-09 §1.4 [D-P8-1]).

PHASE 18 — note: consumes Phase 10's `PaymentService` and the four fee tables (`student_fees`,
`student_fee_installments`, `student_fee_discounts`, `student_fee_payments`); Phase 18 creates no
financial table. It ships `student_fee_reminders`, the fee services and all fee screens.
```

---

## 7. Apply map

Each agent applies **only** the findings listed for its file, plus §2 (ownership maps) and §8.

| Contract file | Findings whose edits touch it |
|---|---|
| `docs/design/finance-commission-spine.md` | F-3.15, F-4.1, F-4.2, F-4.3, F-4.4, F-4.8, F-4.9, F-4.10, F-4.11, F-5.4, F-5.5, F-5.6, F-6.1, F-8.3, F-9.1, F-11.2, F-12.3, F-13.1, F-10.1 (§4.2 row) |
| `docs/phases/phase-01.md` | F-4.11, F-6.4, F-6.5, F-12.1 *(contract text only; the `Money` and `Modules` code changes belong to the Phase 1 remediation team)* |
| `docs/phases/phase-02.md` | F-8.3 *(confirm `DashboardRegistry` keys are unique and that `SystemHealthWidget` stays Phase 2's)* |
| `docs/phases/phase-03.md` | F-2.1, F-2.2, F-2.3, F-2.4, F-2.5, F-6.3, F-6.6, F-6.7, F-9.1, F-10.1 (D19, D22, D23, D24, D25) |
| `docs/phases/phase-04.md` | F-2.1, F-2.3, F-2.4, F-2.5, F-3.6, F-3.12, F-3.13, F-5.1, F-5.2, F-5.3, F-6.3, F-6.6, F-6.7, F-9.1, F-9.2, F-9.3, F-12.4, F-10.1 (cites D19, D21, D23, D24, D25, D26) |
| `docs/phases/phase-05.md` | F-2.1, F-2.6, F-2.8, F-3.2, F-3.4, F-3.5, F-3.7, F-4.1, F-4.5, F-4.12, F-5.3, F-6.2, F-9.1, F-12.1 (Q1 answered), F-10.1 (D27, D28, D29, D30, D31) |
| `docs/phases/phase-06.md` | F-2.6 (visibility stays), F-2.7, F-3.1, F-3.3, F-4.1, F-5.5, F-6.2, F-6.3, F-8.2, F-9.1, F-11.1, F-12.2, F-12.3, F-13.2, F-10.1 (D32, D33, D34, D35) |
| `docs/phases/phase-07.md` | F-3.9 (request satisfied), F-3.12, F-3.13, F-4.1, F-4.11, F-4.13 (no change), F-5.2, F-5.4, F-9.1, F-11.1, F-10.1 (D36, cites D19 + D32), factual drift #1 (the `hr` settings group is not the first since Phase 2) |
| `docs/phases/phase-08-09.md` | F-2.1, F-2.7, F-3.4, F-3.5, F-3.14, F-4.1, F-4.3, F-4.4, F-9.1, F-12.7, F-13.1, F-13.12, F-10.1 (D37, D38) |
| `docs/phases/phase-10-12.md` | F-3.15, F-4.1, F-4.2, F-4.3, F-4.4, F-4.8, F-4.9, **F-4.10**, F-4.11, F-5.4, F-5.5, F-6.1, F-8.3, F-9.1, F-11.2, F-11.3, F-10.1 (D39, cites D16-D18) · *(**F-4.10 added in round 3 — RD-2.** This contract owns two halves of D43 and was never given the row: §2.3 `[D-IMP-2]`'s Model-plan Guards row holds the `updating` whitelist that **enforces INV-8** and admits `invoice_id` on `ProjectPayment` only, quoting the spine §1.4 canonical clause verbatim; and §4.1 "New module slugs (spine §4.1)" is the table that actually **registers** `project_payments.link_invoice`. The omission is what produced ND-2 — the guard was edited without the apply map ever pointing here — and RD-1, where §4.1 still did not register the ability the spine declares.)* |
| `docs/phases/phase-13.md` | F-3.9, F-3.10, F-3.11, F-4.2, F-4.8, F-4.9, F-4.10, F-4.11, F-4.14, F-6.1, F-9.1, F-9.2, F-12.3, F-12.5, F-12.6, F-13.3, F-10.1 (D40-D44) |
| `docs/phases/phase-14-17.md` | F-2.2, F-3.8, F-3.14, F-4.1, F-4.3, F-4.6, F-4.7, F-4.12, F-5.3, F-5.8, F-5.9 (no change), F-7.1, F-7.3, F-9.1, F-9.2, F-11.1, F-10.1 (D45-D48) |
| `docs/phases/phase-18.md` | F-3.15, F-4.2, F-4.6, F-4.11, F-4.14, F-6.9, F-8.3, F-9.1, F-11.3, F-10.1 (D49, D50) |
| `docs/phases/phase-19-23.md` | F-2.5, F-2.6, F-2.8, F-4.7, F-4.13, F-4.14, F-5.7, F-5.10, F-6.5, F-6.8, F-7.1, F-9.1, F-11.5, F-13.2, F-13.5, F-10.1 (D51-D56, cites D21), factual drift #2, #3, #4 (settings-key attributions) |
| `docs/phases/phase-24-25.md` | F-2.5, F-4.11, F-7.1, F-7.2, F-9.1, F-9.2 (manifest rows), F-11.7, F-12.5 (upload manifest), F-10.1 (D57-D60, cites D16) |
| `CLAUDE.md` *(owner)* | §5 blocks A, B, C — F-9.1, F-2.8, F-13.2, F-7.1, F-12.5 |
| `DEVELOPMENT_LOG.md` *(owner)* | §4 block (§4.3) — F-10.1; §5 lines (§6.3) — F-11.2, F-11.3; §9 table (§6.2) — F-13.5…F-13.13 |

---

## 8. Do not change

These are **correct as they stand** (audit §15 plus the items the audit resolved in favour of no action).
No agent may "fix" them.

| # | Item | Why it stays |
|---|---|---|
| 1 | **One commission engine.** `LedgerWriter` is the only insert path (INV-21, model `creating` hook); seven contracts carry a forbidden-list. | F-8.1 — no duplicate commission logic exists anywhere. |
| 2 | **No float on money.** `App\Support\Money` everywhere; six static scans. | F-7.4. Optional later tidy: consolidate the scans into FIN-16 — not now. |
| 3 | **No financial deletes.** The nine append-only tables, their `BEFORE DELETE` triggers and model hooks; every correction is a new row. | F-8.4, D16, D17. |
| 4 | **The four duplicate-prevention layers** (`idempotency_key`, `uq_cle_dedupe`, `uq_cle_source`, `chk_cce_cap`, `chk_cle_allocation_ceiling`) and phase-10-12's 21-file migration plan. | spine §2.19 — reproduced correctly. |
| 5 | **404-not-403 on ownership failure**, everywhere. | Ids cannot be probed. |
| 6 | **Withheld columns absent from the response body** (not merely hidden in Blade). | phase-05/06/07/13 §4.5, spine §8.11, phase-19-23 INV-23-2, asserted by HD-5. |
| 7 | **Requirement 120's nine tests**, their exact method names and the `@group financial-120` annotation; FIN-01 is the guard. | §14 of the audit — fully reconciled. Never rename or reduce. |
| 8 | **The eleven Registry classes** (`PermissionRegistry` → `PrintTokenRegistry`), each "definitions in code, values in the DB", each filtered by module + permission. | The strongest pattern in the contracts. |
| 9 | **`AttendanceStatus` (HR) and `StudentAttendanceStatus` (institute) as two names.** | F-5.9 — one flat `app/Enums` namespace; merging them would be a merge conflict, not a simplification. |
| 10 | **`attachments` carrying both the blameable pair and `uploaded_by` / `uploaded_by_name`.** | F-9.4 — the snapshot must survive a user delete. |
| 11 | **Blameable omissions** on `collaborator_referral_visits`, `notifications`, `notification_preferences`, `report_exports`, `lead_import_rows`, `course_material_downloads`. | F-9.4, legal under D19. |
| 12 | **The Phase 3 ↔ 4, Phase 6 ↔ 8, Phase 14-17 ↔ 18 cycles.** | F-11.6 — broken by ordering and guarded FKs; never build them concurrently, never "fix" the dependency. |
| 13 | **`collaborator_referral_visits` owned by Phase 9**, written through `ReferralService::attach()` and never directly. | spine §2.8 note; INV-R6's prune guard. |
| 14 | **Private profile photos as bare `*_path`** (`employees`, `students`, `collaborators`, teachers, `clients.logo_path`). | D24 tier 2 — they are not public website content. |
| 15 | **`student_fees` / `student_fee_installments` / `collaborator_payout_accounts` keeping `deleted_at`.** | They are mutable documents and destinations, not append-only rows. |
| 16 | **§120.6-8 having no phase-18 HTTP twin.** | Phase 18 is the student side; phase-10-12 §11.1 and phase-13 §11.3 cover the project HTTP path. |
| 17 | **`CommissionRuleSource` having no `global_default` case** and [D-FS-9]'s "no silent fallback to the global default rate". | F-5.6 — only the stray column note is deleted, never the rule. |
| 18 | **Phase 7's `PayslipService`.** It exists (phase-07 §6: `render()`, `export()`). | F-4.13 — the audit's premise was wrong; do not add a second class. |
| 19 | **`collaborator.payout_single_inflight` as a service-level setting** rather than a unique index. | spine [D-FS-6] — the compare-and-swap already prevents double-spend. |
| 20 | **§2's cosmetics** ("AJAX where suitable", "smooth animations"). | F-13.4 — no contract needs to commit to them. |
| 21 | **The `refunded_amount` rollback living inside `rejectReversal()`'s own transaction**, with `PaymentReversalRejected` as a cache/notification leg only. | F-4.9 as amended by ND-7 — a second `afterCommit` decrement would double-decrement. Never add a listener that rolls `refunded_amount` back. |

---

## 9. Drift fixes (round 2)

Applied from [`consistency-audit-round-2.md`](consistency-audit-round-2.md) §4, which audited the
convergence pass and found thirteen new contradictions (ND-1 … ND-13). The rows below are the ones that
changed **this** file or were decided for it. §3's two corrected rows (F-4.9, F-8.2 / F-12.2) are binding in
their corrected form; the superseded wording must not be re-applied. No money guarantee was weakened — both
money corrections **tighten** the canonical text onto the implemented, safer design, and the spine still
wins on anything touching money.

| ND | Change made |
|---|---|
| ND-7 | §3.3's **F-4.9** row rewritten to the implemented form. `PaymentReversalRejected` stays the published rejection leg, but phase-10-12 §10.1's listener is now instructed to **recompute caches and notify only** (`RecomputeCachesAfterReversalRejected` over D40's canonical SQL plus `NotifyReversalRequester`); the instruction "add it plus the listener that rolls `refunded_amount` back" is **dropped**. The rollback and the payment-status restore belong to `PaymentService::rejectReversal()`'s own transaction under the payment's row lock (phase-10-12 §6.3, spine §2.18, phase-18 §6.10.5) — the safer-with-money reading (R1, R7) that all three money contracts implemented. The row now says in writing that a second `afterCommit` decrement would decrement twice, drive `refunded_amount` below zero and below `SUM(payment_reversals.amount)`, and break INV-9's ceiling; §8 row 21 records it so it cannot be "restored". |
| ND-8 | §3.6's **F-8.2 / F-12.2** row named `collaborator_referrals.subject_id`, **which does not exist**. Corrected to the real shape from spine §2.8: `subject_type` plus four explicit nullable subject FKs (`student_id`, `project_id`, `client_id`, `lead_id`) under CHECK `chk_cr_one_subject`, so the collaborator project scope reads `status = active AND subject_type = ReferralSubject::Project AND project_id = projects.id AND collaborator_id = mine`. The row records that phase-06 §9 already applied it this way and that the old wording must not be re-applied literally. D37's "a snapshot is never a scope" guarantee is unchanged. |
| ND-3 | **Decision recorded** (contract edits belong to phase-04 and phase-08-09): phase-08-09 §13.1's ask for `contact_inquiries.collaborator_id` / `.referral_code` / `.referral_visit_id` **stands**, and **phase-04 §2.20 is the owner that defines all three** — nullable, indexed, deferred guarded FKs (the `job_applications.employee_id` pattern of F-3.12), plus a phase-04 §13 Phase 8-9 block. All three are **display snapshots under D37**: written only by `SyncReferralSnapshot`, **read by no engine and used by no access scope**. This closes the F-3.4 / F-3.5 defect that F-2.1's retarget reproduced on `contact_inquiries`; §2.1's "Phase 4 owns `contact_inquiries`, later phases may request additive columns only" is the rule it follows. |
| ND-5 | **Decision recorded** (contract edits belong to phase-03 and phase-19-23): the one sanitiser gains a **named profile allowlist** — `App\Support\RichText::sanitize(string $html, string $profile = 'cms'): string`, owned by phase-03 §6.6, with a committed **`material`** profile for print templates and material HTML. phase-19-23 §6.13 calls `RichText::sanitize($html, 'material')` on save and again on render and adds no filtering of its own; its self-contradicting "configured with this wider tag set … no second purifier profile of its own" sentence is removed. **D25 is unchanged and is not weakened**: one sanitiser class, one code path, two allowlists — a profile is an argument, never a second purifier profile and never a second class (§2.3's `RichText` row reads the same way). D52's snapshot fidelity and D53's token templates keep `div` / `style` / `align` / `data:` images. |
| ND-11 | **Count corrected, no list changed**: F-4.11's canonical `Money` surface is **20 methods** (`add`, `sub`, `mul`, `div`, `percentage`, `percentageOf`, `compare`, `isZero`, `isNegative`, `abs`, `min`, `max`, `sum`, `round`, `roundTo`, `prorate`, `distribute`, `toMinor`, `fromMinor`, `format`) plus the `RemainderPlacement` enum — as phase-01 §3 publishes and phase-24-25 FIN-12 tests. phase-24-25's "19 methods" log row is corrected there. |

---

## 10. Drift fixes (round 3)

Applied from [`consistency-audit-round-3.md`](consistency-audit-round-3.md) §3, which re-checked
`ND-1 … ND-13` against the current contracts (0 OPEN) and filed eight residual items `RD-1 … RD-8` in the
*derived* files. The rows below are the ones that changed **this** file. This is the **last documentation
round**: anything not closed here is a build-time note, not a pending edit.

**No money guarantee was weakened.** RD-2 *narrows* the D43 concession — it replaces a gate naming a
registered `edit` on a money module with a gate naming a single-purpose ability that authorises one column
move — so INV-8 / INV-5 ("a received payment is never editable", "no financial row is ever deleted") stay
literally true, and `project_payments` / `student_fee_payments` still carry **no `edit`, for ever**. RD-6 is
a key **count**, not a key list: no setting was added, removed or renamed.

| RD id | Change made |
|---|---|
| **RD-2** | **D43's gate corrected in all three places in this file, from `project_payments.edit` to `project_payments.link_invoice` + `invoices.edit`:** §3.3's **F-4.10** row (including the `Edits required` column, which now also points at spine §4.1, phase-10-12 §4.1, `Ability::LinkInvoice` and the Accountant-only `RoleSeeder` grant), §4.1's **D43** registry row, and §4.3's **D43** paste row. Each carries the one-line reason: the spine's "a received payment is never editable" rule (INV-8, INV-5) must stay **literally** true, so the concession gets its own **narrow** ability — in no Phase 1 preset, on one slug, authorising exactly the `invoice_id` NULL → value → NULL move and nothing else — because a registered `edit` on a money module is a permission a later role edit, seeder or policy could quietly reuse for a real edit. Authority: spine §1.4 guard 4 ("Resolved — ND-1"), §4.1's seven-row property table, §13.3's approved paste wording, FT-52; phase-13 §13.3. |
| **RD-2** | **§4.3 now opens with an "already applied — do not re-paste" note.** The owner has pasted the §4.3 block into `DEVELOPMENT_LOG.md` §4 and the log's D43 row **already carries the corrected wording** (the `link_invoice` + `invoices.edit` gate, with the "narrow ability precisely so that a received payment is never editable stays literally true" reason). The note tells a future reader not to paste an older copy of the block over it, and restates that on any D43 disagreement the spine wins. |
| **RD-2** | **§7's apply-map row for `docs/phases/phase-10-12.md` gained the missing `F-4.10`.** That contract owns two halves of D43 — §2.3 `[D-IMP-2]`'s Model-plan Guards row (the `updating` whitelist that **enforces** INV-8 and admits `invoice_id` on `ProjectPayment` only, quoting spine §1.4's canonical clause verbatim) and §4.1's "New module slugs (spine §4.1)" table (which **registers** `project_payments.link_invoice`) — yet the row had never been listed, which is what produced **ND-2** and then **RD-1**. The cell records that history so the omission cannot recur. |
| **RD-2** | **§2.3's `RichText` row now pins the two-parameter signature.** `sanitize(string $html, string $profile = 'cms'): string` with a **closed class-level `PROFILES` map of exactly two entries** (`cms`, `material`), `UnknownRichTextProfileException` on anything else, the caller's string never forwarded to `mews/purifier` as a config key, the `material` delta spelled out and the non-weakenable common core named. It states in writing that the **single-argument signature must not be "restored"** (phase-19-23 §6.13 calls `sanitize($html, 'material')`, so a one-parameter signature is a `TypeError`) and that phase-21's own `PrintTemplateService::sanitize(string $html)` is a different method that delegates here. D25 is unchanged: one class, one code path, two allowlists. |
| **RD-6** | **The `website` settings group is 21 Phase-4 keys and 34 in total, not 22 / 35 — corrected in both places:** §2.4's group row and §3.6's **F-6.3** row (decision sentence **and** apply instruction). phase-03 §5.1a = 13 rows, §5.1b = 21 rows, phase-04 §5 = the same 21 key **names** (both contracts recounted and name-diffed independently), so 13 + 21 = **34**. Both rows now record that the old figure was the error, that there is **no 22nd Phase-4 key to restore** (F-6.3 applied literally told phase-03 §5.1 to list a key that does not exist), and that **a future `website.*` key is added to phase-03 §5 and phase-04 §5 in one edit**, bumping both totals together. |

**Filed elsewhere, not this file's work:** RD-1 (phase-10-12 §4.1 must register `link_invoice`), RD-3 and
RD-7 (phase-08-09's retention-sweep guard and the removed `uq_cr_superseded_by` sentence), RD-4
(`build-order.md` F17 / B-H2), RD-5 and RD-8 (`data-model/04-finance-collaborator.md`).
