# DATA MODEL — index and cross-domain view

**Scope.** This file is the index of the system-wide data model and the only place the **cross-domain**
view lives. It covers every phase contract in `docs/phases/` (phase-01 … phase-24-25) plus
[`design/finance-commission-spine.md`](design/finance-commission-spine.md), read after the convergence
apply stage of [`design/resolutions.md`](design/resolutions.md) (decisions **D1–D60**). It owns five
registries that no single domain can own on its own — the enum registry (§4), the module registry (§5),
the numbering registry (§6) and the conventions checklist (§7) — plus the table census (§2) and the
cross-domain relationship map (§3). Per-table column detail lives in the five domain files of §1.2.
Nothing here invents a table, column, enum case, module slug or number format: every row traces to a
contract section, and anything a contract leaves undefined is listed in §8 rather than filled in.

Precedence, unchanged: `finance-commission-spine.md` beats every phase contract on anything touching
money; `resolutions.md` beats every phase contract elsewhere; `CLAUDE.md` conventions hold everywhere.

---

## 1. How to use this data model

### 1.1 Reading order

| You want to | Read |
|---|---|
| know which table owns a fact | §2 census, then that domain file's table inventory |
| add a migration | §7 checklist first, then the owning domain file, then the owning phase contract |
| add or cast an enum | §4. If the name already exists, you **cast**; you never re-declare (R3) |
| add a module or permission | §5, then `PermissionRegistry` (the only declaration site, D4) |
| issue a human-readable number | §6. There is exactly one counter implementation (D27) |
| follow money across domains | the spine first, then §3 |
| know whether a table soft-deletes | the domain file's inventory; the rule is D19 (`CLAUDE.md` §3 block A) |

### 1.2 The five domain files

Domain boundaries below are **binding**; the filenames are this index's convention. If a domain file was
written under a different name, fix the link here — do not move tables between domains.

| # | File | Phases / contracts | Covers | Tables |
|---|---|---|---|---|
| 1 | [`data-model/core.md`](data-model/core.md) | phase-01, phase-02, phase-24-25 | identity and RBAC (`users`, spatie tables), `modules`, `settings`, `branches`, `login_histories`, `activity_log`, `sessions`; backup / restore / integrity run history | 7 own + vendor |
| 2 | [`data-model/website.md`](data-model/website.md) | phase-03, phase-04 | CMS (sections, menus, pages, CTAs, FAQs, `seo_meta`, `media_assets`, revisions, sitemap runs) and the business entities the public site renders (services, technologies, portfolio, team, testimonials, student reviews, success stories, blog, jobs, applications, `contact_inquiries`) | 34 |
| 3 | [`data-model/software-house.md`](data-model/software-house.md) | phase-05, phase-06, phase-07, phase-13 | CRM (`leads`, `clients` + children), projects / milestones / tasks / time / `attachments`, the 24 HR tables, and the finance documents (`invoices`, `invoice_items`, `expenses`, `incomes`, `finance_reversals`, `payment_methods`, `finance_categories`) | 51 |
| 4 | [`data-model/collaborator-and-money.md`](data-model/collaborator-and-money.md) | finance-commission-spine, phase-08-09, phase-10-12 | the spine's 15 money tables (student fee charge / installments / discounts / receipts, project payments, reversals) **and** the whole commission chain (referrals, rule versions, entitlements, ledger, wallet, payouts, allocations, accounts, reconciliations) plus the 4 collaborator-profile tables | 19 |
| 5 | [`data-model/institute.md`](data-model/institute.md) | phase-14-17, phase-18, phase-19-23 | courses and curriculum, inquiries / applications / students / admissions / demos, teachers, classrooms, batches, timetable, sessions, attendance, progress, `student_fee_reminders`, materials, assignments, exams, results, certificates, ID cards, print templates, and the Shared set (tickets, meetings, conversations, messages, notifications, report exports) | 51 |

Three notes on the boundaries, each a contract fact rather than a preference:

- **The spine's four fee tables live in file 4, not file 5.** Phase 10 creates them; Phase 18 ships the
  screens, services and `student_fee_reminders` only and "creates no financial table"
  (resolutions §2.1, F-11.2/F-11.3). File 5 references them and never redefines them.
- **The Shared tables of phase-22/23** (`support_tickets`, `meetings`, `conversations`, `messages`,
  `notifications`, `notification_preferences`, `report_exports`) are documented in file 5 because that is
  where their contract lives, even though every panel reaches them.
- **`attachments` is file 3's** (Phase 6 owns it, F-2.6/F-2.8). There is **no `files` table** — the `files`
  module slug governs the `attachments` table (`CLAUDE.md` §3 block B).

### 1.3 Every domain file has the same six sections

1. scope note naming its phases and contracts · 2. table inventory (`| Table | Owning phase | Purpose |
Key columns | Key relationships |`, money and percentage columns marked, soft-delete status stated) ·
3. relationship map as an indented text tree · 4. enums used and the columns that cast them ·
5. module slugs with permission counts and the settings keys that change the domain's behaviour ·
6. open points with finding ids.

---

## 2. Table census

### 2.1 Per phase

"Own tables" = tables the phase's `create_*` migrations create. Additive column migrations onto another
phase's table are counted as 0 and listed in the last column.

| Phase | Contract | Own tables | Soft-deleting | Append-only (no `deleted_at`) | Touches other phases' tables |
|---|---|---|---|---|---|
| 1 | phase-01 | **4** (`modules`, `settings`, `branches`, `login_histories`) | 1 (`branches`) | 3 | extends `users`, spatie `roles`/`permissions`, `activity_log` |
| 2 | phase-02 | **0** | — | — | 3 additive migrations (`modules`, `settings`, `users.preferences`) |
| 3 | phase-03 | **14** (12 + 2 pivots) | 12 | 2 (`cms_revisions`, `sitemap_generations`) | — |
| 4 | phase-04 | **20** (incl. 4 link tables) | 15 | 5 (4 link tables + `blog_post_views`) | deferred FK columns only |
| 5 | phase-05 | **9** | 7 | 2 (`lead_conversions`, `lead_import_rows`) | `add_crm_deferred_foreign_keys` |
| 6 | phase-06 | **11** | 8 | 3 (`project_value_revisions`, `task_comment_mentions`, `time_entry_segments`) | adds the `projects.collaborator_id` FK later (Phase 8) |
| 7 | phase-07 | **24** | 13 | 11 ([D-HR-3], D19 + D16) | guarded `add_employee_fks_to_*` from other phases |
| 8–9 | phase-08-09 | **4** (`collaborators`, `collaborator_skills`, `collaborator_service`, `collaborator_referral_visits`) | 1 (`collaborators`) | 3 | `activity_log.collaborator_id` |
| 10 | spine (shipped with Phase 8's release) | **15** in **21** migration files | 3 (`student_fees`, `student_fee_installments`, `collaborator_payout_accounts`) | 12 — the nine **D16** financial tables plus three evidence/cache tables ([D-FS-3]; see §8 item 12) | guarded external + deferred FK files 20–21 |
| 10–12 | phase-10-12 | **0** (it ships the spine's set) | — | — | — |
| 13 | phase-13 | **7** | 5 | 2 (`invoice_items`, `finance_reversals`) | adds `project_payments.invoice_id` FK; `expenses.source_type/_id` (D44) |
| 14–17 | phase-14-17 | **25** (24 entity + `course_teacher`) | 20 | 5 ([D-IN-2]) | guarded FKs into `employees`, `contact_inquiries`, `collaborator_referral_visits` |
| 18 | phase-18 | **1** (`student_fee_reminders`) | 1 | — | one additive column on a spine table (§2.4, granted) |
| 19–23 | phase-19-23 | **25** | 17 | 8 (D19) | 2 guarded migrations only (`attachments` morph, `courses.grade_scale_id`) |
| 24–25 | phase-24-25 | **3** (`backup_runs`, `backup_restores`, `integrity_check_runs`) | 0 | 3 (D19) | one index-only migration ([D-P24-1]) |
| | **Total** | **162** | **103** | **59** | |

Counts exclude framework/vendor tables the app does not design: `sessions`, `password_reset_tokens`,
`cache`, `jobs`/`failed_jobs`, and spatie's five permission tables + `activity_log` (Phase 1 publishes and
extends them).

### 2.2 Per domain

| Domain file | Phases | Tables | Money tables | Append-only |
|---|---|---|---|---|
| core | 1, 2, 24-25 | 7 | 0 | 6 |
| website | 3, 4 | 34 | 0 (money columns only: `services.price_from`, `job_openings.salary_*`) | 7 |
| software-house | 5, 6, 7, 13 | 51 | 7 (HR payroll/advances) + 5 (finance documents) | 18 |
| collaborator-and-money | spine, 8-9, 10-12 | 19 | 15 | 15 |
| institute | 14-17, 18, 19-23 | 51 | 0 own (references the spine's 4 fee tables) | 13 |
| | **Total** | **162** | | **59** |

---

## 3. Cross-domain relationship map

Only edges that **leave** a domain. Read `->` as "points at" (the FK lives on the left-hand table).
`[snapshot]` means the column is display-only: **no scope and no engine may read it** (R5, **D37**).
`[deferred]` means the column ships nullable and unindexed-by-FK first, and the owning phase adds the
constraint in a `Schema::hasTable()`-guarded migration.

```
CORE (users / branches / modules / settings / activity_log)
  users.id
    <- employees.user_id                      1-1 nullable UNIQUE   software-house   (D32 bridge)
    <- students.user_id                       1-1 nullable UNIQUE   institute        (D2)
    <- teachers.user_id                       1-1 nullable UNIQUE   institute
    <- clients.user_id                        1-1 nullable UNIQUE   software-house   (portal login)
    <- client_contacts.user_id                1-1 nullable UNIQUE   software-house   (extra portal login)
    <- collaborators.user_id                  1-1 nullable UNIQUE   collaborator-and-money
    <- projects.project_manager_id                                  software-house   (F-3.11: a users.id)
    <- clients.account_manager_id                                   software-house   (F-3.3)
    <- project_members.user_id                                      software-house   (assignment -> users, D32)
    <- tasks.assigned_to / time_entries.user_id                     software-house   (D32)
    <- support_tickets.user_id / .assigned_to                       institute
    <- created_by / updated_by on every business table              all domains       (Blameable)
  branches.id
    <- students / teachers / classrooms / batches                   institute        (D11, nullable)
    <- course_inquiries / student_applications / student_admissions / demo_classes   institute
    <- courses.branch_id (null = every branch)                      institute
    <- timetable_entries / class_sessions                           institute
    <- course_materials / assignments / exams                       institute
    <- certificates / student_id_cards / print_templates            institute
    <- support_tickets / meetings                                   institute
    <- student_fees.branch_id                                       collaborator-and-money
    <- invoices / expenses / incomes                                software-house
    <- users.branch_id                                              core
  activity_log
    <- activity_log.collaborator_id           added by Phase 9; D13 keeps ONE audit store

WEBSITE -> SOFTWARE HOUSE / INSTITUTE   (all twelve links are [deferred], §2.1 of phase-04)
  portfolio_items.client_id      -> clients.id          + client_name [snapshot]
  testimonials.client_id         -> clients.id          + the renderable snapshot
  testimonials.student_id        -> students.id
  student_reviews.student_id     -> students.id ; .course_id -> courses.id
  success_stories.student_id     -> students.id ; .course_id -> courses.id
  contact_inquiries.course_id    -> courses.id
  team_members.department_id     -> departments.id ; .employee_id -> employees.id  (F-3.13, defaults only)
  job_openings.department_id     -> departments.id
  job_applications.employee_id   -> employees.id        (F-3.12, set on hire)
  seo_meta.seoable_type/_id      -> any public entity    morphOne, ONE SEO store (D23)
  faqs.faqable_type/_id          -> Institute\Course     morphTo; there is NO course_faqs table (F-2.2)
  media_assets.id                <- every public *_media_id FK in any domain (D24 tier 1)

WEBSITE -> SOFTWARE HOUSE / INSTITUTE   (routing, not FK)
  contact_inquiries --InquiryRouter--> leads              via CrmLeadInquiryTarget   (F-2.1)
      leads.contact_inquiry_id      UNIQUE uq_leads_inquiry        (F-3.7: the index IS the guard)
  contact_inquiries --InquiryRouter--> course_inquiries
      course_inquiries.contact_inquiry_id  UNIQUE uq_ci_inquiry    (F-3.8)

SOFTWARE HOUSE internal, but across contracts (Phase 6 <-> Phase 5 <-> Phase 4)
  projects.client_id      -> clients.id         NOT NULL restrictOnDelete
  projects.lead_id        -> leads.id           nullable (set by Phase 5 conversion)
  projects.service_id     -> services.id        nullable (Phase 4 -> Phase 6)
  lead_conversions.client_id / .project_id                          the conversion evidence row
  invoices.client_id (NOT NULL) / .project_id (nullable)            Phase 13 -> Phase 5 / 6
  expenses.project_id / incomes.project_id / incomes.client_id      Phase 13 -> Phase 6 / 5
  expenses.source_type='payroll_run' + .source_id -> payroll_runs.id  (D44, no FK, UNIQUE pair)
  teachers.employee_id    -> employees.id       nullable UNIQUE, [deferred]   (a DUTY, D32)

SOFTWARE HOUSE / INSTITUTE -> COLLABORATOR-AND-MONEY
  collaborator_referrals                 is the single truth of attribution (D37)
    .collaborator_id -> collaborators.id                            NOT NULL restrictOnDelete
    .student_id      -> students.id          | institute
    .project_id      -> projects.id          | software-house        exactly one of the four
    .client_id       -> clients.id           | software-house        (CHECK chk_cr_one_subject)
    .lead_id         -> leads.id             | software-house
    .referral_visit_id -> collaborator_referral_visits.id  [deferred, Phase 9]
  snapshots that grant nothing (R5, D37) — never a scope, never an engine read:
    projects.collaborator_id / .referral_code / .referral_date      [snapshot]
    student_fees.collaborator_id                                    [snapshot]
    clients.referral_code_captured / .referral_recorded_at / .referral_visit_id   (F-3.4)
    leads.referral_code_captured / .referral_visit_id               (F-3.5)
    course_inquiries.referral_visit_id                              (F-3.14)
  project_members.collaborator_id -> collaborators.id               the ONLY project-people table (F-2.7)

MONEY IN -> COMMISSION  (the one path that turns cash into an entitlement)
  student_fee_payments          institute-side cash, spine §2.5
    .student_fee_id -> student_fees.id -> students.id / student_admissions.id / courses.id / batches.id
    .student_fee_installment_id -> student_fee_installments.id
    -> collaborator_commission_ledger_entries.student_fee_payment_id     0 or 1 earning per collaborator
  project_payments             software-house-side cash, spine §2.6
    .project_id -> projects.id ; .client_id -> clients.id ; .project_milestone_id -> project_milestones.id
    .invoice_id -> invoices.id   nullable, [deferred Phase 13]; the ONE INV-8 concession is D43
    .payment_method_id -> payment_methods.id  [deferred Phase 13]
    -> collaborator_commission_ledger_entries.project_payment_id
  payment_reversals            cash returned; CHECK chk_pr_one_target = fee receipt XOR project payment
    -> collaborator_commission_ledger_entries.payment_reversal_id       the negative, reverses_entry_id
  collaborator_commission_ledger_entries   (the spine, §51)
    .collaborator_id / .collaborator_wallet_id / .entitlement_id / .collaborator_referral_id
    .commission_setting_id   restrictOnDelete — the rule that produced money can never be deleted
    .student_id / .student_fee_id / .project_id / .project_milestone_id   report filters
    -> collaborator_payout_allocations -> collaborator_payouts            D18: named partial allocations
  finance_reversals            the expense / other-income counterpart (D41); shares the RV- series
  invoices.paid_amount / refunded_amount / balance_amount   CACHE of one SQL over project_payments (D40)

INSTITUTE -> everything else
  student_fees.student_id / .student_admission_id / .course_id / .batch_id / .branch_id   (spine owns it)
  student_fee_reminders -> student_fees / student_fee_installments        Phase 18's one table
  support_tickets  -> clients | students | teachers | collaborators | employees | projects | courses | batches
  meetings         -> projects | courses | batches | clients | leads | collaborators
  conversations.project_id -> projects.id ; ConversationScope enforces §94's six role pairs (D54)
  attachments (morph, Phase 6) <- projects | milestones | tasks | task_comments | tickets | replies
                                  | messages | meetings | assignments | Collaborator | Invoice   (F-13.2)
  course_material_downloads -> users / students / teachers ; PanelType on the row
  certificates / student_id_cards -> print_templates ; both are SNAPSHOTS (D52)
```

---

## 4. Enum registry

Every enum is in the flat `app/Enums/` namespace, string-backed, with `label(): string`, `color(): string`
and `static options(): array` (phase-01 §2). **One class, one name (R3):** the owner declares, everyone
else casts. 202 enum classes.

"First shipped" appears only where it differs from the owner, because the class file lands with whichever
phase migrates first while the **owner** remains the only phase that may change the cases.

### 4.1 Shared across domains — read this before adding any enum

| Enum | Owner | First shipped | Cases | Cast by (cross-domain) | Finding |
|---|---|---|---|---|---|
| `ContentStatus` | phase-03 | 3 | `draft` `scheduled` `published` `archived` | phase-03: `website_sections`/`pages`/`cta_blocks`/`faqs`.status · phase-04: `services`, `portfolio_items`, `team_members`, `success_stories`, `blog_posts`.status. **`PostStatus` does not exist** | F-5.1 |
| `EmploymentType` | **phase-07** | **phase-04** | `full_time` `part_time` `contract` `internship` `temporary` `consultant` `freelance` (+ `isSalaried()`, `leaveEligibleByDefault()`) | `employees.employment_type` (7) · `job_openings.employment_type` (4) | F-5.2 |
| `InquirySource` | phase-04 | 4 | `website` `facebook` `instagram` `tiktok` `google` `whatsapp` `referral` `walk_in` `call` `email` `other` | `contact_inquiries.source`, `job_applications.source` (4) · `leads.source`, `clients.source` (5) · `course_inquiries.source` (14-17). **`LeadSource` / `CourseInquirySource` deleted** | F-5.3 |
| `PaymentMethod` | **phase-07** (cases defined in spine §3) | 7 | `cash` `bank_transfer` `card` `cheque` `easypaisa` `jazzcash` `online_gateway` `adjustment` `other` | `employee_advances.disbursement_method`, `payroll_run_items.payment_method` (7) · `student_fee_payments`, `project_payments`, `payment_reversals.refund_method` (spine) · `expenses`/`incomes.payment_method`, `PaymentMethodType::defaultCode()` (13) | F-5.4 |
| `LedgerEntryType` | **phase-07** (cases defined in spine §3) | 7 | `credit` `debit` | `leave_balance_transactions.entry_type`, `employee_advance_repayments.entry_type` (7) · `collaborator_commission_ledger_entries.entry_type` (spine) | F-5.4 |
| `CommissionCalculationType` | **phase-06** (cases defined in spine §3) | 6 | `percentage` `fixed` `manual` | `projects.commission_type` (6) · `collaborator_commission_settings.calculation_type`, `collaborator_commission_ledger_entries.calculation_type` (spine) | F-5.5 |
| `Priority` | phase-06 | 6 | `low` `medium` `high` `urgent` (+ `weight()`) | `projects.priority`, `tasks.priority` (6) · `support_tickets.priority`, setting `support.ticket_default_priority` (22). **`TicketPriority` deleted**; the SLA multiplier is `TicketSlaService::multiplier(Priority)` | F-5.7 |
| `AttachmentVisibility` | phase-06 | 6 | `internal` `team` `client` | `attachments.visibility` — the **only** client-visibility mechanism; `client_documents` (5) and every phase-19-23 attachment use it. No `is_client_visible` boolean anywhere | F-2.6, F-2.8 |
| `CommentVisibility` | phase-06 | 6 | `internal` `team` (**no `client`**) | `task_comments.visibility`. Clients see no task comments in this release | F-3.2 |
| `ExportFormat` | phase-13 | 13 | `print` `pdf` `csv` + **`excel`** added by phase-23 | `report_exports.format` (23); `ReportExporter::export()` (13) | F-4.14 |
| `FinanceContext` | phase-13 | 13 | `software_house` `institute` `general` | `expenses.context`, `incomes.context`, `finance_categories.context` | — |
| `CourseResourceType` | phase-14-17 | 14 | `pdf` `document` `note` `slide` `image` `video` `audio` `zip` `source_code` `link` (+ `isFile()`, `allowedMimes()`, + `maxSizeSettingKey()` from 19) | `course_topic_resources.type` (14) · `course_materials.type` (19). A second `MaterialType` would fork the MIME whitelist ([D-19-3]) | — |
| `VerificationResult` | phase-20 | 20 | `valid` `revoked` `not_found` `throttled` `not_public` | `certificate_verifications.result`; one endpoint serves certificates **and** ID cards (21) | — |
| `RemainderPlacement` | phase-01 | 1 | `first` `last` `largest` | `Money::distribute()`; setting `institute.installment_remainder_placement` (18) | F-4.11 |
| `PanelType` | phase-01 | 1 | `admin` `collaborator` `student` `teacher` `client` | `roles.panel` (1) · `support_tickets.requester_panel`/`.last_reply_panel`, `ticket_replies.panel`, `messages.panel`, `conversation_participants.panel`, `course_material_downloads.panel` (19-23) | — |
| `DeliveryMode` | phase-14-17 | 14 | `physical` `online` `hybrid` | `courses`, `batches`, `class_sessions`, `timetable_entries`, `demo_classes`, `course_inquiries.preferred_delivery_mode`, `student_applications.preferred_delivery_mode`, `student_admissions` (14-17) · `exams`, `meetings` (20, 22) | — |
| `Weekday` | phase-14-17 | 14 | `monday` … `sunday` | `timetable_entries.day_of_week`; setting `hr.weekend_days` uses the same seven values | — |
| `Gender` | phase-14-17 | 14 | `male` `female` `other` | `students.gender`, `teachers.gender`. Phase 7 has **no** gender column | F-5.8 |
| `Ability` | phase-01 | 1 | the 18 abilities of `CLAUDE.md` §4 | `permissions.ability`. A closed list: no phase adds a case — publishing is `change_status`, reconciling is `change_status`, emailing an invoice is `change_status` | — |

**Two names on purpose, never merged** (F-5.9, resolutions §8 row 9): HR's `AttendanceStatus` (7 cases,
phase-07) and the institute's `StudentAttendanceStatus` (4 cases, phase-14-17). One flat namespace makes
merging them a merge conflict, not a simplification.

**No enum in the system is used by two domains with two meanings.** After convergence the five that used
to be duplicated (`PostStatus`, `LeadSource`, `CourseInquirySource`, `TicketPriority`, the second
`EmploymentType`/`ContentStatus` declarations) are deleted. The care points that remain are ownership, not
meaning, and each is flagged in the table above: three enums are **owned by a later phase but shipped by an
earlier one** (`EmploymentType`, `PaymentMethod`, `LedgerEntryType`, `CommissionCalculationType`), which
means the earlier phase must copy the owner's case list verbatim and may not extend it.

### 4.2 Core — phase-01 (7), phase-24-25 (8)

| Enum | Phase | Cases | Cast by |
|---|---|---|---|
| `UserStatus` | 1 | `active` `inactive` `suspended` `pending` (+ `canLogin()`) | `users.status` |
| `ThemePreference` | 1 | `light` `dark` `system` | `users.theme` |
| `PanelType` | 1 | see §4.1 | see §4.1 |
| `ModuleGroup` | 1 | `system` `software_house` `hr` `finance` `collaborator` `institute` `website` `shared` | `modules.group`. A **grouping**, not a capability: `is_core` is per module (F-6.5) |
| `Ability` | 1 | see §4.1 | `permissions.ability` |
| `LoginStatus` | 1 | `success` `failed` `logout` `blocked` | `login_histories.status` |
| `RemainderPlacement` | 1 | see §4.1 | see §4.1 |
| `BackupType` | 24 | `database` `files` `full` | `backup_runs.type` |
| `BackupStatus` | 24 | `pending` `running` `completed` `failed` `pruned` | `backup_runs.status` |
| `BackupTrigger` | 24 | `manual` `scheduled` `pre_restore` `pre_deploy` `test` | `backup_runs.trigger` |
| `BackupVerificationStatus` | 24 | `unverified` `checksum_ok` `restore_ok` `failed` | `backup_runs.verification_status` |
| `RestoreTarget` | 24 | `local` `staging` `production` | `backup_restores.target` |
| `RestoreStatus` | 24 | `requested` `running` `completed` `failed` `aborted` | `backup_restores.status` |
| `IntegrityCheckSuite` | 24 | `constraints` `wallet` `schema` `routes` `isolation` `uploads` `performance` `security` `backup` | `integrity_check_runs.suite` |
| `IntegrityCheckStatus` | 24 | `passed` `warning` `failed` | `integrity_check_runs.status` |

`retention_class` is deliberately **not** an enum: a string validated against constants on
`BackupRetentionService` (`transient`/`daily`/`weekly`/`monthly`/`yearly`).

### 4.3 Website — phase-03 (16), phase-04 (11)

| Enum | Phase | Cases | Cast by |
|---|---|---|---|
| `ContentStatus` | 3 | see §4.1 | see §4.1 |
| `SectionPlacement` | 3 | `home` `global_header` `global_footer` `page` (later phases add `courses_index`, `services_index`) | `website_sections.placement` |
| `MenuLocation` | 3 | `header` `footer_primary` `footer_secondary` `footer_legal` `mobile` | `menus.location` |
| `MenuItemLinkType` | 3 | `page` `route` `section_anchor` `url` `none` (later: `course`, `service`, `blog_category`) | `menu_items.link_type` |
| `MenuVisibility` | 3 | `all` `guest` `auth` | `menu_items.visibility` |
| `PageLayout` | 3 | `content` `sections` | `pages.layout` |
| `RobotsDirective` | 3 | `index_follow` `index_nofollow` `noindex_follow` `noindex_nofollow` | `seo_meta.robots` |
| `SitemapChangeFrequency` | 3 | `always` `hourly` `daily` `weekly` `monthly` `yearly` `never` | `seo_meta.sitemap_changefreq` |
| `CtaVariant` | 3 | `banner` `card` `inline` `split` `full_width` | `cta_blocks.variant` |
| `ButtonStyle` | 3 | `primary` `secondary` `outline` `ghost` `link` | `cta_blocks.primary_style` |
| `StatisticMetric` | 3 | `manual` `projects_completed` `happy_clients` `students_trained` `active_courses` `team_members` `years_experience` | `website_section_items.metric`; `module()`/`table()` gate a live figure |
| `MediaCollection` | 3 | `sections` `pages` `cta` `seo` `faq` `general` | `media_assets.collection` |
| `ImageProfile` | 3 | `hero` `banner` `card` `thumbnail` `logo` `icon` `og` `video_poster` | `media_assets.profile` (the 8 profiles of D24) |
| `MediaProcessingStatus` | 3 | `pending` `processing` `ready` `failed` `skipped` | `media_assets.derivatives_status` |
| `RevisionEvent` | 3 | `created` `draft_saved` `published` `unpublished` `reverted` `restored` | `cms_revisions.event` |
| `PreviewScope` | 3 | `section` `page` `placement` | signed preview links (no column) |
| `ApprovalStatus` | 4 | `pending` `approved` `rejected` | `testimonials.status`, `student_reviews.status` |
| `TestimonialType` | 4 | `client` `student` `other` | `testimonials.type` |
| `ContentSource` | 4 | `admin` `public_form` `client_panel` `student_panel` `import` | `testimonials.source`, `student_reviews.source` |
| `SocialPlatform` | 4 | `facebook` `instagram` `linkedin` `x_twitter` `github` `youtube` `tiktok` `behance` `dribbble` `website` | the allowlist for `team_members.social_links` keys |
| `WorkMode` | 4 | `onsite` `remote` `hybrid` | `job_openings.work_mode` |
| `JobOpeningStatus` | 4 | `draft` `open` `closed` `filled` | `job_openings.status` |
| `JobApplicationStatus` | 4 | `new` `reviewing` `shortlisted` `interview` `selected` `rejected` | `job_applications.status` (+ `allowedNext()`, the binding six-stage map) |
| `InquiryType` | 4 | `service` `course` `general` | `contact_inquiries.inquiry_type`; `routingTarget()` **is** the routing contract |
| `ContactInquiryStatus` | 4 | `new` `read` `in_progress` `responded` `closed` | `contact_inquiries.status` |
| `InquiryRoutingStatus` | 4 | `not_applicable` `pending` `routed` `failed` | `contact_inquiries.routing_status` |
| `InquirySource` | 4 | see §4.1 | see §4.1 |

No enum exists for `website_sections.section_key`: section types live in `WebsiteSectionRegistry`
([D-W3-9]), so phases 4, 5, 14 and 15 add a type by dropping in a class.

### 4.4 Software house — phase-05 (13), phase-06 (14), phase-07 (28), phase-13 (10)

| Enum | Phase | Cases | Cast by |
|---|---|---|---|
| `LeadStatus` | 5 | `new` `contacted` `interested` `negotiation` `proposal_sent` `won` `lost` | `leads.status`, `lead_activities.from_status`/`.to_status`, `lead_conversions.from_status` |
| `LeadActivityType` | 5 | `note` `call` `whatsapp` `email` `meeting` `status_changed` `assigned` `follow_up_scheduled` `follow_up_completed` `follow_up_missed` `converted` `imported` `duplicate_linked` `system` | `lead_activities.type` |
| `LeadContactOutcome` | 5 | `connected` `no_answer` `busy` `wrong_number` `call_back_later` `not_interested` `left_message` | `lead_activities.outcome`, `lead_follow_ups.outcome` |
| `LeadFollowUpType` | 5 | `call` `whatsapp` `email` `meeting` `visit` `other` | `lead_follow_ups.type` |
| `LeadFollowUpStatus` | 5 | `pending` `completed` `missed` `rescheduled` `cancelled` | `lead_follow_ups.status` (only `pending` fills `open_guard`) |
| `LeadConversionType` | 5 | `client` `project` `client_and_project` | `lead_conversions.conversion_type` |
| `LeadDuplicateMatchType` | 5 | `phone` `whatsapp` `email` `phone_vs_whatsapp` `client_phone` `client_email` `client_contact_phone` `client_contact_email` | `lead_conversions.matched_by`, `lead_import_rows.duplicate_match_type` |
| `LeadImportStatus` | 5 | `pending` `mapping` `validating` `validated` `processing` `completed` `completed_with_errors` `failed` `cancelled` | `lead_imports.status` |
| `LeadImportRowStatus` | 5 | `pending` `created` `updated` `skipped_duplicate` `skipped_invalid` `failed` | `lead_import_rows.status` |
| `LeadImportDuplicateStrategy` | 5 | `skip` `import_and_flag` `update_existing` | `lead_imports.duplicate_strategy` |
| `ClientType` | 5 | `individual` `company` | `clients.client_type` |
| `ClientStatus` | 5 | `active` `inactive` `suspended` `closed` | `clients.status` |
| `ClientDocumentCategory` | 5 | `contract` `nda` `proposal` `quotation` `purchase_order` `tax_certificate` `identity` `registration` `invoice_copy` `other` | `client_documents.category` |
| `ProjectStatus` | 6 | `planning` `pending` `in_progress` `review` `testing` `completed` `on_hold` `cancelled` | `projects.status` |
| `ProjectType` | 6 | `fixed_price` `hourly` `retainer` `maintenance` `internal` | `projects.project_type` (the engagement model; the kind of work is `service_id`) |
| `ProgressMode` | 6 | `auto` `manual` | `projects.progress_mode` (D34) |
| `ProgressBasis` | 6 | `milestones` `tasks` | `projects.progress_basis` |
| `MilestoneStatus` | 6 | `pending` `in_progress` `completed` `on_hold` `cancelled` | `project_milestones.status` |
| `TaskStatus` | 6 | `todo` `in_progress` `in_review` `blocked` `completed` `cancelled` | `tasks.status` |
| `Priority` | 6 | see §4.1 | see §4.1 |
| `ProjectMemberRole` | 6 | `manager` `lead` `member` `reviewer` `observer` | `project_members.role` (a collaborator is never `manager`) |
| `TimeEntrySource` | 6 | `timer` `manual` | `time_entries.source` |
| `TimeEntryStatus` | 6 | `running` `paused` `stopped` | `time_entries.status` |
| `TimerStopReason` | 6 | `pause` `stop` `auto_stop` `switched` | `time_entry_segments.end_reason` (D33) |
| `AttachmentVisibility` / `CommentVisibility` / `CommissionCalculationType` | 6 | see §4.1 | see §4.1 |
| `EmployeeStatus` | 7 | `active` `probation` `suspended` `inactive` `resigned` `terminated` | `employees.status` |
| `EmploymentType` | 7 | see §4.1 | see §4.1 |
| `SkillLevel` | 7 | `beginner` `intermediate` `advanced` `expert` | `employee_skills.level` |
| `EmployeeDocumentType` | 7 | `cnic` `passport` `contract` `offer_letter` `appointment_letter` `degree` `certificate` `experience_letter` `resume` `police_verification` `medical` `other` | `employee_documents.document_type` |
| `DocumentVerificationStatus` | 7 | `pending` `verified` `rejected` `expired` | `employee_documents.verification_status` |
| `DayType` | 7 | `working` `weekly_off` `public_holiday` | `attendances.day_type` |
| `AttendanceStatus` | 7 | `present` `late` `half_day` `early_leave` `absent` `on_leave` `holiday` | `attendances.status` (HR; not the institute's) |
| `AttendanceSource` | 7 | `self_web` `kiosk` `admin` `import` `api` `system` | `attendances.check_in_source`, `.check_out_source` |
| `AttendanceCorrectionType` | 7 | `missing_check_in` `missing_check_out` `wrong_time` `status_change` `leave_regularisation` `holiday_recalculation` `other` | `attendance_corrections.correction_type` |
| `CorrectionSource` | 7 | `self_request` `hr_direct` | `attendance_corrections.source` |
| `AttendanceCorrectionStatus` | 7 | `pending` `approved` `rejected` `cancelled` | `attendance_corrections.status` |
| `HolidayType` | 7 | `public` `religious` `company` `optional` | `holidays.holiday_type` |
| `LeaveAccrualMethod` | 7 | `annual_grant` `monthly_accrual` `none` | `leave_types.accrual_method` |
| `LeaveDayPortion` | 7 | `full_day` `first_half` `second_half` | `leave_requests.day_portion`, `leave_request_days.day_portion` |
| `LeaveRequestStatus` | 7 | `pending` `approved` `rejected` `cancelled` | `leave_requests.status` |
| `LeaveApprovalStatus` | 7 | `pending` `approved` `rejected` `skipped` | `leave_approvals.status` |
| `LeaveLedgerReason` | 7 | `annual_grant` `monthly_accrual` `joining_proration` `carry_forward_in` `carry_forward_expiry` `reservation` `reservation_release` `leave_consumed` `leave_cancelled` `manual_adjustment` `encashment` `year_end_lapse` `exit_settlement` | `leave_balance_transactions.reason` |
| `LedgerEntryType` / `PaymentMethod` | 7 | see §4.1 | see §4.1 |
| `SalaryComponentType` | 7 | `earning` `deduction` | `salary_components.side`, `payroll_run_item_components.side` |
| `SalaryComponentGroup` | 7 | `basic` `allowance` `bonus` `commission` `overtime` `reimbursement` `other_earning` `tax` `advance_recovery` `unpaid_leave` `late_deduction` `statutory` `other_deduction` | `salary_components.component_group`, `payroll_run_item_components.component_group` — **the group decides the side** |
| `SalaryComponentCalculation` | 7 | `fixed` `percentage_of_basic` `percentage_of_gross` `per_day` | `salary_components.calculation_type` |
| `SalaryStructureStatus` | 7 | `scheduled` `active` `superseded` `expired` `cancelled` | `salary_structures.status` |
| `PayrollRunType` | 7 | `regular` `correction` `bonus` `final_settlement` | `payroll_runs.run_type` (only `correction` allows negatives) |
| `PayrollRunStatus` | 7 | `draft` `generated` `locked` `partially_paid` `paid` `cancelled` | `payroll_runs.status` (D36: no unlock) |
| `PayrollItemStatus` | 7 | `draft` `locked` `on_hold` `paid` `cancelled` | `payroll_run_items.status` |
| `AdvanceStatus` | 7 | `requested` `approved` `rejected` `disbursed` `recovering` `settled` `written_off` `cancelled` | `employee_advances.status` |
| `AdvanceRecoveryType` | 7 | `payroll` `manual` `waiver` `correction` | `employee_advance_repayments.recovery_type` |
| `InvoiceStatus` | 13 | `draft` `sent` `partial` `paid` `overdue` `cancelled` | `invoices.status` — **derived**, written only by `InvoiceService::recomputeStatus()` |
| `DiscountMode` | 13 | `none` `percentage` `fixed` | `invoices.discount_mode`, `invoice_items.discount_mode` |
| `ExpenseStatus` | 13 | `pending` `approved` `rejected` `voided` | `expenses.status` (`countsInReports()` true only for `approved`) |
| `IncomeStatus` | 13 | `recorded` `voided` | `incomes.status` |
| `FinanceCategoryType` | 13 | `expense` `income` | `finance_categories.type` |
| `FinanceContext` | 13 | see §4.1 | see §4.1 |
| `PaymentMethodType` | 13 | `cash` `bank` `card` `mobile_wallet` `cheque` `gateway` `manual` `other` | `payment_methods.type` (+ `defaultCode(): PaymentMethod`) |
| `FinanceReportType` | 13 | `income` `expenses` `profit_loss` `receivables_aging` | `ReportRegistry` only — no column |
| `ExportFormat` | 13 | see §4.1 | see §4.1 |
| `AgingBucket` | 13 | `current` `d1_30` `d31_60` `d61_90` `d90_plus` | receivables report only — no column |

### 4.5 Collaborator and money — phase-08-09 (8), spine/phase-10 (27)

| Enum | Phase | Cases | Cast by |
|---|---|---|---|
| `CollaboratorStatus` | 8 | `pending` `active` `inactive` `suspended` | `collaborators.status`; `earnsCommission()` is the single place guard step 4 reads |
| `CollaborationType` | 8 | `freelancer` `agency` `referral_partner` `business_partner` `external_developer` `external_designer` `marketing_partner` `consultant` `trainer` `sales_partner` `other` | `collaborators.collaboration_type` |
| `PayoutAccountStatus` | 8 | `active` `disabled` | `collaborator_payout_accounts.status` (types the spine's two literals, D9) |
| `CollaboratorActivityEvent` | 8 | `login` `student_referral` `project_referral` `task_update` `file_upload` `file_download` `commission_created` `commission_approved` `commission_reversed` `payout_request` `payout_paid` | `activity_log.event` (filtered view); **`visibleProperties()` is the allowlist** and never includes `reason` (F-12.7) |
| `ReferralVisitOutcome` | 9 | `captured` `invalid_code` `collaborator_not_eligible` `self_referral` `bot_filtered` `expired` `converted` `overridden` | `collaborator_referral_visits.outcome` |
| `ReferralCandidateChannel` | 9 | `staff_selection` `typed_code` `session` `cookie` `hidden_field` `query_param` | the D38 six-rank ladder; `rank()` 1-6, `referralSource()` maps to the spine's enum |
| `ReferralConversionSubject` | 9 | `student` `student_admission` `project` `client` `lead` `course_inquiry` `contact_inquiry` | `collaborator_referral_visits.converted_subject_type` — deliberately **wider** than `ReferralSubject`; the spine's enum is untouched |
| `ReferralAttributionModel` | 9 | `first_touch` `last_touch` | setting only |
| `StudentFeeType` | 10 | `course_fee` `admission_fee` `registration_fee` `monthly_fee` `installment` `exam_fee` `certificate_fee` `other` | `student_fees.fee_type`; settings `collaborator.commissionable_fee_types`, `institute.fee_structure_fee_types` |
| `StudentFeeStatus` | 10 | `pending` `partial` `paid` `overpaid` `overdue` `cancelled` `refunded` | `student_fees.status` |
| `InstallmentStatus` | 10 | `pending` `partial` `paid` `overdue` `waived` `cancelled` | `student_fee_installments.status` |
| `FeeDiscountType` | 10 | `fixed_discount` `percentage_discount` `scholarship` `promotional_discount` `referral_discount` `waiver` `correction` `reversal` | `student_fee_discounts.type` (`isScholarship()` routes to `scholarship_amount`) |
| `ReceivedPaymentStatus` | 10 | `cleared` `partially_refunded` `refunded` `voided` `bounced` | `student_fee_payments.status`, `project_payments.status` |
| `ReversalType` | 10 | `full_refund` `partial_refund` `cancellation` `void` `bounced_instrument` `correction` | `payment_reversals.type`, `finance_reversals.type` (13) |
| `ReversalApprovalStatus` | 10 | `not_required` `pending` `approved` `rejected` | `payment_reversals.approval_status` |
| `CommissionScope` | 10 | `student` `project` | `collaborator_commission_settings.commission_for`, `collaborator_commission_entitlements.commission_for`, `collaborator_referrals.commission_for` |
| `CommissionBase` | 10 | `gross` `net_after_discount` `paid` `total_value` `milestone` | `collaborator_commission_settings.base_override`, `collaborator_commission_entitlements.commission_base`, `collaborator_commission_ledger_entries.commission_base`; values match Phase 2's two base settings exactly |
| `FixedCommissionRelease` | 10 | `prorated` `on_first_payment` `per_payment` | `collaborator_commission_settings.fixed_release` |
| `CommissionRuleStatus` | 10 | `scheduled` `active` `superseded` `expired` `cancelled` | `collaborator_commission_settings.status` |
| `CommissionRuleSource` | 10 | `collaborator_rule` `project_override` `manual` — **three cases, no `global_default`** | `collaborator_commission_entitlements.rule_source`, `collaborator_commission_ledger_entries.rule_source`. No silent fallback to a global rate ([D-FS-9], F-5.6) |
| `EntitlementDocumentType` | 10 | `student_admission` `student_fee` `project` `project_milestone` | `collaborator_commission_entitlements.document_type` |
| `EntitlementStatus` | 10 | `open` `fully_released` `closed` `superseded` `cancelled` | `collaborator_commission_entitlements.status` |
| `CommissionSourceType` | 10 | `student_fee_payment` `student_installment_payment` `project_payment` `payment_reversal` `manual_adjustment` | `collaborator_commission_ledger_entries.source_type` (NOT NULL, part of `uq_cle_source`) |
| `LedgerEntryPurpose` | 10 | `student_commission` `project_commission` `reversal` `clawback` `manual_adjustment` `write_off` | `collaborator_commission_ledger_entries.purpose`; `chk_cle_sign` ties purpose to direction |
| `CommissionStatus` | 10 | `pending` `approved` `available` `paid` `reversed` `cancelled` | `collaborator_commission_ledger_entries.status`; `isPayable()` true only for `available` |
| `CommissionApprovalMode` | 10 | `automatic` `manual` | `collaborator_commission_entitlements.approval_mode` (snapshot); setting `collaborator.commission_approval_mode` |
| `CommissionProcessingState` | 10 | `queued` `processed` `skipped` `failed` `not_applicable` | `student_fee_payments.commission_state`, `project_payments.commission_state` — a hint, **never a guard** |
| `CommissionSkipReason` | 10 | the 20 cases of spine §3 (`referral_system_disabled` … `reversal_not_approved`) | `student_fee_payments.commission_skip_reason`, `project_payments.commission_skip_reason` — an enum so "why did this receipt pay nothing?" is groupable |
| `PayoutStatus` | 10 | `requested` `pending` `approved` `paid` `rejected` `cancelled` | `collaborator_payouts.status` |
| `PayoutMethod` | 10 | `bank_transfer` `easypaisa` `jazzcash` `cash` `cheque` `other` | `collaborator_payouts.method`, `collaborator_payout_accounts.method` |
| `AllocationReleaseReason` | 10 | `payout_rejected` `payout_cancelled` `payout_returned` `released_for_reversal` | `collaborator_payout_allocations.release_reason` |
| `ReferralSubject` | 10 | `student` `project` `client` `lead` | `collaborator_referrals.subject_type` |
| `ReferralSource` | 10 | `referral_link` `manual_selection` `admission_form` `import` `api` | `collaborator_referrals.referral_source`. `api` is **reserved** — no REST API in this release (H1, F-13.1) |
| `ReferralStatus` | 10 | `active` `superseded` `revoked` | `collaborator_referrals.status`; only `active` fills `current_guard` |
| `ReconciliationStatus` | 10 | `ok` `drift` `repaired` `failed` | `collaborator_wallet_reconciliations.status`, `collaborator_wallets.reconciliation_status` |
| `PaymentMethod` / `LedgerEntryType` / `CommissionCalculationType` | 7 / 7 / 6 | see §4.1 | the spine defines the cases, declares none of the three |

Money-enum colour convention, so every badge reads alike: earned/healthy `emerald`, waiting `amber`,
terminal-negative `rose`, settled `sky`, inert `slate`.

### 4.6 Institute — phase-14-17 (27), phase-18 (2), phase-19-23 (31)

| Enum | Phase | Cases | Cast by |
|---|---|---|---|
| `CourseStatus` | 14 | `draft` `published` `archived` | `courses.status` |
| `CourseLevel` | 14 | `beginner` `intermediate` `advanced` | `courses.level` |
| `DeliveryMode` / `Weekday` / `CourseResourceType` | 14 | see §4.1 | see §4.1 |
| `DurationUnit` | 14 | `hours` `days` `weeks` `months` | `courses.duration_unit` |
| `LectureType` | 14 | `lecture` `lab` `workshop` `revision` `assessment` `project` | `course_lectures.lecture_type` |
| `CourseInquiryStatus` | 15 | `new` `contacted` `interested` `demo_scheduled` `admission_confirmed` `not_interested` | `course_inquiries.status` (D45: advanced in lockstep by `AdmissionService`) |
| `FollowUpChannel` | 15 | `call` `whatsapp` `sms` `email` `in_person` `other` | `course_inquiry_follow_ups.channel` |
| `FollowUpOutcome` | 15 | `reached` `no_answer` `busy` `wrong_number` `interested` `not_interested` `demo_requested` `admission_requested` `call_later` | `course_inquiry_follow_ups.outcome`; `suggestsStatus()` drives the inquiry status |
| `PreferredTiming` | 15 | `morning` `afternoon` `evening` `night` `weekend` `flexible` | `course_inquiries`, `student_applications`, `student_admissions.preferred_timing` |
| `StudentApplicationStatus` | 15 | `submitted` `under_review` `converted` `rejected` `duplicate` `withdrawn` | `student_applications.status` |
| `StudentStatus` | 15 | `inquiry` `applied` `registered` `active` `completed` `dropped` `suspended` | `students.status` (D45) |
| `AdmissionStage` | 15 | `application` `registration` `fee_collection` `batch_assignment` `active` `completed` `cancelled` `withdrawn` | `student_admissions.stage` — **the §68 pipeline carrier** (D45) |
| `Gender` | 15 | see §4.1 | see §4.1 |
| `DemoSubjectType` | 15 | `inquiry` `application` `student` | `demo_classes.subject_type` |
| `DemoClassStatus` | 15 | `scheduled` `attended` `missed` `converted` `cancelled` | `demo_classes.status` |
| `TeacherStatus` | 16 | `active` `inactive` `on_leave` `resigned` `suspended` | `teachers.status`; `canTeach()` gates the timetable |
| `ClassroomType` | 16 | `classroom` `lab` `hall` `virtual` | `classrooms.type` (`virtual` never clashes) |
| `BatchStatus` | 16 | `planned` `enrolling` `running` `on_hold` `completed` `cancelled` | `batches.status` |
| `EnrollmentStatus` | 16 | `active` `suspended` `transferred_out` `completed` `dropped` `cancelled` | `student_batch_enrollments.status`; `countsInCapacity()` true for `active` only (D48) |
| `ClassSessionStatus` | 16 | `scheduled` `held` `cancelled` `rescheduled` | `class_sessions.status`; attendance counts only on `held` (D46) |
| `ClassCancellationReason` | 16 | `holiday` `teacher_unavailable` `classroom_unavailable` `low_attendance` `technical` `batch_on_hold` `other` | `class_sessions.cancellation_reason` |
| `StudentAttendanceStatus` | 17 | `present` `absent` `leave` `late` | `student_attendances.status` — deliberately **not** `AttendanceStatus` (F-5.9) |
| `AttendanceMarkSource` | 17 | `manual` `bulk` `import` `system` | `student_attendances.marked_via` |
| `ProgressStatus` | 17 | `pending` `in_progress` `completed` `skipped` | `batch_topic_coverage.status`, `student_course_progress.status`, `student_module_progress.status`, `student_topic_progress.status` |
| `ProgressSource` | 17 | `batch_coverage` `manual` `assessment` | `student_topic_progress.source` (`assessment` reserved for Phase 20) |
| `InstallmentInterval` | 18 | `monthly` `fortnightly` `weekly` `custom` | plan generation; `monthly` uses `addMonthsNoOverflow` (D49, D50) |
| `FeeReminderType` | 18 | `upcoming_due` `due_today` `overdue` | `student_fee_reminders.type` |
| `MaterialStatus` | 19 | `draft` `published` `archived` | `course_materials.status` |
| `MaterialTargetType` | 19 | `course` `batch` `student` | `course_material_targets.target_type`, `course_materials.audience_scope` (`breadth()` picks the broadest) |
| `MaterialAccessAction` | 19 | `view` `download` `link_open` | `course_material_downloads.action` |
| `AssignmentStatus` | 19 | `draft` `published` `closed` `archived` | `assignments.status` |
| `SubmissionType` | 19 | `file` `text` `file_or_text` `file_and_text` | `assignments.submission_type` |
| `SubmissionStatus` | 19 | `draft` `submitted` `under_review` `returned` `graded` `missed` `superseded` | `assignment_submissions.status`; `visibleMarks()` only when `graded` |
| `ExamType` | 20 | `quiz` `weekly_test` `monthly_test` `midterm` `final` `practical` | `exams.exam_type` |
| `ExamStatus` | 20 | `draft` `scheduled` `ongoing` `conducted` `marking` `results_published` `cancelled` | `exams.status` |
| `ExamAttendanceStatus` | 20 | `appeared` `absent` `exempt` `debarred` | `exam_results.attendance_status` |
| `VerificationResult` | 20 | see §4.1 | see §4.1 |
| `PrintTemplateType` | 21 | `certificate` `student_id_card` `result_card` | `print_templates.type`; `tokens()` delegates to `PrintTokenRegistry` (D53) |
| `PaperSize` | 21 | `a4` `a5` `letter` `legal` `cr80` `custom` | `print_templates.paper_size` (`cr80` = 85.60 x 53.98 mm) |
| `PageOrientation` | 21 | `portrait` `landscape` | `print_templates.orientation` |
| `CertificateStatus` | 21 | `draft` `issued` `revoked` | `certificates.status`. **`reissued` is a link, not a status** ([D-21-3], D52) |
| `IdCardStatus` | 21 | `active` `expired` `lost` `damaged` `replaced` `revoked` | `student_id_cards.status` |
| `TicketStatus` | 22 | `open` `in_progress` `waiting` `resolved` `closed` | `support_tickets.status`, `ticket_replies.status_from`/`.status_to`; `pausesSla()` only for `waiting` |
| `TicketAssignStrategy` | 22 | `none` `default_assignee` `round_robin` `least_open` | `ticket_departments.auto_assign_strategy` |
| `ReplyVisibility` | 22 | `public` `internal_note` | `ticket_replies.visibility` |
| `MeetingStatus` | 22 | `scheduled` `completed` `cancelled` `postponed` `missed` | `meetings.status` |
| `MeetingParticipantRole` | 22 | `organizer` `required` `optional` `note_taker` | `meeting_participants.role` |
| `MeetingResponse` | 22 | `pending` `accepted` `declined` `tentative` | `meeting_participants.response` |
| `ParticipantType` | 22 | `staff` `client` `student` `teacher` `collaborator` `external` | `meeting_participants.participant_type` |
| `ConversationType` | 22 | `direct` `group` | `conversations.type` |
| `ConversationScope` | 22 | `admin_employee` `employee_employee` `client_manager` `collaborator_staff` `student_staff` `teacher_management` | `conversations.pair_scope` — §94's six pairs verbatim, re-checked on every send via `MessagingMatrix` (D54) |
| `NotificationLevel` | 22 | `info` `success` `warning` `critical` | `notifications.level` |
| `NotificationDigest` | 22 | `immediate` `daily` `off` | `notification_preferences.mail_digest` |
| `NotificationGroup` | 22 | `system` `software_house` `institute` `finance` `collaborator` `support` | the preference screen; **mirrors `ModuleGroup` without redefining it** — a notification group is not a module |
| `ReportGroup` | 23 | `software_house` `institute` `collaborator` `system` | `ReportRegistry` (D56) — no column |
| `ExportStatus` | 23 | `queued` `running` `completed` `failed` `expired` | `report_exports.status` |
| `SearchEntityType` | 23 | `client` `employee` `collaborator` `student` `teacher` `course` `lead` `project` `task` `invoice` `ticket` | the §108 provider registry — no column |
| `AuditSensitivity` | 23 | `normal` `sensitive` `financial` | the §107 audit trail; `financial` requires `view_financial` to read old/new values |

---

## 5. Module registry

Permission name is `{module_slug}.{ability}` (R8, D4). `PermissionRegistry` is the **only** place a
permission name exists; `ModuleSeeder` and `RoleSeeder` append idempotently and never delete (F-6.4).
**112 slugs**: 75 registered at Phase 1, 37 appended later. `ModuleGroup` is a grouping; `is_core` is a
per-module flag (F-6.5) — the ten Phase 1 System modules are the only `is_core = true` rows.

"Panel" = the panels whose routes carry `module:<slug>`. Every slug is reachable from **admin**; extra
panels are listed because their routes are module-gated too. The four `*_portal.*` prefixes are
**permission namespaces, not modules**, and `permissionModuleMap()` returns `null` for them so module
gating never denies them (**D20**, F-12.1).

### 5.1 System (`ModuleGroup::System`)

| Slug | is_core | Phase | Panel |
|---|---|---|---|
| `dashboard` `users` `roles` `permissions` `modules` `settings` `activity_log` `login_history` `backups` `global_search` | **true** (all ten) | 1 | admin |
| `audit_trail` | false | 19-23 | admin |
| `system_health` | false | 24-25 | admin |
| `integrity_checks` | false | 24-25 | admin |

`activity_log` gains `LOGS` + `export` + `print` in 19-23; `global_search` gains `view_any` in 19-23;
`backups` is extended by 24-25. Disabling `system_health` / `integrity_checks` 403s their routes for
everyone including Super Admin but never stops the console commands.

### 5.2 Software house (`ModuleGroup::SoftwareHouse`)

| Slug | is_core | Phase | Panel | Ability set after its phase |
|---|---|---|---|---|
| `leads` | false | 1 (filled 5) | admin; collaborator (`/collaborator/leads`) | `CRUD_FULL` + `restore` + `ASSIGN` + `STATUS` + `import` + `LOGS` + `view_reports` |
| `clients` | false | 1 (filled 5) | admin | `CRUD_FULL` + `restore` + `ASSIGN` + `STATUS` + `MONEY` + `LOGS` + `view_reports` |
| `client_documents` | false | **5** | admin; client | `READ` + `create` + `edit` + `delete` + `FILES` + `STATUS` + `LOGS` |
| `projects` | false | 1 (filled 6) | admin; client | `CRUD_FULL` + `restore` + `STATUS` + `ASSIGN` + `MONEY` + `REPORTS` + `LOGS` |
| `project_milestones` | false | 1 (filled 6) | admin; client | `CRUD` + `STATUS` + `REPORTS` |
| `tasks` | false | 1 (filled 6) | admin; client; collaborator | `CRUD_FULL` + `restore` + `STATUS` + `ASSIGN` + `REPORTS` + `LOGS` |
| `task_comments` | false | **6** | admin; collaborator | `READ` + `create` + `edit` + `delete` |
| `time_tracking` | false | 1 (filled 6) | admin; collaborator | `CRUD` + `STATUS` + `REPORTS` + `export` + `print` + `LOGS` |

### 5.3 HR (`ModuleGroup::Hr`)

| Slug | is_core | Phase | Panel | Note |
|---|---|---|---|---|
| `employees` | false | 1 (filled 7) | admin | `view_financial` gates the Salary tab |
| `departments` | false | 1 (filled 7) | admin | `assign` = set the head |
| `attendance` | false | 1 (filled 7) | admin | **no `delete`** (HR-6) |
| `leaves` | false | 1 (filled 7) | admin | |
| `payroll` | false | 1 (filled 7) | admin | **never `edit`, never `delete`** (D36) |
| `designations` | false | **7** | admin | |
| `employee_documents` | false | **7** | admin | `download` is the privacy gate; every download logged |
| `work_shifts` | false | **7** | admin | |
| `holidays` | false | **7** | admin | |
| `leave_types` | false | **7** | admin | |
| `leave_balances` | false | **7** | admin | **no `edit`, no `delete`** — minting days is append-only (HR-7) |
| `salary_components` | false | **7** | admin | |
| `salary_structures` | false | **7** | admin | **never `edit`/`delete`** — a raise is a new version (HR-10) |
| `salary_slips` | false | **7** | admin | read + print, no right to generate/lock/pay |
| `employee_advances` | false | **7** | admin | **never `delete`** |
| `employee_self_service` | false | **7** | admin (own data) | `view_financial` = own slip amounts |

`modules.depends_on`: `attendance`→`employees`; `leaves`→`employees`,`leave_types`;
`leave_balances`→`leaves`,`leave_types`; `payroll`→`employees`,`attendance`,`salary_structures`;
`salary_structures`→`employees`,`salary_components`; `salary_slips`→`payroll`.

### 5.4 Finance (`ModuleGroup::Finance`)

| Slug | is_core | Phase | Panel | Note |
|---|---|---|---|---|
| `invoices` | false | 1 (filled 13) | admin; client | emailing is `change_status`; no `send` ability |
| `payments` | false | 1 (filled 13) | admin | **umbrella only**, for phase-13's cross-source register. It never gates a project-payment screen (F-6.1) |
| `expenses` | false | 1 (filled 13) | admin | `APPROVE` + `STATUS` (void) + `FILES` (receipt, private disk, D21) |
| `income` | false | 1 (filled 13) | admin | no approval workflow |
| `payment_methods` | false | 1 (filled 13) | admin | no `MONEY`, no `export` |
| `finance_categories` | false | **13** | admin | no `MONEY` — the table holds no money |
| `project_payments` | false | **spine/10** | admin; **client** | on **every** project-payment route, admin and client (F-6.1). `+ view_reports` from 13 |
| `payment_reversals` | false | **spine/10** | admin | created and approved, never edited or deleted |
| `wallet_reconciliation` | false | **spine/10** | admin | `change_status` = run / repair |

### 5.5 Collaborator (`ModuleGroup::Collaborator`)

| Slug | is_core | Phase | Panel | Note |
|---|---|---|---|---|
| `collaborators` | false | 1 (filled 8) | admin; collaborator (`routes/collaborator.php` carries `module:collaborators`) | `forceDelete` refused (INV-C5) |
| `collaborator_commission_settings` | false | 1 (filled spine) | admin | **never `edit`/`delete`** (INV-17) |
| `collaborator_commissions` | false | 1 (filled spine) | admin; collaborator | **never `edit`/`delete`**; `collaborator_commission_entitlements` has no module of its own |
| `collaborator_wallets` | false | 1 (filled spine) | admin; collaborator | `READ` + `MONEY` + `REPORTS` |
| `collaborator_payouts` | false | 1 (filled spine) | admin; collaborator | **never `delete`** |
| `collaborator_referrals` | false | 1 (filled spine + 8-9) | admin | spine set **+ `REPORTS` + `export`** |
| `collaborator_payout_accounts` | false | **8-9** | admin; collaborator | no `view_financial`: nothing unmasks `details_encrypted` (INV-C6) |
| `collaborator_referral_visits` | false | **8-9** | admin | read-only by design; holds IP / user-agent |

### 5.6 Institute (`ModuleGroup::Institute`)

| Slug | is_core | Phase | Panel | Note |
|---|---|---|---|---|
| `course_categories` | false | 1 (filled 14) | admin | |
| `courses` | false | 1 (filled 14) | admin; student; teacher | `view_financial` gates the three fee columns |
| `course_outline` | false | 1 (filled 14) | admin; teacher | modules / topics / lectures / resources / assignment blueprints |
| `course_materials` | false | 1 (filled 19) | admin; student; teacher | `assign` **is** the targeting ability |
| `students` | false | 1 (filled 15) | admin; teacher | `view_financial` is **not** here — money is `student_fees.view_financial` |
| `student_applications` | false | **14-17** | admin | **never `delete`** — a public submission is rejected, not deleted |
| `admissions` | false | 1 (filled 15) | admin | `view_financial` gates the agreed figures |
| `course_inquiries` | false | 1 (filled 15) | admin | |
| `demo_classes` | false | 1 (filled 15) | admin; teacher | |
| `teachers` | false | 1 (filled 16) | admin; student | `view_financial` gates `salary` |
| `classrooms` | false | **14-17** | admin | |
| `batches` | false | 1 (filled 16) | admin; student; teacher | **`assign` is the enrollment / transfer ability** |
| `timetable` | false | 1 (filled 16) | admin; student; teacher | `change_status` = cancel / reschedule / substitute / mark held |
| `student_attendance` | false | 1 (filled 17) | admin; student; teacher | `edit` = the post-lock amendment (INV-I10) |
| `student_progress` | false | 1 (filled 17) | admin; student; teacher | |
| `student_fees` | false | 1 (filled spine + 18) | admin; student | `CRUD_FULL` + `STATUS` + `MONEY` **+ `view_reports`** (18) |
| `installments` | false | 1 (filled spine) | admin; student | `CRUD` + `STATUS` |
| `fee_discounts` | false | 1 (filled spine) | admin | `READ` + `create` + `APPROVE` + `MONEY` + `LOGS` |
| `student_fee_payments` | false | **spine/10** | admin; student | spine set **+ `approve`** (18, F-6.9) **+ `view_reports`** (13). No `edit`, no `delete`, ever |
| `fee_reminders` | false | **18** | admin | `READ` + `create` + `LOGS`; a sent reminder is a log row |
| `assignments` | false | 1 (filled 19) | admin; student; teacher | `print` = the printable sheet |
| `assignment_submissions` | false | **19-23** | admin; student; teacher | **never `delete`**; `edit` **is** the mark-and-feedback ability |
| `grade_scales` | false | **19-23** | admin | |
| `exams` | false | 1 (filled 20) | admin; student; teacher | |
| `results` | false | 1 (filled 20) | admin; student; teacher | **never `delete`**; `approve` = the verification step |
| `certificates` | false | 1 (filled 21) | admin; student; teacher | **never `delete`** except a draft (policy) |
| `student_id_cards` | false | 1 (filled 21) | admin; student | `print` also gates batch printing |
| `print_templates` | false | **19-23** | admin | `body_html` is powerful: separately grantable, separately audited (D53) |

### 5.7 Website (`ModuleGroup::Website`) — all `is_core = false`

| Slug | Phase | Abilities after its phase |
|---|---|---|
| `website_sections` | 1 (filled 3) | `CRUD` + `STATUS` + `LOGS` — `change_status` is publish/unpublish/revert/flush |
| `menus` | 1 (filled 3) | `CRUD` + `STATUS` |
| `pages` | 1 (filled 3) | `CRUD_FULL` + `STATUS` + `LOGS` |
| `faqs` | 1 (filled 3) | `CRUD` + `STATUS` |
| `seo` | 1 (filled 3) | `READ` + `edit` + `export` + `LOGS` — **no `create`/`delete`** |
| `website_cta_blocks` | **3** | `CRUD` + `STATUS` + `LOGS` |
| `website_media` | **3** | `READ` + `create` + `edit` + `delete` + `FILES` + `LOGS` |
| `faq_categories` | **3** | `CRUD` + `STATUS` |
| `service_categories` | **4** | `CRUD` + `STATUS` (sort 410) |
| `services` | 1 (filled 4) | `CRUD_FULL` + `STATUS` + `FILES` (411) |
| `technologies` | **4** | `CRUD` + `STATUS` + `FILES` (412) |
| `portfolio_categories` | **4** | `CRUD` + `STATUS` (415) |
| `portfolio` | 1 (filled 4) | `CRUD_FULL` + `STATUS` + `FILES` (416) |
| `team` | 1 (filled 4) | `CRUD_FULL` + `STATUS` + `FILES` (420) |
| `testimonials` | 1 (filled 4) | `CRUD` + `STATUS` + `APPROVE` + `FILES` (425) |
| `student_reviews` | 1 (filled 4) | `CRUD` + `STATUS` + `APPROVE` + `FILES` (426) |
| `success_stories` | 1 (filled 4) | `CRUD` + `STATUS` + `FILES` (427) |
| `blog_categories` | 1 (filled 4) | `CRUD` + `STATUS` (430) |
| `blog_tags` | **4** | `CRUD` + `STATUS` (431) |
| `blog_posts` | 1 (filled 4) | `CRUD_FULL` + `STATUS` + `APPROVE` + `FILES` + `REPORTS` (432) — `approve` is the *editor* ability |
| `jobs` | 1 (filled 4) | `CRUD_FULL` + `STATUS` (440) |
| `job_applications` | 1 (filled 4) | `READ` + `edit` + `delete` + `STATUS` + `ASSIGN` + `download` + `export` + `print` (441) |
| `contact_inquiries` | 1 (filled 4) | `READ` + `edit` + `delete` + `STATUS` + `ASSIGN` + `export` + `print` + **`view_logs`** (445) |

`contact_inquiries.view_logs` is the **only** way to reach the PII block (`ip_address`, `user_agent`,
`utm_*`, `referrer_url`, `filled_in_seconds`, spam verdict); without it those columns are absent from the
query and the response body (F-12.4, H7). `cms_revisions`, `sitemap_generations` and
`website_section_items` get **no** module of their own. Public routes carry **no** `module:`/`can:`; a
content module may gate its **own** public routes with `site_module`, which 404s (**D26**, F-6.6).

### 5.8 Shared (`ModuleGroup::Shared`) — all `is_core = false`

| Slug | Phase | Panel | Abilities after its phase |
|---|---|---|---|
| `support_tickets` | 1 (filled 22) | admin; client; student; teacher; collaborator | `CRUD_FULL` + `ASSIGN` + `STATUS` + `FILES` + `REPORTS` + `LOGS`; `delete` registered but refused by policy |
| `ticket_departments` | **19-23** | admin | `CRUD` + `STATUS` |
| `meetings` | 1 (filled 22) | admin; client; student; teacher; collaborator | `CRUD_FULL` + `ASSIGN` + `STATUS` + `FILES` + `print` + `REPORTS` |
| `messages` | 1 (filled 22) | admin; client; student; teacher; collaborator | `READ` + `create` + `STATUS` + `FILES` + `LOGS` — **never `edit`/`delete`** |
| `files` | 1 (filled 6, 19-23) | admin; client; collaborator | `READ` + `FILES` + `delete` (6) + `STATUS` (19-23). **Governs the `attachments` table; no `files` table exists** |
| `notifications` | 1 (filled 22) | admin (broadcast register) | `READ` + `STATUS` + `delete`; a user always reaches their own rows |
| `reports` | 1 (filled 13, 19-23) | admin | `READ` + `REPORTS`; every individual report stacks its source module's `view_reports` (D56, INV-23-2) |

---

## 6. Numbering registry

**One implementation only: `App\Services\Finance\DocumentNumberService`, shipped by Phase 5** (D27,
F-4.1), with `next(string $prefixKey, string $counterKey, string $pad = '%06d'): string` and
`reserve(string $counterKey, ?string $periodKey = null, ?string $periodValue = null): int` (F-4.12).
`'%06d'` is a **default, not a house style** — every caller passes its own pad. The counter row is a
`settings` row taken with `SELECT … FOR UPDATE` inside the caller's transaction; the column's UNIQUE index
is the backstop and a 1062 triggers exactly one retry. **There is no second `FOR UPDATE` counter anywhere
in the system**; `ProjectNumberService` and `StudentNumberService` are thin delegates.

| Document | Column (unique index) | Prefix key (default) | Counter key | Pad | Owner | Generator |
|---|---|---|---|---|---|---|
| Lead number | `leads.lead_no` `uq` | `crm.lead_number_prefix` (`LD-`) | `crm.lead_number_next_number` | from `crm.number_padding` (6) | 5 | `LeadService::create()`; immutable (model `updating` throws) |
| Client ID | `clients.client_code` `uq_clients_code` | `crm.client_code_prefix` (`CL-`) | `crm.client_code_next_number` | 6 | 5 | `ClientService::create()`; immutable, never reused |
| Project ID | `projects.code` `uq_projects_code` | `projects.project_code_prefix` (`PRJ-`) | `projects.project_code_next_number` | `%05d` | 6 | `ProjectNumberService::next()` (delegate) |
| Employee ID | `employees.employee_code` `uq_emp_code` | `hr.employee_code_prefix` (`EMP-`) | `hr.employee_code_next_number` | `%05d` | 7 | `EmployeeService::create()`, in-transaction |
| Leave request | `leave_requests.request_number` `uq` | `hr.leave_request_prefix` (`LVR-`) | `hr.leave_request_next_number` | `%05d` | 7 | `LeaveService` |
| Advance | `employee_advances.advance_number` `uq` | `hr.advance_number_prefix` (`ADV-`) | `hr.advance_next_number` | `%05d` | 7 | `AdvanceService` |
| Payroll run | `payroll_runs.run_number` `uq` | `hr.payroll_run_prefix` (`PR-`) | `hr.payroll_run_next_number` | `%05d` | 7 | `PayrollService` |
| Payslip | `payroll_run_items.slip_number` `uq` | `hr.payslip_prefix` (`SLP-`) | `hr.payslip_next_number` | `%05d` | 7 | assigned at generation |
| Collaborator code | `collaborators.collaborator_code` `uq_col_code` | `collaborator.collaborator_code_prefix` (`COL-`) | `collaborator.collaborator_code_next_number` (starts **1001**) | `%04d` | 8 | `CollaboratorService::nextCollaboratorCode()`; immutable (INV-C1) |
| Fee charge | `student_fees.fee_number` `uq_sf_number` | `institute.fee_record_prefix` (`FS-`) | `institute.fee_record_next_number` | caller's | spine/10 | `StudentFeeService::issue()` |
| Fee receipt | `student_fee_payments.receipt_no` `uq_sfp_receipt` | `institute.fee_receipt_prefix` (**Phase 2 key, default not stated** — §8) | `institute.fee_receipt_next_number` | `%06d` | spine/10 | `PaymentService` |
| Project payment | `project_payments.payment_no` `uq_pp_number` | `finance.project_payment_prefix` (`PP-`) | `finance.project_payment_next_number` | caller's | spine/10 | `PaymentService` |
| Reversal voucher | `payment_reversals.reversal_no` `uq_pr_number` **and** `finance_reversals.reversal_no` | `finance.payment_reversal_prefix` (`RV-`) | `finance.payment_reversal_next_number` | caller's | spine/10; shared with 13 | `PaymentService::refund()` / phase-13's reversal services |
| Payout voucher | `collaborator_payouts.payout_no` `uq_cp_number` | `finance.collaborator_payout_prefix` (`PO-`) | `finance.collaborator_payout_next_number` | caller's | spine/10 | `PayoutService` |
| Ledger reference | **accessor** `reference` = `'CLE-' . id` | — | — | — | spine/10 | deliberately **not** a counter: one settings row would serialise every commission in the system ([D-FS-6]) |
| Invoice | `invoices.invoice_number` **nullable**, `uq` | `finance.invoice_prefix` (Phase 2 key, default not stated) | `finance.invoice_next_number` (Phase 2 key) | default | 13 | `InvoiceService::issue()` — assigned **once at issue**, never to a draft, never reused; a cancelled invoice keeps it (**D42**); no yearly reset (Q4) |
| Expense voucher | `expenses.expense_no` `uq_exp_no` | `finance.expense_prefix` (`EXP-`) | `finance.expense_next_number` | default | 13 | `ExpenseService::record()`; no drafts, so no gaps |
| Other-income voucher | `incomes.income_no` `uq` | `finance.income_prefix` (`INC-`) | `finance.income_next_number` | default | 13 | `IncomeService` |
| Student ID | `students.student_code` `uq_st_code` | format `institute.student_id_format` = `{PREFIX}{YY}{SEQ:5}`; `{PREFIX}` reads `institute.student_id_prefix` (Phase 2 key) | `institute.student_id_next_number` + `institute.student_id_period` + `institute.student_id_sequence_scope` (`yearly`) | via `{SEQ:n}` | 15 | `StudentNumberService::nextStudentCode()` → `reserve()` with period reset. **Never `student_id`** ([D-IN-3]) |
| Registration number | `students.registration_number` string(40) nullable `uq_st_regno` | `institute.registration_number_format` (Phase 2 key) | `institute.registration_number_next_number` + `_period` + `_sequence_scope` | via format | 15 | `StudentNumberService::nextRegistrationNumber()` — issued **only** at the registration stage (D45 step 4); a `registration_no` accessor exists for phase-18's spelling |
| Teacher ID | `teachers.teacher_code` `uq_te_code` | `institute.teacher_code_prefix` (`TCH-`) | `institute.teacher_code_next_number` | plain | 16 | `nextTeacherCode()`. **Never `teacher_id`** ([D-IN-3]) |
| Course inquiry | `course_inquiries.inquiry_number` `uq_ci_number` | `institute.inquiry_prefix` (`INQ-`) | `institute.inquiry_next_number` | plain | 15 | `nextInquiryNumber()` |
| Application | `student_applications.application_number` `uq_sap_number` | `institute.application_prefix` (`APP-`) | `institute.application_next_number` | plain | 15 | `nextApplicationNumber()`; quoted on the signed thank-you URL |
| Admission | `student_admissions.admission_number` `uq_sadm_number` | `institute.admission_prefix` (`ADM-`) | `institute.admission_next_number` | plain | 15 | `nextAdmissionNumber()` |
| Certificate | `certificates.certificate_number` string(40) `uq_ce_number` | format `institute.certificate_number_format` = `{PREFIX}{YY}{SEQ:5}`; `{PREFIX}` reads `institute.certificate_prefix` (Phase 2 key) | `institute.certificate_next_number` + `_period` + `_sequence_scope` | via format | 21 | `CertificateService::issue()`; issued once (INV-21-1, D52) |
| ID card | `student_id_cards.card_number` `uq_sic_number` | `institute.id_card_prefix` (`SIC-`) | `institute.id_card_next_number` | plain | 21 | `StudentIdCardService::issue()` |
| Ticket | `support_tickets.ticket_number` `uq_tk_number` | `support.ticket_prefix` (`TKT-`) | `support.ticket_next_number` | plain | 22 | `TicketService`; issued once (INV-22-1) |
| Verification code | `certificates.verification_code` char(16) `uq_ce_code`; `student_id_cards.verification_code` char(16) `uq_sic_code` | **not a counter** | — | — | 21 | 16 chars of a 32-symbol alphabet via `random_bytes`, ~80 bits, **independent of the sequential number**, constant-time compared, rate-limited, every attempt logged (INV-21-2) |
| Backup / restore / check run id | `backup_runs.uuid` char(26) `uq_br_uuid` | **ULID**, not a counter | — | — | 24 | the id quoted in notifications and on screen |
| Generation key | `student_fees.generation_key` `uq_sf_generation` | **not a document number** | — | — | spine/10 | composed by the generator (`structure:{admission}:{head}` / `monthly:{admission}:{YYYY-MM}`); the INSERT **is** the duplicate check (F-3.15) |

### 6.1 Collisions and near-collisions

| # | Item | Verdict |
|---|---|---|
| 1 | `finance.payment_reversal_prefix` / `_next_number` is shared by **two tables** — the spine's `payment_reversals` and phase-13's `finance_reversals` | **Deliberate** (phase-13 R-8): one reversal-voucher sequence an auditor can follow. Each table keeps its own UNIQUE index, so no number exists twice; each table's own series therefore has gaps. Do not "fix" this by splitting the counter |
| 2 | Prefix strings `PR-` (payroll run), `PP-` (project payment), `PO-` (payout) | Distinct strings, distinct counters, distinct tables. Watch it when an admin edits prefixes: nothing stops a human setting two prefixes to the same value, and no contract declares a cross-prefix uniqueness check |
| 3 | `student_fees.fee_number` (`FS-`) vs `student_fee_payments.receipt_no` | Two different documents, two counters; `institute.fee_receipt_prefix`'s default is not stated anywhere (§8 item 2) |
| 4 | `students.student_code` vs `certificates.certificate_number` | Both expand `{PREFIX}{YY}{SEQ:5}` with independent counters and their own `_period` / `_sequence_scope` keys. Identical strings would require an admin to set both prefixes the same; they are in different tables and different unique indexes, so it is a legibility risk, not a data risk |
| 5 | `students.registration_number` is `registration_number`, phase-18 §13.1 asked for `registration_no` | Resolved: the column is `registration_number` and a `registration_no` **accessor** exists so both spellings compile |
| 6 | Human codes named `<table>_id` | **Banned** ([D-IN-3]): `students.student_code`, `teachers.teacher_code`. The UI label stays "Student ID" |

---

## 7. Conventions checklist for any new migration

Run every line. A "no" is a review failure, not a discussion.

**Shape**

1. InnoDB, utf8mb4. `Schema::defaultStringLength()` is **not** set; if a unique index fails, shorten that one column.
2. Table name `snake_case` plural; a pivot is `singular_singular` in alphabetical order (`project_user`).
3. Model `StudioCase` singular in `app/Models/<Domain>/`; core models stay at `app/Models/` root.
4. `timestamps` on every table. `created_by` / `updated_by` nullable FK `users.id` `nullOnDelete`, filled by `Blameable`.
5. `deleted_at` **unless** the table falls in one of D19's append-only categories (money, audit, log, snapshot/revision, view counter, numbering, history pivot). State the category in the migration comment and in the contract. **Never add `deleted_at` back** to such a table (`CLAUDE.md` §3 block A, D16, D19).
6. A table without `deleted_at` gets a model `deleting` hook, and a `BEFORE DELETE` → `SIGNAL SQLSTATE '45000'` trigger where its contract says so (D17).

**Types**

7. Money: `decimal(15,2)` default `0.00`. Arithmetic **only** through `App\Support\Money` (bcmath, intermediate scale 6, half-up at 2, strings in and out, never a float). No `+ - * /` on money in PHP.
8. Every `*_rate` / `*_percentage` column: `decimal(8,4)`. **No "reported percentage" exception** (F-7.1, `CLAUDE.md` §3 block C).
9. Marks are `decimal(8,2)` and are **not** percentages; the ceiling is a per-row CHECK against a snapshotted `total_marks` (F-7.2, D51). Ratings are `unsignedTinyInteger` 1–5. `progress` / `rating` tinyints are the only FIN-18 allowlist entries besides `*_marks`.
10. Status columns: `string(32)` with an enum cast. No status-as-free-string, no status-as-int.
11. Signed magnitudes: store a **positive** magnitude with `CHECK > 0`, carry direction in `entry_type` / the component's side, and aggregate only the **STORED generated** `signed_*` column ([D-FS-5], [D-HR-4]).
12. Generated columns and CHECKs are raw SQL in the migration and **fail loudly** if the server rejects them — no try/catch, no silent fallback.

**Keys**

13. FK column is `<singular>_id`, indexed, with an explicit constraint and an explicit on-delete: `restrictOnDelete` on every money edge, `nullOnDelete` only where the contract says so.
14. **Every FK column has an index**, listed in the owning contract's `Keys` block **and** in `tests/Support/index-manifest.php` (F-9.2).
15. Every column a list screen filters or sorts on has an index (F-9.3).
16. "At most one live row per parent" is a **STORED generated guard column** (`1` while live, `NULL` otherwise) plus a UNIQUE index including it — MariaDB unique indexes ignore NULLs ([D-IN-4], [D-HR-5], [D-19-0]). Never a partial index, never a service-only check.
17. Idempotency is an **INSERT guard**, never a SELECT under a row lock: a UNIQUE index on the key, and 1062 means "already done" (F-3.7, F-3.15, R7).
18. `branch_id` is nullable on institute root entities and on the two high-volume dated tables; everywhere else the branch is reached through the parent ([D-IN-5], D11). Scope every query `where branch_id IS NULL OR branch_id = :user_branch`.
19. A deferred FK (target owned by a later phase) ships as `unsignedBigInteger` nullable + index + **no constraint**, beside a denormalised snapshot the UI renders; the owning phase adds the constraint in a `Schema::hasTable()`-guarded migration that **fails loudly** if the target is missing.

**Behaviour and safety**

20. Migrations are additive and reversible; `down()` is the exact inverse (triggers → guard indexes → generated columns → FKs → tables). No `dropColumn` on live data, no `migrate:fresh` outside local dev.
21. A human-readable number comes from `DocumentNumberService` inside the transaction that creates the document, with the caller's own pad, and is never reused (D27, §6). Do not write a second counter.
22. A cache column (`paid_amount`, `current_students`, `progress_percent`, `replies_count`, wallet balances) is **recomputed from one canonical SQL**, never `increment()`ed (D40, D48, D34). A test asserts it is re-derivable.
23. A snapshot column (`*.collaborator_id`, `*.referral_code`, a `_name` copy, a rule snapshot) is display-only: **no scope and no engine may read it** (R5, D37).
24. Assignment of work → `users.id`; an organisational duty → `employees.id`; the bridge is `employees.user_id` nullable UNIQUE (**D32**).
25. One SEO store: `morphOne(SeoMeta::class, 'seoable')` + `SeoService::save()`. **No table gains an SEO column** (D23).
26. Anything rendered on the public website is a `media_assets` row referenced by a nullable indexed `*_media_id` FK or a `*_media` pivot; a bare `*_path` survives only for a **private** profile photo or document (D24).
27. No private artefact on the `public` disk. Uploads land on a private disk and are streamed by a controller that re-runs the full permission chain, with a row in `upload-manifest.php` (**D21**, F-12.5).
28. Client visibility is `attachments.visibility`; never a new `is_client_visible` boolean (F-2.6, F-2.8).
29. Rich text is sanitised by `App\Support\RichText::sanitize()` on write **and** on render. No second sanitiser (D25).
30. Permission name `{module_slug}.{ability}` from `PermissionRegistry`; route name `panel.resource.action`; no new `Ability` case (publishing is `change_status`). Seeders idempotent, never destructive.
31. Claim no new decision number: cite one from `resolutions.md` §4 (D1–D60). `DEVELOPMENT_LOG.md` §4 is the only registry.
32. Definition of done: forward + rollback tested, model/casts/traits, Form Request, service, permission, isolation test per non-admin role, and — for money — duplicate, partial and reversal tests. `audit:manifest --check` passes (**D60**).

---

## 8. Open points

Items a contract leaves undefined, or where the applied contracts and `resolutions.md` still disagree.
Each needs an owner decision; none is invented here.

| # | Item | Evidence | Why it matters |
|---|---|---|---|
| 1 | **`website` settings group key count disagrees.** resolutions §2.4 / F-6.3 say "phase-03's 13 keys **and** phase-04's **22** keys merged (**35** keys)". The applied contracts say **21** keys and **34** (phase-03 §5, phase-04 §5 twice). | resolutions §2.4 vs phase-03 §5 / phase-04 §5 | One of the two numbers is wrong; a seeder test that asserts a count will fail against the other |
| 2 | **Six Phase 2 settings keys have no stated default**, yet later phases build numbering on them: `institute.student_id_prefix`, `institute.registration_number_format`, `institute.fee_receipt_prefix`, `institute.certificate_prefix`, `finance.invoice_prefix`, `finance.invoice_next_number`. | phase-02 §2 lists names only; spine §2.5, phase-13 §2.4, phase-14-17 §5, phase-19-23 §5 consume them | `SettingsRegistry` needs a literal default for each, and `{PREFIX}` token expansion breaks on a null |
| 3 | **Invoice public-link defaults disagree with H6.** resolutions §6.1 H6 says `finance.invoice_public_link_enabled` default **false** and `finance.invoice_public_link_ttl_days` default **14**. phase-13 §5 ships `invoice_public_link_enabled` default **true** and the key `finance.invoice_public_link_days` default **30**. | resolutions §6.1 H6 / §6.2 F-13.9 vs phase-13 §5 | A guest-reachable invoice route on by default is a privacy decision, and the key **name** differs too |
| 4 | **Settings group count is quoted as 13 but phase-02 §2 lists 12** (`company`, `branding`, `localization`, `contact`, `social`, `seo`, `mail`, `collaborator`, `institute`, `finance`, `security`, `maintenance`). | phase-02 §2 vs phase-03 §5 and phase-06 §5 ("none of its 13 groups") | Later groups are `website` (3), `crm` (5), `projects` (6, sort 86), `hr` (7), `support` + `reports` (19-23), `backup` + `ops` (24-25) — a group inventory cannot be closed until the base count is fixed |
| 5 | **No single file owns the settings-group / settings-key registry.** Each domain file lists only the keys that change its own behaviour. | this index §1.3 item 5 | The ~20 groups and their keys are spread over ten contracts; a `settings-registry.md` counterpart to this index would close it |
| 6 | **`notification_preferences` has no confirmed column casting `NotificationGroup`**, and `ReportGroup`, `SearchEntityType`, `AuditSensitivity`, `FinanceReportType`, `AgingBucket`, `PreviewScope`, `ReferralAttributionModel`, `SocialPlatform` cast no column in any contract section read here. | phase-19-23 §2.25, §3.4-3.5; phase-13 §3; phase-03 §3; phase-08-09 §3.2; phase-04 §3 | They are registry/allowlist enums. Fine, but a reviewer should not add a column for them "to match the others" |
| 7 | **Four enums share the case set `draft` / `published` / `archived`** under four names: `CourseStatus` (14), `MaterialStatus` (19), `ContentStatus` (3, plus `scheduled`), `AssignmentStatus` (19, plus `closed`). No finding covers this and no contract says they are deliberately separate. | phase-14-17 §3, phase-19-23 §3.1, phase-03 §3 | **Observed, not a contract contradiction**: the lifecycles and `label()`/`color()` surfaces differ. Recorded so nobody "consolidates" them the way F-5.1 consolidated `PostStatus`, and so nobody assumes consolidation was intended |
| 8 | **No cross-prefix uniqueness check exists** for the 22 admin-editable numbering prefixes. | §6.1 item 2 | An admin can set two prefixes to the same string. Each column keeps its own UNIQUE index so no data is lost, but two document types would print identical-looking numbers |
| 9 | **The five domain filenames in §1.2 are this index's convention**, derived from the contracts rather than read from the files (they were written in parallel with this one). | §1.2 | If a domain file landed under another name, fix the link here. The **boundaries** in §1.2 are binding either way |
| 10 | **`EmploymentType`, `PaymentMethod`, `LedgerEntryType` and `CommissionCalculationType` are owned by a later phase than the one whose migration ships the class file.** | F-5.2, F-5.4, F-5.5; phase-04 §3, phase-07 §3, phase-06 §3, spine §3 | The shipping phase must copy the owner's case list verbatim and may not extend it. No contract states what happens if the owner later adds a case — presumably an additive enum change plus the owner's tests |
| 11 | **Seven owner decisions are still open** (H1 REST API, H2 §29 collaborator payments direction, H3 ticket SLA, H4 commission approval mode, H5 referral-visit retention, H6 invoice public link, H7 CV visibility). Each has an implemented default that keeps both options open. | resolutions §6.1 | None blocks a migration; H6 is already drifting (item 3) |
| 12 | **The spine's own soft-delete bolding is internally inconsistent.** [D-FS-3] says "the **nine** tables whose soft-delete column is marked **no** in bold", but §2.1's table bolds eight (`student_fee_discounts` is plain `no`, `collaborator_referrals` is bold) while `CLAUDE.md` §3 block A's D16 list names `student_fee_discounts` and `collaborator_payout_allocations` among the nine financial tables. | spine §2.1 / [D-FS-3] vs `CLAUDE.md` §3 block A | The **substantive** facts are unambiguous and binding: **12 of the 15 carry no `deleted_at`**, and the three that keep it are `student_fees`, `student_fee_installments`, `collaborator_payout_accounts`. Only the "which nine are D16" wording needs one pass so a reader cannot derive the wrong nine |

---

*This index is derived from the contract documents after the convergence apply stage
(`docs/design/resolutions.md` §3 and §7). It defines no table, column, enum case, module slug or number
format of its own: everything above cites the contract that owns it, and everything a contract leaves
open is in §8 rather than filled in.*
