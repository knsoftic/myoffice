# PHASE 5 CONTRACT - CRM, clients, client panel

**Status: binding.** Column names, class names, enum cases, permission strings, route names and file paths
below are fixed - do not invent alternatives. Requirement source: [`../requirements.md`](../requirements.md)
sections **18** (leads), **19** (clients + client panel), **17** (inquiry hand-off), **112** (data
isolation), **94-97** (messaging, meetings, files, notifications as consumed by the client panel).
Conventions: [`../../CLAUDE.md`](../../CLAUDE.md). Established contracts that win over this document:
[`phase-01.md`](phase-01.md), [`phase-02.md`](phase-02.md) and
[`../design/finance-commission-spine.md`](../design/finance-commission-spine.md) (the financial spine,
Phase 10). Conflicts are recorded in §12, never silently redesigned.

Decisions are labelled **[D-P5-n]** so a code review can cite them.

---

## 1. Goal and dependencies

### 1.1 Goal

After this phase the business can run its sales pipeline and its client master end to end: capture a lead
from the website, a phone call or a CSV file; see it as a duplicate-checked record with an owner, a
scheduled follow-up that reminds the owner, and an append-only activity timeline; move it across a seven
column Kanban board with live per-column counts and value sums that the server validates; convert a won
lead into a client (and hand it off to a project) with an immutable conversion audit trail that carries
the collaborator attribution forward to where money will actually be made; maintain the client record with
tax details, a generated human-readable client code, named contacts and private documents; and give that
client a login to a panel where every screen - projects, tasks, milestones, progress, files, invoices,
payments, meetings, tickets, messages, notifications - is scoped so Client A can never see a single row of
Client B (§112).

### 1.2 Dependencies

| Phase | What this phase needs from it |
|---|---|
| 1 | `users`, `branches`, RBAC + `PermissionRegistry` + `Gate::before` module gating, the already-registered module slugs `leads` and `clients`, `client_portal.*` permission prefix, `PanelType::Client` + `homeRoute()`, `activity_log` with old/new values + `LogsActivityWithContext`, `Blameable`, `App\Support\Money`, `layouts/admin`, `layouts/panel`, the `x-ui.*` set, middleware aliases `auth` `active` `module` `panel` |
| 2 | `SettingsRegistry` + `SettingsService` (this phase adds the `crm` group), `DashboardRegistry` + the `DashboardWidget` contract, `DateRange`, `Format` (`money()`, `app_date()`, `app_datetime()`), `users.preferences` (table column choices on the CRM lists) |
| 4 | `contact_inquiries` (**Phase 4 owns the table, the `ContactInquirySubmitted` event and the `InquiryRouter`** - F-2.1), reached only by registering `CrmLeadInquiryTarget` into that router (§6.10, §17); and `services` for `leads.service_id` (§18 "interested service") |

### 1.3 Phases this contract is built *before*, and how that is handled

Phase 5 ships before **6** (projects, tasks, milestones), **8-9** (collaborators, referral codes), **10**
(the financial spine: `collaborator_referrals`, `project_payments`), **13** (invoices) and **22** (tickets,
meetings, messages, files, notifications). Three mechanisms keep it honest instead of fictional:

**[D-P5-1] Capability contracts and a section registry.** Nothing in this phase queries a table another
phase owns. Later-phase data reaches the client panel through `App\Support\ClientPortalRegistry`, which
holds classes implementing `App\Contracts\Portal\ClientPortalSection` - the same pluggable pattern Phase 2
set with `DashboardRegistry`. A section declares `key()`, `label()`, `icon()`, `module()`, `permission()`,
`sort()`, `badgeCount(Client): ?int`, `paginate(Client, array $filters): LengthAwarePaginator`,
`view(): string`. Phase 5 registers the three sections it owns; every other section is registered by its
owning phase (§13). A section that is not registered: its nav item never renders **and** its route
`abort(404)`s. A panel screen never renders an invented number and never 500s. The two write-side
dependencies use the same idea: `App\Contracts\Referrals\ReferralRecorder` (bound to
`NullReferralRecorder` here, rebound by Phase 9/10) and `App\Contracts\Projects\ProjectCreator` (bound to
`NullProjectCreator` here, rebound by Phase 6). Both expose `isAvailable(): bool`; a UI control whose
capability is unavailable is not rendered, and its route 404s.

**[D-P5-2] Deferred, guarded foreign keys.** Columns pointing at later-phase tables are created nullable in
the create migration; the FK itself is added by
`database/migrations/*_add_crm_deferred_foreign_keys.php`, every statement wrapped in
`Schema::hasTable()` / `Schema::hasColumn()`, so `migrate:fresh` works in either order and a re-run after
Phase 6/10 lands completes the graph. Same discipline as the spine's **[D-FS-1]**. Affected:
`leads.service_id`, `leads.contact_inquiry_id` (Phase 4), `leads.referral_visit_id` (Phase 9),
`clients.lead_id`, `lead_conversions.project_id`, `lead_conversions.collaborator_referral_id`.

**[D-P5-3] Document numbering ships here (D27).** `leads.lead_no` and `clients.client_code` need a
concurrency-safe counter, which the spine specifies in its §5 as
`App\Services\Finance\DocumentNumberService::next(string $prefixKey, string $counterKey, string $pad = '%06d'): string`.
**Phase 5 is the earliest consumer, so Phase 5 owns the class** (F-4.1, **D27**) and creates it at that
exact path with that exact signature and behaviour (`SELECT ... FOR UPDATE` on the counter's `settings` row
inside the caller's transaction, increment, return `prefix . sprintf(pad, value)`, the column's UNIQUE index
as backstop, exactly one retry on a 1062), plus `reserve()` for period-reset counters (§6.10, F-4.12).
`'%06d'` is only the **default**: **every caller passes its own pad explicitly** - Phase 5 `'%06d'`,
Phase 6 `'%05d'`, Phase 7 `'%05d'`, Phase 8-9 `'%04d'`. Phases 6, 7, 8-9, 10 and 14-17 reuse it unchanged
and **must not re-create it** (§13).

### 1.4 Tables this phase owns

`leads`, `lead_activities`, `lead_follow_ups`, `lead_conversions`, `lead_imports`, `lead_import_rows`,
`clients`, `client_contacts`, `client_documents`. **Nine tables, no pivot tables.** `projects.client_id`
and `project_user` belong to Phase 6; `collaborator_referrals` belongs to Phase 10.

---

## 2. Schema

All tables InnoDB / utf8mb4. Unless a row says otherwise every table carries `created_at`, `updated_at`,
`deleted_at` (soft deletes), `created_by`, `updated_by` (nullable FK `users.id`, `nullOnDelete`, filled by
`Blameable`) - written below as **timestamps / softDeletes / blameable**.

Migration order: `clients` -> `client_contacts` -> `client_documents` -> `leads` -> `lead_activities` ->
`lead_follow_ups` -> `lead_conversions` -> `lead_imports` -> `lead_import_rows` ->
`add_crm_deferred_foreign_keys`.

### 2.1 `leads`

§18 verbatim plus the operational columns the Kanban, duplicate detection, conversion and referral capture
need.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `lead_no` | string(32) | not null | UNIQUE. `crm.lead_number_prefix` + padded counter via `DocumentNumberService`. **Immutable** - the model `updating` hook throws |
| `name` | string(150) | not null | §18 |
| `company` | string(150) | nullable | §18 |
| `email` | string(150) | nullable | §18 |
| `email_normalized` | string(150) | nullable | index. `ContactNormalizer::email()`, written on save (**[D-P5-5]**) |
| `phone` | string(32) | nullable | §18 |
| `phone_normalized` | string(32) | nullable | index. Significant digits only |
| `whatsapp` | string(32) | nullable | §18 |
| `whatsapp_normalized` | string(32) | nullable | index |
| `country` | string(64) | nullable | §18 |
| `country_code` | char(2) | nullable | ISO-3166-1 alpha-2, used by `ContactNormalizer` to expand a local number |
| `service_id` | FK `services.id` | nullable | `nullOnDelete`, deferred FK (Phase 4). §18 "interested service" |
| `interested_service` | string(150) | nullable | free-text snapshot when no catalogue row matches, and the value shown after a service is renamed |
| `budget_amount` | decimal(15,2) | nullable | §18 "budget". The **only** money column on a lead; the Kanban value sums are `SUM(budget_amount)` |
| `source` | string(32) | not null | cast **`InquirySource`** (declared by phase-04 §3 - F-5.3); the sources of §18 plus `email` |
| `source_detail` | string(255) | nullable | campaign, page or referrer carried over from the website hand-off |
| `status` | string(32) | `new` | cast `LeadStatus` - the seven statuses of §18 |
| `status_changed_at` | timestamp | nullable | drives "time in stage" |
| `assigned_to` | FK `users.id` | nullable | `nullOnDelete`, index. §18 "assigned person" |
| `assigned_at` | timestamp | nullable | |
| `assigned_by` | FK `users.id` | nullable | `nullOnDelete` |
| `follow_up_at` | datetime | nullable | index. §18 "follow-up date" - a **cache** of the single open `lead_follow_ups` row, rewritten in the same transaction (**[D-P5-12]**) |
| `last_contacted_at` | timestamp | nullable | stamped by a completed follow-up or a logged call/email/whatsapp activity |
| `last_activity_at` | timestamp | nullable | index. Any timeline row; drives the "stale" chip and the stale digest |
| `notes` | text | nullable | §18 "notes" - the standing note on the record; dated notes live in the timeline |
| `lost_reason` | string(255) | nullable | mandatory when moving to `lost` |
| `lost_at` | timestamp | nullable | |
| `won_at` | timestamp | nullable | |
| `duplicate_of_lead_id` | FK self | nullable | `nullOnDelete`. Set when a user explicitly links this lead to the original |
| `duplicate_flagged_at` | timestamp | nullable | |
| `duplicate_note` | string(255) | nullable | |
| `client_id` | FK `clients.id` | nullable | `nullOnDelete`, index. Set by conversion |
| `converted_at` | timestamp | nullable | |
| `converted_by` | FK `users.id` | nullable | `nullOnDelete` |
| `referral_code_captured` | string(32) | nullable | index. The `?ref=` code as it arrived (§38), stored **always**, even when no collaborator table exists yet (**[D-P5-6]**) |
| `referral_recorded_at` | timestamp | nullable | stamped only when `ReferralRecorder` actually wrote the `collaborator_referrals` row; makes `crm:record-captured-referrals` idempotent |
| `referral_visit_id` | unsignedBigInteger | nullable | index. The `collaborator_referral_visits` row the `?ref=` click came from - the **evidence** behind `referral_code_captured` (F-3.5). FK -> `collaborator_referral_visits.id` `nullOnDelete`, **deferred to Phase 9's guarded migration**; INV-R6's prune guard never prunes a visit this column points at |
| `contact_inquiry_id` | FK `contact_inquiries.id` | nullable | `nullOnDelete`, deferred FK (**Phase 4**, F-2.1). §17 provenance **and** the DB idempotency key - see the `UNIQUE` below |
| `lead_import_id` | FK `lead_imports.id` | nullable | `nullOnDelete` - added by the deferred migration because `lead_imports` is created after `leads` |
| | timestamps / softDeletes / blameable | | |

**Keys.** `UNIQUE uq_leads_no(lead_no)` (spans soft-deleted rows - a number is never reused).
**`UNIQUE uq_leads_inquiry(contact_inquiry_id)`** - one lead per contact inquiry, **enforced by the INSERT
and not by a SELECT** (F-3.7, R7): `CrmLeadInquiryTarget` and `crm:import-pending-inquiries` both simply
insert and treat a 1062 as "already imported", returning the existing lead. MariaDB unique indexes ignore
NULLs, so manually created leads stack freely.
`INDEX idx_leads_board(status, follow_up_at, id)` - the Kanban column query.
`INDEX idx_leads_mine(assigned_to, status)`, `INDEX (created_by, status)` - the visibility scope of §9.
`INDEX (follow_up_at)`, `INDEX (last_activity_at)`, `INDEX (source, created_at)`,
`INDEX (email_normalized)`, `INDEX (phone_normalized)`, `INDEX (whatsapp_normalized)`,
`INDEX (client_id)`, `INDEX (referral_code_captured)`, `INDEX (referral_visit_id)`,
`INDEX (deleted_at)`.
**CHECK** `chk_leads_budget`: `budget_amount IS NULL OR budget_amount >= 0`.
**CHECK** `chk_leads_not_self_duplicate`: `duplicate_of_lead_id IS NULL OR duplicate_of_lead_id <> id`.
No DB uniqueness on phone/email: duplicates are **detected and warned**, never blocked by a constraint
(**[D-P5-5]**) - a receptionist logging a repeat inquiry from the same number is legitimate.
**Relationships.** belongsTo `Service`, `User` (`assignee` via `assigned_to`, `assigner`, `converter`),
`Client`, `Lead` (`duplicateOf`), `ContactInquiry`, `LeadImport`; hasMany `Lead` (`duplicates`),
`LeadActivity`, `LeadFollowUp`, `LeadConversion`; hasOne `LeadFollowUp` (`openFollowUp`, the `pending`
row), `LeadConversion` (`activeConversion`); morphMany the spine's `CollaboratorReferral`
(`subject_type = 'lead'`, read through `ReferralRecorder`, never queried directly from CRM code).

### 2.2 `lead_activities`

The timeline (§18 notes, plus every audit fact a salesperson needs on one screen).

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `lead_id` | FK `leads.id` | not null | `cascadeOnDelete`, index - a timeline is meaningless without its lead, and force-delete is Super-Admin only |
| `type` | string(32) | not null | cast `LeadActivityType` |
| `is_system` | boolean | false | true for every row the services write; system rows are **never** editable or deletable (policy + model hook) |
| `subject` | string(150) | nullable | one-line headline |
| `body` | text | nullable | the note |
| `outcome` | string(32) | nullable | cast `LeadContactOutcome` - for `call` / `whatsapp` / `email` rows |
| `duration_minutes` | unsignedSmallInteger | nullable | call length |
| `from_status` / `to_status` | string(32) | nullable | cast `LeadStatus` - set on `status_changed` rows (§107 old/new on the record itself, not only in the log) |
| `from_user_id` / `to_user_id` | FK `users.id` | nullable | `nullOnDelete` - set on `assigned` rows |
| `lead_follow_up_id` | FK `lead_follow_ups.id` | nullable | `nullOnDelete`, added by the deferred migration |
| `related_lead_id` | FK `leads.id` | nullable | `nullOnDelete` - the other lead on a `duplicate_linked` row |
| `occurred_at` | datetime | not null | index. Back-datable for a call logged an hour later; defaults to `now()` |
| `meta` | json | nullable | import row number, source file name, bulk-operation id |
| | timestamps / softDeletes / blameable | | |

**Keys.** `INDEX idx_la_feed(lead_id, occurred_at, id)` - the only feed query.
`INDEX (type, occurred_at)`, `INDEX (created_by, occurred_at)` (the "my activity" report),
`INDEX (lead_follow_up_id)`.
**CHECK** `chk_la_status_pair`: `(type <> 'status_changed') OR (to_status IS NOT NULL)`.
**Relationships.** belongsTo `Lead`, `LeadFollowUp`, `User` (`fromUser`, `toUser`, `creator`),
`Lead` (`relatedLead`).

### 2.3 `lead_follow_ups`

§18 "follow-up date" made operational: one open follow-up per lead, a reminder, an outcome, a reschedule
chain.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `lead_id` | FK `leads.id` | not null | `cascadeOnDelete`, index |
| `assigned_to` | FK `users.id` | nullable | `nullOnDelete`, index. Defaults to `leads.assigned_to`; the reminder goes here |
| `type` | string(32) | not null | cast `LeadFollowUpType` |
| `scheduled_at` | datetime | not null | index |
| `remind_before_minutes` | unsignedInteger | `crm.follow_up_reminder_minutes` | 0 = remind at `scheduled_at` |
| `reminder_due_at` | datetime | nullable | index. `scheduled_at - remind_before_minutes`, computed in PHP on save (not a generated column - MariaDB 10.4 column-interval arithmetic is not worth the risk) |
| `reminder_sent_at` | timestamp | nullable | stamped **before** the notification is queued, inside the transaction |
| `status` | string(24) | `pending` | cast `LeadFollowUpStatus` |
| `open_guard` | tinyint | **generated STORED** | `CASE WHEN status = 'pending' THEN 1 ELSE NULL END` - carries the unique index below |
| `completed_at` | timestamp | nullable | |
| `completed_by` | FK `users.id` | nullable | `nullOnDelete` |
| `outcome` | string(32) | nullable | cast `LeadContactOutcome`; mandatory on `complete` |
| `outcome_note` | string(255) | nullable | |
| `previous_follow_up_id` | FK self | nullable | `nullOnDelete` - the reschedule chain |
| `rescheduled_at` | timestamp | nullable | |
| `cancel_reason` | string(255) | nullable | mandatory on `cancel` |
| `notes` | string(255) | nullable | what to say / what to send |
| | timestamps / softDeletes / blameable | | |

**Keys.** `UNIQUE uq_lfu_open(lead_id, open_guard)` - **at most one `pending` follow-up per lead**, so
`leads.follow_up_at` is always truthful (**[D-P5-12]**); because MariaDB unique indexes ignore NULL,
completed / missed / cancelled / rescheduled rows stack freely.
`UNIQUE uq_lfu_previous(previous_follow_up_id)` - a follow-up has at most one successor.
`INDEX idx_lfu_due(status, reminder_due_at, reminder_sent_at)` - the reminder sweep's only query.
`INDEX idx_lfu_worklist(assigned_to, status, scheduled_at)` - "my follow-ups".
`INDEX (lead_id, scheduled_at)`.
**CHECK** `chk_lfu_completed`: `(status <> 'completed') OR (completed_at IS NOT NULL AND outcome IS NOT NULL)`.
The generated column is raw SQL in the migration
(`ALTER TABLE lead_follow_ups ADD COLUMN open_guard TINYINT AS (CASE WHEN status = 'pending' THEN 1 ELSE NULL END) STORED`);
if the server rejects it the migration **fails loudly** and is not silently skipped.
**Relationships.** belongsTo `Lead`, `User` (`assignee`, `completedBy`), self (`previousFollowUp`);
hasOne self (`nextFollowUp`); hasMany `LeadActivity`.

### 2.4 `lead_conversions`

The conversion audit trail (§107). **Append-only**: no `deleted_at` - the category rule of **D19**
(`CLAUDE.md` §3: append-only audit / snapshot tables carry no `deleted_at`) - and the model's `updating`
hook permits only `superseded_at` / `supersede_reason` / `project_id` / `collaborator_referral_id` /
`updated_by` to change. No DB delete trigger (this is not a money table, so D16's trigger discipline
is not imported), but `deleting` throws.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `lead_id` | FK `leads.id` | not null | `restrictOnDelete`, index - a converted lead can never be force-deleted |
| `conversion_type` | string(24) | not null | cast `LeadConversionType` |
| `client_id` | FK `clients.id` | nullable | `restrictOnDelete` |
| `created_client` | boolean | false | false = linked to a client that already existed |
| `matched_by` | string(32) | nullable | cast `LeadDuplicateMatchType` when the client was found by the duplicate report |
| `project_id` | FK `projects.id` | nullable | `nullOnDelete`, deferred FK (Phase 6) |
| `from_status` | string(32) | not null | cast `LeadStatus` - the status the lead held when converted |
| `lead_snapshot` | json | not null | every §18 field at the moment of conversion, so the audit survives later edits to either record |
| `field_map` | json | nullable | which lead field populated which client field |
| `budget_amount` | decimal(15,2) | nullable | snapshot |
| `referral_code` | string(32) | nullable | snapshot of the attribution code carried forward |
| `collaborator_referral_id` | FK `collaborator_referrals.id` | nullable | `nullOnDelete`, deferred FK (Phase 10) - the evidence row the attribution was copied from |
| `converted_at` | timestamp | not null | |
| `converted_by` | FK `users.id` | nullable | `nullOnDelete` |
| `notes` | string(255) | nullable | |
| `superseded_at` | timestamp | nullable | set when a later conversion replaces this one |
| `supersede_reason` | string(255) | nullable | mandatory when superseding |
| `active_guard` | tinyint | **generated STORED** | `CASE WHEN superseded_at IS NULL THEN 1 ELSE NULL END` |
| | timestamps / blameable, **no `deleted_at`** (**D19**) | | |

**Keys.** `UNIQUE uq_lc_lead_active(lead_id, active_guard)` - **at most one live conversion per lead**, so
a double-submitted wizard cannot create two clients. `INDEX (client_id)`, `INDEX (project_id)`,
`INDEX (converted_at)`, `INDEX (converted_by, converted_at)`.
**CHECK** `chk_lc_target`: `client_id IS NOT NULL OR project_id IS NOT NULL`.
**Relationships.** belongsTo `Lead`, `Client`, `Project`, `CollaboratorReferral`, `User` (`convertedBy`).

### 2.5 `lead_imports`

One CSV import batch.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `original_filename` | string(255) | not null | |
| `stored_path` | string(255) | not null | **private** `local` disk under `crm/imports/` - never the public disk |
| `file_hash` | string(64) | not null | sha256, index - warns "this exact file was imported on ..." |
| `delimiter` | string(4) | `,` | sniffed, overridable |
| `encoding` | string(16) | `UTF-8` | |
| `column_map` | json | not null | csv header -> lead field |
| `defaults` | json | nullable | source / assigned_to / status applied to every row |
| `duplicate_strategy` | string(24) | `import_and_flag` | cast `LeadImportDuplicateStrategy` |
| `status` | string(24) | `pending` | cast `LeadImportStatus` |
| `total_rows` | unsignedInteger | 0 | |
| `created_count` / `updated_count` / `skipped_count` / `failed_count` | unsignedInteger | 0 | incremented with atomic `increment()` inside each row's transaction |
| `error_report_path` | string(255) | nullable | generated CSV of failed rows, private disk |
| `started_at` / `finished_at` | timestamp | nullable | |
| `failure_message` | string(500) | nullable | set when the batch itself dies |
| | timestamps / softDeletes / blameable | | |

**Keys.** `INDEX (status, created_at)`, `INDEX (file_hash)`, `INDEX (created_by, created_at)`.
**Relationships.** hasMany `LeadImportRow`, `Lead`; belongsTo `User` (creator).

### 2.6 `lead_import_rows`

Per-row result, so "row 42: invalid email" is reviewable and a retry cannot double-create.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `lead_import_id` | FK `lead_imports.id` | not null | `cascadeOnDelete` |
| `row_number` | unsignedInteger | not null | 1-based, header excluded |
| `raw` | json | not null | the row as read, for the error report and a re-run |
| `status` | string(24) | not null | cast `LeadImportRowStatus` |
| `lead_id` | FK `leads.id` | nullable | `nullOnDelete` - the row this line created or updated |
| `duplicate_lead_id` | FK `leads.id` | nullable | `nullOnDelete` - what it matched |
| `duplicate_match_type` | string(32) | nullable | cast `LeadDuplicateMatchType` |
| `errors` | json | nullable | field => message[] |
| | timestamps only - no soft deletes, no blameable (**D19**: an append-only per-row log, child rows of a blameable batch) | | |

**Keys.** `UNIQUE uq_lir_row(lead_import_id, row_number)` - the idempotency guard: a retried chunk
re-inserting a row hits 1062 and is skipped, so a lost queue ack can never double-create a lead.
`INDEX (lead_import_id, status)`, `INDEX (lead_id)`.
**Relationships.** belongsTo `LeadImport`, `Lead` (`lead`, `duplicateLead`).

### 2.7 `clients`

§19 in full. "Client ID" is the human-readable `client_code`; the surrogate `id` is never shown.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `client_code` | string(32) | not null | UNIQUE. `crm.client_code_prefix` + padded counter, e.g. `CL-000014`. **Immutable**; never reused, even after a soft delete |
| `user_id` | FK `users.id` | nullable | `nullOnDelete`, **UNIQUE** - the primary portal login (D2). One user is one client |
| `client_type` | string(16) | `company` | cast `ClientType` |
| `name` | string(150) | not null | the person |
| `company_name` | string(150) | nullable | §19. `display_name` is an **accessor**, `company_name ?: name` - not a column |
| `email` | string(150) | nullable | §19 |
| `email_normalized` | string(150) | nullable | index |
| `phone` | string(32) | nullable | §19 |
| `phone_normalized` | string(32) | nullable | index |
| `whatsapp` | string(32) | nullable | §19 |
| `whatsapp_normalized` | string(32) | nullable | index |
| `website` | string(255) | nullable | §19 "profile" |
| `industry` | string(96) | nullable | §19 "profile" |
| `about` | text | nullable | §19 "profile" |
| `logo_path` | string(255) | nullable | `public` disk (a logo is not confidential) |
| `address` | string(255) | nullable | §19 |
| `city` | string(96) | nullable | |
| `state` | string(96) | nullable | |
| `postal_code` | string(24) | nullable | |
| `country` | string(64) | nullable | §19 |
| `country_code` | char(2) | nullable | normalisation + invoice locale |
| `billing_same_as_address` | boolean | true | |
| `billing_address` | string(255) | nullable | printed on invoices (§31) |
| `tax_registered` | boolean | false | §19 tax details |
| `tax_number` | string(64) | nullable | NTN |
| `sales_tax_number` | string(64) | nullable | STRN / GST |
| `cnic` | string(24) | nullable | individual clients |
| `tax_exempt` | boolean | false | |
| `tax_rate_override` | decimal(8,4) | nullable | percentage convention; null = `finance.default_tax_rate` |
| `withholding_tax_rate` | decimal(8,4) | nullable | |
| `tax_notes` | string(255) | nullable | |
| `currency` | char(3) | nullable | null = `localization.currency` |
| `payment_terms_days` | unsignedSmallInteger | nullable | null = `finance.payment_terms_days` |
| `status` | string(32) | `active` | index, cast `ClientStatus` |
| `status_reason` | string(255) | nullable | |
| `status_changed_at` | timestamp | nullable | |
| `portal_enabled` | boolean | false | the explicit staff switch; panel access also requires `user_id` and an `Active` user |
| `portal_invited_at` | timestamp | nullable | |
| `account_manager_id` | FK `users.id` | nullable | `nullOnDelete`, index. §94 client <-> project manager |
| `source` | string(32) | nullable | cast **`InquirySource`** (phase-04 §3 - F-5.3) - carried from the converted lead |
| `lead_id` | FK `leads.id` | nullable | `nullOnDelete`, index, deferred FK. The originating lead; **not** unique (repeat business converts more leads onto one client) |
| `referral_code_captured` | string(32) | nullable | index (**[D-P5-6]**) |
| `referral_recorded_at` | timestamp | nullable | |
| `notes` | text | nullable | internal, never exposed to the panel |
| | timestamps / softDeletes / blameable | | |

**Keys.** `UNIQUE uq_clients_code(client_code)`, `UNIQUE uq_clients_user(user_id)`,
`INDEX (status, company_name)`, `INDEX (account_manager_id)`, `INDEX (email_normalized)`,
`INDEX (phone_normalized)`, `INDEX (whatsapp_normalized)`, `INDEX (lead_id)`,
`INDEX (referral_code_captured)`, `INDEX (deleted_at)`.
**CHECK** `chk_clients_tax_rate`: `tax_rate_override IS NULL OR (tax_rate_override >= 0 AND tax_rate_override <= 100)`; same shape for `withholding_tax_rate`.
**Relationships.** belongsTo `User` (`portalUser`, `accountManager`), `Lead` (`originLead`); hasMany
`ClientContact`, `ClientDocument`, `Lead` (leads converted onto this client), `LeadConversion`, and -
owned by later phases - `Project` (6), `Invoice` (13), `ProjectPayment` (10), `SupportTicket` (22),
`Meeting` (22). Those four relations are declared on the model but only ever traversed through the
capability contracts of **[D-P5-1]**.

### 2.8 `client_contacts`

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `client_id` | FK `clients.id` | not null | `cascadeOnDelete`, index |
| `user_id` | FK `users.id` | nullable | `nullOnDelete`, **UNIQUE** - an additional portal login for this client |
| `name` | string(150) | not null | |
| `designation` | string(96) | nullable | |
| `department` | string(96) | nullable | |
| `email` | string(150) | nullable | + `email_normalized` string(150) nullable, index |
| `phone` | string(32) | nullable | + `phone_normalized` string(32) nullable, index |
| `whatsapp` | string(32) | nullable | |
| `is_primary` | boolean | false | |
| `primary_guard` | tinyint | **generated STORED** | `CASE WHEN is_primary = 1 THEN 1 ELSE NULL END` |
| `is_billing_contact` | boolean | false | invoice recipient (Phase 13) |
| `portal_access` | boolean | false | may reach the client panel as this client |
| `receives_notifications` | boolean | true | §97 routing |
| `notes` | string(255) | nullable | |
| | timestamps / softDeletes / blameable | | |

**Keys.** `UNIQUE uq_cc_primary(client_id, primary_guard)` - at most one primary contact.
`UNIQUE uq_cc_user(user_id)`. `INDEX (client_id, is_primary)`, `INDEX (email_normalized)`,
`INDEX (phone_normalized)`, `INDEX (portal_access)`.
**Relationships.** belongsTo `Client`, `User`.

### 2.9 `client_documents`

§19 "client documents", under §96's permission discipline and §111's upload rules.
**[D-P5-10] Private by default.** Files live on the **`local` (private)** disk under
`clients/{client_id}/documents/`; they are reachable only through a policy-checked streaming route. A
client sees a document only when `visible_to_client = true`.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `client_id` | FK `clients.id` | not null | `cascadeOnDelete`, index |
| `title` | string(150) | not null | |
| `category` | string(32) | not null | cast `ClientDocumentCategory` |
| `description` | string(255) | nullable | |
| `disk` | string(32) | `local` | stored so a future move to S3 does not invalidate old rows |
| `path` | string(255) | not null | hashed filename, never the user's name |
| `original_name` | string(255) | not null | used for the download filename |
| `mime_type` | string(128) | not null | sniffed from content with `finfo`, not from the request |
| `extension` | string(16) | not null | |
| `size_bytes` | unsignedBigInteger | not null | |
| `checksum` | string(64) | nullable | sha256, index - warns on re-upload of the same file |
| `visible_to_client` | boolean | `crm.client_visible_documents_default` | **the only gate that exposes a file to the panel** |
| `shared_at` | timestamp | nullable | when it was first made visible |
| `shared_by` | FK `users.id` | nullable | `nullOnDelete` |
| `valid_from` | date | nullable | |
| `expires_at` | date | nullable | drives a badge and a filter only - no job, no notification |
| | timestamps / softDeletes / blameable | | |

**Keys.** `INDEX (client_id, category)`, `INDEX (client_id, visible_to_client)`, `INDEX (checksum)`,
`INDEX (expires_at)`.
**CHECK** `chk_cd_size`: `size_bytes > 0`.
**Relationships.** belongsTo `Client`, `User` (`sharedBy`, `creator`).

### 2.10 Relationship summary

```
Client 1--n ClientContact          Client 1--n ClientDocument
Client 1--n Lead (converted onto)  Client 1--1 User (portal login, nullable, unique)
Lead   1--n LeadActivity           Lead   1--n LeadFollowUp (1 pending, DB-enforced)
Lead   1--n LeadConversion (1 live, DB-enforced)  LeadConversion n--1 Client / Project
LeadImport 1--n LeadImportRow      LeadImport 1--n Lead
Lead / Client  -> CollaboratorReferral (Phase 10, subject_type 'lead' | 'client')
```

No `belongsToMany` and no pivot table is introduced by this phase.

### 2.11 `LeadStatus` transition table - the server's answer to a Kanban drag

`LeadService::changeStatus()` accepts a move only if the pair appears below. Anything else is a **422**
with the allowed list, which the optimistic UI uses to spring the card back.

| From | Allowed to | Extra requirement |
|---|---|---|
| `new` | `contacted`, `interested`, `lost` | - |
| `contacted` | `interested`, `negotiation`, `proposal_sent`, `lost` | - |
| `interested` | `contacted`, `negotiation`, `proposal_sent`, `lost` | - |
| `negotiation` | `interested`, `proposal_sent`, `won`, `lost` | - |
| `proposal_sent` | `negotiation`, `won`, `lost` | - |
| `won` | `negotiation` (reopen) | refused when a live `lead_conversions` row exists; reason mandatory |
| `lost` | `new`, `contacted` (reopen) | reason mandatory |

Cross-cutting rules, all enforced in the service and tested: moving **to** `lost` requires
`lost_reason`; moving to `won` stamps `won_at`; reopening clears `lost_at` / `won_at`; every move writes
one `status_changed` activity row with `from_status` / `to_status` plus an `activity_log` entry with old
and new values; `crm.require_follow_up_on_contacted` (default true) refuses a move to
`contacted` / `interested` / `negotiation` / `proposal_sent` unless the request also carries a follow-up
payload or the lead already has an open follow-up.

---

## 3. Enums to add

All in `app/Enums/`, string-backed, with `label(): string`, `color(): string` and
`static options(): array`, exactly as Phase 1 §2 requires.

| Enum | Cases (values) | Extra members |
|---|---|---|
| `LeadStatus` | `new`, `contacted`, `interested`, `negotiation`, `proposal_sent`, `won`, `lost` | `sortOrder(): int` (board column order), `isOpen(): bool`, `isTerminal(): bool`, `requiresReason(): bool` (`lost`), `canConvert(): bool` (`won` only), `allowedTransitions(): array<self>` (§2.11) |
| `LeadActivityType` | `note`, `call`, `whatsapp`, `email`, `meeting`, `status_changed`, `assigned`, `follow_up_scheduled`, `follow_up_completed`, `follow_up_missed`, `converted`, `imported`, `duplicate_linked`, `system` | `isSystem(): bool` (everything except `note` `call` `whatsapp` `email` `meeting`), `icon(): string` |
| `LeadContactOutcome` | `connected`, `no_answer`, `busy`, `wrong_number`, `call_back_later`, `not_interested`, `left_message` | `countsAsContact(): bool` (false for `wrong_number`) |
| `LeadFollowUpType` | `call`, `whatsapp`, `email`, `meeting`, `visit`, `other` | - |
| `LeadFollowUpStatus` | `pending`, `completed`, `missed`, `rescheduled`, `cancelled` | `isOpen(): bool` (only `pending` fills `open_guard`) |
| `LeadConversionType` | `client`, `project`, `client_and_project` | `createsClient(): bool`, `createsProject(): bool` |
| `LeadDuplicateMatchType` | `phone`, `whatsapp`, `email`, `phone_vs_whatsapp`, `client_phone`, `client_email`, `client_contact_phone`, `client_contact_email` | `isExact(): bool`, `againstClient(): bool` |
| `LeadImportStatus` | `pending`, `mapping`, `validating`, `validated`, `processing`, `completed`, `completed_with_errors`, `failed`, `cancelled` | `isRunnable(): bool`, `isFinished(): bool` |
| `LeadImportRowStatus` | `pending`, `created`, `updated`, `skipped_duplicate`, `skipped_invalid`, `failed` | `isSuccess(): bool` |
| `LeadImportDuplicateStrategy` | `skip`, `import_and_flag`, `update_existing` | - |
| `ClientType` | `individual`, `company` | `requiresCnic(): bool` |
| `ClientStatus` | `active`, `inactive`, `suspended`, `closed` | `canUsePortal(): bool` (true only for `active`) |
| `ClientDocumentCategory` | `contract`, `nda`, `proposal`, `quotation`, `purchase_order`, `tax_certificate`, `identity`, `registration`, `invoice_copy`, `other` | `defaultVisibleToClient(): bool` (true only for `contract`, `proposal`, `quotation`, `invoice_copy`) |

**No `LeadSource` enum exists** (F-5.3, R3). `leads.source` and `clients.source` cast to
**`App\Enums\InquirySource`**, declared **once** by phase-04 §3 with the eleven cases `website`,
`facebook`, `instagram`, `tiktok`, `google`, `whatsapp`, `referral`, `walk_in`, `call`, `email`, `other`.
Phase 5 declares no source enum, adds no case, and every former `LeadSource::` reference in this contract
(§2.1, §2.7, §5, §8.11, test 85) reads `InquirySource::`. The values are already identical strings, so no
data migration exists.

`ReferralSource` and `ReferralStatus` are the spine's (§3) and are **not** redefined here; CRM only passes
values through `ReferralRecorder`.

---

## 4. PermissionRegistry additions

Presets are Phase 1's: `READ`, `CRUD`, `CRUD_FULL`, `APPROVE`, `STATUS`, `ASSIGN`, `FILES`, `MONEY`,
`REPORTS`, `LOGS`.

### 4.1 New module slug

| slug | ModuleGroup | icon | is_core | sort | Abilities | Why these |
|---|---|---|---|---|---|---|
| `client_documents` | `SoftwareHouse` | `paper-clip` | false | after `clients` | `READ` + `create` + `edit` + `delete` + `FILES` + `STATUS` + `LOGS` | a contract or an NDA needs its own upload/download grant, separate from editing the client record; `change_status` is the ability that means "share with / unshare from the client portal" (the spine's precedent for reusing `change_status` rather than inventing an `Ability` case) |

No other module is added. `lead_follow_ups`, `lead_activities`, `lead_conversions` and `lead_imports` are
parts of the `leads` module - a separate permission surface would buy nothing. Conversion needs **no new
ability**: it requires `leads.edit` **and** `clients.create`, and the project hand-off additionally
requires `projects.create`.

### 4.2 Abilities added to the Phase 1 slugs

| slug | Abilities after this phase |
|---|---|
| `leads` | `CRUD_FULL` + `restore` + `ASSIGN` + `STATUS` + `import` + `LOGS` + `view_reports` - no `approve` (a lead is not approved) |
| `clients` | `CRUD_FULL` + `restore` + `ASSIGN` + `STATUS` + `MONEY` + `LOGS` + `view_reports` - `view_financial` gates the invoiced / paid / outstanding block and the outstanding column |

`leads.view_any` and `leads.view` are given distinct meanings by **[D-P5-8]** (§9): `view_any` = the whole
pipeline, `view` = only leads the user owns. This uses Phase 1's existing abilities as intended and adds
no setting and no new ability.

### 4.3 Portal permissions (`client_portal.*`)

Phase 1 registers the prefix with "dashboard, profile, plus the read abilities each panel needs"; the
spine §4.3 already adds `client_portal.payments`. The complete set after this phase - each registered
**once**, in `PermissionRegistry`:

| Permission | Gates |
|---|---|
| `client_portal.dashboard` | Phase 1 - the panel home |
| `client_portal.profile` | Phase 1 - view and edit own company profile |
| `client_portal.projects` | project list + detail + progress |
| `client_portal.tasks` | client-visible tasks |
| `client_portal.milestones` | milestones |
| `client_portal.files` | project / task files marked client-visible |
| `client_portal.documents` | `client_documents` where `visible_to_client` |
| `client_portal.download` | the act of streaming any file or document |
| `client_portal.invoices` | invoices + invoice PDF |
| `client_portal.payments` | **declared by the spine §4.3** - receipts against own projects |
| `client_portal.meetings` | meetings the client participates in |
| `client_portal.tickets` | own tickets (read; create arrives with Phase 22) |
| `client_portal.messages` | own conversations (read; send arrives with Phase 22) |
| `client_portal.notifications` | own notifications + mark read |

Phase 1's `Client` role (panel `client`, level 60) receives all of them. `client_portal.download` is
listed separately so a client can be allowed to *see* that a contract exists while downloads are withheld.

---

## 5. SettingsRegistry additions

**[D-P5-4]** One new group is added to Phase 2's `SettingsRegistry` - `crm`, label "CRM & Clients", icon
`user-group`, description "Lead numbering, assignment, duplicate detection, follow-up reminders, import
limits and client portal", sort after `finance`, permission `settings.edit`. Phase 2's registry is built
to take new groups (`groups()` / `fields($group)`), so this is additive, not a redesign. Upload limits and
file types are **not** redefined: client documents reuse `security.max_upload_mb` and
`security.allowed_file_types`.

| group.key | type | default | Meaning |
|---|---|---|---|
| `crm.lead_number_prefix` | text | `LD-` | |
| `crm.lead_number_next_number` | number | `1` | counter, locked in-transaction |
| `crm.client_code_prefix` | text | `CL-` | §19 "Client ID" |
| `crm.client_code_next_number` | number | `1` | |
| `crm.number_padding` | number | `6` | builds the `'%06d'` pad for both counters |
| `crm.default_lead_source` | select(`InquirySource`) | `website` | pre-selected on the create form; validated against `InquirySource` (F-5.3) |
| `crm.auto_assign_mode` | select `off`\|`round_robin`\|`least_open`\|`fixed_user` | `off` | applied only when the caller supplies no assignee |
| `crm.auto_assign_user_id` | number | null | used by `fixed_user` |
| `crm.auto_assign_roles` | multiselect(roles on the `admin` panel) | `["Sales Executive"]` | the pool for `round_robin` / `least_open`; only users with `leads.view` and status `Active` are eligible |
| `crm.lead_statuses_on_board` | multiselect(`LeadStatus`) | all seven | lets a team hide `won` / `lost` columns |
| `crm.kanban_page_size` | number | `25` | cards fetched per column page |
| `crm.duplicate_detection_enabled` | boolean | `true` | |
| `crm.duplicate_match_fields` | multiselect `phone`\|`whatsapp`\|`email` | all three | |
| `crm.duplicate_cross_field` | boolean | `true` | a phone that matches another lead's WhatsApp counts |
| `crm.duplicate_check_clients` | boolean | `true` | also match `clients` and `client_contacts` |
| `crm.duplicate_lookback_days` | number | `0` | `0` = all time |
| `crm.duplicate_block_on_exact` | boolean | `false` | `true` = refuse the save instead of warning |
| `crm.follow_up_default_offset_hours` | number | `24` | pre-fills the scheduler |
| `crm.follow_up_reminder_minutes` | number | `60` | default `remind_before_minutes` |
| `crm.follow_up_reminder_channels` | multiselect `database`\|`mail` | `["database"]` | §97 - mail-ready |
| `crm.follow_up_overdue_grace_minutes` | number | `120` | `pending` -> `missed` after this |
| `crm.require_follow_up_on_contacted` | boolean | `true` | §2.11 cross-cutting rule |
| `crm.stale_lead_days` | number | `7` | the board's "stale" chip |
| `crm.stale_digest_enabled` | boolean | `false` | optional daily digest to each assignee; off by default because §18 does not ask for it |
| `crm.lost_reasons` | json | `["Budget","Timeline","Chose a competitor","No response","Not a fit","Duplicate","Other"]` | the select on the lost modal; free text still allowed |
| `crm.bulk_max_ids` | number | `200` | hard cap on one bulk assign / status call |
| `crm.export_max_rows` | number | `20000` | above this the export is queued and delivered by notification |
| `crm.import_max_rows` | number | `5000` | |
| `crm.import_file_retention_days` | number | `30` | staged CSVs pruned after this |
| `crm.import_row_retention_days` | number | `90` | `lead_import_rows` pruned after this |
| `crm.activity_edit_window_minutes` | number | `1440` | how long an author may edit their own note |
| `crm.client_portal_enabled` | boolean | `true` | master switch; off returns 403 on every `/client` route |
| `crm.client_visible_documents_default` | boolean | `false` | default for `client_documents.visible_to_client` |
| `crm.whatsapp_link_template` | text | `https://wa.me/{phone}` | the WhatsApp button on every lead / client / contact - no hardcoded URL anywhere |

---

## 6. Services

Namespace `App\Services\Crm\` unless stated. Every public method that writes runs in one
`DB::transaction()`; every event is dispatched `DB::afterCommit()`.

### 6.1 `LeadService`

| Method | Guarantees |
|---|---|
| `create(LeadData $data): Lead` | `lead_no` unique under concurrency (`DocumentNumberService` inside the transaction, one retry on 1062); `ContactNormalizer` fills the three `*_normalized` columns; `source` defaults to `crm.default_lead_source`; auto-assignment applied only when `$data->assignedTo` is null and `crm.auto_assign_mode <> off`; `referral_code_captured` stored whenever a code is supplied, and `ReferralRecorder::attach()` called **only if** `isAvailable()`, stamping `referral_recorded_at` on success; an optional follow-up created in the same transaction; exactly one `lead_activities` row (`system` or `imported`); when `crm.duplicate_block_on_exact` is on and `LeadDuplicateDetector` reports an exact match, throws `DuplicateLeadException` unless `$data->confirmDuplicate` is true; fires `LeadCreated` |
| `update(Lead, LeadData): Lead` | never touches `lead_no`, `status`, `assigned_to`, `client_id`, `converted_at` (each has its own method); re-normalises contacts; logs one `activity_log` entry carrying old and new values for the changed attributes only |
| `changeStatus(Lead, LeadStatus $to, StatusChangeData $d): Lead` | row taken with `lockForUpdate`; **compare-and-swap** - when `$d->expectedFrom` is given and differs from the locked row's status, throws `StaleLeadStatusException` (HTTP 409) carrying the current status; the pair must exist in `LeadStatus::allowedTransitions()` else `IllegalLeadTransitionException` (HTTP 422) carrying the allowed list; `lost` requires `lost_reason`; leaving `won` refused while a live `lead_conversions` row exists; `crm.require_follow_up_on_contacted` enforced; stamps `status_changed_at`, `won_at` / `lost_at`; writes exactly one `status_changed` activity row; fires `LeadStatusChanged` |
| `assign(Lead, ?User $to, ?string $reason): Lead` | one `assigned` activity row with `from_user_id` / `to_user_id`; stamps `assigned_at` / `assigned_by`; null unassigns; the open follow-up's `assigned_to` follows the lead unless it was explicitly set to someone else; notifies the new assignee (`LeadAssignedToYou`), never the old one |
| `bulkAssign(array $ids, ?User $to, ?string $reason): BulkResult` | explicit ids only - **never** "everything matching the filter"; at most `crm.bulk_max_ids`; ids the actor cannot see under §9 are silently excluded from the locked set and reported as `forbidden`; rows locked in **ascending id order**; one activity row per lead plus one summary `activity_log` entry naming the count and the actor; returns per-id outcome so the UI can report "187 assigned, 3 skipped" |
| `bulkChangeStatus(array $ids, LeadStatus $to, ?string $reason): BulkResult` | the same, and each row is validated through §2.11 **individually**: an illegal transition is a per-row `skipped` with its reason, never a 500 and never a partial silent write |
| `recordActivity(Lead, ActivityData): LeadActivity` | `is_system = false` for the five manual types; stamps `leads.last_activity_at` and - for `call`/`whatsapp`/`email`/`meeting` with an outcome whose `countsAsContact()` is true - `last_contacted_at`, in the same transaction |
| `updateActivity(LeadActivity, ActivityData)` / `deleteActivity(LeadActivity)` | refuse outright when `is_system`; `updateActivity` refuses after `crm.activity_edit_window_minutes` unless the actor holds `leads.edit` on someone else's note; delete is a soft delete and keeps the row in the audit log |
| `linkDuplicate(Lead $duplicate, Lead $original, string $note): Lead` | sets `duplicate_of_lead_id`, `duplicate_flagged_at`; writes a `duplicate_linked` activity row on **both** leads; refuses a self-link and a cycle (walks the chain, max depth 10) |
| `delete(Lead, string $reason)` / `restore(Lead)` | soft delete refused while a live conversion exists (the correct act is to supersede the conversion); cancels the open follow-up; restore never resurrects a cancelled follow-up |

### 6.2 `LeadDuplicateDetector`

| Method | Guarantees |
|---|---|
| `check(ContactCandidate $c, ?int $ignoreLeadId = null): DuplicateReport` | compares the **normalized** phone / whatsapp / email (only the fields in `crm.duplicate_match_fields`) against `leads` (`withTrashed()`, a trashed hit reported with `is_trashed = true`), and - when `crm.duplicate_check_clients` - against `clients` and `client_contacts`; cross-field matching (`phone` vs `whatsapp`) only when `crm.duplicate_cross_field`; honours `crm.duplicate_lookback_days`; at most 10 matches, newest first; one query per enabled field using the indexed `*_normalized` columns - never a `LIKE '%...%'` scan |
| | **Isolation-safe by construction:** a match the actor may not see under §9 is returned as a `restricted` match - match type, record type and "owned by another user" only, with no name, no contact, no id and no link. Duplicates therefore still surface without leaking another rep's pipeline |
| `checkLead(Lead): DuplicateReport` | the same for an existing row, excluding itself and anything already linked to it |

`App\Support\ContactNormalizer` (pure, unit-tested): `phone(?string $raw, ?string $countryCode): ?string`
strips everything but digits, drops a leading `00`, converts a leading `0` to the country's dialling code
when `countryCode` is known, and keeps the **last 10 significant digits** as the comparison key;
`email(?string): ?string` trims, lowercases, and does **not** strip Gmail dots (two addresses that differ
by a dot are two addresses).

### 6.3 `LeadFollowUpService`

| Method | Guarantees |
|---|---|
| `schedule(Lead, FollowUpData): LeadFollowUp` | at most one `pending` row per lead - DB-enforced by `uq_lfu_open`; a 1062 surfaces as `FollowUpAlreadyOpenException` naming the existing row's date and owner, never a 500; `scheduled_at` may not be in the past beyond the current day; `reminder_due_at = scheduled_at - remind_before_minutes`; rewrites `leads.follow_up_at` in the same transaction; one `follow_up_scheduled` activity row; fires `LeadFollowUpScheduled` |
| `complete(LeadFollowUp, OutcomeData): LeadFollowUp` | `outcome` mandatory; stamps `completed_at` / `completed_by`; clears `leads.follow_up_at` unless `$data->next` is supplied, in which case the next follow-up is created in the **same** transaction and the cache points at it; stamps `leads.last_contacted_at`; one `follow_up_completed` activity row carrying the outcome |
| `reschedule(LeadFollowUp, CarbonInterface $to, string $reason): LeadFollowUp` | old row -> `rescheduled` (releasing `open_guard`), new row with `previous_follow_up_id`, both inside one transaction so the unique index can never see two open rows |
| `cancel(LeadFollowUp, string $reason)` | reason mandatory; clears `leads.follow_up_at` |
| `markMissed(LeadFollowUp)` | callable **only** from `crm:follow-ups-mark-missed`; writes a `follow_up_missed` activity row and notifies the assignee once |
| `dueReminders(CarbonInterface $at, int $limit): Collection` | `status = pending AND reminder_sent_at IS NULL AND reminder_due_at <= $at`, ordered by `reminder_due_at`, `limit`, selected with `lockForUpdate()->skipLocked()` so two overlapping scheduler runs can never send the same reminder twice |
| `worklist(User, DateRange, FollowUpFilters): LengthAwarePaginator` | the "my follow-ups" query, already passed through §9's lead visibility scope |

### 6.4 `LeadConversionService`

| Method | Guarantees |
|---|---|
| `preview(Lead): ConversionPreview` | read-only: the proposed client field map, the duplicate report against existing clients, the attribution that will be carried forward, and whether the project hand-off is available (`ProjectCreator::isAvailable()`). **Writes nothing** |
| `convert(Lead, ConvertLeadData): ConversionResult` | one transaction, lead locked. (1) the lead must be `won`, or - when `$data->promoteToWon` is true and the actor holds `leads.change_status` - `changeStatus()` to `won` runs first inside the same transaction; (2) `uq_lc_lead_active` makes a double-submit impossible (a 1062 returns the existing conversion with `created: false`); (3) either `ClientService::create()` with a generated `client_code`, or a link to an **explicitly chosen** existing `client_id` - a client is never auto-matched silently, the duplicate report is shown and a human picks; (4) the mapped fields copied, `clients.source` and `clients.lead_id` set; (5) the immutable `lead_conversions` row written with `lead_snapshot`, `field_map`, `from_status`, `budget_amount` and `referral_code`; (6) `leads.client_id` / `converted_at` / `converted_by` set - **the lead is never deleted**; (7) attribution carried forward **without any new spine method** (F-4.5): when `ReferralRecorder::isAvailable()`, `ReferralRecorder::attach()` - bound to the spine's `ReferralService::attach($client, $collaborator, ReferralSource::ManualSelection, $leadReferral->referral_code, $leadReferral->referral_date, $ctx)` - is called with the **lead's** referral date so the client's eligibility window starts where the lead's did, and `lead_conversions.collaborator_referral_id = $leadReferral->id` plus `lead_conversions.referral_code` are written as the evidence link; when the recorder is unavailable only `clients.referral_code_captured` is copied from the lead and `crm:record-captured-referrals` attaches it later; (8) when `$data->createProject` and `ProjectCreator::isAvailable()` and the actor holds `projects.create`, `ProjectCreator::createFromLead()` is called with a payload that **includes `collaborator_id` and `referral_code`**, and the returned id is written to `lead_conversions.project_id`; (9) one `converted` activity row; fires `LeadConverted` |
| `supersede(LeadConversion, string $reason): void` | stamps `superseded_at` / `supersede_reason` (releasing `active_guard`) so a corrected conversion can be recorded; **never deletes** the row, never unlinks the client; requires `leads.edit` + `clients.create`; writes an `activity_log` entry with the reason |

**Why step (8) matters commercially.** A referred *lead* and a referred *client* earn nothing: the spine
pays commission only on a received **project payment** whose *project* carries the referral (§45-§48) or a
received student fee. Conversion is therefore the point at which attribution must reach the project, and
`lead_conversions.collaborator_referral_id` is the evidence that the chain
lead -> client -> project -> payment -> ledger row was unbroken.

### 6.5 `LeadBoardService`

| Method | Guarantees |
|---|---|
| `board(BoardFilters): BoardData` | **two** queries per render regardless of column count: one `SELECT status, COUNT(*) AS c, SUM(budget_amount) AS v, COUNT(budget_amount) AS with_budget FROM leads ... GROUP BY status` and one windowed card query (`status IN (...)` with a per-status row-number window, `crm.kanban_page_size` per column), **both** passed through the identical §9 visibility scope, so a column's count and sum can never include a row the user cannot open; sums come back as strings and are totalled and formatted with `Money` / `money()` - no float arithmetic; a column reports `with_budget` so the footer can say "12 of 19 leads have a budget" and the sum is never mistaken for the whole column |
| `column(LeadStatus, BoardFilters, int $page): ColumnPage` | lazy "load more" for one column; same scope, same ordering |
| `move(Lead, LeadStatus $to, LeadStatus $expectedFrom, StatusChangeData $d): MoveResult` | delegates to `LeadService::changeStatus()` and returns exactly what the optimistic UI needs: the updated card, and the refreshed `{count, value_sum, value_sum_formatted, with_budget}` for **only the two affected columns** |

### 6.6 `LeadImportService`

| Method | Guarantees |
|---|---|
| `stage(UploadedFile, ImportOptions): LeadImport` | MIME sniffed with `finfo` (a `.csv` that is really a PHP file is refused), delimiter and encoding sniffed, BOM stripped, file stored on the **private** disk with a hashed name; row count rejected above `crm.import_max_rows`; `file_hash` recorded and a previous import of the same hash reported as a warning; returns a suggested `column_map` built from the header |
| `validateRows(LeadImport): ImportPreview` | a dry run: writes one `lead_import_rows` row per CSV line with per-field errors from the same rules `StoreLeadRequest` uses, resolves the duplicate strategy per row, and **creates no lead**; moves the import to `validated` |
| `run(LeadImport): void` | dispatches `ProcessLeadImportChunk` per 200 rows; each **row** is its own transaction so one bad row never rolls back a good one; each row insert is keyed by `uq_lir_row(lead_import_id, row_number)`, so a retried chunk after a lost ack is a no-op; `created_count` / `updated_count` / `skipped_count` / `failed_count` moved with atomic `increment()`; every created lead carries `lead_import_id` and an `imported` activity row; `update_existing` updates only fields the CSV actually supplies and never changes `status`, `assigned_to` or money columns; writes `error_report_path` at the end; finishes as `completed` or `completed_with_errors`; fires `LeadImportCompleted` |
| `cancel(LeadImport, string $reason)` | stops further chunks; already-created leads are **kept** (deleting a user's real data to undo an import is worse than an honest partial result), and the count is reported |
| `template(): StreamedResponse` | the sample CSV with the exact expected headers |

### 6.7 `ClientService`

| Method | Guarantees |
|---|---|
| `create(ClientData): Client` | `client_code` unique under concurrency via `DocumentNumberService`, **immutable** afterwards (model hook throws); contacts normalised; `portal_enabled` false; an optional primary contact created in the same transaction; fires `ClientCreated` |
| `update(Client, ClientData): Client` | never touches `client_code`, `user_id`, `status`, `portal_enabled`; `billing_same_as_address` true nulls `billing_address` on save so two fields cannot disagree; tax percentages validated 0-100 and stored `decimal(8,4)` |
| `changeStatus(Client, ClientStatus, ?string $reason): Client` | reason mandatory for `suspended` / `closed`; a status whose `canUsePortal()` is false **immediately** revokes the client's sessions (`Auth::logoutOtherDevices` equivalent for that user, plus the `EnsureClientContext` check on every request) without touching `portal_enabled`, so restoring the status restores access |
| `assignAccountManager(Client, ?User, ?string $reason)` | requires `clients.assign`; logged with old and new |
| `enablePortal(Client, PortalInviteData): User` | creates or links a user, assigns the `Client` role, sets `clients.user_id` (or `client_contacts.user_id` + `portal_access` for a contact), sets `users.must_change_password`, stamps `portal_invited_at`, sets `portal_enabled = true`; **never sets or emails a password** - it sends a password-reset invitation (`ClientPortalInvitation`); refuses a user already bound to another client or contact (the two UNIQUE indexes plus an explicit check); requires `clients.change_status` **and** `users.create` |
| `disablePortal(Client, string $reason)` | `portal_enabled = false`, the client's sessions revoked, the user row and its history **kept** |
| `financialSummary(Client): ClientFinancialSummary` | invoiced / paid / outstanding / overdue obtained **only** by calling the owning phases' read models (Phase 13 invoices, the spine's `project_payments`); returns an explicit `unavailable` snapshot when those capabilities are not bound - never a zero pretending to be a fact; every figure withheld unless the caller holds `clients.view_financial` |
| `delete(Client, string $reason)` / `restore(Client)` | soft delete refused while an undeleted project, invoice or payment references the client (the capability contracts answer "does anything reference it"); disables the portal first; `client_code` is never reused |

`ClientContactService` - `create`, `update`, `delete`, `setPrimary(ClientContact)`: setting a new primary
clears the previous one inside one transaction so `uq_cc_primary` can never be violated; deleting the
primary contact of a client that has others refuses until another is promoted.

### 6.8 `ClientDocumentService`

| Method | Guarantees |
|---|---|
| `upload(Client, UploadedFile, DocumentData): ClientDocument` | extension whitelisted from `security.allowed_file_types`, size from `security.max_upload_mb`, MIME sniffed from **content**; `.php`, `.phtml`, `.svg`, `.html` and any double extension refused regardless of the whitelist; stored on the private disk with a hashed name under `clients/{id}/documents/`; `checksum` recorded and a same-checksum document on the same client reported as a warning; `visible_to_client` from `crm.client_visible_documents_default` or the category's `defaultVisibleToClient()`; one activity entry |
| `setClientVisibility(ClientDocument, bool, ?string $reason)` | stamps `shared_at` / `shared_by` the first time; fires `ClientDocumentSharedWithClient` which notifies the client's portal contacts - so "the client will see this file immediately" in the confirm dialog is literally true |
| `download(ClientDocument, User): StreamedResponse` | authorises through `ClientDocumentPolicy::download` (staff) or `::downloadAsClient` (portal, which additionally requires `visible_to_client`); streams from the private disk with `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff` and the stored `mime_type`; writes an `activity_log` entry naming the actor and whether the actor was the client |
| `delete(ClientDocument)` / `forceDelete(ClientDocument)` | soft delete keeps the file on disk (a restore must work); force delete - Super Admin only - removes the file and logs it |

### 6.9 Client panel support

| Class | Responsibility |
|---|---|
| `App\Support\ClientContext` | Resolves the authenticated user's client exactly once per request: `clients.user_id = auth()->id()` **or** `client_contacts.user_id = auth()->id() AND portal_access = 1`, in that order, on a client whose `portal_enabled` is true and whose `status->canUsePortal()` is true. Exposes `client(): Client`, `clientId(): int`, `contact(): ?ClientContact`, `isContact(): bool`. Throws `NoClientContextException` when nothing resolves. **Every panel query takes its client id from here and never from the request.** |
| `App\Http\Middleware\EnsureClientContext` | Alias `client.context`. Returns 403 with an explanatory page when `crm.client_portal_enabled` is off, when no context resolves, when `portal_enabled` is false, or when the client's status forbids the portal. Runs on every `/client` route, so revoking access takes effect on the next request, not at the next login |
| `App\Support\ClientPortalRegistry` | The section registry of **[D-P5-1]**: `register(ClientPortalSection)`, `all()`, `visibleTo(User, Client)` (filters by module enabled **and** permission held, exactly like `Sidebar` and `DashboardRegistry`), `section(string $key): ?ClientPortalSection`. The panel nav is built from `visibleTo()`; a controller whose section is absent `abort(404)`s |
| `App\Services\Crm\ClientPortalService` | `dashboard(Client): DashboardData` - the cards of §19 assembled **only** from registered sections' `badgeCount()` plus Phase 5's own documents and notifications counts; `updateProfile(Client, ClientProfileData)` - a strict whitelist (`name`, `phone`, `whatsapp`, `website`, `address`, `city`, `postal_code`, `about`, `logo_path` and the contact's own name/designation/phone); a client can **never** change `client_code`, `status`, `portal_enabled`, `account_manager_id`, tax numbers, `payment_terms_days`, `currency` or `notes` - each attempt is dropped by the Form Request and asserted by a test |

### 6.10 Shared support classes added here

| Class | Responsibility |
|---|---|
| `App\Services\Finance\DocumentNumberService` | **[D-P5-3] / D27** - the spine's §5 contract, created here and reused unchanged by phases 6, 7, 8-9, 10 and 14-17. Two published methods: `next(string $prefixKey, string $counterKey, string $pad = '%06d'): string` (the pad is a **default, not a house style** - every caller passes its own), and `reserve(string $counterKey, ?string $periodKey = null, ?string $periodValue = null): int` (F-4.12) which reserves the next integer under the same `FOR UPDATE` lock and, when a period is supplied, resets the counter to 1 the first time the **period row** - itself a `settings` key, e.g. `institute.student_id_sequence_period` - differs from `$periodValue`, writing both in one transaction. There is **no second `FOR UPDATE` counter implementation anywhere in the codebase** |
| `App\Support\Inquiry\CrmLeadInquiryTarget` | `implements App\Contracts\Inquiry\InquiryTarget` (Phase 4's interface) and is registered into Phase 4's `InquiryRouter` (phase-04 §6.10.4) - **the only path from a contact inquiry to a lead** (F-2.1). On a software-service inquiry it calls `LeadService::create()` with `contact_inquiry_id`, `source = website`, `source_detail` and any `?ref=` code into `referral_code_captured`; a course inquiry is routed elsewhere by the router, never by this class. Idempotency is `uq_leads_inquiry` plus the 1062 catch (§2.1), never a SELECT. Phase 5 registers **no listener on Phase 4's event** |
| `App\Support\ContactNormalizer` | §6.2 |
| `App\Support\CsvWriter` | Streaming CSV: a generator + `chunkById`, never an array of rows in memory; **escapes formula injection** by prefixing a cell starting with `=`, `+`, `-`, `@`, tab or CR with a single quote. Phase 23's exports reuse it |
| `App\Services\Crm\LeadExporter` / `ClientExporter` | `stream(Filters, array $columns): StreamedResponse` through the §9 scope; above `crm.export_max_rows` the request instead queues `BuildCrmExport` and answers "we will notify you" |

---

## 7. Routes

`routes/admin.php` additions - every row is inside the Phase 1 group (`auth`, `active`, `panel:admin`) and
carries the module middleware shown. `{lead}` and `{client}` bind by id; the soft-delete-aware binding
(`withTrashed`) is used only on `restore`.

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/leads` | `admin.leads.index` | `module:leads`, `can:leads.view` |
| GET `/admin/leads/board` | `admin.leads.board` | `module:leads`, `can:leads.view` |
| GET `/admin/leads/board/column/{status}` | `admin.leads.board.column` | `module:leads`, `can:leads.view` |
| PATCH `/admin/leads/{lead}/board-move` | `admin.leads.board.move` | `module:leads`, `can:leads.change_status`, `throttle:120,1` |
| GET `/admin/leads/follow-ups` | `admin.leads.follow-ups.index` | `module:leads`, `can:leads.view` |
| GET `/admin/leads/create` | `admin.leads.create` | `module:leads`, `can:leads.create` |
| POST `/admin/leads` | `admin.leads.store` | `module:leads`, `can:leads.create` |
| POST `/admin/leads/duplicate-check` | `admin.leads.duplicate-check` | `module:leads`, `can:leads.create`, `throttle:60,1` |
| GET `/admin/leads/{lead}` | `admin.leads.show` | `module:leads`, `can:view,lead` |
| GET `/admin/leads/{lead}/edit` | `admin.leads.edit` | `module:leads`, `can:update,lead` |
| PUT `/admin/leads/{lead}` | `admin.leads.update` | `module:leads`, `can:update,lead` |
| DELETE `/admin/leads/{lead}` | `admin.leads.destroy` | `module:leads`, `can:delete,lead` |
| POST `/admin/leads/{lead}/restore` | `admin.leads.restore` | `module:leads`, `can:leads.restore` |
| GET `/admin/leads/{lead}/print` | `admin.leads.print` | `module:leads`, `can:leads.print` |
| PATCH `/admin/leads/{lead}/status` | `admin.leads.status` | `module:leads`, `can:changeStatus,lead` |
| PATCH `/admin/leads/{lead}/assign` | `admin.leads.assign` | `module:leads`, `can:assign,lead` |
| POST `/admin/leads/bulk/assign` | `admin.leads.bulk.assign` | `module:leads`, `can:leads.assign` |
| POST `/admin/leads/bulk/status` | `admin.leads.bulk.status` | `module:leads`, `can:leads.change_status` |
| POST `/admin/leads/bulk/destroy` | `admin.leads.bulk.destroy` | `module:leads`, `can:leads.delete` |
| POST `/admin/leads/{lead}/activities` | `admin.leads.activities.store` | `module:leads`, `can:update,lead` |
| PUT `/admin/leads/{lead}/activities/{activity}` | `admin.leads.activities.update` | `module:leads`, `can:update,activity` |
| DELETE `/admin/leads/{lead}/activities/{activity}` | `admin.leads.activities.destroy` | `module:leads`, `can:delete,activity` |
| POST `/admin/leads/{lead}/follow-ups` | `admin.leads.follow-ups.store` | `module:leads`, `can:update,lead` |
| PATCH `/admin/leads/{lead}/follow-ups/{followUp}/complete` | `admin.leads.follow-ups.complete` | `module:leads`, `can:complete,followUp` |
| PATCH `/admin/leads/{lead}/follow-ups/{followUp}/reschedule` | `admin.leads.follow-ups.reschedule` | `module:leads`, `can:complete,followUp` |
| PATCH `/admin/leads/{lead}/follow-ups/{followUp}/cancel` | `admin.leads.follow-ups.cancel` | `module:leads`, `can:complete,followUp` |
| POST `/admin/leads/{lead}/duplicate-link` | `admin.leads.duplicate-link` | `module:leads`, `can:update,lead` |
| GET `/admin/leads/{lead}/convert` | `admin.leads.convert.form` | `module:leads`, `can:convert,lead` |
| POST `/admin/leads/{lead}/convert` | `admin.leads.convert.store` | `module:leads`, `can:convert,lead` |
| POST `/admin/leads/conversions/{conversion}/supersede` | `admin.leads.conversions.supersede` | `module:leads`, `can:convert,lead` |
| GET `/admin/leads/export` | `admin.leads.export` | `module:leads`, `can:leads.export` |
| GET `/admin/leads/import` | `admin.leads.import.index` | `module:leads`, `can:leads.import` |
| GET `/admin/leads/import/template` | `admin.leads.import.template` | `module:leads`, `can:leads.import` |
| POST `/admin/leads/import` | `admin.leads.import.store` | `module:leads`, `can:leads.import` |
| GET `/admin/leads/import/{import}` | `admin.leads.import.show` | `module:leads`, `can:leads.import` |
| PUT `/admin/leads/import/{import}/mapping` | `admin.leads.import.mapping` | `module:leads`, `can:leads.import` |
| POST `/admin/leads/import/{import}/validate` | `admin.leads.import.validate` | `module:leads`, `can:leads.import` |
| POST `/admin/leads/import/{import}/run` | `admin.leads.import.run` | `module:leads`, `can:leads.import` |
| POST `/admin/leads/import/{import}/cancel` | `admin.leads.import.cancel` | `module:leads`, `can:leads.import` |
| GET `/admin/leads/import/{import}/errors` | `admin.leads.import.errors` | `module:leads`, `can:leads.import` |
| GET `/admin/clients` | `admin.clients.index` | `module:clients`, `can:clients.view_any` |
| GET `/admin/clients/create` | `admin.clients.create` | `module:clients`, `can:clients.create` |
| POST `/admin/clients` | `admin.clients.store` | `module:clients`, `can:clients.create` |
| GET `/admin/clients/{client}` | `admin.clients.show` | `module:clients`, `can:view,client` |
| GET `/admin/clients/{client}/edit` | `admin.clients.edit` | `module:clients`, `can:update,client` |
| PUT `/admin/clients/{client}` | `admin.clients.update` | `module:clients`, `can:update,client` |
| DELETE `/admin/clients/{client}` | `admin.clients.destroy` | `module:clients`, `can:delete,client` |
| POST `/admin/clients/{client}/restore` | `admin.clients.restore` | `module:clients`, `can:clients.restore` |
| GET `/admin/clients/{client}/print` | `admin.clients.print` | `module:clients`, `can:clients.print` |
| GET `/admin/clients/export` | `admin.clients.export` | `module:clients`, `can:clients.export` |
| PATCH `/admin/clients/{client}/status` | `admin.clients.status` | `module:clients`, `can:changeStatus,client` |
| PATCH `/admin/clients/{client}/account-manager` | `admin.clients.account-manager` | `module:clients`, `can:assign,client` |
| GET `/admin/clients/{client}/financials` | `admin.clients.financials` | `module:clients`, `can:viewFinancial,client` |
| POST `/admin/clients/{client}/portal/enable` | `admin.clients.portal.enable` | `module:clients`, `can:managePortal,client` |
| POST `/admin/clients/{client}/portal/disable` | `admin.clients.portal.disable` | `module:clients`, `can:managePortal,client` |
| POST `/admin/clients/{client}/contacts` | `admin.clients.contacts.store` | `module:clients`, `can:update,client` |
| PUT `/admin/clients/{client}/contacts/{contact}` | `admin.clients.contacts.update` | `module:clients`, `can:update,contact` |
| PATCH `/admin/clients/{client}/contacts/{contact}/primary` | `admin.clients.contacts.primary` | `module:clients`, `can:update,contact` |
| DELETE `/admin/clients/{client}/contacts/{contact}` | `admin.clients.contacts.destroy` | `module:clients`, `can:delete,contact` |
| GET `/admin/clients/{client}/documents` | `admin.clients.documents.index` | `module:client_documents`, `can:client_documents.view_any` |
| POST `/admin/clients/{client}/documents` | `admin.clients.documents.store` | `module:client_documents`, `can:client_documents.upload` |
| PUT `/admin/clients/{client}/documents/{document}` | `admin.clients.documents.update` | `module:client_documents`, `can:update,document` |
| PATCH `/admin/clients/{client}/documents/{document}/visibility` | `admin.clients.documents.visibility` | `module:client_documents`, `can:changeVisibility,document` |
| GET `/admin/client-documents/{document}/download` | `admin.client-documents.download` | `module:client_documents`, `can:download,document` |
| DELETE `/admin/clients/{client}/documents/{document}` | `admin.clients.documents.destroy` | `module:client_documents`, `can:delete,document` |

**D31 - Phase 5 owns `routes/client.php` and every `client.*` route name.** The table below is
the **complete** client route name list for the release (F-6.2): a later phase **appends a screen behind an
existing name** through `ClientPortalRegistry` and never redeclares the name, because Laravel's last
registration silently wins. Specifically: Phase 6 contributes the `projects`, `tasks` and `files` sections
into `client.projects.index` / `.show`, `client.tasks.index`, `client.files.index` (which stays at
`/client/files` with a `?project=` filter) and `client.files.download` - it declares **no**
`client.attachments.download`; the financial spine contributes payments into `client.payments.index` rather
than declaring it; Phase 13 appends invoices the same way. `client.milestones.index` and
`client.progress.show` stay **owned by Phase 5** even though phase-06 §8.11 folds their *views* into the
`client.projects.show` screen - both names resolve to that screen. **Every** row carries `client.context`
(D31, F-12.3), including the rows a later phase fills in.

`routes/client.php` additions - inside Phase 1's group (`auth`, `active`, `panel:client`) plus the new
`client.context` middleware on every row.

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/client` | `client.dashboard` | `can:client_portal.dashboard` |
| GET `/client/profile` | `client.profile.edit` | `can:client_portal.profile` |
| PUT `/client/profile` | `client.profile.update` | `can:client_portal.profile` |
| GET `/client/projects` | `client.projects.index` | `module:projects`, `can:client_portal.projects` |
| GET `/client/projects/{project}` | `client.projects.show` | `module:projects`, `can:viewByClient,project` |
| GET `/client/projects/{project}/tasks` | `client.tasks.index` | `module:tasks`, `can:client_portal.tasks` |
| GET `/client/projects/{project}/milestones` | `client.milestones.index` | `module:project_milestones`, `can:client_portal.milestones` |
| GET `/client/projects/{project}/progress` | `client.progress.show` | `module:projects`, `can:client_portal.projects` |
| GET `/client/files` | `client.files.index` | `module:files`, `can:client_portal.files` |
| GET `/client/files/{file}/download` | `client.files.download` | `module:files`, `can:client_portal.download` |
| GET `/client/documents` | `client.documents.index` | `module:client_documents`, `can:client_portal.documents` |
| GET `/client/documents/{document}/download` | `client.documents.download` | `module:client_documents`, `can:client_portal.download` |
| GET `/client/invoices` | `client.invoices.index` | `module:invoices`, `can:client_portal.invoices` |
| GET `/client/invoices/{invoice}` | `client.invoices.show` | `module:invoices`, `can:client_portal.invoices` |
| GET `/client/invoices/{invoice}/pdf` | `client.invoices.pdf` | `module:invoices`, `can:client_portal.download` |
| GET `/client/payments` | `client.payments.index` | `module:project_payments`, `can:client_portal.payments` |
| GET `/client/meetings` | `client.meetings.index` | `module:meetings`, `can:client_portal.meetings` |
| GET `/client/tickets` | `client.tickets.index` | `module:support_tickets`, `can:client_portal.tickets` |
| GET `/client/tickets/{ticket}` | `client.tickets.show` | `module:support_tickets`, `can:client_portal.tickets` |
| GET `/client/messages` | `client.messages.index` | `module:messages`, `can:client_portal.messages` |
| GET `/client/messages/{conversation}` | `client.messages.show` | `module:messages`, `can:client_portal.messages` |
| GET `/client/notifications` | `client.notifications.index` | `can:client_portal.notifications` |
| POST `/client/notifications/{notification}/read` | `client.notifications.read` | `can:client_portal.notifications` |
| POST `/client/notifications/read-all` | `client.notifications.read-all` | `can:client_portal.notifications` |

Routes whose module belongs to a later phase return **404** until that phase registers its
`ClientPortalSection` (**[D-P5-1]**); the nav item is absent in the same condition. The client **write**
paths into later-phase tables - create a ticket, send a message - are declared by **Phase 22** in this
same file, under the comment marker `// Phase 22: client writes` that Phase 5 leaves behind.

Controllers: `Admin/LeadController`, `Admin/LeadBoardController`, `Admin/LeadActivityController`,
`Admin/LeadFollowUpController`, `Admin/LeadConversionController`, `Admin/LeadImportController`,
`Admin/ClientController`, `Admin/ClientContactController`, `Admin/ClientDocumentController`,
`Client/DashboardController`, `Client/ProfileController`, `Client/ProjectController`,
`Client/TaskController`, `Client/MilestoneController`, `Client/FileController`,
`Client/DocumentController`, `Client/InvoiceController`, `Client/PaymentController`,
`Client/MeetingController`, `Client/TicketController`, `Client/MessageController`,
`Client/NotificationController`.

Form Requests in `app/Http/Requests/Crm/`: `StoreLeadRequest`, `UpdateLeadRequest`,
`ChangeLeadStatusRequest` (requires `lost_reason` when the target is `lost`; accepts
`expected_from_status`), `AssignLeadRequest`, `BulkLeadAssignRequest`, `BulkLeadStatusRequest`,
`StoreLeadActivityRequest`, `StoreLeadFollowUpRequest`, `CompleteLeadFollowUpRequest`,
`ConvertLeadRequest`, `StoreLeadImportRequest`, `UpdateLeadImportMappingRequest`, `StoreClientRequest`,
`UpdateClientRequest`, `ChangeClientStatusRequest`, `StoreClientContactRequest`,
`UpdateClientContactRequest`, `StoreClientDocumentRequest`, `EnableClientPortalRequest`, and
`app/Http/Requests/ClientPortal/UpdateClientProfileRequest`.

---

## 8. UI screens

House rules from Phase 1 §9 and `CLAUDE.md` §6 apply everywhere: `x-ui.*` only, search + filters +
sortable headers + pagination + empty state + skeleton on every list, money right-aligned with
`tabular-nums` through `money()`, toast on every write, `x-ui.confirm` on every destructive or
irreversible action, tables inside `overflow-x-auto`, light and dark, mobile first.

### 8.1 Leads index

**Purpose.** The pipeline as a working list, and the only place bulk operations happen.
**Components.** `x-ui.page-header`, `x-ui.filter-bar`, `x-ui.table`, `x-ui.th-sortable`, `x-ui.badge`,
`x-ui.avatar`, `x-ui.pagination-summary`, `x-ui.modal`, `x-ui.confirm`, `x-ui.empty-state`,
`x-ui.skeleton`.
**Filters.** Free text (lead_no, name, company, phone, whatsapp, email); status (multi-select);
source (multi); assignee (+ "Me", "Unassigned"); follow-up (overdue / today / next 7 days / none);
created range (`DateRange`); budget min-max; "has duplicate"; "converted" yes/no; "trashed".
**Columns.** Lead # | Name + company (two lines) | Contact (call, WhatsApp via
`crm.whatsapp_link_template`, and mail icons) | Source badge | Interested service | Budget
(right-aligned) | Status badge | Assignee avatar + name | Next follow-up chip (rose when overdue, amber
today) | Last activity (relative) | row actions (view, edit, log activity, schedule follow-up, change
status, assign, convert, delete).
**Bulk bar.** Appears on selection: "N selected", Assign, Change status, Export, Delete. Selection is
carried as explicit ids; selecting across pages shows "selection is limited to
`crm.bulk_max_ids` leads" and the button disables beyond it. Change status in bulk opens a modal that
names how many rows the transition is legal for **before** submitting.
**Empty state.** "No leads match these filters" with Clear filters; on a truly empty module, "No leads
yet" with **Add lead** and **Import CSV**.

### 8.2 Lead Kanban board (§18 "Kanban view required")

**Purpose.** Move work across the seven stages and see, per stage, how many leads and how much money sit
there.
**Components.** `x-ui.page-header`, `x-ui.filter-bar`, `x-ui.card`, `x-ui.badge`, `x-ui.avatar`,
`x-ui.skeleton`, `x-ui.modal`, `x-ui.confirm`, `x-ui.empty-state`.
**Columns.** One per `LeadStatus` in `sortOrder()`, limited to `crm.lead_statuses_on_board`. Header shows
the label, the **count**, the **value sum** (`SUM(budget_amount)` via `money()`), and a muted
"`with_budget` of `count` have a budget" line so the sum is never read as the column total. Each column
lazy-loads `crm.kanban_page_size` cards with a "Load more" button hitting
`admin.leads.board.column`; a column with no cards shows a compact in-column empty state.
**Card.** Name + company, budget, source chip, assignee avatar, next-follow-up chip, a "stale" chip when
`last_activity_at` is older than `crm.stale_lead_days`, a duplicate chip when `duplicate_of_lead_id` is
set, and a "Move to" dropdown.
**Drag behaviour.** Native HTML5 drag-and-drop driven by Alpine - **no new JS dependency**.
On drop the card is moved **optimistically**, is rendered in a "saving" state, and the two affected column
headers are adjusted locally by the card's own count and budget. The PATCH carries
`{to_status, expected_from_status, reason?, lost_reason?}`.
**Server validation of the transition.** 200 replaces the card and overwrites both column headers with the
server's figures (so a concurrent change by a colleague self-corrects). **422** (illegal transition) and
**409** (`expected_from_status` no longer current) both spring the card back to its original column, show
a toast naming the reason, and re-render the two headers from the error payload. A drop onto `lost` opens
the reason modal **before** the optimistic move, because the move cannot succeed without it. A drop onto
`won` succeeds and the toast offers a **Convert to client** action.
**Accessibility and touch.** Native DnD is unusable on touch and with a keyboard, so every card also has
the "Move to" dropdown that posts the identical endpoint; the board announces each move through an
`aria-live="polite"` region ("Ahmed Khan moved to Negotiation"). Columns scroll horizontally inside their
own container on small screens.

### 8.3 Lead detail

**Purpose.** One screen that answers "who is this, what happened, what is next".
**Components.** `x-ui.page-header`, `x-ui.tabs`, `x-ui.card`, `x-ui.stat-card`, `x-ui.badge`,
`x-ui.avatar`, `x-ui.modal`, `x-ui.confirm`, `x-ui.empty-state`.
**Header.** Lead #, name, company, status badge, assignee; actions: Log activity, Schedule follow-up,
Change status, Assign, **Convert**, call / WhatsApp / email, Print, Delete.
**Tabs.** *Overview* - the §18 fields, the duplicate warning panel, and a Referral block showing the
captured code, the resolved collaborator (only when `ReferralRecorder::isAvailable()`) and
`referral_recorded_at`. *Timeline* - `lead_activities` newest first, grouped by day, filterable by type,
system rows visually distinct and without edit/delete affordances. *Follow-ups* - the open one at the top
with Complete / Reschedule / Cancel, then history with outcomes. *Conversion* - the `lead_conversions` row
(or an empty state), linking the client and the project, showing the snapshot, the actor, the time and the
attribution that was carried forward.

### 8.4 Lead create / edit form

**Sections.** Contact | Interest (service, budget) | Assignment and follow-up | Source and referral |
Notes.
**Live duplicate check.** On blur of phone, WhatsApp or email (debounced 500 ms) the form posts to
`admin.leads.duplicate-check` and renders an amber panel per match: match type, the record, its status,
owner and last activity, with **Open**, **Log activity on the existing lead** and **Link as duplicate**.
Continuing requires ticking `confirm_duplicate`; when `crm.duplicate_block_on_exact` is on the submit
button stays disabled for an exact match. A `restricted` match renders as a single amber line with no
details and no link.

### 8.5 Follow-up worklist

**Purpose.** The daily call list, plus a month view for planning.
**Components.** `x-ui.tabs` (List / Calendar), `x-ui.filter-bar`, `x-ui.table`, `x-ui.badge`,
`x-ui.modal`, `x-ui.empty-state`.
**Filters.** Assignee (default "Me"; "All" requires `leads.view_any`), type, status, range, overdue only.
**List columns.** When | Lead | Type | What to say (notes) | Assignee | Status | actions (Complete with
outcome, Reschedule, Cancel, Open lead).
**Calendar.** A plain month grid - **no calendar library** - each day cell listing its follow-ups with an
overdue/today colour, the current day outlined, week and month toggles, and a day click opening that day's
list in a modal. Empty state: "Nothing scheduled - pick a lead and set a follow-up".

### 8.6 Import wizard (4 steps)

`x-ui.tabs` as a step rail with a disabled-forward rule. **(1) Upload** - drop zone, template download,
the detected delimiter/encoding/row count, and a warning when the same `file_hash` was imported before.
**(2) Map columns** - a select per CSV header with suggested targets, required fields marked, unmapped
columns explicitly listed as "ignored", and the first five rows previewed as they will be read.
**(3) Options** - default source, default assignee, default status, duplicate strategy (with a sentence
explaining each), and "stop after N errors". **(4) Validate and run** - the dry-run result as counts plus
a row-error table (row number, the offending values, the messages) with a **Download error CSV**, then
Run, then a progress bar polling `admin.leads.import.show` with created / updated / skipped / failed
counters and a Cancel that states plainly that already-created leads are kept.

### 8.7 Clients index

**Filters.** Free text (client_code, name, company, email, phone); status; account manager; country;
portal enabled; created range; trashed.
**Columns.** Client ID | Logo avatar + company / name | Contact | Country | Account manager | Projects
(only when the Phase 6 capability is bound) | Outstanding (right-aligned, **only** with
`clients.view_financial`) | Portal chip (Enabled / Invited / Off) | Status badge | actions.
**Empty state.** "No clients yet" with **Add client** and a hint that winning a lead creates one.

### 8.8 Client detail

**Header.** Client ID, company, status badge, account manager, portal chip; actions: Edit, Change status,
Assign account manager, Enable/Disable portal, Print, Export, Delete.
**Tabs.** *Overview* (identity, address, commercial terms, **Tax details** as its own card: registered,
NTN, STRN, CNIC, exempt, override rates shown as percentages), *Contacts* (table + add/edit modal, the
primary marked with a star, portal access chip), *Documents* (§8.9), *Projects* (capability-gated list or
an "available in Phase 6" empty state), *Financials* (`clients.view_financial` only: invoiced, paid,
outstanding, overdue as `x-ui.stat-card`s, each sourced from the owning phase's read model or rendered as
"-" with a tooltip when that capability is absent - never a fabricated zero), *Portal access* (user, invite
state, Send invite / Resend / Disable, and the contacts holding `portal_access`), *Activity* (the
`activity_log` entries whose subject is this client).

### 8.9 Client documents tab

Drop-zone upload with category, title, description, valid-from / expires-at, and a "Visible to client"
toggle. The list shows title, category badge, size, type icon, uploaded by, date, an expiry badge
(amber within 30 days, rose when past), and a **Visible to client** switch. Turning visibility **on** is
wrapped in `x-ui.confirm` whose text says the client will see and be notified about the file immediately.
Download and delete are row actions; delete is confirmed. Filters: category, visible-to-client, expiring.
Empty state: "No documents" + Upload.

### 8.10 Client panel screens

Shell: `@extends('layouts.panel')`, nav built from `ClientPortalRegistry::visibleTo()`. Every list is
read-only in this phase, every screen shows the client's own company name in the header so the scope is
visible to the user, and every screen's empty state is written for a client, not for staff.

| Screen | Purpose | Components | Filters | Columns / content | Empty state |
|---|---|---|---|---|---|
| Dashboard | "where do we stand" | `x-ui.stat-card`, `x-ui.card`, `x-ui.badge` | - | Active projects, overall progress, open milestones, unpaid invoices total, payments received, open tickets, unread messages, upcoming meetings - each card present only when its section is registered and permitted | "Your account is being set up" |
| Profile | keep their own details right | `x-ui.form.*` | - | Editable whitelist of §6.9 only; tax numbers, status and terms are shown **read-only** with a "contact your account manager" note | - |
| Projects | the work they bought | `x-ui.table`, `x-ui.badge`, progress bar | status, date range, search | Project ID, name, type, start, deadline, **progress**, status, **project value** (never the internal `budget`) | "No projects yet" |
| Project detail | one project at a glance | `x-ui.card`, `x-ui.tabs` | - | Description, dates, progress, milestones, client-visible tasks, client-visible files, the project manager's name | - |
| Tasks | what is being worked on | `x-ui.table`, `x-ui.badge` | project, status, search | Title, milestone, assignee name, priority, status, deadline. **Never** estimated/actual hours, never internal comments | "No tasks shared yet" |
| Milestones | the deliverable plan | `x-ui.table`, progress bar | project, status | Name, description, start, deadline, progress, status; **amount only with `client_portal.invoices`** | "No milestones yet" |
| Progress | one number they care about | `x-ui.card`, progress bars | project | Overall percentage + per-milestone rollup, read-only | "Progress will appear once work starts" |
| Files | deliverables | `x-ui.table` | project, type, search | Name, type icon, size, uploaded date, Download | "No files shared yet" |
| Documents | contracts and papers | `x-ui.table`, `x-ui.badge` | category, search | Title, category, size, shared date, expiry badge, Download | "No documents shared yet" |
| Invoices | what they owe | `x-ui.table`, `x-ui.badge` | status, date range | Invoice #, date, due date, total, paid, remaining, status badge, View, PDF | "No invoices yet" |
| Payments | what they paid | `x-ui.table` | project, date range, method | Receipt #, `paid_on`, project, method, amount, refunded, net received, status badge (a voided receipt is shown as voided, never hidden). **No `collaborator_id`, no commission column** | "No payments recorded yet" |
| Meetings | when we talk | `x-ui.table` or list | upcoming / past | Title, date, time, project, join link, notes, status | "No meetings scheduled" |
| Tickets | support history | `x-ui.table`, `x-ui.badge` | status, priority | Ticket #, subject, department, priority, status, last update, View (New ticket arrives with Phase 22) | "No tickets - you can raise one once support is enabled" |
| Messages | the conversation | list + thread | - | Conversations the signed-in user participates in, read status, timestamps (sending arrives with Phase 22) | "No messages yet" |
| Notifications | what changed | list, `x-ui.badge` | unread only | Title, body, time, Mark read, Mark all read | "Nothing new" |

### 8.11 Dashboard widgets registered into Phase 2's `DashboardRegistry`

| Widget | Span | Permission / module | Data |
|---|---|---|---|
| `LeadsByStatusWidget` | 4 | `leads.view` / `leads` | count per status for the selected `DateRange`, scoped by §9 |
| `PipelineValueWidget` | 4 | `leads.view` / `leads` | `SUM(budget_amount)` over open statuses + the "n of m have a budget" caveat |
| `NewLeadsTrendWidget` | 8 | `leads.view_reports` / `leads` | 14-day line via `x-ui.chart` |
| `LeadsBySourceWidget` | 4 | `leads.view_reports` / `leads` | count + conversion rate per `InquirySource` |
| `LeadConversionRateWidget` | 4 | `leads.view_reports` / `leads` | won / (won + lost) and converted / total for the range |
| `OverdueFollowUpsWidget` | 4 | `leads.view` / `leads` | the actor's overdue follow-ups, linking the worklist |
| `UnassignedLeadsWidget` | 4 | `leads.assign` / `leads` | count + the oldest five |
| `ClientsOverviewWidget` | 4 | `clients.view_any` / `clients` | total, active, portal-enabled, new in range |

---

## 9. Data isolation

Every rule is an Eloquent **global scope plus a policy check** - never a hidden form field
(`CLAUDE.md` §1.10) - and every rule has a feature test asserting the 403/404 **and** the absence of the
forbidden columns from the response body.

### 9.1 Leads and clients (staff side)

**[D-P5-8] Pipeline visibility** uses Phase 1's two existing abilities rather than a new ability or a
setting: `leads.view_any` means "the whole pipeline", `leads.view` alone means "only leads I own". The
global scope `App\Models\Scopes\LeadVisibilityScope` is applied to `Lead` for any authenticated user who
does **not** hold `leads.view_any`:

```
where (assigned_to = auth()->id() or created_by = auth()->id())
```

| Role | Exact query scoping |
|---|---|
| Super Admin | Unrestricted, still subject to module gating (a disabled `leads` / `clients` module 403s Super Admin too) |
| Admin | Unrestricted within Phase 1 §5's grants |
| Sales Executive (`leads` full, `clients` create/edit) | Holds `leads.view_any` -> the whole pipeline; `clients`: all rows, financial figures withheld without `clients.view_financial` |
| Digital Marketer (`leads`) | As granted; if the role is given `leads.view` without `view_any`, the scope above applies automatically - no code change |
| Project Manager (`clients` read, `leads` read) | `leads`: scope applies unless `view_any` is granted; `clients`: read-only, no financial figures, no portal management, no documents unless `client_documents.*` is granted |
| Accountant | `clients` read + `clients.view_financial`; `leads` not granted -> 403 on every lead route |
| Receptionist / Support Agent | No `leads`/`clients` grant in Phase 1 -> 403. If `leads.create` + `leads.view` are later granted, they see only their own captures |
| Teacher / Student / Collaborator roles | 403 on every route in this phase - they are not on the `admin` panel (`panel:admin` denies first) |
| Collaborator (business sense) | Gets **no** lead or client screen in this phase. §36's "referrals / leads" cards are counted from `collaborator_referrals` by Phase 12 and are not CRM screens. Any future collaborator-facing lead list must scope by an `active` referral row |
| Branch (D11) | `leads` and `clients` carry **no `branch_id`**: §18/§19 do not ask for one and D11 scopes branch readiness to institute tables. See §12 Q4 |

`LeadActivity`, `LeadFollowUp`, `LeadConversion`, `LeadImportRow` inherit isolation through their parent:
each policy method resolves `$model->lead` and delegates to `LeadPolicy::view()`, so a user who cannot see
a lead cannot see its timeline, follow-ups, conversion or import rows by guessing an id - and gets **404**,
not 403, so ids cannot be probed. `LeadImport` is visible to `created_by = auth()->id()` unless the user
holds `leads.view_any`.

`ClientDocument` is visible to staff holding `client_documents.view_any`; `visible_to_client` has **no
effect on staff** (it is purely the portal gate).

### 9.2 Client panel - the exact rule per screen

`$C = ClientContext::clientId()`, resolved from the session, never from the request. Every query below is
additionally wrapped by the section's `module()` gate and its `client_portal.*` permission.

| Screen | Exact scoping rule | Policy method that enforces it |
|---|---|---|
| Dashboard | every card is a count produced by the rules below; a card whose section is unregistered is absent | `ClientPortalPolicy::section(User, key)` |
| Profile | `clients.id = $C`; the writable set is the whitelist of §6.9 | `ClientPolicy::viewOwn`, `ClientPolicy::updateOwnProfile` |
| Projects (list) | `projects.client_id = $C` and `projects.deleted_at IS NULL`; explicit column list that omits `budget`, internal notes and cost fields | `ProjectPolicy::viewByClient` |
| Project detail | `projects.id = {project} AND projects.client_id = $C`, else **404** | `ProjectPolicy::viewByClient` |
| Tasks | `tasks.project_id IN (select id from projects where client_id = $C) AND tasks.is_client_visible = 1`; omits `estimated_hours`, `actual_hours` and internal comments | `TaskPolicy::viewByClient` |
| Milestones | `project_milestones.project_id IN (own projects)`; `amount` selected only when the user holds `client_portal.invoices` | `ProjectMilestonePolicy::viewByClient` |
| Progress | derived from the same own-project set; no extra table | `ProjectPolicy::viewByClient` |
| Files | **`attachments.visibility = 'client'`** (`AttachmentVisibility::Client`, phase-06 §2.9 - the `files` module slug governs the `attachments` table and **no `files` table is ever created**: F-2.6, F-2.8) `AND attachments.deleted_at IS NULL AND` the `attachable` resolves to one of the client's own projects / tasks / milestones / tickets; the download route re-checks the same predicate before streaming | `AttachmentPolicy::downloadByClient` |
| Documents | `client_documents.client_id = $C AND visible_to_client = 1 AND deleted_at IS NULL` | `ClientDocumentPolicy::downloadAsClient` |
| Invoices | `invoices.client_id = $C AND invoices.status <> 'draft'`; a cancelled invoice is shown as cancelled, never hidden | `InvoicePolicy::viewByClient` |
| Payments | `project_payments.client_id = $C`, with an explicit column list that **omits `collaborator_id`, `collaborator_referral_id`, `commission_state`, `commission_skip_reason`, `commission_skip_detail`** - the spine's §9 rule, restated unchanged | `ProjectPaymentPolicy::viewByClient` |
| Meetings | `meetings.client_id = $C OR EXISTS (meeting_participants where user_id = auth()->id())` | `MeetingPolicy::viewByClient` |
| Tickets | `support_tickets.client_id = $C` - every portal contact of the company sees the company's tickets (§12 Q3) | `SupportTicketPolicy::viewByClient` |
| Messages | `conversation_participants.user_id = auth()->id()` - **never** `client_id`; a conversation is personal, not corporate | `ConversationPolicy::viewByParticipant` |
| Notifications | `notifications.notifiable_type = User::class AND notifiable_id = auth()->id()`; mark-read re-asserts ownership | `NotificationPolicy::own` |

Cross-cutting panel rules, each with its own test: route-model binding for every `{project}`,
`{invoice}`, `{ticket}`, `{document}`, `{file}` asserts ownership in the policy and returns **404** for
someone else's id; `EnsureClientContext` re-evaluates `portal_enabled`, `clients.status` and
`crm.client_portal_enabled` on **every request**, so revocation is immediate; a client who is also a
`client_contacts.user_id` of a second client resolves to exactly one context (primary binding wins) and
the two UNIQUE indexes plus an explicit service check prevent the ambiguity being created at all.

### 9.3 Policy catalogue

| Policy | Methods |
|---|---|
| `LeadPolicy` | `viewAny`, `view`, `create`, `update`, `delete`, `restore`, `forceDelete`, `changeStatus`, `assign`, `convert` (`leads.edit` **and** `clients.create`), `import`, `export`, `print`, `logActivity`, `scheduleFollowUp` |
| `LeadActivityPolicy` | `view`, `update` (false when `is_system`, window-checked), `delete` (false when `is_system`) |
| `LeadFollowUpPolicy` | `view`, `complete`, `reschedule`, `cancel` (assignee, lead owner, or `leads.edit`) |
| `LeadConversionPolicy` | `view`, `supersede`; `update` and `delete` always **false** |
| `LeadImportPolicy` | `viewAny`, `view`, `create`, `run`, `cancel`, `downloadErrors` |
| `ClientPolicy` | `viewAny`, `view`, `create`, `update`, `delete`, `restore`, `forceDelete`, `changeStatus`, `assign`, `viewFinancial`, `managePortal`, `print`, `export`, `viewOwn`, `updateOwnProfile` |
| `ClientContactPolicy` | `view`, `create`, `update`, `delete`, `grantPortalAccess` |
| `ClientDocumentPolicy` | `viewAny`, `view`, `upload`, `update`, `changeVisibility`, `download`, `downloadAsClient`, `delete`, `forceDelete` |
| `ClientPortalPolicy` | `section(User, string $key)` - module enabled, permission held, section registered |

---

## 10. Events, notifications, jobs, scheduled tasks

### 10.1 Events (all dispatched through `DB::afterCommit()`)

`LeadCreated`, `LeadUpdated`, `LeadStatusChanged`, `LeadAssigned`, `LeadActivityLogged`,
`LeadFollowUpScheduled`, `LeadFollowUpCompleted`, `LeadFollowUpMissed`, `LeadDuplicateDetected`,
`LeadDuplicateLinked`, `LeadConverted`, `LeadConversionSuperseded`, `LeadImportCompleted`,
`ClientCreated`, `ClientUpdated`, `ClientStatusChanged`, `ClientPortalEnabled`, `ClientPortalDisabled`,
`ClientDocumentUploaded`, `ClientDocumentSharedWithClient`.

### 10.2 Listeners

| Listener | Listens to | Does |
|---|---|---|
| `NotifyLeadAssignee` | `LeadAssigned` | `LeadAssignedToYou` to the new assignee only |
| `RecordCapturedReferral` | `LeadCreated`, `ClientCreated` | calls `ReferralRecorder::attach()` when `isAvailable()` and a code was captured; a no-op otherwise |
| `TouchLeadActivityCaches` | `LeadActivityLogged` | `last_activity_at` / `last_contacted_at` (inside the service's transaction, not a second write) |

**No listener on the contact-inquiry event exists** (F-2.1). Phase 4 owns `contact_inquiries`, fires
`ContactInquirySubmitted` and routes it through its own `InquiryRouter`; Phase 5's only entry point is the
`CrmLeadInquiryTarget implements InquiryTarget` of §6.10, registered into that router. Both that target and
`crm:import-pending-inquiries` rely on **`uq_leads_inquiry` and the 1062** - never on a SELECT - and both
return the existing lead when the index rejects the insert (F-3.7, R7).

### 10.3 Notifications (database channel now, mail-ready - §97)

To the **assignee**: `LeadAssignedToYou`, **`LeadFollowUpDueReminder`** (the reminder the requirement asks
for: lead name, company, the scheduled time, the follow-up notes, and a deep link to the lead; channels
from `crm.follow_up_reminder_channels`), `LeadFollowUpOverdue`, `StaleLeadsDigest` (only when
`crm.stale_digest_enabled`).
To **staff**: `NewLeadFromWebsite` (to the auto-assignee, or to holders of `leads.assign` when the lead is
unassigned), `LeadConverted` (to the new client's account manager and the converter),
`LeadImportCompleted` (to the importer, with the counts and the error-report link).
To the **client**: `ClientPortalInvitation` (a signed password-set link, **never** a plaintext password),
`ClientDocumentShared`.

### 10.4 Queued jobs

| Job | Key properties |
|---|---|
| `ProcessLeadImportChunk` | `ShouldBeUnique` (`lead-import-chunk:{import}:{offset}`, `uniqueFor` 3600), `$afterCommit = true`, `tries` 3, `backoff [10,30,60]`; one transaction per row; `failed()` marks the chunk's rows `failed` with the exception class and leaves the batch resumable |
| `BuildLeadImportErrorReport` | writes the error CSV to the private disk, then notifies the importer |
| `BuildCrmExport` | used above `crm.export_max_rows`; streams through `CsvWriter` + `chunkById`, delivers by notification |
| `SendClientPortalInvitation` | mail-channel retryable, never carries a password |

### 10.5 Scheduler

| Command | Cadence | Purpose |
|---|---|---|
| `crm:follow-up-reminders` | every 5 minutes | `LeadFollowUpService::dueReminders()` (locked, `skipLocked`, max 200), stamps `reminder_sent_at` inside the transaction and queues the notification `afterCommit`, so a crash can never double-send |
| `crm:follow-ups-mark-missed` | hourly | `pending` rows whose `scheduled_at` is older than `crm.follow_up_overdue_grace_minutes` -> `missed`, one activity row, one `LeadFollowUpOverdue` per follow-up |
| `crm:record-captured-referrals` | every 15 minutes | leads and clients with `referral_code_captured` and `referral_recorded_at IS NULL`: resolve the code through `ReferralRecorder` and attach. A **no-op while the recorder is unavailable**, so nothing is lost between Phase 5 and Phase 9/10 |
| `crm:import-pending-inquiries` | every 10 minutes | the **safety net** for Phase 4's router: converts `contact_inquiries` received before this phase existed, and any whose routing attempt failed, into leads. Duplicate-proof by `uq_leads_inquiry` + the 1062, not by a SELECT (F-3.7) |
| `crm:prune-imports` | daily 02:30 | deletes staged CSVs older than `crm.import_file_retention_days` and `lead_import_rows` older than `crm.import_row_retention_days`; never deletes a `leads` row |
| `crm:stale-lead-digest` | daily 09:15 | only when `crm.stale_digest_enabled`; one digest per assignee |

---

## 11. Acceptance tests

`tests/Feature/Crm/`. Phase 5 is not done until every numbered test passes.

**Schema and install**

1. `migrate:fresh --seed` runs clean with the nine tables; every Phase 5 migration rolls back cleanly; running `add_crm_deferred_foreign_keys` twice is idempotent.
2. The deferred FK migration is a no-op when `projects`, `collaborator_referrals`, `collaborator_referral_visits`, `services` and `contact_inquiries` are absent, and creates every FK once those tables exist.
3. The three generated columns exist and are `STORED`: `lead_follow_ups.open_guard`, `client_contacts.primary_guard`, `lead_conversions.active_guard`; a migration that cannot create one fails loudly rather than skipping it.

**Numbering and immutability**

4. Two concurrent `LeadService::create()` calls produce two distinct `lead_no` values and no gap-free assumption is violated; the same for `client_code` (assert with parallel transactions).
5. Editing `lead_no` or `client_code` throws; the DB row is unchanged.
6. `client_code` of a soft-deleted client is never re-issued to a new client.

**Leads CRUD, normalization, visibility**

7. Creating a lead fills the three `*_normalized` columns; `+92 300 123 4567`, `03001234567` and `0300-1234567` all normalize to the same key.
8. A user with `leads.view` but not `leads.view_any` sees only leads they own or created on the index, the board, the export and the follow-up worklist; the board counts and sums change accordingly (the same assertion on both queries).
9. A user with `leads.view` hitting another user's lead by id gets **404**, and its timeline, follow-ups and conversion routes also 404.
10. A user without `leads.view` gets 403 on every lead route; a user without `leads.create` gets 403 on store **and** nothing is written.
11. Disabling the `leads` module 403s every lead route for Super Admin too, hides the sidebar entry, and leaves every row intact (row counts identical before and after disable + re-enable).

**Status transitions and Kanban**

12. Every pair in §2.11 is accepted; a pair outside it returns **422** carrying the allowed list and writes nothing.
13. Moving to `lost` without a reason returns 422; with a reason it stamps `lost_reason` / `lost_at` and writes one `status_changed` activity row.
14. A board move whose `expected_from_status` is stale returns **409** with the current status; the lead's status is unchanged and no activity row is written.
15. A successful board move returns refreshed `{count, value_sum, with_budget}` for exactly the two affected columns, and the sums match a fresh `board()` call to the paisa.
16. Column sums ignore NULL budgets but the counts include them; `with_budget` reports the difference; a lead of `999999999999.99` sums without precision loss and the response string equals the DB `SUM` string (no float anywhere).
17. The board renders in a bounded number of queries independent of the number of columns and cards (assert with `DB::listen`): two for the data plus the permission/module lookups.
18. `leads.change_status` is required for the move route; without it the drag endpoint 403s and the status is unchanged.
19. Reopening a `won` lead that has a live conversion is refused; superseding the conversion first makes it possible.
20. `crm.require_follow_up_on_contacted` on refuses a move to `contacted` without a follow-up payload and without an existing open follow-up.

**Duplicate detection**

21. An exact phone match, an exact email match and a cross-field phone-vs-WhatsApp match are each reported with the right `LeadDuplicateMatchType`.
22. With `crm.duplicate_block_on_exact` on, storing without `confirm_duplicate` returns 422 and writes nothing; with the flag on and `confirm_duplicate` true the lead is created.
23. A match the actor may not see is returned as `restricted` with no name, no contact, no id and no link (assert the exact JSON keys).
24. `crm.duplicate_check_clients` on also matches `clients` and `client_contacts`; off does not.
25. A trashed lead is reported with `is_trashed = true` and never blocks a save.

**Follow-ups and reminders**

26. Scheduling a second follow-up while one is `pending` fails with `FollowUpAlreadyOpenException` (a 1062 surfaced as a domain error, never a 500); the DB still holds exactly one open row.
27. `leads.follow_up_at` always equals the open follow-up's `scheduled_at`, and is null when none is open - asserted after schedule, complete, complete-with-next, reschedule and cancel.
28. `crm:follow-up-reminders` sends exactly one `LeadFollowUpDueReminder` per due follow-up, stamps `reminder_sent_at`, and a second immediate run sends nothing.
29. Two overlapping reminder runs (simulated) do not double-send; `skipLocked` is proven by asserting a single notification.
30. `crm:follow-ups-mark-missed` moves only rows past the grace window, writes one `follow_up_missed` activity row each, and notifies the assignee once.
31. Completing a follow-up requires an outcome (422 without), stamps `last_contacted_at`, and `complete` with a `next` payload creates the successor in the same transaction (assert both rows or neither).

**Timeline**

32. A system activity row cannot be edited or deleted through the routes or the service (403 / exception) and remains in the DB.
33. An author can edit their own note inside `crm.activity_edit_window_minutes` and not after; a user with `leads.edit` can edit another's note; both write an `activity_log` entry with old and new values.

**Bulk operations**

34. `bulkAssign` with 3 ids the actor may see and 2 they may not assigns 3, reports 2 as `forbidden`, and writes exactly 3 activity rows plus one summary log entry.
35. `bulkChangeStatus` where one id's transition is illegal moves the legal rows, reports the illegal one with its reason, and leaves it untouched - no partial silent write, no 500.
36. A bulk call above `crm.bulk_max_ids` is rejected with 422 before any row is locked.

**Import**

37. A valid CSV of 50 rows creates 50 leads, each with `lead_import_id` and an `imported` activity row, and the four counters sum to `total_rows`.
38. A file whose content is PHP but whose extension is `.csv` is refused; a file above `crm.import_max_rows` is refused; both with nothing written.
39. Re-running a chunk after a simulated lost ack creates no duplicate lead (the `uq_lir_row` guard) and the counters do not double.
40. One invalid row does not roll back the valid rows in its chunk; the invalid row appears in `lead_import_rows` with field-level errors and in the error CSV with its row number.
41. `duplicate_strategy = skip` skips matches, `import_and_flag` creates them with `duplicate_of_lead_id` set, `update_existing` updates only the supplied fields and never `status`, `assigned_to` or `budget_amount`.
42. Cancelling a running import stops further chunks and keeps already-created leads, and the response says so.

**Conversion**

43. Converting a `won` lead creates a client with a generated `client_code`, sets `leads.client_id` / `converted_at` / `converted_by`, writes one `lead_conversions` row whose `lead_snapshot` matches the lead at that instant, and **does not delete the lead**.
44. Converting a lead that is not `won` is refused; with `promoteToWon` and `leads.change_status` it succeeds and the status move and the conversion are in one transaction (assert both or neither).
45. A double-submitted convert request produces exactly one client and one conversion row (`uq_lc_lead_active`), and the second call returns `created: false`.
46. Editing the lead or the client after conversion does not change `lead_snapshot`.
47. `convert` requires `leads.edit` **and** `clients.create`: missing either returns 403 and writes nothing; the project hand-off additionally requires `projects.create` and is absent from the form when `ProjectCreator::isAvailable()` is false.
48. Linking an existing client instead of creating one sets `created_client = false` and `matched_by`, and never mutates the chosen client's `client_code`.
49. Superseding a conversion stamps `superseded_at` + reason, releases `active_guard` so a new conversion is possible, and never deletes the original row (`delete` throws).

**Referral attribution (coordination with the spine)**

50. A lead created with `?ref=COL-1024` stores `referral_code_captured` even when `ReferralRecorder` is the null implementation, and `referral_recorded_at` stays null.
51. With the recorder bound, creating that lead writes one `collaborator_referrals` row with `subject_type = lead`, `commission_for = null`, the snapshotted code, and stamps `referral_recorded_at`.
52. `crm:record-captured-referrals` attaches captured codes retroactively, is a no-op on a second run, and a no-op while the recorder is unavailable.
53. Converting a referred lead copies the attribution to the client (`subject_type = client`) and records `lead_conversions.collaborator_referral_id` and `referral_code`; with the project hand-off on, the payload handed to `ProjectCreator` carries `collaborator_id` and `referral_code`.
54. **No commission row is created anywhere by anything in this phase** - creating, converting or importing a referred lead or client writes zero rows to `collaborator_commission_ledger_entries` (asserted directly, guarding INV-1).
55. Neither `leads` nor `clients` has a `collaborator_id` column (schema assertion for **[D-P5-6]**).

**Clients**

56. Tax percentages outside 0-100 are rejected by the Form Request and by the CHECK constraint.
57. `billing_same_as_address = true` nulls `billing_address` on save.
58. `clients.view_financial` withheld removes the outstanding column from the index HTML and 403s `admin.clients.financials`; the figures are absent from the response body, not merely hidden by CSS.
59. `financialSummary()` returns an explicit `unavailable` snapshot when the invoice / payment capabilities are unbound, and the UI renders "-" rather than `0.00`.
60. Setting a second primary contact demotes the first in one transaction; `uq_cc_primary` is never violated; deleting the only primary of a multi-contact client is refused.
61. A user already bound to another client (or contact) cannot be bound again: the service refuses and the UNIQUE index backs it.
62. `enablePortal` creates the user with the `Client` role, `must_change_password`, and sends an invitation containing **no password** (assert the rendered mail has no password field); a second call does not create a second user.
63. `disablePortal` and a status whose `canUsePortal()` is false each make the next `/client` request 403 without a re-login.
64. Soft-deleting a client with an undeleted project / invoice / payment is refused; soft delete disables the portal first.

**Client documents**

65. An upload lands on the **private** disk, is not reachable at any public URL (assert a direct storage URL 404s), and has a hashed filename.
66. A `.php`, a double-extension `invoice.pdf.php`, an `.svg` and an oversized file are each refused; a PDF renamed to `.docx` is caught by the content sniff.
67. A client can download only `visible_to_client = 1` documents of their own client; another client's document id returns **404**; a non-visible document of their own client returns 403/404 and is absent from the list.
68. Toggling `visible_to_client` on stamps `shared_at` / `shared_by`, notifies the client's portal contacts, and writes an activity entry; toggling off removes it from the panel immediately.
69. Every download (staff or client) writes an `activity_log` row naming the actor and whether the actor was the client.
70. `client_documents.download` withheld 403s the download route while the list still renders (proving the two permissions are independent).

**Client panel isolation (§112)**

71. Client B's user gets **404** on Client A's project, invoice, payment, ticket, document and file ids - one test per resource.
72. Every panel list for Client B contains zero rows belonging to Client A, asserted by seeding both clients with identical-looking data.
73. The payments screen's HTML and JSON contain no `collaborator`, `commission_state` or `commission_skip_reason` text for any row (the spine's §9 rule).
74. The tasks screen excludes tasks with `is_client_visible = 0` and never renders `estimated_hours` / `actual_hours`.
75. The projects screen renders `project_value` and never the internal `budget`.
76. Milestone `amount` appears only when `client_portal.invoices` is held.
77. The messages screen lists only conversations where the signed-in **user** is a participant, not every conversation of the company.
78. `crm.client_portal_enabled = false` 403s every `/client` route while `/admin` is unaffected.
79. A staff user hitting `/client/*` gets 403 (`panel:client`); a client hitting any `/admin/*` route gets 403; a client hitting `/collaborator`, `/student`, `/teacher` gets 403.
80. A section that is not registered 404s its route and renders no nav item; a registered section whose module is disabled also 404s and disappears from the nav.
81. A client cannot change `client_code`, `status`, `portal_enabled`, `account_manager_id`, any tax field, `payment_terms_days`, `currency` or `notes` through the profile route - each attempt is dropped and the DB value is unchanged.
82. The client panel dashboard issues a bounded number of queries with all sections registered (assert with `DB::listen`) - no N+1 across sections.

**Exports, settings, audit**

83. A CSV export cell beginning with `=`, `+`, `-` or `@` is prefixed with a quote (formula-injection test), and the export honours the §9 scope (a restricted user's export contains only their own leads).
84. An export above `crm.export_max_rows` queues the job and returns the "we will notify you" response instead of streaming.
85. Every `crm` setting is defined in `SettingsRegistry`, seeded, validated by `rulesFor('crm')` (a negative `bulk_max_ids`, an unknown `InquirySource` and an unknown status in `lead_statuses_on_board` are rejected), and changing `crm.whatsapp_link_template` changes every rendered WhatsApp link.
86. Changing a lead's assignee, a lead's status, a client's status, a client's tax number or a document's visibility each writes an `activity_log` row with old and new values, the actor, the IP and - where the act is discretionary - the reason.

---

## 12. Risks and open questions

### 12.1 Risks accepted, with the mitigation

| # | Risk | Mitigation |
|---|---|---|
| R-1 | Phase 5 ships before the six phases whose data its panel shows, so a naive build would either block or fake the screens | **[D-P5-1]**: a section registry plus two capability contracts; an unregistered section 404s and is absent from the nav, and `financialSummary()` reports `unavailable` rather than `0.00`. Tests 59, 80 enforce it |
| R-2 | Optimistic Kanban UI can drift from the server after a concurrent change by a colleague | Compare-and-swap on `expected_from_status` (409), and every response - success or failure - carries the authoritative figures for the two affected columns, which the client overwrites rather than adjusts. Tests 14, 15 |
| R-3 | Native HTML5 drag-and-drop is unusable on touch and with a keyboard | Every card also carries a "Move to" dropdown posting the identical endpoint, plus an `aria-live` region. Accepted in preference to adding a JS dependency for one screen |
| R-4 | Duplicate detection across `leads`, `clients` and `client_contacts` could leak another rep's pipeline | `restricted` matches carry no identifying data. Test 23 |
| R-5 | Phone normalization is heuristic; two different people can share a number (a family, an office line) | Duplicates are warned, never blocked (**[D-P5-5]**); `crm.duplicate_block_on_exact` is opt-in and off by default |
| R-6 | A CSV import can create thousands of rows and thousands of notifications | Row cap, chunked jobs, per-row transactions, one completion notification, pruning command. Tests 37-42 |
| R-7 | Client documents are the most sensitive artefact this phase stores | Private disk, sniffed MIME, extension whitelist, policy-checked streaming, `visible_to_client` as the single exposure gate, every download logged. Tests 65-70 |
| R-8 | Two counters (`lead_number_next_number`, `client_code_next_number`) serialise writes on their `settings` rows | Both are low-frequency documents; the lock is held for the length of one insert, and the UNIQUE index is the backstop. Same trade-off the spine accepted for its document numbers |
| R-9 | `lead_activities` grows without bound on a busy pipeline | Indexed only on the feed query; no retention rule is imposed because a sales history is the asset. Revisit in Phase 24 if volume demands partitioning |

### 12.2 Open questions for the client (defaults assumed, nothing blocked)

| # | Question | Default assumed |
|---|---|---|
| Q1 | ~~`Modules::permissionModuleMap()` and the four `*_portal` prefixes~~ - **ANSWERED, no longer open (D20, F-12.1).** | `permissionModuleMap()` returns **null** when the permission's prefix is not a registered module slug, and `Gate::before` step 1 **falls through on null** - a null means "not module-gated", never "disabled". `client_portal.*`, `student_portal.*`, `teacher_portal.*` and `collaborator_portal.*` are **permission namespaces, not modules**, and no module row is created for them. Phase 5's assumption is therefore now the specified behaviour (phase-01 §3 / §6); the code change belongs to the Phase 1 remediation team, and test 79 plus Phase 1's own four-panel test prove it |
| Q2 | Should sales reps see each other's leads? | **Yes** for any role granted `leads.view_any` (Phase 1 gives Sales Executive "leads full"); a role granted only `leads.view` sees its own (**[D-P5-8]**). No setting is introduced; changing the answer is a permission grant, not a code change |
| Q3 | Should every portal contact of a company see all of that company's support tickets? | **Yes** - `support_tickets.client_id = $C`. Companies expect shared visibility. If per-contact privacy is wanted, Phase 22 adds `support_tickets.is_private_to_creator` and the rule becomes `client_id = $C AND (is_private_to_creator = 0 OR user_id = auth id)` |
| Q4 | Are leads and clients ever branch-specific? | **No** `branch_id` on either table - D11 scopes branch readiness to institute tables and §18/§19 ask for none. Adding it later is one nullable column plus one scope |
| Q5 | Is a lead's "budget" the figure the pipeline should be valued at, or should there be a separate expected deal value? | **Budget is the only money field** (§18 lists one); the board sums it and states how many leads have one. A separate `expected_value` is a one-column addition if the client asks |
| Q6 | Conversion requires the lead to be `won`. Should converting from `proposal_sent` be allowed directly? | **No** - the wizard offers "mark as won and convert" in one transaction instead, so the pipeline statistics stay honest |
| Q7 | May a client upload a document or raise a ticket from the panel? | **Not in this phase.** The panel is read-only apart from profile, notification read and downloads (**[D-P5-11]**); ticket and message writes arrive with Phase 22 |
| Q8 | Should an accidental conversion be reversible? | **No delete, ever.** A conversion is superseded with a reason and the client is soft-deleted separately if it was created in error. Test 49 |

---

## 13. Requests to other phases

### 13.1 Columns and behaviour

| Request | Why |
|---|---|
| `contact_inquiries.id`, the `ContactInquirySubmitted` event and the `InquiryRouter` + `InquiryTarget` interface - **Phase 4** (the owner of the table, F-2.1) | §17 routes software inquiries to CRM. Phase 5 registers `CrmLeadInquiryTarget` into the router (§6.10) and subscribes to **no** event; `leads.contact_inquiry_id` + `uq_leads_inquiry` is the idempotency key. Phase 4 must **not** write `leads` directly |
| The guarded FK promotion for `leads.referral_visit_id` -> `collaborator_referral_visits.id` `nullOnDelete`, and a prune guard that never prunes a visit `leads.referral_visit_id` or `collaborator_referrals.referral_visit_id` points at - **Phase 9** | F-3.5; the click evidence behind `referral_code_captured` must outlive the retention sweep (INV-R6) |
| `services.id` - **Phase 4** | `leads.service_id` (§18 "interested service") |
| `projects.lead_id` bigint nullable FK `leads.id` nullOnDelete, and `projects.client_id` not null - **Phase 6** | the lead-to-project hand-off link the conversion audit points at |
| `projects.project_value` exposed to the client panel and `projects.budget` **never** exposed - **Phase 6** | §19 vs §20: the client sees the contract value, not the internal cost. Test 75 |
| `tasks.is_client_visible` boolean default **true** - **Phase 6** · **satisfied** (phase-06 §2.5, F-3.1) | §19 lists tasks on the client panel; without the flag every internal task leaks. Default true keeps the panel useful from day one. The client rule is the **AND** of `projects.client_can_see_tasks = 1` and `tasks.is_client_visible = 1`; test 74 stands |
| `project_milestones.amount` - **Phase 6** (already requested by the spine §13.1) | the client panel shows it only with `client_portal.invoices` |
| `App\Contracts\Projects\ProjectCreator` implemented and bound, with `createFromLead(LeadConversion, ProjectDraftData): int` accepting and honouring `collaborator_id` + `referral_code` - **Phase 6** | **[D-P5-1]**; and it is the point at which referral attribution reaches the table that will earn commission (§45) |
| A `ClientPortalSection` registered for `projects`, `tasks`, `milestones`, `progress` and `files` with the exact queries of §9.2, **appended behind Phase 5's route names and never redeclaring one** - **Phase 6** | the panel renders nothing it does not own; D31 / F-6.2 |
| `App\Contracts\Referrals\ReferralRecorder` implemented over `ReferralService` and bound - **Phase 9/10** | **[D-P5-6]**; `crm:record-captured-referrals` then backfills every code captured before the binding existed |
| ~~`ReferralService::copyAttribution()`~~ - **withdrawn (F-4.5)** | **No new spine method.** §6.4 step (7) calls the published `attach($client, $collaborator, ReferralSource::ManualSelection, $code, $leadReferralDate, $ctx)` with the lead's referral date and records `lead_conversions.collaborator_referral_id`. Test 53 is unchanged - it asserts the outcome, not the method name |
| ~~`ReferralSource::lead_conversion`~~ - **withdrawn (F-4.5)** | the spine's five cases stand (`referral_link`, `manual_selection`, `admission_form`, `import`, `api`); a carried-over attribution is `manual_selection` with the conversion reference in `notes` - the fallback this row already named |
| **Do not re-create** `App\Services\Finance\DocumentNumberService` - **phases 6, 7, 8-9, 10 and 14-17** | **[D-P5-3] / D27** (F-4.1, F-4.12): Phase 5 ships it at the spine's path with the spine's signature **plus `reserve()`**; every other phase reuses it, passes its own pad, and adds only its own prefix/counter settings keys. `ProjectNumberService` (phase-06 §6.1) and `StudentNumberService` (phase-14-17 §6.5) delegate to it from day one - there is no local `FOR UPDATE` fallback anywhere |
| `project_payments.client_id` and the client-panel column omission list - **Phase 10** (already in its §9) | §9.2 restates it unchanged; test 73 enforces it |
| `invoices.client_id`, and an `InvoiceReadModel` + `ClientPortalSection` for invoices - **Phase 13** | `clients.financialSummary()` and the panel's invoice screen must never re-implement an invoice sum |
| `support_tickets.client_id` nullable FK, `meetings.client_id` nullable FK, and `ClientPortalSection` registrations for tickets, meetings and messages - **Phase 22** | §93-§96 |
| ~~`files.is_client_visible`~~ - **withdrawn (F-2.6, F-2.8); no ask to Phase 22** | there is no `files` table. The `files` **module slug** governs phase-06's **`attachments`** table, and `attachments.visibility` (`AttachmentVisibility`: `internal` / `team` / `client`, default `team` - already shipped by phase-06 §2.9) is the **only** client-visibility mechanism, so §9.2's Files rule is satisfied on the day Phase 6 ships. Phase 19-23 extends the morph map only |
| The client write routes (create ticket, send message) appended at the `// Phase 22: client writes` marker in `routes/client.php` - **Phase 22** | **[D-P5-11]** |
| `leads` and `clients` indexed in global search, and every CRM report calling `LeadBoardService` / `ClientService::financialSummary()` rather than re-summing - **Phase 23** | §108, and the same "one definition of a figure" discipline the spine imposes (INV-26) |

### 13.2 Support classes

| Request | Why |
|---|---|
| `App\Support\Money::sum(array): string` - **Phase 1** (also requested by the spine §13.2) | the board and the pipeline widget total `decimal(15,2)` strings; no PHP `+` on money |
| `App\Support\PermissionRegistry` - **Phase 1**: the `client_documents` module of §4.1, the ability additions of §4.2, the `client_portal.*` set of §4.3 | the registry is the only place permission names exist (D4) |
| `App\Support\SettingsRegistry` - **Phase 2**: the new `crm` group of §5 | settings definitions in code, values in the DB |
| `App\Support\DashboardRegistry` - **Phase 2**: the eight widgets of §8.11 | §98 |
| Middleware alias `client.context` => `EnsureClientContext` registered in `bootstrap/app.php` - **Phase 1 pattern** | §6.9 |
| ~~A decision on Q1 (portal permission prefixes vs `permissionModuleMap()`)~~ - **answered: D20** (F-12.1) | `permissionModuleMap()` returns null for an unregistered prefix and `Gate::before` falls through on null; the four `*_portal` prefixes are permission namespaces, not modules. Specified in phase-01 §3 / §6; the code change is the Phase 1 remediation team's |

### 13.3 Documentation and log updates

| Request | Why |
|---|---|
| `DEVELOPMENT_LOG.md` §4: **D27** - `App\Services\Finance\DocumentNumberService` is the **single** numbering implementation, shipped by Phase 5; every caller passes its own pad; `reserve()` adds the period reset | **[D-P5-3]**, F-4.1, F-4.12 |
| `DEVELOPMENT_LOG.md` §4: **D28** - later-phase data reaches an earlier phase's screens only through a capability contract or a registry; an unavailable capability renders an empty state or 404s, never a fabricated number | **[D-P5-1]**, R-1 |
| `DEVELOPMENT_LOG.md` §4: **D29** - duplicate contacts are **warned**, never blocked by a database constraint; normalisation happens in `ContactNormalizer`, in PHP, into plain indexed columns | **[D-P5-5]** |
| `DEVELOPMENT_LOG.md` §4: **D30** - `leads.view_any` means the whole pipeline and `leads.view` means own records only; no new ability and no visibility setting is introduced | **[D-P5-8]** |
| `DEVELOPMENT_LOG.md` §4: **D31** - Phase 5 owns `routes/client.php` and every `client.*` route name; later phases append screens through `ClientPortalRegistry`, and **every** client route carries `client.context` | §7, F-6.2, F-12.3 |
| `DEVELOPMENT_LOG.md` §9: record Q1-Q8 of §12.2 | open questions with defaults, nothing blocked |
| `CLAUDE.md` §5: add "a referred lead or client earns nothing by itself - attribution must reach the project or the student before any money can be commissioned" | the commercial chain this phase starts and §6.4 step (8) completes |

---

## Convergence log (2026-09-12)

Applied from [`../design/resolutions.md`](../design/resolutions.md) §7 (Apply map, row `docs/phases/phase-05.md`).
Nothing else in this contract was restructured or redesigned.

| Finding | Change made |
|---|---|
| F-2.1 | §1.2 dependency row retargeted **Phase 3 -> Phase 4** (Phase 4 owns `contact_inquiries`, `ContactInquirySubmitted` and `InquiryRouter`); §2.1 `contact_inquiry_id` deferred-FK note now says Phase 4; §6.10 gains `App\Support\Inquiry\CrmLeadInquiryTarget implements InquiryTarget`; §10.2 listener `CreateLeadFromContactInquiry` **deleted** and replaced by a no-listener note; §10.5 `crm:import-pending-inquiries` restated as the safety net; §13.1 request retargeted to Phase 4. |
| F-2.6 | §9.2 Files rule now reads `attachments.visibility = 'client'` (`AttachmentVisibility::Client`, phase-06 §2.9) - the only client-visibility mechanism. |
| F-2.8 | §9.2 policy reference `FilePolicy` -> `AttachmentPolicy::downloadByClient`; §13.1 `files.is_client_visible` ask to Phase 22 **withdrawn** with the slug-to-table sentence (`files` module slug governs the `attachments` table; no `files` table is ever created). |
| F-3.2 | §13.1 `task_comments.is_client_visible` request **deleted** (clients see no task comments in this release; `CommentVisibility` gains no `client` case). |
| F-3.4 | No change: `clients.referral_code_captured` + `referral_recorded_at` stand, there is no `clients.collaborator_id`, and test 55 stands. |
| F-3.5 | §2.1 gains `referral_visit_id` unsignedBigInteger nullable + `INDEX (referral_visit_id)`, FK -> `collaborator_referral_visits.id` `nullOnDelete` **deferred to Phase 9's guarded migration**; added to [D-P5-2]'s affected list, to test 2 and to §13.1 as a Phase 9 ask with the prune guard. Still no `leads.collaborator_id`. |
| F-3.7 | §2.1 Keys gain **`UNIQUE uq_leads_inquiry(contact_inquiry_id)`**; §10.2 / §10.5 state that the inquiry target and `crm:import-pending-inquiries` both rely on the 1062 and return the existing lead - never a SELECT guard (R7). |
| F-4.1 | [D-P5-3] rewritten: Phase 5 owns `DocumentNumberService` as the earliest consumer (**D27**), `'%06d'` is only the default and **every caller passes its own pad** (6/7 `'%05d'`, 8-9 `'%04d'`); the false claim that the spine "does not list it in its §1.3 ownership table" removed; §6.10 and §13.1 name all reusing phases and forbid any second `FOR UPDATE` counter. |
| F-4.5 | §6.4 step (7) now calls the published `attach($client, $collaborator, ReferralSource::ManualSelection, $leadReferral->referral_code, $leadReferral->referral_date, $ctx)` and writes `lead_conversions.collaborator_referral_id` + `referral_code`; §13.1 `copyAttribution` request **withdrawn**, and the `ReferralSource::lead_conversion` request withdrawn with it (the spine's five cases stand). Test 53 unchanged. |
| F-4.12 | §6.10 publishes `reserve(string $counterKey, ?string $periodKey = null, ?string $periodValue = null): int` on the same class, with the period reset driven by a `settings` period row. |
| F-5.3 | §3 `LeadSource` **deleted**; `leads.source` (§2.1) and `clients.source` (§2.7) cast to `App\Enums\InquirySource` (phase-04 §3, eleven cases); `crm.default_lead_source` validates against `InquirySource`; §8.11 `LeadsBySourceWidget` and test 85 updated. |
| F-6.2 | §7 gains the **D31** ownership paragraph: Phase 5 owns `routes/client.php` and every `client.*` name, later phases append through `ClientPortalRegistry` and never redeclare; Phase 6 declares no `client.attachments.download`; `client.files.index` stays `/client/files` with `?project=`; `client.milestones.index` / `client.progress.show` stay Phase 5's although phase-06 §8.11 folds their views into `client.projects.show`; §13.1's Phase 6 section ask now names `files` and the no-redeclare rule. |
| F-9.1 | §2.4 and §2.6 now cite **D19** (the category rule in `CLAUDE.md` §3) instead of arguing the case locally; §2.4 additionally notes that D16's trigger discipline is not imported because it is not a money table. |
| F-12.1 | §12.2 **Q1 answered** (**D20**): `permissionModuleMap()` returns null for an unregistered prefix, `Gate::before` falls through on null, and the four `*_portal` prefixes are permission namespaces, not modules; §13.2's Phase 1 ask closed. |
| F-12.3 | §7's D31 paragraph states that **every** client route carries `client.context`, including rows a later phase fills in (the route table already carried it). |
| F-10.1 | §13.3 renumbered to the registry in resolutions §4: D19 -> **D28**, D20 -> **D29**, D21 -> **D30**, plus the two new rows **D27** (single numbering implementation) and **D31** (`routes/client.php` ownership + `client.context`). |
