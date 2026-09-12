# PHASE 19-23 CONTRACT - Course material, assignments, exams, results, certificates, ID cards, collaboration tools, reporting

**Status: binding.** One document for five phases because they share three cross-cutting mechanisms that
must be built once and not five times: **secure private-file storage with a permission check at every
download** (19, 21, 22, 23), **the printable-document pipeline** (20's result card, 21's certificate and
ID card), and **one notification / one report / one search registry** that every earlier phase plugs into
(22, 23). Splitting them would have produced five uploaders, three PDF paths and two notification stacks.

Covers requirement [`../requirements.md`](../requirements.md) **§79-§85**, **§93-§99**, **§106-§108**, and
the read side of §88 and §98 that §99 reports re-use.

Conventions come from [`../../CLAUDE.md`](../../CLAUDE.md). [`phase-01.md`](phase-01.md) and
[`phase-02.md`](phase-02.md) **win over anything here**; a suspected error in them is recorded in §12.2,
never silently redesigned. Where this document touches money it obeys
[`../design/finance-commission-spine.md`](../design/finance-commission-spine.md) and
[`phase-18.md`](phase-18.md); where it touches the institute object graph it obeys
[`phase-14-17.md`](phase-14-17.md); where it touches a finance report it obeys
[`phase-13.md`](phase-13.md). Those four documents are read, not re-interpreted.

Decisions are labelled **[D-19-n]** … **[D-23-n]** so a code review can cite them.

---

## Contents

| § | Contents |
|---|---|
| 1 | Goal, dependencies, phase ownership, invariants |
| 2 | Schema - 25 tables, `branch_id` placement, status lifecycles, the shared `attachments` contract |
| 3 | Enums to add |
| 4 | PermissionRegistry additions - 5 module slugs, ability deltas, portal permissions, `depends_on` |
| 5 | SettingsRegistry additions - `institute` deltas plus two new groups (`support`, `reports`) |
| 6 | Services, the file-storage layout, the download permission chain, the print pipeline, the registries |
| 7 | Routes |
| 8 | UI screens |
| 9 | Data isolation |
| 10 | Events, notifications (the full §97 list), jobs, scheduled tasks |
| 11 | Acceptance tests |
| 12 | Risks and open questions |
| 13 | Requests to other phases |

---

## 1. Goal, dependencies, ownership, invariants

### 1.1 Goal

After these five phases the institute and the software house run their whole teaching, assessment,
communication and reporting loop inside the platform, with no file ever served without a permission check.

A teacher uploads the eight file kinds of §79 plus an external link, targets them at a whole course, one
batch or a single student, and sees who opened each one; publishes an assignment with a deadline, a total
mark and a late-submission policy; receives file or text submissions that are stamped late to the minute;
and marks them with feedback without ever being able to award more than the total. A coordinator schedules
the six exam types of §81 against a batch, enters a whole batch's marks on one validated sheet where marks
can never exceed the total, and publishes results that derive their percentage, grade and pass decision
from an **admin-configurable grade scale**, then prints a result card. Once a student's attendance,
progress, fees and results satisfy the configured eligibility rules, the institute issues a numbered
certificate carrying a QR code that resolves to a **public verification page revealing only the fields the
admin allows**; a revoked certificate says so in plain language and never quietly disappears; and student
ID cards print singly or a hundred at a time from a template the admin controls, with a photo and a QR.

In parallel the platform gains its collaboration layer: numbered support tickets with departments,
priorities, assignment, the five statuses of §93, threaded replies with attachments and SLA clocks that
pause while a ticket waits on the requester; meetings with participants drawn from all five panels and
from outside, linked to a project or a course, with a URL, notes, ICS and reminders; internal messaging
restricted to exactly the six role pairs of §94, where a student cannot open a thread with a client
because the matrix - not the UI - forbids it; and one notification system with a database channel, a bell
with unread counts, per-user per-event preferences and a mail channel wired and waiting.

Finally everything becomes reportable: a **report registry** in which each of the 31 reports of §99
declares its own columns, filters, permission and date basis and delegates every figure to the service
that owns it, with print, PDF and streamed CSV export; cross-domain analytics charts; the §106 activity log
and §107 audit trail as first-class viewers over Phase 1's one log table; and a keyboard-driven global
search palette across the eleven entity types of §108, each entity filtered by its own permission and its
own isolation scope.

### 1.2 Dependencies

| Phase | What these phases need from it | Status |
|---|---|---|
| 1 | `users`, `branches`, RBAC + `PermissionRegistry` + `Gate::before` module gating, `Blameable`, `LogsActivityWithContext`, `activity_log` with old/new values, `App\Support\Money`, `App\Support\Device`, `Sidebar`, `layouts/admin`, `layouts/panel`, the `x-ui.*` set, `active` / `module` / `panel` middleware, `PanelType`, `Ability` | required |
| 2 | `SettingsRegistry` + `SettingsService`, `DashboardRegistry` + `DashboardWidget`, `DateRange` (+ `previous()`), `Format` (`money()`, `app_date()`, `app_time()`, `app_datetime()`), `users.preferences`, `x-ui.chart` | required |
| 3 | `layouts/site.blade.php`, `<x-site.seo>`, the **`site`** middleware alias (Phase 3 §6.10 ships `site`, `site.cache`, `site.preview`), `App\Support\RichText::sanitize()` (the one sanitiser, D25), `PublicCache` | required for the public verification page |
| 5 | `clients`, `client_contacts`, `ClientContext`, `EnsureClientContext`, `ClientPortalRegistry` + `ClientPortalSection`, **`App\Support\CsvWriter`**, `App\Services\Finance\DocumentNumberService` | required |
| 6 | **`attachments`** (Phase 6 §2.10 - the one polymorphic file table, with `attachments.visibility` cast to `AttachmentVisibility` (`internal` / `team` / `client`); this phase extends the morph map and does **not** create a `files` table), `task_comments`, `projects`, `tasks`, `ProjectProgressService`, the enums `AttachmentVisibility`, `Priority` and `CommissionCalculationType` | required |
| 7 | `employees`, `departments`, `attendance_monthly_summaries`, `payroll_runs` / `payroll_run_items`, **`App\Services\Hr\PayslipService`** with its existing signature `render(PayrollRunItem): View` / `export(PayrollRun, string $format)` (phase-07 §6 - it already exists; Phase 23 never declares a second one) | required for §99 HR reports only |
| 8-9 | `collaborators` (`status`, `referral_code`, `user_id`), `CollaboratorContext`, `ReferralTrackingService::funnel()` | required |
| 10-12 | `student_fee_payments`, `project_payments`, `payment_reversals`, `collaborator_commission_ledger_entries`, `CollaboratorStatementService`, `CollaboratorWalletService`, `DocumentNumberService` | required |
| 13 | `barryvdh/laravel-dompdf`, **`resources/views/layouts/print.blade.php`**, `FinanceReportService`, **`ExportFormat`**, **`App\Services\Reporting\ReportExporter::export(ReportResult $r, ExportFormat $f): StreamedResponse`**, **`App\Support\ReportResult`** (readonly: `array $rows`, `array $groups`, `array $totals`, `array $meta`), `FinanceVisibility` - all three reporting artefacts are owned and shipped by **Phase 13 §6.9** | required |
| 14-17 | `courses`, `course_modules`, `course_topics`, `course_lectures`, **`course_topic_assignments`** (the blueprint Phase 19 instantiates), `course_topic_resources` (the syllabus resource Phase 19 must not duplicate), `students`, `student_admissions`, `teachers`, `classrooms`, `batches`, `student_batch_enrollments`, `class_sessions`, `student_attendances`, `student_course_progress`, `CourseResourceType`, `DeliveryMode`, `Weekday`, `TeacherScope`, `BelongsToAuthenticatedStudent`, `BatchEnrollmentService::roster()`, `AttendanceReportService`, `CourseProgressService`, **`ScheduleClashDetector::check(SlotCandidate $c): ClashReport`** (the generic check of D47, with `App\DataObjects\Institute\SlotCandidate` and `ClashReport`), `StudentDirectoryService` | required |
| 18 | `StudentFeeService` - the fee-cleared check for certificate eligibility through `outstandingFor(StudentAdmission\|StudentBatchEnrollment): string` (phase-18 §6.1) - `FeeSlipBuilder`, the fee scopes the §99 fee reports read | required |

**The four cross-phase artefacts these phases lean on each have a named owner** (audit F-11.5), so all four
stay *required* rather than conditional: `App\Support\ReportResult`, `App\Services\Reporting\ReportExporter`
and `resources/views/layouts/print.blade.php` are **Phase 13 §6.9**; `App\Services\Hr\PayslipService` is
**Phase 7 §6** and already exists. None is re-created here and none degrades to "or Phase 18 ships it".

Migration timestamps sort **after** phase 18's batch, in the order 19 → 20 → 21 → 22 → 23. Every FK whose
target belongs to an earlier phase is a real constraint; the only guarded (`Schema::hasTable`) migrations
are the two listed in §2.1.

### 1.3 Phase ownership - no table is created twice

| Phase | Owns (tables, enums, services, screens) | Must NOT create or write |
|---|---|---|
| **19** | `course_materials`, `course_material_targets`, `course_material_downloads`, `assignments`, `assignment_submissions`, `assignment_submission_files`; `CourseMaterialService`, `MaterialAccessService`, `AssignmentService`, `AssignmentSubmissionService`, `AssignmentGradeCalculator`, **`App\Services\Files\SecureFileService`** (the one uploader every later phase here re-uses) | `course_topic_resources` / `course_topic_assignments` (Phase 14 - read and referenced only), `attachments` (Phase 6), any `student_fee*` row |
| **20** | `grade_scales`, `grade_scale_bands`, `exams`, `exam_results`; `GradeScaleService`, `ExamService`, `ExamResultService`, `ResultCalculator`, `ExamStatisticsService`, `ResultCardBuilder` | `student_attendances`, `student_course_progress` rows except through `CourseProgressService` (§6.12), `certificates` (Phase 21) |
| **21** | `print_templates`, `certificates`, `certificate_verifications`, `student_id_cards`; `PrintTemplateService`, `App\Support\PrintTokenRegistry`, `CertificateEligibilityService`, `CertificateService`, `CertificateVerificationService`, `StudentIdCardService`, `QrCodeService` | `exam_results` (Phase 20 - read only), `students` / `batches` (Phase 15/16), any fee row |
| **22** | `ticket_departments`, `support_tickets`, `ticket_replies`, `meetings`, `meeting_participants`, `conversations`, `conversation_participants`, `messages`, `notifications`, `notification_preferences`; `TicketService`, `TicketSlaService`, `TicketAssignmentService`, `MeetingService`, `ConversationService`, `App\Support\MessagingMatrix`, `NotificationService`, `App\Support\NotificationRegistry`, `NotificationPreferenceService`, `App\Support\UnreadCounters` | `attachments` (Phase 6 - **extended**, never re-created), `task_comments` (Phase 6), any `files` table |
| **23** | `report_exports`; `App\Support\ReportRegistry` + the 31 report classes, `ReportEngine`, `ReportExportService`, `AnalyticsService`, `ActivityLogService`, `AuditTrailService`, `App\Support\GlobalSearchRegistry` + 11 providers, `GlobalSearchService` | `activity_log` (Phase 1 - additive indexes only), `ExportFormat` (Phase 13 - one case added), `ReportExporter` (Phase 13 - promoted, not duplicated), **any `SUM()` another phase's service already defines** |

### 1.4 Invariants

Enforced by the database where the database can express it, by a model hook where it cannot, and by a
named acceptance test in every case.

| # | Invariant | Enforced by |
|---|---|---|
| INV-19-1 | **No private file is ever reachable by URL alone.** Every material, brief, submission, feedback file, certificate PDF, ID-card PDF, ticket attachment, message attachment and report export lives on the `private` disk and is served only by a controller action that runs the seven-step chain of §6.4. `Storage::url()`, `temporaryUrl()` and `public` disk writes are forbidden for all of them. | §6.3, §6.4; PH19-01, PH19-02, PH21-30, PH22-40, PH23-16 |
| INV-19-2 | A stored filename is always a fresh ULID plus a server-decided extension. The client-supplied name is kept in `original_name` and used **only** in `Content-Disposition`. MIME is sniffed from content, never read from the request. | `SecureFileService`; PH19-03, PH19-04 |
| INV-19-3 | A student reaches a material only when a target of that material resolves to their course, one of their batches, or themselves, **and** the material is `published`, **and** `available_from`/`available_until` admit the current time, **and** their enrollment is inside the access window of `institute.material_visible_after_batch_end_days`. Four conditions, one scope, one policy. | `MaterialAccessService`; PH19-10..PH19-16 |
| INV-19-4 | Every material open is logged in `course_material_downloads` **before** the stream starts, inside the same request, with actor, panel, action, IP and device. `download_count` / `view_count` are caches re-derivable from that table. | PH19-17, PH19-18 |
| INV-19-5 | A submission exists only for a student holding an **active enrollment in that assignment's batch**, and at most one **live** submission per (assignment, student). A resubmission supersedes the previous row; nothing is overwritten and nothing is deleted. | `uq_as_live`, `uq_as_attempt`; PH19-20..PH19-24 |
| INV-19-6 | `obtained_marks` can never exceed the assignment's `total_marks`, and `final_marks` is a STORED generated column over `obtained_marks - penalty_marks` floored at zero. Marks arithmetic is bcmath through `AssignmentGradeCalculator`; no PHP `+ - * /` touches a mark. | Form Request + service assertion + generated column + `assignments:verify-marks`; PH19-30..PH19-34 |
| INV-19-7 | Lateness is decided by the server clock against `deadline_at` at the moment the submission row is inserted, stored as `is_late` + `minutes_late`, and never recomputed afterwards (a later deadline edit does not retrospectively un-late a student). | `AssignmentSubmissionService::submit()`; PH19-25, PH19-26 |
| INV-20-1 | **`exam_results.total_marks` is a snapshot taken from the exam at entry time**, which makes `CHECK (obtained_marks <= total_marks)` a real database constraint and makes a published result card reproducible for ever, even after the exam row is edited. | `chk_er_marks`; PH20-01, PH20-02, PH20-32 |
| INV-20-2 | A result's `percentage`, `grade`, `grade_point` and `is_passed` are written **only** by `ResultCalculator` through `ExamResultService`, from the snapshotted scale, with bcmath half-up at 2 decimals. A controller, a Form Request and a seeder can never write them. | Model `updating` hook; PH20-05..PH20-09 |
| INV-20-3 | A grade scale's bands must be contiguous, non-overlapping and cover exactly 0.0000-100.0000, with at most one band boundary separating pass from fail. A scale that fails validation cannot be saved or made default. | `GradeScaleService::validateBands()` + `grades:verify-scales`; PH20-10..PH20-14 |
| INV-20-4 | A band or a scale referenced by any result is never hard-deleted - it is deactivated; the result keeps `grade`, `grade_point` and `percentage` as its own columns so a scale edit can never rewrite history. | Policy + `restrictOnDelete`; PH20-15, PH20-16 |
| INV-20-5 | Results are **corrected, never deleted**: after publication an amendment requires `results.edit`, a mandatory reason, and writes `amended_at` / `amended_by` / `amendment_reason` plus an `activity_log` row with old and new marks. Unpublishing requires a reason too. | `ExamResultService::amend()`; PH20-17, PH20-18 |
| INV-20-6 | Batch-wide entry is one transaction over the **roster for the exam date**; a `student_id` not on that roster is rejected for the whole sheet, and a double submit upserts on `uq_er_exam_student` rather than duplicating. | `ExamResultService::saveSheet()`; PH20-21..PH20-25 |
| INV-21-1 | A certificate number is issued once, inside the issuing transaction, by `DocumentNumberService`, and is never reused, re-numbered or deleted. A wrong certificate is **revoked** (and optionally reissued as a new row pointing at it). Revocation is never a delete. | `uq_ce_number`, policy refuses `delete`/`forceDelete`; PH21-01..PH21-06 |
| INV-21-2 | `verification_code` is a 16-character random code from a 32-symbol alphabet (≈80 bits), independent of the sequential certificate number, unique, and compared with a constant-time comparison after an indexed lookup. The public page is rate-limited per IP and every attempt is logged. | `uq_ce_code`, `CertificateVerificationService`; PH21-10..PH21-16 |
| INV-21-3 | The public verification page renders **only** the fields listed in `institute.certificate_verification_reveals`, plus the certificate status. It never renders a phone, email, CNIC, address, guardian, fee, commission or collaborator field, and no query parameter can widen it. | `CertificateVerificationService::publicPayload()`; PH21-17..PH21-21 |
| INV-21-4 | Every certificate and ID card stores **snapshots** of the names, codes, dates, grade and its `qr_payload`. A later rename of a student, course, batch or teacher, or a change to `institute.certificate_verification_url`, never alters an issued document. | Columns + `CertificateService::issue()`; PH21-07, PH21-08, PH21-22 |
| INV-21-5 | A print template's `body_html` is **never** compiled as Blade, never passed to `eval`, `Blade::render`, `@php` or a view factory. It is sanitised by **`App\Support\RichText::sanitize()`** (Phase 3's single sanitiser over `mews/purifier`, D25) on save **and** again on render, and rendered by a token replacer whose token list comes from `PrintTokenRegistry`. An unknown token renders empty. There is no second sanitiser class in the system. | `PrintTemplateService::render()` over `RichText::sanitize()`; PH21-25..PH21-29 |
| INV-21-6 | At most one **live** certificate per enrollment and one **active** ID card per student, enforced by a generated-column unique index; history stacks freely behind it. | `uq_ce_live`, `uq_sic_live`; PH21-09, PH21-35 |
| INV-22-1 | A ticket number is issued once inside the creating transaction by `DocumentNumberService`; a ticket is never deleted (policy refuses `delete` and `forceDelete`) and a reply is append-only - a correction is a new reply. | `uq_tk_number`, policies; PH22-01..PH22-04 |
| INV-22-2 | SLA clocks are computed by `TicketSlaService` from the department's (or the setting's) minutes, **pause while `status = waiting`**, and are stamped on the row; `first_response_at` is set by the first **public staff** reply only - an internal note is never a response to the customer. | PH22-10..PH22-16 |
| INV-22-3 | An `internal_note` reply is absent from every non-staff response body and from every notification payload. Withheld, not blanked. | `ReplyVisibility` + explicit column lists; PH22-17, PH22-18 |
| INV-22-4 | A conversation can exist only between a pair allowed by `App\Support\MessagingMatrix` (the six pairs of §94) and enabled in `support.messaging_allowed_pairs`. The check runs on create **and** on every send, so revoking a pair silences an existing thread instead of leaking into it. **A student can never message a client, and a client can never message a student or a collaborator.** | `MessagingMatrix`; PH22-20..PH22-28 |
| INV-22-5 | Two users can never accumulate two `direct` conversations: `conversations.direct_key` is a unique hash of the sorted participant ids. | `uq_cv_direct`; PH22-29 |
| INV-22-6 | Read state is a per-participant pointer (`last_read_message_id`, `last_read_at`, `unread_count`); `unread_count` is a cache recomputed by COUNT under a row lock, never incremented blindly. | `ConversationService::markRead()`; PH22-30, PH22-31 |
| INV-22-7 | **Every notification is sent through `NotificationService`**, which resolves channels from `NotificationRegistry` defaults overridden by `notification_preferences`, honours the `support.notifications_mail_enabled` master switch, and no-ops (with a log line, never an exception) when the `notifications` module is disabled. No phase calls `$user->notify()` directly. | §6.17; PH22-50..PH22-56 |
| INV-22-8 | A notification is dispatched only `DB::afterCommit()` and is addressed to a resolved **recipient list**, never to "everyone with a role name". An audience is either an explicit model or the holders of a named permission. | `NotificationRegistry`; PH22-57, PH22-58 |
| INV-23-1 | **A report never re-implements a figure.** Every row, total and chart point is produced by the service that owns the table (`FinanceReportService`, `AttendanceReportService`, `StudentFeeService`, `CollaboratorStatementService`, `CollaboratorWalletService`, `ExamStatisticsService`, …). A `SUM()` inside a report class is a review failure. | §6.20; PH23-01, PH23-02 |
| INV-23-2 | A report is double-gated exactly as Phase 13 §4 established: the hub needs `reports.view_reports`; each report additionally needs its source module's `view_reports`, and a money column additionally needs that module's `view_financial`. A withheld column is **absent from the query and from the export file**, never blank. | `ReportDefinition::permissions()` + `FinanceVisibility`; PH23-03..PH23-08 |
| INV-23-3 | Every export streams: CSV through `CsvWriter` + `chunkById` (with the formula-injection escape), PDF through dompdf over `layouts/print`, print through a Blade. A result above `reports.sync_row_limit` is queued as a `report_exports` row and delivered by notification; the download re-authorises the original report permission and the requester identity. | §6.21; PH23-10..PH23-17 |
| INV-23-4 | Global search returns only rows the user could open: each provider applies its module gate, its permission and the **same isolation scope** the module's index uses. A provider whose module is disabled contributes nothing and is absent from the palette. | `GlobalSearchRegistry`; PH23-20..PH23-29 |
| INV-23-5 | The activity log and audit trail are **read-only** surfaces over Phase 1's `activity_log`. Phase 23 adds indexes and viewers - never a column, never a second log table, never a delete route. Retention pruning is off by default (`reports.activity_log_retention_days = 0`). | §2.25, §6.22; PH23-35..PH23-40 |
| INV-ALL-1 | Money and percentage arithmetic anywhere in these five phases goes through `App\Support\Money` (bcmath, half-up at 2). Marks, percentages, grade points, SLA minutes-to-hours conversions and report totals included. No PHP `+ - * /` on a `decimal` value. | Code review + PH23-45 (static scan) |
| INV-ALL-2 | Every irreversible or discretionary act in these phases (publish, close, revoke, reissue, unpublish, amend, delete a material target, remove a participant, change a template, prune a log) writes an `activity_log` row with old/new values, actor, IP and - where §2.27 marks it - a **mandatory reason**. | `LogsActivityWithContext::withReason()`; PH23-41 |
| INV-ALL-3 | A panel user reaching another panel user's row gets **404, not 403**, so ids cannot be probed; a permission failure gets 403. Both are asserted, together with the absence of the forbidden columns from the body. | §9; every isolation test of §11 |

---

## 2. Schema

InnoDB, utf8mb4, per Phase 1 §1. Unless a row says otherwise every table carries `created_at`,
`updated_at`, `deleted_at` (soft deletes), `created_by`, `updated_by` (nullable FK `users.id`,
`nullOnDelete`, filled by `Blameable`).

**Soft deletes follow the category rule, not a local decision.** The eight tables of §2.1 that carry **no**
`deleted_at` are append-only logs, snapshots and history pivots, and they are omitted under
`DEVELOPMENT_LOG.md` §4 **D19** (the full category table is in `CLAUDE.md` §3). These phases invent no
soft-delete decision of their own and never add `deleted_at` back to one of those tables.

**Percentages and marks.** Every `*_rate` and `*_percentage` column in these five phases is
**`decimal(8,4)`** — there is no "reported percentage" exception (`CLAUDE.md` §3, audit F-7.1). Marks are
**`decimal(8,2)`** and are *not* percentages: their ceiling is a per-row CHECK against a snapshotted
`total_marks` (audit F-7.2), which is why `*_marks` is an allowlisted exception in phase-24-25's FIN-18.

**[D-19-0] The generated-guard device is re-used, not re-invented.** Where "at most one live row per
parent" must be a database fact, the table carries a `STORED` generated `tinyint` that is `1` while the row
is live and `NULL` otherwise, and the unique index includes it (MariaDB unique indexes ignore NULLs). This
is phase-14-17's [D-IN-4] and the spine's `current_guard`, verbatim. Those migrations write raw SQL and
**fail loudly** if the server rejects them.

### 2.1 The 25 tables

| # | Table | Phase | Soft deletes | Why |
|---|---|---|---|---|
| 1 | `course_materials` | 19 | yes | §79 |
| 2 | `course_material_targets` | 19 | **no** | the assignment set (course / batch / student); a history pivot, per **D19** |
| 3 | `course_material_downloads` | 19 | **no** | §79 download tracking; append-only log, `created_at` only |
| 4 | `assignments` | 19 | yes | §80 |
| 5 | `assignment_submissions` | 19 | yes | §80 |
| 6 | `assignment_submission_files` | 19 | **no** | child file rows; cascade with the submission |
| 7 | `grade_scales` | 20 | yes | §82 "grade" - configurable |
| 8 | `grade_scale_bands` | 20 | yes | referenced by results, so never hard-deleted |
| 9 | `exams` | 20 | yes | §81 |
| 10 | `exam_results` | 20 | yes | §82 |
| 11 | `print_templates` | 21 | yes | §84 + §85 "admin controls the template", and §82's result card |
| 12 | `certificates` | 21 | yes | §84 |
| 13 | `certificate_verifications` | 21 | **no** | append-only public-verification log, `created_at` only |
| 14 | `student_id_cards` | 21 | yes | §85 |
| 15 | `ticket_departments` | 22 | yes | §93 "department" - dynamic |
| 16 | `support_tickets` | 22 | yes | §93 |
| 17 | `ticket_replies` | 22 | yes (policy refuses delete) | §93 replies |
| 18 | `meetings` | 22 | yes | §95 |
| 19 | `meeting_participants` | 22 | **no** | pivot with payload |
| 20 | `conversations` | 22 | yes | §94 |
| 21 | `conversation_participants` | 22 | **no** | pivot with read pointer |
| 22 | `messages` | 22 | yes (policy refuses delete) | §94 |
| 23 | `notifications` | 22 | **no** | Laravel's database channel table + context columns; `id` is a uuid |
| 24 | `notification_preferences` | 22 | **no** | §97 per-user preferences; a missing row means "registry default" |
| 25 | `report_exports` | 23 | **no** | operational artefact of a queued §99 export; pruned, never audited |

**Foreign keys and guarded migrations.** Every table these five phases reference ships in an earlier phase,
so `support_tickets.client_id` / `.student_id` / `.teacher_id` / `.collaborator_id` / `.employee_id` /
`.project_id`, `meetings.client_id` / `.lead_id` / `.collaborator_id`, `conversations.project_id` and
`course_materials.branch_id` are all **real constraints**, declared inline. Only **two** migrations are guarded
(`Schema::hasTable` / `Schema::hasColumn`), and both concern a table another phase owns: the single
`attachments` morph assertion of §2.27 and the `courses.grade_scale_id` column of §13.1. A guard whose target
is missing **fails loudly** with an instruction - it never silently skips.

### 2.2 `branch_id` placement (decision D11, phase-14-17 [D-IN-5])

Branch goes on the root entities a branch manager filters and reports on, and on the dated tables whose
reports must not need a three-table join. Everywhere else the branch is reached through the parent.

| Table | `branch_id` | Reason |
|---|---|---|
| `course_materials`, `assignments`, `exams` | **yes** nullable | the three library / schedule indexes a branch coordinator filters on every page load; copied by the service from the batch (or from the course when the target is the whole course) and **asserted equal** to it |
| `certificates`, `student_id_cards` | **yes** nullable | a branch issues and prints its own documents, and the register is reported per branch |
| `support_tickets`, `meetings` | **yes** nullable | §93/§95 worklists are branch-scoped for institute staff; copied from the requester's / organizer's profile, never from the form |
| `print_templates` | **yes** nullable | a branch may own a template; `null` = every branch |
| `course_material_targets`, `course_material_downloads`, `assignment_submissions`, `assignment_submission_files`, `exam_results`, `grade_scales`, `grade_scale_bands`, `ticket_departments`, `ticket_replies`, `meeting_participants`, `conversations`, `conversation_participants`, `messages`, `notifications`, `notification_preferences`, `certificate_verifications`, `report_exports` | no | always reached through a parent that has one, or genuinely global configuration |

Scoping rule for every query, exactly as phase-14-17 §2.2:
`where branch_id IS NULL OR branch_id = :user_branch` when `users.branch_id` is set. No branch-switcher UI.

---

### 2.3 `course_materials` (§79)

The distributable material. **[D-19-1] This is not `course_topic_resources`.** Phase 14's
`course_topic_resources` is the *published syllabus* - authored once, public on the landing page, part of
the course definition. `course_materials` is an *act of distribution*: it is targeted, time-windowed,
enrollment-gated, download-tracked and teacher-owned. Phase 14 §2.8 states the same boundary from the
other side. A material may point at a topic for organisation, but it never replaces the syllabus row.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete`; §2.2 |
| `course_id` | FK `courses.id` | not null | `restrictOnDelete`; a material always belongs to one course, and every target must resolve to that course |
| `course_topic_id` | FK `course_topics.id` | nullable | `nullOnDelete`; optional placement in the outline, used for "materials by topic" |
| `teacher_id` | FK `teachers.id` | nullable | `nullOnDelete`; who shared it, when a teacher did |
| `title` | string(180) | not null | |
| `description` | string(1000) | nullable | |
| `type` | string(24) | not null | cast **`CourseResourceType`** (Phase 14's enum, reused - §3). Covers §79's eight kinds plus `link` |
| `storage_disk` | string(32) | `private` | recorded so a future disk migration is provable; **never `public`** for a file material (INV-19-1) |
| `file_path` | string(255) | nullable | ULID name under the §6.3 layout |
| `original_name` | string(255) | nullable | client name, used only in `Content-Disposition` |
| `extension` | string(16) | nullable | server-decided from the sniffed MIME |
| `mime_type` | string(150) | nullable | sniffed from content (INV-19-2) |
| `file_size_bytes` | unsignedBigInteger | nullable | bytes; validated against `institute.material_max_upload_mb` |
| `checksum_sha256` | char(64) | nullable | duplicate **warning** (never a block) and integrity verification |
| `external_url` | string(500) | nullable | §79 external links; `http`/`https` only, validated, no `javascript:` / `data:` |
| `is_downloadable` | boolean | true | false = inline view only (§6.5 states plainly that this is deterrence, not DRM) |
| `available_from` | datetime | nullable | timed release - a teacher uploads ahead of the class |
| `available_until` | datetime | nullable | |
| `status` | string(16) | `draft` | cast `MaterialStatus` |
| `published_at` | timestamp | nullable | stamped on first publish |
| `audience_scope` | string(16) | `course` | CACHE of the broadest live target, cast `MaterialTargetType`; index badge only |
| `targets_count` | smallint unsigned | 0 | CACHE of `course_material_targets` |
| `view_count` | int unsigned | 0 | CACHE of `course_material_downloads WHERE action = 'view'` |
| `download_count` | int unsigned | 0 | CACHE of `action IN ('download','link_open')` |
| `unique_students_count` | int unsigned | 0 | CACHE of distinct `student_id` in the log |
| `sort_order` | int | 0 | |
| `notes` | string(500) | nullable | internal |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `INDEX (course_id, status)`; `INDEX (course_id, course_topic_id)`;
`INDEX (status, available_from, available_until)` the student query;
`INDEX (branch_id, status)`; `INDEX (type)`; `INDEX (teacher_id)`; `INDEX (checksum_sha256)`;
`INDEX (published_at)`.
**CHECK** `chk_cm_payload`: `(file_path IS NOT NULL AND external_url IS NULL) OR (file_path IS NULL AND external_url IS NOT NULL)` - a material is a file **or** a link, never both, never neither.
**CHECK** `chk_cm_window`: `available_from IS NULL OR available_until IS NULL OR available_until > available_from`.
**CHECK** `chk_cm_size`: `file_size_bytes IS NULL OR file_size_bytes > 0`.
**CHECK** `chk_cm_counts`: `targets_count >= 0 AND view_count >= 0 AND download_count >= 0 AND unique_students_count >= 0`.
**Relationships.** belongsTo `Course`, `CourseTopic`, `Teacher`, `Branch`; hasMany `CourseMaterialTarget`,
`CourseMaterialDownload`.
**Rules.** `type = link` forces `file_path` null and `is_downloadable` true (a link is always "openable");
`CourseResourceType::isFile()` types force `external_url` null. Soft-deleting keeps the file on disk;
`forceDelete` (Super Admin only) deletes the bytes inside the same transaction and logs the path.

### 2.4 `course_material_targets` (§79 "assigned to course, batch or student")

**[D-19-2] Three real foreign keys, not a polymorphic `target_id`.** §109 demands normalised tables with
real foreign keys; a `target_type` + `target_id` pair cannot be constrained. The row therefore carries
three nullable FKs and a CHECK that exactly one is set and agrees with `target_type`. One material can
carry several targets, so one uploaded file can reach three batches without three copies of the bytes.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `course_material_id` | FK `course_materials.id` | not null | `cascadeOnDelete` |
| `target_type` | string(16) | not null | cast `MaterialTargetType` - `course` / `batch` / `student` |
| `target_course_id` | FK `courses.id` | nullable | `cascadeOnDelete` |
| `target_batch_id` | FK `batches.id` | nullable | `cascadeOnDelete` |
| `target_student_id` | FK `students.id` | nullable | `cascadeOnDelete` |
| `target_key` | bigint unsigned | **generated STORED** | `COALESCE(target_course_id, target_batch_id, target_student_id)` - the column the unique index needs |
| `notified_at` | timestamp | nullable | set when `MaterialShared` notified this audience, so re-targeting never re-notifies |
| `created_at`, `updated_at` | | | **no** soft deletes, **no** `updated_by`; the actor of an add/remove is in `activity_log` |

**Keys.** `UNIQUE uq_cmt(course_material_id, target_type, target_key)`;
`INDEX (target_type, target_key)` the student/batch lookup; `INDEX (target_batch_id)`;
`INDEX (target_student_id)`; `INDEX (target_course_id)`.
**CHECK** `chk_cmt_one`:
```
(target_type='course'  AND target_course_id IS NOT NULL AND target_batch_id IS NULL AND target_student_id IS NULL) OR
(target_type='batch'   AND target_batch_id  IS NOT NULL AND target_course_id IS NULL AND target_student_id IS NULL) OR
(target_type='student' AND target_student_id IS NOT NULL AND target_course_id IS NULL AND target_batch_id IS NULL)
```
**Relationships.** belongsTo `CourseMaterial`, `Course`, `Batch`, `Student`.
**Rules.** `CourseMaterialService::setTargets()` asserts that a batch target's `course_id` and a student
target's active enrollment both resolve to the material's `course_id`; a mismatch is a validation error
naming the row, never a silent drop.

### 2.5 `course_material_downloads` (§79 download tracking)

Append-only, `created_at` only, the pattern of Phase 4's `blog_post_views`.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `course_material_id` | FK `course_materials.id` | not null | `cascadeOnDelete` |
| `user_id` | FK `users.id` | nullable | `nullOnDelete` - the actor |
| `student_id` | FK `students.id` | nullable | `nullOnDelete` - set when the actor resolved to a student |
| `teacher_id` | FK `teachers.id` | nullable | `nullOnDelete` |
| `panel` | string(16) | not null | cast `PanelType` - which portal the open came from |
| `action` | string(16) | not null | cast `MaterialAccessAction` - `view` / `download` / `link_open` |
| `bytes_sent` | unsignedBigInteger | nullable | null when the stream was aborted |
| `ip_address` | string(45) | nullable | |
| `user_agent` | text | nullable | |
| `device` | string(64) | nullable | `App\Support\Device` |
| `created_at` | timestamp | not null | no `updated_at`, no soft deletes, no blameable |

**Keys.** `INDEX (course_material_id, created_at)`; `INDEX (student_id, created_at)`;
`INDEX (user_id, created_at)`; `INDEX (action, created_at)`.
**Relationships.** belongsTo `CourseMaterial`, `User`, `Student`, `Teacher`.
**Retention.** `materials:prune-download-log` keeps `institute.material_download_log_retention_days`
(default 365; `0` = keep for ever). Pruning writes one activity row with the deleted count.

### 2.6 `assignments` (§80)

The gradable event. Instantiated from Phase 14's `course_topic_assignments` blueprint when one exists
(phase-14-17 [D-IN-6] and its §13 request), which pre-fills title, description, instructions and
`total_marks` from `estimated_marks`.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete`; copied from the batch |
| `course_id` | FK `courses.id` | not null | `restrictOnDelete`; §80 |
| `batch_id` | FK `batches.id` | not null | `restrictOnDelete`; §80 - an assignment is always for one batch |
| `course_topic_assignment_id` | FK `course_topic_assignments.id` | nullable | `nullOnDelete`; the blueprint it came from |
| `course_topic_id` | FK `course_topics.id` | nullable | `nullOnDelete` |
| `teacher_id` | FK `teachers.id` | nullable | `restrictOnDelete`; §80 "teacher creates" |
| `title` | string(180) | not null | §80 |
| `description` | longText | nullable | §80; rich text, sanitised on save |
| `instructions` | text | nullable | |
| `attachment_path` | string(255) | nullable | §80 "file" - the brief, `private` disk |
| `attachment_original_name` | string(255) | nullable | |
| `attachment_mime_type` | string(150) | nullable | |
| `attachment_size_bytes` | unsignedBigInteger | nullable | |
| `total_marks` | decimal(8,2) | not null | §80; the ceiling of every mark on this assignment |
| `passing_marks` | decimal(8,2) | nullable | null = no pass line, only marks |
| `submission_type` | string(16) | `file_or_text` | cast `SubmissionType` - §80 "uploads a submission or text" |
| `allowed_extensions` | json | nullable | narrows, never widens, `security.allowed_file_types` |
| `max_file_size_mb` | smallint unsigned | nullable | bounded by `security.max_upload_mb` |
| `max_files` | tinyint unsigned | 3 | default from `institute.assignment_max_files_default` |
| `assigned_on` | date | not null | |
| `deadline_at` | datetime | not null | §80 deadline |
| `late_submission_allowed` | boolean | true | §80 "late submission handling" |
| `late_cutoff_at` | datetime | nullable | hard stop; null = no stop while `late_submission_allowed` |
| `late_penalty_percentage` | decimal(8,4) | 0.0000 | % of `total_marks` deducted once, if any (F-7.1) |
| `allow_resubmission` | boolean | true | |
| `max_attempts` | tinyint unsigned | 3 | |
| `marks_visible_to_students` | boolean | true | a teacher may mark privately, then release |
| `status` | string(16) | `draft` | cast `AssignmentStatus` |
| `published_at` | timestamp | nullable | |
| `closed_at` | timestamp | nullable | |
| `expected_count` | smallint unsigned | 0 | CACHE - active enrollments on `deadline_at`'s date |
| `submitted_count` | smallint unsigned | 0 | CACHE of live submissions with `countsAsSubmitted()` |
| `late_count` | smallint unsigned | 0 | CACHE |
| `graded_count` | smallint unsigned | 0 | CACHE |
| `missed_count` | smallint unsigned | 0 | CACHE |
| `average_marks` | decimal(8,2) | nullable | CACHE over graded live submissions (bcmath) |
| `highest_marks` | decimal(8,2) | nullable | CACHE |
| `notes` | string(500) | nullable | |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `INDEX (batch_id, status)`; `INDEX (course_id, status)`; `INDEX (status, deadline_at)`;
`INDEX (teacher_id, status)`; `INDEX (deadline_at)`; `INDEX (course_topic_assignment_id)`;
`INDEX (branch_id, status)`.
**CHECK** `chk_as_total`: `total_marks > 0`.
**CHECK** `chk_as_pass`: `passing_marks IS NULL OR (passing_marks >= 0 AND passing_marks <= total_marks)`.
**CHECK** `chk_as_cutoff`: `late_cutoff_at IS NULL OR late_cutoff_at >= deadline_at`.
**CHECK** `chk_as_penalty`: `late_penalty_percentage BETWEEN 0 AND 100`.
**CHECK** `chk_as_attempts`: `max_attempts BETWEEN 1 AND 10 AND max_files BETWEEN 1 AND 10`.
**CHECK** `chk_as_counts`: every count column `>= 0`.
**Relationships.** belongsTo `Course`, `Batch`, `CourseTopicAssignment`, `CourseTopic`, `Teacher`,
`Branch`; hasMany `AssignmentSubmission`; morphMany `Attachment` (Phase 6, for extra brief files).
**Rules.** `restrictOnDelete` from `assignment_submissions`: an assignment with a submission is **closed or
archived**, never deleted. `total_marks`, `deadline_at` and `submission_type` are frozen once a graded
submission exists; a later correction is an `assignments.edit` with a mandatory reason that also triggers
`RecomputeAssignmentCaches` and is logged with old and new values (INV-19-7 keeps existing lateness intact).

### 2.7 `assignment_submissions` (§80)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `assignment_id` | FK `assignments.id` | not null | `restrictOnDelete` |
| `student_id` | FK `students.id` | not null | `restrictOnDelete` |
| `student_batch_enrollment_id` | FK `student_batch_enrollments.id` | not null | `restrictOnDelete`; proves the roster (INV-19-5) |
| `batch_id` | FK `batches.id` | not null | `restrictOnDelete`; denormalised so the batch report is one index scan |
| `attempt_no` | tinyint unsigned | 1 | |
| `submission_text` | longText | nullable | §80 "or text"; sanitised, rendered as text not HTML |
| `files_count` | tinyint unsigned | 0 | CACHE of `assignment_submission_files` |
| `status` | string(16) | `draft` | cast `SubmissionStatus` |
| `submitted_at` | timestamp | nullable | null while `draft` |
| `is_late` | boolean | false | decided once, at insert (INV-19-7) |
| `minutes_late` | int unsigned | nullable | |
| `obtained_marks` | decimal(8,2) | nullable | null until graded. **One name across the system** (the same column name as `exam_results.obtained_marks`, F-5.10) |
| `penalty_marks` | decimal(8,2) | 0.00 | the late deduction actually applied |
| `final_marks` | decimal(8,2) | **generated STORED** | `CASE WHEN obtained_marks IS NULL THEN NULL ELSE GREATEST(obtained_marks - COALESCE(penalty_marks,0), 0) END` |
| `total_marks` | decimal(8,2) | not null | **snapshot** of the assignment's total at grading/submission time - the same device as INV-20-1, which makes the ceiling a DB CHECK |
| `percentage` | decimal(8,4) | nullable | written only by `AssignmentGradeCalculator` (F-7.1) |
| `is_passed` | boolean | nullable | null when the assignment has no `passing_marks` |
| `feedback` | text | nullable | §80 "adds feedback" |
| `feedback_file_path` | string(255) | nullable | `private` disk |
| `feedback_file_original_name` | string(255) | nullable | |
| `graded_by` | FK `users.id` | nullable | `nullOnDelete` |
| `graded_at` | timestamp | nullable | |
| `returned_at` | timestamp | nullable | returned for rework |
| `marks_released_at` | timestamp | nullable | when the student may see the marks |
| `superseded_by_id` | FK self | nullable | `nullOnDelete` - the resubmission that replaced this row |
| `amended_at` / `amended_by` / `amendment_reason` | timestamp / FK / string(255) | nullable | a post-release mark change (INV-20-5's discipline applied to assignments) |
| `current_guard` | tinyint | **generated STORED** | `CASE WHEN status <> 'superseded' THEN 1 ELSE NULL END` |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_as_live(assignment_id, student_id, current_guard)` - one live submission per student;
`UNIQUE uq_as_attempt(assignment_id, student_id, attempt_no)`;
`UNIQUE uq_as_superseded(superseded_by_id)` - one predecessor per successor;
`INDEX (assignment_id, status)`; `INDEX (student_id, status)`; `INDEX (batch_id, status)`;
`INDEX (graded_by)`; `INDEX (submitted_at)`; `INDEX (status, is_late)`.
**CHECK** `chk_asub_marks`: `obtained_marks IS NULL OR (obtained_marks >= 0 AND obtained_marks <= total_marks)` - **the §80 ceiling as a database fact**.
**CHECK** `chk_asub_penalty`: `penalty_marks >= 0 AND penalty_marks <= total_marks`.
**CHECK** `chk_asub_total`: `total_marks > 0`.
**CHECK** `chk_asub_graded`: `status <> 'graded' OR obtained_marks IS NOT NULL`.
**CHECK** `chk_asub_submitted`: `status = 'draft' OR submitted_at IS NOT NULL`.
**CHECK** `chk_asub_pct`: `percentage IS NULL OR percentage BETWEEN 0 AND 100`.
**CHECK** `chk_asub_attempt`: `attempt_no BETWEEN 1 AND 10`.
**Relationships.** belongsTo `Assignment`, `Student`, `StudentBatchEnrollment`, `Batch`, `User`
(`grader`, `amender`), self (`supersededBy`); hasMany `AssignmentSubmissionFile`.
**Rules.** No route ever deletes a submission; the policy returns false for `delete` and `forceDelete` for
every role. A student may `withdraw` only while `status = draft`.

### 2.8 `assignment_submission_files`

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `assignment_submission_id` | FK `assignment_submissions.id` | not null | `cascadeOnDelete` |
| `storage_disk` | string(32) | `private` | |
| `file_path` | string(255) | not null | ULID name, §6.3 layout |
| `original_name` | string(255) | not null | |
| `extension` | string(16) | not null | server-decided |
| `mime_type` | string(150) | not null | sniffed from content |
| `file_size_bytes` | unsignedBigInteger | not null | |
| `checksum_sha256` | char(64) | nullable | flags a student submitting a classmate's identical file (a **warning** on the grading screen, never an accusation or a block) |
| `uploaded_by` | FK `users.id` | nullable | `nullOnDelete` |
| `download_count` | int unsigned | 0 | |
| timestamps | | | no soft deletes, no blameable |

**Keys.** `INDEX (assignment_submission_id)`; `INDEX (checksum_sha256)`.
**CHECK** `chk_asf_size`: `file_size_bytes > 0`.
**Relationships.** belongsTo `AssignmentSubmission`, `User` (`uploader`).

---

### 2.9 `grade_scales` (§82 "grade" - configurable)

**[D-20-1] The grade scale is data, not code.** §82 asks for a grade; a hardcoded A/B/C ladder would be the
exact "hardcoded status" `CLAUDE.md` §1.8 forbids. A scale is a named set of contiguous percentage bands
that an exam (and a certificate) snapshots.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `code` | string(32) | not null | `DEFAULT`, `PRACTICAL-5`, … |
| `name` | string(150) | not null | |
| `description` | string(500) | nullable | |
| `pass_percentage` | decimal(8,4) | 40.0000 | the scale's pass line, used when an exam carries no `passing_marks` (F-7.1) |
| `is_default` | boolean | false | exactly one, enforced below |
| `default_guard` | tinyint | **generated STORED** | `CASE WHEN is_default = 1 THEN 1 ELSE NULL END` |
| `is_active` | boolean | true | |
| `sort_order` | int | 0 | |
| `bands_count` | tinyint unsigned | 0 | CACHE |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_gs_code(code)`; `UNIQUE uq_gs_default(default_guard)` - one default scale for the
whole database; `INDEX (is_active, sort_order)`.
**CHECK** `chk_gs_pass`: `pass_percentage BETWEEN 0 AND 100`.
**Relationships.** hasMany `GradeScaleBand`, `Exam`, `ExamResult`, `Certificate`.
**Rules.** `restrictOnDelete` from `exams`, `exam_results` and `certificates`; a referenced scale is
deactivated (INV-20-4).

### 2.10 `grade_scale_bands`

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `grade_scale_id` | FK `grade_scales.id` | not null | `cascadeOnDelete` |
| `grade` | string(8) | not null | `A+`, `A`, `B`, `F` |
| `title` | string(60) | nullable | "Excellent", "Fail" |
| `min_percentage` | decimal(8,4) | not null | inclusive (F-7.1) |
| `max_percentage` | decimal(8,4) | not null | inclusive (F-7.1) |
| `grade_point` | decimal(4,2) | nullable | GPA value |
| `is_pass` | boolean | true | |
| `color` | string(16) | nullable | a Tailwind token, so the badge colour is data too |
| `remark_template` | string(255) | nullable | default remark offered on the result sheet |
| `sort_order` | smallint unsigned | 0 | |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_gsb_grade(grade_scale_id, grade)`;
`INDEX (grade_scale_id, min_percentage, max_percentage)`.
**CHECK** `chk_gsb_range`: `min_percentage >= 0 AND max_percentage <= 100 AND max_percentage >= min_percentage`.
**Relationships.** belongsTo `GradeScale`; hasMany `ExamResult`.
**Rules.** Contiguity, non-overlap and full 0-100 coverage cannot be a CHECK (they are set-level) and are
validated by `GradeScaleService::validateBands()` in the saving transaction and re-asserted nightly by
`grades:verify-scales` (INV-20-3). `restrictOnDelete` from `exam_results`.

### 2.11 `exams` (§81)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete`; copied from the batch |
| `course_id` | FK `courses.id` | not null | `restrictOnDelete`; §81 |
| `batch_id` | FK `batches.id` | not null | `restrictOnDelete`; §81 |
| `exam_type` | string(24) | not null | cast `ExamType` - §81's **six** types, no seventh |
| `name` | string(180) | not null | §81 |
| `course_topic_id` | FK `course_topics.id` | nullable | `nullOnDelete`; what it assesses, and the hook for §83 progress (§6.12) |
| `teacher_id` | FK `teachers.id` | nullable | `restrictOnDelete`; examiner / marker |
| `classroom_id` | FK `classrooms.id` | nullable | `nullOnDelete` |
| `delivery_mode` | string(16) | `physical` | cast `DeliveryMode` (Phase 14's enum) |
| `meeting_url` | string(500) | nullable | required when `delivery_mode = online` |
| `scheduled_date` | date | not null | §81 date |
| `start_time` | time | nullable | |
| `end_time` | time | nullable | |
| `duration_minutes` | smallint unsigned | nullable | §81 duration |
| `total_marks` | decimal(8,2) | not null | §81 |
| `passing_marks` | decimal(8,2) | not null | §81 |
| `weight_percentage` | decimal(8,4) | nullable | (F-7.1) share of the course's aggregate grade; null = equal weight. Read by `CertificateService` when `institute.certificate_grade_source = weighted_average` |
| `grade_scale_id` | FK `grade_scales.id` | nullable | `restrictOnDelete`; null = `institute.default_grade_scale_id` |
| `instructions` | text | nullable | §81 |
| `status` | string(16) | `draft` | cast `ExamStatus` |
| `results_published_at` | timestamp | nullable | |
| `results_published_by` | FK `users.id` | nullable | `nullOnDelete` |
| `results_verified_at` / `results_verified_by` | timestamp / FK | nullable | when `institute.result_publish_requires_verification` |
| `expected_count` | smallint unsigned | 0 | CACHE - active enrollments on `scheduled_date` |
| `results_entered_count` | smallint unsigned | 0 | CACHE |
| `appeared_count` / `absent_count` / `passed_count` / `failed_count` | smallint unsigned | 0 | CACHE |
| `highest_marks` / `lowest_marks` / `average_marks` | decimal(8,2) | nullable | CACHE (bcmath) |
| `average_percentage` | decimal(8,4) | nullable | CACHE (F-7.1) |
| `cancellation_reason` | string(255) | nullable | mandatory on `cancelled` |
| `notes` | string(500) | nullable | |
| `active_guard` | tinyint | **generated STORED** | `CASE WHEN status <> 'cancelled' THEN 1 ELSE NULL END` |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_ex_batch_slot(batch_id, scheduled_date, start_time, active_guard)` - a batch cannot sit
two exams in one slot; `INDEX (batch_id, scheduled_date)`; `INDEX (course_id, exam_type)`;
`INDEX (status, scheduled_date)` the exam calendar; `INDEX (teacher_id, scheduled_date)`;
`INDEX (classroom_id, scheduled_date)`; `INDEX (branch_id, scheduled_date)`; `INDEX (course_topic_id)`;
`INDEX (results_published_at)`.
**CHECK** `chk_ex_total`: `total_marks > 0`.
**CHECK** `chk_ex_pass`: `passing_marks >= 0 AND passing_marks <= total_marks`.
**CHECK** `chk_ex_times`: `start_time IS NULL OR end_time IS NULL OR end_time > start_time`.
**CHECK** `chk_ex_duration`: `duration_minutes IS NULL OR duration_minutes > 0`.
**CHECK** `chk_ex_weight`: `weight_percentage IS NULL OR weight_percentage BETWEEN 0 AND 100`.
**CHECK** `chk_ex_counts`: every count `>= 0`.
**Relationships.** belongsTo `Course`, `Batch`, `CourseTopic`, `Teacher`, `Classroom`, `Branch`,
`GradeScale`, `User` (`publisher`, `verifier`); hasMany `ExamResult`.
**Rules.** `restrictOnDelete` from `exam_results`: an exam with a result is **cancelled**, never deleted.
Once `results_published_at` is set, `total_marks`, `passing_marks`, `grade_scale_id` and `scheduled_date`
are frozen (model `updating` hook throws `ExamPublishedException`); a real correction is
`ExamResultService::unpublish()` with a reason, then an amendment. Teacher, classroom and batch
double-booking is refused by **`ScheduleClashDetector::check(SlotCandidate): ClashReport`** (phase-14-17 §6.7,
D47) against `class_sessions`, `demo_classes` and other exams - one generic call, not three subject-specific
ones.

### 2.12 `exam_results` (§82)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `exam_id` | FK `exams.id` | not null | `restrictOnDelete` |
| `student_id` | FK `students.id` | not null | `restrictOnDelete` |
| `student_batch_enrollment_id` | FK `student_batch_enrollments.id` | not null | `restrictOnDelete` - proves the roster |
| `batch_id` | FK `batches.id` | not null | `restrictOnDelete`; denormalised |
| `course_id` | FK `courses.id` | not null | `restrictOnDelete`; denormalised |
| `attendance_status` | string(16) | `appeared` | cast `ExamAttendanceStatus` - the difference between "absent" and "scored zero" |
| `obtained_marks` | decimal(8,2) | nullable | §82; null for `absent` / `exempt` / `debarred` |
| `total_marks` | decimal(8,2) | not null | **snapshot** (INV-20-1) |
| `percentage` | decimal(8,4) | nullable | §82 (F-7.1) |
| `grade_scale_id` | FK `grade_scales.id` | nullable | `restrictOnDelete`; snapshot of the scale used |
| `grade_scale_band_id` | FK `grade_scale_bands.id` | nullable | `restrictOnDelete` |
| `grade` | string(8) | nullable | §82; **snapshot label**, so a later band rename never rewrites a printed card |
| `grade_point` | decimal(4,2) | nullable | snapshot |
| `is_passed` | boolean | nullable | §82 pass/fail |
| `position_in_batch` | smallint unsigned | nullable | rank, computed on publish; ties share a rank and the next rank skips |
| `remarks` | string(500) | nullable | §82 |
| `entered_by` / `entered_at` | FK `users.id` / timestamp | nullable | `nullOnDelete` |
| `verified_by` / `verified_at` | FK `users.id` / timestamp | nullable | `nullOnDelete` |
| `published_at` | timestamp | nullable | |
| `amended_at` / `amended_by` / `amendment_reason` | timestamp / FK / string(255) | nullable | INV-20-5 |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_er_exam_student(exam_id, student_id)` - the hard guard against a double-entered sheet;
`INDEX (student_id, published_at)` the student panel; `INDEX (batch_id, exam_id)`;
`INDEX (exam_id, percentage)` ranking; `INDEX (exam_id, attendance_status)`;
`INDEX (course_id, is_passed)`; `INDEX (student_batch_enrollment_id)`.
**CHECK** `chk_er_marks`: `obtained_marks IS NULL OR (obtained_marks >= 0 AND obtained_marks <= total_marks)`
- **the §20 "marks never exceed the total" guarantee, in the database.**
**CHECK** `chk_er_total`: `total_marks > 0`.
**CHECK** `chk_er_appeared`: `attendance_status <> 'appeared' OR obtained_marks IS NOT NULL`.
**CHECK** `chk_er_absent`: `attendance_status = 'appeared' OR obtained_marks IS NULL`.
**CHECK** `chk_er_pct`: `percentage IS NULL OR percentage BETWEEN 0 AND 100`.
**Relationships.** belongsTo `Exam`, `Student`, `StudentBatchEnrollment`, `Batch`, `Course`, `GradeScale`,
`GradeScaleBand`, `User` (`enteredBy`, `verifiedBy`, `amender`).
**Rules.** The policy refuses `delete` and `forceDelete` for every role, including Super Admin; a wrong row
is amended with a reason. `restrictOnDelete` keeps the scale and band alive behind it.

---

### 2.13 `print_templates` (§82 result card, §84 certificate, §85 ID card)

**[D-21-1] One template table for the three printable documents.** §84 and §85 each demand an
admin-controlled template and §82 a printable result card. Three tables would be three editors, three
token lists and three sanitisers. One table with a `type` gives one editor, one sanitiser and one token
registry.

**[D-21-2] A template is HTML with `{{tokens}}`, never Blade.** Storing Blade and rendering it would hand
remote code execution to anyone holding `print_templates.edit`. `body_html` is sanitised on save and
rendered by a replacer over a fixed token list (INV-21-5).

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `type` | string(24) | not null | cast `PrintTemplateType` - `certificate` / `student_id_card` / `result_card` |
| `code` | string(32) | not null | |
| `name` | string(150) | not null | |
| `description` | string(500) | nullable | |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete`; null = every branch |
| `paper_size` | string(16) | `a4` | cast `PaperSize` (`cr80` is the ID-card size) |
| `orientation` | string(16) | `portrait` | cast `PageOrientation` |
| `width_mm` / `height_mm` | decimal(6,2) | nullable | required when `paper_size = custom` |
| `margin_mm` | decimal(5,2) | 10.00 | |
| `background_image_path` | string(255) | nullable | `public` disk - a design asset with no PII |
| `logo_path` | string(255) | nullable | falls back to `branding.logo_light` |
| `body_html` | longText | not null | sanitised; `{{tokens}}` from `PrintTokenRegistry` |
| `custom_css` | longText | nullable | sanitised (no `@import`, no `url(javascript:)`, no external `url()`) |
| `tokens_used` | json | nullable | extracted on save; an unknown token is a validation warning naming it |
| `signatories` | json | nullable | ordered `[{name, title, image_path}]` |
| `show_qr` | boolean | true | §84, §85 |
| `qr_size_mm` | decimal(5,2) | 25.00 | |
| `is_default` | boolean | false | one default per (`type`, `branch_id`) |
| `default_guard` | tinyint | **generated STORED** | `CASE WHEN is_default = 1 THEN 1 ELSE NULL END` |
| `is_active` | boolean | true | |
| `sort_order` | int | 0 | |
| `preview_path` | string(255) | nullable | cached PNG/PDF preview, `private` disk |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_pt_code(code)`; `UNIQUE uq_pt_default(type, branch_id, default_guard)`;
`INDEX (type, is_active, sort_order)`; `INDEX (branch_id, type)`.
**CHECK** `chk_pt_custom`: `paper_size <> 'custom' OR (width_mm IS NOT NULL AND height_mm IS NOT NULL)`.
**CHECK** `chk_pt_dims`: `width_mm IS NULL OR width_mm > 0` (same for `height_mm`, `margin_mm >= 0`).
**Relationships.** belongsTo `Branch`; hasMany `Certificate`, `StudentIdCard`.
**Rules.** `restrictOnDelete` from `certificates` and `student_id_cards`: a template that printed a document
is deactivated, never deleted, so the document can be re-printed byte-identically. Editing a template that
has issued documents requires `print_templates.edit` plus a reason and is logged with a diff; issued
documents keep their own snapshots and are unaffected.

### 2.14 `certificates` (§84)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `certificate_number` | string(40) | not null | §84; `institute.certificate_prefix` + counter, via `DocumentNumberService`, issued once (INV-21-1) |
| `verification_code` | char(16) | not null | §84 QR target; 16 chars of a 32-symbol alphabet, random (INV-21-2) |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete` |
| `student_id` | FK `students.id` | not null | `restrictOnDelete`; §84 |
| `course_id` | FK `courses.id` | not null | `restrictOnDelete`; §84 |
| `batch_id` | FK `batches.id` | nullable | `restrictOnDelete`; §84 |
| `student_batch_enrollment_id` | FK `student_batch_enrollments.id` | nullable | `restrictOnDelete`; **the grain** of INV-21-6 |
| `teacher_id` | FK `teachers.id` | nullable | `nullOnDelete`; §84 trainer |
| `print_template_id` | FK `print_templates.id` | nullable | `restrictOnDelete` |
| `grade_scale_id` | FK `grade_scales.id` | nullable | `restrictOnDelete` |
| `student_name_snapshot` | string(150) | not null | INV-21-4 |
| `father_name_snapshot` | string(150) | nullable | |
| `student_code_snapshot` | string(32) | not null | |
| `registration_number_snapshot` | string(40) | nullable | |
| `course_name_snapshot` | string(180) | not null | |
| `batch_name_snapshot` | string(150) | nullable | |
| `teacher_name_snapshot` | string(150) | nullable | |
| `branch_name_snapshot` | string(150) | nullable | |
| `course_start_date` | date | nullable | §84 start date |
| `completion_date` | date | not null | §84 completion date |
| `grade` | string(8) | nullable | §84 |
| `grade_point` | decimal(4,2) | nullable | |
| `percentage` | decimal(8,4) | nullable | (F-7.1) |
| `attendance_percentage` | decimal(8,4) | nullable | eligibility evidence, snapshotted (F-7.1) |
| `progress_percentage` | decimal(8,4) | nullable | eligibility evidence, snapshotted (F-7.1) |
| `eligibility_snapshot` | json | nullable | the full `EligibilityReport` at issue time - every rule and its verdict |
| `issued_on` | date | nullable | null while `draft` |
| `issued_by` | FK `users.id` | nullable | `nullOnDelete` |
| `status` | string(16) | `draft` | cast `CertificateStatus` |
| `revoked_at` / `revoked_by` | timestamp / FK | nullable | `nullOnDelete` |
| `revocation_reason` | string(500) | nullable | mandatory on `revoked`, and shown on the public page |
| `reissue_of_id` | FK self | nullable | `nullOnDelete` |
| `reissue_reason` | string(255) | nullable | mandatory when `reissue_of_id` is set |
| `qr_payload` | string(500) | nullable | the absolute verification URL, **snapshotted** (INV-21-4) |
| `pdf_path` | string(255) | nullable | `private` disk |
| `pdf_generated_at` | timestamp | nullable | |
| `print_count` | int unsigned | 0 | §84 print control |
| `last_printed_at` / `last_printed_by` | timestamp / FK | nullable | |
| `verification_count` | int unsigned | 0 | CACHE of `certificate_verifications` |
| `last_verified_at` | timestamp | nullable | |
| `is_publicly_verifiable` | boolean | true | a privacy opt-out; false returns `not_found` to the public page |
| `notes` | string(500) | nullable | |
| `live_guard` | tinyint | **generated STORED** | `CASE WHEN status = 'issued' THEN 1 ELSE NULL END` |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_ce_number(certificate_number)`; `UNIQUE uq_ce_code(verification_code)`;
`UNIQUE uq_ce_live(student_batch_enrollment_id, live_guard)`;
`UNIQUE uq_ce_reissue(reissue_of_id)` - one successor per revoked certificate;
`INDEX (student_id, status)`; `INDEX (course_id, status)`; `INDEX (batch_id, status)`;
`INDEX (status, issued_on)`; `INDEX (branch_id, issued_on)`; `INDEX (completion_date)`.
**CHECK** `chk_ce_dates`: `course_start_date IS NULL OR completion_date >= course_start_date`.
**CHECK** `chk_ce_pct`: each percentage column `IS NULL OR BETWEEN 0 AND 100`.
**CHECK** `chk_ce_revoked`: `status <> 'revoked' OR revocation_reason IS NOT NULL`.
**CHECK** `chk_ce_issued`: `status = 'draft' OR (issued_on IS NOT NULL AND certificate_number IS NOT NULL)`.
**CHECK** `chk_ce_counts`: `print_count >= 0 AND verification_count >= 0`.
**Relationships.** belongsTo `Student`, `Course`, `Batch`, `StudentBatchEnrollment`, `Teacher`, `Branch`,
`PrintTemplate`, `GradeScale`, `User` (`issuer`, `revoker`, `lastPrinter`), self (`reissueOf`, `reissuedAs`);
hasMany `CertificateVerification`.
**Rules.** Policy refuses `delete` and `forceDelete` for every role (INV-21-1). A `draft` may be edited
freely; an `issued` row is immutable except for `print_count` / `last_printed_*` /
`verification_count` / `last_verified_at` / `pdf_path` / `status` transitions and
`is_publicly_verifiable`. The model `updating` hook throws `CertificateIssuedException` on anything else.

### 2.15 `certificate_verifications` (§84 public verification)

Append-only, `created_at` only. It is the abuse-detection surface, the rate-limit evidence and the §106
log of a public endpoint.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `certificate_id` | FK `certificates.id` | nullable | `nullOnDelete`; null when the code matched nothing |
| `submitted_code` | string(40) | not null | stored verbatim (truncated), so enumeration patterns are visible |
| `result` | string(16) | not null | cast `VerificationResult` |
| `ip_address` | string(45) | nullable | |
| `user_agent` | text | nullable | |
| `device` | string(64) | nullable | `App\Support\Device` |
| `referer` | string(255) | nullable | |
| `created_at` | timestamp | not null | no `updated_at`, no soft deletes, no blameable |

**Keys.** `INDEX (certificate_id, created_at)`; `INDEX (ip_address, created_at)` the rate limiter's
evidence; `INDEX (result, created_at)`; `INDEX (submitted_code)`.
**Retention.** `certificates:prune-verification-log` keeps
`institute.certificate_verification_log_retention_days` (default 365; `0` = for ever).

### 2.16 `student_id_cards` (§85)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `card_number` | string(32) | not null | `institute.id_card_prefix` + counter |
| `verification_code` | char(16) | not null | the QR target; resolves to the same public endpoint, card variant |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete` |
| `student_id` | FK `students.id` | not null | `restrictOnDelete` |
| `student_batch_enrollment_id` | FK `student_batch_enrollments.id` | nullable | `nullOnDelete` |
| `course_id` | FK `courses.id` | nullable | `nullOnDelete`; §85 course |
| `batch_id` | FK `batches.id` | nullable | `nullOnDelete`; §85 batch |
| `print_template_id` | FK `print_templates.id` | nullable | `restrictOnDelete` |
| `student_name_snapshot` | string(150) | not null | §85 |
| `father_name_snapshot` | string(150) | nullable | |
| `student_code_snapshot` | string(32) | not null | §85 "student ID" |
| `registration_number_snapshot` | string(40) | nullable | |
| `course_name_snapshot` | string(180) | nullable | |
| `batch_name_snapshot` | string(150) | nullable | |
| `joining_date_snapshot` | date | nullable | §85 joining date |
| `guardian_phone_snapshot` | string(32) | nullable | printed only when the template's token set asks for it |
| `photo_path` | string(255) | nullable | **a copy** of the student photo at issue time, `private` disk - a later photo change never alters an issued card |
| `issued_on` | date | not null | |
| `valid_until` | date | nullable | `issued_on + institute.id_card_validity_months` |
| `status` | string(16) | `active` | cast `IdCardStatus` |
| `replacement_of_id` | FK self | nullable | `nullOnDelete` |
| `replacement_reason` | string(255) | nullable | mandatory when `replacement_of_id` is set |
| `revoked_at` / `revoked_by` / `revocation_reason` | timestamp / FK / string(255) | nullable | |
| `qr_payload` | string(500) | not null | snapshotted absolute URL |
| `pdf_path` | string(255) | nullable | `private` disk |
| `print_count` | int unsigned | 0 | |
| `last_printed_at` / `last_printed_by` | timestamp / FK | nullable | |
| `notes` | string(500) | nullable | |
| `live_guard` | tinyint | **generated STORED** | `CASE WHEN status = 'active' THEN 1 ELSE NULL END` |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_sic_number(card_number)`; `UNIQUE uq_sic_code(verification_code)`;
`UNIQUE uq_sic_live(student_id, live_guard)` - one active card per student;
`UNIQUE uq_sic_replacement(replacement_of_id)`; `INDEX (student_id, status)`;
`INDEX (batch_id, status)`; `INDEX (status, valid_until)` the expiry sweep; `INDEX (branch_id, issued_on)`.
**CHECK** `chk_sic_valid`: `valid_until IS NULL OR valid_until >= issued_on`.
**CHECK** `chk_sic_replacement`: `replacement_of_id IS NULL OR replacement_reason IS NOT NULL`.
**CHECK** `chk_sic_print`: `print_count >= 0`.
**Relationships.** belongsTo `Student`, `StudentBatchEnrollment`, `Course`, `Batch`, `Branch`,
`PrintTemplate`, `User` (`lastPrinter`, `revoker`), self (`replacementOf`, `replacedBy`).
**Rules.** Policy refuses `delete` / `forceDelete`; a lost card is `lost` and its replacement is a new row.
Re-printing increments `print_count` and writes an activity row.

---

### 2.17 `ticket_departments` (§93 "department")

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `name` | string(150) | not null | |
| `slug` | string(170) | not null | stable key used by settings and reports |
| `description` | string(500) | nullable | |
| `email` | string(180) | nullable | the inbox a future mail-in integration would read |
| `allowed_panels` | json | not null | array of `PanelType` values that may open a ticket here - this is what stops a student filing into a client-billing queue |
| `default_assignee_id` | FK `users.id` | nullable | `nullOnDelete` |
| `auto_assign_strategy` | string(16) | `none` | cast `TicketAssignStrategy` |
| `sla_first_response_minutes` | int unsigned | nullable | null = fall back to `support.sla_first_response_minutes` |
| `sla_resolution_minutes` | int unsigned | nullable | null = fall back to `support.sla_resolution_minutes` |
| `is_default` | boolean | false | |
| `default_guard` | tinyint | **generated STORED** | `CASE WHEN is_default = 1 THEN 1 ELSE NULL END` |
| `is_active` | boolean | true | |
| `sort_order` | int | 0 | |
| `open_tickets_count` | int unsigned | 0 | CACHE, recounted by `TicketService` |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_td_slug(slug)`; `UNIQUE uq_td_default(default_guard)`; `INDEX (is_active, sort_order)`.
**CHECK** `chk_td_sla`: `sla_first_response_minutes IS NULL OR sla_first_response_minutes > 0` (same for resolution).
**Relationships.** belongsTo `User` (`defaultAssignee`); hasMany `SupportTicket`.
**Rules.** `restrictOnDelete` from `support_tickets`; a used department is deactivated. Deactivating hides it
from every create form but never moves an existing ticket.

### 2.18 `support_tickets` (§93)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `ticket_number` | string(32) | not null | §93; `support.ticket_prefix` + counter, once (INV-22-1) |
| `ticket_department_id` | FK `ticket_departments.id` | not null | `restrictOnDelete`; §93 |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete` |
| `user_id` | FK `users.id` | not null | `restrictOnDelete`; §93 "user" - the requester |
| `requester_panel` | string(16) | not null | cast `PanelType`; which portal raised it, and the first input to §9 |
| `client_id` | FK `clients.id` | nullable | `nullOnDelete`; **Phase 5 §13 asked for this column** |
| `student_id` | FK `students.id` | nullable | `nullOnDelete` |
| `teacher_id` | FK `teachers.id` | nullable | `nullOnDelete` |
| `collaborator_id` | FK `collaborators.id` | nullable | `nullOnDelete` |
| `employee_id` | FK `employees.id` | nullable | `nullOnDelete` |
| `project_id` | FK `projects.id` | nullable | `nullOnDelete`; a ticket about a project |
| `course_id` | FK `courses.id` | nullable | `nullOnDelete` |
| `batch_id` | FK `batches.id` | nullable | `nullOnDelete` |
| `subject` | string(255) | not null | §93 |
| `description` | longText | not null | §93 |
| `priority` | string(16) | `medium` | cast **`App\Enums\Priority`** (phase-06 §3 - the shared four-case enum; there is no `TicketPriority`, F-5.7); §93 |
| `status` | string(16) | `open` | cast `TicketStatus`; §93's **five**, no sixth |
| `assigned_to` | FK `users.id` | nullable | `nullOnDelete`; §93 assigned agent |
| `assigned_at` / `assigned_by` | timestamp / FK | nullable | `nullOnDelete` |
| `first_response_due_at` | datetime | nullable | SLA |
| `first_response_at` | timestamp | nullable | first **public staff** reply only (INV-22-2) |
| `first_response_by` | FK `users.id` | nullable | `nullOnDelete` |
| `first_response_breached` | boolean | false | stamped by the sweep; also derivable |
| `resolution_due_at` | datetime | nullable | |
| `resolved_at` / `resolved_by` | timestamp / FK | nullable | `nullOnDelete` |
| `resolution_breached` | boolean | false | |
| `closed_at` / `closed_by` | timestamp / FK | nullable | `nullOnDelete` |
| `closure_reason` | string(255) | nullable | |
| `waiting_since` | timestamp | nullable | set on entering `waiting`, cleared on leaving |
| `total_waiting_minutes` | int unsigned | 0 | accumulated pause, so the SLA clock is honest |
| `reopened_count` | smallint unsigned | 0 | |
| `last_reopened_at` | timestamp | nullable | |
| `last_reply_at` | timestamp | nullable | |
| `last_reply_by` | FK `users.id` | nullable | `nullOnDelete` |
| `last_reply_panel` | string(16) | nullable | cast `PanelType` - drives the "awaiting us / awaiting them" chip |
| `replies_count` / `staff_replies_count` / `requester_replies_count` / `attachments_count` | smallint unsigned | 0 | CACHES, recounted under a row lock |
| `is_private_to_creator` | boolean | false | the escape hatch Phase 5 §12 Q3 anticipated |
| `tags` | json | nullable | free labels for §99 ticket-report grouping |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_tk_number(ticket_number)`; `INDEX (status, priority, created_at)` the queue;
`INDEX (assigned_to, status)`; `INDEX (user_id, status)`; `INDEX (client_id, status)`;
`INDEX (student_id, status)`; `INDEX (collaborator_id, status)`; `INDEX (teacher_id, status)`;
`INDEX (ticket_department_id, status)`; `INDEX (first_response_due_at)`; `INDEX (resolution_due_at)`;
`INDEX (branch_id, status)`; `INDEX (project_id)`; `INDEX (last_reply_at)`.
**CHECK** `chk_tk_counts`: every count column `>= 0`.
**CHECK** `chk_tk_resolved`: `status NOT IN ('resolved','closed') OR resolved_at IS NOT NULL`.
**CHECK** `chk_tk_waiting`: `status = 'waiting' OR waiting_since IS NULL`.
**SLA columns are inert when the feature is off.** `first_response_due_at`, `first_response_at`,
`first_response_by`, `resolution_due_at`, `resolved_at`, `waiting_since` are **all nullable** and
`first_response_breached` / `resolution_breached` / `total_waiting_minutes` default to false / false / 0, so
with `support.sla_enabled = false` (§5.2) every SLA column simply stays null or zero, no migration is
involved, and turning SLA on or off later is a settings flip (audit F-13.5, needs-human H3).
**Relationships.** belongsTo `TicketDepartment`, `Branch`, `User` (`requester`, `assignee`, `assigner`,
`firstResponder`, `resolver`, `closer`, `lastReplier`), `Client`, `Student`, `Teacher`, `Collaborator`,
`Employee`, `Project`, `Course`, `Batch`; hasMany `TicketReply`; morphMany `Attachment` (§2.27).
**Rules.** The subject FKs are **derived by the service from the requester's own profiles**, never accepted
from the form (a client can never file a ticket "as" another client). Policy refuses `delete` and
`forceDelete` for every role, so no `restrictOnDelete` is needed anywhere.

### 2.19 `ticket_replies` (§93 replies with attachments)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `support_ticket_id` | FK `support_tickets.id` | not null | `cascadeOnDelete` (a ticket is never deleted, so this never fires) |
| `user_id` | FK `users.id` | nullable | `nullOnDelete`; null = a system entry |
| `panel` | string(16) | nullable | cast `PanelType` |
| `body` | longText | nullable | null only for a system entry |
| `visibility` | string(16) | `public` | cast `ReplyVisibility` - `internal_note` never leaves staff (INV-22-3) |
| `is_first_response` | boolean | false | set by the service on the first public staff reply |
| `is_system` | boolean | false | status change / assignment / SLA breach, rendered in the timeline |
| `system_event` | string(40) | nullable | the event key behind a system entry |
| `status_from` / `status_to` | string(16) | nullable | cast `TicketStatus`, for a system status entry |
| `attachments_count` | tinyint unsigned | 0 | CACHE |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `INDEX (support_ticket_id, id)`; `INDEX (user_id, created_at)`; `INDEX (visibility)`;
`INDEX (is_system)`.
**CHECK** `chk_tr_body`: `is_system = 1 OR body IS NOT NULL OR attachments_count > 0`.
**Relationships.** belongsTo `SupportTicket`, `User`; morphMany `Attachment`.
**Rules.** Append-only: no `edit` route, no `delete` route, and the policy returns false for both for every
role. A correction is a new reply. `deleted_at` exists only to honour `CLAUDE.md` §3.

### 2.20 `meetings` (§95)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `title` | string(180) | not null | §95 |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete` |
| `organizer_id` | FK `users.id` | not null | `restrictOnDelete` |
| `scheduled_at` | datetime | not null | §95 date + time |
| `duration_minutes` | smallint unsigned | not null | default `support.meeting_default_duration_minutes` |
| `ends_at` | datetime | **generated STORED** | `scheduled_at + INTERVAL duration_minutes MINUTE`, so overlap queries never recompute it |
| `delivery_mode` | string(16) | `online` | cast `DeliveryMode` (Phase 14's enum) |
| `location` | string(255) | nullable | for `physical` |
| `classroom_id` | FK `classrooms.id` | nullable | `nullOnDelete`; when a room is booked, `ScheduleClashDetector::check(SlotCandidate)` applies |
| `meeting_url` | string(500) | nullable | §95 meeting URL |
| `agenda` | text | nullable | |
| `notes` | longText | nullable | §95 notes / minutes; visibility per §9 |
| `project_id` | FK `projects.id` | nullable | `nullOnDelete`; §95 "project" |
| `course_id` | FK `courses.id` | nullable | `nullOnDelete`; §95 "course" |
| `batch_id` | FK `batches.id` | nullable | `nullOnDelete` |
| `client_id` | FK `clients.id` | nullable | `nullOnDelete`; **Phase 5 §13 asked for this column** |
| `lead_id` | FK `leads.id` | nullable | `nullOnDelete` |
| `collaborator_id` | FK `collaborators.id` | nullable | `nullOnDelete`; **Phase 8 §13 asked for collaborator participation** |
| `support_ticket_id` | FK `support_tickets.id` | nullable | `nullOnDelete`; a call booked off a ticket |
| `status` | string(16) | `scheduled` | cast `MeetingStatus`; §95 status |
| `is_private` | boolean | false | only participants and `meetings.view_any` holders see it |
| `reminder_minutes_before` | smallint unsigned | nullable | default `support.meeting_default_reminder_minutes` |
| `reminder_sent_at` / `second_reminder_sent_at` | timestamp | nullable | |
| `participants_count` / `accepted_count` / `declined_count` / `attended_count` | smallint unsigned | 0 | CACHES |
| `cancellation_reason` | string(255) | nullable | mandatory on `cancelled` / `postponed` |
| `rescheduled_from_id` | FK self | nullable | `nullOnDelete` |
| `outcome_summary` | string(500) | nullable | |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `INDEX (scheduled_at, status)` the calendar; `INDEX (organizer_id, scheduled_at)`;
`INDEX (status, scheduled_at)`; `INDEX (project_id)`; `INDEX (course_id)`; `INDEX (batch_id)`;
`INDEX (client_id)`; `INDEX (collaborator_id)`; `INDEX (classroom_id, scheduled_at)`;
`INDEX (branch_id, scheduled_at)`; `INDEX (reminder_sent_at)`; `UNIQUE uq_me_resched(rescheduled_from_id)`.
**CHECK** `chk_me_duration`: `duration_minutes BETWEEN 5 AND 1440`.
**CHECK** `chk_me_url`: `delivery_mode <> 'online' OR meeting_url IS NOT NULL`.
**CHECK** `chk_me_cancel`: `status NOT IN ('cancelled','postponed') OR cancellation_reason IS NOT NULL`.
**CHECK** `chk_me_counts`: every count `>= 0`.
**Relationships.** belongsTo `User` (`organizer`), `Branch`, `Classroom`, `Project`, `Course`, `Batch`,
`Client`, `Lead`, `Collaborator`, `SupportTicket`, self (`rescheduledFrom`); hasMany `MeetingParticipant`;
morphMany `Attachment`.

### 2.21 `meeting_participants` (§95 participants across roles)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `meeting_id` | FK `meetings.id` | not null | `cascadeOnDelete` |
| `user_id` | FK `users.id` | nullable | `nullOnDelete`; null for an external guest |
| `participant_type` | string(16) | not null | cast `ParticipantType` - `staff` / `client` / `student` / `teacher` / `collaborator` / `external` |
| `client_id` / `student_id` / `teacher_id` / `collaborator_id` / `employee_id` | FK | nullable | `nullOnDelete`; the profile behind the user, for display and for §9 |
| `external_name` | string(150) | nullable | |
| `external_email` | string(180) | nullable | |
| `role` | string(16) | `required` | cast `MeetingParticipantRole` |
| `response` | string(16) | `pending` | cast `MeetingResponse` |
| `responded_at` | timestamp | nullable | |
| `attended` | boolean | nullable | null = not recorded |
| `attendance_marked_at` / `attendance_marked_by` | timestamp / FK | nullable | `nullOnDelete` |
| `notified_at` / `reminder_sent_at` | timestamp | nullable | |
| `notes` | string(255) | nullable | |
| timestamps | | | **no** soft deletes (a pivot); a removal is a delete plus an activity row |

**Keys.** `UNIQUE uq_mp_user(meeting_id, user_id)`; `UNIQUE uq_mp_external(meeting_id, external_email)`;
`INDEX (user_id, meeting_id)`; `INDEX (participant_type)`; `INDEX (response)`.
**CHECK** `chk_mp_identity`: `user_id IS NOT NULL OR external_email IS NOT NULL`.
**CHECK** `chk_mp_external`: `participant_type <> 'external' OR (user_id IS NULL AND external_name IS NOT NULL)`.
**Relationships.** belongsTo `Meeting`, `User`, `Client`, `Student`, `Teacher`, `Collaborator`, `Employee`.

### 2.22 `conversations` (§94)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `type` | string(16) | `direct` | cast `ConversationType` |
| `subject` | string(180) | nullable | required for `group` |
| `pair_scope` | string(32) | not null | cast `ConversationScope` - **which of §94's six pairs authorised this thread**, stored so the policy re-checks it on every send (INV-22-4) |
| `project_id` | FK `projects.id` | nullable | `nullOnDelete` |
| `course_id` | FK `courses.id` | nullable | `nullOnDelete` |
| `batch_id` | FK `batches.id` | nullable | `nullOnDelete` |
| `support_ticket_id` | FK `support_tickets.id` | nullable | `nullOnDelete` |
| `direct_key` | char(64) | nullable | sha256 of the sorted participant user ids for a `direct` thread; null for `group` (INV-22-5) |
| `last_message_id` | unsignedBigInteger | nullable | FK `messages.id` attached by a follow-up migration (circular reference), `nullOnDelete` |
| `last_message_at` | timestamp | nullable | |
| `messages_count` / `participants_count` | int unsigned / smallint unsigned | 0 | CACHES |
| `is_closed` | boolean | false | |
| `closed_at` / `closed_by` / `closure_reason` | timestamp / FK / string(255) | nullable | `nullOnDelete` |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_cv_direct(direct_key)`; `INDEX (last_message_at)`; `INDEX (type, is_closed)`;
`INDEX (project_id)`; `INDEX (course_id)`; `INDEX (support_ticket_id)`; `INDEX (pair_scope)`.
**CHECK** `chk_cv_group`: `type <> 'group' OR subject IS NOT NULL`.
**CHECK** `chk_cv_direct`: `type <> 'direct' OR direct_key IS NOT NULL`.
**Relationships.** belongsTo `Project`, `Course`, `Batch`, `SupportTicket`, `Message` (`lastMessage`),
`User` (`closer`); hasMany `ConversationParticipant`, `Message`.

### 2.23 `conversation_participants`

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `conversation_id` | FK `conversations.id` | not null | `cascadeOnDelete` |
| `user_id` | FK `users.id` | not null | `restrictOnDelete` |
| `panel` | string(16) | not null | cast `PanelType` - **the side they participate as**, snapshotted, because §94's matrix is about roles |
| `role` | string(16) | `member` | `owner` / `member` |
| `last_read_message_id` | unsignedBigInteger | nullable | FK `messages.id`, `nullOnDelete` |
| `last_read_at` | timestamp | nullable | §94 read status |
| `unread_count` | smallint unsigned | 0 | CACHE, recounted under a row lock (INV-22-6) |
| `is_muted` | boolean | false | |
| `joined_at` | timestamp | not null | |
| `left_at` | timestamp | nullable | |
| `removed_by` | FK `users.id` | nullable | `nullOnDelete` |
| `active_guard` | tinyint | **generated STORED** | `CASE WHEN left_at IS NULL THEN 1 ELSE NULL END` |
| timestamps | | | no soft deletes |

**Keys.** `UNIQUE uq_cp_active(conversation_id, user_id, active_guard)` - one live membership while history
stacks; `INDEX (user_id, last_read_at)`; `INDEX (conversation_id, panel)`; `INDEX (user_id, unread_count)`.
**CHECK** `chk_cp_unread`: `unread_count >= 0`.
**Relationships.** belongsTo `Conversation`, `User`, `Message` (`lastReadMessage`).

### 2.24 `messages` (§94)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `conversation_id` | FK `conversations.id` | not null | `cascadeOnDelete` |
| `user_id` | FK `users.id` | nullable | `nullOnDelete`; null = system |
| `panel` | string(16) | nullable | cast `PanelType` |
| `body` | longText | nullable | plain text plus safe links; always rendered escaped, never as raw HTML |
| `attachments_count` | tinyint unsigned | 0 | CACHE |
| `is_system` | boolean | false | "X joined", "thread closed" |
| `system_event` | string(40) | nullable | |
| `reads_count` | smallint unsigned | 0 | CACHE of participants whose `last_read_message_id >= id` |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `INDEX (conversation_id, id)` the thread page; `INDEX (user_id, created_at)`; `INDEX (created_at)`.
**CHECK** `chk_ms_payload`: `is_system = 1 OR body IS NOT NULL OR attachments_count > 0`.
**Relationships.** belongsTo `Conversation`, `User`; morphMany `Attachment`.
**Rules.** Append-only, like `ticket_replies`: no edit route, no delete route, policy false for both.
**[D-22-1]** A "delete for me" feature is deliberately **not** built: §94 asks for conversations, messages,
attachments, read status and timestamps, and a half-deleted thread makes §112 isolation unprovable.

### 2.25 `notifications` and `notification_preferences` (§97)

**`notifications`** is Laravel's database-channel table (`php artisan make:notifications-table`), created
here because no earlier phase needed it, with the context columns added in the same migration.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | uuid | - | PK, Laravel's |
| `type` | string(255) | not null | the notification class |
| `notifiable_type` / `notifiable_id` | morph | not null | |
| `data` | json | not null | the payload the bell and the index render |
| `read_at` | timestamp | nullable | §97 read state |
| `event_key` | string(64) | not null | the `NotificationRegistry` key (§10.3) - makes every §97 row groupable, filterable and preference-able |
| `module` | string(64) | nullable | so a disabled module's rows can be hidden without deleting them |
| `level` | string(16) | `info` | cast `NotificationLevel` |
| `url` | string(500) | nullable | the deep link, built by the registry, never by a view |
| `actor_id` | FK `users.id` | nullable | `nullOnDelete`; who caused it |
| `emailed_at` | timestamp | nullable | set when the mail channel actually sent |
| `archived_at` | timestamp | nullable | "clear all" archives, it does not delete |
| timestamps | | | no soft deletes, no blameable |

**Keys.** Laravel's `INDEX (notifiable_type, notifiable_id)` **plus**
`INDEX (notifiable_type, notifiable_id, read_at, archived_at)` - the bell count must be one index scan;
`INDEX (event_key, created_at)`; `INDEX (module)`; `INDEX (actor_id)`; `INDEX (created_at)`.

**`notification_preferences`** - §97's per-user preferences. A **missing row means "the registry default"**,
so no backfill is ever needed when a later phase registers a new event.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `user_id` | FK `users.id` | not null | `cascadeOnDelete` |
| `event_key` | string(64) | not null | must exist in `NotificationRegistry`; an unknown key is rejected |
| `database_enabled` | boolean | true | |
| `mail_enabled` | boolean | false | additionally gated by `support.notifications_mail_enabled` |
| `mail_digest` | string(16) | `immediate` | cast `NotificationDigest` |
| timestamps | | | no soft deletes |

**Keys.** `UNIQUE uq_np(user_id, event_key)`; `INDEX (event_key)`.
**[D-22-2] Critical events cannot be muted.** A registry entry may declare `mandatory: true`
(`commission.reversed`, `payout.paid`, `certificate.revoked`, `ticket.sla_breach`); the preference screen
renders those locked with an explanation, and `NotificationService` ignores a stored row that tries to
disable one.

---

### 2.26 `report_exports` (§99 export - Phase 23's one table)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `uuid` | char(36) | not null | the only id ever placed in a URL |
| `report_key` | string(64) | not null | the `ReportRegistry` key, **re-authorised at download** |
| `format` | string(16) | not null | cast `ExportFormat` (Phase 13's enum, plus `excel`) |
| `filters` | json | not null | the exact `ReportRequest` payload, so the file can be explained and reproduced |
| `date_from` / `date_to` | date | nullable | denormalised from the filters for the index |
| `requested_by` | FK `users.id` | not null | `restrictOnDelete`; the **only** user who may download it |
| `status` | string(16) | `queued` | cast `ExportStatus` |
| `row_count` | int unsigned | nullable | |
| `storage_disk` | string(32) | `private` | |
| `file_path` | string(255) | nullable | `exports/reports/{YYYY-MM}/{uuid}.{ext}` |
| `file_size_bytes` | unsignedBigInteger | nullable | |
| `checksum_sha256` | char(64) | nullable | |
| `started_at` / `completed_at` | timestamp | nullable | |
| `expires_at` | datetime | nullable | `completed_at + reports.export_retention_days` |
| `download_count` | int unsigned | 0 | |
| `last_downloaded_at` | timestamp | nullable | |
| `error_class` | string(180) | nullable | |
| `error_message` | string(500) | nullable | the real exception text, shown to the requester |
| timestamps | | | **no** soft deletes, **no** blameable - `requested_by` is the actor and the row is an artefact, not a business record |

**Keys.** `UNIQUE uq_rx_uuid(uuid)`; `INDEX (requested_by, created_at)`;
`INDEX (status, expires_at)` the prune sweep; `INDEX (report_key, created_at)`.
**CHECK** `chk_rx_counts`: `(row_count IS NULL OR row_count >= 0) AND download_count >= 0`.
**Relationships.** belongsTo `User` (`requester`).

### 2.27 Additive migrations on tables other phases own

| Migration | Change | Why |
|---|---|---|
| `add_reporting_indexes_to_activity_log_table` | `INDEX (causer_type, causer_id, created_at)`, `INDEX (subject_type, subject_id, created_at)`, `INDEX (module, created_at)`, `INDEX (log_name, created_at)`, `INDEX (created_at)` | the §106/§107 viewers filter on exactly these. **Indexes only - no column, no data change** (INV-23-5) |
| `assert_attachment_morphs_for_support_tables` *(guarded by `Schema::hasTable`)* | nothing structural - it asserts `attachments` exists **and** that it carries the `visibility` column, and **fails loudly** with an instruction if either is missing | §96: tickets, replies, messages and meetings are four more owners of the one file table ([D-22-3]) |

**No client-visibility migration.** The earlier `add_client_visibility_to_attachments_table` migration is
**deleted**: client visibility is `attachments.visibility` (enum `AttachmentVisibility`: `internal` / `team`
/ `client`), declared by **Phase 6 §2.9 / §3** and the only client-visibility mechanism in the system (audit
F-2.6). These phases add no boolean of their own, and every rule that used to read `is_client_visible = 1`
now reads `visibility = AttachmentVisibility::Client`.

**[D-22-3] The shared `attachments` contract.** §96 says a file may belong to projects, tasks, clients,
employees, collaborators, students, courses, batches, invoices **or tickets**. Phase 6 owns that one
polymorphic table and Phase 22 must not create a second one. The columns Phase 22 relies on are listed in
§13.1 as a request. Phase 22 adds five `attachable_type` values - `SupportTicket`, `TicketReply`, `Message`,
`Meeting` and `Assignment` - and nothing else. Phase 19's `course_materials` and
`assignment_submission_files` remain **separate tables** because they carry behaviour a generic attachment
cannot: targeting, a release window, per-open download tracking, attempt numbering and a duplicate-file
warning checksum.

**The `files` module slug governs the `attachments` table.** There is never a `files` table: `files` is a
**module slug** - a permission namespace (`files.view_any`, `files.upload`, `files.download`, `files.delete`)
- and `attachments` is the row store it gates (`CLAUDE.md` §3, audit F-2.8). The morph map is not Phase 22's
alone: Phase 6 also registers `Collaborator` and `Invoice`, each with its own policy (audit F-13.2), so Phase
22 **appends** its five values and never rewrites the map. Client visibility on any morph is
`attachments.visibility = AttachmentVisibility::Client` (audit F-2.6) - never a boolean on another table.

### 2.28 Status lifecycles - explicit transition tables

Every transition below is the **only** legal path. The Form Request rejects anything else by name, the
service asserts it again, and a row marked *reason* fails validation without one (INV-ALL-2).

**2.28.1 `MaterialStatus`**

| From | To | Ability | Reason | Side effects |
|---|---|---|---|---|
| draft | published | `course_materials.change_status` | no | stamps `published_at`, fires `MaterialPublished`, queues `NotifyMaterialAudience` for un-notified targets |
| published | draft | `course_materials.change_status` | **yes** | hides it from every panel at once; the download log is kept |
| draft / published | archived | `course_materials.change_status` | **yes** | leaves the panels; file kept on disk; log kept |
| archived | published | `course_materials.change_status` | **yes** | |

**2.28.2 `AssignmentStatus`**

| From | To | Ability | Reason | Side effects |
|---|---|---|---|---|
| draft | published | `assignments.change_status` | no | stamps `published_at`, recounts `expected_count`, fires `AssignmentPublished` → notifies the batch |
| published | closed | `assignments.change_status` | no | no new submissions; `markMissed()` fills `missed` rows for non-submitters |
| closed | published | `assignments.change_status` | **yes** | re-opens the window; existing `missed` rows are cleared with an activity row |
| published / closed | archived | `assignments.change_status` | **yes** | |
| draft | *(deleted)* | `assignments.delete` | **yes** | only while no submission exists |

**2.28.3 `SubmissionStatus`**

| From | To | Who | Notes |
|---|---|---|---|
| draft | submitted | the student (or staff on their behalf) | lateness decided here, once (INV-19-7) |
| submitted | under_review | teacher | optional |
| submitted / under_review / returned | graded | teacher | marks + feedback; `marks_released_at` now or on release |
| graded | returned | teacher | sent back for rework; a resubmission is allowed while attempts remain |
| submitted / under_review / returned / graded | superseded | system | when a later attempt is inserted, in the same transaction |
| *(none)* | missed | system | `markMissed()` after close, for a student with no live submission |
| draft | *(row deleted)* | the student | `withdraw` - the only delete in Phase 19, and only while `draft` |

**2.28.4 `ExamStatus`**

| From | To | Ability | Reason | Side effects |
|---|---|---|---|---|
| draft | scheduled | `exams.change_status` | no | clash check; recounts `expected_count`; fires `ExamScheduled` → §97 "exam scheduled" |
| scheduled | ongoing | `exams.change_status` | no | |
| scheduled / ongoing | conducted | `exams.change_status` | no | opens the result sheet |
| conducted | marking | `results.create` | no | set automatically on the first saved row |
| marking | results_published | `results.change_status` | no | requires a row for every roster student and, when `institute.result_publish_requires_verification`, a verifier ≠ the enterer; computes ranks; fires `ResultsPublished` |
| results_published | marking | `results.change_status` | **yes** | unpublish; clears `published_at` on the rows; notifies nobody |
| draft / scheduled / ongoing | cancelled | `exams.change_status` | **yes** | only while no result row exists |

**2.28.5 `CertificateStatus`**

| From | To | Ability | Reason | Side effects |
|---|---|---|---|---|
| draft | issued | `certificates.change_status` (+ `certificates.approve` when `institute.certificate_issue_requires_approval`) | no | number + verification code + QR payload + snapshots, one transaction; fires `CertificateIssued` → §97 "certificate generated" |
| issued | revoked | `certificates.change_status` | **yes** | the public page says *revoked* with the date; nothing is deleted |
| revoked | reissued *(new row)* | `certificates.create` | **yes** | a new `draft` carrying `reissue_of_id`; the revoked row stays for ever |
| draft | *(deleted)* | `certificates.delete` | **yes** | **drafts only**; an issued certificate can never be deleted |

**2.28.6 `IdCardStatus`**

| From | To | Ability | Reason |
|---|---|---|---|
| active | expired | system (`idcards:expire`) | no |
| active | lost / damaged | `student_id_cards.change_status` | **yes** |
| lost / damaged / expired | replaced *(new row)* | `student_id_cards.create` | **yes** |
| active | revoked | `student_id_cards.change_status` | **yes** |

**2.28.7 `TicketStatus` (§93's five)**

| From | To | Who | Reason | Side effects |
|---|---|---|---|---|
| open | in_progress | staff with `support_tickets.edit` | no | usually on assignment or the first reply |
| open / in_progress | waiting | staff | no | stamps `waiting_since`; **the SLA clock pauses** |
| waiting | in_progress | staff, **or automatically on a requester reply** | no | accumulates `total_waiting_minutes` |
| open / in_progress / waiting | resolved | staff with `support_tickets.change_status` | no | stamps `resolved_at`; notifies the requester; starts the auto-close timer |
| resolved | closed | staff, or `tickets:auto-close` after `support.ticket_auto_close_resolved_days` | no | |
| resolved | open | a **requester reply** inside `support.ticket_reopen_window_days`, or staff | no | `reopened_count++`, a fresh `resolution_due_at` |
| closed | open | staff with `support_tickets.change_status` | **yes** | outside the window only staff may reopen |

**2.28.8 `MeetingStatus`**

| From | To | Ability | Reason |
|---|---|---|---|
| scheduled | completed | `meetings.change_status` (organizer or holder) | no |
| scheduled | cancelled | `meetings.change_status` | **yes** |
| scheduled | postponed *(+ a new row)* | `meetings.change_status` | **yes** |
| scheduled | missed | the `meetings:close-past` sweep | no |

**2.28.9 `ExportStatus`**

`queued → running → completed`; `running → failed` (with `error_class` / `error_message`);
`completed → expired` by `reports:prune-exports`. No other transition, and nothing returns from `expired`.

### 2.29 Relationship map - one line per edge that matters

```
Course         1-n CourseMaterial 1-n CourseMaterialTarget -> Course | Batch | Student
CourseMaterial 1-n CourseMaterialDownload -> User / Student / Teacher
CourseTopicAssignment 1-n Assignment               (blueprint -> event, [D-IN-6])
Batch          1-n Assignment 1-n AssignmentSubmission 1-n AssignmentSubmissionFile
AssignmentSubmission 1-1 AssignmentSubmission      (superseded_by)
GradeScale     1-n GradeScaleBand ; GradeScale 1-n Exam ; 1-n ExamResult ; 1-n Certificate
Batch          1-n Exam 1-n ExamResult -> Student, StudentBatchEnrollment
CourseTopic    1-n Exam                            (what it assesses -> §83 progress, §6.12)
PrintTemplate  1-n Certificate ; PrintTemplate 1-n StudentIdCard
StudentBatchEnrollment 1-1 Certificate (live) ; Student 1-1 StudentIdCard (active)
Certificate    1-n CertificateVerification ; Certificate 1-1 Certificate (reissue_of)
TicketDepartment 1-n SupportTicket 1-n TicketReply
SupportTicket  n-1 User(requester) ; n-1 User(assignee) ; n-1 Client|Student|Teacher|Collaborator|Employee
Meeting        1-n MeetingParticipant -> User | external ; Meeting n-1 Project|Course|Batch|Client|Lead|Collaborator
Conversation   1-n ConversationParticipant -> User ; Conversation 1-n Message 1-n Attachment(morph)
User           1-n DatabaseNotification ; User 1-n NotificationPreference ; User 1-n ReportExport
Attachment(morph, Phase 6) <- SupportTicket | TicketReply | Message | Meeting | Assignment
```

---

## 3. Enums to add

All in `app/Enums/`, string-backed, implementing `label(): string` and `color(): string` (a Tailwind token)
and exposing `static options(): array`, exactly as Phase 1 §2 requires.

**Reused unchanged, never redeclared:** `PanelType`, `Ability` (Phase 1); **`Priority`** and
**`AttachmentVisibility`** (phase-06 §3 - `support_tickets.priority` and every attachment these phases write
cast to them, F-5.7 / F-2.6); `CourseResourceType`, `DeliveryMode`, `Weekday`, `ProgressStatus`,
`ProgressSource`, `Gender`, `EnrollmentStatus`, `BatchStatus` (phase-14-17 §3); `PaymentMethod`,
`StudentFeeStatus` (declared by phase-07 §3 with the spine's cases, reused here); `ExportFormat`,
`FinanceContext` (phase-13 §3); `DateRange` presets (Phase 2's `DateRange`, which is a class, not an enum).

**[D-19-3] `CourseResourceType` is the material type.** §79's eight kinds (PDF, notes, images, videos,
documents, ZIP, source code, external links) are already exactly `pdf`, `note`, `image`, `video`,
`document`, `zip`, `source_code`, `link` inside phase-14-17's `CourseResourceType`, which additionally
carries `slide` and `audio` and, crucially, `isFile(): bool` and `allowedMimes(): array` - the server-side
whitelist §111 demands. A second `MaterialType` enum would fork that whitelist. Phase 19 extends the enum
with one helper instead: `maxSizeSettingKey(): string`.

### 3.1 Phase 19

| Enum | Cases (values) | Extra members |
|---|---|---|
| `MaterialStatus` | `draft`, `published`, `archived` | `isVisibleToStudents(): bool` (true only for `published`), `isTerminal(): bool` |
| `MaterialTargetType` | `course`, `batch`, `student` | `column(): string` (`target_course_id` \| `target_batch_id` \| `target_student_id`), `breadth(): int` (3/2/1, which is how `audience_scope` picks the broadest), `modelClass(): string` |
| `MaterialAccessAction` | `view`, `download`, `link_open` | `countsAsDownload(): bool` (false only for `view`) |
| `AssignmentStatus` | `draft`, `published`, `closed`, `archived` | `acceptsSubmissions(): bool` (true only for `published`), `isVisibleToStudents(): bool` (`published`, `closed`), `isTerminal(): bool` |
| `SubmissionType` | `file`, `text`, `file_or_text`, `file_and_text` | `requiresFile(): bool`, `requiresText(): bool`, `allowsFile(): bool`, `allowsText(): bool` - the four booleans the Form Request needs, defined once |
| `SubmissionStatus` | `draft`, `submitted`, `under_review`, `returned`, `graded`, `missed`, `superseded` | `countsAsSubmitted(): bool` (everything except `draft`, `missed`, `superseded`), `isLive(): bool` (not `superseded`), `isGraded(): bool`, `isEditableByStudent(): bool` (true only for `draft`), `visibleMarks(): bool` (`graded` only) |

### 3.2 Phase 20

| Enum | Cases (values) | Extra members |
|---|---|---|
| `ExamType` | `quiz`, `weekly_test`, `monthly_test`, `midterm`, `final`, `practical` | §81's **six**, verbatim. `isMajor(): bool` (`midterm`, `final`) - the default filter for the certificate grade source; `defaultDurationMinutes(): int` |
| `ExamStatus` | `draft`, `scheduled`, `ongoing`, `conducted`, `marking`, `results_published`, `cancelled` | `acceptsResultEntry(): bool` (`conducted`, `marking`), `resultsVisible(): bool` (`results_published` only), `isTerminal(): bool` (`results_published`, `cancelled`), `countsInReports(): bool` (not `draft`, not `cancelled`) |
| `ExamAttendanceStatus` | `appeared`, `absent`, `exempt`, `debarred` | `requiresMarks(): bool` (true only for `appeared`), `countsInDenominator(): bool` (`appeared`, `absent`, `debarred`; `exempt` is excused), `countsAsFail(): bool` (`absent`, `debarred`) |
| `VerificationResult` *(shared with Phase 21)* | `valid`, `revoked`, `not_found`, `throttled`, `not_public` | `isPositive(): bool` (true only for `valid`), `httpStatus(): int` |

`VerificationResult` is declared once here because the verification endpoint also serves ID cards (§2.16).

### 3.3 Phase 21

| Enum | Cases (values) | Extra members |
|---|---|---|
| `PrintTemplateType` | `certificate`, `student_id_card`, `result_card` | `tokens(): array` delegating to `PrintTokenRegistry`, `defaultPaperSize(): PaperSize`, `permission(): string` |
| `PaperSize` | `a4`, `a5`, `letter`, `legal`, `cr80`, `custom` | `widthMm(): ?float`, `heightMm(): ?float` (`cr80` = 85.60 x 53.98 - the ID-card standard), `dompdfPaper(): string\|array` |
| `PageOrientation` | `portrait`, `landscape` | |
| `CertificateStatus` | `draft`, `issued`, `revoked` | `isIssued(): bool`, `isPublic(): bool` (`issued`, `revoked` - a revoked certificate must still resolve publicly, saying *revoked*), `isImmutable(): bool` (everything except `draft`) |
| `IdCardStatus` | `active`, `expired`, `lost`, `damaged`, `replaced`, `revoked` | `isUsable(): bool` (true only for `active`), `isReplaceable(): bool` (`expired`, `lost`, `damaged`) |

**[D-21-3] `reissued` is not a status, it is a link.** It is tempting to add a `reissued` case; the
model is `revoked` plus a new `draft` row carrying `reissue_of_id`, because a single certificate is never
simultaneously "the revoked one" and "the new one". `CertificateStatus` therefore has three cases.

### 3.4 Phase 22

**There is no `TicketPriority` enum** (audit F-5.7). `support_tickets.priority` casts to the shared
**`App\Enums\Priority`** (`low`, `medium`, `high`, `urgent`), declared by phase-06 §3 and reused verbatim:
one enum, one `label()`, one `color()`, so a task badge and a ticket badge cannot drift. The SLA multiplier is
**support policy, not a property of the priority**, and therefore lives on the service as
`TicketSlaService::multiplier(Priority $p): float` (§6.16), not on the enum.

| Enum | Cases (values) | Extra members |
|---|---|---|
| `TicketStatus` | `open`, `in_progress`, `waiting`, `resolved`, `closed` | §93's **five**, verbatim. `isOpen(): bool` (`open`, `in_progress`, `waiting`), `pausesSla(): bool` (`waiting` only), `isResolvedOrClosed(): bool`, `isTerminal(): bool` (`closed`), `requesterCanReply(): bool` (all but `closed`) |
| `TicketAssignStrategy` | `none`, `default_assignee`, `round_robin`, `least_open` | |
| `ReplyVisibility` | `public`, `internal_note` | `reachesRequester(): bool` |
| `MeetingStatus` | `scheduled`, `completed`, `cancelled`, `postponed`, `missed` | §95's "status" plus the two real-world terminal cases. `isLive(): bool`, `isTerminal(): bool`, `countsInReports(): bool` |
| `MeetingParticipantRole` | `organizer`, `required`, `optional`, `note_taker` | `countsInQuorum(): bool` |
| `MeetingResponse` | `pending`, `accepted`, `declined`, `tentative` | `isAnswered(): bool` |
| `ParticipantType` | `staff`, `client`, `student`, `teacher`, `collaborator`, `external` | `panel(): ?PanelType`, `profileColumn(): ?string`, `isInternal(): bool` |
| `ConversationType` | `direct`, `group` | `requiresSubject(): bool` |
| `ConversationScope` | `admin_employee`, `employee_employee`, `client_manager`, `collaborator_staff`, `student_staff`, `teacher_management` | **§94's six pairs, verbatim and in order.** `panels(): array` (the two `PanelType`s it joins), `settingKey(): string`, `describe(): string` - the plain-language reason shown when a pair is refused |
| `NotificationLevel` | `info`, `success`, `warning`, `critical` | `icon(): string`; `color()` drives the bell dot |
| `NotificationDigest` | `immediate`, `daily`, `off` | `isBatched(): bool` |
| `NotificationGroup` | `system`, `software_house`, `institute`, `finance`, `collaborator`, `support` | groups the preference screen; mirrors `ModuleGroup` without redefining it (a notification group is not a module) |

### 3.5 Phase 23

| Enum | Cases (values) | Extra members |
|---|---|---|
| `ReportGroup` | `software_house`, `institute`, `collaborator`, `system` | §99's three sets plus the §106-108 system set. `label()` reads the company / institute name from settings so no brand name is hardcoded (phase-13's `FinanceContext` precedent) |
| `ExportStatus` | `queued`, `running`, `completed`, `failed`, `expired` | `isDownloadable(): bool` (`completed` only), `isTerminal(): bool` |
| `SearchEntityType` | `client`, `employee`, `collaborator`, `student`, `teacher`, `course`, `lead`, `project`, `task`, `invoice`, `ticket` | **§108's eleven, verbatim.** `module(): string`, `permission(): string`, `icon(): string`, `label(): string` - so the palette, the filter chips and the provider registry read one definition |
| `AuditSensitivity` | `normal`, `sensitive`, `financial` | which §107 rows the audit trail shows by default, and which require `view_financial` to read the old/new values |

**`ExportFormat` (Phase 13's enum) gains one case: `excel`**, with `mime()` and `extension()` filled, and it
is offered in the UI **only** when `reports.excel_enabled` is true and the package is installed - exactly the
hand-off phase-13 §13.2 asked for. No parallel export path is created.

---

## 4. PermissionRegistry additions

Ability presets are Phase 1 §4's: `READ`, `CRUD`, `CRUD_FULL`, `APPROVE`, `STATUS`, `ASSIGN`, `FILES`,
`MONEY`, `REPORTS`, `LOGS`. Permission name stays `{slug}.{ability}` (D4) and `PermissionRegistry` remains
the only place a permission name exists. **No new `Ability` case is invented**: publishing is
`change_status`, marking a submission is `edit`, issuing a certificate is `change_status`, running a report
is `view_reports`, and streaming a file is `download`.

### 4.1 New module slugs - five (every one `is_core = false`)

| slug | ModuleGroup | icon | Abilities | Why a module of its own |
|---|---|---|---|---|
| `assignment_submissions` | `Institute` | `clipboard-document-check` | `READ` + `create` + `edit` + `download` + `STATUS` + `export` + `REPORTS` + `LOGS` - **never `delete`** | **grading is not authoring.** A visiting trainer may be allowed to read a roster's submissions without marking them, and a coordinator may mark without being able to publish new assignments. `create` exists only for "record an offline submission on a student's behalf"; `edit` **is** the mark-and-feedback ability |
| `grade_scales` | `Institute` | `academic-cap` | `CRUD` + `STATUS` | §82's grade comes from a scale an exam officer maintains; giving that right away does not imply the right to publish results |
| `print_templates` | `Institute` | `document-duplicate` | `CRUD` + `STATUS` + `print` | §84 and §85 both say the admin controls the template. It holds no student data, so a designer can be given it without any sight of a student record - and because `body_html` is powerful, it must be separately grantable and separately audited |
| `ticket_departments` | `Shared` | `rectangle-stack` | `CRUD` + `STATUS` | §93's department is dynamic; a support lead maintains queues and SLAs without holding `settings.edit` |
| `audit_trail` | `System` | `finger-print` | `READ` + `view_logs` + `export` + `print` | §107 is a different right from §106: the audit trail exposes **old and new values of sensitive changes** (a commission rate, a collaborator re-link, a published mark). A compliance reader may be given it while an operator keeps only `activity_log.view_logs` |

Nothing else needs a module. `course_material_targets` and `course_material_downloads` live under
`course_materials`; `grade_scale_bands` under `grade_scales`; `exam_results` under `results`;
`certificate_verifications` under `certificates.view_logs`; `ticket_replies` under `support_tickets`;
`meeting_participants` under `meetings`; `conversations`, `conversation_participants` and `messages` under
`messages`; `notification_preferences` under the signed-in user's own account (no permission - a user always
owns their own preferences); `report_exports` under `reports`. Analytics is **not** a module: the charts are
`DashboardWidget`s and an Analytics screen, both gated by `reports.view_reports`.

### 4.2 Abilities added to the Phase 1 slugs these phases activate

Additive. The registry stays the only declaration (D4).

| slug | Abilities after these phases | Notes |
|---|---|---|
| `course_materials` | `CRUD` + `FILES` + `ASSIGN` + `STATUS` + `export` + `REPORTS` + `LOGS` | **`assign` is the targeting ability** (course / batch / student); `upload` creates, `download` streams, `view_reports` opens the engagement report |
| `assignments` | `CRUD_FULL` + `FILES` + `STATUS` + `REPORTS` + `LOGS` | `print` = the printable assignment sheet for a physical class |
| `exams` | `CRUD_FULL` + `ASSIGN` + `STATUS` + `REPORTS` + `LOGS` | `assign` = set the examiner / marker; `change_status` covers schedule, conduct, cancel |
| `results` | `READ` + `create` + `edit` + `APPROVE` + `STATUS` + `print` + `export` + `import` + `REPORTS` + `LOGS` - **never `delete`** | `create` = enter a sheet, `edit` = amend with a reason, `approve`/`reject` = the §2.28.4 verification step, `change_status` = publish / unpublish, `print` = the result card |
| `certificates` | `READ` + `create` + `edit` + `APPROVE` + `STATUS` + `print` + `export` + `LOGS` - **never `delete`** except a draft (policy) | `approve` is the issue gate when `institute.certificate_issue_requires_approval`; `change_status` = issue / revoke |
| `student_id_cards` | `READ` + `create` + `edit` + `STATUS` + `print` + `export` + `LOGS` - **never `delete`** | `print` additionally gates **batch printing** |
| `support_tickets` | `CRUD_FULL` + `ASSIGN` + `STATUS` + `FILES` + `REPORTS` + `LOGS` - **`delete` is registered but the policy refuses it for every role** | `assign` = hand to an agent; `upload`/`download` = attachments; `view_any` = the whole queue, **`view` = only tickets assigned to or created by the holder** (the `leads` precedent, [D-P5-8]) |
| `meetings` | `CRUD_FULL` + `ASSIGN` + `STATUS` + `FILES` + `print` + `REPORTS` | `assign` = add or remove participants; `view_any` = every meeting, `view` = only meetings the holder organises or attends |
| `messages` | `READ` + `create` + `STATUS` + `FILES` + `LOGS` - **never `edit`, never `delete`** | `create` = start a thread and send; `change_status` = close a thread; `view_any` exists only for the compliance reader described in §9 and is granted to nobody by default |
| `notifications` | `READ` + `STATUS` + `delete` | `change_status` = mark read / unread; `delete` = archive one's own row. A user always reaches **their own** notifications; these abilities gate the admin-side broadcast register |
| `files` | `STATUS` added (= "share with / unshare from the client portal") | the rest of the `files` ability set belongs to whichever phase ships `attachments` (Phase 6); §13.1 records the ask so the two do not collide |
| `reports` | `READ` + `REPORTS` (`view_reports`, `export`, `print`) | the §99 hub gate; every individual report stacks its source module's `view_reports` on top (INV-23-2) |
| `activity_log` | `LOGS` + `export` + `print` added to Phase 1's set | §106 viewer export |
| `global_search` | `view_any` | §108; each provider stacks its own module gate and permission |

### 4.3 Portal permissions

Phase 1 §4 registers the four portal prefixes with "dashboard, profile, plus the read abilities each panel
needs", and phase-14-17 §4.3, the spine §4.3, phase-05 §4.3 and phase-08-09 §4.3 have each added their own.
The set below is what **these five phases** add, each registered once, in `PermissionRegistry`.

| Panel | Permission | Gates |
|---|---|---|
| Student | `student_portal.materials` | the material library for their own courses (§74) |
| Student | `student_portal.material_download` | the act of streaming or opening a material - separate so a fee-defaulting student can be shown the list but denied the files |
| Student | `student_portal.assignments` | the assignment list and detail (§74, §80) |
| Student | `student_portal.assignment_submit` | creating and resubmitting a submission, and uploading its files |
| Student | `student_portal.exams` | their own exam schedule (§74) |
| Student | `student_portal.results` | their own **published** results (§74, §82) |
| Student | `student_portal.result_card` | printing / downloading their own result card |
| Student | `student_portal.certificate` | their own issued certificate and its PDF (§74) |
| Student | `student_portal.id_card` | their own ID card PDF (§85) |
| Student | `student_portal.tickets` / `.ticket_create` | own tickets, and raising one (§93) |
| Student | `student_portal.messages` / `.message_send` | own conversations, and sending (§94 `student_staff` only) |
| Student | `student_portal.meetings` | meetings they participate in (§95) |
| Student | `student_portal.notifications` | own notifications + mark read (§97) |
| Teacher | `teacher_portal.materials` / `.material_upload` | reading and sharing material for their own batches (§73) |
| Teacher | `teacher_portal.assignments` | creating, publishing and closing assignments for their own batches (§73, §80) |
| Teacher | `teacher_portal.assignment_grade` | marking and feedback - deliberately separate from `.assignments`, the same reasoning as phase-14-17's `attendance_mark` |
| Teacher | `teacher_portal.exams` | their own batches' exams (§73) |
| Teacher | `teacher_portal.results_entry` | entering and saving a result sheet for their own batches |
| Teacher | `teacher_portal.results` | reading results for their own batches |
| Teacher | `teacher_portal.certificates` | seeing which of their students are certificate-eligible and the issued list - **never issuing** |
| Teacher | `teacher_portal.tickets` / `.ticket_create` | §93 "user" includes a trainer |
| Teacher | `teacher_portal.messages` / `.message_send` | §94 `teacher_management` |
| Teacher | `teacher_portal.meetings` | §95 |
| Teacher | `teacher_portal.notifications` | §97 |
| Client | `client_portal.ticket_create` | Phase 5 registered `.tickets` as read and said "create arrives with Phase 22" |
| Client | `client_portal.message_send` | Phase 5 registered `.messages` as read and said the same |
| Client | `client_portal.meeting_respond` | accept / decline an invitation (§95) |
| Collaborator | `collaborator_portal.tickets` / `.ticket_create` | §33 gives collaborators a complete panel and §93's requester is "a user"; both default-granted (see §12.2 Q5) |
| Collaborator | `collaborator_portal.meeting_respond` | the counterpart of the existing `.meetings` read right |
| Collaborator | *(existing, used as-is)* `.meetings`, `.messages`, `.files_upload`, `.files_download`, `.comments`, `.notifications` | phase-08-09 §4.3 already registered them and names Phase 22 as the enforcement point |

**Role grants** (`RoleSeeder`, idempotent, extending Phase 1 §5 and phase-14-17 §4.4):

| Role | Gets |
|---|---|
| Institute Manager | every §4.1 and §4.2 institute slug at full ability, plus `print_templates` CRUD, `grade_scales` CRUD, `certificates.approve`, `results.approve`, `reports.view_reports` |
| Course Coordinator | `course_materials` CRUD + `assign` + FILES, `assignments` CRUD + FILES, `assignment_submissions` READ + `edit` + `download`, `exams` CRUD + `change_status`, `results` create + edit + `change_status` + `print`, `grade_scales` read, `certificates` read + create (draft only), `student_id_cards` read + create + print. **No** `certificates.approve`, **no** `print_templates.*` |
| Teacher (panel role) | the `teacher_portal.*` set above |
| Student (panel role) | the `student_portal.*` set above |
| Client (panel role) | the three `client_portal.*` additions |
| Collaborator (panel role) | the two `collaborator_portal.*` additions |
| Receptionist | `certificates` read + `print`, `student_id_cards` CRUD + `print` (the front desk prints cards), `support_tickets` create + view + reply, `meetings` read |
| Support Agent | `support_tickets` CRUD_FULL + `assign` + `change_status` + FILES + `view_reports`, `ticket_departments` read, `messages` READ + create + FILES, `meetings` CRUD + `assign` |
| Project Manager | `meetings` CRUD + `assign`, `messages` READ + create, `support_tickets` view + reply on tickets of their own projects, `reports.view_reports` |
| Accountant | `reports.view_reports` + `export` + `print`; the finance report set through its own `view_financial` grants (phase-13 §4); **no** institute write ability |
| HR | `reports.view_reports` for the employee / attendance / payroll reports only |
| Admin | everything in §4.1 and §4.2 except `print_templates.delete`; `audit_trail` full |
| Super Admin | everything (`Gate::before`) |

### 4.4 `modules.depends_on` declarations (Phase 2's dependency graph)

| Module | depends_on |
|---|---|
| `course_materials` | `courses`, `batches` |
| `assignments` | `courses`, `batches` |
| `assignment_submissions` | `assignments` |
| `exams` | `courses`, `batches` |
| `results` | `exams`, `grade_scales` |
| `grade_scales` | *(none)* |
| `print_templates` | *(none)* |
| `certificates` | `students`, `courses`, `print_templates` |
| `student_id_cards` | `students`, `print_templates` |
| `support_tickets` | `ticket_departments` |
| `ticket_departments` | *(none)* |
| `meetings` | *(none)* |
| `messages` | *(none)* |
| `notifications` | *(none)* |
| `reports` | *(none)* |
| `audit_trail` | `activity_log` |
| `global_search` | *(none - each provider self-gates)* |

**Module-disable behaviour that must not break a write.** Phase 1's `Gate::before` denies every ability of a
disabled module, which is exactly right for routes. Two services must additionally survive it:

| Service | Behaviour when its module is off |
|---|---|
| `NotificationService` | **no-ops with an `info` log line** and returns; the triggering business transaction still commits (INV-22-7). Disabling notifications must never roll back a fee payment |
| `GlobalSearchService` | drops that entity's provider from the palette and from the result set; the rest still works |
| `CertificateVerificationService` | the public page returns the "verification is unavailable" holding view (503), never a stack trace |

---

## 5. SettingsRegistry additions

All definitions live in code (Phase 2 §2), values in the DB. The existing keys these phases read are **used
exactly as defined and never redefined** - and each belongs to the phase that declared it:

| Key | Declared by |
|---|---|
| `institute.certificate_prefix`, `institute.certificate_verification_url` | Phase 2 §2 |
| `security.max_upload_mb`, `security.allowed_file_types`, `localization.*`, `contact.business_hours` | Phase 2 §2 |
| `institute.attendance_minimum_percentage` | **phase-14-17 §5** (not Phase 2 - audit factual drift #2) |
| `finance.report_sync_row_limit` | **phase-13 §5** (not Phase 2 - audit factual drift #3) |

### 5.1 Additions to the existing `institute` group (phases 19-21)

| group.key | type | default | Meaning |
|---|---|---|---|
| `institute.material_max_upload_mb` | number | `50` | per-file ceiling for a material; the effective limit is `min(this, security.max_upload_mb)` |
| `institute.material_allowed_types` | multiselect(`CourseResourceType`) | all ten | which of §79's kinds may be uploaded at all |
| `institute.material_extra_extensions` | text | `psd,ai,fig,sketch` | design-course extras, **intersected** with `security.allowed_file_types`, never widening it |
| `institute.material_visible_after_batch_end_days` | number | `90` | how long a completed batch keeps access to its material (INV-19-3) |
| `institute.material_download_log_retention_days` | number | `365` | `0` = keep for ever |
| `institute.material_notify_on_publish` | boolean | `true` | fires `MaterialPublished` → the targeted students |
| `institute.assignment_submission_max_mb` | number | `20` | per submission file |
| `institute.assignment_max_files_default` | number | `3` | prefills `assignments.max_files` |
| `institute.assignment_max_attempts_default` | number | `3` | prefills `assignments.max_attempts` |
| `institute.assignment_late_submission_default` | boolean | `true` | prefills `late_submission_allowed` |
| `institute.assignment_late_penalty_default_percentage` | decimal | `0.0000` | prefills `late_penalty_percentage`, which is `decimal(8,4)` (F-7.1) |
| `institute.assignment_deadline_reminder_hours` | text | `48,12` | comma-separated offsets for `assignments:deadline-reminders` |
| `institute.assignment_auto_close_on_deadline` | boolean | `false` | when true, `assignments:close-due` closes a published assignment once `late_cutoff_at` (or `deadline_at` when late submission is off) has passed |
| `institute.assignment_release_marks_immediately` | boolean | `true` | prefills `marks_visible_to_students` |
| `institute.default_grade_scale_id` | select *(scales)* | the seeded `DEFAULT` scale | the fallback when an exam names no scale |
| `institute.exam_default_passing_percentage` | decimal | `40.00` | prefills `exams.passing_marks` as a percentage of `total_marks` |
| `institute.result_publish_requires_verification` | boolean | `true` | the §2.28.4 four-eyes step: the verifier must not be the enterer |
| `institute.result_card_show_position` | boolean | `true` | §82 remark block |
| `institute.result_card_show_attendance` | boolean | `true` | |
| `institute.result_card_show_all_exams` | boolean | `true` | a consolidated card across the course's exams, not just one |
| `institute.progress_from_assessment` | boolean | `false` | when true, publishing a topic-linked exam marks that topic for each passing student through `CourseProgressService` with `ProgressSource::assessment` (§6.12) |
| `institute.certificate_next_number` | number | `1` | counter for the existing `certificate_prefix`, locked in-transaction |
| `institute.certificate_number_format` | text | `{PREFIX}{YY}{SEQ:5}` | token expansion, the `StudentNumberService` convention |
| `institute.certificate_number_sequence_scope` | select `global`\|`yearly`\|`branch_yearly` | `yearly` | |
| `institute.certificate_number_period` | text *(readonly)* | current period key | written by the service, never by a human |
| `institute.certificate_require_pass` | boolean | `true` | eligibility: every `isMajor()` exam of the course passed |
| `institute.certificate_require_min_attendance` | boolean | `true` | eligibility against the existing `attendance_minimum_percentage` |
| `institute.certificate_require_min_progress` | number | `100` | eligibility: `student_course_progress.completion_percentage >= this` |
| `institute.certificate_require_fee_cleared` | boolean | `true` | eligibility: no outstanding balance, asked of `StudentFeeService` |
| `institute.certificate_require_enrollment_completed` | boolean | `true` | eligibility: `EnrollmentStatus::completed` |
| `institute.certificate_grade_source` | select `final_exam`\|`best_exam`\|`weighted_average`\|`manual` | `weighted_average` | how §84's grade is resolved (§6.11) |
| `institute.certificate_issue_requires_approval` | boolean | `true` | adds `certificates.approve` to the issue step |
| `institute.certificate_verification_reveals` | multiselect(`student_name`, `father_name`, `course_name`, `batch_name`, `completion_date`, `grade`, `percentage`, `trainer_name`, `attendance_percentage`, `photo`) | `student_name, course_name, completion_date, grade` | **the whole of INV-21-3 in one setting** |
| `institute.certificate_verification_rate_limit_per_minute` | number | `20` | per IP, on the public endpoint |
| `institute.certificate_verification_log_retention_days` | number | `365` | `0` = for ever |
| `institute.certificate_footer_note` | textarea | *(empty)* | printed on every certificate unless the template overrides it |
| `institute.id_card_prefix` | text | `SIC-` | |
| `institute.id_card_next_number` | number | `1` | |
| `institute.id_card_validity_months` | number | `12` | `0` = no expiry |
| `institute.id_card_require_photo` | boolean | `true` | a card cannot be issued without a student photo |
| `institute.id_card_batch_print_max` | number | `100` | the batch-print ceiling (§6.14) |

### 5.2 New group `support` (Phase 22)

**[D-22-4]** One new `SettingsRegistry` group, label **"Support, Meetings & Messaging"**, icon
`lifebuoy`, description "Ticket numbering, departments, SLA, meeting defaults, messaging rules and
notification delivery", sort after `institute`, permission `settings.edit`. Phase 2's registry takes new
groups by design (`groups()` / `fields($group)`), so this is additive.

| group.key | type | default | Meaning |
|---|---|---|---|
| `support.ticket_prefix` | text | `TKT-` | §93 ticket number |
| `support.ticket_next_number` | number | `1` | counter, locked in-transaction |
| `support.ticket_default_department_id` | select *(departments)* | the default department | used when a panel form offers no choice |
| `support.ticket_default_priority` | select(`App\Enums\Priority`) | `medium` | the shared enum (F-5.7) |
| `support.ticket_auto_assign` | select(`TicketAssignStrategy`) | `least_open` | fallback when the department says `none` |
| `support.sla_enabled` | boolean | **`true`** | **the SLA master switch** (audit F-13.5, H3). When false: no clock is stamped, `tickets:sla-sweep` is a no-op, no breach badge or SLA card renders, `TicketSlaService::multiplier()` is never called, and the SLA dashboard tile set is hidden. Dropping SLA later is this flip, never a migration |
| `support.sla_first_response_minutes` | number | `240` | fallback when the department has none; read only while `sla_enabled` |
| `support.sla_resolution_minutes` | number | `2880` | fallback |
| `support.sla_pause_on_waiting` | boolean | `true` | INV-22-2 |
| `support.sla_business_hours_only` | boolean | `false` | when true, the clock advances only inside `contact.business_hours` |
| `support.ticket_auto_close_resolved_days` | number | `7` | `0` = never auto-close |
| `support.ticket_reopen_window_days` | number | `14` | after this only staff may reopen |
| `support.ticket_allow_client_create` | boolean | `true` | |
| `support.ticket_allow_student_create` | boolean | `true` | |
| `support.ticket_allow_teacher_create` | boolean | `true` | |
| `support.ticket_allow_collaborator_create` | boolean | `true` | |
| `support.ticket_attachment_max_mb` | number | `10` | bounded by `security.max_upload_mb` |
| `support.ticket_max_attachments` | number | `5` | per ticket and per reply |
| `support.meeting_default_duration_minutes` | number | `30` | |
| `support.meeting_default_reminder_minutes` | number | `30` | |
| `support.meeting_second_reminder_minutes` | number | `0` | `0` = one reminder only |
| `support.meeting_allow_external_participants` | boolean | `true` | §95 participants may include an outsider |
| `support.meeting_ics_enabled` | boolean | `true` | the `.ics` download |
| `support.meeting_room_clash_block` | boolean | `true` | a classroom clash is refused; without a room it is only a warning |
| `support.messaging_enabled` | boolean | `true` | master switch for §94 |
| `support.messaging_allowed_pairs` | multiselect(`ConversationScope`) | all six | **the §94 matrix, admin-tunable**; removing a pair silences existing threads of that pair (INV-22-4) |
| `support.messaging_student_can_start` | boolean | `true` | when false a student may reply but not open a thread |
| `support.messaging_client_can_start` | boolean | `true` | |
| `support.messaging_collaborator_can_start` | boolean | `true` | |
| `support.messaging_attachments_enabled` | boolean | `true` | |
| `support.messaging_attachment_max_mb` | number | `10` | |
| `support.messaging_rate_limit_per_minute` | number | `20` | per sender, enforced by middleware **and** in the service |
| `support.notifications_mail_enabled` | boolean | `false` | the §97 "mail channel ready" master switch; `false` until real SMTP exists (Q7) |
| `support.notification_digest_hour` | time | `08:00` | when `NotificationDigest::daily` sends |
| `support.notification_retention_days` | number | `180` | archived rows older than this are pruned; `0` = for ever |
| `support.notification_bell_page_size` | number | `10` | rows in the bell dropdown |
| `support.notification_mark_read_on_open` | boolean | `true` | opening a deep link marks that row read |

### 5.3 New group `reports` (Phase 23)

**[D-23-1]** One new group, label **"Reports & Search"**, icon `chart-bar`, sort last, permission
`settings.edit`.

| group.key | type | default | Meaning |
|---|---|---|---|
| `reports.default_date_preset` | select(`today`\|`yesterday`\|`week`\|`month`\|`year`) | `month` | §99's filter set; `custom` is always available but is never a default |
| `reports.sync_row_limit` | number | `5000` | above this a report is queued as a `report_exports` row instead of streamed inline. **`finance.report_sync_row_limit` continues to win for phase-13's four finance reports** (§13.2 records the convergence ask) |
| `reports.export_max_rows` | number | `200000` | hard ceiling; above it the request is refused with a "narrow your filters" message naming the row count |
| `reports.export_retention_days` | number | `7` | sets `report_exports.expires_at` |
| `reports.excel_enabled` | boolean | `false` | turns on `ExportFormat::Excel`; stays false until the package is installed |
| `reports.pdf_paper_size` | select `a4`\|`letter`\|`legal` | `a4` | |
| `reports.pdf_orientation` | select `portrait`\|`landscape` | `landscape` | wide report tables |
| `reports.cache_ttl_seconds` | number | `300` | the per-user, per-filter result cache; `0` = no cache |
| `reports.global_search_min_chars` | number | `2` | §108 |
| `reports.global_search_per_entity_limit` | number | `5` | rows per entity in the palette |
| `reports.global_search_entities` | multiselect(`SearchEntityType`) | all eleven | lets an admin narrow the palette without touching permissions |
| `reports.global_search_debounce_ms` | number | `250` | |
| `reports.activity_log_retention_days` | number | `0` | **`0` = never prune.** Deliberate: §110 wants an audit trail, and a financial log that self-deletes is not one |
| `reports.audit_sensitive_modules` | multiselect *(module slugs)* | `collaborator_commission_settings, collaborator_commissions, collaborator_payouts, collaborator_referrals, student_fees, fee_discounts, results, certificates, users, roles, settings, modules` | which modules' changes appear in the §107 audit trail by default |
| `reports.audit_show_financial_values` | boolean | `true` | when false, money old/new values need `view_financial` even inside the audit trail |

---

## 6. Services

Namespaces: `App\Services\Institute\` (19, 20, 21 institute behaviour), `App\Services\Files\` (the one
uploader and the one streamer), `App\Services\Support\` (22), `App\Services\Reporting\`,
`App\Services\Audit\`, `App\Services\Search\` (23). Controllers only orchestrate (`CLAUDE.md` §1.9). Every
method that writes more than one row runs in one `DB::transaction()`; every event is dispatched through
`DB::afterCommit()`.

### 6.1 `App\Services\Files\SecureFileService` - the only write path for a private file

One uploader for these five phases, built to the rules Phase 5's `ClientDocumentService::upload` already
established, so the system has two upload code paths in total (that one for client documents, this one for
everything here) rather than six.

| Method | Guarantees |
|---|---|
| `store(UploadedFile $file, FileTarget $target, FileRules $rules): StoredFile` | one transaction-safe write returning `{disk, path, original_name, extension, mime_type, size_bytes, checksum_sha256}`. **The full gate, in this order:** (1) size against `min($rules->maxMb, security.max_upload_mb)`; (2) extension against the **intersection** of `$rules->extensions` and `security.allowed_file_types` - a rule can narrow, never widen; (3) **MIME sniffed from content** (`finfo`) and required to be in `CourseResourceType::allowedMimes()` (or the rule's list) for the declared type; (4) the sniffed MIME must agree with the extension, or the upload is refused naming both; (5) **hard refusal** of `php phtml phar phps pht htaccess html htm svg xhtml shtml js mjs exe bat cmd sh com cgi pl py` and of **any double extension** (`cv.pdf.php`), regardless of the whitelist; (6) a zip is accepted as bytes and **never** inspected or expanded; (7) the name becomes `{ulid}.{extension}` (INV-19-2); (8) the disk is `private` unless the target declares `public` (only `print_templates.background_image_path` does); (9) sha256 computed in a stream, never by loading the file; (10) one `activity_log` row naming the path, size, MIME and target |
| `replace(StoredFile $old, UploadedFile $new, FileTarget, FileRules): StoredFile` | stores the new file first, swaps the column inside the caller's transaction, deletes the old bytes **after commit** - a failed swap never loses both files |
| `delete(StoredFile): void` | deletes bytes only; the caller decides whether the row goes. Called only from a `forceDeleted` observer or a prune command |
| `stream(StoredFile $f, StreamOptions $o): StreamedResponse` | the only streamer (§6.4 step 7): `Content-Type` from the **stored** `mime_type` (never sniffed at read time, never guessed from the URL), `Content-Length`, `Content-Disposition: attachment; filename="{sanitised original_name}"` - or `inline` only when `$o->inline` **and** the stored MIME is `application/pdf` or `image/*`, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`, `Cache-Control: private, no-store`, `Accept-Ranges: none`. A missing file is a 404 with a toast, never a disk error |
| `exists(StoredFile): bool` | used by the integrity command |

`FileRules` is a value object (`maxMb`, `extensions`, `mimes`, `disk`, `pathPattern`); `FileTarget` carries
the owning model and the path tokens. **Nothing in phases 19-23 calls `Storage::put`, `store()`,
`storeAs()`, `Storage::url()` or `temporaryUrl()` directly** - a static scan test (PH23-46) asserts it.

### 6.2 Why there is no signed-URL shortcut

A signed URL moves the authorisation decision to the moment the link was **minted**, not the moment the
bytes are **served** - so a student who is suspended, un-enrolled, fee-blocked or whose material was
unpublished five minutes ago would still download. **[D-19-4] Every private file is served by a controller
action that re-runs the full check against live state, and no signed URL is ever issued for one.** Signed
URLs remain correct for what Phase 3 and Phase 4 use them for (previewing a draft page, a one-shot public
confirmation), because no per-viewer entitlement is involved there.

### 6.3 File storage layout - binding

Disk `private` is `storage/app/private` (Laravel 12's default private root), never published by
`storage:link`. Disk `public` is used by exactly one row in this table.

| Content | Disk | Path pattern | Served by | Who may read |
|---|---|---|---|---|
| Course material file | `private` | `institute/courses/{course_id}/materials/{YYYY}/{MM}/{ulid}.{ext}` | `MaterialAccessService::stream()` | staff with `course_materials.download` (branch-scoped); the teacher of a targeted batch; a student whose target resolves (INV-19-3) |
| Assignment brief | `private` | `institute/assignments/{assignment_id}/brief/{ulid}.{ext}` | `AssignmentService::streamBrief()` | staff with `assignments.download`; the batch's teacher; a student of that batch while the assignment is `published` or `closed` |
| Assignment submission file | `private` | `institute/assignments/{assignment_id}/submissions/{student_id}/{attempt_no}/{ulid}.{ext}` | `AssignmentSubmissionService::streamFile()` | the owning student; the batch's teacher; staff with `assignment_submissions.download`. **Never another student** |
| Assignment feedback file | `private` | `institute/assignments/{assignment_id}/feedback/{submission_id}/{ulid}.{ext}` | same | the owning student **only after `marks_released_at`**; the teacher; staff |
| Certificate PDF | `private` | `institute/certificates/{YYYY}/{certificate_number}.pdf` | `CertificateService::streamPdf()` | staff with `certificates.print`; the owning student. **The public verification page never streams it** (§6.13) |
| ID card PDF | `private` | `institute/id-cards/{YYYY}/{card_number}.pdf` | `StudentIdCardService::streamPdf()` | staff with `student_id_cards.print`; the owning student |
| ID card photo snapshot | `private` | `institute/id-cards/photos/{student_id}/{ulid}.{ext}` | rendered **into** the PDF as a base64 data URI, never served as a URL | nobody directly |
| Result card PDF | *(not stored)* | streamed on demand | `ResultCardBuilder` + dompdf | per §9 |
| Print-template background | `public` | `templates/{type}/{ulid}.{ext}` | the web server | everyone - it is a border graphic with no PII |
| Print-template preview | `private` | `templates/previews/{template_id}.pdf` | `PrintTemplateService::streamPreview()` | holders of `print_templates.view` |
| Ticket / reply attachment | `private` | `support/tickets/{ticket_id}/{ulid}.{ext}` | the `attachments` download route (Phase 6) + `TicketPolicy` | the requester, the assignee, staff with `support_tickets.download`; **never a requester of another ticket** |
| Message attachment | `private` | `support/conversations/{conversation_id}/{ulid}.{ext}` | the `attachments` download route + `ConversationPolicy` | live participants of that conversation only |
| Meeting attachment | `private` | `support/meetings/{meeting_id}/{ulid}.{ext}` | the `attachments` download route + `MeetingPolicy` | participants and holders of `meetings.download` |
| Report export | `private` | `exports/reports/{YYYY-MM}/{uuid}.{ext}` | `ReportExportService::download()` | **only `report_exports.requested_by`**, and only while the report permission is still held and `expires_at` has not passed |
| Material / submission import CSV error report | `private` | `exports/errors/{YYYY-MM}/{uuid}.csv` | the same download action | the requester |

Directory creation is recursive with mode 0755; no path is ever built from user input (the `{ulid}` and the
ids come from the database, the extension from the sniffed MIME), so path traversal has no surface.

### 6.4 The download permission chain - run for **every** private file, in this order

| # | Step | Detail |
|---|---|---|
| 1 | Route middleware | `auth`, `active`, the panel's `panel:*`, `module:{slug}`, and `can:{slug}.download` (admin) **or** `can:{panel}_portal.{x}_download` (portal). A disabled module 403s here, Super Admin included |
| 2 | Route-model binding + global scope | the model is resolved **through** its scope (`BelongsToAuthenticatedStudent`, `TeacherScope`, `BelongsToAuthenticatedCollaborator`, `ClientContext`, branch scope), so another owner's id simply does not resolve → **404** |
| 3 | Policy `download()` | re-asserts the entitlement against live state: status published / issued, availability window, enrollment active or inside the post-batch grace, `marks_released_at` for feedback, `visible_to_client` for an attachment, `requested_by` for an export |
| 4 | Service re-read | the service re-reads the row (`lockForUpdate()` is not needed, a fresh `find()` is) so a stale bound model from a cached page cannot be used |
| 5 | Settings re-check | fee-block, rate limit, `support.messaging_enabled`, `reports.excel_enabled` - whatever setting gates that file |
| 6 | Log **before** the stream | `course_material_downloads` row (materials) or an `activity_log` row (everything else), with actor, panel, IP, device and the path. A stream that starts unlogged is a defect (INV-19-4) |
| 7 | `SecureFileService::stream()` | the hardened headers of §6.1. File missing → 404 |

The same seven steps apply to a PDF the system generated (certificate, ID card, result card, export) - the
only difference is that steps 4-5 ask the document's own status, not a disk path.

### 6.5 `CourseMaterialService` and `MaterialAccessService` (§79)

**`CourseMaterialService`**

| Method | Guarantees |
|---|---|
| `create(CourseMaterialData $d, ?UploadedFile $f): CourseMaterial` | one transaction: `SecureFileService::store()` when a file is given (type must satisfy `CourseResourceType::isFile()`), `external_url` validated as `http(s)` otherwise; `branch_id` copied from the batch target, else from the course; `status = draft`; targets written by `setTargets()`; caches recounted; fires `MaterialCreated` |
| `update(CourseMaterial $m, CourseMaterialData $d): CourseMaterial` | never touches `file_path`; a replacement goes through `replaceFile()` |
| `replaceFile(CourseMaterial $m, UploadedFile $f): CourseMaterial` | `SecureFileService::replace()`; keeps the download log (it is a log of opens, not of bytes) and writes an activity row naming both checksums |
| `setTargets(CourseMaterial $m, array $targets): TargetResult` | one transaction; asserts every batch target's `course_id` and every student target's active enrollment resolve to the material's course; upserts on `uq_cmt`; deletes removed targets with one activity row each; recomputes `audience_scope` as the **broadest** remaining `MaterialTargetType::breadth()`; returns the added / removed counts |
| `publish(CourseMaterial $m): CourseMaterial` | §2.28.1; stamps `published_at`; queues `NotifyMaterialAudience` for targets whose `notified_at` is null when `institute.material_notify_on_publish` |
| `unpublish / archive / restoreToPublished(…, string $reason)` | reason mandatory, logged with old and new status |
| `visibleTo(User $u): Builder` | the one query every panel uses (§6.6) |
| `recountCaches(CourseMaterial $m): void` | `targets_count`, `view_count`, `download_count`, `unique_students_count` by COUNT under a row lock - never incremented (the Phase 6 INV-P6 discipline) |
| `engagement(CourseMaterial $m, DateRange $r): EngagementReport` | per-student opened / not-opened, first and last open, counts; the data behind §8.3 and the Phase 23 material report |

**`MaterialAccessService`**

| Method | Guarantees |
|---|---|
| `grantFor(CourseMaterial $m, User $u): MaterialGrant` | returns `{allowed, reason, action, inline}`; the single implementation of INV-19-3 plus the staff and teacher paths. `reason` is a named enum-like string (`not_published`, `outside_window`, `not_targeted`, `enrollment_expired`, `fee_blocked`, `module_disabled`) used by the UI and the tests |
| `stream(CourseMaterial $m, User $u, MaterialAccessAction $a): StreamedResponse` | runs `grantFor()`, logs (§6.4 step 6), then `SecureFileService::stream()` with `inline` only when `is_downloadable = false` and the MIME allows it |
| `openLink(CourseMaterial $m, User $u): RedirectResponse` | for `type = link`: the same grant and log, then a 302 to `external_url` with `Referrer-Policy: no-referrer` |
| `logAccess(...)` | the append-only write; never throws into the response path (a log failure is logged, the stream still serves - and the test asserts the row is normally present) |

### 6.6 The student material query, written once

```
SELECT m.* FROM course_materials m
 JOIN course_material_targets t ON t.course_material_id = m.id
WHERE m.status = 'published'
  AND (m.available_from  IS NULL OR m.available_from  <= NOW())
  AND (m.available_until IS NULL OR m.available_until >= NOW())
  AND (
        (t.target_type = 'course'  AND t.target_course_id  IN (:enrolled_course_ids))
     OR (t.target_type = 'batch'   AND t.target_batch_id   IN (:enrolled_batch_ids))
     OR (t.target_type = 'student' AND t.target_student_id  = :student_id)
      )
  AND m.course_id IN (:enrolled_course_ids)          -- belt and braces: a mis-targeted row still cannot leak
GROUP BY m.id
```
`:enrolled_course_ids` / `:enrolled_batch_ids` come from the student's enrollments whose status
`countsInAttendance()` **or** whose batch ended less than
`institute.material_visible_after_batch_end_days` ago. The teacher variant replaces the three target clauses
with `TeacherScope::batchIds($teacher)` and adds `m.created_by = auth()->id()` **OR** the batch match, so a
teacher sees material shared with their batches plus their own drafts. The staff variant is the branch scope.

### 6.7 `AssignmentService` (§80)

| Method | Guarantees |
|---|---|
| `create(AssignmentData $d, ?UploadedFile $brief): Assignment` | one transaction; prefills from `course_topic_assignments` when `course_topic_assignment_id` is given (title, description, instructions, `total_marks` ← `estimated_marks`); asserts the topic belongs to the batch's course; `branch_id` from the batch; `status = draft`; `expected_count` from `BatchEnrollmentService::roster($batch, $deadline_at->toDateString())` |
| `update / replaceBrief` | refuses to change `total_marks`, `deadline_at` or `submission_type` once a graded submission exists unless the caller holds `assignments.edit` and supplies a reason; then queues `RecomputeAssignmentCaches` |
| `publish(Assignment $a): Assignment` | §2.28.2; refuses when `deadline_at` is in the past, when `total_marks <= 0`, or when the batch is `cancelled`; fires `AssignmentPublished` |
| `close(Assignment $a): CloseResult` | no new submissions; calls `AssignmentSubmissionService::markMissed()`; recounts caches |
| `reopen(Assignment $a, string $reason)` | clears `missed` rows (with one activity row) and re-publishes |
| `duplicateToBatches(Assignment $a, array $batchIds, array $deadlines): Collection` | one draft per batch, same brief file **referenced, not re-uploaded** (the new row points at the same path; `forceDelete` of one never removes bytes another row still references - asserted by PH19-07) |
| `streamBrief(Assignment $a, User $u)` | §6.4 |
| `recountCaches(Assignment $a): void` | the six counts + `average_marks` / `highest_marks` by aggregate under a row lock, bcmath for the average |
| `statistics(Assignment $a): AssignmentStats` | submitted / late / graded / missed / average / distribution - the one definition Phase 23's report and the teacher screen both read |

### 6.8 `AssignmentSubmissionService` (§80) and `AssignmentGradeCalculator`

| Method | Guarantees |
|---|---|
| `draft(Assignment $a, Student $s): AssignmentSubmission` | creates or returns the student's `draft` row; asserts an **active enrollment in that batch** (INV-19-5) and `AssignmentStatus::acceptsSubmissions()` |
| `submit(AssignmentSubmission $d, SubmissionData $data, array $files): AssignmentSubmission` | one transaction. Asserts: the assignment accepts submissions; `submission_type` satisfied (`requiresFile` / `requiresText`); file count `<= max_files`; each file through `SecureFileService::store()` with the assignment's narrowed extension list; **lateness decided now** - `is_late = now() > deadline_at`, `minutes_late` the whole-minute difference (INV-19-7); refused outright when late and `late_submission_allowed = false`, or when `late_cutoff_at` has passed; `total_marks` snapshotted; `status = submitted`; recounts the assignment; fires `AssignmentSubmitted` |
| `resubmit(AssignmentSubmission $live, SubmissionData, array $files): AssignmentSubmission` | refuses when `allow_resubmission = false` or `attempt_no >= max_attempts`; inserts a **new row** with `attempt_no + 1` and sets the old row `superseded` + `superseded_by_id` in the same transaction (INV-19-5); the new row re-decides lateness |
| `withdraw(AssignmentSubmission $d): void` | `draft` only; deletes the row and its files |
| `grade(AssignmentSubmission $s, GradeData $g, User $actor): AssignmentSubmission` | one transaction. Asserts `status` is live and submitted; **`obtained_marks <= total_marks`** (Form Request, service assertion and `chk_asub_marks` - three layers, INV-19-6); computes `penalty_marks`, `percentage` and `is_passed` through `AssignmentGradeCalculator`; stores `feedback` and an optional feedback file; stamps `graded_by` / `graded_at`; sets `marks_released_at` now when `marks_visible_to_students`, else leaves it null; `status = graded`; recounts; fires `AssignmentGraded` |
| `returnForRework(AssignmentSubmission $s, string $feedback, User $actor)` | `status = returned`; notifies the student |
| `releaseMarks(Assignment $a): int` | stamps `marks_released_at` on every graded row at once, for a teacher who marked privately |
| `amend(AssignmentSubmission $s, GradeData $g, string $reason, User $actor)` | after release: the same ceiling check, plus `amended_at` / `amended_by` / `amendment_reason` and an activity row with old and new marks |
| `markMissed(Assignment $a): int` | one `missed` row per roster student with no live submission; `marked_via`-style system provenance in the activity log; idempotent |
| `bulkGrade(Assignment $a, array $rows, User $actor): BulkGradeResult` | one transaction over the grading grid; **validates every row before writing any** (the `AttendanceService::import` discipline); returns per-row errors |
| `streamFile(AssignmentSubmissionFile|string $f, User $u)` | §6.4; feedback files additionally require `marks_released_at` for the student path |

**`AssignmentGradeCalculator`** - pure, dependency-free, bcmath (intermediate scale 6, final half-up at 2):

```
penalty        = late_penalty_percentage = 0 ? 0.00 : Money::percentage(total_marks, late_penalty_percentage)
penalty_marks  = is_late ? min(penalty, obtained_marks) : 0.00          // a penalty can never create a negative mark
final_marks    = max(obtained_marks - penalty_marks, 0.00)              // also the generated column, so PHP and SQL agree
percentage     = total_marks = 0 ? null : round(final_marks * 100 / total_marks, 2)   // stored in a decimal(8,4) column (F-7.1); the calculator's half-up-at-2 contract is unchanged
is_passed      = passing_marks IS NULL ? null : (final_marks >= passing_marks)
```
PH19-33 asserts the PHP result and the generated column are byte-identical for 40 randomised cases.

### 6.9 `GradeScaleService` (§82)

| Method | Guarantees |
|---|---|
| `create / update(GradeScaleData, array $bands): GradeScale` | one transaction; `validateBands()` first - nothing is written if it fails |
| `validateBands(array $bands): void` | throws `InvalidGradeScale` naming the offending pair unless: at least two bands; every `min <= max`; sorted by `min`, each band's `min` equals the previous band's `max` **plus 0.01** (no gap, no overlap); the lowest `min = 0.00`; the highest `max = 100.00`; grades unique within the scale; at most one transition from `is_pass = false` to `is_pass = true` when read in ascending order (a scale cannot fail 60 % and pass 50 %) |
| `setDefault(GradeScale $s): void` | one transaction; clears the previous default; `uq_gs_default` is the backstop |
| `resolveFor(Exam $e): GradeScale` | `$e->grade_scale_id` → `institute.default_grade_scale_id` → the `is_default` row. Throws `NoGradeScale` rather than silently grading with an invented ladder |
| `bandFor(GradeScale $s, string $percentage): ?GradeScaleBand` | one indexed query, `min <= p <= max`; bcmath comparison, never a float `<=` |
| `deactivate(GradeScale $s, string $reason)` | refuses while it is the default; never deletes a referenced scale (INV-20-4) |

`GradeScaleSeeder` seeds one scale, `DEFAULT`, idempotently: `A+` 90-100, `A` 80-89.99, `B` 70-79.99,
`C` 60-69.99, `D` 50-59.99, `E` 40-49.99 (pass), `F` 0-39.99 (fail), `pass_percentage = 40.00`.

### 6.10 `ExamService`, `ExamResultService`, `ResultCalculator`, `ExamStatisticsService` (§81, §82)

**`ExamService`**

| Method | Guarantees |
|---|---|
| `create / update(ExamData): Exam` | asserts the batch is not `cancelled`, the topic belongs to the batch's course, `passing_marks <= total_marks`, and - when a classroom or a time is given - `ScheduleClashDetector::check(new SlotCandidate(teacherId: …, classroomId: …, batchId: …, startsAt: …, endsAt: …, ignoreType: 'exam', ignoreId: $exam?->id))` returns a `ClashReport` with `$clean === true`; a dirty report is a 422 naming every conflict. **One generic call** (phase-14-17 §6.7, D47) - never three subject-specific checks in sequence |
| `schedule(Exam $e): Exam` | §2.28.4; recounts `expected_count` from the roster on `scheduled_date`; fires `ExamScheduled` → §97 "exam scheduled" to the batch's students and teacher |
| `markConducted / markOngoing / cancel(Exam, ?string $reason)` | cancel requires a reason and refuses once any result row exists |
| `reschedule(Exam $e, ExamData $d, string $reason)` | clash-checked again; notifies the batch; refused after publication |
| `recountCaches(Exam $e): void` | the nine caches by aggregate under a row lock, bcmath averages |

**`ExamResultService`** - the batch-wide entry engine of §20

| Method | Guarantees |
|---|---|
| `openSheet(Exam $e): ResultSheet` | the roster for `scheduled_date` (`BatchEnrollmentService::roster`) left-joined to existing rows, plus the resolved grade scale, `total_marks`, `passing_marks` and each student's previous-exam marks for context. Read-only; writes nothing |
| `saveSheet(Exam $e, array $rows, User $actor): SheetResult` | **one transaction.** (1) `ExamStatus::acceptsResultEntry()` or throw; (2) every `student_id` must be on the roster - one stranger rejects the **whole sheet** naming it (INV-20-6); (3) per row: `attendance_status` valid, `obtained_marks` present iff `appeared`, `0 <= marks <= exam.total_marks` - **validated for every row before any write**; (4) `total_marks`, `grade_scale_id` snapshotted per row; (5) `percentage`, `grade`, `grade_point`, `is_passed` from `ResultCalculator` (INV-20-2); (6) `upsert` on `uq_er_exam_student` so a double submit updates rather than duplicates; (7) `entered_by` / `entered_at`; (8) exam → `marking`; (9) `recountCaches`; (10) returns `{saved, updated, errors[]}` with a row index per error |
| `verify(Exam $e, User $actor): Exam` | requires `results.approve` and `actor->id <> entered_by` of every row when `institute.result_publish_requires_verification`; stamps `results_verified_at` / `_by` and each row's `verified_*` |
| `publish(Exam $e, User $actor): PublishResult` | refuses unless every roster student has a row and (when required) verification has happened; computes `position_in_batch` via `ResultCalculator::rank()`; stamps `published_at` on exam and rows; fires `ResultsPublished` → §97 "result published" to each student; when `institute.progress_from_assessment` and `course_topic_id` is set, calls `CourseProgressService` per passing student with `ProgressSource::assessment` (§6.12) |
| `unpublish(Exam $e, string $reason, User $actor)` | clears `published_at` on exam and rows, returns the exam to `marking`, writes one activity row with the reason, and **notifies nobody** (a retraction is handled by a human) |
| `amend(ExamResult $r, ResultAmendData $d, string $reason, User $actor): ExamResult` | the ceiling check again; `amended_*` stamped; recalculates that row only; recounts the exam; activity row with old and new marks, percentage and grade (INV-20-5) |
| `import(Exam $e, UploadedFile $csv): ImportReport` | `student_code` or `registration_number` + marks + optional remarks; validates every row before writing any; returns per-row errors; one transaction |
| `sheetCsvTemplate(Exam $e): StreamedResponse` | the import template, pre-filled with the roster and a blank marks column |

**`ResultCalculator`** - pure, bcmath (intermediate scale 6, final half-up at 2):

```
percentage = total_marks = 0 ? null : round(obtained_marks * 100 / total_marks, 2)
band       = GradeScaleService::bandFor(scale, percentage)
grade      = band?->grade ;  grade_point = band?->grade_point
is_passed  = attendance_status->countsAsFail() ? false
           : (exam.passing_marks > 0 ? obtained_marks >= exam.passing_marks : band?->is_pass ?? false)
rank       : order by obtained_marks DESC among attendance_status = appeared;
             equal marks share a rank; the next distinct mark skips to 1 + count(better); absent rows get null
```
**[D-20-2] The exam's `passing_marks` wins over the band's `is_pass`** when both exist, because §81 makes
passing marks an exam-level field and a coordinator who types 33 / 100 means 33.

**`ExamStatisticsService`** - the one definition every screen, chart and Phase 23 report reads

| Method | Returns |
|---|---|
| `forExam(Exam): ExamStats` | appeared, absent, passed, failed, pass rate, highest, lowest, average, average percentage, grade distribution, the five-band histogram |
| `forBatch(Batch, ?ExamType): BatchExamStats` | one row per exam plus the batch's aggregate and each student's weighted aggregate |
| `forCourse(Course, DateRange): CourseExamStats` | pass-rate trend by exam type and month |
| `forStudent(StudentBatchEnrollment): StudentExamStats` | every published result, the weighted aggregate, the resolved overall grade - **the input `CertificateService` uses**, so the certificate grade and the result card can never disagree |
| `aggregateFor(StudentBatchEnrollment, string $mode): AggregateGrade` | `final_exam` / `best_exam` / `weighted_average` per `institute.certificate_grade_source`; `weighted_average` uses `exams.weight_percentage` (equal weight when all are null) over `isMajor()` exams, bcmath |

### 6.11 `ResultCardBuilder` (§82 printable result card)

| Method | Returns / rules |
|---|---|
| `forResult(ExamResult $r, ResultCardOptions $o): ResultCardData` | a single-exam card: institute header (company / branding settings), student block (name, student code, registration number, father name, photo when the template asks), course / batch / teacher, exam name + type + date, `obtained_marks` / `total_marks` / `percentage` / `grade` / pass-fail / `remarks`, position when `institute.result_card_show_position`, attendance when `institute.result_card_show_attendance`, the grade-scale legend, signatories from the template |
| `forEnrollment(StudentBatchEnrollment $e, ResultCardOptions $o): ResultCardData` | the consolidated card (`institute.result_card_show_all_exams`): one line per published exam, the weighted aggregate and the overall grade from `ExamStatisticsService` |
| `forBatch(Batch $b, ?Exam $e): Collection<ResultCardData>` | batch printing: one card per student, one PDF with a page break between cards, bounded by the same ceiling as ID cards |
| `ResultCardOptions::studentCopy()` | the student-panel variant: **only published results**, no internal remarks field flagged `internal`, no other student's marks, no position when the setting is off. It cannot be overridden by a query parameter (the Phase 18 `studentCopy()` precedent) |

Rendered through `print_templates` of type `result_card` (or a built-in fallback Blade when none is default),
`layouts/print.blade.php`, A4, `@media print` page breaks; dompdf for the PDF. **Reads only stored snapshot
columns** (`total_marks`, `grade`, `percentage` on the result row), never a live recomputation, so a card
reprinted a year later is identical (the `InvoicePdfService` discipline).

### 6.12 The §83 progress hook - `ProgressSource::assessment`

phase-14-17 §3 reserved `ProgressSource::assessment` for Phase 20 and nobody writes it yet. Phase 20 writes
it in exactly one place: `ExamResultService::publish()`, when **all** of these hold - `institute.progress_from_assessment`
is true, the exam has a `course_topic_id`, and the student's result `is_passed`. It then calls
`CourseProgressService::markTopicForStudent($enrollment, $topic, ['status' => completed, 'source' => assessment, 'percentage' => 100])`.
**Phase 20 never writes a progress table directly** and never overrides a row whose `source = manual`
(phase-14-17 §6.10 already guarantees that). §13.1 asks Phase 17 for the `source` parameter on that
signature; until it exists the feature stays off behind its setting, and the setting's help text says so.

### 6.13 `PrintTemplateService` and `App\Support\PrintTokenRegistry` (§84, §85)

**`PrintTokenRegistry`** - the same pattern as `PermissionRegistry` / `SettingsRegistry`: pure arrays, no DB.

```
tokens(PrintTemplateType $type): [ token => ['label', 'example', 'group', 'formatter'] ]
all(): [ type => tokens ]
validate(PrintTemplateType $type, string $html): array   // returns the unknown tokens found
resolve(PrintTemplateType $type, Model $document): array // token => already-formatted string
```

| Type | Tokens (exact list) |
|---|---|
`certificate` | `{certificate_number}` `{verification_code}` `{verification_url}` `{qr}` `{student_name}` `{father_name}` `{student_code}` `{registration_number}` `{course_name}` `{batch_name}` `{trainer_name}` `{branch_name}` `{course_start_date}` `{completion_date}` `{issued_on}` `{grade}` `{grade_point}` `{percentage}` `{attendance_percentage}` `{company_name}` `{company_logo}` `{footer_note}` `{signatory_1_name}` `{signatory_1_title}` `{signatory_1_image}` `{signatory_2_*}` `{signatory_3_*}` |
| `student_id_card` | `{card_number}` `{verification_code}` `{verification_url}` `{qr}` `{student_name}` `{father_name}` `{student_code}` `{registration_number}` `{course_name}` `{batch_name}` `{joining_date}` `{issued_on}` `{valid_until}` `{guardian_phone}` `{photo}` `{company_name}` `{company_logo}` `{branch_name}` `{branch_phone}` `{branch_address}` |
| `result_card` | `{student_name}` `{student_code}` `{registration_number}` `{father_name}` `{course_name}` `{batch_name}` `{teacher_name}` `{exam_name}` `{exam_type}` `{exam_date}` `{obtained_marks}` `{total_marks}` `{percentage}` `{grade}` `{grade_point}` `{result_status}` `{position}` `{remarks}` `{attendance_percentage}` `{results_table}` `{aggregate_percentage}` `{aggregate_grade}` `{grade_scale_legend}` `{company_name}` `{company_logo}` `{issued_on}` |

Every token is resolved **from the document's snapshot columns** through `Format` (`money()`, `app_date()`),
so a date format change restyles every future print and no past print is altered. `{qr}` and `{photo}`
resolve to base64 `data:` URIs, so dompdf never makes a network request (and the CDN allow-list of the admin
shell is irrelevant to a PDF).

**`PrintTemplateService`**

| Method | Guarantees |
|---|---|
| `create / update(PrintTemplateData): PrintTemplate` | `sanitize()` first; `tokens_used` extracted and unknown tokens returned as validation **warnings** naming each one; editing a template with issued documents requires a reason and logs a diff |
| `sanitize(string $html): string` | allows a layout subset (`div span p h1-h6 table thead tbody tr td th ul ol li img br hr strong em small b i sub sup` + `style`, `class`, `src` for `data:`/relative, `width`, `height`, `align`) and **strips**: `script`, `iframe`, `object`, `embed`, `link`, `meta`, `form`, `input`, every `on*` attribute, `javascript:` / `vbscript:` / external `url()`, every Blade construct (`{{`, `{!!`, `@php`, `@include`, `@extends`, `<?php`). CSS is sanitised for `@import`, `expression(` and external `url()`. **It is a thin wrapper over Phase 3's `App\Support\RichText::sanitize()`** (`mews/purifier`) configured with this wider tag set - the system's single sanitiser (D25, audit F-2.5). It declares no `HtmlSanitizer` and no second purifier profile of its own, and `render()` passes the stored HTML through `RichText::sanitize()` **again** before output, so a row written directly into the database cannot reach a print |
| `render(PrintTemplate $t, Model $document): string` | **token replacement only** - `str_replace` over the resolved map, with every value HTML-escaped unless the token is declared `raw` (`{qr}`, `{photo}`, `{company_logo}`, `{signatory_*_image}`, `{results_table}`, `{grade_scale_legend}` - each of which the service itself builds). Unknown tokens become empty strings. **Never Blade, never `eval`** (INV-21-5) |
| `toPdf(PrintTemplate $t, Model $document): string` | dompdf over `render()` wrapped in `layouts/print`, paper from the template, `isRemoteEnabled = false` |
| `preview(PrintTemplate $t): string` | renders with `PrintTokenRegistry` example values - **never a real student's data** |
| `setDefault / deactivate / duplicate` | one default per (type, branch); a used template is deactivated, never deleted |

A `PrintTemplateSeeder` seeds one default per type, idempotently, using only the tokens above.

### 6.14 `CertificateEligibilityService`, `CertificateService`, `CertificateVerificationService`, `QrCodeService` (§84)

**`CertificateEligibilityService`**

`check(StudentBatchEnrollment $e): EligibilityReport` returns `{eligible, rules: [{key, required, actual, passed, message}]}`
over exactly these rules, each switched by its setting, each reading the owning service - never its own SQL:

| Rule key | Source | Condition |
|---|---|---|
| `certificate_available` | `courses.certificate_available` | must be true - always checked, not a setting |
| `enrollment_completed` | `student_batch_enrollments.status` | `EnrollmentStatus::completed` when `institute.certificate_require_enrollment_completed` |
| `min_attendance` | `student_batch_enrollments.attendance_percentage` (the `AttendanceService` cache) | `>= institute.attendance_minimum_percentage` when `certificate_require_min_attendance` |
| `min_progress` | `student_course_progress.completion_percentage` | `>= institute.certificate_require_min_progress` when set |
| `exams_passed` | `ExamStatisticsService::forStudent()` | every `isMajor()` published exam `is_passed` when `certificate_require_pass` |
| `fee_cleared` | **`StudentFeeService`** (never a local SUM - INV-23-1) | no outstanding balance on the admission when `certificate_require_fee_cleared` |
| `no_live_certificate` | `uq_ce_live` | no `issued` certificate for this enrollment |

A failing rule is shown on the screen with its actual value, so "why can't I issue this?" is answered by the
UI, not by a developer. `certificates.approve` holders see an **override** control that records
`eligibility_snapshot` with the failed rules plus a mandatory reason - the override is audited, never silent.

**`CertificateService`**

| Method | Guarantees |
|---|---|
| `draft(StudentBatchEnrollment $e, CertificateData $d): Certificate` | runs eligibility (stores the report in `eligibility_snapshot`); resolves the grade through `ExamStatisticsService::aggregateFor()` per `institute.certificate_grade_source` (or takes the typed grade when `manual`); writes every snapshot column; `status = draft`, **no number yet** |
| `issue(Certificate $c, User $actor): Certificate` | **one transaction**: re-runs eligibility unless an override is recorded; `DocumentNumberService::next('institute.certificate_prefix', 'institute.certificate_next_number')` with the format / scope keys; generates `verification_code` (16 chars, `random_bytes`, Crockford-style alphabet with I/L/O/U removed, retried once on a 1062); builds `qr_payload` from `institute.certificate_verification_url` **snapshotted now**; `issued_on`, `issued_by`, `status = issued`; queues `GenerateCertificatePdf`; fires `CertificateIssued` (§97) |
| `bulkIssue(Batch $b, array $enrollmentIds, User $actor): BulkIssueResult` | per-enrollment transaction (one failure never blocks the rest), bounded by `institute.id_card_batch_print_max`'s sibling `reports.sync_row_limit`-style guard of 200 per request; returns issued / skipped / failed with the eligibility reason for each skip |
| `revoke(Certificate $c, string $reason, User $actor)` | `status = revoked`, `revoked_at/_by`, reason stored **and published on the verification page**; notifies the student (`certificate.revoked`, mandatory); never deletes |
| `reissue(Certificate $c, string $reason, User $actor): Certificate` | requires `$c` to be `revoked`; creates a new `draft` with `reissue_of_id` and fresh snapshots |
| `regeneratePdf(Certificate $c): void` | re-renders from the stored snapshots and the stored template; a template edit therefore **cannot** change the content, only the layout - and the activity row says so |
| `streamPdf(Certificate $c, User $u)` | §6.4; generates on demand if `pdf_path` is null |
| `markPrinted(Certificate $c, User $u)` | `print_count++`, `last_printed_at/_by`, activity row; the printed sheet carries "Reprint #n" when `print_count > 1` |
| `register(CertificateFilters, DateRange): Paginator` | the §99 certificate report's only query source |

**`CertificateVerificationService`** (the public side)

| Method | Guarantees |
|---|---|
| `verify(string $code, RequestContext $ctx): VerificationOutcome` | (1) normalise (upper-case, strip spaces and dashes); (2) rate-limit per IP from `institute.certificate_verification_rate_limit_per_minute` using Laravel's `RateLimiter` - on exceed, log `throttled` and return it **without touching the database lookup**; (3) one indexed lookup on `verification_code`, then `hash_equals()` on the stored code (constant-time, so timing cannot distinguish a near-miss); (4) also try `student_id_cards.verification_code` when the certificate misses, so one QR endpoint serves both; (5) map to `valid` / `revoked` / `not_public` / `not_found`; (6) **always** write a `certificate_verifications` row; (7) increment `verification_count` / `last_verified_at` on a hit; (8) return `publicPayload()` |
| `publicPayload(Certificate $c): array` | **only** the keys named in `institute.certificate_verification_reveals`, plus `certificate_number`, `status`, `issued_on`, and - when revoked - `revoked_at` and `revocation_reason`. Built by whitelist (`array_intersect_key`), never by exclusion, so a new column can never leak by default (INV-21-3). No query parameter, header or locale changes the set |
| `payloadForCard(StudentIdCard $c): array` | name, student code, course, batch, `valid_until`, status. Never a phone, address or guardian |

**`QrCodeService`** (wraps `simplesoftwareio/simple-qrcode`, installed in Phase 21 per `DEVELOPMENT_LOG.md` §2)

`svg(string $payload, int $sizePx): string`, `pngDataUri(string $payload, int $sizePx): string`,
`forCertificate(Certificate): string` / `forIdCard(StudentIdCard): string` - each reading the row's
**snapshotted** `qr_payload`, never rebuilding the URL from current settings (INV-21-4). Error correction
level `M`, quiet zone 2 modules, minimum 25 mm at 300 dpi so a phone camera actually resolves it.

### 6.15 `StudentIdCardService` (§85)

| Method | Guarantees |
|---|---|
| `issue(Student $s, IdCardData $d, User $actor): StudentIdCard` | one transaction; refuses without a photo when `institute.id_card_require_photo`; copies the photo through `SecureFileService` into the card's own path (so the card is frozen); number from `DocumentNumberService`; `verification_code` as for certificates; `valid_until` from `institute.id_card_validity_months`; snapshots; `status = active`; `uq_sic_live` guarantees the single active card; queues `GenerateIdCardPdf`; fires `IdCardIssued` |
| `bulkIssue(Batch|array $students, User $actor): BulkIssueResult` | one transaction per student; skips a student who already holds an active card (reported, not failed) |
| `replace(StudentIdCard $c, string $reason, User $actor): StudentIdCard` | marks the old row `lost` / `damaged` / `replaced`, issues a new row with `replacement_of_id` |
| `revoke / expire(…)` | reason mandatory for revoke; `idcards:expire` does the date-driven one |
| `batchPrint(array $cardIds, User $actor): StreamedResponse` | **§85 batch printing**: bounded by `institute.id_card_batch_print_max` (refused above it, naming the limit); renders each card through `PrintTemplateService`, lays them out on the template's sheet with crop marks, one dompdf document; increments every card's `print_count`; writes **one** activity row naming the count and the filter used |
| `streamPdf(StudentIdCard $c, User $u)` | §6.4 |

### 6.16 `TicketService`, `TicketSlaService`, `TicketAssignmentService` (§93)

**`TicketService`**

| Method | Guarantees |
|---|---|
| `create(TicketData $d, User $requester): SupportTicket` | **one transaction.** (1) the department must be active and its `allowed_panels` must contain the requester's panel, and the matching `support.ticket_allow_*_create` must be true - otherwise a 403 naming the reason; (2) **the subject FKs are derived**, never accepted: `client_id` from `ClientContext`, `student_id` / `teacher_id` / `collaborator_id` / `employee_id` from the requester's own profiles, `project_id` / `course_id` / `batch_id` validated to belong to the requester; (3) `branch_id` from the requester's profile; (4) number from `DocumentNumberService`; (5) priority from the form when staff, from `support.ticket_default_priority` when a portal user (a client cannot self-declare "urgent" - §12.2 Q6); (6) SLA due dates from `TicketSlaService::dueAt()`; (7) attachments through `SecureFileService` into `attachments`; (8) auto-assignment through `TicketAssignmentService`; (9) fires `TicketCreated` → staff notification |
| `reply(SupportTicket $t, ReplyData $d, User $actor): TicketReply` | one transaction. A portal user may reply only while `TicketStatus::requesterCanReply()`; `visibility = internal_note` requires `support_tickets.edit` (a portal user's reply is forced `public`); **a public staff reply on a ticket with no `first_response_at` stamps it and sets `is_first_response`**; a requester reply on `waiting` moves the ticket to `in_progress` and accumulates `total_waiting_minutes`; a requester reply on `resolved` inside `support.ticket_reopen_window_days` reopens it; recounts the four caches; fires `TicketReplied` |
| `assign(SupportTicket $t, ?User $agent, User $actor)` | requires `support_tickets.assign`; the agent must hold `support_tickets.view_any`; writes a system reply; fires `TicketAssigned` |
| `changeStatus(SupportTicket $t, TicketStatus $to, ?string $reason, User $actor)` | the §2.28.7 table is the only allowed map; `waiting` stamps `waiting_since`, leaving it accumulates the pause; `resolved` / `closed` stamp their actor and time; writes a system reply carrying `status_from` / `status_to`; fires `TicketStatusChanged` |
| `changePriority / changeDepartment(…, string $reason)` | staff only, reason mandatory, **recomputes the SLA due dates** and says so in the system reply |
| `recountCaches(SupportTicket $t): void` | COUNT under a row lock; also refreshes `ticket_departments.open_tickets_count` |
| `queue(TicketFilters, User): Paginator` | the one scoped query the index, the dashboard tiles and the §99 ticket report share |

**`TicketSlaService`**

| Method | Guarantees |
|---|---|
| `multiplier(Priority $p): float` | **the SLA multiplier lives here, not on the enum** (audit F-5.7): `low` 2.0, `medium` 1.0, `high` 0.5, `urgent` 0.25. It multiplies an **integer minute count**, never money, and the product is rounded half-up to a whole minute. Because it is support policy rather than a property of the shared `Priority` enum, changing the ladder touches one service method |
| `minutesFor(SupportTicket $t): SlaMinutes` | `department.sla_*` → `support.sla_*`, multiplied by `multiplier($t->priority)`, floored at 1 minute. Returns a null `SlaMinutes` when `support.sla_enabled` is false, and `multiplier()` is then never called |
| `dueAt(CarbonInterface $from, int $minutes): CarbonImmutable` | plain addition, or - when `support.sla_business_hours_only` - advancing only inside `contact.business_hours` (Phase 2's json: per-day open / close / closed), skipping closed days entirely. One implementation, used by create, reopen, department change and the sweep |
| `pause(SupportTicket) / resume(SupportTicket)` | on entering / leaving `waiting`; `resume` adds the elapsed minutes to `total_waiting_minutes` **and** pushes `first_response_due_at` / `resolution_due_at` forward by the same amount, so a pause is honest in both directions |
| `breaches(DateRange, TicketFilters): BreachReport` | first-response and resolution breaches, per agent and per department - the §99 ticket report's and the SLA chart's only source |
| `sweep(int $limit = 500): SweepResult` | stamps `first_response_breached` / `resolution_breached`, fires `TicketSlaBreached` once per ticket per kind (idempotent on the boolean), notifies the assignee and the holders of `support_tickets.view_reports`. **A no-op returning an empty `SweepResult` when `support.sla_enabled` is false** |

**The whole SLA feature sits behind `support.sla_enabled`** (default `true` - audit F-13.5, needs-human H3).
With it off: `TicketService::create()` / `reply()` / `changePriority()` / `changeDepartment()` stamp no clock
and leave every SLA column null (§2.18), `dueAt()` and `multiplier()` are never called, `tickets:sla-sweep`
returns immediately, no breach badge, countdown, SLA card or `admin.tickets.sla` tile renders, and
`breaches()` is not reachable from any screen. Turning it back on starts clocks from that moment forward and
never back-dates a breach on a ticket that was open while the feature was off.

**`TicketAssignmentService`** - `assignAutomatically(SupportTicket): ?User` per
`department.auto_assign_strategy` (falling back to `support.ticket_auto_assign`): `default_assignee` takes
the department's; `round_robin` takes the next eligible agent by `(last assigned_at, id)`;
`least_open` takes the eligible agent with the fewest `TicketStatus::isOpen()` tickets (ties → lowest id).
Eligible = holds `support_tickets.view_any`, `UserStatus::Active`, and - when the ticket has a branch - the
same branch or none. Returns null (leaving the ticket unassigned and notifying `support_tickets.assign`
holders) rather than guessing.

### 6.17 `MeetingService` (§95)

| Method | Guarantees |
|---|---|
| `create(MeetingData $d, array $participants, User $organizer): Meeting` | one transaction. `delivery_mode = online` requires `meeting_url`; a `classroom_id` runs `ScheduleClashDetector::check(new SlotCandidate(classroomId: …, startsAt: …, endsAt: …, ignoreType: 'meeting', ignoreId: $meeting?->id))` (phase-14-17 §6.7, D47) and a `ClashReport` with `$clean === false` is **refused** when `support.meeting_room_clash_block`, otherwise rendered as a warning listing the conflicts; each participant is resolved to a user **or** an external name+email (refused when `support.meeting_allow_external_participants` is false); a participant's `participant_type` and profile FKs are derived from their roles, never posted; the organizer is inserted as `organizer` / `accepted`; counts set; fires `MeetingScheduled` → `meeting.invited` to every internal participant |
| `update(Meeting $m, MeetingData $d, User $actor)` | a change of `scheduled_at`, `duration_minutes`, `meeting_url` or `location` re-notifies every participant and resets their `response` to `pending` (an invitation accepted for Tuesday is not an acceptance for Thursday) |
| `reschedule(Meeting $m, CarbonInterface $to, string $reason, User $actor): Meeting` | marks the old row `postponed` with the reason and creates a new row carrying `rescheduled_from_id`, so the history of a slipping meeting is readable |
| `cancel(Meeting $m, string $reason, User $actor)` | reason mandatory; notifies participants (`meeting.cancelled`) |
| `respond(Meeting $m, User $u, MeetingResponse $r)` | participants only (policy 404 otherwise); stamps `responded_at`; recounts `accepted_count` / `declined_count` |
| `markAttendance(Meeting $m, array $attended, User $actor)` | organizer or `meetings.edit`; sets `attended` per participant, recounts, and moves a past `scheduled` meeting to `completed` |
| `addParticipants / removeParticipant(…, string $reason)` | `meetings.assign`; removal writes an activity row with the reason; a removed participant immediately loses access (the scope is live, not snapshotted) |
| `saveNotes(Meeting $m, string $notes, User $actor)` | organizer or `meetings.edit`; notes are sanitised rich text; **a portal participant never writes notes** |
| `ics(Meeting $m, User $u): Response` | RFC 5545 `VEVENT` with UID = `meeting-{id}@{app host}`, `SEQUENCE` incremented on each reschedule, organizer, the viewer as the only `ATTENDEE`, `DESCRIPTION` carrying agenda + URL, `VALARM` at `reminder_minutes_before`. Gated by `support.meeting_ics_enabled` |
| `calendar(CalendarQuery $q, User $u): Collection` | the month / week / day / agenda feed, scoped per §9 |
| `upcomingFor(User $u, int $limit): Collection` | the panel dashboards' "upcoming meetings" tile - one query, used by all five panels |

### 6.18 `App\Support\MessagingMatrix` and `ConversationService` (§94)

**`MessagingMatrix`** is the whole of §94 in one class - the thing that stops a student messaging a client.

```
pairFor(User $a, User $b): ?ConversationScope
mayStart(User $initiator, User $target): MessagingDecision    // {allowed, scope, reason}
mayParticipate(Conversation $c, User $u): MessagingDecision
panelsOf(User $u): array<PanelType>                           // a user may hold several
```

| `ConversationScope` | §94 clause | Allowed when |
|---|---|---|
| `admin_employee` | Admin ↔ employee | one side holds an admin-panel role with `level <= 20`, the other an admin-panel role |
| `employee_employee` | employee ↔ employee | both hold an admin-panel role |
| `client_manager` | client ↔ project manager | one side is a client (`ClientContext` resolves), the other holds `projects.view_any` **or** is the `project_manager_id` of a project of that client |
| `collaborator_staff` | collaborator ↔ authorised staff | one side is a collaborator, the other holds `collaborators.view_any` |
| `student_staff` | student ↔ institute staff | one side is a student, the other holds `students.view_any` **or** is a teacher of one of that student's batches |
| `teacher_management` | teacher ↔ institute management | one side is a teacher, the other holds `teachers.view_any` or `batches.view_any` |

**Explicitly refused, each with its own test and its own plain-language reason:**
student ↔ client, student ↔ collaborator, student ↔ student, client ↔ client, client ↔ collaborator,
client ↔ student, collaborator ↔ collaborator, teacher ↔ client, teacher ↔ collaborator,
anyone ↔ a user whose `UserStatus` is not `Active`. A pair that resolves to several scopes takes the
**first** match in the table order above, and that value is what lands in `conversations.pair_scope`.
A scope absent from `support.messaging_allowed_pairs` is refused even when the roles match, and
`mayParticipate()` re-checks on **every** send so removing a pair silences existing threads (INV-22-4).

**`ConversationService`**

| Method | Guarantees |
|---|---|
| `startDirect(User $a, User $b, ?MessageData $first): Conversation` | `MessagingMatrix::mayStart()` or 403 with the reason; `support.messaging_enabled` and the `*_can_start` setting for the initiator's panel; `direct_key = sha256(sorted ids)`; a 1062 on `uq_cv_direct` **returns the existing thread** instead of failing (INV-22-5); inserts both participants with their `panel`; sends the first message when given |
| `startGroup(array $userIds, string $subject, User $creator, ?contextIds): Conversation` | every pair (creator, member) must be allowed, **and** every pair (member, member) must be allowed - so a group can never become the back door a direct thread refuses (PH22-26) |
| `send(Conversation $c, User $u, MessageData $d, array $files): Message` | one transaction: live participant, thread not closed, matrix re-checked, `support.messaging_rate_limit_per_minute` enforced in the service as well as in middleware, body or attachment present, attachments through `SecureFileService` when `support.messaging_attachments_enabled`; updates `last_message_id` / `last_message_at` / `messages_count`; **increments every other live participant's `unread_count` by recount, not by `++`**; fires `MessageSent` → `message.received` (§97 "new message") to the others, honouring `is_muted` |
| `markRead(Conversation $c, User $u, ?Message $upTo): void` | sets `last_read_message_id` / `last_read_at`, recomputes `unread_count` by COUNT, refreshes `messages.reads_count` |
| `unreadCountFor(User $u): int` | one indexed `SUM(unread_count)` - the bell's message badge |
| `addParticipant / removeParticipant / leave(…)` | matrix-checked; a leave sets `left_at` (history stays); `active_guard` keeps one live membership |
| `close(Conversation $c, string $reason, User $actor)` | `messages.change_status`; a closed thread is readable for ever, writable by nobody |
| `threadsFor(User $u, ThreadFilters): Paginator` | **always** `conversation_participants.user_id = auth()->id() AND left_at IS NULL` - never a client id, never a company (phase-05 §9.2's rule, restated) |

### 6.19 `App\Support\NotificationRegistry`, `NotificationService`, `NotificationPreferenceService`, `UnreadCounters` (§97)

**`NotificationRegistry`** - definitions in code, rows in the DB, exactly as `PermissionRegistry` and
`SettingsRegistry`. It is the single list of every notifiable event in the system, which is what makes the
§97 preference screen buildable and the mail channel a one-line switch.

```
events(): [ key => NotificationEvent ]
event(string $key): ?NotificationEvent
group(NotificationGroup $g): array
forUser(User $u): array                  // events whose module is enabled and whose audience can include this user
defaultsFor(string $key): ChannelSet
```
Each `NotificationEvent` declares: `key`, `title`, `description`, `group` (`NotificationGroup`), `module`
(so a disabled module hides it), `level` (`NotificationLevel`), `notification` (the class), `audience`
(`AudienceResolver`: an explicit model, `permission:<name>`, or a closure the owning phase supplies),
`defaultChannels` (`database` always, `mail` per event), `mandatory` (bool - [D-22-2]), `urlBuilder`,
`requiredPermission` (a permission the recipient must hold for the row to be created at all - e.g. a
commission notification needs `collaborator_portal.student_commission`).

**`NotificationService`**

| Method | Guarantees |
|---|---|
| `dispatch(string $eventKey, AudienceInput $audience, array $payload, ?User $actor): DispatchResult` | the only entry point (INV-22-7). (1) unknown key → `InvalidNotificationEvent` (a typo fails loudly in development, not silently in production); (2) module disabled → log + return; (3) resolve recipients (de-duplicated by user id, skipping inactive users and users without a `users` row); (4) drop a recipient lacking `requiredPermission`; (5) per recipient resolve channels: registry defaults ← `notification_preferences` ← the `support.notifications_mail_enabled` master switch ← `mandatory` (which cannot be switched off); (6) `Notification::sendNow` is never used - everything is queued, `afterCommit` (INV-22-8); (7) fills `event_key`, `module`, `level`, `url`, `actor_id` on the row |
| `dispatchToPermission(string $eventKey, string $permission, array $payload)` | the "tell whoever may act on this" path (`PayoutRequested`, `RefundAwaitingApproval`, `TicketSlaBreached`) |
| `markRead / markAllRead / archive / archiveAll(User, …)` | archive never deletes (§2.25) |
| `bell(User $u): BellPayload` | the unread count + the latest `support.notification_bell_page_size` rows, one query, cached per request |

**`NotificationPreferenceService`** - `matrixFor(User): PreferenceMatrix` (registry events × channels, with
the user's overrides applied and `mandatory` rows locked), `update(User, array $rows)` (rejects unknown keys
and ignored attempts to disable a mandatory event), `resetToDefaults(User)`.

**`App\Support\UnreadCounters`** - one cached-per-request class feeding the topbar of all five panels:
`notifications(User): int`, `messages(User): int`, `tickets(User): int` (open tickets awaiting the viewer),
`all(User): array`. Built as **three indexed COUNT queries, not three N+1 relations**, and the Phase 2
dashboard-query budget test (PH23-47) asserts it.

### 6.20 `ReportRegistry`, `ReportEngine` and the 31 reports of §99

**[D-23-2] Reports are declarations, not controllers.** `ReportRegistry` is the fourth registry in the
system (after permissions, settings and dashboard widgets) and follows the same shape: pure arrays / classes
in code, no DB. A new report is a class dropped into `app/Reports/`; no controller, route or view changes.

```
App\Support\ReportRegistry
    register(ReportDefinition $d): void
    all(): Collection<ReportDefinition>
    group(ReportGroup $g): Collection
    definition(string $key): ?ReportDefinition
    visibleTo(User $u): Collection      // module enabled AND every permission held - the Sidebar/DashboardRegistry rule
```

`App\Reports\ReportDefinition` (interface, one class per report in `app/Reports/{Group}/`):

| Member | Meaning |
|---|---|
| `key(): string` | `in.pending_fees` - stable, used by routes, exports and saved filters |
| `title() / description() / icon()` | |
| `group(): ReportGroup` | |
| `module(): string` | the source module slug - drives the module gate |
| `permissions(): array` | the stacked `can:` list: `reports.view_reports` plus the source module's `view_reports`, plus `view_financial` when any column is money (INV-23-2) |
| `filters(): array` | `FilterDefinition`s: `key`, `type` (`select` `multiselect` `date` `date_range` `number_range` `text` `boolean` `entity`), `options` (array or callable), `default`, `permission` (a filter a viewer may not use is absent), `span` |
| `dateFilter(): ?DateFilter` | the **named date column** this report measures on (§99's today/yesterday/week/month/year/custom), so the screen, the CSV and the PDF always agree - phase-13 §6.7's `meta.date column` rule generalised |
| `columns(): array` | `ColumnDefinition`s: `key`, `label`, `type` (`text` `number` `money` `percent` `date` `datetime` `badge` `link`), `align`, `sortable`, `permission` (**withheld → absent from the SELECT and from the file**), `total` (`sum`/`avg`/`count`/null), `width` |
| `groupBy(): array` | the allowed grouping keys |
| `run(ReportRequest $r): ReportResult` | **delegates to the owning service** (INV-23-1) and returns phase-13's `{rows, groups, totals, meta}` |
| `formats(): array` | which `ExportFormat` cases this report supports |
| `chart(): ?ChartDefinition` | optional: the chart rendered above the table through `x-ui.chart` |

`App\Services\Reporting\ReportEngine`

| Method | Guarantees |
|---|---|
| `run(string $key, ReportRequest $r, User $u): ReportResult` | resolves the definition (404 when unknown or not `visibleTo`), authorises every `permissions()` entry, strips filters and columns the user may not use, applies the branch scope and the role scope of §9, caches for `reports.cache_ttl_seconds` keyed by `(key, user id, filters hash)`, and stamps `meta` with the date column, the filters in force, the omitted columns and the row count |
| `export(string $key, ReportRequest $r, ExportFormat $f, User $u): Response|ReportExport` | re-authorises, then: `row_count <= reports.sync_row_limit` → stream now through `ReportExporter`; above it → create a `report_exports` row and queue `BuildReportExport`; above `reports.export_max_rows` → refuse, naming the count and the limit |
| `describe(string $key, User $u): ReportSchema` | the JSON the filter bar and the column picker are built from |

**The 31 reports of §99.** Every `run()` delegates; the *Source* column is the service that owns the figure.
A report whose source service does not yet exist renders the Phase 5 [D-P5-1] empty state ("this report
arrives with Phase N") rather than a fabricated number.

| key | Group | Module gate | Source (never re-implemented) | Key columns | Filters beyond the date range |
|---|---|---|---|---|---|
| `sh.clients` | software_house | `clients` | `ClientService` + `ClientService::financialSummary()` | client, company, country, status, projects, invoiced, paid, outstanding, account manager, created | status, country, account manager, collaborator, has outstanding |
| `sh.projects` | software_house | `projects` | Phase 6 project read model + `ProjectProgressService` | project, client, PM, type, status, priority, start, deadline, progress %, value, received, collaborator | status, priority, client, PM, collaborator, overdue |
| `sh.leads` | software_house | `leads` | `LeadBoardService` | lead, company, source, status, assignee, budget, follow-up, age, converted | status, source, assignee, service, stale |
| `sh.employees` | software_house | `employees` | Phase 7 employee directory | employee, code, department, designation, type, joining, status, tenure | department, designation, type, status, branch |
| `sh.attendance` | software_house | `attendance` | Phase 7 `attendance_monthly_summaries` | employee, present, absent, late, leave, half day, hours, % | department, month, employee, below threshold |
| `sh.payroll` | software_house | `payroll` | Phase 7 `PayslipService` / payroll run items | run, employee, basic, allowances, bonus, commission, deductions, advance, tax, net | run, month, department, status |
| `sh.invoices` | software_house | `invoices` | `FinanceReportService` | invoice, client, project, issued, due, total, paid, balance, status, days overdue | status, client, project, aging bucket |
| `sh.payments` | software_house | `project_payments` | `FinanceReportService` | receipt, client, project, invoice, method, paid on, gross, refunded, net | client, project, method, has refund |
| `sh.income` | software_house | `income` | `FinanceReportService::report(income)` | source, category, month, amount, method, context | source, category, context, method, branch |
| `sh.expenses` | software_house | `expenses` | `FinanceReportService::report(expenses)` | category, month, amount, method, context, approver | category, context, project, method, approver |
| `sh.profit_loss` | software_house | `income` + `expenses` | `FinanceReportService::report(profit_loss)` | period, income, expenses, commission memo, payouts, net | context, branch, comparison period |
| `in.students` | institute | `students` | `StudentDirectoryService::forAdmin()` | student, code, registration no, course, batch, status, joining, city, collaborator, attendance %, progress % | status, course, batch, branch, collaborator, gender, city, joining range |
| `in.courses` | institute | `courses` | `CourseService` | course, code, category, level, mode, duration, fee*, batches, active students, completed, certificate | category, level, mode, status, fee range* |
| `in.batches` | institute | `batches` | `BatchService` | batch, course, teacher, start, end, capacity, enrolled, seats left, status, syllabus %, avg attendance | status, course, teacher, branch, date range |
| `in.teachers` | institute | `teachers` | `TeacherService` | teacher, code, qualification, experience, courses, batches, students, sessions held, avg attendance, salary* | status, course, branch |
| `in.attendance` | institute | `student_attendance` | **`AttendanceReportService`** (its four reports, unchanged) | the four shapes of phase-14-17 §6.9 | batch, course, teacher, month, below minimum |
| `in.admissions` | institute | `admissions` | `AdmissionService` funnel | admission no, student, course, batch, stage, date, counsellor, collaborator, agreed total*, paid*, pending* | stage, course, batch, counsellor, collaborator, branch, date range |
| `in.fees` | institute | `student_fees` | **`StudentFeeService`** + Phase 18 scopes | fee no, student, course, batch, head, gross, discount, net, paid, balance, due date, status | status, fee type, course, batch, branch, method, date range |
| `in.pending_fees` | institute | `student_fees` | **`StudentFeeService`** (the collection-desk aggregate) | student, course, batch, next due, amount due, balance, days late, bucket | bucket, course, batch, branch, amount range |
| `in.exams` | institute | `exams` | **`ExamStatisticsService::forBatch()`** | exam, type, course, batch, date, total, passing, expected, appeared, absent, pass rate, average | type, status, course, batch, teacher, date range |
| `in.results` | institute | `results` | **`ExamStatisticsService`** | student, exam, obtained, total, %, grade, pass/fail, position, remarks | exam, type, course, batch, grade, pass/fail, percentage range |
| `in.certificates` | institute | `certificates` | **`CertificateService::register()`** | certificate no, student, course, batch, trainer, completion, grade, issued on, status, verifications | status, course, batch, branch, grade, issue range |
| `co.performance` | collaborator | `collaborators` | `ReferralTrackingService::funnel()` + `CollaboratorStatementService` | collaborator, code, status, visits, referred students, referred projects, project value, commission earned, paid, available | status, type, date range, branch |
| `co.referred_students` | collaborator | `collaborator_referrals` | `StudentDirectoryService::forCollaborator()` (admin view) | collaborator, student, course, batch, registration date, status, total paid, commission earned | collaborator, course, status, date range |
| `co.referred_projects` | collaborator | `collaborator_referrals` | Phase 6 read model + `CollaboratorStatementService` | collaborator, project, client, value, received, rate, commission earned, status | collaborator, status, date range |
| `co.student_commission` | collaborator | `collaborator_commissions` | **`CollaboratorStatementService`** | ledger id, collaborator, student, fee receipt, base, rate, amount, status, date | collaborator, status, date range |
| `co.project_commission` | collaborator | `collaborator_commissions` | **`CollaboratorStatementService`** | ledger id, collaborator, project, payment, base, rate, amount, status, date | collaborator, status, date range |
| `co.pending_commission` | collaborator | `collaborator_commissions` | **`CollaboratorWalletService`** | collaborator, pending entries, pending total, oldest entry, hold until | collaborator, age bucket |
| `co.paid_commission` | collaborator | `collaborator_payouts` | **`CollaboratorWalletService::payoutsPaidTotal()`** | collaborator, payout no, amount, method, paid on, reference, allocated entries | collaborator, method, date range |
| `co.payout_history` | collaborator | `collaborator_payouts` | **Phase 12 payout register** | payout no, collaborator, requested, approved, paid, amount, method, status, actor | status, method, collaborator, date range |
| `co.commission_reversals` | collaborator | `payment_reversals` | **`CollaboratorStatementService`** (reversal rows) | reversal no, collaborator, original entry, amount, reason, date, actor | collaborator, reason, date range |

`*` = money or salary column, additionally gated by that module's `view_financial` and absent from the query
when withheld (INV-23-2). Two further system reports round out §106-108 and are **not** §99 reports:
`sys.activity_log` and `sys.audit_trail` (§6.22), each gated by its own module.

### 6.21 `ReportExporter` and `ReportExportService` (§99 print / PDF / CSV)

Phase 13 §6.9 ships **`App\Services\Reporting\ReportExporter::export(ReportResult $r, ExportFormat $f):
StreamedResponse`** - that namespace and that signature, with `App\Support\ReportResult` as the readonly
`{array $rows, array $groups, array $totals, array $meta}` DTO and `resources/views/layouts/print.blade.php`
as the print layout (audit F-4.14). **Phase 23 reuses the class and the signature**, adds the `excel` branch,
and never duplicates it (phase-13 §13.2). Every format streams; nothing is materialised with `->get()`.

| Format | How |
|---|---|
| `print` | a print-styled Blade over `layouts/print`, the filters in force printed in the header, repeated table head, page numbers, "generated at / generated by" footer |
| `pdf` | dompdf over the same Blade, paper and orientation from `reports.pdf_*`, `isRemoteEnabled = false` |
| `csv` | **streamed** through Phase 5's `App\Support\CsvWriter` + `chunkById` - a generator, never an array of rows in memory - with its formula-injection escape (`=`, `+`, `-`, `@`, tab, CR prefixed with `'`) |
| `excel` | only when `reports.excel_enabled`; the same column set through the same `CsvWriter`-shaped generator into a single-sheet xlsx. A missing package renders a disabled button with a tooltip, never a 500 |

`App\Services\Reporting\ReportExportService`

| Method | Guarantees |
|---|---|
| `queue(ReportDefinition $d, ReportRequest $r, ExportFormat $f, User $u): ReportExport` | one row, `status = queued`, filters stored verbatim; dispatches `BuildReportExport` afterCommit |
| `download(ReportExport $x, User $u): StreamedResponse` | §6.4: `requested_by = $u->id` (404 otherwise), `status` downloadable, `expires_at` not passed, **and the report's own permissions still held** - a user who lost `view_financial` yesterday cannot download yesterday's file today (PH23-16) |
| `prune(): int` | expires and deletes files past `expires_at`; one activity row with the count |

### 6.22 `AnalyticsService`, `ActivityLogService`, `AuditTrailService` (§98, §106, §107)

**`AnalyticsService`** - one method per chart, each delegating. Phase 23 adds **only** charts whose subject
these five phases own; it never re-registers a widget another phase already put in `DashboardRegistry`
(§98's cards belong to phases 5-18).

| Method | Chart | Source |
|---|---|---|
| `examPassRateTrend(DateRange, filters)` | line, pass rate per month per exam type | `ExamStatisticsService::forCourse()` |
| `resultGradeDistribution(DateRange, filters)` | bar, students per grade band | `ExamStatisticsService` |
| `assignmentCompliance(DateRange, filters)` | stacked bar, submitted / late / missed per batch | `AssignmentService::statistics()` |
| `materialEngagement(DateRange, filters)` | bar, unique students who opened vs targeted, per material or batch | `CourseMaterialService::engagement()` |
| `certificateIssuanceTrend(DateRange)` | line, issued and revoked per month | `CertificateService::register()` |
| `ticketVolumeAndSla(DateRange)` | combo, created / resolved bars + breach-rate line | `TicketService::queue()` + `TicketSlaService::breaches()` |
| `ticketsByDepartmentAndPriority(DateRange)` | stacked bar | `TicketService::queue()` |
| `meetingLoad(DateRange)` | bar, meetings and attendance rate per organizer | `MeetingService` |
| `notificationHealth(DateRange)` | bar, created vs read vs emailed per event group | `notifications` aggregate |

Each is also registered as a `DashboardWidget` (Phase 2) so it can appear on the master dashboard, and each
declares its own `permission()` and `module()` so a user without the right never receives the JSON.

**`ActivityLogService`** (§106) - read-only over Phase 1's `activity_log`:
`query(ActivityLogFilters): Paginator` (user / causer, module, log name, subject type, event, date range,
IP, device, free text on description, `reason IS NOT NULL`), `timelineFor(Model $subject)` (the "history"
tab every detail screen can embed), `export(filters, ExportFormat)`, `contextFor(Activity): array` (IP,
device, user agent, module, reason). **No write method, no delete route** (INV-23-5).

**`AuditTrailService`** (§107) - the same table, a different question: *what changed, from what, to what*.
`query(AuditFilters): Paginator` restricted to rows that carry both `properties.old` and
`properties.attributes` **and** whose `module` is in `reports.audit_sensitive_modules`;
`diff(Activity): array` returning one row per changed field with `{field, label, old, new, type}`, formatted
by type (money through `money()`, dates through `app_date()`, enums through `label()`, booleans as Yes/No,
an encrypted value as `[encrypted]` - never the value, per Phase 2's rule); `forSubject(Model)`;
`sensitivityOf(Activity): AuditSensitivity`. A `financial` row's old/new values additionally require that
module's `view_financial` when `reports.audit_show_financial_values` is false - withheld, not blanked.
The §107 examples are all reachable: *student collaborator A → B*, *project commission 10 % → 15 %*,
*result amended 62 → 67*, *certificate revoked*, *commission rate changed*, each with its reason.

### 6.23 `GlobalSearchRegistry` and `GlobalSearchService` (§108)

**[D-23-3] Eleven providers, one per §108 entity, each owning its own scope.** A single UNION query over
eleven tables could not apply eleven different isolation rules, so each provider is a class that reuses the
**same scope its module's index uses** - which is why a student searching "Ahmed" can never surface another
student.

```
App\Support\GlobalSearchRegistry
    register(SearchProvider $p): void
    all(): Collection<SearchProvider>
    availableTo(User $u): Collection     // module enabled AND permission held AND listed in reports.global_search_entities
```

`App\Search\SearchProvider` (interface): `type(): SearchEntityType`, `module(): string`,
`permission(): string`, `columns(): array` (the searchable columns), `query(string $term, User $u, int $limit): Collection`,
`present(Model $m): SearchHit` (`title`, `subtitle`, `badge`, `meta`, `url`, `icon`), `weight(): int`.

| Provider | Searches | Scope applied (the same one its index uses) | Result route |
|---|---|---|---|
| `ClientSearchProvider` | name, company, `client_code`, email, phone | account-manager scope (Phase 5 §9); client panel: own row only | `admin.clients.show` |
| `EmployeeSearchProvider` | name, `employee_code`, email, phone, designation | branch + HR scope (Phase 7) | `admin.employees.show` |
| `CollaboratorSearchProvider` | name, company, `collaborator_code`, `referral_code`, email, phone | `collaborators.view_any`; a collaborator sees **only themselves** | `admin.collaborators.show` |
| `StudentSearchProvider` | name, `student_code`, `registration_number`, phone, CNIC, email | branch scope; teacher → `TeacherScope::batchIds()`; student → own row | `admin.students.show` |
| `TeacherSearchProvider` | name, `teacher_code`, email, phone, specialization | branch scope; student → teachers of their own batches only | `admin.teachers.show` |
| `CourseSearchProvider` | name, `code`, slug, category | branch scope; fee columns withheld without `courses.view_financial` | `admin.courses.show` |
| `LeadSearchProvider` | name, company, phone, email | `leads.view_any` = all, `leads.view` = own ([D-P5-8]) | `admin.leads.show` |
| `ProjectSearchProvider` | name, `project_code`, client name | member / PM scope (Phase 6); client → own projects | `admin.projects.show` |
| `TaskSearchProvider` | title, `task_code` | assignee / project-member scope (Phase 6) | `admin.tasks.show` |
| `InvoiceSearchProvider` | `invoice_number`, client name | `invoices.view_any` + `view_financial` for the amount; client → own | `admin.invoices.show` |
| `TicketSearchProvider` | `ticket_number`, subject | `support_tickets.view_any` = all, `view` = own/assigned; portal → own | `admin.tickets.show` / the panel route |

**`GlobalSearchService`**

| Method | Guarantees |
|---|---|
| `search(string $term, User $u, array $types = [], ?int $limit = null): SearchResults` | trims and requires `reports.global_search_min_chars`; runs only `availableTo($u)` providers (intersected with `$types`); `reports.global_search_per_entity_limit` rows each; **one query per provider, never a query per row**; wraps each in a try/catch so one broken provider cannot blank the palette (it is logged and reported as unavailable); orders groups by `weight()`; tags every hit with the route the viewer may actually open (a hit whose show route the viewer lacks is presented without a link, never dropped silently) |
| `exactMatch(string $term, User $u): ?SearchHit` | a document-number fast path: `ticket_number`, `invoice_number`, `certificate_number`, `student_code`, `registration_number`, `card_number`, `fee_number`, `receipt_number` - pasting a number jumps straight to the record |
| `recentFor(User $u): array` | the last 5 hits the user opened, from `users.preferences` (Phase 2) - **no new table, no server-side search history** |

Results are **never cached across users**. The endpoint is `throttle:60,1` per user.

---

## 7. Routes

Admin routes carry `auth`, `active`, `panel:admin` from Phase 1 §8's file group; panel routes carry their own
`panel:*`; public routes carry `site` (Phase 3 §6.10's alias, which also ships `site.cache` and `site.preview`) and no `auth`. `module:*` is stated
on every row because these modules are all non-core and therefore disableable. Multiple `can:` entries are
**and**-ed. Rows marked **(write)** appear at Phase 5's `// Phase 22: client writes` marker in
`routes/client.php`, as [D-P5-11] requires.

### 7.1 Admin - course materials (`module:course_materials`)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/course-materials` | `admin.course-materials.index` | `can:course_materials.view_any` |
| GET `/admin/course-materials/create` | `admin.course-materials.create` | `can:course_materials.create` |
| POST `/admin/course-materials` | `admin.course-materials.store` | `can:course_materials.create`, `throttle:30,1` |
| GET `/admin/course-materials/{material}` | `admin.course-materials.show` | `can:course_materials.view` |
| GET `/admin/course-materials/{material}/edit` | `admin.course-materials.edit` | `can:course_materials.edit` |
| PUT `/admin/course-materials/{material}` | `admin.course-materials.update` | `can:course_materials.edit` |
| POST `/admin/course-materials/{material}/file` | `admin.course-materials.file.replace` | `can:course_materials.upload`, `throttle:20,1` |
| GET `/admin/course-materials/{material}/download` | `admin.course-materials.download` | `can:course_materials.download` |
| POST `/admin/course-materials/{material}/targets` | `admin.course-materials.targets.store` | `can:course_materials.assign` |
| DELETE `/admin/course-materials/{material}/targets/{target}` | `admin.course-materials.targets.destroy` | `can:course_materials.assign` |
| POST `/admin/course-materials/{material}/status` | `admin.course-materials.status` | `can:course_materials.change_status` |
| DELETE `/admin/course-materials/{material}` | `admin.course-materials.destroy` | `can:course_materials.delete` |
| GET `/admin/course-materials/{material}/engagement` | `admin.course-materials.engagement` | `can:course_materials.view_reports` |
| GET `/admin/course-materials/export/{format}` | `admin.course-materials.export` | `can:course_materials.export` |

### 7.2 Admin - assignments and submissions (`module:assignments` / `module:assignment_submissions`)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/assignments` | `admin.assignments.index` | `can:assignments.view_any` |
| GET `/admin/assignments/create` | `admin.assignments.create` | `can:assignments.create` |
| POST `/admin/assignments` | `admin.assignments.store` | `can:assignments.create`, `throttle:30,1` |
| GET `/admin/assignments/{assignment}` | `admin.assignments.show` | `can:assignments.view` |
| GET `/admin/assignments/{assignment}/edit` | `admin.assignments.edit` | `can:assignments.edit` |
| PUT `/admin/assignments/{assignment}` | `admin.assignments.update` | `can:assignments.edit` |
| POST `/admin/assignments/{assignment}/status` | `admin.assignments.status` | `can:assignments.change_status` |
| POST `/admin/assignments/{assignment}/duplicate` | `admin.assignments.duplicate` | `can:assignments.create` |
| GET `/admin/assignments/{assignment}/brief` | `admin.assignments.brief.download` | `can:assignments.download` |
| GET `/admin/assignments/{assignment}/print` | `admin.assignments.print` | `can:assignments.print` |
| DELETE `/admin/assignments/{assignment}` | `admin.assignments.destroy` | `can:assignments.delete` |
| GET `/admin/assignments/{assignment}/submissions` | `admin.assignment-submissions.index` | `module:assignment_submissions`, `can:assignment_submissions.view_any` |
| GET `/admin/assignment-submissions/{submission}` | `admin.assignment-submissions.show` | `can:assignment_submissions.view` |
| POST `/admin/assignment-submissions/{submission}/grade` | `admin.assignment-submissions.grade` | `can:assignment_submissions.edit`, `throttle:60,1` |
| POST `/admin/assignment-submissions/{submission}/return` | `admin.assignment-submissions.return` | `can:assignment_submissions.edit` |
| POST `/admin/assignment-submissions/{submission}/amend` | `admin.assignment-submissions.amend` | `can:assignment_submissions.edit` |
| POST `/admin/assignments/{assignment}/grade-bulk` | `admin.assignment-submissions.grade-bulk` | `can:assignment_submissions.edit`, `throttle:10,1` |
| POST `/admin/assignments/{assignment}/release-marks` | `admin.assignment-submissions.release` | `can:assignment_submissions.change_status` |
| POST `/admin/assignments/{assignment}/mark-missed` | `admin.assignment-submissions.mark-missed` | `can:assignment_submissions.change_status` |
| POST `/admin/assignments/{assignment}/submissions` | `admin.assignment-submissions.store` | `can:assignment_submissions.create` (offline submission on a student's behalf) |
| GET `/admin/assignment-submissions/{submission}/files/{file}` | `admin.assignment-submissions.file.download` | `can:assignment_submissions.download` |
| GET `/admin/assignment-submissions/{submission}/feedback-file` | `admin.assignment-submissions.feedback.download` | `can:assignment_submissions.download` |
| GET `/admin/assignments/{assignment}/export/{format}` | `admin.assignment-submissions.export` | `can:assignment_submissions.export` |

### 7.3 Admin - grade scales and exams (`module:grade_scales` / `module:exams`)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/grade-scales` | `admin.grade-scales.index` | `can:grade_scales.view_any` |
| GET `/admin/grade-scales/create` | `admin.grade-scales.create` | `can:grade_scales.create` |
| POST `/admin/grade-scales` | `admin.grade-scales.store` | `can:grade_scales.create` |
| GET `/admin/grade-scales/{scale}/edit` | `admin.grade-scales.edit` | `can:grade_scales.edit` |
| PUT `/admin/grade-scales/{scale}` | `admin.grade-scales.update` | `can:grade_scales.edit` |
| POST `/admin/grade-scales/{scale}/default` | `admin.grade-scales.default` | `can:grade_scales.change_status` |
| POST `/admin/grade-scales/{scale}/status` | `admin.grade-scales.status` | `can:grade_scales.change_status` |
| DELETE `/admin/grade-scales/{scale}` | `admin.grade-scales.destroy` | `can:grade_scales.delete` |
| GET `/admin/exams` | `admin.exams.index` | `can:exams.view_any` |
| GET `/admin/exams/calendar` | `admin.exams.calendar` | `can:exams.view_any` |
| GET `/admin/exams/create` | `admin.exams.create` | `can:exams.create` |
| POST `/admin/exams` | `admin.exams.store` | `can:exams.create` |
| GET `/admin/exams/{exam}` | `admin.exams.show` | `can:exams.view` |
| GET `/admin/exams/{exam}/edit` | `admin.exams.edit` | `can:exams.edit` |
| PUT `/admin/exams/{exam}` | `admin.exams.update` | `can:exams.edit` |
| POST `/admin/exams/{exam}/status` | `admin.exams.status` | `can:exams.change_status` |
| POST `/admin/exams/{exam}/reschedule` | `admin.exams.reschedule` | `can:exams.change_status` |
| POST `/admin/exams/{exam}/examiner` | `admin.exams.examiner` | `can:exams.assign` |
| DELETE `/admin/exams/{exam}` | `admin.exams.destroy` | `can:exams.delete` |
| GET `/admin/exams/export/{format}` | `admin.exams.export` | `can:exams.export` |

### 7.4 Admin - results (`module:results`)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/exams/{exam}/results` | `admin.results.sheet` | `module:results`, `can:results.view_any` |
| POST `/admin/exams/{exam}/results` | `admin.results.save` | `can:results.create`, `throttle:30,1` |
| POST `/admin/exams/{exam}/results/verify` | `admin.results.verify` | `can:results.approve` |
| POST `/admin/exams/{exam}/results/publish` | `admin.results.publish` | `can:results.change_status` |
| POST `/admin/exams/{exam}/results/unpublish` | `admin.results.unpublish` | `can:results.change_status` |
| PUT `/admin/results/{result}` | `admin.results.amend` | `can:results.edit` |
| GET `/admin/exams/{exam}/results/template` | `admin.results.template` | `can:results.import` |
| POST `/admin/exams/{exam}/results/import` | `admin.results.import` | `can:results.import`, `throttle:5,1` |
| GET `/admin/results/{result}/card` | `admin.results.card` | `can:results.print` |
| GET `/admin/enrollments/{enrollment}/result-card` | `admin.results.card.consolidated` | `can:results.print` |
| GET `/admin/exams/{exam}/result-cards` | `admin.results.cards.batch` | `can:results.print` |
| GET `/admin/exams/{exam}/results/export/{format}` | `admin.results.export` | `can:results.export` |
| GET `/admin/results/statistics` | `admin.results.statistics` | `can:results.view_reports` |

### 7.5 Admin - print templates, certificates, ID cards

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/print-templates` | `admin.print-templates.index` | `module:print_templates`, `can:print_templates.view_any` |
| GET `/admin/print-templates/create` | `admin.print-templates.create` | `can:print_templates.create` |
| POST `/admin/print-templates` | `admin.print-templates.store` | `can:print_templates.create` |
| GET `/admin/print-templates/{template}/edit` | `admin.print-templates.edit` | `can:print_templates.edit` |
| PUT `/admin/print-templates/{template}` | `admin.print-templates.update` | `can:print_templates.edit` |
| POST `/admin/print-templates/{template}/duplicate` | `admin.print-templates.duplicate` | `can:print_templates.create` |
| POST `/admin/print-templates/{template}/default` | `admin.print-templates.default` | `can:print_templates.change_status` |
| GET `/admin/print-templates/{template}/preview` | `admin.print-templates.preview` | `can:print_templates.view` |
| GET `/admin/print-templates/{template}/tokens` | `admin.print-templates.tokens` | `can:print_templates.view` |
| DELETE `/admin/print-templates/{template}` | `admin.print-templates.destroy` | `can:print_templates.delete` |
| GET `/admin/certificates` | `admin.certificates.index` | `module:certificates`, `can:certificates.view_any` |
| GET `/admin/certificates/eligible` | `admin.certificates.eligible` | `can:certificates.create` |
| GET `/admin/enrollments/{enrollment}/certificate/eligibility` | `admin.certificates.eligibility` | `can:certificates.create` (writes nothing) |
| GET `/admin/certificates/create` | `admin.certificates.create` | `can:certificates.create` |
| POST `/admin/certificates` | `admin.certificates.store` | `can:certificates.create` |
| GET `/admin/certificates/{certificate}` | `admin.certificates.show` | `can:certificates.view` |
| PUT `/admin/certificates/{certificate}` | `admin.certificates.update` | `can:certificates.edit` (drafts only - policy) |
| POST `/admin/certificates/{certificate}/issue` | `admin.certificates.issue` | `can:certificates.change_status` (+ `can:certificates.approve` when required) |
| POST `/admin/certificates/bulk-issue` | `admin.certificates.bulk-issue` | `can:certificates.change_status`, `throttle:5,1` |
| POST `/admin/certificates/{certificate}/revoke` | `admin.certificates.revoke` | `can:certificates.change_status` |
| POST `/admin/certificates/{certificate}/reissue` | `admin.certificates.reissue` | `can:certificates.create` |
| GET `/admin/certificates/{certificate}/print` | `admin.certificates.print` | `can:certificates.print` |
| GET `/admin/certificates/{certificate}/pdf` | `admin.certificates.pdf` | `can:certificates.print` |
| POST `/admin/certificates/{certificate}/regenerate-pdf` | `admin.certificates.pdf.regenerate` | `can:certificates.edit` |
| GET `/admin/certificates/{certificate}/verifications` | `admin.certificates.verifications` | `can:certificates.view_logs` |
| DELETE `/admin/certificates/{certificate}` | `admin.certificates.destroy` | `can:certificates.delete` (drafts only - policy) |
| GET `/admin/certificates/export/{format}` | `admin.certificates.export` | `can:certificates.export` |
| GET `/admin/student-id-cards` | `admin.student-id-cards.index` | `module:student_id_cards`, `can:student_id_cards.view_any` |
| GET `/admin/student-id-cards/create` | `admin.student-id-cards.create` | `can:student_id_cards.create` |
| POST `/admin/student-id-cards` | `admin.student-id-cards.store` | `can:student_id_cards.create` |
| POST `/admin/student-id-cards/bulk-issue` | `admin.student-id-cards.bulk-issue` | `can:student_id_cards.create`, `throttle:5,1` |
| GET `/admin/student-id-cards/{card}` | `admin.student-id-cards.show` | `can:student_id_cards.view` |
| POST `/admin/student-id-cards/{card}/status` | `admin.student-id-cards.status` | `can:student_id_cards.change_status` |
| POST `/admin/student-id-cards/{card}/replace` | `admin.student-id-cards.replace` | `can:student_id_cards.create` |
| GET `/admin/student-id-cards/{card}/print` | `admin.student-id-cards.print` | `can:student_id_cards.print` |
| GET `/admin/student-id-cards/{card}/pdf` | `admin.student-id-cards.pdf` | `can:student_id_cards.print` |
| POST `/admin/student-id-cards/batch-print` | `admin.student-id-cards.batch-print` | `can:student_id_cards.print`, `throttle:5,1` |
| GET `/admin/student-id-cards/export/{format}` | `admin.student-id-cards.export` | `can:student_id_cards.export` |

### 7.6 Admin - tickets, meetings, messages, notifications (`ModuleGroup::Shared`)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/ticket-departments` | `admin.ticket-departments.index` | `module:ticket_departments`, `can:ticket_departments.view_any` |
| POST `/admin/ticket-departments` | `admin.ticket-departments.store` | `can:ticket_departments.create` |
| PUT `/admin/ticket-departments/{department}` | `admin.ticket-departments.update` | `can:ticket_departments.edit` |
| POST `/admin/ticket-departments/{department}/status` | `admin.ticket-departments.status` | `can:ticket_departments.change_status` |
| DELETE `/admin/ticket-departments/{department}` | `admin.ticket-departments.destroy` | `can:ticket_departments.delete` |
| GET `/admin/tickets` | `admin.tickets.index` | `module:support_tickets`, `can:support_tickets.view_any\|support_tickets.view` |
| GET `/admin/tickets/create` | `admin.tickets.create` | `can:support_tickets.create` |
| POST `/admin/tickets` | `admin.tickets.store` | `can:support_tickets.create`, `throttle:20,1` |
| GET `/admin/tickets/{ticket}` | `admin.tickets.show` | `can:view,ticket` (policy) |
| POST `/admin/tickets/{ticket}/replies` | `admin.tickets.replies.store` | `can:reply,ticket`, `throttle:60,1` |
| POST `/admin/tickets/{ticket}/assign` | `admin.tickets.assign` | `can:support_tickets.assign` |
| POST `/admin/tickets/{ticket}/status` | `admin.tickets.status` | `can:support_tickets.change_status` |
| POST `/admin/tickets/{ticket}/priority` | `admin.tickets.priority` | `can:support_tickets.edit` |
| POST `/admin/tickets/{ticket}/department` | `admin.tickets.department` | `can:support_tickets.edit` |
| GET `/admin/tickets/{ticket}/attachments/{attachment}` | `admin.tickets.attachment.download` | `can:support_tickets.download` |
| GET `/admin/tickets/sla` | `admin.tickets.sla` | `can:support_tickets.view_reports` |
| GET `/admin/tickets/export/{format}` | `admin.tickets.export` | `can:support_tickets.export` |
| GET `/admin/meetings` | `admin.meetings.index` | `module:meetings`, `can:meetings.view_any\|meetings.view` |
| GET `/admin/meetings/calendar` | `admin.meetings.calendar` | `can:meetings.view_any\|meetings.view` |
| GET `/admin/meetings/create` | `admin.meetings.create` | `can:meetings.create` |
| POST `/admin/meetings` | `admin.meetings.store` | `can:meetings.create` |
| GET `/admin/meetings/{meeting}` | `admin.meetings.show` | `can:view,meeting` |
| PUT `/admin/meetings/{meeting}` | `admin.meetings.update` | `can:update,meeting` |
| POST `/admin/meetings/{meeting}/status` | `admin.meetings.status` | `can:meetings.change_status` |
| POST `/admin/meetings/{meeting}/reschedule` | `admin.meetings.reschedule` | `can:meetings.change_status` |
| POST `/admin/meetings/{meeting}/participants` | `admin.meetings.participants.store` | `can:meetings.assign` |
| DELETE `/admin/meetings/{meeting}/participants/{participant}` | `admin.meetings.participants.destroy` | `can:meetings.assign` |
| POST `/admin/meetings/{meeting}/respond` | `admin.meetings.respond` | `can:respond,meeting` |
| POST `/admin/meetings/{meeting}/attendance` | `admin.meetings.attendance` | `can:update,meeting` |
| PUT `/admin/meetings/{meeting}/notes` | `admin.meetings.notes` | `can:update,meeting` |
| GET `/admin/meetings/{meeting}/ics` | `admin.meetings.ics` | `can:view,meeting` |
| GET `/admin/meetings/{meeting}/print` | `admin.meetings.print` | `can:meetings.print` |
| DELETE `/admin/meetings/{meeting}` | `admin.meetings.destroy` | `can:meetings.delete` |
| GET `/admin/messages` | `admin.messages.index` | `module:messages`, `can:messages.view` |
| GET `/admin/messages/{conversation}` | `admin.messages.show` | `can:view,conversation` (404 for a non-participant) |
| POST `/admin/messages` | `admin.messages.store` | `can:messages.create`, `throttle:20,1` |
| POST `/admin/messages/{conversation}/send` | `admin.messages.send` | `can:send,conversation`, `throttle:support.messaging_rate_limit_per_minute` |
| POST `/admin/messages/{conversation}/read` | `admin.messages.read` | `can:view,conversation` |
| POST `/admin/messages/{conversation}/participants` | `admin.messages.participants.store` | `can:send,conversation` |
| POST `/admin/messages/{conversation}/leave` | `admin.messages.leave` | `can:view,conversation` |
| POST `/admin/messages/{conversation}/close` | `admin.messages.close` | `can:messages.change_status` |
| GET `/admin/messages/recipients` | `admin.messages.recipients` | `can:messages.create` - **the matrix-filtered recipient picker** (§8.13) |
| GET `/admin/messages/{conversation}/attachments/{attachment}` | `admin.messages.attachment.download` | `can:view,conversation` |

### 7.7 Notifications - shared across all five panels

Registered once in a shared route group and included by each panel file, so the bell behaves identically
everywhere. `{panel}` is the current panel's prefix.

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/{panel}/notifications` | `{panel}.notifications.index` | `module:notifications`, `can:{panel}_portal.notifications` (admin: `can:notifications.view_any`) |
| GET `/{panel}/notifications/bell` | `{panel}.notifications.bell` | same + `throttle:120,1` (the polling endpoint) |
| POST `/{panel}/notifications/{notification}/read` | `{panel}.notifications.read` | same; the row must be the viewer's own (404 otherwise) |
| POST `/{panel}/notifications/read-all` | `{panel}.notifications.read-all` | same |
| POST `/{panel}/notifications/{notification}/archive` | `{panel}.notifications.archive` | same |
| GET `/{panel}/notifications/{notification}/go` | `{panel}.notifications.go` | same - marks read (per `support.notification_mark_read_on_open`) then redirects to the stored `url`, **after re-authorising the target route** |
| GET `/account/notification-preferences` | `account.notification-preferences.edit` | `auth`, `active` - **no permission: a user always owns their own preferences** |
| PUT `/account/notification-preferences` | `account.notification-preferences.update` | `auth`, `active` |

### 7.8 Admin - reports, analytics, logs, global search (Phase 23)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/reports` | `admin.reports.index` | `module:reports`, `can:reports.view_reports` |
| GET `/admin/reports/{report}` | `admin.reports.show` | `module:reports`, `can:reports.view_reports` + **the report's own `permissions()`, resolved by the controller from `ReportRegistry`** |
| GET `/admin/reports/{report}/schema` | `admin.reports.schema` | the same stack |
| GET `/admin/reports/{report}/export/{format}` | `admin.reports.export` | the same stack + `can:reports.export`, `throttle:20,1` |
| GET `/admin/reports/{report}/print` | `admin.reports.print` | the same stack + `can:reports.print` |
| GET `/admin/report-exports` | `admin.report-exports.index` | `module:reports`, `can:reports.export` |
| GET `/admin/report-exports/{export:uuid}/download` | `admin.report-exports.download` | `module:reports`, `can:reports.export` + policy (`requested_by`, status, expiry, the report's own permissions) |
| DELETE `/admin/report-exports/{export:uuid}` | `admin.report-exports.destroy` | `can:reports.export` + the same policy |
| GET `/admin/analytics` | `admin.analytics.index` | `module:reports`, `can:reports.view_reports` |
| GET `/admin/analytics/chart/{chart}` | `admin.analytics.chart` | `can:reports.view_reports` + the chart's own `permission()`, `throttle:60,1` |
| GET `/admin/activity-log` *(Phase 1 route, extended)* | `admin.activity-log.index` | `can:activity_log.view_logs` |
| GET `/admin/activity-log/{activity}` *(Phase 1)* | `admin.activity-log.show` | `can:activity_log.view_logs` |
| GET `/admin/activity-log/export/{format}` | `admin.activity-log.export` | `can:activity_log.export`, `throttle:10,1` |
| GET `/admin/audit-trail` | `admin.audit-trail.index` | `module:audit_trail`, `can:audit_trail.view_logs` |
| GET `/admin/audit-trail/{activity}` | `admin.audit-trail.show` | `module:audit_trail`, `can:audit_trail.view_logs` |
| GET `/admin/audit-trail/export/{format}` | `admin.audit-trail.export` | `can:audit_trail.export`, `throttle:10,1` |
| GET `/admin/search` | `admin.search.index` | `module:global_search`, `can:global_search.view_any` - the full-page results view |
| GET `/admin/search/suggest` | `admin.search.suggest` | `module:global_search`, `can:global_search.view_any`, `throttle:60,1` - the palette's JSON |

**The palette is available in every panel**, so `{panel}.search.suggest` is registered in the same shared
group as the bell, each gated by `can:global_search.view_any` plus that panel's own scopes (§9).

### 7.9 Student panel (`routes/student.php`: `auth`, `active`, `panel:student`)

Every route is additionally scoped by `BelongsToAuthenticatedStudent` and a policy returning **404** for
another student's id (phase-14-17 §7.8's rule).

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/student/materials` | `student.materials.index` | `module:course_materials`, `can:student_portal.materials` |
| GET `/student/materials/{material}` | `student.materials.show` | `can:student_portal.materials` |
| GET `/student/materials/{material}/download` | `student.materials.download` | `can:student_portal.material_download`, `throttle:60,1` |
| GET `/student/materials/{material}/open` | `student.materials.open` | `can:student_portal.material_download` (the `link` redirect) |
| GET `/student/assignments` | `student.assignments.index` | `module:assignments`, `can:student_portal.assignments` |
| GET `/student/assignments/{assignment}` | `student.assignments.show` | `can:student_portal.assignments` |
| GET `/student/assignments/{assignment}/brief` | `student.assignments.brief` | `can:student_portal.assignments` |
| POST `/student/assignments/{assignment}/submission` | `student.assignments.submission.start` | `can:student_portal.assignment_submit` |
| PUT `/student/submissions/{submission}` | `student.submissions.update` | `can:student_portal.assignment_submit` (draft only) |
| POST `/student/submissions/{submission}/submit` | `student.submissions.submit` | `can:student_portal.assignment_submit`, `throttle:10,1` |
| POST `/student/assignments/{assignment}/resubmit` | `student.submissions.resubmit` | `can:student_portal.assignment_submit`, `throttle:10,1` |
| DELETE `/student/submissions/{submission}` | `student.submissions.withdraw` | `can:student_portal.assignment_submit` (draft only) |
| GET `/student/submissions/{submission}/files/{file}` | `student.submissions.file` | `can:student_portal.assignment_submit` |
| GET `/student/submissions/{submission}/feedback-file` | `student.submissions.feedback` | `can:student_portal.assignments` (+ `marks_released_at`) |
| GET `/student/exams` | `student.exams.index` | `module:exams`, `can:student_portal.exams` |
| GET `/student/exams/{exam}` | `student.exams.show` | `can:student_portal.exams` |
| GET `/student/results` | `student.results.index` | `module:results`, `can:student_portal.results` |
| GET `/student/results/{result}` | `student.results.show` | `can:student_portal.results` (published only) |
| GET `/student/results/{result}/card` | `student.results.card` | `can:student_portal.result_card` - forced `studentCopy()` |
| GET `/student/enrollments/{enrollment}/result-card` | `student.results.card.consolidated` | `can:student_portal.result_card` |
| GET `/student/certificates` | `student.certificates.index` | `module:certificates`, `can:student_portal.certificate` |
| GET `/student/certificates/{certificate}/pdf` | `student.certificates.pdf` | `can:student_portal.certificate` (issued only) |
| GET `/student/id-card` | `student.id-card.show` | `module:student_id_cards`, `can:student_portal.id_card` |
| GET `/student/id-card/pdf` | `student.id-card.pdf` | `can:student_portal.id_card` |
| GET `/student/tickets` · `/student/tickets/{ticket}` | `student.tickets.index` · `.show` | `module:support_tickets`, `can:student_portal.tickets` |
| GET POST `/student/tickets/create` · `/student/tickets` | `student.tickets.create` · `.store` | `can:student_portal.ticket_create`, `throttle:10,1` |
| POST `/student/tickets/{ticket}/replies` | `student.tickets.replies.store` | `can:student_portal.tickets`, `throttle:30,1` |
| GET `/student/messages` · `/student/messages/{conversation}` | `student.messages.index` · `.show` | `module:messages`, `can:student_portal.messages` |
| POST `/student/messages` · `/student/messages/{conversation}/send` | `student.messages.store` · `.send` | `can:student_portal.message_send`, matrix-checked, `throttle` from the setting |
| GET `/student/meetings` | `student.meetings.index` | `module:meetings`, `can:student_portal.meetings` |
| POST `/student/meetings/{meeting}/respond` | `student.meetings.respond` | `can:student_portal.meetings` |

### 7.10 Teacher panel (`routes/teacher.php`: `auth`, `active`, `panel:teacher`)

Every route is scoped by `TeacherScope` and a policy returning **404** outside it.

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/teacher/materials` | `teacher.materials.index` | `module:course_materials`, `can:teacher_portal.materials` |
| GET POST `/teacher/materials/create` · `/teacher/materials` | `teacher.materials.create` · `.store` | `can:teacher_portal.material_upload`, `throttle:30,1` |
| PUT `/teacher/materials/{material}` | `teacher.materials.update` | `can:teacher_portal.material_upload` (own rows) |
| POST `/teacher/materials/{material}/targets` | `teacher.materials.targets` | `can:teacher_portal.material_upload` (own batches only) |
| POST `/teacher/materials/{material}/status` | `teacher.materials.status` | `can:teacher_portal.material_upload` |
| GET `/teacher/materials/{material}/download` | `teacher.materials.download` | `can:teacher_portal.materials` |
| GET `/teacher/assignments` | `teacher.assignments.index` | `module:assignments`, `can:teacher_portal.assignments` |
| GET POST `/teacher/assignments/create` · `/teacher/assignments` | `teacher.assignments.create` · `.store` | `can:teacher_portal.assignments` |
| PUT `/teacher/assignments/{assignment}` | `teacher.assignments.update` | `can:teacher_portal.assignments` |
| POST `/teacher/assignments/{assignment}/status` | `teacher.assignments.status` | `can:teacher_portal.assignments` |
| GET `/teacher/assignments/{assignment}/submissions` | `teacher.submissions.index` | `module:assignment_submissions`, `can:teacher_portal.assignments` |
| GET `/teacher/submissions/{submission}` | `teacher.submissions.show` | `can:teacher_portal.assignments` |
| POST `/teacher/submissions/{submission}/grade` | `teacher.submissions.grade` | `can:teacher_portal.assignment_grade`, `throttle:60,1` |
| POST `/teacher/assignments/{assignment}/grade-bulk` | `teacher.submissions.grade-bulk` | `can:teacher_portal.assignment_grade` |
| POST `/teacher/assignments/{assignment}/release-marks` | `teacher.submissions.release` | `can:teacher_portal.assignment_grade` |
| GET `/teacher/submissions/{submission}/files/{file}` | `teacher.submissions.file` | `can:teacher_portal.assignments` |
| GET `/teacher/exams` · `/teacher/exams/{exam}` | `teacher.exams.index` · `.show` | `module:exams`, `can:teacher_portal.exams` |
| GET `/teacher/exams/{exam}/results` | `teacher.results.sheet` | `module:results`, `can:teacher_portal.results_entry` |
| POST `/teacher/exams/{exam}/results` | `teacher.results.save` | `can:teacher_portal.results_entry`, `throttle:30,1` |
| GET `/teacher/results` | `teacher.results.index` | `can:teacher_portal.results` |
| GET `/teacher/batches/{batch}/certificate-candidates` | `teacher.certificates.candidates` | `module:certificates`, `can:teacher_portal.certificates` |
| GET `/teacher/tickets` + create / reply | `teacher.tickets.*` | `module:support_tickets`, `can:teacher_portal.tickets` / `.ticket_create` |
| GET `/teacher/messages` + send | `teacher.messages.*` | `module:messages`, `can:teacher_portal.messages` / `.message_send` |
| GET `/teacher/meetings` + respond | `teacher.meetings.*` | `module:meetings`, `can:teacher_portal.meetings` |

### 7.11 Client and collaborator panels

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/client/tickets` · `/client/tickets/{ticket}` *(Phase 5 rows, unchanged)* | `client.tickets.index` · `.show` | `module:support_tickets`, `can:client_portal.tickets`, `client.context` |
| GET POST `/client/tickets/create` · `/client/tickets` **(write)** | `client.tickets.create` · `.store` | `can:client_portal.ticket_create`, `throttle:10,1` |
| POST `/client/tickets/{ticket}/replies` **(write)** | `client.tickets.replies.store` | `can:client_portal.tickets`, `throttle:30,1` |
| GET `/client/messages` · `/client/messages/{conversation}` *(Phase 5 rows)* | `client.messages.index` · `.show` | `module:messages`, `can:client_portal.messages` |
| POST `/client/messages` · `/client/messages/{conversation}/send` **(write)** | `client.messages.store` · `.send` | `can:client_portal.message_send`, matrix-checked |
| GET `/client/meetings` *(Phase 5 row)* | `client.meetings.index` | `module:meetings`, `can:client_portal.meetings` |
| POST `/client/meetings/{meeting}/respond` **(write)** | `client.meetings.respond` | `can:client_portal.meeting_respond` |
| GET `/client/meetings/{meeting}/ics` | `client.meetings.ics` | `can:client_portal.meetings` |
| GET `/collaborator/tickets` + create / reply | `collaborator.tickets.*` | `module:support_tickets`, `can:collaborator_portal.tickets` / `.ticket_create` |
| GET `/collaborator/meetings` · `/collaborator/meetings/{meeting}` | `collaborator.meetings.index` · `.show` | `module:meetings`, `can:collaborator_portal.meetings` |
| POST `/collaborator/meetings/{meeting}/respond` | `collaborator.meetings.respond` | `can:collaborator_portal.meeting_respond` |
| GET `/collaborator/messages` + send | `collaborator.messages.*` | `module:messages`, `can:collaborator_portal.messages` |

### 7.12 Public - certificate and ID-card verification (§84)

`routes/web.php`, guest-safe, no `auth`, no session requirement beyond CSRF on the POST.

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/verify` | `site.verify.form` | `module:certificates`, `site` |
| POST `/verify` | `site.verify.submit` | `module:certificates`, `site`, `throttle:institute.certificate_verification_rate_limit_per_minute` |
| GET `/verify/{code}` | `site.verify.show` | `module:certificates`, `site`, the same throttle - **the QR target**, so a phone camera reaches the result in one hop |

`{code}` is constrained to `[A-Z0-9]{16}` at the route level, so a malformed code never reaches the database.
The response sends `X-Robots-Tag: noindex` and no `Link: canonical`, so a verification result never enters a
search index; the landing `/verify` form itself is indexable.

---

## 8. UI screens

House rules from Phase 1 §9 and `CLAUDE.md` §6 apply to every screen below and are not repeated per screen:
`x-ui.*` components only (Phase 3 §13 froze that set; these phases add **new namespaces** -
`components/material/`, `components/print/`, `components/support/`, `components/search/` - and modify no
`x-ui.*` component), search + filters + sortable headers + pagination + empty state + skeleton loader on
every list, tables inside `overflow-x-auto`, money and marks right-aligned with `tabular-nums`, a toast on
every write, `x-ui.confirm` with a **mandatory reason field** on every irreversible action, light and dark,
mobile first.

**Where a Kanban, a calendar or a wizard appears, and where it deliberately does not:**

| Pattern | Used for | Why |
|---|---|---|
| **Calendar** | the exam schedule (§8.6), meetings (§8.12) | both are dated entities the requirement renders as a schedule, and phase-14-17 §8.13 already established the month / week / day / agenda component built from `x-ui.card` + a CSS grid |
| **Wizard** | share material (3 steps, §8.2), batch-wide result entry (3 steps, §8.7), bulk certificate issue (3 steps, §8.9), batch ID-card print (3 steps, §8.11), new conversation (2 steps, §8.13) | each is a multi-entity action with a **preview-before-write** step, the pattern the spine §8.1 and Phase 18 §8.3 established |
| **No Kanban** | tickets | §93 asks for statuses, assignment and SLA - not a board. The requirement's Kanbans are leads (§18) and tasks (§22). A ticket board would be invented scope; the queue gets saved-view tabs instead |
| **No Kanban, no calendar** | assignments, materials, certificates | §79, §80 and §84 ask for lists, a deadline and a register |

### 8.1 Material library - `admin.course-materials.index`

**Purpose.** Find, share and audit every distributable file of §79.
**Components.** `x-ui.page-header` (Share material), `x-ui.filter-bar`, `x-ui.tabs` (All · Published ·
Drafts · Scheduled · Archived), `x-ui.table` with a card/grid toggle, `x-ui.badge`, `x-ui.empty-state`,
`x-ui.skeleton`, and a new `<x-material.type-icon>` (one glyph per `CourseResourceType`).
**Filters.** Course, batch, topic, type (multi), audience scope, status, teacher / uploader, availability
window (`available now` · `scheduled` · `expired`), branch, text search on title and description.
**Columns.** Type icon · Title (+ file size and extension, or the link host) · Course · Audience chips
(`Course` / `B: WEB-101-B7` / `S: Ahmed`) · Topic · Status badge · Available from → until · Opens
(`view_count`) · Downloads · Unique students (`unique_students_count` / targeted) · Updated · actions
(Download · Targets · Publish/Unpublish · Edit · Engagement · Delete).
**Empty state.** "No material shared yet - upload a PDF, a video or paste a link, and choose who sees it",
with a primary *Share material* button.
**Bulk.** Publish, archive, re-target (opens the targeting step for the selection) - each behind
`x-ui.confirm` naming the count.

### 8.2 Share-material wizard - `admin.course-materials.create` (3 steps)

| Step | Contents | Validation shown inline |
|---|---|---|
| 1 **What** | Type (`CourseResourceType` radio cards), then either a drop-zone (`<x-material.dropzone>` over `x-ui.form.file`, showing the effective size limit and the accepted extensions resolved from the settings) or a URL field; title, description, course, topic | the sniffed MIME and the real size appear **after** upload; a refused file names the rule that refused it (extension, MIME mismatch, size, double extension) |
| 2 **Who** | Audience picker: *Whole course* / *Selected batches* (multi-select of the course's batches, each showing enrolled count) / *Selected students* (searchable, scoped to the course's active enrollments). Several targets may be combined; a chip list shows the resulting audience and the **distinct student count** | a batch of another course cannot be picked (absent from the list, and refused server-side) |
| 3 **When** | Publish now / save as draft / schedule (`available_from`, `available_until`), `is_downloadable` toggle with its honest help text ("view-only is a deterrent, not protection - a determined user can still capture the file"), notify toggle (default from `institute.material_notify_on_publish`) | a window that ends before it starts is refused |

### 8.3 Material detail and engagement - `admin.course-materials.show` / `.engagement`

**Detail.** File card (icon, name, size, MIME, checksum, uploader, timestamps), the audience chip list with
per-target add/remove, the availability window, a *Download* button, and - for `pdf`/`image` - an inline
preview iframe served by the same authorised stream.
**Engagement.** `x-ui.stat-card` ×4 (targeted students · opened · not opened · total downloads), a
`x-ui.table` of students with first open, last open, count and device, a *Not opened* tab with a
"remind these students" action (one notification, `material.reminder`), and `x-ui.chart` of opens per day.
**Empty state.** "Nobody has opened this yet."

### 8.4 Assignments index and detail - `admin.assignments.index` / `.show`

**Index filters.** Course, batch, teacher, status, deadline range, late-allowed, *needs grading*, branch.
**Index columns.** Title · Course / batch · Teacher · Deadline (with a relative "in 3 days" / "2 days ago")
· Total marks · Expected · Submitted (+ late) · Graded · Missed · Average · Status badge · actions
(Submissions · Publish/Close · Edit · Print · Duplicate).
**Detail.** Header with the deadline countdown and a *Needs grading: n* pill; tabs -
**Brief** (description, instructions, the brief file, submission rules rendered as plain sentences:
"one file or text · up to 3 files · 20 MB each · pdf, zip, docx · late allowed until 25 Sep 23:59 with a
10 % penalty"), **Submissions** (§8.5), **Statistics** (`x-ui.chart` of the mark distribution, the four
counts), **Activity** (the `ActivityLogService::timelineFor()` embed).
**Empty state (submissions).** "No submissions yet - the deadline is in 3 days."

### 8.5 Grading screen - `admin.assignment-submissions.index` and `.show`

**Purpose.** Mark a whole batch quickly without ever exceeding the total (§80).
**Components.** `x-ui.tabs` (Ungraded · Graded · Late · Missed · All), `x-ui.table`, a split-pane detail
(`x-ui.card` left: the submission; right: the marking form), `x-ui.badge`, `x-ui.confirm`.
**Columns.** Student (+ roll no) · Attempt · Submitted at · Late (badge with the minutes) · Files · Text ·
Marks · Penalty · Final · % · Status · Graded by · actions.
**Marking form.** `obtained_marks` (a number input with `max = total_marks` **and** a live "x of 40" hint;
exceeding it disables the button and shows the server's message), the computed penalty and final marks shown
read-only as the value is typed, `feedback` textarea, an optional feedback file, *Save* / *Save and next*
(keyboard `ctrl+enter`), *Return for rework*, and - when marks were already released - an *Amend* path that
demands a reason.
**Bulk grade.** A compact grid (student × marks × feedback) with per-row inline errors; the whole grid is
validated before anything is written, and the error summary names each offending row.
**Files.** Each file row shows the name, size, type icon and a **duplicate-checksum warning chip** when
another student in the same assignment submitted identical bytes - phrased as "identical file to Ahmed
Raza's submission", never as an accusation.
**Empty state.** "Nothing to grade."

### 8.6 Exams index, calendar and detail

**Index filters.** Course, batch, exam type, status, teacher, date range, branch, *results pending*.
**Index columns.** Name · Type badge · Course / batch · Date + time · Duration · Total / passing marks ·
Expected · Entered · Appeared · Pass rate · Status badge · actions (Results · Edit · Status · Reschedule).
**Calendar.** Month / week / day / agenda over `scheduled_date`, one chip per exam coloured by
`ExamType::color()`, a batch and a teacher filter, a "conflicts" ribbon when two exams share a teacher or a
room, click → detail. Built from the same component as phase-14-17 §8.13, not a second calendar.
**Detail.** Header (status stepper `draft → scheduled → conducted → marking → published` rendered as
`x-ui.tabs`-style breadcrumbs), cards for the schedule, the marks scheme (total / passing / scale with its
band legend), the roster count, and the statistics once entered; panels for *Result sheet*,
*Result cards*, *Activity*.
**Empty state.** "No exams scheduled for this batch."

### 8.7 Batch-wide result entry - `admin.results.sheet` (the §20 sheet, 3 steps)

**Purpose.** Enter a whole batch's marks once, safely (§82).
**Step 1 - Sheet.** A sticky-header grid: one row per roster student (photo, name, roll no, student code),
then `attendance_status` (a four-way segmented control: Appeared · Absent · Exempt · Debarred),
`obtained_marks` (numeric, `max = total_marks`, `inputmode="decimal"`), the live `percentage`, the live
`grade` chip resolved from the scale client-side **and** re-resolved server-side, `remarks` (prefilled from
the band's `remark_template`), and the student's previous exam marks as a grey reference column. A header
strip shows *Total marks 40 · Passing 16 · Scale DEFAULT · Entered 12 / 25*. Marking a row Absent clears and
disables its marks box. Entering a value above the total turns the cell rose, shows "max 40" and blocks the
save button - and the server refuses it anyway (INV-19-6's sibling for exams).
**Step 2 - Review.** A read-only summary: counts, average, pass rate, the grade histogram, and a list of
anomalies ("3 students have no marks", "1 student scored 0 while marked Appeared") - warnings, not blocks,
except the missing rows when publishing.
**Step 3 - Save / verify / publish.** Save (keeps `marking`), then *Send for verification* when
`institute.result_publish_requires_verification` (the verifier must be a different user - the button is
disabled for the enterer with the reason on hover), then *Publish* behind `x-ui.confirm` that states plainly
what publishing does: "25 students will be notified and will see their marks; ranks will be computed."
**Import.** A *Download template / Upload CSV* pair above the grid; the upload shows a per-row error table
and writes nothing until every row is valid.
**Empty state.** "This batch has no active students on the exam date."

### 8.8 Result card - `admin.results.card` / `.card.consolidated` / `.cards.batch`

A print view over `layouts/print.blade.php` and the `result_card` print template: institute header and logo
from the `company` / `branding` settings, the student block, the result table (one row for a single exam, one
row per exam plus the aggregate for the consolidated card), the grade-scale legend, attendance and position
when their settings allow, remarks, signatories, and a footer with "generated at" and the issuing user.
Batch printing emits one PDF with a page break per student. A reprint is stamped *Reprint #n*. The student
panel renders the identical partials through `ResultCardOptions::studentCopy()`.

### 8.9 Certificates - index, eligibility, detail, bulk issue

**Index filters.** Status, course, batch, branch, grade, issue date range, *has verifications*, text search
on number, code and student name.
**Index columns.** Certificate no · Student (+ code) · Course / batch · Trainer · Completion date · Grade ·
Issued on · Status badge (`draft` slate, `issued` emerald, `revoked` rose) · Verifications · actions
(View · Issue · Print · PDF · Revoke · Reissue).
**Eligible list - `admin.certificates.eligible`.** The operational screen: one row per completed enrollment
with a **rule chip set** (attendance 82 % ✓ · progress 100 % ✓ · exams passed ✓ · fee cleared ✗), a
*Draft certificate* action on eligible rows, and an *Override* action (behind `certificates.approve`, with a
mandatory reason) on ineligible ones. Filters: course, batch, branch, *eligible only*, *blocked by* (a
select over the rule keys).
**Detail.** The rendered certificate preview (an iframe of the authorised render), the snapshot table (every
`*_snapshot` column, labelled "as printed"), the eligibility report as it was at issue, the QR with its
`qr_payload` shown as text so it can be checked, the verification log (`certificates.view_logs`), the print
history, and - when revoked - a rose banner with the date, actor and reason.
**Bulk issue wizard (3 steps).** 1 Select a batch → the eligible list with checkboxes and the blocking rule
per row; 2 Review: the count, the template, the numbering preview ("CERT-25-00041 … 00068"); 3 Issue, with a
progress result table (issued / skipped + reason / failed + error). Bounded per request; the rest is queued.
**Empty state.** "No certificates issued yet" / "No student has completed this batch yet."

### 8.10 Public verification page - `site.verify.*` (§84)

**Purpose.** Let an employer confirm a certificate in one scan, and learn nothing else.
**Layout.** `layouts/site.blade.php`, `<x-site.seo>` with `noindex`, a single centred card.
**Form.** One code field (16 characters, auto-uppercasing, dash-tolerant), a *Verify* button, and one line of
help: "Enter the code printed on the certificate, or scan its QR code."
**Valid result.** A large emerald *Verified* panel: the certificate number, and **only** the fields
`institute.certificate_verification_reveals` permits (by default the student name, the course, the completion
date and the grade), plus "issued by {company name} on {date}". No phone, no email, no CNIC, no address, no
guardian, no fee, no collaborator, no internal id, and **no link to the PDF**.
**Revoked result.** A rose panel: "This certificate was revoked on {date}." plus the reason, and the same
minimal field set - because hiding a revocation would be the one dishonest outcome.
**Not found.** A slate panel: "No certificate matches this code." - identical wording and identical timing
for an unknown code, a `not_public` certificate and a malformed code, so the page cannot be used to
enumerate which numbers exist.
**Throttled.** "Too many attempts. Please try again in a minute."
**ID card.** The same endpoint resolves a card code and shows the card panel (name, student code, course,
batch, validity, status) under the same whitelist discipline.

### 8.11 Student ID cards - index and batch print

**Index filters.** Status, course, batch, branch, validity (`valid` · `expiring in 30 days` · `expired`),
*printed / never printed*, text search.
**Index columns.** Card no · Student (+ code, photo thumb) · Course / batch · Issued on · Valid until ·
Status badge · Prints · actions (View · PDF · Print · Replace · Revoke).
**Detail.** A live card preview at true size, the snapshot table, the QR, the print history, the replacement
chain.
**Batch print wizard (3 steps).** 1 Scope: batch / course / status filter, with the resulting count and the
ceiling from `institute.id_card_batch_print_max` shown plainly; 2 Review: the template, the sheet layout
(how many cards per A4 page), a thumbnail grid, and a list of students **excluded for want of a photo**;
3 Print: one PDF, with every card's `print_count` incremented and one activity row written.
**Empty state.** "No ID cards issued - issue one from a student's profile or print a whole batch."

### 8.12 Tickets and meetings

**Ticket queue - `admin.tickets.index`.** Tabs as saved views: *Unassigned · Mine · Awaiting us ·
Awaiting requester · Breaching soon · Resolved · All*. Filters: department, status, priority, assignee,
requester panel, branch, project, course, SLA state, date range, text search on number and subject.
Columns: Ticket no · Subject (+ the requester's panel chip) · Requester · Department · Priority badge ·
Status badge · Assignee · Last reply (+ who and which side) · First response due (a countdown that turns
amber then rose, and reads *paused* while `waiting`) · Resolution due · Age. Row actions: Open · Assign ·
Status. Bulk: assign, change priority, change department (each with a reason).
**With `support.sla_enabled = false`** the *Breaching soon* tab, the SLA-state filter, the two due-date
columns, the breach badge, the detail sidebar's SLA card and the whole `admin.tickets.sla` screen are
**absent** (not blank, not zeroed) - the queue keeps working on status, priority and last reply alone.
**Ticket detail - `admin.tickets.show`.** A two-column layout: left the **timeline** (description, then
public replies and internal notes interleaved with system entries, internal notes visually distinct with an
amber rail and an "internal - the requester cannot see this" label), a reply composer with a
*Public reply / Internal note* toggle, a drop-zone, and canned *Resolve and reply* / *Ask requester and set
waiting* actions; right a sticky sidebar with the requester card (name, panel, client / student /
collaborator link, other open tickets), department, priority, assignee, the SLA card (two clocks with their
pause state), the linked project / course / batch, attachments, and *Book a meeting from this ticket*.
**SLA dashboard - `admin.tickets.sla`.** `x-ui.stat-card` ×4 (open, breaching in 2 h, breached today,
median first response), `x-ui.chart` of volume and breach rate, and tables by agent and by department.
**Meetings - `admin.meetings.index` / `.calendar` / `.show`.** Index columns: Title · When (+ duration) ·
Mode · Where (room or a *Join* button for online) · Organizer · Participants (avatar stack + count) ·
Responses (3/5 accepted) · Linked to (project / course / client chip) · Status badge. Calendar: month /
week / day / agenda, colour by status, a *My meetings only* toggle, drag to reschedule behind
`x-ui.confirm` + a reason. Detail: the when/where card with *Join*, *Add to calendar (.ics)* and *Copy
link*; the participant table with type, role, response and an attendance checkbox; the agenda and a notes
editor (staff only); attachments; the reschedule chain.
**Empty states.** "No tickets - that is a good sign." / "No meetings scheduled."

### 8.13 Internal messaging - `{panel}.messages.*` (§94)

**Layout.** A two-pane inbox inside `layouts/admin` / `layouts/panel`: left a conversation list (avatar or
avatar stack, title, the last message snippet, relative time, an unread pill), right the thread.
**Thread.** Day separators, messages grouped by sender, the sender's **panel chip** on every group (so a
student always knows they are talking to "Institute staff"), read receipts as a subtle "Seen by 2" under the
last own message, attachment cards with the authorised download, and a composer with a text area
(`ctrl+enter` sends), an attachment button (hidden when `support.messaging_attachments_enabled` is false)
and a live character counter.
**New conversation wizard (2 steps).** 1 **Recipient** - a searchable picker served by
`admin.messages.recipients`, which returns **only** users `MessagingMatrix::mayStart()` allows, grouped by
panel, each row showing the pair that authorises it ("Institute staff"); a name the matrix forbids is simply
**not in the list**, and the search box says so in one line: "You can message institute staff and your
teachers." 2 **Message** - subject (groups only) and the first message.
**Refusal.** Any attempt to post to a forbidden pair (a replayed request, a tampered id) returns 403 with
`ConversationScope::describe()`'s plain sentence - "Students and clients cannot message each other." - and
writes an activity row.
**Closed thread.** Readable, with the composer replaced by "This conversation was closed on {date}."
**Empty states.** "No conversations yet." / "Say hello."

### 8.14 Notification bell, index and preferences (§97)

**Bell** (`components/support/bell.blade.php`, in the topbar of all five panels). An icon button with an
unread count badge (`99+` above 99, coloured by the **highest unread level**), an Alpine dropdown listing
`support.notification_bell_page_size` rows - icon by `NotificationGroup`, title, one-line body, relative
time, a dot for unread - with *Mark all read* and *See all*. It polls `{panel}.notifications.bell` every 60
seconds (and immediately after any write in the tab), sends `If-None-Match`, and stops polling when the tab
is hidden.
**Index.** Tabs *Unread · All · Archived*; filters by group, level and date range; grouped by day; row
actions *Open* (which marks read and re-authorises the target), *Mark read/unread*, *Archive*; bulk
*Mark all read* and *Archive read*. Empty state: "You are all caught up."
**Preferences - `account.notification-preferences.edit`.** A table of the registry's events grouped by
`NotificationGroup`: event title + description, an In-app toggle, a Mail toggle (disabled with the reason
when `support.notifications_mail_enabled` is false), and a digest select. Mandatory events render locked
with a lock icon and the tooltip "You will always be told about this." A *Reset to defaults* action behind
`x-ui.confirm`. Only the events `NotificationRegistry::forUser()` returns are listed, so a student never
sees a payout row.

### 8.15 Reports hub, report screen, exports (§99)

**Hub - `admin.reports.index`.** Three or four `x-ui.card` sections by `ReportGroup` (the group's label reads
the company / institute name from settings), each a grid of report tiles: icon, title, one-line description,
and a *last run* hint from `users.preferences`. Only `ReportRegistry::visibleTo()` tiles render; a tile whose
source phase has not shipped renders disabled with "arrives with Phase N". A search box filters tiles.
**Report screen - `admin.reports.show`.** A single generic Blade driven by `ReportEngine::describe()`:
`x-ui.page-header` with the report title and the export menu (Print · PDF · CSV · Excel, each hidden when
the format or the permission is absent); a `x-ui.filter-bar` built from `filters()` plus the §99 date-range
control (Today · Yesterday · This week · This month · This year · Custom, with a *compare to previous
period* toggle where the report supports it); an optional `x-ui.chart` from `chart()`; the table from
`columns()` with sortable headers, group-by sub-totals, a sticky totals row using `tabular-nums`, and a
**"columns" picker** persisted per user in `users.preferences`; `x-ui.pagination-summary`; a `meta` strip
under the table stating the date column used, the filters in force and any **omitted columns or sources**
("amounts hidden - you do not hold view_financial") so a partial total can never be read as a full one.
**Large result.** Above `reports.sync_row_limit` the screen shows the first page plus a banner: "This report
has 42,180 rows. Export it and we will notify you when the file is ready." - and the export button switches
to *Queue export*.
**Exports register - `admin.report-exports.index`.** Report, format, filters summary, rows, size, requested
at, status badge, expires in, *Download*. A failed row shows the real error message. Empty state: "No
exports yet."

### 8.16 Analytics - `admin.analytics.index` (§98 charts)

A responsive widget grid of `x-ui.chart` cards fed by `AnalyticsService`, with one global `DateRange`
selector at the top feeding every chart, a branch filter, and per-chart skeletons while the JSON loads.
Sections: *Institute* (admissions trend, course-wise students, fee collection, attendance, exam pass-rate
trend, grade distribution, certificate issuance), *Software house* (revenue vs expense, project pipeline,
lead conversion), *Collaborator* (referred students, commission accrued vs paid, contribution share),
*Support* (ticket volume and SLA, meeting load, notification health). A chart whose module is disabled or
whose permission is missing is **absent**, and the section header disappears with its last chart. Charts a
previous phase already registered are rendered from that registration, never re-implemented.

### 8.17 Activity log and audit trail (§106, §107)

**Activity log - `admin.activity-log.index`** (Phase 1's screen, extended). Filters: user / causer, module,
log name, event, subject type, date range, IP, device, "has a reason", free text. Columns: When (absolute +
relative) · User (avatar + name + role) · Module · Event badge · Description · Subject (a link when the
viewer may open it) · IP · Device. A row expands in place to show the full property bag, the user agent and
the reason. Export to CSV / PDF. Empty state: "No activity matches these filters."
**Audit trail - `admin.audit-trail.index`.** The §107 view: the same filter rail plus a *sensitivity* filter
(normal · sensitive · financial) and a *field* filter. Each row renders **what changed** as a compact diff -
`Commission rate 10.0000 % → 15.0000 %`, old in rose with a strike, new in emerald - plus who, when, from
which IP, and the **reason**. A financial value the viewer may not see renders as `••••` with a tooltip
naming the permission required (withheld, not blanked into a zero). The detail screen shows the full
field-by-field table, the subject's link, and the surrounding activity of that subject. The §107 examples
are all reachable from here: *student collaborator A → B*, *project commission 10 % → 15 %*,
*result 62 → 67*, *certificate revoked*, *fee discount approved*.
**Both screens are read-only.** There is no delete, no edit and no "clear log" button anywhere (INV-23-5).

### 8.18 Global search palette (§108)

**Trigger.** `Ctrl/Cmd + K` anywhere in any panel (registered once in the shared topbar), the topbar search
box, or `/` when no input is focused. Built as `components/search/palette.blade.php` with Alpine - a modal
over `x-ui.modal` with `role="dialog"`, `aria-modal`, a focus trap and `aria-live` result announcements.
**Behaviour.** Debounced by `reports.global_search_debounce_ms`; a minimum of
`reports.global_search_min_chars`; results grouped by `SearchEntityType` with the entity's icon and label,
`reports.global_search_per_entity_limit` rows per group; each row shows title, subtitle and a badge (status,
code); `↑` `↓` move, `Enter` opens, `Tab` cycles entity groups, `Esc` closes; entity **filter chips** along
the top toggle groups (persisted in `users.preferences`); a *Recent* list when the box is empty; an exact
document-number match is pinned to the top as a "Jump to" row. A group whose provider is unavailable shows
one grey line ("clients unavailable"), never an error toast.
**Empty state.** "No matches for 'xyz'" plus the three entity types the viewer may actually search, so the
absence is informative rather than mysterious.
**Full page - `admin.search.index`.** The same results paginated per entity, with each group's "see all in
{module}" link carrying the term into that module's own index filter.

### 8.19 Panel screens these phases add (§73, §74)

| Panel | Screen | Contents |
|---|---|---|
| Student | **Materials** | the §6.6 query as a grid grouped by course then topic, type icons, a *New* dot for anything published since the last visit, a search box, and per-row *Download* / *Open link*. A material withheld by the fee block shows a lock with the plain reason. Empty: "Your teacher has not shared material yet." |
| Student | **Assignments** | tabs *To do · Submitted · Graded · Missed*; cards with the deadline countdown (rose inside 24 h), total marks, the submission rules in one sentence, and the state chip. Detail: the brief + file, a submission form (text area and/or drop-zone per `submission_type`, showing the remaining attempts), the late warning **before** submitting ("you are 2 days late - a 10 % penalty applies"), and after grading the marks, the penalty, the final marks, the percentage and the teacher's feedback with its file |
| Student | **Exams** | upcoming and past exams for their own batches: name, type, date, time, duration, room or join link, total and passing marks, instructions. No other student's data |
| Student | **Results** | **published results only**, one card per exam (obtained / total, percentage, grade chip, pass-fail, position when the setting allows, remarks) plus the consolidated aggregate and a *Download result card* button. A batch average is shown only as an anonymous figure - never a classmate's name or mark |
| Student | **Certificate** | the issued certificate card with its number, grade, completion date, a *Download PDF* button and the verification link they can share. When none exists: the eligibility checklist as read-only progress ("attendance 68 % - 75 % needed"), which is the single most useful screen in the panel |
| Student | **ID card** | a true-size preview and a *Download PDF*; when expired, the plain "ask the office for a replacement" line |
| Teacher | **Materials** | their batches' library plus their own drafts, with the same share wizard limited to their own batches and students |
| Teacher | **Assignments** | create / publish / close for their own batches, a *Needs grading* count per assignment, and the §8.5 grading screen restricted to their batches |
| Teacher | **Exams / Results** | their own batches' exams and the §8.7 sheet, gated by `teacher_portal.results_entry`; publishing stays with staff unless the teacher also holds `results.change_status` |
| Teacher | **Certificate candidates** | their batches' eligible students with the rule chips - read-only, no issue button |
| Client | **Tickets / Messages / Meetings** | the Phase 5 read screens, now with *New ticket*, a reply composer, a message composer and *Accept / Decline* on an invitation. No internal note is ever rendered |
| Collaborator | **Tickets / Messages / Meetings** | the §36 tiles become real: their own tickets, their own threads with authorised staff, and their meetings with *Accept / Decline* and `.ics` |

### 8.20 Dashboard widgets registered into Phase 2's `DashboardRegistry`

| Widget | Panel | Permission | Shows |
|---|---|---|---|
| `UngradedSubmissionsWidget` | admin, teacher | `assignment_submissions.view_any` / `teacher_portal.assignment_grade` | submissions awaiting marking, oldest first |
| `UpcomingExamsWidget` | admin, teacher, student | `exams.view_any` / the portal reads | the next five exams with date and batch |
| `ResultsAwaitingPublicationWidget` | admin | `results.change_status` | exams in `marking` with their entered / expected counts |
| `CertificatesEligibleWidget` | admin | `certificates.create` | completed enrollments that pass every rule |
| `OpenTicketsWidget` | admin | `support_tickets.view_any` | open / breaching / unassigned counts |
| `TicketSlaWidget` | admin | `support_tickets.view_reports` | today's breach rate and the median first response |
| `UpcomingMeetingsWidget` | all five | `meetings.view` / the portal reads | the viewer's next meetings with a *Join* button |
| `UnreadMessagesWidget` | all five | `messages.view` / the portal reads | unread conversations |
| `MaterialEngagementWidget` | admin, teacher | `course_materials.view_reports` | the least-opened recent materials |
| `ExamPassRateChartWidget` · `GradeDistributionChartWidget` · `TicketVolumeChartWidget` · `CertificateIssuanceChartWidget` | admin | `reports.view_reports` | the §8.16 charts, reusable on the master dashboard |

---

## 9. Data isolation

Every rule is an Eloquent **global scope plus a Policy check**, never a hidden form field (`CLAUDE.md` §1.10),
and every rule has a feature test asserting the status code **and** the absence of the forbidden columns from
the response body (INV-ALL-3). Ownership failures return **404**; permission failures return **403**.

### 9.1 Phase 19 - materials, assignments, submissions

| Role | Exact query scoping |
|---|---|
| **Super Admin** | unrestricted, still subject to module gating (a disabled `course_materials` 403s them too, data intact) |
| **Admin** | unrestricted within Phase 1 §5's grants |
| **Institute Manager / Course Coordinator** | `where branch_id IS NULL OR branch_id = user.branch_id` on `course_materials`, `assignments`, `assignment_submissions` (via the assignment) - applied by the scope, not the controller |
| **Teacher** | `TeacherScope`: `course_materials` → rows whose targets resolve to `TeacherScope::batchIds($teacher)`, **plus** their own rows of any status (so a draft is visible to its author); `assignments` → `batch_id IN batchIds`; `assignment_submissions` → through that assignment. Writing needs `teacher_portal.material_upload` / `.assignments` / `.assignment_grade`. A material targeted at a batch they do not teach is **404** |
| **Student** | `BelongsToAuthenticatedStudent` + the §6.6 query; `assignments` → `batch_id IN` their active (or in-grace) enrollments **and** `status IN (published, closed)`; `assignment_submissions` → `student_id = own` **only**, and the SELECT is an explicit column list **omitting** `graded_by`, `amendment_reason`, and omitting `obtained_marks`, `penalty_marks`, `final_marks`, `percentage`, `is_passed`, `feedback` and `feedback_file_path` until `marks_released_at IS NOT NULL`. Another student's submission, file or feedback is **404**. A draft or archived assignment is **404** |
| **Client** | **403 on every route in Phase 19.** A client has no relationship to a course batch |
| **Collaborator** | **403 on every route in Phase 19.** §57-§59 grant a collaborator a student's *name, course, batch, registration date, status, paid total and commission* - never their coursework, marks or files |
| **Branch (D11)** | when `users.branch_id` is set, every query adds `where branch_id IS NULL OR branch_id = user.branch_id`; `branch_id` is stamped by the service from the batch / course, never from the form |
| **Module gating** | disabling `course_materials`, `assignments` or `assignment_submissions` 403s those routes for everyone through `Gate::before`, leaving every row and every file intact |

### 9.2 Phase 20 - grade scales, exams, results

| Role | Exact query scoping |
|---|---|
| **Institute Manager / Course Coordinator** | branch scope on `exams` and (through the exam) `exam_results`. `grade_scales` are **global configuration**, readable by anyone with `grade_scales.view_any`, writable only with `grade_scales.edit` |
| **Teacher** | `exams` and `exam_results` restricted to `TeacherScope::batchIds()`; may enter a sheet with `teacher_portal.results_entry` and read with `.results`; **cannot publish** unless separately granted `results.change_status`; cannot verify their own entry when verification is required (the service checks `actor <> entered_by`) |
| **Student** | `exam_results` → `student_id = own` **and** `published_at IS NOT NULL`; the SELECT omits `entered_by`, `verified_by`, `amended_by`, `amendment_reason`. `exams` → only exams of their own batches. A sibling's result, an unpublished result and another batch's exam are all **404**. `position_in_batch` is rendered only when `institute.result_card_show_position`; the batch average is shown as an anonymous number and **never** as a ranked list of names |
| **Client / Collaborator** | **403 on every route in Phase 20** |
| **Accountant / HR** | no access to `exams` or `results` (403); the §99 `in.results` report needs `results.view_reports`, which they are not granted |
| **Branch** | `where branch_id IS NULL OR branch_id = user.branch_id` on `exams`; `exam_results` inherit it through the exam |

### 9.3 Phase 21 - templates, certificates, ID cards, the public page

| Role | Exact query scoping |
|---|---|
| **Institute Manager** | branch scope on `certificates` and `student_id_cards`; `print_templates` scoped `branch_id IS NULL OR = user.branch_id` |
| **Course Coordinator** | the same read scope; may draft a certificate but not issue one (no `certificates.approve`, and `change_status` withheld by the role grant) |
| **Receptionist** | `certificates` read + print, `student_id_cards` full - branch-scoped. No `certificates.change_status` |
| **Teacher** | `certificates` read-only for students of `TeacherScope::batchIds()` (candidate list + issued list); **403** on issue, revoke, reissue, templates and ID cards |
| **Student** | `certificates` → `student_id = own AND status = issued`; `student_id_cards` → `student_id = own`. The response omits `eligibility_snapshot`, `issued_by`, `revoked_by`, `print_count`, `verification_count` and `notes`. Another student's certificate, PDF or card is **404** |
| **Client / Collaborator** | **403** on everything in Phase 21 |
| **Anonymous (the public page)** | no model is ever bound: `CertificateVerificationService` looks the code up itself and returns the whitelisted payload of INV-21-3. No list endpoint, no id in any URL, no enumeration (an unknown, non-public and malformed code are indistinguishable in wording and timing), rate-limited per IP, every attempt logged. The certificate **PDF is never served publicly** |
| **Branch** | stamped from the student's branch at draft time and scoped thereafter |

### 9.4 Phase 22 - tickets, meetings, messaging, notifications

| Role | Exact query scoping |
|---|---|
| **Support Agent / staff with `support_tickets.view_any`** | every ticket, branch-scoped when `users.branch_id` is set. `internal_note` replies visible |
| **Staff with only `support_tickets.view`** | `where assigned_to = auth()->id() OR created_by = auth()->id() OR user_id = auth()->id()` - the `leads` [D-P5-8] precedent |
| **Client** | `support_tickets.client_id = ClientContext::clientId()` **and**, when `is_private_to_creator`, `user_id = auth()->id()` (phase-05 §12 Q3's shape). Replies filtered to `visibility = public`. `meetings` → `client_id = own OR EXISTS(meeting_participants WHERE user_id = auth id)`; `meetings.notes` render only when the client is a participant and the meeting is `completed`. `conversations` → **`conversation_participants.user_id = auth()->id()`, never `client_id`** (a conversation is personal, not corporate - phase-05 §9.2). `attachments` → **`visibility = AttachmentVisibility::Client`** (the enum column of phase-06 §2.9 - there is no `is_client_visible` boolean, F-2.6) **and** the owner resolves to one of their own tickets / projects, re-checked before the stream |
| **Student** | `support_tickets` → `user_id = auth()->id()` (a student has no company to share with); public replies only. `meetings` → participant rows only. `conversations` → participant rows only, and `MessagingMatrix` refuses a thread with a client, a collaborator or another student, on create **and** on send |
| **Teacher** | `support_tickets` → `user_id = own`, plus tickets about their own batches when granted `support_tickets.view`. `meetings` → participant or `course_id`/`batch_id` within `TeacherScope`. Messaging per `teacher_management` and `student_staff` |
| **Collaborator** | `support_tickets` → `user_id = own` (**not** `collaborator_id`, so a second login of the same partner firm cannot read a colleague's ticket unless they are the requester). `meetings` → participant rows or `collaborator_id = own` when they are a participant; a meeting they are not in is **404**. `conversations` → participant rows; `collaborator_staff` only. **No access to any student's coursework, marks, certificate or fee row** |
| **Everybody, notifications** | `notifications.notifiable_id = auth()->id() AND notifiable_type = User::class`. There is **no** route by which one user reads another's notification row; the admin `notifications.view_any` register shows aggregate counts per event, **not** other users' payloads. `notification_preferences` → own rows only, no permission needed |
| **Messaging, in every role** | `threadsFor()` is always `conversation_participants.user_id = auth()->id() AND left_at IS NULL`; `messages.view_any` exists for a future compliance reader and is granted to **nobody** by default, and even then it is read-only and logs every read as an activity row |
| **Branch** | `support_tickets` and `meetings` carry `branch_id` from the requester / organizer; institute staff queries add the D11 clause. A collaborator, client or partner is **not** branch-bound |
| **Module gating** | disabling `support_tickets`, `meetings` or `messages` 403s their routes for everyone; disabling `notifications` additionally makes `NotificationService` a logged no-op (§4.4) so no business write ever fails because the bell is off |

### 9.5 Phase 23 - reports, analytics, logs, search

| Role | Exact query scoping |
|---|---|
| **Every report, every role** | `ReportEngine` applies, in this order: (1) `reports.view_reports`; (2) the report's module gate; (3) the report's own `permissions()`; (4) **the source module's own isolation scope** - `in.students` runs through `StudentDirectoryService`'s scope, `sh.projects` through the project member scope, `co.*` through `BelongsToAuthenticatedCollaborator` when the viewer is a collaborator; (5) the branch clause; (6) column-level `permission()`, with a withheld column **absent from the SELECT and from the file** |
| **Accountant** | the finance, fee and collaborator-money reports with `view_financial`; **no** institute coursework or result report |
| **HR** | `sh.employees`, `sh.attendance`, `sh.payroll` only - every other tile is absent from the hub, and a direct URL is 403 |
| **Institute Manager** | the eleven `in.*` reports, branch-scoped; `sh.profit_loss` is **403** even while holding `reports.view_reports` (phase-13 §4 rule 4, restated) |
| **Teacher** | no access to `/admin/reports`; their reporting is `teacher.reports.attendance` (phase-14-17) plus the per-assignment and per-exam statistics of their own batches |
| **Collaborator** | no access to `/admin/reports`. Their §56 statement stays the spine's `collaborator.statement.*` screen, whose figures come from `CollaboratorStatementService` - the same service the `co.*` admin reports call, so the two can never disagree |
| **Student / Client** | no access to any Phase 23 admin route (403) |
| **Report exports** | `report_exports` is scoped `requested_by = auth()->id()` for everyone except Super Admin (who sees the register but **cannot download another user's file** - the policy checks `requested_by` regardless of role, because the file's contents were shaped by that user's permissions) |
| **Activity log / audit trail** | `activity_log.view_logs` / `audit_trail.view_logs`; additionally, a row whose `subject_type` belongs to a module the viewer cannot see is **excluded from the query** (a PM with no finance rights never reads a payout's diff). A financial old/new value needs that module's `view_financial` when `reports.audit_show_financial_values` is false. Read-only for every role, Super Admin included |
| **Global search** | each provider applies its own module gate, its own permission **and its own index scope** (§6.23). A student's palette can therefore return their own courses and teachers and nothing else; a collaborator's returns only themselves and their own referred students; a client's returns their own projects, invoices and tickets. A hit whose detail route the viewer cannot open is rendered without a link rather than silently dropped, so the count never lies |

---

## 10. Events, notifications, jobs, scheduled tasks

### 10.1 Events (every one dispatched through `DB::afterCommit()`)

**Phase 19.** `MaterialCreated`, `MaterialPublished`, `MaterialUnpublished`, `MaterialArchived`,
`MaterialTargetsChanged`, `MaterialAccessed`, `AssignmentCreated`, `AssignmentPublished`,
`AssignmentClosed`, `AssignmentReopened`, `AssignmentSubmitted`, `AssignmentResubmitted`,
`AssignmentGraded`, `AssignmentReturned`, `AssignmentMarksReleased`, `AssignmentMarkAmended`,
`AssignmentMissed`.

**Phase 20.** `GradeScaleCreated`, `GradeScaleUpdated`, `GradeScaleDefaultChanged`, `ExamCreated`,
`ExamScheduled`, `ExamRescheduled`, `ExamConducted`, `ExamCancelled`, `ResultSheetSaved`,
`ResultsVerified`, `ResultsPublished`, `ResultsUnpublished`, `ResultAmended`.

**Phase 21.** `PrintTemplateChanged`, `CertificateDrafted`, `CertificateIssued`, `CertificateRevoked`,
`CertificateReissued`, `CertificatePrinted`, `CertificateVerified`, `CertificateVerificationThrottled`,
`IdCardIssued`, `IdCardReplaced`, `IdCardRevoked`, `IdCardsBatchPrinted`.

**Phase 22.** `TicketCreated`, `TicketReplied`, `TicketAssigned`, `TicketStatusChanged`,
`TicketPriorityChanged`, `TicketDepartmentChanged`, `TicketResolved`, `TicketClosed`, `TicketReopened`,
`TicketSlaBreached`, `MeetingScheduled`, `MeetingUpdated`, `MeetingRescheduled`, `MeetingCancelled`,
`MeetingResponseRecorded`, `MeetingCompleted`, `ConversationStarted`, `MessageSent`,
`ConversationParticipantAdded`, `ConversationParticipantRemoved`, `ConversationClosed`.

**Phase 23.** `ReportExportQueued`, `ReportExportCompleted`, `ReportExportFailed`, `ReportExported`
(the synchronous path, for the §106 log), `ActivityLogPruned`.

### 10.2 Listeners

| Listener | Listens to | Does |
|---|---|---|
| `NotifyMaterialAudience` | `MaterialPublished` | resolves the targets to distinct students, sends `material.published`, stamps `course_material_targets.notified_at` so a re-publish never re-notifies the same audience |
| `RecountAssignmentCaches` | `AssignmentSubmitted`, `AssignmentGraded`, `AssignmentMissed`, `AssignmentReturned` | `AssignmentService::recountCaches()` (a recount, never an increment) |
| `RecountExamCaches` | `ResultSheetSaved`, `ResultAmended` | `ExamService::recountCaches()` |
| `MarkTopicFromAssessment` | `ResultsPublished` | §6.12, only when the setting and the topic link are present; calls `CourseProgressService`, never a progress table |
| `GenerateCertificateArtifacts` | `CertificateIssued` | queues `GenerateCertificatePdf` |
| `SyncDepartmentTicketCounts` | `TicketCreated`, `TicketStatusChanged` | recounts `ticket_departments.open_tickets_count` |
| `StampTicketFirstResponse` | `TicketReplied` | inside the service's transaction, not a second write - listed here because a reviewer will look for it |
| `TouchConversationCaches` | `MessageSent` | `last_message_*`, `messages_count`, each participant's `unread_count` by recount |
| `RecordPhaseActivity` | every event above | one `activity_log` row with old/new values, module, IP, device and (where the act is discretionary) the reason - through `LogsActivityWithContext::withReason()` |

### 10.3 Notifications - the complete §97 list, plus this phase's additions

`NotificationRegistry` is the **one** declaration of every notifiable event in the system. §97's sixteen are
marked **§97**; each is owned (triggered) by the phase that owns its business act, while Phase 22 owns the
registry entry, the channel resolution and the delivery.

| Event key | §97 clause | Trigger (owner) | Audience | Level | Default channels | Mandatory |
|---|---|---|---|---|---|---|
| `project.created` | **§97** new project | `ProjectCreated` (6) | PM, project members, the client when `client_portal.projects` | info | database | no |
| `task.assigned` | **§97** new task | `TaskAssigned` (6) | the assignee (user or collaborator) | info | database | no |
| `lead.created` | **§97** new lead | `LeadCreated` (5) | the auto-assignee, else holders of `leads.assign` | info | database | no |
| `admission.created` | **§97** new admission | `AdmissionCreated` (15) | holders of `admissions.view_any`, the counsellor | info | database | no |
| `student.created` | **§97** new student | `StudentRegistered` (15) | holders of `students.view_any` | info | database | no |
| `fee.paid` | **§97** fee paid | `StudentFeePaymentRecorded` (18/spine) | the student (receipt link), holders of `student_fees.view_reports` | success | database + mail | no |
| `fee.due` | **§97** fee due | `FeeReminderSent` (18) | the student (and the guardian email when present) | warning | database + mail | no |
| `commission.student_added` | **§97** student commission added | `CommissionCreated` (10) | the collaborator, gated by `collaborator_portal.student_commission` | success | database | no |
| `commission.project_added` | **§97** project commission added | `CommissionCreated` (11) | the collaborator, gated by `collaborator_portal.project_commission` | success | database | no |
| `commission.approved` | **§97** commission approved | `CommissionApproved` (12) | the collaborator | success | database | no |
| `commission.reversed` | **§97** commission reversed | `CommissionReversed` (12) | the collaborator | critical | database + mail | **yes** |
| `payout.paid` | **§97** payout paid | `PayoutPaid` (12) | the collaborator | success | database + mail | **yes** |
| `exam.scheduled` | **§97** exam scheduled | **`ExamScheduled` (20)** | every active student of the batch + the batch teacher + the examiner | info | database | no |
| `result.published` | **§97** result published | **`ResultsPublished` (20)** | each student with a row, gated by `student_portal.results` | info | database + mail | no |
| `certificate.generated` | **§97** certificate generated | **`CertificateIssued` (21)** | the student, with the verification link | success | database + mail | no |
| `message.received` | **§97** new message | **`MessageSent` (22)** | the other live participants, honouring `is_muted` | info | database | no |
| `material.published` | §79 (the act of sharing is pointless if nobody is told) | **`MaterialPublished` (19)** | the targeted students | info | database | no |
| `material.reminder` | §79 | the engagement screen's action (19) | students who have not opened it | info | database | no |
| `assignment.published` | §80 ("student views status") | **`AssignmentPublished` (19)** | the batch's students | info | database | no |
| `assignment.deadline_reminder` | §80 deadline | `assignments:deadline-reminders` (19) | students with no live submission | warning | database | no |
| `assignment.submitted` | §80 (teacher reviews) | **`AssignmentSubmitted` (19)** | the assignment's teacher | info | database | no |
| `assignment.graded` | §80 (feedback) | **`AssignmentGraded` / `AssignmentMarksReleased` (19)** | the student - **only once `marks_released_at` is set** | success | database | no |
| `assignment.returned` | §80 | `AssignmentReturned` (19) | the student | warning | database | no |
| `certificate.revoked` | §84 revocation support | **`CertificateRevoked` (21)** | the student, plus holders of `certificates.view_any` | critical | database + mail | **yes** |
| `idcard.issued` | §85 | `IdCardIssued` (21) | the student | info | database | no |
| `ticket.created` | §93 | `TicketCreated` (22) | the assignee, else holders of `support_tickets.assign` | info | database + mail | no |
| `ticket.replied` | §93 | `TicketReplied` (22) | the **other** side only (a requester reply notifies staff, a public staff reply notifies the requester; an internal note notifies staff only) | info | database + mail | no |
| `ticket.assigned` | §93 | `TicketAssigned` (22) | the new assignee | info | database | no |
| `ticket.status_changed` | §93 | `TicketStatusChanged` (22) | the requester (resolved / closed / reopened only) | info | database | no |
| `ticket.sla_breach` | §93 | `TicketSlaBreached` (22) | the assignee + holders of `support_tickets.view_reports` | critical | database + mail | **yes** |
| `meeting.invited` | §95 | `MeetingScheduled` (22) | every internal participant | info | database + mail | no |
| `meeting.updated` | §95 | `MeetingUpdated` / `MeetingRescheduled` (22) | every participant | warning | database + mail | no |
| `meeting.cancelled` | §95 | `MeetingCancelled` (22) | every participant | warning | database + mail | no |
| `meeting.reminder` | §95 reminders | `meetings:send-reminders` (22) | every participant who has not declined | info | database + mail | no |
| `export.ready` | §99 export | `ReportExportCompleted` (23) | the requester | success | database | no |
| `export.failed` | §99 | `ReportExportFailed` (23) | the requester, with the real error | warning | database | no |
| *(spine, unchanged)* `payout.requested`, `payout.approved`, `payout.rejected`, `wallet.drift`, `commission.generation_failed`, `refund.awaiting_approval` | - | Phases 10-12 | per the spine §10.3 | - | - | per the spine |
| *(other phases, unchanged)* `lead.followup_due`, `client.portal_invitation`, `client.document_shared`, `leave.requested`, `leave.decided`, `payroll.slip_ready`, `invoice.sent`, `invoice.reminder`, `fee.overdue`, `batch.near_capacity`, `session.unmarked` | - | Phases 5, 7, 13, 15-18 | per their own contracts | - | - | - |

**Every one of those rows is a registry entry, not a new table and not a new channel.** A phase that already
ships a notification class keeps it; Phase 22 registers its key, its audience resolver and its default
channels so it becomes preference-able and appears in the bell with the right icon and deep link.

### 10.4 Queued jobs

| Job | Key properties |
|---|---|
| `NotifyMaterialAudience` | `ShouldBeUnique` (`material-notify:{material_id}`, `uniqueFor` 3600), `$afterCommit = true`, `tries` 3, chunked 200 recipients; stamps `notified_at` per target **inside** the chunk's transaction so a retry cannot double-notify |
| `RecomputeAssignmentCaches` | `ShouldBeUnique` (`assignment-caches:{id}`); recount only, never an increment; the repair path used by `assignments:verify-marks` |
| `RecomputeExamCaches` | `ShouldBeUnique` (`exam-caches:{id}`) |
| `GenerateCertificatePdf` | `ShouldBeUnique` (`certificate-pdf:{id}`), `tries` 3, `backoff [10,30,60]`; renders through `PrintTemplateService::toPdf()` from **snapshots only**; writes the file then sets `pdf_path` / `pdf_generated_at`; a failure leaves the certificate issued and valid (the PDF is regenerable) |
| `GenerateIdCardPdf` | `id-card-pdf:{id}`, otherwise identical |
| `BuildBatchIdCardPdf` | one job per batch-print request above 25 cards, delivered by `export.ready`-style notification; bounded by `institute.id_card_batch_print_max` |
| `SendTicketSlaBreachNotices` | dispatched by the sweep; idempotent on the breach booleans |
| `BuildReportExport` | `ShouldBeUnique` (`report-export:{uuid}`), `tries` 2, `timeout` 900; sets `running`, streams to the private disk through `CsvWriter` / dompdf with `chunkById`, records `row_count`, `file_size_bytes`, `checksum_sha256`, `expires_at`, then `completed` + `export.ready`; `failed()` writes `failed` with `error_class` / `error_message` and notifies `export.failed` |
| `SendNotificationDigest` | per user per day, assembled from unread `database` rows whose preference is `daily` |
| `PruneMaterialDownloadLog` / `PruneCertificateVerificationLog` / `PruneNotifications` / `PruneReportExports` | chunked deletes, each writing one activity row with the count |

### 10.5 Scheduler

| Command | Cadence | Purpose |
|---|---|---|
| `assignments:deadline-reminders` | hourly | for each offset in `institute.assignment_deadline_reminder_hours`, notify students of `published` assignments with no live submission whose deadline falls in that window; one row per (assignment, student, offset), deduplicated through the notification's own idempotency key so a re-run never double-sends |
| `assignments:close-due` | hourly | when `institute.assignment_auto_close_on_deadline`, close published assignments past their effective cutoff and call `markMissed()` |
| `materials:expire` | daily 00:30 | nothing is deleted - it only warms the "expired" filter counts and notifies the uploader of a material whose `available_until` passed with under 50 % engagement |
| `materials:prune-download-log` | weekly | `institute.material_download_log_retention_days` |
| `exams:open-result-entry` | daily 01:30 | moves `scheduled` exams whose date has passed to `conducted`, so the sheet is waiting for the teacher; never touches a cancelled exam |
| `exams:remind-unentered` | daily 09:30 | notifies the examiner and `results.view_reports` holders of exams `conducted` more than `3` days with an incomplete sheet |
| `grades:verify-scales` | daily 02:20 | re-asserts INV-20-3 for every active scale; **reports, never repairs**; notifies `grade_scales.view_any` holders on drift |
| `assignments:verify-marks` | daily 02:25 | asserts `obtained_marks <= total_marks` and that `final_marks` equals the PHP calculation for every submission touched in 60 days; reports, never repairs |
| `certificates:prune-verification-log` | weekly | `institute.certificate_verification_log_retention_days` |
| `certificates:verify-integrity` | daily 02:30 | asserts every `issued` certificate has a number, a code, a `qr_payload` and (when `pdf_generated_at` is set) a file on disk; re-queues a missing PDF; alerts loudly on a missing number or code |
| `idcards:expire` | daily 00:40 | `active` → `expired` where `valid_until < today`; notifies the student 30 days before |
| `tickets:sla-sweep` | every 10 minutes | `TicketSlaService::sweep()` - stamps breaches, fires `ticket.sla_breach` once per kind, bounded to 500 rows. **Returns immediately (a logged no-op) when `support.sla_enabled` is false** (F-13.5) |
| `tickets:auto-close` | daily 01:10 | `resolved` → `closed` after `support.ticket_auto_close_resolved_days`; writes a system reply; never closes a ticket with an unread requester reply |
| `tickets:recount-departments` | daily 01:15 | repairs `open_tickets_count` by COUNT |
| `meetings:send-reminders` | every 5 minutes | meetings whose `scheduled_at - reminder_minutes_before` has arrived and `reminder_sent_at IS NULL`, locked with `skipLocked`, max 200; stamps the timestamp **inside** the transaction and queues the notification `afterCommit`, so a crash can never double-send (the `crm:follow-up-reminders` pattern) |
| `meetings:close-past` | hourly | `scheduled` meetings whose `ends_at` passed by more than 2 hours → `missed` (or `completed` when attendance was marked) |
| `notifications:digest` | daily at `support.notification_digest_hour` | dispatches `SendNotificationDigest` per user with `daily` preferences and unread rows |
| `notifications:prune` | weekly | archived rows older than `support.notification_retention_days` |
| `reports:prune-exports` | daily 03:00 | expires and deletes files past `expires_at`; one activity row with the count |
| `reports:warm-caches` | daily 06:30 | pre-computes the five heaviest dashboard and analytics aggregates for the current month, so the first morning page load is not the slow one |
| `activity-log:prune` | monthly | **disabled by default** (`reports.activity_log_retention_days = 0`); when enabled it never deletes a row whose `module` is financial, and it writes one `ActivityLogPruned` row recording the range and the count |

---

## 11. Acceptance tests

`tests/Feature/Institute/` (19-21), `tests/Feature/Support/` (22), `tests/Feature/Reporting/` (23).
Every authorization test asserts the **status code and that nothing was written**; every isolation test
additionally asserts the **absence of the forbidden columns from the response body**. None of these phases is
done until every test below passes.

### 11.1 Phase 19 - files, materials, download control

1. **PH19-01** A material's `file_path` is on the `private` disk; `Storage::disk('public')->exists()` is false for it, and `GET /storage/{path}` returns 404 even for Super Admin.
2. **PH19-02** No route in phases 19-23 returns a `Storage::url()` or a `temporaryUrl()` for a private file - asserted by crawling every registered route's response for `/storage/` links on a seeded fixture set.
3. **PH19-03** Uploading `notes.pdf` stores a ULID filename; `original_name` is `notes.pdf`; the download's `Content-Disposition` carries `notes.pdf`; the stored `mime_type` is `application/pdf` sniffed from content.
4. **PH19-04** A file named `lesson.pdf` whose bytes are a PHP script is **refused**, naming the MIME mismatch; nothing is written and no file lands on disk.
5. **PH19-05** `shell.php`, `x.phtml`, `a.svg`, `b.html` and `cv.pdf.php` are each refused regardless of `security.allowed_file_types`.
6. **PH19-06** A file of `institute.material_max_upload_mb + 1` MB is refused naming the limit; a file above `security.max_upload_mb` is refused even when the institute key is larger.
7. **PH19-07** `duplicateToBatches()` creates three assignments referencing one brief file; force-deleting one does **not** delete the bytes the other two reference.
8. **PH19-08** A `link` material cannot carry a `file_path` (CHECK), a file material cannot carry an `external_url`, and a material with neither is refused.
9. **PH19-09** `javascript:alert(1)` and `data:text/html,…` are refused as `external_url`.
10. **PH19-10** A student of batch B sees a material targeted at *course C*, at *batch B* and at *themselves*; they do **not** see one targeted at batch B2 of the same course (404 on its download).
11. **PH19-11** An unpublished, archived, future-`available_from` or past-`available_until` material is 404 for the student and visible to staff.
12. **PH19-12** A student whose batch ended `institute.material_visible_after_batch_end_days + 1` days ago gets 404; one day inside the window gets 200.
13. **PH19-13** A dropped student (`EnrollmentStatus::dropped`) loses access immediately, with no cache to bust.
14. **PH19-14** A teacher sees material targeted at their own batches plus their own drafts; a material of a batch they do not teach is 404.
15. **PH19-15** A collaborator and a client each get 403 on every Phase 19 route.
16. **PH19-16** Unpublishing a material makes the student's previously-working download URL 404 on the **next** request - proving the decision is made at stream time, not at link time.
17. **PH19-17** A successful download writes exactly one `course_material_downloads` row with actor, panel, action, IP and device; `download_count` then equals the log count; a recount produces the same number.
18. **PH19-18** A `view` of an `is_downloadable = false` PDF streams `inline` with `nosniff` and the sandbox CSP and logs `action = view`; the download route for the same material still streams `attachment` for staff holding `course_materials.download`.
19. **PH19-19** `setTargets()` refuses a batch of another course and a student with no active enrollment in the material's course, naming the row; nothing is written.
20. **PH19-20** A student with no active enrollment in the assignment's batch cannot create a submission (403) and cannot read the brief (404).
21. **PH19-21** Two concurrent submits for the same (assignment, student) produce exactly **one** live row (`uq_as_live`), and the loser is reported, not crashed.
22. **PH19-22** A resubmission creates `attempt_no = 2`, sets the first row `superseded` with `superseded_by_id`, and leaves both rows' files intact.
23. **PH19-23** Resubmission is refused at `max_attempts` and when `allow_resubmission = false`, each naming the reason.
24. **PH19-24** Student A gets 404 on student B's submission, file and feedback file.
25. **PH19-25** A submission one minute after `deadline_at` is `is_late = true` with `minutes_late = 1`; editing the deadline afterwards does **not** change either value.
26. **PH19-26** With `late_submission_allowed = false` a late submit is refused; with a `late_cutoff_at` in the past it is refused even when late submission is allowed.
27. **PH19-27** `markMissed()` creates one `missed` row per non-submitting roster student, is idempotent on a second run, and never overwrites a live submission.
28. **PH19-28** Closing an assignment blocks new submissions (403) and reopening it with a reason clears the `missed` rows and logs it.
29. **PH19-29** A student sees no marks, percentage or feedback while `marks_released_at` is null, and sees them immediately after `releaseMarks()` - asserted on the response body, not the view.
30. **PH19-30** Grading `41` against `total_marks = 40` is refused by the Form Request, by the service when called directly, and by `chk_asub_marks` when inserted raw - **three independent layers**.
31. **PH19-31** A negative mark and a negative penalty are each refused.
32. **PH19-32** A 10 % late penalty on 40 marks with 35 obtained yields `penalty_marks = 4.00`, `final_marks = 31.00`, `percentage = 77.50`.
33. **PH19-33** For 40 randomised (total, obtained, penalty) triples, `AssignmentGradeCalculator`'s `final_marks` equals the value the generated column computes, byte for byte.
34. **PH19-34** A penalty larger than the obtained marks floors `final_marks` at `0.00` and never goes negative.
35. **PH19-35** `bulkGrade` with one invalid row writes **nothing** and returns that row's index and message.
36. **PH19-36** An amendment after release requires a reason, stamps `amended_*`, and writes an activity row carrying old and new marks.
37. **PH19-37** `delete` and `forceDelete` on a submission return false for every role including Super Admin; a `draft` withdraw by its owner succeeds and removes its files.
38. **PH19-38** `migrate:fresh --seed` runs clean and every Phase 19 migration rolls back cleanly, generated columns and CHECKs included.

### 11.2 Phase 20 - grade scales, exams, the result sheet

39. **PH20-01** `exam_results.total_marks` is written from the exam at entry time; editing `exams.total_marks` afterwards (where still legal) leaves the stored result row unchanged and its percentage unchanged.
40. **PH20-02** A raw insert of `obtained_marks = 51` against `total_marks = 50` is rejected by `chk_er_marks`.
41. **PH20-03** The Form Request rejects `obtained_marks > exam.total_marks` naming the student row; the whole sheet is rejected and **no row is written**.
42. **PH20-04** `attendance_status = absent` forces `obtained_marks` null (CHECK both ways) and `is_passed = false`.
43. **PH20-05** 33/100 with `passing_marks = 33` is `is_passed = true`; 32.99 is false.
44. **PH20-06** With `passing_marks = 0` the band's `is_pass` decides; with `passing_marks > 0` the exam wins even when the band says pass ([D-20-2]).
45. **PH20-07** 27 of 40 yields `percentage = 67.50` and grade `C` on the seeded scale; 26.5 of 40 yields `66.25` and `C`; bcmath, half-up, no float drift across 50 randomised cases.
46. **PH20-08** `percentage`, `grade`, `grade_point` and `is_passed` cannot be written by a mass-assignment update or a factory - the model hook throws.
47. **PH20-09** A result carries snapshots of `grade` and `grade_point`; renaming the band to `C+` afterwards does not change the stored row or the printed card.
48. **PH20-10** A scale with a gap (0-39, 45-100) is refused naming the gap; an overlap (0-50, 40-100) is refused naming the overlap.
49. **PH20-11** A scale not reaching 100.00 or not starting at 0.00 is refused.
50. **PH20-12** A scale that passes 60-69 and fails 70-79 is refused (one pass boundary only).
51. **PH20-13** Two scales cannot both be `is_default` (`uq_gs_default`); `setDefault` swaps atomically.
52. **PH20-14** `grades:verify-scales` reports a scale broken by a direct DB edit and notifies, without repairing it.
53. **PH20-15** Deleting a band referenced by a result is refused; deactivating the scale is allowed and the result still renders its grade.
54. **PH20-16** Deleting an exam with results is refused; cancelling it is allowed only while no result exists.
55. **PH20-17** Amending a published result requires `results.edit` and a reason, re-checks the ceiling, and logs old and new marks, percentage and grade.
56. **PH20-18** `delete` / `forceDelete` on `exam_results` return false for every role; unpublishing requires a reason and notifies nobody.
57. **PH20-19** Publishing with one roster student missing a row is refused, naming the student count.
58. **PH20-20** Publishing computes `position_in_batch` with shared ranks: marks 40, 38, 38, 35 produce ranks 1, 2, 2, 4.
59. **PH20-21** A sheet containing a `student_id` not on the roster for `scheduled_date` is rejected **entirely**, naming the id.
60. **PH20-22** Submitting the same sheet twice produces exactly 25 rows, not 50 (`uq_er_exam_student` upsert).
61. **PH20-23** Two concurrent saves of the same sheet leave consistent data and no duplicate rows.
62. **PH20-24** A student enrolled after the exam date is absent from the sheet, and adding them is rejected.
63. **PH20-25** The CSV import rejects the whole file when one row's marks exceed the total, returning the row number.
64. **PH20-26** With `institute.result_publish_requires_verification = true`, the enterer cannot verify (403) and publishing before verification is refused.
65. **PH20-27** `exam.scheduled` notifications go to every active student of the batch, the batch teacher and the examiner - and to nobody else.
66. **PH20-28** `result.published` goes only to students who have a row, and only to those holding `student_portal.results`.
67. **PH20-29** A student sees a published result and gets 404 on an unpublished one and on another student's result.
68. **PH20-30** A teacher can enter a sheet for their own batch and gets 404 for another batch's exam; a client and a collaborator get 403 everywhere in Phase 20.
69. **PH20-31** With `institute.progress_from_assessment = true` and a topic-linked exam, publishing marks the topic `completed` with `ProgressSource::assessment` for passing students only, and **never** overwrites a row whose source is `manual`.
70. **PH20-32** A result card reprinted after the exam's `total_marks` was changed renders the original snapshot values.
71. **PH20-33** `ExamStatisticsService` and the `in.results` report return identical figures for the same filter set (one definition, INV-23-1).

### 11.3 Phase 21 - certificates, verification, ID cards, templates

72. **PH21-01** Issuing assigns one number from `institute.certificate_prefix` + counter; 20 concurrent issues produce 20 distinct, gap-free numbers and no duplicate (1062 retried once).
73. **PH21-02** A draft has no number; a number is never reused after revocation.
74. **PH21-03** `delete` and `forceDelete` on an `issued` certificate return false for every role including Super Admin; a `draft` delete with a reason succeeds.
75. **PH21-04** Revoking requires a reason, sets `revoked_at` / `revoked_by`, keeps the row, and the public page then reports `revoked` with the date and the reason.
76. **PH21-05** Reissuing creates a new draft with `reissue_of_id`; the revoked row is untouched; `uq_ce_reissue` prevents two successors.
77. **PH21-06** An attempt to update any column of an issued certificate other than the print / verification / status columns throws `CertificateIssuedException`.
78. **PH21-07** Renaming the student, course, batch or teacher after issue does not change the certificate's rendered output.
79. **PH21-08** Changing `institute.certificate_verification_url` after issue does not change the stored `qr_payload`, and the printed QR still resolves.
80. **PH21-09** A second live certificate for the same enrollment is refused by `uq_ce_live`; after revocation a new one may be issued.
81. **PH21-10** `verification_code` is 16 characters from the 32-symbol alphabet, unique across 10,000 generated codes in a loop, and never equal to the certificate number.
82. **PH21-11** `GET /verify/{code}` returns the valid panel for an issued certificate and `X-Robots-Tag: noindex`.
83. **PH21-12** An unknown code, a `not_public` certificate and a malformed code return the **same wording** and statistically indistinguishable timing.
84. **PH21-13** Exceeding `institute.certificate_verification_rate_limit_per_minute` from one IP returns the throttled panel, writes a `throttled` log row, and performs **no** certificate lookup.
85. **PH21-14** Every verification attempt - valid, revoked, not found, throttled - writes exactly one `certificate_verifications` row with IP and device.
86. **PH21-15** A valid verification increments `verification_count` and `last_verified_at`.
87. **PH21-16** `/verify/{code}` with a 15- or 17-character code never reaches the database (route constraint).
88. **PH21-17** The public payload contains **only** the keys in `institute.certificate_verification_reveals` plus number, status and dates; adding `?fields=phone`, an `Accept` header or a locale change does not widen it.
89. **PH21-18** With the default reveal set, the body contains no `phone`, `email`, `cnic`, `address`, `guardian`, `father_name`, `collaborator`, fee or internal id - asserted by searching the raw response for each seeded value.
90. **PH21-19** Turning `father_name` on in the setting adds exactly that one field and nothing else.
91. **PH21-20** The public page never links to or serves the certificate PDF; requesting the PDF route unauthenticated is a redirect to login, and as another student a 404.
92. **PH21-21** An ID-card code resolves on the same endpoint and reveals only name, student code, course, batch, validity and status.
93. **PH21-22** `student_id_cards.photo_path` is a copy: replacing the student's photo afterwards does not change the issued card's PDF.
94. **PH21-23** Eligibility blocks issue when attendance, progress, exams or fees fail, and the report names each failing rule with its actual value; `StudentFeeService` is the only source of the fee verdict (asserted by mocking it).
95. **PH21-24** An override requires `certificates.approve` plus a reason, stores the failing rules in `eligibility_snapshot`, and writes an activity row.
96. **PH21-25** A template whose `body_html` contains `{{ 7*7 }}`, `@php`, `<?php`, `<script>`, `onerror=` or `<iframe>` is sanitised on save, and the rendered output contains none of them and never the evaluated `49`.
97. **PH21-26** An unknown token renders as an empty string and is reported as a warning at save time.
98. **PH21-27** `{qr}` and `{photo}` render as `data:` URIs; dompdf makes zero network requests (`isRemoteEnabled = false` asserted).
99. **PH21-28** A template preview uses registry example values and contains no real student's name.
100. **PH21-29** Deleting a template referenced by a certificate is refused; deactivating it is allowed and the certificate still re-prints identically.
101. **PH21-30** A certificate PDF and an ID-card PDF live on the private disk, and their paths are not guessable URLs; the stream route 404s for another student.
102. **PH21-31** `markPrinted` increments `print_count` and the second print is stamped "Reprint #2".
103. **PH21-32** Batch issue of 30 eligible enrollments issues 30, skips the ineligible with their reason, and a single failure does not roll back the other 29.
104. **PH21-33** Batch ID-card printing above `institute.id_card_batch_print_max` is refused naming the limit; at the limit it emits one PDF and increments every card's `print_count` with **one** activity row.
105. **PH21-34** A student without a photo is excluded from the batch print and named in the excluded list when `institute.id_card_require_photo`.
106. **PH21-35** A second `active` card for one student is refused (`uq_sic_live`); `replace()` moves the old to `lost` and issues a new one with `replacement_of_id`.
107. **PH21-36** A teacher sees the candidate list for their own batches and gets 403 on issue, revoke and templates; a client and a collaborator get 403 on everything in Phase 21.

### 11.4 Phase 22 - tickets, meetings, messaging, notifications

108. **PH22-01** Creating a ticket issues one number from the counter; 20 concurrent creates produce 20 distinct numbers.
109. **PH22-02** `delete` / `forceDelete` on a ticket and on a reply return false for every role; there is no edit route for a reply.
110. **PH22-03** A client cannot set `client_id`, `student_id` or `collaborator_id` through the form - the service derives them, and a forged payload is ignored (asserted on the stored row).
111. **PH22-04** A portal user cannot set `priority` (it comes from the setting) while staff can.
112. **PH22-05** A ticket in a department whose `allowed_panels` excludes the requester's panel is refused with 403 naming the department.
113. **PH22-06** `support.ticket_allow_student_create = false` makes the student create route 403 while the read route still works.
114. **PH22-10** `first_response_due_at` = created_at + department minutes × `TicketSlaService::multiplier(Priority $p)`; `urgent` is a quarter of `medium`. With `support.sla_enabled = false` the same create leaves both due dates **null** and never calls `multiplier()`.
115. **PH22-11** An **internal note** does not set `first_response_at`; the next public staff reply does, and sets `is_first_response`.
116. **PH22-12** Moving to `waiting` stamps `waiting_since`; returning to `in_progress` adds the elapsed minutes to `total_waiting_minutes` **and** pushes both due dates forward by the same amount.
117. **PH22-13** With `support.sla_business_hours_only = true` a ticket created at 18:00 Friday gets a Monday-morning due date from `contact.business_hours`.
118. **PH22-14** A requester reply on a `resolved` ticket inside the reopen window reopens it, increments `reopened_count` and sets a fresh `resolution_due_at`; outside the window it does not, and only staff can reopen (with a reason).
119. **PH22-15** `tickets:sla-sweep` stamps each breach boolean once and fires `ticket.sla_breach` once per kind, even when run three times.
120. **PH22-16** `tickets:auto-close` closes a `resolved` ticket after the configured days and skips one with an unread requester reply.
121. **PH22-17** A client's ticket response body contains no `internal_note` reply - asserted by searching the raw body for the note's text - and the reply count the client sees excludes them.
122. **PH22-18** An internal note never appears in any notification payload.
123. **PH22-19** Client A gets 404 on client B's ticket; with `is_private_to_creator` a second contact of the same company also gets 404, and without it 200.
124. **PH22-20** A student **cannot** start a conversation with a client: the recipient picker omits them, a forged user id returns 403 with `ConversationScope::describe()`'s sentence, and no row is written.
125. **PH22-21** A client cannot message a student or a collaborator; a collaborator cannot message a student or another collaborator; a student cannot message another student.
126. **PH22-22** Each of §94's six pairs **can** message, and each lands the correct `pair_scope` on the row.
127. **PH22-23** Removing `student_staff` from `support.messaging_allowed_pairs` makes an existing student thread read-only (send 403) while leaving it readable.
128. **PH22-24** `support.messaging_enabled = false` 403s every send in every panel.
129. **PH22-25** A user whose `UserStatus` is not `Active` can neither be messaged nor send.
130. **PH22-26** A group conversation cannot be used as a back door: adding a client to a group containing a student is refused, whether at creation or later.
131. **PH22-27** `support.messaging_student_can_start = false` lets a student reply but not start a thread.
132. **PH22-28** Exceeding `support.messaging_rate_limit_per_minute` is refused by the service even when the HTTP middleware is bypassed.
133. **PH22-29** Two users starting a direct thread simultaneously end with **one** conversation (`uq_cv_direct`), and the second call returns the first thread.
134. **PH22-30** `unread_count` is correct after a send, after `markRead`, after a re-send, and after a replayed `markRead`; a recount matches.
135. **PH22-31** `messages.view_any` is granted to nobody by the seeders; a user holding only it can read but not send, and each read writes an activity row.
136. **PH22-32** A non-participant gets **404** on a conversation and on a message attachment.
137. **PH22-33** A meeting participant can respond; a non-participant gets 404 on respond, on the detail and on the `.ics`.
138. **PH22-34** Changing `scheduled_at` resets every participant's `response` to `pending` and re-notifies them.
139. **PH22-35** A classroom double-booking is refused when `support.meeting_room_clash_block` and only warned without a room.
140. **PH22-36** Cancelling requires a reason and notifies every participant; rescheduling creates the successor row with `rescheduled_from_id`.
141. **PH22-37** An external participant needs a name and an email, is refused when `support.meeting_allow_external_participants` is false, and is never sent an in-app notification (no user row).
142. **PH22-38** A client sees `meetings.notes` only as a participant on a `completed` meeting; a student never writes notes (403).
143. **PH22-39** `meetings:send-reminders` run twice sends one reminder; a crash between the stamp and the queue cannot double-send (the stamp is inside the transaction).
144. **PH22-40** A ticket, message and meeting attachment all live on the private disk, and each download 404s for a non-participant and 403s without the permission.
145. **PH22-50** Every one of §97's sixteen events is present in `NotificationRegistry` with a key, an audience, a level and a URL - asserted by name against a hardcoded list in the test.
146. **PH22-51** A notification is never created for a recipient lacking the event's `requiredPermission` (a collaborator without `collaborator_portal.student_commission` receives no `commission.student_added` row).
147. **PH22-52** Turning off `database` for an event in `notification_preferences` stops the row; a **mandatory** event ignores the stored preference and is still delivered.
148. **PH22-53** With `support.notifications_mail_enabled = false` no mail is queued even when the per-user mail toggle is on; flipping the master switch queues it with no code change.
149. **PH22-54** Disabling the `notifications` module makes `NotificationService::dispatch()` a logged no-op **and the triggering fee payment still commits** - the transaction is unaffected.
150. **PH22-55** An unknown event key throws `InvalidNotificationEvent` (a typo fails loudly).
151. **PH22-56** `dispatch()` inside a rolled-back transaction sends nothing (afterCommit).
152. **PH22-57** The bell count is one query; a user with 1,000 notifications still renders the topbar inside the `DB::listen` budget.
153. **PH22-58** A user can read, mark and archive only their own notifications; another user's id returns 404. Archiving never deletes the row.
154. **PH22-59** `{panel}.notifications.go` re-authorises the target route: a notification deep-linking to a module the user has since lost returns 403, not a leak.
155. **PH22-60** The preference screen lists only `NotificationRegistry::forUser()` events - a student sees no payout or commission row.

### 11.5 Phase 23 - reports, exports, logs, search

156. **PH23-01** Every report class's `run()` delegates: a static scan asserts no `SUM(`, `COUNT(`, `AVG(` or `DB::raw` aggregate appears in `app/Reports/`.
157. **PH23-02** `in.fees` totals equal `StudentFeeService`'s for the same filters; `sh.income` equals `FinanceReportService`'s; `co.paid_commission` equals `CollaboratorWalletService::payoutsPaidTotal()` - to the paisa.
158. **PH23-03** Without `reports.view_reports` the hub is 403 and every report URL is 403.
159. **PH23-04** With `reports.view_reports` but without `student_fees.view_reports`, `in.fees` is absent from the hub and 403 by URL.
160. **PH23-05** An Institute Manager holding `reports.view_reports` is still 403 on `sh.profit_loss`.
161. **PH23-06** Without `students.view_financial` the money columns of `in.admissions` are **absent from the SQL** (asserted with `DB::listen`), absent from the HTML and absent from the CSV header - not blank.
162. **PH23-07** A filter carrying a `permission` the user lacks is absent from the filter bar and ignored when posted.
163. **PH23-08** A report whose module is disabled is absent from the hub and 403 by URL, for Super Admin too.
164. **PH23-09** `meta` names the date column used, and the same report with `DateRange::month()` and with an equivalent custom range returns identical rows.
165. **PH23-10** A CSV export of 50,000 rows completes without exceeding a 128 MB memory ceiling (streamed, `chunkById`).
166. **PH23-11** A cell beginning `=`, `+`, `-`, `@`, tab or CR is prefixed with `'` in the CSV (formula injection).
167. **PH23-12** A result above `reports.sync_row_limit` creates a `report_exports` row, queues the job, and the screen says so instead of streaming.
168. **PH23-13** A result above `reports.export_max_rows` is refused naming the count and the limit, and creates no row.
169. **PH23-14** `BuildReportExport` writes the file to the private disk, records `row_count`, `file_size_bytes` and `checksum_sha256`, sets `expires_at`, and notifies `export.ready`.
170. **PH23-15** A failed export stores `error_class` / `error_message` and notifies `export.failed` with the real message.
171. **PH23-16** Only `requested_by` can download an export (404 for everyone else, **including Super Admin**), only while `completed`, only before `expires_at`, and **only while still holding the report's permissions** - revoking `view_financial` then downloading yesterday's file is refused.
172. **PH23-17** `reports:prune-exports` expires rows, deletes the files, and logs the count.
173. **PH23-18** `ExportFormat::Excel` is offered only when `reports.excel_enabled`; with it off the button is absent and the URL returns a validation error, never a 500.
174. **PH23-19** A PDF export renders through dompdf with `isRemoteEnabled = false` and makes no network request.
175. **PH23-20** Global search below `reports.global_search_min_chars` returns nothing and runs no query.
176. **PH23-21** Eleven providers exist, one per `SearchEntityType` case - asserted by enumerating the enum against the registry.
177. **PH23-22** A user without a provider's permission receives no hits of that type and the group is absent from the response.
178. **PH23-23** A disabled module removes its provider from the palette and from the full-page results.
179. **PH23-24** A student's search for a seeded classmate's name returns zero student hits; searching their own name returns themselves.
180. **PH23-25** A collaborator's search returns only themselves among collaborators and only their own referred students.
181. **PH23-26** A client's search returns only their own projects, invoices and tickets.
182. **PH23-27** A teacher's student search is limited to `TeacherScope::batchIds()`.
183. **PH23-28** A provider throwing an exception degrades to "unavailable" for that group and the other ten still return.
184. **PH23-29** The palette issues **one query per enabled provider** (asserted with `DB::listen`), never one per row.
185. **PH23-30** Pasting a certificate number, ticket number, invoice number or student code jumps to that record through `exactMatch()`, and only when the viewer may open it.
186. **PH23-35** The activity-log and audit-trail screens expose no write, edit or delete route - asserted by enumerating the route list for those prefixes.
187. **PH23-36** `AuditTrailService::query()` returns only rows with both old and new values whose module is in `reports.audit_sensitive_modules`.
188. **PH23-37** A commission-rate change renders as `10.0000 % → 15.0000 %` with its reason, actor, IP and timestamp (§107's own example).
189. **PH23-38** A student's collaborator re-link renders old and new collaborator names with the reason (§107's other example).
190. **PH23-39** With `reports.audit_show_financial_values = false`, a money old/new value is withheld behind `view_financial` and rendered as `••••`, never as `0`.
191. **PH23-40** An encrypted setting's change renders `[encrypted]` and never the value (Phase 2's rule, re-asserted here).
192. **PH23-41** Every §2.28 discretionary transition writes an `activity_log` row with old value, new value, actor, IP and reason - one parameterised test over the whole transition table.
193. **PH23-42** A PM without finance rights sees no payout, ledger or invoice row in either log screen.
194. **PH23-43** `activity-log:prune` is off by default; enabled, it never deletes a financial-module row and writes one `ActivityLogPruned` summary.
195. **PH23-44** The five additive `activity_log` indexes exist after migration, and the log screen's query uses one of them (`EXPLAIN` asserted).
196. **PH23-45** A static scan finds no PHP `+ - * /` on a money, mark or percentage value in `app/Services/Institute`, `app/Services/Support`, `app/Services/Reporting` or `app/Reports`.
197. **PH23-46** A static scan finds no `Storage::put`, `->store(`, `->storeAs(`, `Storage::url(` or `temporaryUrl(` outside `SecureFileService` in phases 19-23.
198. **PH23-47** The reports hub, the analytics screen and any panel topbar each issue a bounded number of queries (asserted with `DB::listen`) - no N+1 across widgets, charts or the three unread counters.
199. **PH23-48** `migrate:fresh --seed` runs clean with all five phases' migrations, and every one rolls back cleanly.
200. **PH23-49** Disabling `reports`, `audit_trail` or `global_search` 403s their routes for everyone while leaving every row intact, and re-enabling restores identical row counts (the Phase 2 module-data-safety test, extended).

---

## 12. Risks and open questions

### 12.1 Risks accepted, with the mitigation

| # | Risk | Mitigation |
|---|---|---|
| R-1 | **Five phases in one document invites a partial build** - a team could ship tickets without notifications, or results without a grade scale. | §1.3 names the owner of every table and §4.4 declares the `depends_on` graph, so `results` cannot be enabled without `grade_scales` and `support_tickets` cannot be enabled without `ticket_departments`. The tracker in `DEVELOPMENT_LOG.md` keeps five separate rows. |
| R-2 | **`is_downloadable = false` is not protection.** A determined student can screenshot or capture the stream. | Stated plainly in the UI help text and in §6.3, so nobody sells it as DRM. The real control is the permission and the log - and the log tells you who opened what. |
| R-3 | **The private disk grows without bound** (materials, submissions, PDFs, exports). | Submissions are bounded per assignment (`max_files` × `max_file_size_mb` × `max_attempts`); exports expire and are pruned; PDFs are regenerable and may be pruned; `institute.material_max_upload_mb` caps the rest. Phase 25 must document disk sizing, and `SystemHealthWidget` (Phase 2) already surfaces storage usage. |
| R-4 | **A template editor holding `print_templates.edit` is a powerful role** even with sanitisation, because a template prints onto official documents. | INV-21-5 (no Blade, no eval), a separate module slug so the right is granted deliberately, a mandatory reason and a logged diff on every edit of a template with issued documents, and snapshots so the **content** of an issued certificate can never be changed by a template edit - only its layout. |
| R-5 | **The public verification endpoint is the only unauthenticated surface these phases add**, so it is where enumeration and scraping will be attempted. | 80-bit random codes independent of the sequential number, a route-level 16-character constraint, per-IP rate limiting, identical wording and timing for every negative case, a whitelist payload, `noindex`, every attempt logged, and no list endpoint of any kind. |
| R-6 | **SLA pausing is easy to get subtly wrong**, and a wrong SLA is worse than none. | One implementation (`TicketSlaService`), a pause that moves **both** due dates by the same amount, `total_waiting_minutes` stored so the arithmetic is auditable, and five named tests (PH22-12, -13, -15, -16 and the breach sweep). **And a switch**: §93 never asked for SLA, so the whole feature sits behind `support.sla_enabled` (default `true`, every clock column nullable) - if the institute decides a wrong clock is worse than no clock, it is a settings flip, not a migration (audit F-13.5, H3). |
| R-7 | **The messaging matrix will be asked to bend** ("let the student ask the client about the project"). | The matrix is one class plus one multiselect setting, re-checked on every send. Bending it is an admin setting change, not a code change - and removing a pair silences the thread instead of leaking into it. |
| R-8 | **31 reports depending on 10 other phases' services** means the hub is only as finished as the phases behind it. | `ReportRegistry::visibleTo()` plus the [D-P5-1] capability pattern: a report whose source service is not bound renders a disabled tile saying which phase brings it. No report ever fabricates a number. |
| R-9 | **`reports.sync_row_limit` and `finance.report_sync_row_limit` are two knobs for one idea.** | Documented in §5.3, Phase 13's key wins for its four reports, and §13.2 records the convergence ask so the duplication is visible rather than silently divergent. |
| R-10 | **The result sheet is the highest-stakes data-entry screen in the system**; a mis-typed total would corrupt every percentage of a batch. | `total_marks` snapshotted per row, the ceiling enforced in three layers, a review step before publication, a missing-row block, four-eyes verification by default, amendment with a mandatory reason, and `assignments:verify-marks` / the publication block as the standing proof. |
| R-11 | **Three generated columns and nine CHECK constraints rest on MariaDB features Laravel's schema builder does not express.** | Those migrations write raw SQL and **fail loudly** if the server rejects them (the phase-06 INV-P17 / phase-14-17 [D-IN-4] rule), and `financial:verify-constraints` (the spine's daily command) is extended to assert these too. |
| R-12 | **Notification volume could become noise**, and a muted bell is a useless bell. | Per-user, per-event preferences; digest for `daily`; only four mandatory events; `ticket.replied` notifies the **other side** only; `NotifyMaterialAudience` stamps `notified_at` so a re-publish does not re-notify. |
| R-13 | **`attachments` belongs to Phase 6 and this contract was written before its §2.10 was final.** | §13.1 states the exact columns Phase 22 needs as a request; the migration that asserts the table exists **fails loudly** with an instruction rather than creating a rival `files` table. |
| R-14 | **Excel is still absent** although §99 says "CSV/Excel". | `ExportFormat::Excel` exists, is gated by `reports.excel_enabled`, and is off until the package is installed - so the gap is visible in a setting rather than promised by a button that 500s. Print, PDF and CSV ship complete. |

### 12.2 Open questions for the client (defaults assumed, nothing blocked)

| # | Question | Default assumed |
|---|---|---|
| Q1 | **Which fields may the public certificate page reveal?** The safe default shows the student name, course, completion date and grade. | `institute.certificate_verification_reveals = student_name, course_name, completion_date, grade`. Father's name, percentage, trainer and photo are available but **off**. One setting change, no code. |
| Q2 | **Certificate eligibility - should an unpaid fee block a certificate?** | **Yes** (`certificate_require_fee_cleared = true`), together with 75 % attendance, 100 % progress and passing every major exam. Every rule is a setting, and `certificates.approve` holders may override with a reason. |
| Q3 | **How is the certificate grade computed when a course has several exams?** | `weighted_average` over `isMajor()` exams using `exams.weight_percentage` (equal weight when unset). Alternatives `final_exam`, `best_exam` and `manual` are one setting away. |
| Q4 | **Late assignment policy** - reject, accept freely, or accept with a penalty? | Accept with a **0 %** default penalty (so lateness is recorded but costs nothing until the institute decides), `late_submission_allowed = true`, no hard cutoff. Per-assignment overrides exist. |
| Q5 | **May collaborators raise support tickets?** §59 does not list it, but §93's requester is "a user" and §33 gives collaborators a full panel. | **Yes** - `collaborator_portal.tickets` + `.ticket_create`, granted by default, switchable off with `support.ticket_allow_collaborator_create`. |
| Q6 | **May a portal user choose a ticket's priority?** | **No** - a client declaring everything "urgent" destroys the queue. Portal tickets take `support.ticket_default_priority`; staff set priority with a reason. |
| Q7 | **Should results be visible to students the moment they are entered, or only on publication?** | **Publication only**, with four-eyes verification on by default. A teacher may mark privately and release later (`marks_visible_to_students`). |
| Q8 | **Does a student see their rank?** | **Yes** (`result_card_show_position = true`) as a number only. A named ranked list of classmates is never shown to a student (§112). |
| Q9 | **ID-card validity period?** | 12 months, and an expiring card warns the student 30 days ahead. `0` months = no expiry. |
| Q10 | **Activity-log retention?** | **Never prune** (`reports.activity_log_retention_days = 0`). §110 wants an audit trail, and a financial log that deletes itself is not one. The prune command exists, is off, and refuses financial modules even when on. |
| Q11 | **Should messages be deletable or editable?** | **No** - append-only, like ticket replies and financial rows. A correction is a new message. This is also what makes §112 isolation provable. |
| Q12 | **Mail channel** - real SMTP for notifications now? | `support.notifications_mail_enabled = false` until real credentials exist (Q7 of `DEVELOPMENT_LOG.md` §9); every mail-capable event already declares `mail` in its defaults, so turning it on is one toggle. |
| Q13 | **Does the institute want a separate "announcement" feature** (one-to-many broadcast, as distinct from a conversation)? | **Not built** - §94 asks for conversations between role pairs and §97 for notifications; a broadcast is served today by a group conversation or by a notification. Recorded so the absence is a decision, not an omission. |

---

## 13. Requests to other phases

Stated as `table.column - why`, plus the behavioural asks.

### 13.1 Columns, tables and classes these phases need

| Request | Why |
|---|---|
| **`attachments`** (Phase 6 §2.10) with at least `attachable_type`, `attachable_id`, `disk`, `path`, `original_name`, `mime_type`, `size_bytes`, `checksum_sha256`, `uploaded_by`, **`visibility`** (enum `AttachmentVisibility`: `internal` / `team` / `client`, default `internal`), timestamps, soft deletes, `INDEX (attachable_type, attachable_id)` - and **no second `files` table** | §96, [D-22-3]. Phase 22 adds the `SupportTicket`, `TicketReply`, `Message`, `Meeting` and `Assignment` morph values and nothing else (Phase 6 itself also registers `Collaborator` and `Invoice`). Phase 19's own file tables stay separate because they carry targeting, windows, tracking and attempts |
| `course_topic_assignments.id`, `.title`, `.description`, `.instructions`, `.estimated_marks`, `.attachment_path` readable, and **hasMany `Assignment` by `course_topic_assignment_id`** - **Phase 14** (already promised in its §2.9) | §65 → §80: the blueprint instantiates into the gradable event |
| `courses.certificate_available` - **Phase 14** (exists) | the first, non-optional certificate eligibility rule |
| `courses.grade_scale_id` bigint nullable FK `grade_scales.id` `nullOnDelete`, in a guarded migration **shipped by Phase 20** - **Phase 14 must not re-create it** | a course-level default scale between the exam and the global setting. Optional: the fallback chain works without it |
| `BatchEnrollmentService::roster(Batch $b, string $date): Collection` - **Phase 16** (exists) | the single definition of "who is in this batch on this date", used by assignment `expected_count`, the result sheet and `markMissed()` |
| `TeacherScope::batchIds(Teacher $t): array` and the `BelongsToAuthenticatedStudent` scope - **Phases 16, 15** (exist) | every teacher and student isolation rule in §9 |
| **`ScheduleClashDetector::check(SlotCandidate $c): ClashReport`** - **SATISFIED**: phase-14-17 §6.7 publishes the generic check plus `App\DataObjects\Institute\SlotCandidate` (readonly `?int $teacherId`, `?int $classroomId`, `?int $batchId`, `CarbonInterface $startsAt`, `CarbonInterface $endsAt`, `?string $ignoreType`, `?int $ignoreId`) and `ClashReport` (readonly `bool $clean`, `array $conflicts`); the three subject-specific methods are wrappers over it (D47, audit F-4.7) | §81 exams and §95 meetings must not double-book a teacher or a room. §6.10 and §6.17 call `check()` once - never three checks in sequence |
| **`CourseProgressService::markTopicForStudent()` must accept a `source` of `ProgressSource::assessment`** (or expose `markFromAssessment(StudentBatchEnrollment, CourseTopic, array)`) - **Phase 17** | §83 + phase-14-17 §3, which reserved the case "for Phase 20". Until it lands, `institute.progress_from_assessment` stays off and its help text says so |
| `student_batch_enrollments.attendance_percentage` and `student_course_progress.completion_percentage` readable as caches - **Phase 17** (exist) | two certificate eligibility rules and the result card's attendance line |
| **`StudentFeeService::outstandingFor(StudentAdmission\|StudentBatchEnrollment $subject): string`** - **SATISFIED**: published by phase-18 §6.1 alongside `summaryFor()`, `reassignBatch()` and `withinServiceContext()` (audit F-4.6) | the `fee_cleared` eligibility rule. Phase 21 must never sum a fee row itself (INV-23-1) |
| `App\Services\Finance\DocumentNumberService::next(string $prefixKey, string $counterKey, string $pad = '%06d'): string` plus `reserve(string $counterKey, ?string $periodKey = null, ?string $periodValue = null): int` - **Phase 5** (the owner, D27; **do not re-create**) | certificate, ID-card and ticket numbering; each caller here passes its own pad explicitly |
| `App\Support\CsvWriter` with its formula-injection escape - **Phase 5** (exists; **do not re-create**) | every CSV export in §6.21 |
| **`ExportFormat` gains an `excel` case** with `mime()` / `extension()`, and `App\Services\Reporting\ReportExporter::export(ReportResult $r, ExportFormat $f): StreamedResponse` keeps its signature - **Phase 13 §6.9** (the owner; it asked for exactly this in its §13.2) | §99 "CSV/Excel" without a parallel export path |
| `FinanceReportService::report(FinanceReportType, DateRange, ReportFilters): ReportResult` and `App\Support\FinanceVisibility` - **Phase 13** (exist), with `App\Support\ReportResult` as the readonly `{array $rows, array $groups, array $totals, array $meta}` DTO of phase-13 §6.9 | the five `sh.*` finance reports and the money-column gating of INV-23-2 |
| `CollaboratorStatementService` (`commissionAccruedTotal`, the ledger query) and `CollaboratorWalletService` (`payoutsPaidTotal`, pending totals) - **Phase 12** | the nine `co.*` reports; phase-08-09 §13 and phase-10-12 §13 already require this of Phase 23 |
| `ReferralTrackingService::funnel()` - **Phase 9** | `co.performance`'s visit-to-admission funnel |
| `AttendanceReportService`'s four reports - **Phase 17** (exist) | `in.attendance` renders them unchanged |
| Phase 7's employee / attendance / payroll read models: a directory query, `attendance_monthly_summaries`, and **`App\Services\Hr\PayslipService::export(PayrollRun $run, string $format)`** (with `render(PayrollRunItem): View`) - **Phase 7 §6, which already declares exactly these two methods; nobody adds a second payslip class** (audit F-4.13) | `sh.employees`, `sh.attendance`, `sh.payroll`. Phase 23 calls this signature and never re-sums |
| A project read model exposing project, client, PM, status, progress, value, received - **Phase 6** | `sh.projects`, `ProjectSearchProvider`, and the meeting/ticket project links |
| `resources/views/layouts/print.blade.php` - **Phase 13 §6.9** (the sole owner - not "whichever of 13 or 18 ships first"; **do not create a second one**, audit F-4.14) | result cards, certificates, ID cards, report prints |
| `barryvdh/laravel-dompdf` - **Phase 13** (already planned in `DEVELOPMENT_LOG.md` §2) | every PDF here |
| `simplesoftwareio/simple-qrcode` - **installed by Phase 21** (already planned in `DEVELOPMENT_LOG.md` §2) | §84 and §85 QR codes |
| `App\Support\PermissionRegistry` - **Phase 1**: the five module slugs of §4.1, the ability deltas of §4.2, and the portal permissions of §4.3; `RoleSeeder` grants them per §4.3 | D4 - the registry is the only place a permission name exists |
| `App\Support\SettingsRegistry` - **Phase 2**: the `institute` keys of §5.1 and the two new groups of §5.2-5.3 | settings definitions in code, values in the DB |
| `App\Support\DashboardRegistry` - **Phase 2**: the widgets of §8.20; and (optional) `DashboardWidget::panel(): PanelType` so the student / teacher dashboards can use one widget framework | §98, and phase-08-09 §13 already requested the `panel()` addition |
| `App\Support\Money::sum(array): string` and `Money::percentage()` - **Phase 1** (also requested by the spine and Phase 5) | mark and report totals without a PHP `+` |
| `App\Support\Device` - **Phase 1** (exists) | `course_material_downloads.device`, `certificate_verifications.device` |
| Middleware alias **`site`** - **Phase 3 §6.10** (exists; Phase 3 ships `site`, `site.cache` and `site.preview` - the alias is `site`, never `site.enabled`, audit F-6.8) | the public verification page |
| A shared route group (or an include) for the bell, the preferences screen and the search palette across all five panel route files - **Phase 1 pattern** | §7.7, §7.8; five copies of the same five routes would drift |

### 13.2 Behaviour other phases must honour

| Request | Why |
|---|---|
| **Phase 6**: the project detail page's Files tab uses `attachments` filtered on **`visibility`** (`AttachmentVisibility::Client` for the client view), and its Tickets / Meetings panels read `TicketService::queue()` / `MeetingService::upcomingFor()` rather than their own queries | one definition of a figure (INV-23-1); one visibility mechanism (F-2.6) |
| **Phase 5**: the `ClientPortalSection` registrations for `tickets`, `meetings`, `files` and `messages` are **supplied by Phase 22** with the exact §9.4 queries, and the client write routes are appended at the `// Phase 22: client writes` marker ([D-P5-11]) | phase-05 §13 asked for exactly this |
| **Phases 5-18**: every notification class they already ship gets a `NotificationRegistry` entry (key, audience, level, URL, default channels) and is dispatched through `NotificationService`; **no phase calls `$user->notify()` directly after Phase 22 lands** | INV-22-7; otherwise the bell, the preferences screen and §97 are all partial |
| **Phase 13**: `finance.report_sync_row_limit` and `reports.sync_row_limit` should converge on one key in Phase 24's hardening pass; until then Phase 13's wins for its four reports | R-9 |
| **Phase 14**: the course landing page's outline accordion keeps rendering `course_topic_resources` (public syllabus), **never** `course_materials` (enrollment-gated) | §79 vs §65; a leaked material is a leaked file |
| **Phase 17**: `class_sessions` with a `course_topic_id` continue to drive batch coverage; Phase 20 writes progress **only** through `CourseProgressService` and never overrides a `manual` row | §83, phase-14-17 §6.10 |
| **Phase 18**: the student fee screens may link to a certificate's eligibility blocker ("fee outstanding blocks the certificate") but must not compute eligibility themselves | one definition |
| **Phase 24** (security / integrity): run PH19-01..09, PH21-10..21, PH22-20..28 and PH23-16 as part of the hardening pass, plus the two static scans PH23-45 and PH23-46 | §110, §111, §112 |
| **Phase 25** (deployment): document the `private` disk layout of §6.3, its backup inclusion, the queue workers the PDF and export jobs need, and every scheduler entry of §10.5 | §114, §115 |

### 13.3 Documentation and log updates

| Request | Why |
|---|---|
| `DEVELOPMENT_LOG.md` §4 - **cite D21** (no private artefact on the public disk): every private file is served by a controller that re-runs the full permission chain at stream time; no signed URL and no public-disk path is ever issued for a material, submission, certificate, ID card, attachment or export | INV-19-1, [D-19-4] |
| `DEVELOPMENT_LOG.md` §4 - **D51**: a mark's ceiling is enforced three times - Form Request, service, and a database CHECK made possible by snapshotting `total_marks` onto the result / submission row | INV-19-6, INV-20-1 |
| `DEVELOPMENT_LOG.md` §4 - **D52**: an issued certificate and an issued ID card are **snapshots**; a later rename, template edit or settings change alters neither their content nor their QR target. A wrong certificate is revoked and reissued, never edited or deleted | INV-21-1, INV-21-4 |
| `DEVELOPMENT_LOG.md` §4 - **D53**: a print template is sanitised HTML with `{{tokens}}`, rendered by a token replacer - never Blade, never `eval` | INV-21-5 |
| `DEVELOPMENT_LOG.md` §4 - **D54**: §94's six role pairs live in `App\Support\MessagingMatrix` plus one multiselect setting, are re-checked on every send, and are the reason a student cannot message a client | INV-22-4 |
| `DEVELOPMENT_LOG.md` §4 - **D55**: every notification in the system is declared in `NotificationRegistry` and delivered by `NotificationService`; a disabled `notifications` module is a logged no-op and never rolls back a business write | INV-22-7, INV-22-8 |
| `DEVELOPMENT_LOG.md` §4 - **D56**: a report is a declaration in `ReportRegistry` that delegates every figure to the service owning the table; a `SUM()` in a report class is a review failure, and a withheld column is absent from the query and the file | INV-23-1, INV-23-2 |
| `DEVELOPMENT_LOG.md` §9 - record Q1-Q13 of §12.2 as assumed defaults | open questions, nothing blocked |
| `CLAUDE.md` §6 - three new rules: (1) a private file is never linked, only streamed through its authorised route; (2) marks and percentages go through their calculator, never through PHP arithmetic; (3) a notification is sent through `NotificationService`, never through `$user->notify()` | the three mistakes most likely to be made by the next developer to touch these modules |
| `CLAUDE.md` §3 - **SATISFIED by the category rule**: `course_material_targets`, `course_material_downloads`, `assignment_submission_files`, `meeting_participants`, `conversation_participants`, `notifications`, `notification_preferences` and `report_exports` carry no `deleted_at` because they are append-only logs, pivots and snapshots under **D19**; no per-phase line and no local decision number is added | **cite D19** (`CLAUDE.md` §3, audit F-9.1), §2.1 |

---

## Convergence log (2026-09-12)

Applied from `docs/design/resolutions.md` §7 (Apply map row `docs/phases/phase-19-23.md`) plus its §2
ownership maps. Nothing outside those rows was restructured.

| Finding | Change made |
|---|---|
| F-2.5 | INV-21-5 and §6.13's `sanitize()` row now name `App\Support\RichText::sanitize()` (Phase 3, `mews/purifier`, D25) as the **only** sanitiser, applied on save **and** again on render; §1.2's Phase 3 row names it too. No `HtmlSanitizer`, no second purifier profile. |
| F-2.6 | §2.27: the `add_client_visibility_to_attachments_table` migration is **deleted**; the remaining guarded migration now also asserts the `visibility` column. Every `is_client_visible` reference replaced by `visibility = AttachmentVisibility::Client` (§9.4 Client row, §13.1, §13.2, §1.2). §13.1's ask to Phase 6 for the boolean is deleted; the `attachments` column list asks for `visibility` (enum) instead. |
| F-2.8 | [D-22-3] gains the slug-to-table sentence: the `files` **module slug** is a permission namespace and `attachments` is the table it gates; no `files` table is ever created (`CLAUDE.md` §3). |
| F-4.7 | §1.2, §2.11 Rules, §2.20, §6.10 and §6.17 now call the generic `ScheduleClashDetector::check(SlotCandidate): ClashReport` (phase-14-17 §6.7, D47) **once** instead of three subject-specific checks; §13.1's request is marked SATISFIED with the exact `SlotCandidate` / `ClashReport` shapes. |
| F-4.13 | §1.2 and §13.1 cite `App\Services\Hr\PayslipService::export(PayrollRun, string $format)` / `render(PayrollRunItem): View` exactly (phase-07 §6 - it already exists); "If the names differ, Phase 23 adapts" deleted. |
| F-4.14 | §1.2, §6.21 and §13.1 name **Phase 13 §6.9** as the sole owner of `App\Support\ReportResult` (readonly `rows` / `groups` / `totals` / `meta`), `App\Services\Reporting\ReportExporter::export(ReportResult, ExportFormat): StreamedResponse` and `resources/views/layouts/print.blade.php`; the "Phase 13 **or 18**, whichever ships first" clause and the `Response|PendingExport` return type are gone. |
| F-5.7 | §3.4: `TicketPriority` **deleted**; `support_tickets.priority` (§2.18) and `support.ticket_default_priority` (§5.2) cast to the shared `App\Enums\Priority` (phase-06 §3, added to §3's reuse list). `slaMultiplier()` moved off the enum to `TicketSlaService::multiplier(Priority $p): float` (§6.16), and PH22-10 asserts the service method. |
| F-5.10 | `assignment_submissions.marks_obtained` renamed **`obtained_marks`** everywhere: §2.7 column, `final_marks` generated expression, `chk_asub_marks`, `chk_asub_graded`, INV-19-6, §6.8 `grade()` + `AssignmentGradeCalculator` pseudocode, §8.5 marking form, §9.1 student column list, `assignments:verify-marks` (§10.5). One name, matching `exam_results.obtained_marks`. |
| F-6.5 | **No change** - §4.1 already declares all five new slugs `is_core = false`; `ModuleGroup` stays a grouping. |
| F-6.8 | The middleware alias is **`site`**, not `site.enabled`: §1.2, §7 preamble, the three §7.12 public verification routes and §13.1. §13.1 also re-attributes the alias to **Phase 3 §6.10** (which ships `site`, `site.cache`, `site.preview`) instead of phase-14-17 §7.10. |
| F-7.1 | Every `*_percentage` column widened to **`decimal(8,4)`**: `assignments.late_penalty_percentage`, `assignment_submissions.percentage`, `grade_scales.pass_percentage`, `grade_scale_bands.min_percentage` / `.max_percentage`, `exams.weight_percentage` / `.average_percentage`, `exam_results.percentage`, `certificates.percentage` / `.attendance_percentage` / `.progress_percentage`. INV-20-3's coverage range and the `institute.assignment_late_penalty_default_percentage` default restated at 4 decimals; a new §2 paragraph states the rule. The calculator's half-up-at-2 contract is **unchanged**. |
| F-7.2 | The same §2 paragraph records that marks stay `decimal(8,2)` and are not percentages - the ceiling is a per-row CHECK against a snapshotted `total_marks` - which is why `*_marks` is allowlisted in phase-24-25's FIN-18. |
| F-9.1 | §2 preamble cites **D19** (category rule, full table in `CLAUDE.md` §3) for the eight tables with no `deleted_at`; §2.1 row 2 cites D19 instead of phase-14-17's [D-IN-2]; §13.3's `CLAUDE.md` ask is marked satisfied by D19 rather than claiming a local number. |
| F-11.5 | §1.2 gains a paragraph naming an owner for all four cross-phase artefacts (`ReportResult`, `ReportExporter`, `layouts/print.blade.php` → Phase 13 §6.9; `PayslipService` → Phase 7 §6), so all four stay **required** rather than conditional. Phase 18's row also names `StudentFeeService::outstandingFor()`. |
| F-13.2 | [D-22-3] records that the morph map is shared: Phase 6 also registers `Collaborator` and `Invoice` (each with a policy), so Phase 22 **appends** its five values and never rewrites the map. §13.1's `attachments` row says the same. |
| F-13.5 | SLA is built **behind a switch**: `support.sla_enabled` (boolean, default `true`) added to §5.2; §2.18 records that every SLA clock column is nullable and every breach flag defaults false, so "off" needs no migration; §6.16 defines the off-behaviour (no clock stamped, `dueAt()` / `multiplier()` never called, `sweep()` a logged no-op, no breach badge or SLA card); §8.12, §10.5's `tickets:sla-sweep`, R-6 and PH22-10 restated accordingly. |
| F-10.1 | §13.3 renumbered to the registry of resolutions §4: D22 (file streaming) → **cite D21**, D23 → **D51**, D24 → **D52**, D25 → **D53**, D26 → **D54**, D27 → **D55**, D28 → **D56**. Phase-local `[D-19-n]` … `[D-23-n]` labels are untouched. |
| drift #2 | §5 preamble: `institute.attendance_minimum_percentage` is attributed to **phase-14-17 §5**, not Phase 2. |
| drift #3 | §5 preamble: `finance.report_sync_row_limit` is attributed to **phase-13 §5**, not Phase 2. |
| drift #4 | **No change needed** - R-9, §5.3 and §13.2 already self-flag `reports.sync_row_limit` vs `finance.report_sync_row_limit` and already name Phase 24's hardening pass as the convergence point. |
| §2.3 map | §13.1's numbering ask retargeted to **Phase 5** as the sole owner of `App\Services\Finance\DocumentNumberService` (D27), with the canonical `next()` / `reserve()` signatures and "each caller passes its own pad". |
| F-4.6 | §13.1's `StudentFeeService` ask marked **SATISFIED** by phase-18 §6.1 (`outstandingFor()` published alongside `summaryFor()`), with the "or an equivalent answer" hedge removed. |
