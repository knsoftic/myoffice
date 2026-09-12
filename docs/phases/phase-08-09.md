# PHASE 8-9 CONTRACT - Collaborator management, referral codes and referral tracking

**Status: binding for phases 8 and 9.** Where this file conflicts with
[`../design/finance-commission-spine.md`](../design/finance-commission-spine.md) (the authoritative
financial design), [`phase-01.md`](phase-01.md) or [`phase-02.md`](phase-02.md), **those win** and the
conflict is recorded in §12.2 as an open question, never silently redesigned. Conventions live in
[`../../CLAUDE.md`](../../CLAUDE.md); the requirement source of truth is
[`../requirements.md`](../requirements.md) sections **33, 34, 35, 36, 37, 38, 45, 59, 60** (plus 57, 58
and 112 for isolation, 107 for audit).

Decisions are labelled **[D-P8-n]** / **[D-P9-n]** so a code review can cite them. Spine decisions are
cited as **[D-FS-n]** and spine invariants as **INV-n**.

---

## Contents

| § | Contents |
|---|---|
| 1 | Goal, dependencies, **ownership**, **invariants**, build order |
| 2 | Schema (4 new tables + 1 additive column), relationships |
| 3 | Enums |
| 4 | PermissionRegistry additions (incl. the full §59 portal set) |
| 5 | SettingsRegistry additions |
| 6 | Services |
| 7 | Routes |
| 8 | UI screens |
| 9 | Data isolation |
| 10 | Events, notifications, jobs, scheduled tasks |
| 11 | Acceptance tests |
| 12 | Risks and open questions |
| 13 | Requests to other phases |

---

## 1. Goal, dependencies, ownership, invariants

### 1.1 Goal

After these two phases the business can onboard a collaborator (§33, §34) - application, approval,
unique ID `COL-1024`, profile, collaboration type, services, skills, four statuses, a login account and
encrypted payout destinations - give that collaborator their own panel with a dashboard whose every
money figure is read from the spine's ledger and wallet (§36) and their own activity log (§60); set that
collaborator's commission rules through the spine's effective-dated rule table (§35); hand them a
referral code and referral URL (`/admission?ref=COL-1024` and the public-inquiry equivalent, §38); track
every click on those URLs; have the referral survive the whole admission or inquiry flow through a
cookie, a hidden token and server-side re-resolution with a written precedence ladder that a
receptionist's manual pick always wins (§37, §38, §67); link or re-link a student, project, client or
lead to a collaborator by hand with a mandatory reason, a stored old-and-new pair and an audit row
(§37, §45, §107); and be certain that Collaborator A can see nothing whatsoever of Collaborator B
(§112).

**This phase creates no money.** It creates the *subject* of money (the collaborator), the *evidence* of
attribution (visits), and the *screens* for rules the spine owns. Not one line here inserts a ledger
row, a wallet balance, a payment or a payout.

### 1.2 Dependencies

| Phase / document | What is needed from it |
|---|---|
| **1** | `users`, RBAC + `PermissionRegistry` + `Gate::before` module gating, `Ability`, `PanelType`, `UserStatus`, `activity_log` (+ `LogsActivityWithContext`, `Device`), `Blameable`, `Money`, `Sidebar`, `layouts/panel`, `layouts/admin`, `x-ui.*`, `routes/collaborator.php` (already grouped `auth`, `active`, `panel:collaborator`, `module:collaborators`) |
| **2** | `SettingsRegistry` + `SettingsService` (the `collaborator` group already exists), `DashboardRegistry`, `DateRange`, `Format` (`money()`, `app_date()`), `users.preferences` |
| **finance-commission-spine** | `collaborator_wallets`, `collaborator_commission_settings`, `collaborator_commission_ledger_entries`, `collaborator_payouts`, `collaborator_payout_accounts`, `collaborator_referrals`, `collaborator_payout_allocations`; `ReferralService`, `CommissionRuleService`, `CollaboratorWalletService`, `CollaboratorStatementService`; enums `ReferralSource`, `ReferralSubject`, `ReferralStatus`, `CommissionScope`, `CommissionCalculationType`, `CommissionBase`, `FixedCommissionRelease`, `CommissionRuleStatus`, `PayoutMethod`, `CommissionStatus` |
| **4** | `services` (the public-site service catalogue) - the target of the `collaborator_service` pivot (§34 "services") |
| **5** | `leads`, `clients` - nullable referral subjects; **`App\Services\Finance\DocumentNumberService`** - Phase 5 ships the single numbering implementation (D27, F-4.1); this phase reuses it and never re-creates it |
| **6** | `projects` (+ `project_members`, `tasks.assigned_collaborator_id`) - §45, §36 "assigned projects / tasks" |
| **15** | `students`, `student_admissions`, `course_inquiries` - the student referral subjects (§37, §67) |
| **22** | `meetings`, `messages`, `notifications`, `attachments` (the `files` module slug governs Phase 6's `attachments` table; there is no `files` table) - four §36 dashboard tiles and §96 |

### 1.3 Ownership - what these phases create, and what they must never create

| Owns | Must NOT create |
|---|---|
| **Phase 8**: `collaborators`, `collaborator_skills`, `collaborator_service`; `activity_log.collaborator_id`; enums §3.1; `CollaboratorService`, `CollaboratorCodeService`, `CollaboratorOnboardingService`, `CollaboratorPayoutAccountService`, `CollaboratorPortalMetricsService`, `CollaboratorActivityService`; the admin collaborator CRUD + approval queue + profile tabs; the payout-account screens; the commission-rule screen (the spine's §8.4, built here against the spine's service); the collaborator panel shell, sidebar, dashboard, profile and activity log | any of the spine's 15 tables; `ReferralService`; `CommissionRuleService`; `CollaboratorWalletService`; any balance computation (INV-26) |
| **Phase 9**: `collaborator_referral_visits`; enums §3.2; `ReferralLinkService`, `ReferralTrackingService`, `ReferralAttributionResolver`, `CaptureReferral` middleware, `<x-site.referral-field>`, the `SyncReferralSnapshot` + `MarkReferralVisitConverted` listeners; the referral-visit register and conversion report; the manual-link and change-attribution screens | `collaborator_referrals` (the table **or** direct writes to it - everything goes through `ReferralService`, spine §13.1); any ledger, entitlement or wallet write |

### 1.4 Build order [D-P8-1]

`DEVELOPMENT_LOG.md` §5 puts phases 8 and 9 **before** Phase 10, which per spine §1.3 ships the spine's
migration set. Phases 8-9 therefore need tables a later phase creates. This is resolved by order and by
existing machinery, never by duplicating a table:

1. Phase 8's migrations run first (they depend on nothing of the spine's).
2. **The spine's migration set is applied immediately afterwards, in the same release** - its timestamps
   already sort after Phase 8 ([D-FS-1]), and its `add_fks_to_*` migrations are `Schema::hasTable()`
   guarded, so the order is legal in both `migrate` and `migrate:fresh`.
3. Every Phase 8/9 surface that touches a spine table is gated by a Phase 1 **module** switch
   (`collaborator_commission_settings`, `collaborator_wallets`, `collaborator_commissions`,
   `collaborator_payouts`, `collaborator_referrals`). If the spine is not yet installed those modules
   stay disabled: `Gate::before` 403s the routes, `Sidebar` hides the items, and the dashboard registry
   drops the widgets - with no broken code and no stub tables.
4. Two idempotent backfills close the gap for records created in window 1-2:
   `php artisan collaborators:backfill-wallets` and `php artisan collaborators:seed-initial-rules`.

### 1.5 Invariants

| # | Invariant | Enforced by |
|---|---|---|
| INV-C1 | `collaborators.collaborator_code` is assigned once and **never changes**. | model `updating` hook throws `ImmutableCollaboratorCodeException`; FT-C03 |
| INV-C2 | `collaborators.referral_code` is globally unique, stored upper-case, and **immutable once any `collaborator_referrals`, `collaborator_referral_visits` or ledger row references the collaborator** (spine §13.1). | `uq_col_referral_code` + `CollaboratorCodeService::changeReferralCode()` + policy; FT-C04 |
| INV-C3 | Exactly one `collaborator_wallets` row exists per collaborator, created in the **same transaction** as the collaborator (spine §6.6 row 17). | `CollaboratorObserver::created` + `uq_cw_collaborator`; FT-C05 |
| INV-C4 | Only `status = Active` earns commission. Phase 8 never writes `commission_eligible`, a ledger row or a wallet balance to express eligibility - the spine's guard step 4 decides it per payment. | spine §6.1 step 4; FT-C12 |
| INV-C5 | A collaborator with any financial history is **never** force-deleted. Soft delete is allowed and is treated as inactive by the engine. | RESTRICT FKs from the spine + `CollaboratorPolicy::forceDelete()` returning false with the reason; FT-C13 |
| INV-C6 | `details_encrypted` on a payout account and `account_details_encrypted` on a payout **never** appear in a response body, a log line, an activity diff, an export, a mail or an exception payload. Only `account_last4` is ever rendered. | `encrypted` cast + `$hidden` + `LogsActivityWithContext` redaction + FT-C18, FT-C19 |
| INV-C7 | Every balance, earning and payout figure shown in the admin collaborator screens or the collaborator panel comes from `CollaboratorWalletService` / `CollaboratorStatementService`. No controller, widget, export or Blade file in these phases contains `SUM(` over a spine table (INV-26). | FT-C21 (static assertion) |
| INV-R1 | `collaborator_referrals` is the **only** truth of attribution. `students.collaborator_id`, `projects.collaborator_id` and every other subject snapshot column is display-only, is never read by the commission engine, and **is used by no access scope** (**D37**; spine §2.2 states this for `student_fees.collaborator_id`; it is restated here for every subject). | `SyncReferralSnapshot` is the only writer; FT-R09 |
| INV-R2 | Nothing the browser posts is trusted as a referral **code**. The browser posts a **visit token**; the server re-resolves the code from `collaborator_referral_visits` or the encrypted cookie and re-validates it. | `ReferralAttributionResolver`; FT-R05, FT-R06 |
| INV-R3 | A staff member's explicit manual selection always outranks every captured candidate, and when the two disagree the losing candidate is preserved as a **superseded** referral row with its reason (spine §2.8) - written by the spine's published `ReferralService::recordLosingCandidate()`, never by this phase (F-4.4). | `ReferralAttributionResolver` + `ReferralService::attach()` + `ReferralService::recordLosingCandidate()`; FT-R03, FT-R04 |
| INV-R4 | Changing an attribution **supersedes, never mutates**, requires `collaborator_referrals.edit` + a mandatory reason, stores old and new, writes an audit row, and **never re-points a ledger row** (spine §6.6 row 3, INV-18). | spine `ReferralService::change()`; FT-R10, FT-R11 |
| INV-R5 | At most one `active` referral per subject exists, for the life of the database. | spine `uq_cr_student_current` / `uq_cr_project_current` / `uq_cr_client_current` / `uq_cr_lead_current`; FT-R02 |
| INV-R6 | A referral visit that has converted, or that any `collaborator_referrals.referral_visit_id` **or `leads.referral_visit_id`** points at, is **never pruned**. | `PruneReferralVisits` exclusion + FT-R15 |

---

## 2. Schema

All tables InnoDB, utf8mb4. Phase 1 §1 rules apply: `timestamps` + `softDeletes` + `created_by` /
`updated_by` (nullable FK `users.id`, `nullOnDelete`) on business tables. Deviations are named and
justified.

### 2.1 `collaborators` (§33, §34)

The partner record. Separate from `users` per **D2**: a collaborator may exist before (or without) a
login. No `branch_id`: a collaborator is not branch-bound (spine §9, last-but-one row), which is the
deliberate exception to **D11**. **[D-P8-2]**

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `collaborator_code` | string(32) | not null | §34 "Collaborator ID", §38 `COL-1024`. `collaborator.collaborator_code_prefix` + counter via **Phase 5's** `App\Services\Finance\DocumentNumberService::next(..., '%04d')` - the pad is passed explicitly by this caller (D27, F-4.1) - assigned in-transaction. Immutable (INV-C1) |
| `referral_code` | string(32) | not null | §38. Initialised **equal to** `collaborator_code`; stored upper-case; `[A-Z0-9][A-Z0-9-]{3,31}`. Immutable once referenced (INV-C2). Snapshotted into `collaborator_referrals.referral_code` by the spine |
| `user_id` | FK `users.id` | nullable | `nullOnDelete`, **UNIQUE**. The login account, created at approval |
| `name` | string(150) | not null | person or contact name |
| `company_name` | string(150) | nullable | §34 |
| `photo_path` | string(255) | nullable | `public` disk, image MIME validated, max 2 MB |
| `email` | string(150) | nullable | **UNIQUE** (MariaDB allows many NULLs). Asserted equal to `users.email` while `user_id` is set |
| `phone` | string(32) | nullable | |
| `whatsapp` | string(32) | nullable | §34 |
| `country` | string(100) | nullable | §34 |
| `address` | string(255) | nullable | §34 |
| `collaboration_type` | string(32) | not null | cast `CollaborationType` (§33) |
| `joining_date` | date | nullable | §34 |
| `status` | string(32) | `pending` | cast `CollaboratorStatus` - §34's four states exactly |
| `status_reason` | string(255) | nullable | mandatory when moving to `inactive` or `suspended` |
| `status_changed_at` | timestamp | nullable | |
| `status_changed_by` | FK `users.id` | nullable | `nullOnDelete` |
| `applied_at` | timestamp | nullable | when the `pending` record was created |
| `approved_at` | timestamp | nullable | onboarding |
| `approved_by` | FK `users.id` | nullable | `nullOnDelete` |
| `notes` | text | nullable | internal, never shown in the panel |
| `created_at` / `updated_at` | timestamps | nullable | |
| `deleted_at` | timestamp | nullable | softDeletes. `forceDelete` blocked by policy (INV-C5) |
| `created_by` / `updated_by` | FK `users.id` | nullable | `nullOnDelete`, Blameable |

**Keys.** `UNIQUE uq_col_code(collaborator_code)` - the ID is quoted in commission disputes, and two
concurrent creations cannot share it (the loser retries with the next counter value).
`UNIQUE uq_col_referral_code(referral_code)` - §38 "unique referral code"; it is also the public lookup
key, so a duplicate would make attribution ambiguous. `UNIQUE uq_col_user(user_id)` - one panel account
per collaborator. `UNIQUE uq_col_email(email)`.
`INDEX (status, collaboration_type)` the index screen's two default filters; `INDEX (joining_date)`;
`INDEX (company_name)`; `INDEX (created_at)` the pending-application queue; `INDEX (deleted_at)`.
**No CHECK constraints** - every rule here (code format, reason-on-suspend) is a Form Request rule plus
a model hook, because a regex CHECK would block the seeder and the import path without adding a
guarantee the service does not already give.

**Relationships.**
`belongsTo` `User` as `account` (`user_id`), `statusChanger`, `approver`, `creator`, `editor`;
`hasMany` `CollaboratorSkill` (`collaborator_id`, cascade);
`belongsToMany` `Service` **through the pivot `collaborator_service`**;
`hasMany` `CollaboratorReferralVisit`;
`hasOne` `CollaboratorWallet` (spine) · `hasMany` `CollaboratorCommissionSetting`,
`CollaboratorCommissionEntitlement`, `CollaboratorCommissionLedgerEntry`, `CollaboratorPayout`,
`CollaboratorPayoutAccount`, `CollaboratorPayoutAllocation`, `CollaboratorWalletReconciliation`,
`CollaboratorReferral` (all spine tables, all RESTRICT back to here);
`belongsToMany` `Student` as `referredStudents` **through the pivot `collaborator_referrals`**
(`wherePivot('status', 'active')`), and the same shape for `referredProjects`, `referredClients`,
`referredLeads`;
`belongsToMany` `Project` as `assignedProjects` **through Phase 6's `project_members`** - there is no
`project_collaborator` table (F-2.7):
`belongsToMany(Project::class, 'project_members')->wherePivotNull('deleted_at')->wherePivotNotNull('collaborator_id')`;
`hasMany` `Task` as `assignedTasks` via `tasks.assigned_collaborator_id` (Phase 6).

### 2.2 `collaborator_skills` (§34 "skills")

A child list, not a shared taxonomy: phases 7 (employees), 13 (team) and 16 (teachers) also carry
skills, and inventing a cross-domain `skills` table here would collide with whatever Phase 7 already
built. **[D-P8-3]** - if a shared taxonomy appears later this table migrates into it additively (§13).

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `collaborator_id` | FK `collaborators.id` | not null | **`cascadeOnDelete`** - meaningless without its parent, and the parent can only ever be soft-deleted (INV-C5) |
| `name` | string(80) | not null | as typed: "Laravel", "Adobe Illustrator" |
| `slug` | string(80) | not null | `Str::slug(name)`, the filter key |
| `sort_order` | int | 0 | display order |
| `created_at` / `updated_at` | timestamps | nullable | |
| `created_by` / `updated_by` | FK `users.id` | nullable | `nullOnDelete`, Blameable |

**Keys.** `UNIQUE uq_cskill(collaborator_id, slug)` - the sync path is "replace the set", and the unique
index is what makes a double-submitted profile form idempotent instead of additive.
`INDEX (slug)` - "find every collaborator who can do React".
**No `deleted_at`** - a removed skill has no audit or financial value, and a soft-deleted row would
collide with `uq_cskill` on re-add. This is the **history-pivot** category of `CLAUDE.md` §3's
soft-delete rule, not a local exception: cite **D19** (F-9.1).
**Relationships.** `belongsTo` `Collaborator`.

### 2.3 `collaborator_service` (pivot, §34 "services")

A collaborator offers the services the company already sells, so the pivot targets Phase 4's `services`
catalogue rather than a free-text list - which is what makes "find an agency that does SEO" a join and
not a `LIKE`.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `collaborator_id` | FK `collaborators.id` | not null | `cascadeOnDelete` |
| `service_id` | FK `services.id` | not null | `cascadeOnDelete` |
| `created_at` | timestamp | nullable | no `updated_at`: the row has nothing to update |

**Keys.** `PRIMARY KEY (collaborator_id, service_id)` - the composite PK *is* the uniqueness guarantee,
so a re-submitted form cannot duplicate a service. `INDEX (service_id)` for the reverse lookup.
**No `deleted_at`** - an append-only history pivot under `CLAUDE.md` §3's category rule: cite **D19**
(F-9.1).
Created by a `Schema::hasTable('services')`-guarded migration in the spirit of [D-FS-1], so
`migrate:fresh` cannot break if Phase 4 is ever re-ordered; the guard logs loudly when it skips.
**Relationships.** the `belongsToMany` declared on both `Collaborator` and `Service`.

### 2.4 `collaborator_referral_visits` (Phase 9 - §38 click / visit tracking)

The evidence that a referral URL was used, and the carrier that lets the referral survive a multi-step
admission flow. Spine §13.1 assigns this table to Phase 9 and forbids Phase 9 from writing
`collaborator_referrals` directly. Not a financial table: it may be pruned (INV-R6 excepted) and it has
no delete trigger.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `visit_token` | char(26) | not null | ULID. **The only referral value that ever travels through the browser** (cookie value + hidden field), so a forged value can at worst name a visit that does not exist (INV-R2) |
| `collaborator_id` | FK `collaborators.id` | nullable | `restrictOnDelete` - a visit is evidence; null when the code did not resolve |
| `referral_code` | string(32) | not null | the normalised code from the URL, **kept even when invalid** so "people are using a dead code" is reportable |
| `outcome` | string(32) | `captured` | cast `ReferralVisitOutcome` |
| `outcome_detail` | string(191) | nullable | the human sentence shown in the register |
| `landing_url` | string(255) | not null | §38 `/admission?ref=COL-1024` |
| `landing_path` | string(191) | not null | path only, for per-page conversion reporting |
| `query_string` | string(255) | nullable | the rest of the query as received (campaign parameters included) - one column instead of inventing a UTM schema |
| `referer_url` | string(255) | nullable | |
| `ip_address` | string(45) | nullable | subject to `collaborator.referral_visit_retention_days` (§5, default **365**) - the client can shorten the window without a migration (F-13.12) |
| `user_agent` | text | nullable | |
| `device` / `platform` / `browser` | string(64) | nullable | from Phase 1's `App\Support\Device` |
| `is_bot` | boolean | false | set by the crawler filter; bot rows never attribute |
| `user_id` | FK `users.id` | nullable | `nullOnDelete` - who was logged in, for self-referral detection |
| `session_id` | string(255) | nullable | |
| `visits_count` | unsignedInteger | 1 | incremented when the same token hits another referral URL with the same code - one row per visitor-code pair, not per page view |
| `first_seen_at` | timestamp | not null | |
| `last_seen_at` | timestamp | not null | |
| `expires_at` | timestamp | not null | `first_seen_at + collaborator.referral_cookie_days`; an expired visit can never win attribution |
| `converted_at` | timestamp | nullable | first conversion |
| `converted_subject_type` | string(24) | nullable | cast `ReferralConversionSubject` |
| `converted_subject_id` | bigint unsigned | nullable | **deliberately no FK**: the six possible subjects live in five different phases' tables. The referentially sound link is `converted_referral_id` below, or the subject's own `referral_visit_id` |
| `converted_referral_id` | FK `collaborator_referrals.id` | nullable | `nullOnDelete`, guarded migration - the attribution row this visit produced |
| `created_at` / `updated_at` | timestamps | nullable | no blameable (the writer is an anonymous web request), **no `deleted_at`** - an append-only evidence/log table under `CLAUDE.md` §3's category rule, pruned by retention and never soft-deleted: cite **D19** (F-9.1) |

**Keys.** `UNIQUE uq_crv_token(visit_token)` - the token is the cookie's whole value, so a collision
would hand one visitor another's attribution.
`INDEX (collaborator_id, first_seen_at)` the partner's own click report; `INDEX (referral_code, first_seen_at)`;
`INDEX (outcome, first_seen_at)` the conversion funnel; `INDEX (expires_at)` the prune job;
`INDEX (converted_referral_id)`; `INDEX (landing_path, first_seen_at)`;
`INDEX (ip_address, first_seen_at)` abuse detection; `INDEX (user_id)` self-referral detection.
**CHECK** `chk_crv_dates`: `last_seen_at >= first_seen_at AND expires_at >= first_seen_at`.
**CHECK** `chk_crv_visits`: `visits_count >= 1`.
**Relationships.** `belongsTo` `Collaborator`, `User`, `CollaboratorReferral` (`convertedReferral`);
the reverse `hasMany` from `Collaborator`; `hasMany` `CollaboratorReferral` via the spine's
`referral_visit_id`.

### 2.5 Additive column on a Phase 1 table

| Migration | Change | Why |
|---|---|---|
| `add_collaborator_id_to_activity_log_table` | `activity_log.collaborator_id` bigint unsigned nullable, FK `collaborators.id` `nullOnDelete`, `INDEX (collaborator_id, created_at)` | §60 demands a per-collaborator activity log. D13 contracts **one** audit store, so §60 must be a filtered view over `activity_log` - and the filter has to be an indexed column, because the rows that matter most (commission created, commission approved, payout paid) have a **null causer** (the engine, the scheduler) and a subject the collaborator does not own. Scoping by causer alone would silently drop them |

No other column on another phase's table is written here; everything else is a request in §13.

### 2.6 Tables this phase **references but never creates**

`collaborator_wallets`, `collaborator_commission_settings`, `collaborator_commission_entitlements`,
`collaborator_commission_ledger_entries`, `collaborator_payouts`, `collaborator_payout_allocations`,
`collaborator_payout_accounts`, `collaborator_wallet_reconciliations`, `collaborator_referrals` - all
spine §2, all created by the spine's atomic migration set (§1.4).

---

## 3. Enums to add

All in `app/Enums/`, string-backed, implementing `label(): string` and `color(): string` and exposing
`static options(): array`, exactly as Phase 1 §2 requires. No spine enum is redefined.

### 3.1 Phase 8

| Enum | Cases (values) | Extra members |
|---|---|---|
| `CollaboratorStatus` | `pending`, `active`, `inactive`, `suspended` | §34's four states exactly. `canLogin(): bool` (true only for `active`), `earnsCommission(): bool` (true only for `active` - the single place the spine's guard step 4 reads), `isSelectableForNewReferral(): bool` (true for `active`; `pending`/`inactive` only with staff confirmation; `suspended` never), `requiresReason(): bool` (true for `inactive`, `suspended`) |
| `CollaborationType` | `freelancer`, `agency`, `referral_partner`, `business_partner`, `external_developer`, `external_designer`, `marketing_partner`, `consultant`, `trainer`, `sales_partner`, `other` | §33's list verbatim plus `other`. `bringsStudents(): bool` / `bringsProjects(): bool` drive nothing but the default commission-rule pre-fill at onboarding |
| `PayoutAccountStatus` | `active`, `disabled` | the spine's §2.15 `status` column is declared as the two literals with no enum named; this types it per **D9** without changing the column |
| `CollaboratorActivityEvent` | `login`, `student_referral`, `project_referral`, `task_update`, `file_upload`, `file_download`, `commission_created`, `commission_approved`, `commission_reversed`, `payout_request`, `payout_paid` | §60's eleven events verbatim. `activityEvent(): string` (the value written to `activity_log.event`), `module(): string`, `icon(): string`, **`visibleProperties(): array`** - the per-event **allowlist** of `properties` keys the collaborator's own feed may render (F-12.7); `reason` is in no event's list, and a key absent from the list is absent from the response body, not blanked. The view filters on these eleven; `activity_log` still stores everything else |

### 3.2 Phase 9

| Enum | Cases (values) | Extra members |
|---|---|---|
| `ReferralVisitOutcome` | `captured`, `invalid_code`, `collaborator_not_eligible`, `self_referral`, `bot_filtered`, `expired`, `converted`, `overridden` | groupable in the §38 funnel report, the same discipline as the spine's `CommissionSkipReason`. `attributable(): bool` (true only for `captured`) |
| `ReferralCandidateChannel` | `staff_selection`, `typed_code`, `session`, `cookie`, `hidden_field`, `query_param` | the precedence ladder of §6.3. `rank(): int` (1-6), `referralSource(): ReferralSource` maps to the spine's enum (`staff_selection` -> `manual_selection`, `typed_code` -> `admission_form`, the rest -> `referral_link`), `isBrowserSupplied(): bool` |
| `ReferralConversionSubject` | `student`, `student_admission`, `project`, `client`, `lead`, `course_inquiry`, `contact_inquiry` | what a visit turned into. Deliberately **wider** than the spine's `ReferralSubject` (which has no inquiry cases) and additive to it - the spine's enum is untouched |
| `ReferralAttributionModel` | `first_touch`, `last_touch` | which visit wins when a visitor has several (§5 setting) |

**No REST API in this release (H1, F-13.1).** There is no `routes/api.php` and no token guard in these
phases. The spine's `ReferralSource::api` case **stays** as a reserved value for a future import or API
path; it is written by nothing Phase 8/9 ships, and adding an API later needs no schema change. Every
idempotency key on this path is generated server-side per submission (spine §2.19).

---

## 4. PermissionRegistry additions

Ability presets are Phase 1 §4's: `READ`, `CRUD`, `CRUD_FULL`, `APPROVE`, `STATUS`, `ASSIGN`, `FILES`,
`MONEY`, `REPORTS`, `LOGS`. Permission name stays `{slug}.{ability}` (**D4**); the registry remains the
only place a permission name exists.

### 4.1 New module slugs (both `is_core = false`)

| slug | ModuleGroup | icon | Abilities | Why these |
|---|---|---|---|---|
| `collaborator_payout_accounts` | `Collaborator` | `credit-card` | `READ` + `create` + `edit` + `delete` + `STATUS` + `LOGS` | §55 "sensitive payout data must be protected": an encrypted bank destination deserves its own gate rather than riding on `collaborator_payouts.view`, so an Accountant who may approve payouts does not automatically get to manage destinations. `change_status` means verify / disable. **No ability anywhere reveals `details_encrypted`** (INV-C6) - there is deliberately no `view_financial` on this module, because there is nothing to unmask |
| `collaborator_referral_visits` | `Collaborator` | `cursor-arrow-rays` | `READ` + `REPORTS` + `LOGS` | §38 click tracking. Read-only by design (nobody edits a click), and separate from `collaborator_referrals` because the rows carry IP addresses and user agents - data a Sales Executive who may link referrals has no business reading |

### 4.2 Abilities added to Phase 1 slugs (additive; the registry stays the only declaration)

| slug | Abilities after this phase | Note |
|---|---|---|
| `collaborators` | `CRUD_FULL` + `restore` + `APPROVE` + `STATUS` + `MONEY` + `REPORTS` + `LOGS` | `approve` / `reject` = onboarding a `pending` application (§34); `change_status` = activate / deactivate / suspend / reinstate; `view_financial` gates **every** money column on the collaborator screens (lifetime earned, available balance, commission totals); `delete` is soft only and `forceDelete` is refused outright (INV-C5) |
| `collaborator_referrals` | spine's `READ` + `create` + `edit` + `STATUS` + `LOGS`, **plus** `REPORTS` + `export` | §38's conversion reporting and the §99 collaborator performance report. `edit` is the attribution-change ability the spine's §7.3 already uses |

### 4.3 The full §59 collaborator portal permission set

Phase 1 §4 already registers eighteen `collaborator_portal.*` permissions and the spine §4.3 adds two.
The table below is the complete set, with the §59 clause each one implements and where it is enforced -
nothing in it is invented, and nothing in §59 is missing.

| Permission | §59 clause | Enforced on |
|---|---|---|
| `collaborator_portal.dashboard` | view own dashboard | `collaborator.dashboard` route; every §36 tile additionally checks its own permission below |
| `collaborator_portal.students` | view own referred students | `collaborator.students.index` (spine §7.5) - §57 |
| `collaborator_portal.student_fee_status` | view student fee status | the *Total paid* / *Fee status* columns of the §57 list |
| `collaborator_portal.student_commission` | view own student commission | the *Commission earned* column, the student-commission tiles, `collaborator.commissions.index` student rows |
| `collaborator_portal.projects` | view own projects | `collaborator.projects.index` (spine §7.5) - §58 |
| `collaborator_portal.project_client` | view project client | the *Client* column of the §58 list |
| `collaborator_portal.project_value` | view project value | the *Project value* column + the "total project value" §36 tile |
| `collaborator_portal.project_payments` | view project payments | the *Amount received* column and the payment list on a project |
| `collaborator_portal.project_commission` | view own project commission | the *Commission rate* / *Commission earned* columns and the project-commission tiles |
| `collaborator_portal.tasks` | view assigned tasks | `collaborator.tasks.index` (Phase 6) |
| `collaborator_portal.tasks_update` | update assigned tasks | the task status / progress POST (Phase 6) |
| `collaborator_portal.files_upload` | upload files | the upload endpoint (Phase 22) - it writes an **`attachments`** row (Phase 6's table, `Collaborator` in the morph map) with `visibility` set; the `files` module slug governs that table and no `files` table exists (F-13.2, F-2.8) |
| `collaborator_portal.files_download` | download files | the download endpoint (Phase 22) - streams an `attachments` row owned by the collaborator through a controller that re-runs the permission chain (D21); never a public path |
| `collaborator_portal.comments` | add comments | the comment POST (Phase 6 / 22) |
| `collaborator_portal.meetings` | view meetings | `collaborator.meetings.index` (Phase 22) |
| `collaborator_portal.messages` | send messages | the conversation routes (Phase 22) |
| `collaborator_portal.payout_request` | request payout | `collaborator.payouts.create|store|cancel` (spine §7.5) - **gated twice**: this permission **and** `setting('collaborator.payout_request_enabled')`. Per Phase 1 §5 the Collaborator role does **not** receive it by default |
| `collaborator_portal.statement_download` | download statement | `collaborator.statement.index|export` (spine §7.5) - §56 |
| `collaborator_portal.wallet` | (spine §4.3) | `collaborator.wallet.index` - §50 |
| `collaborator_portal.payouts` | (spine §4.3) | `collaborator.payouts.index` (read-only history, no request right) |

**Added by this phase** under Phase 1 §4's explicit licence ("plus the read abilities each panel
needs"), because §3 and §36 require surfaces §59's list does not name:

| Permission | Why it exists | Granted to the Collaborator role by default |
|---|---|---|
| `collaborator_portal.profile` | §3 requires profile management for every user; Phase 1 already implies `.profile` for the other three portals | yes |
| `collaborator_portal.referrals` | §36 lists *referrals* on the dashboard; this gates the referral list and the referral-link screen (the collaborator's own code and URLs) | yes |
| `collaborator_portal.leads` | §36 lists *leads* | yes |
| `collaborator_portal.activity_log` | §60 - the collaborator's own activity log | yes |
| `collaborator_portal.notifications` | §36 lists *notifications* | yes |
| `collaborator_portal.payout_accounts` | managing one's own encrypted payout destinations is not the same right as requesting money. The spine §7.5 currently gates `collaborator.payout-accounts.*` with `payout_request`, which the Collaborator role does not hold - so by default a collaborator could not register a bank account at all. Registered here; **the spine's routes keep their existing gate until Phase 12 adopts it** (§12.2 Q2, §13) | yes |

**Role grants.** `RoleSeeder` (Phase 1 §5) is extended idempotently: the **Collaborator** role receives
every `collaborator_portal.*` above **minus `payout_request`** (Phase 1 §5, spine §4.3). **Sales
Executive** and **Receptionist** receive `collaborators.view_any` + `collaborators.view` +
`collaborator_referrals.create` (so they can pick a collaborator at admission / lead entry) and **no**
`collaborators.view_financial`. **Accountant** receives `collaborator_payout_accounts.*`.
**HR**, **Teacher**, **Student**, **Client** receive nothing from §4.1-4.2.

---

## 5. SettingsRegistry additions

All inside Phase 2's existing `collaborator` group; **no new group, and no Phase 2 or spine key is
redefined**. Phase 2 already owns `referral_system_enabled`, `automatic_commission_enabled`,
`commission_approval_mode`, `student_commission_base`, `project_commission_base`,
`default_student_commission_type|rate`, `default_project_commission_type|rate`,
`commission_on_admission_fee`, `commission_on_registration_fee`, `commission_reversal_on_refund`,
`payout_request_enabled`, `minimum_payout`, `payout_methods`; the spine §5 owns `commission_hold_days`,
`commissionable_fee_types`, `student_commission_document`, `fixed_commission_release`,
`commission_on_overpayment`, `commission_min_entry_amount`, `clawback_on_paid_commission`,
`payout_single_inflight`, `payout_auto_approve_below`, `statement_show_technical_rows`. Those thirteen
+ ten keys are **used as defined**.

| group.key | type | default | Meaning |
|---|---|---|---|
| `collaborator.collaborator_code_prefix` | text | `COL-` | §38's `COL-1024` |
| `collaborator.collaborator_code_next_number` | number | `1001` | counter, locked in-transaction by Phase 5's `DocumentNumberService`; first code is `COL-1001` |
| `collaborator.referral_code_editable` | boolean | `true` | allows a vanity code (`ACME`) while INV-C2 still holds |
| `collaborator.referral_query_param` | text | `ref` | §38's `?ref=` |
| `collaborator.referral_landing_path` | text | `/admission` | §38's student referral URL |
| `collaborator.referral_inquiry_landing_path` | text | `/contact` | the client / project-inquiry equivalent (§17 routes software inquiries to CRM) |
| `collaborator.referral_cookie_days` | number | `30` | attribution window; also `collaborator_referral_visits.expires_at` |
| `collaborator.referral_attribution_model` | select `first_touch`\|`last_touch` | `last_touch` | which visit wins when a visitor has several (§12.2 Q3) |
| `collaborator.referral_visit_tracking_enabled` | boolean | `true` | off = capture still works for attribution, no visit row is written |
| `collaborator.referral_visit_retention_days` | number | `365` | prune window; converted and referenced rows are never pruned (INV-R6) |
| `collaborator.referral_override_reason_required` | boolean | `true` | a staff pick that contradicts a captured code must carry a reason (§6.3) |
| `collaborator.referral_self_attribution_blocked` | boolean | `true` | a collaborator's own logged-in visit, or their own user as the subject, never attributes |
| `collaborator.referral_public_name_visible` | boolean | `true` | whether the public form shows "Referred by Ali Traders" after validating a code - §38 says the URL *preselects* the collaborator, so the default is to show it |
| `collaborator.pending_application_alert_days` | number | `3` | a `pending` collaborator older than this is flagged on the dashboard |
| `collaborator.payout_account_verification_required` | boolean | `true` | a payout may only target a verified account. Phase 8 owns the verification UI and the flag; **the guard itself is requested from the spine's `PayoutService`** (§13), because §6.4.2's guard list is the spine's |
| `collaborator.seed_commission_rules_on_approval` | boolean | `true` | on approval, create the collaborator's first student and project rule versions from Phase 2's `default_*_commission_type|rate` (spine §6.1.1 point 3) |

---

## 6. Services

Namespace `App\Services\Collaborator\`. Every write runs inside one `DB::transaction()`; every event is
dispatched through `DB::afterCommit()` (INV-20). No method here computes a balance (INV-C7) or inserts a
ledger, wallet, entitlement or payout row.

### 6.1 `CollaboratorCodeService`

| Method | Guarantees |
|---|---|
| `nextCollaboratorCode(): string` | **Phase 5's** `App\Services\Finance\DocumentNumberService::next('collaborator.collaborator_code_prefix', 'collaborator.collaborator_code_next_number', '%04d')` - reused, never re-created, and this caller passes its own `'%04d'` pad explicitly (D27, F-4.1) - inside the caller's transaction; `uq_col_code` is the backstop and a 1062 triggers exactly one retry. Never reuses a number, even after a soft delete |
| `normalizeReferralCode(string $code): string` | trim, strip whitespace, upper-case, collapse repeated `-`; the **one** normaliser used by the public URL, the typed form field, the admin form and the lookup - so `col-1024`, ` COL-1024 ` and `COL-1024` are one code |
| `assertAvailable(string $code, ?Collaborator $except = null): void` | throws `ReferralCodeTakenException` naming nothing about the holder (no enumeration through the error message) |
| `changeReferralCode(Collaborator, string $code, string $reason): Collaborator` | refuses with `ReferralCodeLockedException` when **any** `collaborator_referrals`, `collaborator_referral_visits` or `collaborator_commission_ledger_entries` row references the collaborator (INV-C2), and when `collaborator.referral_code_editable` is false; validates format + availability; writes an activity row with old and new code and the mandatory reason |
| `referralUrl(Collaborator, ?string $path = null): string` | `seo.canonical_base_url` (falling back to `config('app.url')`) + `$path ?? collaborator.referral_landing_path` + `?{collaborator.referral_query_param}={referral_code}`. Appends to an existing query string correctly, so `/courses/php-basics?ref=COL-1024` is produced by passing the course path |
| `referralUrls(Collaborator): array` | the three §38 surfaces: admission, inquiry, and an arbitrary-path builder for the copy-link UI |

### 6.2 `CollaboratorService`, `CollaboratorOnboardingService`, `CollaboratorPayoutAccountService`

| Method | Guarantees | Events |
|---|---|---|
| `CollaboratorService::create(CollaboratorData): Collaborator` | one transaction: assigns `collaborator_code`, sets `referral_code = collaborator_code` unless an explicit one is given, stamps `applied_at`, forces `status = pending` unless the caller holds `collaborators.approve` **and** asked for `active`, syncs skills and services, stores the photo. **The observer creates the wallet in the same transaction** (INV-C3) | `CollaboratorCreated` |
| `CollaboratorService::update(Collaborator, CollaboratorData): Collaborator` | never touches `collaborator_code`, `referral_code`, `status` or `user_id` (each has its own method); replaces the skill set through the unique index; re-syncs the pivot; deletes a replaced photo file | `CollaboratorUpdated` |
| `CollaboratorService::syncSkills(Collaborator, array $names): void` | idempotent by `uq_cskill`; removals are hard deletes (the table carries no `deleted_at` - **D19**) | - |
| `CollaboratorOnboardingService::approve(Collaborator, ApprovalData, User): Collaborator` | requires `status = pending` (any other state throws `InvalidStatusTransition`); stamps `approved_at` / `approved_by`, `status = active`, `joining_date` default today; **provisions the login** (§6.2.1) when asked; seeds the two first commission rule versions when `collaborator.seed_commission_rules_on_approval` is true **and** the `collaborator_commission_settings` module is enabled, by calling the spine's `CommissionRuleService::createVersion()` with Phase 2's default type and rate, `effective_from = today`, reason `"initial rule at onboarding"` - never by writing the table; skips with a logged warning when the spine is not yet installed (§1.4) | `CollaboratorApproved` |
| `CollaboratorOnboardingService::reject(Collaborator, string $reason, User)` | `pending -> inactive` with `status_reason`; **no fifth status is invented** (§34 names four); the login is not created; an activity row carries the reason | `CollaboratorRejected` |
| `CollaboratorOnboardingService::changeStatus(Collaborator, CollaboratorStatus, ?string $reason, User)` | only the transitions of §6.2.2; a reason is mandatory for `inactive` and `suspended`; suspending also **revokes the panel session** (`Auth::logoutOtherDevices` equivalent: delete the user's `sessions` rows) and sets `users.status = Inactive` so Phase 1's `active` middleware locks the panel on the next request; **never** touches a wallet, a ledger row or a referral's `commission_eligible` (INV-C4) | `CollaboratorStatusChanged` |
| `CollaboratorOnboardingService::provisionUser(Collaborator, User $actor): User` | creates the `users` row (D15: staff-created, never self-registration) with the **Collaborator** role, `status = Active`, `must_change_password = true`, a random password that is never logged, `email` asserted equal to `collaborators.email`; links `user_id` under `uq_col_user`; sends the Phase 1 password-reset mail as the invitation. Re-running returns the existing user | `CollaboratorUserProvisioned` |
| `CollaboratorPayoutAccountService::store(Collaborator, PayoutAccountData, User): CollaboratorPayoutAccount` | writes the spine's `collaborator_payout_accounts`; puts account number / IBAN / branch code / mobile number into `details_encrypted` (Laravel `encrypted` cast) and **only the last four digits** into `account_last4`; `is_verified = false`; the activity row diff shows `[encrypted]` and never the value (INV-C6); `uq_cpacc_default` makes "exactly one default" a database fact, so no clear-all-others loop can half-fail | `PayoutAccountAdded` |
| `CollaboratorPayoutAccountService::verify(...)` / `disable(...)` / `makeDefault(...)` | `verify` stamps `verified_by` / `verified_at` and needs `collaborator_payout_accounts.change_status`; `disable` sets `status = disabled` and refuses while an in-flight payout points at the account; `makeDefault` relies on the unique index rather than a loop | `PayoutAccountVerified`, `PayoutAccountDisabled` |

**6.2.1 Login provisioning rule.** A collaborator record without `user_id` is legal and invisible to the
panel. A `pending` or `inactive` collaborator's user (if any) cannot log in, because
`CollaboratorStatus::canLogin()` is mirrored onto `users.status` and Phase 1's `active` middleware is the
enforcement point - there is no second authentication path to keep in sync.

**6.2.2 `collaborators.status` transitions.** Anything not in this table throws
`InvalidStatusTransition`. Every row writes an `activity_log` entry with old and new status, actor, IP
and, where marked, a **mandatory reason** (§107, INV-24).

| From | To | Trigger | Permission | Reason |
|---|---|---|---|---|
| - | `pending` | record created (staff form or a public application) | `collaborators.create` | - |
| - | `active` | created directly by an approver | `collaborators.create` + `.approve` | - |
| `pending` | `active` | approval (§34 onboarding) | `collaborators.approve` | optional |
| `pending` | `inactive` | application rejected | `collaborators.reject` | **mandatory** |
| `active` | `inactive` | relationship paused | `collaborators.change_status` | **mandatory** |
| `active` | `suspended` | disciplinary | `collaborators.change_status` | **mandatory** |
| `inactive` / `suspended` | `active` | reinstated. **No commission is backfilled** - §6.6 row 1 of the spine: an admin with `collaborator_commissions.approve` runs `commissions:evaluate` explicitly, and `uq_cle_source` makes that safe exactly once | `collaborators.change_status` | optional |
| `suspended` | `inactive` | relationship ended after suspension | `collaborators.change_status` | **mandatory** |
| any | (soft deleted) | `collaborators.delete`; refused by policy when the collaborator has an in-flight payout or a non-zero available balance, because a debt does not disappear with the relationship (spine §6.6 row 2) | `collaborators.delete` | **mandatory** |

### 6.3 `ReferralAttributionResolver` (Phase 9) - the precedence ladder

```php
final class ReferralAttributionResolver
{
    public function resolve(ReferralResolutionContext $ctx): ReferralDecision;
}
```

`ReferralResolutionContext` carries: the `Request`, the subject model or subject type, the staff pick
(`?int $collaboratorId`, `bool $staffExplicitlyChoseNone`), the typed code, and the acting user.
`ReferralDecision` carries: `?Collaborator $winner`, `ReferralCandidateChannel $channel`,
`ReferralSource $source`, `?CollaboratorReferralVisit $visit`, `ReferralCandidate[] $losers`,
`bool $overrideReasonRequired`, `?string $rejectionReason`.

**The ladder. The first rank that yields an eligible collaborator wins; every lower rank that resolved a
*different* collaborator becomes a loser.** [D-P9-2]

| Rank | Candidate | Carrier | Wins when | `referral_source` written |
|---|---|---|---|---|
| 1 | **Staff manual selection** | the authenticated admin / receptionist form field | always, when present. Requires `collaborator_referrals.create`; the collaborator must satisfy `isSelectableForNewReferral()` (`suspended` is refused outright, `pending` / `inactive` need an explicit confirm checkbox) | `manual_selection` |
| 2 | **Applicant-typed code** | the public form's visible "Referral code" field (§67) | ranks 1 absent, the normalised code resolves to an `active` collaborator | `admission_form` |
| 3 | **Server session attribution** | `session('referral.visit_token')`, written by the `CaptureReferral` middleware | ranks 1-2 absent, the visit exists, `expires_at >= now`, `outcome` is `attributable()` | `referral_link` |
| 4 | **Encrypted cookie** | `ref_attr` (value = `visit_token`) | ranks 1-3 absent, same visit checks | `referral_link` |
| 5 | **Hidden form field** | `referral_visit_token` rendered server-side by `<x-site.referral-field>` | ranks 1-4 absent, same visit checks. **The posted value is a token, never a code** (INV-R2) | `referral_link` |
| 6 | **Raw query parameter** | `?ref=` still on the submitting request | ranks 1-5 absent; the code is re-resolved and re-validated server-side | `referral_link` |
| - | **nothing** | - | every rank empty or ineligible | **no referral row is created at all.** Later payments skip with `no_referral` (spine §6.1 step 3, INV-2) |

**Modifiers.**

1. **Which visit** (ranks 3-6, when a visitor has several unexpired visits): `last_touch` (default) takes
   the greatest `last_seen_at`; `first_touch` takes the least `first_seen_at`. Set by
   `collaborator.referral_attribution_model`.
2. **Eligibility** at ranks 2-6: the collaborator must be `active` and not trashed. Otherwise the visit
   is stamped `collaborator_not_eligible` and the resolver falls through to the next rank - a dead or
   suspended partner's link never silently attributes.
3. **Self-referral** (`collaborator.referral_self_attribution_blocked`): if the visit's `user_id`, or the
   subject's own user, is the collaborator's own `user_id`, the candidate is discarded and the visit is
   stamped `self_referral`.
4. **Bots**: `is_bot` visits never attribute (`bot_filtered`).
5. **Expiry**: a visit past `expires_at` is stamped `expired` and discarded; the cookie is cleared.
6. **Staff "No collaborator"**: an explicit staff choice of *none* is rank 1 with a null winner. **No
   referral row is created**, the captured candidate is recorded on the visit (`outcome = overridden`,
   detail naming the rejected code) and in the activity row with the mandatory reason. There is no
   referral row to supersede, because `collaborator_referrals.collaborator_id` is NOT NULL.
7. **Override** (rank 1 wins and a rank 2-6 candidate resolved a *different* collaborator): the Form
   Request requires `referral_override_reason` (5-255 chars) when
   `collaborator.referral_override_reason_required` is true, and the loser is preserved as a
   **superseded** referral row per INV-R3 and spine §2.8 by calling the spine's published method
   (F-4.4) - **never by writing `collaborator_referrals` here**:
   `ReferralService::recordLosingCandidate(CollaboratorReferral $winner, Collaborator $loser, ReferralContext $ctx, string $reason): CollaboratorReferral`.
   It writes `status = superseded`, `effective_from = effective_to = today`,
   `superseded_by_id` = the winner, `commission_eligible = false`, `change_reason` = the override reason
   (mandatory), `changed_by` = the actor, and the losing candidate's own visit evidence from `$ctx`.
   `uq_cr_superseded_by` means only one loser may point at a given winner, which is exactly the
   one-submission case.
8. **Effective dating**: `referral_date = effective_from = min(today, the subject's business date)`,
   floored at the winning visit's `first_seen_at` date when the winner came from a visit - so a
   back-dated admission credits the partner from the admission date, never from before the click, and
   never from the future (spine INV-16 resolves every payment on `paid_on` against this window).

**Where the decision is recorded.** `activity_log` event `referral.decided`, `module = collaborator_referrals`,
`collaborator_id` = the winner, `reason` = the override reason when present, and
`properties.referral_decision` exactly:

```json
{
  "winner": {"collaborator_id": 7, "code": "COL-1024", "channel": "staff_selection", "source": "manual_selection"},
  "losers": [{"collaborator_id": 12, "code": "COL-1031", "channel": "cookie", "visit_id": 903}],
  "subject": {"type": "student", "id": 418},
  "effective_from": "2026-09-12",
  "override_reason": "walk-in student named the partner at the desk",
  "attribution_model": "last_touch"
}
```

### 6.4 `ReferralTrackingService` and `ReferralLinkService` (Phase 9)

| Method | Guarantees |
|---|---|
| `ReferralTrackingService::record(Request, string $rawCode): ?CollaboratorReferralVisit` | one transaction. Normalises the code; resolves the collaborator through `ReferralService::resolveCode()` (the spine's method - never a local query); classifies the outcome (`captured` / `invalid_code` / `collaborator_not_eligible` / `self_referral` / `bot_filtered`); reuses the existing row when the same `visit_token` already holds the same code (`visits_count + 1`, `last_seen_at = now`) instead of writing a row per page view; writes `landing_url`, `landing_path`, `query_string`, referer, IP, UA and Phase 1 `Device` fields; sets `expires_at = now + collaborator.referral_cookie_days`. Returns null (and writes nothing) when `collaborator.referral_visit_tracking_enabled` is false - attribution still works from the session and cookie |
| `ReferralTrackingService::markConverted(CollaboratorReferralVisit, ReferralConversionSubject, int $subjectId, ?CollaboratorReferral): void` | idempotent: only the **first** conversion stamps `converted_at`; later ones append to `outcome_detail`. Sets `outcome = converted` and `converted_referral_id` when a referral row exists |
| `ReferralTrackingService::funnel(DateRange, filters): ReferralFunnel` | clicks, unique visitors, attributable clicks, conversions, conversion rate, grouped by collaborator / code / landing path / outcome. Reads only this table - it never joins the ledger, because commission figures come from the wallet and statement services (INV-C7) |
| `ReferralLinkService::capture(Request): ?CollaboratorReferralVisit` | called by the `CaptureReferral` middleware: reads `?{referral_query_param}=`, calls `record()`, writes `session('referral.visit_token')` and queues the `ref_attr` cookie (`httpOnly`, `sameSite=lax`, `secure` in production, path `/`, TTL = `referral_cookie_days` days, encrypted by Laravel's `EncryptCookies` so its contents cannot be forged). Never redirects, never 500s on a bad code, never blocks the page |
| `ReferralLinkService::tokenForForm(Request): ?string` | the value `<x-site.referral-field>` renders - the token, never the code |
| `ReferralLinkService::forget(Request): void` | clears session key and cookie after a conversion or an expiry |

**Middleware `CaptureReferral`** is appended to the **public `web` group only** (`routes/web.php`), never
to the five panel groups. It runs after `StartSession` and before the controller, is a no-op when no
referral parameter is present, and is exempt from CSRF concerns because it never writes a form.

### 6.5 Linking, re-linking, and what happens to commission already earned

Both paths go through the spine's `ReferralService` (spine §13.1 forbids anything else); this phase
supplies the screens, the Form Requests, the resolver and the snapshot listener.

| Act | Method called | Permission | Reason | What is stored |
|---|---|---|---|---|
| **Manual link** of a student / project / client / lead that has no attribution | `ReferralService::attach($subject, $collaborator, ReferralSource, $code, $on, ?ReferralContext $context = null)` - this phase **always** passes an `App\DataObjects\Collaborator\ReferralContext` (`referralVisitId`, `landingUrl`, `ipAddress`, `userAgent`, `referralDate`, `notes`) so the click evidence reaches the attribution row (F-4.3) | `collaborator_referrals.create` | optional (mandatory when it overrides a captured candidate, §6.3 rule 7) | a new `active` referral; the code snapshotted; `referral_visit_id` and the rest of the evidence filled from the DTO; the loser as a superseded row through `recordLosingCandidate()` |
| **Change** the collaborator of a student / project / client / lead | `ReferralService::change($subject, $newCollaborator, $reason)` | `collaborator_referrals.edit` | **mandatory**, 10-255 chars, validated server-side | old row -> `status = superseded`, `effective_to = today`, `superseded_by_id`, `change_reason`, `changed_by`; new row `effective_from = today`; the **open entitlement is superseded**; an `activity_log` row with old and new collaborator **names and codes** plus the reason (§37, §107) |
| **Revoke** an attribution without naming a successor | `ReferralService::revoke($subject, $reason)` | `collaborator_referrals.change_status` | **mandatory** | `status = revoked`, `effective_to = today`, `commission_eligible = false`: future commission stops, **all history stays** |

**Commission already earned - the spine's decision, restated verbatim in effect (spine §6.6 row 3,
§6.2 `ReferralService::change()`, INV-18).** This phase adds nothing to it and changes nothing about it:

1. **Existing ledger rows are never re-pointed.** Collaborator A keeps exactly what A earned, in A's
   wallet, on A's statement. Re-pointing would rewrite a statement that has already been issued.
2. The **open entitlement is superseded**, so the promise is closed under A and a fresh one opens under
   B on B's first commissionable payment.
3. **Only payments with `paid_on >= new.effective_from` credit B.** A back-dated receipt keyed in after
   the switch but dated before it correctly earns for **A** - because the engine resolves the referral on
   the payment's value date, never on `now()` (INV-16).
4. Attribution cannot be back-dated: `change()` starts the new row **today**. The UI states this on the
   form.
5. Pending, unapproved entries are **not** moved automatically. If the business insists, it is two
   `manual_adjustment` rows (a debit on A, a credit on B) sharing one reason, both permanently visible,
   both needing `collaborator_commissions.create` - never an edit of a posted row.
6. Nothing is deleted, ever: the superseded referral row, A's entries, A's statement and A's payouts all
   survive.
7. **Projects only:** a project-level rate override (`projects.commission_type|commission_rate|commission_fixed_amount`,
   spine §6.1.1 precedence 1) belongs to the **project**, so after a switch B inherits it. The change
   screen therefore displays the override and offers to clear it; clearing is an audited edit of the
   project row requiring `projects.edit` (§13).

### 6.6 `CollaboratorPortalMetricsService` and `CollaboratorActivityService`

| Method | Guarantees |
|---|---|
| `CollaboratorPortalMetricsService::dashboard(Collaborator, DateRange, User $viewer): CollaboratorDashboardData` | assembles the §36 tiles (§8.7 maps every one to its source). **Every money figure comes from `CollaboratorWalletService::derive()`** and every movement figure from `CollaboratorStatementService::build()` (INV-C7, INV-26); a tile whose `collaborator_portal.*` permission the viewer lacks is **absent from the DTO**, not zeroed; a tile whose module is disabled is absent; when the wallet's `reconciliation_status` is `drift` the money tiles render the **derived** figures behind the spine's banner (spine §6.5.4) |
| `CollaboratorPortalMetricsService::referralSummary(Collaborator, DateRange)` | attributed students / projects / clients / leads from `collaborator_referrals` (`status = active`), clicks and conversion rate from `collaborator_referral_visits` |
| `CollaboratorActivityService::record(CollaboratorActivityEvent, Collaborator, ?Model $subject, array $properties, ?string $reason): void` | writes one `activity_log` row with `collaborator_id`, `module`, `event`, IP / UA / device from `LogsActivityWithContext`; **never writes a payout account's or a payout's encrypted details** (INV-C6) |
| `CollaboratorActivityService::feed(Collaborator, DateRange, ?CollaboratorActivityEvent): LengthAwarePaginator` | `activity_log where collaborator_id = :id and event in (§60's eleven)`, newest first, driven by `INDEX (collaborator_id, created_at)`. **The feed is an allowlist, not a judgement (F-12.7):** it renders only the `properties` keys returned by `CollaboratorActivityEvent::visibleProperties()` for that event and **never `reason`** - so staff-written reasons about the collaborator (`status_changed`, `referral.decided` override reasons) and any other party's data are structurally unreachable rather than filtered case by case. A key not on the list is **absent from the response body** (FT-C29) |

**§60 event sources - who writes each of the eleven.**

| §60 event | `CollaboratorActivityEvent` | Written by | Causer |
|---|---|---|---|
| Login | `login` | Phase 1's `RecordSuccessfulLogin`, stamped with `collaborator_id` by the `tapActivity()` addition (§13) | the collaborator's user |
| Student referral | `student_referral` | `SyncReferralSnapshot` on `ReferralAttached` / `ReferralChanged` where subject is a student | staff, or null for a public capture |
| Project referral | `project_referral` | the same, subject project | staff / null |
| Task update | `task_update` | Phase 6's task service when `tasks.assigned_collaborator_id` matches | the collaborator's user |
| File upload / download | `file_upload` / `file_download` | Phase 22's file service | the collaborator's user |
| Commission created | `commission_created` | the spine's `LedgerWriter` activity row, stamped with `collaborator_id` | **null** (the engine) - which is precisely why §2.5's column exists |
| Commission approved | `commission_approved` | the spine's `CommissionApprovalService` | staff |
| Commission reversed | `commission_reversed` | the spine's `CommissionReversalService` (covers the clawback case) | staff / null |
| Payout request | `payout_request` | the spine's `PayoutService::request()` | the collaborator's user |
| Payout paid | `payout_paid` | the spine's `PayoutService::markPaid()` | staff |

---

## 7. Routes

Admin routes inherit `auth`, `active`, `panel:admin` from `routes/admin.php`; collaborator routes inherit
`auth`, `active`, `panel:collaborator`, `module:collaborators` from `routes/collaborator.php` (Phase 1
§8). `module:*` is stated only where it differs. **Spine §7.3 / §7.5 routes are reused unchanged and are
not redefined here**; the table marks the ones this phase adds.

### 7.1 Admin - collaborators

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/collaborators` | `admin.collaborators.index` | `module:collaborators`, `can:collaborators.view_any` |
| GET `/admin/collaborators/create` | `admin.collaborators.create` | `can:collaborators.create` |
| POST `/admin/collaborators` | `admin.collaborators.store` | `can:collaborators.create` |
| GET `/admin/collaborators/{collaborator}` | `admin.collaborators.show` | `can:collaborators.view` |
| GET `/admin/collaborators/{collaborator}/edit` | `admin.collaborators.edit` | `can:collaborators.edit` |
| PUT `/admin/collaborators/{collaborator}` | `admin.collaborators.update` | `can:collaborators.edit` |
| DELETE `/admin/collaborators/{collaborator}` | `admin.collaborators.destroy` | `can:collaborators.delete` (soft; policy refuses with an in-flight payout or non-zero available balance) |
| POST `/admin/collaborators/{collaborator}/restore` | `admin.collaborators.restore` | `can:collaborators.restore`, `withTrashed` binding |
| GET `/admin/collaborators/pending` | `admin.collaborators.pending` | `can:collaborators.approve` |
| POST `/admin/collaborators/{collaborator}/approve` | `admin.collaborators.approve` | `can:collaborators.approve` |
| POST `/admin/collaborators/{collaborator}/reject` | `admin.collaborators.reject` | `can:collaborators.reject` |
| POST `/admin/collaborators/{collaborator}/status` | `admin.collaborators.status` | `can:collaborators.change_status` |
| POST `/admin/collaborators/{collaborator}/user` | `admin.collaborators.user.store` | `can:collaborators.edit`, `can:users.create` |
| POST `/admin/collaborators/{collaborator}/referral-code` | `admin.collaborators.referral-code` | `can:collaborators.edit`, `throttle:10,1` |
| GET `/admin/collaborators/{collaborator}/referral-links` | `admin.collaborators.referral-links` | `can:collaborators.view` |
| GET `/admin/collaborators/{collaborator}/activity` | `admin.collaborators.activity` | `can:collaborators.view_logs` |
| GET `/admin/collaborators/options` | `admin.collaborators.options` | `can:collaborator_referrals.create`, `throttle:60,1` - the picker endpoint: **id, code, name, company, status only** (§9) |
| GET `/admin/collaborators/export/{format}` | `admin.collaborators.export` | `can:collaborators.export` |

### 7.2 Admin - payout accounts (new; the collaborator-side pair is spine §7.5)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/collaborators/{collaborator}/payout-accounts` | `admin.payout-accounts.index` | `module:collaborator_payout_accounts`, `can:collaborator_payout_accounts.view_any` |
| POST `/admin/collaborators/{collaborator}/payout-accounts` | `admin.payout-accounts.store` | `can:collaborator_payout_accounts.create` |
| PUT `/admin/payout-accounts/{account}` | `admin.payout-accounts.update` | `can:collaborator_payout_accounts.edit` |
| DELETE `/admin/payout-accounts/{account}` | `admin.payout-accounts.destroy` | `can:collaborator_payout_accounts.delete` (soft) |
| POST `/admin/payout-accounts/{account}/verify` | `admin.payout-accounts.verify` | `can:collaborator_payout_accounts.change_status` |
| POST `/admin/payout-accounts/{account}/default` | `admin.payout-accounts.default` | `can:collaborator_payout_accounts.edit` |

### 7.3 Admin - referrals and referral tracking

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/referrals` | `admin.referrals.index` | **spine §7.3** - `module:collaborator_referrals`, `can:collaborator_referrals.view_any` |
| POST `/admin/students/{student}/referral` | `admin.referrals.store-student` | **spine §7.3** - `can:collaborator_referrals.create` |
| PUT `/admin/students/{student}/referral` | `admin.referrals.change-student` | **spine §7.3** - `can:collaborator_referrals.edit` |
| PUT `/admin/projects/{project}/referral` | `admin.referrals.change-project` | **spine §7.3** - `can:collaborator_referrals.edit` |
| POST `/admin/referrals/{referral}/revoke` | `admin.referrals.revoke` | **spine §7.3** - `can:collaborator_referrals.change_status` |
| POST `/admin/projects/{project}/referral` | `admin.referrals.store-project` | **new** - `can:collaborator_referrals.create` (§45 manual linking; the spine defines only the change route) |
| POST `/admin/clients/{client}/referral` | `admin.referrals.store-client` | **new** - `can:collaborator_referrals.create` |
| PUT `/admin/clients/{client}/referral` | `admin.referrals.change-client` | **new** - `can:collaborator_referrals.edit` |
| POST `/admin/leads/{lead}/referral` | `admin.referrals.store-lead` | **new** - `can:collaborator_referrals.create` |
| PUT `/admin/leads/{lead}/referral` | `admin.referrals.change-lead` | **new** - `can:collaborator_referrals.edit` |
| GET `/admin/referrals/{referral}` | `admin.referrals.show` | **new** - `can:collaborator_referrals.view` (the supersede chain, old -> new, reasons, the winning visit) |
| GET `/admin/referrals/export/{format}` | `admin.referrals.export` | **new** - `can:collaborator_referrals.export` |
| GET `/admin/referral-visits` | `admin.referral-visits.index` | **new** - `module:collaborator_referral_visits`, `can:collaborator_referral_visits.view_any` |
| GET `/admin/referral-visits/{visit}` | `admin.referral-visits.show` | **new** - `can:collaborator_referral_visits.view` |
| GET `/admin/referral-visits/report` | `admin.referral-visits.report` | **new** - `can:collaborator_referral_visits.view_reports` |
| GET `/admin/referral-visits/export/{format}` | `admin.referral-visits.export` | **new** - `can:collaborator_referral_visits.view_reports` |

### 7.4 Admin - commission rules (the spine's screen §8.4, built in Phase 8)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/collaborators/{collaborator}/commission-rules` | `admin.commission-rules.index` | **spine §7.3** - `module:collaborator_commission_settings`, `can:collaborator_commission_settings.view` |
| POST `/admin/collaborators/{collaborator}/commission-rules` | `admin.commission-rules.store` | **spine §7.3** - `can:collaborator_commission_settings.create` |
| POST `/admin/commission-rules/{rule}/close` | `admin.commission-rules.close` | **spine §7.3** - `can:collaborator_commission_settings.create` |
| GET `/admin/collaborators/{collaborator}/commission-rules/preview` | `admin.commission-rules.preview` | **new** - `can:collaborator_commission_settings.view`, read-only ("a PKR 10,000 receipt today earns 1,000.00 under the current rule and 1,500.00 under this one"). **Writes nothing** |

### 7.5 Collaborator panel (Phase 8)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/collaborator` | `collaborator.dashboard` | `can:collaborator_portal.dashboard` (replaces Phase 1's placeholder) |
| GET `/collaborator/dashboard/widget/{key}` | `collaborator.dashboard.widget` | `can:collaborator_portal.dashboard`, `throttle:60,1` |
| GET `/collaborator/profile` | `collaborator.profile.edit` | `can:collaborator_portal.profile` |
| PUT `/collaborator/profile` | `collaborator.profile.update` | `can:collaborator_portal.profile` |
| GET `/collaborator/referrals` | `collaborator.referrals.index` | `can:collaborator_portal.referrals` |
| GET `/collaborator/referral-links` | `collaborator.referral-links` | `can:collaborator_portal.referrals` |
| GET `/collaborator/leads` | `collaborator.leads.index` | `can:collaborator_portal.leads`, `module:leads` |
| GET `/collaborator/activity` | `collaborator.activity.index` | `can:collaborator_portal.activity_log` |
| GET `/collaborator/wallet` · `/commissions` · `/statement` · `/payouts` · `/payout-accounts` · `/students` · `/projects` | - | **spine §7.5**, unchanged |

### 7.6 Public (Phase 9)

| Method + URI | Route name | Middleware |
|---|---|---|
| (every public GET route in `routes/web.php`) | - | **`CaptureReferral` appended to the public `web` group** - reads `?ref=`, records the visit, sets session + cookie |
| POST `/referral/validate` | `site.referral.validate` | `throttle:10,1` - returns `{valid, code, collaborator_name?}`; `collaborator_name` only when `collaborator.referral_public_name_visible` is true; never an id, email, phone, status or any commission figure |

---

## 8. UI screens

Phase 1 §9 and `CLAUDE.md` §6 apply everywhere: `x-ui.*` only, search + filters + sortable headers +
pagination + empty state + skeleton loader on every list, money right-aligned with `tabular-nums` and
gated by `collaborators.view_financial`, a toast on every write, `x-ui.confirm` on every destructive or
irreversible act, tables inside `overflow-x-auto`, light and dark.

**No Kanban and no calendar in these phases.** §18 asks for a lead Kanban and §22 a task Kanban; §33-60
never ask for a board or a calendar, so one here would be invented scope (the same stance as spine §8).
**Wizards** are used exactly where the requirement implies a staged act: collaborator onboarding (§34),
a new commission rule version (§35), and changing an attribution (§37).

### 8.1 Collaborator index (`admin/collaborators/index.blade.php`)

**Purpose.** The partner book: who they are, what state they are in, and what they are worth.
**Components.** `x-ui.page-header` (with a *New collaborator* primary action and an *Export* split),
`x-ui.filter-bar`, `x-ui.table` + `x-ui.th-sortable`, `x-ui.badge`, `x-ui.avatar`,
`x-ui.pagination-summary`, `x-ui.empty-state`, `x-ui.skeleton`, `x-ui.confirm`.
**Filters.** status (multi), collaboration type (multi), service, skill, country, has login account
(yes/no), joining-date range, "has referred students", "has available balance" (only with
`collaborators.view_financial`), include trashed (only with `collaborators.restore`), free text over
code / referral code / name / company / email / phone.
**Columns.** Code · Name + company (avatar, `referral_code` as a copy chip) · Type · Services (up to
three chips, "+n") · Status badge (`CollaboratorStatus::color()`) · Joined · Referred students ·
Referred projects · **Lifetime earned** · **Available balance** (rose when negative) · row actions
(view, edit, approve, status, payout accounts, commission rules, referral links, delete).
The last two money columns are **absent from the response** without `collaborators.view_financial`, and
both are read through `CollaboratorWalletService` with one eager batch per page - never a per-row query
and never a `SUM()` in the view (INV-C7).
**Empty state.** "No collaborators yet" + *New collaborator*; the filtered variant offers *Clear filters*.

### 8.2 Collaborator create / onboarding wizard

Four `x-ui.tabs`-style steps inside one form (Alpine, client-side step validation, server-side Form
Request the single source of truth): (1) **Identity** - name, company, email, phone, WhatsApp, country,
address, photo; (2) **Collaboration** - type, services (multiselect from Phase 4's catalogue), skills
(tag input), joining date, notes; (3) **Access** - create a login now? (email pre-filled, the invite is
a Phase 1 password-reset mail, never a password shown on screen), referral code (pre-filled with the
about-to-be-assigned `collaborator_code`, editable only when `collaborator.referral_code_editable`);
(4) **Review** - everything read-only, plus the two commission rules that **will** be created from
Phase 2's defaults at approval ("student 10.0000% of actual paid, effective today"), with the plain
sentence that a rule can later only be **versioned**, never edited.
A creator without `collaborators.approve` sees step 4 stating the record will be saved as **Pending**.

### 8.3 Collaborator show - the tabbed profile

`x-ui.page-header` (code, name, status badge, referral code chip with copy, action dropdown) + four
`x-ui.stat-card`s (referred students, referred projects, lifetime earned, available balance - the last
two permission-gated) + `x-ui.tabs`:

| Tab | Contents |
|---|---|
| **Profile** | §34 fields, services, skills, login state, approval trail (applied / approved by / status changes with reasons read from `activity_log`) |
| **Commission rules** | the spine's §8.4 timeline + *Add new version* wizard (type + rate/fixed -> base + fee types + caps -> `effective_from` + **mandatory reason** -> preview). The open version renders **locked**, closable only |
| **Referrals** | attributed students / projects / clients / leads, each with source, code, date, window, status, and the supersede chain; *Change* and *Revoke* actions |
| **Students** | §57 columns, admin view (no per-field hiding here - `collaborators.view_financial` gates the money columns) |
| **Projects** | §58 columns |
| **Wallet** | the spine's §8.5 embedded (six stat cards, the printed identity line, the reconciliation banner) |
| **Payouts** | the spine's §8.6 register filtered to this collaborator |
| **Payout accounts** | §8.4 below |
| **Statement** | the spine's §8.7 embedded, with the proof footer |
| **Activity** | §60's eleven events, filterable, with IP and device (`collaborators.view_logs`) |

Tabs whose module is disabled are **not rendered** (Phase 1 `@module`), which is also how the screen
stays correct before the spine is installed (§1.4).

### 8.4 Payout accounts (admin, and the collaborator's own copy)

**Purpose.** §55: a reusable, protected destination, typed once.
**Components.** `x-ui.card` per account, `x-ui.badge` (method, default, verified), `x-ui.modal` form,
`x-ui.confirm`.
**Columns / card face.** Label · method chip · account title · bank · **`•••• 1234`** · default badge ·
verified badge (+ who / when) · status · actions (edit, make default, verify, disable).
**Rules rendered on screen.** "Account details are encrypted and cannot be displayed again - re-enter
them to change." The add/edit form posts the full number; the response, the table, every export, every
activity diff and every notification show **only the last four** (INV-C6). There is no reveal control,
no print of full details, and no admin override - because no screen in this system has a legitimate
reason to show a stored account number.
**Empty state.** "No payout destination yet - a payout cannot be paid without one."

### 8.5 Referral links screen (admin tab and collaborator panel)

Purpose: hand over §38's artefacts. Shows `collaborator_code`, `referral_code`, and three ready URLs -
admission (`{base}/admission?ref=COL-1024`), inquiry (`{base}/contact?ref=COL-1024`) and a
**build-your-own** row where a course or service path produces `/courses/php-basics?ref=COL-1024` - each
with a copy button and a short "what happens when someone uses this" explanation (cookie window in days,
that a receptionist may override it, that commission follows only received payments). Admin-side only: a
*Change referral code* action, disabled with the reason when INV-C2 locks it.

### 8.6 Referral visit register and conversion report (Phase 9)

**Register.** Filters: collaborator, code, outcome, converted (yes/no), landing path, date range, bot
(yes/no), IP. Columns: first seen · last seen · code (+ collaborator, or a rose "unknown code" chip) ·
landing path · visits · device / platform / browser · outcome badge + detail on hover · converted
subject (deep link) · IP (masked to `/24` unless the viewer holds
`collaborator_referral_visits.view_logs`). Read-only: **no row actions at all**, because nobody edits a
click. Empty state: "No referral visits in this range".
**Conversion report** (`view_reports`). Per collaborator and per landing path: clicks, unique visitors,
attributable clicks, conversions, conversion rate, and the outcome breakdown as a grouped bar
(`x-ui.chart`) - "312 clicks, 41 attributable, 9 converted; 171 invalid code". Plus a **dead-code panel**
listing codes that receive clicks but resolve to nothing, which is how a partner who printed an old
flyer gets found. Export: print / PDF / CSV.

### 8.7 Collaborator panel - shell and dashboard (§36)

**Shell.** `layouts/panel.blade.php` (Phase 1), with the collaborator nav built by `App\Support\Sidebar`
so every item needs its module **and** its permission: Dashboard · My students · My projects · My tasks ·
Referrals (+ Referral links) · Wallet · Commissions · Statement · Payouts (+ Payout accounts) ·
Meetings · Messages · Activity · Profile. Topbar: notification bell, theme switcher, profile dropdown.
A `suspended` or `inactive` collaborator never reaches the shell - Phase 1's `active` middleware logs
them out with the status message.

**Dashboard.** A fixed, permission-filtered Blade fed by `CollaboratorPortalMetricsService` - **not**
Phase 2's `DashboardRegistry`, whose widget contract has no panel dimension **[D-P8-5]** (the optional
`panel()` addition is requested in §13). A global `x-ui` date-range selector feeds every tile; each tile
skeletons while its JSON loads; a tile the viewer may not see is absent from the payload.

| §36 tile | Source | Gate |
|---|---|---|
| Total referred students | `collaborator_referrals` `subject_type = student`, `status = active` | `.students` |
| Active referred students | the same, joined to `students.status = Active` | `.students` |
| Total referred projects | `collaborator_referrals` `subject_type = project`, `status = active` | `.projects` |
| Total project value | `SUM(projects.project_value)` over those projects | `.project_value` |
| Student commission earned | `CollaboratorWalletService::derive()->total_student_commission` | `.student_commission` |
| Project commission earned | `derive()->total_project_commission` | `.project_commission` |
| Total earnings | `derive()->lifetime_earned` | `.wallet` |
| Pending earnings | `derive()->pending_balance` | `.wallet` |
| Available balance | `derive()->available_balance` - **rose when negative**, with the spine's §6.3.5 clawback sentence | `.wallet` |
| Paid earnings | `derive()->paid_balance` (from allocations, INV-23 - never `status = paid`) | `.wallet` |
| Commission history | the last 10 ledger rows, scoped | `.student_commission` / `.project_commission` |
| Assigned projects | `project_members` (Phase 6) where `collaborator_id = mine` and the pivot is not soft-deleted | `.projects` |
| Assigned tasks | `tasks.assigned_collaborator_id` (Phase 6) | `.tasks` |
| Referrals | `referralSummary()` - attributed subjects, clicks, conversion rate | `.referrals` |
| Leads | `collaborator_referrals` `subject_type = lead` (Phase 5) | `.leads` |
| Meetings | Phase 22 | `.meetings` |
| Messages | Phase 22 unread count | `.messages` |
| Notifications | the `notifications` table, unread | `.notifications` |

The identity line from spine §6.5.2 is printed under the money tiles
(`lifetime 50,000.00 = pending 0.00 + available 30,000.00 + reserved 0.00 + paid 20,000.00`), so a
collaborator can see that the figures add up; on `drift` the spine's rose banner replaces the chip and
every figure is the derived one.

### 8.8 Change-attribution wizard (§37) - admin

Three steps, reached from a student, project, client or lead: (1) **Current** - the active referral,
read-only: collaborator, code, source, referral date, effective window, and the **count and total of
commission already earned** (read from the statement service, `collaborators.view_financial` gated);
(2) **New** - the collaborator picker (suspended partners excluded, `pending` / `inactive` behind a
confirm) plus, for a project, the project-level override panel with a *Clear override* checkbox;
(3) **Reason and confirm** - a required reason (10-255 chars) and a plain, unavoidable statement of the
consequences, rendered verbatim from §6.5:

> Commission already earned by **Ali Traders (COL-1024)** stays with them and stays on their statement.
> **Bilal Digital (COL-1031)** earns only on payments dated **2026-09-12** or later. A back-dated receipt
> dated before today will still earn for Ali Traders. Nothing already posted will change.

Submitting calls `ReferralService::change()`. The confirm dialog names both collaborators. The
public-form equivalent of the override (a receptionist contradicting a captured code at admission) uses
the same reason field inline on the admission form (§6.3 rule 7).

### 8.9 Admin dashboard widgets (into Phase 2's `DashboardRegistry`)

`CollaboratorsByStatusWidget`, `PendingCollaboratorApplicationsWidget` (counts + age, driven by
`collaborator.pending_application_alert_days`), `ReferralVisitsTrendWidget` (14-day line),
`ReferralConversionWidget` (clicks -> attributable -> converted funnel),
`ReferredSubjectsBreakdownWidget` (students / projects / clients / leads). Each declares `module()` and
`permission()` so Phase 2's gating applies unchanged. §98's collaborator **money** cards are **not**
re-implemented here: they are the spine's `WalletLiabilityWidget`, `CollaboratorLeaderboardWidget`,
`CommissionPendingApprovalWidget` and `CommissionPaidThisMonthWidget` (INV-C7).

---

## 9. Data isolation

Every rule is an Eloquent **global scope** plus a **Policy** check, never a hidden form field
(`CLAUDE.md` §1.10), and each has a feature test asserting the status code **and** the absence of the
forbidden columns from the response body.

| Role | Exact query scoping |
|---|---|
| **Super Admin** | Unrestricted, still subject to module gating (a disabled module 403s Super Admin too). |
| **Admin** | Unrestricted within Phase 1 §5's grants; money columns still require `collaborators.view_financial`. |
| **Accountant** | Full read on `collaborators`, `collaborator_skills`, `collaborator_service`; `collaborator_payout_accounts.*`; **no** `collaborators.edit`, `.approve` or `collaborator_referrals.edit` - a partner's attribution is a commercial fact, not an accounting one. |
| **HR / Project Manager / Institute Manager / Course Coordinator / Support Agent / Teacher** | No access to `collaborators`, `collaborator_skills`, `collaborator_referral_visits` or any `collaborator_*` route: **403**. (Project Manager sees a project's collaborator **name** only, through the project screen's whitelisted columns.) |
| **Sales Executive / Receptionist** | `collaborators`: **only** `admin.collaborators.options`, returning `id, collaborator_code, name, company_name, status` for `status = active` (plus `pending`/`inactive` flagged, never `suspended`, never trashed) - enough to attribute at the desk and nothing more. May create a referral (`collaborator_referrals.create`); **may not** change one, may not read visits, may not read any money column (403 / column absent). |
| **Collaborator** | The global scope `BelongsToAuthenticatedCollaborator` (spine §9) is **extended by this phase** to `Collaborator` (`where id = auth()->user()->collaborator->id`), `CollaboratorSkill`, `CollaboratorReferralVisit` and `CollaboratorPayoutAccount`, in addition to the spine's nine models and both payment tables. Route-model binding asserts ownership in the policy and returns **404, not 403**, so ids cannot be probed. The panel's student and project lists are derived **only** through `collaborator_referrals` (`status = active`) and Phase 6's `project_members` (an active pivot row carrying my `collaborator_id`) - **never through a snapshot column such as `projects.collaborator_id`**, which is display-only (D37, F-2.7, F-8.2); `activity_log` is scoped to `collaborator_id = own`; the referral list shows only own rows and, on a superseded row, **never the successor collaborator's identity**. Every money and sensitive column is withheld unless the matching `collaborator_portal.*` permission is held, and a withheld column is **absent from the response**, not blank (§57, §58, §59). Nothing anywhere exposes another collaborator's code, name, rate, wallet, payout, account or referral - including the picker endpoint of §7.1, which is admin-panel only. |
| **Student / Client** | No access to any table in these phases: **403**. A student's own screens never render `collaborator_id`, `referral_code` or `referral_source` (spine §9 already omits the commission columns from their payment views). |
| **Public (guest)** | May trigger a referral **capture** and may call `POST /referral/validate` (throttled 10/min), which returns only `{valid, code, collaborator_name?}` - and `collaborator_name` only when `collaborator.referral_public_name_visible` is true. Code enumeration therefore reveals at most a partner's display name, which is the intent of §38's "preselects that collaborator"; it can never reveal an id, contact detail, status, rate, or whether a code is merely inactive versus non-existent (both answer `valid: false`). |
| **Branch (D11)** | Deliberately **not applied** to these phases: a collaborator is not branch-bound (spine §9), so `collaborators`, `collaborator_skills` and `collaborator_referral_visits` carry no `branch_id` and are global. A branch-scoped user still sees only their branch's **students** through Phase 15's scoping, which is what limits what they can attribute. |
| **Module gating** | Disabling `collaborators` 403s the whole panel and every admin collaborator route for everyone, Super Admin included, while every row, every queued job and every posted commission stays intact (spine §6.6 row 22). Disabling `collaborator_referral_visits` stops the register and the report; **the `CaptureReferral` middleware keeps working**, because losing a partner's attribution is a money loss and hiding a screen is not a reason to cause one. |

---

## 10. Events, notifications, jobs, scheduled tasks

### 10.1 Events (all dispatched through `DB::afterCommit()`)

**Phase 8:** `CollaboratorCreated`, `CollaboratorUpdated`, `CollaboratorApproved`,
`CollaboratorRejected`, `CollaboratorStatusChanged`, `CollaboratorUserProvisioned`,
`CollaboratorReferralCodeChanged`, `PayoutAccountAdded`, `PayoutAccountVerified`,
`PayoutAccountDisabled`.
**Phase 9:** `ReferralVisitRecorded`, `ReferralVisitConverted`, `ReferralCandidateOverridden`.
**Reused, never redefined:** the spine's `ReferralAttached`, `ReferralChanged`, `ReferralRevoked`,
`CommissionRuleVersioned`.

### 10.2 Listeners and observers

| Class | Responsibility |
|---|---|
| `CollaboratorObserver::created` | creates the `collaborator_wallets` row **in the same transaction** (spine §1.2, §6.6 row 17, INV-C3); a no-op with a logged warning when the spine is not yet migrated (§1.4) |
| `CollaboratorObserver::updating` | throws `ImmutableCollaboratorCodeException` on `collaborator_code`; routes a `referral_code` change through `CollaboratorCodeService` (INV-C1, INV-C2) |
| `SeedInitialCommissionRules` (on `CollaboratorApproved`) | calls the spine's `CommissionRuleService::createVersion()` for both scopes when the setting and the module allow |
| `SyncReferralSnapshot` (on `ReferralAttached` / `ReferralChanged` / `ReferralRevoked`) | the **only** writer of the subject snapshot columns - `students.collaborator_id`, `.referral_code`, `.referral_source`, `.referral_date`, `.referral_visit_id`; `student_admissions` / `course_inquiries` / `contact_inquiries` / `projects` equivalents; and on the CRM side `leads.referral_code_captured` + `.referral_visit_id` and `clients.referral_code_captured` + `.referral_recorded_at` (F-3.4, F-3.5 - **neither `leads` nor `clients` carries a `collaborator_id`**) - INV-R1; also writes the §60 `student_referral` / `project_referral` activity rows |
| `MarkReferralVisitConverted` (on `ReferralAttached` / `ReferralChanged`) | stamps the winning visit's conversion columns, idempotently |
| `ClearReferralCookie` (on `ReferralAttached`) | forgets the session key and queues cookie expiry so one click cannot attribute two subjects by accident |
| `SuspendCollaboratorSessions` (on `CollaboratorStatusChanged`) | mirrors `CollaboratorStatus::canLogin()` onto `users.status` and deletes the user's `sessions` rows |

### 10.3 Notifications (database channel now, mail-ready - §97)

To **staff**: `CollaboratorApplicationReceived` (holders of `collaborators.approve`),
`PayoutAccountAwaitingVerification` (holders of `collaborator_payout_accounts.change_status`).
To the **collaborator**: `CollaboratorAccountApproved` (carries the referral code and the two URLs),
`CollaboratorAccountStatusChanged` (states the reason when one was recorded),
`NewReferralAttributed` (§97's "new student" / "new project" for the partner - names the subject, never
another party's contact details).
**Not sent:** nothing tells collaborator A that a subject moved to B. That is a commercial conversation,
not a system notification, and §97 does not ask for it.

### 10.4 Queued jobs and scheduler

| Job / command | Cadence | Purpose |
|---|---|---|
| `collaborators:backfill-wallets` | on demand (§1.4) | idempotent `firstOrCreate` of a wallet for every collaborator; reports how many were missing |
| `collaborators:seed-initial-rules` | on demand (§1.4) | creates the first rule version for approved collaborators that have none; skips anyone who already has a version |
| `collaborators:sync-referral-snapshots` | daily 02:30 | re-derives every subject snapshot column from `collaborator_referrals` (the truth) and reports every repair - a cache-repair job in the spine's spirit, never a writer of attribution |
| `referrals:prune-visits` | weekly | deletes `collaborator_referral_visits` older than `collaborator.referral_visit_retention_days` (§5, default 365 - F-13.12), **excluding** any row with `converted_at`, any row referenced by `collaborator_referrals.referral_visit_id` **or by `leads.referral_visit_id`** (F-3.5), and any row whose collaborator has a ledger entry sourced through it (INV-R6) |
| `collaborators:flag-pending` | daily 08:00 | notifies approvers about `pending` records older than `collaborator.pending_application_alert_days` |

---

## 11. Acceptance tests

`tests/Feature/Collaborator/` and `tests/Feature/Referral/`. Test ids are prefixed `FT-C` / `FT-R` so
they never collide with the spine's `FT-01..FT-51`. Every test that touches a spine table also calls the
spine's `assertWalletMatchesLedger($collaborator)` helper, so these phases can never silently break the
financial proof.

### 11.1 Collaborator record, code, status (Phase 8)

| # | Test | Asserted |
|---|---|---|
| FT-C01 | `test_collaborator_code_is_generated_sequentially` | first record is `COL-1001`, second `COL-1002`; `collaborator.collaborator_code_next_number` advanced; changing the prefix setting changes only later codes |
| FT-C02 | `test_concurrent_creation_cannot_share_a_code` | two processes behind a latch create two collaborators: two distinct codes, zero exceptions surfaced, `uq_col_code` never violated in the response |
| FT-C03 | `test_collaborator_code_is_immutable` | `$collaborator->update(['collaborator_code' => 'COL-9'])` throws `ImmutableCollaboratorCodeException`; a raw UPDATE is caught by no constraint **and is therefore explicitly documented as out of scope** - the model hook is the guarantee |
| FT-C04 | `test_referral_code_is_locked_once_referenced` | a vanity change succeeds while untouched; after one `collaborator_referrals` row exists it throws `ReferralCodeLockedException`; the same after one visit row; the same after one ledger row; the failure names no other collaborator |
| FT-C05 | `test_wallet_is_created_with_the_collaborator` | creating a collaborator creates exactly one `collaborator_wallets` row **in the same transaction** (a rolled-back create leaves zero wallets); a second concurrent create cannot produce two wallets (`uq_cw_collaborator`) |
| FT-C06 | `test_status_transitions` | every row of §6.2.2 succeeds; `pending -> suspended`, `inactive -> pending` and `active -> pending` throw `InvalidStatusTransition`; a missing reason fails validation for `inactive` and `suspended`; each transition writes an `activity_log` row with old, new, actor, IP and reason |
| FT-C07 | `test_approval_seeds_commission_rules_and_provisions_login` | approval stamps `approved_at` / `approved_by`, sets `active`, creates two `collaborator_commission_settings` versions from Phase 2's defaults with `effective_from = today`, creates the user with the Collaborator role and `must_change_password`, and sends the invite; the password appears in no log, no response and no activity row |
| FT-C08 | `test_approval_with_the_setting_off_seeds_no_rules` | `seed_commission_rules_on_approval = false` -> zero rule rows, approval still succeeds |
| FT-C09 | `test_rules_are_not_seeded_when_the_module_is_disabled` | with `collaborator_commission_settings` disabled, approval succeeds, zero rule rows, one warning activity row (§1.4) |
| FT-C10 | `test_pending_and_suspended_collaborators_cannot_log_in` | a `pending` collaborator's user cannot authenticate; suspending an `active` one deletes their `sessions` rows and the next request is logged out by Phase 1's `active` middleware with the status message |
| FT-C11 | `test_skills_and_services_sync_is_idempotent` | submitting the same profile twice leaves one row per skill (`uq_cskill`) and one pivot row per service (composite PK); removing a skill deletes it; a service the catalogue does not contain fails validation |
| FT-C12 | `test_status_never_touches_money` | suspending, reactivating and soft-deleting a collaborator who has commissions changes **no** ledger row, **no** wallet column, and **no** `collaborator_referrals.commission_eligible`; `assertWalletMatchesLedger` still passes (INV-C4) |
| FT-C13 | `test_collaborator_with_history_cannot_be_force_deleted` | `forceDelete` is refused by the policy with a reason; a raw delete is refused by the spine's RESTRICT FKs; soft delete succeeds and the wallet, ledger, payouts and statement remain readable `withTrashed()`; soft delete is refused while an in-flight payout or a non-zero available balance exists |

### 11.2 Payout account security (Phase 8)

| # | Test | Asserted |
|---|---|---|
| FT-C14 | `test_payout_account_details_are_encrypted_at_rest` | the raw DB value of `details_encrypted` contains none of the submitted digits; the model decrypts correctly; `account_last4` holds exactly the last four |
| FT-C15 | `test_payout_account_details_never_reach_a_response` | the index, show, edit, export, print and JSON responses contain `•••• 1234` and **not** the full number; no route exists that returns it |
| FT-C16 | `test_payout_account_details_are_never_logged` | creating, editing, verifying and disabling write activity rows whose diff shows `[encrypted]`; a scan of `storage/logs` after the whole test class finds none of the digits; the same assertion for a thrown exception's context |
| FT-C17 | `test_exactly_one_default_account_per_collaborator` | a second default INSERT violates `uq_cpacc_default`; `makeDefault` switches atomically; a soft-deleted default frees the slot |
| FT-C18 | `test_payout_account_authorization` | without `collaborator_payout_accounts.view_any` -> 403 and nothing written; without `.change_status` -> 403 on verify; a collaborator reaching another collaborator's account -> **404** |
| FT-C19 | `test_disabling_an_account_in_use_is_refused` | an account referenced by an in-flight payout cannot be disabled; the error names the payout |

### 11.3 Panel, dashboard, isolation, activity (Phase 8)

| # | Test | Asserted |
|---|---|---|
| FT-C20 | `test_dashboard_figures_come_from_the_wallet_service` | every money tile equals `CollaboratorWalletService::derive()` to the paisa; forcing a wallet cache drift makes the panel render the **derived** figures behind the banner (spine §6.5.4) and never the stale cache |
| FT-C21 | `test_no_balance_is_computed_outside_the_two_services` | a static assertion over `app/Http/Controllers/{Admin,Collaborator}`, `app/Dashboard`, the exports and `resources/views/{admin,collaborator}` finds no `SUM(` over a spine table and no arithmetic on a money attribute (INV-C7, INV-26) |
| FT-C22 | `test_collaborator_sees_only_own_everything` | A requesting B's profile, skills, referral, visit, wallet, commission entry, payout, allocation, payout account or statement gets **404** on every route; id enumeration over each route; A's every list query contains zero B rows; A's activity feed contains zero rows with `collaborator_id = B` |
| FT-C23 | `test_portal_permissions_gate_columns_not_values` | without `.student_commission` the §57 response body contains no commission key at all (not a null, not a zero); the same for `.project_value`, `.project_payments`, `.student_fee_status`; without `.payout_request` a POST payout is 403 even with `payout_request_enabled = true` |
| FT-C24 | `test_other_roles_are_locked_out` | Teacher, Student, Client, HR, Project Manager, Institute Manager and Support Agent each get 403 on every route of §7.1-7.4; a Receptionist gets 200 on `collaborators.options` with exactly five keys per row and 403 on index, show, edit and every money route |
| FT-C25 | `test_money_columns_need_view_financial` | an admin with `collaborators.view_any` but not `.view_financial` receives an index response containing no lifetime-earned and no available-balance key |
| FT-C26 | `test_activity_log_shows_the_eleven_events_scoped` | each of §60's eleven events appears in the collaborator's feed with IP and device; a commission created by the engine (null causer) **does** appear, proving `activity_log.collaborator_id` is the filter; another collaborator's rows never appear; staff-written reasons about the collaborator are absent from their own feed |
| FT-C27 | `test_module_gating_hides_without_deleting` | disabling `collaborators` 403s the panel and the admin routes for Super Admin too, hides the sidebar items, and leaves row counts in `collaborators`, `collaborator_skills`, `collaborator_service` and every spine table identical before and after disable + re-enable |
| FT-C28 | `test_migrations_roll_back_cleanly` | each Phase 8/9 migration runs forward and back on a database holding rows (CHECKs and the `collaborator_service` guard included); `migrate:fresh --seed` is clean; the `services`-guarded migration is a no-op when the table is absent |
| FT-C29 | `test_activity_feed_renders_only_allowlisted_properties` | a staff-written `referral.decided` row carrying `override_reason`, a `status_changed` row carrying `reason`, and a row carrying an extra `properties` key not named by `CollaboratorActivityEvent::visibleProperties()` all appear in the collaborator's feed **with those keys absent from the response body** (not null, not blank); adding a new key to an event's `properties` without adding it to `visibleProperties()` keeps it invisible (F-12.7) |

### 11.4 Referral capture, precedence, tracking (Phase 9)

| # | Test | Asserted |
|---|---|---|
| FT-R01 | `test_referral_url_capture_records_a_visit_and_sets_the_carriers` | `GET /admission?ref=COL-1024` writes one visit (`outcome = captured`, landing path, device, `expires_at = now + 30d`), sets `session('referral.visit_token')`, and queues an encrypted `httpOnly` `ref_attr` cookie; a second hit with the same token and code increments `visits_count` instead of writing a row; `/courses/php-basics?ref=` and `/contact?ref=` behave identically |
| FT-R02 | `test_admission_attaches_the_captured_collaborator` | submitting the admission form with only the cookie creates exactly one `active` `collaborator_referrals` row with `referral_source = referral_link`, the code snapshotted, `referral_visit_id` set, `effective_from` = the admission date clamped to today; the visit is `converted`; a second submission cannot create a second active referral (`uq_cr_student_current`) |
| FT-R03 | `test_staff_selection_outranks_every_captured_candidate` | with a cookie naming A, a receptionist picking B produces **B** active (`manual_selection`) and **A** as a `superseded` row with `effective_to = today`, `superseded_by_id` = B's row, the override reason and `changed_by`; the activity row carries the full `referral_decision` JSON of §6.3 |
| FT-R04 | `test_override_requires_a_reason` | with `referral_override_reason_required = true` the same submission without `referral_override_reason` fails validation and writes **nothing**; with the setting false it succeeds and still records both candidates |
| FT-R05 | `test_a_forged_hidden_field_cannot_attribute` | posting `referral_visit_token` = a random ULID, an expired token, another visitor's token paired with a mismatched session, or a raw **code** in the token field attributes nobody; a tampered cookie fails Laravel's decryption and is ignored; the form still submits successfully with no referral |
| FT-R06 | `test_precedence_ladder_matrix` | all six ranks exercised in every combination: each rank wins only when every higher rank is absent; the resolved `referral_source` matches §6.3; an ineligible collaborator at one rank falls through to the next instead of attributing |
| FT-R07 | `test_attribution_model_chooses_the_visit` | a visitor with two unexpired visits (A then B): `last_touch` attributes **B**, `first_touch` attributes **A**; an expired visit is never chosen and its cookie is cleared |
| FT-R08 | `test_ineligible_and_abusive_candidates_never_attribute` | an unknown code -> visit `invalid_code`, no referral, the form still submits; a `suspended` collaborator's code -> `collaborator_not_eligible`; a collaborator's own logged-in click -> `self_referral`; a bot user agent -> `bot_filtered`; `referral_system_enabled = false` -> capture still records a visit but no referral is attached, and the later payment skips with the spine's `referral_system_disabled` |
| FT-R09 | `test_snapshot_columns_are_display_only` | after attach and after change, `students.collaborator_id` / `.referral_code` mirror the **active** referral; corrupting the snapshot by hand changes **no** commission outcome for a subsequent payment (the engine resolves the referral table); `collaborators:sync-referral-snapshots` repairs it and reports the repair (INV-R1) |
| FT-R10 | `test_changing_attribution_preserves_earned_commission` | A earned 1,000 on a receipt; switching to B with a reason leaves A's ledger row byte-identical and still pointing at A and at referral #1; the old referral is `superseded` with `effective_to = today` and `previous_referral_id` / `superseded_by_id` / `change_reason` / `changed_by` populated; the open entitlement is `superseded`; **no ledger row points at B**; `assertWalletMatchesLedger` passes for both |
| FT-R11 | `test_change_requires_permission_and_reason_and_is_audited` | without `collaborator_referrals.edit` -> 403 and nothing written; an empty or 9-character reason -> validation error and nothing written; success writes one `activity_log` row holding **old and new collaborator names and codes** plus the reason, the actor and the IP (§37, §107) |
| FT-R12 | `test_payment_dates_decide_who_earns_after_a_change` | a receipt dated before the switch earns for **A** even when keyed in after it; one dated on or after `effective_from` earns for **B**; attribution cannot be back-dated (the service always starts the new row today) |
| FT-R13 | `test_revoke_stops_future_commission_and_keeps_history` | `revoke` sets `revoked`, `effective_to = today`, `commission_eligible = false`; a later payment skips with `referral_not_commission_eligible`; every earlier entry, wallet figure and statement line is unchanged |
| FT-R14 | `test_manual_linking_for_every_subject` | student, project, client and lead each attach with `collaborator_referrals.create`; a second active attach on the same subject violates the spine's unique guard and surfaces as a domain error naming the existing collaborator, not a 500; a `suspended` collaborator cannot be selected at all |
| FT-R15 | `test_visit_pruning_never_destroys_evidence` | `referrals:prune-visits` deletes an old unconverted visit and **refuses** to delete a converted one, one referenced by `collaborator_referrals.referral_visit_id`, or one behind a ledger row (INV-R6) |
| FT-R16 | `test_referral_validate_endpoint_leaks_nothing` | a valid code returns `{valid: true, code, collaborator_name}`; an unknown code and an inactive collaborator's code both return `{valid: false}` with **identical** bodies (no existence oracle); no id, email, phone, status or rate in any response; the 11th request in a minute is 429 |
| FT-R17 | `test_project_override_is_surfaced_on_a_change` | changing a project's collaborator while `projects.commission_type` is set renders the override on step 2 and, when *Clear override* is ticked, writes an audited edit of the project row requiring `projects.edit`; untouched, B inherits the override and the screen said so |
| FT-R18 | `test_conversion_report_totals_match_the_register` | clicks, attributable clicks and conversions in the report equal the filtered register counts; the dead-code panel lists exactly the codes with visits and no collaborator; CSV and PDF exports match the screen |

---

## 12. Risks and open questions

### 12.1 Risks accepted, with the mitigation

| # | Risk | Mitigation / why accepted |
|---|---|---|
| R-1 | **Build order.** Phases 8-9 precede the phase that ships the spine's tables, so an incautious team could build a stub `collaborator_wallets` or write `collaborator_referrals` directly - which would fork the financial spine on day one. | §1.4 fixes the release order, §1.3 states the forbidden list, the observer and the rule-seeding both degrade to a logged warning, and two idempotent backfills exist. FT-C09 and FT-C27 assert the degraded path. |
| R-2 | **Referral attribution is a commercial dispute waiting to happen**: a partner who sees a click but no commission will ask why. | Every negative outcome is a typed, groupable reason on the visit (`ReferralVisitOutcome`) or on the payment (the spine's `CommissionSkipReason`); §8.6's report and dead-code panel make it answerable before the partner calls. |
| R-3 | **Cookie-based attribution is fragile by nature** - cleared cookies, private windows, a different device between the click and the walk-in admission. | Three carriers (server session, encrypted cookie, server-rendered hidden token) plus the applicant-typed code plus the staff pick. When all fail the honest result is **no attribution**, which §39's "no collaborator reference means no commission" already makes safe. |
| R-4 | **`last_touch` as the default can take a student from the partner who first introduced them.** | Settings-driven (`referral_attribution_model`), both visits are visible in the register, and the staff pick outranks both. Raised as Q3. |
| R-5 | **Visit rows carry IP addresses and user agents** - personal data with a 365-day default retention and no consent banner anywhere in the requirement. | Retention is a setting, pruning is scheduled, IPs are masked to `/24` unless the viewer holds `view_logs`, and the whole register is behind its own module and permission. Raised as Q6. |
| R-6 | **A vanity referral code is attractive and dangerous**: a partner asking to change `COL-1024` to `ACME` after six months of clicks would orphan every printed flyer and every stored snapshot. | INV-C2 locks the code the moment any referral, visit or ledger row exists; the only window is before first use; `referral_code_editable` can close it entirely. |
| R-7 | **`admin.collaborators.options` is a deliberate information surface** for receptionists, and a wide one if the payload ever grows. | Five keys, `suspended` and trashed excluded, its own permission, throttled, and FT-C24 asserts the exact key set so a future "just add the email" cannot pass CI. |
| R-8 | **The public `validate` endpoint confirms that a code exists** (by design - §38 preselects the collaborator and shows the name). | Unknown and ineligible codes return byte-identical bodies, the name is settings-gated, and the endpoint is throttled; nothing else about the partner is exposed. FT-R16. |
| R-9 | **Two snapshot stores for one fact** (`students.collaborator_id` beside `collaborator_referrals`) is exactly the duplication §109 warns about. | One writer (`SyncReferralSnapshot`), one truth (the referral table), a daily re-derivation job, and INV-R1 + FT-R09 asserting the snapshot cannot change a commission outcome. The columns exist because §37 and §66 name them and because every student list must show "referred by" without a join. |
| R-10 | **`collaborator_skills` without soft deletes**, and the next developer may "fix" it - reintroducing the unique-index collision on re-add. | It is not a deviation: `CLAUDE.md` §3's category rule (**D19**) puts history pivots in the no-`deleted_at` set, and FT-C11 fails if soft deletes return. |
| R-11 | **The collaborator dashboard bypasses Phase 2's widget registry**, so two dashboard implementations now exist and can drift. | [D-P8-5]: the registry's contract has no panel dimension and changing another phase's contract is out of bounds here. The panel reads the same two services (INV-C7), and §13 requests the optional `panel()` addition for a later convergence. |
| R-12 | **The commission-rule screen is built in Phase 8 against a Phase 10 service.** | Module gating makes the screen structurally unreachable until the spine is installed; the screen never writes the table, only calls `CommissionRuleService`. |

### 12.2 Open questions for the client (defaults assumed, nothing blocked)

| # | Question | Default assumed |
|---|---|---|
| Q1 | Is the collaborator's **ID** and their **referral code** the same string, as §38's `COL-1024` example reads? | **Yes** - `referral_code` is initialised equal to `collaborator_code`; a vanity code is allowed only before first use, and only when `referral_code_editable` is on. |
| Q2 | Should managing one's own payout **destination** be gated by a permission separate from **requesting** money? Spine §7.5 gates `collaborator.payout-accounts.*` with `collaborator_portal.payout_request`, which Phase 1 §5 does **not** grant the Collaborator role - so by default a collaborator cannot register a bank account. | **Yes, separate**: `collaborator_portal.payout_accounts` is registered here and granted to the role. The spine's routes keep their existing gate until Phase 12 adopts it (§13) - recorded rather than silently re-gated. |
| Q3 | First-touch or last-touch attribution when a visitor clicks two partners' links? | **`last_touch`**, settings-driven, with both visits visible and the staff pick outranking both. |
| Q4 | Must a receptionist who overrides a tracked referral give a **reason**? | **Yes** (`referral_override_reason_required = true`). Overriding a partner's tracked link without a written reason is the single most disputable act in the referral system. |
| Q5 | How long should the attribution window be? | **30 days** (`referral_cookie_days`), matching the default back-date window so the two do not contradict each other. |
| Q6 | Is a 365-day retention of referral-visit IPs and user agents acceptable, and is a cookie notice needed on the public site? | **365 days**, prunable, IPs masked in the UI. No consent banner is designed, because the requirement defines none - flagged for a legal decision before launch. |
| Q7 | May a staff member attribute a **pending** or **inactive** collaborator (a partner not yet approved, or paused)? | **Yes, behind an explicit confirm** - attribution is a fact and the spine's guard step 4 decides earning per payment. **`suspended` is refused outright.** |
| Q8 | Should `minimum_payout`, `commission_hold_days` and `fixed_commission_release` be overridable **per collaborator**? §35's per-collaborator list does not include them, and the spine defines all three as global `collaborator.*` settings snapshotted onto each entitlement. | **No per-collaborator override.** They stay global settings, read exactly as the spine defines them; `collaborator_commission_settings` already carries the per-collaborator `fixed_release`, `base_override`, `min_payment_amount` and `max_commission_amount`. |
| Q9 | Should a collaborator be notified when a subject is moved to another collaborator? | **No.** §97 does not list it and it is a commercial conversation; the change is fully audited and visible to staff. |
| Q10 | May a collaborator's panel show the **other** partner on a superseded referral of theirs? | **No** - a superseded row shows the date, the reason category and nothing about the successor, so the panel can never become a competitor directory. |

---

## 13. Requests to other phases

Stated as `table.column - why`, plus the behavioural asks.

### 13.1 Subject snapshot columns (written only by `SyncReferralSnapshot`, read only for display - INV-R1)

| Request | Why |
|---|---|
| `students.collaborator_id` FK nullable `nullOnDelete`, `.referral_code` string(32), `.referral_source` string(32), `.referral_date` date, `.referral_visit_id` FK nullable - **Phase 15** | §37 names all four verbatim; §66 names "referred by collaborator" and "referral code". Display snapshot only - the engine resolves `collaborator_referrals` on the payment date |
| `student_admissions.collaborator_id`, `.referral_code`, `.referral_visit_id` - **Phase 15** | §69's admission record lists the collaborator; it is also the spine's default commission document grain |
| `course_inquiries.collaborator_id`, `.referral_code`, `.referral_visit_id` - **Phase 14-17** | §86 lists *referral* as an inquiry source; the referral must survive inquiry -> admission, and `collaborator_referrals` has no inquiry subject. **`referral_visit_id` is satisfied**: phase-14-17 §2.11 adds it nullable + indexed with the deferred guarded FK (F-3.14) |
| `contact_inquiries.collaborator_id`, `.referral_code`, `.referral_visit_id` - **Phase 4** | §17's public form is the client / project-inquiry entry point of §38. **Phase 4 owns `contact_inquiries`** (F-2.1, resolutions §2.1); Phase 3 owns only the `contact` *section* rendering, so the ask is addressed to Phase 4 |
| `leads.referral_visit_id` FK nullable `nullOnDelete` + `.referral_code_captured` string(32) - **Phase 5** | §18's lead source *Referral*; `collaborator_referrals.lead_id` already exists in the spine. Phase 5's design wins (F-3.5): the evidence id is kept, the code snapshot is named `referral_code_captured`, and **there is no `leads.collaborator_id`** - a lead's attribution is resolved through `collaborator_referrals`, never through a column on `leads` (D37) |
| `clients.referral_code_captured` string(32) + `.referral_recorded_at` - **Phase 5** | §45's client-side attribution; `collaborator_referrals.client_id` already exists. Phase 5's design wins (F-3.4): **no `clients.collaborator_id`** - the snapshot is the captured code plus the timestamp, and every scope and engine read goes through `collaborator_referrals` (D37) |
| `projects.collaborator_id`, `.referral_code`, `.referral_date` - **Phase 6** | §20 "referred by collaborator", §45 names the three fields. The three commission-override columns are already requested by the spine §13.1 |

### 13.2 Behaviour and structures needed from other phases

| Request | Why |
|---|---|
| **Spine / Phase 10** *(satisfied - F-4.3)*: `ReferralService::attach(Model $subject, Collaborator, ReferralSource, ?string $code, ?CarbonInterface $on, ?ReferralContext $context = null)`, with `App\DataObjects\Collaborator\ReferralContext` (readonly: `?int $referralVisitId`, `?string $landingUrl`, `?string $ipAddress`, `?string $userAgent`, `?CarbonInterface $referralDate`, `?string $notes`) published at spine §13.2 | §38 click evidence reaches the attribution row through the DTO; this phase always passes one (§6.5) |
| **Spine / Phase 10** *(satisfied - F-4.4)*: `ReferralService::recordLosingCandidate(CollaboratorReferral $winner, Collaborator $loser, ReferralContext $ctx, string $reason): CollaboratorReferral` is published by the spine §6.2 | spine §2.8 specifies that the loser of a URL-versus-receptionist race is stored as a superseded row; only the spine may write the table, so the spine exposes the call and §6.3 modifier 7 invokes it (INV-R3) |
| **Spine / Phase 12**: `PayoutService::request()` / `createFor()` should refuse an unverified destination when `collaborator.payout_account_verification_required` is true | §55 "sensitive payout data must be protected"; the setting and the verification UI are Phase 8's, the guard list is the spine's (§6.4.2) |
| **Spine / Phase 12**: adopt `collaborator_portal.payout_accounts` as the gate for `collaborator.payout-accounts.*` | Q2 - otherwise the Collaborator role cannot register a bank account |
| **Phase 1**: `PermissionRegistry` gains the two module slugs of §4.1, the ability additions of §4.2 and the six portal permissions of §4.3; `RoleSeeder` grants them per §4.3 | D4 - the registry is the only place a permission name exists |
| **Phase 1**: `LogsActivityWithContext::tapActivity()` also fills `activity_log.collaborator_id` from the causer's collaborator profile when one exists | §60, so eight of the eleven events need no extra code |
| **Phase 1**: `App\Support\Device` is reused unchanged for `collaborator_referral_visits` | no second user-agent parser |
| **Phase 2**: `SettingsRegistry` gains the sixteen `collaborator.*` keys of §5; `DashboardRegistry` gains the five widgets of §8.9 | settings definitions in code, values in the DB |
| **Phase 2** *(optional, R-11)*: `DashboardWidget` gains `panel(): PanelType` so the collaborator dashboard can converge on the registry | one widget framework instead of two |
| **Phase 4**: `services.id` - the `collaborator_service` pivot target; a service must not be hard-deletable while a collaborator references it | §34 "services" |
| **Phase 5**: `leads.id`, `clients.id`; the lead and client screens host the *Referred by* panel and the change wizard | §18, §45 |
| **Phase 6**: `project_members.collaborator_id` (§20 "collaborators" on a project) and `tasks.assigned_collaborator_id` (§22 "assigned collaborator") - both needed by §36's *assigned projects* and *assigned tasks* tiles and by §59's task permissions. **No `project_collaborator` pivot is requested or created** (F-2.7): `project_members` is the only project-people table and this phase reads it through `assignedProjects` (§2.1) | §20, §22, §36 |
| **Phase 6**: clearing a project's commission override after an attribution change is an audited edit requiring `projects.edit` | §6.5 rule 7, FT-R17 |
| **Phase 15**: the admission form must render `<x-site.referral-field>`, pass the staff pick + typed code + the override reason into `ReferralAttributionResolver`, and call `ReferralService::attach()` - never write `collaborator_referrals` or a snapshot column itself | §67, §68, INV-R1, INV-R2 |
| **Phase 22**: `meetings` and `messages` must accept a collaborator participant, and §59's `collaborator_portal.files_upload` / `files_download` resolve to **`attachments` rows owned by the collaborator** - Phase 6's `attachments` with `Collaborator` in the morph map, gated by `attachments.visibility`; the `files` module slug governs that table and **no `files` table is ever created** (F-13.2, F-2.8) | §94, §95, §96, and four §36 tiles |
| **Phase 23**: the §99 collaborator performance, referred-students and referred-projects reports must call `CollaboratorStatementService` / `CollaboratorWalletService` and `ReferralTrackingService::funnel()` - never re-implement a sum | INV-C7, INV-26 |
| **`DEVELOPMENT_LOG.md` §4**: cite **D37** (allocated by `docs/design/resolutions.md` §4.1; this contract's former "D19") - `collaborator_referrals` is the single truth of attribution; every `*.collaborator_id` / `*.referral_code` on a subject table is a display snapshot written by one listener, read by no engine and **used by no access scope** | INV-R1, R-9, F-10.1 |
| **`DEVELOPMENT_LOG.md` §4**: cite **D38** (formerly this contract's "D20") - a referral decision is made by a documented six-rank precedence ladder in which an authenticated staff selection always wins, the losing candidate is preserved as a superseded referral row, and nothing the browser posts is trusted as a code | INV-R2, INV-R3, F-10.1 |
| **`DEVELOPMENT_LOG.md` §5** *(satisfied - F-11.2)*: the tracker line under Phase 8 recording that the spine's migration set is applied in the same release, immediately after Phase 8's migrations, and that the two backfills run once afterwards (resolutions §6.3; echoes spine §12.2 Q10) | §1.4 |
| **`CLAUDE.md` §3** *(satisfied - F-9.1)*: the soft-delete **category** rule is pasted into `CLAUDE.md` §3 and numbered **D19**; `collaborator_skills`, `collaborator_service` and `collaborator_referral_visits` are covered by it as history-pivot / log tables, so this contract records no local exception | D19 |
| **Phase 2** *(this contract owns the allowlist)*: no ask - `CollaboratorActivityEvent::visibleProperties()` (§3.1) is declared here and a collaborator's feed renders nothing outside it (F-12.7, FT-C29) | §60 |

---

## Convergence log (2026-09-12)

Applied from `docs/design/resolutions.md` §3 (apply-map row for this file) plus §2 ownership maps.

| Finding | Change made |
|---|---|
| F-2.1 | §13.1 `contact_inquiries.*` snapshot ask retargeted **Phase 3 -> Phase 4** (Phase 4 owns the table and the §17 routing). |
| F-2.7 | §1.2, §2.1, §8.7, §9, §13.2: `project_collaborator` deleted everywhere; `assignedProjects` is now `belongsToMany(Project::class, 'project_members')->wherePivotNull('deleted_at')->wherePivotNotNull('collaborator_id')`; the §13.2 ask is `project_members.collaborator_id` and states no pivot is created. |
| F-3.4 | §13.1: `clients.collaborator_id` ask **deleted**; retargeted to `clients.referral_code_captured` + `.referral_recorded_at` (Phase 5's design). §10.2 listener line updated. |
| F-3.5 | §13.1: `leads.collaborator_id` ask **deleted**; `leads.referral_visit_id` kept and `referral_code` retargeted to `referral_code_captured`. INV-R6 and §10.4's prune job now also exclude rows `leads.referral_visit_id` points at. |
| F-3.14 | §13.1: `course_inquiries.referral_visit_id` marked satisfied by phase-14-17 §2.11; the row now names **Phase 14-17** instead of "Phase 15". |
| F-4.1 | §1.2, §2.1, §5, §6.1: `DocumentNumberService` moved out of the spine dependency row and retargeted to **Phase 5's** `App\Services\Finance\DocumentNumberService`, with this phase passing its own `'%04d'` pad explicitly (D27). |
| F-4.3 | §6.5 and §13.2: `attach()` is the six-parameter form with `?ReferralContext $context = null`; the DTO's six readonly properties are named and this phase always passes one. Ask marked satisfied. |
| F-4.4 | INV-R3, §6.3 modifier 7, §13.2: the losing candidate is written by the spine's published `ReferralService::recordLosingCandidate($winner, $loser, $ctx, $reason)` (status `superseded`, `commission_eligible = false`, reason mandatory), never by this phase. Ask marked satisfied. |
| F-9.1 | §2.2, §2.3, §2.4, §6.2, R-10, §13.2: local soft-delete decisions **[D-P8-4]** and **[D-P9-1]** replaced by citations of **D19**'s category rule; `collaborator_service` gained the explicit no-`deleted_at` line; the `CLAUDE.md` ask is marked satisfied. |
| F-12.7 | §3.1 `CollaboratorActivityEvent` gains `visibleProperties(): array`; §6.6's feed renders only allowlisted `properties` keys and never `reason`; new test **FT-C29** asserts an unlisted key is absent from the response body. |
| F-13.1 | §3.2: note added - no REST API, no `routes/api.php`, no token guard in this release; the spine's `ReferralSource::api` case stays reserved; idempotency keys are generated server-side (H1). |
| F-13.12 | §2.4 `ip_address` now names `collaborator.referral_visit_retention_days` (default 365, §5) as the shortenable retention control; §10.4's prune row cites it. |
| F-10.1 | §13.2: this contract's claimed **D19 -> D37** and **D20 -> D38** per resolutions §4.2; INV-R1 now cites D37 and carries D37's "used by no access scope" clause. |
| F-13.2 / F-2.8 *(ownership map §2.1)* | §1.2 and §13.2: the `files` **table** reference deleted - §59's `collaborator_portal.files_upload` / `files_download` resolve to Phase 6's **`attachments`** rows owned by the collaborator, gated by `attachments.visibility`; no `files` table is ever created. |

**Noted, not applied as worded:** F-12.7 asks for the allowlist to be added to "its §13.2". `CollaboratorActivityEvent` is declared by this contract (§3.1) and §13.2 here lists only asks to *other* phases, so the allowlist was declared at §3.1 and §13.2 records that there is no ask. No guarantee changed.
