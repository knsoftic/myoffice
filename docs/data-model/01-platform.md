# MASTER DATA MODEL — 01 · Platform and shared services

## 1. Scope

This file is the data-model slice for **the platform and the shared services**: the identity, RBAC,
module-gating, configuration, branch and audit tables of
[`phase-01.md`](../phases/phase-01.md) §1, the three additive migrations and the settings/widget/dashboard
framework of [`phase-02.md`](../phases/phase-02.md) §1–§3, and the cross-cutting shared services of
[`phase-19-23.md`](../phases/phase-19-23.md) — tickets, meetings, messaging, notifications and the one
report-export table (its §2.17–§2.27), plus the one shared file store `attachments` that
[`phase-06.md`](../phases/phase-06.md) §2.9 owns and that five later morph owners write into. The
reporting/search support surface is included: `report_exports` is the **only** table Phase 23 creates —
reports, analytics, the activity-log viewers, the audit trail and global search are read-only registries
over tables other phases own, and their per-user state lives in `users.preferences`. Names here are
**post-convergence**: every decision cited is from [`resolutions.md`](../design/resolutions.md) (D17–D60)
and every finding id from [`consistency-audit.md`](../design/consistency-audit.md). Where money is
mentioned, [`finance-commission-spine.md`](../design/finance-commission-spine.md) wins — but see §2.1: this
domain owns **no** money column. Tables belonging to institute content (materials, assignments, exams,
results, certificates, ID cards, print templates) and to phase-24-25 operations (`backup_runs`,
integrity runs) are **out of scope for this file** and are only cross-referenced where they change a
platform slug, enum or setting.

---

## 2. Table inventory

### 2.1 Money and percentage columns — none

**This domain contains no `decimal(15,2)` money column and no `decimal(8,4)` percentage column.** Every
figure a shared-service screen renders (a fee balance on a ticket, a commission total in a report, a
payout line in an export) is read from the service that owns the row — `StudentFeeService`,
`FinanceReportService`, `CollaboratorWalletService`, `CollaboratorStatementService` — never summed here
(INV-23-1, D56). The only numerics in these tables are **integer minute counts** (`ticket_departments.sla_*_minutes`,
`support_tickets.total_waiting_minutes`, `meetings.duration_minutes`), **integer caches** (reply, participant,
message and attachment counts) and **byte sizes** (`attachments.size_bytes`, `report_exports.file_size_bytes`).
`TicketSlaService::multiplier(Priority): float` multiplies a whole-minute integer, never money
(phase-19-23 §6.16). Markers `¤` (money) and `%` (percentage) therefore appear nowhere below; they are kept
in the legend so the absence is visible rather than assumed.

### 2.2 Inventory

| Table | Owning phase | Soft deletes | Purpose | Key columns | Key relationships |
|---|---|---|---|---|---|
| `users` | **1** §1.1 (extended Laravel table; `preferences` added by **2** §1) | **yes** + blameable | The one identity row behind all five panels | `email` uq · `status` (idx) · `status_reason` · `status_changed_at` · `theme` · `locale` · `timezone` · `phone` · `whatsapp` · `avatar_path` (private-tier bare path, D24) · `last_login_at` · `last_login_ip` · `password_changed_at` · `must_change_password` · `branch_id` (idx) · `preferences` json | belongsTo `branches` (nullOnDelete, D11) · self `created_by`/`updated_by` · spatie `HasRoles` · hasMany `login_histories`, `notification_preferences`, `report_exports`, `conversation_participants`, `meeting_participants` · morphMany `notifications` |
| `sessions` | **1** §1.8 (Laravel) | no | Database session driver; **read-only** to the session-management UI | `id` · `user_id` · `ip_address` · `user_agent` · `payload` · `last_activity` | belongsTo `users` (no FK declared by the contract) |
| `roles` | **1** §1.2 (spatie, extended) | **no** (D19) | Panelled, levelled role definitions | `name` · `guard_name` · `label` · `description` · `panel` (idx) · `level` (lower = stronger; Super Admin 1) · `is_system` · `is_default` · `created_by`/`updated_by` | belongsToMany `permissions` (`role_has_permissions`) · morphedByMany `users` (`model_has_roles`) |
| `permissions` | **1** §1.2 (spatie, extended) | **no** (D19) | The `{module_slug}.{ability}` namespace (R8, D4) | `name` uq · `guard_name` · `module` (idx) · `ability` · `group` · `label` · `description` · `sort_order` | belongsTo `modules` on `modules.slug` (`Permission::moduleModel()`) · belongsToMany `roles` |
| `model_has_roles`, `model_has_permissions`, `role_has_permissions` | **1** §1.2 (spatie vendor migration, **published unchanged**) | no | The three RBAC pivots | composite PKs per spatie | `users` ↔ `roles` ↔ `permissions` |
| `modules` | **1** §1.3 · **2** §1 | **no** (D19) | The ~112-slug module register that `Gate::before` gates on | `slug` uq · `name` · `icon` · `group` (idx) · `is_enabled` (idx) · `is_core` · `sort_order` · `settings` json · `depends_on` json (**2**) · `disabled_at` · `disabled_by` · `disable_reason` (**2**) | belongsTo `users` (`disabled_by`, nullOnDelete) · logical hasMany `permissions` via `slug` |
| `settings` | **1** §1.4 · **2** §1 | **no** (D19) | Values for the code-declared `SettingsRegistry` | **unique(`group`,`key`)** · `value` longText · `type` · `options` json · `is_encrypted` · `is_public` · `is_readonly` (**2**) · `label` · `description` · `sort_order` · `updated_by` (**2**) | belongsTo `users` (`updated_by`, nullOnDelete) |
| `branches` | **1** §1.5 | **yes** + blameable | The branch every institute table hangs `branch_id` off (D11) | `code` uq · `name` · `phone` · `email` · `city` · `address` · `is_default` · `is_active` · `sort_order` | hasMany `users`, `support_tickets`, `meetings` (+ every institute table's nullable `branch_id`) |
| `login_histories` | **1** §1.6 | **no** — append-only log (D19) | Success / failure / logout / blocked evidence per attempt | `user_id` (idx, nullOnDelete) · `email` (captured for a failed attempt with no user) · `status` (idx) · `ip_address` · `user_agent` · `device` · `platform` · `browser` · `session_id` (idx) · `logged_in_at` · `logged_out_at` · composite idx (`user_id`,`created_at`) | belongsTo `users` |
| `activity_log` | **1** §1.7 (spatie, extended) · **8-9** adds `collaborator_id` · **19-23** adds 5 indexes | **no** — append-only audit (D19) | The **one** audit store (D13); §106 viewer and §107 audit trail are read-only views over it | spatie `log_name`, `description`, `subject_*`, `causer_*`, `properties`, `batch_uuid` + **1**: `ip_address`, `user_agent`, `device`, `module` (idx), `reason` · **8-9**: `collaborator_id` (FK `collaborators.id` nullOnDelete, idx (`collaborator_id`,`created_at`)) · **19-23**: idx on (`causer_type`,`causer_id`,`created_at`), (`subject_type`,`subject_id`,`created_at`), (`module`,`created_at`), (`log_name`,`created_at`), (`created_at`) | morphTo `subject`, morphTo `causer` · belongsTo `collaborators` |
| `attachments` | **6** §2.9 (**never re-created**; F-2.6, F-2.8, F-13.2, [D-22-3]) | **yes** + blameable + `uploaded_by`/`uploaded_by_name` snapshot (kept deliberately, §8 item 10) | The one polymorphic private file store the `files` **module slug** gates — there is never a `files` table | `attachable_type` (morph **alias**, never a FQCN) · `attachable_id` · `disk` (private) · `path` (`{ulid}.{ext}`) · `original_name` · `mime_type` (sniffed) · `extension` · `size_bytes` · `checksum_sha256` · **`visibility`** (the only client-visibility mechanism) · `uploaded_by` · `uploaded_by_name` | morphTo `attachable` — Phase 6 registers `project`, `task`, `task_comment`, `project_milestone`, `collaborator`, `invoice`; **Phase 22 appends exactly five**: `SupportTicket`, `TicketReply`, `Message`, `Meeting`, `Assignment` · belongsTo `users` (`uploader`) |
| `ticket_departments` | **22** §2.17 | **yes** + blameable | §93's dynamic queue, its SLA minutes and its auto-assign policy | `slug` uq · `name` · `email` · **`allowed_panels` json** (which `PanelType`s may file here) · `default_assignee_id` · `auto_assign_strategy` · `sla_first_response_minutes` · `sla_resolution_minutes` · `is_default` + **`default_guard`** (STORED, `uq_td_default`) · `is_active` · `sort_order` · `open_tickets_count` (cache) · CHECK `chk_td_sla` | belongsTo `users` (`defaultAssignee`) · hasMany `support_tickets` (`restrictOnDelete`) |
| `support_tickets` | **22** §2.18 | **yes** (policy refuses `delete`/`forceDelete` for every role, INV-22-1) | §93's ticket: one requester, one department, five statuses, optional SLA | `ticket_number` uq (issued once, `DocumentNumberService`) · `ticket_department_id` (restrict) · `branch_id` · `user_id` (requester, restrict) · `requester_panel` · subject FKs **derived by the service, never posted**: `client_id`, `student_id`, `teacher_id`, `collaborator_id`, `employee_id`, `project_id`, `course_id`, `batch_id` · `subject` · `description` · `priority` · `status` · `assigned_to`/`assigned_at`/`assigned_by` · SLA set (**all nullable**): `first_response_due_at`, `first_response_at`, `first_response_by`, `first_response_breached`, `resolution_due_at`, `resolved_at`, `resolved_by`, `resolution_breached`, `waiting_since`, `total_waiting_minutes` · `closed_at`/`closed_by`/`closure_reason` · `reopened_count`/`last_reopened_at` · `last_reply_at`/`last_reply_by`/`last_reply_panel` · 4 count caches · `is_private_to_creator` · `tags` json · CHECKs `chk_tk_counts`, `chk_tk_resolved`, `chk_tk_waiting` | belongsTo `ticket_departments`, `branches`, `users` ×7 (requester, assignee, assigner, firstResponder, resolver, closer, lastReplier), `clients`, `students`, `teachers`, `collaborators`, `employees`, `projects`, `courses`, `batches` · hasMany `ticket_replies` · morphMany `attachments` · hasMany `meetings`, `conversations` |
| `ticket_replies` | **22** §2.19 | yes (`deleted_at` only to honour `CLAUDE.md` §3; **append-only**: no edit route, no delete route, policy false) | §93 replies, internal notes and the system timeline | `support_ticket_id` (cascade) · `user_id` (null = system) · `panel` · `body` · **`visibility`** (`internal_note` never leaves staff, INV-22-3) · `is_first_response` · `is_system` · `system_event` · `status_from`/`status_to` · `attachments_count` · CHECK `chk_tr_body` | belongsTo `support_tickets`, `users` · morphMany `attachments` |
| `meetings` | **22** §2.20 | **yes** + blameable | §95's meeting across every panel, optionally room-booked | `title` · `branch_id` · `organizer_id` (restrict) · `scheduled_at` · `duration_minutes` · **`ends_at`** (STORED generated) · `delivery_mode` · `location` · `classroom_id` (clash-checked) · `meeting_url` · `agenda` · `notes` · subject FKs `project_id`, `course_id`, `batch_id`, `client_id`, `lead_id`, `collaborator_id`, `support_ticket_id` · `status` · `is_private` · `reminder_minutes_before`/`reminder_sent_at`/`second_reminder_sent_at` · 4 count caches · `cancellation_reason` · `rescheduled_from_id` (`uq_me_resched`) · `outcome_summary` · CHECKs `chk_me_duration`, `chk_me_url`, `chk_me_cancel`, `chk_me_counts` | belongsTo `users` (`organizer`), `branches`, `classrooms`, `projects`, `courses`, `batches`, `clients`, `leads`, `collaborators`, `support_tickets`, self (`rescheduledFrom`) · hasMany `meeting_participants` · morphMany `attachments` |
| `meeting_participants` | **22** §2.21 | **no** — pivot with payload (D19); a removal is a delete **plus** an activity row | §95 participants across roles, including an external guest | `meeting_id` (cascade) · `user_id` (null for an external) · `participant_type` · profile FKs `client_id`/`student_id`/`teacher_id`/`collaborator_id`/`employee_id` · `external_name`/`external_email` · `role` · `response`/`responded_at` · `attended`/`attendance_marked_at`/`attendance_marked_by` · `notified_at`/`reminder_sent_at` · `notes` · `uq_mp_user(meeting_id,user_id)` · `uq_mp_external(meeting_id,external_email)` · CHECKs `chk_mp_identity`, `chk_mp_external` | belongsTo `meetings`, `users`, `clients`, `students`, `teachers`, `collaborators`, `employees` |
| `conversations` | **22** §2.22 | **yes** + blameable | §94's thread, carrying the authorised role pair as a column | `type` · `subject` · **`pair_scope`** (which of §94's six pairs authorised it — re-checked on every send, INV-22-4) · context FKs `project_id`, `course_id`, `batch_id`, `support_ticket_id` · **`direct_key`** char(64) `uq_cv_direct` (sha256 of sorted participant ids, INV-22-5) · `last_message_id` (FK attached by a follow-up migration — circular) · `last_message_at` · `messages_count`/`participants_count` caches · `is_closed`/`closed_at`/`closed_by`/`closure_reason` · CHECKs `chk_cv_group`, `chk_cv_direct` | belongsTo `projects`, `courses`, `batches`, `support_tickets`, `messages` (`lastMessage`), `users` (`closer`) · hasMany `conversation_participants`, `messages` |
| `conversation_participants` | **22** §2.23 | **no** — pivot with a read pointer (D19) | Membership, the side they participate **as**, and the read pointer | `conversation_id` (cascade) · `user_id` (restrict) · **`panel`** (snapshotted side) · `role` (`owner`/`member`) · `last_read_message_id` · `last_read_at` · `unread_count` (cache, recounted under a row lock, INV-22-6) · `is_muted` · `joined_at`/`left_at`/`removed_by` · **`active_guard`** (STORED) with `uq_cp_active(conversation_id,user_id,active_guard)` · CHECK `chk_cp_unread` | belongsTo `conversations`, `users`, `messages` (`lastReadMessage`) |
| `messages` | **22** §2.24 | yes (**append-only**: no edit route, no delete route, policy false; [D-22-1] no "delete for me") | §94's message — plain text, always rendered escaped | `conversation_id` (cascade) · `user_id` (null = system) · `panel` · `body` · `attachments_count` · `is_system`/`system_event` · `reads_count` cache · idx (`conversation_id`,`id`) · CHECK `chk_ms_payload` | belongsTo `conversations`, `users` · morphMany `attachments` |
| `notifications` | **22** §2.25 (Laravel database channel + context columns, created here because no earlier phase needed it) | **no** (D19) | §97's in-app row; the bell, the index and the deep link | **`id` uuid** · `type` · `notifiable_type`/`notifiable_id` · `data` json · `read_at` · **`event_key`** (the `NotificationRegistry` key) · `module` (so a disabled module hides rather than deletes) · `level` · `url` (built by the registry, never by a view) · `actor_id` · `emailed_at` · **`archived_at`** ("clear all" archives, never deletes) · idx (`notifiable_type`,`notifiable_id`,`read_at`,`archived_at`) so the bell count is one index scan | morphTo `notifiable` · belongsTo `users` (`actor`) |
| `notification_preferences` | **22** §2.25 | **no** (D19) | §97 per-user, per-event channels; **a missing row means "the registry default"**, so a new event never needs a backfill | `user_id` (cascade) · `event_key` (must exist in `NotificationRegistry`) · `database_enabled` · `mail_enabled` (additionally gated by `support.notifications_mail_enabled`) · `mail_digest` · `uq_np(user_id,event_key)` | belongsTo `users` |
| `report_exports` | **23** §2.26 — **Phase 23's only table** | **no**, and **no blameable**: `requested_by` is the actor and the row is an artefact, not a business record | A queued §99 export: what was asked, by whom, where the file is, when it dies | **`uuid`** `uq_rx_uuid` (the only id ever in a URL) · `report_key` (**re-authorised at download**) · `format` · `filters` json (the exact `ReportRequest`, so the file is reproducible) · `date_from`/`date_to` · `requested_by` (restrict — **the only user who may download it**) · `status` · `row_count` · `storage_disk` (`private`) · `file_path` · `file_size_bytes` · `checksum_sha256` · `started_at`/`completed_at` · `expires_at` · `download_count`/`last_downloaded_at` · `error_class`/`error_message` · CHECK `chk_rx_counts` | belongsTo `users` (`requester`) |

**Legend.** `¤` money `decimal(15,2)` via `App\Support\Money` · `%` percentage `decimal(8,4)` — **neither
marker occurs in this domain** (§2.1). "blameable" = `created_by`/`updated_by` nullable FK `users.id`,
`nullOnDelete`, filled by `App\Models\Concerns\Blameable`.

### 2.3 Framework tables referenced but not defined by any contract

`password_reset_tokens`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`. They exist as
Laravel defaults; no phase contract specifies their columns. They are **named** by
phase-02 §3 (`SystemHealthWidget` reads the failed-jobs count and the queue/cache driver) and by
phase-24-25 §5 (`backup.excluded_tables` excludes `cache`, `cache_locks`, `sessions`, `job_batches` and
deliberately **includes** `jobs` and `failed_jobs` so a restore cannot lose queued commission work).

### 2.4 Requested but not yet defined — do not treat as existing

| Table | Requested by | State |
|---|---|---|
| `password_histories` (`user_id` FK cascade, `password` string(255), `created_at`) | phase-24-25 §13.1 + Q13, from **Phase 1** (Phase 1 owns identity) | **No contract defines it.** Until it exists, `security.password_history_count` is inert and SEC-18 asserts only the policy rules it can. |

### 2.5 Tables this domain deliberately does not create

`files` (F-2.8, [D-22-3] — the `files` module slug governs `attachments`) · a second activity/audit store
(INV-23-5: Phase 23 adds indexes and viewers, never a column, never a second log table, never a delete
route) · a server-side search-history table (phase-19-23 §6.23: recent hits come from `users.preferences`)
· `routes/api.php` and any token table (H1 / F-13.1: no REST API in this release) · a broadcast /
announcement table (§12.2 Q13: served by a group conversation or a notification).

---

## 3. Relationship map

```
branches (1)
  -> users.branch_id                    nullable, nullOnDelete        [D11]
  -> support_tickets.branch_id          nullable
  -> meetings.branch_id                 nullable
  -> (every institute table's nullable branch_id - other files)

users (1)  -- the single identity behind all five panels
  -> users.created_by / users.updated_by            self, nullOnDelete
  -> model_has_roles -> roles -> role_has_permissions -> permissions
  -> model_has_permissions -> permissions           direct grants
  -> login_histories.user_id                        nullable (a failed attempt may have none)
  -> sessions.user_id                               read-only UI
  -> activity_log.causer_*                          morph; null for engine / scheduler acts
  -> notifications.notifiable_*                     morph, uuid PK
  -> notification_preferences.user_id               cascade; missing row = registry default
  -> report_exports.requested_by                    restrict; the only permitted downloader
  -> conversation_participants.user_id              restrict
  -> meeting_participants.user_id                   nullable (external guest)
  -> support_tickets.user_id                        restrict; the requester
  -> support_tickets.assigned_to / _by / first_response_by / resolved_by / closed_by / last_reply_by
  -> meetings.organizer_id                          restrict
  -> attachments.uploaded_by  (+ uploaded_by_name snapshot, survives a user delete)
  -> modules.disabled_by, settings.updated_by       nullOnDelete

modules (1 + 2)
  -> permissions                        logical, on permissions.module = modules.slug
  -> depends_on json                    slug list; ModuleService::dependents()/missingDependencies()
  (permissionModuleMap() returns NULL for an unregistered prefix -> Gate::before falls through)  [D20]

settings (1 + 2)
  -> SettingsRegistry (code)            definitions in code, values here; unique(group, key)

ticket_departments (22)
  -> support_tickets                    restrictOnDelete; a used department is deactivated, never deleted
       -> ticket_replies                cascade (a ticket is never deleted, so it never fires)
            -> attachments              morph: TicketReply
       -> attachments                   morph: SupportTicket
       -> meetings.support_ticket_id    a call booked off a ticket
       -> conversations.support_ticket_id

meetings (22)
  -> meeting_participants               cascade; user_id OR external_email (chk_mp_identity)
       -> clients | students | teachers | collaborators | employees    profile behind the participant
  -> meetings.rescheduled_from_id        self, UNIQUE - a slipping meeting reads as a chain
  -> attachments                         morph: Meeting
  -> classrooms.classroom_id             ScheduleClashDetector::check(SlotCandidate)   [D47]

conversations (22)
  -> conversation_participants           cascade; active_guard keeps one live membership, history stacks
  -> messages                            cascade, append-only
       -> attachments                    morph: Message
  -> conversations.last_message_id       follow-up migration (circular reference)
  -> conversation_participants.last_read_message_id

attachments (6, shared)  -- morph map, appended never rewritten
  <- project | task | task_comment | project_milestone | collaborator | invoice     (Phase 6)
  <- SupportTicket | TicketReply | Message | Meeting | Assignment                   (Phase 22, five)
  visibility = internal | team | client                                             [F-2.6]

report_exports (23)
  -> users.requested_by                  the only downloader; re-authorised at download
  -> ReportRegistry.report_key            code-side; never a FK

activity_log (1, extended)
  <- every phase's LogsActivityWithContext writes here        one audit store  [D13]
  -> collaborator_id                      (Phase 8-9) so a null-causer engine act is still per-collaborator
  read-only viewers: §106 activity log, §107 audit trail       [INV-23-5]
```

---

## 4. Enums in this domain

String-backed, `label()` + `color()` + `static options()` — Phase 1 §2's contract, honoured by every row.
"Declared by" is the **one** declaration site (R3); everyone else casts.

| Enum | Declared by | Casts these columns in this domain |
|---|---|---|
| `UserStatus` (`active`, `inactive`, `suspended`, `pending`; + `canLogin()`) | 1 §2 | `users.status` |
| `ThemePreference` (`light`, `dark`, `system`) | 1 §2 | `users.theme` |
| `PanelType` (`admin`, `collaborator`, `student`, `teacher`, `client`; + `homeRoute()`, `routePrefix()`) | 1 §2 | `roles.panel` · `support_tickets.requester_panel`, `.last_reply_panel` · `ticket_replies.panel` · `conversation_participants.panel` · `messages.panel` · `ticket_departments.allowed_panels` (json **array of** values) |
| `ModuleGroup` (`System`, `SoftwareHouse`, `Hr`, `Finance`, `Collaborator`, `Institute`, `Website`, `Shared`) | 1 §2 | `modules.group` — a **grouping** only; `is_core` is the per-row flag (F-6.5) |
| `Ability` (18 cases incl. `Restore`, `ViewFinancial`, `ViewReports`, `ViewLogs`) | 1 §2 | `permissions.ability` |
| `LoginStatus` (`success`, `failed`, `logout`, `blocked`) | 1 §2 | `login_histories.status` |
| `RemainderPlacement` (`first`, `last`, `largest`) | 1 §2 (added by F-4.11) | **no column** — the remainder rule of `Money::distribute()` |
| `AttachmentVisibility` (`internal`, `team`, `client`) | **6** §3 | `attachments.visibility` — the only client-visibility mechanism in the system (F-2.6) |
| `Priority` (`low`, `medium`, `high`, `urgent`) | **6** §3 | `support_tickets.priority`. **`TicketPriority` is deleted** (F-5.7); the SLA multiplier is `TicketSlaService::multiplier(Priority): float`, not an enum member |
| `DeliveryMode` | **14-17** §3 | `meetings.delivery_mode` |
| `TicketStatus` (`open`, `in_progress`, `waiting`, `resolved`, `closed` — §93's five, no sixth) | 22 §3.4 | `support_tickets.status` · `ticket_replies.status_from`, `.status_to` |
| `TicketAssignStrategy` (`none`, `default_assignee`, `round_robin`, `least_open`) | 22 §3.4 | `ticket_departments.auto_assign_strategy` (+ setting `support.ticket_auto_assign`) |
| `ReplyVisibility` (`public`, `internal_note`; + `reachesRequester()`) | 22 §3.4 | `ticket_replies.visibility` |
| `MeetingStatus` (`scheduled`, `completed`, `cancelled`, `postponed`, `missed`) | 22 §3.4 | `meetings.status` |
| `MeetingParticipantRole` (`organizer`, `required`, `optional`, `note_taker`) | 22 §3.4 | `meeting_participants.role` |
| `MeetingResponse` (`pending`, `accepted`, `declined`, `tentative`) | 22 §3.4 | `meeting_participants.response` |
| `ParticipantType` (`staff`, `client`, `student`, `teacher`, `collaborator`, `external`) | 22 §3.4 | `meeting_participants.participant_type` |
| `ConversationType` (`direct`, `group`) | 22 §3.4 | `conversations.type` |
| `ConversationScope` (`admin_employee`, `employee_employee`, `client_manager`, `collaborator_staff`, `student_staff`, `teacher_management` — §94's six, **in order**) | 22 §3.4 | `conversations.pair_scope` (+ multiselect setting `support.messaging_allowed_pairs`) |
| `NotificationLevel` (`info`, `success`, `warning`, `critical`) | 22 §3.4 | `notifications.level` |
| `NotificationDigest` (`immediate`, `daily`, `off`) | 22 §3.4 | `notification_preferences.mail_digest` |
| `NotificationGroup` (`system`, `software_house`, `institute`, `finance`, `collaborator`, `support`) | 22 §3.4 | **no column** — groups the `NotificationRegistry` and the preference screen; mirrors `ModuleGroup` without redefining it |
| `ExportFormat` (+ `excel`, added by 23 with `mime()`/`extension()`) | **13** §3 | `report_exports.format` — gated by `reports.excel_enabled`; no parallel export path |
| `ExportStatus` (`queued`, `running`, `completed`, `failed`, `expired`) | 23 §3.5 | `report_exports.status` |
| `ReportGroup` (`software_house`, `institute`, `collaborator`, `system`) | 23 §3.5 | **no column** — `ReportRegistry` grouping; `label()` reads the company/institute name from settings |
| `SearchEntityType` (§108's eleven) | 23 §3.5 | **no column** — `GlobalSearchRegistry` + the multiselect `reports.global_search_entities` |
| `AuditSensitivity` (`normal`, `sensitive`, `financial`) | 23 §3.5 | **no column** — §107 classification, paired with `reports.audit_sensitive_modules` / `.audit_show_financial_values` |

**Not an enum.** `settings.type` is a `string(16)` with a contract-listed value set, not a PHP enum — see
open point **O-2**, where the two contracts disagree on that set.

---

## 5. Module slugs and settings keys

### 5.1 Module slugs owned by this domain

Permission name is `{slug}.{ability}` (R8, D4); `PermissionRegistry` is the only declaration site.
Counts below are **distinct permissions after de-duplicating the presets** (`CRUD_FULL` already contains
`export` and `print`, so a later `REPORTS` adds only `view_reports`).

| slug | Group | `is_core` | Declared / extended by | Abilities as a contract enumerates them | Count |
|---|---|---|---|---|---|
| `dashboard` | System | yes | 1 §4 | named in contracts: `dashboard.view` (phase-02 §4) | **not enumerated** — O-5 |
| `users` | System | yes | 1 §4 | preset list only | **not enumerated** — O-5 |
| `roles` | System | yes | 1 §4 | `roles.delete` named (1 §5) | **not enumerated** — O-5 |
| `permissions` | System | yes | 1 §4 | preset list only | **not enumerated** — O-5 |
| `modules` | System | yes | 1 §4 · 2 §4 | `view_any`, `view`, `change_status` named | **not enumerated** — O-5 |
| `settings` | System | yes | 1 §4 · 2 §4 | `view`, `edit` named | **not enumerated** — O-5 |
| `activity_log` | System | yes | 1 §4 · 19-23 §4.2 (+ `LOGS` + `export` + `print`) | `view_logs`, `export`, `print` **added to** Phase 1's unstated set | **not enumerated** — O-5 |
| `login_history` | System | yes | 1 §4 (table is `login_histories`) | preset list only | **not enumerated** — O-5 |
| `backups` | System | yes | 1 §4 · **24-25 §4.2 enumerates the final set** | `view_any`, `view`, `create`, `download`, `delete`, `restore`, `view_logs` | **7** |
| `global_search` | System | yes | 1 §4 · 19-23 §4.2 | `view_any` (each provider stacks its own module gate + permission) | **1** named |
| `audit_trail` | System | **no** | 19-23 §4.1 | `READ` + `view_logs` + `export` + `print`; `depends_on: activity_log` | **5** |
| `support_tickets` | Shared | no | 1 §4 · 19-23 §4.2 | `CRUD_FULL` + `ASSIGN` + `STATUS` + `FILES` + `REPORTS` + `LOGS`; `delete` **registered but refused by the policy for every role**; `view` = own/assigned only ([D-P5-8] precedent) | **13** |
| `meetings` | Shared | no | 1 §4 · 19-23 §4.2 | `CRUD_FULL` + `ASSIGN` + `STATUS` + `FILES` + `view_reports`; `view` = organised or attended | **12** |
| `messages` | Shared | no | 1 §4 · 19-23 §4.2 | `READ` + `create` + `STATUS` + `FILES` + `LOGS` — **never `edit`, never `delete`**; `view_any` granted to nobody by default | **7** |
| `files` | Shared | no | 1 §4 · **6 §4.2** (`READ` + `FILES` + `delete`) · 19-23 §4.2 (+ `STATUS`) | `view_any`, `view`, `upload`, `download`, `delete`, `change_status` (= share/unshare with the client portal) | **6** |
| `notifications` | Shared | no | 1 §4 · 19-23 §4.2 | `READ` + `STATUS` + `delete` (archive one's own row); a user always reaches their **own** rows | **4** |
| `reports` | Shared | no | 1 §4 · 19-23 §4.2 | `READ` + `REPORTS` = `view_any`, `view`, `view_reports`, `export`, `print`. Double-gated: the hub needs `reports.view_reports`, each report stacks its source module's `view_reports` and money columns its `view_financial` (INV-23-2) | **5** (see O-6) |
| `ticket_departments` | Shared | **no** | 19-23 §4.1 | `CRUD` + `STATUS` | **6** |

**System-group slugs declared elsewhere whose tables are outside this file:** `system_health` (4 —
`view_any`, `view`, `view_logs`, `export`) and `integrity_checks` (5 — `view_any`, `view`, `create`,
`export`, `view_logs`), both phase-24-25 §4.1, both `is_core = false`. Listed so the System group reads
complete; their tables belong to the operations slice.

**Portal prefixes are not modules.** `client_portal.*`, `student_portal.*`, `teacher_portal.*`,
`collaborator_portal.*` are permission **namespaces**: `permissionModuleMap()` returns `null` for them and
`Gate::before` falls through (**D20**, F-12.1). Phase 22 adds `client_portal.ticket_create`,
`.message_send`, `.meeting_respond`; `collaborator_portal.tickets`, `.ticket_create`, `.meeting_respond`;
plus the student/teacher ticket, message, meeting and notification reads (phase-19-23 §4.3).

**`modules.depends_on` inside this domain:** `support_tickets -> ticket_departments`;
`audit_trail -> activity_log`; `ticket_departments`, `meetings`, `messages`, `notifications`, `reports`,
`global_search` declare none (phase-19-23 §4.4). Two services must survive their own module being
disabled: `NotificationService` **no-ops with an `info` log line and never rolls back the business
transaction** (INV-22-7, D55), and `GlobalSearchService` drops that provider from the palette.

### 5.2 Settings groups that change this domain's behaviour

Definitions live in `SettingsRegistry` (code), values in `settings` (DB).

| Group | Keys | Owner | What it changes here |
|---|---|---|---|
| `company` | 8 | 2 §2 | shell, print headers, `ReportGroup::label()` (no brand name is hardcoded) |
| `branding` | 8 | 2 §2 | `brand_color`/`accent_color` repaint the shell through CSS variables at runtime |
| `localization` | 10 | 2 §2 | `currency*`, separators, `timezone`, `date_format`, `time_format`, `week_start`, `locale` drive `Format::money()`/`app_date()`/`app_time()` everywhere, and `app.timezone`/`app.locale` via `ConfigureFromSettings` |
| `mail` | 9 | 2 §2 | `mailer`/`host`/`port`/`username`/**`password` (encrypted, logged as `[encrypted]`)**/`encryption`/`from_*`/`reply_to`; `TestMailService` uses the **saved** values, never `.env` |
| `security` | 8 (2 §2) **+ 18** (24-25 §5) | 2 §2 owns the first 8; 24-25 adds and redefines none | 2: `password_min_length`, `force_password_change_days`, `login_max_attempts`, `lockout_minutes`, `session_lifetime`, `allowed_file_types`, `max_upload_mb`, `two_factor_enabled` *(declared, inert)*. 24-25: `force_https`, `hsts_*` (3), `csp_*` (3), `frame_ancestors`, `login_throttle_per_minute`, `global_write_throttle_per_minute`, `export_throttle_per_minute`, `print_throttle_per_minute`, `session_absolute_lifetime_hours`, `session_single_device`, `password_history_count`, `upload_blocked_extensions`, `upload_require_mime_match`, `trusted_proxies`. `allowed_file_types` + `max_upload_mb` are the **intersection ceiling** every uploader narrows into (never widens) |
| `maintenance` | 5 | 2 §2 | `maintenance_mode` blocks the public site but never `/admin`; `public_site_enabled` serves the holding page |
| `support` | **37** | 19-23 §5.2 ([D-22-4], label "Support, Meetings & Messaging") | ticket numbering (`ticket_prefix`, `ticket_next_number`), `ticket_default_department_id`/`_priority`, `ticket_auto_assign`, **`sla_enabled` (the master switch)** + 4 SLA keys, auto-close and reopen windows, 4 per-panel `ticket_allow_*_create`, 2 attachment ceilings, 6 meeting defaults incl. `meeting_room_clash_block`, `messaging_enabled` + **`messaging_allowed_pairs`** + 3 `*_can_start` + 3 attachment/rate keys, `notifications_mail_enabled` + 4 notification delivery keys |
| `reports` | **15** | 19-23 §5.3 ([D-23-1], label "Reports & Search") | `default_date_preset`, `sync_row_limit`, `export_max_rows`, `export_retention_days` (sets `report_exports.expires_at`), `excel_enabled`, `pdf_paper_size`/`_orientation`, `cache_ttl_seconds`, 4 `global_search_*`, **`activity_log_retention_days` default `0` = never prune**, `audit_sensitive_modules`, `audit_show_financial_values` |
| `institute.default_branch_id` | 1 key | 2 §2 | the default `branches` row a service copies `branch_id` from |
| `ops` | 16 | 24-25 §5 | health endpoint + token, queue/scheduler heartbeats, `failed_jobs_alert_threshold`, `slow_query_ms`, `query_budget_enforced`, `log_retention_days`, error monitoring/digest, `maintenance_secret`, `app_version` |
| `backup` | (24-25 §5.1) | 24-25 §5 | gates the `backups` module's behaviour; tables are in the operations slice |

Remaining Phase 2 groups (`contact` 12, `social` 8, `seo` 11, `collaborator` 15, `institute` 10,
`finance` 9) belong to other domains, except that `contact.business_hours` (json, per-day open/close/closed)
is read by `TicketSlaService::dueAt()` when `support.sla_business_hours_only` is on.

### 5.3 Dashboard widget keys (phase-02 `DashboardRegistry`, one key = one owning phase)

`key()` is globally unique and a duplicate registration throws `DuplicateWidgetKeyException` (F-8.3).
Phase 2 owns its ten — `UsersByStatusWidget`, `RolesOverviewWidget`, `ModulesEnabledWidget`,
`LoginsTodayWidget`, `FailedLoginsWidget`, `LoginTrendChartWidget`, `RecentActivityWidget`,
`RecentLoginsWidget`, **`SystemHealthWidget`** (stays Phase 2's; phase-24-25 refactors its body to read
`SystemHealthService` and registers only its own four new keys) and `StorageUsageWidget`. The shared
services add `OpenTicketsWidget`, `TicketSlaWidget`, `UpcomingMeetingsWidget`, `UnreadMessagesWidget` and
`TicketVolumeChartWidget` (phase-19-23 §8.20). Per-user layout and hidden widgets persist in
`users.preferences` — never in a table.

---

## 6. Open points

| # | Open point | Source |
|---|---|---|
| **O-1** | **`branches` has no module slug and no `branches.*` permission.** phase-01 §1.5 creates the table and `Branch::default()`; §4's module list omits `branches`, and no contract from phase-02 to phase-24-25 registers it. Branch CRUD screens therefore have no declared gate, and `institute.default_branch_id` is the only admin-editable branch surface. No finding id — the audit never mentions `branches`. | phase-01 §1.5 vs §4 |
| **O-2** | **`settings.type` has two incompatible value sets.** phase-01 §1.4 declares `string(16)` with `string\|text\|boolean\|integer\|decimal\|json\|file\|select` (8). phase-02 §2 declares registry field types `text textarea email tel url number decimal boolean select multiselect color image file json time password richtext` (17) — `string` and `integer` are absent from it and 9 of its types are absent from phase-01's set. Not reconciled by any finding. | phase-01 §1.4 vs phase-02 §2 |
| **O-3** | **`attachments.visibility` default is stated twice, differently:** phase-06 §2.9 says default **`team`**; phase-19-23 §13.1's ask says default **`internal`**. F-2.6 settled the *mechanism* (the enum replaces every boolean) but not the default. Owner (Phase 6) should win; recorded rather than renamed here. | F-2.6 · phase-06 §2.9 · phase-19-23 §13.1 |
| **O-4** | **Citation drift:** phase-19-23 §13.1 and §13.2 cite `attachments` as "Phase 6 §2.10"; in phase-06 §2.9 is `attachments` and §2.10 is `time_entries`. Text-only. | phase-19-23 §13.1 |
| **O-5** | **Phase 1 §4 never enumerates the ability set per System module slug** — only the ten presets and the rule "financial modules additionally get `MONEY`…". So the permission count for `dashboard`, `users`, `roles`, `permissions`, `modules`, `settings`, `activity_log` and `login_history` is not derivable from any contract. F-6.4 reworded the surrounding paragraph but added no per-slug matrix. | F-6.4 · phase-01 §4 |
| **O-6** | **`reports` ability set stated twice, differently:** phase-06 §4.2 and phase-13 §4 both say "unchanged (`REPORTS`)" (3); phase-19-23 §4.2 declares `READ` + `REPORTS` (5). The later additive declaration is the live one, but two contracts still read "unchanged". | phase-06 §4.2 · phase-13 §4 · phase-19-23 §4.2 |
| **O-7** | **Two keys for one idea:** `reports.sync_row_limit` and `finance.report_sync_row_limit`. Convergence is explicitly deferred to Phase 24's hardening pass; until then **phase-13's key wins for its four finance reports**. | phase-19-23 R-9 + §13.2 |
| **O-8** | **D20 is contract text, not code yet.** `Modules::permissionModuleMap()` returning `null` for an unregistered prefix and `Gate::before` falling through on `null` is listed **Pending** against the Phase 1 remediation team. Until it lands **no phase may assume a `*_portal` permission survives module gating**, and all four non-admin panels are at risk for everyone including Super Admin. | F-12.1 / D20 · phase-01 §11 A2 |
| **O-9** | **The canonical `Money` surface is pending code.** The 20-method surface and `App\Enums\RemainderPlacement` are in phase-01 §3's text, flagged **Pending** in §11 A1, asserted later by FIN-12. This domain owns no money column, but `Format::money()` and every shared-service screen read through `Money`. | F-4.11 · phase-01 §11 A1 |
| **O-10** | **`password_histories` is requested, not defined** (see §2.4). `security.password_history_count` is inert until Phase 1 ships it. | phase-24-25 §13.1, Q13 |
| **O-11** | **`security.two_factor_enabled` is declared and inert** — no phase owns 2FA; phase-24-25 Q6 puts it out of scope for this release and warns it must not be bolted onto a hardening pass. | phase-02 §2 · phase-24-25 Q6 |
| **O-12** | **No reader is declared for four `security` keys owned by Phase 2.** `ConfigureFromSettings` (phase-02 §3) applies only mail, `app.timezone`, `app.locale` and the brand colour. `password_min_length`, `force_password_change_days`, `login_max_attempts` and `lockout_minutes` get their application points only in phase-24-25 (`RateLimitServiceProvider`, SEC-18, SEC-26) — i.e. 23 phases after the setting is editable. | phase-02 §2/§3 vs phase-24-25 §5, SEC-18/SEC-26 |
| **O-13** | **`login_histories` has no `reason` column**, yet phase-24-25 §6.3's `EnforceSessionLifetime` records "a `logout` login-history row **with reason `absolute_timeout`**". Either the column is an additive ask on Phase 1 or the reason belongs in `activity_log`. Not requested in phase-24-25 §13.1. | phase-01 §1.6 vs phase-24-25 §6.3, SEC-26 |
| **O-14** | **Off-by-one in a count sentence:** phase-19-23 §2 says "the **eight** tables of §2.1 that carry no `deleted_at`"; §2.1 lists **nine** (`course_material_targets`, `course_material_downloads`, `assignment_submission_files`, `certificate_verifications`, `meeting_participants`, `conversation_participants`, `notifications`, `notification_preferences`, `report_exports`), and §13.3's list of eight omits `certificate_verifications`. The schema is unambiguous; only the count is wrong. | phase-19-23 §2 vs §2.1 vs §13.3 |
| **O-15** | **`branches.is_default` has no uniqueness guard** in phase-01 §1.5, while the later `ticket_departments.is_default` + `default_guard` STORED column + `uq_td_default` ([D-19-0]) is the system's established device for exactly this. `Branch::default()` is ambiguous if two rows are flagged. Phase 1 is built, so this is a contract-text gap that may or may not be a code gap. | phase-01 §1.5 vs phase-19-23 §2.17 |
| **O-16** | **Needs-human, still open (recommended default implemented):** H1 no REST API this release (F-13.1 — requirements §6 still says a disabled module "blocks API access"); H3 ticket SLA built behind `support.sla_enabled` default `true`, every clock column nullable so the flip needs no migration (F-13.5). | resolutions §6.1 H1, H3 |
| **O-17** | **Scope recorded as deliberate, not requested by any requirement:** `notification_preferences` per user/event with `mail_digest` and mandatory-event locking (F-13.6) — build, with the four `mandatory: true` events ([D-22-2]) permanently unmutable. | F-13.6 · resolutions §6.2 |
| **O-18** | **Assumed client defaults inside this domain:** `support.notifications_mail_enabled = false` until real SMTP exists (Q12); messages and ticket replies are append-only, never editable or deletable (Q11, [D-22-1]); `reports.activity_log_retention_days = 0` — never prune, and the prune command refuses financial modules even when on (Q10); no separate announcement/broadcast feature (Q13); a portal user may not choose a ticket priority (Q6); collaborators may raise tickets (Q5). | phase-19-23 §12.2 |
