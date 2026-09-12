# DATA MODEL — 05 · The institute

## 1. Scope

This slice covers the institute object graph as it stands **after** the 2026-09-12 convergence
(`docs/design/resolutions.md` applied to every contract). Three contracts feed it:
[`../phases/phase-14-17.md`](../phases/phase-14-17.md) (Phases 14-17 — course categories, courses, the
three-level outline, course inquiries, applications, students, admissions, demo classes, teachers,
classrooms, batches, enrollments, timetable, class sessions, attendance, syllabus coverage and course
progress: **25 tables**), [`../phases/phase-18.md`](../phases/phase-18.md) (fee screens, services and
documents — **1 new table**, `student_fee_reminders`; the four fee **tables** are Phase 10's and are
defined in [`../design/finance-commission-spine.md`](../design/finance-commission-spine.md) §2.2-2.5 and
only *referenced* here, per resolutions §2.1 and F-11.3), and the institute part of
[`../phases/phase-19-23.md`](../phases/phase-19-23.md) (Phases 19-21 — course materials, assignments,
submissions, grade scales, exams, results, print templates, certificates and student ID cards:
**14 tables**). Requirement coverage: `../requirements.md` §61-75, §76-78 (fee screens only), §79-87,
§89-90 (public course surface), §105 (course SEO) and the institute half of §99. Phases 22-23 (tickets,
meetings, messaging, notifications, reports) are **not** this slice even though they ship in the same
contract. Binding decisions cited below: **D11** (branch), **D16/D19** (soft deletes), **D21** (private
artefacts), **D27** (`DocumentNumberService`), **D32** (users vs employees), **D37** (attribution),
**D45-D48** (institute), **D49-D50** (fee plans), **D51-D53** (marks, snapshots, print templates),
**D60** (manifests). Nothing below is asserted that a contract does not define; everything undecided is
in §6.

**Markers used in the inventory.** `SD:yes` / `SD:no` = soft deletes (`deleted_at`) present or
deliberately absent. `$` before a column = money, `decimal(15,2)` written only through
`App\Support\Money` (bcmath). `%` = percentage, `decimal(8,4)` (F-7.1, no exception). `#` = marks,
`decimal(8,2)` with a per-row CHECK against a snapshotted total (F-7.2, D51). `G` = STORED generated
column. `CACHE` = derived value with exactly one writer service and a recount command.

---

## 2. Table inventory

### 2.1 Phase 14 — curriculum (7 tables)

| Table | Owning phase | Purpose | Key columns | Key relationships |
|---|---|---|---|---|
| `course_categories` | 14 | §64 flat, reorderable catalogue grouping. `SD:yes`. No `parent_id` (Q1). | `name`, `slug` (uq), `icon`, `image_path` (**`public` disk** `courses/categories/`), `is_active`, `sort_order` (not unique), `courses_count` CACHE, `seo_title`, `seo_description` | hasMany `courses` (`restrictOnDelete`) |
| `courses` | 14 | §62 the sellable course; the public `/courses/{slug}` subject. `SD:yes`; archived, never deleted once sold. | `code` (uq), `slug` (uq, frozen after publish), `course_category_id`, `branch_id` (null = every branch), `$course_fee`, `$admission_fee`, `$registration_fee`, `$monthly_fee`, `installment_available`, `max_installments`, `level`, `delivery_mode`, `duration_value`+`duration_unit`, `total_classes`, `class_duration_minutes`, `default_teacher_id`, `requirements` json, `outcomes` json, `certificate_available`, `is_featured`, `admission_open`, `status`, `published_at`, `image_path`, `thumbnail_path`, `promo_video_url`, `modules_count`/`topics_count`/`lectures_count`/`outline_minutes` CACHE, `seo_title`/`seo_description`/`seo_keywords`/`og_image_path`/`canonical_url`/`is_indexable` | belongsTo `course_categories`, `branches`, `teachers` (`defaultTeacher`); hasMany `course_modules`, `batches`, `course_inquiries`, `student_applications`, `student_admissions`, `student_batch_enrollments`, `demo_classes`, `student_fees` (P10, read-only); morphMany `faqs` (**Phase 3**, `faqable`); belongsToMany `teachers` via `course_teacher` |
| `course_modules` | 14 | §65 outline level 1. `SD:yes`. | `course_id` (cascade), `title`, `sort_order`, `duration_minutes`, `is_active`, `topics_count`/`lectures_count` CACHE | belongsTo `courses`; hasMany `course_topics`, `student_module_progress` |
| `course_topics` | 14 | §65 outline level 2; the progress grain. `SD:yes`. | `course_module_id` (cascade), `course_id` (denormalised, asserted equal), `title`, `sort_order`, `weight` (1-100, CHECK), `estimated_minutes`, `is_active`, `lectures_count`/`resources_count`/`assignments_count` CACHE | belongsTo `course_modules`, `courses`; hasMany `course_lectures`, `course_topic_resources`, `course_topic_assignments`, `batch_topic_coverage`, `student_topic_progress`, `class_sessions` |
| `course_lectures` | 14 | §65 outline level 3. `SD:yes`. | `course_topic_id` (cascade), `course_id`, `title`, `lecture_type`, `sort_order`, `duration_minutes`, `video_url`, `is_preview`, `is_active` | belongsTo `course_topics`, `courses`; hasMany `class_sessions` |
| `course_topic_resources` | 14 | §65 **published syllabus** resource (not the distributed material of §79 — [D-19-1]). `SD:yes`. | `course_topic_id` (cascade), `course_id`, `title`, `type` (`CourseResourceType`), `file_path` (**`public` disk** `courses/{course}/resources/`), `external_url`, `file_size`, `mime_type`, `is_public`, `is_downloadable`, `sort_order`; CHECK `file_path OR external_url` | belongsTo `course_topics`, `courses` |
| `course_topic_assignments` | 14 | §65 assignment **blueprint** — never graded, no submissions ([D-IN-6]). `SD:yes`. | `course_topic_id` (cascade), `course_id`, `title`, `instructions`, `#estimated_marks`, `estimated_hours` `decimal(10,2)` (F-7.3), `attachment_path`, `sort_order`, `is_active` | belongsTo `course_topics`, `courses`; hasMany `assignments` (P19, by `course_topic_assignment_id`) |

**No `course_faqs` table (F-2.2).** Course FAQs are Phase 3 `faqs` rows with
`faqable_type = App\Models\Institute\Course`, written by `FaqService::save()` under `courses.edit`.

### 2.2 Phase 15 — funnel, students, admissions (6 tables)

| Table | Owning phase | Purpose | Key columns | Key relationships |
|---|---|---|---|---|
| `course_inquiries` | 15 | §86 institute funnel head. `SD:yes`. Not a `collaborator_referrals` subject — the snapshot lives here, the attribution attaches to the student at conversion. | `inquiry_number` (uq), `branch_id`, `name`, `phone`, `whatsapp`, `email`, `city`, `education`, `course_id`, `batch_id` (guarded FK), `preferred_delivery_mode`, `preferred_timing`, `source` (**Phase 4 `InquirySource`**, F-5.3), `source_url`, `contact_inquiry_id` (guarded FK -> Phase 4, `uq_ci_inquiry`, F-3.8), `status`, `assigned_to`, `follow_up_date`, `last_contacted_at` CACHE, `contact_attempts` CACHE, `collaborator_id` / `referral_code` / `referral_code_valid` / `referral_visit_id` (**display snapshots only**, INV-I3, D37, F-3.14), `lost_reason`, `converted_application_id`, `converted_student_id`, `converted_at`, `ip_address`, `user_agent`, `idempotency_key` (uq) | belongsTo `branches`, `courses`, `batches`, `users` (`assignee`), `collaborators`, `contact_inquiries` (P4), `collaborator_referral_visits` (P9), `student_applications`, `students`; hasMany `course_inquiry_follow_ups`, `demo_classes` |
| `course_inquiry_follow_ups` | 15 | §68 follow-up stage as an append-only contact log. **`SD:no`** (D19 log category). | `course_inquiry_id` (cascade), `channel`, `outcome`, `contacted_at`, `notes`, `next_follow_up_at`, `status_before`/`status_after` snapshots, `user_id`, `user_name` snapshot | belongsTo `course_inquiries`, `users` |
| `student_applications` | 15 | §67 public admission form landed as a reviewable record; creates no student, no login, no fee ([D-IN-7]). `SD:yes`. | `application_number` (uq), `branch_id`, `course_inquiry_id`, `name`, `father_name`, `phone`, `whatsapp`, `email`, `city`, `education`, `course_id` (restrict), `batch_id`, `preferred_timing`, `preferred_delivery_mode`, `message`, `referral_code` / `referral_code_valid` / `collaborator_id` / `referral_source` / `referral_visit_id` / `landing_url` (resolved server-side only, INV-I4), `ip_address`, `user_agent`, `idempotency_key` (uq), `duplicate_fingerprint` (**non-unique** — flags, never blocks), `duplicate_of_application_id`, `status`, `reviewed_by`/`reviewed_at`/`review_notes`, `rejection_reason`, `converted_student_id`, `converted_admission_id`, `converted_at` | belongsTo `courses`, `batches`, `branches`, `course_inquiries`, `collaborators`, `collaborator_referral_visits`, `users` (`reviewer`), self (`duplicateOf`), `students`, `student_admissions`; hasMany `demo_classes` |
| `students` | 15 | §66 the student record; exists before a login (D2). `SD:yes`; never force-deletable once fees or attendance exist. | `student_code` (uq, [D-IN-3]), `registration_number` (uq, NULLs stack; `registration_no` accessor for Phase 18), `user_id` (uq), `branch_id` (required by the spine), `name`, `father_name`, `gender`, `date_of_birth`, `cnic`, `phone`, `whatsapp`, `email`, `address`, `city`, `photo_path` (private tier, D24 item 14), `guardian_name`/`guardian_phone`/`guardian_relation`, `education`, `institution_name`, `joining_date`, `status`, `status_changed_at`, `status_reason`, `collaborator_id` / `referral_code` / `referral_source` / `referral_date` / `referral_visit_id` (**display snapshot, written only by Phase 9's `SyncReferralSnapshot`**, INV-I3, D37) | belongsTo `users`, `branches`, `collaborators`; hasMany `student_admissions`, `student_batch_enrollments`, `student_attendances`, `student_course_progress`, `demo_classes`, `student_fees` + `student_fee_payments` (P10), `collaborator_referrals` (P10); belongsToMany `batches` via `student_batch_enrollments`. **No `current_batch_id` / `current_course_id`** ([D-IN-8]) |
| `student_admissions` | 15 | §69 admission record; carrier of the §68 pipeline (**D45**) and the spine's default commission document. `SD:yes`. Agreed figures freeze at `figures_locked_at` (INV-I2). | `admission_number` (uq), `branch_id`, `student_id` (restrict), `course_id` (restrict), `batch_id`, `student_application_id`, `course_inquiry_id`, `stage` (`AdmissionStage`; `status` accessor alias for Phase 18, [D-IN-17]), `admission_date`, `registration_date`, `activated_on`, `completed_on`, `counselor_id`, `delivery_mode`, `preferred_timing`, `$course_fee`, `$admission_fee`, `$registration_fee`, `$discount_amount`, `$scholarship_amount`, `$total_amount`, `$net_payable` (the spine's collectible denominator), `$course_fee_net_payable` **G**, `$monthly_fee`, `discount_reason`, `payment_method`, `installment_plan_requested`, `requested_installments`, `figures_locked_at`, `$charged_amount`/`$paid_amount`/`$refunded_amount`/`$balance_amount` **CACHE — written only inside `StudentFeeService::withinServiceContext()`**, `cancelled_at`/`cancelled_by`/`cancellation_reason`, `withdrawn_at`/`withdrawal_reason`, `active_guard` **G**, `collaborator_id`/`referral_code` snapshots | belongsTo `students`, `courses`, `batches`, `branches`, `student_applications`, `course_inquiries`, `collaborators`, `users` (`counselor`, `canceller`); hasMany `student_batch_enrollments`, `student_fees` (P10), `collaborator_commission_entitlements` (P10, read-only) |
| `demo_classes` | 15 | §87 a demo is a real booking and takes part in clash detection. `SD:yes`. | `branch_id`, `subject_type` (`DemoSubjectType`), exactly one of `course_inquiry_id` / `student_application_id` / `student_id` (CHECK), `attendee_name`/`attendee_phone` snapshots, `course_id` (restrict), `batch_id`, `teacher_id` (restrict), `classroom_id`, `delivery_mode`, `meeting_url`, `scheduled_on`, `start_time`, `end_time`, `status`, `attended_at`, `attendance_remarks`, `converted_admission_id`, `cancellation_reason`, `reminder_sent_at`, `active_guard` **G** | belongsTo `course_inquiries`, `student_applications`, `students`, `courses`, `batches`, `teachers`, `classrooms`, `branches`, `student_admissions` |

### 2.3 Phase 16 — teachers, rooms, batches, schedule (7 tables)

| Table | Owning phase | Purpose | Key columns | Key relationships |
|---|---|---|---|---|
| `teachers` | 16 | §72 trainer; optionally linked to an `employees` row as an organisational **duty** (D32, F-11.1). `SD:yes`; deactivated once they have taught. | `teacher_code` (uq, [D-IN-3]), `user_id` (uq — login, marks the register), `employee_id` (uq, guarded FK -> `employees.id` — the duty), `branch_id`, `slug` (uq, required when `is_public`), `name`, `photo_path` (private tier), `phone`, `whatsapp`, `email`, `gender`, `qualification`, `experience_years`, `experience_note`, `skills` json, `specialization`, `bio`, `public_bio`, `social_links` json, `joining_date`, `$salary` (**display only, forced NULL when `employee_id` is set**, gated by `teachers.view_financial`), `status`, `status_reason`, `is_public`, `sort_order` | belongsTo `users`, `employees` (P7), `branches`; belongsToMany `courses` via `course_teacher`; hasMany `batches`, `timetable_entries`, `class_sessions`, `demo_classes`, `batch_topic_coverage`, `courses` (`defaultForCourses`) |
| `course_teacher` | 16 | §72 course-to-teacher pivot with payload. **`SD:no`**, no blameable (D19 history-pivot category). | `id` (addressable), `course_id` (cascade), `teacher_id` (cascade), `is_primary` (at most one per course, service-enforced), `assigned_on`; `uq_cte(course_id, teacher_id)` | belongsTo `courses`, `teachers` |
| `classrooms` | 16 | §70/§71 the bookable room — without a row there is nothing to clash-detect. `SD:yes`. | `branch_id`, `code` (uq), `name`, `type` (`ClassroomType`; `virtual` never clash-detected), `capacity` (CHECK > 0 — the second capacity ceiling for a physical batch), `location`, `is_active` | belongsTo `branches`; hasMany `batches`, `timetable_entries`, `class_sessions`, `demo_classes` (all `nullOnDelete`) |
| `batches` | 16 | §70 the running cohort. `SD:yes`; cancelled or completed, never deleted once it had a student. | `branch_id`, `code` (uq), `name`, `course_id` (restrict), `teacher_id` (restrict), `start_date`, `end_date`, `days` json (`Weekday[]`), `start_time`, `end_time`, `classroom_id`, `delivery_mode`, `meeting_url`, `student_capacity` (CHECK > 0), `current_students` **CACHE with no authority** (INV-I7, **D48**), `status`, `%syllabus_completion_percentage` CACHE, `sessions_planned_count`/`sessions_held_count` CACHE, `completed_on`, `cancellation_reason` | belongsTo `courses`, `teachers`, `classrooms`, `branches`; hasMany `student_batch_enrollments`, `timetable_entries`, `class_sessions`, `batch_topic_coverage`, `student_admissions`, `demo_classes`, `student_course_progress`, `student_fees` (P10); belongsToMany `students` via `student_batch_enrollments` |
| `student_batch_enrollments` | 16 | The roster — lifecycle, transfer history and attendance caches. `SD:yes`. Attendance, progress, submissions, results, certificates and ID cards all join through it. | `student_id` (restrict), `batch_id` (restrict), `course_id` (denormalised, asserted), `student_admission_id` (restrict), `roll_number` (uq per batch), `status`, `enrolled_on`, `completed_on`, `left_on`, `leave_reason`, `transferred_from_id`, `transferred_to_id` (uq), `transfer_reason`, `is_overbooked` + `overbook_reason` (CHECK, INV-I6), `sessions_expected_count`/`present_count`/`absent_count`/`leave_count`/`late_count` CACHE, `%attendance_percentage` CACHE, `%progress_percentage` CACHE, `current_guard` **G**; `uq_sbe_active(student_id, batch_id, current_guard)` | belongsTo `students`, `batches`, `courses`, `student_admissions`, self (`transferredFrom`/`transferredTo`); hasMany `student_attendances`, `assignment_submissions` (P19), `exam_results` (P20); hasOne `student_course_progress`, `certificates` (P21) |
| `timetable_entries` | 16 | §71 the **recurring weekly rule** (not a dated event). `SD:yes`. | `branch_id` (copied from batch, asserted), `batch_id` (cascade), `course_id` (restrict, denormalised), `teacher_id` (restrict), `day_of_week` (`Weekday`), `start_time`, `end_time`, `classroom_id`, `delivery_mode`, `meeting_url`, `effective_from`, `effective_to`, `is_active`, `active_guard` **G**; three exact-duplicate unique backstops (batch / teacher / room) behind `ScheduleClashDetector` | belongsTo `batches`, `courses`, `teachers`, `classrooms`, `branches`; hasMany `class_sessions` |
| `class_sessions` | 16 | The **dated occurrence** attendance and coverage attach to ([D-IN-10], **D46**). `SD:yes`. | `branch_id`, `timetable_entry_id` (null = one-off), `batch_id` (restrict), `course_id` (restrict), `teacher_id` (who actually takes it), `original_teacher_id` (substitution), `classroom_id`, `session_date`, `start_time`, `end_time`, `sequence_no`, `delivery_mode`, `meeting_url`, `title`, `course_topic_id`, `course_lecture_id`, `status`, `cancellation_reason` (`ClassCancellationReason`) + `cancellation_detail`, `rescheduled_to_id` (uq) / `rescheduled_from_id`, `expected_count`/`present_count`/`absent_count`/`leave_count`/`late_count` CACHE, `attendance_marked_at`, `attendance_marked_by`, `active_guard` **G**; `uq_cs_generated(timetable_entry_id, session_date, active_guard)` makes generation idempotent | belongsTo `timetable_entries`, `batches`, `courses`, `teachers` (`teacher`, `originalTeacher`), `classrooms`, `branches`, `course_topics`, `course_lectures`, `users`, self; hasMany `student_attendances`; hasOne `batch_topic_coverage` |

### 2.4 Phase 17 — attendance and progress (5 tables)

| Table | Owning phase | Purpose | Key columns | Key relationships |
|---|---|---|---|---|
| `student_attendances` | 17 | §75 four states, against a dated session only (INV-I9). `SD:yes` **but never deleted** — the policy refuses `delete` for every role; a correction is `amend()` (INV-I10). | `class_session_id` (restrict), `student_id` (restrict), `student_batch_enrollment_id` (restrict — proves the roster), `batch_id` (denormalised), `status` (`StudentAttendanceStatus`), `check_in_time`, `minutes_late`, `remarks`, `marked_via` (`AttendanceMarkSource`), `marked_by`, `marked_at`, `amended_at`/`amended_by`/`amendment_reason`; `uq_sa_session_student(class_session_id, student_id)` | belongsTo `class_sessions`, `students`, `student_batch_enrollments`, `batches`, `users` (`marker`, `amender`) |
| `batch_topic_coverage` | 17 | §83 class-level syllabus coverage; an upsert cache keyed by its unique index. **`SD:no`** (D19 derived cache). | `batch_id` (cascade), `course_topic_id` (cascade), `course_module_id` (cascade, denormalised roll-up), `class_session_id`, `status` (`ProgressStatus`), `%completion_percentage`, `covered_on`, `teacher_id`; `uq_btc(batch_id, course_topic_id)` | belongsTo `batches`, `course_topics`, `course_modules`, `class_sessions`, `teachers` |
| `student_course_progress` | 17 | §83 course level, one row per enrollment (**the grain**). `SD:yes`. | `student_batch_enrollment_id` (cascade, uq), `student_id`/`course_id`/`batch_id` denormalised, `status`, `%completion_percentage`, `modules_total`/`modules_completed`/`topics_total`/`topics_completed`, `weight_total`/`weight_completed`, `started_on`, `completed_on`, `last_activity_at` | belongsTo `student_batch_enrollments`, `students`, `courses`, `batches`; hasMany `student_module_progress`, `student_topic_progress` |
| `student_module_progress` | 17 | §83 module level, **derived only** from topic rows. **`SD:no`**, no blameable (never hand-edited). | `student_course_progress_id` (cascade), `course_module_id` (cascade), `status`, `%completion_percentage`, `topics_total`/`topics_completed`, `completed_on`; `uq_smp(student_course_progress_id, course_module_id)` | belongsTo `student_course_progress`, `course_modules` |
| `student_topic_progress` | 17 | §83 topic level — the only hand-marked grain. **`SD:no`**. | `student_course_progress_id` (cascade), `course_topic_id` (cascade), `course_module_id` (cascade, denormalised), `status`, `%completion_percentage`, `source` (`ProgressSource`; `assessment` reserved for Phase 20), `marked_by`, `marked_at`, `completed_on`, `remarks`; `uq_stp(student_course_progress_id, course_topic_id)` | belongsTo `student_course_progress`, `course_topics`, `course_modules`, `users` (`marker`) |

### 2.5 Phase 18 — fees (1 owned table; 4 referenced)

| Table | Owning phase | Purpose | Key columns | Key relationships |
|---|---|---|---|---|
| `student_fee_reminders` | **18** (the only table this phase creates) | §77/§97 reminder log; the dedupe guard is a unique index, not a SELECT. **`SD:no`**, **no `updated_at`** (D19 append-only log). Carries no money magnitude — it is not one of the spine's nine financial tables. | `student_fee_id` (cascade), `student_fee_installment_id` (cascade, nullable), `student_id` (cascade), `branch_id`, `type` (`FeeReminderType`), `channel`, `due_date`, `offset_days` (signed), `$amount_due` (snapshot), `recipient_email`, `recipient_phone`, `notification_id`, `sent_at`, `sent_by`, `is_manual`, `run_uuid`, `dedupe_line` **G** = `COALESCE(student_fee_installment_id, 0)`; `uq_sfr_dedupe(student_fee_id, dedupe_line, type, due_date, offset_days)` ([D18-1]) | belongsTo `student_fees`, `student_fee_installments`, `students`, `branches`, `users` (`sender`) |
| `student_fees` | **10** (spine §2.2) — Phase 18 inserts and maintains | The fee charge document (§41, §76); holds no cash. `SD:yes`. Referenced here, **never redefined**. | `fee_number` (uq), `generation_key` (uq, F-3.15 — the INSERT *is* the duplicate check), `branch_id`, `student_id`, `student_admission_id`, `course_id`, `batch_id` (display/filter only — `reassignBatch()` moves no money), `fee_type`, `title`, `$gross_amount`, `$discount_amount`, `$scholarship_amount`, `$net_amount`, `$paid_amount`, `$refunded_amount`, `$balance_amount` (may be negative = advance), `has_installment_plan`, `installment_count`, `due_date` ([D18-3]), `status`, `collaborator_id` (display snapshot written once at issue), `cancelled_*` | belongsTo `students`, `student_admissions`, `courses`, `batches`, `branches`, `collaborators`; hasMany `student_fee_installments`, `student_fee_discounts`, `student_fee_payments`, `student_fee_reminders`, `collaborator_commission_ledger_entries` (read-only trail) |
| `student_fee_installments` | **10** (spine §2.3) | §77 schedule line — a promise, not money. `SD:yes` (blocked by policy once paid). | `student_fee_id` (restrict), `installment_no` (uq per charge, never renumbered — **D50**), `$amount`, `due_date`, `$paid_amount` CACHE, `$waived_amount`, `paid_on`, `status`; `paid_amount <= amount` deliberately **not** enforced | belongsTo `student_fees`; hasMany `student_fee_payments`, `student_fee_reminders` |
| `student_fee_discounts` | **10** (spine §2.4) | §43/§78 append-only discount, scholarship, waiver and correction history. **`SD:no`** (D16 under D19). Phase 18 may **insert only**. | `student_fee_id`, `type`, `$amount` (signed delta to net), `percentage`, `reason` (mandatory), `approved_by`/`approved_by_name`/`approved_at`, `effective_on`, `reverses_discount_id`, `idempotency_key` | belongsTo `student_fees`, self (`reverses`/`reversedBy`) |
| `student_fee_payments` | **10** (spine §2.5) | §39/§42 cash received — the student commission trigger. **`SD:no`**. Inserted **only** through `PaymentService::recordStudentFeePayment()`. | `receipt_no` (uq), `idempotency_key`, `duplicate_fingerprint`, `student_fee_id`, `student_fee_installment_id`, `student_id`, `branch_id`, `$amount`, `$refunded_amount`, `$net_received_amount` **G**, `payment_method`, `payment_method_id`, `reference_no`, `paid_on`, `status`, `collaborator_id`, `collaborator_referral_id`, `commission_state`, `commission_skip_reason`, `received_by`/`received_by_name`, `receipt_path` | belongsTo `student_fees`, `student_fee_installments`, `students`; hasMany `payment_reversals` (spine §2.7) |

Phase 18 also **reads** `payment_reversals`, the entitlement / ledger / wallet tables (read-only, no
`SUM()` of its own — INV-26) and creates no other table (F-11.3).

### 2.6 Phases 19-21 — materials, assessment, documents (14 tables)

| Table | Owning phase | Purpose | Key columns | Key relationships |
|---|---|---|---|---|
| `course_materials` | 19 | §79 the **act of distribution** — targeted, time-windowed, enrollment-gated, download-tracked ([D-19-1]). `SD:yes`. | `branch_id`, `course_id` (restrict), `course_topic_id`, `teacher_id`, `title`, `type` (**Phase 14's `CourseResourceType`**), `storage_disk` (`private`, **never `public`** — D21), `file_path`, `original_name`, `extension`, `mime_type` (sniffed), `file_size_bytes`, `checksum_sha256`, `external_url`, `is_downloadable`, `available_from`/`available_until`, `status`, `published_at`, `audience_scope` CACHE, `targets_count`/`view_count`/`download_count`/`unique_students_count` CACHE; CHECK file XOR link | belongsTo `courses`, `course_topics`, `teachers`, `branches`; hasMany `course_material_targets`, `course_material_downloads` |
| `course_material_targets` | 19 | §79 "assigned to course, batch or student" — **three real FKs, no polymorphic id** ([D-19-2]). **`SD:no`** (D19 history pivot), no `updated_by`. | `course_material_id` (cascade), `target_type` (`MaterialTargetType`), `target_course_id` / `target_batch_id` / `target_student_id` (exactly one, CHECK), `target_key` **G** = `COALESCE(...)`, `notified_at`; `uq_cmt(course_material_id, target_type, target_key)` | belongsTo `course_materials`, `courses`, `batches`, `students` |
| `course_material_downloads` | 19 | §79 per-open tracking. **`SD:no`**, `created_at` only, no blameable. | `course_material_id` (cascade), `user_id`, `student_id`, `teacher_id`, `panel` (`PanelType`), `action` (`MaterialAccessAction`), `bytes_sent`, `ip_address`, `user_agent`, `device` | belongsTo `course_materials`, `users`, `students`, `teachers` |
| `assignments` | 19 | §80 the gradable event, instantiated from the Phase 14 blueprint. `SD:yes`; closed or archived once a submission exists. | `branch_id`, `course_id` (restrict), `batch_id` (restrict), `course_topic_assignment_id`, `course_topic_id`, `teacher_id` (restrict), `title`, `description`, `instructions`, `attachment_path` (+ name / mime / size, `private` disk), `#total_marks` (CHECK > 0), `#passing_marks`, `submission_type`, `allowed_extensions` json (narrows only), `max_file_size_mb`, `max_files`, `assigned_on`, `deadline_at`, `late_submission_allowed`, `late_cutoff_at`, `%late_penalty_percentage` (F-7.1), `allow_resubmission`, `max_attempts`, `marks_visible_to_students`, `status`, `published_at`, `closed_at`, `expected_count`/`submitted_count`/`late_count`/`graded_count`/`missed_count` CACHE, `#average_marks`/`#highest_marks` CACHE | belongsTo `courses`, `batches`, `course_topic_assignments`, `course_topics`, `teachers`, `branches`; hasMany `assignment_submissions`; morphMany `attachments` (**Phase 6**, `Assignment` morph value added by Phase 22) |
| `assignment_submissions` | 19 | §80 the submission and its mark. `SD:yes` but the policy refuses `delete`/`forceDelete` for every role. | `assignment_id` (restrict), `student_id` (restrict), `student_batch_enrollment_id` (restrict — proves the roster), `batch_id` (denormalised), `attempt_no`, `submission_text`, `files_count` CACHE, `status`, `submitted_at`, `is_late` (decided once at insert), `minutes_late`, `#obtained_marks` (**one name system-wide**, F-5.10), `#penalty_marks`, `#final_marks` **G**, `#total_marks` (**snapshot** — makes the ceiling a DB CHECK, D51), `%percentage`, `is_passed`, `feedback`, `feedback_file_path`, `graded_by`/`graded_at`, `returned_at`, `marks_released_at`, `superseded_by_id` (uq), `amended_*`, `current_guard` **G**; `uq_as_live(assignment_id, student_id, current_guard)` | belongsTo `assignments`, `students`, `student_batch_enrollments`, `batches`, `users` (`grader`, `amender`), self; hasMany `assignment_submission_files` |
| `assignment_submission_files` | 19 | Child file rows of a submission (a named exception to the `files`/`attachments` rule, `CLAUDE.md` §3). **`SD:no`**, no blameable. | `assignment_submission_id` (cascade), `storage_disk` (`private`), `file_path`, `original_name`, `extension`, `mime_type` (sniffed), `file_size_bytes`, `checksum_sha256` (duplicate **warning** only), `uploaded_by`, `download_count` | belongsTo `assignment_submissions`, `users` (`uploader`) |
| `grade_scales` | 20 | §82 the grade as **data, not code** ([D-20-1]). `SD:yes`; deactivated when referenced. | `code` (uq), `name`, `%pass_percentage` (F-7.1), `is_default` + `default_guard` **G** (`uq_gs_default` — one default for the whole DB), `is_active`, `sort_order`, `bands_count` CACHE | hasMany `grade_scale_bands`, `exams`, `exam_results`, `certificates` (all `restrictOnDelete`) |
| `grade_scale_bands` | 20 | The contiguous percentage bands of a scale. `SD:yes`. Contiguity / coverage is set-level and is validated by `GradeScaleService` plus `grades:verify-scales` (INV-20-3). | `grade_scale_id` (cascade), `grade` (uq per scale), `title`, `%min_percentage`, `%max_percentage` (F-7.1, inclusive), `grade_point` `decimal(4,2)`, `is_pass`, `color`, `remark_template`, `sort_order` | belongsTo `grade_scales`; hasMany `exam_results` (restrict) |
| `exams` | 20 | §81 the scheduled assessment; clash-checked through `ScheduleClashDetector::check(SlotCandidate)` (D47, INV-I8). `SD:yes`; cancelled, never deleted once a result exists. | `branch_id`, `course_id` (restrict), `batch_id` (restrict), `exam_type` (six cases), `name`, `course_topic_id`, `teacher_id` (restrict), `classroom_id`, `delivery_mode`, `meeting_url`, `scheduled_date`, `start_time`, `end_time`, `duration_minutes`, `#total_marks`, `#passing_marks`, `%weight_percentage` (F-7.1), `grade_scale_id` (restrict), `instructions`, `status`, `results_published_at`/`_by`, `results_verified_at`/`_by`, `expected_count`/`results_entered_count`/`appeared_count`/`absent_count`/`passed_count`/`failed_count` CACHE, `#highest_marks`/`#lowest_marks`/`#average_marks` CACHE, `%average_percentage` CACHE, `cancellation_reason`, `active_guard` **G**; `uq_ex_batch_slot(batch_id, scheduled_date, start_time, active_guard)` | belongsTo `courses`, `batches`, `course_topics`, `teachers`, `classrooms`, `branches`, `grade_scales`, `users`; hasMany `exam_results` |
| `exam_results` | 20 | §82 one result per (exam, student). `SD:yes` but the policy refuses `delete`/`forceDelete` for **every** role including Super Admin; a wrong row is amended with a reason (INV-20-5). | `exam_id` (restrict), `student_id` (restrict), `student_batch_enrollment_id` (restrict), `batch_id`, `course_id` (denormalised), `attendance_status` (`ExamAttendanceStatus`), `#obtained_marks`, `#total_marks` (**snapshot**, D51 — CHECK `obtained <= total`), `%percentage`, `grade_scale_id`, `grade_scale_band_id`, `grade` (snapshot label), `grade_point` snapshot, `is_passed`, `position_in_batch`, `remarks`, `entered_by`/`_at`, `verified_by`/`_at`, `published_at`, `amended_*`; `uq_er_exam_student(exam_id, student_id)` | belongsTo `exams`, `students`, `student_batch_enrollments`, `batches`, `courses`, `grade_scales`, `grade_scale_bands`, `users` |
| `print_templates` | 21 | §82/§84/§85 — **one** template table for result card, certificate and ID card ([D-21-1]); HTML with `{{tokens}}`, never Blade ([D-21-2], **D53**). `SD:yes`; deactivated once it printed a document. | `type` (`PrintTemplateType`), `code` (uq), `name`, `branch_id` (null = every branch), `paper_size` (`cr80` = ID card), `orientation`, `width_mm`/`height_mm`/`margin_mm`, `background_image_path` (`public` disk — a design asset with no PII), `logo_path`, `body_html` (sanitised by `RichText::sanitize()`), `custom_css` (sanitised), `tokens_used` json, `signatories` json, `show_qr`, `qr_size_mm`, `is_default` + `default_guard` **G** (`uq_pt_default(type, branch_id, default_guard)`), `is_active`, `sort_order`, `preview_path` (`private`) | belongsTo `branches`; hasMany `certificates`, `student_id_cards` (restrict) |
| `certificates` | 21 | §84 an issued certificate is a **snapshot** (**D52**); revoked and reissued, never edited or deleted. `SD:yes` but the policy refuses `delete`/`forceDelete` for every role (INV-21-1). | `certificate_number` (uq, via `DocumentNumberService`), `verification_code` (uq, char(16)), `branch_id`, `student_id` (restrict), `course_id` (restrict), `batch_id`, `student_batch_enrollment_id` (**the grain**, `uq_ce_live` with `live_guard`), `teacher_id`, `print_template_id` (restrict), `grade_scale_id` (restrict), the eight `*_snapshot` identity columns, `course_start_date`, `completion_date`, `grade`, `grade_point`, `%percentage`, `%attendance_percentage`, `%progress_percentage` (F-7.1, eligibility evidence), `eligibility_snapshot` json, `issued_on`/`issued_by`, `status`, `revoked_*`, `reissue_of_id` (uq) + `reissue_reason`, `qr_payload` (snapshotted absolute URL), `pdf_path` (`private`), `print_count`, `last_printed_*`, `verification_count` CACHE, `last_verified_at`, `is_publicly_verifiable`, `live_guard` **G** | belongsTo `students`, `courses`, `batches`, `student_batch_enrollments`, `teachers`, `branches`, `print_templates`, `grade_scales`, `users`, self (`reissueOf`/`reissuedAs`); hasMany `certificate_verifications` |
| `certificate_verifications` | 21 | §84 public verification log — the abuse-detection and rate-limit evidence. **`SD:no`**, `created_at` only, no blameable. | `certificate_id` (null when nothing matched), `submitted_code` (verbatim, truncated), `result` (`VerificationResult`), `ip_address`, `user_agent`, `device`, `referer` | belongsTo `certificates` |
| `student_id_cards` | 21 | §85 an issued card is a **snapshot** (**D52**); a lost card is `lost` and its replacement is a new row. `SD:yes`; policy refuses `delete`/`forceDelete`. | `card_number` (uq), `verification_code` (uq, char(16)), `branch_id`, `student_id` (restrict), `student_batch_enrollment_id`, `course_id`, `batch_id`, `print_template_id` (restrict), the seven `*_snapshot` columns, `photo_path` (**a copy** of the student photo at issue time, `private` disk), `issued_on`, `valid_until`, `status`, `replacement_of_id` (uq) + `replacement_reason`, `revoked_*`, `qr_payload`, `pdf_path` (`private`), `print_count`, `last_printed_*`, `live_guard` **G**; `uq_sic_live(student_id, live_guard)` — one active card per student | belongsTo `students`, `student_batch_enrollments`, `courses`, `batches`, `branches`, `print_templates`, `users`, self (`replacementOf`/`replacedBy`) |

### 2.7 Tables this domain references but does not own

| Table | Owner | Used for |
|---|---|---|
| `branches`, `users`, `activity_log` | 1 | `branch_id` (D11), `user_id` / blameable / marker / grader columns, every reasoned transition (INV-I14) |
| `faqs`, `faq_categories`, `seo_meta`, `media_assets` | 3 | course FAQs by `faqable` (F-2.2); the public catalogue's SEO by `route_key = site.courses.index`; the public image pipeline (D23, D24) |
| `contact_inquiries` | 4 | `course_inquiries.contact_inquiry_id` provenance through Phase 4's `InquiryRouter` (F-2.1, F-3.8) |
| `student_reviews` | 4 | §90's per-course reviews — needs `course_id` + an approved scope (phase-14-17 §13.1, still a request) |
| `attachments` | 6 | extra brief files on `assignments` (morph value added by Phase 22, [D-22-3]); client visibility is `attachments.visibility`, never a boolean (F-2.6) |
| `employees` | 7 | `teachers.employee_id` — an organisational **duty** (D32); guarded FK, `SyncTeacherFromEmployee` |
| `collaborators`, `collaborator_referral_visits` | 8 / 9 | the four referral snapshot column sets (inquiry, application, student, admission) and the visit evidence ids |
| `collaborator_referrals`, `collaborator_commission_entitlements`, `collaborator_commission_ledger_entries` | 10 | the **only** attribution authority (D37) and the read-only commission trail |
| `payment_methods` | 13 | `student_fee_payments.payment_method_id`; the `PaymentMethod` enum stays the snapshot of record |

---

## 3. Relationship map

```
branches (P1)
  students · teachers · classrooms · batches
  course_inquiries · student_applications · student_admissions · demo_classes
  courses (nullable: null = every branch)
  timetable_entries · class_sessions                     (copied from the batch, asserted equal)
  course_materials · assignments · exams · certificates · student_id_cards · print_templates
  student_fee_reminders

course_categories (P14)
  courses
    course_modules
      course_topics
        course_lectures
        course_topic_resources
        course_topic_assignments
          assignments                                    (P19, course_topic_assignment_id)
        batch_topic_coverage                             (P17)
        student_topic_progress                           (P17)
        class_sessions                                   (P16, course_topic_id = topic taught)
        exams                                            (P20, course_topic_id = what it assesses)
        course_materials                                 (P19, optional placement)
    faqs                                                 (Phase 3, faqable morph - no course_faqs table)
    course_teacher  ->  teachers
    batches
    course_inquiries · student_applications · student_admissions · demo_classes
    student_batch_enrollments · course_materials · assignments · exams · certificates
    student_fees                                         (Phase 10 table, read-only here)

course_inquiries (P15)                                   the §86 funnel head
  course_inquiry_follow_ups                              (append-only contact log)
  demo_classes
  student_applications                                   (course_inquiry_id; 1-0/1 by conversion)
  student_admissions                                     (course_inquiry_id, the funnel trail)
  -> contact_inquiries (P4, provenance) · collaborator_referral_visits (P9, evidence)

student_applications (P15)
  demo_classes
  students                                               (converted_student_id, 1-0/1)
  student_admissions                                     (converted_admission_id, 1-0/1)

students (P15)
  student_admissions                                     (one live per student+course: uq_sadm_live)
    student_batch_enrollments
    student_fees                                         (Phase 10; Phase 18 writes the caches back)
    collaborator_commission_entitlements                 (Phase 10, read-only)
  student_batch_enrollments
  student_attendances · student_course_progress · demo_classes
  certificates · student_id_cards · assignment_submissions · exam_results
  course_material_targets (target_student_id) · course_material_downloads
  student_fee_payments · student_fee_reminders            (Phase 10 / Phase 18)
  collaborator_referrals                                 (Phase 10 - THE attribution authority, D37)
  users                                                  (0/1-1, created at activation)

teachers (P16)
  employees                                              (0/1-1, Phase 7, a duty under D32)
  users                                                  (0/1-1, the teacher panel login)
  course_teacher -> courses ; courses.default_teacher_id
  batches · timetable_entries · class_sessions · demo_classes · batch_topic_coverage
  course_materials · assignments · exams · certificates (teacher_id snapshot)

classrooms (P16)
  batches · timetable_entries · class_sessions · demo_classes · exams     (all nullOnDelete)

batches (P16)
  student_batch_enrollments                              (the roster everything joins through)
    student_attendances
    student_course_progress                              (1-1, uq_scp_enrollment)
      student_module_progress                            (derived from topic rows)
      student_topic_progress                             (the only hand-marked grain)
    assignment_submissions · exam_results · certificates · student_id_cards
  timetable_entries                                      (the recurring weekly rule)
    class_sessions                                       (the dated occurrence - D46)
      student_attendances
      batch_topic_coverage                               (1-0/1 per session)
  batch_topic_coverage  ->  course_topics
  assignments · exams · course_materials (via course_material_targets.target_batch_id)
  student_fees.batch_id                                  (display/filter only - reassignBatch)

grade_scales (P20)
  grade_scale_bands
    exam_results
  exams · exam_results · certificates                    (snapshotted scale id)

print_templates (P21)
  certificates
    certificate_verifications                            (append-only public log)
  student_id_cards

assignments (P19)
  assignment_submissions
    assignment_submission_files
  attachments                                            (Phase 6 morph, extra brief files)

course_materials (P19)
  course_material_targets                                (course | batch | student, three real FKs)
  course_material_downloads                              (append-only, per open)

student_fees (P10 table, Phase 18 service)
  student_fee_installments
    student_fee_payments · student_fee_reminders
  student_fee_discounts                                  (append-only, signed delta to net)
  student_fee_payments
    payment_reversals                                    (P10)
  student_fee_reminders                                  (P18, the only table Phase 18 creates)
  collaborator_commission_ledger_entries                 (P10, read-only trail)
```

---

## 4. Enums

### 4.1 Declared by phase-14-17 §3 (27 enums, `app/Enums`, string-backed, `label()` + `color()` + `options()`)

| Enum | Cast by |
|---|---|
| `CourseStatus` | `courses.status` |
| `CourseLevel` | `courses.level` |
| `DeliveryMode` | `courses.delivery_mode`, `course_inquiries.preferred_delivery_mode`, `student_applications.preferred_delivery_mode`, `student_admissions.delivery_mode`, `demo_classes.delivery_mode`, `batches.delivery_mode`, `timetable_entries.delivery_mode`, `class_sessions.delivery_mode`, `exams.delivery_mode` (P20) |
| `DurationUnit` | `courses.duration_unit` |
| `LectureType` | `course_lectures.lecture_type` |
| `CourseResourceType` | `course_topic_resources.type`, `course_materials.type` (P19, reused — [D-19-3]); `institute.material_allowed_types` |
| `CourseInquiryStatus` | `course_inquiries.status`; snapshotted as strings in `course_inquiry_follow_ups.status_before` / `.status_after` |
| `FollowUpChannel` | `course_inquiry_follow_ups.channel` |
| `FollowUpOutcome` | `course_inquiry_follow_ups.outcome` |
| `PreferredTiming` | `course_inquiries.preferred_timing`, `student_applications.preferred_timing`, `student_admissions.preferred_timing` |
| `StudentApplicationStatus` | `student_applications.status` |
| `StudentStatus` | `students.status` |
| `AdmissionStage` | `student_admissions.stage` (the §68 pipeline — **D45**) |
| `Gender` | `students.gender`, `teachers.gender` — declared here and nowhere else (F-5.8) |
| `DemoSubjectType` | `demo_classes.subject_type` |
| `DemoClassStatus` | `demo_classes.status` (§87's four plus `cancelled`, [D-IN-11]) |
| `TeacherStatus` | `teachers.status` |
| `ClassroomType` | `classrooms.type` |
| `BatchStatus` | `batches.status` |
| `EnrollmentStatus` | `student_batch_enrollments.status` |
| `Weekday` | `timetable_entries.day_of_week`; the array in `batches.days`; `institute.timetable_working_days` |
| `ClassSessionStatus` | `class_sessions.status` |
| `ClassCancellationReason` | `class_sessions.cancellation_reason` |
| `StudentAttendanceStatus` | `student_attendances.status` — deliberately **not** `AttendanceStatus`, which is Phase 7's HR enum (F-5.9, resolutions §8 item 9) |
| `AttendanceMarkSource` | `student_attendances.marked_via` |
| `ProgressStatus` | `batch_topic_coverage.status`, `student_course_progress.status`, `student_module_progress.status`, `student_topic_progress.status` |
| `ProgressSource` | `student_topic_progress.source` (`assessment` reserved for Phase 20) |

### 4.2 Declared by phase-18 §3

| Enum | Cast by |
|---|---|
| `FeeReminderType` | `student_fee_reminders.type` |
| `InstallmentInterval` | **no column** — a parameter of `InstallmentPlanCalculator` and the plan wizard |

`RemainderPlacement` is **not** declared here: it is Phase 1's, beside `Money::distribute()` (F-4.11);
Phase 18 only casts `institute.installment_remainder_placement` to it.

### 4.3 Declared by phase-19-23 §3.1-3.3 (institute part)

| Enum | Cast by |
|---|---|
| `MaterialStatus` | `course_materials.status` |
| `MaterialTargetType` | `course_material_targets.target_type`, `course_materials.audience_scope` (cache of the broadest live target) |
| `MaterialAccessAction` | `course_material_downloads.action` |
| `AssignmentStatus` | `assignments.status` |
| `SubmissionType` | `assignments.submission_type` |
| `SubmissionStatus` | `assignment_submissions.status` |
| `ExamType` | `exams.exam_type` (§81's six, no seventh) |
| `ExamStatus` | `exams.status` |
| `ExamAttendanceStatus` | `exam_results.attendance_status` |
| `VerificationResult` | `certificate_verifications.result` (declared once in §3.2; serves certificates **and** ID cards) |
| `PrintTemplateType` | `print_templates.type` |
| `PaperSize` | `print_templates.paper_size` |
| `PageOrientation` | `print_templates.orientation` |
| `CertificateStatus` | `certificates.status` — three cases; `reissued` is a **link**, not a status ([D-21-3]) |
| `IdCardStatus` | `student_id_cards.status` |

### 4.4 Reused by this domain, declared elsewhere

| Enum | Owner | Cast by, in this domain |
|---|---|---|
| `InquirySource` (11 cases) | phase-04 §3 | `course_inquiries.source` — `CourseInquirySource` is **deleted** (F-5.3) |
| `ReferralSource` | spine §3 | `student_applications.referral_source`, `students.referral_source` |
| `PaymentMethod` | phase-07 §3 (cases from spine §3) | `student_admissions.payment_method` (intent snapshot), `student_fee_payments.payment_method` |
| `StudentFeeType`, `StudentFeeStatus`, `InstallmentStatus`, `FeeDiscountType`, `ReceivedPaymentStatus`, `ReversalType` | spine §3 | the four fee tables + `payment_reversals`; `institute.fee_structure_fee_types` |
| `PanelType` | Phase 1 | `course_material_downloads.panel` |
| `RemainderPlacement` | phase-01 §3 | `institute.installment_remainder_placement` |
| `AttachmentVisibility` | phase-06 §3 | `attachments.visibility` on assignment brief files (F-2.6) |
| `ExportFormat` | phase-13 §3 | institute report and result-sheet exports |

---

## 5. Modules and settings

### 5.1 Module slugs

Permission name is `{slug}.{ability}` (D4, R8); presets are Phase 1 §4's
(`READ`=2, `CRUD`=5, `CRUD_FULL`=7, `APPROVE`=2, `STATUS`=1, `ASSIGN`=1, `FILES`=2, `MONEY`=1,
`REPORTS`=3, `LOGS`=1). Counts below are **distinct ability names** after merging the presets (so the
`export`/`print` overlap between `CRUD_FULL` and `REPORTS` is counted once). All are `is_core = false`.

| slug | Group | New or activated | Ability set | Permissions |
|---|---|---|---|---|
| `course_categories` | Institute | activated by 14 | `CRUD` + `STATUS` | 6 |
| `courses` | Institute | activated by 14 | `CRUD_FULL` + `STATUS` + `MONEY` + `REPORTS` + `LOGS` | 11 |
| `course_outline` | Institute | activated by 14 | `CRUD` + `FILES` + `STATUS` | 8 |
| `course_inquiries` | Institute | activated by 15 | `CRUD_FULL` + `ASSIGN` + `STATUS` + `REPORTS` + `LOGS` | 11 |
| `student_applications` | Institute | **new** (15, §4.1) | `READ` + `create` + `edit` + `APPROVE` + `STATUS` + `export` + `LOGS` — **never `delete`** | 9 |
| `students` | Institute | activated by 15 | `CRUD_FULL` + `STATUS` + `import` + `FILES` + `REPORTS` + `LOGS` | 13 |
| `admissions` | Institute | activated by 15 | `CRUD_FULL` + `STATUS` + `MONEY` + `REPORTS` + `LOGS` | 11 |
| `demo_classes` | Institute | activated by 15 | `CRUD` + `ASSIGN` + `STATUS` + `print` + `REPORTS` | 10 |
| `teachers` | Institute | activated by 16 | `CRUD_FULL` + `ASSIGN` + `STATUS` + `MONEY` + `REPORTS` + `LOGS` | 12 |
| `classrooms` | Institute | **new** (16, §4.1) | `CRUD` + `STATUS` | 6 |
| `batches` | Institute | activated by 16 | `CRUD_FULL` + `ASSIGN` + `STATUS` + `REPORTS` + `LOGS` (**`assign` = enroll / transfer**) | 11 |
| `timetable` | Institute | activated by 16 | `CRUD` + `STATUS` + `print` + `export` + `REPORTS` | 9 |
| `student_attendance` | Institute | activated by 17 | `CRUD` + `STATUS` + `print` + `export` + `import` + `REPORTS` + `LOGS` | 11 |
| `student_progress` | Institute | activated by 17 | `READ` + `create` + `edit` + `STATUS` + `export` + `REPORTS` | 8 |
| `fee_reminders` | Institute | **new** (18, §4.1) | `READ` + `create` + `LOGS` | 4 |
| `course_materials` | Institute | activated by 19 | `CRUD` + `FILES` + `ASSIGN` + `STATUS` + `export` + `REPORTS` + `LOGS` | 13 |
| `assignments` | Institute | activated by 19 | `CRUD_FULL` + `FILES` + `STATUS` + `REPORTS` + `LOGS` | 12 |
| `assignment_submissions` | Institute | **new** (19, §4.1) | `READ` + `create` + `edit` + `download` + `STATUS` + `export` + `REPORTS` + `LOGS` — **never `delete`** | 10 |
| `grade_scales` | Institute | **new** (20, §4.1) | `CRUD` + `STATUS` | 6 |
| `exams` | Institute | activated by 20 | `CRUD_FULL` + `ASSIGN` + `STATUS` + `REPORTS` + `LOGS` | 11 |
| `results` | Institute | activated by 20 | `READ` + `create` + `edit` + `APPROVE` + `STATUS` + `print` + `export` + `import` + `REPORTS` + `LOGS` — **never `delete`** | 12 |
| `print_templates` | Institute | **new** (21, §4.1) | `CRUD` + `STATUS` + `print` | 7 |
| `certificates` | Institute | activated by 21 | `READ` + `create` + `edit` + `APPROVE` + `STATUS` + `print` + `export` + `LOGS` | 10 |
| `student_id_cards` | Institute | activated by 21 | `READ` + `create` + `edit` + `STATUS` + `print` + `export` + `LOGS` | 8 |

**Six new slugs in this domain** (`student_applications`, `classrooms`, `fee_reminders`,
`assignment_submissions`, `grade_scales`, `print_templates`); eighteen Phase 1 Institute slugs activated.
**229 permissions** in total across the 24 rows.

Spine-owned fee slugs the Phase 18 screens run on (listed for completeness, declared in spine §4):
`student_fees` = `CRUD_FULL` + `STATUS` + `MONEY` **+ `view_reports` (Phase 18)** = 10;
`installments` = `CRUD` + `STATUS` = 6; `fee_discounts` = `READ` + `create` + `APPROVE` + `MONEY` +
`LOGS` = 7; `student_fee_payments` = `READ` + `create` + `print` + `export` + `STATUS` + `MONEY` +
`LOGS` **+ `approve` (Phase 18, F-6.9)** = 9.

No new module for `class_sessions` (under `timetable`), `batch_topic_coverage` and the three progress
tables (under `student_progress`), `course_material_targets` / `course_material_downloads` (under
`course_materials`), `grade_scale_bands` (under `grade_scales`), `exam_results` (under `results`),
`certificate_verifications` (under `certificates.view_logs`), enrollment (under `batches.assign`) or
course FAQs (Phase 3's `faqs`, written under `courses.edit`).

`modules.depends_on` for this domain (phase-19-23 §4.4): `course_materials` -> `courses`, `batches`;
`assignments` -> `courses`, `batches`; `assignment_submissions` -> `assignments`; `exams` -> `courses`,
`batches`; `results` -> `exams`, `grade_scales`; `certificates` -> `students`, `courses`,
`print_templates`; `student_id_cards` -> `students`, `print_templates`; `grade_scales` and
`print_templates` have none.

### 5.2 Portal permission namespaces (D20: namespaces, not modules)

| Panel | Added by 14-17 §4.3 | Added by 19-23 §4.3 | Fees |
|---|---|---|---|
| Student | `.course`, `.batch`, `.timetable`, `.attendance`, `.progress`, `.teachers` (6) | `.materials`, `.material_download`, `.assignments`, `.assignment_submit`, `.exams`, `.results`, `.result_card`, `.certificate`, `.id_card` (9) | `student_portal.fees`, `.payments` — spine §4.3 |
| Teacher | `.batches`, `.students`, `.timetable`, `.course_outline`, `.attendance`, `.attendance_mark`, `.progress`, `.progress_mark`, `.demo_classes`, `.reports` (10) | `.materials`, `.material_upload`, `.assignments`, `.assignment_grade`, `.exams`, `.results_entry`, `.results`, `.certificates` (8) | none |
| Collaborator | none — §57's referred-student list is `StudentDirectoryService::forCollaborator()` under the existing `collaborator_portal.students`, scoped on an **active `collaborator_referrals` row only** (D37, R5) | none | none |

The read/write split is deliberate: `attendance_mark`, `progress_mark`, `assignment_grade`,
`material_upload`, `results_entry` and `material_download` are separate from their read counterparts so a
visiting trainer can read a roster without writing a register, and a fee-defaulting student can be shown
a material list while the files stay denied.

### 5.3 Settings that change this domain's behaviour

All keys live in Phase 2's **existing `institute` group** (no new group in this domain) — definitions in
`App\Support\SettingsRegistry`, values in `settings`. **88 keys**: 37 from phase-14-17 §5, 10 from
phase-18 §5, 41 from phase-19-23 §5.1.

| Family | Keys | What it changes in the data model |
|---|---|---|
| Student / registration numbering | `student_id_next_number`, `student_id_format`, `student_id_sequence_scope`, `student_id_period` *(readonly)*, `registration_number_next_number`, `registration_number_sequence_scope`, `registration_number_period` *(readonly)* | `students.student_code` and `.registration_number` issued once, in-transaction, by `StudentNumberService` over Phase 5's `DocumentNumberService::reserve()` (**D27**, INV-I5). The two `*_period` keys are the reset markers and are written only by the service |
| Other counters | `teacher_code_prefix`/`_next_number`, `inquiry_prefix`/`_next_number`, `application_prefix`/`_next_number`, `admission_prefix`/`_next_number`, `certificate_next_number`, `certificate_number_format`, `certificate_number_sequence_scope`, `certificate_number_period` *(readonly)*, `id_card_prefix`, `id_card_next_number` | `teachers.teacher_code`, `course_inquiries.inquiry_number`, `student_applications.application_number`, `student_admissions.admission_number`, `certificates.certificate_number`, `student_id_cards.card_number` — every caller passes its own pad to the one numbering service |
| Funnel | `inquiry_followup_days`, `inquiry_stale_days` (flags, never auto-closes), `admission_form_require_batch`, `application_duplicate_window_days` | `course_inquiry_follow_ups.next_follow_up_at` default, the stale-inquiry flag, whether `student_applications.batch_id` is mandatory, the `duplicate_fingerprint` warning window |
| Pipeline / logins | **`require_fee_before_activation`** (`none`\|`any_payment`\|`full_first_installment`), `auto_create_student_login`, `auto_create_teacher_login` | the **only** place §68's stage-5 / stage-6 ordering is decided (§2.31, D45); whether activation creates a `users` row with `must_change_password` |
| Capacity | `batch_default_capacity`, `batch_allow_overbooking`, `batch_near_capacity_threshold` | prefills `batches.student_capacity`; whether `student_batch_enrollments.is_overbooked` + `overbook_reason` can ever be set (INV-I6, **D48**) |
| Timetable | `timetable_working_days`, `timetable_day_start`, `timetable_day_end`, `timetable_slot_gap_minutes`, `session_generation_weeks_ahead` | slot validation, the minimum gap `ScheduleClashDetector` enforces (**D47**), and how far ahead `class_sessions` are materialised |
| Attendance | `attendance_lock_hours`, `attendance_minimum_percentage` *(declared by phase-14-17 §5, not Phase 2 — audit drift #2)*, `attendance_leave_counts_in_denominator`, `attendance_auto_absent_on_close`, `demo_class_duration_minutes` | the INV-I10 amendment window; the `%attendance_percentage` denominator; whether closing a session writes `absent` rows with `marked_via = system`; `demo_classes.end_time` default |
| Progress | `progress_weighting` (`topic_count`\|`topic_weight`), `progress_from_assessment` | whether `student_course_progress.weight_total` or the topic count is the denominator; whether publishing a topic-linked exam writes `student_topic_progress` with `ProgressSource::assessment` |
| Public catalogue | `public_course_catalogue_per_page` | §89 pagination of `PublicCourseService` |
| Fees (phase-18 §5) | `fee_due_days`, `monthly_fee_due_day`, `installment_remainder_placement`, `fee_overdue_grace_days`, `discount_approval_required`, `discount_max_percentage`, `fee_structure_fee_types`, `auto_generate_fee_structure_on_admission`, `fee_slip_show_commission`, `fee_slip_footer_note` | `student_fees.due_date` / `.status` (the overdue sweeper), the deterministic paisa placement of `Money::distribute()` (**D49**), whether a `student_fee_discounts` row needs an approver ([D18-8]), which heads the structure generator offers, and whether §68's registration -> fee-collection step runs automatically |
| Materials | `material_max_upload_mb`, `material_allowed_types`, `material_extra_extensions` (**intersected** with `security.allowed_file_types`, never widening), `material_visible_after_batch_end_days`, `material_download_log_retention_days`, `material_notify_on_publish` | `course_materials.file_size_bytes` / `.mime_type` validation, the post-batch visibility window (INV-19-3) and the `course_material_downloads` prune horizon |
| Assignments | `assignment_submission_max_mb`, `assignment_max_files_default`, `assignment_max_attempts_default`, `assignment_late_submission_default`, `assignment_late_penalty_default_percentage`, `assignment_deadline_reminder_hours`, `assignment_auto_close_on_deadline`, `assignment_release_marks_immediately` | prefills `assignments.max_files`, `.max_attempts`, `.late_submission_allowed`, `.late_penalty_percentage`, `.marks_visible_to_students`; drives `assignments:close-due` |
| Exams / results | `default_grade_scale_id`, `exam_default_passing_percentage`, `result_publish_requires_verification`, `result_card_show_position`, `result_card_show_attendance`, `result_card_show_all_exams` | the `grade_scale_id` fallback chain (exam -> `courses.grade_scale_id` -> this), `exams.passing_marks` prefill, the four-eyes step that fills `exam_results.verified_by` (the verifier may not be the enterer), and what the result card renders |
| Certificates | `certificate_require_pass`, `certificate_require_min_attendance`, `certificate_require_min_progress`, `certificate_require_fee_cleared`, `certificate_require_enrollment_completed`, `certificate_grade_source`, `certificate_issue_requires_approval`, `certificate_footer_note` | every rule recorded in `certificates.eligibility_snapshot`; `certificate_require_fee_cleared` is answered by `StudentFeeService::outstandingFor()` — Phase 21 never sums a fee row (INV-23-1) |
| Public verification | `certificate_verification_reveals` (multiselect), `certificate_verification_rate_limit_per_minute`, `certificate_verification_log_retention_days` | **the whole of INV-21-3 in one setting** — which snapshot columns the public page may show; the rate limit whose evidence is `certificate_verifications`; the log prune horizon |
| ID cards | `id_card_validity_months`, `id_card_require_photo`, `id_card_batch_print_max` | `student_id_cards.valid_until`; whether a card can be issued without `photo_path`; the batch-print ceiling |

Pre-existing keys this domain **reads and never redefines**: `institute.admission_open` (ANDed with
`courses.admission_open`), `institute.default_branch_id`, `institute.student_id_prefix`,
`institute.registration_number_format`, `institute.attendance_grace_minutes` (feeds
`student_attendances.minutes_late`), `institute.default_class_duration`,
`institute.installment_reminder_days`, `institute.certificate_prefix`,
`institute.certificate_verification_url`, `institute.fee_receipt_prefix` / `_next_number`,
`institute.fee_record_prefix` / `_next_number`, `maintenance.admission_form_enabled`,
`security.max_upload_mb`, `security.allowed_file_types`, `localization.week_start`,
`finance.backdate_limit_days`, and every `collaborator.*` commission key.

---

## 6. Open points

| # | Open point | Source |
|---|---|---|
| O-1 | **SEO columns on institute tables vs D23.** `courses.seo_title` / `.seo_description` / `.seo_keywords` / `.og_image_path` / `.canonical_url` / `.is_indexable` and `course_categories.seo_title` / `.seo_description` are still defined in phase-14-17 §2.3-2.4, while **D23 / F-2.3** say "one SEO store: `seo_meta` via `morphOne` + `SeoService`; no phase adds SEO columns to its own table". F-2.3's apply map lists only phase-03 and phase-04, so phase-14-17 was never edited. Either the six course columns become a `seoable` morph (and `og_image_path` becomes `seo_meta.og_image_media_id`) or D23 needs an institute exception. **No name invented here.** | F-2.3, D23, resolutions §7 apply map; phase-14-17 §2.3, §2.4, §13.1 (which already asks Phase 3 for `seo_meta` keyed `route_key = site.courses.index`) |
| O-2 | **Public-rendered course images as bare `*_path` vs D24.** `course_categories.image_path` and `course_topic_resources.file_path` are explicitly on the **`public` disk**, and `courses.image_path` / `.thumbnail_path` / `.og_image_path` name no disk at all — yet all of them render on the public site, where **D24 / F-2.4** make `media_assets` + `MediaService` mandatory. Resolutions §2.1 exempts only the *private* tier (`students.photo_path`, teacher photo, `clients.logo_path`, `employees.photo_path`, `collaborators.photo_path`); it never names the four course columns, and phase-14-17 is not in F-2.4's apply map. | F-2.4, D24, resolutions §2.1 / §8 item 14; phase-14-17 §2.3, §2.4, §2.8 |
| O-3 | **Commission denominator for a student admission is still an explicit ask to Phase 10.** phase-14-17 §13.1 asks Phase 10 to confirm whether `CommissionBaseResolver` reads `student_admissions.net_payable` (as contracted) or the generated `course_fee_net_payable` when `collaborator.commission_on_admission_fee` / `..._registration_fee` are false. Both columns exist; the contract insists it "must be a decision, not a default". Until answered, `net_payable` stands and a prorated fixed commission releases slightly more slowly than a purist expects. | phase-14-17 §13.1, R-7; spine §6.1.4 |
| O-4 | **`courses.grade_scale_id` is optional and owned by Phase 20, not Phase 14.** phase-19-23 §13.1 requires a nullable FK `grade_scales.id` `nullOnDelete` added in a **guarded migration shipped by Phase 20**, and says "Phase 14 must not re-create it". phase-14-17 §2.4 does not list the column. The fallback chain (`exams.grade_scale_id` -> `institute.default_grade_scale_id`) works without it, so the column's existence is still conditional. | phase-19-23 §13.1, §2.11; phase-14-17 §2.4 |
| O-5 | **`institute.progress_from_assessment` ships off.** Phase 20 may write `student_topic_progress` only through `CourseProgressService::markTopicForStudent(..., source: ProgressSource::assessment)` (or `markFromAssessment()`), a method phase-14-17 §6.10 has not yet published with that signature. The setting's help text must say so until it lands. `ProgressSource::assessment` exists as a reserved case. | phase-19-23 §13.1, §6.12; phase-14-17 §3, §13.1 |
| O-6 | **`student_reviews.course_id` + an approved scope is an unfulfilled request to Phase 4**, needed by §90's per-course review block on the course landing page. Nothing in phase-04 is recorded as satisfying it. | phase-14-17 §13.1 |
| O-7 | **Two name aliases carried by accessors, not by columns.** `student_admissions.stage` exposes a `status` accessor/mutator + `scopeLive()` for Phase 18 ([D-IN-17]); `students.registration_number` exposes a `registration_no` accessor; `courses.duration_value` + `.duration_unit` expose a `duration` accessor. These are resolved, not open — but any migration, factory, index manifest row or raw query must use the **column** names, never the alias. | phase-14-17 §13.4, §2.14, §2.15; phase-18 §13.1 |
| O-8 | **Private-disk placement is not stated for five upload columns**: `students.photo_path`, `teachers.photo_path`, `course_topic_assignments.attachment_path`, `student_id_cards.photo_path` *(stated `private`)* and `certificates.pdf_path` *(stated `private`)*. The first three name no disk. **D21** requires a private disk plus a controller that re-runs the permission chain; `phase-24-25`'s `upload-manifest.php` is the authority and must carry a row (disk + permission) for each. | D21, F-12.5, D60; phase-14-17 §2.9, §2.14, §2.17 |
| O-9 | **Client-facing scope decisions recorded as defaults, each one setting away** (resolutions §6.2 / phase-14-17 §12.2 / phase-19-23 §12.2): flat course categories, no inquiry Kanban, `leave` excluded from the attendance denominator (Q6), no auto-absent on close (Q7), class-driven progress with per-student override (Q8), weighted topics (Q9), `cancelled` added to `DemoClassStatus` (Q10), 8-week session horizon (Q13), 0 % default late penalty (19-23 Q4), publication-only result visibility with four-eyes verification (Q7), rank shown as a number only (Q8), 12-month ID-card validity (Q9), and `grade_scales` / `grade_scale_bands` built as configurable data (**F-13.13**). None blocks a migration; each is a settings flip. | phase-14-17 §12.2 Q1, Q4, Q6-Q10, Q13; phase-19-23 §12.2 Q1-Q4, Q7-Q9; resolutions §6.2 F-13.13 |
| O-10 | **Phase 18's Q3, Q4, Q6, Q7, Q9, Q11 remain client questions with no schema consequence**: slip granularity (admission-level vs per head), where the monthly amount is typed (both optional columns exist), no yearly reset of fee/receipt numbers, no plan built after money is received ([D18-4] — changing it would be a **spine** change), the collaborator's name but never a figure on a student slip, and exam/certificate fees non-commissionable by default. | phase-18 §12.2 |
| O-11 | **Three named non-DB guarantees** a reviewer must not try to "fix" with an index: schedule overlap (**D47** — MariaDB cannot express a range constraint; `ScheduleClashDetector` under parent row locks + three exact-duplicate unique backstops + `timetable:verify-clashes`), capacity (**D48** — a locked recount, never the `current_students` cache), and grade-band contiguity / full 0-100 coverage (set-level; `GradeScaleService::validateBands()` + `grades:verify-scales`). | phase-14-17 §12.1 R-1, R-2, INV-I6-I8; phase-19-23 §2.10, INV-20-3 |
| O-12 | **Cache drift is a standing operational risk, not a defect.** Thirteen CACHE families in this domain (`*_count` on courses / modules / topics / batches / sessions / enrollments / assignments / exams / materials, `%attendance_percentage`, `%progress_percentage`, `%completion_percentage`, `%syllabus_completion_percentage`, `certificates.verification_count`, and the four `student_admissions` money caches) each have exactly one writer service, a recount command, a nightly scheduled proof and a corrupt-then-repair test. The four money caches additionally refuse any write outside `StudentFeeService::withinServiceContext()`. | phase-14-17 §12.1 R-3, INV-I7, INV-I11, INV-I2; phase-18 §2.3 |

### Invariant cross-check (nothing below is weakened anywhere in this slice)

| Guarantee | Where it lives |
|---|---|
| This domain creates, updates or deletes **no money row**; the admission holds agreed figures and Phase-18-written caches only | INV-I1, INV-I2; phase-14-17 §6.8 |
| Attribution authority is `collaborator_referrals`; every `*.collaborator_id` / `*.referral_code` / `*.referral_visit_id` here is a display snapshot read by no engine and used by **no scope** | **D37**, R5, INV-I3; phase-14-17 §9, §6.14 |
| Nothing the browser posts is trusted as a referral code — the form posts Phase 9's visit token and the server re-resolves | INV-I4; phase-14-17 §6.4 |
| Every percentage is `decimal(8,4)` through `Money::percentageOf()`-grade bcmath; marks are `decimal(8,2)` with a CHECK against a snapshotted total | INV-I11 (F-7.1), **D51** (F-7.2) |
| Marks, certificates and ID cards are snapshots: revoked and reissued, never edited or deleted; results and submissions are never deleted by any role | **D51**, **D52**; INV-20-5, INV-21-1 |
| No private artefact on the public disk; every material, submission, PDF and card is streamed by a controller that re-runs the permission chain | **D21**; phase-19-23 §6.1-6.4 |
| One numbering implementation (Phase 5), one clash detector, one fee service, one progress writer, one attendance writer | **D27**, **D47**, F-4.6, INV-I11, INV-I10 |
| Every FK column in this domain is indexed **and** has a row in `tests/Support/index-manifest.php`; `audit:manifest --check` is part of the definition of done | F-9.2, **D60** |
