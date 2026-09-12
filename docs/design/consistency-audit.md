# CONSISTENCY AUDIT - the thirteen contracts read against each other

**Status: advisory.** This document changes nothing. It reports every place the financial spine and the
twelve phase contracts contradict each other, name the same thing differently, ask for something the owner
never delivers, or leave a requirement uncovered. Each finding names the clashing documents, the problem
and the **minimal** fix. No contract is edited here; a human decides.

Sources read in full: [`finance-commission-spine.md`](finance-commission-spine.md),
[`../phases/phase-01.md`](../phases/phase-01.md) … [`../phases/phase-24-25.md`](../phases/phase-24-25.md),
[`../requirements.md`](../requirements.md), [`../../CLAUDE.md`](../../CLAUDE.md),
[`../../DEVELOPMENT_LOG.md`](../../DEVELOPMENT_LOG.md).

Severity: **critical** = the build cannot proceed or money/security is at risk · **high** = two developers
will write incompatible code · **medium** = a stated guarantee is untrue or a request is silently unmet ·
**low** = drift worth fixing before it spreads.

---

## Contents

| § | Contents |
|---|---|
| 1 | Summary scoreboard |
| 2 | Table ownership collisions |
| 3 | Columns referenced but never defined by the owner |
| 4 | Service, signature and support-class gaps |
| 5 | Enum duplication and drift |
| 6 | Permission, module-slug and route-name drift |
| 7 | Money, percentage and decimal typing |
| 8 | Commission-path integrity |
| 9 | Soft deletes, blameable columns, indexes |
| 10 | Decision-number collisions in `DEVELOPMENT_LOG.md` §4 |
| 11 | Phase-ordering problems |
| 12 | Data-isolation gaps |
| 13 | Requirement coverage - uncovered requirements and invented features |
| 14 | Requirement 120 - the nine acceptance tests, and who claims each |
| 15 | What is already clean (so nobody "fixes" it) |
| 16 | Recommended resolution order |

---

## 1. Summary scoreboard

| Area | critical | high | medium | low |
|---|---|---|---|---|
| Table ownership (§2) | 1 | 3 | 4 | 0 |
| Undefined columns (§3) | 1 | 8 | 5 | 0 |
| Services / signatures (§4) | 1 | 5 | 8 | 0 |
| Enums (§5) | 2 | 3 | 4 | 1 |
| Permissions / modules / routes (§6) | 0 | 2 | 5 | 3 |
| Decimal typing (§7) | 0 | 1 | 1 | 2 |
| Commission path (§8) | 0 | 1 | 2 | 0 |
| Soft deletes / indexes (§9) | 0 | 1 | 2 | 1 |
| Decision numbers (§10) | 1 | 0 | 0 | 0 |
| Phase ordering (§11) | 0 | 3 | 3 | 1 |
| Data isolation (§12) | 0 | 2 | 4 | 1 |
| Requirement coverage (§13) | 0 | 0 | 3 | 9 |
| **Total** | **7** | **32** | **41** | **18** |

Seven criticals: one table with three owners (**F-2.1**), one scoping rule built on a column that does not
exist (**F-3.1**), three classes/enums each claimed by two or three phases (**F-4.1**, **F-5.1**,
**F-5.2**), and the decision-number collision that blocks the first migration (**F-10.1**).

---

## 2. Table ownership collisions

### F-2.1 · critical · `contact_inquiries` has three claimed owners

| Document | Claim |
|---|---|
| phase-04 §2.20 | **creates** `contact_inquiries` with 40 columns, the routing state machine and `uq_contact_inquiry_routed_target` |
| phase-03 §1.3 | "Phase 3 does **not** create: … `contact_inquiries`" and §13.3 "**Phase 5** owns the contact section's form handling: the `contact` section type, `contact_inquiries`, and the §17 routing" |
| phase-05 §1.2 | dependency row "**3** | `contact_inquiries` and the event the public contact form fires" and §13.1 "`contact_inquiries.id` plus an event fired on receipt - **Phase 3**" |
| phase-08-09 §13.1 | "`contact_inquiries.collaborator_id`, `.referral_code`, `.referral_visit_id` - **Phase 3**" |

Three different phases are each told someone else owns it, and the phase that actually defines it (4) is
named by nobody. Phase 5 builds a listener against "Phase 3's contact-inquiry event" that Phase 3 does not
fire; Phase 4 fires `ContactInquirySubmitted` and expects Phase 5 to register a `CrmLeadInquiryTarget`
instead.

**Minimal fix.** Declare **Phase 4 the owner** (it is the only contract with the schema). Then: phase-03
§13.3 hands the contact *section type* to Phase 4, not Phase 5; phase-05 §1.2 / §13.1 retarget their
requests from Phase 3 to Phase 4 and replace `CreateLeadFromContactInquiry` with the
`CrmLeadInquiryTarget implements InquiryTarget` Phase 4 §6.10.4 specifies; phase-08-09 §13.1 retargets to
Phase 4.

### F-2.2 · high · `course_faqs` is forbidden by Phase 3 and created by Phase 14

phase-03 §1.3 lists `faqs` / `faq_categories` as shared CMS infrastructure with a `faqable_type` /
`faqable_id` morph created **specifically** for course FAQs, and §13.3 states "**Do not create**
`course_faqs` - use `faqs.faqable_type` / `faqable_id` (§90 course FAQs); the hook already exists".
phase-14-17 §2.1 row 8 and §2.10 create `course_faqs` anyway, with the note "Global site FAQs stay with
Phase 3's `faqs` module; this table is course-scoped".

Result: two FAQ stores, two admin screens, two sanitisation paths, and a dead nullable morph on `faqs`.

**Minimal fix.** Drop `course_faqs`; Phase 14's course screen writes `faqs` rows with
`faqable_type = Course`, `faqable_id = courses.id` under `courses.edit`, as phase-03 §6.13
`FaqService::save()` already allows ("`faqable_*` writable only by the owning phase's service").

### F-2.3 · high · per-entity SEO columns versus `seo_meta`

phase-03 §1.3 (`seo_meta` row): "every public-facing entity … `morphOne` + the `route_key` form. **No phase
adds SEO columns to its own table.**" §13.3 repeats it as a binding ask.

phase-04 adds to its own tables: `seo_title`, `seo_description` (`service_categories` §2.2,
`portfolio_categories` §2.6, `blog_categories` §2.13, `job_openings` §2.18); `seo_title`,
`seo_description`, `seo_keywords`, `og_image_path`, `noindex` (`services` §2.3, `portfolio_items` §2.7);
`seo_title`, `meta_description`, `meta_keywords`, `canonical_url`, `og_image_path`, `noindex`
(`blog_posts` §2.16). It even ships `<x-cms.seo-fields>` (§8.13) as a rival to phase-03's SEO drawer, and
phase-03 §8.12's SEO manager is specified to list "later-phase types" from `seo_meta`.

Consequence: phase-03's SEO screen, sitemap (`SitemapService` reads `seo_meta.sitemap_include` and
`robots`) and fallback chain cannot see Phase 4's 7 entity types at all. §105 becomes two features.

**Minimal fix.** Phase 4 drops the 7 × 5 SEO columns and declares `morphOne(SeoMeta::class)` plus a
`SitemapRegistry` provider per entity (which phase-03 §6.5 and phase-04 §13 already agree on). Keep
`<x-cms.seo-fields>` only if it writes `seo_meta`.

### F-2.4 · high · two media subsystems

phase-03 owns `media_assets`, `MediaService::store()`, `ImageProfile` (8 profiles, WebP derivatives,
`srcset`), `website_section_media`, `<x-site.image>`, and §13.3 states "**Do not create** a second
media/image table - use `media_assets` + `MediaService` + `ImageProfile`".

phase-04 §6.6 ships `App\Services\Cms\ImageUploadService` (its own disk layout `cms/{folder}/{Y}/{m}`,
its own MIME map, its own thumbnail), a `portfolio_images` table (§2.8), `<x-cms.image-field>` (§8.13),
and 11 `*_path` string columns. phase-04 §13 then asks Phase 3 for "`intervention/image` installed + a
shared image pipeline" - i.e. it knows the pipeline exists and still builds a second one.

Five further contracts store bare paths rather than `media_assets` ids: `clients.logo_path` (phase-05
§2.7), `collaborators.photo_path` (phase-08-09 §2.1), `employees.photo_path` (phase-07 §2.4),
`students.photo_path` (phase-14-17 §2.14), `teachers` photo. None of them gets derivatives, alt text,
usage counting or the delete guard.

**Minimal fix.** Accept two tiers explicitly and write it down: **`media_assets` is mandatory for anything
rendered on the public website** (Phase 3 and Phase 4 content), **a bare `*_path` is allowed for a private
profile photo** (employee, student, collaborator, client logo). Then phase-04 must drop
`ImageUploadService` and `portfolio_images` in favour of `media_assets` + a `portfolio_item_media` pivot,
or phase-03 must withdraw its §13.3 ban. Do not ship both uploaders.

### F-2.5 · high · two HTML sanitisers - a duplicated security control

| Document | Class | Implementation |
|---|---|---|
| phase-03 §6.6, INV-13 | `App\Support\RichText::sanitize()` | wraps `mews/purifier` with a committed `cms` profile; §13.4 requests `composer require mews/purifier ^3.4`; sanitises **on write and again on render** |
| phase-04 §6.9 | `App\Support\HtmlSanitizer` | in-house allowlist, no package (phase-04 Q7 "In-house `HtmlSanitizer` (no new dependency); flagged as R7"); sanitises on write only |

phase-24-25 §1.2 depends on **both** (`RichText::sanitize()` via Phase 3, `HtmlSanitizer` via Phase 4) and
SEC-05 requires every `{!! !!}` to name "the sanitiser that makes it safe" - which will be two different
answers for two halves of the same website. phase-19-23 §1.2 depends on `RichText::sanitize()` only, and
phase-21's `PrintTemplateService` (INV-21-5) sanitises template HTML with a third unnamed path.

**Minimal fix.** One sanitiser. Recommend phase-03's `RichText` + `mews/purifier` (it is already the
render-time control and already has an iframe host allowlist); phase-04 deletes `HtmlSanitizer` and calls
`RichText::sanitize()`; phase-04 Q7 is answered "adopt the package".

### F-2.6 · medium · `attachments` gains two competing client-visibility mechanisms

phase-06 §2.9 gives `attachments` a `visibility` column cast to `AttachmentVisibility`
(`internal` / `team` / `client`) with `visibleToClient(): bool`. phase-19-23 §2.27 ships
`add_client_visibility_to_attachments_table` adding **`attachments.is_client_visible` boolean default
false** "because Phase 5 §13 asked Phase 22 for it". Both now decide the same question, and phase-19-23
§13.1 asks Phase 6 to add the boolean as well.

**Minimal fix.** Keep `visibility`. phase-05 §9.2's Files rule becomes
`attachments.visibility = 'client'`; phase-19-23 drops the migration and retargets its `is_client_visible`
references to `visibility`.

### F-2.7 · medium · `project_collaborator` pivot requested but deliberately not created

phase-06 [D-P6-4] creates **one** table `project_members` (with `user_id` XOR `collaborator_id`) and
explicitly rejects "the two pivots `project_user` + `collaborator_project`". phase-08-09 §2.1 declares
`belongsToMany Project as assignedProjects` **through the pivot `project_collaborator`**, §9 scopes the
collaborator panel on "`collaborator_referrals` … and `project_collaborator`", and §13.2 requests the
pivot from Phase 6.

**Minimal fix.** phase-08-09 reads `project_members` with `wherePivotNull('deleted_at')` and
`whereNotNull('collaborator_id')`.

### F-2.8 · medium · the `files` module slug has no table

Phase 1 §4 registers `files` under `ModuleGroup::Shared` and seeds its permissions. phase-05 §9.2 scopes
the client Files screen on `files.is_client_visible` and §13.1 requests the column "from **Phase 22**".
phase-06 §2.9 creates `attachments` instead; phase-19-23 [D-22-3] forbids a `files` table outright.
So `module:files` gates routes over a table called something else, and phase-05's Files rule cannot
compile.

**Minimal fix.** Record in `CLAUDE.md` §3 that the `files` **module slug** governs the `attachments`
**table**; retarget phase-05 §9.2 / §13.1 to `attachments.visibility`. See also **F-13.2** for the wider
§96 fragmentation.

---

## 3. Columns referenced but never defined by the owner

Every "requests to other phases" row was cross-checked against the owning contract's schema.

### F-3.1 · critical · `tasks.is_client_visible` does not exist

phase-05 §9.2 (the binding client-panel scoping table): "Tasks | `tasks.project_id IN (select id from
projects where client_id = $C) AND tasks.is_client_visible = 1`". §13.1 requests
"`tasks.is_client_visible` boolean default **true** - **Phase 6** | §19 lists tasks on the client panel;
without the flag every internal task leaks". Test 74 asserts it.

phase-06 §2.5 defines no such column. Phase 6 instead gates the **entire** client task list with one
global setting `projects.client_can_see_tasks` (§5, §7.7, §9) - so either every task of every project is
visible to its client, or none is. Phase 5's per-task rule and its test 74 are unimplementable.

**Minimal fix.** Phase 6 adds `tasks.is_client_visible` boolean default true, indexed with
`(project_id, is_client_visible)`, and ANDs it with the setting. One column, one clause.

### F-3.2 · high · `task_comments.is_client_visible`

phase-05 §13.1 requests it (default **false**). phase-06 §2.7 defines `visibility` cast to
`CommentVisibility` with only `internal` and `team`, and phase-06 §9 gives the Client 403 on
`task_comments` entirely. The request is dead.

**Minimal fix.** Withdraw the phase-05 request (clients see no comments in this release) **or** add a
`client` case to `CommentVisibility`. Do not leave the request list claiming a column that will not exist.

### F-3.3 · high · `clients.assigned_to`

phase-06 §13.1 requests "`clients.assigned_to` (FK `users.id`, nullable) - **Phase 5**" and §9 builds the
Sales Executive project scope on it ("`projects.view` on projects of **their own** clients
(`clients.assigned_to = user` from Phase 5)"). phase-05 §2.7 has **`account_manager_id`** and no
`assigned_to`.

**Minimal fix.** phase-06 §9 and §13.1 read `clients.account_manager_id`.

### F-3.4 · high · `clients.collaborator_id` / `clients.referral_code`

phase-08-09 §13.1 requests both "- **Phase 5** | §45's client-side attribution". phase-05 [D-P5-6] stores
**`referral_code_captured`** + `referral_recorded_at` and **no** `collaborator_id`, and phase-05 test 55
is `test_neither_leads_nor_clients_has_a_collaborator_id_column` ("Neither `leads` nor `clients` has a
`collaborator_id` column (schema assertion for [D-P5-6])").

Two contracts assert opposite schemas, and both ship a test.

**Minimal fix.** Phase 5's design wins (the snapshot columns are a display cache, and phase-08-09's own
INV-R1 says a snapshot is never authority, so a `collaborator_id` on `clients` buys nothing the referral
table does not already give). phase-08-09 §13.1 retargets to `clients.referral_code_captured` and drops
the `collaborator_id` ask.

### F-3.5 · high · `leads.collaborator_id` / `.referral_code` / `.referral_visit_id`

Same clash as F-3.4 on `leads`. phase-08-09 §13.1 asks for three columns; phase-05 §2.1 provides
`referral_code_captured` and `referral_recorded_at` only, and deliberately no `referral_visit_id`.

**Minimal fix.** Either phase-05 adds `referral_visit_id` (a nullable guarded FK, harmless and genuinely
useful for the §38 funnel) **or** phase-08-09 accepts that a lead's visit evidence lives only on
`collaborator_referrals.referral_visit_id`. Pick one and edit the other contract.

### F-3.6 · high · `leads.interested_service_id`

phase-04 §6.10.4 (the binding field map) and §13 request `leads.interested_service_id` - nullable FK →
`services.id`. phase-05 §2.1 names it **`service_id`** and adds a free-text snapshot
`interested_service`.

**Minimal fix.** phase-04 §6.10.4 maps `contact_inquiries.service_id → leads.service_id`.

### F-3.7 · high · `leads.contact_inquiry_id` is not unique

phase-04 §13 requests it "nullable FK → `contact_inquiries.id`, `nullOnDelete`, **unique** | provenance of
a website lead **and the idempotency key that stops a second lead from the same inquiry**". phase-05 §2.1
declares the column with `nullOnDelete` and lists no unique index (its `Keys` block names
`uq_leads_no` only).

Without the unique index, phase-04's §6.10.2 step 8 ("the `uq_contact_inquiry_routed_target` unique index
is the last line of defence") protects the *inquiry* side but nothing stops phase-05's
`crm:import-pending-inquiries` (every 10 minutes) from creating a second lead for one inquiry - and
phase-05 §10.2 only promises the listener "idempotent on `contact_inquiry_id`", which is a SELECT guard.

**Minimal fix.** Add `UNIQUE uq_leads_inquiry(contact_inquiry_id)` to phase-05 §2.1. MariaDB ignores
NULLs, so manually-created leads stack freely.

### F-3.8 · high · `course_inquiries.contact_inquiry_id`

phase-04 §13 requests it from Phase 15, "nullable FK → `contact_inquiries.id`, `nullOnDelete`, **unique**
| provenance + idempotency, exactly as the lead". phase-14-17 §2.11 defines no such column; it has its own
`idempotency_key` for the public form, which is a different guarantee (it dedupes a replayed POST, not a
replayed route).

**Minimal fix.** Add `contact_inquiries_id`… use phase-04's exact name `contact_inquiry_id`, nullable,
unique, to `course_inquiries`.

### F-3.9 · high · `expenses.source_type` / `.source_id` (or `.payroll_run_id`)

phase-07 §13.1 and Q14: "`expenses.source_type` / `expenses.source_id` (or an `expenses.payroll_run_id`)
and a listener creating **one** expense row per paid payroll run - **Phase 13** | §29-30 and the
profit-and-loss report of §99 must include salaries."

phase-13 §2.6 `expenses` has neither column and no payroll listener. phase-13 §6.7.3's P&L has three
blocks (direct income, expenses, collaborator commission) and no salary line. **§99's profit & loss is
wrong by the whole payroll** until this is resolved.

**Minimal fix.** phase-13 adds `expenses.source_type` string(32) nullable + `expenses.source_id` bigint
nullable + `UNIQUE uq_exp_source(source_type, source_id)`, and a listener on phase-07's `PayrollRunPaid`
writing one `approved` expense with `context = general` and a reserved category.

### F-3.10 · high · `project_milestones.title` vs `.name`

phase-13 §1.2 ("`project_milestones` (`title`, `amount`)"), §13.1 ("`project_milestones.title`") and §8.2
("insert line from milestone") use `title`. phase-06 §2.4 names it **`name`**.

**Minimal fix.** phase-13 reads `project_milestones.name`.

### F-3.11 · high · `projects.project_manager_id` is a user, not an employee

phase-06 §2.1: "`project_manager_id` | FK `users.id` | nullable | `nullOnDelete`, index - §20".
phase-13 §9 `ProjectManagerScope`:
`whereHas('project', fn ($q) => $q->where('project_manager_id', $user->employee_id))`.

An employee id compared to a user id will silently match the wrong project or nothing at all. This is the
same `users.id` vs `employees.id` dispute as **F-11.1**, surfacing as a concrete broken scope.

**Minimal fix.** phase-13 §9 compares to `$user->id`.

### F-3.12 · medium · `job_applications.employee_id`

phase-07 §13.1 requests it ("§16's 'Selected' candidate becomes an employee; the link proves where the
hire came from"). phase-04 §2.19 defines no such column.

**Minimal fix.** phase-04 adds a nullable, indexed `unsignedBigInteger employee_id` with the FK deferred
to Phase 7's guarded migration, exactly as its own §2.1 pattern does for `department_id`.

### F-3.13 · medium · `team_members.employee_id`

phase-07 §13.1 and Q15 request it so the public Team section reuses `employees.name`, `photo_path`,
`designation`, `bio` and skills. phase-04 §2.9 defines `department_id` only, and states "**No `user_id`
link** - a team member is website content, not an account".

**Minimal fix.** phase-04 adds nullable `employee_id` (deferred FK). The "website content" principle is
preserved: publication stays an explicit CMS act, the employee row is only the source of defaults.

### F-3.14 · medium · `course_inquiries.referral_visit_id`

phase-08-09 §13.1 requests `collaborator_id`, `referral_code` **and `referral_visit_id`** on
`course_inquiries`. phase-14-17 §2.11 delivers the first two plus `referral_code_valid`, and no
`referral_visit_id` - although §2.13 `student_applications` and §2.14 `students` both have one.

**Minimal fix.** Add it to `course_inquiries` for symmetry, or withdraw the ask.

### F-3.15 · medium · `student_fees.generation_key`

phase-18 §2.4 / §13.1 / Q1 request `student_fees.generation_key` string(64) nullable **UNIQUE** from
Phase 10 ("makes the fee-structure and monthly generators duplicate-proof by INSERT instead of by
SELECT"). The spine §2.2 and phase-10-12 §2.2 migration 01 do not include it. phase-18 [D18-2] then runs a
documented **SELECT-under-a-row-lock** guard - the one check-then-act the spine exists to eliminate
(§2.19 "The principle: the INSERT is the check").

**Minimal fix.** Add the column to the spine §2.2 and to phase-10-12 migration 01. It is one nullable
unique column on a table the spine already owns.

---

## 4. Service, signature and support-class gaps

### F-4.1 · critical · `DocumentNumberService` is claimed by three phases

| Document | Claim |
|---|---|
| phase-05 [D-P5-3], §6.10, §13.1 | "Phase 5 therefore **creates that class at that exact path with that exact signature**… Phase 10 reuses it unchanged and **must not re-create it**." Default pad `'%06d'` |
| phase-07 [D-HR-14], §5, §13.1 | "**Phase 7 is the first phase that needs this class**, so Phase 7 ships it… **Phase 10 must reuse it, not re-create it**." Default pad `'%05d'` |
| phase-10-12 §1.3, §6.3 | Phase 10 "**Ships** … `DocumentNumberService`". Default pad `'%06d'` |

phase-07's premise is factually wrong (Phase 5 is earlier in the tracker and needs it for `lead_no` and
`client_code`); phase-06 §6.1 adds a fourth voice by making `ProjectNumberService` "delegate to it the
moment it exists", and phase-08-09 §2.1 calls it with `'%04d'`.

**Minimal fix.** **Phase 5 ships it** (earliest consumer), default pad `'%06d'`, every caller passes its
own pad explicitly. Edit phase-07 [D-HR-14] and phase-10-12 §1.3 to "reuse, do not create". Also resolve
**F-4.12** (`reserve()`), which extends the same class.

### F-4.2 · high · `PaymentService::refund()` has two published signatures

| Document | Signature |
|---|---|
| spine §6.2 | `refund(StudentFeePayment\|ProjectPayment $p, string $amount, string $reason, ReversalType, ?string $method): PaymentReversal` |
| phase-10-12 §6.3 | `refund(StudentFeePayment\|ProjectPayment $p, RefundData $data): PaymentReversal` |
| phase-18 §6.10.5 | calls `PaymentService::refund($p, $amount, $reason, ReversalType::PartialRefund, $method)` - the spine's form |

phase-10-12 opens with "Where this document and the spine appear to disagree, **the spine wins**", so the
DTO form is the error - but it is the form a developer reading phase-10-12 §6.3 will implement, and
phase-13 §6.5 calls `refund()` too.

**Minimal fix.** Adopt the DTO (`RefundData` is better than five positionals) and correct the spine §6.2
and phase-18 §6.10.5 to match. Whichever is chosen, one form.

### F-4.3 · high · `ReferralService::attach()` cannot fill the referral evidence columns

`collaborator_referrals` (spine §2.8) carries `referral_visit_id`, `landing_url`, `ip_address`,
`user_agent`, `referral_date` and `notes`. The published signature in spine §6.2 and phase-10-12 §6.3 is
`attach(Model $subject, Collaborator $c, ReferralSource $s, ?string $code = null, ?CarbonInterface $on = null)`
- five arguments, none of which carries any of those six columns.

Both consumers ask for the fix and neither gets it:
- phase-08-09 §13.2: "`ReferralService::attach()` must accept a `ReferralContext` DTO carrying
  `referral_visit_id`, `landing_url`, `ip_address`, `user_agent`, `referral_date`, `notes` - the columns
  already exist on `collaborator_referrals` but **the published signature cannot fill them**".
- phase-14-17 §13.1: the same, "**including the `ReferralContext` DTO Phase 9 §13 already asked for**".

Consequence: §38's click evidence never reaches the attribution row, `collaborator_referrals.referral_visit_id`
is permanently NULL, and phase-08-09 INV-R6 ("a referral visit that any
`collaborator_referrals.referral_visit_id` points at is never pruned") protects nothing.

**Minimal fix.** Add `?ReferralContext $context = null` as the sixth parameter in spine §6.2 and
phase-10-12 §6.3, and define the readonly DTO in `App\DataObjects\Collaborator\`.

### F-4.4 · high · `ReferralService::recordLosingCandidate()` does not exist

spine §2.8 specifies the losing-candidate row ("a race between a `?ref=` URL and a receptionist's manual
selection therefore resolves to exactly one attribution and **the loser is recorded as a superseded row
with its reason**"), phase-08-09 INV-R3 makes it an invariant, §6.3 modifier 7 describes the exact row, and
§13.2 requests the method because "only the spine may write the table, so the spine must expose the call".
Neither spine §6.2 nor phase-10-12 §6.3 publishes it.

**Minimal fix.** Add
`recordLosingCandidate(CollaboratorReferral $winner, Collaborator $loser, ReferralContext $ctx, string $reason): CollaboratorReferral`
to the spine §6.2 `ReferralService` block.

### F-4.5 · high · `ReferralService::copyAttribution()` does not exist

phase-05 §6.4 step (7) of the binding `convert()` algorithm: "attribution carried forward:
`ReferralRecorder::copyAttribution($lead, $client, $reason)` when available", and §13.1 requests
"`ReferralService::copyAttribution(Model $from, Model $to, string $reason)` (or an equivalent) - **Phase
10** | the spine's §6.2 contract has `attach` / `change` / `revoke` but **no subject-to-subject copy**".

Not published. phase-05 test 53 asserts it works.

**Minimal fix.** Either add the method, or phase-05's `convert()` calls
`attach($client, $collaborator, ReferralSource::ManualSelection, $code, $leadReferralDate, $ctx)` and
records the lead referral id in `lead_conversions.collaborator_referral_id`. The second option needs no
spine change and is the smaller edit.

### F-4.6 · high · four `StudentFeeService` methods four other contracts depend on

| Method | Requested by | In phase-18 §6.1? |
|---|---|---|
| `summaryFor($student\|$admission): FeeSummary` | phase-14-17 §13.1 (student index, batch roster, admission stepper, student panel, §57 collaborator list) - it even notes "**Not yet in Phase 18's published method list**" | no |
| `reassignBatch(StudentBatchEnrollment $from, Batch $to): int` | phase-14-17 §13.1 (same-course batch move must repoint `student_fees.batch_id`) | no |
| `withinServiceContext(): bool` | phase-14-17 §13.1 - and **phase-14-17 §2.15's `student_admissions` model `updating` hook is specified to call it**, so the Institute model will not compile without it | no |
| `outstandingFor(StudentAdmission\|StudentBatchEnrollment): string` | phase-19-23 §13.1 (the `fee_cleared` certificate-eligibility rule; "Phase 21 must never sum a fee row itself") | no |

**Minimal fix.** Add all four to phase-18 §6.1. `withinServiceContext()` is the urgent one - it is a hard
compile dependency of another phase's model.

### F-4.7 · medium · `ScheduleClashDetector::check(SlotCandidate)`

phase-19-23 §13.1 requests the generic form so §81 exams and §95 meetings join clash detection
("Without it, Phase 20 calls the three existing subject-specific checks in sequence, which is correct but
duplicated"). phase-14-17 §6.7 publishes the subject-specific checks only. INV-I8 ("No teacher, classroom
or batch is ever double-booked") is therefore not true for exams and meetings as written.

**Minimal fix.** phase-14-17 §6.7 adds `check(SlotCandidate $c): ClashReport` with the DTO
phase-19-23 specifies; the three existing methods become thin wrappers.

### F-4.8 · medium · `CollaboratorWalletService::payoutsPaidTotal()` and `CollaboratorStatementService::commissionAccruedTotal()`

phase-13 §13.1 requests both (P&L block C and the commission memo row) with the explicit note "Phase 13
must never sum payouts, allocations or ledger rows itself (spine INV-26, FT-42)". phase-19-23 §13.1
requests the same two for its nine `co.*` reports. Neither is in spine §6.2 nor phase-10-12 §6.3.

Consequence: phase-13 §6.7.3 and phase-19-23 §6.20 either violate INV-26 or cannot be built.

**Minimal fix.** Add both to spine §6.2 / phase-10-12 §6.3.

### F-4.9 · medium · `PaymentReversalRejected` event

phase-13 §13.1: "when an approver rejects a refund, the spine rolls `refunded_amount` back, and the
invoice caches must follow in the same request. The spine already fires `PaymentReversalRecorded` and
`PaymentReversalApproved`; **the rejection leg is missing**". spine §10.1 confirms the gap.

**Minimal fix.** Add `PaymentReversalRejected` to spine §10.1 and to phase-10-12 §10.1.

### F-4.10 · medium · `project_payments.invoice_id` mutability

phase-13 §13.1 asks for a **concession to INV-8**: "`project_payments.invoice_id` must be settable from
NULL → value and back to NULL by `InvoiceService` alone - add it to the spine INV-8 'lifecycle columns'
whitelist". spine INV-8 currently permits only `notes` / `reference_no` / `receipt_path`, and its policy
text says so. Without the concession, an advance received before its invoice existed can never be
attached and `invoices.paid_amount` can never be right (phase-13 §6.3 rules 4-5).

**Minimal fix.** Amend spine INV-8 to add `invoice_id`, with the four guards phase-13 already specifies
(conditional, audited, reason-mandatory, permission-gated twice, zero commission effect). Record it as a
decision so nobody later "tightens" INV-8 back.

### F-4.11 · medium · `App\Support\Money` - eleven requested methods, none in Phase 1

Phase 1 §3 publishes `add`, `sub`, `mul`, `div`, `percentage`, `compare`, `isZero`, `format`. Requested
and never consolidated:

| Method | Requested by |
|---|---|
| `min`, `max`, `abs`, `isNegative`, `sum(array)`, `round($v, $scale = 2)`, `prorate($amount, $part, $total)` | spine §13.2, phase-10-12 §13.1, phase-07 §13.1, phase-14-17 §13.2 |
| `percentageOf($part, $whole)` | phase-14-17 §13.2 |
| `toMinor`, `fromMinor`, `distribute($amount, $parts, RemainderPlacement)` | phase-18 §13.2 ("`distribute` is the only function that may split money") |
| `roundTo($amount, int $nearest)` | phase-13 §13.1 |
| confirm `percentage()` and `distribute()` are public and float-free | phase-24-25 §13.1, tested by FIN-12 |

phase-24-25 FIN-12 tests `distribute()` directly, so a missing method is a failing test, not a missing
feature.

**Minimal fix.** One edit to phase-01 §3 listing all twelve, with the stated contract (bcmath,
intermediate scale 6, final half-up at 2, strings in and out, never a float).

### F-4.12 · medium · `DocumentNumberService::reserve()` with a period reset

phase-14-17 §13.1: "`DocumentNumberService::reserve(string $counterKey, ?string $periodKey = null,
?string $periodValue = null): int` - **Phase 10** | §6.5 needs a counter reservation with a **period
reset**, which `next()` does not provide. **If Phase 10 declines, `StudentNumberService` implements the
identical `FOR UPDATE` logic locally and the duplication is recorded as tech debt.**"

Its `institute.student_id_sequence_scope` (`global` / `yearly` / `branch_yearly`) and the two readonly
`*_period` settings keys depend on it.

**Minimal fix.** Add `reserve()` to the same class (see F-4.1). A second `FOR UPDATE` counter
implementation is exactly the duplication every contract elsewhere forbids.

### F-4.13 · medium · `PayslipService` does not exist

phase-19-23 §1.2 lists it as **required** from Phase 7 ("`employees`, `departments`,
`attendance_monthly_summaries`, `payroll_runs` / `payroll_run_items`, **`PayslipService`**") and §13.1
repeats it ("a directory query, `attendance_monthly_summaries`, `PayslipService::export`"). phase-07 §6
publishes `PayrollRunService`, `PayrollCalculator`, `AdvanceService`, `LeaveBalanceService`,
`AttendanceSummaryService` - no `PayslipService`.

**Minimal fix.** phase-19-23 §13.1 already allows for it ("If the names differ, Phase 23 adapts; it never
re-sums"). Name the real class in phase-19-23 §1.2, or add `PayslipService` to phase-07 §6.

### F-4.14 · medium · `ReportExporter`, `ReportResult` and `layouts/print.blade.php`

phase-19-23 §1.2 lists from Phase 13 as **required**: "`layouts/print.blade.php`, `FinanceReportService`,
`ExportFormat`, **`ReportExporter::export(ReportResult, ExportFormat)`**, **`ReportResult`
(`{rows, groups, totals, meta}`)**, `FinanceVisibility`". §13.1 repeats the first and the two bolded.
phase-18 §13.2 also requests `layouts/print.blade.php` "- Phase 13 (or Phase 18 if 13 has not shipped
it)".

phase-13 §3 / §6 publish `ExportFormat`, `FinanceVisibility`, `FinanceReportService`, `InvoicePdfService`
and `FinanceReportService::report(...)`. There is **no** `ReportExporter`, no `ReportResult` DTO and no
print layout anywhere in phase-13.

**Minimal fix.** phase-13 §6.9 adds `App\Support\ReportResult` (readonly DTO) and
`App\Services\Reporting\ReportExporter::export(ReportResult, ExportFormat): StreamedResponse`, plus
`resources/views/layouts/print.blade.php`. Three additions that four later phases already assume.

---

## 5. Enum duplication and drift

`app/Enums/` is a flat namespace. Two classes with one name is a merge conflict, not a style question.

### F-5.1 · critical · `ContentStatus` declared twice with different cases

| Document | Cases |
|---|---|
| phase-03 §3 | `draft`, `scheduled`, `published`, `archived` (+ `isPublic()` true only for `published`, `isEditable()`) |
| phase-04 §3 | `Draft = draft`, `Published = published`, `Archived = archived` - **no `scheduled`** - and a **separate** `PostStatus` carrying `draft`, `scheduled`, `published`, `archived` |

phase-03 §13.3 instructs Phase 4 to "Reuse `ContentStatus`"; phase-04 §3 lists it under "Enums to add".
phase-04 casts `services.status`, `portfolio_items.status`, `team_members.status`, `success_stories.status`
to its three-case version while phase-03 casts `website_sections.status`, `pages.status`, `faqs.status`,
`cta_blocks.status` to its four-case version. `pages.status = scheduled` is load-bearing (phase-03 §6.4
`schedule()`, `cms:publish-scheduled`).

**Minimal fix.** One `ContentStatus` with all four cases (`scheduled` is harmless on a service - it simply
never occurs); phase-04 keeps `PostStatus` only if it adds behaviour `ContentStatus` lacks, otherwise
deletes it too.

### F-5.2 · critical · `EmploymentType` declared twice with different cases

| Document | Cases |
|---|---|
| phase-04 §3 | `full_time`, `part_time`, `contract`, `internship`, `freelance` - casts `job_openings.employment_type` |
| phase-07 §3 | `full_time`, `part_time`, `contract`, `internship`, `temporary`, `consultant` (+ `isSalaried()`, `leaveEligibleByDefault()`) - casts `employees.employment_type` and validates `leave_types.applies_to_employment_types` |

Neither contract mentions the other. `freelance` exists only in Phase 4; `temporary` and `consultant` only
in Phase 7; whichever class is written second either breaks a cast or silently loses cases.

**Minimal fix.** One enum with the union (`full_time`, `part_time`, `contract`, `internship`, `temporary`,
`consultant`, `freelance`) and Phase 7's two helpers. Phase 4 stops declaring it; phase-07 §3 gains
`freelance`.

### F-5.3 · high · three enums for one source vocabulary

| Document | Enum | Cases |
|---|---|---|
| phase-04 §3 | `InquirySource` | `website`, `facebook`, `instagram`, `tiktok`, `google`, `whatsapp`, `referral`, `walk_in`, `call`, `email`, `other` - declared as "**The superset of §18 lead sources and §86 inquiry sources, so Phases 5 and 15 reuse it rather than declaring a third list**" |
| phase-05 §3 | `LeadSource` | `website`, `facebook`, `instagram`, `tiktok`, `google`, `whatsapp`, `referral`, `walk_in`, `call`, `other` - casts `leads.source` **and `clients.source`** |
| phase-14-17 §3 | `CourseInquirySource` | `website`, `whatsapp`, `facebook`, `instagram`, `tiktok`, `call`, `walk_in`, `referral`, `other` - casts `course_inquiries.source` |

phase-04 §13 asks both phases to reuse `InquirySource` and raises it as Q8 ("Will Phases 5 and 15 reuse
it? **Reuse. Requested in §13**"). Both declined silently by declaring their own. Three classes, three
`color()` maps, three badge palettes for one concept, and a §99 "inquiries by source" report that cannot
union them.

**Minimal fix.** Keep **one** `InquirySource` with the eleven-case superset; `LeadSource` and
`CourseInquirySource` become deleted aliases. The column values are already identical strings, so no data
migration is involved.

### F-5.4 · high · `PaymentMethod` and `LedgerEntryType` each claimed by two phases

| Document | Claim |
|---|---|
| spine §3, phase-10-12 §3 | both listed among "all 30 enums", created by Phase 10 |
| phase-07 §3, [D-HR-14], §13.1 | "`LedgerEntryType` … Created **here** because Phase 7 needs it first; the financial spine §3 defines the same two cases and **must not create it twice**" and "`PaymentMethod` … **Verbatim from the financial spine §3**, created here because advance disbursement and salary payment need it first" - and §13.1 "**Phase 10 must not create them again**" |

phase-13 §3 and phase-14-17 §3 both correctly say they reuse the spine's. The contradiction is only
between Phase 7 and Phase 10.

**Minimal fix.** Phase 7 ships both (it migrates first); edit phase-10-12 §3 to mark them "reused, not
created", as phase-13 and phase-14-17 already do.

### F-5.5 · high · `CommissionCalculationType` claimed by two phases

phase-06 §3: "**`App\Enums\CommissionCalculationType`** (`percentage`, `fixed`, `manual`) is **created by
Phase 6** with exactly the cases, values and name the spine's §3 specifies, because
`projects.commission_type` casts to it and Phase 6 migrates first. **Phase 10 must reuse the existing
class and not redeclare it** (§13)."
spine §3 and phase-10-12 §3 list it as a Phase 10 enum.

**Minimal fix.** Same as F-5.4: Phase 6 ships it, phase-10-12 §3 marks it reused. phase-06 §1.3 already
states this; only phase-10-12 needs the edit.

### F-5.6 · medium · `CommissionRuleSource` - four values in a column note, three in the enum

spine §2.10's `rule_source` column note: "cast `CommissionRuleSource` (`collaborator_rule` /
`project_override` / **`global_default`** / `manual`)". spine §3 and phase-10-12 §3 declare three cases
without `global_default`. spine §6.1.1 [D-FS-9] is emphatic: "**There is no silent fallback to the global
default rate**".

The enum is right and the column note is a leftover. Left as-is, a developer will add the fourth case and
then someone will use it.

**Minimal fix.** Delete `global_default` from spine §2.10's note.

### F-5.7 · medium · `TicketPriority` versus the shared `Priority`

phase-06 §3 declares `Priority` (`low`, `medium`, `high`, `urgent`) with the binding note "Shared: §20
project priority, §22 task priority, and **Phase 22 tickets must reuse it rather than declare a second
one**". phase-19-23 §3.4 declares `TicketPriority` with **the same four cases**, adding only
`slaMultiplier()`.

**Minimal fix.** phase-19-23 casts `support_tickets.priority` to `Priority` and moves `slaMultiplier()`
onto `TicketSlaService` (an SLA multiplier is a support-domain policy, not a property of the word
"urgent"). See also **F-13.5** on whether SLA belongs here at all.

### F-5.8 · medium · `Gender` ownership note is wrong about ordering

phase-14-17 §3 declares `Gender` (`male`, `female`, `other`) with "**Phase 7 should reuse it** rather than
declare a second one". Phase 7 migrates first and declares no `Gender`; `employees` has no gender column
(§24 does not list one), so there is nothing to reuse. Harmless, but the note will confuse whoever builds
Phase 7.

**Minimal fix.** Delete the note, or move the enum to Phase 7 if a gender column is ever added there.

### F-5.9 · medium · `AttendanceStatus` naming - resolved, record it

phase-07 §3 owns `AttendanceStatus` (the seven §26 employee states). phase-14-17 §3 deliberately names
its four §75 states `StudentAttendanceStatus` with the reason stated ("two enums with one name in one
namespace is a merge conflict waiting to happen"). **No action** - recorded so nobody "simplifies" it.

### F-5.10 · low · one concept, two column names inside one contract

phase-19-23 §2.7 `assignment_submissions.marks_obtained` versus §2.12 `exam_results.obtained_marks`.
Both are "the mark a student got", both `decimal(8,2)`, both CHECK-ceilinged against a snapshotted
`total_marks`.

**Minimal fix.** Pick one (`obtained_marks` reads better beside `total_marks`) and use it in both tables.

---

## 6. Permission, module-slug and route-name drift

### F-6.1 · high · three different gates for the project-payments surface

| Document | Gate |
|---|---|
| spine §7.2 (admin project payments) | `module:payments`, `can:project_payments.view_any` |
| spine §4.1 | registers a **new module slug** `project_payments` (ModuleGroup Finance) |
| spine §7.6 (`GET /client/payments`) | `panel:client`, `can:client_portal.payments` - **no module middleware** |
| phase-05 §7 (`GET /client/payments`) | `module:project_payments`, `can:client_portal.payments` |
| phase-13 §4.2 | keeps `payments` as "the umbrella money-in module the spine already gates project payments with (`module:payments`)" **and** adds `view_reports` to the `project_payments` slug |

So disabling `payments` hides the admin register but not the client one; disabling `project_payments`
hides the client register (per Phase 5) but not the admin one (per the spine). phase-10-12 §9's
module-gating row lists **both** slugs, which is the only honest reading.

**Minimal fix.** One rule: `module:project_payments` on every project-payment route (admin and client);
`payments` stays the umbrella for phase-13's cross-source register only. Edit spine §7.2 and §7.6.

### F-6.2 · high · duplicate route names across phases

| Route name | Declared by | URI |
|---|---|---|
| `client.projects.index` / `.show` | phase-05 §7 **and** phase-06 §7.7 | same |
| `client.tasks.index` | phase-05 §7 (`/client/projects/{project}/tasks`) **and** phase-06 §7.7 (same) | same |
| `client.files.index` | phase-05 §7 (`/client/files`) **and** phase-06 §7.7 (`/client/projects/{project}/files`) | **different URIs, same name** |
| `client.files.download` vs `client.attachments.download` | phase-05 §7 vs phase-06 §7.7 | same act, two names |
| `client.payments.index` | spine §7.6 **and** phase-05 §7 | same |
| `client.milestones.index`, `client.progress.show` | phase-05 §7 only; phase-06 §8.11 folds both into `client.projects.show` | phase-05's two routes have no owner |
| `collaborator.projects.index` | spine §7.5 **and** phase-06 §7.6 | phase-06 Q5 flags it and accepts one screen - **resolved** |

Laravel's last registration wins, silently. `client.files.index` is the dangerous one: two different URIs
under one name means `route('client.files.index')` resolves to whichever file loaded last.

**Minimal fix.** phase-05 §7 is the authority for `routes/client.php` (it owns the file and the
`// Phase 22: client writes` marker). Phase 6 and the spine **append screens to Phase 5's route names**
rather than redeclaring them; `client.files.index` stays at `/client/files` with a `?project=` filter.

### F-6.3 · medium · the `website` settings group is created twice

| Document | Group definition | Keys |
|---|---|---|
| phase-03 §5.1 | `['label' => 'Website', 'icon' => 'globe-alt', 'sort' => 75, 'permission' => 'settings.edit']` | 13 (`cache_*`, `preview_ttl_minutes`, `image_*`, `menu_max_depth`, `revision_keep`, `hero_video_enabled`, `faq_accordion_open_first`, `show_theme_toggle`) |
| phase-04 §5 | "slug `website`, label **"Website & Forms"**, icon `globe-alt`, **sort 85**" | 22 (`*_per_page`, `blog_view_*`, `team_page_enabled`, `careers_*`, `cv_*`, `contact_*`, `spam_blocklist`, `inquiry_*`) |

`SettingsRegistry::groups()` is keyed by slug, so the second declaration overwrites the first's label and
sort. The 35 keys do not collide, but the group metadata does. phase-06 §5 independently uses **sort 85**
for its `projects` group, so two groups would tie.

**Minimal fix.** One `website` group - label "Website & Forms", sort 75 - declared once, with both key
sets merged. Move phase-06's `projects` group to sort 86.

### F-6.4 · medium · Phase 1's "all permissions are seeded now" is not true

phase-01 §4: "**Modules to register in Phase 1** (permissions for all of them are seeded now so later
phases only add UI)". Phase 1 registers **75** slugs. Later phases register **37** more:

| Phase | New slugs |
|---|---|
| 3 | `website_cta_blocks`, `website_media`, `faq_categories` |
| 4 | `service_categories`, `portfolio_categories`, `technologies`, `blog_tags` |
| 5 | `client_documents` |
| 6 | `task_comments` |
| 7 | `designations`, `employee_documents`, `work_shifts`, `holidays`, `leave_types`, `leave_balances`, `salary_components`, `salary_structures`, `salary_slips`, `employee_advances`, `employee_self_service` |
| 8-9 | `collaborator_payout_accounts`, `collaborator_referral_visits` |
| 10-12 / spine | `student_fee_payments`, `project_payments`, `payment_reversals`, `wallet_reconciliation` |
| 13 | `finance_categories` |
| 14-17 | `classrooms`, `student_applications` |
| 18 | `fee_reminders` |
| 19-23 | `assignment_submissions`, `grade_scales`, `print_templates`, `ticket_departments`, `audit_trail` |
| 24-25 | `system_health`, `integrity_checks` |

**112 modules total**, a third of them registered after Phase 1. Phase 1's `RoleSeeder` (18 roles, §5)
therefore cannot be complete, and seven contracts each append to it (phase-03 §13.1, phase-04 §13,
phase-06 §4.3, phase-07 §4.4, phase-08-09 §4.3, phase-14-17 §4.4, phase-19-23 §4.3, phase-24-25 §4.3).

**Minimal fix.** Reword phase-01 §4 to "the modules known at Phase 1; later phases append to the registry
and to `RoleSeeder`, both idempotently" - which is what every contract already does. No code change; the
sentence is simply false as written and a reviewer will read it as a closed list.

### F-6.5 · medium · `audit_trail` is a non-core `System` module

phase-19-23 §4.1 registers `audit_trail` with `ModuleGroup::System` and "every one `is_core = false`".
phase-01 §4 declares the System group "**`is_core = true`**, never disableable" for all ten of its slugs,
and phase-01 §6's `Gate::before` denies a disabled non-core module to everyone including Super Admin.

A disableable module in the group whose defining property is that it cannot be disabled. phase-24-25 §4.1
does the same for `system_health` and `integrity_checks`, but it states the reason ("the business must be
able to hide an operations screen without being told it cannot").

**Minimal fix.** Record in phase-01 §4 that `ModuleGroup::System` is a **grouping**, not a core flag, and
that `is_core` is per-module. Then all three are legal.

### F-6.6 · medium · two philosophies for gating public routes

phase-03 §9, last row: "`module:` … apply to **admin routes only**. **No public route carries a `module:`
or `can:` middleware**, because the public site is not a module's UI - it is the published output
(INV-15)." phase-03 INV-15 makes it an invariant: disabling `website_sections` must not take the site down.

phase-04 §7.1 / §7.3 introduces `site_module:<slug>` on nine public routes, which "aborts **404** (not
403) so a disabled module leaves no trace on the public site", and test 57 asserts that disabling
`blog_posts` makes `/blog` 404.

Both are defensible; they are not compatible as stated.

**Minimal fix.** Amend phase-03 INV-15 to "disabling `website_sections` never takes the public site down;
other Website modules may gate their own public routes through `site_module`" - which keeps Phase 3's
actual guarantee and legalises Phase 4's middleware.

### F-6.7 · low · the admin URI space for "the website" is split

phase-03 puts everything under `/admin/website/*` with route names `admin.website.*`. phase-04 puts
`services`, `portfolio`, `team`, `testimonials`, `student-reviews`, `success-stories`, `blog-*`, `jobs`,
`job-applications`, `contact-inquiries` at the top level (`admin.services.*` …). §100 describes one
"Website CMS" covering both sets.

**Minimal fix.** Sidebar-only: one **Website** group listing both, with a stated rule ("content *about*
the site lives under `/admin/website`; business entities the site renders live at the top level").
No route renames needed.

### F-6.8 · low · `site.enabled` versus `site`

phase-19-23 §1.2 and §13.1 require middleware alias **`site.enabled`**, attributing it to
"Phase 14-17 §7.10 (exists)". phase-14-17 §13.4 explicitly **dropped** it: "the first draft's
`site.enabled` is dropped; §7.10 uses Phase 3's three aliases". Phase 3 §6.10 ships `site`, `site.cache`,
`site.preview`.

**Minimal fix.** phase-19-23 uses `site`.

### F-6.9 · low · `student_fee_payments.approve` - a real spine gap, already closed

spine §6.6 row 6 and FT-39 both require "the module's `approve` ability" to post a back-dated receipt, but
spine §4.1 grants `student_fee_payments` no `approve`. phase-18 §4.2 and Q2 add it. **No action beyond
making the registry edit** - recorded here so the reason survives.

---

## 7. Money, percentage and decimal typing

### F-7.1 · high · percentage columns are `decimal(5,2)` in two contracts and `decimal(8,4)` everywhere else

`CLAUDE.md` §3: "Percentage column | `decimal(8,4)`". Honoured by the spine (`commission_rate`,
`rate`, `percentage`), phase-05 (`tax_rate_override`, `withholding_tax_rate`), phase-06
(`progress_percent`, `commission_rate`, `weight`), phase-07 (`payable_factor`, `attendance_percentage`,
`advance_recovery_cap_percent`, `tax_default_rate`) and phase-13 (`tax_rate`, `discount_rate`).

**Not** honoured by:

| Document | Columns at `decimal(5,2)` |
|---|---|
| phase-14-17 INV-I11 (explicit: "Every percentage in this phase … is `decimal(5,2)`") | `batches.syllabus_completion_percentage`, `student_batch_enrollments.attendance_percentage`, `.progress_percentage`, `batch_topic_coverage.completion_percentage`, `student_course_progress.completion_percentage`, `student_module_progress.completion_percentage`, `student_topic_progress.completion_percentage` |
| phase-19-23 | `assignments.late_penalty_percentage`, `assignment_submissions.percentage`, `grade_scales.pass_percentage`, `grade_scale_bands.min_percentage`, `.max_percentage`, `exams.weight_percentage`, `exams.average_percentage`, `exam_results.percentage`, `certificates.percentage`, `certificates.attendance_percentage` |

This is not cosmetic: **phase-24-25 FIN-18 asserts** "every `*_rate` / `*_percentage` is `decimal(8,4)`"
over `information_schema.COLUMNS` for the whole schema. Seventeen columns fail that test as written, and
the allowlist FIN-18 describes names only two exceptions (`progress` as tinyint, `rating` as tinyint).

**Minimal fix.** Either (a) change the seventeen to `decimal(8,4)` - two decimals is enough for a grade
band but `decimal(8,4)` costs nothing and keeps one rule; or (b) amend `CLAUDE.md` §3 to
"`decimal(8,4)` for a **rate** used in money arithmetic, `decimal(5,2)` for a **reported** percentage"
and extend FIN-18's allowlist accordingly. (a) is the smaller edit.

### F-7.2 · medium · FIN-18's regex also catches the marks columns

phase-24-25 FIN-18 requires every column matching
`amount|fee|salary|price|budget|balance|total|paid|discount|tax|value|commission` to be `decimal(15,2)`.
`assignments.total_marks`, `assignment_submissions.total_marks` and `exams.total_marks` are
`decimal(8,2)` and match on `total`. `course_topic_assignments.estimated_marks` is `decimal(8,2)` too.

**Minimal fix.** Add `*_marks` to FIN-18's allowlist with the stated reason ("marks are not money; the
ceiling is a per-row CHECK against a snapshotted total").

### F-7.3 · low · `estimated_hours` has two widths

phase-06 §2.5 `tasks.estimated_hours` `decimal(10,2)` (generated over `estimated_minutes`);
phase-14-17 §2.9 `course_topic_assignments.estimated_hours` `decimal(5,2)`.

**Minimal fix.** One width. Harmless either way; listed so the index manifest and FIN-18 allowlist agree.

### F-7.4 · low · no float arithmetic is proposed anywhere - record it

Thirteen contracts each describe money as decimal strings through `App\Support\Money` and each ships a
static scan (spine FT-41, phase-06 P6-55, phase-07 FT-HR-35, phase-10-12 test 54, phase-13 test 9,
phase-19-23 PH23-45, phase-24-25 FIN-16). Six independent scans over overlapping directories is itself
duplication, but no contract proposes float arithmetic on money. **No defect.**

**Minimal fix (optional).** Consolidate to phase-24-25 FIN-16, which already covers every namespace the
other five name.

---

## 8. Commission-path integrity

### F-8.1 · clean · every commission write goes through the spine's services

Checked every contract for a second commission path. Result: none.

| Contract | Statement |
|---|---|
| phase-05 §11 test 54 | "**No commission row is created anywhere by anything in this phase**" - asserted directly against `collaborator_commission_ledger_entries` |
| phase-06 §10.1 | `ProjectValueRevised` / `ProjectReferralLinked` are "the phase's contract with the commission spine… **Phase 6 must never assume what those listeners do**" |
| phase-07 §6.9 | "The collaborator commission engine **never posts into payroll**, and payroll never reads the commission ledger or a wallet" - employee commission is a manual typed component |
| phase-08-09 §1.1 | "**This phase creates no money.** … Not one line here inserts a ledger row, a wallet balance, a payment or a payout" |
| phase-13 §1.3 | "Any commission calculation, ledger row, wallet delta, entitlement or payout" - never created here |
| phase-14-17 INV-I1 | "**This phase never creates, updates or deletes a money row**" |
| phase-18 §6.9 | an explicit forbidden-list asserted by PH18-37 |
| phase-19-23 INV-23-1 | "A `SUM()` inside a report class is a review failure" |
| phase-24-25 HD-2 | "Phase 24 adds no production behaviour that can change money" |

`LedgerWriter` is the only insert path (spine INV-21, enforced by a model `creating` hook); spine §2.19's
layered dedupe (`uq_cle_dedupe`, `uq_cle_source`, `chk_cce_cap`, `chk_cle_allocation_ceiling`) is intact in
phase-10-12's migration plan. **No action.**

### F-8.2 · high · Phase 6 scopes a collaborator's projects on a display snapshot

phase-08-09 INV-R1 and spine §2.2 are unambiguous: a `*.collaborator_id` column on a subject table is a
**display snapshot** written by one listener, and "the engine reads neither". phase-14-17 §9 obeys it -
the collaborator's student list is "scoped by `students.collaborator_id` **and re-asserted against a
current `collaborator_referrals` row**".

phase-06 §9 (Collaborator row) does not: "`Project` -> projects where they hold an **active**
`project_members` row **or `projects.collaborator_id = my id`**".

A stale or mis-written snapshot therefore grants read access to another partner's project, its client name
and (with `collaborator_portal.project_value`) its contract value. That is a §112 breach driven by a column
the system's own invariant says is not authoritative.

**Minimal fix.** phase-06 §9 becomes: projects where an active `project_members` row exists **or** an
`active` `collaborator_referrals` row with `project_id = projects.id` and `collaborator_id = mine` exists.
One join, matching phase-14-17's pattern.

### F-8.3 · medium · duplicate dashboard-widget keys

`DashboardRegistry` is keyed by `key()`; two classes with one key means one card silently disappears.

| Widget | Claimed by |
|---|---|
| `FeeCollectedTodayWidget`, `PendingFeesWidget`, `OverdueFeesWidget` | spine §8.12, phase-10-12 §8.12 **and** phase-18 §13.2 (which asks "**Phase 10 must not also register** … whoever ships first owns the key") |
| `ProjectValuePipelineWidget` (phase-06 §8.12) vs `ProjectPaymentsThisMonthWidget` (spine §8.12) | different keys - no clash |
| `WebsiteContentWidget`, `SeoHealthWidget` | phase-03 §13.2 only |
| `BackupStatusWidget`, `IntegrityStatusWidget`, `FailedJobsWidget`, `QueueHealthWidget` | phase-24-25 §13.1; `SystemHealthWidget` is phase-02's and phase-24-25 asks it to read `SystemHealthService` |

**Minimal fix.** phase-18 owns the three fee widgets (it owns the screens); spine §8.12 and phase-10-12
§8.12 drop them and keep the eight commission/wallet ones.

### F-8.4 · medium · no financial row is proposed for update or delete - with one open concession

The nine append-only tables (spine §2.1) are never updated or deleted by any contract. The only mutation
asks are:

| Ask | Document | Status |
|---|---|---|
| `project_payments.invoice_id` NULL↔value | phase-13 §13.1 | **open** - see F-4.10 |
| `invoices.paid_amount` as a derived cache of `project_payments` | phase-13 §2.4, §2.8 | correct - derived, "Nothing may `increment()` it" |
| `student_admissions` money caches written only by Phase 18 | phase-14-17 §2.15 | correct - guarded by `withinServiceContext()` (F-4.6) |
| `student_fees` caches written only by `recomputeCaches()` | phase-18 §2.3 | correct - recomputed, never incremented |

**No action** beyond F-4.10.

---

## 9. Soft deletes, blameable columns, indexes

### F-9.1 · high · ~50 tables deviate from `CLAUDE.md` §3 and §3 is never amended

`CLAUDE.md` §3: "**Every** business table carries: `created_at`, `updated_at`, `deleted_at` (soft
deletes), `created_by`, `updated_by`". Eleven contracts deviate deliberately, each with a named decision
and a stated reason:

| Document | Tables without `deleted_at` | Decision cited |
|---|---|---|
| spine §2.1 | 9 (`student_fee_discounts`, `student_fee_payments`, `project_payments`, `payment_reversals`, `collaborator_referrals`, `collaborator_commission_settings`, `collaborator_commission_entitlements`, `collaborator_commission_ledger_entries`, `collaborator_payouts`) | [D-FS-3] → D16 |
| phase-03 §2.14 | 3 (`cms_revisions`, `sitemap_generations`, `seo_meta`) | [D-W3-6] → "D17" |
| phase-04 §2 | 4 (3 pivots + `blog_post_views`); `portfolio_images` has no `deleted_at` and no `updated_by` | stated inline |
| phase-05 §2.4, §2.6 | 2 (`lead_conversions`, `lead_import_rows`) | stated inline |
| phase-06 §2.2, §2.8, §2.11 | 3 (`project_value_revisions`, `task_comment_mentions`, `time_entry_segments`) | INV-P3, inline |
| phase-07 §2.1 | 11 | [D-HR-3] → "D19" |
| phase-08-09 §2.2-2.4 | 3 (`collaborator_skills`, `collaborator_service`, `collaborator_referral_visits`) | [D-P8-4], [D-P9-1] |
| phase-13 §2 | 2 (`finance_reversals`, `invoice_items`) | [D-FS-3] / Q3 |
| phase-14-17 §2.1 | 5 | [D-IN-2] |
| phase-19-23 §2.1 | 9 | [D-19-0] |
| phase-24-25 §2 | 3 | stated inline |

Every one is individually justified and several carry `BEFORE DELETE` triggers. The defect is that
`CLAUDE.md` §3 still says "every", eleven contracts each request a `DEVELOPMENT_LOG.md` decision row to
record it, and those decision numbers collide (**F-10.1**). A developer reading `CLAUDE.md` will add
`deleted_at` to an append-only table and break a unique guard
(phase-08-09 R-10 names exactly that failure for `collaborator_skills`).

**Minimal fix.** One edit to `CLAUDE.md` §3: "Every business table carries timestamps and blameable.
Soft deletes are the default and are **deliberately omitted** on append-only money, audit and log tables -
see `DEVELOPMENT_LOG.md` §4 D16. A table without `deleted_at` is protected by a model `deleting` hook and,
where the contract says so, a `BEFORE DELETE` trigger; never add one back."

### F-9.2 · medium · foreign keys without a stated index

phase-24-25 PRF-04 asserts "Every foreign key column has an index … a FK without a usable index fails".
A sample of columns whose owning contract lists no index for them:

| Table.column | Contract |
|---|---|
| `invoices.replaces_invoice_id`, `.payment_method_id`, `.issued_by`, `.cancelled_by` | phase-13 §2.4 |
| `expenses.approved_by`, `.rejected_by`, `.voided_by`, `.corrects_expense_id` | phase-13 §2.6 |
| `testimonials.approved_by`, `.submitted_by_user_id` | phase-04 §2.10 |
| `student_reviews.approved_by`, `.submitted_by_user_id` | phase-04 §2.11 |
| `portfolio_images.created_by` | phase-04 §2.8 |
| `course_inquiries.converted_application_id`, `.converted_student_id` | phase-14-17 §2.11 |
| `student_admissions.student_application_id`, `.course_inquiry_id` | phase-14-17 §2.15 |

Not exhaustive - PRF-04 will find the rest. Listed because PRF-04 is a **go-live gate**, so these are
scheduled failures, not theoretical ones.

**Minimal fix.** Each owning contract appends the missing FK indexes to its `Keys` block, and every phase
adds its rows to `tests/Support/index-manifest.php` as phase-24-25 §13.2 already requires.

### F-9.3 · medium · filtered columns without a stated index

| Column | Filtered by | Contract |
|---|---|---|
| `contact_inquiries.routing_target` | the "Awaiting CRM / Awaiting Institute" tabs (§8.10) | phase-04 §2.20 indexes `routing_status` but not `routing_target` |
| `blog_posts.views_count` | the register's sortable "views" column (§8.7) | phase-04 §2.16 |
| `job_openings.is_featured` | the featured filter | covered by `(status, is_featured, sort_order)` - fine |
| `notifications.archived_at` | "clear all" / archived filter | phase-19-23 §2.25 indexes `(notifiable_type, notifiable_id, read_at, archived_at)` - fine |

**Minimal fix.** Two index additions in phase-04.

### F-9.4 · low · blameable asymmetries

`portfolio_images` has `created_by` without `updated_by`; `collaborator_referral_visits`,
`notifications`, `notification_preferences`, `report_exports`, `lead_import_rows` and
`course_material_downloads` have neither. Each is justified in its contract (an anonymous web request, a
log row, a child of a blameable parent). phase-06 §2.9 `attachments` carries **both** the blameable pair
and `uploaded_by` / `uploaded_by_name`, which is redundant but deliberate (the snapshot survives a user
delete).

**No action** - recorded so the `CLAUDE.md` edit of F-9.1 covers them.

---

## 10. Decision-number collisions in `DEVELOPMENT_LOG.md` §4

### F-10.1 · critical · nine contracts request overlapping decision numbers with different meanings

`DEVELOPMENT_LOG.md` §4 currently ends at **D15**. Every contract then requests the next numbers, and none
of them knew about the others:

| Number | Claimed by | Meaning |
|---|---|---|
| **D16** | spine §13.3, phase-10-12 §13.3, phase-24-25 §13.4 | append-only financial tables carry no `deleted_at` — **consistent, three votes** |
| **D17** | spine §13.3 | DB CHECKs, STORED generated columns, `BEFORE DELETE` triggers are part of the contract |
| | phase-03 §12.2 Q1 / §13.4 | `cms_revisions`, `sitemap_generations`, `seo_meta` carry no soft deletes |
| | phase-24-25 §13.4 | release deploys are directory swaps with a rename rollback |
| **D18** | spine §13.3 | a payout allocates named ledger entries with partial amounts |
| | phase-03 §13.4 | public content is published by snapshot and invalidated by a cache version stamp |
| | phase-24-25 §13.4 | three separated MySQL users (runtime DML / migration DDL / backup read-only) |
| **D19** | phase-05 §13.3 | later-phase data reaches an earlier phase only through a capability contract |
| | phase-06 §13.2 | elapsed time is append-only segments; one running timer is a unique index |
| | phase-07 §13.2 | the eleven append-only HR tables carry no soft deletes |
| | phase-08-09 §13.2 | `collaborator_referrals` is the single truth of attribution |
| | phase-10-12 §13.3 | one calculation site per commission side |
| | phase-13 §13.3 | `invoices.paid_amount` is a cache of one canonical SQL |
| | phase-14-17 §13.3 | the §68 pipeline is carried by `student_admissions.stage` |
| | phase-18 §13.3 | an installment plan's live lines always equal the charge's net fee |
| | phase-24-25 §13.4 | `Model::shouldBeStrict()` outside production is the N+1 audit |
| **D20** | phase-05, phase-06, phase-07, phase-08-09, phase-13, phase-14-17, phase-18, phase-24-25 | **eight different meanings** |
| **D21** | phase-05, phase-06, phase-07, phase-13, phase-14-17 | five |
| **D22** | phase-06, phase-14-17, phase-19-23 | three |
| **D23-D28** | phase-19-23 | uncontested |

phase-06 §13.2 even ends "Numbering note: the spine already claims **D16, D17, D18**, so Phase 6's
decisions start at **D19**" - which is exactly what five other contracts concluded independently.

This is blocking: phase-10-12 §12.2 states "Q1 (decision **D16** …) is **blocking the migration set** and
must be recorded in `DEVELOPMENT_LOG.md` §4 before file 01 is written". Two contracts writing D17 with
different text will make the log unusable as the single source of truth it claims to be.

**Minimal fix.** One human allocation pass, in tracker order, before the first migration:

| Range | Owner |
|---|---|
| D16-D18 | spine (as written) |
| D19-D20 | phase-03 |
| D21-D23 | phase-05 |
| D24-D27 | phase-06 |
| D28-D30 | phase-07 |
| D31-D32 | phase-08-09 |
| D33 | phase-10-12 |
| D34-D36 | phase-13 |
| D37-D40 | phase-14-17 |
| D41-D42 | phase-18 |
| D43-D49 | phase-19-23 |
| D50-D54 | phase-24-25 |

Then each contract's §13 documentation row is renumbered. Nothing else changes.

---

## 11. Phase-ordering problems

### F-11.1 · high · `users.id` versus `employees.id` for staff assignment

| Document | Decision |
|---|---|
| phase-06 [D-P6-2] | "**Work is assigned to a `users` row, never to an `employees` row.** §20 and §22 say 'employees', but `employees` is an HR profile created in Phase 7 … while the thing that logs in, holds permissions, receives a notification and starts a timer is a user." `tasks.assigned_user_id` FK `users.id`; `CHECK chk_tasks_assignee` allows at most one of `assigned_user_id` / `assigned_collaborator_id` |
| phase-07 [D-HR-2] | "**Everything in the system that means 'a member of staff'** (task assignee, project manager, department head, leave approver as a person, course coordinator) **points at `employees.id`**. `users.id` is used only for *who performed an act*." §13.1 requests "**`tasks.assigned_employee_id`, `projects.project_manager_id`, `project_user`-style staff links and any time-tracking `employee_id` must reference `employees.id`, not `users.id`** - Phases 5, 6"; §13.2 asks for it as **D21** |

Phase 7 is asking Phase 6 to add a third assignee column that Phase 6's own CHECK constraint forbids, and
phase-07 Q1 raises only the *migration order*, not the conflict itself. phase-13 §9 then picks Phase 7's
side and breaks (**F-3.11**), while phase-19-23 §1.2 needs `employees` for §99's HR reports and
phase-14-17 §2.17 links `teachers.employee_id`.

**Minimal fix.** Phase 6's answer is the right one for *assignment* (it drives notifications, permissions
and timers, all of which need a login) and Phase 7's is right for *duties* (department head, reporting
line, leave approver as a post). Write that split down once:

> Assignment of work → `users.id`. Organisational duty → `employees.id`. The bridge is
> `employees.user_id` (nullable, unique).

Then delete the `tasks.assigned_employee_id` request from phase-07 §13.1, fix phase-13 §9, and keep
phase-07's D21 text but narrow it to "a duty is held by a post".

### F-11.2 · high · the spine's tables are needed two phases before the phase that creates them

`DEVELOPMENT_LOG.md` §5 orders 8 → 9 → 10. spine §1.3 [D-FS-2] puts all 15 tables in Phase 10. Phase 8
needs `collaborator_wallets` (its observer, INV-C3), `collaborator_commission_settings` (the rule screen,
§7.4) and `collaborator_payout_accounts` (§7.2); Phase 9 needs `collaborator_referrals`.

phase-08-09 §1.4 [D-P8-1] resolves it honestly - Phase 8's migrations, then "the spine's migration set is
applied **immediately afterwards, in the same release**", with module switches hiding the spine-dependent
screens and two idempotent backfills (`collaborators:backfill-wallets`,
`collaborators:seed-initial-rules`). spine §12.2 Q10 and phase-10-12 Q-H both ask for a tracker note.

**That note is still absent from `DEVELOPMENT_LOG.md` §5**, so the tracker reads as if Phase 8 can be
ticked before the spine exists.

**Minimal fix.** Add one line under Phase 8 in §5: "the financial spine's migration set ships in the same
release, immediately after Phase 8's migrations (spine §1.3, phase-08-09 §1.4)".

### F-11.3 · high · Phase 18 owns the fee *screens* but not the fee *tables*

`DEVELOPMENT_LOG.md` §5 Phase 18 reads "Student fees, installments, discounts, scholarships (commission
triggers)". spine §1.3 moves `student_fees`, `student_fee_installments`, `student_fee_discounts` and
`student_fee_payments` into Phase 10's migration set, and phase-18 §2 opens "**This phase creates no
financial table**". phase-10-12 Q-H asks for the tracker to say that Phase 18 **depends on** Phase 10.

**Minimal fix.** One line under Phase 18 in §5: "consumes Phase 10's `PaymentService` and the four fee
tables; ships `student_fee_reminders`, the services and the screens".

### F-11.4 · medium · `DocumentNumberService` is needed four phases before Phase 10

`lead_no` / `client_code` (Phase 5), `projects.code` (Phase 6), five HR counters (Phase 7),
`collaborator_code` (Phase 8) all precede Phase 10. Resolved only by F-4.1.

### F-11.5 · medium · three "required" classes do not exist in the owning contract

phase-19-23 §1.2 marks as **required**: Phase 13's `ReportExporter`, `ReportResult` and
`layouts/print.blade.php`, and Phase 7's `PayslipService`. None exists (**F-4.13**, **F-4.14**). A phase
cannot be blocked on something nobody owns.

**Minimal fix.** Add them to phase-13 §6.9 and phase-07 §6 respectively, or name the real classes.

### F-11.6 · medium · Phase 3 and Phase 4 are mutually dependent

phase-04 §1.2 needs Phase 3's `layouts/site`, public middleware stack, `SitemapRegistry`, rich-text
bundle and catch-all ordering. phase-03 §6.1 needs Phase 4 to register six section types plus their
`SectionDataProvider`s, and §13.3 binds Phase 4 to them. Both contracts state "the two must not be built
concurrently" (phase-03 §12.1 R-8 / phase-04 §1.2, R3).

This is a legitimate cycle broken by ordering, not a defect - **recorded** so nobody tries to parallelise
them. The same shape exists between phase-06/phase-08 (guarded FK promotion) and
phase-14-17/phase-18 (service calls both ways), and both are handled the same way.

### F-11.7 · low · phase-24-25 believes phases 19-23 are unwritten

phase-24-25 §13.2: "Phases **19-23, whose contracts are not yet written**, must include these four
additions in their own §13". `phase-19-23.md` exists and its §13.1 does supply the manifest rows.

**Minimal fix.** Delete the clause.

---

## 12. Data-isolation gaps

Requirement 112 is the benchmark: "Client A cannot see Client B. Student A cannot see Student B's private
records. Collaborator A cannot see Collaborator B's earnings. A teacher sees only assigned batches.
Collaborators see only their own students, projects, commissions, wallet and payouts unless explicitly
granted more."

Overall the contracts are strong: every one has a §9 with per-role query rules, a global scope plus a
policy, 404-not-403 on ownership failure, and a test asserting the forbidden columns are **absent from the
response body**. The gaps below are the exceptions.

### F-12.1 · high · the portal permission prefixes may be denied to everyone

phase-05 §12.2 Q1 (raised against Phase 1, correctly flagged rather than redesigned):

> `Modules::permissionModuleMap()` maps a permission to a module **by its prefix**, so `client_portal.*`
> (and `student_portal.*`, `teacher_portal.*`, `collaborator_portal.*`) resolve to module slugs that
> Phase 1 §4 never registers. If an unregistered slug is treated as "disabled", `Gate::before` denies
> every portal permission to everyone, Super Admin included.

phase-01 §6 step 1 is the mechanism: "if the ability maps to a module … that is disabled **and not core**
→ return `false` (denies everyone, Super Admin included)". Phase 1 §4 registers 18
`collaborator_portal.*` permissions and the three other prefixes but **no `collaborator_portal` module
row**. Whether this fails depends entirely on how `permissionModuleMap()` treats an unknown prefix - which
phase-01 §3 does not specify.

If it fails, all four non-admin panels are dead on day one, for every user, with no error message.

**Minimal fix.** phase-01 §3 states explicitly: "`permissionModuleMap()` returns **null** for a permission
whose module slug is not in the registry, and `Gate::before` step 1 falls through on null." Plus a Phase 1
test: "a Collaborator, Student, Teacher and Client can each reach their own panel with every module
disabled except their own."

### F-12.2 · high · Phase 6's collaborator project scope - see F-8.2

A §112 breach built on a display-snapshot column. Listed in both sections because it is both an
isolation defect and a commission-invariant violation.

### F-12.3 · medium · Phase 6's client routes skip `EnsureClientContext`

phase-05 §6.9 ships `App\Http\Middleware\EnsureClientContext` (alias `client.context`) and §7 puts it
"on **every** row" of `routes/client.php`, because it "re-evaluates `portal_enabled`, `clients.status` and
`crm.client_portal_enabled` on **every request**, so revocation is immediate" (test 63, test 78).

phase-06 §7.7 declares five client routes with `auth`, `active`, `panel:client`, `module:projects` and
**no `client.context`**. A client whose portal was disabled, whose status moved to `suspended`, or whose
whole portal was switched off by `crm.client_portal_enabled = false`, still reaches
`/client/projects`.

**Minimal fix.** phase-06 §7.7 adds `client.context`. Same check for the spine §7.6's two client routes
and phase-13 §7.7's client routes.

### F-12.4 · medium · Phase 4's PII modules have no row scope for a widened role

phase-04 §9.1.2 / §9.1.3: `contact_inquiries` and `job_applications` scope to
`assigned_to = auth()->id()` unless the user holds `{module}.view_any`. That is correct as far as it goes,
but:

- `job_applications` holds a CV, an email, a phone and a cover letter. The only thing between a role and
  every CV in the system is one permission with no branch, no department and no job-opening scope.
- phase-04 §9.1 says "SEO Expert … **no** `job_applications` or `contact_inquiries` permissions - the PII
  modules are not part of its grant", but the grant table for **Digital Marketer** lists
  `contact_inquiries` without saying whether it receives `view_any`. phase-04 §13 asks Phase 1 to give HR
  `jobs.*` and `job_applications.*` "in full".
- `contact_inquiries` stores `ip_address`, `user_agent`, `utm_*` and `filled_in_seconds` with no stated
  gate beyond `view`.

**Minimal fix.** phase-04 §9.1 states the `view_any` grant explicitly per role (Digital Marketer: no
`view_any`), and adds a `job_opening_id` scope option for a hiring manager who should see one role's
applicants only. Add the CV-download activity row to the §107 sensitive set (phase-04 §10.5 already logs
it - good).

### F-12.5 · medium · `expenses.receipt_path` is on the public disk

phase-13 §2.6: "`receipt_path` | string(255) | nullable | §30 receipt; MIME-validated upload on the
**`public` disk** under `expenses/`".

Every comparable artefact in the system is private and controller-served: `client_documents` (phase-05
[D-P5-10], private `local`), `employee_documents` (phase-07 §2.6, private), CVs (phase-04 §6.8, private
`local`, "never the `public` disk, so no URL can reach it"), `attachments` (phase-06 §2.9, private
`local`), materials / submissions / certificates / ID cards / exports (phase-19-23 INV-19-1, "**No private
file is ever reachable by URL alone**"). phase-24-25's `upload-manifest.php` expects a disk and a
permission per upload field.

A vendor invoice or a bank receipt at a guessable `/storage/expenses/...` URL is a §111 defect.

**Minimal fix.** Move to the private disk with a policy-checked streaming route, exactly as phase-13 §2.4
already does for `invoices.pdf_path`… which is also worth checking: phase-13 §2.4 says `pdf_path` is a
"cached render of the issued PDF" without naming a disk.

### F-12.6 · medium · `expenses` and `incomes` have no scope for a project manager's own project

phase-13 §9 gives the Project Manager no Phase 13 permission by default and, when widened, a
`ProjectManagerScope` on `invoices` and `project_payments` only. `expenses.project_id` exists and a PM
would reasonably be given project expenses; no scope is stated for it, so a widened PM would see every
expense in the company.

**Minimal fix.** Extend `ProjectManagerScope` to `expenses` (`whereHas('project', …)` plus
`project_id IS NULL` invisible, matching the invoice rule).

### F-12.7 · low · "hides rows whose properties contain another party's data" has no mechanical definition

phase-08-09 §6.6 `CollaboratorActivityService::feed()`: "The collaborator's own feed additionally
**hides** rows whose `properties` contain another party's data and never shows `reason` text written by
staff about them". `activity_log.properties` is free-form JSON written by eleven phases; there is no rule
a developer can implement from that sentence, and FT-C-series does not pin it.

**Minimal fix.** Replace with an **allowlist**: the collaborator's feed renders only
`properties` keys named in `CollaboratorActivityEvent::visibleProperties()`, and never `reason`.

---

## 13. Requirement coverage

Walked `requirements.md` section by section. Every numbered section maps to at least one contract except
the items below.

### 13.1 Requirements no contract covers

#### F-13.1 · medium · §1 "REST API where required" and §6 "blocks API access"

§1: "middleware, policies, gates, events, notifications, jobs, queues, scheduler; **REST API where
required**". §6: a disabled module "hides its sidebar item, blocks routes, **blocks API access**, and
preserves existing data".

No contract defines an API surface: no `routes/api.php`, no token guard, no resource classes, no rate
limits beyond the web limiters, no versioning. phase-01 §8 lists six route files, none of them `api.php`.
The spine §2.19 mentions "the API takes it from an `Idempotency-Key` header" and phase-08-09 §3.2 has a
`ReferralSource::api` case - two contracts assume an API that nobody owns.

**Minimal fix.** A one-line client decision: either "no REST API in this release" (then drop the
`Idempotency-Key` header sentence and keep `ReferralSource::api` for a future import) or a named phase
owns `routes/api.php`, Sanctum tokens, and the module gate for them.

#### F-13.2 · medium · §96 "Files" is implemented as six disjoint stores

§96: "Files may belong to projects, tasks, clients, employees, collaborators, students, courses, batches,
invoices, tickets. **Permissions control visibility.**" Phase 1 registers one `files` module slug.

What actually exists:

| Store | Owner | Covers |
|---|---|---|
| `attachments` | phase-06 §2.9 | project, task, task_comment, milestone (+ ticket, reply, message, meeting, assignment from phase-19-23 [D-22-3]) |
| `client_documents` | phase-05 §2.9 | clients |
| `employee_documents` | phase-07 §2.6 | employees |
| `course_materials` + `course_material_targets` | phase-19-23 §2.3-2.4 | courses, batches, students |
| `assignment_submission_files` | phase-19-23 §2.8 | submissions |
| `media_assets` | phase-03 §2.13 | CMS images |
| bare `*_path` columns | 6 contracts | client logo, collaborator photo, employee photo, student photo, expense receipt, invoice PDF, CV, leave attachment |

No collaborator file store exists at all, although §96 names collaborators and §59 grants them
`files_upload` / `files_download`. No invoice attachment store exists, although §96 names invoices.
No single screen or permission answers §96.

**Minimal fix.** Accept the fragmentation explicitly (each store carries behaviour a generic table
cannot - targeting, windows, attempt numbering, encryption) but close the two real holes: add
`Collaborator` and `Invoice` to `attachments`' morph map, and record in `CLAUDE.md` §3 that the `files`
module slug governs `attachments` and that the five specialised stores are named exceptions.

#### F-13.3 · medium · §29 lists "collaborator payments" as **tracked income**

§29 "Tracked income": "Client payments, project payments, course fees, student fees, admission fees,
**collaborator payments**, other income, expenses."

Every contract treats a collaborator payout as money **out**: phase-13 §6.7.3 P&L block C is "collaborator
commission" as a cost, phase-13 R-4 warns against double-counting it as an expense, and no contract records
a payout as income. Either §29's phrase means "payments *to* collaborators" (a cost, as implemented) or
"payments *from* collaborators" (which has no other mention anywhere in §33-60).

**Minimal fix.** One client confirmation; the implemented reading is almost certainly correct.

#### F-13.4 · low · §2's cosmetics

"AJAX where suitable", "smooth animations": no contract commits to them and none needs to. phase-24-25
§8.7/§8.8 cover the measurable half (responsive, keyboard, contrast). **No action.**

### 13.2 Features no requirement asks for

Each is flagged for a client decision rather than removal. Several are operationally necessary and should
simply be recorded as deliberate.

| # | Feature | Contract | Requirement basis | Severity |
|---|---|---|---|---|
| F-13.5 | **Ticket SLA** clocks, pause-on-`waiting`, breach sweeps, `first_response_at`, per-department minutes, `TicketPriority::slaMultiplier()`, six `support.sla_*` settings | phase-19-23 §2.18, §6.16, INV-22-2 | §93 lists ticket number, user, subject, department, priority, description, attachment, assigned agent, status. **No SLA anywhere in §93 or §99** | medium |
| F-13.6 | **`notification_preferences`** per-user per-event, `mail_digest`, mandatory-event locking | phase-19-23 §2.25, §6.19 | §97 asks for in-app notifications and "architecture must support email too". Preferences are not requested | low |
| F-13.7 | **`cms_revisions`** + revert, signed shareable **preview** links, **`sitemap_generations`** history | phase-03 §2.14, §6.12, §6.5 | §100 asks for enable/disable/edit/reorder/status; §105 for "sitemap support". Revisions, preview links and build history are additions | low |
| F-13.8 | **CSV lead import**: `lead_imports`, `lead_import_rows`, a 4-step wizard, chunked jobs, error CSV, two prune commands | phase-05 §2.5-2.6, §6.6, §8.6 | §18 lists no import. §4's generic `import` ability is the only hook, and phase-05 §4.2 does add `leads.import` | low |
| F-13.9 | **Public signed invoice link** (`invoices.public_token`, `finance.invoice_public_link_*`, a guest route) | phase-13 §2.4, §7.8, §8.12 | §31 lists no client-facing link; phase-13 justifies it from D2 (a client may have no login) | low |
| F-13.10 | **`technologies`** as a table plus two pivots and a module slug | phase-04 §2.4-2.5, §2.7 | §11 and §12 list "technologies" as a *field* | low |
| F-13.11 | **Backup restore**: `backup_restores`, a 3-step wizard, a confirmation phrase, scratch-database verification | phase-24-25 §2.2, §6.10, §8.3 | §114 lists database backup, file backup, manual, scheduled. Restore is not named - but HD-6 ("a backup is not a backup until it has been restored") is right | low |
| F-13.12 | **`collaborator_referral_visits`** click tracking with IP, UA, device, bot filter, funnel report, 365-day retention | phase-08-09 §2.4, §6.4, §8.6 | §38 asks only that the URL "preselects that collaborator". The visit table is the carrier that makes the referral survive the flow, so it is hard to call invented - but the **funnel report and IP retention** are | low |
| F-13.13 | **`grade_scales` / `grade_scale_bands`** as configurable data | phase-19-23 §2.9-2.10 | §82 lists "grade" as a result field. A configurable scale is a design choice, well justified | low |

**Minimal fix for all of §13.2.** One table in `DEVELOPMENT_LOG.md` §9 listing them with the assumed
default ("build it" / "do not build it"), so the client sees the scope they are paying for. Only F-13.5
(SLA) is large enough to warrant dropping if unwanted - it is four settings, two clock columns, a pause
rule, a sweeper and five tests.

---

## 14. Requirement 120 - the nine acceptance tests

Every one of the nine is claimed, by name, at two levels, with a test that fails if the suite is reduced.
This is the one area of the thirteen contracts that is fully reconciled.

| §120 | Scenario | Spine (service level) | phase-10-12 | phase-18 (HTTP level) | phase-24-25 (pinned) |
|---|---|---|---|---|---|
| 1 | Student **without** collaborator pays a fee → no row, not a zero row | FT-01 | §11.1 | PH18-01 | **FIN-02** |
| 2 | Student with collaborator pays 10,000 at 10% → one 1,000 | FT-02 | §11.1 | PH18-02 | **FIN-03** |
| 3 | Same fee payment processed twice → exactly one | FT-03 | §11.1 | PH18-03 | **FIN-04** |
| 4 | Three 10,000 installments → three commissions | FT-04 | §11.1 | PH18-04 | **FIN-05** |
| 5 | Student refund after a 1,000 commission → −1,000 reversal, original preserved | FT-05 | §11.1 | PH18-05 | **FIN-06** |
| 6 | Project **without** collaborator receives payment → no commission | FT-06 | §11.1 | — | **FIN-07** |
| 7 | Project with collaborator: 100,000 at 15% → 15,000 | FT-07 | §11.1 | — | **FIN-08** |
| 8 | Project payment refunded → reversal entry | FT-08 | §11.1 | — | **FIN-09** |
| 9 | Wallet 50,000, payout 20,000 → available 30,000, history preserved | FT-09 | §11.1 | PH18-06 | **FIN-10** |

phase-24-25 **FIN-01** is the guard: it asserts the suite `--group=financial-120` contains **exactly**
those nine method names, "A missing or renamed test **fails this test by name** - the §120 suite can never
be quietly reduced", and §13.1 requires phases 10-12 and 18 to carry the `@group financial-120`
annotation and keep the exact names.

Two notes, neither a defect:
- §120.6-8 (the project side) have no HTTP-level twin in phase-18 because phase-18 is the student side;
  phase-10-12 §11.1 and phase-13 §11.3 cover the project HTTP path.
- FIN-03 asserts `status = pending` because `commission_approval_mode` defaults to **manual**
  (`DEVELOPMENT_LOG.md` §9 Q5). §120.2 says "One commission of PKR 1,000" without a status, so this is
  consistent. Worth one client confirmation that "available" is not expected.

---

## 15. What is already clean (so nobody "fixes" it)

Listed because a reviewer arriving at an audit this long will otherwise assume nothing was right.

| Area | Evidence |
|---|---|
| **One commission engine** | No duplicate commission logic exists anywhere. `LedgerWriter` is the only insert path, enforced by a model hook (spine INV-21) and asserted by seven contracts' forbidden-lists (§8.1) |
| **No float on money** | Thirteen contracts, six static scans, `App\Support\Money` everywhere. No contract proposes float arithmetic (F-7.4) |
| **No financial deletes** | The nine append-only tables carry `BEFORE DELETE` triggers plus model hooks; every correction is a new row; no contract proposes an update or delete on them (F-8.4) |
| **Duplicate prevention** | spine §2.19's four layers (idempotency key, `uq_cle_dedupe`, `uq_cle_source`, `chk_cce_cap`) are reproduced correctly in phase-10-12's 21-file migration plan and re-asserted by phase-24-25 FIN-04 and FIN-17 |
| **404-not-403 on ownership** | Every contract with a panel states it and tests it. Ids cannot be probed anywhere |
| **Withheld columns absent from the body** | Stated as a rule in phase-05, phase-06, phase-07, phase-13 (§4.5 rule 3), spine §8.11, phase-19-23 INV-23-2 and asserted by phase-24-25 HD-5 |
| **Requirement 120** | Fully claimed, twice, and pinned by name (§14) |
| **The Registry pattern** | `PermissionRegistry` → `SettingsRegistry` → `WebsiteSectionRegistry` → `DashboardRegistry` → `ClientPortalRegistry` → `InquiryRouter` → `NotificationRegistry` → `ReportRegistry` → `GlobalSearchRegistry` → `SitemapRegistry` → `PrintTokenRegistry`. Eleven instances of one pattern, each "definitions in code, values/rows in the DB", each filtered by module + permission. This is the strongest thing about the thirteen contracts |
| **Deliberate deviations are named** | Every soft-delete omission, every NULL-tolerant unique guard, every raw-SQL migration carries a `[D-xx-n]` label and a stated reason. The problem is the decision *numbering* (F-10.1), not the discipline |
| **Cross-contract reconciliation already done** | phase-14-17 §13.4 is a model: eleven sibling asks resolved in one table with the reasoning shown, including three accessor aliases so another phase's published code compiles unchanged |

---

## 16. Recommended resolution order

Nothing below needs a redesign. The list is ordered by what blocks what.

**Before the first migration of any phase**

1. **F-10.1** - allocate decision numbers D16-D54 once (one table edit in `DEVELOPMENT_LOG.md` §4).
2. **F-9.1** - amend `CLAUDE.md` §3 for soft deletes, so the fifty deviations are legal.
3. **F-12.1** - specify `permissionModuleMap()`'s behaviour for an unknown prefix, plus the four-panel
   test. All four panels depend on it.
4. **F-4.1** - assign `DocumentNumberService` to Phase 5, pad `'%06d'`, and add `reserve()` (F-4.12).
5. **F-4.11** - publish the twelve `Money` methods in phase-01 §3.
6. **F-3.15** - add `student_fees.generation_key` to the spine's migration 01.

**Before Phase 3 / 4 are built (they share files and must not run concurrently)**

7. **F-5.1** - one `ContentStatus`.
8. **F-2.3**, **F-2.4**, **F-2.5** - decide SEO, media and sanitiser ownership. These are the three
   largest single-edit wins in the audit.
9. **F-2.1** - declare Phase 4 the owner of `contact_inquiries` and retarget three contracts' requests.
10. **F-6.3** - one `website` settings group.

**Before Phase 5 / 6 are built**

11. **F-3.1** - add `tasks.is_client_visible`.
12. **F-3.3**, **F-3.6**, **F-3.7** - three column-name / uniqueness fixes in phase-05 / phase-06.
13. **F-6.2** - make phase-05 the authority for `routes/client.php`; Phase 6, the spine and Phase 13
    append.
14. **F-8.2 / F-12.2** - fix Phase 6's collaborator project scope.
15. **F-12.3** - add `client.context` to Phase 6's, the spine's and Phase 13's client routes.

**Before Phase 7**

16. **F-11.1** - write down the `users.id` (assignment) versus `employees.id` (duty) split and delete
    phase-07's `tasks.assigned_employee_id` request.
17. **F-5.2** - one `EmploymentType`.
18. **F-5.4** - Phase 7 ships `PaymentMethod` and `LedgerEntryType`; phase-10-12 marks them reused.

**Before Phase 8 / 9 / 10**

19. **F-11.2** - the tracker note about the spine's migration set.
20. **F-4.3**, **F-4.4**, **F-4.5**, **F-5.5** - the `ReferralService` surface (`ReferralContext`,
    `recordLosingCandidate`, the attribution-copy decision) and `CommissionCalculationType` ownership.
21. **F-3.4**, **F-3.5**, **F-2.7** - retarget phase-08-09's three schema asks.
22. **F-4.2** - one `refund()` signature.

**Before Phase 13**

23. **F-3.9** - `expenses.source_type` / `.source_id` + the payroll listener (§99's P&L is wrong without
    it).
24. **F-3.10**, **F-3.11** - `project_milestones.name`, `project_manager_id` as a user id.
25. **F-4.8**, **F-4.9**, **F-4.10**, **F-4.14** - the four spine/Phase-13 seams.
26. **F-12.5** - move `expenses.receipt_path` to the private disk.

**Before Phase 14-17**

27. **F-2.2** - drop `course_faqs`.
28. **F-5.3** - one `InquirySource`.
29. **F-3.8**, **F-3.14** - the two inquiry provenance columns.
30. **F-7.1** - the seventeen percentage columns (or the `CLAUDE.md` + FIN-18 amendment).

**Before Phase 18 / 19-23**

31. **F-4.6** - the four `StudentFeeService` methods (`withinServiceContext()` is a compile dependency).
32. **F-4.7**, **F-4.13** - `ScheduleClashDetector::check()` and the `PayslipService` naming.
33. **F-2.6**, **F-5.7**, **F-6.5**, **F-6.8** - four small phase-19-23 alignments.
34. **F-8.3** - the three fee widget keys.

**Before go-live (Phase 24-25's gates)**

35. **F-7.2**, **F-9.2**, **F-9.3** - FIN-18's allowlist and the FK/filter indexes PRF-04 will demand.
36. **F-13.1**, **F-13.2**, **F-13.3** - three client decisions (API, files, §29 wording).
37. **F-13.5** … **F-13.13** - record the invented-scope list in `DEVELOPMENT_LOG.md` §9.

**Cosmetic, any time**

38. **F-5.6**, **F-5.8**, **F-5.9**, **F-5.10**, **F-6.4**, **F-6.6**, **F-6.7**, **F-9.4**, **F-11.7**,
    **F-12.7**, and the five factual-drift items below.

### Factual drift worth correcting while editing

| # | Document | Statement | Correction |
|---|---|---|---|
| 1 | phase-07 §5 | the `hr` group is "the first group added since Phase 2" | phase-03 (`website`), phase-04 (`website`), phase-05 (`crm`) and phase-06 (`projects`) precede it |
| 2 | phase-19-23 §5 | `institute.attendance_minimum_percentage` is a Phase 2 key | it is phase-14-17 §5 |
| 3 | phase-19-23 §5 | `finance.report_sync_row_limit` is a Phase 2 key | it is phase-13 §5 |
| 4 | phase-19-23 R-9 | `reports.sync_row_limit` and `finance.report_sync_row_limit` are two keys for one idea | self-flagged; converge in Phase 24 |
| 5 | phase-07 §13.1 | "the financial spine §13.2 already asks for exactly this set; Phase 7 needs it **earlier**" | correct, and the same is true of `DocumentNumberService` - see F-4.1 |
