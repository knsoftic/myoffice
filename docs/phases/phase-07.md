# PHASE 7 CONTRACT - HR: employees, departments, attendance, leave, payroll

**Status: binding.** Column names, table names, class names, enum cases, permission strings, route names
and settings keys below are fixed. Conventions live in [`../../CLAUDE.md`](../../CLAUDE.md);
[`phase-01.md`](phase-01.md) and [`phase-02.md`](phase-02.md) win over this document in any conflict and
such a conflict is recorded in §12.2 as an open question, never silently redesigned. Progress is tracked
in [`../../DEVELOPMENT_LOG.md`](../../DEVELOPMENT_LOG.md).

Requirement source: [`../requirements.md`](../requirements.md) section **D (§24-28)**, with §99 (HR
reports), §106-107 (activity log + audit trail), §109-112 (database, financial integrity, security, data
isolation) and §113 (branch-ready, D11). Where this phase touches money it obeys the same discipline as
[`../design/finance-commission-spine.md`](../design/finance-commission-spine.md) - that document stays
the authority for collaborator commission, which is **a different subject from employee payroll** (§6.9).

Decisions are labelled **[D-HR-n]** so a code review can cite them.

---

## Contents

| § | Contents |
|---|---|
| 1 | Goal, dependencies, ownership, **invariants** |
| 2 | Schema (24 tables), status lifecycles, guards and CHECK constraints, migration order |
| 3 | Enums |
| 4 | PermissionRegistry additions |
| 5 | SettingsRegistry additions (new `hr` group) |
| 6 | Services: **attendance resolution**, **leave balance ledger**, **payroll algorithm**, lock and correction model |
| 7 | Routes |
| 8 | UI screens |
| 9 | Data isolation |
| 10 | Events, notifications, jobs, scheduled tasks |
| 11 | Acceptance tests (65) |
| 12 | Risks and open questions |
| 13 | Requests to other phases |

---

## 1. Goal, dependencies, ownership, invariants

### 1.1 Goal

After this phase the business can run its own staff end to end: maintain an employee record (with a
login or without one, documents that warn before they expire, skills, an emergency contact, an
employment type, a designation and a reporting line) inside dynamic departments that each have a head;
capture daily attendance from check in/check out against a named shift, with late minutes, early-leave
minutes, working hours, weekends and public holidays resolved by one calendar service, corrected only
through an audited correction row, and summarised per month; run a leave system with per-type annual
quotas, a balance that is always re-derivable from its own ledger, attachments, a multi-level approval
chain and a deterministic effect on attendance; and produce a monthly payroll run that reads those
attendance summaries, computes every allowance, deduction, bonus, commission, advance recovery and tax
as a typed component line with bcmath, gets **locked** so nothing can be edited afterwards, pays out per
employee, prints a salary slip, and is corrected only by issuing a new correction item that references
the original. Every employee sees their own attendance, leave and slips - and nobody else's.

### 1.2 Dependencies

| Phase | What this phase needs from it |
|---|---|
| 1 | `users` (+ `branch_id`, `status`), RBAC + `PermissionRegistry` + `Gate::before` module gating, `branches`, extended `activity_log` with old/new values, `App\Support\Money`, `Blameable`, `LogsActivityWithContext`, `x-ui.*`, `layouts/admin`, middleware aliases `active` / `module` / `panel` |
| 2 | `SettingsRegistry` + `SettingsService` (this phase adds the `hr` group), `DashboardRegistry`, `DateRange`, `Format` (`money()`, `app_date()`, `app_time()`), `modules.depends_on` |
| 5 | `App\Services\Finance\DocumentNumberService` - **Phase 5 ships it** (**D27**); Phase 7 reuses it unchanged and passes its own pad `'%05d'` explicitly (§5, [D-HR-14]) |
| 6 | **Nothing.** Under **D32** the assignment of work points at `users.id`, so Phase 6 needs no `employees` FK for a task assignee, a project manager or a timer - see [D-HR-1], [D-HR-2] and §13 |

Nothing in this phase depends on phases 8-25. It must migrate and pass its tests on a database that
contains only phases 1-7.

### 1.3 Phase ownership

| Owns | Must NOT create or touch |
|---|---|
| The 24 tables of §2, all enums of §3, the 11 new module slugs of §4.1, the `hr` settings group of §5, every service in §6, the routes of §7, the screens of §8, the jobs and commands of §10 | `users`, `branches`, `settings`, `modules` (Phase 1/2 own them - this phase only **adds rows/definitions**); `teachers` (Phase 16), `students` (Phase 15), `expenses` (Phase 13), `files` (Phase 22), any `collaborator_*` table (Phase 8-12) |

**[D-HR-1] Migration ordering.** Phases 4, 5 and 6 ship before Phase 7 in the tracker. Under **D32** the
assignment of work points at `users.id`, so none of them needs an `employees` FK for a task assignee, a
project manager or a timer; but an **organisational duty** or a provenance link that an earlier phase
records does point at `employees.id` (`team_members.employee_id` and `job_applications.employee_id`, both
owned by Phase 4). Two rules resolve this without re-numbering the plan:

1. Phase 7 owns the **definition** of `departments`, `designations` and `employees`. Whichever phase
   migrates first may ship that three-table core migration verbatim from §2.1-§2.4; it is never written
   twice, and the remaining 21 tables always belong to Phase 7.
2. Every foreign key **into** `employees` from another phase lives in its own
   `add_employee_fks_to_<table>` migration guarded by `Schema::hasTable('employees')`, exactly as
   [D-FS-1] does for the financial spine. So `migrate:fresh` works in any order.

This is raised as Q1 in §12.2; the default assumed is the two rules above.

**[D-HR-2] Staff identity - narrowed to duties by D32.** `employees.user_id` is nullable and UNIQUE (D2):
an employee record exists without a login (a labourer, an ex-employee) and a login maps to at most one
employee. A **duty** (department head, reporting line, leave approver, course coordinator) is held by a
post and therefore points at `employees.id`. **Who performed an act, who is assigned work, who holds a
timer points at `users.id`** (`created_by`, `approved_by`, `acted_by_user_id`, `tasks.assigned_to`,
`projects.project_manager_id`, a running timer's owner). The bridge between the two is
`employees.user_id`. This is **D32**, and it binds phases 6, 13 and 14-17 as much as this one.

### 1.4 Invariants - enforced by the DB, by model hooks, and by the tests forever

| # | Invariant | Enforced by |
|---|---|---|
| HR-1 | Exactly **one attendance row per employee per calendar date**, weekends and holidays included. | Generated guard column + `UNIQUE uq_att_day(day_guard)`; FT-HR-13 |
| HR-2 | An attendance row **snapshots its shift** (`expected_in_at`, `expected_out_at`, `expected_minutes`, both grace values). Editing or deleting a `work_shift` can never change a past day's late/early/hours figures. | Columns are written once at row creation; FT-HR-12 |
| HR-3 | `late_minutes`, `early_leave_minutes`, `worked_minutes`, `overtime_minutes` are **integers of minutes**, derived only by `AttendanceService::resolve()` from the snapshot. No float, no money. | §6.3; FT-HR-10 |
| HR-4 | For every row, `payable_factor` is in `[0, 1]`, and for a **working** day `lop_days = 1 - payable_factor`. This single identity is the only bridge between attendance and pay. | `CHECK chk_att_factor`; §6.4; FT-HR-21 |
| HR-5 | Payroll **never reads `attendances`**. It reads `attendance_monthly_summaries`, which is the only place attendance is aggregated. | `PayrollCalculator` signature takes a summary; FT-HR-37 |
| HR-6 | An attendance row is never silently edited. Every manual change - by the employee or by HR - inserts an `attendance_corrections` row carrying `old_values`, `new_values`, a mandatory reason and the actor, plus an `activity_log` entry. | `AttendanceCorrectionService` is the only writer of a manual change; FT-HR-14 |
| HR-7 | A leave balance is a **cache with no truth of its own**: every column equals the sum of its `leave_balance_transactions`, and `available_days = entitled + carried_forward + accrued + adjusted - consumed - pending - encashed`. | `LeaveBalanceService::assertConsistent()`, called by the test helper `assertLeaveBalanceMatchesLedger()` after every leave test; FT-HR-25 |
| HR-8 | At most **one counted leave day per employee per date**, so quota and attendance can never double-count. | `UNIQUE uq_lrd_day(day_guard)` on `leave_request_days`; FT-HR-26 |
| HR-9 | A leave balance can never go below zero unless `hr.leave_negative_balance_allowed` is on; approval is refused with the exact shortfall named. | `LeaveBalanceService::reserve()` under the balance row lock; FT-HR-27 |
| HR-10 | A salary structure row is **never UPDATEd**. A raise is a new version: the open version is closed at `effective_from - 1 day`, the successor carries `version + 1` and `supersedes_id`. At most one open version per employee. | `UNIQUE uq_ss_open(open_guard)` + model `updating` hook; FT-HR-41 |
| HR-11 | `employees.current_gross_salary` is a **cache** of the active structure's gross, written only by `SalaryStructureService`. No payroll figure is ever read from it. | FT-HR-41 |
| HR-12 | Money is `decimal(15,2)`, rates are `decimal(8,4)`, day counts are `decimal(8,4)`. Every arithmetic step goes through `App\Support\Money` (bcmath, intermediate scale 6, final half-up at 2). No PHP `+ - * /` touches a money value. | FT-HR-35 (static scan over `app/Services/Hr`), FT-HR-33 |
| HR-13 | A slip's totals are the **SUM of its stored component rows**, never a recomputation. `gross_earnings = SUM(components where side = earning)`, `total_deductions = SUM(components where side = deduction)`, `net_salary = gross_earnings - total_deductions`. The printed slip therefore always adds up. | `PayrollRunService::lock()` asserts it before locking; FT-HR-34 |
| HR-14 | `net_salary >= 0` on a **regular** run. When deductions would exceed earnings the advance-recovery line is reduced (the remainder stays owed), and if it still cannot balance the item is held, never negative. | `CHECK chk_pri_sign` + §6.6 step 9; FT-HR-38 |
| HR-15 | Once a run is `locked`, every money column, every component row and every snapshot on its items is **immutable**; once an item is `paid`, its payment columns freeze too. Only `status`, `hold_reason`, `paid_at`, `payment_*` (before paid), `notes`, `updated_*` may ever change. | Model `updating` hook throwing `ImmutablePayrollAttributeException`; FT-HR-45 |
| HR-16 | **No payroll row is ever deleted** after lock - not even soft-deleted. The append-only tables of §2.1 carry no `deleted_at`; a draft run's items may be hard-deleted only while the run is `draft` or `generated`, which is what "regenerate" means. | Model `deleting` hook + `BEFORE DELETE` trigger on `payroll_run_items` / `payroll_run_item_components` checking the parent status; FT-HR-45, FT-HR-60 |
| HR-17 | A wrong slip is corrected by a **new item on a `correction` run** that references the original (`corrects_item_id`). The original is never touched, and a negative amount is legal only on a correction run. | `CHECK chk_pri_sign` / `chk_pric_sign` using the denormalised `run_type`; FT-HR-47 |
| HR-18 | Locking a payroll run **locks the period's attendance**: every `attendances` row and every `attendance_monthly_summaries` row in the period gets `locked_at` + `locked_by_payroll_run_id`, and a later edit or rebuild is refused. | `PayrollRunService::lock()` in one transaction; FT-HR-22, FT-HR-45 |
| HR-19 | An advance can never be recovered for more than it was worth: `recovered_amount + waived_amount <= amount`, asserted by the DB, and every recovery is an append-only row referencing the payroll item that took it. | `CHECK chk_adv_ceiling`; FT-HR-39 |
| HR-20 | Every discretionary act - status change, manual attendance correction, leave approval/rejection, balance adjustment, structure version, advance approval/waiver, run lock, item hold, correction issue - writes an `activity_log` row with old/new values, actor, IP and a **mandatory reason** where the act is discretionary. | `LogsActivityWithContext::withReason()`; FT-HR-62 |
| HR-21 | An employee sees their own attendance, leave, documents and slips and nothing else, because self-service controllers query **only** through `auth()->user()->employee` relations and route-model binding returns **404** (not 403) for another employee's row. | §9; FT-HR-52, FT-HR-53 |
| HR-22 | Every money write and every status transition runs in one `DB::transaction()` with the lock order **employee -> period/run -> balance/advance -> child rows by ascending id**, and events fire only through `DB::afterCommit()`. | §6.2; FT-HR-49 |

---

## 2. Schema

All tables InnoDB, utf8mb4, `timestamps`. Blameable (`created_by`, `updated_by`, nullable FK `users.id`,
`nullOnDelete`) on every table in this phase. Soft deletes per the table list below.

### 2.1 The 24 tables

| # | Table | Soft deletes | Why |
|---|---|---|---|
| 1 | `departments` | yes | §25 dynamic departments |
| 2 | `designations` | yes | §24 designation, made dynamic |
| 3 | `employees` | yes | §24 |
| 4 | `employee_skills` | yes | §24 skills, filterable |
| 5 | `employee_documents` | yes | §24 documents + expiry tracking |
| 6 | `work_shifts` | yes | the definition late / early leave / working hours are measured against (§26) |
| 7 | `holidays` | yes | public holidays (§26 "absent" must not punish a holiday) |
| 8 | `attendances` | yes | §26, one row per employee per day |
| 9 | `attendance_corrections` | **no** | the audit trail of a manual correction - deleting it destroys the evidence |
| 10 | `attendance_monthly_summaries` | **no** | a derived cache, rebuildable; never soft-deleted |
| 11 | `leave_types` | yes | §27 + annual quotas |
| 12 | `leave_balances` | **no** | a cache over table 13 (HR-7) |
| 13 | `leave_balance_transactions` | **no** | append-only day ledger |
| 14 | `leave_requests` | yes | §27 |
| 15 | `leave_request_days` | **no** | the day-by-day expansion that attendance and quota both read |
| 16 | `leave_approvals` | **no** | the approval chain, one row per level |
| 17 | `salary_components` | yes | §28 typed allowances and deductions |
| 18 | `salary_structures` | **no** | immutable effective-dated versions (HR-10) |
| 19 | `salary_structure_components` | **no** | the version's lines, written with it |
| 20 | `employee_advances` | **no** | money advanced (§28) |
| 21 | `employee_advance_repayments` | **no** | append-only recovery history |
| 22 | `payroll_runs` | **no** | §28 monthly run, generated then locked |
| 23 | `payroll_run_items` | **no** | the salary slip itself |
| 24 | `payroll_run_item_components` | **no** | typed lines of the slip |

**[D-HR-3] The eleven tables marked "no" in bold omit `deleted_at`**, deviating from `CLAUDE.md` §3 for
the same reason [D-FS-3] does in the financial spine: a nullable `deleted_at` on an append-only payroll,
ledger or audit table is an invitation - one `->delete()` from a future controller and a slip vanishes
from every total while the money stays paid. Cancellation, holding, voiding and correction are
**statuses and rows**, never deletes. This is not a local exception: it is the category rule **D19**
(append-only money, audit, log, snapshot, revision, counter and history-pivot tables carry no
`deleted_at`), stated in full in `CLAUDE.md` §3, and the payroll / advance tables additionally cite
**D16**. [D-HR-3] therefore **cites D19**; it claims no decision number of its own (§12.2 Q2).

**[D-HR-4] Signed day and money magnitudes.** `leave_balance_transactions.days`,
`employee_advance_repayments.amount` and `payroll_run_item_components.amount` are **positive
magnitudes**; direction is carried by `entry_type` / the component's side, and the only column anything
sums is the STORED generated column `signed_days` / `signed_amount`. Written as raw SQL in the migration
(`... AS (CASE WHEN entry_type = 'credit' THEN days ELSE -days END) STORED`); if the server rejects it
the migration must **fail loudly**, never silently fall back. The one exception is a **correction** run,
where `payroll_run_item_components.amount` itself may be negative (HR-17) - the component's side then
tells you which total it reduces.

**[D-HR-5] NULL-tolerant unique guards.** MariaDB treats NULL as distinct in a unique index, so every
"only one of these at a time" rule is a STORED generated column that is NULL when the rule does not
apply, plus a UNIQUE index on it. The guards in this phase are `designations.department_guard`,
`work_shifts.default_guard`, `holidays.holiday_guard`, `attendances.day_guard`,
`leave_request_days.day_guard`, `salary_structures.open_guard`, `payroll_runs.regular_guard`. Each has
its own test asserting the **second** INSERT throws (FT-HR-59), and `hr:verify-constraints` re-asserts
all of them daily.

**[D-HR-6] Rejected, with the reason.**

| Rejected | Why |
|---|---|
| A global `skills` dictionary + `employee_skill` pivot | §24 asks for "skills" on an employee, not a taxonomy to maintain. `employee_skills` rows with `UNIQUE(employee_id, name)` are filterable today; a dictionary can be introduced later by adding a nullable `skill_id` without moving data. |
| `holidays.end_date` (a multi-day holiday as one row) | The attendance engine asks "is this date a holiday?" millions of times; a range makes that a scan and makes `UNIQUE(branch, date)` impossible. `HolidayService::createRange()` writes one row per date, which is also what the calendar screen wants. |
| A shift **rotation** table (`employee_shift_assignments`) | §26 never asks for rotating shifts. `employees.work_shift_id` plus the per-row shift snapshot (HR-2) already keeps history correct when an employee moves shift. Adding rotation later is additive: a new table plus one resolver change in `WorkCalendarService`. |
| A separate `leave_approval_flows` definition table | The chain is fully determined by `leave_types.approval_levels` + `employees.reports_to_id` + holders of `leaves.approve` (§6.5.3). A configurable flow builder is scope §27 does not ask for. |
| Multiple leave attachments in their own table | §27 says "attachment" (singular). One `attachment_path` + `attachment_name` on the request. When Phase 22 ships the polymorphic `files` table (§96) the column migrates to it; until then a table would be dead weight. |
| `payroll_run_items.is_editable` / an "unlock" action | An unlock is an edit of paid money with extra steps. A locked run is corrected by a correction item (HR-17) - there is no door back. |
| An income-tax **slab engine** | §28 says "tax", not "FBR slabs". Tax is a typed deduction component with three modes (§5, `hr.tax_mode`), default `manual`. A slab table is offered as Q5 in §12.2 rather than invented here. |
| Auto-paid overtime | §28's earning list is basic, allowances, bonus, commission. `overtime_minutes` is measured because it is free (worked minus expected) and reported, but it is **never paid automatically**; `hr.overtime_pay_enabled` defaults to false and overtime, when paid, is an ordinary `overtime` component. |
| Employee bank account columns for salary disbursement | Not in §24 or §28, and unprotected bank data is a liability (§111). `payroll_run_items.payment_method` + `payment_reference` record how a salary was paid; stored destinations are Q6 in §12.2. |

### 2.2 `departments` (§25)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `code` | string(32) | not null | `UNIQUE`; uppercase, stable (`DEV`, `DSGN`, `MKT`) |
| `name` | string(150) | not null | §25's list (Development, Design, Marketing, SEO, Sales, HR, Accounts, Support, Institute, Management) is seeded, not hardcoded |
| `description` | string(255) | nullable | |
| `head_employee_id` | FK `employees.id` | nullable, null | `nullOnDelete`; added by a separate guarded migration (circular FK with `employees.department_id`) |
| `branch_id` | FK `branches.id` | nullable, null | `nullOnDelete`, D11 |
| `employee_count` | unsignedInteger | 0 | CACHE of non-exited employees, written only by `DepartmentService` |
| `is_active` | boolean | true | index |
| `sort_order` | integer | 0 | |
| `created_at` / `updated_at` | timestamps | nullable | |
| `deleted_at` | timestamp | nullable | softDeletes |
| `created_by` / `updated_by` | FK `users.id` | nullable | `nullOnDelete`, Blameable |

**Keys.** `UNIQUE uq_dept_code(code)`; `UNIQUE uq_dept_name(name, deleted_at)`; `INDEX (is_active, sort_order)`; `INDEX (branch_id)`; `INDEX (head_employee_id)`.
**Relationships.** hasMany `Employee`, `Designation`; belongsTo `Employee` as `head`, `Branch`, `User` (`creator`, `editor`).
**Policy.** `delete` is refused while any non-trashed employee references it; the message names the count and offers "deactivate instead".

### 2.3 `designations` (§24)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `department_id` | FK `departments.id` | nullable, null | `nullOnDelete`; null = a title usable in any department |
| `title` | string(150) | not null | "Senior Laravel Developer" |
| `code` | string(32) | nullable | `UNIQUE` when present |
| `level` | unsignedSmallInteger | 50 | seniority for sorting an org list (lower = more senior), mirrors `roles.level` |
| `description` | string(255) | nullable | |
| `is_active` | boolean | true | index |
| `sort_order` | integer | 0 | |
| `department_guard` | string(200) STORED generated | - | `CONCAT(COALESCE(department_id,0),':',title)` when `deleted_at IS NULL`, else NULL |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_desig_guard(department_guard)` - one title per department, reusable after a soft delete; `UNIQUE uq_desig_code(code)`; `INDEX (department_id, is_active)`.
**Relationships.** belongsTo `Department`; hasMany `Employee`.

### 2.4 `employees` (§24)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `employee_code` | string(32) | not null | §24 Employee ID. `UNIQUE`. `hr.employee_code_prefix` + counter via `DocumentNumberService`, assigned in-transaction |
| `user_id` | FK `users.id` | nullable, null | `nullOnDelete`, **UNIQUE** (D2, [D-HR-2]) |
| `branch_id` | FK `branches.id` | nullable, null | `nullOnDelete`, D11 |
| `department_id` | FK `departments.id` | not null | `restrictOnDelete` |
| `designation_id` | FK `designations.id` | nullable | `nullOnDelete` |
| `reports_to_id` | FK `employees.id` | nullable, null | `nullOnDelete`; the reporting line the leave chain and the team scope use |
| `work_shift_id` | FK `work_shifts.id` | nullable, null | `nullOnDelete`; null = the default shift of the employee's branch |
| `name` | string(150) | not null | |
| `photo_path` | string(255) | nullable | `public` disk under `employees/photos/` |
| `phone` | string(32) | nullable | |
| `whatsapp` | string(32) | nullable | |
| `email` | string(150) | nullable | `UNIQUE` when present (NULLs are distinct) |
| `address` | string(255) | nullable | |
| `city` | string(100) | nullable | |
| `joining_date` | date | not null | index |
| `employment_type` | string(32) | `full_time` | cast `EmploymentType`, index |
| `status` | string(32) | `active` | cast `EmployeeStatus`, index |
| `status_reason` | string(255) | nullable | mandatory when moving to `suspended` / `inactive` |
| `status_changed_at` | timestamp | nullable | |
| `exit_date` | date | nullable | set with `resigned` / `terminated`; blocks inclusion in later payroll runs |
| `exit_reason` | string(255) | nullable | |
| `weekly_off_days` | json | nullable | per-employee override of the shift / `hr.weekend_days`; array of `monday`..`sunday` |
| `is_attendance_exempt` | boolean | false | management / field staff: no absent marking, no late marks, `payable_factor` always 1.0000. Documented on the employee screen so it can never be a silent pay decision |
| `current_gross_salary` | decimal(15,2) | 0.00 | **CACHE** of the active salary structure's gross (HR-11); shown only with `employees.view_financial` |
| `emergency_contact_name` | string(150) | nullable | §24 emergency contact |
| `emergency_contact_relation` | string(64) | nullable | |
| `emergency_contact_phone` | string(32) | nullable | |
| `emergency_contact_alt_phone` | string(32) | nullable | |
| `emergency_contact_address` | string(255) | nullable | |
| `bio` | text | nullable | reused by the public Team section (§13 request) |
| `notes` | text | nullable | HR-only |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_emp_code(employee_code)`; `UNIQUE uq_emp_user(user_id)`; `UNIQUE uq_emp_email(email)`; `INDEX (department_id, status)`; `INDEX (designation_id)`; `INDEX (status, employment_type)`; `INDEX (reports_to_id)`; `INDEX (branch_id, status)`; `INDEX (joining_date)`; `INDEX (work_shift_id)`; full-text-free search uses `INDEX (name)` plus `employee_code` / `phone` / `email` equality.
**CHECK** `chk_emp_salary_nonneg`: `current_gross_salary >= 0`.
**CHECK** `chk_emp_exit`: `exit_date IS NULL OR exit_date >= joining_date`.
**Relationships.** belongsTo `User` (login), `Department`, `Designation`, `Branch`, `WorkShift`, `Employee` as `manager` (`reports_to_id`), `User` (`creator`, `editor`); hasMany `Employee` as `directReports`, `EmployeeSkill`, `EmployeeDocument`, `Attendance`, `AttendanceCorrection`, `AttendanceMonthlySummary`, `LeaveRequest`, `LeaveBalance`, `LeaveBalanceTransaction`, `SalaryStructure`, `EmployeeAdvance`, `PayrollRunItem`; hasOne `SalaryStructure` as `activeSalaryStructure` (status `active`); belongsToMany `LeaveType` through pivot **`leave_balances`**; belongsToMany `PayrollRun` through pivot **`payroll_run_items`**; hasOne `Department` as `headedDepartment` (inverse of `departments.head_employee_id`).
**Policy.** `delete` (soft) is allowed only when no attendance, leave, payroll item or advance row exists; otherwise the UI offers `exit()` instead and says why. `forceDelete` is always refused.

### 2.5 `employee_skills` (§24)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `employee_id` | FK `employees.id` | not null | `cascadeOnDelete` (only reachable on a force delete, which the policy refuses) |
| `name` | string(64) | not null | free text, trimmed, title-cased on save |
| `level` | string(16) | nullable | cast `SkillLevel` |
| `years_experience` | decimal(4,1) | nullable | |
| `sort_order` | integer | 0 | |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_eskill(employee_id, name, deleted_at)`; `INDEX (name)` for "who knows Laravel".
**Relationships.** belongsTo `Employee`.

### 2.6 `employee_documents` (§24, with expiry tracking)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `employee_id` | FK `employees.id` | not null | `cascadeOnDelete` |
| `document_type` | string(32) | not null | cast `EmployeeDocumentType`, index |
| `title` | string(150) | not null | |
| `document_number` | string(64) | nullable | CNIC / passport / licence number |
| `file_path` | string(255) | not null | **private** disk `local` under `employees/{id}/documents/`, hashed name; never `public` |
| `file_name` | string(255) | not null | the original name, shown to the user |
| `mime_type` | string(128) | not null | validated server-side against `security.allowed_file_types` |
| `size_kb` | unsignedInteger | 0 | validated against `security.max_upload_mb` |
| `issued_on` | date | nullable | |
| `expires_on` | date | nullable | index - the expiry sweeper reads it |
| `reminder_days` | unsignedSmallInteger | nullable | null = `hr.document_expiry_reminder_days` |
| `is_confidential` | boolean | true | a confidential document needs `employee_documents.download`; every download writes an activity row |
| `verification_status` | string(16) | `pending` | cast `DocumentVerificationStatus` |
| `verified_by` | FK `users.id` | nullable | `nullOnDelete` |
| `verified_at` | timestamp | nullable | |
| `expiry_notified_at` | timestamp | nullable | stops the reminder repeating daily |
| `notes` | string(255) | nullable | |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `INDEX (employee_id, document_type)`; `INDEX (expires_on, expiry_notified_at)`; `INDEX (verification_status)`.
**CHECK** `chk_empdoc_dates`: `expires_on IS NULL OR issued_on IS NULL OR expires_on >= issued_on`.
**Relationships.** belongsTo `Employee`, `User` as `verifier`.
**File lifecycle.** An `EmployeeDocumentObserver` deletes the stored file on `forceDeleted` only; a soft delete keeps the file so a restore is lossless.

### 2.7 `work_shifts` (the definition late and early leave are measured from)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `code` | string(32) | not null | `UNIQUE` |
| `name` | string(100) | not null | "General 9-6", "Night 22-06" |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete`, D11 |
| `start_time` | time | not null | |
| `end_time` | time | not null | |
| `crosses_midnight` | boolean | false | true when `end_time <= start_time`; written by the service, never by hand |
| `break_minutes` | unsignedSmallInteger | 0 | subtracted from worked minutes |
| `expected_minutes` | unsignedSmallInteger | not null | net paid minutes of the shift, computed once by the service |
| `grace_in_minutes` | unsignedSmallInteger | 15 | default from `hr.late_grace_minutes` |
| `grace_out_minutes` | unsignedSmallInteger | 10 | default from `hr.early_leave_grace_minutes` |
| `min_full_day_minutes` | unsignedSmallInteger | 480 | default from `hr.full_day_min_minutes` |
| `min_half_day_minutes` | unsignedSmallInteger | 240 | default from `hr.half_day_min_minutes` |
| `weekly_off_days` | json | nullable | null = `hr.weekend_days` |
| `is_default` | boolean | false | one default per branch |
| `is_active` | boolean | true | index |
| `sort_order` | integer | 0 | |
| `default_guard` | string(16) STORED generated | - | `COALESCE(branch_id,0)` when `is_default = 1 AND deleted_at IS NULL`, else NULL |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_ws_code(code)`; `UNIQUE uq_ws_default(default_guard)`; `INDEX (branch_id, is_active)`.
**CHECK** `chk_ws_minutes`: `break_minutes >= 0 AND grace_in_minutes >= 0 AND grace_out_minutes >= 0 AND expected_minutes > 0`.
**CHECK** `chk_ws_day_thresholds`: `min_half_day_minutes <= min_full_day_minutes`.
**Relationships.** hasMany `Employee`, `Attendance`; belongsTo `Branch`.
**Immutability by snapshot.** Editing a shift is allowed (HR may fix a typo or move the start time), and it changes **nothing** about past attendance because every row snapshotted its window (HR-2). The edit screen states that in one sentence.

### 2.8 `holidays`

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete`; null = all branches (D11) |
| `holiday_date` | date | not null | exactly one day per row ([D-HR-6]) |
| `title` | string(150) | not null | "Independence Day" |
| `holiday_type` | string(16) | `public` | cast `HolidayType` |
| `is_paid` | boolean | true | false produces `payable_factor = 0` for that date |
| `is_recurring_yearly` | boolean | false | the yearly generator copies it to the next year with a new date |
| `description` | string(255) | nullable | |
| `is_active` | boolean | true | index |
| `holiday_guard` | string(40) STORED generated | - | `CONCAT(COALESCE(branch_id,0),':',DATE_FORMAT(holiday_date,'%Y-%m-%d'))` when `is_active = 1 AND deleted_at IS NULL`, else NULL |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_hol_guard(holiday_guard)`; `INDEX (holiday_date, is_active)`; `INDEX (branch_id, holiday_date)`.
**Relationships.** belongsTo `Branch`; hasMany `Attendance`.
**Rule.** A holiday added or removed **after** a period is locked changes nothing - locked attendance is refused (HR-18). Before lock, `AttendanceService::recomputeDate()` re-resolves the affected rows and every change writes a correction row with the reason "holiday calendar changed".

### 2.9 `attendances` (§26)

One row per employee per calendar date, including weekends and holidays, created either by the employee
checking in or by the nightly `hr:close-attendance-day` command.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `employee_id` | FK `employees.id` | not null | `restrictOnDelete` - attendance is payroll evidence |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete`, snapshot of the employee's branch (D11) |
| `attendance_date` | date | not null | for a night shift this is the **shift start** date |
| `work_shift_id` | FK `work_shifts.id` | nullable | `nullOnDelete` - the row keeps its own snapshot |
| `day_type` | string(16) | `working` | cast `DayType`: `working` / `weekly_off` / `public_holiday` |
| `holiday_id` | FK `holidays.id` | nullable | `nullOnDelete`; set whenever a holiday row matched, even if `day_type` resolved to `weekly_off` |
| `status` | string(32) | `absent` | cast `AttendanceStatus` - the seven states of §3 |
| `expected_in_at` | datetime | nullable | SNAPSHOT, null on a non-working day |
| `expected_out_at` | datetime | nullable | SNAPSHOT; `attendance_date + 1` when the shift crosses midnight |
| `expected_minutes` | unsignedSmallInteger | 0 | SNAPSHOT |
| `grace_in_minutes` | unsignedSmallInteger | 0 | SNAPSHOT |
| `grace_out_minutes` | unsignedSmallInteger | 0 | SNAPSHOT |
| `break_minutes` | unsignedSmallInteger | 0 | SNAPSHOT |
| `check_in_at` | datetime | nullable | the **first** punch of the day; never overwritten |
| `check_out_at` | datetime | nullable | the **last** punch of the day; moved forward only |
| `check_in_source` | string(16) | nullable | cast `AttendanceSource` |
| `check_out_source` | string(16) | nullable | cast `AttendanceSource` |
| `check_in_ip` | string(45) | nullable | §111 |
| `check_out_ip` | string(45) | nullable | |
| `worked_minutes` | unsignedInteger | 0 | `check_out - check_in - break`, 0 while `check_out_at` is null |
| `late_minutes` | unsignedInteger | 0 | `max(0, check_in - (expected_in + grace_in))` |
| `early_leave_minutes` | unsignedInteger | 0 | `max(0, (expected_out - grace_out) - check_out)` |
| `overtime_minutes` | unsignedInteger | 0 | `max(0, worked - expected)`; measured, never auto-paid ([D-HR-6]) |
| `payable_factor` | decimal(8,4) | 1.0000 | the only number payroll cares about (HR-4) |
| `leave_request_id` | FK `leave_requests.id` | nullable | `nullOnDelete` |
| `leave_type_id` | FK `leave_types.id` | nullable | `nullOnDelete`, snapshot for reporting |
| `is_manual` | boolean | false | true once a correction set the row; `resolve()` then never overwrites it |
| `requires_correction` | boolean | false | a missing check-out after the shift ended, or a punch on a locked date |
| `remarks` | string(255) | nullable | |
| `locked_at` | timestamp | nullable | set by `PayrollRunService::lock()` |
| `locked_by_payroll_run_id` | FK `payroll_runs.id` | nullable | `nullOnDelete` |
| `day_guard` | string(40) STORED generated | - | `CONCAT(employee_id,':',DATE_FORMAT(attendance_date,'%Y-%m-%d'))` when `deleted_at IS NULL`, else NULL |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_att_day(day_guard)` (HR-1); `INDEX (employee_id, attendance_date)`; `INDEX (attendance_date, status)` the daily register; `INDEX (branch_id, attendance_date)`; `INDEX (status, attendance_date)`; `INDEX (leave_request_id)`; `INDEX (locked_by_payroll_run_id)`; `INDEX (requires_correction, attendance_date)`.
**CHECK** `chk_att_times`: `check_out_at IS NULL OR check_in_at IS NULL OR check_out_at >= check_in_at`.
**CHECK** `chk_att_factor`: `payable_factor >= 0 AND payable_factor <= 1`.
**CHECK** `chk_att_minutes`: `worked_minutes >= 0 AND late_minutes >= 0 AND early_leave_minutes >= 0 AND overtime_minutes >= 0`.
**Relationships.** belongsTo `Employee`, `WorkShift`, `Holiday`, `LeaveRequest`, `LeaveType`, `Branch`, `PayrollRun` as `lockedByRun`; hasMany `AttendanceCorrection`.
**Policy.** `update` and `delete` are refused while `locked_at` is set (`LockedAttendanceException`); an unlocked manual change must go through `AttendanceCorrectionService` (HR-6).

### 2.10 `attendance_corrections` (the audit trail of every manual change)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `attendance_id` | FK `attendances.id` | nullable | `cascadeOnDelete`; null when the correction asks for a day that has no row yet |
| `employee_id` | FK `employees.id` | not null | `restrictOnDelete` |
| `attendance_date` | date | not null | carried so a correction can precede its row |
| `correction_type` | string(32) | not null | cast `AttendanceCorrectionType` |
| `source` | string(16) | not null | cast `CorrectionSource`: `self_request` / `hr_direct` |
| `old_values` | json | nullable | the row as it was (status, punches, factor, remarks) - null when no row existed |
| `new_values` | json | not null | only the keys being changed; validated against a whitelist |
| `reason` | text | not null | **mandatory**, min 10 characters |
| `status` | string(16) | `pending` | cast `AttendanceCorrectionStatus` |
| `requested_by` | FK `users.id` | nullable | `nullOnDelete` |
| `requested_at` | timestamp | not null | |
| `reviewed_by` | FK `users.id` | nullable | `nullOnDelete` |
| `reviewed_at` | timestamp | nullable | |
| `review_comment` | string(255) | nullable | mandatory on reject |
| `applied_at` | timestamp | nullable | set when the values are written onto the attendance row |
| `created_at` / `updated_at` | timestamps | nullable | **no `deleted_at`** |
| `created_by` / `updated_by` | FK `users.id` | nullable | Blameable |

**Keys.** `INDEX (employee_id, attendance_date)`; `INDEX (status, requested_at)` the approval queue; `INDEX (attendance_id)`.
**Relationships.** belongsTo `Attendance`, `Employee`, `User` as `requester` / `reviewer`.
**Rules.** Append-only: `status`, `reviewed_*`, `review_comment`, `applied_at` may change, nothing else; a `deleting` hook throws `AppendOnlyRowException` and a `BEFORE DELETE` trigger raises `SIGNAL SQLSTATE '45000'`. An `hr_direct` correction by a holder of `attendance.edit` is created `approved` and applied in the same transaction - the row still exists, so the audit trail is identical for both paths.

### 2.11 `attendance_monthly_summaries` (§26 monthly summary, the only input payroll reads)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `employee_id` | FK `employees.id` | not null | `restrictOnDelete` |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete` |
| `period_year` | smallInteger unsigned | not null | |
| `period_month` | tinyInteger unsigned | not null | 1-12 |
| `period_start` / `period_end` | date | not null | stored so a non-calendar period is possible later without a migration |
| `calendar_days` | tinyInteger unsigned | 0 | days in the period |
| `working_days` | decimal(8,4) | 0.0000 | days whose `day_type = working` |
| `weekly_off_days` | tinyInteger unsigned | 0 | |
| `holiday_days` | tinyInteger unsigned | 0 | `day_type = public_holiday` |
| `present_days` | decimal(8,4) | 0.0000 | status `present` + `late` + `early_leave` |
| `late_count` | smallInteger unsigned | 0 | rows with `late_minutes > 0` |
| `early_leave_count` | smallInteger unsigned | 0 | |
| `half_day_count` | smallInteger unsigned | 0 | |
| `absent_days` | decimal(8,4) | 0.0000 | |
| `paid_leave_days` | decimal(8,4) | 0.0000 | |
| `unpaid_leave_days` | decimal(8,4) | 0.0000 | |
| `payable_days` | decimal(8,4) | 0.0000 | `SUM(payable_factor)` over every row in the period |
| `lop_days` | decimal(8,4) | 0.0000 | `SUM(1 - payable_factor)` over **working** rows only (HR-4) |
| `worked_minutes` | unsignedInteger | 0 | |
| `expected_minutes` | unsignedInteger | 0 | |
| `late_minutes` | unsignedInteger | 0 | |
| `early_leave_minutes` | unsignedInteger | 0 | |
| `overtime_minutes` | unsignedInteger | 0 | |
| `attendance_percentage` | decimal(8,4) | 0.0000 | `present_days / working_days * 100`, via `Money` |
| `generated_at` | timestamp | not null | |
| `is_final` | boolean | false | true once the month has closed and every row is resolved |
| `locked_at` | timestamp | nullable | set by `PayrollRunService::lock()` |
| `locked_by_payroll_run_id` | FK `payroll_runs.id` | nullable | `nullOnDelete` |
| timestamps / blameable | | | **no `deleted_at`** (derived cache) |

**Keys.** `UNIQUE uq_ams_period(employee_id, period_year, period_month)`; `INDEX (period_year, period_month)`; `INDEX (branch_id, period_year, period_month)`; `INDEX (locked_by_payroll_run_id)`.
**CHECK** `chk_ams_nonneg`: every day and minute column `>= 0`.
**CHECK** `chk_ams_month`: `period_month BETWEEN 1 AND 12`.
**Relationships.** belongsTo `Employee`, `Branch`, `PayrollRun` as `lockedByRun`.
**Rebuild rule.** `AttendanceSummaryService::rebuild()` is idempotent and refuses a period with `locked_at` set, naming the run that locked it.

### 2.12 `leave_types` (§27 + annual quotas)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `code` | string(32) | not null | `UNIQUE` (`AL`, `SL`, `CL`, `UL`, `ML`) |
| `name` | string(100) | not null | Annual, Sick, Casual, Unpaid, Maternity - seeded, fully dynamic |
| `description` | string(255) | nullable | |
| `annual_quota_days` | decimal(6,2) | 0.00 | 0 = no quota (e.g. Unpaid) |
| `is_paid` | boolean | true | drives `payable_factor` of the leave day |
| `accrual_method` | string(16) | `annual_grant` | cast `LeaveAccrualMethod` |
| `accrual_days_per_month` | decimal(6,2) | 0.00 | used when `accrual_method = monthly_accrual` |
| `accrue_from_joining` | boolean | true | pro-rates the first year's grant from `joining_date` |
| `carry_forward_enabled` | boolean | false | |
| `max_carry_forward_days` | decimal(6,2) | 0.00 | |
| `carry_forward_expiry_months` | tinyInteger unsigned | 0 | 0 = never expires |
| `max_consecutive_days` | smallInteger unsigned | 0 | 0 = unlimited |
| `min_notice_days` | smallInteger unsigned | 0 | filing inside the notice window needs `leaves.approve` |
| `allow_half_day` | boolean | true | |
| `allow_negative_balance` | boolean | false | per-type override of `hr.leave_negative_balance_allowed` |
| `requires_attachment` | boolean | false | |
| `attachment_required_after_days` | smallInteger unsigned | 0 | a sick note only after N days |
| `excludes_weekends` | boolean | true | intervening weekly-off days are not counted against the quota |
| `excludes_holidays` | boolean | true | intervening public holidays are not counted |
| `applies_to_employment_types` | json | nullable | null = all; values validated against `EmploymentType` |
| `allowed_on_probation` | boolean | false | |
| `approval_levels` | tinyInteger unsigned | 1 | 1 or 2 - the chain depth (§6.5.3) |
| `is_encashable` | boolean | false | |
| `color` | string(16) | `slate` | a Tailwind colour token for the calendar; the enum `color()` pattern cannot apply because types are data |
| `is_active` | boolean | true | index |
| `sort_order` | integer | 0 | |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_lt_code(code)`; `UNIQUE uq_lt_name(name, deleted_at)`; `INDEX (is_active, sort_order)`.
**CHECK** `chk_lt_nonneg`: `annual_quota_days >= 0 AND accrual_days_per_month >= 0 AND max_carry_forward_days >= 0`.
**CHECK** `chk_lt_levels`: `approval_levels BETWEEN 1 AND 2`.
**Relationships.** hasMany `LeaveRequest`, `LeaveBalance`, `LeaveBalanceTransaction`; belongsToMany `Employee` through pivot **`leave_balances`**.
**Policy.** `delete` is refused once any request or balance row references the type; `is_active = false` is the retirement path.

### 2.13 `leave_balances` (a cache over §2.14 - HR-7)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `employee_id` | FK `employees.id` | not null | `restrictOnDelete` |
| `leave_type_id` | FK `leave_types.id` | not null | `restrictOnDelete` |
| `leave_year` | smallInteger unsigned | not null | the year the leave year **starts** in (`hr.leave_year_start_month`) |
| `period_start` / `period_end` | date | not null | the concrete leave-year window |
| `entitled_days` | decimal(8,4) | 0.0000 | granted quota (pro-rated for a joiner) |
| `carried_forward_days` | decimal(8,4) | 0.0000 | |
| `accrued_days` | decimal(8,4) | 0.0000 | monthly accrual to date |
| `adjusted_days` | decimal(8,4) | 0.0000 | **may be negative** (manual correction) |
| `consumed_days` | decimal(8,4) | 0.0000 | approved leave |
| `pending_days` | decimal(8,4) | 0.0000 | reserved by requests awaiting approval |
| `encashed_days` | decimal(8,4) | 0.0000 | |
| `expired_days` | decimal(8,4) | 0.0000 | carry-forward that lapsed |
| `available_days` | decimal(8,4) | 0.0000 | CACHE: `entitled + carried_forward + accrued + adjusted - consumed - pending - encashed - expired` |
| `last_recalculated_at` | timestamp | nullable | |
| timestamps / blameable | | | **no `deleted_at`** |

**Keys.** `UNIQUE uq_lb(employee_id, leave_type_id, leave_year)`; `INDEX (leave_type_id, leave_year)`.
**CHECK** `chk_lb_nonneg`: every column except `adjusted_days` and `available_days` is `>= 0`.
**Relationships.** belongsTo `Employee`, `LeaveType`; hasMany `LeaveBalanceTransaction` (by employee + type + year).
**Rule.** Only `LeaveBalanceService` writes this table, always under `lockForUpdate()`, always in the same transaction as the ledger row that justifies the change (HR-7).

### 2.14 `leave_balance_transactions` (append-only day ledger)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `employee_id` | FK `employees.id` | not null | `restrictOnDelete` |
| `leave_type_id` | FK `leave_types.id` | not null | `restrictOnDelete` |
| `leave_year` | smallInteger unsigned | not null | |
| `entry_type` | string(8) | not null | cast `LedgerEntryType` - `credit` adds days, `debit` removes them |
| `reason` | string(32) | not null | cast `LeaveLedgerReason` |
| `days` | decimal(8,4) | not null | positive magnitude ([D-HR-4]) |
| `signed_days` | decimal(8,4) STORED generated | - | `CASE WHEN entry_type='credit' THEN days ELSE -days END` - the only column anything sums |
| `leave_request_id` | FK `leave_requests.id` | nullable | `nullOnDelete` |
| `balance_after_days` | decimal(8,4) | nullable | snapshot of `available_days` after the write, so the statement reads like a bank statement |
| `notes` | string(255) | nullable | mandatory for `manual_adjustment`, `encashment`, `exit_settlement` |
| `performed_by` | FK `users.id` | nullable | `nullOnDelete` |
| `occurred_on` | date | not null | the **value date** - never `now()` for a back-dated grant |
| `created_at` / `updated_at` | timestamps | nullable | **no `deleted_at`** |
| `created_by` / `updated_by` | FK `users.id` | nullable | Blameable |

**Keys.** `INDEX (employee_id, leave_type_id, leave_year)`; `INDEX (leave_request_id)`; `INDEX (reason, occurred_on)`.
**CHECK** `chk_lbt_days`: `days > 0`.
**Relationships.** belongsTo `Employee`, `LeaveType`, `LeaveRequest`, `User` as `performer`.
**Rules.** Nothing may UPDATE `entry_type`, `days`, `leave_type_id`, `employee_id`, `leave_year`, `leave_request_id` or `occurred_on`; a `deleting` hook throws `AppendOnlyRowException` and a `BEFORE DELETE` trigger raises `SIGNAL SQLSTATE '45000'`. A wrong entry is corrected by an opposite entry with `reason = manual_adjustment` and a mandatory note.

### 2.15 `leave_requests` (§27)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `request_number` | string(32) | not null | `UNIQUE`, `hr.leave_request_prefix` + counter |
| `employee_id` | FK `employees.id` | not null | `restrictOnDelete` |
| `leave_type_id` | FK `leave_types.id` | not null | `restrictOnDelete` |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete` snapshot |
| `from_date` | date | not null | index |
| `to_date` | date | not null | |
| `day_portion` | string(16) | `full_day` | cast `LeaveDayPortion`; `first_half` / `second_half` only when `from_date = to_date` |
| `total_days` | decimal(8,4) | not null | counted days after excluding weekends/holidays per the type |
| `paid_days` | decimal(8,4) | 0.0000 | |
| `unpaid_days` | decimal(8,4) | 0.0000 | a quota overrun approved under `allow_negative_balance` lands here |
| `reason` | text | not null | §27 |
| `attachment_path` | string(255) | nullable | **private** disk, `leaves/{employee}/` |
| `attachment_name` | string(255) | nullable | |
| `contact_during_leave` | string(64) | nullable | |
| `status` | string(16) | `pending` | cast `LeaveRequestStatus` - §27's Pending / Approved / Rejected, plus `cancelled` |
| `current_approval_level` | tinyInteger unsigned | 1 | which chain level the request waits on |
| `balance_snapshot_days` | decimal(8,4) | nullable | available balance at application time, for the audit trail |
| `applied_on` | date | not null | |
| `approved_at` | timestamp | nullable | stamped when the **last** level approves |
| `rejected_at` | timestamp | nullable | |
| `rejection_reason` | string(255) | nullable | mandatory on reject |
| `cancelled_at` | timestamp | nullable | |
| `cancelled_by` | FK `users.id` | nullable | `nullOnDelete` |
| `cancellation_reason` | string(255) | nullable | mandatory on cancel |
| `attendance_applied_at` | timestamp | nullable | when the approved leave was written onto attendance rows |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_lr_number(request_number)`; `INDEX (employee_id, status)`; `INDEX (status, from_date)`; `INDEX (leave_type_id, from_date)`; `INDEX (from_date, to_date)`; `INDEX (branch_id, status)`.
**CHECK** `chk_lr_dates`: `to_date >= from_date`.
**CHECK** `chk_lr_days`: `total_days > 0 AND paid_days >= 0 AND unpaid_days >= 0 AND paid_days + unpaid_days = total_days`.
**Relationships.** belongsTo `Employee`, `LeaveType`, `Branch`, `User` as `canceller`; hasMany `LeaveRequestDay`, `LeaveApproval`, `Attendance`, `LeaveBalanceTransaction`.
**Policy.** `update` only while `pending` and only by the owner or a holder of `leaves.edit`; `delete` refused once any approval row has acted - the path is `cancel`.

### 2.16 `leave_request_days` (the expansion attendance and quota both read)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `leave_request_id` | FK `leave_requests.id` | not null | `cascadeOnDelete` (only a pending, never-acted request is deletable) |
| `employee_id` | FK `employees.id` | not null | denormalised so the guard can exist |
| `leave_date` | date | not null | |
| `day_portion` | string(16) | `full_day` | cast `LeaveDayPortion` |
| `day_fraction` | decimal(8,4) | 1.0000 | `1.0000` or `0.5000` |
| `is_counted` | boolean | true | false for a skipped weekend / holiday inside the range (kept, so the audit trail shows why 5 calendar days cost 3 quota days) |
| `is_paid` | boolean | true | snapshot of the type at approval time |
| `is_active` | boolean | true | false once the request is rejected or cancelled - releases the guard |
| `attendance_id` | FK `attendances.id` | nullable | `nullOnDelete`; set when the day was written onto attendance |
| `day_guard` | string(40) STORED generated | - | `CONCAT(employee_id,':',DATE_FORMAT(leave_date,'%Y-%m-%d'))` when `is_active = 1 AND is_counted = 1`, else NULL |
| timestamps / blameable | | | **no `deleted_at`** |

**Keys.** `UNIQUE uq_lrd_day(day_guard)` (HR-8); `INDEX (leave_request_id)`; `INDEX (employee_id, leave_date)`; `INDEX (attendance_id)`.
**Relationships.** belongsTo `LeaveRequest`, `Employee`, `Attendance`.
**Deliberate restriction.** One counted leave day per employee per date, so two half-day leaves of different types on one date are refused with a clear message naming the existing request. This is what makes HR-8 enforceable in the database instead of in a service that can be bypassed.

### 2.17 `leave_approvals` (the approval chain)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `leave_request_id` | FK `leave_requests.id` | not null | `cascadeOnDelete` |
| `level` | tinyInteger unsigned | not null | 1 = reporting manager, 2 = HR |
| `expected_approver_employee_id` | FK `employees.id` | nullable | `nullOnDelete`; the post that owes the decision |
| `expected_approver_user_id` | FK `users.id` | nullable | `nullOnDelete`; null = "any holder of the fallback permission" |
| `fallback_permission` | string(64) | nullable | `leaves.approve`, used when no named approver resolved |
| `status` | string(16) | `pending` | cast `LeaveApprovalStatus` |
| `acted_by_user_id` | FK `users.id` | nullable | `nullOnDelete` - who actually decided |
| `acted_at` | timestamp | nullable | |
| `comment` | string(255) | nullable | mandatory on `rejected` |
| `notified_at` | timestamp | nullable | |
| timestamps / blameable | | | **no `deleted_at`** |

**Keys.** `UNIQUE uq_la_level(leave_request_id, level)`; `INDEX (status, expected_approver_user_id)` the approval inbox; `INDEX (expected_approver_employee_id, status)`.
**Relationships.** belongsTo `LeaveRequest`, `Employee` as `expectedApprover`, `User` as `actor`.
**Rules.** Level 2 cannot act before level 1 is `approved` (service guard + test). A rejection at any level rejects the request and marks the remaining levels `skipped`. **An employee may never approve their own leave**, whatever permissions they hold; when the resolved approver is the requester, the level falls through to the next named approver or to the fallback permission.

### 2.18 `salary_components` (§28 typed allowances and deductions)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `code` | string(32) | not null | `UNIQUE` (`BASIC`, `HRA`, `FUEL`, `TAX`, `ADV_RECOVERY`) |
| `name` | string(100) | not null | |
| `component_group` | string(32) | not null | cast `SalaryComponentGroup` - the group decides the side, so a deduction can never be summed as an earning |
| `side` | string(16) | not null | cast `SalaryComponentType`; written from `component_group->side()`, never by hand |
| `calculation_type` | string(24) | `fixed` | cast `SalaryComponentCalculation` |
| `default_amount` | decimal(15,2) | 0.00 | |
| `default_rate` | decimal(8,4) | 0.0000 | percentage, when the calculation is percentage-based |
| `is_taxable` | boolean | true | earnings only; feeds `taxable_gross` |
| `affects_gross` | boolean | true | false for a reimbursement that must not inflate gross |
| `is_statutory` | boolean | false | EOBI / PF style deductions |
| `is_attendance_dependent` | boolean | false | true for a `per_day` component |
| `is_system` | boolean | false | `BASIC`, `UNPAID_LEAVE`, `LATE`, `ADV_RECOVERY`, `TAX`, `ROUNDING` cannot be renamed, re-sided or deleted |
| `print_label` | string(100) | nullable | what the slip prints when it differs from `name` |
| `is_active` | boolean | true | index |
| `sort_order` | integer | 0 | slip print order |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_sc_code(code)`; `INDEX (side, is_active, sort_order)`.
**CHECK** `chk_sc_defaults`: `default_amount >= 0 AND default_rate >= 0 AND default_rate <= 100`.
**Relationships.** hasMany `SalaryStructureComponent`, `PayrollRunItemComponent`.
**Policy.** `delete` refused when `is_system` or when any structure/slip line references it; `is_active = false` retires it.

### 2.19 `salary_structures` (§28 salary structure per employee - immutable versions)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `employee_id` | FK `employees.id` | not null | `restrictOnDelete` |
| `version` | unsignedInteger | 1 | 1, 2, 3 ... per employee |
| `supersedes_id` | FK `salary_structures.id` | nullable | `nullOnDelete`, the previous version |
| `superseded_by_id` | FK `salary_structures.id` | nullable | `nullOnDelete` |
| `effective_from` | date | not null | index |
| `effective_to` | date | nullable | null = open-ended (at most one per employee) |
| `basic_salary` | decimal(15,2) | 0.00 | the only independent money figure |
| `gross_salary` | decimal(15,2) | 0.00 | `basic + SUM(earning lines)`, computed by `Money` and stored |
| `total_deduction_amount` | decimal(15,2) | 0.00 | the structure's recurring deductions |
| `net_salary_estimate` | decimal(15,2) | 0.00 | `gross - total_deduction_amount`, **display only** - no slip ever reads it |
| `currency` | string(3) | `PKR` | constant for now (the spine's R-12 applies here too) |
| `pay_frequency` | string(16) | `monthly` | monthly only in this phase (§12.1 R-4) |
| `status` | string(16) | `scheduled` | cast `SalaryStructureStatus` |
| `change_reason` | string(255) | not null | **mandatory** - why this version exists ("annual increment 2026") |
| `approved_by` | FK `users.id` | nullable | `nullOnDelete`, when `salary_structures.approve` is required |
| `approved_at` | timestamp | nullable | |
| `open_guard` | string(24) STORED generated | - | `employee_id` when `effective_to IS NULL AND status IN ('scheduled','active')`, else NULL |
| `created_at` / `updated_at` | timestamps | nullable | **no `deleted_at`** |
| `created_by` / `updated_by` | FK `users.id` | nullable | Blameable |

**Keys.** `UNIQUE uq_ss_open(open_guard)` (HR-10); `UNIQUE uq_ss_version(employee_id, version)`; `INDEX (employee_id, effective_from)`; `INDEX (status, effective_from)`.
**CHECK** `chk_ss_money`: `basic_salary >= 0 AND gross_salary >= basic_salary AND total_deduction_amount >= 0`.
**CHECK** `chk_ss_dates`: `effective_to IS NULL OR effective_to >= effective_from`.
**Relationships.** belongsTo `Employee`, `SalaryStructure` as `predecessor` / `successor`, `User` as `approver`; hasMany `SalaryStructureComponent`, `PayrollRunItem`.
**Rules.** No money column, no date and no component line is ever UPDATEd (the model `updating` hook allows only `status`, `effective_to`, `superseded_by_id`, `approved_*`, `updated_*`). `createVersion()` refuses to close a version at a date that would strand a **locked** payroll item whose period lies beyond the new `effective_to`.

### 2.20 `salary_structure_components`

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `salary_structure_id` | FK `salary_structures.id` | not null | `cascadeOnDelete` (reachable only while the version is being built inside its own transaction) |
| `salary_component_id` | FK `salary_components.id` | not null | `restrictOnDelete` |
| `component_code` | string(32) | not null | SNAPSHOT |
| `component_name` | string(100) | not null | SNAPSHOT of the printable name |
| `component_group` | string(32) | not null | SNAPSHOT |
| `side` | string(16) | not null | SNAPSHOT |
| `calculation_type` | string(24) | not null | SNAPSHOT |
| `rate` | decimal(8,4) | 0.0000 | |
| `amount` | decimal(15,2) | 0.00 | the resolved monthly amount for a full-attendance month |
| `is_taxable` | boolean | true | SNAPSHOT |
| `affects_gross` | boolean | true | SNAPSHOT |
| `sort_order` | integer | 0 | |
| timestamps / blameable | | | **no `deleted_at`** |

**Keys.** `UNIQUE uq_ssc(salary_structure_id, salary_component_id)`; `INDEX (salary_component_id)`.
**CHECK** `chk_ssc_money`: `amount >= 0 AND rate >= 0 AND rate <= 100`.
**Relationships.** belongsTo `SalaryStructure`, `SalaryComponent`.

### 2.21 `employee_advances` (§28 advance)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `advance_number` | string(32) | not null | `UNIQUE`, `hr.advance_number_prefix` + counter |
| `employee_id` | FK `employees.id` | not null | `restrictOnDelete` |
| `amount` | decimal(15,2) | not null | what was advanced |
| `reason` | text | not null | |
| `requested_on` | date | not null | |
| `installment_count` | tinyInteger unsigned | 1 | |
| `installment_amount` | decimal(15,2) | 0.00 | `amount / installment_count` via `Money::div`, residual added to the last installment |
| `first_recovery_year` | smallInteger unsigned | nullable | the payroll period recovery starts in |
| `first_recovery_month` | tinyInteger unsigned | nullable | |
| `recovered_amount` | decimal(15,2) | 0.00 | CACHE of `SUM(signed_amount)` of its repayments |
| `waived_amount` | decimal(15,2) | 0.00 | CACHE of waiver rows |
| `outstanding_amount` | decimal(15,2) | 0.00 | CACHE `amount - recovered - waived`; never negative |
| `status` | string(16) | `requested` | cast `AdvanceStatus` |
| `approved_by` / `approved_at` | FK `users.id` / timestamp | nullable | `nullOnDelete` |
| `rejected_by` / `rejected_at` | FK `users.id` / timestamp | nullable | `nullOnDelete` |
| `rejection_reason` | string(255) | nullable | mandatory on reject |
| `disbursed_on` | date | nullable | |
| `disbursement_method` | string(24) | nullable | cast `PaymentMethod` |
| `disbursement_reference` | string(64) | nullable | |
| `settled_at` | timestamp | nullable | stamped when `outstanding_amount` reaches 0 |
| `notes` | string(255) | nullable | |
| `created_at` / `updated_at` | timestamps | nullable | **no `deleted_at`** |
| `created_by` / `updated_by` | FK `users.id` | nullable | Blameable |

**Keys.** `UNIQUE uq_adv_number(advance_number)`; `INDEX (employee_id, status)`; `INDEX (status, requested_on)`; `INDEX (first_recovery_year, first_recovery_month)`.
**CHECK** `chk_adv_amount`: `amount > 0 AND installment_count > 0`.
**CHECK** `chk_adv_ceiling`: `recovered_amount >= 0 AND waived_amount >= 0 AND recovered_amount + waived_amount <= amount` (HR-19).
**Relationships.** belongsTo `Employee`, `User` as `approver` / `rejecter`; hasMany `EmployeeAdvanceRepayment`.
**Rules.** `amount`, `employee_id` and `disbursed_on` are immutable once `status = disbursed`; cancellation and write-off are statuses, never deletes.

### 2.22 `employee_advance_repayments` (append-only)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `employee_advance_id` | FK `employee_advances.id` | not null | `restrictOnDelete` |
| `payroll_run_item_id` | FK `payroll_run_items.id` | nullable | `restrictOnDelete`; set when payroll took the money |
| `entry_type` | string(8) | `debit` | cast `LedgerEntryType`; `debit` reduces the outstanding, `credit` gives it back (a correction run) |
| `recovery_type` | string(16) | `payroll` | cast `AdvanceRecoveryType` |
| `amount` | decimal(15,2) | not null | positive magnitude |
| `signed_amount` | decimal(15,2) STORED generated | - | `CASE WHEN entry_type='debit' THEN amount ELSE -amount END` |
| `recovered_on` | date | not null | value date |
| `reference` | string(64) | nullable | |
| `notes` | string(255) | nullable | mandatory for `waiver` and `correction` |
| `performed_by` | FK `users.id` | nullable | `nullOnDelete` |
| `created_at` / `updated_at` | timestamps | nullable | **no `deleted_at`** |
| `created_by` / `updated_by` | FK `users.id` | nullable | Blameable |

**Keys.** `UNIQUE uq_aar_item(employee_advance_id, payroll_run_item_id)` - one payroll recovery per advance per slip (NULLs are distinct, so manual rows are unconstrained); `INDEX (employee_advance_id, recovered_on)`; `INDEX (payroll_run_item_id)`.
**CHECK** `chk_aar_amount`: `amount > 0`.
**Relationships.** belongsTo `EmployeeAdvance`, `PayrollRunItem`, `User` as `performer`.
**Rules.** Append-only: nothing but `notes` may be updated; `deleting` throws and a `BEFORE DELETE` trigger raises `SIGNAL SQLSTATE '45000'`.

### 2.23 `payroll_runs` (§28 monthly run - generated, then locked)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `run_number` | string(32) | not null | `UNIQUE`, `hr.payroll_run_prefix` + counter |
| `title` | string(150) | nullable | "March 2026 payroll" |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete`; null = all branches (D11) |
| `run_type` | string(24) | `regular` | cast `PayrollRunType` |
| `parent_run_id` | FK `payroll_runs.id` | nullable | `nullOnDelete`; a correction run points at the run it corrects |
| `period_year` | smallInteger unsigned | not null | |
| `period_month` | tinyInteger unsigned | not null | 1-12 |
| `period_start` / `period_end` | date | not null | |
| `payment_date` | date | nullable | planned disbursement date |
| `status` | string(24) | `draft` | cast `PayrollRunStatus` |
| `employee_count` | unsignedInteger | 0 | CACHE of its items |
| `total_gross` | decimal(15,2) | 0.00 | CACHE `SUM(items.gross_earnings)` |
| `total_deductions` | decimal(15,2) | 0.00 | CACHE |
| `total_net` | decimal(15,2) | 0.00 | CACHE |
| `total_paid` | decimal(15,2) | 0.00 | CACHE of items marked paid |
| `day_basis` | string(16) | not null | SNAPSHOT of `hr.payroll_day_basis` at generation |
| `lop_basis` | string(16) | not null | SNAPSHOT of `hr.lop_basis` |
| `tax_mode` | string(16) | not null | SNAPSHOT of `hr.tax_mode` |
| `settings_snapshot` | json | nullable | every `hr.*` key that influenced the calculation, so a slip is reproducible years later |
| `generated_at` | timestamp | nullable | |
| `generated_by` | FK `users.id` | nullable | `nullOnDelete` |
| `locked_at` | timestamp | nullable | **the immutability line** (HR-15) |
| `locked_by` | FK `users.id` | nullable | `nullOnDelete` |
| `paid_at` | timestamp | nullable | |
| `cancelled_at` / `cancelled_by` | timestamp / FK `users.id` | nullable | `nullOnDelete` |
| `cancellation_reason` | string(255) | nullable | mandatory on cancel |
| `notes` | text | nullable | |
| `regular_guard` | string(40) STORED generated | - | `CONCAT(COALESCE(branch_id,0),':',period_year,':',period_month)` when `run_type='regular' AND status <> 'cancelled'`, else NULL |
| `created_at` / `updated_at` | timestamps | nullable | **no `deleted_at`** |
| `created_by` / `updated_by` | FK `users.id` | nullable | Blameable |

**Keys.** `UNIQUE uq_pr_number(run_number)`; `UNIQUE uq_pr_regular(regular_guard)` - one live regular run per branch per month; `INDEX (period_year, period_month, status)`; `INDEX (status, locked_at)`; `INDEX (branch_id, period_year, period_month)`; `INDEX (parent_run_id)`.
**CHECK** `chk_pr_month`: `period_month BETWEEN 1 AND 12`.
**CHECK** `chk_pr_totals`: `total_gross >= 0 AND total_deductions >= 0 AND total_paid >= 0` (`total_net` may be negative on a correction run).
**Relationships.** belongsTo `Branch`, `PayrollRun` as `parentRun`, `User` as `generator` / `locker` / `canceller`; hasMany `PayrollRunItem`, `PayrollRun` as `correctionRuns`, `Attendance` as `lockedAttendances`, `AttendanceMonthlySummary` as `lockedSummaries`; belongsToMany `Employee` through pivot **`payroll_run_items`**.

### 2.24 `payroll_run_items` (the salary slip - §28)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `payroll_run_id` | FK `payroll_runs.id` | not null | `restrictOnDelete` |
| `run_type` | string(24) | not null | DENORMALISED from the run so the sign CHECK can exist (HR-17) |
| `slip_number` | string(32) | not null | `UNIQUE`, `hr.payslip_prefix` + counter, assigned at generation |
| `employee_id` | FK `employees.id` | not null | `restrictOnDelete` |
| `salary_structure_id` | FK `salary_structures.id` | nullable | `restrictOnDelete`; the version used |
| `attendance_monthly_summary_id` | FK `attendance_monthly_summaries.id` | nullable | `restrictOnDelete`; the summary used (HR-5) |
| `corrects_item_id` | FK `payroll_run_items.id` | nullable | `restrictOnDelete`; set on a correction item |
| `department_name` | string(150) | nullable | SNAPSHOT - the slip must print what was true then |
| `designation_title` | string(150) | nullable | SNAPSHOT |
| `employment_type` | string(32) | nullable | SNAPSHOT |
| `joining_date` | date | nullable | SNAPSHOT |
| `basic_salary` | decimal(15,2) | 0.00 | SNAPSHOT from the structure |
| `contracted_gross` | decimal(15,2) | 0.00 | SNAPSHOT: the full-attendance gross |
| `gross_earnings` | decimal(15,2) | 0.00 | `SUM(components where side = earning)` (HR-13) |
| `total_deductions` | decimal(15,2) | 0.00 | `SUM(components where side = deduction)` |
| `taxable_gross` | decimal(15,2) | 0.00 | sum of taxable earnings |
| `tax_amount` | decimal(15,2) | 0.00 | §28 tax - also a component row; this column is the reporting handle |
| `allowance_amount` | decimal(15,2) | 0.00 | §28 allowances - reporting handle = sum of the `allowance` group |
| `bonus_amount` | decimal(15,2) | 0.00 | §28 bonus |
| `commission_amount` | decimal(15,2) | 0.00 | §28 commission - an **employee** commission component (§6.9) |
| `overtime_amount` | decimal(15,2) | 0.00 | |
| `advance_recovery_amount` | decimal(15,2) | 0.00 | §28 advance |
| `unpaid_leave_deduction` | decimal(15,2) | 0.00 | loss of pay |
| `late_deduction` | decimal(15,2) | 0.00 | only when `hr.late_deduction_lates_per_day > 0` |
| `net_salary` | decimal(15,2) | 0.00 | `gross_earnings - total_deductions` (HR-13, HR-14) |
| `payable_days` | decimal(8,4) | 0.0000 | SNAPSHOT from the summary |
| `lop_days` | decimal(8,4) | 0.0000 | SNAPSHOT |
| `working_days` | decimal(8,4) | 0.0000 | SNAPSHOT |
| `present_days` | decimal(8,4) | 0.0000 | SNAPSHOT |
| `paid_leave_days` / `unpaid_leave_days` | decimal(8,4) | 0.0000 | SNAPSHOT |
| `late_count` | smallInteger unsigned | 0 | SNAPSHOT |
| `day_divisor` | decimal(8,4) | 0.0000 | the divisor actually used for the per-day rate |
| `per_day_amount` | decimal(15,2) | 0.00 | SNAPSHOT |
| `calculation_snapshot` | json | nullable | every input and intermediate of §6.6, so the slip is reproducible and auditable |
| `status` | string(16) | `draft` | cast `PayrollItemStatus` |
| `hold_reason` | string(255) | nullable | mandatory when `on_hold` |
| `paid_at` | timestamp | nullable | |
| `payment_method` | string(24) | nullable | cast `PaymentMethod` |
| `payment_reference` | string(64) | nullable | |
| `paid_by` | FK `users.id` | nullable | `nullOnDelete` |
| `notes` | string(255) | nullable | the only field editable after payment |
| `created_at` / `updated_at` | timestamps | nullable | **no `deleted_at`** |
| `created_by` / `updated_by` | FK `users.id` | nullable | Blameable |

**Keys.** `UNIQUE uq_pri_slip(slip_number)`; `UNIQUE uq_pri_employee(payroll_run_id, employee_id)`; `INDEX (employee_id, created_at)` the employee's own slip list; `INDEX (payroll_run_id, status)`; `INDEX (status, paid_at)`; `INDEX (corrects_item_id)`; `INDEX (salary_structure_id)`; `INDEX (attendance_monthly_summary_id)`.
**CHECK** `chk_pri_sign`: `run_type = 'correction' OR (gross_earnings >= 0 AND total_deductions >= 0 AND net_salary >= 0)` (HR-14, HR-17).
**CHECK** `chk_pri_days`: `payable_days >= 0 AND lop_days >= 0 AND working_days >= 0`.
**Relationships.** belongsTo `PayrollRun`, `Employee`, `SalaryStructure`, `AttendanceMonthlySummary`, `PayrollRunItem` as `correctedItem`, `User` as `payer`; hasMany `PayrollRunItemComponent`, `EmployeeAdvanceRepayment`, `PayrollRunItem` as `corrections`.

### 2.25 `payroll_run_item_components` (the typed lines of the slip)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `payroll_run_item_id` | FK `payroll_run_items.id` | not null | `cascadeOnDelete` - fires only while the parent run is `draft`/`generated` (HR-16) |
| `run_type` | string(24) | not null | DENORMALISED for the sign CHECK |
| `salary_component_id` | FK `salary_components.id` | nullable | `restrictOnDelete`; null only for a system line whose component was retired |
| `component_code` | string(32) | not null | SNAPSHOT |
| `component_name` | string(100) | not null | SNAPSHOT - what the slip prints |
| `component_group` | string(32) | not null | SNAPSHOT, cast `SalaryComponentGroup` |
| `side` | string(16) | not null | SNAPSHOT, cast `SalaryComponentType` |
| `calculation_type` | string(24) | not null | SNAPSHOT |
| `rate` | decimal(8,4) | 0.0000 | |
| `base_amount` | decimal(15,2) | 0.00 | what the rate was applied to |
| `quantity` | decimal(8,4) | 0.0000 | days / count for a `per_day` or late-deduction line |
| `amount` | decimal(15,2) | not null | the stored, quantised figure the totals sum (HR-13) |
| `is_taxable` | boolean | true | SNAPSHOT |
| `source_type` | string(64) | nullable | `employee_advance`, `leave_request`, or a future commission source |
| `source_id` | unsignedBigInteger | nullable | |
| `calculation_note` | string(255) | nullable | the human formula: "34,000.00 / 31 x 2.5000 days" |
| `sort_order` | integer | 0 | print order |
| `created_at` / `updated_at` | timestamps | nullable | **no `deleted_at`** |
| `created_by` / `updated_by` | FK `users.id` | nullable | Blameable |

**Keys.** `UNIQUE uq_pric_line(payroll_run_item_id, component_code)`; `INDEX (salary_component_id)`; `INDEX (source_type, source_id)`; `INDEX (component_group)`.
**CHECK** `chk_pric_sign`: `run_type = 'correction' OR amount >= 0`.
**CHECK** `chk_pric_rate`: `rate >= 0 AND rate <= 100`.
**Relationships.** belongsTo `PayrollRunItem`, `SalaryComponent`.

### 2.26 Status lifecycles - explicit transition tables

**`EmployeeStatus`**

| From | To | Who / rule |
|---|---|---|
| `probation` | `active`, `inactive`, `terminated` | `employees.change_status`; reason mandatory for anything but `active` |
| `active` | `suspended`, `inactive`, `resigned`, `terminated` | reason mandatory; `resigned`/`terminated` require `exit_date` and run `EmployeeService::exit()` |
| `suspended` | `active`, `inactive`, `terminated` | reason mandatory |
| `inactive` | `active` | reason mandatory |
| `resigned`, `terminated` | - | **terminal.** A re-hire is a new employee row with a new code; the old record stays for payroll history |

`EmployeeService::exit()` in one transaction: stamps `exit_date` / `exit_reason`; cancels every `pending`
leave request with the reason "employee exited"; closes the open salary structure at `exit_date`; posts an
`exit_settlement` leave-ledger entry for any encashable balance; leaves every advance outstanding and
flags it on the final-settlement screen; excludes the employee from future regular runs; and - when
`user_id` is set - sets `users.status = inactive` so Phase 1's `EnsureUserIsActive` ends the session.

**`AttendanceStatus`** is not a workflow: it is **recomputed** by §6.3 from punches, leave and the
calendar, except when `is_manual = true`.

**`AttendanceCorrectionStatus`**

| From | To | Who |
|---|---|---|
| `pending` | `approved` | `attendance.approve`: applies `new_values`, stamps `applied_at`, sets the row `is_manual`, rebuilds that month's summary |
| `pending` | `rejected` | `attendance.reject`; `review_comment` mandatory |
| `pending` | `cancelled` | the requester, while still pending |
| `approved`, `rejected`, `cancelled` | - | terminal |

**`LeaveRequestStatus`**

| From | To | Who / rule |
|---|---|---|
| `pending` | `pending` (next level) | level 1 approves and `approval_levels = 2`: `current_approval_level` becomes 2, days stay **reserved** |
| `pending` | `approved` | the final level approves: the reservation converts to consumption and attendance rows are written |
| `pending` | `rejected` | any level rejects: reservation released, remaining levels `skipped`, reason mandatory |
| `pending` | `cancelled` | the employee withdraws, or `leaves.change_status`: reservation released |
| `approved` | `cancelled` | `leaves.change_status` **and** no touched attendance date is locked: consumption credited back, leave removed from attendance, each affected day recomputed, reason mandatory. Refused when any touched date is locked (HR-18) - the remedy is then a payroll correction run |
| `rejected`, `cancelled` | - | terminal |

**`PayrollRunStatus`**

| From | To | Who / rule |
|---|---|---|
| `draft` | `generated` | `payroll.create`: items computed by `PayrollRunService::generate()` |
| `generated` | `generated` | regeneration: draft items and their components are deleted and rewritten; allowed only while unlocked |
| `draft`, `generated` | `cancelled` | `payroll.change_status`, reason mandatory; nothing was ever paid |
| `generated` | `locked` | `payroll.approve`: asserts HR-13 per item, stamps `locked_at`/`locked_by`, locks the period's attendance and summaries (HR-18), makes every item immutable (HR-15) |
| `locked` | `partially_paid` | the first item is marked paid |
| `partially_paid` | `paid` | every item that is not `on_hold`/`cancelled` is paid; stamps `paid_at` |
| `locked`, `partially_paid`, `paid` | - | **never back to `generated`, never cancelled, never unlocked.** A mistake becomes a `correction` run (HR-17) |

**`PayrollItemStatus`**: `draft` -> `locked` (with the run) -> `paid`; `locked` <-> `on_hold` (reason
mandatory); `draft` -> `cancelled` (excluded before lock). A `paid` item is terminal.

**`AdvanceStatus`**: `requested` -> `approved` | `rejected` | `cancelled`; `approved` -> `disbursed` |
`cancelled`; `disbursed` -> `recovering` (first repayment) -> `settled`; `disbursed` / `recovering` ->
`written_off` (`employee_advances.approve` + mandatory reason, posts a `waiver` repayment row).
`settled`, `rejected`, `cancelled`, `written_off` are terminal.

### 2.27 Migration order and the circular foreign keys

One ordered migration set, timestamps ascending, so `migrate:fresh` works and every rollback is clean:

| # | Migration | Note |
|---|---|---|
| 1 | `create_departments_table` | **without** `head_employee_id` |
| 2 | `create_designations_table` | |
| 3 | `create_work_shifts_table` | before employees, because `employees.work_shift_id` points at it |
| 4 | `create_employees_table` | FKs to `departments`, `designations`, `work_shifts`, `branches`, `users`, and the self FK `reports_to_id` |
| 5 | `add_head_employee_id_to_departments_table` | the circular edge, added after `employees` exists |
| 6 | `create_employee_skills_table`, `create_employee_documents_table` | |
| 7 | `create_holidays_table` | |
| 8 | `create_leave_types_table` | before `leave_requests` |
| 9 | `create_leave_requests_table` | **without** `attendance`-side columns |
| 10 | `create_attendances_table` | FK `leave_request_id` resolves here; `locked_by_payroll_run_id` is added in step 16 |
| 11 | `create_leave_request_days_table`, `create_leave_approvals_table`, `create_leave_balances_table`, `create_leave_balance_transactions_table` | `leave_request_days.attendance_id` resolves because `attendances` exists |
| 12 | `create_attendance_corrections_table`, `create_attendance_monthly_summaries_table` | |
| 13 | `create_salary_components_table`, `create_salary_structures_table`, `create_salary_structure_components_table` | |
| 14 | `create_employee_advances_table` | |
| 15 | `create_payroll_runs_table`, `create_payroll_run_items_table`, `create_payroll_run_item_components_table` | |
| 16 | `add_payroll_lock_fks_to_attendance_tables` | `attendances.locked_by_payroll_run_id` and `attendance_monthly_summaries.locked_by_payroll_run_id` |
| 17 | `create_employee_advance_repayments_table` | after `payroll_run_items`, so `payroll_run_item_id` resolves |
| 18 | `add_hr_generated_columns_and_constraints` | **raw SQL**: every STORED generated column, every guard UNIQUE index, every CHECK of §2, and the `BEFORE DELETE` triggers. Must fail loudly if the server rejects one; `down()` drops them in reverse |

Any FK that points **into** these tables from another phase is added by that phase in a
`Schema::hasTable()`-guarded `add_employee_fks_to_*` migration ([D-HR-1]).

**Seeders** (idempotent, never destructive - `firstOrCreate` + metadata update only): `DepartmentSeeder`
(§25's ten departments), `DesignationSeeder`, `WorkShiftSeeder` (one default "General 09:00-18:00"),
`HolidaySeeder` (the current year's public holidays for Pakistan, editable), `LeaveTypeSeeder` (Annual 14
paid, Sick 8 paid, Casual 10 paid, Unpaid 0 unpaid - see §12.2 Q10), `SalaryComponentSeeder` (the six
system components of §2.18 plus House Rent, Fuel, Medical as examples), and an `HrDemoSeeder` for §116's
demo data, kept out of the production seed path.

---

## 3. Enums to add

All in `app/Enums/`, string-backed, implementing `label(): string` and `color(): string` and exposing
`static options(): array`, exactly as Phase 1 §2 requires.

| Enum | Cases (values) | Extra members |
|---|---|---|
| `EmployeeStatus` | `active`, `probation`, `suspended`, `inactive`, `resigned`, `terminated` | `isPayrollEligible(): bool` (true for `active`, `probation`), `isExited(): bool`, `requiresReason(): bool` |
| `EmploymentType` | `full_time`, `part_time`, `contract`, `internship`, `temporary`, `consultant`, `freelance` | `isSalaried(): bool` (false for `consultant` **and `freelance`**), `leaveEligibleByDefault(): bool` (false for `consultant` and `freelance`). **Phase 7 is the single owner** (F-5.2): phase-04 declares no second copy and casts `job_openings.employment_type` to `App\Enums\EmploymentType` |
| `SkillLevel` | `beginner`, `intermediate`, `advanced`, `expert` | |
| `EmployeeDocumentType` | `cnic`, `passport`, `contract`, `offer_letter`, `appointment_letter`, `degree`, `certificate`, `experience_letter`, `resume`, `police_verification`, `medical`, `other` | `expectsExpiry(): bool` (`cnic`, `passport`, `contract`, `medical`, `police_verification`), `isConfidentialByDefault(): bool` |
| `DocumentVerificationStatus` | `pending`, `verified`, `rejected`, `expired` | |
| `DayType` | `working`, `weekly_off`, `public_holiday` | `isWorking(): bool`, `countsTowardWorkingDays(): bool` |
| `AttendanceStatus` | **the seven states of §26**: `present`, `late`, `half_day`, `early_leave`, `absent`, `on_leave`, `holiday` | `isPresentState(): bool` (`present`, `late`, `early_leave`), `defaultPayableFactor(): string` (a decimal **string**, never a float), `countsAsWorkedDay(): bool` |
| `AttendanceSource` | `self_web`, `kiosk`, `admin`, `import`, `api`, `system` | `isSelfService(): bool`; `system` is the nightly closer |
| `AttendanceCorrectionType` | `missing_check_in`, `missing_check_out`, `wrong_time`, `status_change`, `leave_regularisation`, `holiday_recalculation`, `other` | |
| `CorrectionSource` | `self_request`, `hr_direct` | `needsApproval(): bool` (`self_request` reads `hr.attendance_correction_requires_approval`) |
| `AttendanceCorrectionStatus` | `pending`, `approved`, `rejected`, `cancelled` | `isTerminal(): bool` |
| `HolidayType` | `public`, `religious`, `company`, `optional` | `changesDayType(): bool` - false for `optional`, which is informational only |
| `LeaveAccrualMethod` | `annual_grant`, `monthly_accrual`, `none` | |
| `LeaveDayPortion` | `full_day`, `first_half`, `second_half` | `fraction(): string` (`1.0000` / `0.5000`, decimal strings) |
| `LeaveRequestStatus` | `pending`, `approved`, `rejected`, `cancelled` | §27 verbatim plus `cancelled`. `isOpen(): bool`, `reservesBalance(): bool` (only `pending`), `consumesBalance(): bool` (only `approved`) |
| `LeaveApprovalStatus` | `pending`, `approved`, `rejected`, `skipped` | |
| `LeaveLedgerReason` | `annual_grant`, `monthly_accrual`, `joining_proration`, `carry_forward_in`, `carry_forward_expiry`, `reservation`, `reservation_release`, `leave_consumed`, `leave_cancelled`, `manual_adjustment`, `encashment`, `year_end_lapse`, `exit_settlement` | `requiresNote(): bool` (`manual_adjustment`, `encashment`, `exit_settlement`) |
| `LedgerEntryType` | `credit`, `debit` | **Declared here** because Phase 7 migrates first; the financial spine §3 defines these two cases and the spine, phase-10-12, phase-13, phase-14-17 and phase-18 **reuse, never redeclare** ([D-HR-14], §13, F-5.4) |
| `PaymentMethod` | `cash`, `bank_transfer`, `card`, `cheque`, `easypaisa`, `jazzcash`, `online_gateway`, `adjustment`, `other` | Verbatim from the financial spine §3 (§32); **declared here** because advance disbursement and salary payment need it first, and reused unchanged by every later money phase ([D-HR-14], F-5.4) |
| `SalaryComponentType` | `earning`, `deduction` | `sign(): int` |
| `SalaryComponentGroup` | `basic`, `allowance`, `bonus`, `commission`, `overtime`, `reimbursement`, `other_earning`, `tax`, `advance_recovery`, `unpaid_leave`, `late_deduction`, `statutory`, `other_deduction` | `side(): SalaryComponentType` - **the group decides the side**; `isSystem(): bool`; `reportingColumn(): ?string` (which `payroll_run_items` handle it sums into) |
| `SalaryComponentCalculation` | `fixed`, `percentage_of_basic`, `percentage_of_gross`, `per_day` | `needsRate(): bool`, `pass(): int` - 1 for everything except `percentage_of_gross`, which is pass 2 (§6.6 step 3) |
| `SalaryStructureStatus` | `scheduled`, `active`, `superseded`, `expired`, `cancelled` | `isLive(): bool` |
| `PayrollRunType` | `regular`, `correction`, `bonus`, `final_settlement` | `allowsNegativeAmounts(): bool` (only `correction`), `occupiesRegularSlot(): bool` |
| `PayrollRunStatus` | `draft`, `generated`, `locked`, `partially_paid`, `paid`, `cancelled` | `isLocked(): bool` (`locked`, `partially_paid`, `paid`), `isEditable(): bool`, `isTerminal(): bool` |
| `PayrollItemStatus` | `draft`, `locked`, `on_hold`, `paid`, `cancelled` | `isImmutable(): bool`, `isPayable(): bool` |
| `AdvanceStatus` | `requested`, `approved`, `rejected`, `disbursed`, `recovering`, `settled`, `written_off`, `cancelled` | `isRecoverable(): bool` (`disbursed`, `recovering`), `isTerminal(): bool` |
| `AdvanceRecoveryType` | `payroll`, `manual`, `waiver`, `correction` | `requiresNote(): bool` |

---

## 4. PermissionRegistry additions

Ability presets are Phase 1's: `READ`, `CRUD`, `CRUD_FULL`, `APPROVE`, `STATUS`, `ASSIGN`, `FILES`,
`MONEY`, `REPORTS`, `LOGS`. **No new `Ability` case is invented.** Permission name = `{slug}.{ability}`,
label = `"{Ability label} {Module name}"`.

### 4.1 New module slugs (every one `is_core = false`, `ModuleGroup::Hr`)

| slug | icon | Abilities | Why these |
|---|---|---|---|
| `designations` | `identification` | `CRUD` + `STATUS` | §24's designation becomes dynamic data, the same shape as departments |
| `employee_documents` | `document-text` | `READ` + `create` + `delete` + `FILES` + `STATUS` + `LOGS` | CNICs and contracts are the most sensitive rows in HR: `download` is the privacy gate and every download is logged |
| `work_shifts` | `clock` | `CRUD` + `STATUS` + `ASSIGN` | the shift defines what "late" means; an edit changes nobody's history (HR-2) but changes everyone's future |
| `holidays` | `calendar-days` | `CRUD` + `import` + `export` | the calendar a clerk maintains yearly; `import` for a published holiday list |
| `leave_types` | `tag` | `CRUD` + `STATUS` | quotas and accrual rules are policy, not a leave clerk's business |
| `leave_balances` | `scale` | `READ` + `create` + `export` + `LOGS` | **no `edit`, no `delete`**: minting or removing days is an append-only adjustment (HR-7), and the right to mint days is not the right to approve a leave |
| `salary_components` | `adjustments-horizontal` | `CRUD` + `STATUS` | a component definition affects every future slip, so it is permissioned apart from one employee's salary |
| `salary_structures` | `banknotes` | `READ` + `create` + `APPROVE` + `STATUS` + `MONEY` + `LOGS` | **never `edit`, never `delete`** (HR-10): a raise is a new version |
| `salary_slips` | `document-currency-dollar` | `READ` + `print` + `export` + `MONEY` + `LOGS` | lets an Accountant read and print slips with no right to generate, lock or pay a run |
| `employee_advances` | `credit-card` | `READ` + `create` + `APPROVE` + `STATUS` + `MONEY` + `LOGS` | **never `delete`**: a cancellation is a status, a waiver is a row |
| `employee_self_service` | `user-circle` | `READ` + `create` + `print` + `upload` + `download` + `view_financial` | the staff member's own window (§9): `view` = own attendance and leave, `create` = own punch / leave request / correction request, `view_financial` = own slip amounts, `print` = own slip |

`attendance_corrections`, `leave_approvals`, `leave_request_days`, `salary_structure_components`,
`payroll_run_item_components` and `employee_skills` deliberately get **no** module of their own: each is
only ever viewed inside its parent's screen under the parent's `view` ability, and a separate module would
add permission surface for no gain.

### 4.2 Abilities added to the five Phase-1 HR slugs (additive; the registry stays the only place they are declared)

| slug | Abilities after this phase | Notes |
|---|---|---|
| `employees` | `CRUD_FULL` + `STATUS` + `ASSIGN` + `MONEY` + `FILES` + `import` + `REPORTS` + `LOGS` | `view_financial` gates `current_gross_salary` and the Salary tab; `assign` gates department / designation / shift / reporting-line changes; `delete` is further restricted by §2.4's policy |
| `departments` | `CRUD` + `STATUS` + `ASSIGN` | `assign` is "set the head of department" |
| `attendance` | `READ` + `create` + `edit` + `APPROVE` + `STATUS` + `import` + `export` + `REPORTS` + `LOGS` | **no `delete`**: a wrong row is corrected (HR-6). `edit` = issue a direct correction; `approve`/`reject` = decide a correction request; `change_status` = rebuild a summary |
| `leaves` | `READ` + `create` + `edit` + `delete` + `APPROVE` + `STATUS` + `FILES` + `export` + `REPORTS` + `LOGS` | `delete` only while `pending` and untouched by any approval (policy); `download` gates the attachment; `change_status` is cancellation |
| `payroll` | `READ` + `create` + `APPROVE` + `STATUS` + `print` + `export` + `MONEY` + `REPORTS` + `LOGS` | **never `edit`, never `delete`** (HR-15, HR-16). `create` = create/generate a run and issue a correction item; `approve` = **lock**; `change_status` = mark paid / hold / cancel a draft |

### 4.3 `modules.depends_on` declarations (Phase 2's dependency graph)

| Module | depends_on |
|---|---|
| `attendance` | `employees` |
| `leaves` | `employees`, `leave_types` |
| `leave_balances` | `leaves`, `leave_types` |
| `payroll` | `employees`, `attendance`, `salary_structures` |
| `salary_structures` | `employees`, `salary_components` |
| `salary_slips` | `payroll` |
| `employee_documents`, `designations`, `employee_advances`, `employee_self_service` | `employees` |
| `work_shifts`, `holidays`, `salary_components`, `leave_types` | - (standalone configuration) |

Disabling `employees` is therefore blocked while the modules above are enabled, with Phase 2's impact
modal naming them - and no HR row is ever touched by a toggle.

### 4.4 Role grants (extends Phase 1 §5; `RoleSeeder` stays idempotent)

| Role | Gets |
|---|---|
| **HR** | every module of §4.1 and §4.2 in full, including `payroll.approve` and every `MONEY` ability |
| **Accountant** | `payroll` (`READ`, `MONEY`, `REPORTS`, `print`, `export`, `change_status`), `salary_slips` (full), `employee_advances` (`READ`, `MONEY`), `employees` (`READ` + `view_financial`). **Not `payroll.approve`** - whoever locks a run is not whoever pays it |
| **Admin** | as Phase 1 §5 defines (everything except the named exclusions) |
| **Project Manager, Developer, Designer, SEO Expert, Digital Marketer, Sales Executive, Receptionist, Support Agent, Institute Manager, Course Coordinator** | `employee_self_service.*`; a line manager (anyone granted `leaves.approve`) additionally gets `leaves` (`READ` + `APPROVE`) and `attendance` (`view_any` + `APPROVE`), **scoped to their direct reports by §9** |
| **Teacher, Student, Client, Collaborator** | nothing from this phase - every HR route 403s |

---

## 5. SettingsRegistry additions

This phase adds **one new group**, `hr`, to Phase 2's `SettingsRegistry::groups()`. It is **not** the first
group added since Phase 2 - phase-03 / phase-04 (`website`, sort 75), phase-05 (`crm`) and phase-06
(`projects`, sort 86) precede it - so `groups()` is already proven open for extension. It is declared
exactly like the existing ones (`label` "HR & Payroll", `icon` `users`, `description`, `sort` after
`finance`, `permission` `settings.edit`). No existing key is redefined; in
particular `institute.attendance_grace_minutes` is the **student** grace period and is not reused here.

| group.key | type | default | Meaning |
|---|---|---|---|
| `hr.employee_code_prefix` | text | `EMP-` | §24 Employee ID |
| `hr.employee_code_next_number` | number | `1` | counter, locked in-transaction |
| `hr.leave_request_prefix` | text | `LVR-` | |
| `hr.leave_request_next_number` | number | `1` | |
| `hr.advance_number_prefix` | text | `ADV-` | |
| `hr.advance_next_number` | number | `1` | |
| `hr.payroll_run_prefix` | text | `PR-` | |
| `hr.payroll_run_next_number` | number | `1` | |
| `hr.payslip_prefix` | text | `SLP-` | |
| `hr.payslip_next_number` | number | `1` | |
| `hr.weekend_days` | multiselect (`monday`..`sunday`) | `sunday` | the default weekly off; a shift or an employee may override it |
| `hr.late_grace_minutes` | number | `15` | default `work_shifts.grace_in_minutes` |
| `hr.early_leave_grace_minutes` | number | `10` | default `work_shifts.grace_out_minutes` |
| `hr.full_day_min_minutes` | number | `480` | worked minutes that make a full day |
| `hr.half_day_min_minutes` | number | `240` | below this a worked day becomes `half_day` |
| `hr.short_day_as_half_day` | boolean | `false` | when true, `half_day_min <= worked < full_day_min` pays 0.5 instead of 1.0 |
| `hr.auto_absent_enabled` | boolean | `true` | the nightly closer marks a working day with no punch `absent` |
| `hr.attendance_day_close_time` | time | `23:50` | when `hr:close-attendance-day` runs for the current date |
| `hr.self_check_in_enabled` | boolean | `true` | turns the self-service punch off without touching permissions |
| `hr.self_check_in_ip_whitelist` | textarea | *(empty)* | comma/newline separated IPs or CIDRs; empty = any IP. A refused punch is logged with its IP (§111) |
| `hr.attendance_correction_window_days` | number | `7` | how far back a self-service correction request may reach |
| `hr.attendance_correction_requires_approval` | boolean | `true` | false lets a holder of `attendance.edit` apply corrections directly - the correction row is still written (HR-6) |
| `hr.overtime_pay_enabled` | boolean | `false` | overtime minutes are always measured; paying them is opt-in and still a manual component ([D-HR-6]) |
| `hr.late_deduction_lates_per_day` | number | `0` | `0` = no late deduction; `3` means every third late costs one day's pay |
| `hr.document_expiry_reminder_days` | number | `30` | default per-document reminder lead time |
| `hr.leave_year_start_month` | select 1-12 | `1` | the leave year need not be the calendar year |
| `hr.leave_accrual_run_day` | number | `1` | day of month the monthly accrual posts |
| `hr.leave_carry_forward_enabled` | boolean | `true` | master switch above each type's own flag |
| `hr.leave_negative_balance_allowed` | boolean | `false` | master switch above each type's own flag (HR-9) |
| `hr.leave_default_approval_levels` | number | `1` | default for a new leave type |
| `hr.payroll_day_basis` | select `calendar_days`\|`working_days`\|`fixed_30` | `calendar_days` | the divisor for the per-day rate (§6.6 step 4) |
| `hr.lop_basis` | select `basic`\|`gross` | `gross` | what a loss-of-pay day is deducted from |
| `hr.unpaid_leave_deduction_enabled` | boolean | `true` | false means unpaid leave is tracked but never deducted |
| `hr.payroll_net_rounding` | select `none`\|`nearest_1`\|`nearest_10` | `none` | an extra business rounding on net, posted as a visible `ROUNDING` component so the slip still adds up |
| `hr.tax_mode` | select `none`\|`fixed_percentage`\|`manual` | `manual` | §28's "tax"; no slab engine ([D-HR-6], Q5) |
| `hr.tax_default_rate` | decimal | `0.0000` | used only by `fixed_percentage` |
| `hr.advance_max_multiple_of_basic` | decimal | `1.0000` | a request above this needs `employee_advances.approve` plus a reason |
| `hr.advance_recovery_default_installments` | number | `1` | |
| `hr.advance_recovery_cap_percent` | decimal | `50.0000` | recovery may never take more than this share of net (§6.6 step 7) |
| `hr.payslip_show_attendance` | boolean | `true` | prints the attendance block on the slip |
| `hr.payslip_footer_note` | textarea | *(empty)* | |
| `hr.employee_self_service_enabled` | boolean | `true` | master switch for the `my/*` screens, independent of the module toggle |
| `hr.payroll_reminder_day` | number | `25` | the day HR is reminded to generate the run |

**Document numbering.** Every number above is allocated by
`App\Services\Finance\DocumentNumberService::next(string $prefixKey, string $counterKey, string $pad = '%06d'): string`,
which takes `SELECT ... FOR UPDATE` on the counter's `settings` row **inside the caller's transaction**,
increments it and returns `prefix . sprintf(pad, value)`. The UNIQUE index on each number column is the
backstop: a 1062 triggers exactly one retry. **Every HR caller passes `'%05d'` explicitly** - the class
default is `'%06d'` and no caller relies on it.

**[D-HR-14] Who ships the shared artefacts.** **Phase 5 ships `DocumentNumberService`** (the earliest
consumer; decision **D27**) with exactly that signature and locking behaviour, and **Phase 7 reuses it
unchanged** - it creates no second numbering class, no second `FOR UPDATE` counter and no local fallback.
Phase 7 **does** declare `App\Enums\LedgerEntryType` and `App\Enums\PaymentMethod` (§3), because it is the
first phase to migrate a table that casts them; the financial spine §3 defines their cases and every later
money phase reuses them (§13).

---

## 6. Services

Namespace `App\Services\Hr\` for everything in this phase. The shared
`App\Services\Finance\DocumentNumberService` of §5 is **Phase 5's class, called not created** ([D-HR-14],
D27). House rules, without exception:

- Every write method runs in **one** `DB::transaction()` with the lock order of HR-22 and dispatches its
  events through `DB::afterCommit()`.
- Every money and day figure is a **decimal string** passed through `App\Support\Money` (bcmath,
  intermediate scale 6, final half-up at 2). Minute figures are integers (HR-3, HR-12).
- Controllers orchestrate, Form Requests validate, Policies authorise, Services decide. A controller
  never computes a salary figure, and a Blade view never sums a money column.

### 6.1 Support classes

| Class | Responsibility |
|---|---|
| `App\Services\Hr\WorkCalendarService` | The **only** answer to "what kind of day is this?": `dayTypeFor(Employee, CarbonInterface): DayType`, `shiftFor(Employee, CarbonInterface): WorkShift`, `expectedWindow(Employee, CarbonInterface): ShiftWindow` (expected in/out datetimes + grace + break + expected minutes), `workingDaysBetween(Employee, $from, $to): string`, `holidaysBetween(?Branch, $from, $to): Collection`. Everything else - attendance, leave day expansion, payroll divisors, the calendar screen - calls it, so weekends and holidays can never disagree between two screens. |
| `App\Services\Hr\EmployeeScopeResolver` | `visibility(User): EmployeeVisibility` - `all`, `team(array $employeeIds)` or `own(int $employeeId)` or `none`, per §9. Every HR list query starts from it. |
| `App\Support\Hr\PayslipDraft` / `PayslipLine` | Immutable value objects returned by `PayrollCalculator`: the lines, the totals, the snapshot. Nothing but `PayrollRunService` may persist one. |
| `App\Exceptions\Hr\*` | `LockedAttendanceException`, `LockedPayrollException`, `ImmutablePayrollAttributeException`, `ImmutableSalaryStructureException`, `AppendOnlyRowException`, `InsufficientLeaveBalanceException`, `OverlappingLeaveException` - each carries a message a user can act on, not a stack trace. |

### 6.2 Service contracts

Each signature states what it **guarantees**. Every method is idempotent under replay unless stated.

| Service / method | Guarantees | Events |
|---|---|---|
| **`EmployeeService`** | | |
| `create(EmployeeData, ?UserData): Employee` | a unique `employee_code` under concurrency; `user_id` unique or null (D2); when `UserData` is given the `users` row is created in the **same transaction** with `must_change_password = true` and the role the caller chose, never a hardcoded role; the department's `employee_count` cache recomputed | `EmployeeCreated` |
| `update(Employee, EmployeeData): Employee` | `employee_code` and `user_id` are never changed by this path; a department / designation / shift / reporting-line change needs `employees.assign` and writes old and new values to the audit log | `EmployeeUpdated` |
| `changeStatus(Employee, EmployeeStatus, string $reason): Employee` | only the transitions of §2.26; reason mandatory; `status_changed_at` stamped; the linked `users.status` follows for `suspended` / `inactive` / exits | `EmployeeStatusChanged` |
| `exit(Employee, CarbonInterface $exitDate, EmployeeStatus, string $reason): Employee` | the full settlement sequence of §2.26; refuses an `exit_date` before `joining_date`; never deletes anything | `EmployeeExited` |
| `linkUser(Employee, User): Employee` / `unlinkUser(Employee): Employee` | refuses a `user_id` already linked to another employee, naming it; unlinking never deletes the login | `EmployeeUserLinked` |
| `syncSkills(Employee, array $skills): void` | trimmed, case-folded uniqueness per employee; removing a skill soft-deletes the row | - |
| `uploadDocument(Employee, DocumentData, UploadedFile): EmployeeDocument` | MIME **and** extension validated server-side against `security.allowed_file_types`, size against `security.max_upload_mb`, a disguised `.php` refused; stored on the **private** disk with a hashed name; `expires_on >= issued_on` | `EmployeeDocumentUploaded` |
| `downloadDocument(EmployeeDocument, User): StreamedResponse` | a streamed private-disk response (never a public URL); writes an activity row naming the document, the actor and the IP **before** streaming | - |
| **`DepartmentService`** | | |
| `store` / `update` / `toggle` | `code` uppercased and unique; `delete` refused while any live employee references it, naming the count | `DepartmentSaved` |
| `setHead(Department, ?Employee, string $reason): Department` | the head must be an active employee; a head from another department is allowed only with `departments.assign` and the reason recorded; old and new head written to the audit log | `DepartmentHeadChanged` |
| **`HolidayService`** | | |
| `createRange(HolidayData, $from, $to): Collection` | one row per date ([D-HR-6]); a 1062 on `holiday_guard` reports the existing holiday instead of crashing | `HolidayCalendarChanged` |
| `copyYear(int $fromYear, int $toYear): int` | copies only `is_recurring_yearly` rows; idempotent - an existing date is skipped, never duplicated | - |
| `destroy(Holiday, string $reason)` | refused when any attendance row for that date is locked; otherwise queues `RecomputeAttendanceDate` for every affected employee and each change writes a correction row | `HolidayCalendarChanged` |
| **`AttendanceService`** | | |
| `checkIn(Employee, CheckInData): Attendance` | **idempotent**: the first punch of the day wins and is never overwritten; creates the row with the full shift snapshot (HR-2); refuses a locked date (`LockedAttendanceException`); refuses when `hr.self_check_in_enabled` is off or the IP is outside `hr.self_check_in_ip_whitelist` (refusal logged with the IP); stores source and IP | `AttendanceCheckedIn` |
| `checkOut(Employee, CheckOutData): Attendance` | refuses without a check-in; `check_out_at >= check_in_at`; a later punch moves `check_out_at` forward only; recomputes the row and clears `requires_correction` | `AttendanceCheckedOut` |
| `resolve(Attendance): Attendance` | the §6.3 algorithm, pure and deterministic; **never touches a row with `is_manual = true` or `locked_at` set**; rewrites status, minutes and `payable_factor` only | - |
| `recomputeDate(CarbonInterface $date, ?Collection $employees): int` | re-resolves a date after a calendar, shift or leave change; each changed row writes a `holiday_recalculation` / `leave_regularisation` correction row with old and new values | - |
| `closeDay(CarbonInterface $date): DayCloseReport` | creates the missing row for **every** payroll-eligible employee for that date: `holiday` on a non-working day, `absent` on a working day with no punch (when `hr.auto_absent_enabled`), `requires_correction = true` when a check-in has no check-out; skips `is_attendance_exempt` employees (factor 1.0000); idempotent - running it twice changes nothing | `AttendanceDayClosed` |
| `import(UploadedFile, ImportOptions): ImportReport` | a **dry-run first** (row-by-row diff and error list, nothing written), then an all-or-nothing transaction per chunk; never overwrites a locked or manual row; every created or changed row carries `source = import` and a correction row when it changed an existing value | `AttendanceImported` |
| **`AttendanceCorrectionService`** | | |
| `request(Employee, CorrectionData, User): AttendanceCorrection` | `source = self_request`, status `pending`; refuses a date older than `hr.attendance_correction_window_days`, a locked date, and a second pending request for the same date; `reason` mandatory | `AttendanceCorrectionRequested` |
| `applyDirect(Attendance|DateContext, CorrectionData, User): AttendanceCorrection` | needs `attendance.edit`; writes an `hr_direct` row already `approved` **and** applies it in the same transaction, so both paths leave identical evidence (HR-6) | `AttendanceCorrectionApproved` |
| `approve(AttendanceCorrection, User): AttendanceCorrection` | needs `attendance.approve`; writes `new_values` onto the row (whitelisted keys only), sets `is_manual = true`, stamps `applied_at`, queues the month's summary rebuild; refuses when the date became locked since the request | `AttendanceCorrectionApproved` |
| `reject(AttendanceCorrection, string $comment, User)` | comment mandatory; the attendance row is untouched | `AttendanceCorrectionRejected` |
| **`AttendanceSummaryService`** | | |
| `build(Employee, int $year, int $month): AttendanceMonthlySummary` | the one definition of every monthly figure (§6.4); `payable_days = SUM(payable_factor)`, `lop_days = SUM(1 - payable_factor)` over working rows; idempotent upsert; **refuses a period with `locked_at` set**, naming the run | `AttendanceSummaryBuilt` |
| `buildPeriod(int $year, int $month, ?Branch): int` | chunked over employees, one job per 100 | - |
| `markFinal(Employee, $year, $month)` | sets `is_final` once every date in the period has a resolved row | - |
| **`LeaveBalanceService`** | | |
| `grantYear(int $leaveYear, ?Employee): int` | one `annual_grant` (or `joining_proration`) credit per (employee, type, year); idempotent - a second run writes nothing; pro-rates from `joining_date` when `accrue_from_joining` | `LeaveYearGranted` |
| `accrueMonth(int $year, int $month): int` | one `monthly_accrual` credit per (employee, type, month), keyed by `occurred_on`; idempotent | - |
| `carryForward(int $fromYear, int $toYear): int` | `min(remaining, max_carry_forward_days)` credited as `carry_forward_in`, the lapse recorded as `year_end_lapse`; idempotent per (employee, type) | `LeaveCarriedForward` |
| `reserve(LeaveRequest): void` | under the balance row lock: refuses when `available_days < total_days` unless the type or `hr.leave_negative_balance_allowed` permits it, and the exception names the exact shortfall (HR-9); writes a `reservation` debit | - |
| `consume(LeaveRequest): void` | releases the reservation and writes `leave_consumed` in the same transaction, so no moment exists where the days are counted twice | - |
| `release(LeaveRequest, LeaveLedgerReason): void` | the inverse of `reserve`/`consume`, used by reject and cancel | - |
| `adjust(Employee, LeaveType, string $signedDays, string $note, User): LeaveBalanceTransaction` | needs `leave_balances.create`; note mandatory; an append-only row, never an UPDATE of a balance column | `LeaveBalanceAdjusted` |
| `available(Employee, LeaveType, CarbonInterface $on): string` | a decimal string, derived from the ledger, never from a cached column when the caller passes `fresh: true` | - |
| `assertConsistent(Employee, ?LeaveType): void` | throws when a cached column differs from `SUM(signed_days)` (HR-7); the test helper `assertLeaveBalanceMatchesLedger()` calls it | - |
| **`LeaveRequestService`** | | |
| `apply(Employee, LeaveRequestData, ?UploadedFile): LeaveRequest` | a unique `request_number`; the day expansion of §6.5.1; `total_days > 0` or the application is refused with the reason ("every day in the range is a holiday"); the overlap guard of HR-8 surfaces as `OverlappingLeaveException` naming the existing request; attachment rules enforced; balance **reserved**; the chain of §6.5.3 created; refuses a range that touches a **locked** attendance date | `LeaveRequested` |
| `approveLevel(LeaveRequest, User, ?string $comment): LeaveRequest` | only the acting level; level 2 refused before level 1; self-approval refused (§2.17); the final approval consumes the balance and calls `applyToAttendance()` in the same transaction | `LeaveApproved` |
| `reject(LeaveRequest, User, string $reason)` | reason mandatory; reservation released; remaining levels `skipped`; no attendance row is touched | `LeaveRejected` |
| `cancel(LeaveRequest, User, string $reason)` | reason mandatory; refuses when any touched attendance date is locked (HR-18); credits the balance back and re-resolves each affected attendance date | `LeaveCancelled` |
| `applyToAttendance(LeaveRequest): int` | writes the leave onto each counted day (§6.5.2) and stamps `attendance_applied_at`; idempotent | - |
| **`SalaryComponentService`** | `store` / `update` / `toggle`: `side` always written from `component_group->side()`; a system component's `code`, `component_group` and `side` are immutable; a component in use cannot be deleted | - |
| **`SalaryStructureService`** | | |
| `createVersion(Employee, StructureData, string $reason): SalaryStructure` | **never UPDATEs a rate** (HR-10): closes the open version at `effective_from - 1 day`, inserts `version + 1` with `supersedes_id`, refuses an `effective_from` that would strand a locked payroll item, refuses an overlap; computes `gross_salary` and `total_deduction_amount` with `Money` from the stored lines; updates `employees.current_gross_salary` (HR-11); reason mandatory | `SalaryStructureVersioned` |
| `effectiveOn(Employee, CarbonInterface $date): ?SalaryStructure` | zero or one version resolved by the **period end date**, never `now()` - this is what payroll calls | - |
| `cancel(SalaryStructure, string $reason)` | allowed only while `scheduled` and never referenced by an item | `SalaryStructureCancelled` |
| **`AdvanceService`** | | |
| `request(Employee, AdvanceData): EmployeeAdvance` | unique `advance_number`; `amount > 0`; an amount above `hr.advance_max_multiple_of_basic * basic` requires `employee_advances.approve` to file and records the justification; `installment_amount` computed with `Money::div`, residual on the last installment | `AdvanceRequested` |
| `approve` / `reject` / `cancel` | only the transitions of §2.26; rejection reason mandatory | `AdvanceApproved` / `AdvanceRejected` |
| `disburse(EmployeeAdvance, DisbursementData): EmployeeAdvance` | stamps `disbursed_on`, method and reference; from here `amount` is immutable | `AdvanceDisbursed` |
| `recordRecovery(EmployeeAdvance, string $amount, ?PayrollRunItem, AdvanceRecoveryType, ?string $note): EmployeeAdvanceRepayment` | append-only; under the advance row lock, a conditional `UPDATE ... WHERE recovered_amount + waived_amount + :x <= amount` makes over-recovery impossible (HR-19); one payroll recovery per advance per slip (unique index); recomputes the three caches; stamps `settled_at` at zero | `AdvanceRecovered` / `AdvanceSettled` |
| `waive(EmployeeAdvance, string $amount, string $reason, User)` | needs `employee_advances.approve`; posts a `waiver` row with a mandatory reason; never edits `amount` | `AdvanceWaived` |
| `dueFor(Employee, int $year, int $month): Collection` | the installments payroll should recover this period - the **only** definition, so payroll and the advances screen can never disagree | - |
| **`PayrollCalculator`** | `build(Employee, PayrollPeriod, AttendanceMonthlySummary, SalaryStructure, PayrollInputs): PayslipDraft` - a **pure function**: no writes, no events, no `now()`. Runs §6.6 exactly; identical inputs always produce identical lines, which is what makes FT-HR-44 possible | - |
| **`PayrollRunService`** | | |
| `create(PayrollRunData): PayrollRun` | unique `run_number`; the regular-slot guard refuses a second live regular run for a branch and month, naming the existing one; snapshots `day_basis`, `lop_basis`, `tax_mode` and every influencing `hr.*` key | `PayrollRunCreated` |
| `generate(PayrollRun, ?array $employeeIds): GenerationReport` | only while `draft` / `generated`; builds or refuses on a missing attendance summary (never silently assumes full attendance); deletes and rewrites draft items (that is what "regenerate" means); one item per employee (unique index); `payroll.create`; employees without an effective structure, or exited before the period, are **skipped with a named reason**, never paid zero | `PayrollRunGenerated` |
| `preview(PayrollRun, Employee): PayslipDraft` | read-only: the same calculator the generator uses, so the preview can never differ from the result | - |
| `lock(PayrollRun, User): PayrollRun` | `payroll.approve`; asserts HR-13 for every item and throws listing any item whose stored lines do not sum to its totals; stamps `locked_at`/`locked_by`; locks the period's attendance rows and summaries (HR-18); from here every item is immutable (HR-15) | `PayrollRunLocked` |
| `markItemPaid(PayrollRunItem, PaymentData, User): PayrollRunItem` | only from `locked`; requires `payment_method`; `payment_reference` mandatory for every method except `cash`; stamps `paid_at`/`paid_by`; posts the advance recovery rows of this slip (§6.6 step 7) if not already posted; recomputes the run's `total_paid` and status | `PayrollItemPaid` / `PayrollRunPaid` |
| `holdItem(PayrollRunItem, string $reason, User)` | `hold_reason` mandatory; a held item is excluded from the run's "fully paid" test and is named on the run screen | `PayrollItemHeld` |
| `cancel(PayrollRun, string $reason, User)` | only from `draft` / `generated`; reason mandatory | `PayrollRunCancelled` |
| `issueCorrection(PayrollRunItem $original, array $lines, string $reason, User): PayrollRunItem` | §6.8: finds or creates the `correction` run for that period, inserts a **new** item with `corrects_item_id`, components that may be negative, a net that may be negative, and a mandatory reason; **the original row is never touched**; an advance recovery reversed this way posts a `credit` repayment row | `PayrollCorrectionIssued` |
| **`PayslipService`** | `render(PayrollRunItem): View` - the printable slip of §8.17 from stored component rows only (never a recomputation); `export(PayrollRun, string $format)` - the payroll register as CSV. PDF waits for dompdf in Phase 13 ([D-HR-16], §13) | - |

### 6.3 Attendance resolution - the seven states, computed deterministically

`AttendanceService::resolve()` runs these steps **in order** and stops at the first that decides. It is
pure: the same row, leave data and calendar always produce the same result.

| Step | Condition | Result |
|---|---|---|
| 0 | `locked_at` is set | throw `LockedAttendanceException` - nothing is recomputed behind a locked payroll (HR-18) |
| 1 | `is_manual = true` | **stop.** A correction decided this row; `status` and `payable_factor` stay as the correction set them |
| 2 | `employee.is_attendance_exempt` | `day_type` resolved for reporting, `status = present`, `payable_factor = 1.0000`, late and early-leave minutes forced to 0 |
| 3 | `WorkCalendarService::dayTypeFor()` returns `public_holiday` | `status = holiday`, `holiday_id` set, `payable_factor = holiday.is_paid ? 1.0000 : 0.0000`, late/early 0. A punch on this day still records `worked_minutes` and `overtime_minutes = worked_minutes` (measured, not paid) |
| 4 | it returns `weekly_off` | `status = holiday`, `day_type = weekly_off`, `payable_factor = 1.0000`; `holiday_id` is still set if a holiday row also matched, so the report can say "the holiday fell on the weekend" |
| 5 | an **active, counted** `leave_request_days` row covers the date with `day_portion = full_day` | `status = on_leave`, `leave_request_id` / `leave_type_id` set, `payable_factor = is_paid ? 1.0000 : 0.0000` |
| 6 | a half-day leave row covers the date | `status = half_day`, `payable_factor = (is_paid ? 0.5000 : 0.0000) + (the working half is evaluated from punches: 0.5000 when it was worked, else 0.0000)` |
| 7 | `check_in_at` is null | `status = absent`, `payable_factor = 0.0000` |
| 8 | otherwise - compute from the snapshot | `late_minutes = max(0, check_in_at - (expected_in_at + grace_in_minutes))`; `early_leave_minutes = max(0, (expected_out_at - grace_out_minutes) - check_out_at)`, or `0` while `check_out_at` is null; `worked_minutes = check_out_at ? (check_out_at - check_in_at - break_minutes) : 0`; `overtime_minutes = max(0, worked_minutes - expected_minutes)` |
| 9 | `check_out_at` is null and `expected_out_at` has passed | `requires_correction = true`, `worked_minutes = 0`, `status = half_day`, `payable_factor = 0.5000` - a forgotten punch-out never silently pays a full day and never silently pays nothing; the employee and HR are both notified |
| 10 | `worked_minutes = 0` | `status = absent`, `payable_factor = 0.0000` |
| 11 | `worked_minutes < min_half_day_minutes` | `status = half_day`, `payable_factor = 0.5000` |
| 12 | `worked_minutes < min_full_day_minutes` | `payable_factor = hr.short_day_as_half_day ? 0.5000 : 1.0000`; `status = late` when `late_minutes > 0`, else `early_leave` when `early_leave_minutes > 0`, else `half_day` when the factor was halved, else `present` |
| 13 | `worked_minutes >= min_full_day_minutes` | `payable_factor = 1.0000`; `status = late` when `late_minutes > 0`, else `early_leave` when `early_leave_minutes > 0`, else `present` |

**Status precedence** when a day is both late and an early leave: `late` wins as the *status*; both minute
columns are always stored, so no report loses the early leave. **Night shifts**: the row belongs to the
shift's **start** date, `expected_out_at` is on the following day, and a 02:00 punch-out is that row's
check-out - never a new row for the next date. Timestamps are stored in the app timezone
(`Asia/Karachi`, which has no DST), so a shift window never shifts under a row.

### 6.4 How attendance feeds payroll - the only bridge

```
attendances.payable_factor            (per day, 0.0000-1.0000, HR-4)
        |  SUM over the period
        v
attendance_monthly_summaries          payable_days, lop_days, working_days, present_days,
        |                             paid/unpaid leave days, late_count   (HR-5)
        |  one row, snapshotted by id onto the slip
        v
payroll_run_items                     payable_days, lop_days, day_divisor, per_day_amount
        |  per_day_amount x lop_days
        v
payroll_run_item_components           one UNPAID_LEAVE deduction line, plus an optional LATE line
```

| Rule | Statement |
|---|---|
| R1 | `payable_days = SUM(payable_factor)` over **every** row in the period (working days, weekly offs and paid holidays all contribute), so a full month of attendance equals the calendar days. |
| R2 | `lop_days = SUM(1 - payable_factor)` over rows whose `day_type = working` only. A weekend is never a loss of pay. |
| R3 | Payroll reads the summary **by id** and snapshots its figures onto the item. A later rebuild of the summary can never silently change a generated slip. |
| R4 | A missing summary is a **hard stop**: generation reports "no attendance summary for March 2026" per employee and writes no item. Full attendance is never assumed. |
| R5 | Locking a run locks the rows **and** the summaries of the period (HR-18), so the arithmetic behind a paid slip can never be edited afterwards. |

### 6.5 Leave mechanics

**6.5.1 Day expansion (`LeaveRequestService::apply()`).** For each date from `from_date` to `to_date`:
resolve `WorkCalendarService::dayTypeFor()`; when the day is `weekly_off` and `leave_type.excludes_weekends`
is true, or `public_holiday` and `excludes_holidays` is true, insert the row with `is_counted = false`
(audit trail of why 5 calendar days cost 3 quota days) and move on; otherwise insert `is_counted = true`
with `day_fraction` from `day_portion`. `total_days = SUM(day_fraction WHERE is_counted)`, computed by
`Money`. `max_consecutive_days` is checked against counted days. A range whose counted total is zero is
refused with that exact reason.

**6.5.2 Effect on attendance.** Only on **final approval**, and only for counted days: for each day,
`Attendance::firstOrCreate` for (employee, date) with the shift snapshot, then set `leave_request_id`,
`leave_type_id` and re-run `resolve()` (steps 5-6). A date whose row is `locked` makes the whole approval
fail before anything is written. Cancelling an approved leave sets `leave_request_days.is_active = false`
(releasing the guard), clears the leave columns and re-runs `resolve()` for each date.

**6.5.3 The approval chain.** Built at apply time from data, with no flow-definition table:

| Level | Expected approver | Fallback |
|---|---|---|
| 1 | `employee.reports_to` (its `user_id`, when set) | `fallback_permission = leaves.approve` - any holder may act |
| 2 (only when `leave_type.approval_levels = 2`) | the head of the employee's department, when it is a different person from level 1 | `leaves.approve` |

Rules: level 2 cannot act before level 1 is `approved`; a rejection at any level rejects the request and
marks later levels `skipped`; an employee can never approve their own request - when the resolved approver
is the requester, that level falls through to the fallback permission; the inbox of §8.13 lists exactly
the rows where `status = pending` and (`expected_approver_user_id = me` OR I hold the fallback
permission and the request is inside my §9 scope).

**6.5.4 Balance arithmetic.** Reservation on apply, conversion on approval, release on reject/cancel - all
three as ledger rows (§2.14), never as a direct balance edit. `available_days` is recomputed inside the
same transaction and `balance_after_days` snapshotted, so the employee's leave statement reads like a bank
statement and HR-7 is checkable at any moment.

### 6.6 The payroll algorithm - `PayrollCalculator::build()`

Written so two developers produce identical figures to the paisa. All arithmetic is `App\Support\Money`
(bcmath, **intermediate scale 6, final quantisation half-up at 2 decimals**). Every step that produces a
line **stores that line**; no total is ever recomputed from the inputs (HR-13).

| Step | Action |
|---|---|
| 0 | **Resolve inputs.** `SalaryStructureService::effectiveOn($employee, $period->end)` - a missing structure is a skip with the reason `no_salary_structure`. `AttendanceMonthlySummary` for (employee, year, month) - a missing summary is a skip with the reason `no_attendance_summary` (R4). An employee whose `status->isPayrollEligible()` is false, or whose `exit_date < period_start`, is skipped with that reason. Everything from here is read from the **snapshot**, never from the live employee row. |
| 1 | **Basic.** Line `BASIC`, group `basic`, `amount = structure.basic_salary`. |
| 2 | **Pass-1 earnings.** For each structure line with `side = earning` and `calculation->pass() = 1`: `fixed` -> `amount`; `percentage_of_basic` -> `Money::percentage(basic, rate)`; `per_day` -> `Money::mul(amount, summary.payable_days)`. Each result quantised to 2 and stored with its `calculation_note`. |
| 3 | **Pass-2 earnings.** `percentage_of_gross` lines are computed against the sum of the stored pass-1 earnings (`gross_before_pass_2`). A `percentage_of_gross` line may never reference another `percentage_of_gross` line - the Form Request refuses such a component set, so there is no circularity to resolve. |
| 4 | **Attendance proration.** `day_divisor` = `calendar_days` (default) \| `summary.working_days` \| `30`, per the run's snapshotted `hr.payroll_day_basis`; a divisor of zero is a skip with the reason `zero_day_divisor`. `prorate_base` = `basic_salary` or `contracted_gross`, per `hr.lop_basis`. `per_day_amount = Money::div(prorate_base, day_divisor)`. When `hr.unpaid_leave_deduction_enabled` and `summary.lop_days > 0`: store a deduction line `UNPAID_LEAVE`, group `unpaid_leave`, `quantity = lop_days`, `base_amount = per_day_amount`, `amount = Money::mul(per_day_amount, lop_days)`, note "34,000.00 / 31 x 2.5000 days". |
| 5 | **Late deduction.** Only when `hr.late_deduction_lates_per_day > 0`: `late_days = floor(summary.late_count / N)`; store a `LATE` deduction line of `Money::mul(per_day_amount, late_days)`. Default `0` means no line is ever created. |
| 6 | **Run inputs.** Bonus, commission, overtime and reimbursement lines entered for this run (one typed line per component, `payroll.create` required, each with an optional `source_type`/`source_id` and a note). §28's four named earnings therefore all exist as typed components rather than as loose columns. |
| 7 | **Structure deductions and tax.** Each `side = deduction` structure line is computed like step 2. `taxable_gross = SUM(stored earning lines where is_taxable)`. Tax per the run's snapshotted `hr.tax_mode`: `none` -> no line; `fixed_percentage` -> `Money::percentage(taxable_gross, hr.tax_default_rate)`; `manual` -> whatever the `TAX` component holds for this employee or this run. Stored as a `tax` group line. |
| 8 | **Advance recovery.** `AdvanceService::dueFor()` gives the installments due this period. `net_before_recovery = SUM(earnings) - SUM(deductions so far)`. `cap = Money::percentage(net_before_recovery, hr.advance_recovery_cap_percent)`. For each advance in ascending `id`: `take = Money::min(installment_amount, outstanding, remaining_cap)`; when `take > 0` store an `ADV_RECOVERY` deduction line with `source_type = employee_advance`, `source_id`. **The unrecovered remainder stays on the advance** - never written off, never silently dropped. The `employee_advance_repayments` row is written when the slip is **paid** (`markItemPaid`), so an unpaid slip never reduces an advance. |
| 9 | **Totals.** `gross_earnings = SUM(stored earning lines)`; `total_deductions = SUM(stored deduction lines)`; `net_salary = Money::sub(gross_earnings, total_deductions)`. If `net_salary < 0` on a **regular** run: reduce the `ADV_RECOVERY` line by the shortfall and re-total; if it is still negative, drop the recovery line entirely and re-total; if it is *still* negative, the item is stored `on_hold` with `hold_reason` naming the offending deductions and is excluded from payment until HR acts (HR-14). A negative net is stored only on a `correction` run. |
| 10 | **Business rounding.** When `hr.payroll_net_rounding <> none`, compute the rounded net and store the difference as a visible `ROUNDING` line (earning or deduction as the sign requires), so `gross - deductions = net` still holds exactly (HR-13). |
| 11 | **Reporting handles.** Each `payroll_run_items` money handle (`allowance_amount`, `bonus_amount`, `commission_amount`, `overtime_amount`, `tax_amount`, `advance_recovery_amount`, `unpaid_leave_deduction`, `late_deduction`) is written as the **sum of its component group**, so a report never has to know the component codes and can never disagree with the slip. |
| 12 | **Snapshot.** `calculation_snapshot` stores the structure id + version, the summary id and its figures, the divisor, the per-day amount, the tax mode and rate, the advance decisions with their caps, and every setting that influenced a step - so the slip can be explained years later without re-deriving anything. |

**Worked example** (the numbers a test asserts, FT-HR-33): basic 40,000.00; house allowance 10% of basic
= 4,000.00; fuel allowance fixed 5,000.00; contracted gross 49,000.00. March = 31 calendar days,
`day_basis = calendar_days`, `lop_basis = gross` -> `per_day_amount = 49,000.00 / 31 = 1,580.645161` ->
stored **1,580.65**. `lop_days = 2.5000` (two absents and one unpaid half day) -> `UNPAID_LEAVE` =
1,580.65 x 2.5000 = 3,951.625 -> stored **3,951.63**. Tax manual 1,200.00. Advance installment 5,000.00
with a 50% cap of net-before-recovery (49,000.00 - 3,951.63 - 1,200.00 = 43,848.37 -> cap 21,924.19) ->
the full 5,000.00 is taken. `gross_earnings = 49,000.00`; `total_deductions = 10,151.63`;
`net_salary = 38,848.37`.

### 6.7 Lock and immutability - how a run becomes untouchable

| Moment | What becomes immutable |
|---|---|
| `generate()` | nothing - draft items exist to be regenerated |
| `lock()` (`payroll.approve`) | every money column, every snapshot and every component row of every item (HR-15, model hook); every `attendances` row and `attendance_monthly_summaries` row in the period (HR-18); the run's period, branch, basis snapshots and totals. Deletion of an item or a component now throws at the model **and** at the trigger (HR-16). Mutable from here: item `status`, `hold_reason`, `paid_at`, `payment_method`, `payment_reference`, `paid_by`, `notes`, `updated_*` |
| `markItemPaid()` | that item's payment columns too; only `notes` may still change |
| `paid` (whole run) | the run's `paid_at`; the run can never be cancelled, unlocked or regenerated |

There is **no unlock action** ([D-HR-6]). An honest mistake discovered after locking is fixed by §6.8.

### 6.8 Correction - never an edit

```
PayrollRun #12  (regular, March 2026, locked, paid)
  PayrollRunItem #340  SLP-00340  Ali  net 38,848.37     <- untouched, for ever
        ^ corrects_item_id
PayrollRun #19  (correction, March 2026, parent_run_id = 12)
  PayrollRunItem #402  SLP-00402  Ali  net  +2,000.00    <- the missed fuel allowance
  PayrollRunItem #403  SLP-00403  Sara net  -1,500.00    <- an overpaid bonus, recovered
```

Rules: a correction run carries `run_type = correction` and `parent_run_id`; it does not occupy the
regular monthly slot, so any number of corrections may exist for one month; its items and components may
be negative (`CHECK` keyed on the denormalised `run_type`); every correction item carries
`corrects_item_id` and a mandatory reason in the activity log; an advance recovery being undone posts a
`credit` repayment row rather than editing the original; a correction run itself is generated, locked and
paid through the same lifecycle, so the correction is as auditable as the thing it corrects. Reporting
always sums `regular + correction` items for a period, which is why no report ever needs to know a slip
was wrong.

### 6.9 Employee commission is not collaborator commission

§28 lists "commission" as a payroll earning. That is an **employee** earning - a sales incentive paid
through a slip. It is a typed `commission` component entered per run (step 6), with `source_type` /
`source_id` left available so a future phase can link it to whatever produced it. Three rules keep the two
worlds apart:

1. Nothing in this phase computes an employee commission automatically. There is no rule engine, no
   percentage of revenue, no trigger - a figure is entered by a holder of `payroll.create` and audited.
2. The collaborator commission engine (the financial spine, phases 10-12) **never posts into payroll**,
   and payroll never reads the commission ledger or a wallet. A collaborator is an external partner paid
   by a payout, not an employee paid by a slip.
3. If the client later wants a sales-commission engine for employees, it arrives as its own phase that
   writes `payroll_run_item_components` rows with `source_type = sales_commission` - additive, and still
   subject to lock and correction.

### 6.10 Edge-case decision table

| # | Situation | Decision |
|---|---|---|
| 1 | Employee joins on the 10th | Attendance rows exist only from `joining_date`; the summary's `working_days` counts only dates `>= joining_date`; `lop_days` therefore cannot punish the first nine days |
| 2 | Employee exits mid-month | The final regular run includes them: `working_days` stops at `exit_date`; outstanding advances are listed on the final-settlement screen and are **not** auto-deducted beyond the cap |
| 3 | A holiday is declared after attendance was marked absent | `recomputeDate()` re-resolves each affected row and writes a `holiday_recalculation` correction row per change; refused for locked dates |
| 4 | Leave approved after the month is locked | Approval is refused naming the locked period; the remedy is a correction item (§6.8) |
| 5 | Shift changed mid-month | Future rows use the new window; past rows keep their snapshot (HR-2) |
| 6 | Two check-ins in one day | The first wins; the second returns the same row and is logged as a duplicate punch, never a second row (HR-1) |
| 7 | Check-out on the next calendar day (night shift) | Belongs to the shift-start row; a punch-out more than 18 hours after check-in is refused and raises `requires_correction` |
| 8 | Employee has no shift and no branch default | `WorkCalendarService` falls back to `hr.*` defaults and the row records `work_shift_id = null`; the employee screen shows a "no shift assigned" warning because late minutes are meaningless without one |
| 9 | `is_attendance_exempt` employee | Always `payable_factor = 1.0000`, never absent, never late; the flag is shown on every HR screen so it can never be a silent pay decision |
| 10 | Quota exceeded but business wants to allow it | Either the type's `allow_negative_balance`, or the days are approved as **unpaid** (`unpaid_days`), which flows into `lop_days` and is therefore deducted honestly |
| 11 | Half day + half-day leave on one date | One leave day row, `payable_factor = 0.5000` from the leave plus 0.5000 only if the working half was actually worked (§6.3 step 6) |
| 12 | A second regular run for the same month | Refused by `uq_pr_regular`, naming the existing run; the correct act is a correction run |
| 13 | Generating a run for 400 employees | Chunked jobs of 100 with `ShouldBeUnique` per run; the run screen shows progress; a failed chunk is retried and items are idempotent per (run, employee) |
| 14 | A component is retired between two runs | Past slips keep their snapshot (`component_code`, `component_name`, `side`); the retired component simply stops appearing in new structures |
| 15 | Someone disables the `payroll` module mid-month | Every payroll route 403s for everyone including Super Admin (Phase 1 `Gate::before`); every row, every locked run and every queued job stays intact |
| 16 | An advance bigger than the employee's salary | Allowed with `employee_advances.approve` and a reason; recovery is capped per run by `hr.advance_recovery_cap_percent`, so it simply takes more months |
| 17 | A paid slip's employee is soft-deleted | `restrictOnDelete` refuses the database delete and the policy refuses the act, naming the payroll history |
| 18 | Salary structure back-dated into a locked period | `createVersion()` refuses, naming the locked run; the remedy is a correction item |

---

## 7. Routes

Every route below lives in `routes/admin.php` and therefore already carries `auth`, `active`,
`panel:admin` from Phase 1 §8. `module:*` is stated per group. HR is an **admin-panel** phase: no
collaborator, student, teacher or client route is added, and §9 explains why employee self-service belongs
in `/admin/my/*`.

### 7.1 Employees, departments, designations, documents

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/employees` | `admin.employees.index` | `module:employees`, `can:employees.view_any` |
| GET `/admin/employees/create` | `admin.employees.create` | `can:employees.create` |
| POST `/admin/employees` | `admin.employees.store` | `can:employees.create` |
| GET `/admin/employees/{employee}` | `admin.employees.show` | `can:employees.view` |
| GET `/admin/employees/{employee}/edit` | `admin.employees.edit` | `can:employees.edit` |
| PUT `/admin/employees/{employee}` | `admin.employees.update` | `can:employees.edit` |
| DELETE `/admin/employees/{employee}` | `admin.employees.destroy` | `can:employees.delete` |
| POST `/admin/employees/{employee}/restore` | `admin.employees.restore` | `can:employees.restore` |
| POST `/admin/employees/{employee}/status` | `admin.employees.status` | `can:employees.change_status` |
| POST `/admin/employees/{employee}/exit` | `admin.employees.exit` | `can:employees.change_status` |
| POST `/admin/employees/{employee}/assignments` | `admin.employees.assignments` | `can:employees.assign` |
| POST `/admin/employees/{employee}/user` | `admin.employees.user.link` | `can:employees.edit`, `can:users.create` |
| DELETE `/admin/employees/{employee}/user` | `admin.employees.user.unlink` | `can:employees.edit` |
| GET `/admin/employees/export/{format}` | `admin.employees.export` | `can:employees.export` |
| GET `/admin/employees/import` | `admin.employees.import.form` | `can:employees.import` |
| POST `/admin/employees/import` | `admin.employees.import.store` | `can:employees.import` |
| GET `/admin/employees/{employee}/print` | `admin.employees.print` | `can:employees.print` |
| GET `/admin/departments` | `admin.departments.index` | `module:departments`, `can:departments.view_any` |
| POST `/admin/departments` | `admin.departments.store` | `can:departments.create` |
| PUT `/admin/departments/{department}` | `admin.departments.update` | `can:departments.edit` |
| POST `/admin/departments/{department}/head` | `admin.departments.head` | `can:departments.assign` |
| POST `/admin/departments/{department}/toggle` | `admin.departments.toggle` | `can:departments.change_status` |
| DELETE `/admin/departments/{department}` | `admin.departments.destroy` | `can:departments.delete` |
| GET `/admin/designations` | `admin.designations.index` | `module:designations`, `can:designations.view_any` |
| POST `/admin/designations` | `admin.designations.store` | `can:designations.create` |
| PUT `/admin/designations/{designation}` | `admin.designations.update` | `can:designations.edit` |
| POST `/admin/designations/{designation}/toggle` | `admin.designations.toggle` | `can:designations.change_status` |
| DELETE `/admin/designations/{designation}` | `admin.designations.destroy` | `can:designations.delete` |
| GET `/admin/employees/{employee}/documents` | `admin.employee-documents.index` | `module:employee_documents`, `can:employee_documents.view` |
| POST `/admin/employees/{employee}/documents` | `admin.employee-documents.store` | `can:employee_documents.upload` |
| GET `/admin/employee-documents/expiring` | `admin.employee-documents.expiring` | `can:employee_documents.view_any` |
| GET `/admin/employee-documents/{document}/download` | `admin.employee-documents.download` | `can:employee_documents.download`, `throttle:30,1` |
| POST `/admin/employee-documents/{document}/verify` | `admin.employee-documents.verify` | `can:employee_documents.change_status` |
| DELETE `/admin/employee-documents/{document}` | `admin.employee-documents.destroy` | `can:employee_documents.delete` |

### 7.2 Shifts and holidays

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/work-shifts` | `admin.work-shifts.index` | `module:work_shifts`, `can:work_shifts.view_any` |
| POST `/admin/work-shifts` | `admin.work-shifts.store` | `can:work_shifts.create` |
| PUT `/admin/work-shifts/{shift}` | `admin.work-shifts.update` | `can:work_shifts.edit` |
| POST `/admin/work-shifts/{shift}/toggle` | `admin.work-shifts.toggle` | `can:work_shifts.change_status` |
| POST `/admin/work-shifts/{shift}/assign` | `admin.work-shifts.assign` | `can:work_shifts.assign` |
| DELETE `/admin/work-shifts/{shift}` | `admin.work-shifts.destroy` | `can:work_shifts.delete` |
| GET `/admin/holidays` | `admin.holidays.index` | `module:holidays`, `can:holidays.view_any` |
| GET `/admin/holidays/calendar` | `admin.holidays.calendar` | `can:holidays.view_any` |
| POST `/admin/holidays` | `admin.holidays.store` | `can:holidays.create` |
| PUT `/admin/holidays/{holiday}` | `admin.holidays.update` | `can:holidays.edit` |
| DELETE `/admin/holidays/{holiday}` | `admin.holidays.destroy` | `can:holidays.delete` |
| POST `/admin/holidays/copy-year` | `admin.holidays.copy-year` | `can:holidays.create` |
| POST `/admin/holidays/import` | `admin.holidays.import` | `can:holidays.import` |
| GET `/admin/holidays/export/{format}` | `admin.holidays.export` | `can:holidays.export` |

### 7.3 Attendance and corrections

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/attendance` | `admin.attendance.index` | `module:attendance`, `can:attendance.view_any` |
| GET `/admin/attendance/monthly` | `admin.attendance.monthly` | `can:attendance.view_any` |
| GET `/admin/attendance/{attendance}` | `admin.attendance.show` | `can:attendance.view` |
| POST `/admin/attendance/punch` | `admin.attendance.punch` | `can:attendance.create`, `throttle:60,1` |
| POST `/admin/attendance/mark` | `admin.attendance.mark` | `can:attendance.create` |
| POST `/admin/attendance/{attendance}/recompute` | `admin.attendance.recompute` | `can:attendance.edit` |
| POST `/admin/attendance/{attendance}/corrections` | `admin.attendance.corrections.store` | `can:attendance.edit` |
| GET `/admin/attendance-corrections` | `admin.attendance-corrections.index` | `can:attendance.view_any` |
| POST `/admin/attendance-corrections/{correction}/approve` | `admin.attendance-corrections.approve` | `can:attendance.approve` |
| POST `/admin/attendance-corrections/{correction}/reject` | `admin.attendance-corrections.reject` | `can:attendance.reject` |
| GET `/admin/attendance/import` | `admin.attendance.import.form` | `can:attendance.import` |
| POST `/admin/attendance/import/preview` | `admin.attendance.import.preview` | `can:attendance.import` |
| POST `/admin/attendance/import` | `admin.attendance.import.store` | `can:attendance.import` |
| GET `/admin/attendance/export/{format}` | `admin.attendance.export` | `can:attendance.export` |
| GET `/admin/attendance/summaries` | `admin.attendance-summaries.index` | `can:attendance.view_reports` |
| POST `/admin/attendance/summaries/rebuild` | `admin.attendance-summaries.rebuild` | `can:attendance.change_status` |
| GET `/admin/attendance/reports/monthly` | `admin.attendance.reports.monthly` | `can:attendance.view_reports` |

### 7.4 Leave

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/leave-types` | `admin.leave-types.index` | `module:leave_types`, `can:leave_types.view_any` |
| POST `/admin/leave-types` | `admin.leave-types.store` | `can:leave_types.create` |
| PUT `/admin/leave-types/{leaveType}` | `admin.leave-types.update` | `can:leave_types.edit` |
| POST `/admin/leave-types/{leaveType}/toggle` | `admin.leave-types.toggle` | `can:leave_types.change_status` |
| DELETE `/admin/leave-types/{leaveType}` | `admin.leave-types.destroy` | `can:leave_types.delete` |
| GET `/admin/leave-balances` | `admin.leave-balances.index` | `module:leave_balances`, `can:leave_balances.view_any` |
| GET `/admin/leave-balances/{employee}` | `admin.leave-balances.show` | `can:leave_balances.view` |
| POST `/admin/leave-balances/adjust` | `admin.leave-balances.adjust` | `can:leave_balances.create` |
| POST `/admin/leave-balances/grant-year` | `admin.leave-balances.grant-year` | `can:leave_balances.create` |
| GET `/admin/leave-balances/export/{format}` | `admin.leave-balances.export` | `can:leave_balances.export` |
| GET `/admin/leaves` | `admin.leaves.index` | `module:leaves`, `can:leaves.view_any` |
| GET `/admin/leaves/calendar` | `admin.leaves.calendar` | `can:leaves.view_any` |
| GET `/admin/leaves/create` | `admin.leaves.create` | `can:leaves.create` |
| POST `/admin/leaves` | `admin.leaves.store` | `can:leaves.create` |
| GET `/admin/leaves/{leaveRequest}` | `admin.leaves.show` | `can:leaves.view` |
| PUT `/admin/leaves/{leaveRequest}` | `admin.leaves.update` | `can:leaves.edit` |
| DELETE `/admin/leaves/{leaveRequest}` | `admin.leaves.destroy` | `can:leaves.delete` |
| POST `/admin/leaves/{leaveRequest}/approve` | `admin.leaves.approve` | `can:leaves.approve` |
| POST `/admin/leaves/{leaveRequest}/reject` | `admin.leaves.reject` | `can:leaves.reject` |
| POST `/admin/leaves/{leaveRequest}/cancel` | `admin.leaves.cancel` | `can:leaves.change_status` |
| GET `/admin/leaves/{leaveRequest}/attachment` | `admin.leaves.attachment` | `can:leaves.download`, `throttle:30,1` |
| GET `/admin/leaves/export/{format}` | `admin.leaves.export` | `can:leaves.export` |
| GET `/admin/leaves/reports/balance` | `admin.leaves.reports.balance` | `can:leaves.view_reports` |

### 7.5 Salary structures, components, advances

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/salary-components` | `admin.salary-components.index` | `module:salary_components`, `can:salary_components.view_any` |
| POST `/admin/salary-components` | `admin.salary-components.store` | `can:salary_components.create` |
| PUT `/admin/salary-components/{component}` | `admin.salary-components.update` | `can:salary_components.edit` |
| POST `/admin/salary-components/{component}/toggle` | `admin.salary-components.toggle` | `can:salary_components.change_status` |
| DELETE `/admin/salary-components/{component}` | `admin.salary-components.destroy` | `can:salary_components.delete` |
| GET `/admin/employees/{employee}/salary-structures` | `admin.salary-structures.index` | `module:salary_structures`, `can:salary_structures.view` |
| GET `/admin/employees/{employee}/salary-structures/create` | `admin.salary-structures.create` | `can:salary_structures.create` |
| POST `/admin/employees/{employee}/salary-structures` | `admin.salary-structures.store` | `can:salary_structures.create` |
| POST `/admin/salary-structures/{structure}/approve` | `admin.salary-structures.approve` | `can:salary_structures.approve` |
| POST `/admin/salary-structures/{structure}/cancel` | `admin.salary-structures.cancel` | `can:salary_structures.change_status` |
| GET `/admin/advances` | `admin.advances.index` | `module:employee_advances`, `can:employee_advances.view_any` |
| GET `/admin/advances/create` | `admin.advances.create` | `can:employee_advances.create` |
| POST `/admin/advances` | `admin.advances.store` | `can:employee_advances.create` |
| GET `/admin/advances/{advance}` | `admin.advances.show` | `can:employee_advances.view` |
| POST `/admin/advances/{advance}/approve` | `admin.advances.approve` | `can:employee_advances.approve` |
| POST `/admin/advances/{advance}/reject` | `admin.advances.reject` | `can:employee_advances.reject` |
| POST `/admin/advances/{advance}/disburse` | `admin.advances.disburse` | `can:employee_advances.change_status` |
| POST `/admin/advances/{advance}/recovery` | `admin.advances.recovery` | `can:employee_advances.change_status` |
| POST `/admin/advances/{advance}/waive` | `admin.advances.waive` | `can:employee_advances.approve` |
| POST `/admin/advances/{advance}/cancel` | `admin.advances.cancel` | `can:employee_advances.change_status` |

### 7.6 Payroll and slips

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/payroll` | `admin.payroll-runs.index` | `module:payroll`, `can:payroll.view_any` |
| GET `/admin/payroll/create` | `admin.payroll-runs.create` | `can:payroll.create` |
| POST `/admin/payroll` | `admin.payroll-runs.store` | `can:payroll.create` |
| GET `/admin/payroll/{run}` | `admin.payroll-runs.show` | `can:payroll.view` |
| POST `/admin/payroll/{run}/generate` | `admin.payroll-runs.generate` | `can:payroll.create`, `throttle:6,1` |
| GET `/admin/payroll/{run}/preview/{employee}` | `admin.payroll-runs.preview` | `can:payroll.view_financial` |
| POST `/admin/payroll/{run}/lock` | `admin.payroll-runs.lock` | `can:payroll.approve` |
| POST `/admin/payroll/{run}/pay` | `admin.payroll-runs.pay` | `can:payroll.change_status` |
| POST `/admin/payroll/{run}/cancel` | `admin.payroll-runs.cancel` | `can:payroll.change_status` |
| GET `/admin/payroll/{run}/export/{format}` | `admin.payroll-runs.export` | `can:payroll.export` |
| GET `/admin/payroll/{run}/register/print` | `admin.payroll-runs.register.print` | `can:payroll.print` |
| POST `/admin/payroll/items/{item}/pay` | `admin.payroll-items.pay` | `can:payroll.change_status` |
| POST `/admin/payroll/items/{item}/hold` | `admin.payroll-items.hold` | `can:payroll.change_status` |
| POST `/admin/payroll/items/{item}/release` | `admin.payroll-items.release` | `can:payroll.change_status` |
| POST `/admin/payroll/items/{item}/correction` | `admin.payroll-items.correction` | `can:payroll.create`, `can:payroll.approve` |
| GET `/admin/payslips` | `admin.payslips.index` | `module:salary_slips`, `can:salary_slips.view_any` |
| GET `/admin/payslips/{item}` | `admin.payslips.show` | `can:salary_slips.view` |
| GET `/admin/payslips/{item}/print` | `admin.payslips.print` | `can:salary_slips.print` |
| GET `/admin/payslips/export/{format}` | `admin.payslips.export` | `can:salary_slips.export` |
| GET `/admin/payroll/reports/cost` | `admin.payroll.reports.cost` | `can:payroll.view_reports` |

### 7.7 Employee self-service (`/admin/my/*`)

All carry `module:employee_self_service` plus the gate below; every one additionally resolves
`auth()->user()->employee` and 404s when there is none (§9).

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/my/hr-profile` | `admin.my.profile` | `can:employee_self_service.view` |
| GET `/admin/my/attendance` | `admin.my.attendance.index` | `can:employee_self_service.view` |
| POST `/admin/my/attendance/check-in` | `admin.my.attendance.check-in` | `can:employee_self_service.create`, `throttle:10,1` |
| POST `/admin/my/attendance/check-out` | `admin.my.attendance.check-out` | `can:employee_self_service.create`, `throttle:10,1` |
| POST `/admin/my/attendance/corrections` | `admin.my.attendance.corrections.store` | `can:employee_self_service.create` |
| GET `/admin/my/leave` | `admin.my.leave.index` | `can:employee_self_service.view` |
| GET `/admin/my/leave/create` | `admin.my.leave.create` | `can:employee_self_service.create` |
| POST `/admin/my/leave` | `admin.my.leave.store` | `can:employee_self_service.create` |
| GET `/admin/my/leave/{leaveRequest}` | `admin.my.leave.show` | `can:employee_self_service.view` |
| POST `/admin/my/leave/{leaveRequest}/cancel` | `admin.my.leave.cancel` | `can:employee_self_service.create` |
| GET `/admin/my/payslips` | `admin.my.payslips.index` | `can:employee_self_service.view_financial` |
| GET `/admin/my/payslips/{item}` | `admin.my.payslips.show` | `can:employee_self_service.view_financial` |
| GET `/admin/my/payslips/{item}/print` | `admin.my.payslips.print` | `can:employee_self_service.print` |
| GET `/admin/my/documents` | `admin.my.documents.index` | `can:employee_self_service.view` |
| POST `/admin/my/documents` | `admin.my.documents.store` | `can:employee_self_service.upload` |
| GET `/admin/my/documents/{document}/download` | `admin.my.documents.download` | `can:employee_self_service.download` |
| GET `/admin/my/approvals` | `admin.my.approvals.index` | `can:leaves.approve` |

Controllers: `Admin/Hr/EmployeeController`, `DepartmentController`, `DesignationController`,
`EmployeeDocumentController`, `WorkShiftController`, `HolidayController`, `AttendanceController`,
`AttendanceCorrectionController`, `AttendanceSummaryController`, `LeaveTypeController`,
`LeaveBalanceController`, `LeaveRequestController`, `SalaryComponentController`,
`SalaryStructureController`, `EmployeeAdvanceController`, `PayrollRunController`,
`PayrollRunItemController`, `PayslipController`, and `Admin/Hr/SelfService/{Profile,Attendance,Leave,Payslip,Document,Approval}Controller`.
Form Requests in `app/Http/Requests/Hr/`: `StoreEmployeeRequest`, `UpdateEmployeeRequest`,
`ChangeEmployeeStatusRequest`, `ExitEmployeeRequest`, `StoreEmployeeDocumentRequest`,
`StoreDepartmentRequest`, `StoreDesignationRequest`, `StoreWorkShiftRequest`, `StoreHolidayRequest`,
`PunchAttendanceRequest`, `MarkAttendanceRequest`, `StoreAttendanceCorrectionRequest`,
`ReviewAttendanceCorrectionRequest`, `ImportAttendanceRequest`, `StoreLeaveTypeRequest`,
`StoreLeaveRequestRequest`, `ReviewLeaveRequest`, `AdjustLeaveBalanceRequest`,
`StoreSalaryComponentRequest`, `StoreSalaryStructureRequest`, `StoreAdvanceRequest`,
`DisburseAdvanceRequest`, `StorePayrollRunRequest`, `GeneratePayrollRequest`, `LockPayrollRunRequest`,
`PayPayrollItemRequest`, `HoldPayrollItemRequest`, `IssuePayrollCorrectionRequest`.
Policies in `app/Policies/Hr/`: one per model, each implementing the row rules of §2 and the scope of §9.

---

## 8. UI screens

House rules from Phase 1 §9 and `CLAUDE.md` §6 apply to every screen: `@extends('layouts.admin')`,
`x-ui.*` components only, search + filters + sortable headers + pagination + empty state + skeleton loader
on every list, money right-aligned with `tabular-nums` through `money()`, dates through `app_date()`,
toast on every write, `x-ui.confirm` with a **mandatory reason field** on every irreversible action,
tables inside `overflow-x-auto`, light and dark, mobile first. Views live under
`resources/views/admin/hr/` (`employees/`, `departments/`, `designations/`, `shifts/`, `holidays/`,
`attendance/`, `leaves/`, `salary/`, `advances/`, `payroll/`, `payslips/`) and
`resources/views/admin/my/` for self-service.

**No Kanban anywhere in this phase** - §24-28 never ask for one, and a "leave board" would be invented
scope. Two calendar-shaped screens exist because a month of attendance and a team's leave cannot be read
as a flat list (§8.8, §8.10, §8.15), and two wizards exist because the requirement implies them: the
employee form (§8.2) and payroll generation (§8.18).

### 8.1 Employees index

**Purpose.** The HR roster and the entry point to every other HR screen.
**Components.** `x-ui.page-header` (+ Create, Import, Export), `x-ui.filter-bar`, `x-ui.table`,
`x-ui.th-sortable`, `x-ui.badge`, `x-ui.avatar`, `x-ui.pagination-summary`, `x-ui.empty-state`,
`x-ui.skeleton`, `x-ui.stat-card` row (headcount, on probation, on leave today, exited this year).
**Filters.** Free text (name / `employee_code` / phone / email), department, designation, employment type,
status (multi), branch, shift, reporting manager, joining-date range, "has login", "documents expiring in
30 days", "no salary structure".
**Columns.** Avatar + name (+ `employee_code` beneath) · department · designation · employment type badge ·
status badge · joining date · reporting manager · phone · **gross salary** (only with
`employees.view_financial`, otherwise the column is absent from the markup, not hidden with CSS) · row
actions (view, edit, documents, salary, attendance, leave, status, exit, delete - each permission-gated).
**Empty state.** "No employees yet" + a Create employee action; the filtered-empty variant says which
filters are in force and offers Clear.

### 8.2 Employee create / edit (a 5-step wizard on create, tabs on edit)

**Purpose.** Capture §24 completely without a 40-field wall.
**Components.** `x-ui.card`, `x-ui.tabs`, `x-ui.form.*`, `x-ui.form.file` (photo, with preview and
remove), `x-ui.toggle`, `x-ui.confirm`, a sticky save bar that appears only when the form is dirty and
warns on navigate-away (the Phase 2 settings pattern).
**Steps / tabs.** (1) **Personal** - name, photo, phone, whatsapp, email, address, city; (2)
**Employment** - department, designation, reporting manager, branch, shift, employment type, joining date,
status; (3) **Emergency contact** - name, relation, phone, alternate phone, address; (4) **Skills** -
repeatable name + level + years rows (Alpine), duplicates refused inline; (5) **Login & salary** - an
optional "create a login for this employee" block (email, role select from the DB, `must_change_password`
forced on) and an optional first salary structure (basic + component lines, with a live gross total
computed server-side on blur - never in JavaScript as the source of truth).
**Edit.** The same five as tabs, plus Documents; `employee_code` renders read-only with a tooltip, and the
salary tab is replaced by a link to the structure timeline (§8.16) because a salary is never edited in
place (HR-10).

### 8.3 Employee profile (`show`)

**Purpose.** One employee, everything about them, permission by permission.
**Components.** `x-ui.card` header (avatar, name, code, status badge, department / designation, quick
actions), `x-ui.tabs`, `x-ui.stat-card`, `x-ui.table`, `x-ui.badge`, `x-ui.empty-state`.
**Tabs.** Overview (employment facts, emergency contact, skills chips, reporting line, direct reports) ·
**Attendance** (this month's grid + the summary strip: present / absent / late / leave / payable days) ·
**Leave** (balance cards per type, request history) · **Salary** (`employees.view_financial`: the structure
timeline and the slip list) · **Documents** (with expiry chips) · **Advances** · **Activity** (the
`activity_log` rows for this employee, actor + IP + reason).

### 8.4 Departments / 8.5 Designations

**Purpose.** §25's dynamic lists.
**Components.** `x-ui.table`, inline `x-ui.modal` create/edit forms, `x-ui.toggle`, `x-ui.confirm`,
`x-ui.empty-state`.
**Departments columns.** Code · name · head (avatar + name, with an "assign head" action) · employees
count · branch · active toggle · sort handle · actions. Deleting a department with employees shows the
refusal with the count and an "deactivate instead" button.
**Designations columns.** Title · code · department (or "any") · level · employees count · active · actions.

### 8.6 Employee documents and the expiring-documents screen

**Purpose.** §24's documents with the expiry tracking the requirement implies by asking for documents at
all.
**Components.** `x-ui.table`, `x-ui.form.file`, `x-ui.badge`, `x-ui.modal`, `x-ui.confirm`.
**Columns.** Type · title · number · issued · **expires** (a badge: grey "no expiry", emerald "valid",
amber "expires in N days", rose "expired") · verification badge · size · uploaded by · actions (download,
verify, delete).
**Expiring screen.** `/admin/employee-documents/expiring`: every document expiring within
`hr.document_expiry_reminder_days` or already expired, across all employees, filterable by department and
type, sorted by `expires_on`. Empty state: "No documents expiring in the next 30 days".
**Security.** Downloads stream from the private disk, are throttled, and write an activity row naming the
document, the actor and the IP before the stream starts.

### 8.7 Work shifts

**Purpose.** Define what late and early leave mean.
**Components.** `x-ui.table`, `x-ui.modal` form, `x-ui.badge`, `x-ui.confirm`.
**Columns.** Code · name · window (`09:00 - 18:00`, with a moon icon when it crosses midnight) · break ·
expected hours · grace in / out · full-day / half-day thresholds · weekly off chips · default badge ·
employees on this shift · active · actions.
**Form note, always visible.** "Changing a shift affects future days only - attendance already recorded
keeps the times it was measured against." (HR-2)

### 8.8 Holidays (list + calendar)

**Purpose.** The calendar attendance and leave both read.
**Components.** `x-ui.tabs` (List / Calendar), `x-ui.table`, `x-ui.modal`, `x-ui.badge`, `x-ui.confirm`,
a 12-month grid built with Tailwind grid (no calendar library).
**Calendar behaviour.** One card per month for the selected year; each day cell shows the holiday title on
hover, weekly-off days tinted, today ringed; clicking an empty day opens the create modal pre-filled with
that date; clicking a holiday opens edit. A year selector and a branch selector sit in the header, plus
"Copy recurring holidays to {next year}" behind a confirm that states how many rows it will create and
that existing dates are skipped.
**Empty state.** "No holidays defined for 2026" + Add holiday + Copy from 2025.

### 8.9 Attendance daily register

**Purpose.** The HR view of one day, and the place a missing punch gets fixed.
**Components.** `x-ui.page-header` with a date stepper (< Today >), `x-ui.filter-bar`, `x-ui.table`,
`x-ui.badge`, `x-ui.modal` (mark / punch / correct), `x-ui.confirm`, `x-ui.stat-card` strip (present,
absent, late, on leave, holiday, not marked).
**Filters.** Date (single), department, designation, branch, shift, status (multi), "late only", "missing
check-out", "manual rows only", "needs correction".
**Columns.** Employee (avatar + code) · department · shift window · check in (+ source icon and a clock
icon when late) · check out · worked hours (`h:mm`) · late · early leave · overtime · status badge ·
payable factor · manual / correction chips · actions (view, punch, correct).
**Bulk.** Checkbox selection + "Mark selected present / absent / on leave" behind a confirm naming the
count and the date; each bulk write produces one correction row per employee (HR-6).
**Empty state.** "Attendance for this date has not been created yet" + "Close this day now" when the user
holds `attendance.create` (explaining that the nightly job normally does it).

### 8.10 Monthly attendance grid (calendar-shaped)

**Purpose.** A month of attendance for a team on one screen - the only readable form of §26's monthly data.
**Components.** `x-ui.card`, a sticky-first-column grid inside `overflow-x-auto`, `x-ui.badge`,
`x-ui.skeleton`, a legend for the seven states.
**Behaviour.** Rows = employees, columns = days 1-31, each cell a single-letter state chip coloured from
`AttendanceStatus::color()` (weekends and holidays tinted, not blank); the cell tooltip shows punches,
minutes and remarks; clicking a cell opens the attendance detail drawer; the right-hand frozen columns
show present / absent / leave / late / **payable days** for the month. Filters: month, department, branch,
shift, employee search. Export to CSV with `attendance.export`.
**Empty state.** "No attendance recorded for March 2026".

### 8.11 Attendance detail drawer

**Purpose.** Explain one day completely, so nobody has to guess how a status was reached.
**Components.** `x-ui.modal`, `x-ui.badge`, `x-ui.table` (the correction history), `x-ui.confirm`.
**Content.** The resolution trace rendered as a readable list: day type and why (weekend / holiday name /
working), the shift window **as snapshotted**, punches with source and IP, the minute arithmetic
(`09:22 - (09:00 + 15) = 7 late minutes`), the resulting status and payable factor, the leave link when
there is one, the lock state with the run that locked it, and every correction row (old -> new, actor,
reason, time).

### 8.12 Correction queue

**Purpose.** The approval inbox for §26's manual corrections.
**Filters.** Status, type, source (self request / HR direct), department, date range, requester.
**Columns.** Employee · date · type · requested change (old -> new, rendered compactly) · reason (truncated,
full on hover) · source · requester · age · actions (approve, reject - each opening a confirm with a
comment field, mandatory on reject).
**Empty state.** "No correction requests waiting" with a link to the register.

### 8.13 Leave types / 8.14 Leave balances

**Leave types.** `x-ui.table` + a tabbed `x-ui.modal` form (Basics / Quota & accrual / Rules / Approval).
Columns: code · name · quota · paid badge · accrual · carry forward · half day · attachment rule ·
approval levels · colour swatch · active · in-use count · actions.
**Leave balances.** One row per employee with one column per active leave type, each cell showing
`available / entitled` and a progress bar; filters by department, leave year, type, "negative balance",
"unused > N days". Row click opens the employee's leave ledger (a bank-statement table of
`leave_balance_transactions`: date, reason, +/- days, balance after, request link, actor, note). Header
actions: "Grant leave year" and "Adjust balance" (both behind a confirm; the adjust modal requires days,
type, employee and a mandatory note). Empty state: "No balances for 2026 - grant the leave year to start".

### 8.15 Leave requests: index, calendar, detail

**Index filters.** Status, leave type, department, branch, date range (overlapping the range, not merely
contained), employee, "waiting on me", "paid / unpaid", "has attachment".
**Index columns.** Request # · employee · type badge (type colour) · from - to (+ portion chip) · days ·
paid / unpaid split · status badge · level chip ("waiting: level 2 - HR") · applied on · actions (view,
approve, reject, cancel, download attachment).
**Calendar view.** A month grid of the team's approved and pending leave: one bar per request in the
type's colour, pending rendered hatched, weekends and holidays tinted, a per-day count badge when more
than three people are off. Its purpose is stated on screen: an approver checking whether the team can
spare the person. Filters: month, department, type, status.
**Detail.** Header (employee, type, dates, days, status), the **approval chain as a vertical timeline**
(level, expected approver, actor, time, comment - pending levels greyed), the balance box (available at
application time vs now), the attachment (download, permission-gated), the affected days list (counted /
skipped with the reason), the attendance effect after approval, and the activity log. Actions: Approve /
Reject / Cancel, each `x-ui.confirm`, reason mandatory on reject and cancel.
**Empty state.** "No leave requests in this range".

### 8.16 Leave apply form (admin and self-service share one Blade partial)

**Components.** `x-ui.card`, `x-ui.form.*`, `x-ui.form.file`, `x-ui.badge`, `x-ui.skeleton`.
**Behaviour.** Choosing a type shows its rules inline (quota, paid, notice days, attachment rule) and the
**live available balance**; choosing dates calls a read-only preview endpoint that returns the counted
days, the skipped weekends/holidays with reasons, the resulting paid/unpaid split and any conflict
("Ahmed is already on leave 12-14 March", "this range includes a locked payroll period"); the submit
button stays disabled while a blocking conflict exists, and the blocking reason is printed, never merely
implied. Attachment required when the type says so, validated server-side.

### 8.17 Approval inbox (`/admin/my/approvals`)

**Purpose.** Everything waiting on this user: leave levels and attendance corrections for their scope (§9).
**Components.** `x-ui.tabs` (Leave / Corrections), `x-ui.table`, `x-ui.badge`, `x-ui.confirm`, a count
badge mirrored on the sidebar item.
**Empty state.** "Nothing is waiting for you".

### 8.18 Salary components, structure timeline, advances

**Salary components.** `x-ui.table` grouped by side; columns: code · name · group badge · calculation ·
default amount / rate · taxable · affects gross · system lock icon · active · used-in count · actions.
**Structure timeline (per employee).** One `x-ui.card` per **immutable** version, newest first, drawn as a
vertical timeline: version number, effective window, basic, gross, component lines, the change reason, who
created and approved it, and a "current" ring on the active one. The only write action is **New version**,
whose form explains in one line that the open version will be closed the day before the new
`effective_from`. A version that has ever been used by a locked slip shows a lock chip.
**Advances.** Index filters: status, employee, department, date range, "outstanding only". Columns:
advance # · employee · amount · installments · recovered · **outstanding** · status badge · requested ·
actions (approve, reject, disburse, record manual recovery, waive, cancel). Detail shows the repayment
ledger (date, type, amount, slip link, actor, note) and the recovery schedule with the cap that applies.

### 8.19 Payroll runs index and run detail (generation wizard)

**Index filters.** Period (year, month), branch, run type, status.
**Index columns.** Run # · period · type badge · branch · employees · **total gross** · total deductions ·
**total net** · paid · status badge (with a lock icon from `locked` onward) · generated by · locked by ·
actions (open, generate, lock, pay, export, cancel).
**Run detail - a 4-step wizard** mirroring the lifecycle, rendered as `x-ui.tabs` that unlock in order:
1. **Period & scope** - year, month, branch, run type, payment date, an employee-selection panel
   (all payroll-eligible, or a department, or explicit employees) with the excluded ones listed and why.
2. **Readiness** - a checklist before anything is computed: attendance summaries present for every
   selected employee (with a "build missing summaries" action), employees without a salary structure,
   unresolved attendance rows, pending corrections, pending leave requests touching the period, advances
   due. Each failing row links to the screen that fixes it. Generation is allowed while warnings remain
   only for the non-blocking ones, and the blocking ones are named.
3. **Generate & review** - the item table: employee · payable days · lop days · gross · deductions ·
   **net** · a "why" disclosure per row expanding into the component lines with their calculation notes ·
   status. Inline footer totals. Re-generate (confirm: "draft figures will be recomputed"), hold, exclude,
   and per-row Preview (§6.2) are available here and only here.
4. **Lock & pay** - `x-ui.confirm` stating the employee count and the total net and that **nothing can be
   edited after locking**; then per-item Mark paid (method, reference, date) and a bulk "Mark all paid"
   with the same fields; `hold` rows are listed separately with their reasons. After locking, every edit
   control is gone from the markup (not disabled) and an "Issue correction" action appears per row.
**Empty state (index).** "No payroll runs yet" + Create run. **Empty state (step 3).** "Nothing generated
yet" + Generate.

### 8.20 Salary slip (printable) and the correction modal

**Slip.** `resources/views/admin/hr/payslips/print.blade.php`, a dedicated print stylesheet (A4, no
sidebar, `@media print` only black on white). Blocks: company header from the `company` / `branding`
settings; employee block (name, code, designation, department, joining date, branch); period and slip
number; **earnings table** and **deductions table** side by side, one row per stored component with its
`calculation_note`; totals (gross earnings, total deductions, **net salary**) with net also in words; the
attendance block (working, present, absent, paid/unpaid leave, payable days, lop days, late count) when
`hr.payslip_show_attendance`; payment block (method, reference, paid date) when paid; the footer note and
a "computer generated" line. A correction item prints with a visible "CORRECTION - adjusts SLP-00340"
banner and signed amounts.
**Correction modal.** Opened from a locked item: shows the original lines read-only, lets the user add
signed component lines (component select, amount, note), previews the resulting net delta, requires a
reason of at least 10 characters, and states that a **new** slip will be created on a correction run and
the original will not change (HR-17).

### 8.21 Dashboard widgets (registered into Phase 2's `DashboardRegistry`)

| Widget | Span | Permission / module | Data |
|---|---|---|---|
| `HeadcountWidget` | 3 | `employees.view_any` | active / probation / exited this year, delta vs the previous range |
| `AttendanceTodayWidget` | 6 | `attendance.view_any` | present / absent / late / on leave / not marked for today, each a link into the register |
| `LateArrivalsWidget` | 3 | `attendance.view_any` | today's late list with minutes |
| `PendingLeaveApprovalsWidget` | 3 | `leaves.approve` | rows waiting on **this** user, scoped by §9 |
| `OnLeaveTodayWidget` | 3 | `leaves.view_any` | who is off today and for how long |
| `UpcomingHolidaysWidget` | 3 | `holidays.view_any` | the next five holidays |
| `ExpiringDocumentsWidget` | 3 | `employee_documents.view_any` | documents expiring inside the reminder window |
| `AttendanceTrendChartWidget` | 6 | `attendance.view_reports` | 30-day present / absent / late lines through `x-ui.chart` |
| `DepartmentHeadcountChartWidget` | 6 | `employees.view_reports` | headcount per department |
| `PayrollCostWidget` | 3 | `payroll.view_financial` | last locked run: gross, deductions, net, and the month-on-month delta |
| `PayrollStatusWidget` | 3 | `payroll.view_any` | the current month's run state (not created / draft / generated / locked / paid) with the next action |
| `AdvanceOutstandingWidget` | 3 | `employee_advances.view_financial` | total outstanding and the employee count |

Every widget reads through the services of §6 (never its own SQL for a money or day figure), respects the
date range, and is filtered by permission and module state by the registry itself.

---

## 9. Data isolation

Every rule is an Eloquent scope **plus** a Policy check - never a hidden form field (`CLAUDE.md` §1.10) -
and every rule has a feature test asserting both the HTTP status **and** the absence of the forbidden
columns from the response body.

`App\Services\Hr\EmployeeScopeResolver::visibility(User)` returns exactly one of:

| Result | When | Effect on every HR list query |
|---|---|---|
| `all` | Super Admin, or the user holds `employees.view_any` (HR, Admin, Accountant) | no employee restriction; the **branch** rule below still applies |
| `team([...ids])` | the user has an employee record and holds `leaves.approve` or `attendance.approve` but **not** `employees.view_any` | own id + every descendant of the `reports_to_id` tree, resolved iteratively with a **depth cap of 5** and a single cached query per request |
| `own(id)` | the user has an employee record and holds only `employee_self_service.*` | that one id |
| `none` | the user has no employee record and no HR view permission | every HR route 403s |

| Role | Exact query scoping |
|---|---|
| **Super Admin** | Unrestricted, still subject to module gating - a disabled HR module 403s Super Admin too (D5). |
| **Admin / HR** | `all`. Money columns additionally require `employees.view_financial` / `payroll.view_financial`; without it the column is **omitted from the select and from the markup**, not hidden with CSS. |
| **Accountant** | `all` for read on `payroll_runs`, `payroll_run_items`, `payroll_run_item_components`, `employee_advances` and the employee roster; **no** access to `leave_requests.reason`, leave attachments or `employee_documents` (403) - salary is their business, medical notes are not. Cannot hold `payroll.approve` and `payroll.change_status` usefully at once: the policy refuses `markItemPaid` by the same user who locked the run when both abilities are held. |
| **Line manager** (Project Manager, Institute Manager, Course Coordinator, any role granted `leaves.approve`) | `team`. Sees attendance, leave requests and leave balances of their direct and indirect reports only; `payroll_run_items`, `salary_structures`, `employee_advances` and `employee_documents` are **403 for the whole module**, including for their own reports - a manager approves absence, not pay. Route-model binding on another branch of the tree returns **404**. |
| **Employee (self-service)** | `own`. Every `/admin/my/*` controller resolves `auth()->user()->employee` and queries **only through its relations** (`$employee->attendances()`, `->leaveRequests()`, `->payrollItems()`, `->documents()`); no id from the request ever reaches a `where`. Route-model binding for a slip, a leave request, a correction or a document asserts `employee_id === $employee->id` and returns **404**, not 403, so other people's ids cannot be probed. A user with no employee record gets 404 on every `my/*` route. `employee_self_service.view_financial` is what reveals slip amounts; without it the slip list is hidden entirely. |
| **Teacher / Student / Client / Collaborator** | **No access to any table in this phase.** Every route in §7 is 403 for them (panel middleware plus the permission). A teacher who is also an employee reaches HR only through the admin panel with an admin-panel role - never through `/teacher`. |
| **Branch (D11)** | When `users.branch_id` is set, every employee, attendance, summary, leave and payroll query adds `where branch_id IS NULL OR branch_id = user.branch_id`. A run created for one branch can never include another branch's employee (asserted at generation). |
| **Module gating** | Disabling `employees`, `attendance`, `leaves`, `payroll`, `salary_slips` or any §4.1 module 403s those routes for everyone via Phase 1's `Gate::before`, hides the sidebar items, and leaves every row, every locked run and every queued job intact. |
| **Privacy specifics** | `leave_requests.reason` and its attachment are readable by: the employee, the resolved approval chain, and holders of `leaves.view_any`. `employee_documents` file bytes are readable only with `employee_documents.download` (or the owner through `employee_self_service.download`), always streamed from the private disk, always logged. `employees.current_gross_salary`, every `salary_structures` money column and every slip money column require the matching `view_financial`. |

---

## 10. Events, notifications, jobs, scheduled tasks

### 10.1 Events (every one dispatched through `DB::afterCommit()` - a rolled-back transaction can never notify anyone)

`EmployeeCreated`, `EmployeeUpdated`, `EmployeeStatusChanged`, `EmployeeExited`, `EmployeeUserLinked`,
`EmployeeDocumentUploaded`, `EmployeeDocumentExpiring`, `DepartmentSaved`, `DepartmentHeadChanged`,
`HolidayCalendarChanged`, `AttendanceCheckedIn`, `AttendanceCheckedOut`, `AttendanceDayClosed`,
`AttendanceImported`, `AttendanceSummaryBuilt`, `AttendanceCorrectionRequested`,
`AttendanceCorrectionApproved`, `AttendanceCorrectionRejected`, `LeaveYearGranted`, `LeaveCarriedForward`,
`LeaveBalanceAdjusted`, `LeaveRequested`, `LeaveApproved`, `LeaveRejected`, `LeaveCancelled`,
`SalaryStructureVersioned`, `SalaryStructureCancelled`, `AdvanceRequested`, `AdvanceApproved`,
`AdvanceRejected`, `AdvanceDisbursed`, `AdvanceRecovered`, `AdvanceWaived`, `AdvanceSettled`,
`PayrollRunCreated`, `PayrollRunGenerated`, `PayrollRunLocked`, `PayrollItemHeld`, `PayrollItemPaid`,
`PayrollRunPaid`, `PayrollRunCancelled`, `PayrollCorrectionIssued`.

### 10.2 Queued jobs

| Job | Key properties |
|---|---|
| `CloseAttendanceDay` | one job per branch per date; `ShouldBeUnique` (`attendance-close:{branch}:{date}`), `tries` 3; idempotent (§6.2) |
| `RecomputeAttendanceDate` | `attendance-recompute:{date}:{employeeId}`; dispatched after a holiday, shift or leave change |
| `BuildAttendanceMonthlySummary` | `ams:{employeeId}:{year}:{month}`, `$afterCommit = true`; refuses a locked period |
| `ImportAttendanceChunk` | 500 rows per job, carrying the import `run_uuid`; writes its own per-row result log |
| `GeneratePayrollRunChunk` | 100 employees per job, `ShouldBeUnique` (`payroll-generate:{runId}:{chunk}`), `tries` 3, `backoff [30,60,120]`; items are idempotent per (run, employee), so a retry cannot double-insert |
| `AccrueMonthlyLeave` | `leave-accrual:{year}:{month}`; idempotent per (employee, type, month) |
| `ProcessLeaveYearRollover` | `leave-rollover:{fromYear}`; carry-forward + lapse in one pass per employee |
| `NotifyExpiringDocuments` | batched per day; stamps `expiry_notified_at` so nobody is mailed twice |
| `SendPayslipNotifications` | dispatched on `PayrollRunLocked`, one notification per item, chunked |

### 10.3 Notifications (database channel now, mail-ready - §97)

To the **employee**: `AttendanceCorrectionDecided`, `MissingCheckOutDetected`, `LeaveApproved`,
`LeaveRejected`, `LeaveCancelledByAdmin`, `LeaveBalanceGranted`, `AdvanceApproved`, `AdvanceRejected`,
`AdvanceDisbursed`, `PayslipAvailable` (on lock, with the net and a link - never the PDF as an
attachment), `DocumentExpiringSoon`.
To the **approver / line manager**: `LeaveRequestAwaitingApproval`, `AttendanceCorrectionAwaitingApproval`,
`TeamMemberAbsent` (opt-in per `users.preferences`).
To **HR** (holders of the named permission): `PayrollRunReadyToGenerate` (`payroll.create`),
`PayrollRunLockedNotification` (`payroll.view_any`), `PayrollGenerationFailed` (`payroll.create`, naming
the employees and reasons), `DocumentsExpiringDigest` (`employee_documents.view_any`),
`AttendanceDayCloseFailed` (`attendance.view_any`), `LeaveBalanceInconsistencyDetected`
(`leave_balances.view_any`).

### 10.4 Scheduler (`routes/console.php` / `bootstrap/app.php` schedule)

| Command | Cadence | Purpose |
|---|---|---|
| `hr:close-attendance-day` | daily at `hr.attendance_day_close_time` (23:50) | create the missing rows for today: holidays, weekly offs, absents, `requires_correction` flags (§6.2) |
| `hr:flag-missing-checkouts` | hourly | rows whose shift ended more than 2 hours ago with no check-out -> `requires_correction` + notify the employee and HR once |
| `hr:build-attendance-summaries` | daily 01:30 | incremental rebuild of the current and previous month for every employee; skips locked periods |
| `hr:accrue-leave` | monthly on `hr.leave_accrual_run_day` at 00:30 | `monthly_accrual` credits for every type using that method |
| `hr:leave-year-rollover` | yearly on the first day of `hr.leave_year_start_month` at 00:40 | grant the new year, carry forward, lapse the rest |
| `hr:expire-carry-forward` | daily 00:50 | lapse carry-forward past `carry_forward_expiry_months` |
| `hr:document-expiry-reminders` | daily 08:00 | per-document and digest notifications |
| `hr:payroll-reminder` | monthly on `hr.payroll_reminder_day` 09:00 | remind `payroll.create` holders that the month's run does not exist yet |
| `hr:leave-pending-reminder` | daily 09:30 | leave requests pending more than 2 days, to the resolved approver |
| `hr:verify-leave-balances` | daily 02:00 | `LeaveBalanceService::assertConsistent()` for every employee; notifies on drift (HR-7) and never silently repairs |
| `hr:verify-constraints` | daily 02:30 | assert every unique guard, CHECK, generated column and delete trigger of §2 still exists; alert loudly if one vanished |

---

## 11. Acceptance tests

`tests/Feature/Hr/`. Every leave test ends with `assertLeaveBalanceMatchesLedger($employee)` and every
payroll test ends with `assertSlipAddsUp($item)` (the HR-13 identity), so the nightly verifiers and the
suite share one implementation.

### 11.1 Employees, departments, documents

| # | Test | Asserts |
|---|---|---|
| FT-HR-01 | `test_employee_code_is_unique_under_concurrency` | 20 parallel creates produce 20 distinct `employee_code` values and zero duplicates |
| FT-HR-02 | `test_employee_can_exist_without_a_login` | create with no `user_id` succeeds (D2); a second employee cannot take an already-linked `user_id` (unique violation surfaced as a named validation error) |
| FT-HR-03 | `test_creating_an_employee_with_a_login_is_atomic` | a failure while creating the `users` row rolls back the employee too; no orphan row in either table |
| FT-HR-04 | `test_department_delete_is_refused_while_employees_exist` | 422/refusal naming the count; the department still exists; `is_active = false` succeeds instead |
| FT-HR-05 | `test_department_head_change_is_audited` | an `activity_log` row with old and new head, actor, IP and the mandatory reason |
| FT-HR-06 | `test_designation_title_is_unique_per_department_and_reusable_after_soft_delete` | the second insert throws; after a soft delete the same title inserts again (the `department_guard` trick) |
| FT-HR-07 | `test_document_upload_validation` | a `.php` renamed to `.pdf` is refused by MIME sniffing; an oversized file is refused; a valid PDF lands on the **private** disk and is not reachable by URL |
| FT-HR-08 | `test_document_download_is_permissioned_and_logged` | without `employee_documents.download` -> 403 and nothing streamed; with it -> 200 plus an activity row naming document, actor and IP |
| FT-HR-09 | `test_employee_exit_cascades_correctly` | pending leave cancelled, open salary structure closed at `exit_date`, linked user set inactive, advances still outstanding, nothing deleted |

### 11.2 Attendance

| # | Test | Asserts |
|---|---|---|
| FT-HR-10 | `test_late_and_early_leave_are_computed_from_the_shift_snapshot` | 09:22 on a 09:00 shift with 15 minutes grace -> `late_minutes = 7`, status `late`; 17:30 against an 18:00 end with 10 minutes grace -> `early_leave_minutes = 20`; both minute columns populated when both happen and the status is `late` |
| FT-HR-11 | `test_working_hours_exclude_the_break` | 09:00-18:00 with a 60-minute break -> `worked_minutes = 480`, `overtime_minutes = 0`; 09:00-19:30 -> `overtime_minutes = 90` and **no** automatic overtime earning |
| FT-HR-12 | `test_editing_a_shift_does_not_change_past_attendance` | change the shift start to 10:00; a previously resolved row keeps `late_minutes = 7` and its snapshot (HR-2) |
| FT-HR-13 | `test_one_attendance_row_per_employee_per_day` | a second insert for the same date throws on `uq_att_day`; a second `checkIn()` returns the same row with the original `check_in_at` and logs a duplicate punch |
| FT-HR-14 | `test_manual_change_always_writes_a_correction_row` | a direct HR correction and an approved self-request both produce an `attendance_corrections` row with old/new values, reason and actor, plus an activity row; a direct `Attendance::update()` from a controller is impossible because the policy refuses it |
| FT-HR-15 | `test_correction_outside_the_window_is_refused` | a request 30 days back with a 7-day window -> refused naming the window; HR with `attendance.edit` can still correct it directly |
| FT-HR-16 | `test_weekend_and_holiday_resolution` | a Sunday -> `day_type = weekly_off`, status `holiday`, factor 1.0000; a declared holiday -> `public_holiday` with `holiday_id`; an unpaid holiday -> factor 0.0000; an `optional` holiday leaves the day `working` |
| FT-HR-17 | `test_holiday_declared_late_recomputes_and_audits` | an `absent` row becomes `holiday` after the declaration, a `holiday_recalculation` correction row exists, and a **locked** date is refused instead |
| FT-HR-18 | `test_night_shift_crossing_midnight` | a 22:00 check-in and a 06:10 check-out belong to the start date's row, `worked_minutes = 490`, no row created for the next date; a punch-out 19 hours later is refused |
| FT-HR-19 | `test_missing_checkout_is_half_day_and_flagged` | no check-out after the shift ended -> `requires_correction = true`, status `half_day`, factor 0.5000, one notification to the employee and one to HR |
| FT-HR-20 | `test_day_close_is_idempotent` | running `hr:close-attendance-day` twice creates each row once, never duplicates, and leaves existing punched rows untouched; `is_attendance_exempt` employees are never marked absent |
| FT-HR-21 | `test_monthly_summary_identity` | `payable_days = SUM(payable_factor)` and `lop_days = SUM(1 - payable_factor)` over working rows, to four decimals, for a hand-built 31-day month containing a weekend, a holiday, a paid leave, an unpaid leave, a half day and two absents |
| FT-HR-22 | `test_summary_rebuild_is_refused_when_locked` | after a run locks the period, `rebuild` throws naming the run, and the stored figures are byte-identical afterwards |
| FT-HR-23 | `test_attendance_import_dry_run_writes_nothing` | the preview returns a diff and an error list with zero writes; the commit then refuses to overwrite a manual or locked row and writes a correction row for every changed value |

### 11.3 Leave

| # | Test | Asserts |
|---|---|---|
| FT-HR-24 | `test_day_expansion_skips_weekends_and_holidays` | a Friday-to-Tuesday range with `excludes_weekends` -> `total_days = 3`, two `is_counted = false` rows with their reasons, and the balance debited by exactly 3 |
| FT-HR-25 | `test_balance_equals_its_ledger_after_every_transition` | apply -> reserve, approve -> consume, cancel -> credit: after each step the cached columns equal `SUM(signed_days)` and `available_days` matches the formula (HR-7) |
| FT-HR-26 | `test_overlapping_leave_is_refused_by_the_database` | a second request covering one already-counted date throws on `uq_lrd_day` and the service reports the existing request number; after the first is cancelled the same date applies cleanly |
| FT-HR-27 | `test_quota_overrun_is_refused_with_the_exact_shortfall` | 12 available, 15 requested -> refused naming "3 days short"; with the type's `allow_negative_balance` it is approved and the extra 3 land in `unpaid_days` |
| FT-HR-28 | `test_approval_chain_order_and_self_approval` | level 2 cannot act first; a rejection at level 1 marks level 2 `skipped`; the requester's own user cannot approve even holding `leaves.approve`; the final approval sets `approved_at` |
| FT-HR-29 | `test_approved_leave_writes_attendance` | each counted date becomes `on_leave` (or `half_day` for a portion) with the leave links and the type's paid factor; an uncounted weekend stays `holiday`; re-running `applyToAttendance()` changes nothing |
| FT-HR-30 | `test_cancelling_approved_leave_restores_attendance_and_balance` | days re-resolved to `absent`/`present` per punches, the ledger credited back, and the act refused outright when any touched date is locked |
| FT-HR-31 | `test_accrual_grant_and_carry_forward_are_idempotent` | running `hr:accrue-leave`, `grantYear` and `carryForward` twice produces exactly one ledger row each; carry-forward is capped at `max_carry_forward_days` with the remainder recorded as `year_end_lapse` |
| FT-HR-32 | `test_leave_attachment_rules` | a type requiring an attachment refuses without one; a 4-day sick leave with `attachment_required_after_days = 3` requires it while a 2-day one does not; the file is stored privately and is only downloadable with `leaves.download` |

### 11.4 Payroll money

| # | Test | Asserts |
|---|---|---|
| FT-HR-33 | `test_the_worked_example_to_the_paisa` | the §6.6 example produces exactly `per_day_amount = 1580.65`, `UNPAID_LEAVE = 3951.63`, `gross = 49000.00`, `deductions = 10151.63`, `net = 38848.37`, and `gross - deductions = net` |
| FT-HR-34 | `test_slip_totals_are_the_sum_of_stored_lines` | mutating a component row in the fixture and re-asserting makes the test fail, proving the totals are summed from rows and not recomputed (HR-13); `lock()` refuses a run whose item does not add up, naming it |
| FT-HR-35 | `test_no_float_touches_money` | a static scan of `app/Services/Hr` and `app/Models/Hr` finds no arithmetic operator applied to a money or day attribute and no `(float)` / `floatval` / `round(` on one (HR-12) |
| FT-HR-36 | `test_day_basis_options_change_the_per_day_rate` | the same employee and month under `calendar_days`, `working_days` and `fixed_30` produce three different, exactly-asserted per-day amounts, each recorded in `day_divisor` |
| FT-HR-37 | `test_missing_attendance_summary_blocks_generation` | no item is written, the report names the employee and the reason, and full attendance is **not** assumed (R4) |
| FT-HR-38 | `test_net_salary_never_goes_negative_on_a_regular_run` | deductions exceeding earnings first shrink the advance recovery, then drop it, then the item is `on_hold` with a reason; the DB CHECK makes a negative net impossible on a regular run |
| FT-HR-39 | `test_advance_recovery_respects_the_cap_and_never_over_recovers` | a 5,000 installment against a 6,000 net with a 50% cap recovers 3,000 and leaves 2,000 outstanding; a parallel double call recovers the installment exactly once; `recovered + waived <= amount` holds under concurrency |
| FT-HR-40 | `test_advance_repayment_is_written_only_when_the_slip_is_paid` | a locked but unpaid slip leaves the advance untouched; marking it paid posts exactly one repayment row linked to the item |
| FT-HR-41 | `test_salary_structure_is_versioned_not_edited` | an `update()` of `basic_salary` throws `ImmutableSalaryStructureException`; `createVersion()` closes the old window at `effective_from - 1 day`, sets `version = 2` and `supersedes_id`, and a second open version throws on `uq_ss_open` |
| FT-HR-42 | `test_payroll_uses_the_structure_effective_in_the_period` | a raise effective 15 April does not change the March slip; generating March after the raise still uses version 1 |
| FT-HR-43 | `test_one_regular_run_per_branch_per_month` | the second create throws on `uq_pr_regular` naming the existing run; a `correction` run for the same month succeeds; a cancelled regular run frees the slot |
| FT-HR-44 | `test_regeneration_is_deterministic_and_idempotent` | generating twice from identical inputs produces identical item and component rows (compared field by field) and never duplicates an item for an employee |
| FT-HR-45 | `test_locking_freezes_everything` | after `lock()`: updating any money column, snapshot or component throws `ImmutablePayrollAttributeException`; deleting an item or component throws at the model and at the trigger; the period's attendance rows and summaries refuse edits and rebuilds (HR-15, HR-16, HR-18) |
| FT-HR-46 | `test_a_paid_run_cannot_be_unlocked_or_cancelled` | every such attempt is refused; no route and no service method exists that moves `paid`/`locked` back to `generated` |
| FT-HR-47 | `test_correction_is_a_new_item_never_an_edit` | `issueCorrection()` leaves the original byte-identical, creates a correction run with `parent_run_id`, inserts an item with `corrects_item_id`, allows a negative net there, and a negative amount on a **regular** item is refused by the CHECK (HR-17) |
| FT-HR-48 | `test_slip_number_is_unique_under_concurrency` | 50 concurrent generations produce 50 distinct `slip_number` values; a 1062 retries exactly once |
| FT-HR-49 | `test_deadlock_free_under_concurrency` | two runs plus a leave approval plus two punches for overlapping employees, executed concurrently, complete with no deadlock and correct final figures (HR-22's lock order) |

### 11.5 Authorization and isolation (each asserts the HTTP status **and** that nothing was written or leaked)

| # | Test | Asserts |
|---|---|---|
| FT-HR-50 | `test_every_hr_route_403s_without_its_permission` | a permission-less admin-panel user gets 403 on all of §7; granting the exact permission makes it 200 - table-driven over every route |
| FT-HR-51 | `test_panel_roles_cannot_reach_hr` | Teacher, Student, Client and Collaborator users get 403 on every HR route, including the `my/*` group |
| FT-HR-52 | `test_employee_sees_only_their_own_attendance_leave_and_slips` | the `my/*` index responses contain only the user's own rows; another employee's slip, leave request, correction and document each return **404**; the slip list is absent entirely without `employee_self_service.view_financial` |
| FT-HR-53 | `test_line_manager_sees_only_direct_and_indirect_reports` | a manager's leave and attendance lists contain exactly their subtree; a sibling manager's report returns 404; payroll, salary, advances and documents are 403 for the whole module |
| FT-HR-54 | `test_accountant_sees_money_but_not_medical` | payroll and slips are readable; `leave_requests.reason`, leave attachments and `employee_documents` are 403; the leave list response does not contain the reason column |
| FT-HR-55 | `test_financial_columns_require_view_financial` | without `employees.view_financial` the employee index and show responses contain no salary figure anywhere in the HTML or JSON, and the Salary tab is absent from the markup |
| FT-HR-56 | `test_branch_scoping` | a user with `branch_id = 2` sees only branch-2 and branch-null employees, attendance and runs; generating a run for branch 2 never includes a branch-1 employee |
| FT-HR-57 | `test_segregation_of_duties_on_payroll` | the user who locked a run cannot mark its items paid when they hold both abilities; an employee can never approve their own leave; an advance cannot be approved by its own requester |
| FT-HR-58 | `test_module_gating_preserves_data` | disabling `payroll` 403s its routes for Super Admin too, hides the sidebar item, and leaves identical row counts in all four payroll tables before and after disable + re-enable |

### 11.6 Schema guarantees and audit

| # | Test | Asserts |
|---|---|---|
| FT-HR-59 | `test_every_guard_check_generated_column_and_trigger_exists` | every unique guard, CHECK, STORED generated column and `BEFORE DELETE` trigger of §2 is present in `information_schema`; each guard's **second** INSERT throws; this is the test that fails CI when a constraint is silently dropped |
| FT-HR-60 | `test_append_only_tables_refuse_deletion` | deleting a `leave_balance_transactions`, `attendance_corrections`, `employee_advance_repayments`, `salary_structures` or locked `payroll_run_items` row throws at the model hook first and at the trigger if the hook is bypassed with raw SQL |
| FT-HR-61 | `test_migrations_roll_forward_and_back` | `migrate:fresh --seed` runs clean on a database containing only phases 1-7; every Phase-7 migration rolls back cleanly in reverse order, including the generated columns and triggers |
| FT-HR-62 | `test_every_discretionary_act_is_audited_with_a_reason` | status change, direct correction, leave approval/rejection, balance adjustment, structure version, advance waiver, run lock, item hold and correction issue each write an `activity_log` row with old/new values, actor, IP and the reason; a blank reason is refused by the Form Request (HR-20) |
| FT-HR-63 | `test_seeders_are_idempotent` | running the department, designation, shift, holiday, leave-type and salary-component seeders twice changes no row count and preserves every admin-edited value |
| FT-HR-64 | `test_dashboard_widgets_are_bounded` | the HR widgets together issue a bounded number of queries (asserted with `DB::listen`) - no N+1 across employees - and are filtered by permission and module state |
| FT-HR-65 | `test_payslip_print_renders_in_light_and_dark_and_prints_from_stored_lines` | the print view renders with no console error, contains every stored component row with its note, shows `gross - deductions = net`, and a correction slip shows the CORRECTION banner naming the original slip number |

---

## 12. Risks and open questions

### 12.1 Risks accepted, with the mitigation

| # | Risk | Mitigation / why it is accepted |
|---|---|---|
| R-1 | **One attendance row per employee per calendar day, weekends included**, means roughly `headcount x 365` rows a year (73,000 rows for 200 staff). A flat list is useless at that size. | It is what makes HR-1, the monthly grid, the summary identity and "absent" itself provable; without holiday and weekend rows every aggregate needs a calendar join in PHP. The indexes in §2.9 keep every screen on `(employee_id, attendance_date)` or `(attendance_date, status)`, and the monthly grid reads one month at a time. Past a few million rows the table partitions by year with no definition change. |
| R-2 | **`payable_factor` is the single point of truth for pay.** One bad `resolve()` change silently mis-pays everyone. | It is computed in one place, it is covered by FT-HR-10/16/19/21, the identity `lop_days = 1 - payable_factor` is asserted, and the figure is printed on the slip and visible in the grid, so an error is visible to HR before it is paid. |
| R-3 | **The seven states cannot express "late **and** early leave" in one status.** A report grouping by status under-counts early leaves. | Both minute columns are always stored and both counts are in the monthly summary (`late_count`, `early_leave_count`); the status is a label, the minutes are the data. Stated here so no reviewer reads `status = late` as "not an early leave". |
| R-4 | **Monthly pay frequency only.** A weekly or biweekly payroll needs a new period model. | §28 describes a monthly slip. `payroll_runs` stores `period_start` / `period_end` (not only year + month) and structures carry `pay_frequency`, so a future frequency is additive rather than archaeological. Q4. |
| R-5 | **No statutory engine** (no FBR tax slabs, no EOBI, no provident fund, no gratuity). | §28 lists "tax" and nothing else statutory. Tax has three modes and everything else is a typed component an accountant can define today. Inventing Pakistani statutory rules that change every budget year would be a liability in code; Q5 offers the slab table. |
| R-6 | **Employee commission is manual.** Someone must type it. | §28 never describes how commission is earned, and guessing would either duplicate the collaborator engine or invent a sales-target system. §6.9 keeps the boundary explicit and leaves `source_type`/`source_id` for a later phase. |
| R-7 | **Self check-in is only as trustworthy as the network.** Anyone can punch from a phone unless the IP whitelist is set. | `hr.self_check_in_ip_whitelist`, the stored IP and source on every punch, the correction audit trail, and the fact that HR sees sources in the register. Biometric or geofenced attendance is Q7, and the `import` path already accepts a device export. |
| R-8 | **A manager's `team` scope walks `reports_to_id` recursively.** A cycle or a 20-deep tree would be a performance or infinite-loop hazard. | Depth capped at 5, one cached query per request, and `EmployeeService` refuses a `reports_to_id` that would create a cycle (asserted). |
| R-9 | **Locking attendance with payroll is strict**: a genuinely wrong attendance record discovered after payment can never be corrected in place. | That is the point (HR-18). The remedy is a payroll correction item, which is auditable; an editable history behind a paid slip is how payroll disputes become unwinnable. |
| R-10 | **Append-only tables with delete triggers surprise developers.** A factory or cleanup script deleting a leave ledger row gets a raw SQL error. | Model `deleting` hooks throw first with a readable message; the trigger is the last line of defence; FT-HR-60 pins the behaviour; and §13 asks for a paragraph in `CLAUDE.md` so nobody "fixes" it by dropping the trigger. |
| R-11 | **Three guarantees rest on MariaDB features Laravel's schema builder does not express** - enforced CHECKs, STORED generated columns, and unique indexes over them. On MySQL 5.7 CHECKs are silently ignored. | The migrations write raw SQL and must **fail loudly** rather than skip a constraint; FT-HR-59 asserts every one exists, so a silently dropped guard fails CI instead of surfacing in a payroll dispute. |
| R-12 | **No multi-currency.** Salary is PKR. | `salary_structures.currency` exists as a constant so a future split is additive (the same decision as the financial spine's R-12). |
| R-13 | **This phase is large** - 24 tables, ~17 services, 28 enums, 16 modules - and the real risk is partial implementation: the CHECKs, the triggers, the correction run or the summary identity "deferred for now", leaving this document's guarantees stated but not true. | It must be built as one unit in the order employees -> calendar -> attendance -> leave -> payroll, and the tests that prove the DB-level guarantees (FT-HR-45, FT-HR-59, FT-HR-60) are the ones most likely to be skipped and **must not be**. |

### 12.2 Open questions for the client (defaults assumed, nothing blocked)

| # | Question | Default assumed |
|---|---|---|
| Q1 | Phase 4 records two links into `employees` (`team_members.employee_id`, `job_applications.employee_id`) and Phases 5-6 ship **before** Phase 7. Move the `departments` / `designations` / `employees` trio into the earlier migration set, or keep the tracker as it is? *(Work assignment is not part of this question: under **D32** it points at `users.id`.)* | Keep the tracker; Phase 7 owns the definition and whichever phase lands first ships that one migration verbatim, with every inbound FK in a `Schema::hasTable()`-guarded follow-up ([D-HR-1]). A one-line note in `DEVELOPMENT_LOG.md` §5 makes it honest. |
| Q2 | Do you accept decision **D19** - that the eleven append-only HR tables omit `deleted_at`, exactly as the financial spine's D16 does for money tables? | **Answered: yes, omit it.** **D19** is allocated (the general category rule) and `CLAUDE.md` §3 carries the category table; the payroll and advance tables additionally cite **D16**. A nullable `deleted_at` on a locked slip or a leave ledger is a loaded gun ([D-HR-3]). |
| Q3 | Is the weekend **Sunday only**, or Saturday + Sunday, or a Saturday half day? | `hr.weekend_days = [sunday]`, overridable per shift and per employee. A Saturday half day is not modelled as a weekend: it is a shift with fewer `expected_minutes` - say so if that is the real pattern. |
| Q4 | Is payroll strictly **monthly**? | Yes for this phase (R-4). |
| Q5 | Should salary **income tax** be computed from FBR slabs automatically, or entered per employee? | Entered: `hr.tax_mode = manual` with a `fixed_percentage` option. If slabs are wanted, they arrive as a `tax_slabs` table plus one calculator step - additive, no redesign ([D-HR-6]). |
| Q6 | Do you want employee **bank details** stored for salary disbursement (encrypted, like the collaborator payout accounts)? | Not stored in Phase 7: the slip records method and reference only. If wanted, it is an `employee_payout_accounts` table modelled on `collaborator_payout_accounts` with the same encryption. |
| Q7 | Biometric / card-reader attendance: integrate a device, or keep CSV import? | CSV/XLSX import with a dry-run preview (§6.2). A device integration is a later phase writing through `AttendanceService`, never straight into the table. |
| Q8 | Is **overtime paid**, and at what multiplier? | Measured but never auto-paid; `hr.overtime_pay_enabled = false` and overtime is a manual component when it is paid ([D-HR-6]). |
| Q9 | Does a **late** arrival cost money, and after how many instances? | No: `hr.late_deduction_lates_per_day = 0`. Set it to 3 and every third late costs one day's pay. |
| Q10 | Leave: quotas per type, carry-forward caps, and does the leave year follow the calendar? | Seeded as Annual 14 paid (carry forward 7), Sick 8 paid, Casual 10 paid, Unpaid unlimited unpaid; leave year = calendar year (`hr.leave_year_start_month = 1`). All settings-driven and editable before the first grant. |
| Q11 | How many **approval levels** for leave - manager only, or manager then HR? | One level (the reporting manager, falling back to any `leaves.approve` holder). Set a type's `approval_levels = 2` for manager-then-HR. |
| Q12 | Is **leave encashment** needed, and at what rate? | `leave_types.is_encashable` and the `encashment` ledger reason exist; the payroll earning for an encashment is a manual `other_earning` component in this phase. A formula-driven encashment is a later addition. |
| Q13 | Who may **lock** a payroll run, and must a second person pay it? | HR (or anyone granted `payroll.approve`) locks; the policy refuses the locker to also mark items paid when they hold both abilities (§9). Say the word if one person must do both. |
| Q14 | Should a paid payroll run post an **expense** into Phase 13's finance ledger? | **Answered and built - by Phase 13, not here** (**D44**): phase-13 §6.4's listener `RecordPayrollExpense` hears this phase's `PayrollRunPaid` and writes exactly **one** `approved` expense per run (`source_type = payroll_run`, `source_id = run.id`, unique), so §99's profit and loss includes salaries exactly once and payroll still owns no finance table. |
| Q15 | Should the **Team** section of the public website (§13) read from `employees`? | **Answered: yes** - through the nullable `team_members.employee_id` **Phase 4 now adds** (phase-04 §2.9, deferred guarded FK). It is a source of defaults only: employee data is never published unless a team row exists and is marked public, and publication stays an explicit CMS act. |

---

## 13. Requests to other phases

### 13.1 Columns, tables and behaviour this phase needs

| Request | Why |
|---|---|
| `branches.id` - Phase 1 (exists) | nullable `branch_id` on `departments`, `employees`, `work_shifts`, `holidays`, `attendances`, `attendance_monthly_summaries`, `leave_requests`, `payroll_runs` (D11) |
| `users.branch_id`, `users.status` - Phase 1 (exists) | branch scoping in §9 and the exit cascade that ends a session |
| `App\Support\Money` - **satisfied by phase-01 §3**, which now publishes the one canonical surface (`add`, `sub`, `mul`, `div`, `percentage`, `percentageOf`, `compare`, `isZero`, `isNegative`, `abs`, `min`, `max`, `sum`, `round`, `roundTo`, `prorate`, `distribute`, `toMinor`, `fromMinor`, `format`, plus `App\Enums\RemainderPlacement`), bcmath only, intermediate scale 6, final half-up at 2, strings in and out | §6.6 is written in terms of them; nothing in this phase adds a method and no later phase redefines one |
| `App\Support\PermissionRegistry` - Phase 1: the 11 module slugs of §4.1, the ability additions of §4.2, the `depends_on` graph of §4.3, the role grants of §4.4 | the registry is the only place permission names exist (D4) |
| `App\Support\SettingsRegistry` - Phase 2: the new `hr` **group** and its 43 keys (§5) | settings definitions live in code, values in the DB. Phase 2's `groups()` is already extended by `website` (phase-03/04), `crm` (phase-05) and `projects` (phase-06), so the `hr` group is the fourth addition, not the first |
| `App\Support\DashboardRegistry` - Phase 2: the twelve widgets of §8.21 | §98's HR cards |
| `App\Services\Finance\DocumentNumberService` - **Phase 5 ships it** ([D-HR-14], **D27**); **Phase 7 reuses it unchanged**, passing `'%05d'` explicitly | employee codes, leave request numbers, advance numbers, run numbers and slip numbers all need a concurrency-safe counter, and Phase 5 is the earliest consumer. Same class, same signature, same `FOR UPDATE` behaviour; Phase 7 creates no second numbering class |
| `App\Enums\LedgerEntryType` and `App\Enums\PaymentMethod` - **Phase 7 declares them verbatim** per the spine's §3 case lists; the spine, phases 10-12, 13, 14-17 and 18 **reuse, never recreate** | the leave ledger needs credit/debit and advance disbursement needs a payment method before Phase 10 migrates |
| `teachers.employee_id` nullable FK -> `employees.id`, `nullOnDelete` - Phase 16, and `teachers.salary` (§72) treated as **display only** or dropped | §72 says a teacher may link to an employee. Two salary figures for one person is how a payroll dispute starts: a linked teacher's pay must come from their salary structure and their slip |
| `team_members.employee_id` nullable unsignedBigInteger + `INDEX` - **satisfied: Phase 4 adds it** (phase-04 §2.9), FK deferred to this phase's `Schema::hasTable('employees')`-guarded migration | §13's public Team section reuses `employees.name`, `photo_path`, `designation`, `bio` and skills as a **source of defaults only**; there is no `user_id` link and publication stays an explicit CMS act |
| `job_applications.employee_id` nullable unsignedBigInteger + `INDEX` - **satisfied: Phase 4 adds it** (phase-04 §2.19), FK deferred to this phase's guarded migration (the same pattern as `department_id`) | §16's "Selected" candidate becomes an employee; the link proves where the hire came from |
| `expenses.source_type` / `expenses.source_id` + the payroll listener - **satisfied: Phase 13 ships both** (**D44**): phase-13 §2.6 adds `source_type` string(32) nullable, `source_id` unsignedBigInteger nullable, `UNIQUE uq_exp_source(source_type, source_id)`, and phase-13 §6.4's `RecordPayrollExpense` listens to this phase's `PayrollRunPaid` and writes one `approved` expense (`context = general`, reserved category `salaries`, `source_type = payroll_run`, `source_id = run.id`, amount = the run's net paid total via `Money`) | §29-30 and the profit-and-loss report of §99 must include salaries. Payroll must not create a finance table, and finance must not recompute a salary: one derived row, sourced from the run and unique per run, is the honest seam |
| `files` polymorphic table - Phase 22 | when it exists, `employee_documents.file_path` and `leave_requests.attachment_path` migrate to it; until then a table for one attachment would be dead weight ([D-HR-6]) |
| Phase 22 (notifications): the notification classes of §10.3 | §97 |
| Phase 23 (reports, exports): every HR report - attendance, payroll, leave (§99) - **must** call `AttendanceSummaryService`, `LeaveBalanceService` and the stored slip rows, and must never re-implement a sum | HR-5, HR-13: two definitions of "payable days" is two different salaries |
| Phase 24 (security / integrity testing): run FT-HR-45, FT-HR-48, FT-HR-49, FT-HR-59, FT-HR-60 as part of the hardening pass | §110, §111 |
| Phase 25 (deployment): `php artisan schedule:work` must be documented as **required**, not optional | without the scheduler, attendance is never closed, no absent is ever marked and no leave accrues (§10.4) |

### 13.2 Documentation and log updates

| Request | Why |
|---|---|
| `DEVELOPMENT_LOG.md` §4: this phase **cites D19** (already allocated - the soft-delete category rule); the eleven append-only HR tables carry no `deleted_at` and are protected by model hooks plus `BEFORE DELETE` triggers, and the payroll / advance tables additionally cite **D16** (Q2) | one category rule in `CLAUDE.md` §3 beats fifty local exceptions; this phase invents no decision number |
| `DEVELOPMENT_LOG.md` §4: **D36** - a payroll run is immutable from `lock()`; there is no unlock, and a correction is a new item on a `correction` run that references the original | HR-15, HR-17 - the single most important rule in this phase |
| `DEVELOPMENT_LOG.md` §4: this phase **cites D32** - an organisational **duty** (department head, reporting line, leave approver, course coordinator) references `employees.id`; **who performed an act, who is assigned work and who holds a timer reference `users.id`**, and the bridge is `employees.user_id` (nullable, unique) | [D-HR-2] as narrowed; it constrains phases 6, 13 and 14-17 |
| `tests/Support/index-manifest.php` (Phase 24): every FK index named in the `Keys` blocks of §2 has a row in the manifest | F-9.2 - the manifest is the authority for the index sweep (**D60**) |
| `CLAUDE.md` §5 (or a new §5b "Payroll invariants"): "attendance feeds payroll only through `attendance_monthly_summaries`", "a locked payroll run is never edited - issue a correction item", "a leave balance is a cache over `leave_balance_transactions`" | HR-5, HR-7, HR-15 deserve to sit beside the commission invariants where every developer reads them |
| `DEVELOPMENT_LOG.md` §9: Q3, Q5, Q6, Q10 and Q11 of §12.2 added to the open-questions table with the defaults assumed | quotas, weekend pattern, tax mode and bank details are business answers, not engineering ones |

---

## Convergence log (2026-09-12)

Applied from [`../design/resolutions.md`](../design/resolutions.md) §3 / §7 (apply-map row
`docs/phases/phase-07.md`). Nothing else in this contract changed.

| Finding | Change made |
|---|---|
| F-3.9 | §13.1 `expenses.source_type` / `source_id` row and §12.2 Q14 rewritten as **satisfied**: phase-13 §2.6 adds both columns + `UNIQUE uq_exp_source`, and phase-13 §6.4's `RecordPayrollExpense` listener on this phase's `PayrollRunPaid` writes one `approved` expense per paid run (**D44**). |
| F-3.12 | §13.1 `job_applications.employee_id` row marked **satisfied** - Phase 4 adds the nullable column + index (phase-04 §2.19); the FK stays in this phase's `Schema::hasTable('employees')`-guarded migration. |
| F-3.13 | §13.1 `team_members.employee_id` row and §12.2 Q15 marked **satisfied** - Phase 4 adds the nullable column + index (phase-04 §2.9) as a **source of defaults only**; publication stays an explicit CMS act. |
| F-4.1 | **Phase 5 ships `DocumentNumberService`; Phase 7 reuses it unchanged.** [D-HR-14] rewritten (§5), the "Phase 7 is the first phase that needs this class" claim deleted, the quoted signature corrected to the canonical default pad `'%06d'` with **every HR caller passing `'%05d'` explicitly**, §6's namespace note changed to "called not created", §1.2 gains a Phase 5 dependency row, and §13.1's row retargeted to Phase 5 (**D27**). |
| F-4.11 | §13.1's `Money` additions request replaced with "**satisfied by phase-01 §3**" plus the canonical method list (bcmath only, intermediate scale 6, final half-up at 2, strings in and out) and `App\Enums\RemainderPlacement`. |
| F-4.13 | **No change** - the audit's premise was wrong: `PayslipService` (`render(PayrollRunItem): View`, `export(PayrollRun, string $format)`) already exists at §6.2 and stays the only one. |
| F-5.2 | §3 `EmploymentType` gains the seventh case **`freelance`** (`isSalaried()` and `leaveEligibleByDefault()` both false for it) and is marked the single owner; phase-04 casts `job_openings.employment_type` to it and declares no copy. |
| F-5.4 | §3 rows for `LedgerEntryType` and `PaymentMethod` restated as **declared here, reused (never redeclared) by the spine, phases 10-12, 13, 14-17 and 18**; the same sentence added to §13.1 and covered by the rewritten [D-HR-14]. |
| F-9.1 | [D-HR-3] (§2.1) no longer claims a decision of its own: it **cites D19** (the soft-delete category rule, stated in `CLAUDE.md` §3), with **D16** additionally cited for the payroll / advance money tables; §12.2 Q2 marked answered. |
| F-11.1 | **D32 applied.** [D-HR-2] narrowed - a *duty* is `employees.id`, while who performed an act, who is assigned work and who holds a timer are `users.id`, bridged by `employees.user_id`. [D-HR-1]'s premise, §1.2's "5 / 6 - Nothing" row and §12.2 Q1 corrected accordingly, and §13.1's `tasks.assigned_employee_id` / `projects.project_manager_id` / `project_user` / time-tracking `employee_id` request **deleted**. |
| F-10.1 (§4.2) | §13.2 renumbered: the old "D19" row now **cites D19**, the payroll-immutability decision becomes **D36**, and the staff-identity row now **cites D32** with the narrowed wording. No contract invents a number. |
| Factual drift #1 | §5 and §13.1 no longer call `hr` "the first group added since Phase 2": `website` (phase-03/04, sort 75), `crm` (phase-05) and `projects` (phase-06, sort 86) precede it. |
| F-9.2 ("every contract" clause) | §13.2 gains the row "every FK index named in the `Keys` blocks of §2 has a row in `tests/Support/index-manifest.php`" (**D60**). |
