# PHASE 6 CONTRACT - Projects, milestones, tasks, time tracking

**Status: binding.** Requirement sections **20, 21, 22, 23** (`../requirements.md` C). Conventions come from
[`../../CLAUDE.md`](../../CLAUDE.md); [`phase-01.md`](phase-01.md) and [`phase-02.md`](phase-02.md) win over
anything here and a suspected error in them is recorded in §12.2, never silently redesigned. Where this
phase touches money or referral attribution it obeys
[`../design/finance-commission-spine.md`](../design/finance-commission-spine.md) (the Phase 10 spine), which
also wins over this document; every column the spine asks Phase 6 for in its §13.1 is delivered here and
marked **[spine]**.

Decisions are labelled **[D-P6-n]** so a code review can cite them.

---

## Contents

| Section | Contents |
|---|---|
| 1 | Goal, dependencies, ownership, invariants |
| 2 | Schema (11 tables), relationships, status lifecycles, guard indexes |
| 3 | Enums |
| 4 | PermissionRegistry additions |
| 5 | SettingsRegistry additions (new `projects` group) |
| 6 | Services, the progress algorithm, the timer algorithm |
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

After this phase the software house can run delivery end to end: register a project against a client with
its contract value, priority, deadline, the collaborator who referred it and the commission rate that
referral earns; staff a team of employees **and** collaborators with per-project roles; break the work into
milestones and into tasks with subtasks, checklists, comments with @mentions, attachments and a complete
activity history; move work across a drag-and-drop Kanban board; watch progress roll up from checklists to
tasks to milestones to the project without anyone typing a percentage; and capture effort with a
start/pause/stop timer (exactly one running per person, enforced by the database) or a manual entry, then
read it back as daily, weekly, per-employee, per-collaborator and per-project hours. Every change to a
project's value is an append-only, reasoned revision - so when Phase 10/11 pays commission on a received
client payment, the value it paid against can always be proved.

### 1.2 Dependencies

| Phase | What this phase needs from it | Status |
|---|---|---|
| 1 | `users`, RBAC + `PermissionRegistry` + `Gate::before` module gating, `activity_log` with old/new values + `LogsActivityWithContext`, `App\Support\Money`, `Blameable`, `Sidebar`, `layouts/admin`, `layouts/panel`, the `x-ui.*` set | required |
| 2 | `SettingsRegistry` + `SettingsService`, `DashboardRegistry`, `DateRange`, `Format` (`money()`, `app_date()`, `app_datetime()`), per-user `users.preferences` | required |
| 5 | `clients` (`id`, `user_id`), `leads` (`id`) | required |
| 7 | `employees.user_id` - the HR profile behind an assignable staff user | **later** ([D-P6-2], **D32**) |
| 8 | `collaborators` (`id`, `status`, `referral_code`, `user_id`, soft deletes) | **later** (guarded FKs, §2.1) |
| 9 / 10 | `Collaborator\ReferralService` + `collaborator_referrals` - the authoritative attribution record | **later** ([D-P6-5]) |
| 10 / 11 | `project_payments`, `ProjectCommissionService` - consumers of `projects.net_value`, `projects.commission_*`, `project_milestones.amount`, `project_value_revisions` | **later** |
| 13 | `invoices` - a milestone may later be invoiced; no Phase 6 column depends on it | **later** |

**[D-P6-1] Migration order and guarded foreign keys.** Phase 6 migrates before phases 7, 8, 9 and 10, so
every FK whose target table does not exist yet is declared as a plain indexed `unsignedBigInteger` column
now and promoted to a real constraint by a separate `*_add_collaborator_fks_to_project_tables.php` migration
**shipped by Phase 8** and guarded with `Schema::hasTable('collaborators')`. This mirrors the spine's
[D-FS-1] instead of inventing a second convention. The four columns waiting on that migration are
`projects.collaborator_id`, `project_members.collaborator_id`, `tasks.assigned_collaborator_id` and
`time_entries.collaborator_id` / `time_entry_segments.collaborator_id`.

**[D-P6-2] Work is assigned to a `users` row, never to an `employees` row (D32).** The system-wide rule
this decision becomes: **assignment of work -> `users.id`; organisational duty -> `employees.id`; the bridge
is `employees.user_id` (nullable, unique)** - it binds phases 7, 13 and 14-17 as well (F-11.1).
§20 and §22 say "employees",
but `employees` is an HR profile created in Phase 7 (D2: domain profiles are separate tables with a nullable
`user_id`), while the thing that logs in, holds permissions, receives a notification and starts a timer is a
user. Assignment columns therefore point at `users.id`; the picker is labelled "Employee" and is filtered to
users holding an `admin`-panel role, and the HR profile is reached through `employees.user_id` when Phase 7
exists. A project member who is a **collaborator** is stored as `collaborator_id` instead, because a
collaborator record may legitimately exist with no login (D2) and because §23 demands collaborator hours as
a separate rollup.

### 1.3 Ownership - no table is created twice

| Phase | Owns | Must NOT create |
|---|---|---|
| **6** | the 11 tables of §2, all 13 enums of §3, `App\Enums\CommissionCalculationType` (the spine's exact cases - see §3), `Project*`/`Task*`/`Timer*` services, every screen in §8 | `collaborator_referrals`, `project_payments`, `invoices`, `employees`, `collaborators` |
| **7** | the `employees` profile and the FK promotion for nothing here; it **reads** `project_members` for workload | any Phase 6 table |
| **8** | `collaborators` + the guarded FK migration of [D-P6-1] | any Phase 6 table |
| **9/10** | `collaborator_referrals` + `ReferralService`; the listener that turns `ProjectReferralLinked` into a referral row | `project_value_revisions` |
| **11/13** | `project_payments` screens, invoicing of milestones | `project_milestones`, `projects` |
| **22** | tickets, meetings, messaging; it **extends** `attachments` (§2.9) rather than creating a `files` table | `attachments`, `task_comments` |

**`project_members` is the only project-people table** (F-2.7). No `project_collaborator` and no
`project_user` pivot exists anywhere in the system: a later phase that needs a collaborator's projects uses
`belongsToMany(Project::class, 'project_members')->wherePivotNull('deleted_at')->wherePivotNotNull('collaborator_id')`
against this table (see §9 and phase-08-09 §2.1).

### 1.4 Invariants

| # | Invariant | Enforced by |
|---|---|---|
| INV-P1 | A project's `project_value`, `discount_amount` and commission override can only change through `ProjectValueService::revise()`, which writes one append-only `project_value_revisions` row with a **mandatory reason** per change. A plain `update()` that touches those columns throws. | Model `updating` hook + `chk_pvr_*`; P6-22, P6-23 |
| INV-P2 | `projects.net_value` is a **STORED generated column** (`project_value - discount_amount`), never written by PHP, so the spine's project commission denominator cannot drift. | Migration raw SQL; P6-03 |
| INV-P3 | No financial row is ever deleted: `project_value_revisions` has no `deleted_at` and a `BEFORE DELETE` trigger raising `SIGNAL SQLSTATE '45000'`. | `trg_pvr_no_delete`; P6-04, P6-05 |
| INV-P4 | **At most one running timer per worker**, for the life of the database, decided by a unique INSERT and never by a SELECT-then-INSERT. | `uq_te_running`, `uq_tes_open`; P6-28, P6-29, P6-32 |
| INV-P5 | Timer time is stored **only** as append-only start/stop segment rows. There is no mutable running total: while a timer runs the open segment has `ended_at IS NULL` and contributes zero, and the live figure on screen is `cached seconds + (now - open segment start)`, computed client-side and never persisted. | `time_entry_segments`; P6-30, P6-31 |
| INV-P6 | Every duration cache (`time_entries.duration_seconds`, `tasks.actual_seconds`, `projects.actual_seconds`, `tasks.checklist_*`, `tasks.comment_count`, `tasks.attachment_count`) is **recomputed by SUM/COUNT under a row lock**, never incremented. A cache is therefore self-healing and a replayed job cannot inflate it. | `TimeRollupService`, `TaskCacheService`; P6-30, P6-34, P6-44 |
| INV-P7 | `actual_hours` and `actual_minutes` are STORED generated columns over `actual_seconds`; `estimated_hours` is generated over `estimated_minutes`. §22's `actual_hours` field exists and is unwritable. | Migration raw SQL; P6-35 |
| INV-P8 | `progress_percent` on a task, milestone or project is written **only** by `ProjectProgressService`. Any other writer throws. In `auto` mode the value is derived by §6.3; in `manual` mode it is set by a human, with a reason, and the project row records `progress_mode = manual` and `progress_set_by`. | Model `updating` hook; P6-13..P6-21 |
| INV-P9 | A cancelled task, subtask or milestone is excluded from every progress denominator - it is never counted as 0 %. | §6.3; P6-17 |
| INV-P10 | Assignment is single-valued and typed: a task has **at most one** of `assigned_user_id` / `assigned_collaborator_id`; a `project_members` row has **exactly one** of `user_id` / `collaborator_id`. | `chk_tasks_assignee`, `chk_pm_one_party`; P6-09, P6-10 |
| INV-P11 | At most one **active** membership per (project, person); removing a member soft-deletes the row so team history survives. | `uq_pm_user_active`, `uq_pm_collaborator_active`; P6-11 |
| INV-P12 | Subtasks are exactly **one** level deep: `depth IN (0,1)` and `depth = 1` exactly when `parent_task_id` is set. | `chk_tasks_depth`; P6-08 |
| INV-P13 | Attribution (`collaborator_id`, `referral_code`, `referral_date`) may be set freely while the project has no `collaborator_referrals` row and no `project_payments` row; after either exists it changes **only** through the spine's `ReferralService::change()` with a mandatory reason, and no existing ledger row is ever re-pointed. | `ProjectReferralService`; P6-25 |
| INV-P14 | Money and percentage arithmetic - project value, discount, milestone amount, every weighted progress average - goes through `App\Support\Money` (bcmath). No PHP `+ - * /` touches a money or percentage value. | Code review + P6-55 (static scan) |
| INV-P15 | A client and a collaborator reach a project only through their own relation (`clients.user_id`, `collaborators.user_id`); every list is scoped by a global scope plus a policy, ownership failures return **404, not 403**, and a money column the viewer may not see is **absent from the query**, not blanked. | §9; P6-47..P6-53 |
| INV-P16 | Every status transition, assignment, value revision, attribution change, member change, time-entry edit and discard writes an `activity_log` row with old/new values, actor, IP and - where the act is discretionary - a **mandatory reason**. | `LogsActivityWithContext::withReason()`; P6-27 |
| INV-P17 | Three guarantees rest on MariaDB features Laravel's schema builder does not express (STORED generated columns, enforced CHECK constraints, unique indexes over generated columns). Those migrations write raw SQL and must **fail loudly** if the server rejects them, never fall back silently. | Migrations + P6-02, P6-54 |

---

## 2. Schema

All tables InnoDB / utf8mb4. Unless a row says otherwise every table carries `timestamps`, `softDeletes`
and the blameable pair `created_by` / `updated_by` (nullable FK `users.id`, `nullOnDelete`) per
`CLAUDE.md` §3. **No Phase 6 table carries `branch_id`**: D11 scopes branch-readiness to the institute
tables, and if the software house later opens branches that is one additive nullable column per table.

Eleven tables: `projects`, `project_value_revisions`, `project_members`, `project_milestones`, `tasks`,
`task_checklist_items`, `task_comments`, `task_comment_mentions`, `attachments`, `time_entries`,
`time_entry_segments`.

### 2.1 `projects`

§20, plus the five columns the spine's §13.1 requires.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `code` | string(32) | not null | §20 "Project ID". `projects.project_code_prefix` + zero-padded counter; UNIQUE |
| `name` | string(200) | not null | |
| `client_id` | FK `clients.id` | not null | `restrictOnDelete` - a project is always somebody's work, and the spine denormalises `client_id` onto every payment |
| `lead_id` | FK `leads.id` | nullable | `nullOnDelete`; set when Phase 5 converts a `Won` lead (§18). Nothing here requires it |
| `project_manager_id` | FK `users.id` | nullable | `nullOnDelete`, index - §20 |
| `service_id` | FK `services.id` | nullable | `nullOnDelete` (Phase 4, §11) - the kind of work, so it is not duplicated as free text |
| `collaborator_id` | unsignedBigInteger | nullable | index - §20 "referred by collaborator", §45. FK added by Phase 8 ([D-P6-1]). A **display snapshot only** (R5, D37): no scope and no commission engine reads it; the authority is an `active` `collaborator_referrals` row (§9, F-8.2) |
| `referral_code` | string(32) | nullable | **SNAPSHOT** of the code used at linking (§45); a later code change never rewrites it |
| `referral_date` | date | nullable | §45 |
| `commission_type` | string(16) | nullable | **[spine]** cast `CommissionCalculationType`; non-null means "project override" in the spine's §6.1.1 precedence |
| `commission_rate` | decimal(8,4) | nullable | **[spine]** percent, e.g. `15.0000` (§45) |
| `commission_fixed_amount` | decimal(15,2) | nullable | **[spine]** |
| `description` | text | nullable | |
| `project_type` | string(32) | not null, default `fixed_price` | cast `ProjectType` - the engagement model (§12.2 Q1) |
| `priority` | string(16) | not null, default `medium` | cast `Priority`, index |
| `status` | string(32) | not null, default `planning` | cast `ProjectStatus`, index - the eight statuses of §20 |
| `start_date` | date | nullable | §20 |
| `deadline` | date | nullable | §20, index |
| `completed_on` | date | nullable | stamped when status becomes `completed`; feeds §12 portfolio completion date |
| `currency` | string(3) | not null, default `PKR` | constant from `localization.currency`; no multi-currency (spine R-12) |
| `budget_amount` | decimal(15,2) | not null, default `0.00` | §20 "budget" - what the business allocates to deliver |
| `project_value` | decimal(15,2) | not null, default `0.00` | **[spine]** §20 - the contract value; the denominator for commission base `total_value` |
| `discount_amount` | decimal(15,2) | not null, default `0.00` | **[spine]** |
| `net_value` | decimal(15,2) | **generated STORED** | **[spine]** `project_value - discount_amount` (INV-P2) |
| `value_revision_count` | unsignedSmallInteger | not null, default 0 | cache of `project_value_revisions` rows; recomputed, never incremented |
| `progress_percent` | decimal(8,4) | not null, default `0.0000` | written only by `ProjectProgressService` (INV-P8) |
| `progress_mode` | string(16) | not null, default `auto` | cast `ProgressMode` ([D-P6-3]) |
| `progress_basis` | string(16) | not null, default `milestones` | cast `ProgressBasis`; seeded from `projects.default_progress_basis` |
| `progress_reason` | string(255) | nullable | mandatory when `progress_mode = manual` |
| `progress_set_by` | FK `users.id` | nullable | `nullOnDelete` - who typed the manual figure |
| `progress_updated_at` | timestamp | nullable | |
| `estimated_minutes` | unsignedInteger | not null, default 0 | CACHE: sum of non-cancelled task estimates |
| `actual_seconds` | unsignedBigInteger | not null, default 0 | CACHE: sum of live time entries (INV-P6) |
| `actual_minutes` | unsignedInteger | **generated STORED** | `ROUND(actual_seconds / 60)` |
| `actual_hours` | decimal(10,2) | **generated STORED** | `ROUND(actual_seconds / 3600, 2)` (INV-P7) |
| timestamps / softDeletes / blameable | | | soft delete = archived |

**Keys.** `UNIQUE uq_projects_code(code)`; `INDEX (client_id, status)`; `INDEX (status, deadline)`;
`INDEX (project_manager_id, status)`; `INDEX (collaborator_id)`; `INDEX (priority, status)`;
`INDEX (deadline)`; `INDEX (name)` for search; `INDEX (deleted_at)`.

**CHECK** `chk_projects_progress`: `progress_percent >= 0 AND progress_percent <= 100`.
**CHECK** `chk_projects_money`: `project_value >= 0 AND discount_amount >= 0 AND budget_amount >= 0 AND discount_amount <= project_value`.
**CHECK** `chk_projects_dates`: `start_date IS NULL OR deadline IS NULL OR deadline >= start_date`.
**CHECK** `chk_projects_commission`: `commission_type IS NULL OR (commission_type = 'percentage' AND commission_rate IS NOT NULL) OR (commission_type = 'fixed' AND commission_fixed_amount IS NOT NULL)`
- an override can never be half-configured, which is exactly what would make the spine's `project_override`
  branch resolve a NULL rate.
**CHECK** `chk_projects_rate`: `commission_rate IS NULL OR (commission_rate >= 0 AND commission_rate <= 100)`.
**CHECK** `chk_projects_manual_progress`: `progress_mode = 'auto' OR progress_reason IS NOT NULL`.

**Relationships.** belongsTo `Client`, `Lead`, `Service`, `User` (`projectManager`, `progressSetBy`,
`creator`, `editor`), `Collaborator` (guarded; always loaded `withTrashed()`). hasMany
`ProjectValueRevision`, `ProjectMember`, `ProjectMilestone`, `Task`, `TimeEntry` and - once later phases
exist - `ProjectPayment`, `CollaboratorReferral`, `Invoice`. belongsToMany `User` **through the pivot
`project_members`** (`withPivot('role', 'deleted_at')`, `wherePivotNull('deleted_at')`) and belongsToMany
`Collaborator` through the same pivot. morphMany `Attachment` (`attachable`).

### 2.2 `project_value_revisions`

**[spine]** §107's "project value 200,000 -> 260,000" audit, and the row the spine's §6.6 case 8 reads when
it supersedes an entitlement. Append-only: no `deleted_at`, no UPDATE.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `project_id` | FK `projects.id` | not null | `restrictOnDelete` - the audit outlives a force-delete attempt |
| `revision_no` | unsignedInteger | not null | 1-based, `UNIQUE (project_id, revision_no)` |
| `old_project_value` / `new_project_value` | decimal(15,2) | not null | |
| `old_discount_amount` / `new_discount_amount` | decimal(15,2) | not null | |
| `old_net_value` / `new_net_value` | decimal(15,2) | not null | copied from the generated column before and after |
| `delta_amount` | decimal(15,2) | **generated STORED** | `new_net_value - old_net_value`; signed on purpose, so no positive CHECK |
| `old_commission_type` / `new_commission_type` | string(16) | nullable | §107 also audits the rate change (10 % -> 15 %) |
| `old_commission_rate` / `new_commission_rate` | decimal(8,4) | nullable | |
| `old_commission_fixed_amount` / `new_commission_fixed_amount` | decimal(15,2) | nullable | |
| `reason` | string(255) | not null | mandatory in the Form Request (§107) |
| `effective_on` | date | not null | the business date the new value applies from, never `now()` |
| `changed_by` | FK `users.id` | nullable | `nullOnDelete` |
| `changed_by_name` | string(150) | not null | snapshot, immune to user deletion |
| `ip_address` | string(45) | nullable | |
| `notes` | string(255) | nullable | |
| `created_at` / `updated_at` | timestamps | | **no `deleted_at`** - the category rule of **D19** (`CLAUDE.md` §3: append-only money / audit / revision tables carry none) plus **D16** (append-only financial tables), enforced by INV-P3's trigger |

**Keys.** `UNIQUE uq_pvr_revision(project_id, revision_no)`; `INDEX (project_id, effective_on)`;
`INDEX (effective_on)`; `INDEX (changed_by)`.
**CHECK** `chk_pvr_values`: `new_project_value >= 0 AND new_discount_amount >= 0 AND new_discount_amount <= new_project_value`.
**CHECK** `chk_pvr_change`: at least one pair really differs -
`old_project_value <> new_project_value OR old_discount_amount <> new_discount_amount
OR NOT (old_commission_type <=> new_commission_type) OR NOT (old_commission_rate <=> new_commission_rate)
OR NOT (old_commission_fixed_amount <=> new_commission_fixed_amount)` - a no-op revision cannot be written.
**Trigger** `trg_pvr_no_delete` `BEFORE DELETE` -> `SIGNAL SQLSTATE '45000'`. The model's `deleting` and
`updating` hooks throw `ImmutableRevisionException` first, so the trigger is only the last line of defence
(the spine's R-5 lesson: a raw SQL error with no Eloquent explanation is how someone "fixes" it by dropping
the trigger).
**Relationships.** belongsTo `Project`, `User` (`changedBy`). `Project` hasMany `valueRevisions`.

### 2.3 `project_members`

§20 "employees, collaborators", with a per-project role. **[D-P6-4]** One table, not the two pivots
`project_user` + `collaborator_project` that `CLAUDE.md` §3's pivot rule would imply: this is a first-class
assignment record with its own role, lifecycle, audit and policy, the convention's `singular_singular` rule
describes plain attachment pivots, and splitting it would duplicate every column, scope and screen for no
gain. Removal is a **soft delete**, so "who was on this project in March" stays answerable.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `project_id` | FK `projects.id` | not null | `cascadeOnDelete` |
| `user_id` | FK `users.id` | nullable | `cascadeOnDelete` - a staff member ([D-P6-2]) |
| `collaborator_id` | unsignedBigInteger | nullable | index; FK added by Phase 8, `restrictOnDelete` |
| `role` | string(32) | not null, default `member` | cast `ProjectMemberRole` |
| `notes` | string(255) | nullable | e.g. "front-end only" |
| `active_guard` | tinyint | **generated STORED** | `CASE WHEN deleted_at IS NULL THEN 1 ELSE NULL END` - exists only to carry the unique indexes |
| timestamps / softDeletes / blameable | | | `deleted_at` = removed from the team; `updated_by` = who removed |

**Keys.** `UNIQUE uq_pm_user_active(project_id, user_id, active_guard)`,
`UNIQUE uq_pm_collaborator_active(project_id, collaborator_id, active_guard)` - MariaDB unique indexes
ignore NULLs, so removed rows stack freely while at most one **active** membership per person per project
can exist, even under a double-submitted form (INV-P11). `INDEX (user_id, deleted_at)`,
`INDEX (collaborator_id, deleted_at)`, `INDEX (project_id, role)`.
**CHECK** `chk_pm_one_party`: `(user_id IS NOT NULL) + (collaborator_id IS NOT NULL) = 1` (INV-P10).
**Relationships.** belongsTo `Project`, `User`, `Collaborator`. `Project` hasMany `members`;
`ProjectMember` is also the pivot model for both `belongsToMany` relations in §2.1.

### 2.4 `project_milestones`

§21, plus `amount`, without which the spine's `milestone` commission base cannot exist.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `project_id` | FK `projects.id` | not null | `cascadeOnDelete` |
| `name` | string(200) | not null | §21 |
| `description` | text | nullable | §21 |
| `start_date` | date | nullable | §21 |
| `deadline` | date | nullable | §21, index |
| `amount` | decimal(15,2) | nullable | **[spine]** the milestone payment value; NULL = not a payment milestone |
| `weight` | decimal(8,4) | not null, default `1.0000` | project-progress weighting (§6.3) |
| `progress_percent` | decimal(8,4) | not null, default `0.0000` | derived (INV-P8) |
| `status` | string(32) | not null, default `pending` | cast `MilestoneStatus`, index |
| `sort_order` | int | not null, default 0 | |
| `completed_on` | date | nullable | stamped on `completed` |
| timestamps / softDeletes / blameable | | | soft delete keeps `project_payments.project_milestone_id` resolvable |

**Keys.** `INDEX (project_id, sort_order)`, `INDEX (project_id, status)`, `INDEX (deadline)`.
**CHECK** `chk_pms_amount`: `amount IS NULL OR amount >= 0`. **CHECK** `chk_pms_weight`: `weight > 0`.
**CHECK** `chk_pms_progress`: `progress_percent >= 0 AND progress_percent <= 100`.
**CHECK** `chk_pms_dates`: `start_date IS NULL OR deadline IS NULL OR deadline >= start_date`.
**Relationships.** belongsTo `Project`; hasMany `Task`; morphMany `Attachment`; once Phase 10 exists hasMany
`ProjectPayment`. `MilestonePolicy::delete()` refuses while a `project_payment` references the milestone and
names the payment number.

### 2.5 `tasks`

§22. One table for tasks and subtasks, exactly one level deep (INV-P12).

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK; the human reference is the accessor `reference` = `'TSK-' . id` (the spine's `CLE-` pattern) |
| `project_id` | FK `projects.id` | not null | `cascadeOnDelete`; §22 lists project first and standalone tasks are not requested |
| `project_milestone_id` | FK `project_milestones.id` | nullable | `nullOnDelete` |
| `parent_task_id` | FK `tasks.id` | nullable | `cascadeOnDelete` - subtasks (§22) |
| `depth` | unsignedTinyInteger | not null, default 0 | 0 = task, 1 = subtask |
| `title` | string(200) | not null | |
| `description` | text | nullable | |
| `assigned_user_id` | FK `users.id` | nullable | `nullOnDelete`, index - §22 "assigned employee" ([D-P6-2]) |
| `assigned_collaborator_id` | unsignedBigInteger | nullable | index; FK added by Phase 8 - §22 "assigned collaborator" |
| `assigned_at` | timestamp | nullable | when the current assignment was made |
| `assigned_by` | FK `users.id` | nullable | `nullOnDelete` |
| `reporter_id` | FK `users.id` | nullable | `nullOnDelete` - §22 "reporter"; defaults to the creator |
| `priority` | string(16) | not null, default `medium` | cast `Priority`, index |
| `status` | string(32) | not null, default `todo` | cast `TaskStatus`, index |
| `start_date` | date | nullable | §22 |
| `due_date` | date | nullable | §22 "deadline", index |
| `estimated_minutes` | unsignedInteger | nullable | §22 "estimated hours", stored in minutes |
| `estimated_hours` | decimal(10,2) | **generated STORED** | `ROUND(estimated_minutes / 60, 2)` |
| `actual_seconds` | unsignedBigInteger | not null, default 0 | CACHE over live `time_entries` (INV-P6) |
| `actual_minutes` | unsignedInteger | **generated STORED** | `ROUND(actual_seconds / 60)` |
| `actual_hours` | decimal(10,2) | **generated STORED** | §22 "actual hours" - derived and unwritable (INV-P7) |
| `progress_percent` | decimal(8,4) | not null, default `0.0000` | derived (INV-P8, §6.3) |
| `checklist_total` / `checklist_done` | unsignedSmallInteger | not null, default 0 | CACHE, recomputed |
| `subtask_total` / `subtask_done` | unsignedSmallInteger | not null, default 0 | CACHE, recomputed |
| `comment_count` / `attachment_count` | unsignedSmallInteger | not null, default 0 | CACHE - keeps the Kanban free of N+1 |
| `is_client_visible` | boolean | not null, default **true** | §19 lists tasks on the client panel; without a per-row flag every internal task leaks (F-3.1, requested by phase-05 §13.1). The client rule is the **AND** of the project setting and this column: `projects.client_can_see_tasks = 1 AND tasks.is_client_visible = 1` (§7.7, §9). Default true keeps the panel useful from day one; a single internal task is hidden by clearing the flag, which is an `activity_log`-ed edit |
| `board_position` | decimal(20,10) | not null, default `0` | fractional ordering inside (`project_id`, `status`) - §6.5 |
| `blocked_reason` | string(255) | nullable | mandatory while `status = blocked` |
| `completed_at` | timestamp | nullable | |
| `completed_by` | FK `users.id` | nullable | `nullOnDelete` |
| timestamps / softDeletes / blameable | | | |

**Keys.** `INDEX (project_id, status, board_position)` - the Kanban query;
`INDEX (assigned_user_id, status)`, `INDEX (assigned_collaborator_id, status)`,
`INDEX (project_milestone_id, status)`, `INDEX (parent_task_id)`, `INDEX (due_date, status)`,
`INDEX (reporter_id)`, `INDEX (title)`, **`INDEX (project_id, is_client_visible)`** - the client panel's
only task query (F-3.1) - `INDEX (deleted_at)`.
**CHECK** `chk_tasks_assignee`: `(assigned_user_id IS NOT NULL) + (assigned_collaborator_id IS NOT NULL) <= 1`.
**CHECK** `chk_tasks_depth`: `(parent_task_id IS NULL AND depth = 0) OR (parent_task_id IS NOT NULL AND depth = 1)`.
**CHECK** `chk_tasks_progress`: `progress_percent >= 0 AND progress_percent <= 100`.
**CHECK** `chk_tasks_dates`: `start_date IS NULL OR due_date IS NULL OR due_date >= start_date`.
**CHECK** `chk_tasks_checklist`: `checklist_done <= checklist_total`.
**Relationships.** belongsTo `Project`, `ProjectMilestone`, `Task` (`parent`), `Collaborator` (guarded),
`User` (`assignee`, `reporter`, `assigner`, `completer`). hasMany `Task` (`subtasks`), `TaskChecklistItem`,
`TaskComment`, `TimeEntry`. morphMany `Attachment`. The task's **activity history** is `activity_log`
filtered by subject (D13) - no per-task history table is created.

### 2.6 `task_checklist_items`

§22 "checklists".

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `task_id` | FK `tasks.id` | not null | `cascadeOnDelete` |
| `title` | string(255) | not null | |
| `is_done` | boolean | not null, default false | |
| `done_at` | timestamp | nullable | |
| `done_by` | FK `users.id` | nullable | `nullOnDelete` |
| `sort_order` | int | not null, default 0 | |
| timestamps / softDeletes / blameable | | | |

**Keys.** `INDEX (task_id, sort_order)`, `INDEX (task_id, is_done)`.
**CHECK** `chk_tci_done`: `is_done = 0 OR done_at IS NOT NULL`.
**Relationships.** belongsTo `Task`, `User` (`doneBy`). Toggling an item recomputes
`tasks.checklist_total` / `checklist_done` over non-trashed rows and then the progress chain (INV-P6).

### 2.7 `task_comments`

§22 "comments", §59 "add comments" for collaborators.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `task_id` | FK `tasks.id` | not null | `cascadeOnDelete` |
| `user_id` | FK `users.id` | nullable | `nullOnDelete` - the author's login |
| `author_name` | string(150) | not null | snapshot, so a deleted user does not erase the conversation |
| `body` | text | not null | validated 1..5000 chars; stored raw and escaped on render (no HTML) |
| `visibility` | string(16) | not null, default `team` | cast `CommentVisibility` - `internal` is staff-only, `team` includes assigned collaborators (§112) |
| `mention_count` | unsignedTinyInteger | not null, default 0 | cache |
| `edited_at` | timestamp | nullable | set on an in-window edit |
| timestamps / softDeletes / blameable | | | |

**Keys.** `INDEX (task_id, created_at)`, `INDEX (user_id)`, `INDEX (visibility)`.
**Relationships.** belongsTo `Task`, `User`. hasMany `TaskCommentMention`. morphMany `Attachment`.
A comment is editable by its author only, and only inside `projects.task_comment_edit_minutes`; every edit
and delete writes an activity row with the old body (§107).

### 2.8 `task_comment_mentions`

§22 "mentions" - a row per mentioned user so "mentions of me" is an index lookup, not a `LIKE '%@name%'`.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `task_comment_id` | FK `task_comments.id` | not null | `cascadeOnDelete` |
| `user_id` | FK `users.id` | not null | `cascadeOnDelete` |
| `notified_at` | timestamp | nullable | stamped when the notification is queued |
| `created_at` / `updated_at` | timestamps | | no soft deletes (**D19**: a history pivot / join record with no independent life) |

**Keys.** `UNIQUE uq_tcm_pair(task_comment_id, user_id)` - one mention per person per comment however many
times the handle appears. `INDEX (user_id, created_at)`.
**Relationships.** belongsTo `TaskComment`, `User`. Only users who are **active members of the comment's
project** (or its manager) may be mentioned, so the autocomplete cannot be used to enumerate staff.

### 2.9 `attachments`

§22 "attachments" and §96 "files may belong to projects, tasks, clients, employees, ... permissions control
visibility". **[D-P6-6]** One polymorphic table, created here because Phase 6 is the first phase that needs
it, governed by Phase 1's existing `files` module slug. Later phases attach to this table instead of
creating a second file store (§13).

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `attachable_type` | string(191) | not null | morph map alias, never a raw FQCN. Phase 6 registers six: `project`, `task`, `task_comment`, `project_milestone`, **`collaborator`** and **`invoice`** - the two §96 subjects that had no file store at all (F-13.2). Each of the six resolves to a model with a policy, and `AttachmentPolicy` authorises the **subject** before the attachment (§9); the `collaborator` and `invoice` rows are unreachable until phases 8 and 13 ship those models, which is a 404, never a 500. Later phases extend the map only (§13) |
| `attachable_id` | unsignedBigInteger | not null | |
| `disk` | string(32) | not null, default `local` | the **private** disk; nothing is web-reachable |
| `path` | string(255) | not null | `projects/{project_id}/{ulid}.{ext}` - a hashed name, never the user's filename |
| `original_name` | string(255) | not null | shown to humans, used for the download filename |
| `mime_type` | string(127) | not null | from the sniffed content, not the request header |
| `extension` | string(16) | not null | validated against `security.allowed_file_types` |
| `size_bytes` | unsignedBigInteger | not null | validated against `security.max_upload_mb` |
| `checksum_sha256` | char(64) | nullable | integrity + duplicate detection |
| `visibility` | string(16) | not null, default `team` | cast `AttachmentVisibility` (`internal` / `team` / `client`) - §96 |
| `uploaded_by` | FK `users.id` | nullable | `nullOnDelete` |
| `uploaded_by_name` | string(150) | not null | snapshot |
| timestamps / softDeletes / blameable | | | soft delete keeps the audit; the blob is removed by a queued job only after 30 days |

**Keys.** `INDEX (attachable_type, attachable_id)`, `INDEX (uploaded_by)`, `INDEX (checksum_sha256)`,
`INDEX (visibility)`.
**CHECK** `chk_att_size`: `size_bytes > 0`.
**Relationships.** morphTo `attachable`; belongsTo `User` (`uploader`). Download happens only through the
permission-checked controller of §7, which writes an activity row (§60 "file download").

### 2.10 `time_entries`

§23. One row per work session (a timer run, however many pauses) or per manual entry. **It holds no
mutable running total** (INV-P5): `duration_seconds` is a recomputed cache over §2.11.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `project_id` | FK `projects.id` | not null | `restrictOnDelete`; denormalised from the task so "project hours" is one indexed GROUP BY |
| `task_id` | FK `tasks.id` | nullable | `nullOnDelete`; NULL allowed only when `projects.allow_time_without_task` is on |
| `user_id` | FK `users.id` | nullable | `nullOnDelete` - the **worker**, when the worker is staff |
| `collaborator_id` | unsignedBigInteger | nullable | index; FK added by Phase 8 - the worker, when the worker is a collaborator (§23 "collaborator hours") |
| `recorded_by` | FK `users.id` | nullable | `nullOnDelete` - who keyed it (equals the worker for a self-started timer) |
| `source` | string(16) | not null | cast `TimeEntrySource` (`timer` / `manual`) |
| `status` | string(16) | not null, default `stopped` | cast `TimeEntryStatus` (`running` / `paused` / `stopped`) |
| `started_at` | timestamp | not null | first segment start, or the manual from-time |
| `ended_at` | timestamp | nullable | last segment end, or the manual to-time; NULL only while `running` |
| `work_date` | date | not null | the business date, derived from `started_at` in the worker's timezone; **every daily/weekly rollup groups on this**, never on a timestamp |
| `duration_seconds` | unsignedInteger | not null, default 0 | CACHE = `SUM(time_entry_segments.duration_seconds)`, recomputed under lock (INV-P6) |
| `duration_minutes` | unsignedInteger | **generated STORED** | `ROUND(duration_seconds / 60)` - display only |
| `duration_hours` | decimal(10,2) | **generated STORED** | `ROUND(duration_seconds / 3600, 2)` |
| `description` | string(500) | nullable | what was worked on |
| `manual_reason` | string(255) | nullable | mandatory for a manual entry dated outside `projects.manual_time_backdate_limit_days` |
| `discard_reason` | string(255) | nullable | mandatory on soft delete - a wrong entry is discarded and re-entered, never silently edited to zero |
| `running_guard` | varchar(40) | **generated STORED** | `CASE WHEN status = 'running' THEN COALESCE(CONCAT('u:', user_id), CONCAT('c:', collaborator_id)) ELSE NULL END` |
| timestamps / softDeletes / blameable | | | soft delete = discarded; discarded entries leave every cache and rollup |

**Keys.** **`UNIQUE uq_te_running(running_guard)`** - the database guarantee behind "only one timer may run
per person" (INV-P4): a second `start` INSERT fails with 1062 and the service turns that into a domain error
naming the entry already running. `INDEX (user_id, work_date)`, `INDEX (collaborator_id, work_date)`,
`INDEX (project_id, work_date)`, `INDEX (task_id, work_date)`, `INDEX (work_date)`, `INDEX (status)`,
`INDEX (recorded_by, work_date)`, `INDEX (deleted_at)`.
**CHECK** `chk_te_one_worker`: `(user_id IS NOT NULL) + (collaborator_id IS NOT NULL) = 1`.
**CHECK** `chk_te_window`: `ended_at IS NULL OR ended_at >= started_at`.
**CHECK** `chk_te_running_open`: `status <> 'running' OR ended_at IS NULL`.
**CHECK** `chk_te_manual_complete`: `source <> 'manual' OR (ended_at IS NOT NULL AND status = 'stopped')` -
a manual entry is always a closed interval; the timer statuses belong to the timer.
**CHECK** `chk_te_duration`: `duration_seconds >= 0 AND duration_seconds <= 86400` - one entry can never
claim more than 24 hours, whatever a clock change or a forgotten timer does.
**Relationships.** belongsTo `Project`, `Task`, `User` (`worker`, `recorder`), `Collaborator` (guarded).
hasMany `TimeEntrySegment`. A running entry is discarded only after it is stopped, so the guard can never be
held by a trashed row.

### 2.11 `time_entry_segments`

The append-only clock record: one row per start, closed on pause or stop (INV-P5). **This is the only place
elapsed time exists**; every figure anywhere in the system is a SUM over these rows.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `time_entry_id` | FK `time_entries.id` | not null | `cascadeOnDelete` |
| `user_id` | FK `users.id` | nullable | `nullOnDelete` - denormalised worker, needed by the open guard |
| `collaborator_id` | unsignedBigInteger | nullable | index; FK added by Phase 8 |
| `started_at` | timestamp | not null | written once |
| `ended_at` | timestamp | nullable | written once, NULL while the clock runs |
| `duration_seconds` | int | **generated STORED** | `CASE WHEN ended_at IS NULL THEN 0 ELSE TIMESTAMPDIFF(SECOND, started_at, ended_at) END` - an open segment contributes **zero**, which is what makes "no mutable running total" true |
| `end_reason` | string(16) | nullable | cast `TimerStopReason` (`pause` / `stop` / `auto_stop` / `switched`) |
| `ip_address` | string(45) | nullable | evidence, same shape as `login_histories` |
| `device` | string(64) | nullable | from `App\Support\Device` |
| `open_guard` | varchar(40) | **generated STORED** | `CASE WHEN ended_at IS NULL THEN COALESCE(CONCAT('u:', user_id), CONCAT('c:', collaborator_id)) ELSE NULL END` |
| `created_at` / `updated_at` | timestamps | | **no soft deletes** - append-only by code (**D19**) |

**Keys.** **`UNIQUE uq_tes_open(open_guard)`** - at most one open segment per worker across every project
and task, the second independent enforcement of INV-P4. `INDEX (time_entry_id, started_at)`,
`INDEX (started_at)`, `INDEX (user_id, started_at)`, `INDEX (collaborator_id, started_at)`.
**CHECK** `chk_tes_window`: `ended_at IS NULL OR ended_at > started_at` - a zero-length segment is a bug,
not data. **CHECK** `chk_tes_one_worker`: `(user_id IS NOT NULL) + (collaborator_id IS NOT NULL) = 1`.
**Model hooks.** `updating` permits only `ended_at` + `end_reason`, and only while `ended_at` is still NULL;
`deleting` throws unless the parent is being force-deleted (which no UI route offers).
**Relationships.** belongsTo `TimeEntry`, `User`, `Collaborator`.

### 2.12 Relationship map - one line per edge that matters

| Edge | Kind |
|---|---|
| `Client` 1-N `Project` | `client_id`, RESTRICT |
| `Lead` 1-N `Project` | `lead_id`, nullOnDelete (Phase 5 conversion) |
| `User` 1-N `Project` | `project_manager_id` |
| `Collaborator` 1-N `Project` | `collaborator_id` - §20/§45 referral link |
| `Project` N-N `User` | **pivot `project_members`**, `withPivot('role')` |
| `Project` N-N `Collaborator` | **the same pivot `project_members`** |
| `Project` 1-N `ProjectValueRevision` | append-only, RESTRICT |
| `Project` 1-N `ProjectMilestone` 1-N `Task` | milestone nullable on a task |
| `Task` 1-N `Task` | `parent_task_id`, depth 1 only |
| `Task` 1-N `TaskChecklistItem` / `TaskComment` / `TimeEntry` | |
| `TaskComment` 1-N `TaskCommentMention` N-1 `User` | unique pair |
| `Project` / `ProjectMilestone` / `Task` / `TaskComment` 1-N `Attachment` | polymorphic `attachable` |
| `Project` 1-N `TimeEntry` 1-N `TimeEntrySegment` | project denormalised for rollups |
| `Project` 1-N `ProjectPayment` (Phase 10) | consumer of `net_value` |
| `ProjectMilestone` 1-N `ProjectPayment` (Phase 10) | the `milestone` commission base |
| `Project` 1-N `CollaboratorReferral` (Phase 10) | the authoritative attribution record |

### 2.13 Status lifecycles - explicit transition tables

Any transition not listed throws `InvalidStatusTransition`. Every transition writes an `activity_log` row
with old and new status, actor and IP; rows marked **reason** refuse to proceed without one (INV-P16).

**2.13.1 `projects.status`** (`ProjectStatus`, the eight statuses of §20)

| From | To | Trigger | Actor / permission |
|---|---|---|---|
| - | `planning` | project created | `projects.create` |
| `planning` | `pending` | scoped, waiting on the client (sign-off, content, advance) | `projects.change_status` |
| `planning` / `pending` | `in_progress` | work starts; stamps `started_at` if unset | `projects.change_status` |
| `in_progress` | `review` | internal review | same |
| `review` | `testing` | QA | same |
| `review` / `testing` | `in_progress` | rework | same |
| `testing` / `review` | `completed` | delivered; stamps `completed_on`, forces `progress_percent = 100` | `projects.change_status` |
| `planning` / `pending` / `in_progress` / `review` / `testing` | `on_hold` | paused, **reason** | `projects.change_status` |
| `on_hold` | the status it was held from | resumed | same |
| any non-terminal | `cancelled` | **reason**; running timers on its tasks are auto-stopped; progress frozen | `projects.change_status` |
| `completed` | `in_progress` | re-opened, **reason** | `projects.change_status` + `projects.edit` |
| `cancelled` | `planning` | re-opened, **reason** | same |

A project that is `completed` or `cancelled` refuses new time entries and new timers
(`ProjectStatus::allowsTimeLogging()` false) - the honest alternative to silently accepting hours against
closed work.

**2.13.2 `project_milestones.status`** (`MilestoneStatus`)

| From | To | Trigger | Actor |
|---|---|---|---|
| - | `pending` | created | `project_milestones.create` |
| `pending` | `in_progress` | first task moves off `todo`, or manual | system / `project_milestones.change_status` |
| `in_progress` | `completed` | every non-cancelled task is `completed`, or manual; stamps `completed_on` | system / `change_status` |
| `pending` / `in_progress` | `on_hold` | **reason** | `change_status` |
| `pending` / `in_progress` / `on_hold` | `cancelled` | **reason**; excluded from progress (INV-P9) | `change_status` |
| `completed` | `in_progress` | a task re-opens, or manual, **reason** | system / `change_status` |

**2.13.3 `tasks.status`** (`TaskStatus`)

| From | To | Trigger | Actor |
|---|---|---|---|
| - | `todo` | created | `tasks.create` |
| `todo` | `in_progress` | board drag, status select, or **starting a timer on it** | `tasks.change_status` / assignee / `collaborator_portal.tasks_update` |
| `in_progress` | `in_review` | submitted | same |
| `in_review` | `in_progress` | changes requested | same |
| any open | `blocked` | **`blocked_reason` mandatory** | same |
| `blocked` | the status it came from | unblocked | same |
| `in_progress` / `in_review` | `completed` | stamps `completed_at`, `completed_by`; **refused while an open subtask exists** | same |
| `completed` | `in_progress` | re-opened, **reason** | `tasks.change_status` |
| any | `cancelled` | **reason**; excluded from every denominator (INV-P9); any running timer is stopped | `tasks.change_status` |

**2.13.4 `time_entries.status`** (`TimeEntryStatus`)

| From | To | Trigger | Actor |
|---|---|---|---|
| - | `running` | `TimerService::start()` - opens entry + first segment | the worker, `time_tracking.create` |
| - | `stopped` | manual entry created | `time_tracking.create` |
| `running` | `paused` | `pause()` - closes the open segment with `end_reason = pause` | the worker |
| `paused` | `running` | `resume()` - opens a new segment | the worker |
| `running` / `paused` | `stopped` | `stop()`; `ended_at` = last segment end; caches recomputed | the worker |
| `running` | `stopped` | `projects:auto-stop-timers` after `projects.timer_max_hours`, `end_reason = auto_stop`, owner notified | system |
| `stopped` | - | terminal; a correction is a discard + a new entry | - |

### 2.14 Guard indexes, CHECKs and generated columns

**[D-P6-7]** The guarantees below are written as **raw SQL inside the migrations** because Laravel's schema
builder cannot express them, and they must **fail loudly** if MariaDB rejects them - never be skipped
(INV-P17, the spine's R-3). `projects:verify-constraints` re-asserts the whole list daily and P6-53 asserts
it in CI.

| Kind | Objects |
|---|---|
| STORED generated | `projects.net_value`, `projects.actual_minutes`, `projects.actual_hours`, `project_value_revisions.delta_amount`, `project_members.active_guard`, `tasks.estimated_hours`, `tasks.actual_minutes`, `tasks.actual_hours`, `time_entries.duration_minutes`, `time_entries.duration_hours`, `time_entries.running_guard`, `time_entry_segments.duration_seconds`, `time_entry_segments.open_guard` |
| Unique over a guard | `uq_pm_user_active`, `uq_pm_collaborator_active`, `uq_te_running`, `uq_tes_open` |
| Unique plain | `uq_projects_code`, `uq_pvr_revision`, `uq_tcm_pair` |
| CHECK | the 27 constraints named in §2.1-§2.11 (6 on `projects`, 2 on `project_value_revisions`, 1 on `project_members`, 4 on `project_milestones`, 5 on `tasks`, 1 on `task_checklist_items`, 1 on `attachments`, 5 on `time_entries`, 2 on `time_entry_segments`) |
| Trigger | `trg_pvr_no_delete` |

---

## 3. Enums to add

All in `app/Enums/`, string-backed, each implementing `label(): string` and `color(): string` (a Tailwind
token) and exposing `static options(): array`, exactly as Phase 1 §2 requires.

| Enum | Cases (values) | Extra members |
|---|---|---|
| `ProjectStatus` | `planning`, `pending`, `in_progress`, `review`, `testing`, `completed`, `on_hold`, `cancelled` | `isOpen(): bool`, `isTerminal(): bool` (completed/cancelled), `allowsTimeLogging(): bool`, `progressWeight(): string` (the §6.3 fallback: planning 0, pending 0, in_progress 10, review 80, testing 90, completed 100, on_hold 0, cancelled 0), `boardOrder(): int` |
| `ProjectType` | `fixed_price`, `hourly`, `retainer`, `maintenance`, `internal` | §20 "project type" read as the engagement model; the *kind of work* is `projects.service_id` (§11) so the same fact is never stored twice (§12.2 Q1) |
| `ProgressMode` | `auto`, `manual` | `isDerived(): bool` |
| `ProgressBasis` | `milestones`, `tasks` | |
| `MilestoneStatus` | `pending`, `in_progress`, `completed`, `on_hold`, `cancelled` | `isOpen()`, `progressWeight()` (0 / 50 / 100 / 0 / excluded) |
| `TaskStatus` | `todo`, `in_progress`, `in_review`, `blocked`, `completed`, `cancelled` | `isOpen()`, `isBoardColumn(): bool` (everything but `cancelled`), `progressWeight(): string` (todo 0, in_progress 25, in_review 75, blocked 25, completed 100, cancelled excluded), `requiresReason(): bool` (blocked, cancelled) |
| `Priority` | `low`, `medium`, `high`, `urgent` | `weight(): int` for sorting. Shared: §20 project priority, §22 task priority, and Phase 22 tickets must reuse it rather than declare a second one |
| `ProjectMemberRole` | `manager`, `lead`, `member`, `reviewer`, `observer` | `canManage(): bool` (manager, lead), `canLogTime(): bool` (everything but `observer`), `canBeCollaborator(): bool` (member, reviewer, observer - a collaborator is never given `manager`). These are **project** roles, not job titles: the job title lives on the employee record and the RBAC role governs the application |
| `TimeEntrySource` | `timer`, `manual` | `isTimer(): bool` |
| `TimeEntryStatus` | `running`, `paused`, `stopped` | `isLive(): bool` (running/paused) |
| `TimerStopReason` | `pause`, `stop`, `auto_stop`, `switched` | `closesEntry(): bool` (false for `pause`) |
| `AttachmentVisibility` | `internal`, `team`, `client` | `visibleToCollaborator(): bool` (team, client), `visibleToClient(): bool` (client only) |
| `CommentVisibility` | `internal`, `team` | `visibleToCollaborator(): bool` |

**`App\Enums\CommissionCalculationType`** (`percentage`, `fixed`, `manual`) is **created by Phase 6** with
exactly the cases, values and name the spine's §3 specifies, because `projects.commission_type` casts to it
and Phase 6 migrates first. The spine and phase-10-12 **reuse it, not create it** - two declarations of one
`app/Enums` name is a merge conflict, never a style question (F-5.5, R3, §13).

---

## 4. PermissionRegistry additions

Phase 1 already registers the module slugs `projects`, `project_milestones`, `tasks`, `time_tracking`
(group `SoftwareHouse`) and `files` (group `Shared`), and seeds their permissions. Phase 6 therefore mostly
**fixes the ability list** of existing slugs; it adds one slug. Presets are Phase 1's: `READ`, `CRUD`,
`CRUD_FULL`, `APPROVE`, `STATUS`, `ASSIGN`, `FILES`, `MONEY`, `REPORTS`, `LOGS`. No new case is added to the
`Ability` enum.

### 4.1 New module slug (`is_core = false`)

| slug | ModuleGroup | icon | Abilities | Why |
|---|---|---|---|---|
| `task_comments` | `SoftwareHouse` | `chat-bubble-left-right` | `READ` + `create` + `edit` + `delete` | a role must be able to discuss a task without holding `tasks.edit`; §59 grants collaborators "add comments" and nothing else |

### 4.2 Abilities on existing Phase 1 slugs (additive; the registry stays the only declaration site)

| slug | Abilities after this phase | Notes |
|---|---|---|
| `projects` | `CRUD_FULL` + `restore` + `STATUS` + `ASSIGN` + `MONEY` + `REPORTS` + `LOGS` | `view_financial` gates `budget_amount`, `project_value`, `discount_amount`, `net_value`, `commission_*`, `project_milestones.amount` and the whole value-revision screen; `assign` gates team changes |
| `project_milestones` | `CRUD` + `STATUS` + `REPORTS` | `amount` is shown only with `projects.view_financial` - a milestone does not get its own money ability |
| `tasks` | `CRUD_FULL` + `restore` + `STATUS` + `ASSIGN` + `REPORTS` + `LOGS` | `change_status` is what a board drag requires; `assign` is separate so a developer can move a card without re-assigning it |
| `time_tracking` | `CRUD` + `STATUS` + `REPORTS` + `export` + `print` + `LOGS` | `create` covers both the timer and a manual entry; `view_any` is "everyone's time", `view` is "my time"; `delete` is discard-with-reason |
| `files` | `READ` + `FILES` + `delete` | `upload` / `download` already exist in the `FILES` preset; `delete` is added for attachment removal |
| `reports` | unchanged | the time reports of §8.9 live under `time_tracking.view_reports`; Phase 23 aggregates them |

**No new ability is invented for revising a project value.** That route requires **both**
`projects.view_financial` and `projects.edit`, which is the honest expression of "only someone trusted with
money may change the contract value".

### 4.3 Portal permissions

Already registered by Phase 1 and used as-is: `collaborator_portal.projects`, `.project_client`,
`.project_value`, `.tasks`, `.tasks_update`, `.comments`, `.files_upload`, `.files_download`.

Added (Phase 1 §4 explicitly allows "plus the read abilities each panel needs"):

| Permission | Panel | Meaning |
|---|---|---|
| `collaborator_portal.time_tracking` | collaborator | may start a timer and log time on an assigned task, and see own hours |
| `client_portal.projects` | client | the read-only project list + progress view incl. milestones (§19) |
| `client_portal.tasks` | client | the read-only task list, additionally gated by `projects.client_can_see_tasks` |
| `client_portal.files` | client | download attachments whose `visibility = client` |

Role grants (`RoleSeeder`, idempotent): Project Manager gets every `projects` / `project_milestones` /
`tasks` / `time_tracking` / `task_comments` / `files` ability except `projects.delete`; Developer, Designer
and SEO Expert get `projects.view`, `tasks.view`/`view_any`/`edit`/`change_status`, `task_comments.*`,
`files.upload`/`download`, `time_tracking.view`/`create`/`edit`; Accountant gets `projects.view_any` +
`view` + `view_financial` + `view_reports` and nothing writable; Collaborator gets the four portal
permissions above minus whatever the admin revokes; Client gets `client_portal.*`.

---

## 5. SettingsRegistry additions

**[D-P6-8] One new group, `projects`.** Phase 2 §2 defines groups in code precisely so a later phase can add
one; none of its 13 groups is the right home for a timer limit or a project code prefix, and scattering
these keys into `finance` would make the settings screen lie about what they control. The group definition
follows Phase 2's shape exactly:
`projects => ['label' => 'Projects & Time', 'icon' => 'rectangle-stack', 'description' => 'Project numbering, progress, and time-tracking rules', 'sort' => 86, 'permission' => 'settings.edit']`.
**Sort is 86, not 85** (F-6.3): `SettingsRegistry::groups()` is keyed by slug and sorted by `sort`, and 85
was already taken by another group's declaration - two groups sharing a sort makes the settings screen's
order depend on registration order.

| group.key | type | default | Meaning |
|---|---|---|---|
| `projects.project_code_prefix` | text | `PRJ-` | §20 "Project ID" |
| `projects.project_code_next_number` | number | `1` | counter, locked in-transaction (§6.1) |
| `projects.default_progress_basis` | select `milestones`\|`tasks` | `milestones` | seeds `projects.progress_basis` on create |
| `projects.progress_manual_override_enabled` | boolean | `true` | when false the manual mode is refused outright and progress is purely derived ([D-P6-3]) |
| `projects.default_task_estimate_minutes` | number | `60` | the progress weight used for a task with no estimate - a documented constant, never a magic number in a service |
| `projects.allow_time_without_task` | boolean | `true` | project-level time entries (meetings, setup) |
| `projects.timer_auto_stop_enabled` | boolean | `true` | |
| `projects.timer_max_hours` | number | `12` | a single running timer is auto-stopped past this; also the `chk_te_duration` business counterpart |
| `projects.manual_time_backdate_limit_days` | number | `7` | beyond it `manual_reason` is mandatory and `time_tracking.approve`-level trust is required (`time_tracking.change_status`) |
| `projects.manual_time_max_hours_per_day` | number | `16` | a worker's live entries for one `work_date` may not exceed this |
| `projects.working_hours_per_day` | number | `8` | the denominator of the utilisation figure in the weekly rollup |
| `projects.working_days_per_week` | number | `6` | with `localization.week_start`, defines "this week" in §6.6 |
| `projects.task_comment_edit_minutes` | number | `15` | the author's edit window |
| `projects.client_can_see_tasks` | boolean | `true` | §19 lists tasks in the client panel; the switch lets a business hide them without code |
| `projects.client_can_see_attachments` | boolean | `true` | applies on top of `AttachmentVisibility::Client` |
| `projects.deadline_reminder_days` | number | `3` | project deadline + task due-date reminders |
| `projects.overdue_digest_enabled` | boolean | `true` | the daily overdue digest to project managers |

Upload limits are **not** redefined: `security.max_upload_mb` and `security.allowed_file_types` from Phase 2
are the only source, and `AttachmentService` validates against them.

---

## 6. Services

Namespace `App\Services\Project\`. Every write method runs inside one `DB::transaction()`, takes row locks
in the fixed order **project -> milestone -> task -> time entry -> segment** (so no two code paths can
deadlock against each other), and dispatches events only through `DB::afterCommit()`. All percentage and
money arithmetic goes through `App\Support\Money` (bcmath, intermediate scale 6, final half-up) - INV-P14.

### 6.1 Contracts

| Service / method | Guarantees | Events |
|---|---|---|
| **`ProjectNumberService`** | | |
| `next(): string` | A **thin delegate, from day one**: `App\Services\Finance\DocumentNumberService` is shipped by **Phase 5** (F-4.1, **D27**), which migrates before Phase 6, so this class always calls `DocumentNumberService::next('projects.project_code_prefix', 'projects.project_code_next_number', '%05d')` - **passing its own pad explicitly**, because `'%06d'` is only that method's default. The `SELECT ... FOR UPDATE` on the settings row inside the caller's transaction, the increment, the `uq_projects_code` backstop and the single retry on a 1062 all live **once** in that class; `ProjectNumberService` adds only the two settings keys and the `PRJ-` shape. There is no conditional fallback and no second locking implementation (§13) | - |
| **`ProjectService`** | | |
| `create(ProjectData): Project` | a unique `code` under concurrency; `progress_basis` seeded from settings; the project manager is also inserted as a `project_members` row with role `manager`; `collaborator_id` only through `ProjectReferralService`; `project_value` written here **and** recorded as revision 1 when non-zero | `ProjectCreated` |
| `update(Project, ProjectData): Project` | refuses to touch `project_value`, `discount_amount`, `commission_type`, `commission_rate`, `commission_fixed_amount` or `collaborator_id` - those have their own services (INV-P1, INV-P13); keeps `project_manager_id` and the `manager` membership in sync; recalculates progress when `progress_basis` changes | `ProjectUpdated` |
| `changeStatus(Project, ProjectStatus, ?string $reason): Project` | only the transitions of §2.13.1; a reason where marked; stamps `completed_on` and forces progress to 100 on `completed`; **stops every running timer on the project's tasks** when moving to `completed` / `cancelled` / `on_hold`; writes the activity row with old and new status | `ProjectStatusChanged` |
| `addMember(Project, ProjectMemberData): ProjectMember` | exactly one of user/collaborator; at most one active membership (1062 -> "already on this team"); a collaborator may not be given `manager`; notifies the member | `ProjectTeamChanged` |
| `updateMemberRole(ProjectMember, ProjectMemberRole)` | the last `manager` cannot be demoted while `project_manager_id` points at them | `ProjectTeamChanged` |
| `removeMember(ProjectMember): void` | soft delete, so history survives (INV-P11); refuses while the member has a **running timer** on the project; open tasks assigned to them are listed back to the caller for re-assignment - never silently unassigned | `ProjectTeamChanged` |
| `archive(Project) / restore(Project)` | soft delete cascades nothing; tasks, time and revisions stay; `restore` is a permissioned act | `ProjectArchived` / `ProjectRestored` |
| **`ProjectValueService`** | | |
| `revise(Project, ReviseValueData, string $reason): ProjectValueRevision` | the **only** writer of the five value/override columns (INV-P1). One transaction: lock the project, snapshot old values, refuse a no-op (`chk_pvr_change`), `revision_no = max + 1`, write the append-only row with `effective_on`, `changed_by_name` and `ip_address`, update the project, recompute `value_revision_count`, write an activity row with old/new **and the reason**, then fire the event after commit. Never touches a payment, an entitlement or a ledger row - the spine's §6.6 case 8 listener decides what happens to commission | `ProjectValueRevised` |
| `history(Project): Collection` | ordered `revision_no` asc, eager-loaded actor; the only read path for the audit screen | - |
| **`ProjectReferralService`** | | |
| `link(Project, Collaborator, ?string $code, ?CarbonInterface $on): Project` | allowed only while the project has **no** `collaborator_referrals` row and **no** `project_payments` row; snapshots `referral_code` and `referral_date`; **delegates to `Collaborator\ReferralService::attach()` when that class exists** (Phase 9/10), otherwise writes the project columns and fires the event so the Phase 9 listener can backfill the referral row | `ProjectReferralLinked` |
| `change(Project, Collaborator $new, string $reason): Project` | once attribution evidence or a payment exists this method **only** forwards to `Collaborator\ReferralService::change()` (supersede, never mutate; no ledger row re-pointed - INV-P13) and mirrors the new snapshot onto the project; without that class it refuses rather than guess | `ProjectReferralChanged` |
| `clear(Project, string $reason): Project` | same rule; clears the three project columns and revokes the referral | `ProjectReferralCleared` |
| **`MilestoneService`** | | |
| `create / update` | dates inside the project window (warning, not a block, when the deadline is later than the project's); `amount` optional; `weight > 0` | `MilestoneSaved` |
| `reorder(Project, array $idsInOrder): void` | one UPDATE per row inside a transaction; ids not belonging to the project are rejected | - |
| `changeStatus(ProjectMilestone, MilestoneStatus, ?string $reason)` | §2.13.2 only; recalculates milestone then project progress | `MilestoneStatusChanged` |
| `delete(ProjectMilestone)` | refused when a `project_payment` references it, naming the payment; otherwise soft delete and its tasks are detached (`project_milestone_id = null`), never deleted | `MilestoneDeleted` |
| **`TaskService`** | | |
| `create(TaskData): Task` | `depth` derived from `parent_task_id` (INV-P12, a subtask of a subtask is refused); milestone must belong to the project; `board_position` = max + 1 in the target column; `reporter_id` defaults to the actor; assignment validated against active project membership | `TaskCreated` (+ `TaskAssigned`) |
| `assign(Task, ?User, ?Collaborator, ?string $note): Task` | at most one assignee (INV-P10); the assignee must be an **active member** of the project, and a collaborator assignee must additionally be a member with a role whose `canLogTime()` is true; stamps `assigned_at` / `assigned_by`; **notifies the new assignee** and writes an activity row naming both old and new assignee | `TaskAssigned` |
| `changeStatus(Task, TaskStatus, ?string $reason): Task` | §2.13.3 only; `blocked` without `blocked_reason` is refused; `completed` is refused while any non-cancelled subtask is open, and the error names them; stops a running timer on the task when moving to `completed` / `cancelled`; cascades the progress chain | `TaskStatusChanged`, `TaskCompleted` |
| `move(Task, TaskStatus $to, ?int $afterId, ?int $beforeId): Task` | the Kanban drop: status transition + fractional reposition in one transaction (§6.5); returns the renormalised column when it had to renumber | `TaskStatusChanged` (only when the column changed) |
| `update / delete / restore` | delete is a soft delete and refuses while a **running** timer references the task; its time entries are kept and keep counting for the project, never for the deleted task | `TaskUpdated` / `TaskDeleted` |
| **`TaskChecklistService`** | `add`, `toggle`, `rename`, `reorder`, `remove` - each recomputes `checklist_total` / `checklist_done` by COUNT over non-trashed rows under the task's lock, then cascades progress (INV-P6) | `TaskChecklistChanged` |
| **`TaskCommentService`** | | |
| `post(Task, User, string $body, CommentVisibility, array $files): TaskComment` | stores the comment, extracts `@handle` mentions, resolves them **only** against active members + the project manager (an unknown or non-member handle is left as plain text, so the autocomplete cannot enumerate staff), writes one `task_comment_mentions` row per distinct user (unique pair), recomputes `tasks.comment_count`, attaches any files through `AttachmentService`, and notifies assignee + reporter + mentioned users exactly once each (a person mentioned in their own comment is not notified) | `TaskCommentPosted`, `UserMentionedInComment` |
| `edit(TaskComment, string $body)` | author only, inside `projects.task_comment_edit_minutes`; stamps `edited_at`; logs the old body; **new** mentions notify, existing ones do not re-notify | `TaskCommentEdited` |
| `delete(TaskComment)` | author, or `task_comments.delete`; soft delete; counts recomputed | `TaskCommentDeleted` |
| **`AttachmentService`** | | |
| `store(Model $attachable, UploadedFile, AttachmentVisibility, User): Attachment` | extension allow-list **and** sniffed MIME must both pass (a `.php` renamed `.png` is refused), size against `security.max_upload_mb`, stored on the **private** disk under a ULID name, `checksum_sha256` computed, `uploaded_by_name` snapshotted, counts recomputed | `AttachmentUploaded` |
| `download(Attachment, User): StreamedResponse` | policy first, then a streamed response with the original filename; writes an activity row (§60) | `AttachmentDownloaded` |
| `delete(Attachment)` | soft delete only; the blob is removed by `attachments:prune-deleted` after 30 days, so an accidental delete is recoverable | `AttachmentDeleted` |
| **`ProjectProgressService`** - the single writer of every `progress_percent` (INV-P8) | | |
| `recalculateTask(Task): bool` | §6.3; returns whether the value changed; cascades to the parent task, then the milestone, then the project; recursion-guarded by a static in-request set | `TaskProgressChanged` |
| `recalculateMilestone(ProjectMilestone): bool` / `recalculateProject(Project): bool` | §6.3; a project in `manual` mode is left untouched | `ProjectProgressChanged` |
| `setManual(Project, string $percent, string $reason, User): Project` | refused unless `projects.progress_manual_override_enabled`; sets `progress_mode = manual`, `progress_reason`, `progress_set_by`, `progress_updated_at`; writes an activity row with old/new **and** the derived figure it overrode, so the gap is always visible | `ProjectProgressChanged` |
| `setAuto(Project, string $reason)` | returns to derivation and immediately recalculates | `ProjectProgressChanged` |
| **`TimerService`** - §6.4 | | |
| `start(User\|Collaborator $worker, Project, ?Task, ?string $description): TimeEntry` | at most one running timer per worker, decided by the INSERT (INV-P4); moves a `todo` task to `in_progress`; refuses on a closed project/task; opens entry + first segment in one transaction | `TimerStarted` |
| `pause(TimeEntry)` / `resume(TimeEntry)` | closes / opens a segment; never writes a total | `TimerPaused` / `TimerResumed` |
| `stop(TimeEntry, TimerStopReason): TimeEntry` | closes the open segment, sets `ended_at`, recomputes `duration_seconds` by SUM, then the task and project caches, all in one transaction | `TimerStopped`, `TimeEntryRecorded` |
| `switchTo(worker, Project, ?Task): TimeEntry` | stop (`end_reason = switched`) + start, atomically - the only safe way to move a timer, because two separate calls would race the unique guard | `TimerStopped`, `TimerStarted` |
| `current(worker): ?TimeEntry` | the running or paused entry with its open segment, for the topbar widget | - |
| `autoStopStale(int $maxHours, int $limit = 200): int` | the scheduled sweep; `end_reason = auto_stop`; notifies each owner; bounded and idempotent | `TimerAutoStopped` |
| **`TimeEntryService`** | | |
| `createManual(ManualTimeData): TimeEntry` | `source = manual`, `status = stopped`, both ends required and `ended_at > started_at`, never in the future, inside the back-date window unless `manual_reason` is given and the actor holds `time_tracking.change_status`; the worker's live total for that `work_date` is locked and checked against `projects.manual_time_max_hours_per_day`; writes one closed segment so every figure still comes from §2.11 | `TimeEntryRecorded` |
| `update(TimeEntry, ManualTimeData)` | manual entries only (a timer entry is evidence: correct it by discarding); re-checks the day cap; caches recomputed; old/new logged | `TimeEntryUpdated` |
| `discard(TimeEntry, string $reason)` | stops it first if live, then soft-deletes with `discard_reason`; caches recomputed so it leaves every rollup; never hard-deletes | `TimeEntryDiscarded` |
| **`TimeRollupService`** (the cache writer) | `syncTask(Task)`, `syncProject(Project)` - `UPDATE ... SET actual_seconds = (SELECT COALESCE(SUM(duration_seconds),0) FROM time_entries WHERE ... AND deleted_at IS NULL)` under the row lock. **Recompute, never increment** (INV-P6) | - |
| **`TimesheetService`** - §6.6, the only read path for hours | `daily(Filters): DailyRollup`, `weekly(Filters): WeeklyGrid`, `byUser(Filters)`, `byCollaborator(Filters)`, `byProject(Filters)`, `byTask(Filters)`, `export(Filters, string $format)` - one GROUP BY query per rollup, no N+1, seconds summed then formatted once | - |

Form Requests (`app/Http/Requests/Project/`): `StoreProjectRequest`, `UpdateProjectRequest`,
`ReviseProjectValueRequest` (reason required, `effective_on` required, no-op rejected),
`StoreProjectMemberRequest`, `StoreMilestoneRequest`, `UpdateMilestoneRequest`, `StoreTaskRequest`,
`UpdateTaskRequest`, `AssignTaskRequest`, `MoveTaskRequest`, `ChangeTaskStatusRequest` (reason required for
`blocked`/`cancelled`), `StoreChecklistItemRequest`, `StoreTaskCommentRequest`, `StoreAttachmentRequest`,
`StartTimerRequest`, `StoreTimeEntryRequest`, `UpdateTimeEntryRequest`, `DiscardTimeEntryRequest`,
`SetProjectProgressRequest`.

Policies (`app/Policies/`): `ProjectPolicy`, `ProjectMemberPolicy`, `ProjectMilestonePolicy`, `TaskPolicy`,
`TaskCommentPolicy`, `AttachmentPolicy`, `TimeEntryPolicy`, `ProjectValueRevisionPolicy` (view only - there
is no update or delete ability to grant).

### 6.2 Progress: the decision

**[D-P6-3] Progress is derived, never typed** - from checklists up through subtasks, tasks and milestones to
the project - and the **only** escape hatch is an explicit per-project `progress_mode = manual` that demands
a reason, records who set it, keeps showing the derived figure beside it, and can be switched off globally
with `projects.progress_manual_override_enabled`.

Why derived wins as the default: §20 and §21 both list `progress`, and §19 shows it to the **client**, which
means a hand-typed number is a number nobody maintains - it drifts the moment work moves, and the one place
it is read is the place it damages trust. Deriving it makes the board the single input: moving a card or
ticking a checklist item updates the client's view with no extra step, and `DashboardRegistry` widgets,
reports and the client panel all agree because they read one column written by one service.

Why a manual override exists at all: a fixed-scope project whose remaining 20 % is "client sign-off" has no
task to tick, and a project manager asked to report 60 % to a client must be able to say so. Denying that
produces a spreadsheet outside the system, which is worse than an audited column inside it. The override is
therefore per project, reasoned, attributed, visible and revocable - and it never hides the derived number.

### 6.3 The progress algorithm

One function, four levels, evaluated bottom-up; every division is `Money::div` at scale 6, every
multiplication `Money::mul`, the result rounded half-up to 4 decimals and clamped to `0 .. 100`. Cancelled
rows are **excluded from the denominator**, never counted as zero (INV-P9).

**Level 1 - a leaf task** (no non-cancelled subtasks):

```
status = completed            -> 100
status = cancelled            -> excluded from its parent's aggregate
checklist_total > 0           -> max( status.progressWeight(), 100 * checklist_done / checklist_total )
otherwise                     -> status.progressWeight()
```

`max(...)` keeps the two signals honest in both directions: a task in `in_review` with 1 of 10 ticks is 75 %
(review implies the work is done), and a task `in_progress` with 9 of 10 ticks is 90 % rather than 25 %.

**Level 2 - a parent task** (has at least one non-cancelled subtask): the weighted average of its subtasks,
`w = COALESCE(subtask.estimated_minutes, projects.default_task_estimate_minutes)`. A parent's own checklist
is ignored while subtasks exist - one rule wins, stated here so two developers cannot disagree.

**Level 3 - a milestone:** the weighted average of its non-cancelled **depth-0** tasks (a subtask's
contribution is already inside its parent), same weight rule. With no such task it falls back to
`MilestoneStatus::progressWeight()`, and a `completed` milestone is always 100.

**Level 4 - a project:**

```
progress_mode = manual                                   -> unchanged (the derived figure is still computed
                                                            for display beside it, never stored)
progress_basis = milestones AND >= 1 non-cancelled milestone
        -> SUM(m.progress_percent * m.weight) / SUM(m.weight)
else IF >= 1 non-cancelled depth-0 task
        -> SUM(t.progress_percent * w_t) / SUM(w_t)
else    -> ProjectStatus::progressWeight()
then    status = completed -> forced to 100
        status = cancelled -> frozen at its last value
```

Tasks attached to no milestone are deliberately **not** folded into the milestone basis: inventing a
synthetic bucket with an invented weight would make the number unexplainable on screen. A project whose work
lives outside its milestones should use `progress_basis = tasks`, and the project screen says so when it
detects unmilestoned open tasks.

**Cascade.** Every write that can move a number (`checklist toggle`, `task status`, `task estimate`,
`subtask add/remove`, `milestone weight/status`, `task re-milestoned`, `progress_basis` change) calls
`ProjectProgressService` inside the **same transaction**, which walks task -> parent -> milestone -> project,
writes only rows whose value actually changed, and fires one `ProjectProgressChanged` per changed row after
commit. The nightly `projects:recalculate-progress` recomputes open projects as drift repair and logs any
row it had to correct - a repair that finds something is a bug report, not routine maintenance.

### 6.4 The timer algorithm

**Storage.** A timer run is one `time_entries` row plus **N append-only `time_entry_segments` rows** - one
per start, closed on pause or stop (INV-P5). `time_entries.duration_seconds` is a cache recomputed as
`SUM(segments.duration_seconds)`; an open segment has `ended_at IS NULL` and its generated
`duration_seconds` is **0**, so no stored number ever "ticks". The live figure on screen is
`duration_seconds + (now - open_segment.started_at)`, computed in Alpine from a server timestamp and never
persisted - which is why a crashed browser, a lost request or a double-clicked Stop cannot inflate anything.

**Exactly one running timer per person.** Two independent database guarantees, never a SELECT-then-INSERT:

| Guard | Object | What it forbids |
|---|---|---|
| `uq_te_running(running_guard)` | `time_entries` | a second entry in status `running` for the same worker |
| `uq_tes_open(open_guard)` | `time_entry_segments` | a second segment with `ended_at IS NULL` for the same worker, across every entry, project and task |

`start()` simply INSERTs and catches `Illuminate\Database\UniqueConstraintViolationException`, re-reads the
worker's live entry and throws `TimerAlreadyRunningException` naming that task with a "switch to this task?"
action - so the race between two browser tabs resolves to one timer and a clear message, not two clocks.
The worker identity in both guards is `u:{user_id}` or `c:{collaborator_id}`, so a collaborator is bound by
the same single-timer rule as an employee.

**Pause semantics.** A paused entry holds **no** open segment, so starting a timer on another task while one
is paused is legitimate (§23 asks only that one timer *runs*). A worker may therefore hold several paused
entries and exactly one running one; the topbar widget lists them.

**Resilience.** `projects:auto-stop-timers` (every 5 minutes) closes any open segment older than
`projects.timer_max_hours` with `end_reason = auto_stop` and notifies the owner; `chk_te_duration` caps a
single entry at 24 hours as the database backstop. A wrong timer entry is **discarded with a reason** and
re-entered manually - the same "void and re-enter" discipline the spine applies to a mis-keyed receipt
(INV-P6 makes the caches self-correct).

**`actual_hours` derivation.** Nothing writes `tasks.actual_hours`: it is a STORED generated column over
`actual_seconds`, which is recomputed by `TimeRollupService` as the SUM of that task's live entries inside
the same transaction that stopped, edited or discarded an entry; `projects.actual_seconds` is recomputed the
same way from the project's entries (not from the tasks, so project-level time with no task is included).
Seconds are the only stored unit for elapsed time; minutes are the unit for estimates; hours exist only as
generated columns so §22's field name is real and unwritable (INV-P7).

### 6.5 Kanban ordering

`tasks.board_position` is `decimal(20,10)` and a drop writes **one** row: the new position is the midpoint of
its neighbours (`(before + after) / 2`, or `min - 1` / `max + 1` at the ends) computed with `Money::div`, so
moving a card never renumbers a column. When the gap between neighbours falls below `0.0000001`,
`TaskService::move()` renormalises that one column to `1, 2, 3 ...` inside the same transaction and returns
the new order so the client can resync. Concurrent drops are safe: two identical positions are legal and the
tie breaks on `id`, which is a cosmetic ordering question, never lost work.

### 6.6 Rollups (§23)

`TimesheetService` is the only place hours are summed (the discipline the spine applies to balances):
reports, exports, dashboard widgets and the panels all call it.

| Rollup | Grouping | Notes |
|---|---|---|
| Daily | `work_date` | per worker, or across a filter set; the day is the worker's business date, never a timestamp range |
| Weekly grid | `work_date` pivoted into 7 columns x task rows | week bounds from `localization.week_start`; row totals, column totals, grand total, and a utilisation % against `working_hours_per_day x working_days_per_week` |
| Per employee | `user_id` | §23 "employee hours"; includes entries recorded on their behalf |
| Per collaborator | `collaborator_id` | §23 "collaborator hours"; the same shape, never mixed into the employee figures |
| Per project | `project_id` | §23 "project hours"; `estimated_minutes` vs `actual_hours` variance beside it |
| Per task | `task_id` | the task detail panel |

Every rollup: excludes soft-deleted entries, sums `duration_seconds` and formats once at the edge, accepts
Phase 2's `DateRange` (today / yesterday / week / month / quarter / year / custom) and a filter set (project,
client, task, worker, source, has-manual-entries), and returns totals the CSV/print export re-uses rather
than recomputing.

---

## 7. Routes

Every admin route additionally carries `auth`, `active`, `panel:admin` from the Phase 1 route-file group;
panel routes carry their own `panel:*`. `module:*` is stated per block. Ownership checks that could leak an
id return **404**, not 403 (INV-P15). Controllers live in `App\Http\Controllers\Admin\`,
`...\Collaborator\`, `...\Client\`.

### 7.1 Admin - projects (`module:projects`)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/projects` | `admin.projects.index` | `can:projects.view_any` |
| GET `/admin/projects/create` | `admin.projects.create` | `can:projects.create` |
| POST `/admin/projects` | `admin.projects.store` | `can:projects.create` |
| GET `/admin/projects/{project}` | `admin.projects.show` | `can:view,project` |
| GET `/admin/projects/{project}/edit` | `admin.projects.edit` | `can:update,project` |
| PUT `/admin/projects/{project}` | `admin.projects.update` | `can:update,project` |
| DELETE `/admin/projects/{project}` | `admin.projects.destroy` | `can:delete,project` |
| POST `/admin/projects/{project}/restore` | `admin.projects.restore` | `can:projects.restore` |
| POST `/admin/projects/{project}/status` | `admin.projects.status` | `can:projects.change_status` |
| POST `/admin/projects/{project}/progress` | `admin.projects.progress` | `can:projects.edit` |
| GET `/admin/projects/{project}/value` | `admin.projects.value.index` | `can:projects.view_financial` |
| POST `/admin/projects/{project}/value` | `admin.projects.value.store` | `can:projects.view_financial`, `can:projects.edit` |
| POST `/admin/projects/{project}/referral` | `admin.projects.referral.store` | `can:projects.edit` (initial link only - a **change** goes to the spine's `admin.referrals.change-project`) |
| GET `/admin/projects/{project}/members` | `admin.projects.members.index` | `can:view,project` |
| POST `/admin/projects/{project}/members` | `admin.projects.members.store` | `can:projects.assign` |
| PUT `/admin/projects/{project}/members/{member}` | `admin.projects.members.update` | `can:projects.assign` |
| DELETE `/admin/projects/{project}/members/{member}` | `admin.projects.members.destroy` | `can:projects.assign` |
| GET `/admin/projects/{project}/board` | `admin.projects.board` | `module:tasks`, `can:tasks.view_any` |
| GET `/admin/projects/{project}/activity` | `admin.projects.activity` | `can:projects.view_logs` |
| GET `/admin/projects/{project}/print` | `admin.projects.print` | `can:projects.print` |
| GET `/admin/projects/export/{format}` | `admin.projects.export` | `can:projects.export` |

### 7.2 Admin - milestones (`module:project_milestones`)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/projects/{project}/milestones` | `admin.milestones.index` | `can:project_milestones.view_any` |
| POST `/admin/projects/{project}/milestones` | `admin.milestones.store` | `can:project_milestones.create` |
| GET `/admin/milestones/{milestone}` | `admin.milestones.show` | `can:view,milestone` |
| PUT `/admin/milestones/{milestone}` | `admin.milestones.update` | `can:update,milestone` |
| DELETE `/admin/milestones/{milestone}` | `admin.milestones.destroy` | `can:delete,milestone` |
| POST `/admin/milestones/{milestone}/status` | `admin.milestones.status` | `can:project_milestones.change_status` |
| POST `/admin/projects/{project}/milestones/reorder` | `admin.milestones.reorder` | `can:project_milestones.edit` |

### 7.3 Admin - tasks, checklists, comments (`module:tasks`)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/tasks` | `admin.tasks.index` | `can:tasks.view_any` |
| GET `/admin/tasks/board` | `admin.tasks.board` | `can:tasks.view_any` |
| GET `/admin/tasks/my` | `admin.tasks.my` | `can:tasks.view` (own assigned work) |
| GET `/admin/tasks/create` | `admin.tasks.create` | `can:tasks.create` |
| POST `/admin/tasks` | `admin.tasks.store` | `can:tasks.create` |
| GET `/admin/tasks/{task}` | `admin.tasks.show` | `can:view,task` |
| PUT `/admin/tasks/{task}` | `admin.tasks.update` | `can:update,task` |
| DELETE `/admin/tasks/{task}` | `admin.tasks.destroy` | `can:delete,task` |
| POST `/admin/tasks/{task}/restore` | `admin.tasks.restore` | `can:tasks.restore` |
| POST `/admin/tasks/{task}/status` | `admin.tasks.status` | `can:tasks.change_status`, policy |
| POST `/admin/tasks/{task}/assign` | `admin.tasks.assign` | `can:tasks.assign` |
| POST `/admin/tasks/{task}/move` | `admin.tasks.move` | `can:tasks.change_status`, `throttle:120,1` |
| POST `/admin/tasks/{task}/subtasks` | `admin.tasks.subtasks.store` | `can:tasks.create` |
| GET `/admin/tasks/{task}/mentionable` | `admin.tasks.mentionable` | `can:view,task`, `throttle:60,1` (project members only) |
| GET `/admin/tasks/{task}/activity` | `admin.tasks.activity` | `can:tasks.view_logs` |
| GET `/admin/tasks/export/{format}` | `admin.tasks.export` | `can:tasks.export` |
| POST `/admin/tasks/{task}/checklist` | `admin.checklist.store` | `can:update,task` |
| PUT `/admin/checklist-items/{item}` | `admin.checklist.update` | `can:update,item` |
| DELETE `/admin/checklist-items/{item}` | `admin.checklist.destroy` | `can:delete,item` |
| POST `/admin/tasks/{task}/comments` | `admin.task-comments.store` | `module:tasks`, `can:task_comments.create`, policy |
| PUT `/admin/task-comments/{comment}` | `admin.task-comments.update` | `can:update,comment` (author + window) |
| DELETE `/admin/task-comments/{comment}` | `admin.task-comments.destroy` | `can:delete,comment` |

### 7.4 Admin - attachments (`module:files`)

| Method + URI | Route name | Middleware |
|---|---|---|
| POST `/admin/attachments` | `admin.attachments.store` | `can:files.upload`, policy on the target, `throttle:30,1` |
| GET `/admin/attachments/{attachment}/download` | `admin.attachments.download` | `can:files.download`, policy |
| DELETE `/admin/attachments/{attachment}` | `admin.attachments.destroy` | `can:files.delete`, policy |

### 7.5 Admin - time tracking (`module:time_tracking`)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/timer/current` | `admin.timer.current` | `can:time_tracking.create`, `throttle:120,1` |
| POST `/admin/timer/start` | `admin.timer.start` | `can:time_tracking.create`, `throttle:60,1` |
| POST `/admin/timer/{entry}/pause` | `admin.timer.pause` | `can:time_tracking.create`, policy (own) |
| POST `/admin/timer/{entry}/resume` | `admin.timer.resume` | `can:time_tracking.create`, policy (own) |
| POST `/admin/timer/{entry}/stop` | `admin.timer.stop` | `can:time_tracking.create`, policy (own) |
| POST `/admin/timer/switch` | `admin.timer.switch` | `can:time_tracking.create` |
| GET `/admin/time-entries` | `admin.time-entries.index` | `can:time_tracking.view_any` |
| POST `/admin/time-entries` | `admin.time-entries.store` | `can:time_tracking.create` |
| PUT `/admin/time-entries/{entry}` | `admin.time-entries.update` | `can:update,entry` |
| DELETE `/admin/time-entries/{entry}` | `admin.time-entries.destroy` | `can:delete,entry` (reason required) |
| GET `/admin/timesheet` | `admin.timesheet.index` | `can:time_tracking.view` (own week grid) |
| GET `/admin/time-tracking/reports` | `admin.time-tracking.reports` | `can:time_tracking.view_reports` |
| GET `/admin/time-tracking/reports/export/{format}` | `admin.time-tracking.export` | `can:time_tracking.export` |
| GET `/admin/time-tracking/print` | `admin.time-tracking.print` | `can:time_tracking.print` |

### 7.6 Collaborator panel (`auth`, `active`, `panel:collaborator`, `module:collaborators`)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/collaborator/projects` | `collaborator.projects.index` | `can:collaborator_portal.projects` |
| GET `/collaborator/projects/{project}` | `collaborator.projects.show` | `can:collaborator_portal.projects` + policy (404) |
| GET `/collaborator/tasks` | `collaborator.tasks.index` | `can:collaborator_portal.tasks` |
| GET `/collaborator/tasks/board` | `collaborator.tasks.board` | `can:collaborator_portal.tasks` |
| GET `/collaborator/tasks/{task}` | `collaborator.tasks.show` | `can:collaborator_portal.tasks` + policy (404) |
| POST `/collaborator/tasks/{task}/status` | `collaborator.tasks.status` | `can:collaborator_portal.tasks_update` + policy |
| POST `/collaborator/tasks/{task}/move` | `collaborator.tasks.move` | `can:collaborator_portal.tasks_update`, `throttle:120,1` |
| POST `/collaborator/checklist-items/{item}/toggle` | `collaborator.checklist.toggle` | `can:collaborator_portal.tasks_update` + policy |
| POST `/collaborator/tasks/{task}/comments` | `collaborator.task-comments.store` | `can:collaborator_portal.comments` + policy |
| POST `/collaborator/attachments` | `collaborator.attachments.store` | `can:collaborator_portal.files_upload` + policy |
| GET `/collaborator/attachments/{attachment}/download` | `collaborator.attachments.download` | `can:collaborator_portal.files_download` + policy (404) |
| GET `/collaborator/timer/current` | `collaborator.timer.current` | `can:collaborator_portal.time_tracking`, `throttle:120,1` |
| POST `/collaborator/timer/start` | `collaborator.timer.start` | `can:collaborator_portal.time_tracking`, `throttle:60,1` |
| POST `/collaborator/timer/{entry}/pause` \| `/resume` \| `/stop` | `collaborator.timer.pause` \| `.resume` \| `.stop` | same + policy (own) |
| GET `/collaborator/timesheet` | `collaborator.timesheet.index` | `can:collaborator_portal.time_tracking` |
| POST `/collaborator/time-entries` | `collaborator.time-entries.store` | `can:collaborator_portal.time_tracking` (manual entry on an assigned task only) |

`collaborator.projects.index` is the **work** list here (project, status, progress, my open tasks, my hours).
The spine's §7.5 lists the same route name for the §58 financial list: Phase 12 **adds its money columns to
this existing screen** rather than registering a second route (§13).

### 7.7 Client panel - contributed into **Phase 5's** `routes/client.php`, read-only by construction

**Phase 6 declares no client route and no `client.*` route name** (F-6.2, **D31**). Phase 5 owns
`routes/client.php` and every `client.*` name; Laravel's last registration silently wins, so a second
declaration of one name - especially `client.files.index`, which the two contracts put at two different URIs -
is a defect, not a duplication. Phase 6 therefore ships **controllers and `ClientPortalSection`
implementations registered into `App\Support\ClientPortalRegistry`** (phase-05 §6.9), and Phase 5's existing
rows resolve to them. Every one of those rows carries **`client.context`** in addition to the middleware
below (F-12.3, D31), and an unregistered section makes its route `abort(404)`.

| Route name (declared by phase-05 §7) | Phase 6's contribution | Middleware on Phase 5's row |
|---|---|---|
| `client.projects.index` | the `projects` section + `Client\ProjectController@index` | `client.context`, `module:projects`, `can:client_portal.projects` |
| `client.projects.show` | `@show` - the progress view of §8.11, which also **renders** the milestone and progress panels | `client.context`, `module:projects`, `can:viewByClient,project` (policy, 404) |
| `client.tasks.index` | the `tasks` section + `Client\TaskController@index` | `client.context`, `module:tasks`, `can:client_portal.tasks`, and the rule `projects.client_can_see_tasks = 1 AND tasks.is_client_visible = 1` enforced in `TaskPolicy::viewByClient` and in the query (F-3.1) |
| `client.files.index` (**stays at `/client/files`** with a `?project=` filter - Phase 5's URI) | the `files` section + `Client\FileController@index`, listing `attachments` where `visibility = client` | `client.context`, `module:files`, `can:client_portal.files`, and `projects.client_can_see_attachments` |
| `client.files.download` (**replaces the `client.attachments.download` this contract used to declare**) | `Client\FileController@download` -> `AttachmentService::download()` | `client.context`, `module:files`, `can:client_portal.download` + `AttachmentPolicy::downloadByClient` (404) |

`client.milestones.index` and `client.progress.show` remain **Phase 5's names**: §8.11 folds their *views*
into the `client.projects.show` screen, and both names resolve to that screen rather than disappearing.

**No POST, PUT or DELETE route exists in the client panel in this phase**, so "read-only" is a property of
Phase 5's route table rather than a promise made in a controller.

---

## 8. UI screens

House rules from Phase 1 §9 and `CLAUDE.md` §6 apply everywhere: `x-ui.*` components only, search + filters
+ sortable headers + pagination + empty state + skeleton loader on every list, money right-aligned with
`tabular-nums`, a toast on every write, `x-ui.confirm` on every destructive act, responsive (tables inside
`overflow-x-auto`), light and dark.

**Kanban: yes, for tasks only** (§22 asks for it; §18's lead Kanban belongs to Phase 5). **Calendar: no** -
sections 20-23 never ask for one, and the institute timetable (§71) is a different phase. **Wizard: no** -
the requirement asks for no multi-step project form, so a project is one sectioned form with a sticky save
bar; the only wizard-like flow is the spine's record-payment modal, which Phase 6 does not own.

New shared components Phase 6 adds to `resources/views/components/ui/`: `progress` (bar + ring, with an
accessible `aria-valuenow`), `avatar-stack`, `drawer` (right-hand slide-over used by the task detail),
`timer` (the topbar widget), `kanban-column`. They join the Phase 1 set and are reusable by later phases.
One npm addition: `sortablejs` for drag and drop (no jQuery; Chart.js already came with Phase 2).

### 8.1 Projects index - `admin/projects/index.blade.php`

**Purpose.** The delivery manager's list of everything in flight.
**Components.** `x-ui.page-header` (+ New project), `x-ui.filter-bar`, `x-ui.table`, `x-ui.th-sortable`,
`x-ui.badge`, `x-ui.progress`, `x-ui.avatar-stack`, `x-ui.pagination-summary`, `x-ui.empty-state`,
`x-ui.skeleton`.
**Filters.** status (multi), priority, client, project manager, referred-by collaborator, service,
project type, date range on `deadline` or `start_date` (toggle, stated on screen), "overdue only"
(`deadline < today AND status` open), "no milestones", "unassigned", progress range, free text on
`code` / `name` / client name. Filters persist per user through `users.preferences`.
**Columns.** `code` - name (+ client beneath) - status badge - priority chip - progress bar with % -
team `avatar-stack` (+N) - deadline (red when overdue, amber within `projects.deadline_reminder_days`) -
**project value** and **net value** (right-aligned, `tabular-nums`, **only when `projects.view_financial`** -
the columns are absent from the query otherwise, never blanked) - open/total tasks - `actual_hours` -
row actions (view, edit, board, archive - each permission-gated).
**Footer.** Count, and sums of project value / net value / actual hours over the filtered set, labelled with
the filter in force.
**Empty state.** "No projects match these filters" + Clear filters; on a truly empty table, "Create your
first project" with the primary action.

### 8.2 Project create / edit form

**Purpose.** One screen, collapsible sections, no wizard.
**Sections.** *Identity* (name, client, service, project type, description) · *Ownership* (project manager,
priority, status) · *Schedule* (start date, deadline) · *Commercials* (currency read-only, budget, project
value, discount, net value shown live - **visible only with `projects.view_financial`**; on **edit** the
value fields are read-only with a "Revise value" button that opens §8.8, because INV-P1 forbids a silent
change) · *Referral* (referred-by collaborator + referral code + referral date, and the commission override:
type, rate or fixed amount, with the live sentence "COL-1024 earns 15.0000 % of received payments on this
project"; on edit, once attribution evidence or a payment exists the control is read-only with a "Change
referral" button that routes to the spine's change screen) · *Team* (the §8.6 picker, available on create).
**Components.** `x-ui.card`, `x-ui.tabs` (optional on mobile), `x-ui.form.*`, `x-ui.badge`, `x-ui.confirm`.
**Behaviour.** Sticky save bar appears only when dirty and warns on navigate-away (Phase 2 pattern);
server-side validation messages inline; the commission block is hidden entirely - not disabled - for a user
without `projects.view_financial`.

### 8.3 Project detail - `admin/projects/show.blade.php`

**Purpose.** The single place a project is understood.
**Header.** `code` + name, client, status badge with the change-status dropdown (reason modal where §2.13.1
requires one), priority, progress ring, deadline chip, PM avatar, quick actions (New task, New milestone,
Start timer, Record payment - the last one appears only once Phase 10/11 exists and the permission is held).
**Tabs** (`x-ui.tabs`, deep-linked):
1. **Overview** - `x-ui.stat-card` row: progress, open tasks, overdue tasks, hours logged vs estimated,
   milestones completed, team size; plus (gated by `view_financial`) project value, net value, received and
   outstanding once Phase 10 exists. Unmilestoned-open-tasks notice when `progress_basis = milestones`.
2. **Milestones** - §8.5.
3. **Board** - §8.4, scoped to this project.
4. **Tasks** - the table form of the board (filters + sortable columns), for people who prefer a list.
5. **Team** - §8.6.
6. **Time** - the project's entries, the per-task rollup, and estimated-vs-actual variance.
7. **Files** - attachment grid with visibility chips, drag-and-drop upload zone, per-row download/delete.
8. **Value & commission** (`projects.view_financial` only) - §8.8.
9. **Activity** - the `activity_log` feed for the project and its children, filterable by actor and by event
   type, infinite scroll (`projects.view_logs`).
**Empty states.** Every tab has its own ("No milestones yet - add the first payment stage", "No files",
"Nobody has logged time on this project").

### 8.4 Task Kanban board - `admin/tasks/board.blade.php`

**Purpose.** §22's required board, usable by a PM for a whole project and by a developer for their own work.
**Columns.** One per `TaskStatus::isBoardColumn()` (`todo`, `in_progress`, `in_review`, `blocked`,
`completed`), each with a count, the summed estimate, and a collapse toggle. `cancelled` is not a column -
it is a filter.
**Card.** `TSK-41` - title (2 lines max) - assignee avatar (or an "Unassigned" chip) - priority chip -
due-date chip (red overdue / amber soon) - checklist ratio - comment and attachment counts - a timer
play/stop button - a milestone chip when set.
**Drag and drop.** `sortablejs`; on drop the client POSTs `admin.tasks.move` with `to_status`, `after_id`,
`before_id`; optimistic move with a rollback + toast on failure (a refused transition, a missing
`blocked_reason`, or an open subtask blocking `completed` - in which case the reason modal opens instead).
`x-ui.skeleton` cards while the board loads.
**Keyboard and accessibility.** Drag is never the only path: every card has a status `select` and a
"Move to..." item in its action menu, the board is tab-navigable, and column counts are announced with
`aria-live`. This is required, not optional - HTML5 drag and drop is unusable with a keyboard.
**Swimlanes.** Toggle: none / by milestone / by assignee. **Filters.** assignee, priority, milestone, due
window, "my tasks", free text. **WIP.** Per-column count with a soft warning (no hard limit - none is
requested). **Empty column.** "Nothing here - drop a card or create a task".

### 8.5 Milestones tab

**Components.** `x-ui.table` with drag handles (`sortablejs`), `x-ui.progress`, `x-ui.badge`, `x-ui.modal`.
**Columns.** Order handle - name - status badge - start / deadline - weight - **amount** (gated by
`projects.view_financial`) - tasks done/total - progress bar - actions (edit, status, delete).
**Behaviour.** Inline reorder persists immediately; a weight change recalculates project progress live; a
vertical timeline strip above the table shows each milestone as a span between its dates with a progress fill
(plain SVG, no Gantt library and no new dependency). Delete is refused with a named reason when a payment
references the milestone.
**Empty state.** "No milestones - a project can be tracked by tasks instead" with both actions (add a
milestone, switch `progress_basis` to tasks).

### 8.6 Team tab

**Components.** `x-ui.card` grid of member cards (avatar, name, project role select, "Employee" or
"Collaborator" chip, open-task count, hours logged), `x-ui.modal` for Add member, `x-ui.confirm` for removal.
**Add member modal.** Two tabs - **Employee** (searchable select over admin-panel users, excluding existing
active members) and **Collaborator** (searchable over active collaborators; roles offered exclude `manager`).
**Removal.** Confirm dialog states plainly that history is kept, refuses while the member has a running
timer, and when they hold open tasks it lists them with a "re-assign to..." control instead of silently
unassigning.
**Former members.** A collapsed section listing soft-deleted memberships with the dates and who removed them.

### 8.7 Task detail - `x-ui.drawer` over the board, full page at `admin/tasks/show.blade.php`

**Purpose.** Everything §22 asks for in one place.
**Layout.** Left: title (inline editable), description, **subtasks** (inline add, each with status and
assignee), **checklist** (add, tick, reorder, progress bar), **comments**, **attachments**, **activity**.
Right sidebar: status, priority, assignee, reporter, milestone, start/due dates, estimate, `actual_hours`
with a timer control, created/updated by.
**Comments.** `x-ui.form.textarea` with an `@` autocomplete fed by `admin.tasks.mentionable` (project members
only), mentions rendered as chips, visibility toggle (`internal` / `team`) with a one-line explanation of who
sees which, author-only edit inside the window showing "edited" afterwards, file drop straight into a comment.
**Activity.** One merged feed - task changes, status moves, assignment changes, checklist ticks, comments,
attachments, time entries - each with actor, relative time, and old -> new values.
**Empty states.** "No subtasks", "No checklist items", "No comments yet - @mention a teammate to pull them in".

### 8.8 Value & commission tab (`projects.view_financial`)

**Purpose.** Make §107's "project value 200,000 -> 260,000" and §45's rate visible and provable.
**Top.** Three `x-ui.stat-card`s: project value, discount, net value - plus the commission override sentence
and the collaborator chip.
**Revise value modal** (`x-ui.modal` + `x-ui.confirm`). New project value, new discount, effective date,
optional commission-override change, **mandatory reason**; a live preview of old -> new with the delta; an
amber notice when the project already has payments, naming what the spine will do (supersede the open
entitlement; an over-release is reported, never auto-clawed back) so nobody revises a value believing it is
cosmetic.
**Revisions table.** `#` - effective on - old -> new value - old -> new discount - delta (signed,
colour-coded) - old -> new rate - reason - actor - recorded at. Read-only for everyone: there is no edit or
delete control anywhere on the screen, because the table has no such route (INV-P3).
**Empty state.** "The value has never been revised."

### 8.9 Time tracking screens

**Topbar timer widget** (`x-ui.timer`, in `layouts/admin` and `layouts/panel`). Shows the running task, the
live elapsed figure (computed client-side from the server timestamp - INV-P5), pause/stop, and a dropdown
listing paused entries plus "Start timer..." with a project/task picker. Starting while something runs offers
**Switch** (one atomic call), never two clocks. Survives a reload by re-reading `timer.current`.
**My timesheet** (`admin.timesheet.index`). A week grid: rows = project > task, columns = the 7 days of the
week (`localization.week_start`), cells = hours with an inline manual-entry editor, row totals, day totals,
week total, and a utilisation bar against `working_hours_per_day x working_days_per_week`. Week paging,
"jump to today", and a day/week toggle.
**Manual entry modal.** Project (required) > task (optional when `allow_time_without_task`), date, from/to
time **or** a duration, description; a back-date warning naming the limit and the extra permission; the day
cap checked server-side with the remaining allowance shown.
**Time entries register** (`admin.time-entries.index`, `time_tracking.view_any`). Filters: date range on
`work_date`, worker (employee or collaborator), project, client, task, source (timer/manual), "has manual
entries", "discarded" (trashed toggle). Columns: date - worker (+ a "collaborator" chip) - project - task -
from-to - **duration** - source chip - segment count (hover shows each start/stop pair, which is where the
append-only evidence surfaces) - recorded by - actions (edit manual, discard with reason). Footer sums hours.
**Time reports** (`admin.time-tracking.reports`, `time_tracking.view_reports`). Phase 2's `DateRange` selector
plus four `x-ui.tabs`: **Daily**, **Per employee**, **Per collaborator**, **Per project** (each: rows, hours,
share %, a bar chart through `x-ui.chart`, and for projects the estimated-vs-actual variance). Export CSV and
print; both re-use the service totals.
**Empty states.** "No time logged in this range" + Start timer / Add manual entry.

### 8.10 Collaborator panel screens

**My projects** (`collaborator.projects.index`). Columns: project - status - progress - my open tasks - my
hours; **client name only with `collaborator_portal.project_client`**, **project value only with
`collaborator_portal.project_value`** - a column the user lacks is absent from the response, not blank
(the spine's §8.11 rule). Phase 12 adds the §58 money columns to this same screen.
**My tasks** (list + board). Only tasks assigned to the collaborator; the board shows the same columns with
drag-and-drop limited to the transitions `collaborator_portal.tasks_update` allows.
**Task detail.** Description, checklist (tickable), comments (`team` visibility only), attachments
(upload/download per permission), own time entries with the timer. Never shown: estimates in money terms,
other assignees' hours, internal comments, the value/commission tab, other collaborators' anything.
**My timesheet.** The same week grid, scoped to self.

### 8.11 Client panel screens

**My projects** (`client.projects.index`). Read-only cards/table: project, status badge, progress bar,
deadline, milestones completed, last update. No hours, no costs, no internal assignee workload.
**Project progress view** (`client.projects.show`). A large progress ring, the milestone timeline with status
badges and dates, a read-only task list grouped by status (when `projects.client_can_see_tasks`), the files
list filtered to `visibility = client` (when `projects.client_can_see_attachments`), and the project's last
activity as a plain "what changed" list with no actor IPs. Invoices and payments arrive in Phase 13.
**Empty state.** "Your project has not started yet - your project manager will update this page."
**Route ownership.** These screens are reached through **Phase 5's** `client.projects.index` /
`client.projects.show` names (§7.7, D31). Folding the milestone timeline and the progress ring into
`client.projects.show` is a **view** decision only: `client.milestones.index` and `client.progress.show`
stay owned by Phase 5 and resolve to this same screen (F-6.2).

### 8.12 Dashboard widgets registered into Phase 2's `DashboardRegistry`

`ProjectsByStatusWidget`, `ProjectsOverdueWidget`, `ProjectDeadlinesWidget` (next 14 days),
`MyTasksWidget` (assigned to the viewer, grouped by status), `TasksOverdueWidget`,
`TaskThroughputChartWidget` (completed per week, 12 weeks), `HoursLoggedThisWeekWidget`,
`TeamUtilisationWidget` (hours vs capacity, per member), `ProjectValuePipelineWidget` (net value by status -
declares `permission() = projects.view_financial`). Each declares `module()` and `permission()` so Phase 2's
gating applies unchanged, and each reads through `TimesheetService` / the project scopes rather than writing
its own SQL. These are the §98 "projects" cards; Phase 23 reuses the same classes.

---

## 9. Data isolation

Every rule is an Eloquent **scope or global scope plus a Policy** check - never a hidden form field
(`CLAUDE.md` §1.10) - and every rule has a feature test asserting the status code **and** that the forbidden
columns are absent from the response body (INV-P15).

The two reusable building blocks:

```
Project::visibleTo(User $u)      // the single definition of "may see this project"
  $u->can('projects.view_any')   -> no restriction
  otherwise                      -> where(project_manager_id = $u->id)
                                    orWhereHas('members', user_id = $u->id, deleted_at IS NULL)
                                    orWhereHas('tasks', assigned_user_id = $u->id)

Task::visibleTo(User $u)
  $u->can('tasks.view_any')      -> whereIn(project_id, Project::visibleTo($u))
  otherwise                      -> the same, further limited to
                                    assigned_user_id = $u->id OR reporter_id = $u->id
                                    OR the project membership role canManage()
```

| Role | Exact query scoping |
|---|---|
| **Super Admin** | Unrestricted, still subject to module gating - disabling `projects` 403s Super Admin too (D5) while every row stays intact. |
| **Admin** | Unrestricted within the permissions Phase 1 §5 grants. |
| **Project Manager** | Holds `projects.view_any` / `tasks.view_any`, so unrestricted read and write inside the module; money columns additionally require `projects.view_financial`; a value revision additionally requires `projects.edit`. |
| **Developer / Designer / SEO Expert** | `Project::visibleTo()` and `Task::visibleTo()` above: **their own tasks**, plus the projects those tasks live in (read-only on the project row). They may change status, tick checklists, comment, upload, and log time on **their own** tasks only - `TaskPolicy::update()` requires `assigned_user_id = id` or a managing membership. Every money column (`budget_amount`, `project_value`, `discount_amount`, `net_value`, `commission_*`, `project_milestones.amount`) is excluded from the SELECT because they lack `projects.view_financial`. `time_entries`: own rows only (`time_tracking.view`, not `view_any`). |
| **Accountant** | Read-only across every Phase 6 table with `projects.view_any` + `projects.view_financial` + `view_reports`; no write ability at all. Needed because the spine's project-payment screens show the project value beside the receipt. |
| **Sales Executive** | `projects.view` on projects of **their own** clients - `clients.account_manager_id = $user->id` (phase-05 §2.7; there is no `clients.assigned_to` column - F-3.3) - no tasks, no time, no money. |
| **HR / Receptionist / Institute Manager / Course Coordinator / Teacher** | No Phase 6 permission is granted: every route 403s. HR reads workload through Phase 7's own screens, which call `TimesheetService` with an explicit user filter. |
| **Collaborator** | A global scope `BelongsToAuthenticatedCollaborator` (resolved from `collaborators.user_id`) restricts: `Task` -> `assigned_collaborator_id = my id`; `TimeEntry` -> `collaborator_id = my id`; `Project` -> **a snapshot is never a scope** (R5, D37, F-8.2 / F-12.2): projects where an **active** (`deleted_at IS NULL`) `project_members` row exists for my `collaborator_id`, **or** an **`active`** `collaborator_referrals` row exists with `subject_type = ReferralSubject::Project`, `project_id = projects.id` and `collaborator_id = mine`. `projects.collaborator_id` is a **display snapshot written by one listener and read by no scope and no engine**, so it grants nothing - a stale or hand-written value can never hand another partner's project, client name or contract value to the wrong collaborator (asserted by P6-58); `TaskComment` -> `visibility = team` within visible tasks; `Attachment` -> `visibility IN (team, client)` on visible subjects; `ProjectMember` -> the visible projects only; `ProjectValueRevision` -> **no access at all** (403). Route-model binding asserts ownership in the policy and returns **404, not 403**, so ids cannot be probed. Column gating per §8.10: `clients.name` only with `collaborator_portal.project_client`, `project_value` / `net_value` only with `collaborator_portal.project_value`, and the column is **absent from the query** otherwise. They may never see: another collaborator's or employee's hours, internal comments, `budget_amount`, `commission_*` on the project row, the team tab, or any task they are not assigned. |
| **Client** | A global scope on `Project` (`client_id = auth()->user()->client->id`), on `Task` (through that project set **and** `tasks.is_client_visible = 1`), on `ProjectMilestone` (through that project set), on `Attachment` (`visibility = client` on a visible subject). Additionally: `projects.client_can_see_tasks` / `client_can_see_attachments` must be on - the client task rule is the **AND** of the project setting and the column (`projects.client_can_see_tasks = 1 AND tasks.is_client_visible = 1`), enforced in the query **and** in `TaskPolicy::viewByClient` (F-3.1). The SELECT is an **explicit column list** that omits `budget_amount`, `project_value`, `discount_amount`, `net_value`, `commission_type`, `commission_rate`, `commission_fixed_amount`, `collaborator_id`, `referral_code`, `estimated_minutes`, `actual_seconds` and every hour column - a client must never learn the referral, the margin or the effort. Zero access to `project_value_revisions`, `project_members`, `time_entries`, `task_comments` (403). Read-only is structural: the panel registers no write route (§7.7). |
| **Student / Teacher** | No access to any Phase 6 table - every route 403. |
| **Time entries** | Own rows with `time_tracking.view`; all rows inside visible projects with `view_any`; rollups need `view_reports`. A worker may edit or discard **only their own** manual entries; editing someone else's requires `time_tracking.edit` **and** `view_any`, and always logs old/new plus the reason. |
| **Attachments** | `AttachmentPolicy` resolves the subject first: a user must be able to `view` the attachable **and** hold `files.download`; `internal` visibility is invisible to collaborators and clients; `delete` belongs to the uploader or `files.delete`. |
| **Module gating** | Disabling `projects`, `project_milestones`, `tasks`, `time_tracking` or `files` 403s those routes for everyone including Super Admin and hides their sidebar items, while every row, cache and queued job stays intact (D5, Phase 2's data-safety test). |
| **Branch (D11)** | Not applicable: no Phase 6 table carries `branch_id` (§2). When the software house wants branch-scoped delivery it is one additive nullable column plus one clause in `Project::visibleTo()`. |

---

## 10. Events, notifications, jobs, scheduled tasks

### 10.1 Events (all dispatched through `DB::afterCommit()`)

`ProjectCreated`, `ProjectUpdated`, `ProjectStatusChanged`, `ProjectArchived`, `ProjectRestored`,
`ProjectValueRevised`, `ProjectTeamChanged`, `ProjectReferralLinked`, `ProjectReferralChanged`,
`ProjectReferralCleared`, `ProjectProgressChanged`, `MilestoneSaved`, `MilestoneStatusChanged`,
`MilestoneDeleted`, `TaskCreated`, `TaskAssigned`, `TaskStatusChanged`, `TaskCompleted`, `TaskUpdated`,
`TaskDeleted`, `TaskChecklistChanged`, `TaskProgressChanged`, `TaskCommentPosted`, `TaskCommentEdited`,
`TaskCommentDeleted`, `UserMentionedInComment`, `AttachmentUploaded`, `AttachmentDownloaded`,
`AttachmentDeleted`, `TimerStarted`, `TimerPaused`, `TimerResumed`, `TimerStopped`, `TimerAutoStopped`,
`TimeEntryRecorded`, `TimeEntryUpdated`, `TimeEntryDiscarded`.

**`ProjectValueRevised` and `ProjectReferralLinked` / `ProjectReferralChanged` are the phase's contract with
the commission spine**: Phase 10/11 listens to them (entitlement supersede per the spine's §6.6 case 8, and
`collaborator_referrals` creation per its §6.2) and Phase 6 must never assume what those listeners do.

### 10.2 Notifications (database channel now, mail-ready - §97)

| Notification | To | Trigger |
|---|---|---|
| `TaskAssignedNotification` | the assignee (the user, or the collaborator's user when one exists) | `TaskAssigned` - §97 "new task". Carries task reference, title, project, priority, due date and a deep link; **not** sent when a user assigns a task to themselves |
| `TaskUnassignedNotification` | the previous assignee | re-assignment, so nobody keeps working on something taken away |
| `TaskMentionedNotification` | each newly mentioned user | `UserMentionedInComment`, once per person per comment |
| `TaskCommentedNotification` | assignee + reporter (minus the author, minus anyone already receiving a mention) | `TaskCommentPosted` |
| `TaskStatusChangedNotification` | reporter + project manager | a move into `blocked`, `in_review` or `completed` |
| `TaskDueSoonNotification` / `TaskOverdueNotification` | assignee (+ PM on overdue) | scheduler, using `projects.deadline_reminder_days` |
| `ProjectAssignedNotification` | the added member | `ProjectTeamChanged` |
| `ProjectCreatedNotification` | the client's user, when one exists | §97 "new project" |
| `ProjectStatusChangedNotification` | the client's user | a move to `in_progress`, `completed`, `on_hold` or `cancelled` |
| `ProjectDeadlineApproachingNotification` | project manager | scheduler |
| `ProjectValueRevisedNotification` | holders of `projects.view_financial` | `ProjectValueRevised`, carrying old -> new and the reason |
| `TimerAutoStoppedNotification` | the worker | the sweep closed their timer; states the hours recorded and how to correct them |
| `OverdueWorkDigestNotification` | project managers | the daily digest, when `projects.overdue_digest_enabled` |

Notification classes live in `app/Notifications/Project/`; Phase 22 wires the mail channel and the preference
matrix without changing them.

### 10.3 Queued jobs

| Job | Key properties |
|---|---|
| `RecalculateProjectProgress` | `ShouldBeUnique` (`project-progress:{id}`, `uniqueFor` 300), `$afterCommit = true`; used only for bulk paths (a milestone reorder, a CSV import, the nightly repair) - an interactive change recalculates **inline inside the transaction** so the user never sees a stale percentage |
| `SyncProjectTimeCaches` | `project-time:{id}`; the bulk counterpart of `TimeRollupService` |
| `NotifyTaskAssigned` / `NotifyMentions` | `$afterCommit = true`, `tries` 3 - a notification failure never rolls back a task change |
| `PruneDeletedAttachments` | removes blobs of attachments soft-deleted more than 30 days ago; logs each path |
| `BuildTimesheetExport` | CSV/PDF for large ranges, delivered through a notification |

### 10.4 Scheduler

| Command | Cadence | Purpose |
|---|---|---|
| `projects:auto-stop-timers` | every 5 minutes | closes open segments older than `projects.timer_max_hours` with `end_reason = auto_stop`, bounded to 200 rows, notifies each owner (§6.4) |
| `projects:recalculate-progress` | daily 01:00 | drift repair over open projects, bounded; **logs every row it had to correct** so a silent bug surfaces as a report |
| `projects:deadline-reminders` | daily 08:00 | project deadlines and task due dates inside `projects.deadline_reminder_days` |
| `projects:overdue-digest` | daily 09:00 | one digest per project manager listing overdue tasks and milestones, when enabled |
| `attachments:prune-deleted` | daily 02:30 | the 30-day blob prune |
| `projects:verify-constraints` | daily 02:10 | asserts every unique index, CHECK, generated column and trigger of §2.14 still exists and alerts loudly if one vanished (INV-P17; the spine runs the same pattern for its own tables) |

---

## 11. Acceptance tests

`tests/Feature/Project/`. Phase 6 is not done until every row passes. Each row names the assertion, not the
intention.

**Schema, constraints and guards**

1. **P6-01** `migrate:fresh --seed` runs clean; every Phase 6 migration rolls back in reverse order without
   error, including the trigger and the generated columns.
2. **P6-02** All 13 STORED generated columns exist with the exact expression of §2.14
   (`information_schema.columns.generation_expression` is asserted, not just the value).
3. **P6-03** `projects.net_value` equals `project_value - discount_amount` after a value revision, and a
   direct `DB::table('projects')->update(['net_value' => 1])` **fails** at the database.
4. **P6-04** Inserting a second `project_value_revisions` row with the same `(project_id, revision_no)` throws;
   `chk_pvr_change` rejects a revision where nothing differs.
5. **P6-05** `DELETE FROM project_value_revisions` throws SQLSTATE 45000, and
   `ProjectValueRevision::first()->delete()` throws `ImmutableRevisionException` **before** reaching the
   trigger; `->update()` on any money column throws.
6. **P6-06** `chk_projects_money` rejects a discount greater than the value and a negative value;
   `chk_projects_commission` rejects `commission_type = percentage` with a NULL rate;
   `chk_projects_rate` rejects `101.0000`.
7. **P6-07** `chk_projects_manual_progress` rejects `progress_mode = manual` with no reason.
8. **P6-08** `chk_tasks_depth` rejects a subtask of a subtask; `TaskService::create()` refuses it with a
   readable error first.
9. **P6-09** `chk_tasks_assignee` rejects a task with both a user and a collaborator assignee.
10. **P6-10** `chk_pm_one_party` rejects a `project_members` row with neither party and with both.
11. **P6-11** Adding the same user twice to one project throws on `uq_pm_user_active`; removing them and
    re-adding them **succeeds**, and the trashed row is still readable with `withTrashed()`.
12. **P6-12** `chk_te_duration` rejects an entry of 86401 seconds; `chk_te_manual_complete` rejects a manual
    entry with a NULL `ended_at`; `chk_tes_window` rejects a zero-length segment.

**Progress derivation**

13. **P6-13** A task with 4 checklist items, 1 ticked, `in_progress`: progress = 25 (the status floor equals
    the ratio); ticking a second gives 50; ticking all four gives 100 while the status stays `in_progress`.
14. **P6-14** A task `in_review` with 1 of 10 ticked is **75**, not 10 - the `max()` rule of §6.3.
15. **P6-15** A parent with two subtasks estimated 60 and 180 minutes, the small one complete: parent
    progress = **25.0000** (weighted, not 50); the parent's own checklist is ignored.
16. **P6-16** A milestone with three tasks (estimates 60/60/120, the two small ones complete):
    progress = **50.0000**; the project with that single milestone reports the same figure.
17. **P6-17** A **cancelled** task is excluded from the denominator: the same milestone with one extra
    cancelled task still reports 50.0000 (INV-P9).
18. **P6-18** Two milestones with weights 3 and 1 at 100 % and 0 %: project = **75.0000**. With
    `progress_basis = tasks` the same project reports the task-weighted figure instead.
19. **P6-19** A project with no milestones and no tasks reports `ProjectStatus::progressWeight()`; moving it
    to `completed` forces 100; moving a project to `cancelled` freezes the last value.
20. **P6-20** `progress_mode = manual` survives every checklist tick, task move and milestone completion; the
    screen still shows the derived figure beside it; `setAuto()` immediately recalculates; with
    `projects.progress_manual_override_enabled = false` the manual route 403s.
21. **P6-21** Any writer other than `ProjectProgressService` throws when it touches `progress_percent`
    (INV-P8).

**Project value, commission override, referral**

22. **P6-22** `ProjectValueService::revise()` writes exactly one revision row with old/new value, old/new
    discount, old/new net, the signed `delta_amount`, the reason, `effective_on`, `changed_by_name` and the IP,
    updates the project, bumps `value_revision_count`, and writes one activity row carrying the reason.
23. **P6-23** `ProjectService::update()` with a changed `project_value` in the payload **throws** and writes
    nothing (INV-P1); the same for `discount_amount`, `commission_type`, `commission_rate`,
    `commission_fixed_amount` and `collaborator_id`.
24. **P6-24** Ten concurrent revisions produce `revision_no` 1..10 with no gap and no duplicate.
25. **P6-25** `ProjectReferralService::link()` snapshots `referral_code` and `referral_date`, fires
    `ProjectReferralLinked`, and is **refused** once a `project_payments` row exists (a stub row in the test),
    directing the caller to the change flow (INV-P13).
26. **P6-26** A project-override commission (`percentage`, `15.0000`) round-trips as `decimal(8,4)` and is
    readable by a stubbed resolver as the spine's `project_override` source; a half-configured override cannot
    be saved (P6-06).
27. **P6-27** Every status change, assignment, team change, value revision, referral change and time-entry
    discard writes an `activity_log` row with old and new values, the actor, the IP and - where mandated - the
    reason; a `blocked` transition with no reason is rejected by the Form Request (INV-P16).

**Timer and time tracking**

28. **P6-28** `TimerService::start()` twice for the same user throws `TimerAlreadyRunningException`, the second
    INSERT is rejected by `uq_te_running`, **exactly one** `time_entries` row exists, and the message names the
    task already running.
29. **P6-29** Two parallel requests (`DB` transaction concurrency test) both calling `start()` leave exactly one
    running entry and one open segment; `uq_tes_open` rejects the loser.
30. **P6-30** start -> pause -> resume -> stop produces **two** segments with non-null `ended_at`,
    `duration_seconds` = the sum of the two, and no row where a total was incremented: rewriting
    `duration_seconds` by recomputation yields the identical value (INV-P6).
31. **P6-31** While running, the entry's `duration_seconds` stays at the value of the closed segments only, and
    the open segment's generated `duration_seconds` is **0** (INV-P5).
32. **P6-32** Starting a timer on task B while A runs is refused; `switchTo()` closes A with
    `end_reason = switched` and opens B atomically, leaving one running entry.
33. **P6-33** A paused entry does not block a new timer on another task; a worker may hold several paused
    entries and exactly one running one.
34. **P6-34** `tasks.actual_seconds` equals `SUM` of its live entries after stop, after a manual edit and after
    a discard; `actual_hours` equals `ROUND(actual_seconds/3600, 2)`; `projects.actual_seconds` includes
    task-less project time; a replayed `SyncProjectTimeCaches` changes nothing (idempotent).
35. **P6-35** `tasks.actual_hours` cannot be written: a direct update fails at the database (INV-P7).
36. **P6-36** `autoStopStale()` closes a 13-hour-old open segment with `end_reason = auto_stop`, notifies the
    owner, is bounded, and a second run finds nothing.
37. **P6-37** A manual entry with `ended_at <= started_at`, with a future date, outside the back-date window
    without a reason, or pushing the worker's `work_date` total past `manual_time_max_hours_per_day` is
    rejected with a field-level message.
38. **P6-38** Discarding an entry requires a reason, soft-deletes it, removes it from every cache and every
    rollup, and leaves the segments readable; discarding a **running** entry stops it first so `uq_te_running`
    is released.
39. **P6-39** `work_date` is computed in the worker's timezone: a timer started at 23:30 Asia/Karachi belongs to
    that calendar day, and the daily rollup for the next day is zero.
40. **P6-40** `TimesheetService` rollups: daily, weekly grid, per employee, per collaborator, per project and
    per task each sum to the same grand total over the same filter set, exclude discarded entries, and the CSV
    export matches the screen to the second.
41. **P6-41** A collaborator's hours never appear in the per-employee rollup and vice versa (§23).

**Kanban, comments, attachments**

42. **P6-42** A drop between two cards writes one row whose `board_position` lies strictly between its
    neighbours; 200 sequential drops in the same gap trigger exactly one column renormalisation and the final
    order matches the drag order.
43. **P6-43** `move()` into `completed` on a task with an open subtask is refused and names the subtask;
    `blocked` without a reason is refused; a refused move leaves `board_position` and `status` untouched.
44. **P6-44** A comment mentioning `@alice @alice @bob` creates **two** mention rows, notifies each once, and a
    handle for a non-member is left as plain text with no row and no notification; `tasks.comment_count` matches
    a COUNT of live comments.
45. **P6-45** An edit after `task_comment_edit_minutes` is refused; an edit by a non-author is refused; an
    in-window author edit stamps `edited_at` and logs the old body.
46. **P6-46** Upload rejects an oversized file, a disallowed extension, and a `.php` payload renamed `.png`
    (sniffed MIME); the stored name is a ULID, the file is **not** reachable by URL, and a download goes through
    the controller and writes an activity row.

**Authorization and isolation** (each asserts the HTTP status **and** that nothing was written / that the
forbidden columns are absent from the body)

47. **P6-47** A permission-less user gets 403 on every Phase 6 route (the test walks the route list, so a route added later cannot escape it); granting the exact permission named in
    §7 makes that route 200.
48. **P6-48** A Developer sees only projects they manage, are a member of, or hold a task in; a project they
    cannot see 404s by id; the index response contains no `project_value`, `budget_amount`, `net_value` or
    `commission_*` key.
49. **P6-49** A Developer may change the status of **their own** task and is refused (403) on someone else's;
    they may log time only on their own task.
50. **P6-50** A Collaborator sees only tasks where `assigned_collaborator_id` is theirs; another
    collaborator's task 404s; the project list omits the client name without
    `collaborator_portal.project_client` and omits `project_value` / `net_value` without
    `collaborator_portal.project_value` - **the keys are absent, not null**; `internal` comments and `internal`
    attachments never appear; `/collaborator/...` requests for the value-revision data 403.
51. **P6-51** A Client sees only their own projects; another client's project 404s; the payload omits every
    money, effort, referral and commission column; with `projects.client_can_see_tasks = false` the task route
    403s, and with the setting on, a task whose `is_client_visible = 0` is **absent from the list and 404s by
    id** (F-3.1; phase-05 test 74 is the twin assertion); an attachment with `visibility != client` 404s on
    download. No write route exists in the client panel (asserted by walking Phase 5's route list).
52. **P6-52** A Student and a Teacher get 403 on every Phase 6 route; a Student hitting `/admin/projects` gets
    403 from `panel:admin` (Phase 1).
53. **P6-53** Disabling the `projects` module 403s its routes for Super Admin too, hides the sidebar entries,
    and leaves row counts in all 11 tables identical before and after disable + re-enable; the same for `tasks`
    and `time_tracking`.

**Integrity and performance**

54. **P6-54** `projects:verify-constraints` passes on a fresh database and **fails loudly** when a CHECK, a
    unique guard, a generated column or the trigger is dropped (the test drops one in a transaction and rolls
    back).
55. **P6-55** A static scan asserts no `+ - * /` on `project_value`, `discount_amount`, `net_value`,
    `budget_amount`, `amount`, `weight` or `progress_percent` outside `App\Support\Money` (INV-P14).
56. **P6-56** Query budgets with `DB::listen`: the projects index with 50 rows, the board with 200 cards, the
    project detail and the week grid each issue a bounded number of queries with no N+1 (the caches of §2.5
    exist for this).
57. **P6-57** Soft-deleting (archiving) a project keeps its tasks, time entries and revisions; `restore` brings
    the project back with every cache and percentage unchanged; force-deleting a project that has revisions is
    refused by the FK.
58. **P6-58** **A snapshot grants nothing** (F-8.2 / F-12.2, R5): a project whose `collaborator_id` is written
    by hand to collaborator B, with **no** `project_members` row and **no** `active` `collaborator_referrals`
    row for B, **404s** for B's user, is absent from `collaborator.projects.index`, and leaks neither the
    client name nor `project_value`. Adding an `active` `collaborator_referrals` row
    (`subject_type = project`, `project_id`, `collaborator_id = B`) makes exactly that project visible;
    superseding or revoking the row removes it again on the next request. An active `project_members` row
    alone also grants access, independently of any snapshot.

---

## 12. Risks and open questions

### 12.1 Risks accepted, with the mitigation

| # | Risk | Mitigation / why it is accepted |
|---|---|---|
| R-1 | **Two guard tricks carry the single-timer guarantee** (`running_guard`, `open_guard`), and clever is a liability: a developer who writes the generated expression slightly differently in a later migration silently removes the protection. | Both guards have their own test asserting the **second** INSERT throws (P6-28, P6-29), `projects:verify-constraints` re-asserts the expression text daily, and P6-02 compares `generation_expression` rather than behaviour. |
| R-2 | **Caches everywhere** (`actual_seconds`, `checklist_*`, `subtask_*`, `comment_count`, `attachment_count`, `value_revision_count`, four `progress_percent` columns). Every one of them can drift. | They are **recomputed, never incremented** (INV-P6), so any write self-heals; the nightly recalculation logs corrections as bug reports; P6-34 and P6-44 assert recomputation is idempotent. The alternative - deriving on read - costs an aggregate per Kanban card. |
| R-3 | **The progress algorithm is an interpretation.** §20 and §21 list "progress" and say nothing about weights, so a client may expect a simple task count. | §6.3 is one page, the project screen shows which basis and which weights produced the number, and the manual override exists for the case where the derivation disagrees with reality. Raised as Q2. |
| R-4 | **Estimate-weighted averages punish bad estimates**: a task with no estimate silently weighs 60 minutes. | The fallback is a **setting** (`projects.default_task_estimate_minutes`), not a constant in a service, and the screen flags open tasks with no estimate. |
| R-5 | **Overlapping manual time entries are not prevented.** Detecting an overlap is a check-then-act race that no index can close, so two entries can claim the same hour. | The day cap (`manual_time_max_hours_per_day`) is enforced under a lock, which bounds the damage; overlaps are visible in the register and in the week grid; and time is not money - no payment depends on it in this phase. Stated plainly rather than implied. |
| R-6 | **Assignment points at `users`, not `employees`** ([D-P6-2]), so a project member who has no login cannot be assigned work. | That is the intent: assignment drives notifications, timers and permissions, all of which need a login. Phase 7 reaches the HR profile through `employees.user_id`; a staff member who must appear on a team without a login is modelled as a collaborator or given an inactive user. Raised as Q3. |
| R-7 | **`attachments` is claimed by this phase for the whole application** ([D-P6-6]). If Phase 22 designs a different file model the table will be bent to fit or duplicated. | §13 states the contract now, while Phase 22 is unwritten, which is the cheapest moment to agree it; the table is deliberately generic (polymorphic subject, visibility, private disk, checksum) and carries nothing project-specific. |
| R-8 | **`collaborator.projects.index` is registered here although the spine's §7.5 lists it under Phase 12.** | Same name, same permission, same controller - Phase 12 adds money columns to the existing screen (§13). Phase 6 needs a collaborator project list four phases earlier and the alternative (a second route) is worse. Flagged as Q5 rather than decided silently. |
| R-9 | **Four FKs stay unenforced until Phase 8 runs** its guarded migration: a typo could write `collaborator_id = 999`. | The columns are indexed and always filtered through the `Collaborator` relation; `ProjectReferralService` and `ProjectService` validate existence against the model when the table exists; P6-01 asserts the promotion migration is reversible. The alternative - delaying projects until Phase 8 - reorders the client's plan. |
| R-10 | **No billable/non-billable split and no hourly rates** on time entries. A business that invoices by the hour cannot produce an invoice line from Phase 6. | §23 asks for hours, not billing; inventing a rate here would duplicate Phase 13's invoicing model and Phase 7's payroll. One nullable `is_billable` column plus a rate on `project_members` is the additive change if Phase 13 needs it. |
| R-11 | **The activity feed unions several subjects** (project, milestones, tasks, comments, time entries) out of one `activity_log` table. On a long-running project that query grows. | It is paginated with a keyset on `id`, filtered by `subject_type + subject_id IN (...)` against the existing index, and bounded to the project's own children. Phase 23 owns the global log screen and its indexing. |

### 12.2 Open questions for the client (defaults assumed, nothing blocked)

| # | Question | Default assumed |
|---|---|---|
| Q1 | §20's **"project type"** - does it mean the engagement model (fixed price / hourly / retainer) or the kind of work (web development, SEO, ...)? | The engagement model, as `ProjectType`; the kind of work is `projects.service_id` pointing at the Phase 4 `services` table (§11) so the same fact is not stored twice. If the client means the kind of work, hide `project_type` and keep `service_id` - no schema change. |
| Q2 | Is **progress** derived from the work, or typed by the project manager? | Derived ([D-P6-3], §6.3), with an audited per-project manual override that can be switched off globally. |
| Q3 | Should a staff member **without a login** be assignable to a project or a task? | No ([D-P6-2]): assignment drives notifications, permissions and timers. Such a person is added as a collaborator or given an inactive user account. |
| Q4 | Are **subtasks one level deep** enough, or is an arbitrary tree wanted? | One level (INV-P12). An arbitrary tree makes progress rollup, the board and the "complete the parent" rule ambiguous, and §22 says only "subtasks". |
| Q5 | `collaborator.projects.index` is registered in Phase 6 and extended by Phase 12 (the spine's §7.5 lists it there). Acceptable? | Yes - one screen, one route name, one controller; Phase 12 adds the §58 money columns. Recorded here because it touches a binding document. |
| Q6 | May a **client** see tasks and files at all, or only milestones and a progress ring? | Yes, both, per §19, each behind a setting (`projects.client_can_see_tasks`, `client_can_see_attachments`) and behind `AttachmentVisibility::Client`, so a business can close it without code. |
| Q7 | Should completing the last task **auto-complete** its milestone and the project? | The milestone yes (§2.13.2, reversible), the **project no** - closing a project is a commercial act with a delivery date and a commission consequence, so it stays a human decision. |
| Q8 | Does a timer need **idle detection** ("you were away 40 minutes - keep or discard?") | No. `projects.timer_max_hours` plus the auto-stop sweep covers the forgotten timer; idle detection needs client-side activity tracking the requirement never asks for. |

---

## 13. Requests to other phases

Stated as `table.column - why`, plus the behavioural asks.

### 13.1 Columns, tables and behaviour this phase needs

| Request | Why |
|---|---|
| `clients.id`, `clients.user_id` (nullable, unique) - **Phase 5** | `projects.client_id` (RESTRICT) and the client panel's `auth()->user()->client` scope (INV-P15) |
| ~~`clients.assigned_to`~~ - **withdrawn (F-3.3); no such column is asked for** | the column already exists under its real name, **`clients.account_manager_id`** (phase-05 §2.7, FK `users.id`, nullable, indexed), and §9's Sales Executive row now reads it |
| `leads.id` - **Phase 5**, plus setting `projects.lead_id` when a `Won` lead converts | §18 -> §20 traceability; nothing here breaks if it stays null |
| `services.id` - **Phase 4** | `projects.service_id`, so "kind of work" is not free text (§12.2 Q1) |
| `employees.user_id` (FK `users.id`, nullable, **unique**) - **Phase 7** | the only bridge from an assigned user to an HR profile ([D-P6-2]). Phase 7 must **not** add a second assignment table: workload comes from `project_members` and `TimesheetService` |
| `collaborators` + a guarded migration `add_collaborator_fks_to_project_tables` promoting `projects.collaborator_id`, `project_members.collaborator_id`, `tasks.assigned_collaborator_id`, `time_entries.collaborator_id`, `time_entry_segments.collaborator_id` (all `restrictOnDelete`) - **Phase 8** | [D-P6-1]; RESTRICT because a collaborator with delivery history must not vanish |
| `collaborators.user_id` (nullable, unique) - **Phase 8** | the collaborator panel scope and the assignee notification |
| `Collaborator\ReferralService` (`attach`, `change`, `revoke`, `effectiveOn`) - **Phase 9/10**, plus a listener on `ProjectReferralLinked` / `ProjectReferralChanged` that writes `collaborator_referrals` | attribution evidence is the spine's table, not a project column (INV-P13) |
| A listener on **`ProjectValueRevised`** performing the entitlement supersede of the spine's §6.6 case 8 - **Phase 10/11** | Phase 6 records the revision and says nothing about commission; the spine decides |
| `project_payments.project_milestone_id` must reference `project_milestones.id` with `nullOnDelete`, and Phase 11 must read `projects.net_value` / `project_milestones.amount` rather than recompute them - **Phase 10/11** | INV-P2; two definitions of one denominator is how a ledger and a project screen disagree |
| `App\Services\Finance\DocumentNumberService::next(string $prefixKey, string $counterKey, string $pad = '%06d')` - **Phase 5** (F-4.1, **D27**) · **satisfied** | Phase 5 migrates before Phase 6 and ships the single numbering implementation, so `ProjectNumberService` delegates **from day one** and passes `'%05d'` explicitly (§6.1). One settings-row-locking routine in the codebase, not two - and no phase, Phase 10 included, may re-create the class |
| Extend the **existing** `collaborator.projects.index` screen with the §58 money columns instead of adding a route - **Phase 12** | R-8 / Q5 |
| `invoices.project_milestone_id` (nullable) if milestones are to be invoiced, and `invoices.paid_amount` derived from `project_payments` - **Phase 13** | §31 + the spine's §13.1; Phase 6 adds no invoice column itself |
| Use the `attachments` table of §2.9 for ticket, meeting, client, employee, student and course files; do **not** create a `files` table. Wire the mail channel for the 14 notification classes of §10.2 - **Phase 22** | [D-P6-6], §96, §97 |
| Every project, task and time report must call `TimesheetService` / the `Project::visibleTo()` scope and must never re-implement a sum or a visibility rule; `projects`, `tasks` and `project code` must be in global search respecting those scopes - **Phase 23** | §99, §108, the discipline the spine applies to balances |
| `SettingsRegistry`: the new `projects` group and its 17 keys (§5); `DashboardRegistry`: the nine widgets of §8.12 - **Phase 2 registries, extended here** | settings definitions live in code, values in the DB |
| Run P6-28, P6-29, P6-54, P6-55 and P6-56 again in the hardening pass - **Phase 24** | the DB-level guarantees are the ones most likely to be quietly skipped (the spine's R-13) |

### 13.2 Documentation and log updates

| Request | Why |
|---|---|
| `DEVELOPMENT_LOG.md` §4: **D32** - **assignment of work -> `users.id`; organisational duty -> `employees.id`; the bridge is `employees.user_id` (nullable, unique)**. It binds phases 7, 13 and 14-17 too | [D-P6-2], F-11.1 |
| `DEVELOPMENT_LOG.md` §4: **D33** - elapsed time is stored only as append-only `time_entry_segments` rows; every duration is a recomputed cache and "one running timer per worker" is a database unique index, not application logic | INV-P4, INV-P5, INV-P6 |
| `DEVELOPMENT_LOG.md` §4: **D34** - progress is derived from checklists/subtasks/tasks/milestones by `ProjectProgressService`, the only writer of `progress_percent`; a per-project manual override is reasoned, attributed and revocable | [D-P6-3] |
| `DEVELOPMENT_LOG.md` §4: **D35** - `project_value_revisions` is append-only with a `BEFORE DELETE` trigger, and the five value/override columns on `projects` are writable only by `ProjectValueService` | INV-P1, INV-P3 |
| `DEVELOPMENT_LOG.md` §9: record Q1-Q8 of §12.2 beside the existing Q1-Q8 with the defaults assumed | the log is the single question list |
| `CLAUDE.md` §3: one paragraph noting that `project_value_revisions` carries no soft deletes (the same reasoning as the spine's D16) and that `project_members` uses `deleted_at` as "removed from the team" | so nobody "fixes" either by adding or removing a column |
| `CLAUDE.md` §6: the five new `x-ui.*` components of §8 and the `sortablejs` dependency | they are shared, not project-local |

Numbering note: decision numbers are **allocated centrally** in
[`../design/resolutions.md`](../design/resolutions.md) §4 and pasted into `DEVELOPMENT_LOG.md` §4 by the
owner; no contract may claim one (F-10.1). Phase 6's four are **D32, D33, D34, D35** (they replace the
D19-D22 this contract previously claimed). Phase 6 also **cites** D19 (soft-delete categories, §2.2 / §2.8 /
§2.11), D16 (append-only financial tables, §2.2), D27 (`DocumentNumberService`, §6.1) and D31
(`routes/client.php` ownership and `client.context`, §7.7).

---

## Convergence log (2026-09-12)

Applied from [`../design/resolutions.md`](../design/resolutions.md) §7 (Apply map, row `docs/phases/phase-06.md`).
Nothing else in this contract was restructured or redesigned.

| Finding | Change made |
|---|---|
| F-2.6 | **No change - `attachments.visibility` stays** as the only client-visibility mechanism (§2.9, `AttachmentVisibility` `internal` / `team` / `client` in §3). Phase 19-23's `is_client_visible` migration is deleted on its side; nothing here needs an `is_client_visible` column. |
| F-2.7 | §1.3 gains the sentence that **`project_members` is the only project-people table**: no `project_collaborator` and no `project_user` pivot exists, and a later phase reaches a collaborator's projects through `belongsToMany(Project::class, 'project_members')`. |
| F-3.1 | §2.5 gains **`is_client_visible` boolean not null default `true`** and `INDEX (project_id, is_client_visible)`; §7.7 and §9 (Client row) state the client rule as the **AND** of `projects.client_can_see_tasks = 1` and `tasks.is_client_visible = 1`, enforced in the query **and** in `TaskPolicy::viewByClient`; P6-51 extended to assert it. |
| F-3.3 | §9's Sales Executive row now reads **`clients.account_manager_id = $user->id`**; §13.1's `clients.assigned_to` request **withdrawn** (no such column exists). |
| F-4.1 | §6.1 `ProjectNumberService::next()` is now a **thin delegate from day one** to Phase 5's `DocumentNumberService`, passing `'%05d'` **explicitly**; the "if the class exists" conditional and the duplicate locking description are gone. §13.1's request retargeted **Phase 10 -> Phase 5** and marked satisfied (**D27**). |
| F-5.5 | §3's note restated: `CommissionCalculationType` is **declared by Phase 6**; the spine and phase-10-12 **reuse, not create** (R3). No case or name changed. |
| F-6.2 | §7.7 rewritten: **Phase 6 declares no `client.*` route name** (D31). It contributes controllers and `ClientPortalSection` registrations behind Phase 5's existing names; the redeclarations of `client.projects.index` / `.show` and `client.tasks.index` are **deleted**; the file route becomes Phase 5's `client.files.index` at `/client/files` with a `?project=` filter; `client.attachments.download` -> **`client.files.download`**; §8.11 notes that `client.milestones.index` / `client.progress.show` stay Phase 5's and resolve to `client.projects.show`. |
| F-6.3 | §5's `projects` settings group sort **85 -> 86**, with the reason stated. |
| F-8.2 / F-12.2 | §9's Collaborator row: `projects.collaborator_id = my id` **deleted** as a scope. A collaborator's projects are now an **active `project_members` row** for their `collaborator_id` **or** an **`active` `collaborator_referrals` row** (`subject_type = ReferralSubject::Project`, `project_id = projects.id`, `collaborator_id = mine`) - R5 / D37, a snapshot is never a scope. §2.1's column note marks it display-only. New test **P6-58** asserts a hand-written snapshot grants nothing and that the referral row (or a membership) is what grants access. |
| F-9.1 | §2.2 cites **D19 + D16**, §2.8 and §2.11 cite **D19**, instead of each arguing its own case. |
| F-11.1 | [D-P6-2] **unchanged** in substance and now cites **D32** with the full rule (assignment -> `users.id`; duty -> `employees.id`; bridge `employees.user_id` nullable unique); §1.2's Phase 7 row cites it too. |
| F-12.3 | §7.7 states that **every** client route Phase 6 contributes to carries `client.context` (the five rows previously had `auth`, `active`, `panel:client`, `module:projects` only). |
| F-13.2 | §2.9's morph map gains **`collaborator`** and **`invoice`** - the two §96 subjects with no file store - each resolving to a policy-backed model, with the note that they 404 until phases 8 and 13 ship. |
| F-10.1 | §13.2 renumbered to resolutions §4: D19 -> **D33**, D20 -> **D34**, D21 -> **D32**, D22 -> **D35**; the closing numbering note replaced - numbers are allocated centrally, no contract claims one, and the D19 / D16 / D27 / D31 citations are listed. |
