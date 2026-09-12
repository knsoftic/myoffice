# DATA MODEL — 03 Software house (CRM, delivery, HR)

## 1. Scope

This file is the data-model slice for the **software-house operation**: the CRM and client side of
[`../phases/phase-05.md`](../phases/phase-05.md) (leads, lead pipeline, clients, client contacts, client
documents, the client panel), the delivery side of [`../phases/phase-06.md`](../phases/phase-06.md)
(projects, value revisions, members, milestones, tasks, subtasks, checklists, comments, mentions,
attachments, time entries and segments) and the people side of
[`../phases/phase-07.md`](../phases/phase-07.md) (departments, designations, employees, skills, documents,
shifts, holidays, attendance, corrections, monthly summaries, leave types/balances/ledger/requests/days/
approvals, salary components and structures, advances and repayments, payroll runs, items and item
components). **44 tables, 55 enum declarations, 13 new module slugs, 94 settings keys.** It is written
**after** the convergence applied on 2026-09-12, so every name here is the canonical post-apply name: the
decisions that bind it are [`../design/resolutions.md`](../design/resolutions.md) §2–§4 (D19, D21, D24,
D27, D28, D29, D30, D31, D32, D33, D34, D35, D36, plus cited D11, D16) and, on anything touching money,
[`../design/finance-commission-spine.md`](../design/finance-commission-spine.md), which outranks every
phase contract. Phase 5/6/7 own **no** financial table: `project_payments`, `payment_reversals`, the nine
collaborator tables and the four student-fee tables are Phase 10's (spine §2.1); `invoices`, `expenses`,
`incomes` and `finance_reversals` are Phase 13's. Nothing below is marked as existing unless a contract
defines it.

**Conventions in force** (`CLAUDE.md` §3, resolutions §5): money is `decimal(15,2)` via
`App\Support\Money` (bcmath, intermediate scale 6, final half-up at 2); every `*_rate` / `*_percentage`
column is `decimal(8,4)`; statuses are `string(32)` + a string-backed enum with `label()` / `color()`;
permissions are `{module_slug}.{ability}`; routes are `panel.resource.action`; soft deletes are the
default and are **deliberately absent on append-only tables** (D19, D16). **No Phase 5 or Phase 6 table
carries `branch_id`** (D11 scopes branch-readiness to institute tables; phase-05 §12.2 Q4, phase-06 §2);
**eight Phase 7 tables do** carry a nullable `branch_id` under D11.

### 1.1 Legend

| Mark | Meaning |
|---|---|
| `$` | money column, `decimal(15,2)`, arithmetic through `App\Support\Money` only |
| `%` | percentage / rate column, `decimal(8,4)` (F-7.1) |
| `G` | STORED generated column — raw SQL in the migration, must fail loudly if MariaDB rejects it |
| `U` | carries a UNIQUE index (often over a NULL-tolerant guard column) |
| `SD = yes` | `deleted_at` present (mutable business table) |
| `SD = no` | append-only — **no `deleted_at`**, per D19 (+ D16 where the row is money) |

---

## 2. Table inventory

### 2.1 Phase 5 — CRM and clients (9 tables, no pivot tables)

Migration order: `clients` -> `client_contacts` -> `client_documents` -> `leads` -> `lead_activities` ->
`lead_follow_ups` -> `lead_conversions` -> `lead_imports` -> `lead_import_rows` ->
`add_crm_deferred_foreign_keys`.

| Table | Owning phase | Purpose | Key columns | SD | Key relationships |
|---|---|---|---|---|---|
| `leads` | 5 (§2.1) | §18 pipeline record: the sales lead from first contact to won/lost | `lead_no` U immutable, `name`, `email`/`phone`/`whatsapp` + three `*_normalized`, `country_code`, `service_id`, `interested_service`, **`budget_amount` $** (the only money on a lead), `source` (InquirySource), `status` (LeadStatus), `status_changed_at`, `assigned_to`/`assigned_at`/`assigned_by`, `follow_up_at` (cache of the one open follow-up), `last_contacted_at`, `last_activity_at`, `lost_reason`, `won_at`, `duplicate_of_lead_id`, `client_id`, `converted_at`/`converted_by`, `referral_code_captured`, `referral_recorded_at`, `referral_visit_id`, `contact_inquiry_id` U, `lead_import_id` | yes | belongsTo `services` (Ph4, deferred FK), `users` ×3, `clients`, self (`duplicateOf`), `contact_inquiries` (**Ph4**, deferred FK, F-2.1), `lead_imports`; hasMany `lead_activities`, `lead_follow_ups`, `lead_conversions`, self (`duplicates`); hasOne `lead_follow_ups` (`openFollowUp`), `lead_conversions` (`activeConversion`); referenced by `collaborator_referrals.lead_id` (spine §2.8) and `projects.lead_id` (Ph6) |
| `lead_activities` | 5 (§2.2) | the lead timeline: notes, calls, system facts, status and assignment changes | `lead_id` cascade, `type` (LeadActivityType), `is_system`, `subject`, `body`, `outcome` (LeadContactOutcome), `duration_minutes`, `from_status`/`to_status` (LeadStatus), `from_user_id`/`to_user_id`, `lead_follow_up_id`, `related_lead_id`, `occurred_at`, `meta` json | yes | belongsTo `leads`, `lead_follow_ups`, `users` ×3, `leads` (`relatedLead`) |
| `lead_follow_ups` | 5 (§2.3) | §18 follow-up made operational: at most one open row per lead, reminder, outcome, reschedule chain | `lead_id` cascade, `assigned_to`, `type` (LeadFollowUpType), `scheduled_at`, `remind_before_minutes`, `reminder_due_at`, `reminder_sent_at`, `status` (LeadFollowUpStatus), **`open_guard` G U**, `completed_at`/`completed_by`, `outcome`, `previous_follow_up_id` U, `cancel_reason` | yes | belongsTo `leads`, `users` ×2, self (`previousFollowUp`); hasOne self (`nextFollowUp`); hasMany `lead_activities` |
| `lead_conversions` | 5 (§2.4) | the §107 conversion audit trail — lead to client (and optionally project) | `lead_id` restrict, `conversion_type` (LeadConversionType), `client_id` restrict, `created_client`, `matched_by`, `project_id` (deferred FK Ph6), `from_status`, `lead_snapshot` json, `field_map` json, **`budget_amount` $** (snapshot), `referral_code` (snapshot), `collaborator_referral_id` (deferred FK Ph10), `converted_at`/`converted_by`, `superseded_at`/`supersede_reason`, **`active_guard` G U** | **no (D19)** | belongsTo `leads`, `clients`, `projects` (Ph6), `collaborator_referrals` (spine), `users`. `updating` hook permits only `superseded_at`, `supersede_reason`, `project_id`, `collaborator_referral_id`, `updated_by`; `deleting` throws (no DB trigger — not a money table) |
| `lead_imports` | 5 (§2.5) | one CSV import batch (F-13.8 = build) | `original_filename`, `stored_path` (private `local` disk, `crm/imports/`), `file_hash`, `delimiter`, `encoding`, `column_map` json, `defaults` json, `duplicate_strategy`, `status` (LeadImportStatus), `total_rows`, `created_count`/`updated_count`/`skipped_count`/`failed_count`, `error_report_path`, `started_at`/`finished_at`, `failure_message` | yes | hasMany `lead_import_rows`, `leads`; belongsTo `users` |
| `lead_import_rows` | 5 (§2.6) | per-row import result and the retry idempotency guard | `lead_import_id` cascade, `row_number`, `raw` json, `status` (LeadImportRowStatus), `lead_id`, `duplicate_lead_id`, `duplicate_match_type`, `errors` json, **`UNIQUE uq_lir_row(lead_import_id, row_number)`** | **no (D19)** | belongsTo `lead_imports`, `leads` ×2. No blameable either (append-only child of a blameable batch) |
| `clients` | 5 (§2.7) | §19 client master; `client_code` is the human "Client ID" | `client_code` U immutable, `user_id` U (portal login, D2), `client_type` (ClientType), `name`, `company_name`, contact trio + `*_normalized`, `website`, `industry`, `about`, `logo_path`, address block, `billing_same_as_address`, `billing_address`, `tax_registered`, `tax_number`, `sales_tax_number`, `cnic`, `tax_exempt`, **`tax_rate_override` %**, **`withholding_tax_rate` %**, `tax_notes`, `currency`, `payment_terms_days`, `status` (ClientStatus), `status_reason`, `portal_enabled`, `portal_invited_at`, `account_manager_id` (F-3.3: the only name for "assigned staff"), `source` (InquirySource), `lead_id` (not unique), `referral_code_captured`, `referral_recorded_at`, `notes` | yes | belongsTo `users` (`portalUser`, `accountManager`), `leads` (`originLead`); hasMany `client_contacts`, `client_documents`, `leads`, `lead_conversions`, and — through D28 capability contracts only — `projects` (6), `invoices` (13), `project_payments` (10), `support_tickets` / `meetings` (22); referenced by `collaborator_referrals.client_id` |
| `client_contacts` | 5 (§2.8) | additional people at a client, each optionally a portal login | `client_id` cascade, `user_id` U, `name`, `designation`, `department`, `email` + `email_normalized`, `phone` + `phone_normalized`, `whatsapp`, `is_primary`, **`primary_guard` G U**, `is_billing_contact`, `portal_access`, `receives_notifications` | yes | belongsTo `clients`, `users` |
| `client_documents` | 5 (§2.9) | §19 client documents: contracts, NDAs, tax certificates — private by default | `client_id` cascade, `title`, `category` (ClientDocumentCategory), `disk` (`local`), `path` (hashed), `original_name`, `mime_type` (sniffed with `finfo`), `extension`, `size_bytes`, `checksum`, **`visible_to_client`** (the only panel exposure gate), `shared_at`/`shared_by`, `valid_from`, `expires_at` | yes | belongsTo `clients`, `users` ×2. A named exception to the `attachments` store (`CLAUDE.md` §3 block B) because it carries sharing and expiry behaviour |

### 2.2 Phase 6 — projects, tasks, time (11 tables)

| Table | Owning phase | Purpose | Key columns | SD | Key relationships |
|---|---|---|---|---|---|
| `projects` | 6 (§2.1) | §20 project master plus the five spine columns the commission denominator needs | `code` U, `name`, `client_id` **RESTRICT**, `lead_id`, `project_manager_id` (**`users.id`**, F-3.11/D32), `service_id`, `collaborator_id` (display snapshot only — R5/D37), `referral_code`, `referral_date`, `commission_type` (CommissionCalculationType), **`commission_rate` %**, **`commission_fixed_amount` $**, `project_type` (ProjectType), `priority` (Priority), `status` (ProjectStatus), `start_date`, `deadline`, `completed_on`, `currency`, **`budget_amount` $**, **`project_value` $**, **`discount_amount` $**, **`net_value` $ G** (`project_value - discount_amount`, INV-P2), `value_revision_count`, **`progress_percent` %**, `progress_mode` (ProgressMode), `progress_basis` (ProgressBasis), `progress_reason`, `progress_set_by`, `estimated_minutes`, `actual_seconds`, `actual_minutes` G, `actual_hours` G `decimal(10,2)` | yes (= archived) | belongsTo `clients`, `leads`, `services`, `users` ×4, `collaborators` (Ph8 guarded FK); hasMany `project_value_revisions`, `project_members`, `project_milestones`, `tasks`, `time_entries`, and later `project_payments` (10), `collaborator_referrals` (10), `invoices` (13), `expenses` (13, F-12.6); belongsToMany `users` **and** `collaborators` through `project_members`; morphMany `attachments` |
| `project_value_revisions` | 6 (§2.2) | the §107 append-only audit of every contract-value / discount / commission-override change | `project_id` restrict, `revision_no` U per project, **`old_/new_project_value` $**, **`old_/new_discount_amount` $**, **`old_/new_net_value` $**, **`delta_amount` $ G** (signed), `old_/new_commission_type`, **`old_/new_commission_rate` %**, **`old_/new_commission_fixed_amount` $**, `reason` (mandatory), `effective_on`, `changed_by`, `changed_by_name` (snapshot), `ip_address` | **no (D19 + D16)** | belongsTo `projects`, `users`. `trg_pvr_no_delete` BEFORE DELETE -> SIGNAL 45000; model `updating`/`deleting` throw `ImmutableRevisionException` first. Read by the spine §6.6 case 8 entitlement supersede |
| `project_members` | 6 (§2.3) | §20 project team — **the only project-people table** (F-2.7): no `project_user`, no `project_collaborator` | `project_id` cascade, `user_id` **xor** `collaborator_id` (`chk_pm_one_party`), `role` (ProjectMemberRole), `notes`, **`active_guard` G** carrying `uq_pm_user_active` U + `uq_pm_collaborator_active` U | yes (= removed from team) | belongsTo `projects`, `users`, `collaborators`; is the pivot model for both `belongsToMany` edges on `projects`. Phase 8-9 reads it as `belongsToMany(Project::class,'project_members')->wherePivotNull('deleted_at')->wherePivotNotNull('collaborator_id')` |
| `project_milestones` | 6 (§2.4) | §21 milestones; `amount` is the spine's `milestone` commission base | `project_id` cascade, **`name`** (F-3.10 — never `title`), `description`, `start_date`, `deadline`, **`amount` $** (NULL = not a payment milestone), **`weight` %**, **`progress_percent` %**, `status` (MilestoneStatus), `sort_order`, `completed_on` | yes | belongsTo `projects`; hasMany `tasks`, later `project_payments` (spine §2.6 `project_milestone_id`, `nullOnDelete`); morphMany `attachments`. `MilestonePolicy::delete` refuses while a payment references it |
| `tasks` | 6 (§2.5) | §22 tasks and subtasks, exactly one level deep | `project_id` cascade, `project_milestone_id`, `parent_task_id`, `depth` (0/1), `title`, `assigned_user_id` **xor** `assigned_collaborator_id`, `assigned_at`/`assigned_by`, `reporter_id`, `priority` (Priority), `status` (TaskStatus), `start_date`, `due_date`, `estimated_minutes`, `estimated_hours` G `decimal(10,2)`, `actual_seconds`, `actual_minutes` G, `actual_hours` G, **`progress_percent` %**, `checklist_total`/`checklist_done`, `subtask_total`/`subtask_done`, `comment_count`/`attachment_count`, **`is_client_visible`** default true (F-3.1), `board_position` `decimal(20,10)`, `blocked_reason`, `completed_at`/`completed_by` | yes | belongsTo `projects`, `project_milestones`, self (`parent`), `collaborators`, `users` ×4; hasMany self (`subtasks`), `task_checklist_items`, `task_comments`, `time_entries`; morphMany `attachments`. History is `activity_log` filtered by subject (D13) — no per-task history table |
| `task_checklist_items` | 6 (§2.6) | §22 checklists; toggling recomputes the task's checklist caches and the progress chain | `task_id` cascade, `title`, `is_done`, `done_at`, `done_by`, `sort_order` | yes | belongsTo `tasks`, `users` |
| `task_comments` | 6 (§2.7) | §22 comments, §59 collaborator comments | `task_id` cascade, `user_id`, `author_name` (snapshot), `body` (1..5000, stored raw, escaped on render), `visibility` (CommentVisibility — `internal` / `team` only, **no `client` case**, F-3.2), `mention_count`, `edited_at` | yes | belongsTo `tasks`, `users`; hasMany `task_comment_mentions`; morphMany `attachments`. Editable by the author inside `projects.task_comment_edit_minutes` |
| `task_comment_mentions` | 6 (§2.8) | one row per mentioned user so "mentions of me" is an index lookup | `task_comment_id` cascade, `user_id` cascade, `notified_at`, **`UNIQUE uq_tcm_pair(task_comment_id, user_id)`** | **no (D19)** | belongsTo `task_comments`, `users`. Only active members of the comment's project (or its manager) are mentionable |
| `attachments` | 6 (§2.9) | **the** polymorphic private file store; the `files` module slug governs this table and no `files` table is ever created (F-2.8, F-13.2) | `attachable_type` (morph alias: `project`, `task`, `task_comment`, `project_milestone`, **`collaborator`**, **`invoice`**), `attachable_id`, `disk` (`local`, private), `path` (`projects/{id}/{ulid}.{ext}`), `original_name`, `mime_type` (sniffed), `extension`, `size_bytes`, `checksum_sha256`, **`visibility`** (AttachmentVisibility `internal`/`team`/`client` — the only client-visibility mechanism, F-2.6), `uploaded_by`, `uploaded_by_name` (snapshot) | yes (blob removed by a job after 30 days) | morphTo `attachable`; belongsTo `users`. Later phases extend the morph map only. Streamed by a permission-checked controller (D21); blameable pair **and** `uploaded_by`/`uploaded_by_name` both kept deliberately (resolutions §8 item 10) |
| `time_entries` | 6 (§2.10) | §23 one row per work session or manual entry; holds **no** mutable running total | `project_id` restrict, `task_id` (NULL only when `projects.allow_time_without_task`), `user_id` **xor** `collaborator_id`, `recorded_by`, `source` (TimeEntrySource), `status` (TimeEntryStatus), `started_at`, `ended_at`, `work_date` (every rollup groups on this), `duration_seconds` (cache = SUM of segments), `duration_minutes` G, `duration_hours` G `decimal(10,2)`, `description`, `manual_reason`, `discard_reason` (mandatory on soft delete), **`running_guard` G** carrying `uq_te_running` U (INV-P4) | yes (= discarded) | belongsTo `projects`, `tasks`, `users` ×2, `collaborators`; hasMany `time_entry_segments`. `chk_te_duration` caps one entry at 86 400 s |
| `time_entry_segments` | 6 (§2.11) | the append-only clock record — **the only place elapsed time exists** (D33) | `time_entry_id` cascade, `user_id` **xor** `collaborator_id`, `started_at`, `ended_at`, **`duration_seconds` G** (open segment contributes 0), `end_reason` (TimerStopReason), `ip_address`, `device`, **`open_guard` G** carrying `uq_tes_open` U | **no (D19)** | belongsTo `time_entries`, `users`, `collaborators`. `updating` permits only `ended_at` + `end_reason` while `ended_at` is NULL; `deleting` throws |

### 2.3 Phase 7 — HR (24 tables)

Migration order is the 18-step set of phase-07 §2.27; step 18 (`add_hr_generated_columns_and_constraints`)
adds every STORED column, guard UNIQUE, CHECK and `BEFORE DELETE` trigger as raw SQL and must fail loudly.
Every table carries the blameable pair. `branch_id` (nullable, D11) is on `departments`, `employees`,
`work_shifts`, `holidays`, `attendances`, `attendance_monthly_summaries`, `leave_requests`, `payroll_runs`.

| Table | Owning phase | Purpose | Key columns | SD | Key relationships |
|---|---|---|---|---|---|
| `departments` | 7 (§2.2) | §25 dynamic departments | `code` U, `name` U-with-`deleted_at`, `description`, `head_employee_id` (added by a guarded follow-up migration — circular FK), `branch_id`, `employee_count` (cache), `is_active`, `sort_order` | yes | hasMany `employees`, `designations`; belongsTo `employees` (`head` — a **duty**, D32), `branches` |
| `designations` | 7 (§2.3) | §24 job titles made dynamic | `department_id` (NULL = usable anywhere), `title`, `code` U, `level`, `is_active`, `sort_order`, **`department_guard` G U** | yes | belongsTo `departments`; hasMany `employees` |
| `employees` | 7 (§2.4) | §24 employee master — the **organisational** identity; `users.id` remains the identity for assignment (D32) | `employee_code` U, `user_id` U (the D32 bridge), `branch_id`, `department_id` restrict, `designation_id`, `reports_to_id` (self), `work_shift_id`, `name`, `photo_path`, contact block, `email` U-when-present, `joining_date`, `employment_type` (EmploymentType), `status` (EmployeeStatus), `status_reason`, `exit_date`/`exit_reason`, `weekly_off_days` json, `is_attendance_exempt`, **`current_gross_salary` $** (cache of the active structure, behind `employees.view_financial`), emergency-contact block, `bio`, `notes` | yes | belongsTo `users`, `departments`, `designations`, `branches`, `work_shifts`, self (`manager`); hasMany `employee_skills`, `employee_documents`, `attendances`, `attendance_corrections`, `attendance_monthly_summaries`, `leave_requests`, `leave_balances`, `leave_balance_transactions`, `salary_structures`, `employee_advances`, `payroll_run_items`, self (`directReports`); hasOne `salary_structures` (`activeSalaryStructure`), `departments` (`headedDepartment`); belongsToMany `leave_types` via `leave_balances` and `payroll_runs` via `payroll_run_items`. Referenced by `teachers.employee_id` (14-17), `team_members.employee_id` (Ph4, F-3.13), `job_applications.employee_id` (Ph4, F-3.12) — all nullable, FKs deferred to this phase's guarded migration. **No `gender` column** (F-5.8) |
| `employee_skills` | 7 (§2.5) | §24 skills, filterable ("who knows Laravel") | `employee_id` cascade, `name` (trimmed, title-cased), `level` (SkillLevel), `years_experience` `decimal(4,1)`, `sort_order`, `UNIQUE (employee_id, name, deleted_at)` | yes | belongsTo `employees` |
| `employee_documents` | 7 (§2.6) | §24 documents with expiry tracking; a named exception to `attachments` | `employee_id` cascade, `document_type` (EmployeeDocumentType), `title`, `document_number`, `file_path` (**private `local` disk**, hashed), `file_name`, `mime_type`, `size_kb`, `issued_on`, `expires_on`, `reminder_days`, `is_confidential`, `verification_status` (DocumentVerificationStatus), `verified_by`/`verified_at`, `expiry_notified_at` | yes | belongsTo `employees`, `users` (`verifier`). File deleted only on `forceDeleted` |
| `work_shifts` | 7 (§2.7) | the definition "late" and "early leave" are measured from | `code` U, `name`, `branch_id`, `start_time`, `end_time`, `crosses_midnight`, `break_minutes`, `expected_minutes`, `grace_in_minutes`, `grace_out_minutes`, `min_full_day_minutes`, `min_half_day_minutes`, `weekly_off_days` json, `is_default`, **`default_guard` G U** (one default per branch) | yes | hasMany `employees`, `attendances`; belongsTo `branches`. Editing changes nothing historical — every attendance row snapshots its window (HR-2) |
| `holidays` | 7 (§2.8) | one row per holiday date so "is this a holiday?" is an index hit | `branch_id` (NULL = all), `holiday_date`, `title`, `holiday_type` (HolidayType), `is_paid`, `is_recurring_yearly`, `is_active`, **`holiday_guard` G U** | yes | belongsTo `branches`; hasMany `attendances` |
| `attendances` | 7 (§2.9) | §26 one row per employee per calendar date, weekends and holidays included | `employee_id` restrict, `branch_id`, `attendance_date`, `work_shift_id`, `day_type` (DayType), `holiday_id`, `status` (AttendanceStatus, 7 states), snapshot block (`expected_in_at`, `expected_out_at`, `expected_minutes`, `grace_in_minutes`, `grace_out_minutes`, `break_minutes`), `check_in_at`/`check_out_at`, `check_in_source`/`check_out_source` (AttendanceSource), `check_in_ip`/`check_out_ip`, `worked_minutes`, `late_minutes`, `early_leave_minutes`, `overtime_minutes`, **`payable_factor` %** (0..1 — the only figure payroll reads per day), `leave_request_id`, `leave_type_id`, `is_manual`, `requires_correction`, `locked_at`, `locked_by_payroll_run_id`, **`day_guard` G U** (HR-1) | yes | belongsTo `employees`, `work_shifts`, `holidays`, `leave_requests`, `leave_types`, `branches`, `payroll_runs` (`lockedByRun`); hasMany `attendance_corrections`. Update/delete refused once `locked_at` is set |
| `attendance_corrections` | 7 (§2.10) | the audit trail of every manual attendance change (both `self_request` and `hr_direct`) | `attendance_id`, `employee_id` restrict, `attendance_date`, `correction_type` (AttendanceCorrectionType), `source` (CorrectionSource), `old_values` json, `new_values` json (whitelisted), `reason` (mandatory, min 10 chars), `status` (AttendanceCorrectionStatus), `requested_by`/`requested_at`, `reviewed_by`/`reviewed_at`, `review_comment`, `applied_at` | **no (D19)** | belongsTo `attendances`, `employees`, `users` ×2. `deleting` throws `AppendOnlyRowException`; `BEFORE DELETE` trigger raises SIGNAL 45000 |
| `attendance_monthly_summaries` | 7 (§2.11) | §26 monthly summary — **the only attendance input payroll reads** (HR-5) | `employee_id` restrict, `branch_id`, `period_year`/`period_month`, `period_start`/`period_end`, `calendar_days`, `working_days`, `weekly_off_days`, `holiday_days`, `present_days`, `late_count`, `early_leave_count`, `half_day_count`, `absent_days`, `paid_leave_days`, `unpaid_leave_days`, `payable_days`, `lop_days`, minute totals, **`attendance_percentage` %**, `generated_at`, `is_final`, `locked_at`, `locked_by_payroll_run_id`, `UNIQUE uq_ams_period(employee_id, period_year, period_month)` | **no (D19, derived cache)** | belongsTo `employees`, `branches`, `payroll_runs`; referenced by `payroll_run_items.attendance_monthly_summary_id`. Rebuild is idempotent and refused once locked |
| `leave_types` | 7 (§2.12) | §27 leave types plus quota, accrual, carry-forward and approval policy | `code` U, `name` U-with-`deleted_at`, `annual_quota_days` `decimal(6,2)`, `is_paid`, `accrual_method` (LeaveAccrualMethod), `accrual_days_per_month`, `accrue_from_joining`, `carry_forward_enabled`, `max_carry_forward_days`, `carry_forward_expiry_months`, `max_consecutive_days`, `min_notice_days`, `allow_half_day`, `allow_negative_balance`, `requires_attachment`, `attachment_required_after_days`, `excludes_weekends`, `excludes_holidays`, `applies_to_employment_types` json (validated against EmploymentType), `allowed_on_probation`, `approval_levels` (1..2), `is_encashable`, `color`, `is_active`, `sort_order` | yes | hasMany `leave_requests`, `leave_balances`, `leave_balance_transactions`; belongsToMany `employees` via `leave_balances` |
| `leave_balances` | 7 (§2.13) | a **cache** over the day ledger, per employee / type / leave year (HR-7) | `employee_id` restrict, `leave_type_id` restrict, `leave_year`, `period_start`/`period_end`, `entitled_days`, `carried_forward_days`, `accrued_days`, `adjusted_days` (may be negative), `consumed_days`, `pending_days`, `encashed_days`, `expired_days`, `available_days` (cache of the identity), `last_recalculated_at`, `UNIQUE uq_lb(employee_id, leave_type_id, leave_year)` | **no (D19)** | belongsTo `employees`, `leave_types`; hasMany `leave_balance_transactions`. Written only by `LeaveBalanceService` under `lockForUpdate()`, always in the ledger row's transaction |
| `leave_balance_transactions` | 7 (§2.14) | the append-only day ledger every balance is derived from | `employee_id` restrict, `leave_type_id` restrict, `leave_year`, `entry_type` (**LedgerEntryType**), `reason` (LeaveLedgerReason), `days` (positive magnitude, D-HR-4), **`signed_days` G** (the only column anything sums), `leave_request_id`, `balance_after_days`, `notes`, `performed_by`, `occurred_on` (value date) | **no (D19)** | belongsTo `employees`, `leave_types`, `leave_requests`, `users`. Nothing may UPDATE the identity columns; `deleting` throws and a `BEFORE DELETE` trigger fires. A wrong entry is corrected by an opposite `manual_adjustment` entry |
| `leave_requests` | 7 (§2.15) | §27 the leave application and its lifecycle | `request_number` U, `employee_id` restrict, `leave_type_id` restrict, `branch_id`, `from_date`/`to_date`, `day_portion` (LeaveDayPortion), `total_days`, `paid_days`, `unpaid_days`, `reason`, `attachment_path` (**private disk**), `attachment_name`, `contact_during_leave`, `status` (LeaveRequestStatus), `current_approval_level`, `balance_snapshot_days`, `applied_on`, `approved_at`, `rejected_at`/`rejection_reason`, `cancelled_at`/`cancelled_by`/`cancellation_reason`, `attendance_applied_at` | yes | belongsTo `employees`, `leave_types`, `branches`, `users`; hasMany `leave_request_days`, `leave_approvals`, `attendances`, `leave_balance_transactions` |
| `leave_request_days` | 7 (§2.16) | the day-by-day expansion attendance **and** quota both read | `leave_request_id` cascade, `employee_id` (denormalised for the guard), `leave_date`, `day_portion`, `day_fraction` (1.0000 / 0.5000), `is_counted`, `is_paid` (snapshot), `is_active`, `attendance_id`, **`day_guard` G U** (HR-8: one counted leave day per employee per date) | **no (D19)** | belongsTo `leave_requests`, `employees`, `attendances` |
| `leave_approvals` | 7 (§2.17) | the approval chain, one row per level | `leave_request_id` cascade, `level` (1 = reporting manager, 2 = HR), `expected_approver_employee_id` (a **duty**, D32), `expected_approver_user_id`, `fallback_permission` (`leaves.approve`), `status` (LeaveApprovalStatus), `acted_by_user_id` (**who acted** = `users.id`, D32), `acted_at`, `comment`, `notified_at`, `UNIQUE uq_la_level(leave_request_id, level)` | **no (D19)** | belongsTo `leave_requests`, `employees`, `users`. Level 2 cannot act before level 1 approves; an employee may never approve their own leave |
| `salary_components` | 7 (§2.18) | §28 typed allowances and deductions; the group decides the side | `code` U, `name`, `component_group` (SalaryComponentGroup), `side` (SalaryComponentType, written from the group), `calculation_type` (SalaryComponentCalculation), **`default_amount` $**, **`default_rate` %**, `is_taxable`, `affects_gross`, `is_statutory`, `is_attendance_dependent`, `is_system`, `print_label`, `is_active`, `sort_order` | yes | hasMany `salary_structure_components`, `payroll_run_item_components`. `is_system` rows (`BASIC`, `UNPAID_LEAVE`, `LATE`, `ADV_RECOVERY`, `TAX`, `ROUNDING`) cannot be renamed, re-sided or deleted |
| `salary_structures` | 7 (§2.19) | §28 per-employee salary as **immutable effective-dated versions** (HR-10) | `employee_id` restrict, `version`, `supersedes_id`/`superseded_by_id`, `effective_from`/`effective_to`, **`basic_salary` $**, **`gross_salary` $**, **`total_deduction_amount` $**, **`net_salary_estimate` $** (display only — no slip reads it), `currency`, `pay_frequency`, `status` (SalaryStructureStatus), `change_reason` (mandatory), `approved_by`/`approved_at`, **`open_guard` G U**, `UNIQUE uq_ss_version(employee_id, version)` | **no (D19 + D16)** | belongsTo `employees`, self (`predecessor`/`successor`), `users`; hasMany `salary_structure_components`, `payroll_run_items`. `updating` allows only `status`, `effective_to`, `superseded_by_id`, `approved_*`, `updated_*`; a raise is a new version |
| `salary_structure_components` | 7 (§2.20) | the version's lines, written with it and never edited | `salary_structure_id` cascade, `salary_component_id` restrict, snapshot block (`component_code`, `component_name`, `component_group`, `side`, `calculation_type`, `is_taxable`, `affects_gross`), **`rate` %**, **`amount` $**, `sort_order`, `UNIQUE uq_ssc(salary_structure_id, salary_component_id)` | **no (D19)** | belongsTo `salary_structures`, `salary_components` |
| `employee_advances` | 7 (§2.21) | §28 salary advance, its approval, disbursement and recovery plan | `advance_number` U, `employee_id` restrict, **`amount` $**, `reason`, `requested_on`, `installment_count`, **`installment_amount` $** (via `Money::div`, residual on the last), `first_recovery_year`/`first_recovery_month`, **`recovered_amount` $**, **`waived_amount` $**, **`outstanding_amount` $**, `status` (AdvanceStatus), approval/rejection block, `disbursed_on`, `disbursement_method` (**PaymentMethod**), `disbursement_reference`, `settled_at`; `chk_adv_ceiling`: `recovered + waived <= amount` (HR-19) | **no (D19 + D16)** | belongsTo `employees`, `users` ×2; hasMany `employee_advance_repayments`. `amount`, `employee_id`, `disbursed_on` immutable once disbursed; cancellation and write-off are statuses |
| `employee_advance_repayments` | 7 (§2.22) | the append-only recovery history (payroll, manual, waiver, correction) | `employee_advance_id` restrict, `payroll_run_item_id` restrict, `entry_type` (**LedgerEntryType**), `recovery_type` (AdvanceRecoveryType), **`amount` $** (positive magnitude), **`signed_amount` $ G**, `recovered_on`, `reference`, `notes` (mandatory for `waiver`/`correction`), `performed_by`, **`UNIQUE uq_aar_item(employee_advance_id, payroll_run_item_id)`** | **no (D19 + D16)** | belongsTo `employee_advances`, `payroll_run_items`, `users`. Only `notes` is updatable; `deleting` throws and a trigger fires |
| `payroll_runs` | 7 (§2.23) | §28 the monthly run: generated, locked, then paid (D36 — no unlock) | `run_number` U, `title`, `branch_id`, `run_type` (PayrollRunType), `parent_run_id` (a correction run's target), `period_year`/`period_month`, `period_start`/`period_end`, `payment_date`, `status` (PayrollRunStatus), `employee_count`, **`total_gross` $**, **`total_deductions` $**, **`total_net` $**, **`total_paid` $**, `day_basis`/`lop_basis`/`tax_mode` (settings snapshots), `settings_snapshot` json, `generated_at`/`generated_by`, `locked_at`/`locked_by` (the immutability line), `paid_at`, `cancelled_at`/`cancelled_by`/`cancellation_reason`, **`regular_guard` G U** (one live regular run per branch per month) | **no (D19 + D16)** | belongsTo `branches`, self (`parentRun`), `users` ×3; hasMany `payroll_run_items`, self (`correctionRuns`), `attendances` (`lockedAttendances`), `attendance_monthly_summaries` (`lockedSummaries`); belongsToMany `employees` via `payroll_run_items`. `PayrollRunPaid` is the event Phase 13's `RecordPayrollExpense` listens to (D44) |
| `payroll_run_items` | 7 (§2.24) | the salary slip itself — a snapshot, reproducible years later | `payroll_run_id` restrict, `run_type` (denormalised for the sign CHECK), `slip_number` U, `employee_id` restrict, `salary_structure_id` restrict, `attendance_monthly_summary_id` restrict, `corrects_item_id` restrict, snapshot block (`department_name`, `designation_title`, `employment_type`, `joining_date`), **`basic_salary` $**, **`contracted_gross` $**, **`gross_earnings` $**, **`total_deductions` $**, **`taxable_gross` $**, **`tax_amount` $**, **`allowance_amount` $**, **`bonus_amount` $**, **`commission_amount` $** (an *employee* commission, never a collaborator one), **`overtime_amount` $**, **`advance_recovery_amount` $**, **`unpaid_leave_deduction` $**, **`late_deduction` $**, **`net_salary` $**, day snapshots (`payable_days`, `lop_days`, `working_days`, `present_days`, `paid_leave_days`, `unpaid_leave_days`, `late_count`, `day_divisor`), **`per_day_amount` $**, `calculation_snapshot` json, `status` (PayrollItemStatus), `hold_reason`, `paid_at`, `payment_method` (**PaymentMethod**), `payment_reference`, `paid_by`, `UNIQUE uq_pri_employee(payroll_run_id, employee_id)` | **no (D19 + D16)** | belongsTo `payroll_runs`, `employees`, `salary_structures`, `attendance_monthly_summaries`, self (`correctedItem`), `users`; hasMany `payroll_run_item_components`, `employee_advance_repayments`, self (`corrections`). `chk_pri_sign` allows negatives only on a `correction` run |
| `payroll_run_item_components` | 7 (§2.25) | the typed lines the slip prints and the totals sum (HR-13) | `payroll_run_item_id` cascade, `run_type` (denormalised), `salary_component_id` restrict, snapshot block (`component_code`, `component_name`, `component_group`, `side`, `calculation_type`, `is_taxable`), **`rate` %**, **`base_amount` $**, `quantity`, **`amount` $**, `source_type`/`source_id` (`employee_advance`, `leave_request`, or a future commission source), `calculation_note`, `sort_order`, `UNIQUE uq_pric_line(payroll_run_item_id, component_code)` | **no (D19 + D16)** | belongsTo `payroll_run_items`, `salary_components` |

### 2.4 Money and percentage register

Every column below is `decimal(15,2)` (money, `Money`/bcmath only) or `decimal(8,4)` (rate/percentage,
F-7.1). Nothing else in this domain is money or a percentage; hour and day columns are listed separately
so nobody mistakes them.

| Kind | Columns |
|---|---|
| Money `decimal(15,2)` — Phase 5 | `leads.budget_amount`, `lead_conversions.budget_amount` |
| Money `decimal(15,2)` — Phase 6 | `projects.budget_amount`, `.project_value`, `.discount_amount`, `.net_value` (G), `.commission_fixed_amount`; `project_value_revisions.old_project_value`, `.new_project_value`, `.old_discount_amount`, `.new_discount_amount`, `.old_net_value`, `.new_net_value`, `.delta_amount` (G, signed), `.old_commission_fixed_amount`, `.new_commission_fixed_amount`; `project_milestones.amount` |
| Money `decimal(15,2)` — Phase 7 | `employees.current_gross_salary`; `salary_components.default_amount`; `salary_structures.basic_salary`, `.gross_salary`, `.total_deduction_amount`, `.net_salary_estimate`; `salary_structure_components.amount`; `employee_advances.amount`, `.installment_amount`, `.recovered_amount`, `.waived_amount`, `.outstanding_amount`; `employee_advance_repayments.amount`, `.signed_amount` (G); `payroll_runs.total_gross`, `.total_deductions`, `.total_net`, `.total_paid`; `payroll_run_items.basic_salary`, `.contracted_gross`, `.gross_earnings`, `.total_deductions`, `.taxable_gross`, `.tax_amount`, `.allowance_amount`, `.bonus_amount`, `.commission_amount`, `.overtime_amount`, `.advance_recovery_amount`, `.unpaid_leave_deduction`, `.late_deduction`, `.net_salary`, `.per_day_amount`; `payroll_run_item_components.base_amount`, `.amount` |
| Percentage / rate `decimal(8,4)` | `clients.tax_rate_override`, `.withholding_tax_rate`; `projects.commission_rate`, `.progress_percent`; `project_value_revisions.old_commission_rate`, `.new_commission_rate`; `project_milestones.weight`, `.progress_percent`; `tasks.progress_percent`; `attendances.payable_factor` (a 0..1 factor, same type); `attendance_monthly_summaries.attendance_percentage`; `salary_components.default_rate`; `salary_structure_components.rate`; `payroll_run_item_components.rate` |
| Not money, not a percentage (stated to stop a "fix") | hours: `projects.actual_hours`, `tasks.estimated_hours`, `.actual_hours`, `time_entries.duration_hours` — all `decimal(10,2)` G. Day counts: `leave_types.annual_quota_days`, `.accrual_days_per_month`, `.max_carry_forward_days` `decimal(6,2)`; every `*_days` on `leave_balances`, `leave_balance_transactions`, `leave_requests`, `leave_request_days`, `attendance_monthly_summaries`, `payroll_run_items` `decimal(8,4)`. Experience: `employee_skills.years_experience` `decimal(4,1)` |

---

## 3. Relationship map

Read as parent -> children. `[Ph N]` marks a table another phase owns; `(deferred)` marks an FK a
later phase promotes in a `Schema::hasTable()`-guarded migration.

```
users [Ph 1]
  - clients.user_id (UNIQUE, portal login)
  - client_contacts.user_id (UNIQUE, extra portal login)
  - leads.assigned_to / assigned_by / converted_by / created_by / updated_by
  - projects.project_manager_id / progress_set_by            (D32: assignment -> users.id)
  - project_members.user_id (xor collaborator_id)
  - tasks.assigned_user_id (xor assigned_collaborator_id) / reporter_id / assigned_by / completed_by
  - task_comments.user_id          task_comment_mentions.user_id
  - time_entries.user_id / recorded_by      time_entry_segments.user_id
  - attachments.uploaded_by
  - employees.user_id (UNIQUE)                              (D32: the only bridge)
  - leave_approvals.acted_by_user_id / expected_approver_user_id
  - payroll_runs.generated_by / locked_by / cancelled_by     payroll_run_items.paid_by

branches [Ph 1]
  - departments.branch_id   employees.branch_id   work_shifts.branch_id   holidays.branch_id
  - attendances.branch_id   attendance_monthly_summaries.branch_id
  - leave_requests.branch_id   payroll_runs.branch_id        (D11; no Phase 5/6 table has one)

contact_inquiries [Ph 4]
  - leads.contact_inquiry_id (UNIQUE uq_leads_inquiry, deferred)   F-2.1 / F-3.7
      the only path is CrmLeadInquiryTarget registered into Phase 4's InquiryRouter

services [Ph 4]
  - leads.service_id (deferred)            F-3.6: the column is service_id
  - projects.service_id                    ("kind of work" lives here, not in project_type)

leads
  - lead_activities          (cascade; is_system rows never editable)
  - lead_follow_ups          (at most one pending: uq_lfu_open over open_guard)
      - lead_follow_ups.previous_follow_up_id -> self (UNIQUE: one successor)
  - lead_conversions         (at most one live: uq_lc_lead_active over active_guard)
      -> clients (restrict)   -> projects.id (deferred, Ph 6)
      -> collaborator_referrals.id (deferred, Ph 10) = the attribution evidence link
  - leads.duplicate_of_lead_id -> self        (cycle-checked, depth 10)
  - leads.client_id -> clients                (set by conversion; the lead is never deleted)
  - leads.referral_visit_id -> collaborator_referral_visits.id (deferred, Ph 9)   F-3.5
  - leads.lead_import_id -> lead_imports      (deferred inside this phase)
  - collaborator_referrals.lead_id -> leads   [Ph 10 spine §2.8, nullOnDelete]

lead_imports
  - lead_import_rows         (UNIQUE (lead_import_id, row_number) = retry idempotency)
      - lead_import_rows.lead_id / duplicate_lead_id -> leads

clients
  - client_contacts          (one primary: uq_cc_primary over primary_guard)
  - client_documents         (private disk; visible_to_client is the only panel gate)
  - leads                    (repeat business: many leads converted onto one client)
  - lead_conversions
  - projects.client_id       RESTRICT  [Ph 6]
  - collaborator_referrals.client_id   [Ph 10]
  - invoices.client_id [Ph 13]   project_payments.client_id [Ph 10]
  - support_tickets.client_id / meetings.client_id [Ph 22]
      all five reached only through D28 capability contracts / ClientPortalRegistry

projects
  - project_value_revisions  (append-only; trg_pvr_no_delete; revision_no unique per project)
  - project_members          (user xor collaborator; one active row per person per project)
  - project_milestones
      - tasks.project_milestone_id
      - project_payments.project_milestone_id [Ph 10, nullOnDelete] = the milestone commission base
  - tasks
      - tasks.parent_task_id -> self           (depth 0 or 1 only)
      - task_checklist_items
      - task_comments
          - task_comment_mentions (UNIQUE pair)
          - attachments (attachable_type = task_comment)
      - time_entries
      - attachments (attachable_type = task)
  - time_entries             (project_id denormalised for rollups)
      - time_entry_segments  (append-only; the only store of elapsed time)
  - attachments (attachable_type = project | project_milestone)
  - project_payments.project_id [Ph 10]   invoices.project_id / expenses.project_id [Ph 13]
  - collaborator_referrals.project_id [Ph 10]  = the authority for a collaborator's project scope
  - projects.lead_id -> leads   projects.collaborator_id -> collaborators (deferred, Ph 8)

collaborators [Ph 8]  (all five FKs promoted by Phase 8's add_collaborator_fks_to_project_tables)
  - projects.collaborator_id          (display snapshot only; R5 / D37)
  - project_members.collaborator_id   (restrictOnDelete)  = one of the two scope authorities
  - tasks.assigned_collaborator_id
  - time_entries.collaborator_id      time_entry_segments.collaborator_id
  - attachments (attachable_type = collaborator)   F-13.2

departments
  - designations             (department_guard: one title per department)
  - employees.department_id  (restrict)
  - departments.head_employee_id -> employees   (a duty, D32; guarded follow-up migration)

employees
  - employee_skills          employee_documents
  - employees.reports_to_id -> self             (the leave chain and the team scope; depth capped at 5)
  - attendances
      - attendance_corrections
      - attendances.leave_request_id -> leave_requests   attendances.holiday_id -> holidays
      - attendances.locked_by_payroll_run_id -> payroll_runs
  - attendance_monthly_summaries     (UNIQUE employee + year + month)
      - payroll_run_items.attendance_monthly_summary_id   = the only attendance -> payroll bridge
  - leave_balances           (cache; UNIQUE employee + type + year)
  - leave_balance_transactions  (append-only; signed_days is the only summable column)
  - leave_requests
      - leave_request_days   (day_guard: one counted leave day per employee per date)
          - leave_request_days.attendance_id -> attendances
      - leave_approvals      (UNIQUE request + level)
  - salary_structures        (open_guard: one open version per employee)
      - salary_structure_components
      - payroll_run_items.salary_structure_id
  - employee_advances
      - employee_advance_repayments -> payroll_run_items (UNIQUE advance + item)
  - payroll_run_items
  - teachers.employee_id [Ph 14-17]   team_members.employee_id [Ph 4]   job_applications.employee_id [Ph 4]
      all nullable, FKs deferred to Phase 7's guarded migration

work_shifts -> employees.work_shift_id, attendances.work_shift_id (each row snapshots its window)
leave_types -> leave_requests, leave_balances, leave_balance_transactions
salary_components -> salary_structure_components, payroll_run_item_components

payroll_runs
  - payroll_run_items
      - payroll_run_item_components
      - payroll_run_items.corrects_item_id -> self       (a correction is a new item, D36)
  - payroll_runs.parent_run_id -> self                   (correction / bonus / final_settlement run)
  - attendances.locked_by_payroll_run_id                 (lock cascade, HR-18)
  - attendance_monthly_summaries.locked_by_payroll_run_id
  - expenses.source_type='payroll_run', source_id=run.id [Ph 13, UNIQUE, D44]
```

---

## 4. Enums

55 declarations live in this domain (13 + 14 + 28), all in the flat `app/Enums/` namespace, string-backed,
each with `label()`, `color()` and `static options()`.

### 4.1 Declared by Phase 5 (13)

| Enum | Casts | Note |
|---|---|---|
| `LeadStatus` | `leads.status`, `lead_activities.from_status`/`to_status`, `lead_conversions.from_status` | `allowedTransitions()` is the §2.11 transition table the server enforces |
| `LeadActivityType` | `lead_activities.type` | |
| `LeadContactOutcome` | `lead_activities.outcome`, `lead_follow_ups.outcome` | `countsAsContact()` false for `wrong_number` |
| `LeadFollowUpType` | `lead_follow_ups.type` | |
| `LeadFollowUpStatus` | `lead_follow_ups.status` | only `pending` fills `open_guard` |
| `LeadConversionType` | `lead_conversions.conversion_type` | |
| `LeadDuplicateMatchType` | `lead_conversions.matched_by`, `lead_import_rows.duplicate_match_type` | |
| `LeadImportStatus` | `lead_imports.status` | |
| `LeadImportRowStatus` | `lead_import_rows.status` | |
| `LeadImportDuplicateStrategy` | `lead_imports.duplicate_strategy` | |
| `ClientType` | `clients.client_type` | |
| `ClientStatus` | `clients.status` | `canUsePortal()` true only for `active` |
| `ClientDocumentCategory` | `client_documents.category` | `defaultVisibleToClient()` |

### 4.2 Declared by Phase 6 (14)

| Enum | Casts | Note |
|---|---|---|
| `ProjectStatus` | `projects.status` | 8 statuses; `progressWeight()` is the §6.3 fallback |
| `ProjectType` | `projects.project_type` | the engagement model; the *kind of work* is `service_id` |
| `ProgressMode` | `projects.progress_mode` | `auto` / `manual` (D34) |
| `ProgressBasis` | `projects.progress_basis` | `milestones` / `tasks` |
| `MilestoneStatus` | `project_milestones.status` | `cancelled` is excluded from every denominator (INV-P9) |
| `TaskStatus` | `tasks.status` | |
| **`Priority`** | `projects.priority`, `tasks.priority`, **`support_tickets.priority` [Ph 19-23]** | the shared enum; `TicketPriority` is deleted (F-5.7) |
| `ProjectMemberRole` | `project_members.role` | `canBeCollaborator()` — a collaborator is never `manager` |
| `TimeEntrySource` | `time_entries.source` | |
| `TimeEntryStatus` | `time_entries.status` | |
| `TimerStopReason` | `time_entry_segments.end_reason` | |
| **`AttachmentVisibility`** | `attachments.visibility`; read by phase-05 §9.2 and phase-19-23 | `internal` / `team` / `client` — the only client-visibility mechanism (F-2.6) |
| **`CommentVisibility`** | `task_comments.visibility` | `internal` / `team` only — **no `client` case** (F-3.2) |
| **`CommissionCalculationType`** | `projects.commission_type`; reused by the spine and phase-10-12 | declared here because Phase 6 migrates first (F-5.5, R3) — the spine and 10-12 **reuse, never re-create** |

### 4.3 Declared by Phase 7 (28)

| Enum | Casts |
|---|---|
| `EmployeeStatus` | `employees.status` |
| **`EmploymentType`** (7 cases incl. `freelance`) | `employees.employment_type`, `payroll_run_items.employment_type`, `leave_types.applies_to_employment_types` (validation), **`job_openings.employment_type` [Ph 4]** — Phase 7 is the single owner (F-5.2) |
| `SkillLevel` | `employee_skills.level` |
| `EmployeeDocumentType` | `employee_documents.document_type` |
| `DocumentVerificationStatus` | `employee_documents.verification_status` |
| `DayType` | `attendances.day_type` |
| `AttendanceStatus` (7 states) | `attendances.status` — deliberately **not** merged with the institute's `StudentAttendanceStatus` (F-5.9, resolutions §8 item 9) |
| `AttendanceSource` | `attendances.check_in_source`, `.check_out_source` |
| `AttendanceCorrectionType` | `attendance_corrections.correction_type` |
| `CorrectionSource` | `attendance_corrections.source` |
| `AttendanceCorrectionStatus` | `attendance_corrections.status` |
| `HolidayType` | `holidays.holiday_type` |
| `LeaveAccrualMethod` | `leave_types.accrual_method` |
| `LeaveDayPortion` | `leave_requests.day_portion`, `leave_request_days.day_portion` |
| `LeaveRequestStatus` | `leave_requests.status` |
| `LeaveApprovalStatus` | `leave_approvals.status` |
| `LeaveLedgerReason` | `leave_balance_transactions.reason` |
| **`LedgerEntryType`** | `leave_balance_transactions.entry_type`, `employee_advance_repayments.entry_type`; **reused verbatim by the spine, 10-12, 13, 14-17, 18** (F-5.4 — cases defined in spine §3, declared here because Phase 7 migrates first) |
| **`PaymentMethod`** | `employee_advances.disbursement_method`, `payroll_run_items.payment_method`; **reused by every later money phase** (F-5.4) |
| `SalaryComponentType` | `salary_components.side`, `salary_structure_components.side`, `payroll_run_item_components.side` |
| `SalaryComponentGroup` | `salary_components.component_group`, `salary_structure_components.component_group`, `payroll_run_item_components.component_group` |
| `SalaryComponentCalculation` | `salary_components.calculation_type` + both snapshot copies |
| `SalaryStructureStatus` | `salary_structures.status` |
| `PayrollRunType` | `payroll_runs.run_type`, `payroll_run_items.run_type`, `payroll_run_item_components.run_type` |
| `PayrollRunStatus` | `payroll_runs.status` |
| `PayrollItemStatus` | `payroll_run_items.status` |
| `AdvanceStatus` | `employee_advances.status` |
| `AdvanceRecoveryType` | `employee_advance_repayments.recovery_type` |

### 4.4 Consumed here, declared elsewhere

| Enum | Owner | Used on |
|---|---|---|
| `InquirySource` (11 cases) | phase-04 §3 (F-5.3) | `leads.source`, `clients.source`, and the validation of `crm.default_lead_source`. **No `LeadSource` enum exists** |
| `ReferralSource`, `ReferralStatus`, `ReferralSubject`, `CommissionScope` | spine §3 | passed through `ReferralRecorder` / written on `collaborator_referrals`; CRM and projects declare none of them |
| `Ability`, `UserStatus`, `ModuleGroup`, `RemainderPlacement` | phase-01 §2 | permissions, the blameable/user edges, `Money::distribute()` |

---

## 5. Modules, permissions and settings

### 5.1 Module slugs and permission counts

13 new slugs; 12 Phase-1 slugs whose ability list this domain finalises. Names are
`{module_slug}.{ability}` from `PermissionRegistry` — the only declaration site (R8, D4). Presets:
`READ`=2, `CRUD`=5, `CRUD_FULL`=7, `APPROVE`=2, `STATUS`=1, `ASSIGN`=1, `FILES`=2, `MONEY`=1,
`REPORTS`=3 (`view_reports`,`export`,`print`), `LOGS`=1; counts below are **distinct** permissions after
preset overlap.

| slug | New? | Phase | Group | Abilities | Permissions |
|---|---|---|---|---|---|
| `leads` | Ph 1 slug, finalised here | 5 §4.2 | SoftwareHouse | `CRUD_FULL` + `restore` + `ASSIGN` + `STATUS` + `import` + `LOGS` + `view_reports` | 13 |
| `clients` | Ph 1 slug, finalised here | 5 §4.2 | SoftwareHouse | `CRUD_FULL` + `restore` + `ASSIGN` + `STATUS` + `MONEY` + `LOGS` + `view_reports` | 13 |
| `client_documents` | **new** | 5 §4.1 | SoftwareHouse | `READ` + `create` + `edit` + `delete` + `FILES` + `STATUS` + `LOGS` | 9 |
| `projects` | Ph 1 slug | 6 §4.2 | SoftwareHouse | `CRUD_FULL` + `restore` + `STATUS` + `ASSIGN` + `MONEY` + `REPORTS` + `LOGS` | 13 |
| `project_milestones` | Ph 1 slug | 6 §4.2 | SoftwareHouse | `CRUD` + `STATUS` + `REPORTS` | 9 |
| `tasks` | Ph 1 slug | 6 §4.2 | SoftwareHouse | `CRUD_FULL` + `restore` + `STATUS` + `ASSIGN` + `REPORTS` + `LOGS` | 12 |
| `time_tracking` | Ph 1 slug | 6 §4.2 | SoftwareHouse | `CRUD` + `STATUS` + `REPORTS` + `export` + `print` + `LOGS` | 10 |
| `files` | Ph 1 slug | 6 §4.2 | Shared | `READ` + `FILES` + `delete` | 5 |
| `task_comments` | **new** | 6 §4.1 | SoftwareHouse | `READ` + `create` + `edit` + `delete` | 5 |
| `employees` | Ph 1 slug | 7 §4.2 | Hr | `CRUD_FULL` + `STATUS` + `ASSIGN` + `MONEY` + `FILES` + `import` + `REPORTS` + `LOGS` | 15 |
| `departments` | Ph 1 slug | 7 §4.2 | Hr | `CRUD` + `STATUS` + `ASSIGN` | 7 |
| `attendance` | Ph 1 slug | 7 §4.2 | Hr | `READ` + `create` + `edit` + `APPROVE` + `STATUS` + `import` + `export` + `REPORTS` + `LOGS` (**no `delete`**) | 12 |
| `leaves` | Ph 1 slug | 7 §4.2 | Hr | `READ` + `create` + `edit` + `delete` + `APPROVE` + `STATUS` + `FILES` + `export` + `REPORTS` + `LOGS` | 14 |
| `payroll` | Ph 1 slug | 7 §4.2 | Hr | `READ` + `create` + `APPROVE` + `STATUS` + `print` + `export` + `MONEY` + `REPORTS` + `LOGS` (**never `edit`/`delete`**) | 11 |
| `designations` | **new** | 7 §4.1 | Hr | `CRUD` + `STATUS` | 6 |
| `employee_documents` | **new** | 7 §4.1 | Hr | `READ` + `create` + `delete` + `FILES` + `STATUS` + `LOGS` | 8 |
| `work_shifts` | **new** | 7 §4.1 | Hr | `CRUD` + `STATUS` + `ASSIGN` | 7 |
| `holidays` | **new** | 7 §4.1 | Hr | `CRUD` + `import` + `export` | 7 |
| `leave_types` | **new** | 7 §4.1 | Hr | `CRUD` + `STATUS` | 6 |
| `leave_balances` | **new** | 7 §4.1 | Hr | `READ` + `create` + `export` + `LOGS` (**no `edit`/`delete`** — minting days is an append-only adjustment) | 5 |
| `salary_components` | **new** | 7 §4.1 | Hr | `CRUD` + `STATUS` | 6 |
| `salary_structures` | **new** | 7 §4.1 | Hr | `READ` + `create` + `APPROVE` + `STATUS` + `MONEY` + `LOGS` (**never `edit`/`delete`** — a raise is a new version) | 8 |
| `salary_slips` | **new** | 7 §4.1 | Hr | `READ` + `print` + `export` + `MONEY` + `LOGS` | 6 |
| `employee_advances` | **new** | 7 §4.1 | Hr | `READ` + `create` + `APPROVE` + `STATUS` + `MONEY` + `LOGS` (**never `delete`**) | 8 |
| `employee_self_service` | **new** | 7 §4.1 | Hr | `READ` + `create` + `print` + `upload` + `download` + `view_financial` | 7 |
| | | | | **Total** | **222** |

No module is created for `lead_activities`, `lead_follow_ups`, `lead_conversions`, `lead_imports`,
`attendance_corrections`, `leave_approvals`, `leave_request_days`, `salary_structure_components`,
`payroll_run_item_components` or `employee_skills`: each is only ever seen inside its parent's screen
under the parent's `view` ability. Conversion invents no ability either — it needs `leads.edit` +
`clients.create` (+ `projects.create` for the project hand-off), and revising a project value needs
`projects.view_financial` + `projects.edit`.

**`modules.depends_on`** (phase-07 §4.3): `attendance` -> `employees`; `leaves` -> `employees`,
`leave_types`; `leave_balances` -> `leaves`, `leave_types`; `payroll` -> `employees`, `attendance`,
`salary_structures`; `salary_structures` -> `employees`, `salary_components`; `salary_slips` -> `payroll`;
`employee_documents` / `designations` / `employee_advances` / `employee_self_service` -> `employees`.
`work_shifts`, `holidays`, `salary_components`, `leave_types` stand alone.

### 5.2 Portal permission namespaces (not modules — D20)

`client_portal.*`, `collaborator_portal.*`, `student_portal.*`, `teacher_portal.*` are **permission
namespaces**: `Modules::permissionModuleMap()` returns `null` for them and `Gate::before` step 1 falls
through on null (F-12.1 / D20). The complete `client_portal` set after Phase 5 is 14 permissions:
`dashboard`, `profile` (Phase 1), `projects`, `tasks`, `milestones`, `files`, `documents`, `download`,
`invoices`, `meetings`, `tickets`, `messages`, `notifications` (phase-05 §4.3), `payments` (spine §4.3).
Phase 6 adds `collaborator_portal.time_tracking` and uses the eight `collaborator_portal.*` permissions
Phase 1 already registers. `client_portal.download` is separate on purpose: a client may be allowed to
see that a contract exists while downloads are withheld.

### 5.3 Settings groups that change this domain's behaviour

Three groups, **94 keys**, all declared in `SettingsRegistry` (definitions in code, values in the DB).

| Group | Label / sort | Keys |
|---|---|---|
| `crm` | "CRM & Clients", after `finance`, `settings.edit` | **34** (phase-05 §5) |
| `projects` | "Projects & Time", icon `rectangle-stack`, **sort 86** (F-6.3 — 85 was taken) | **17** (phase-06 §5) |
| `hr` | "HR & Payroll", icon `users`, after `finance` | **43** (phase-07 §5) |

| Group | Keys (grouped by what they control) | Behaviour they change |
|---|---|---|
| `crm` | `lead_number_prefix` + `lead_number_next_number`, `client_code_prefix` + `client_code_next_number`, `number_padding` | the two `DocumentNumberService` counters and the pad |
| `crm` | `default_lead_source` (validated against `InquirySource`), `auto_assign_mode`, `auto_assign_user_id`, `auto_assign_roles` | what a new lead gets when the caller supplies no source / assignee |
| `crm` | `lead_statuses_on_board`, `kanban_page_size` | which Kanban columns exist and how many cards per page |
| `crm` | `duplicate_detection_enabled`, `duplicate_match_fields`, `duplicate_cross_field`, `duplicate_check_clients`, `duplicate_lookback_days`, `duplicate_block_on_exact` | `LeadDuplicateDetector`; D29 keeps the default **warn, never block** |
| `crm` | `follow_up_default_offset_hours`, `follow_up_reminder_minutes`, `follow_up_reminder_channels`, `follow_up_overdue_grace_minutes`, `require_follow_up_on_contacted` | `lead_follow_ups.remind_before_minutes` / `reminder_due_at`, the `pending -> missed` sweep, and the §2.11 cross-cutting rule |
| `crm` | `stale_lead_days`, `stale_digest_enabled` | the board's stale chip and the optional digest |
| `crm` | `lost_reasons`, `bulk_max_ids`, `export_max_rows`, `import_max_rows`, `import_file_retention_days`, `import_row_retention_days`, `activity_edit_window_minutes` | the lost modal, bulk caps, export/import caps and retention, the author's note-edit window |
| `crm` | `client_portal_enabled`, `client_visible_documents_default`, `whatsapp_link_template` | the master portal switch (403 on every `/client` route when off), the default of `client_documents.visible_to_client`, and the WhatsApp link (no hardcoded URL) |
| `projects` | `project_code_prefix` + `project_code_next_number` | `projects.code` via `DocumentNumberService` (pad `'%05d'`) |
| `projects` | `default_progress_basis`, `progress_manual_override_enabled`, `default_task_estimate_minutes` | seeds `projects.progress_basis`; when the override is off `progress_mode = manual` is refused outright (D34) |
| `projects` | `allow_time_without_task`, `timer_auto_stop_enabled`, `timer_max_hours`, `manual_time_backdate_limit_days`, `manual_time_max_hours_per_day` | whether `time_entries.task_id` may be NULL, the auto-stop sweep, and when `manual_reason` becomes mandatory |
| `projects` | `working_hours_per_day`, `working_days_per_week` | the utilisation denominator in the weekly rollup |
| `projects` | `task_comment_edit_minutes` | the author's comment-edit window |
| `projects` | `client_can_see_tasks`, `client_can_see_attachments` | the client task rule is the **AND** of `client_can_see_tasks` and `tasks.is_client_visible` (F-3.1); attachments additionally need `AttachmentVisibility::Client` |
| `projects` | `deadline_reminder_days`, `overdue_digest_enabled` | deadline / due-date reminders and the PM digest |
| `hr` | `employee_code_prefix`/`_next_number`, `leave_request_prefix`/`_next_number`, `advance_number_prefix`/`advance_next_number`, `payroll_run_prefix`/`_next_number`, `payslip_prefix`/`_next_number` | the five HR counters; **every HR caller passes `'%05d'` explicitly** (D27) |
| `hr` | `weekend_days`, `late_grace_minutes`, `early_leave_grace_minutes`, `full_day_min_minutes`, `half_day_min_minutes`, `short_day_as_half_day` | the defaults `work_shifts` copies and the thresholds `AttendanceStatus` resolution uses |
| `hr` | `auto_absent_enabled`, `attendance_day_close_time` | the nightly `hr:close-attendance-day` closer |
| `hr` | `self_check_in_enabled`, `self_check_in_ip_whitelist` | the self-service punch, independent of permissions; a refused punch is logged with its IP |
| `hr` | `attendance_correction_window_days`, `attendance_correction_requires_approval` | how far back a self-request may reach and whether `hr_direct` applies immediately (the correction row is written either way) |
| `hr` | `overtime_pay_enabled`, `late_deduction_lates_per_day` | overtime is always measured, never auto-paid; `0` lates = no late deduction |
| `hr` | `document_expiry_reminder_days` | default `employee_documents.reminder_days` |
| `hr` | `leave_year_start_month`, `leave_accrual_run_day`, `leave_carry_forward_enabled`, `leave_negative_balance_allowed`, `leave_default_approval_levels` | the leave-year window on `leave_balances`, the accrual job, the two master switches above each type's flag, and the default `leave_types.approval_levels` |
| `hr` | `payroll_day_basis`, `lop_basis`, `unpaid_leave_deduction_enabled`, `payroll_net_rounding` | the per-day divisor, what a loss-of-pay day comes off, and the visible `ROUNDING` component — all three snapshotted onto `payroll_runs` at generation |
| `hr` | `tax_mode`, `tax_default_rate` | §28 tax: `none` / `fixed_percentage` / `manual` (default `manual`; no slab engine) |
| `hr` | `advance_max_multiple_of_basic`, `advance_recovery_default_installments`, `advance_recovery_cap_percent` | when an advance needs `employee_advances.approve`, and the ceiling on what recovery may take from net |
| `hr` | `payslip_show_attendance`, `payslip_footer_note` | what `PayslipService::render()` prints |
| `hr` | `employee_self_service_enabled`, `payroll_reminder_day` | the `my/*` master switch and the generation reminder |

**Keys owned elsewhere that this domain reads and must not redefine:**
`security.max_upload_mb` + `security.allowed_file_types` (every upload in all three phases),
`localization.currency` (`projects.currency`, `salary_structures.currency`), `localization.week_start`
(with `projects.working_days_per_week`), `finance.default_tax_rate` and `finance.payment_terms_days`
(the NULL fallbacks behind `clients.tax_rate_override` / `payment_terms_days`),
`collaborator.referral_visit_retention_days` (default 365, F-13.12/H5 — its prune command must never
prune a visit `leads.referral_visit_id` points at).

---

## 6. Open points

Nothing in the three contracts is marked TODO. What remains open is listed with its source.

### 6.1 Deferred foreign keys — a column exists, the constraint arrives later

| Column | Target | Promoted by | Source |
|---|---|---|---|
| `leads.contact_inquiry_id` | `contact_inquiries.id` `nullOnDelete` | Phase 4 (owner of the table) | F-2.1, phase-05 §2.1 |
| `leads.service_id` | `services.id` `nullOnDelete` | Phase 4 | F-3.6, phase-05 §2.1 |
| `leads.referral_visit_id` | `collaborator_referral_visits.id` `nullOnDelete` + the prune guard | **Phase 9** | F-3.5, phase-05 §13.1 |
| `leads.lead_import_id` | `lead_imports.id` | `add_crm_deferred_foreign_keys` (same phase) | phase-05 §2.1 |
| `clients.lead_id` | `leads.id` `nullOnDelete` | `add_crm_deferred_foreign_keys` | phase-05 §2.7 |
| `lead_conversions.project_id` | `projects.id` `nullOnDelete` | Phase 6 | phase-05 §2.4 |
| `lead_conversions.collaborator_referral_id` | `collaborator_referrals.id` `nullOnDelete` | Phase 10 | F-4.5, phase-05 §2.4 |
| `projects.collaborator_id`, `project_members.collaborator_id`, `tasks.assigned_collaborator_id`, `time_entries.collaborator_id`, `time_entry_segments.collaborator_id` | `collaborators.id` (`restrictOnDelete`) | **Phase 8** `add_collaborator_fks_to_project_tables` | [D-P6-1], phase-06 §13.1 |
| `departments.head_employee_id` | `employees.id` `nullOnDelete` | Phase 7 step 5 (circular with `employees.department_id`) | phase-07 §2.27 |
| `attendances.locked_by_payroll_run_id`, `attendance_monthly_summaries.locked_by_payroll_run_id` | `payroll_runs.id` | Phase 7 step 16 | phase-07 §2.27 |
| `team_members.employee_id`, `job_applications.employee_id` | `employees.id` | Phase 7's `Schema::hasTable('employees')`-guarded migration (Phase 4 ships the nullable column + index) | F-3.12, F-3.13 |
| `teachers.employee_id` | `employees.id` `nullOnDelete` | Phase 14-17 | phase-07 §13.1, F-11.1 |

### 6.2 Open questions carried forward (defaults assumed, nothing blocked)

| # | Question | Default that is being built |
|---|---|---|
| phase-05 Q2 | Should sales reps see each other's leads? | yes for `leads.view_any`; `leads.view` = own records (D30) |
| phase-05 Q3 | Do all portal contacts of a company see all its tickets? | yes (`support_tickets.client_id = $C`); per-contact privacy would be a Phase 22 column |
| phase-05 Q4 | Are leads and clients ever branch-specific? | **no `branch_id`** on either (D11 scopes branch-readiness to institute tables) |
| phase-05 Q5 | Is `leads.budget_amount` the pipeline value, or is a separate expected value wanted? | budget is the only money field; the board also reports `with_budget` |
| phase-05 Q6 | May a lead convert directly from `proposal_sent`? | no — the wizard offers "mark won and convert" in one transaction |
| phase-05 Q7 | May a client upload a document or raise a ticket from the panel? | not in this release; panel writes are profile, notification-read and downloads only |
| phase-05 Q8 | Is an accidental conversion reversible? | never deleted — superseded with a reason |
| phase-06 Q1 | Does §20 "project type" mean the engagement model or the kind of work? | engagement model = `ProjectType`; kind of work = `projects.service_id` |
| phase-06 Q2 | Is progress derived or typed? | derived (D34) with an audited, globally switchable manual override |
| phase-06 Q3 | May a staff member without a login be assigned work? | no (D32) |
| phase-06 Q4 | Subtasks one level deep, or an arbitrary tree? | one level (`chk_tasks_depth`) |
| phase-06 Q5 | `collaborator.projects.index` declared in 6 and extended in 12 — acceptable? | yes, one route name, one controller |
| phase-06 Q6 | May a client see tasks and files at all? | yes, behind two settings **and** `AttachmentVisibility::Client` |
| phase-06 Q7 | Does the last task auto-complete its milestone and project? | milestone yes (reversible), project **no** |
| phase-06 Q8 | Does the timer need idle detection? | no — `timer_max_hours` + the auto-stop sweep |
| phase-07 Q1 | Move the `departments`/`designations`/`employees` trio earlier, since 5 and 6 ship first? | keep the tracker; whichever phase lands first ships Phase 7's migration verbatim, inbound FKs guarded ([D-HR-1]) |
| phase-07 Q3 | Sunday only, Sat+Sun, or a Saturday half day? | `hr.weekend_days = [sunday]`; a half Saturday is a shift, not a weekend |
| phase-07 Q4 | Is payroll strictly monthly? | yes in this phase; `period_start`/`period_end` + `pay_frequency` keep a later frequency additive |
| phase-07 Q5 | FBR tax slabs or manual tax? | `hr.tax_mode = manual`; slabs would be a `tax_slabs` table + one calculator step |
| phase-07 Q6 | Store employee bank details for disbursement? | not stored — method + reference only; would be `employee_payout_accounts` modelled on the encrypted collaborator table |
| phase-07 Q7 | Biometric device or CSV import? | CSV/XLSX import with a dry run; a device would write through `AttendanceService` |
| phase-07 Q8, Q9 | Is overtime paid? Does a late cost money? | measured, never auto-paid (`overtime_pay_enabled = false`); `late_deduction_lates_per_day = 0` |
| phase-07 Q10, Q11, Q12 | Quotas / carry-forward / leave year; approval levels; encashment | Annual 14 (CF 7), Sick 8, Casual 10, Unpaid unlimited; leave year = calendar; 1 level; encashment is a manual `other_earning` component |
| phase-07 Q13 | Who locks a run, and must a second person pay it? | `payroll.approve` locks; the policy refuses the locker to also mark items paid |

Answered and closed, recorded so nobody reopens them: phase-05 Q1 (D20, F-12.1), phase-07 Q2 (D19 — the
eleven append-only HR tables omit `deleted_at`), phase-07 Q14 (D44 — Phase 13's `RecordPayrollExpense`
writes one `approved` expense per paid run), phase-07 Q15 (`team_members.employee_id` as a source of
defaults only).

### 6.3 Needs-human items that touch this domain

| # | Item | What is built now |
|---|---|---|
| H1 | No REST API in this release (F-13.1) | no `routes/api.php` anywhere in this domain; idempotency keys are server-generated |
| H5 | Referral-visit retention, 365 days of IP + user agent (F-13.12) | `collaborator.referral_visit_retention_days`; the prune command must skip any visit `leads.referral_visit_id` or `collaborator_referrals.referral_visit_id` points at |
| F-13.8 | CSV lead import (wizard, chunked jobs, error CSV) | **build** — `lead_imports` + `lead_import_rows`, gated by `leads.import` |

### 6.4 Residual clashes — recorded, not resolved here

These survived the apply stage; each needs an owner's ruling rather than a name invented in this file.

| # | Clash | Sources |
|---|---|---|
| 1 | **Disk of the three bare `*_path` photo columns.** phase-05 §2.7 says `clients.logo_path` is on the **`public`** disk ("a logo is not confidential") and phase-07 §2.4 says `employees.photo_path` is on the **`public`** disk under `employees/photos/`, while resolutions §8 item 14 and D24 list both as the **private** tier-2 exception ("a bare `*_path` is allowed only for a **private** profile photo or document") and D21 bans private artefacts from the public disk. The tier itself is settled (no `media_assets` row is required); only the disk is contradictory. | phase-05 §2.7, phase-07 §2.4 vs resolutions §8 item 14, D21, D24, F-2.4 |
| 2 | **Who registers `client_portal.projects` / `.tasks` / `.files`.** phase-05 §4.3 presents the complete 14-permission set "each registered **once**", including these three; phase-06 §4.3 lists the same three under "Added". One of the two declaration sites has to be the registry's. | phase-05 §4.3 vs phase-06 §4.3 (no audit finding) |
| 3 | **How a lead/client reaches `collaborator_referrals`.** phase-05 §2.1 describes a `morphMany` with `subject_type = 'lead'`, and resolutions F-8.2 writes `subject_type = Project, subject_id = projects.id`; the spine §2.8 has **no `subject_id`** — it carries four explicit nullable FKs (`lead_id`, `client_id`, `project_id`, `student_id`) plus a `subject_type` discriminator and `chk_cr_one_subject`. The spine's shape is the one that exists; the relation is `hasMany`/`hasOne` on the explicit FK, not a morph. | phase-05 §2.1, resolutions F-8.2 vs spine §2.8 (the spine wins on money-touching shape) |
| 4 | **`leave_requests.attachment_path` / `employee_documents.file_path` "migrate to the `files` table" when Phase 22 ships it** (phase-07 §13.1, [D-HR-6]). F-2.8 / F-13.2 / `CLAUDE.md` §3 block B say **no `files` table is ever created**: the `files` slug governs `attachments` (Phase 6, already shipped before Phase 7), and `employee_documents` is a named exception that stays. The Phase 22 migration sentence is stale. | phase-07 §13.1 + [D-HR-6] vs F-2.8, F-13.2, resolutions §5 block B |
| 5 | **D43's gate on `project_payments`** — the spine §2.6 row 4 records it as explicitly unresolved (`project_payments` has no `edit` ability), with the conservative reading binding until the owner rules. It touches this domain only through `projects`/`project_milestones`, but a project-payment screen built on it would inherit the ambiguity. | spine §2.6 (line 135), D43, F-4.10 |
