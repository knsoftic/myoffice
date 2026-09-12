# PHASE 14-17 CONTRACT - Institute core (courses, outline, inquiries, admissions, students, teachers, batches, timetable, attendance, progress)

**Status: binding.** One document for four phases because they share one object graph: a course cannot be
sold without an outline, an admission cannot complete without a batch, a batch cannot run without a
timetable, and attendance and progress are meaningless without both. Splitting them into four contracts
would have produced four copies of the same schema.

Covers requirement [`../requirements.md`](../requirements.md) **§61-75**, **§86**, **§87**, and the public
course surface of **§89-90**. Where it meets money it stops: every rupee is owned by
[`../design/finance-commission-spine.md`](../design/finance-commission-spine.md) (the Phase 10/18
contract). Where it meets Phase 1 or Phase 2 those contracts win
([`phase-01.md`](phase-01.md), [`phase-02.md`](phase-02.md)); conflicts are recorded in §12, never
silently redesigned.

Decisions are labelled **[D-IN-n]** so a code review can cite them.

| § | Contents |
|---|---|
| 1 | Goal, dependencies, phase ownership, invariants |
| 2 | Schema (25 tables), `branch_id` placement, status lifecycles, the §68 pipeline as transitions |
| 3 | Enums |
| 4 | PermissionRegistry additions |
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

After these four phases the institute runs end to end without a spreadsheet: a coordinator publishes a
course with its three fee components and a three-level outline; the public site lists it at
`/courses/{slug}` and a visitor arriving through a collaborator's referral link applies on the public
admission form with the referral intact; a receptionist works the inquiry, logs follow-ups, schedules a
demo class, and walks the applicant through the seven-step admission pipeline of §68, each step an
explicit, permissioned, audited state transition; registration issues a student ID and a registration
number from the institute settings; fee collection is handed to the financial spine (this phase never
writes a money row); batch assignment enforces capacity without a race; the timetable refuses to
double-book a teacher, a classroom or a batch and renders in the five views of §71; teachers mark
attendance in four states per dated class session and tick off syllabus topics; and every student,
teacher, batch and branch has an attendance percentage and a course-progress percentage that are
re-derivable from the underlying rows.

### 1.2 Dependencies

| Phase | What this phase needs from it |
|---|---|
| 1 | `users`, `branches`, RBAC + `PermissionRegistry` + `Gate::before` module gating, `Blameable`, `LogsActivityWithContext`, `App\Support\Money`, `Sidebar`, `layouts/admin`, `layouts/panel`, the `x-ui.*` set, `active` / `module` / `panel` middleware |
| 2 | `SettingsRegistry` + `SettingsService` (group `institute`), `DashboardRegistry`, `DateRange`, `Format` (`money()`, `app_date()`, `app_time()`), `users.preferences` |
| 3 | `layouts/site.blade.php`, the `site` / `site.preview` / `site.cache` middleware aliases, the SEO head partial + `seo_meta` by `route_key`, `SitemapRegistry`, and the CMS section renderer that embeds the course carousel and the upcoming-batch strip (§89) |
| 4 | `contact_inquiries` and `App\Enums\InquirySource` - Phase 4 owns both; a CMS contact-form course enquiry arrives through Phase 4's `InquiryRouter` and lands with `course_inquiries.contact_inquiry_id` set (§2.11, F-2.1, F-3.8, F-5.3) |
| 5 | `App\Services\Finance\DocumentNumberService` - `next()` **and** `reserve()`. Phase 5 ships the single numbering implementation (**D27**, F-4.1, F-4.12); this phase reuses it and creates no counter service |
| 7 | `employees` - the optional teacher link (§72, a **duty** under D32) - and the `EmployeeUpdated` event. The FK is added in a `Schema::hasTable()`-guarded migration so `migrate:fresh` works in any order **[D-IN-1]** |
| 8 | `collaborators` (`status`, `referral_code`, `name`) for the referral snapshot on students, applications and inquiries |
| 9 | `collaborator_referral_visits`, the `CaptureReferral` middleware, `ReferralAttributionResolver`, `ReferralLinkService::tokenForForm()`, the `<x-site.referral-field>` component and the `SyncReferralSnapshot` listener - this phase uses all five and re-implements none |
| 10 | `ReferralService::resolveCode()` / `::attach(..., ?ReferralContext $context = null)` / `::effectiveOn()`, `App\DataObjects\Collaborator\ReferralContext` (spine §13.2), the `ReferralAttached` / `ReferralChanged` / `ReferralRevoked` events. **Not** `DocumentNumberService`, which is Phase 5's (F-4.1) |
| 18 | `StudentFeeService::generateStructure()` / `::transferPayment()` plus F-4.6's four - `::summaryFor()`, `::reassignBatch()`, `::withinServiceContext()`, `::outstandingFor()` - and `PaymentService` (receipts): called, never reimplemented. Every one of them is published in Phase 18 §6.1 |

Migration timestamps sort **after** phase 7 and **before** the spine's `add_fks_to_*` migrations, which
attach `student_fees.student_id`, `.student_admission_id`, `.course_id` and `.batch_id` to my tables.

### 1.3 Phase ownership - no table is created twice

| Phase | Owns (code, screens, services) | Must NOT create or write |
|---|---|---|
| **14** | `course_categories`, `courses`, `course_modules`, `course_topics`, `course_lectures`, `course_topic_resources`, `course_topic_assignments`; `CourseService`, `CourseCategoryService`, `CourseOutlineService`, `PublicCourseService`; the public catalogue and landing page | `course_materials` (Phase 19), `assignments` (Phase 19), `faqs` / `faq_categories` (Phase 3 - course FAQs are `faqs` rows, see §2.10) |
| **15** | `course_inquiries`, `course_inquiry_follow_ups`, `student_applications`, `students`, `student_admissions`, `demo_classes`; `CourseInquiryService`, `StudentApplicationService`, `StudentService`, `StudentNumberService`, `AdmissionService`, `DemoClassService`; the public admission form | any `student_fee*` table or row (Phase 10 owns the tables, Phase 18 the service); `collaborator_referrals` rows (written only through `ReferralService`) |
| **16** | `teachers`, `course_teacher`, `classrooms`, `batches`, `student_batch_enrollments`, `timetable_entries`, `class_sessions`; `TeacherService`, `ClassroomService`, `BatchService`, `BatchEnrollmentService`, `TimetableService`, `ScheduleClashDetector`, `ClassSessionService` | `employees` (Phase 7) |
| **17** | `student_attendances`, `batch_topic_coverage`, `student_course_progress`, `student_module_progress`, `student_topic_progress`; `AttendanceService`, `AttendanceReportService`, `CourseProgressService` | `exams`, `results` (Phase 20), `certificates` (Phase 21) |

The four phases ship as four migration batches in tracker order; nothing in a later batch is referenced
by an earlier one except through a guarded FK migration.

### 1.4 Invariants

| # | Invariant | Enforced by |
|---|---|---|
| INV-I1 | **This phase never creates, updates or deletes a money row.** Fee charges, installment plans, discounts, receipts and reversals are created only by `StudentFeeService` / `PaymentService`. The admission record holds *agreed figures* and *caches*, never cash. | §6.8; FT-15, FT-16 |
| INV-I2 | Once the first fee charge exists for an admission, the agreed figures (`course_fee`, `admission_fee`, `registration_fee`, `discount_amount`, `scholarship_amount`, `total_amount`, `net_payable`) are **frozen** (`figures_locked_at`). A correction is a Phase 18 discount/correction row, never an UPDATE here - otherwise the commission denominator moves under a posted ledger row. | Model `updating` hook throws `AdmissionFiguresLocked`; FT-17 |
| INV-I3 | A collaborator link on `students` is a **display snapshot** whose only writer is Phase 9's `SyncReferralSnapshot` listener (its INV-R1). The pre-subject snapshots on `course_inquiries` and `student_applications` are written once at creation by this phase and are never read as authority. The commission engine reads neither; it resolves `collaborator_referrals` effective on the payment date. | §6.4; FT-18 |
| INV-I4 | Nothing the browser posts is trusted as a referral code. The public form posts the **visit token** rendered by `<x-site.referral-field>` (Phase 9 INV-R2) plus an optionally typed code; the server re-resolves both through `ReferralAttributionResolver` and `ReferralService::resolveCode()`. A `collaborator_id` in the request is discarded; an unknown or ineligible code is stored verbatim with `referral_code_valid = false` and attaches nobody. | §6.4; FT-19, FT-20 |
| INV-I5 | `student_code` and `registration_number` are globally unique for the life of the database and are issued exactly once, inside the caller's transaction, by `StudentNumberService` over a `SELECT ... FOR UPDATE` counter. A 1062 causes one retry, never a duplicate. | `uq_st_code`, `uq_st_regno`; FT-21 |
| INV-I6 | A batch can never exceed `student_capacity` except by an explicit, permissioned, reasoned overbooking recorded on the enrollment row. The check is a recount under a row lock on the batch - never a read of the `current_students` cache. | §6.6; FT-26, FT-27 |
| INV-I7 | `batches.current_students` is a **cache** equal to `COUNT(student_batch_enrollments WHERE status = 'active')` and is re-derivable by `batches:recount-students`. No screen, report or capacity check may treat it as truth. | FT-28 |
| INV-I8 | No teacher, classroom or batch is ever double-booked **by any phase**: a new or edited timetable entry, class session or demo class here, and every later-phase booking that occupies the same teacher, room or batch - a §81 exam and a §95 meeting that names a classroom (Phase 19-23) - is rejected when it overlaps an existing one. Overlap is half-open (`start < other_end AND other_start < end`), so 09:00-10:00 and 10:00-11:00 never clash. Every caller, in this phase and in later ones, reaches the test through the one published `ScheduleClashDetector::check(SlotCandidate): ClashReport` (§6.7, F-4.7); nothing re-implements an overlap predicate. | `ScheduleClashDetector` under parent row locks + three exact-duplicate unique indexes + `timetable:verify-clashes`; FT-31 to FT-35 |
| INV-I9 | Attendance exists only against a **dated** `class_sessions` row, at most one row per (session, student), and only for a student with an active enrollment in that session's batch on that date. | `uq_sa_session_student`; `AttendanceService`; FT-39, FT-40 |
| INV-I10 | Attendance is corrected, never deleted. After `institute.attendance_lock_hours` a change requires `student_attendance.edit` plus a mandatory reason and writes an activity row with old and new status. | Policy + `AttendanceService::amend()`; FT-41 |
| INV-I11 | Every percentage in this phase (`attendance_percentage`, `progress_percentage`, `completion_percentage`, `syllabus_completion_percentage`) is `decimal(8,4)` - the one system-wide rule of `CLAUDE.md` §3, with no "reported percentage" exception - computed through `App\Support\Money::percentageOf()`-grade bcmath division and rounded half-up, and is a cache re-derivable by its recount command. A percentage is never stored as a float and never written by a controller. | §6.9, §6.10; FT-43, FT-44 |
| INV-I12 | The outline is exactly three levels (module -> topic -> lecture). There is no `parent_id` and no recursion anywhere in the tree. | Schema (§2.3-2.5); FT-07 |
| INV-I13 | A curriculum row that any student progress, attendance or coverage row references is never hard-deleted; it is deactivated (`is_active = false`). Soft delete is refused by policy when referenced. | Policies; FT-08 |
| INV-I14 | Every status change in §2.30 that is marked *reason mandatory* fails validation without a reason and writes an `activity_log` row with old value, new value, actor, IP and reason. | Form Requests + `LogsActivityWithContext::withReason()`; FT-45 |
| INV-I15 | A teacher sees only batches they are assigned to - as batch teacher, as a timetable-entry teacher, or as the actual teacher of a session (substitution). A student sees only their own rows. Both are global scopes plus a policy, never a hidden form field. | §9; FT-46 to FT-52 |

---

## 2. Schema

InnoDB, utf8mb4, per Phase 1 §1. Every table below carries `created_at`, `updated_at`, `created_by`,
`updated_by` (nullable FK `users.id`, `nullOnDelete`, filled by `Blameable`) unless a row says otherwise.

**[D-IN-2] Soft deletes - an application of decision D19.** Every *entity* table soft-deletes per
`CLAUDE.md` §3. Five tables deliberately do **not**, and none of them is an entity: `course_teacher` (a
pivot, as Phase 1's pivots), `course_inquiry_follow_ups` (an append-only contact log - a deleted follow-up
is a falsified sales record), and the three derived upsert caches `batch_topic_coverage`,
`student_module_progress`, `student_topic_progress` (a soft-deleted row would block the unique-keyed
upsert that maintains them, and their content is recomputable from scratch). All five fall inside **D19**'s
append-only categories (history pivot, log, derived cache) as stated in `CLAUDE.md` §3; this contract
invents no local rule and needs no new global decision.

**[D-IN-3] Human codes are never called `<table>_id`.** §66's "Student ID" is the column
`students.student_code`, §72's "Teacher ID" is `teachers.teacher_code`. Naming them `student_id` /
`teacher_id` would collide with the FK convention of `CLAUDE.md` §3 and every join in the system would
become a coin toss. The UI label stays "Student ID".

**[D-IN-4] `active_guard` / `current_guard` generated columns.** Where "at most one live row per
parent" must be enforced by the database, the table carries a `STORED` generated tinyint that is `1`
while the row is live and `NULL` otherwise, and the unique index includes it. MariaDB unique indexes
ignore NULLs, so historical rows stack freely while exactly one live row can exist. Written as raw SQL in
the migration; if the server rejects it the migration **fails loudly** rather than silently dropping the
guard. (Same device as the spine's `collaborator_referrals.current_guard`.)

### 2.1 The 25 tables

| # | Table | Phase | Soft deletes | Why |
|---|---|---|---|---|
| 1 | `course_categories` | 14 | yes | §64 |
| 2 | `courses` | 14 | yes | §62 |
| 3 | `course_modules` | 14 | yes | §65 level 1 |
| 4 | `course_topics` | 14 | yes | §65 level 2 |
| 5 | `course_lectures` | 14 | yes | §65 level 3 |
| 6 | `course_topic_resources` | 14 | yes | §65 "resources" |
| 7 | `course_topic_assignments` | 14 | yes | §65 "assignments" - the syllabus blueprint, not the gradable artefact |
| 8 | `course_inquiries` | 15 | yes | §86 |
| 9 | `course_inquiry_follow_ups` | 15 | **no** | §68 follow-up stage; append-only |
| 10 | `student_applications` | 15 | yes | §67 public admission form |
| 11 | `students` | 15 | yes | §66 |
| 12 | `student_admissions` | 15 | yes | §69 |
| 13 | `demo_classes` | 15 | yes | §87 |
| 14 | `teachers` | 16 | yes | §72 |
| 15 | `course_teacher` | 16 | **no** | §72 "courses" - pivot |
| 16 | `classrooms` | 16 | yes | §70, §71 require a bookable room |
| 17 | `batches` | 16 | yes | §70 |
| 18 | `student_batch_enrollments` | 16 | yes | §68 batch assignment; the roster everything else joins through |
| 19 | `timetable_entries` | 16 | yes | §71 the recurring weekly rule |
| 20 | `class_sessions` | 16 | yes | the dated occurrence §75 attendance must attach to |
| 21 | `student_attendances` | 17 | yes | §75 |
| 22 | `batch_topic_coverage` | 17 | **no** | §83 class-level syllabus progress |
| 23 | `student_course_progress` | 17 | yes | §83 course level |
| 24 | `student_module_progress` | 17 | **no** | §83 module level, derived |
| 25 | `student_topic_progress` | 17 | **no** | §83 topic level, derived |

24 entity tables plus the `course_teacher` pivot. No table in this list is created by any other phase.
There is **no `course_faqs` table**: course FAQs are Phase 3's `faqs` rows (F-2.2, §2.10).

### 2.2 `branch_id` placement (decision D11)

D11 says institute tables carry a nullable `branch_id` "as they get created", without overcomplicating
the first release. Blanket denormalisation onto every child row would create seven more columns that can
drift from their parent. **[D-IN-5] `branch_id` goes on the root entities a branch manager filters and
reports on, and on the two high-volume dated tables whose reports must not need a three-table join.
Everywhere else the branch is reached through the parent.**

| Table | `branch_id` | Reason |
|---|---|---|
| `students`, `teachers`, `classrooms`, `batches` | **yes** nullable | the four things a branch owns; required by the spine (`students.branch_id`) |
| `course_inquiries`, `student_applications`, `student_admissions`, `demo_classes` | **yes** nullable | the funnel is reported per branch; a walk-in belongs to the branch that took it |
| `courses`, `course_categories` | **yes** nullable on `courses` only; `null` = offered at every branch | the catalogue and the public site are one; a branch-specific course is the exception. Categories stay global |
| `timetable_entries`, `class_sessions` | **yes** nullable, copied from the batch by the service and asserted equal | §71's classroom-wise and daily views are branch-scoped and run on every page load |
| `course_modules`, `course_topics`, `course_lectures`, `course_topic_resources`, `course_topic_assignments` | no | curriculum is not branch property (INV-I12) |
| `student_batch_enrollments`, `student_attendances`, `batch_topic_coverage`, the three progress tables, `course_inquiry_follow_ups`, `course_teacher` | no | always reached through a parent that has one |

Scoping rule for every query: `where branch_id IS NULL OR branch_id = :user_branch` when
`users.branch_id` is set (§9). There is no branch switcher UI in these phases.

### 2.3 `course_categories`

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `name` | string(150) | not null | §64 |
| `slug` | string(170) | not null | URL segment, generated from name, editable |
| `description` | string(500) | nullable | |
| `icon` | string(64) | nullable | icon token, not markup |
| `image_path` | string(255) | nullable | `public` disk under `courses/categories/` |
| `is_active` | boolean | true | §64 enable/disable |
| `sort_order` | int | 0 | §64 reorder; **not unique** - a unique `sort_order` makes a two-row swap impossible without a temp value |
| `courses_count` | int unsigned | 0 | CACHE of non-archived courses; recount command |
| `seo_title` | string(180) | nullable | §105 |
| `seo_description` | string(500) | nullable | |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_cc_slug(slug)`; `INDEX (is_active, sort_order)`; `INDEX (name)`.
**Relationships.** hasMany `Course`. No `parent_id`: §64 asks for a flat, reorderable list (§12 Q1).
**Rules.** `restrictOnDelete` from `courses.course_category_id`, so a category with courses cannot be
deleted; the UI offers "move courses, then delete". Disabling a category hides it from the public site
and from the course form but changes **no** course status unless `cascade_courses` is explicitly passed,
mirroring Phase 2's module discipline.

### 2.4 `courses`

Every field of §62.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete`; null = every branch (§2.2) |
| `course_category_id` | FK `course_categories.id` | not null | `restrictOnDelete` |
| `code` | string(32) | not null | human course code, e.g. `WEB-101` |
| `name` | string(180) | not null | |
| `slug` | string(200) | not null | public URL `/courses/{slug}`; immutable once `published_at` is set unless `courses.edit` + reason (a changed slug breaks every shared link) |
| `short_description` | string(500) | nullable | card + meta fallback |
| `full_description` | longText | nullable | rich text, sanitised on save |
| `image_path` | string(255) | nullable | hero image |
| `thumbnail_path` | string(255) | nullable | card image |
| `promo_video_url` | string(255) | nullable | §90 landing page media |
| `duration_value` | smallint unsigned | nullable | §62 duration |
| `duration_unit` | string(16) | `weeks` | cast `DurationUnit` |
| `total_classes` | smallint unsigned | nullable | §62 "number of classes" |
| `class_duration_minutes` | smallint unsigned | nullable | falls back to `institute.default_class_duration` |
| `course_fee` | decimal(15,2) | 0.00 | §62 component 1 |
| `admission_fee` | decimal(15,2) | 0.00 | §62 component 2; read by the spine's fee-type settings |
| `registration_fee` | decimal(15,2) | 0.00 | §62 component 3 |
| `monthly_fee` | decimal(15,2) | nullable | not in §62; added because Phase 18 §13.1 asks for it so `fees:generate-monthly` can prefill instead of asking every month. Null = no monthly head |
| `installment_available` | boolean | false | §62 |
| `max_installments` | tinyint unsigned | 0 | 0 when `installment_available` is false; the admission wizard offers at most this many |
| `installment_note` | string(255) | nullable | shown on the public page |
| `level` | string(16) | `beginner` | cast `CourseLevel` (§62) |
| `delivery_mode` | string(16) | `physical` | cast `DeliveryMode` (§62 "type") |
| `default_teacher_id` | FK `teachers.id` | nullable | `nullOnDelete`; §62 "trainer" - the default, overridable per batch. Guarded FK (teachers ship in Phase 16) |
| `requirements` | json | nullable | ordered array of strings (§62) |
| `outcomes` | json | nullable | ordered array of strings (§62 learning outcomes) |
| `certificate_available` | boolean | false | §62; read by Phase 21 |
| `is_featured` | boolean | false | §62 featured |
| `admission_open` | boolean | true | §89 "admission open"; effective = this AND `setting('institute.admission_open')` |
| `status` | string(16) | `draft` | cast `CourseStatus` |
| `published_at` | timestamp | nullable | stamped on first publish |
| `sort_order` | int | 0 | catalogue ordering |
| `modules_count` | smallint unsigned | 0 | CACHE, written only by `CourseOutlineService` |
| `topics_count` | smallint unsigned | 0 | CACHE |
| `lectures_count` | smallint unsigned | 0 | CACHE |
| `outline_minutes` | int unsigned | 0 | CACHE, sum of lecture `duration_minutes` |
| `seo_title` | string(180) | nullable | §105 |
| `seo_description` | string(500) | nullable | |
| `seo_keywords` | string(255) | nullable | |
| `og_image_path` | string(255) | nullable | |
| `canonical_url` | string(255) | nullable | |
| `is_indexable` | boolean | true | §105 index/noindex |
| `notes` | text | nullable | internal |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_co_code(code)`, `UNIQUE uq_co_slug(slug)`;
`INDEX (status, is_featured, sort_order)` public catalogue; `INDEX (course_category_id, status)`;
`INDEX (level)`, `INDEX (delivery_mode)`, `INDEX (branch_id, status)`,
`INDEX (default_teacher_id)`, `INDEX (admission_open, status)`.
**CHECK** `chk_co_money`: `course_fee >= 0 AND admission_fee >= 0 AND registration_fee >= 0`.
**CHECK** `chk_co_installments`: `(installment_available = 0 AND max_installments = 0) OR (installment_available = 1 AND max_installments BETWEEN 1 AND 36)`.
**CHECK** `chk_co_duration`: `duration_value IS NULL OR duration_value > 0`.
**Relationships.** belongsTo `CourseCategory`, `Branch`, `Teacher` (`defaultTeacher`); hasMany
`CourseModule`, `Batch`, `CourseInquiry`, `StudentApplication`, `StudentAdmission`,
`StudentBatchEnrollment`, `DemoClass`; morphMany `App\Models\Cms\Faq` as `faqs` (`faqable`, Phase 3's
table - §2.10); hasManyThrough `CourseTopic` (via modules) and `CourseLecture`
(via topics); belongsToMany `Teacher` through pivot **`course_teacher`**; hasMany `StudentFee` (Phase 10
table, read-only here).
**Rules.** `restrictOnDelete` from `batches`, `student_admissions`, `student_applications`,
`student_batch_enrollments` and `student_fees`, so a course that has ever been sold cannot be deleted -
it is archived (`status = archived`). Force-deleting a draft course cascades its outline tree only.

### 2.5 `course_modules`

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `course_id` | FK `courses.id` | not null | `cascadeOnDelete` (tree belongs to the course; a course with students cannot be force-deleted) |
| `title` | string(180) | not null | |
| `description` | text | nullable | |
| `sort_order` | smallint unsigned | 0 | reorder within the course |
| `duration_minutes` | int unsigned | nullable | planned |
| `is_active` | boolean | true | deactivate instead of delete (INV-I13) |
| `topics_count` | smallint unsigned | 0 | CACHE |
| `lectures_count` | smallint unsigned | 0 | CACHE |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `INDEX (course_id, sort_order)`, `INDEX (course_id, is_active)`.
**Relationships.** belongsTo `Course`; hasMany `CourseTopic`, `StudentModuleProgress`.

### 2.6 `course_topics`

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `course_module_id` | FK `course_modules.id` | not null | `cascadeOnDelete` |
| `course_id` | FK `courses.id` | not null | `cascadeOnDelete`; denormalised so course-wide progress queries never join two levels. The service asserts it equals the module's course |
| `title` | string(180) | not null | |
| `description` | text | nullable | |
| `sort_order` | smallint unsigned | 0 | |
| `weight` | smallint unsigned | 1 | progress weighting (§6.10); 1 = equal weight |
| `estimated_minutes` | int unsigned | nullable | |
| `is_active` | boolean | true | |
| `lectures_count` | smallint unsigned | 0 | CACHE |
| `resources_count` | smallint unsigned | 0 | CACHE |
| `assignments_count` | smallint unsigned | 0 | CACHE |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `INDEX (course_module_id, sort_order)`, `INDEX (course_id, is_active)`.
**CHECK** `chk_ct_weight`: `weight BETWEEN 1 AND 100`.
**Relationships.** belongsTo `CourseModule`, `Course`; hasMany `CourseLecture`,
`CourseTopicResource`, `CourseTopicAssignment`, `BatchTopicCoverage`, `StudentTopicProgress`,
`ClassSession`.

### 2.7 `course_lectures`

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `course_topic_id` | FK `course_topics.id` | not null | `cascadeOnDelete` |
| `course_id` | FK `courses.id` | not null | `cascadeOnDelete`, denormalised |
| `title` | string(180) | not null | |
| `description` | text | nullable | |
| `lecture_type` | string(16) | `lecture` | cast `LectureType` |
| `sort_order` | smallint unsigned | 0 | |
| `duration_minutes` | smallint unsigned | nullable | feeds `courses.outline_minutes` |
| `video_url` | string(255) | nullable | |
| `is_preview` | boolean | false | free public preview on the landing page (§90) |
| `is_active` | boolean | true | |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `INDEX (course_topic_id, sort_order)`, `INDEX (course_id, is_preview)`.
**Relationships.** belongsTo `CourseTopic`, `Course`; hasMany `ClassSession` (the session that taught it).

### 2.8 `course_topic_resources`

The **syllabus** resource list of §65 - the planned reading, link or file that is part of the published
outline. It is not the distributable material of §79: that is Phase 19's `course_materials`, gated by
enrollment and assignable to a batch or a single student. A public `is_public` resource is visible on the
landing page; everything else needs `course_outline.view`.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `course_topic_id` | FK `course_topics.id` | not null | `cascadeOnDelete` |
| `course_id` | FK `courses.id` | not null | `cascadeOnDelete`, denormalised |
| `title` | string(180) | not null | |
| `type` | string(24) | not null | cast `CourseResourceType` |
| `file_path` | string(255) | nullable | `public` disk under `courses/{course}/resources/` |
| `external_url` | string(500) | nullable | §79 external links |
| `file_size` | int unsigned | nullable | bytes |
| `mime_type` | string(120) | nullable | validated server-side, not from the client name |
| `is_public` | boolean | false | shown on the public landing page |
| `is_downloadable` | boolean | true | |
| `sort_order` | smallint unsigned | 0 | |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `INDEX (course_topic_id, sort_order)`, `INDEX (course_id, is_public)`.
**CHECK** `chk_ctr_target`: `file_path IS NOT NULL OR external_url IS NOT NULL`.
**Relationships.** belongsTo `CourseTopic`, `Course`.

### 2.9 `course_topic_assignments`

The **blueprint** of §65: "this topic has a practice assignment worth these marks". It is never graded
and has no submissions. Phase 19's `assignments` instantiates one for a batch with a deadline; that is
where submissions, marks and feedback live (§80). **[D-IN-6]** Two tables because one is curriculum
(authored once, public, versionless) and the other is an event with a deadline per batch.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `course_topic_id` | FK `course_topics.id` | not null | `cascadeOnDelete` |
| `course_id` | FK `courses.id` | not null | `cascadeOnDelete` |
| `title` | string(180) | not null | |
| `description` | text | nullable | |
| `instructions` | text | nullable | |
| `estimated_marks` | decimal(8,2) | nullable | the default `total_marks` Phase 19 pre-fills |
| `estimated_hours` | decimal(10,2) | nullable | |
| `attachment_path` | string(255) | nullable | starter file |
| `sort_order` | smallint unsigned | 0 | |
| `is_active` | boolean | true | |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `INDEX (course_topic_id, sort_order)`, `INDEX (course_id)`.
**Relationships.** belongsTo `CourseTopic`, `Course`; hasMany `Assignment` (Phase 19, by
`course_topic_assignment_id` - see §13).

### 2.10 Course FAQs - no table (owned by Phase 3)

**This phase creates no `course_faqs` table (F-2.2).** §90's course-page FAQs are `faqs` rows
(`faqable_type = App\Models\Institute\Course`, `faqable_id = courses.id`) written by `FaqService::save()`
under `courses.edit`. `faqs` / `faq_categories` and the `faqable_*` morph are Phase 3's (its §6.13 allows
the owning phase's service to write `faqable_*`); this phase writes rows and creates no table, no index,
no permission and no test of its own for them. The `Course` model exposes `morphMany(Faq::class,
'faqable')` as `faqs` and the course screen's FAQs tab (§8.4) edits that relation.

### 2.11 `course_inquiries`

§86. The institute's own funnel head. Software-house enquiries go to CRM leads (§17, §18); a course
enquiry arriving on the public contact form is a **Phase 4** `contact_inquiries` row (Phase 4 owns that
table, the `ContactInquirySubmitted` event and the `InquiryRouter` - F-2.1), routed here by an
`InquiryTarget` that calls `CourseInquiryService::createFromPublic()` and passes the originating
`contact_inquiry_id` (F-3.8).

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `inquiry_number` | string(32) | not null | `institute.inquiry_prefix` + counter, assigned in-transaction; a walk-in must be quotable on a slip |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete` |
| `name` | string(150) | not null | |
| `phone` | string(32) | not null | normalised to digits + country code by the service |
| `whatsapp` | string(32) | nullable | |
| `email` | string(180) | nullable | |
| `city` | string(100) | nullable | |
| `education` | string(150) | nullable | free text (§66/§67 use free-form qualifications) |
| `course_id` | FK `courses.id` | nullable | `nullOnDelete`; "interested in" |
| `batch_id` | FK `batches.id` | nullable | `nullOnDelete`; guarded FK (Phase 16) |
| `preferred_delivery_mode` | string(16) | nullable | cast `DeliveryMode` |
| `preferred_timing` | string(16) | nullable | cast `PreferredTiming` |
| `source` | string(24) | not null | cast **`App\Enums\InquirySource`** - Phase 4's single 11-case declaration (F-5.3); this phase declares no source enum |
| `source_url` | string(500) | nullable | landing page of a website enquiry |
| `contact_inquiry_id` | unsignedBigInteger | nullable | FK -> `contact_inquiries.id` (**Phase 4** owns that table), `nullOnDelete`, added in a `Schema::hasTable()`-guarded migration ([D-IN-1]). The provenance key for a CMS contact-form enquiry routed here by Phase 4's `InquiryRouter` - exactly the guarantee `leads.contact_inquiry_id` carries (F-3.8). A different guarantee from `idempotency_key`, which dedupes a replayed POST of *this* phase's own form; both are kept |
| `status` | string(24) | `new` | cast `CourseInquiryStatus` (§86) |
| `assigned_to` | FK `users.id` | nullable | `nullOnDelete`; the counsellor working it |
| `follow_up_date` | date | nullable | next action date (§68 follow-up) |
| `last_contacted_at` | timestamp | nullable | CACHE of the newest follow-up |
| `contact_attempts` | smallint unsigned | 0 | CACHE of follow-up rows |
| `message` | text | nullable | §67/§17 visitor message |
| `notes` | text | nullable | internal |
| `collaborator_id` | FK `collaborators.id` | nullable | `nullOnDelete`. **Display snapshot only** (INV-I3); nothing financial |
| `referral_code` | string(32) | nullable | snapshot of the code quoted |
| `referral_code_valid` | boolean | false | result of `ReferralService::resolveCode()` |
| `referral_visit_id` | unsignedBigInteger | nullable | FK -> `collaborator_referral_visits.id` (Phase 9), `nullOnDelete`, deferred guarded FK - identical to §2.13 and §2.14, and the third column of Phase 9 §13.1's snapshot set (F-3.14). **Display / evidence only** (INV-I3) |
| `lost_reason` | string(255) | nullable | mandatory when moving to `not_interested` |
| `converted_application_id` | FK `student_applications.id` | nullable | `nullOnDelete` |
| `converted_student_id` | FK `students.id` | nullable | `nullOnDelete` |
| `converted_at` | timestamp | nullable | |
| `ip_address` | string(45) | nullable | public submissions |
| `user_agent` | text | nullable | |
| `idempotency_key` | string(64) | nullable | one ULID per public form render; a replayed POST cannot create two enquiries |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_ci_number(inquiry_number)`; `UNIQUE uq_ci_idem(idempotency_key)` (NULLs stack, so
staff-created rows need no key); **`UNIQUE uq_ci_inquiry(contact_inquiry_id)`** (F-3.8; MariaDB ignores
NULLs, so staff-created and public-form enquiries stack freely and a replayed route cannot create a second
row); `INDEX (status, follow_up_date)` the work queue;
`INDEX (assigned_to, status)`; `INDEX (course_id, status)`; `INDEX (source, created_at)` conversion
report; `INDEX (phone)` duplicate lookup; `INDEX (branch_id, status)`; `INDEX (collaborator_id)`;
`INDEX (referral_visit_id)` (F-3.14); `INDEX (converted_application_id)`, `INDEX (converted_student_id)`
(F-9.2 - every FK column is indexed and has a row in `tests/Support/index-manifest.php`).
**Relationships.** belongsTo `Branch`, `Course`, `Batch`, `User` (`assignee`), `Collaborator`,
`ContactInquiry` (Phase 4), `CollaboratorReferralVisit` (Phase 9),
`StudentApplication` (`convertedApplication`), `Student` (`convertedStudent`); hasMany
`CourseInquiryFollowUp`, `DemoClass`.
**Note.** A course enquiry is **not** a `collaborator_referrals` subject: the spine's CHECK allows only
student / project / client / lead. The code lives here as a snapshot and the real attribution is attached
to the **student** at conversion (§6.4, §13).

### 2.12 `course_inquiry_follow_ups`

§68's follow-up stage, as an append-only contact log. No soft deletes ([D-IN-2]).

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `course_inquiry_id` | FK `course_inquiries.id` | not null | `cascadeOnDelete` |
| `channel` | string(16) | not null | cast `FollowUpChannel` |
| `outcome` | string(24) | not null | cast `FollowUpOutcome` |
| `contacted_at` | timestamp | not null | |
| `notes` | string(1000) | nullable | |
| `next_follow_up_at` | date | nullable | copied onto the inquiry's `follow_up_date` |
| `status_before` | string(24) | nullable | snapshot of `CourseInquiryStatus` |
| `status_after` | string(24) | nullable | snapshot |
| `user_id` | FK `users.id` | nullable | `nullOnDelete`; who made contact |
| `user_name` | string(150) | nullable | snapshot, immune to user deletion |
| timestamps / blameable | | | no `deleted_at` |

**Keys.** `INDEX (course_inquiry_id, contacted_at)`; `INDEX (user_id, contacted_at)`;
`INDEX (next_follow_up_at)`.
**Relationships.** belongsTo `CourseInquiry`, `User`.

### 2.13 `student_applications`

§67's public online admission form, landed as a reviewable record. **[D-IN-7] The public form never
creates a `students` row, never a `users` row (D15) and never a fee row.** It creates one application
that staff convert. The same table serves a walk-in applicant typed in by a receptionist, so §68's
"application" stage has exactly one shape.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `application_number` | string(32) | not null | `institute.application_prefix` + counter; quoted on the thank-you page so the applicant can ask about it |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete` |
| `course_inquiry_id` | FK `course_inquiries.id` | nullable | `nullOnDelete`; set when promoted from an enquiry |
| `name` | string(150) | not null | §67 |
| `father_name` | string(150) | nullable | §67 |
| `phone` | string(32) | not null | §67 |
| `whatsapp` | string(32) | nullable | §67 |
| `email` | string(180) | nullable | §67 |
| `city` | string(100) | nullable | §67 |
| `education` | string(150) | nullable | §67 |
| `course_id` | FK `courses.id` | not null | `restrictOnDelete`; §67 |
| `batch_id` | FK `batches.id` | nullable | `nullOnDelete`; §67. Required when `institute.admission_form_require_batch` |
| `preferred_timing` | string(16) | nullable | cast `PreferredTiming` (§67) |
| `preferred_delivery_mode` | string(16) | nullable | cast `DeliveryMode` (§67 online/physical) |
| `message` | text | nullable | §67 |
| `referral_code` | string(32) | nullable | §67; exactly as submitted or as captured from `?ref=` |
| `referral_code_valid` | boolean | false | `ReferralService::resolveCode()` verdict (INV-I4) |
| `collaborator_id` | FK `collaborators.id` | nullable | `nullOnDelete`; resolved server-side only |
| `referral_source` | string(24) | nullable | cast the spine's `ReferralSource` - `admission_form` or `referral_link` |
| `referral_visit_id` | FK `collaborator_referral_visits.id` | nullable | `nullOnDelete`; guarded FK (Phase 9) |
| `landing_url` | string(500) | nullable | §38 evidence |
| `ip_address` | string(45) | nullable | |
| `user_agent` | text | nullable | |
| `idempotency_key` | string(64) | not null | one ULID per rendered form; the duplicate guard |
| `duplicate_fingerprint` | string(64) | not null | sha1 of normalised phone + course id + lowercased trimmed name; **non-unique** - it flags, never blocks |
| `duplicate_of_application_id` | FK self | nullable | `nullOnDelete`; set by review or by the dedupe job |
| `status` | string(24) | `submitted` | cast `StudentApplicationStatus` |
| `reviewed_by` | FK `users.id` | nullable | `nullOnDelete` |
| `reviewed_at` | timestamp | nullable | |
| `review_notes` | string(500) | nullable | |
| `rejection_reason` | string(255) | nullable | mandatory on `rejected` |
| `converted_student_id` | FK `students.id` | nullable | `nullOnDelete` |
| `converted_admission_id` | FK `student_admissions.id` | nullable | `nullOnDelete` |
| `converted_at` | timestamp | nullable | |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_sap_number(application_number)`; `UNIQUE uq_sap_idem(idempotency_key)`;
`INDEX (status, created_at)` the inbox; `INDEX (duplicate_fingerprint)`; `INDEX (phone)`;
`INDEX (course_id, status)`; `INDEX (collaborator_id)`; `INDEX (branch_id, status)`.
**Relationships.** belongsTo `Course`, `Batch`, `Branch`, `CourseInquiry`, `Collaborator`,
`CollaboratorReferralVisit`, `User` (`reviewer`), self (`duplicateOf`), `Student`
(`convertedStudent`), `StudentAdmission` (`convertedAdmission`); hasMany `DemoClass`.

### 2.14 `students`

Every field of §66.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `student_code` | string(32) | not null | §66 "Student ID" ([D-IN-3]); issued at creation by `StudentNumberService` |
| `registration_number` | string(40) | nullable | §66; issued **only** at the registration stage of §68 (§6.5). Phase 18 §13.1 asks for this column as `registration_no`; the name here is spelled out to match §66, and a `registration_no` accessor is provided so either spelling compiles (§13) |
| `user_id` | FK `users.id` | nullable | `nullOnDelete`; D2 - a student record exists before a login |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete`; required by the spine |
| `name` | string(150) | not null | |
| `father_name` | string(150) | nullable | §66 |
| `gender` | string(16) | nullable | cast `Gender` |
| `date_of_birth` | date | nullable | |
| `cnic` | string(24) | nullable | §66 CNIC / B-Form; stored digits-only, displayed formatted |
| `phone` | string(32) | not null | |
| `whatsapp` | string(32) | nullable | |
| `email` | string(180) | nullable | |
| `address` | string(255) | nullable | |
| `city` | string(100) | nullable | |
| `photo_path` | string(255) | nullable | profile photo, image MIME validated, max 2 MB |
| `guardian_name` | string(150) | nullable | §66 |
| `guardian_phone` | string(32) | nullable | §66 |
| `guardian_relation` | string(40) | nullable | |
| `education` | string(150) | nullable | §66 |
| `institution_name` | string(180) | nullable | §66 school/college |
| `joining_date` | date | nullable | §66 |
| `status` | string(16) | `inquiry` | cast `StudentStatus` - the seven statuses of §66 |
| `status_changed_at` | timestamp | nullable | |
| `status_reason` | string(255) | nullable | mandatory for `suspended` / `dropped` |
| `collaborator_id` | FK `collaborators.id` | nullable | `nullOnDelete`. §37 link, **display snapshot** written only by Phase 9's `SyncReferralSnapshot` (INV-I3) |
| `referral_code` | string(32) | nullable | §37 snapshot |
| `referral_source` | string(24) | nullable | §37, cast the spine's `ReferralSource` |
| `referral_date` | date | nullable | §37 |
| `referral_visit_id` | FK `collaborator_referral_visits.id` | nullable | `nullOnDelete`; guarded FK - requested by Phase 9 §13.1 as part of the snapshot set |
| `notes` | text | nullable | |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_st_code(student_code)`; `UNIQUE uq_st_regno(registration_number)` (NULLs stack, so
unregistered students coexist); `UNIQUE uq_st_user(user_id)`; `INDEX (status, created_at)`;
`INDEX (branch_id, status)`; `INDEX (collaborator_id, status)` the §57 list; `INDEX (phone)`;
`INDEX (cnic)`; `INDEX (name)` search; `INDEX (joining_date)`.
**Relationships.** belongsTo `User`, `Branch`, `Collaborator`; hasMany `StudentAdmission`,
`StudentBatchEnrollment`, `StudentAttendance`, `StudentCourseProgress`, `DemoClass`, `StudentFee`
(Phase 10), `StudentFeePayment` (Phase 10), `CollaboratorReferral` (Phase 10); belongsToMany `Batch`
through pivot **`student_batch_enrollments`** (payload accessed through the enrollment model).
**Rules.** `restrictOnDelete` from `student_fees`, `student_fee_payments`, `student_batch_enrollments`
and `student_attendances`: a student with financial or attendance history is never hard-deletable (spine
§13.1). The policy refuses `forceDelete` and names the reason.
**[D-IN-8] No `current_batch_id` / `current_course_id` cache on `students`.** A student can be enrolled
in two courses at once (§63 expects many short courses), so a single "current" column would lie. Screens
load `activeEnrollments` (one indexed query) and render a chip per batch.

### 2.15 `student_admissions`

§69's admission record, and the carrier of the §68 pipeline stage. It is also the spine's **default
commission document** (`collaborator.student_commission_document = admission`), which is why its agreed
figures freeze the moment money is charged (INV-I2).

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `admission_number` | string(32) | not null | `institute.admission_prefix` + counter |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete` |
| `student_id` | FK `students.id` | not null | `restrictOnDelete` |
| `course_id` | FK `courses.id` | not null | `restrictOnDelete` |
| `batch_id` | FK `batches.id` | nullable | `nullOnDelete`; filled at the batch-assignment stage; a transfer updates it |
| `student_application_id` | FK `student_applications.id` | nullable | `nullOnDelete` |
| `course_inquiry_id` | FK `course_inquiries.id` | nullable | `nullOnDelete`; the funnel trail |
| `stage` | string(24) | `application` | cast `AdmissionStage` - §68 as one column (§2.31). **[D-IN-17]** Named `stage`, not `status`, because `students.status` already exists and the two advance in lockstep; Phase 18 §13.1 asks for `student_admissions.status`, so the model exposes a `status` accessor/mutator aliasing `stage` and its `scopeLive()`, and Phase 18's code compiles unchanged (§13) |
| `admission_date` | date | not null | §69 |
| `registration_date` | date | nullable | stamped with the registration number |
| `activated_on` | date | nullable | |
| `completed_on` | date | nullable | |
| `counselor_id` | FK `users.id` | nullable | `nullOnDelete`; §69 counselor |
| `delivery_mode` | string(16) | nullable | cast `DeliveryMode`; agreed mode |
| `preferred_timing` | string(16) | nullable | cast `PreferredTiming` |
| `course_fee` | decimal(15,2) | 0.00 | §69; snapshot of `courses.course_fee` at admission, negotiable until locked |
| `admission_fee` | decimal(15,2) | 0.00 | §69 |
| `registration_fee` | decimal(15,2) | 0.00 | §69 |
| `discount_amount` | decimal(15,2) | 0.00 | §69 discount |
| `scholarship_amount` | decimal(15,2) | 0.00 | §69 scholarship |
| `total_amount` | decimal(15,2) | 0.00 | §69 total = `course_fee + admission_fee + registration_fee`, written by the service through `Money` |
| `net_payable` | decimal(15,2) | 0.00 | `total_amount - discount - scholarship`. **The spine's collectible denominator** (§6.1.4) |
| `course_fee_net_payable` | decimal(15,2) | **generated STORED** | `GREATEST(course_fee - discount_amount - scholarship_amount, 0)` - offered to Phase 10 for the case where admission and registration fees are not commissionable (§13) |
| `discount_reason` | string(255) | nullable | §78 wants a reason; the authoritative discount history is Phase 18's `student_fee_discounts` |
| `payment_method` | string(32) | nullable | §69 agreed method, cast the spine's `PaymentMethod`. A snapshot of intent - the receipt carries the truth |
| `monthly_fee` | decimal(15,2) | nullable | not in §69; requested by Phase 18 §13.1, defaulted from `courses.monthly_fee` |
| `installment_plan_requested` | boolean | false | |
| `requested_installments` | tinyint unsigned | 0 | capped by `courses.max_installments` |
| `figures_locked_at` | timestamp | nullable | set by the Phase 18 listener on the first charge (INV-I2) |
| `charged_amount` | decimal(15,2) | 0.00 | CACHE of the sum of `student_fees.net_amount`, written **only** by Phase 18 |
| `paid_amount` | decimal(15,2) | 0.00 | §69 paid - CACHE, written only by Phase 18 |
| `refunded_amount` | decimal(15,2) | 0.00 | CACHE, Phase 18 |
| `balance_amount` | decimal(15,2) | 0.00 | §69 pending - CACHE `net_payable - (paid - refunded)`; may be negative (advance) |
| `cancelled_at` | timestamp | nullable | |
| `cancelled_by` | FK `users.id` | nullable | `nullOnDelete` |
| `cancellation_reason` | string(255) | nullable | mandatory |
| `withdrawn_at` | timestamp | nullable | student-initiated |
| `withdrawal_reason` | string(255) | nullable | mandatory |
| `active_guard` | tinyint | **generated STORED** | `CASE WHEN stage NOT IN ('cancelled','withdrawn','completed') THEN 1 ELSE NULL END` ([D-IN-4]) |
| `collaborator_id` | FK `collaborators.id` | nullable | `nullOnDelete`; §69 collaborator - **display snapshot** (INV-I3) |
| `referral_code` | string(32) | nullable | snapshot |
| `notes` | text | nullable | |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_sadm_number(admission_number)`;
`UNIQUE uq_sadm_live(student_id, course_id, active_guard)` - one live admission per student per course,
while a completed or cancelled one still allows re-admission;
`INDEX (stage, admission_date)`; `INDEX (student_id, stage)`; `INDEX (course_id, stage)`;
`INDEX (batch_id)`; `INDEX (counselor_id, admission_date)`; `INDEX (collaborator_id)`;
`INDEX (branch_id, stage)`; `INDEX (admission_date)` for the monthly-admissions chart;
`INDEX (student_application_id)`, `INDEX (course_inquiry_id)` (F-9.2 - every FK column is indexed and has
a row in `tests/Support/index-manifest.php`).
**CHECK** `chk_sadm_nonneg`: every money column `>= 0`.
**CHECK** `chk_sadm_discount_ceiling`: `discount_amount + scholarship_amount <= course_fee + admission_fee + registration_fee`.
**Relationships.** belongsTo `Student`, `Course`, `Batch`, `Branch`, `StudentApplication`,
`CourseInquiry`, `Collaborator`, `User` (`counselor`, `canceller`); hasMany `StudentBatchEnrollment`,
`StudentFee` (Phase 10), `CollaboratorCommissionEntitlement` (Phase 10, read-only).
**Rules.** `restrictOnDelete` from `student_fees` and `collaborator_commission_entitlements`. The four
money caches are writable only inside Phase 18's service: the model's `updating` hook rejects a write to
`charged_amount`, `paid_amount`, `refunded_amount` or `balance_amount` unless
`StudentFeeService::withinServiceContext()` is true - a method **published by Phase 18 §6.1** (F-4.6), so
this is a satisfied dependency, not an outstanding ask.

### 2.16 `demo_classes`

§87. A demo is a real booking, so it takes part in clash detection for the teacher and the classroom
(§6.7) - otherwise the demo quietly double-books a room a batch is already in.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete` |
| `subject_type` | string(16) | not null | cast `DemoSubjectType` - explicit, for indexes and reports |
| `course_inquiry_id` | FK `course_inquiries.id` | nullable | `nullOnDelete` |
| `student_application_id` | FK `student_applications.id` | nullable | `nullOnDelete` |
| `student_id` | FK `students.id` | nullable | `nullOnDelete` |
| `attendee_name` | string(150) | not null | snapshot; a demo slip must print even after the subject row moves on |
| `attendee_phone` | string(32) | nullable | snapshot |
| `course_id` | FK `courses.id` | not null | `restrictOnDelete`; §87 |
| `batch_id` | FK `batches.id` | nullable | `nullOnDelete`; "sit in on this batch" |
| `teacher_id` | FK `teachers.id` | nullable | `restrictOnDelete`; §87 |
| `classroom_id` | FK `classrooms.id` | nullable | `nullOnDelete`; §87 |
| `delivery_mode` | string(16) | `physical` | cast `DeliveryMode` |
| `meeting_url` | string(500) | nullable | §87 |
| `scheduled_on` | date | not null | §87 date |
| `start_time` | time | not null | §87 time |
| `end_time` | time | not null | defaults to `start_time` + `institute.demo_class_duration_minutes` |
| `status` | string(16) | `scheduled` | cast `DemoClassStatus` |
| `attended_at` | timestamp | nullable | |
| `attendance_remarks` | string(500) | nullable | what the teacher thought |
| `converted_admission_id` | FK `student_admissions.id` | nullable | `nullOnDelete`; §87 `converted` |
| `cancellation_reason` | string(255) | nullable | mandatory on `cancelled` |
| `reminder_sent_at` | timestamp | nullable | |
| `notes` | string(500) | nullable | |
| `active_guard` | tinyint | **generated STORED** | `CASE WHEN status = 'scheduled' THEN 1 ELSE NULL END` |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_dc_teacher_slot(teacher_id, scheduled_on, start_time, active_guard)` - the
exact-duplicate backstop; `UNIQUE uq_dc_room_slot(classroom_id, scheduled_on, start_time, active_guard)`;
`INDEX (scheduled_on, status)` the day view; `INDEX (teacher_id, scheduled_on)`;
`INDEX (classroom_id, scheduled_on)`; `INDEX (course_id, status)`; `INDEX (status)`;
`INDEX (branch_id, scheduled_on)`.
**CHECK** `chk_dc_times`: `end_time > start_time`.
**CHECK** `chk_dc_one_subject`: exactly one of `course_inquiry_id`, `student_application_id` and
`student_id` is non-null.
**Relationships.** belongsTo `CourseInquiry`, `StudentApplication`, `Student`, `Course`, `Batch`,
`Teacher`, `Classroom`, `Branch`, `StudentAdmission` (`convertedAdmission`).

### 2.17 `teachers`

§72. Optionally linked to an `employees` row. `teachers.employee_id` points at **`employees.id`** and is
correct under **D32**: teaching a course is an organisational **duty** held by a post, so it references the
employee, while `teachers.user_id` (who logs in, who marks a register) references `users.id` (F-11.1). The
bridge between the two is `employees.user_id`, never a second link here.

**[D-IN-9] The link is one-way and the teacher row stays the read surface.** When `employee_id` is set, the identity and HR fields (`name`, `phone`, `email`,
`photo_path`, `joining_date`, `salary`) are locked in the teacher form and kept in step by the
`SyncTeacherFromEmployee` listener on Phase 7's employee-updated event. Institute screens therefore never
need to know whether a teacher is staff or a visiting trainer, and an unlinked teacher is a first-class
record (many institutes pay trainers per batch, not a salary).

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `teacher_code` | string(32) | not null | §72 "Teacher ID" ([D-IN-3]); `institute.teacher_code_prefix` + counter |
| `user_id` | FK `users.id` | nullable | `nullOnDelete`; the teacher-panel login (D2) |
| `employee_id` | FK `employees.id` | nullable | `nullOnDelete`; §72 "may link to an employee". Added by a guarded migration ([D-IN-1]) |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete` |
| `slug` | string(170) | nullable | public trainer profile (§89); required when `is_public` |
| `name` | string(150) | not null | |
| `photo_path` | string(255) | nullable | §72 |
| `phone` | string(32) | nullable | |
| `whatsapp` | string(32) | nullable | |
| `email` | string(180) | nullable | |
| `gender` | string(16) | nullable | cast `Gender` |
| `qualification` | string(255) | nullable | §72 |
| `experience_years` | tinyint unsigned | nullable | §72 |
| `experience_note` | string(255) | nullable | "3 years at X, 2 freelance" |
| `skills` | json | nullable | §72, ordered array of strings |
| `specialization` | string(255) | nullable | §72 |
| `bio` | text | nullable | §72 internal bio |
| `public_bio` | text | nullable | what the website shows |
| `social_links` | json | nullable | §13-style map (linkedin, github, ...) |
| `joining_date` | date | nullable | §72 |
| `salary` | decimal(15,2) | nullable | §72, and **display only** - never a payroll input (Phase 7 §13 asks for exactly this). It is the agreed fee of an **unlinked** visiting trainer; when `employee_id` is set it is forced to NULL and the pay figure comes from the employee's salary structure and slip, so one person never has two salary numbers. Rendered only to holders of `teachers.view_financial` |
| `status` | string(16) | `active` | cast `TeacherStatus` |
| `status_reason` | string(255) | nullable | mandatory for `suspended` / `resigned` |
| `is_public` | boolean | false | show on the public site |
| `sort_order` | int | 0 | public ordering |
| `notes` | text | nullable | |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_te_code(teacher_code)`; `UNIQUE uq_te_user(user_id)`;
`UNIQUE uq_te_employee(employee_id)` - one employee is at most one teacher;
`UNIQUE uq_te_slug(slug)`; `INDEX (status, name)`; `INDEX (branch_id, status)`;
`INDEX (is_public, sort_order)`.
**CHECK** `chk_te_salary`: `salary IS NULL OR salary >= 0`.
**Relationships.** belongsTo `User`, `Employee`, `Branch`; belongsToMany `Course` through pivot
**`course_teacher`**; hasMany `Batch`, `TimetableEntry`, `ClassSession`, `DemoClass`,
`BatchTopicCoverage`; hasMany `Course` as `defaultForCourses` (via `courses.default_teacher_id`).
**Rules.** `restrictOnDelete` from `batches`, `timetable_entries`, `class_sessions` and `demo_classes`:
a teacher who has ever taught is deactivated, never deleted. Unlinking an employee clears `employee_id`
and unlocks the identity fields; it never deletes either row, and writes an audit entry with a reason.

### 2.18 `course_teacher` (pivot)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK (an id, not a composite PK, so the row is addressable) |
| `course_id` | FK `courses.id` | not null | `cascadeOnDelete` |
| `teacher_id` | FK `teachers.id` | not null | `cascadeOnDelete` |
| `is_primary` | boolean | false | at most one per course, enforced by the service |
| `assigned_on` | date | nullable | |
| timestamps | | | no soft deletes, no blameable ([D-IN-2]) |

**Keys.** `UNIQUE uq_cte(course_id, teacher_id)`; `INDEX (teacher_id)`.

### 2.19 `classrooms`

§70 and §71 both require a bookable room; without a row there is nothing to clash-detect.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete` |
| `code` | string(32) | not null | `LAB-1`, `R-204` |
| `name` | string(150) | not null | |
| `type` | string(16) | `classroom` | cast `ClassroomType` |
| `capacity` | smallint unsigned | not null | used as the **second** capacity ceiling when a batch is physical (§6.6) |
| `location` | string(150) | nullable | floor / building |
| `is_active` | boolean | true | |
| `notes` | string(255) | nullable | |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_cr_code(code)`; `INDEX (branch_id, is_active)`; `INDEX (type, is_active)`.
**CHECK** `chk_cr_capacity`: `capacity > 0`.
**Relationships.** belongsTo `Branch`; hasMany `Batch`, `TimetableEntry`, `ClassSession`, `DemoClass`.
**Rules.** `nullOnDelete` everywhere it is referenced: losing a room must never delete a schedule, it
must make the schedule roomless and visible in the "unassigned room" filter.

### 2.20 `batches`

Every field of §70.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete` |
| `code` | string(32) | not null | `WEB-101-B7` |
| `name` | string(150) | not null | §70 |
| `course_id` | FK `courses.id` | not null | `restrictOnDelete`; §70 |
| `teacher_id` | FK `teachers.id` | nullable | `restrictOnDelete`; §70. Null = unassigned, allowed only while `status = planned` |
| `start_date` | date | not null | §70 |
| `end_date` | date | nullable | §70 |
| `days` | json | nullable | §70 "days" - array of `Weekday` values; the default weekly pattern `TimetableService::seedFromBatch()` expands |
| `start_time` | time | nullable | §70 default slot start |
| `end_time` | time | nullable | §70 default slot end |
| `classroom_id` | FK `classrooms.id` | nullable | `nullOnDelete`; §70 |
| `delivery_mode` | string(16) | `physical` | cast `DeliveryMode`; §70 online/physical/hybrid |
| `meeting_url` | string(500) | nullable | default link for online sessions |
| `student_capacity` | smallint unsigned | not null | §70; defaults to `institute.batch_default_capacity` |
| `current_students` | smallint unsigned | 0 | §70 "current students" - **CACHE** of active enrollments (INV-I7) |
| `status` | string(16) | `planned` | cast `BatchStatus` |
| `syllabus_completion_percentage` | decimal(8,4) | 0.00 | CACHE from `batch_topic_coverage` (§6.10) |
| `sessions_planned_count` | smallint unsigned | 0 | CACHE of `class_sessions` |
| `sessions_held_count` | smallint unsigned | 0 | CACHE of sessions with `status = held` |
| `completed_on` | date | nullable | |
| `cancellation_reason` | string(255) | nullable | mandatory on `cancelled` |
| `notes` | text | nullable | |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_ba_code(code)`; `INDEX (course_id, status)`; `INDEX (teacher_id, status)`;
`INDEX (status, start_date)` the upcoming-batches strip (§89); `INDEX (branch_id, status)`;
`INDEX (classroom_id)`; `INDEX (start_date)`.
**CHECK** `chk_ba_capacity`: `student_capacity > 0`.
**CHECK** `chk_ba_dates`: `end_date IS NULL OR end_date >= start_date`.
**CHECK** `chk_ba_times`: `start_time IS NULL OR end_time IS NULL OR end_time > start_time`.
**CHECK** `chk_ba_current`: `current_students >= 0`.
**Relationships.** belongsTo `Course`, `Teacher`, `Classroom`, `Branch`; hasMany
`StudentBatchEnrollment`, `TimetableEntry`, `ClassSession`, `BatchTopicCoverage`, `StudentAdmission`,
`DemoClass`, `StudentCourseProgress`, `StudentFee` (Phase 10); belongsToMany `Student` through pivot
**`student_batch_enrollments`**.
**Rules.** `restrictOnDelete` from `student_batch_enrollments` and `class_sessions`; a batch that ever
had a student is cancelled or completed, never deleted. The public "upcoming batches" query is
`status = enrolling AND start_date >= today AND current_students < student_capacity`.

### 2.21 `student_batch_enrollments`

The roster. §68's batch-assignment stage creates it; attendance, progress and the teacher's student list
all join through it. Not a bare pivot - it carries lifecycle, transfer history and the attendance caches.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `student_id` | FK `students.id` | not null | `restrictOnDelete` |
| `batch_id` | FK `batches.id` | not null | `restrictOnDelete` |
| `course_id` | FK `courses.id` | not null | `restrictOnDelete`; denormalised from the batch, asserted equal by the service |
| `student_admission_id` | FK `student_admissions.id` | nullable | `restrictOnDelete`; null only for a historic import |
| `roll_number` | string(16) | nullable | per-batch roll number for printed registers |
| `status` | string(16) | `active` | cast `EnrollmentStatus` |
| `enrolled_on` | date | not null | |
| `completed_on` | date | nullable | |
| `left_on` | date | nullable | drop / transfer-out date |
| `leave_reason` | string(255) | nullable | mandatory on `dropped` / `suspended` |
| `transferred_from_id` | FK self | nullable | `nullOnDelete` |
| `transferred_to_id` | FK self | nullable | `nullOnDelete` |
| `transfer_reason` | string(255) | nullable | mandatory on transfer |
| `is_overbooked` | boolean | false | set when capacity was deliberately exceeded (INV-I6) |
| `overbook_reason` | string(255) | nullable | mandatory when `is_overbooked` |
| `sessions_expected_count` | smallint unsigned | 0 | CACHE - held sessions in this batch while the enrollment was active |
| `present_count` | smallint unsigned | 0 | CACHE |
| `absent_count` | smallint unsigned | 0 | CACHE |
| `leave_count` | smallint unsigned | 0 | CACHE |
| `late_count` | smallint unsigned | 0 | CACHE |
| `attendance_percentage` | decimal(8,4) | 0.00 | CACHE (§6.9) |
| `progress_percentage` | decimal(8,4) | 0.00 | CACHE mirrored from `student_course_progress` |
| `current_guard` | tinyint | **generated STORED** | `CASE WHEN status = 'active' THEN 1 ELSE NULL END` |
| `notes` | string(500) | nullable | |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_sbe_active(student_id, batch_id, current_guard)` - a student cannot hold two active
enrollments in one batch, while a re-enrollment after dropping is legal;
`UNIQUE uq_sbe_roll(batch_id, roll_number)`; `UNIQUE uq_sbe_transfer_to(transferred_to_id)` - an
enrollment has at most one successor; `INDEX (batch_id, status)` the roster;
`INDEX (student_id, status)` the student panel; `INDEX (course_id, status)`;
`INDEX (student_admission_id)`; `INDEX (enrolled_on)`.
**CHECK** `chk_sbe_counts`: every count column `>= 0`.
**CHECK** `chk_sbe_pct`: `attendance_percentage BETWEEN 0 AND 100 AND progress_percentage BETWEEN 0 AND 100`.
**CHECK** `chk_sbe_overbook`: `is_overbooked = 0 OR overbook_reason IS NOT NULL`.
**Relationships.** belongsTo `Student`, `Batch`, `Course`, `StudentAdmission`, self
(`transferredFrom`, `transferredTo`); hasMany `StudentAttendance`; hasOne `StudentCourseProgress`.

### 2.22 `timetable_entries`

§71's recurring weekly rule. One row = "this batch, this weekday, this time, from this date until that
one". It is not a dated event: that is `class_sessions`.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete`; copied from the batch, asserted equal |
| `batch_id` | FK `batches.id` | not null | `cascadeOnDelete`; §71 |
| `course_id` | FK `courses.id` | not null | `restrictOnDelete`; §71, denormalised for the course-wise filter |
| `teacher_id` | FK `teachers.id` | nullable | `restrictOnDelete`; §71. Null inherits the batch teacher at generation time |
| `day_of_week` | string(9) | not null | cast `Weekday`; §71 "day" |
| `start_time` | time | not null | §71 |
| `end_time` | time | not null | §71 |
| `classroom_id` | FK `classrooms.id` | nullable | `nullOnDelete`; §71 |
| `delivery_mode` | string(16) | `physical` | cast `DeliveryMode` |
| `meeting_url` | string(500) | nullable | §71 |
| `notes` | string(500) | nullable | §71 |
| `effective_from` | date | not null | defaults to the batch start date |
| `effective_to` | date | nullable | null = until the batch ends |
| `is_active` | boolean | true | |
| `active_guard` | tinyint | **generated STORED** | `CASE WHEN is_active = 1 THEN 1 ELSE NULL END` |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** three exact-duplicate backstops, each a cheap guard behind the real overlap check of §6.7:
`UNIQUE uq_tte_batch(batch_id, day_of_week, start_time, effective_from, active_guard)`,
`UNIQUE uq_tte_teacher(teacher_id, day_of_week, start_time, effective_from, active_guard)`,
`UNIQUE uq_tte_room(classroom_id, day_of_week, start_time, effective_from, active_guard)`.
`INDEX (teacher_id, day_of_week, is_active)` the teacher-wise view;
`INDEX (classroom_id, day_of_week, is_active)` the classroom-wise view;
`INDEX (batch_id, day_of_week)`; `INDEX (effective_from, effective_to)`;
`INDEX (branch_id, day_of_week)`.
**CHECK** `chk_tte_times`: `end_time > start_time`.
**CHECK** `chk_tte_dates`: `effective_to IS NULL OR effective_to >= effective_from`.
**Relationships.** belongsTo `Batch`, `Course`, `Teacher`, `Classroom`, `Branch`; hasMany `ClassSession`.

### 2.23 `class_sessions`

The dated occurrence. **[D-IN-10] Attendance, topic coverage and cancellations attach to a session, not
to a recurring rule.** §71 describes only the weekly pattern, but §75 needs daily attendance, §83 needs
"which class covered which topic", and real institutes cancel, reschedule and substitute. A pure
recurrence model cannot record any of those without lying about the past.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete` |
| `timetable_entry_id` | FK `timetable_entries.id` | nullable | `nullOnDelete`; null = a one-off extra class |
| `batch_id` | FK `batches.id` | not null | `restrictOnDelete` |
| `course_id` | FK `courses.id` | not null | `restrictOnDelete` |
| `teacher_id` | FK `teachers.id` | nullable | `restrictOnDelete`; the teacher who **actually** takes it |
| `original_teacher_id` | FK `teachers.id` | nullable | `nullOnDelete`; set when substituted, so §99 reports can tell |
| `classroom_id` | FK `classrooms.id` | nullable | `nullOnDelete` |
| `session_date` | date | not null | |
| `start_time` | time | not null | |
| `end_time` | time | not null | |
| `sequence_no` | smallint unsigned | nullable | nth class of the batch - "class 12 of 40" |
| `delivery_mode` | string(16) | `physical` | cast `DeliveryMode` |
| `meeting_url` | string(500) | nullable | |
| `title` | string(180) | nullable | what was taught, free text |
| `course_topic_id` | FK `course_topics.id` | nullable | `nullOnDelete`; the topic covered (§83) |
| `course_lecture_id` | FK `course_lectures.id` | nullable | `nullOnDelete` |
| `status` | string(16) | `scheduled` | cast `ClassSessionStatus` |
| `cancellation_reason` | string(24) | nullable | cast `ClassCancellationReason` |
| `cancellation_detail` | string(255) | nullable | mandatory on `cancelled` |
| `rescheduled_to_id` | FK self | nullable | `nullOnDelete` |
| `rescheduled_from_id` | FK self | nullable | `nullOnDelete` |
| `expected_count` | smallint unsigned | 0 | CACHE - active enrollments on `session_date` |
| `present_count` | smallint unsigned | 0 | CACHE |
| `absent_count` | smallint unsigned | 0 | CACHE |
| `leave_count` | smallint unsigned | 0 | CACHE |
| `late_count` | smallint unsigned | 0 | CACHE |
| `attendance_marked_at` | timestamp | nullable | null = not marked; drives the "unmarked" report |
| `attendance_marked_by` | FK `users.id` | nullable | `nullOnDelete` |
| `notes` | string(500) | nullable | |
| `active_guard` | tinyint | **generated STORED** | `CASE WHEN status IN ('scheduled','held') THEN 1 ELSE NULL END` |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_cs_generated(timetable_entry_id, session_date, active_guard)` - makes session
generation **idempotent**: the generator can run twice, or a manual run can overlap the scheduled one,
without producing two classes; `UNIQUE uq_cs_batch_slot(batch_id, session_date, start_time, active_guard)`;
`UNIQUE uq_cs_teacher_slot(teacher_id, session_date, start_time, active_guard)`;
`UNIQUE uq_cs_room_slot(classroom_id, session_date, start_time, active_guard)`;
`UNIQUE uq_cs_resched(rescheduled_to_id)`.
`INDEX (session_date, status)` the daily view; `INDEX (batch_id, session_date)`;
`INDEX (teacher_id, session_date)` the teacher panel; `INDEX (classroom_id, session_date)`;
`INDEX (status, attendance_marked_at)` the unmarked sweep; `INDEX (branch_id, session_date)`;
`INDEX (course_topic_id)`.
**CHECK** `chk_cs_times`: `end_time > start_time`.
**CHECK** `chk_cs_counts`: every count `>= 0`.
**Relationships.** belongsTo `TimetableEntry`, `Batch`, `Course`, `Teacher` (`teacher`,
`originalTeacher`), `Classroom`, `Branch`, `CourseTopic`, `CourseLecture`, `User` (`attendanceMarker`),
self (`rescheduledTo`, `rescheduledFrom`); hasMany `StudentAttendance`; hasOne `BatchTopicCoverage`.

### 2.24 `student_attendances`

§75, with exactly the four states the requirement names.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `class_session_id` | FK `class_sessions.id` | not null | `restrictOnDelete` |
| `student_id` | FK `students.id` | not null | `restrictOnDelete` |
| `student_batch_enrollment_id` | FK `student_batch_enrollments.id` | not null | `restrictOnDelete`; proves the student was on the roster |
| `batch_id` | FK `batches.id` | not null | `restrictOnDelete`; denormalised so the batch report is one index scan |
| `status` | string(16) | not null | cast `StudentAttendanceStatus` - present / absent / leave / late |
| `check_in_time` | time | nullable | |
| `minutes_late` | smallint unsigned | nullable | computed from `check_in_time` and `institute.attendance_grace_minutes` |
| `remarks` | string(255) | nullable | |
| `marked_via` | string(16) | `manual` | cast `AttendanceMarkSource` |
| `marked_by` | FK `users.id` | nullable | `nullOnDelete` |
| `marked_at` | timestamp | not null | |
| `amended_at` | timestamp | nullable | set by `AttendanceService::amend()` |
| `amended_by` | FK `users.id` | nullable | `nullOnDelete` |
| `amendment_reason` | string(255) | nullable | mandatory after the lock window (INV-I10) |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_sa_session_student(class_session_id, student_id)` - the hard guard against a
double-marked roster; `INDEX (student_id, status)`; `INDEX (batch_id, status)`;
`INDEX (student_batch_enrollment_id)`; `INDEX (marked_at)`; `INDEX (status)`.
**Relationships.** belongsTo `ClassSession`, `Student`, `StudentBatchEnrollment`, `Batch`, `User`
(`marker`, `amender`).
**Rules.** `AttendanceService` never deletes a row; the soft-delete column exists only to honour
`CLAUDE.md` §3 and the policy returns false for `delete` on every role.

### 2.25 `batch_topic_coverage`

§83 at class level: "this batch covered this topic on this date". An upsert cache keyed by its unique
index, so no soft deletes ([D-IN-2]).

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `batch_id` | FK `batches.id` | not null | `cascadeOnDelete` |
| `course_topic_id` | FK `course_topics.id` | not null | `cascadeOnDelete` |
| `course_module_id` | FK `course_modules.id` | not null | `cascadeOnDelete`; denormalised for the module roll-up |
| `class_session_id` | FK `class_sessions.id` | nullable | `nullOnDelete`; the session that covered it |
| `status` | string(16) | `pending` | cast `ProgressStatus` |
| `completion_percentage` | decimal(8,4) | 0.00 | a topic may be half covered |
| `covered_on` | date | nullable | |
| `teacher_id` | FK `teachers.id` | nullable | `nullOnDelete`; who marked it |
| `notes` | string(500) | nullable | |
| timestamps / blameable | | | no `deleted_at` |

**Keys.** `UNIQUE uq_btc(batch_id, course_topic_id)`; `INDEX (batch_id, status)`;
`INDEX (course_topic_id)`; `INDEX (class_session_id)`; `INDEX (covered_on)`.
**CHECK** `chk_btc_pct`: `completion_percentage BETWEEN 0 AND 100`.
**Relationships.** belongsTo `Batch`, `CourseTopic`, `CourseModule`, `ClassSession`, `Teacher`.

### 2.26 `student_course_progress`

§83 at course level, one row per enrollment.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `student_batch_enrollment_id` | FK `student_batch_enrollments.id` | not null | `cascadeOnDelete`; **the grain** |
| `student_id` | FK `students.id` | not null | `cascadeOnDelete`; denormalised |
| `course_id` | FK `courses.id` | not null | `cascadeOnDelete`; denormalised |
| `batch_id` | FK `batches.id` | not null | `cascadeOnDelete`; denormalised |
| `status` | string(16) | `pending` | cast `ProgressStatus` |
| `completion_percentage` | decimal(8,4) | 0.00 | §83 |
| `modules_total` | smallint unsigned | 0 | snapshot of the active outline |
| `modules_completed` | smallint unsigned | 0 | |
| `topics_total` | smallint unsigned | 0 | |
| `topics_completed` | smallint unsigned | 0 | |
| `weight_total` | int unsigned | 0 | sum of active topic `weight` |
| `weight_completed` | int unsigned | 0 | weighted sum of completed topics (§6.10) |
| `started_on` | date | nullable | |
| `completed_on` | date | nullable | |
| `last_activity_at` | timestamp | nullable | |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_scp_enrollment(student_batch_enrollment_id)`;
`INDEX (student_id, status)`; `INDEX (course_id, status)`; `INDEX (batch_id, completion_percentage)`.
**CHECK** `chk_scp_pct`: `completion_percentage BETWEEN 0 AND 100`.
**Relationships.** belongsTo `StudentBatchEnrollment`, `Student`, `Course`, `Batch`; hasMany
`StudentModuleProgress`, `StudentTopicProgress`.

### 2.27 `student_module_progress`

§83 at module level. **Derived** - written only by `CourseProgressService` from topic rows (or marked
directly when a module has no active topics). No soft deletes ([D-IN-2]).

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `student_course_progress_id` | FK `student_course_progress.id` | not null | `cascadeOnDelete` |
| `course_module_id` | FK `course_modules.id` | not null | `cascadeOnDelete` |
| `status` | string(16) | `pending` | cast `ProgressStatus` |
| `completion_percentage` | decimal(8,4) | 0.00 | |
| `topics_total` | smallint unsigned | 0 | |
| `topics_completed` | smallint unsigned | 0 | |
| `completed_on` | date | nullable | |
| timestamps | | | no `deleted_at`, no blameable (never hand-edited) |

**Keys.** `UNIQUE uq_smp(student_course_progress_id, course_module_id)`;
`INDEX (course_module_id, status)`.
**CHECK** `chk_smp_pct`: `completion_percentage BETWEEN 0 AND 100`.
**Relationships.** belongsTo `StudentCourseProgress`, `CourseModule`.

### 2.28 `student_topic_progress`

§83 at topic level - the only hand-marked grain. No soft deletes ([D-IN-2]).

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `student_course_progress_id` | FK `student_course_progress.id` | not null | `cascadeOnDelete` |
| `course_topic_id` | FK `course_topics.id` | not null | `cascadeOnDelete` |
| `course_module_id` | FK `course_modules.id` | not null | `cascadeOnDelete`; denormalised for the roll-up |
| `status` | string(16) | `pending` | cast `ProgressStatus` |
| `completion_percentage` | decimal(8,4) | 0.00 | |
| `source` | string(16) | `batch_coverage` | cast `ProgressSource` - how it got this value |
| `marked_by` | FK `users.id` | nullable | `nullOnDelete` |
| `marked_at` | timestamp | nullable | |
| `completed_on` | date | nullable | |
| `remarks` | string(255) | nullable | |
| timestamps | | | no `deleted_at` |

**Keys.** `UNIQUE uq_stp(student_course_progress_id, course_topic_id)`;
`INDEX (course_topic_id, status)`; `INDEX (status)`.
**CHECK** `chk_stp_pct`: `completion_percentage BETWEEN 0 AND 100`.
**Relationships.** belongsTo `StudentCourseProgress`, `CourseTopic`, `CourseModule`, `User` (`marker`).

### 2.29 Relationship map - one line per edge that matters

```
course_categories 1-n courses 1-n course_modules 1-n course_topics 1-n course_lectures
course_topics 1-n course_topic_resources , 1-n course_topic_assignments
courses 1-n faqs (Phase 3, faqable morph) , n-n teachers (course_teacher) , 1-n batches
course_inquiries 1-n course_inquiry_follow_ups , 1-0/1 student_applications , 1-n demo_classes
student_applications 1-0/1 students , 1-0/1 student_admissions
students 1-n student_admissions 1-n student_batch_enrollments n-1 batches
batches n-1 teachers , n-1 classrooms , 1-n timetable_entries 1-n class_sessions
class_sessions 1-n student_attendances n-1 student_batch_enrollments
batches 1-n batch_topic_coverage n-1 course_topics
student_batch_enrollments 1-1 student_course_progress 1-n student_module_progress , 1-n student_topic_progress
students 1-n student_fees (Phase 10) ; student_admissions 1-n student_fees (Phase 10)
students 1-n collaborator_referrals (Phase 10, the attribution authority)
teachers 0/1-1 employees (Phase 7) , 0/1-1 users ; students 0/1-1 users
```

### 2.30 Status lifecycles - explicit transition tables

Any transition not in these tables throws `InvalidStatusTransition`. Every transition writes an
`activity_log` row with old value, new value, actor, IP and - where marked - a **mandatory reason**
(INV-I14).

**2.30.1 `courses.status`** (`CourseStatus`)

| From | To | Trigger | Actor / permission |
|---|---|---|---|
| - | `draft` | created | `courses.create` |
| `draft` | `published` | publish; refused unless name, slug, category, `course_fee`, `level`, `delivery_mode` and at least one module are present | `courses.change_status` |
| `published` | `draft` | unpublish (withdraw from the site) | `courses.change_status` |
| `published` / `draft` | `archived` | retire; **reason mandatory**; refused while any batch is `enrolling` or `running` | `courses.change_status` |
| `archived` | `draft` | revive, **reason mandatory** | `courses.change_status` |

**2.30.2 `course_inquiries.status`** (`CourseInquiryStatus`, §86 verbatim)

| From | To | Trigger | Actor |
|---|---|---|---|
| - | `new` | created (public form, walk-in, phone, CMS contact form) | public or `course_inquiries.create` |
| `new` | `contacted` | first follow-up logged | `course_inquiries.edit` |
| `contacted` | `interested` / `not_interested` | follow-up outcome | `course_inquiries.change_status` |
| `new` / `contacted` / `interested` | `demo_scheduled` | a `demo_classes` row is created for this inquiry | `demo_classes.create` |
| `demo_scheduled` | `interested` / `not_interested` | demo attended / missed | `demo_classes.change_status` |
| `interested` / `demo_scheduled` / `contacted` | `admission_confirmed` | converted to an application or straight to a student | `students.create` |
| any | `not_interested` | lost; **`lost_reason` mandatory** | `course_inquiries.change_status` |
| `not_interested` | `contacted` | re-opened; **reason mandatory** | `course_inquiries.change_status` |
| `admission_confirmed` | - | terminal | - |

**2.30.3 `student_applications.status`** (`StudentApplicationStatus`)

| From | To | Trigger | Actor |
|---|---|---|---|
| - | `submitted` | public form POST or receptionist entry | public (throttled) or `student_applications.create` |
| `submitted` | `under_review` | a reviewer opens and claims it | `student_applications.edit` |
| `submitted` / `under_review` | `duplicate` | marked against an existing application; `duplicate_of_application_id` mandatory | `student_applications.change_status` |
| `submitted` / `under_review` | `converted` | a student + admission are created in one transaction | `students.create` + `admissions.create` |
| `submitted` / `under_review` | `rejected` | **`rejection_reason` mandatory** | `student_applications.reject` |
| `submitted` / `under_review` | `withdrawn` | the applicant withdrew | `student_applications.change_status` |
| `converted` / `rejected` / `duplicate` / `withdrawn` | - | terminal (a rejected applicant re-applies as a new row) | - |

**2.30.4 `students.status`** (`StudentStatus`, the seven statuses of §66)

| From | To | Trigger | Actor |
|---|---|---|---|
| - | `inquiry` | a student shell created from an inquiry before any application | `students.create` |
| - / `inquiry` | `applied` | an application is converted; the admission row is created at `stage = application` | `students.create` |
| `applied` | `registered` | registration stage: `registration_number` issued | `admissions.change_status` |
| `registered` | `active` | activation stage: an active enrollment exists **and** the fee precondition of `institute.require_fee_before_activation` is satisfied | `admissions.change_status` |
| `active` | `completed` | every live admission reached `completed` | `students.change_status` |
| `active` / `registered` | `dropped` | **reason mandatory**; active enrollments move to `dropped` in the same transaction | `students.change_status` |
| `active` / `registered` | `suspended` | **reason mandatory**; the login (if any) is set to `Inactive` | `students.change_status` |
| `suspended` | `active` | reinstated, **reason mandatory** | `students.change_status` |
| `dropped` | `active` | re-admitted through a new admission record | `admissions.create` |
| `completed` | `active` | a new admission to another course | `admissions.create` |

**2.30.5 `student_admissions.stage`** (`AdmissionStage` - §68 as one column)

| From | To | Trigger | Guard | Actor |
|---|---|---|---|---|
| - | `application` | admission record created from an application or a walk-in | a course is chosen and the agreed figures are set | `admissions.create` |
| `application` | `registration` | registration | `registration_number` issued; `registration_date` stamped | `admissions.change_status` |
| `registration` | `fee_collection` | the first fee charge is issued by Phase 18 | at least one `student_fees` row exists; `figures_locked_at` stamped | `student_fees.create` |
| `fee_collection` | `batch_assignment` | an enrollment is created | capacity check passed (§6.6) | `batches.assign` |
| `registration` | `batch_assignment` | batch assigned before fees (allowed - see note) | same | `batches.assign` |
| `batch_assignment` | `active` | activation | an active enrollment exists **and** the fee precondition holds | `admissions.change_status` |
| `active` | `completed` | course finished | the batch is `completed` or a certificate is issued (Phase 21) | `admissions.change_status` |
| any non-terminal | `cancelled` | institute cancels; **reason mandatory**; refused when any cleared receipt exists (Phase 18 policy) | - | `admissions.change_status` |
| any non-terminal | `withdrawn` | student withdraws; **reason mandatory** | - | `admissions.change_status` |
| `cancelled` / `withdrawn` / `completed` | - | terminal; `active_guard` becomes NULL so a re-admission is possible | - | - |

**Note on ordering.** Stages 5 (fee collection) and 6 (batch assignment) may happen in either order -
institutes seat a student before the first installment clears every day. The **transition to `active`** is
what enforces the business rule, through `institute.require_fee_before_activation`
(`none` / `any_payment` / `full_first_installment`). This is the only place the ordering is decided.

**2.30.6 `demo_classes.status`** (`DemoClassStatus`)

| From | To | Trigger | Actor |
|---|---|---|---|
| - | `scheduled` | booked; clash-checked for teacher and classroom | `demo_classes.create` |
| `scheduled` | `attended` | the attendee turned up | `demo_classes.change_status` |
| `scheduled` | `missed` | no-show, or the scheduled end time passed unmarked for 24h (job flags, never auto-marks) | `demo_classes.change_status` |
| `scheduled` | `cancelled` | **reason mandatory** ([D-IN-11]: §87 lists four statuses; a demo that is called off is neither `missed` nor deleted, so `cancelled` is added) | `demo_classes.change_status` |
| `attended` / `missed` | `converted` | the attendee's admission record is created; `converted_admission_id` set | `admissions.create` |
| `converted` | - | terminal | - |

**2.30.7 `batches.status`** (`BatchStatus`)

| From | To | Trigger | Guard | Actor |
|---|---|---|---|---|
| - | `planned` | created | - | `batches.create` |
| `planned` | `enrolling` | opened for admission | a teacher **and** a timetable entry exist | `batches.change_status` |
| `enrolling` / `planned` | `running` | first session held, or `start_date` reached (scheduled job) | at least one active enrollment | job / `batches.change_status` |
| `enrolling` | `planned` | re-closed | `current_students = 0` | `batches.change_status` |
| `running` / `enrolling` | `on_hold` | paused; **reason mandatory**; future sessions cancelled with the reason and the roster notified | - | `batches.change_status` |
| `on_hold` | `running` | resumed; sessions regenerated from the timetable | - | `batches.change_status` |
| `running` | `completed` | `end_date` passed and the syllabus is covered, or closed manually | every session is `held` or `cancelled` | job / `batches.change_status` |
| `planned` / `enrolling` / `on_hold` | `cancelled` | **reason mandatory**; refused when `current_students > 0` unless every enrollment is moved or dropped first | - | `batches.change_status` |
| `completed` / `cancelled` | - | terminal | - | - |

**2.30.8 `student_batch_enrollments.status`** (`EnrollmentStatus`)

| From | To | Trigger | Actor |
|---|---|---|---|
| - | `active` | enrolled; capacity checked under a batch row lock | `batches.assign` |
| `active` | `transferred_out` | transferred to another batch; **reason mandatory**; the successor row is created in the same transaction | `batches.assign` |
| `active` | `completed` | batch completed, or the student finished | `students.change_status` |
| `active` | `dropped` | **reason mandatory** | `students.change_status` |
| `active` | `suspended` | **reason mandatory**; keeps the seat, stops counting in attendance expectations | `students.change_status` |
| `suspended` | `active` | reinstated | `students.change_status` |
| `active` / `suspended` | `cancelled` | enrolled in error; refused once any attendance row exists | `batches.assign` |
| `transferred_out` / `completed` / `dropped` / `cancelled` | - | terminal | - |

Only `active` fills `current_guard`, so `batches.current_students` counts exactly the `active` rows and a
student may be re-enrolled in the same batch later.

**2.30.9 `class_sessions.status`** (`ClassSessionStatus`)

| From | To | Trigger | Actor |
|---|---|---|---|
| - | `scheduled` | generated from a timetable entry, or added as a one-off | job / `timetable.create` |
| `scheduled` | `held` | attendance is marked, or marked held manually | `student_attendance.create` / `timetable.change_status` |
| `scheduled` | `cancelled` | **`cancellation_detail` mandatory**; the roster is notified; attendance rows are refused afterwards | `timetable.change_status` |
| `scheduled` | `rescheduled` | moved; a new `scheduled` row is created and the two are linked both ways; clash-checked | `timetable.change_status` |
| `held` | `cancelled` | only while no attendance row exists; **reason mandatory** | `timetable.change_status` |
| `cancelled` / `rescheduled` | - | terminal | - |

**2.30.10 progress statuses** (`ProgressStatus` on `batch_topic_coverage`, `student_topic_progress`,
`student_module_progress`, `student_course_progress`)

| From | To | Trigger |
|---|---|---|
| - | `pending` | the row is created when progress is first computed for the enrollment |
| `pending` | `in_progress` | `completion_percentage` moves above 0 and below 100 |
| `pending` / `in_progress` | `completed` | `completion_percentage` reaches 100 |
| `pending` / `in_progress` | `skipped` | a coordinator drops the topic for this batch; **reason mandatory**; skipped weight leaves the denominator |
| `completed` / `skipped` | `in_progress` | re-opened; **reason mandatory** (module and course rows recompute) |

### 2.31 The §68 admission pipeline as explicit transitions

One table, because the pipeline spans three records and "where are we" must have exactly one answer at
every step. Read it as the contract for `AdmissionService`.

| # | §68 step | `course_inquiries.status` | `student_applications.status` | `students.status` | `student_admissions.stage` | Service call | Permission | Side effects |
|---|---|---|---|---|---|---|---|---|
| 1 | Inquiry | `new` | - | - | - | `CourseInquiryService::create()` | public / `course_inquiries.create` | inquiry number issued; `NewCourseInquiry` notification to assignees |
| 2 | Follow-up | `contacted` -> `interested` / `demo_scheduled` | - | - | - | `CourseInquiryService::logFollowUp()` | `course_inquiries.edit` | follow-up row; `follow_up_date`, `contact_attempts`, `last_contacted_at` updated; optional `DemoClassService::schedule()` |
| 3 | Application | `admission_confirmed` | `submitted` -> `converted` | `applied` | `application` | `StudentApplicationService::convert()` | `students.create` + `admissions.create` | **one transaction**: student created with `student_code`; admission created with the agreed figures; `ReferralService::attach()` called when a valid collaborator was resolved; `StudentApplicationConverted`, `StudentCreated` events |
| 4 | Registration | - | - | `registered` | `registration` | `AdmissionService::register()` | `admissions.change_status` | `registration_number` issued (§6.5); `registration_date`; `StudentRegistered` event |
| 5 | Fee collection | - | - | `registered` | `fee_collection` | `AdmissionService::requestFees()` -> `StudentFeeService::generateStructure()` | `student_fees.create` | **Phase 18 writes the charges**; `figures_locked_at` stamped (INV-I2); a receipt recorded later by `PaymentService` is what fires the commission engine - never this phase |
| 6 | Batch assignment | - | - | `registered` | `batch_assignment` | `BatchEnrollmentService::enroll()` | `batches.assign` | enrollment row under a batch row lock; `batches.current_students` incremented; `student_admissions.batch_id` set; progress row created; `BatchAssigned` notification to the student and the teacher |
| 7 | Active student | - | - | `active` | `active` | `AdmissionService::activate()` | `admissions.change_status` | login created when `institute.auto_create_student_login` and a contact exists; `activated_on`; `StudentActivated` event; welcome notification |

**Guards that are checked in every step.** The student's `branch_id` matches the admission's; the course
is `published` and `admission_open` (unless the actor holds `admissions.create` and confirms an override
with a reason); the batch belongs to the admission's course; and no step may be skipped - calling
`activate()` from `application` throws `InvalidStatusTransition` and names the missing step.

---

## 3. Enums to add

All in `app/Enums/`, string-backed, implementing `label(): string` and `color(): string` and exposing
`static options(): array`, exactly as Phase 1 §2 requires. The spine's `PaymentMethod`, `ReferralSource`,
`StudentFeeType`, `StudentFeeStatus` and `InstallmentStatus` are **reused as-is and never redefined**.

**`App\Enums\InquirySource` is Phase 4's** (11 cases: `website`, `facebook`, `instagram`, `tiktok`,
`google`, `whatsapp`, `referral`, `walk_in`, `call`, `email`, `other`). `course_inquiries.source` casts to
it; this phase declares **no** `CourseInquirySource` (F-5.3, which also deletes Phase 5's `LeadSource`).
§86's eight channels are a subset of those 11 values as identical strings, so there is no data migration
and [D-IN-12]'s "record an unmapped channel rather than lose it" is satisfied by the shared `other` case.

| Enum | Cases (values) | Extra members |
|---|---|---|
| `CourseStatus` | `draft`, `published`, `archived` | `isPublic(): bool` (true only for `published`) |
| `CourseLevel` | `beginner`, `intermediate`, `advanced` | §62 |
| `DeliveryMode` | `physical`, `online`, `hybrid` | `needsClassroom(): bool` (false for `online`), `needsMeetingUrl(): bool` (false for `physical`). §62 "type", §70, §71, §87 all use this one enum |
| `DurationUnit` | `hours`, `days`, `weeks`, `months` | `label()` pluralises with the value |
| `LectureType` | `lecture`, `lab`, `workshop`, `revision`, `assessment`, `project` | |
| `CourseResourceType` | `pdf`, `document`, `note`, `slide`, `image`, `video`, `audio`, `zip`, `source_code`, `link` | `isFile(): bool`, `allowedMimes(): array` - the server-side MIME whitelist (§111) |
| `CourseInquiryStatus` | `new`, `contacted`, `interested`, `demo_scheduled`, `admission_confirmed`, `not_interested` | §86 verbatim. `isOpen(): bool`, `isWon(): bool`, `isLost(): bool` |
| `FollowUpChannel` | `call`, `whatsapp`, `sms`, `email`, `in_person`, `other` | |
| `FollowUpOutcome` | `reached`, `no_answer`, `busy`, `wrong_number`, `interested`, `not_interested`, `demo_requested`, `admission_requested`, `call_later` | `suggestsStatus(): ?CourseInquiryStatus` - drives the inquiry status without the user having to set both |
| `PreferredTiming` | `morning`, `afternoon`, `evening`, `night`, `weekend`, `flexible` | §67, §70 timing; a select, so it is reportable |
| `StudentApplicationStatus` | `submitted`, `under_review`, `converted`, `rejected`, `duplicate`, `withdrawn` | `isOpen(): bool`, `isTerminal(): bool` |
| `StudentStatus` | `inquiry`, `applied`, `registered`, `active`, `completed`, `dropped`, `suspended` | §66's seven verbatim. `canLogin(): bool` (false for `dropped`/`suspended`), `isEnrollable(): bool` (`registered`/`active`), `countsAsActive(): bool` |
| `AdmissionStage` | `application`, `registration`, `fee_collection`, `batch_assignment`, `active`, `completed`, `cancelled`, `withdrawn` | §68's seven steps (the inquiry and follow-up steps live on the inquiry) plus two terminal states. `isLive(): bool`, `isTerminal(): bool`, `order(): int` for the stepper UI |
| `Gender` | `male`, `female`, `other` | §66. Declared here and nowhere else (F-5.8) |
| `DemoSubjectType` | `inquiry`, `application`, `student` | which FK on `demo_classes` is populated |
| `DemoClassStatus` | `scheduled`, `attended`, `missed`, `converted`, `cancelled` | §87's four plus `cancelled` ([D-IN-11]) |
| `TeacherStatus` | `active`, `inactive`, `on_leave`, `resigned`, `suspended` | §72 "status". `canTeach(): bool` (true only for `active`) - a non-teaching teacher cannot be put on a timetable |
| `ClassroomType` | `classroom`, `lab`, `hall`, `virtual` | a `virtual` room never takes part in classroom clash detection |
| `BatchStatus` | `planned`, `enrolling`, `running`, `on_hold`, `completed`, `cancelled` | `acceptsEnrollment(): bool` (`planned`/`enrolling`), `isLive(): bool`, `isTerminal(): bool` |
| `EnrollmentStatus` | `active`, `suspended`, `transferred_out`, `completed`, `dropped`, `cancelled` | `countsInCapacity(): bool` (true for `active` only), `countsInAttendance(): bool` (true for `active`) |
| `Weekday` | `monday` ... `sunday` | `isoNumber(): int`, `short(): string`, `static fromDate(CarbonInterface): self`, `static ordered(?string $weekStart): array` honouring `localization.week_start` |
| `ClassSessionStatus` | `scheduled`, `held`, `cancelled`, `rescheduled` | `countsInAttendance(): bool` (true only for `held`), `isTerminal(): bool` |
| `ClassCancellationReason` | `holiday`, `teacher_unavailable`, `classroom_unavailable`, `low_attendance`, `technical`, `batch_on_hold`, `other` | groupable in the §99 report: "nine classes lost to teacher unavailability" |
| `StudentAttendanceStatus` | `present`, `absent`, `leave`, `late` | §75's four, no more. `countsAsPresent(): bool` (`present` + `late`), `isAbsence(): bool` (`absent` only - `leave` is excused), `countsInDenominator(): bool` (all four; `leave` policy in §6.9) |
| `AttendanceMarkSource` | `manual`, `bulk`, `import`, `system` | `system` only for the absent-fill of §6.9 |
| `ProgressStatus` | `pending`, `in_progress`, `completed`, `skipped` | §83's three plus `skipped`. `isDone(): bool`, `countsInDenominator(): bool` (false for `skipped`) |
| `ProgressSource` | `batch_coverage`, `manual`, `assessment` | how a topic row got its value; `assessment` is reserved for Phase 20 |

`StudentAttendanceStatus` is deliberately **not** named `AttendanceStatus`: Phase 7 owns employee
attendance with a different case set (§26 adds half day and early leave), and two enums with one name in
one namespace is a merge conflict waiting to happen.

---

## 4. PermissionRegistry additions

Ability presets are Phase 1's: `READ`, `CRUD`, `CRUD_FULL`, `APPROVE`, `STATUS`, `ASSIGN`, `FILES`,
`MONEY`, `REPORTS`, `LOGS`. Permission name stays `{slug}.{ability}` (D4). **No new `Ability` case is
invented**: reordering a category is `edit`, marking a session held is `change_status`, and enrolling a
student is `assign`.

### 4.1 New module slugs (both `is_core = false`)

| slug | ModuleGroup | icon | Abilities | Why a module of its own |
|---|---|---|---|---|
| `classrooms` | `Institute` | `building-office-2` | `CRUD` + `STATUS` | a room is booked by the timetable and by demos; its own permission lets a branch admin manage rooms without touching batches |
| `student_applications` | `Institute` | `inbox-arrow-down` | `READ` + `create` + `edit` + `APPROVE` + `STATUS` + `export` + `LOGS` - **never `delete`** | the public §67 inbox is reviewed by a receptionist who must not hold `students.create` until the day they convert one; a public submission is never deleted, it is rejected or marked duplicate |

Nothing else needs a new module. `class_sessions` lives under `timetable`, `batch_topic_coverage` and the
three progress tables under `student_progress`, course FAQs (Phase 3's `faqs` rows, §2.10) / outline
resources under `courses` and
`course_outline`, and enrollment under `batches.assign` - a separate `student_enrollments` module would
add permission surface for no gain (the same reasoning the spine applied to entitlements).

### 4.2 Abilities for the Phase 1 Institute slugs this phase activates

Additive; `PermissionRegistry` remains the only place a permission name exists.

| slug | Abilities after this phase | Notes |
|---|---|---|
| `course_categories` | `CRUD` + `STATUS` | `edit` also authorises reorder |
| `courses` | `CRUD_FULL` + `STATUS` + `MONEY` + `REPORTS` + `LOGS` | `view_financial` gates the three fee columns everywhere a course is rendered |
| `course_outline` | `CRUD` + `FILES` + `STATUS` | modules, topics, lectures, resources and assignment blueprints |
| `course_inquiries` | `CRUD_FULL` + `ASSIGN` + `STATUS` + `REPORTS` + `LOGS` | `assign` = hand the inquiry to another counsellor |
| `demo_classes` | `CRUD` + `ASSIGN` + `STATUS` + `print` + `REPORTS` | `print` = the demo slip |
| `students` | `CRUD_FULL` + `STATUS` + `import` + `REPORTS` + `LOGS` + `FILES` | `view_financial` is **not** here: a student's money is `student_fees.view_financial` |
| `admissions` | `CRUD_FULL` + `STATUS` + `MONEY` + `REPORTS` + `LOGS` | `view_financial` gates the agreed figures and the paid/pending caches |
| `teachers` | `CRUD_FULL` + `ASSIGN` + `STATUS` + `MONEY` + `REPORTS` + `LOGS` | `view_financial` gates `salary`; `assign` = attach courses |
| `batches` | `CRUD_FULL` + `ASSIGN` + `STATUS` + `REPORTS` + `LOGS` | **`assign` is the enrollment / transfer ability** |
| `timetable` | `CRUD` + `STATUS` + `print` + `export` + `REPORTS` | `change_status` covers cancel / reschedule / substitute / mark held |
| `student_attendance` | `CRUD` + `STATUS` + `print` + `export` + `import` + `REPORTS` + `LOGS` | `edit` is what the post-lock amendment of INV-I10 requires |
| `student_progress` | `READ` + `create` + `edit` + `STATUS` + `export` + `REPORTS` | `create`/`edit` = mark a topic covered for a batch or for one student |

### 4.3 Portal permissions

Phase 1 reserved `student_portal.*` and `teacher_portal.*` with "dashboard, profile, plus the read
abilities each panel needs". The exact set used here:

| Panel | Permissions added |
|---|---|
| Student | `student_portal.course`, `.batch`, `.timetable`, `.attendance`, `.progress`, `.teachers` (name and public bio of their own teachers only) |
| Teacher | `teacher_portal.batches`, `.students`, `.timetable`, `.course_outline`, `.attendance`, `.attendance_mark`, `.progress`, `.progress_mark`, `.demo_classes`, `.reports` |
| Collaborator | none added. §57's referred-student list is served by `StudentDirectoryService::forCollaborator()` under the existing `collaborator_portal.students`, and the commission side stays the spine's |

`teacher_portal.attendance_mark` and `.progress_mark` are separate from their read counterparts on
purpose: a visiting trainer may be allowed to see a roster without being allowed to write the register.

### 4.4 Role grants (delta to Phase 1 §5, applied by the idempotent seeder)

| Role | Gets |
|---|---|
| Institute Manager | every slug in §4.1 and §4.2 at full ability, plus `view_financial` and `view_reports` |
| Course Coordinator | `courses` / `course_outline` / `course_categories` CRUD, `batches` CRUD + `assign`, `timetable` CRUD + `change_status`, `classrooms` read, `students` read + edit, `student_attendance` CRUD, `student_progress` CRUD, `teachers` read. **No** `view_financial` |
| Receptionist | `course_inquiries` CRUD, `student_applications` read + edit + approve/reject, `demo_classes` CRUD, `students` create + edit + read, `admissions` create + read + change_status, `batches` read + `assign`, `timetable` read |
| Sales Executive | `course_inquiries` full, `demo_classes` CRUD, `courses` read, `batches` read, `student_applications` read |
| Digital Marketer | `course_inquiries` read + edit + export, `courses` read, `student_applications` read |
| Accountant | `students` read, `admissions` read + `view_financial`, `courses` read + `view_financial`, `batches` read |
| Teacher (panel role) | `teacher_portal.*` from §4.3 |
| Student (panel role) | `student_portal.*` from §4.3 |

---

## 5. SettingsRegistry additions

All inside Phase 2's existing `institute` group; no new group, and Phase 2's keys (`admission_open`,
`default_branch_id`, `student_id_prefix`, `registration_number_format`, `fee_receipt_prefix`,
`certificate_prefix`, `attendance_grace_minutes`, `default_class_duration`, `installment_reminder_days`)
and the `maintenance` group's `admission_form_enabled` are **used exactly as defined, not redefined**.

| group.key | type | default | Meaning |
|---|---|---|---|
| `institute.student_id_next_number` | number | `1` | counter for `student_id_prefix`, locked in-transaction |
| `institute.student_id_format` | text | `{PREFIX}{YY}{SEQ:5}` | token expansion (§6.5); `{PREFIX}` reads the existing `student_id_prefix` |
| `institute.student_id_sequence_scope` | select `global`\|`yearly`\|`branch_yearly` | `yearly` | when the counter resets |
| `institute.student_id_period` | text *(readonly)* | current period key | written by the service, never by a human; the reset marker of §6.5 |
| `institute.registration_number_next_number` | number | `1` | counter for the existing `registration_number_format` |
| `institute.registration_number_sequence_scope` | select `global`\|`yearly`\|`branch_yearly` | `yearly` | |
| `institute.registration_number_period` | text *(readonly)* | current period key | |
| `institute.teacher_code_prefix` | text | `TCH-` | |
| `institute.teacher_code_next_number` | number | `1` | |
| `institute.inquiry_prefix` | text | `INQ-` | |
| `institute.inquiry_next_number` | number | `1` | |
| `institute.application_prefix` | text | `APP-` | |
| `institute.application_next_number` | number | `1` | |
| `institute.admission_prefix` | text | `ADM-` | |
| `institute.admission_next_number` | number | `1` | |
| `institute.inquiry_followup_days` | number | `2` | default offset for `next_follow_up_at` |
| `institute.inquiry_stale_days` | number | `14` | an open inquiry untouched this long is **flagged**, never auto-closed |
| `institute.admission_form_require_batch` | boolean | `false` | §67's batch field becomes mandatory |
| `institute.application_duplicate_window_days` | number | `7` | window for the `duplicate_fingerprint` warning |
| `institute.require_fee_before_activation` | select `none`\|`any_payment`\|`full_first_installment` | `any_payment` | the only place §68's stage 5 / stage 6 ordering is decided (§2.31) |
| `institute.auto_create_student_login` | boolean | `true` | activation creates a `users` row with the Student role, `must_change_password = true` |
| `institute.auto_create_teacher_login` | boolean | `true` | same for a new teacher |
| `institute.batch_default_capacity` | number | `20` | prefills `batches.student_capacity` |
| `institute.batch_allow_overbooking` | boolean | `false` | when false, even `batches.assign` cannot exceed capacity (§6.6) |
| `institute.batch_near_capacity_threshold` | number | `90` | percentage that fires `BatchNearCapacity` |
| `institute.timetable_working_days` | multiselect(`Weekday`) | `monday..saturday` | validation + the calendar's visible columns |
| `institute.timetable_day_start` | time | `08:00` | calendar bounds and slot validation |
| `institute.timetable_day_end` | time | `22:00` | |
| `institute.timetable_slot_gap_minutes` | number | `0` | minimum gap enforced between two slots of the same teacher or room; `0` = back-to-back allowed |
| `institute.session_generation_weeks_ahead` | number | `8` | how far ahead `class_sessions` are materialised |
| `institute.attendance_lock_hours` | number | `48` | after this, a change needs `student_attendance.edit` + a reason (INV-I10) |
| `institute.attendance_minimum_percentage` | number | `75` | the pass line shown on reports and read by Phase 21 for certificate eligibility |
| `institute.attendance_leave_counts_in_denominator` | boolean | `false` | §6.9's one policy choice, stated rather than hidden in a formula |
| `institute.attendance_auto_absent_on_close` | boolean | `false` | when true, marking a session held fills unmarked students as `absent` with `marked_via = system` |
| `institute.demo_class_duration_minutes` | number | `60` | |
| `institute.progress_weighting` | select `topic_count`\|`topic_weight` | `topic_weight` | §6.10's denominator |
| `institute.public_course_catalogue_per_page` | number | `12` | |

Every key is typed, validated and defaulted by `SettingsRegistry`, so the seeder, the form and the
server-side rules stay one definition (Phase 2 §2).

---

## 6. Services

Namespace `App\Services\Institute\`. Controllers only orchestrate (`CLAUDE.md` §1.9). Every method that
writes more than one row runs in one `DB::transaction()`; every event is dispatched
`DB::afterCommit()`.

### 6.1 `CourseCategoryService`

| Method | Guarantees |
|---|---|
| `create(array $data): CourseCategory` | unique slug (suffix `-2`, `-3` on collision), `sort_order` = current max + 1 |
| `update(CourseCategory $c, array $data): CourseCategory` | a slug change on a category with published courses requires a reason and is logged |
| `reorder(array $orderedIds): void` | one transaction, one UPDATE per row; ids not belonging to the set are rejected; no unique constraint on `sort_order` so any permutation is writable in any order |
| `setActive(CourseCategory $c, bool $active, bool $cascadeCourses = false): void` | never touches a course unless `cascadeCourses`; with cascade, published courses move to `draft` and each move is logged individually |
| `delete(CourseCategory $c): void` | refused while any course references it (FK `restrictOnDelete`), with a message naming the count |
| `recount(?CourseCategory $c = null): void` | rewrites `courses_count` from the table |

### 6.2 `CourseService`

| Method | Guarantees |
|---|---|
| `create(array $data): Course` | `code` and `slug` unique; `status = draft`; fee columns written through `Money`; `requirements` / `outcomes` normalised to a flat array of trimmed non-empty strings |
| `update(Course $c, array $data): Course` | `slug` is immutable once `published_at` is set unless a `slug_change_reason` is supplied (audited); fee changes are logged with old and new values and **never** touch an existing admission or charge |
| `publish(Course $c): Course` / `unpublish` / `archive(Course $c, string $reason)` | the §2.30.1 transition table, with its completeness guard |
| `duplicate(Course $c, array $overrides): Course` | deep-copies modules, topics, lectures, resources, assignment blueprints and the course's `faqs` rows (through `FaqService::save()` with the new `faqable_id` - §2.10) inside one transaction; the copy is `draft` with a new code and slug; no batch, student or fee data is copied |
| `setFeatured(Course $c, bool $featured)` / `reorder(array $orderedIds)` | as §6.1 |
| `recountOutline(Course $c): void` | rewrites `modules_count`, `topics_count`, `lectures_count`, `outline_minutes` from the tree; the only writer of those four columns besides `CourseOutlineService` |
| `effectiveAdmissionOpen(Course $c): bool` | `setting('institute.admission_open') && $c->admission_open && $c->status === published` - the single definition used by the public page, the admission form and the API |

### 6.3 `CourseOutlineService`

| Method | Guarantees |
|---|---|
| `addModule(Course $c, array $data): CourseModule` | appends at the end; recounts the course |
| `addTopic(CourseModule $m, array $data): CourseTopic` | sets `course_id` from the module (never from the request); recounts module and course |
| `addLecture(CourseTopic $t, array $data): CourseLecture` | sets `course_id` and `course_topic_id` server-side; recounts topic, module, course, `outline_minutes` |
| `addResource(CourseTopic $t, array $data, ?UploadedFile $file): CourseTopicResource` | MIME validated against `CourseResourceType::allowedMimes()` by content, not by name; stored with a hashed filename; `file_size` and `mime_type` recorded; either a file or a URL must be present |
| `addAssignmentBlueprint(CourseTopic $t, array $data): CourseTopicAssignment` | marks are `decimal(8,2)` |
| `reorder(string $level, int $parentId, array $orderedIds): void` | `level` in `module\|topic\|lecture\|resource\|assignment`; every id must belong to `parentId`, which must belong to the course being edited - the guard that stops a crafted request from reordering another course's tree |
| `moveTopic(CourseTopic $t, CourseModule $target, int $position): void` | same course only; rewrites `course_module_id`, re-sorts both modules, repoints `student_topic_progress.course_module_id` and `batch_topic_coverage.course_module_id`, then recomputes every affected progress row |
| `duplicateModule(CourseModule $m): CourseModule` | deep copy within the same course |
| `setActive(mixed $node, bool $active): void` | deactivating a topic removes its weight from every progress denominator and triggers `CourseProgressService::recomputeForCourse()` |
| `delete(mixed $node): void` | refused (policy) when the node is referenced by `batch_topic_coverage`, `student_topic_progress` or `class_sessions`; the UI offers "deactivate instead" (INV-I13) |

### 6.4 `StudentApplicationService` and referral capture

| Method | Guarantees |
|---|---|
| `submitFromPublic(PublicAdmissionData $data): StudentApplication` | one row per `idempotency_key` (unique index, a 1062 returns the existing row so a double-submit is silent); honeypot field must be empty and the form must be older than 2 seconds; rate limited `throttle:5,1` per IP **and** 20 per day per phone; `application_number` issued; `duplicate_fingerprint` computed; course must be published with `effectiveAdmissionOpen`; batch (when given) must belong to the course, be `enrolling` and have a free seat; **no student, user or fee row is created** |
| `resolveReferral(ReferralFormInput $in): ReferralIntent` | **delegates to Phase 9's `ReferralAttributionResolver`** and adds nothing of its own. `ReferralFormInput` carries the posted `referral_visit_token` (rendered by `<x-site.referral-field>`, never a code - Phase 9 INV-R2), an optionally typed code, the staff pick and the override reason; the resolver applies its own precedence ladder and `ReferralService::resolveCode()` does the code-to-collaborator step. The returned intent is stored on the application as `referral_code` + `referral_code_valid` + `collaborator_id` + `referral_source` + `referral_visit_id` + `landing_url`. An unknown code, a non-`Active` or trashed collaborator, a self-referral or a disabled referral system yields `valid = false`, `collaborator_id = null`, and the code is still stored verbatim for staff to see. A `collaborator_id` in the request is **discarded** (INV-I4) |
| `markDuplicate(StudentApplication $a, StudentApplication $of, string $reason)` / `reject(StudentApplication $a, string $reason)` / `withdraw(...)` | §2.30.3 transitions; reason mandatory; audited |
| `convert(StudentApplication $a, array $overrides): StudentAdmission` | **one transaction**: `StudentService::create()` (issuing `student_code`), `AdmissionService::createFromApplication()`, then - only when the resolved referral is valid - `ReferralService::attach($student, $collaborator, ReferralSource::AdmissionForm, $a->referral_code, $a->created_at->toDateString(), $context)` where `$context` is `App\DataObjects\Collaborator\ReferralContext` - the readonly DTO now published in **spine §13.2** and accepted as `attach()`'s sixth parameter (F-4.3) - carrying `referralVisitId`, `landingUrl`, `ipAddress`, `userAgent`, `referralDate` and `notes` from the application row. The application is stamped `converted`, `converted_student_id`, `converted_admission_id`, `converted_at`; a linked inquiry moves to `admission_confirmed`; Phase 9's `MarkReferralVisitConverted` fires off the resulting event. Re-running on an already converted application returns the existing admission and writes nothing |
| `linkExistingStudent(StudentApplication $a, Student $s): StudentAdmission` | the duplicate case: no new student, a new admission, and the referral is attached **only if the student has no current referral** - an existing attribution is never overwritten here; changing it is `admin.referrals.change-student` (spine §7.3) with a reason |

**[D-IN-13] Referral attachment happens at conversion, not at submission.** `collaborator_referrals`
requires a subject row, and the public form deliberately creates no student. The application therefore
carries the evidence (`referral_code`, `referral_code_valid`, `collaborator_id`, `referral_visit_id`,
`landing_url`, IP, UA) and `convert()` turns it into the one authoritative attribution through
`ReferralService::attach()`. The §37 snapshot columns on `students` are then written by **Phase 9's
`SyncReferralSnapshot`** listener, not by anything here (Phase 9 INV-R1, INV-I3); this phase owns no
snapshot listener, and `collaborators:sync-referral-snapshots` is Phase 9's repair job. The snapshots this
phase does write - on `course_inquiries` and `student_applications` - exist **before** any subject row, so
no listener can maintain them; they are write-once capture evidence, are never read as authority, and are
superseded by the referral row the moment one exists.

### 6.5 `StudentNumberService`

| Method | Guarantees |
|---|---|
| `nextStudentCode(?Branch $b = null): string` | expands `institute.student_id_format` and reserves the counter |
| `nextRegistrationNumber(Student $s, StudentAdmission $a): string` | expands `institute.registration_number_format` with the same token set |
| `nextTeacherCode(): string`, `nextInquiryNumber(): string`, `nextApplicationNumber(): string`, `nextAdmissionNumber(): string` | plain prefix + counter |

**Token set** (unknown tokens are a validation error on the setting, not a silent blank): `{PREFIX}`,
`{YYYY}`, `{YY}`, `{MM}`, `{BRANCH}` (branch code or empty), `{COURSE}` (course code or empty),
`{SEQ:n}` (zero-padded to n).

**Counter algorithm**, inside the caller's transaction:

```
1. period = scope === 'global' ? '-' : (scope === 'yearly' ? 'YYYY' : 'YYYY-BRANCHCODE')
2. SELECT ... FOR UPDATE on the two settings rows (counter, period)
3. if stored period <> period  ->  counter = 1, stored period = period   (the yearly reset)
4. value = counter; counter = counter + 1; write both rows
5. return the expanded string
6. on a 1062 from the unique index, retry exactly once from step 2; a second failure throws
```

The settings row lock is held for microseconds, so two receptionists registering simultaneously serialise
without deadlocking (INV-I5).

**Steps 2-4 are not implemented here.** `App\Services\Finance\DocumentNumberService` is the **single**
numbering implementation in the system and is shipped by **Phase 5** - the earliest consumer - not by
Phase 10 (**D27**, F-4.1). It exists long before this phase migrates, so `StudentNumberService` is a thin
token-expanding wrapper that calls
`DocumentNumberService::reserve(string $counterKey, ?string $periodKey = null, ?string $periodValue = null): int`
for the locked counter and its period reset (F-4.12), and `::next(string $prefixKey, string $counterKey, string $pad = '%06d')`
where no period scope applies - **passing its own pad explicitly** (`'%05d'` for `{SEQ:5}`, and whatever
`{SEQ:n}` resolves to otherwise). There is **no local `FOR UPDATE` fallback and no tech-debt note**: a
second counter implementation is a review failure, because two `FOR UPDATE` paths over one settings row are
how a duplicate `student_code` is born.

### 6.6 `StudentService`, `AdmissionService`, `BatchEnrollmentService`

**`StudentService`**

| Method | Guarantees |
|---|---|
| `create(array $data): Student` | `student_code` from §6.5; `status = applied` (or `inquiry` when created from an inquiry with no application); `branch_id` defaults to the actor's branch or `institute.default_branch_id`; phone normalised; photo MIME-validated (max 2 MB) |
| `update(Student $s, array $data): Student` | `student_code` and `registration_number` are **never** writable through this method |
| `changeStatus(Student $s, StudentStatus $to, ?string $reason): Student` | §2.30.4 table; reason enforced where marked; `suspended` also sets the linked user to `UserStatus::Inactive`; `active` restores it; cascades enrollment status where the table says so |
| `createLogin(Student $s): User` | only when `auto_create_student_login`, an email or phone exists, and no `user_id` is set; Student role, `must_change_password = true`, random password delivered by notification; never exposes the password in a response or a log |
| `merge(Student $keep, Student $duplicate, string $reason): Student` | moves admissions, enrollments, attendance and progress to `keep`, refuses when both carry financial rows (that is a Phase 18 decision), soft-deletes the duplicate, writes one audit entry listing every moved row count |

**`AdmissionService`** - one public method per §68 step, each asserting the previous stage:

| Method | Guarantees |
|---|---|
| `createFromApplication(StudentApplication $a, Student $s, array $overrides): StudentAdmission` | snapshots `course_fee`, `admission_fee`, `registration_fee` from the course; applies the agreed discount and scholarship; computes `total_amount` and `net_payable` through `Money`; `stage = application`; `admission_number` issued; refuses a second live admission for the same (student, course) via `uq_sadm_live` |
| `updateFigures(StudentAdmission $a, array $money, string $reason): StudentAdmission` | refused once `figures_locked_at` is set (INV-I2); writes an audit row with every old and new figure |
| `register(StudentAdmission $a): StudentAdmission` | issues `registration_number` (§6.5), stamps `registration_date`, stage -> `registration`, student -> `registered` |
| `requestFees(StudentAdmission $a, FeeStructureData $d): FeeStructureResult` | **delegates** to `StudentFeeService::generateStructure()` (Phase 18 §6.1, which asserts `SUM(charges.net_amount) === $a->net_payable` and aborts otherwise); asserts `$d->installments <= course.max_installments` and that `courses.installment_available` is true; stage -> `fee_collection`; creates nothing itself (INV-I1) |
| `assignBatch(StudentAdmission $a, Batch $b, array $opts): StudentBatchEnrollment` | delegates to `BatchEnrollmentService::enroll()`, sets `student_admissions.batch_id`, stage -> `batch_assignment` |
| `activate(StudentAdmission $a): StudentAdmission` | asserts an active enrollment exists and the `require_fee_before_activation` precondition (reading Phase 18's charge and receipt rows, never recomputing them); stage -> `active`; student -> `active`; creates the login; fires `StudentActivated` |
| `complete(StudentAdmission $a)` / `cancel(StudentAdmission $a, string $reason)` / `withdraw(StudentAdmission $a, string $reason)` | §2.30.5; `cancel` asks Phase 18 whether a cleared receipt exists and refuses if so, naming the receipt - a refund is Phase 18's act, not a cancellation side effect |
| `transferBatch(StudentAdmission $a, Batch $to, string $reason)` | see below |

**`BatchEnrollmentService`** - capacity is enforced here and nowhere else:

| Method | Guarantees |
|---|---|
| `enroll(Student $s, Batch $b, StudentAdmission $a, array $opts): StudentBatchEnrollment` | one transaction: (1) `lockForUpdate` the batch row; (2) assert `BatchStatus::acceptsEnrollment()`; (3) assert the batch's course equals the admission's course; (4) assert the student is `registered` or `active`; (5) **recount** `COUNT(*) WHERE status = 'active'` - never read `current_students`; (6) when `count >= student_capacity`, and again when the batch is physical and `count >= classroom.capacity`, refuse with `BatchCapacityExceeded` unless `$opts['overbook']` is true **and** `institute.batch_allow_overbooking` is true **and** the actor holds `batches.assign` **and** a reason is supplied - then set `is_overbooked` + `overbook_reason`; (7) insert the enrollment (a 1062 on `uq_sbe_active` becomes "already enrolled", not a duplicate); (8) `current_students = recount + 1`; (9) assign the next free `roll_number`; (10) `CourseProgressService::openFor($enrollment)`; (11) warn (never block) when the student's other active batches have a timetable slot overlapping this batch's, returning the clashing slots for the UI |
| `transfer(StudentBatchEnrollment $e, Batch $to, string $reason): StudentBatchEnrollment` | one transaction, both batch rows locked in ascending id order (deadlock-free): capacity checked on the target, old row -> `transferred_out` with `left_on` and the reason, new row created and linked both ways, attendance history stays with the old row, progress is **carried over** (the topic rows are repointed to the new enrollment's progress row when the course is the same; a different course opens a fresh progress row), `student_admissions.batch_id` updated, and the fee side is handed to Phase 18: a **same-course** transfer calls `StudentFeeService::reassignBatch()` (a non-financial `batch_id` repoint, per spine §2.2 "a batch transfer updates this and nothing financial"), a **different-course** transfer calls `StudentFeeService::transferPayment()` per Phase 18 §13.3 so the carried receipt keeps its original `paid_on`. This phase writes neither (§13). Both batches recount |
| `drop(StudentBatchEnrollment $e, string $reason)` / `suspend` / `reinstate` / `complete` | §2.30.8; each recounts the batch |
| `recount(Batch $b): int` | the authority for `current_students`; used by the nightly command (INV-I7) |
| `roster(Batch $b, ?Carbon $on = null): Collection` | enrollments that were `active` on a date - the function attendance marking and `expected_count` both use, so a student who joined mid-course is never marked absent for classes held before they enrolled |

### 6.7 `TimetableService`, `ScheduleClashDetector`, `ClassSessionService` - the clash rules

**`ScheduleClashDetector` is the single clash authority.** Timetable entries, one-off sessions,
reschedules, substitutions and demo classes all call it; so do Phase 19-23's exams (§81) and
classroom-bearing meetings (§95). Nothing re-implements an overlap test.

**The published generic entry point (F-4.7), the only one any phase calls:**

```
ScheduleClashDetector::check(SlotCandidate $c): ClashReport
```

`App\DataObjects\Institute\SlotCandidate` - a readonly DTO, named arguments, **no positional money-style
ambiguity**:

| Property | Type | Meaning |
|---|---|---|
| `teacherId` | `?int` | skip the teacher dimension when null |
| `classroomId` | `?int` | skip the classroom dimension when null |
| `batchId` | `?int` | skip the batch dimension when null |
| `startsAt` | `CarbonInterface` | slot start, date **and** time |
| `endsAt` | `CarbonInterface` | slot end, half-open |
| `ignoreType` | `?string` | the row type to exclude (`timetable_entry` \| `class_session` \| `demo_class` \| `exam` \| `meeting`) - how an edit ignores itself |
| `ignoreId` | `?int` | that row's id |

`App\DataObjects\Institute\ClashReport` - readonly `bool $clean`, `array $conflicts` (every clash found,
never just the first, so the UI can show them all at once; each conflict names its type, id, dimension,
subject label and window).

**Additive recurring extension.** A §71 weekly *rule* is not a dated slot, so `SlotCandidate` carries four
further readonly properties, all nullable and all defaulted, which a dated caller never passes:
`?Weekday $dayOfWeek`, `?CarbonInterface $effectiveFrom`, `?CarbonInterface $effectiveTo`,
`?DeliveryMode $deliveryMode`. When `dayOfWeek` is set the candidate is recurring and the day/window
predicates below apply; otherwise `startsAt` / `endsAt` are the whole story. This is additive only - an
exam or a meeting constructs the DTO with the seven canonical properties and nothing else.

**The three subject wrappers** (`forTimetableEntry()`, `forClassSession()`, `forDemoClass()`) build a
`SlotCandidate` from their model and call `check()`. They are thin by contract: a wrapper that contains an
overlap predicate is a review failure.

**Overlap predicates.**

| Dimension | Predicate |
|---|---|
| Time (always half-open) | `start_time < other.end_time AND other.start_time < end_time`. So 09:00-10:00 and 10:00-11:00 do **not** clash; with `institute.timetable_slot_gap_minutes = g`, the candidate's window is widened by `g` on both sides for the teacher and classroom dimensions only |
| Day (recurring vs recurring) | `day_of_week` equal **and** the date windows overlap: `effective_from <= COALESCE(other.effective_to, '9999-12-31') AND other.effective_from <= COALESCE(effective_to, '9999-12-31')` |
| Date (dated vs dated) | `session_date` equal |
| Recurring vs dated | the dated row's `session_date` falls inside the recurring row's window **and** `Weekday::fromDate(session_date) = day_of_week` |

**The three dimensions checked, in this order** (the report lists every clash found, not just the first,
so the UI can show all of them at once):

| # | Dimension | Scope searched | Skipped when |
|---|---|---|---|
| 1 | **Teacher** | `timetable_entries` (`is_active`), `class_sessions` (`scheduled`/`held`), `demo_classes` (`scheduled`) with the same `teacher_id` | `teacher_id` is null |
| 2 | **Classroom** | the same three tables with the same `classroom_id` | `classroom_id` is null, or `delivery_mode = online`, or the classroom's `type = virtual` - a virtual room holds infinite classes |
| 3 | **Batch** | the same three tables with the same `batch_id` | never skipped: students cannot be in two places, so a batch clash is always an error |

**Later-phase occupants.** The three tables above are the scope this phase owns. Phase 19-23's `exams` and
classroom-bearing `meetings` are added to the same scope by registering them with the detector (one
declaration per table: table, teacher column, classroom column, batch column, date column, time columns,
live-status filter). The predicates, the row locks and the report shape do not change, which is what makes
INV-I8 true for exams and meetings without a second implementation (F-4.7).

**Serialisation.** Overlap is a range condition and MariaDB cannot express it as a unique index, so the
guard is: `TimetableService` / `ClassSessionService` / `DemoClassService` open a transaction,
`lockForUpdate()` the **parent rows** involved (teacher row, classroom row, batch row, always in
ascending table-then-id order to avoid deadlocks), then call the detector, then insert. Two concurrent
writers for the same teacher therefore serialise on the teacher row. The three exact-duplicate unique
indexes per table (§2.22, §2.23, §2.16) are the cheap backstop for identical submissions, and
`timetable:verify-clashes` (§10.4) reports anything that ever slipped in through a seeder or raw SQL.
**[D-IN-14]** A clash is **always** an error for the batch dimension; for teacher and classroom it can be
overridden only by a holder of `timetable.change_status` supplying a reason, which is recorded on the
entry's `notes` and in the activity log. Nothing is ever silently allowed.

**`TimetableService`**

| Method | Guarantees |
|---|---|
| `create(Batch $b, array $data): TimetableEntry` | `branch_id`, `course_id` copied from the batch; `teacher_id` defaults to the batch teacher and must be a teacher whose `TeacherStatus::canTeach()` is true; times inside `timetable_day_start`/`end`; `day_of_week` in `timetable_working_days`; clash-checked as above; then `ClassSessionService::generate()` for the open window |
| `seedFromBatch(Batch $b): Collection` | expands `batches.days` + `start_time` + `end_time` into one entry per weekday, each clash-checked; partial success is impossible (all or nothing) |
| `update(TimetableEntry $e, array $data)` | re-checks clashes ignoring itself; **future** `scheduled` sessions are regenerated, `held` sessions are never touched |
| `end(TimetableEntry $e, Carbon $on, string $reason)` | sets `effective_to`, deactivates, cancels future `scheduled` sessions with the reason and notifies the roster |
| `views(TimetableQuery $q): TimetableGrid` | the one query object behind all five views of §71 (§8.13), returning slots already grouped for the requested axis |

**`ClassSessionService`**

| Method | Guarantees |
|---|---|
| `generate(?Batch $b, Carbon $from, Carbon $to): int` | idempotent: inserts on `uq_cs_generated(timetable_entry_id, session_date, active_guard)` and ignores 1062; never generates before the batch `start_date`, after `end_date`, after `effective_to`, or for a batch that is `completed`/`cancelled`/`on_hold`; sets `sequence_no` as the count of prior sessions of that batch + 1; copies teacher, room, mode and URL from the entry |
| `createOneOff(Batch $b, array $data): ClassSession` | an extra class with `timetable_entry_id = null`; clash-checked |
| `cancel(ClassSession $s, ClassCancellationReason $r, string $detail)` | refused once attendance exists; notifies the roster and the teacher; decrements nothing (the session stays visible) |
| `reschedule(ClassSession $s, array $newSlot, string $reason): ClassSession` | clash-checks the new slot, creates the successor, links both ways, old row -> `rescheduled`, notifies |
| `substituteTeacher(ClassSession $s, Teacher $t, string $reason)` | stores `original_teacher_id`, clash-checks the substitute, notifies both teachers |
| `markHeld(ClassSession $s): ClassSession` | stamps `status = held`; when `institute.attendance_auto_absent_on_close` is true, fills unmarked roster members as `absent` with `marked_via = system`; recounts the batch's `sessions_held_count` |

### 6.8 The fee boundary - what this phase does and does not create

The brief's explicit coordination point. **This phase creates no money row (INV-I1).**

| Fact | Owner | How this phase touches it |
|---|---|---|
| `student_fees` charge rows (course fee, admission fee, registration fee, monthly fee) | Phase 10 table / Phase 18 service | `AdmissionService::requestFees()` calls `StudentFeeService::generateStructure(StudentAdmission $a, FeeStructureData $d)` and passes the admission's agreed figures; Phase 18 asserts `SUM(net) = net_payable` and aborts the whole transaction if it does not hold. This phase never inserts |
| `student_fee_installments` | Phase 18 | requested through the same call (`$d->installments`), capped by `courses.max_installments` and refused when `courses.installment_available` is false |
| The monthly fee head | Phase 18 | driven by `courses.monthly_fee` / `student_admissions.monthly_fee`, which this phase provides so `fees:generate-monthly` does not have to ask every month (Phase 18 R-3) |
| `student_fee_discounts` | Phase 18 | the admission's `discount_amount` / `scholarship_amount` are the **agreed** figures; the authoritative, reason-carrying, approvable discount history is Phase 18's table. The admission wizard shows both and reconciles them on the charge screen |
| `student_fee_payments` receipts | Phase 10 | never created here. The admission screen links to the spine's record-payment modal |
| Commission | Phase 10 | fired by a receipt, never by anything in this phase (INV-I1, spine INV-1) |
| `student_admissions.charged_amount` / `paid_amount` / `refunded_amount` / `balance_amount` | **columns owned here, written only by Phase 18** | the model rejects a write outside `StudentFeeService::withinServiceContext()`; this phase only reads them |
| `student_admissions.figures_locked_at` | column owned here, stamped by Phase 18 on the first charge | makes INV-I2 enforceable |
| `student_fees.batch_id` on a batch transfer | Phase 18 | `BatchEnrollmentService::transfer()` calls `StudentFeeService::reassignBatch()` for a same-course move and `::transferPayment()` for a course change; it never UPDATEs a fee or payment row |
| Fee status shown on a student screen, in the institute dashboard and in the §57 collaborator list | Phase 18 | read through `StudentFeeService::summaryFor()`; this phase never sums `student_fee_payments` itself |

Consequence for the UI: the admission wizard's "fee collection" step is a **handover**. It renders Phase
18's charge form and the spine's record-payment modal inside the wizard shell, and the wizard advances
only after Phase 18 reports at least one charge.

### 6.9 `AttendanceService` and `AttendanceReportService`

**`AttendanceService`**

| Method | Guarantees |
|---|---|
| `roster(ClassSession $s): Collection` | `BatchEnrollmentService::roster($s->batch, $s->session_date)` joined to any existing attendance row, so re-opening the screen shows what was already marked |
| `mark(ClassSession $s, array $marks, array $meta): AttendanceResult` | one transaction; refuses when the session is `cancelled` or `rescheduled`; refuses a `student_id` not on the roster for that date (INV-I9); `upsert` on `uq_sa_session_student` so a double submit cannot duplicate; `minutes_late` computed from `check_in_time` and `attendance_grace_minutes` (a `present` mark whose check-in exceeds the grace becomes `late` automatically, stated on screen); stamps `marked_by`, `marked_at`, `marked_via`; sets the session's five counters and `attendance_marked_at`, moves the session to `held`; recounts each affected enrollment; fires `AttendanceMarked` |
| `amend(StudentAttendance $a, StudentAttendanceStatus $to, string $reason): StudentAttendance` | allowed freely inside `institute.attendance_lock_hours` of `marked_at`; after that it requires `student_attendance.edit` and a non-empty reason, stamps `amended_at`/`amended_by`/`amendment_reason`, and logs old and new status (INV-I10). Never deletes |
| `bulk(ClassSession $s, StudentAttendanceStatus $status): AttendanceResult` | "mark all present" / "mark all absent", then individual overrides |
| `import(Batch $b, UploadedFile $csv): ImportReport` | `marked_via = import`; validates every row before writing any; returns per-row errors; one transaction |
| `recountEnrollment(StudentBatchEnrollment $e): void` | the authority for the five enrollment counters and `attendance_percentage` |

**The percentage, defined once** (INV-I11):

```
denominator = count of HELD sessions of the batch whose session_date falls inside the enrollment's
              active window, for which the student has an attendance row
              (+ rows with status = leave only when institute.attendance_leave_counts_in_denominator)
numerator   = present_count + late_count
attendance_percentage = denominator = 0 ? 0.00 : round(numerator * 100 / denominator, 2)   // bcmath, half-up
```

Cancelled and rescheduled sessions never enter either side. A student enrolled on the 10th is never
measured against classes held on the 3rd (that is what `roster($date)` guarantees).

**`AttendanceReportService`** - the four reports of §75, each a query object with a `DateRange`, a branch
scope and an export:

| Report | Shape | Key columns |
|---|---|---|
| `daily(Carbon $date, filters)` | one row per session held that day | batch, course, teacher, classroom, time, expected, present, absent, leave, late, percentage, marked-by, "not marked" flag |
| `monthly(Batch $b, int $year, int $month)` | student x day matrix | one cell per session day with a P/A/L/Lt glyph, row totals, column totals, the batch average |
| `percentage(filters)` | one row per enrollment | student, batch, course, sessions held, present, absent, leave, late, percentage, a `below_minimum` flag from `institute.attendance_minimum_percentage` |
| `batchSummary(filters)` | one row per batch | sessions planned, held, cancelled, average attendance, students below the minimum, last session date |

All four read the same three base queries, so a number can never differ between two screens.

### 6.10 `CourseProgressService`

§83 at three levels, with the batch as the normal driver and the student as the override.

| Method | Guarantees |
|---|---|
| `openFor(StudentBatchEnrollment $e): StudentCourseProgress` | creates the course row plus one module row and one topic row per **active** outline node, snapshotting `topics_total`, `modules_total` and `weight_total`; idempotent on `uq_scp_enrollment` |
| `markTopicForBatch(Batch $b, CourseTopic $t, array $data): void` | upserts `batch_topic_coverage` (status, percentage, `covered_on`, `class_session_id`, teacher), then fans out to every **active** enrollment's topic row **unless** that row's `source = manual` (a hand-set individual result is never overwritten by the class-level mark); then recomputes module and course rows. One transaction, one recompute per enrollment |
| `markTopicForStudent(StudentBatchEnrollment $e, CourseTopic $t, array $data): void` | `source = manual`; recomputes upward |
| `markFromSession(ClassSession $s): void` | called when a session with a `course_topic_id` is marked `held`: the topic becomes `completed` for the batch (or `in_progress` at the given percentage) |
| `skipTopic(Batch $b, CourseTopic $t, string $reason): void` | `skipped` status; the topic's weight leaves every denominator; reason mandatory and audited |
| `recompute(StudentCourseProgress $p): StudentCourseProgress` | the only writer of the percentages |
| `recomputeForCourse(Course $c): int` | after an outline change (topic added, deactivated, moved, weight changed) - queued in chunks |

**The formulas, defined once** (INV-I11, bcmath, half-up at 2):

```
topic contribution     = topic.completion_percentage / 100 * (weighting = topic_weight ? topic.weight : 1)
module.percentage      = sum(topic contributions in module) * 100 / sum(weights of non-skipped active topics in module)
course.weight_total    = sum(weights of non-skipped active topics)
course.weight_completed= sum(topic contributions)
course.percentage      = weight_total = 0 ? 0.00 : weight_completed * 100 / weight_total
status                 = percentage = 0 ? pending : (percentage >= 100 ? completed : in_progress)
```

A module with no active topics is hand-markable and then contributes its own status with weight 1. A
deactivated or skipped topic leaves both numerator and denominator, so deactivating an uncovered topic
**raises** the percentage instead of stalling it - which is the behaviour a coordinator expects when they
drop a topic from a batch. `batches.syllabus_completion_percentage` is the same formula over
`batch_topic_coverage`.

### 6.11 `TeacherService`, `ClassroomService`, `BatchService`

**`TeacherService`**

| Method | Guarantees |
|---|---|
| `create(array $data): Teacher` | `teacher_code` from §6.5; optional login when `auto_create_teacher_login`; `slug` required when `is_public` |
| `linkEmployee(Teacher $t, Employee $e, string $reason): Teacher` | refuses when either side is already linked (`uq_te_employee`); copies identity and `salary` from the employee; locks those fields in the form; audits with the reason |
| `unlinkEmployee(Teacher $t, string $reason): Teacher` | clears `employee_id`, keeps the copied values, unlocks the fields, deletes nothing |
| `syncFromEmployee(Employee $e): ?Teacher` | the listener body ([D-IN-9]); one-way only |
| `assignCourses(Teacher $t, array $courseIds, ?int $primaryCourseId): void` | syncs `course_teacher`; at most one `is_primary` per course |
| `changeStatus(Teacher $t, TeacherStatus $to, ?string $reason)` | moving off `active` is refused while the teacher has a `scheduled` session in the next `session_generation_weeks_ahead` window, naming the sessions, until they are reassigned or substituted - the check that stops a resigned teacher silently owning next week's timetable |
| `workload(Teacher $t, DateRange $r): WorkloadReport` | weekly hours, batches, students, sessions held, attendance-marking compliance |

**`ClassroomService`** - `create`/`update`/`setActive`/`delete`; deactivation is refused while a
`scheduled` session or an active timetable entry references the room, naming them; delete is refused once
any session ever used it (FK is `nullOnDelete`, the policy is stricter).

**`BatchService`**

| Method | Guarantees |
|---|---|
| `create(array $data): Batch` | `code` unique; capacity defaults from settings; `status = planned`; `days` validated against `timetable_working_days`; `classroom.capacity` warning when it is below `student_capacity` |
| `update(Batch $b, array $data)` | lowering `student_capacity` below `current_students` is refused, naming the count; changing course is refused once any enrollment exists; changing the teacher re-checks clashes for every active timetable entry and offers to substitute future sessions |
| `changeStatus(Batch $b, BatchStatus $to, ?string $reason)` | §2.30.7, including the session and notification side effects |
| `recountStudents(Batch $b)` / `recountSessions(Batch $b)` | the cache authorities (INV-I7) |
| `capacitySnapshot(Batch $b): array` | `{capacity, active, free, percentage, room_capacity, effective_capacity}` - the single source for the capacity meter, the public "seats left" label and the `BatchNearCapacity` event |
| `upcomingForPublic(?Course $c): Collection` | `status = enrolling AND start_date >= today AND current_students < student_capacity`, ordered by `start_date` - §89's strip and §90's table read nothing else |

### 6.12 `CourseInquiryService` and `DemoClassService`

**`CourseInquiryService`**

| Method | Guarantees |
|---|---|
| `createFromPublic(array $data): CourseInquiry` | `source = InquirySource::Website` (Phase 4's enum, F-5.3); `idempotency_key` unique; honeypot + `throttle:5,1`; `inquiry_number` issued; when called by Phase 4's `InquiryTarget` it stores `contact_inquiry_id` and relies on `uq_ci_inquiry` - a 1062 returns the existing inquiry, so a re-routed or replayed `contact_inquiries` row can never create a second enquiry (F-3.8); referral code and `referral_visit_id` resolved and snapshotted exactly as §6.4 (no attachment - there is no subject yet); assigned by round-robin among users holding `course_inquiries.edit` when `assigned_to` is absent; fires `CourseInquiryReceived` |
| `create(array $data): CourseInquiry` | the staff/walk-in path, same guarantees minus the throttle |
| `logFollowUp(CourseInquiry $i, array $data): CourseInquiryFollowUp` | appends the log row, recomputes `contact_attempts`, `last_contacted_at`, `follow_up_date` (defaulting to +`inquiry_followup_days`), and applies `FollowUpOutcome::suggestsStatus()` through `changeStatus()` so the two can never disagree |
| `changeStatus(CourseInquiry $i, CourseInquiryStatus $to, ?string $reason)` | §2.30.2; `lost_reason` mandatory for `not_interested` |
| `assign(CourseInquiry $i, User $to, ?string $note)` | audited; notifies the new owner |
| `promoteToApplication(CourseInquiry $i, array $data): StudentApplication` | copies the contact fields, links both ways, carries the referral snapshot forward |
| `convertDirect(CourseInquiry $i, array $data): StudentAdmission` | the walk-in shortcut: creates the application (status `converted`), the student and the admission in one transaction, so the funnel is never missing a step in the reports |
| `funnel(DateRange $r, filters): FunnelReport` | counts per status and per source, with the conversion rate - the §88 "inquiry conversion" chart reads this |

**`DemoClassService`**

| Method | Guarantees |
|---|---|
| `schedule(array $data): DemoClass` | exactly one subject FK (CHECK-backed); `attendee_name`/`phone` snapshotted from the subject; `end_time` defaulted from `demo_class_duration_minutes`; **clash-checked through `ScheduleClashDetector` for teacher and classroom** (§6.7); teacher must be `canTeach()`; moves a linked inquiry to `demo_scheduled`; fires `DemoClassScheduled` |
| `reschedule(DemoClass $d, array $slot, string $reason)` | clash-checked; notifies |
| `markAttended(DemoClass $d, ?string $remarks)` / `markMissed(DemoClass $d)` / `cancel(DemoClass $d, string $reason)` | §2.30.6; each updates the linked inquiry's status through `CourseInquiryService` |
| `convert(DemoClass $d, array $data): StudentAdmission` | creates or reuses the application and student, sets `converted_admission_id`, status `converted` |
| `calendar(DateRange $r, filters): Collection` | the demo calendar, shown on the same grid as the timetable so a coordinator sees both |

### 6.13 `PublicCourseService` - the §89-90 read model, with referral preservation

| Method | Guarantees |
|---|---|
| `catalogue(CatalogueQuery $q): Paginator` | `status = published AND is_indexable-agnostic`, category `is_active`, branch-agnostic; filters: category, level, delivery mode, certificate, fee range, `featured`, free-text on name and short description; ordered `is_featured desc, sort_order, name`; eager-loads category and default teacher; `institute.public_course_catalogue_per_page` |
| `landing(string $slug): CourseLandingPayload` | one payload, one query budget: the course, its category, the **active** outline tree (lectures flagged `is_preview`), public resources, FAQs, `requirements`, `outcomes`, trainers (`course_teacher` + default teacher, `is_public` only), upcoming batches via `BatchService::upcomingForPublic()`, approved student reviews for the course (Phase 4's `student_reviews`, read-only), the three fee components, `certificate_available`, and `effectiveAdmissionOpen` |
| `applyUrl(Course $c, ?Batch $b = null): string` | **the referral-preserving link.** `route('site.admission.create', array_filter(['course' => $c->slug, 'batch' => $b?->code, 'ref' => ReferralLinkService::displayCode()]))`. The **authoritative** carrier is Phase 9's visit row, session and encrypted cookie, written by its `CaptureReferral` middleware; the `ref` query parameter is kept purely so a shared or bookmarked link still works and so the visitor can see the attribution. Every "Apply now", "Enroll", "Inquire" and WhatsApp button on every public page is built by this method - a hand-written `href="/admission"` anywhere is a review failure because it drops the visible half of the attribution |
| `whatsappUrl(Course $c): string` | `https://wa.me/{setting('contact.whatsapp')}?text=` + a prefilled message naming the course and the captured referral code, so a WhatsApp enquiry still carries the referral when staff create the inquiry |
| `sitemapEntries(): iterable` | published, indexable courses and active categories for Phase 3's sitemap |

**Referral preservation, end to end.** (1) `/courses/php-laravel?ref=COL-1024` - Phase 9's
`CaptureReferral` middleware records a `collaborator_referral_visits` row and stores the token in the
session and an encrypted cookie. (2) Every link on the page is built by `applyUrl()`, so the visible code
survives navigation even if the visitor browses three courses first - and the session/cookie carries it
even when the link does not. (3) `/admission?course=php-laravel&ref=COL-1024` renders the form with
Phase 9's `<x-site.referral-field>` (which posts a **visit token**, never a code) plus a read-only chip
"Referred by: Ahmed Traders (COL-1024)" and an optional "have a referral code?" text input.
(4) The POST hands the token, the typed code, the staff pick and any override reason to
`ReferralAttributionResolver`; nothing from the browser is trusted as an id (INV-I4, Phase 9 INV-R2).
(5) `convert()` calls `ReferralService::attach()` - the first moment a `collaborator_referrals` row
exists. A code that cannot be verified at step 3 is shown as "we could not verify that referral code" and
the application is still accepted.

### 6.14 `StudentDirectoryService`

One read model for every "list of students" in the system, so column exposure is decided in one file.

| Method | Guarantees |
|---|---|
| `forAdmin(StudentQuery $q): Paginator` | full columns, branch-scoped per §9 |
| `forTeacher(Teacher $t, StudentQuery $q): Paginator` | restricted to `TeacherScope::batchIds($t)`; omits `cnic`, `address`, `guardian_phone`, every collaborator column and every money column |
| `forCollaborator(Collaborator $c, StudentQuery $q): Paginator` | §57: name, course, batch, registration date, status, plus `total_paid` and `commission_earned` **only** when the matching `collaborator_portal.*` permissions are held, each value fetched from `StudentFeeService` / the spine's statement service - never computed here. Omits phone, email, CNIC, address and guardian fields unless `collaborator_portal.student_basic_details` is granted. **Scoped by an `active` `collaborator_referrals` row only** - `students.collaborator_id` is a display snapshot, read by no scope and no engine (**D37**, resolutions R5), so a hand-written or stale snapshot grants nothing (FT-50) |
| `forStudent(Student $s): StudentSelfPayload` | own row only |

---

## 7. Routes

Every admin route carries `auth`, `active`, `panel:admin` from the Phase 1 route group; panel routes carry
their own `panel:*`; public routes carry only the `web` group plus what is stated. `module:*` is written
once per block where it is constant. Route names follow `panel.resource.action` (`CLAUDE.md` §3); public
routes use the `site.` prefix ([D-IN-15]; Phase 3 to confirm, §13).

### 7.1 Admin - course categories and courses (`module:course_categories` / `module:courses`)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/course-categories` | `admin.course-categories.index` | `can:course_categories.view_any` |
| POST `/admin/course-categories` | `admin.course-categories.store` | `can:course_categories.create` |
| GET `/admin/course-categories/{category}/edit` | `admin.course-categories.edit` | `can:course_categories.edit` |
| PUT `/admin/course-categories/{category}` | `admin.course-categories.update` | `can:course_categories.edit` |
| POST `/admin/course-categories/reorder` | `admin.course-categories.reorder` | `can:course_categories.edit` |
| POST `/admin/course-categories/{category}/toggle` | `admin.course-categories.toggle` | `can:course_categories.change_status` |
| DELETE `/admin/course-categories/{category}` | `admin.course-categories.destroy` | `can:course_categories.delete` |
| GET `/admin/courses` | `admin.courses.index` | `module:courses`, `can:courses.view_any` |
| GET `/admin/courses/create` | `admin.courses.create` | `can:courses.create` |
| POST `/admin/courses` | `admin.courses.store` | `can:courses.create` |
| GET `/admin/courses/{course}` | `admin.courses.show` | `can:courses.view` |
| GET `/admin/courses/{course}/edit` | `admin.courses.edit` | `can:courses.edit` |
| PUT `/admin/courses/{course}` | `admin.courses.update` | `can:courses.edit` |
| DELETE `/admin/courses/{course}` | `admin.courses.destroy` | `can:courses.delete` |
| POST `/admin/courses/{course}/publish` | `admin.courses.publish` | `can:courses.change_status` |
| POST `/admin/courses/{course}/unpublish` | `admin.courses.unpublish` | `can:courses.change_status` |
| POST `/admin/courses/{course}/archive` | `admin.courses.archive` | `can:courses.change_status` |
| POST `/admin/courses/{course}/featured` | `admin.courses.featured` | `can:courses.change_status` |
| POST `/admin/courses/{course}/duplicate` | `admin.courses.duplicate` | `can:courses.create` |
| POST `/admin/courses/reorder` | `admin.courses.reorder` | `can:courses.edit` |
| GET `/admin/courses/export/{format}` | `admin.courses.export` | `can:courses.export` |

### 7.2 Admin - course outline (`module:course_outline`)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/courses/{course}/outline` | `admin.course-outline.index` | `can:course_outline.view` |
| POST `/admin/courses/{course}/modules` | `admin.course-modules.store` | `can:course_outline.create` |
| PUT `/admin/course-modules/{module}` | `admin.course-modules.update` | `can:course_outline.edit` |
| DELETE `/admin/course-modules/{module}` | `admin.course-modules.destroy` | `can:course_outline.delete` |
| POST `/admin/course-modules/{module}/duplicate` | `admin.course-modules.duplicate` | `can:course_outline.create` |
| POST `/admin/course-modules/{module}/topics` | `admin.course-topics.store` | `can:course_outline.create` |
| PUT `/admin/course-topics/{topic}` | `admin.course-topics.update` | `can:course_outline.edit` |
| DELETE `/admin/course-topics/{topic}` | `admin.course-topics.destroy` | `can:course_outline.delete` |
| POST `/admin/course-topics/{topic}/move` | `admin.course-topics.move` | `can:course_outline.edit` |
| POST `/admin/course-topics/{topic}/lectures` | `admin.course-lectures.store` | `can:course_outline.create` |
| PUT `/admin/course-lectures/{lecture}` | `admin.course-lectures.update` | `can:course_outline.edit` |
| DELETE `/admin/course-lectures/{lecture}` | `admin.course-lectures.destroy` | `can:course_outline.delete` |
| POST `/admin/course-topics/{topic}/resources` | `admin.course-resources.store` | `can:course_outline.upload` |
| PUT `/admin/course-resources/{resource}` | `admin.course-resources.update` | `can:course_outline.edit` |
| DELETE `/admin/course-resources/{resource}` | `admin.course-resources.destroy` | `can:course_outline.delete` |
| POST `/admin/course-topics/{topic}/assignments` | `admin.course-topic-assignments.store` | `can:course_outline.create` |
| PUT `/admin/course-topic-assignments/{blueprint}` | `admin.course-topic-assignments.update` | `can:course_outline.edit` |
| DELETE `/admin/course-topic-assignments/{blueprint}` | `admin.course-topic-assignments.destroy` | `can:course_outline.delete` |
| POST `/admin/courses/{course}/outline/reorder` | `admin.course-outline.reorder` | `can:course_outline.edit` |
| POST `/admin/courses/{course}/outline/{node}/toggle` | `admin.course-outline.toggle` | `can:course_outline.change_status` |
| POST `/admin/courses/{course}/faqs` | `admin.course-faqs.store` | `can:courses.edit` |
| PUT `/admin/course-faqs/{faq}` | `admin.course-faqs.update` | `can:courses.edit` |
| DELETE `/admin/course-faqs/{faq}` | `admin.course-faqs.destroy` | `can:courses.edit` |

These three routes write **Phase 3's `faqs` rows** through `FaqService::save()` with
`faqable_type = Course` (§2.10, F-2.2); `{faq}` binds `faqs.id`. No `course_faqs` table exists, and the
names do not collide with Phase 3's own `admin.faqs.*` CRUD.

### 7.3 Admin - inquiries, applications, demo classes

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/course-inquiries` | `admin.course-inquiries.index` | `module:course_inquiries`, `can:course_inquiries.view_any` |
| GET `/admin/course-inquiries/create` | `admin.course-inquiries.create` | `can:course_inquiries.create` |
| POST `/admin/course-inquiries` | `admin.course-inquiries.store` | `can:course_inquiries.create` |
| GET `/admin/course-inquiries/{inquiry}` | `admin.course-inquiries.show` | `can:course_inquiries.view` |
| PUT `/admin/course-inquiries/{inquiry}` | `admin.course-inquiries.update` | `can:course_inquiries.edit` |
| DELETE `/admin/course-inquiries/{inquiry}` | `admin.course-inquiries.destroy` | `can:course_inquiries.delete` |
| POST `/admin/course-inquiries/{inquiry}/follow-ups` | `admin.course-inquiries.follow-ups.store` | `can:course_inquiries.edit` |
| POST `/admin/course-inquiries/{inquiry}/assign` | `admin.course-inquiries.assign` | `can:course_inquiries.assign` |
| POST `/admin/course-inquiries/{inquiry}/status` | `admin.course-inquiries.status` | `can:course_inquiries.change_status` |
| POST `/admin/course-inquiries/{inquiry}/promote` | `admin.course-inquiries.promote` | `can:student_applications.create` |
| POST `/admin/course-inquiries/{inquiry}/convert` | `admin.course-inquiries.convert` | `can:admissions.create` |
| GET `/admin/course-inquiries/reports/funnel` | `admin.course-inquiries.funnel` | `can:course_inquiries.view_reports` |
| GET `/admin/course-inquiries/export/{format}` | `admin.course-inquiries.export` | `can:course_inquiries.export` |
| GET `/admin/student-applications` | `admin.student-applications.index` | `module:student_applications`, `can:student_applications.view_any` |
| GET `/admin/student-applications/{application}` | `admin.student-applications.show` | `can:student_applications.view` |
| POST `/admin/student-applications/{application}/claim` | `admin.student-applications.claim` | `can:student_applications.edit` |
| POST `/admin/student-applications/{application}/duplicate` | `admin.student-applications.duplicate` | `can:student_applications.change_status` |
| POST `/admin/student-applications/{application}/reject` | `admin.student-applications.reject` | `can:student_applications.reject` |
| POST `/admin/student-applications/{application}/withdraw` | `admin.student-applications.withdraw` | `can:student_applications.change_status` |
| POST `/admin/student-applications/{application}/convert` | `admin.student-applications.convert` | `can:students.create` (the policy also requires `admissions.create`) |
| GET `/admin/student-applications/export/{format}` | `admin.student-applications.export` | `can:student_applications.export` |
| GET `/admin/demo-classes` | `admin.demo-classes.index` | `module:demo_classes`, `can:demo_classes.view_any` |
| GET `/admin/demo-classes/calendar` | `admin.demo-classes.calendar` | `can:demo_classes.view_any` |
| POST `/admin/demo-classes` | `admin.demo-classes.store` | `can:demo_classes.create` |
| PUT `/admin/demo-classes/{demo}` | `admin.demo-classes.update` | `can:demo_classes.edit` |
| POST `/admin/demo-classes/{demo}/reschedule` | `admin.demo-classes.reschedule` | `can:demo_classes.edit` |
| POST `/admin/demo-classes/{demo}/status` | `admin.demo-classes.status` | `can:demo_classes.change_status` |
| POST `/admin/demo-classes/{demo}/convert` | `admin.demo-classes.convert` | `can:admissions.create` |
| GET `/admin/demo-classes/{demo}/slip` | `admin.demo-classes.slip` | `can:demo_classes.print` |

### 7.4 Admin - students and admissions

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/students` | `admin.students.index` | `module:students`, `can:students.view_any` |
| GET `/admin/students/create` | `admin.students.create` | `can:students.create` |
| POST `/admin/students` | `admin.students.store` | `can:students.create` |
| GET `/admin/students/{student}` | `admin.students.show` | `can:students.view` |
| GET `/admin/students/{student}/edit` | `admin.students.edit` | `can:students.edit` |
| PUT `/admin/students/{student}` | `admin.students.update` | `can:students.edit` |
| DELETE `/admin/students/{student}` | `admin.students.destroy` | `can:students.delete` |
| POST `/admin/students/{student}/status` | `admin.students.status` | `can:students.change_status` |
| POST `/admin/students/{student}/login` | `admin.students.login.store` | `can:students.edit` |
| POST `/admin/students/{student}/merge` | `admin.students.merge` | `can:students.delete` |
| POST `/admin/students/import` | `admin.students.import` | `can:students.import` |
| GET `/admin/students/export/{format}` | `admin.students.export` | `can:students.export` |
| GET `/admin/admissions` | `admin.admissions.index` | `module:admissions`, `can:admissions.view_any` |
| GET `/admin/admissions/create` | `admin.admissions.create` | `can:admissions.create` |
| POST `/admin/admissions` | `admin.admissions.store` | `can:admissions.create` |
| GET `/admin/admissions/{admission}` | `admin.admissions.show` | `can:admissions.view` |
| PUT `/admin/admissions/{admission}/figures` | `admin.admissions.figures` | `can:admissions.edit` |
| POST `/admin/admissions/{admission}/register` | `admin.admissions.register` | `can:admissions.change_status` |
| POST `/admin/admissions/{admission}/fees` | `admin.admissions.fees` | `can:student_fees.create` |
| POST `/admin/admissions/{admission}/batch` | `admin.admissions.batch` | `can:batches.assign` |
| POST `/admin/admissions/{admission}/activate` | `admin.admissions.activate` | `can:admissions.change_status` |
| POST `/admin/admissions/{admission}/complete` | `admin.admissions.complete` | `can:admissions.change_status` |
| POST `/admin/admissions/{admission}/cancel` | `admin.admissions.cancel` | `can:admissions.change_status` |
| POST `/admin/admissions/{admission}/withdraw` | `admin.admissions.withdraw` | `can:admissions.change_status` |
| POST `/admin/admissions/{admission}/transfer` | `admin.admissions.transfer` | `can:batches.assign` |
| GET `/admin/admissions/{admission}/print` | `admin.admissions.print` | `can:admissions.print` |
| GET `/admin/admissions/export/{format}` | `admin.admissions.export` | `can:admissions.export` |

### 7.5 Admin - teachers, classrooms, batches

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/teachers` | `admin.teachers.index` | `module:teachers`, `can:teachers.view_any` |
| GET `/admin/teachers/create` · POST `/admin/teachers` | `admin.teachers.create` · `.store` | `can:teachers.create` |
| GET `/admin/teachers/{teacher}` | `admin.teachers.show` | `can:teachers.view` |
| GET `/admin/teachers/{teacher}/edit` · PUT `/admin/teachers/{teacher}` | `admin.teachers.edit` · `.update` | `can:teachers.edit` |
| DELETE `/admin/teachers/{teacher}` | `admin.teachers.destroy` | `can:teachers.delete` |
| POST `/admin/teachers/{teacher}/employee-link` | `admin.teachers.employee-link.store` | `can:teachers.edit` |
| DELETE `/admin/teachers/{teacher}/employee-link` | `admin.teachers.employee-link.destroy` | `can:teachers.edit` |
| POST `/admin/teachers/{teacher}/courses` | `admin.teachers.courses` | `can:teachers.assign` |
| POST `/admin/teachers/{teacher}/status` | `admin.teachers.status` | `can:teachers.change_status` |
| GET `/admin/teachers/{teacher}/workload` | `admin.teachers.workload` | `can:teachers.view_reports` |
| GET `/admin/teachers/export/{format}` | `admin.teachers.export` | `can:teachers.export` |
| GET `/admin/classrooms` | `admin.classrooms.index` | `module:classrooms`, `can:classrooms.view_any` |
| POST `/admin/classrooms` | `admin.classrooms.store` | `can:classrooms.create` |
| PUT `/admin/classrooms/{classroom}` | `admin.classrooms.update` | `can:classrooms.edit` |
| POST `/admin/classrooms/{classroom}/toggle` | `admin.classrooms.toggle` | `can:classrooms.change_status` |
| DELETE `/admin/classrooms/{classroom}` | `admin.classrooms.destroy` | `can:classrooms.delete` |
| GET `/admin/batches` | `admin.batches.index` | `module:batches`, `can:batches.view_any` |
| GET `/admin/batches/create` · POST `/admin/batches` | `admin.batches.create` · `.store` | `can:batches.create` |
| GET `/admin/batches/{batch}` | `admin.batches.show` | `can:batches.view` |
| GET `/admin/batches/{batch}/edit` · PUT `/admin/batches/{batch}` | `admin.batches.edit` · `.update` | `can:batches.edit` |
| DELETE `/admin/batches/{batch}` | `admin.batches.destroy` | `can:batches.delete` |
| POST `/admin/batches/{batch}/status` | `admin.batches.status` | `can:batches.change_status` |
| GET `/admin/batches/{batch}/roster` | `admin.batches.roster` | `can:batches.view` |
| POST `/admin/batches/{batch}/enrollments` | `admin.batches.enrollments.store` | `can:batches.assign` |
| POST `/admin/enrollments/{enrollment}/transfer` | `admin.enrollments.transfer` | `can:batches.assign` |
| POST `/admin/enrollments/{enrollment}/status` | `admin.enrollments.status` | `can:students.change_status` |
| GET `/admin/batches/{batch}/print-roster` | `admin.batches.print-roster` | `can:batches.print` |
| GET `/admin/batches/export/{format}` | `admin.batches.export` | `can:batches.export` |

### 7.6 Admin - timetable and class sessions (`module:timetable`)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/timetable/{view?}` (`daily`\|`weekly`\|`teacher`\|`batch`\|`classroom`, default `weekly`) | `admin.timetable.index` | `can:timetable.view_any` |
| POST `/admin/timetable` | `admin.timetable.store` | `can:timetable.create` |
| PUT `/admin/timetable/{entry}` | `admin.timetable.update` | `can:timetable.edit` |
| DELETE `/admin/timetable/{entry}` | `admin.timetable.destroy` | `can:timetable.delete` |
| POST `/admin/timetable/{entry}/end` | `admin.timetable.end` | `can:timetable.change_status` |
| POST `/admin/batches/{batch}/timetable/seed` | `admin.timetable.seed` | `can:timetable.create` |
| POST `/admin/timetable/check-clash` | `admin.timetable.check-clash` | `can:timetable.create` (dry run, writes nothing) |
| GET `/admin/timetable/print/{view}` | `admin.timetable.print` | `can:timetable.print` |
| GET `/admin/timetable/export/{format}` | `admin.timetable.export` | `can:timetable.export` |
| GET `/admin/class-sessions` | `admin.class-sessions.index` | `can:timetable.view_any` |
| GET `/admin/class-sessions/{session}` | `admin.class-sessions.show` | `can:timetable.view` |
| POST `/admin/class-sessions` | `admin.class-sessions.store` | `can:timetable.create` |
| POST `/admin/class-sessions/generate` | `admin.class-sessions.generate` | `can:timetable.create` |
| POST `/admin/class-sessions/{session}/cancel` | `admin.class-sessions.cancel` | `can:timetable.change_status` |
| POST `/admin/class-sessions/{session}/reschedule` | `admin.class-sessions.reschedule` | `can:timetable.change_status` |
| POST `/admin/class-sessions/{session}/substitute` | `admin.class-sessions.substitute` | `can:timetable.change_status` |
| POST `/admin/class-sessions/{session}/held` | `admin.class-sessions.held` | `can:timetable.change_status` |

### 7.7 Admin - attendance and progress

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/attendance` | `admin.attendance.index` | `module:student_attendance`, `can:student_attendance.view_any` |
| GET `/admin/attendance/sessions/{session}` | `admin.attendance.mark` | `can:student_attendance.create` |
| POST `/admin/attendance/sessions/{session}` | `admin.attendance.store` | `can:student_attendance.create`, `throttle:30,1` |
| POST `/admin/attendance/sessions/{session}/bulk` | `admin.attendance.bulk` | `can:student_attendance.create` |
| PUT `/admin/attendance/{attendance}` | `admin.attendance.update` | `can:student_attendance.edit` |
| POST `/admin/attendance/import` | `admin.attendance.import` | `can:student_attendance.import` |
| GET `/admin/attendance/reports/daily` | `admin.attendance.reports.daily` | `can:student_attendance.view_reports` |
| GET `/admin/attendance/reports/monthly` | `admin.attendance.reports.monthly` | `can:student_attendance.view_reports` |
| GET `/admin/attendance/reports/percentage` | `admin.attendance.reports.percentage` | `can:student_attendance.view_reports` |
| GET `/admin/attendance/reports/batch` | `admin.attendance.reports.batch` | `can:student_attendance.view_reports` |
| GET `/admin/attendance/print/{report}` | `admin.attendance.print` | `can:student_attendance.print` |
| GET `/admin/attendance/export/{report}/{format}` | `admin.attendance.export` | `can:student_attendance.export` |
| GET `/admin/progress` | `admin.progress.index` | `module:student_progress`, `can:student_progress.view_any` |
| GET `/admin/batches/{batch}/progress` | `admin.progress.batch` | `can:student_progress.view` |
| POST `/admin/batches/{batch}/progress/topics/{topic}` | `admin.progress.batch.topic` | `can:student_progress.create` |
| POST `/admin/batches/{batch}/progress/topics/{topic}/skip` | `admin.progress.batch.skip` | `can:student_progress.change_status` |
| GET `/admin/enrollments/{enrollment}/progress` | `admin.progress.student` | `can:student_progress.view` |
| POST `/admin/enrollments/{enrollment}/progress/topics/{topic}` | `admin.progress.student.topic` | `can:student_progress.edit` |
| POST `/admin/enrollments/{enrollment}/progress/recompute` | `admin.progress.recompute` | `can:student_progress.edit` |
| GET `/admin/progress/export/{format}` | `admin.progress.export` | `can:student_progress.export` |

### 7.8 Student panel (`routes/student.php`: `auth`, `active`, `panel:student`)

Every route is additionally scoped by the `BelongsToAuthenticatedStudent` global scope and a policy that
returns **404** for another student's id (§9).

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/student` | `student.dashboard` | `can:student_portal.dashboard` |
| GET `/student/courses` | `student.courses.index` | `module:courses`, `can:student_portal.course` |
| GET `/student/courses/{enrollment}` | `student.courses.show` | `can:student_portal.course` |
| GET `/student/batches/{enrollment}` | `student.batches.show` | `module:batches`, `can:student_portal.batch` |
| GET `/student/timetable` | `student.timetable.index` | `module:timetable`, `can:student_portal.timetable` |
| GET `/student/attendance` | `student.attendance.index` | `module:student_attendance`, `can:student_portal.attendance` |
| GET `/student/progress` | `student.progress.index` | `module:student_progress`, `can:student_portal.progress` |
| GET `/student/teachers` | `student.teachers.index` | `module:teachers`, `can:student_portal.teachers` |

### 7.9 Teacher panel (`routes/teacher.php`: `auth`, `active`, `panel:teacher`)

Every route is scoped by `TeacherScope` and a policy returning **404** for a batch, session or student
outside it.

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/teacher` | `teacher.dashboard` | `can:teacher_portal.dashboard` |
| GET `/teacher/batches` | `teacher.batches.index` | `module:batches`, `can:teacher_portal.batches` |
| GET `/teacher/batches/{batch}` | `teacher.batches.show` | `can:teacher_portal.batches` |
| GET `/teacher/students` | `teacher.students.index` | `module:students`, `can:teacher_portal.students` |
| GET `/teacher/timetable` | `teacher.timetable.index` | `module:timetable`, `can:teacher_portal.timetable` |
| GET `/teacher/sessions/{session}` | `teacher.sessions.show` | `can:teacher_portal.timetable` |
| GET `/teacher/sessions/{session}/attendance` | `teacher.attendance.mark` | `module:student_attendance`, `can:teacher_portal.attendance_mark` |
| POST `/teacher/sessions/{session}/attendance` | `teacher.attendance.store` | `can:teacher_portal.attendance_mark`, `throttle:30,1` |
| PUT `/teacher/attendance/{attendance}` | `teacher.attendance.update` | `can:teacher_portal.attendance_mark` |
| GET `/teacher/attendance` | `teacher.attendance.index` | `can:teacher_portal.attendance` |
| GET `/teacher/batches/{batch}/progress` | `teacher.progress.show` | `module:student_progress`, `can:teacher_portal.progress` |
| POST `/teacher/batches/{batch}/progress/topics/{topic}` | `teacher.progress.store` | `can:teacher_portal.progress_mark` |
| POST `/teacher/enrollments/{enrollment}/progress/topics/{topic}` | `teacher.progress.student` | `can:teacher_portal.progress_mark` |
| GET `/teacher/courses/{course}/outline` | `teacher.courses.outline` | `module:course_outline`, `can:teacher_portal.course_outline` |
| GET `/teacher/demo-classes` | `teacher.demo-classes.index` | `module:demo_classes`, `can:teacher_portal.demo_classes` |
| POST `/teacher/demo-classes/{demo}/status` | `teacher.demo-classes.status` | `can:teacher_portal.demo_classes` |
| GET `/teacher/reports/attendance` | `teacher.reports.attendance` | `can:teacher_portal.reports` |

### 7.10 Public (`routes/web.php`, guest-safe, no `auth`)

Phase 3 owns the public shell and its three aliases - `site` (`EnsurePublicSiteAvailable`: the 503 holding
page / maintenance view, bypassed for staff holding `website_sections.view`), `site.preview` and
`site.cache` (the terminable response cache). This phase **reuses all three** and adds exactly one.

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/courses` | `site.courses.index` | `module:courses`, `site`, `site.preview`, `site.cache` |
| GET `/courses/category/{category:slug}` | `site.courses.category` | `module:courses`, `site`, `site.preview`, `site.cache` |
| GET `/courses/{course:slug}` | `site.courses.show` | `module:courses`, `site`, `site.preview`, `site.cache` |
| GET `/admission` | `site.admission.create` | `module:students`, `site`, `admission.open` - **never `site.cache`**: the form carries a CSRF token, an idempotency ULID and a referral field |
| POST `/admission` | `site.admission.store` | `admission.open`, `throttle:5,1` |
| GET `/admission/submitted/{application:application_number}` | `site.admission.submitted` | `signed` (a signed URL, so an application number cannot be enumerated) |
| POST `/course-inquiries` | `site.course-inquiries.store` | `module:course_inquiries`, `site`, `throttle:5,1` |

| New alias | Class | Behaviour |
|---|---|---|
| `admission.open` | `EnsureAdmissionFormOpen` | renders the "admissions are currently closed" page (HTTP 200, `noindex`) with the contact block when `maintenance.admission_form_enabled` or `institute.admission_open` is false; POST returns 422 with the same message. Bypassed, like Phase 3's `site`, for a staff user holding `admissions.create`, who sees an amber ribbon naming the state |

Cache invalidation: publishing, unpublishing, archiving or editing a course, and any outline or FAQ change,
flush Phase 3's public cache for that course's URLs through its published-content invalidation hook, and
the course pages register with `SitemapRegistry`; the catalogue's own SEO comes from Phase 3's `seo_meta`
row keyed `route_key = site.courses.index` (§13).

---

## 8. UI screens

House rules from Phase 1 §9 and `CLAUDE.md` §6 apply everywhere: `x-ui.*` components only, every list has
search + filters + sortable headers + pagination + an empty state + a skeleton loader, money and
percentages are right-aligned `tabular-nums`, every write raises a toast, every destructive or
irreversible action goes through `x-ui.confirm` with a reason field where §2.30 says *reason mandatory*,
tables scroll inside `overflow-x-auto`, and every screen renders in light and dark.

**[D-IN-16] What is built and what is refused.**

| Pattern | Where | Why |
|---|---|---|
| **Calendar** | timetable (daily + weekly), class sessions, demo classes | §71 explicitly asks for daily and weekly views |
| **Wizard** | course form (5 tabs), admission pipeline (7 steps), enrollment/transfer modal (3 steps) | §68 is a sequence of gated steps; the course form has 40+ fields |
| **Matrix grid** | monthly attendance (student x day), batch progress (student x topic) | §75 "monthly" and §83 are two-dimensional by nature |
| **Kanban** | **not built** | §86 gives statuses, not a board; §18 is where the requirement asks for a Kanban (leads) and §22 for tasks. An inquiry board would be invented scope. Offered as open question Q4; the inquiry index instead ships status tabs with counts, which is the same information in a sortable, filterable, exportable list |

### 8.1 Course categories (`admin.course-categories.index`)

**Purpose.** Manage and order the catalogue's top level (§64).
**Components.** `x-ui.page-header`, `x-ui.card`, `x-ui.table` with a drag handle column, `x-ui.form.toggle`,
`x-ui.modal` (create/edit), `x-ui.confirm`, `x-ui.badge`, `x-ui.empty-state`.
**Filters.** search on name; state (active/inactive/all).
**Columns.** drag handle · icon · name · slug · courses count (links to the filtered course list) · active
toggle · sort order · actions (edit, delete).
**Reorder.** Alpine sortable on the tbody; on drop, one POST of the ordered id array; optimistic UI with a
rollback toast on failure. Keyboard accessible (alt+arrow moves a row) so reordering is not mouse-only.
**Empty state.** "No categories yet - courses need one" + Add category.
**Disable.** Toggling off opens a confirm that names how many published courses stay live and offers the
`cascade_courses` checkbox, worded plainly: "also move 7 published courses to draft".

### 8.2 Courses index (`admin.courses.index`)

**Purpose.** The catalogue work surface.
**Components.** `x-ui.page-header` (+ Add course), `x-ui.filter-bar`, `x-ui.table`, `x-ui.th-sortable`,
`x-ui.badge`, `x-ui.stat-card` row (published / draft / archived / featured), `x-ui.pagination-summary`,
`x-ui.empty-state`, `x-ui.skeleton`.
**Filters.** category, status, level, delivery mode, certificate available, installment available,
featured, branch, fee range, free text (name, code).
**Columns.** thumbnail · name + code · category · level · mode · duration · **course fee** (hidden
without `courses.view_financial`) · batches (active count) · students (active enrollments) · status badge
· featured star · actions (view, edit, outline, duplicate, publish/unpublish, archive, delete).
**Empty state.** "No courses match these filters" with a Clear filters action; the true-empty variant
offers Add course and Import is deliberately absent (§62 has no import requirement).

### 8.3 Course form (`admin.courses.create|edit`) - 5 tabs

**Components.** `x-ui.tabs`, `x-ui.form.*`, `x-ui.form.file` with preview, `x-ui.card`, a sticky save bar
(Alpine, appears when dirty, warns on navigate-away - the Phase 2 settings pattern).

| Tab | Fields |
|---|---|
| Basics | name, code, slug (auto from name, lock icon once published), category, level, delivery mode, duration value + unit, total classes, class duration, default teacher, featured, admission open, sort order |
| Content | short description, full description (rich text), requirements (repeatable rows), outcomes (repeatable rows), certificate available |
| Fees | course fee, admission fee, registration fee, **live total**, installment available toggle -> max installments + note. A read-only hint states that changing a fee never changes an existing admission or charge (INV-I2) |
| Media | image, thumbnail, promo video URL, gallery-free by design (§62 lists two images) |
| SEO | seo title, seo description, keywords, canonical URL, OG image, indexable toggle, plus a Google-style preview |

Validation errors jump to and flag the owning tab. The Fees tab is hidden entirely without
`courses.view_financial`, and the server re-checks before saving those three columns.

### 8.4 Course detail + outline builder (`admin.courses.show`, `admin.course-outline.index`)

**Purpose.** One screen per course: what it is, what it teaches, who is in it.
**Tabs.** Overview (the public landing preview + stats) · **Outline** · Batches · Students · FAQs ·
Inquiries · Activity.
**Outline builder.** A three-level nested drag-and-drop tree (module > topic > lecture) with per-node
inline rename, an "add" button at every level, a collapse-all control, and a right-hand drawer for the
selected node showing its description, duration, weight, resources and assignment blueprints.
Components: `x-ui.card`, `x-ui.modal`, `x-ui.form.*`, `x-ui.badge`, `x-ui.icon-button`, `x-ui.confirm`,
`x-ui.empty-state`, `x-ui.skeleton`. Each node shows `is_active`, its child count and total minutes; the
header shows `modules_count / topics_count / lectures_count / outline_minutes`.
**Drag rules.** A lecture can only be dropped into a topic, a topic only into a module of the same course,
a module only within its course. The drop posts `{level, parentId, orderedIds}` and the server re-verifies
ownership (§6.3) - the client's word is never taken.
**Deactivate vs delete.** A node referenced by coverage, progress or a session renders its delete button
disabled with a tooltip naming the references and offers Deactivate instead (INV-I13).
**Empty state.** "This course has no outline yet" + Add first module, with a note that a course cannot be
published without one.

### 8.5 Course inquiries (`admin.course-inquiries.index|show`)

**Index purpose.** The counsellor's daily queue.
**Components.** `x-ui.tabs` as status tabs with live counts, `x-ui.filter-bar`, `x-ui.table`,
`x-ui.badge`, `x-ui.stat-card` row (new today / due follow-ups / overdue follow-ups / converted this
month), `x-ui.empty-state`.
**Filters.** status, source, course, assigned to, branch, follow-up date (overdue / today / this week /
range), created range, has referral code, free text (name, phone, inquiry number).
**Columns.** inquiry # · name + phone (click-to-call, WhatsApp icon) · course · source badge · status
badge · assigned to · last contacted · **next follow-up** (red when overdue) · attempts · actions (view,
log follow-up, schedule demo, assign, convert).
**Row highlight.** Stale rows (`institute.inquiry_stale_days`) get an amber left border and a tooltip -
flagged, never auto-closed.
**Show purpose.** Everything about one enquiry in one scroll.
**Layout.** Left: contact card, course interest, referral chip (collaborator name + code + valid/invalid
badge), source and landing URL. Right: a **follow-up timeline** (newest first: channel icon, outcome
badge, notes, who, when) with an inline "log follow-up" form that sets the next date from
`inquiry_followup_days`; below it the demo classes and the conversion panel.
**Conversion panel.** Two buttons: "Create application" and "Admit directly" (the walk-in shortcut), each
opening the admission wizard pre-filled.
**Empty state.** "No inquiries in this view" + Add inquiry.

### 8.6 Application inbox (`admin.student-applications.index|show`)

**Purpose.** Triage the public §67 form without touching student records until a human decides.
**Components.** `x-ui.tabs` (submitted / under review / converted / rejected / duplicates),
`x-ui.table`, `x-ui.badge`, `x-ui.modal`, `x-ui.confirm`, `x-ui.empty-state`.
**Filters.** status, course, batch, branch, has referral, referral valid/invalid, date range, free text
(name, phone, application number).
**Columns.** application # · submitted at · name + phone · course · preferred batch / timing / mode ·
referral chip · **duplicate flag** · status · reviewer · actions (review, reject, mark duplicate,
convert).
**Duplicate flag.** When another application shares the `duplicate_fingerprint` inside
`institute.application_duplicate_window_days`, the row turns amber and the review screen shows both side
by side with a "same person?" decision.
**Review screen.** The submitted data read-only on the left, an editable conversion form on the right
(course, batch, agreed fees pre-filled from the course, counselor, branch), the referral verdict spelled
out ("COL-1024 - Ahmed Traders, active - will be attached on conversion" / "code not recognised - nobody
will be attached"), and a single Convert button that runs §6.4's one transaction.
**Empty state.** "Nothing waiting - the public form is " + open/closed state, linking to the setting.

### 8.7 Students (`admin.students.index|show`)

**Index components.** `x-ui.filter-bar`, `x-ui.table`, `x-ui.avatar`, `x-ui.badge`, `x-ui.stat-card` row
(total / active / new this month / dropped), `x-ui.empty-state`.
**Filters.** status (the seven), course, batch, branch, gender, city, joining range, referred-by
collaborator, has login, attendance below minimum, free text (name, `student_code`,
`registration_number`, phone, CNIC).
**Columns.** photo · name + father name · `student_code` · registration # · course/batch chips · status
badge · phone · joining date · **fee status** chip (from Phase 18, hidden without
`student_fees.view_financial`) · attendance % · actions.
**Show layout.** Header: photo, name, `student_code`, registration #, status badge with a change-status
action, branch, referral chip. Tabs: Profile · Admissions · **Fees** (Phase 18 partial, permission-gated)
· Batches (enrollment history with transfers) · Attendance (summary + register link) · Progress ·
Documents (Phase 19) · Activity.
**Empty states.** Per tab, each with the action that fills it ("No admission yet" + Start admission).

### 8.8 Admission wizard (`admin.admissions.create`, `admin.admissions.show`)

The §68 pipeline as a stepper - the most important screen of Phase 15.
**Components.** `x-ui.card`, a horizontal `x-ui.tabs`-based stepper with state icons (done / current /
locked), `x-ui.form.*`, `x-ui.modal`, `x-ui.badge`, `x-ui.confirm`, `x-ui.skeleton`.

| Step | Contents | Advance rule |
|---|---|---|
| 1 Student | pick existing or create (the §66 form), inquiry/application link, referral chip | a student exists |
| 2 Course + figures | course, delivery mode, timing, the three fees pre-filled, discount, scholarship, **live net payable**, installment request | figures valid; `chk_sadm_discount_ceiling` mirrored client-side |
| 3 Registration | registration date, counselor; shows the registration number that **will** be issued | `register()` succeeded |
| 4 Fees | **Phase 18's charge form embedded** (fee heads, due dates, installment plan), then the spine's record-payment modal | at least one charge exists; the step shows charged / paid / balance from Phase 18 |
| 5 Batch | batch picker showing capacity meters and the weekly slot of each candidate, a timing match hint, and a clash warning when the student's other batches overlap | an active enrollment exists |
| 6 Activate | a checklist (registration number, charge, payment per `require_fee_before_activation`, batch, login) with every unmet item linked to its step | all required items green |

On an existing admission the same stepper is the detail screen, with the current stage highlighted, every
completed step collapsible, and the money panel read-only. Cancel / withdraw / transfer live in the
header's actions menu, each behind `x-ui.confirm` with a mandatory reason.
**Locked figures.** Once `figures_locked_at` is set, step 2's money fields render read-only with a
"locked - a correction is a fee adjustment" note linking to Phase 18's discount screen (INV-I2).

### 8.9 Demo classes (`admin.demo-classes.index|calendar`)

**Purpose.** Book, remind, mark, convert (§87).
**Components.** `x-ui.tabs` (upcoming / today / past / converted), `x-ui.table`, `x-ui.badge`,
`x-ui.modal` (schedule, 1 step), `x-ui.confirm`, `x-ui.empty-state`; the calendar shares the timetable
grid component so a coordinator sees demos and classes in one picture.
**Filters.** status, teacher, course, classroom, branch, date range, subject type.
**Columns.** date + time · attendee (name, phone, subject-type badge) · course · teacher · classroom /
online · status badge · outcome remarks · actions (reschedule, mark attended/missed, cancel, convert,
print slip).
**Schedule modal.** Subject picker (inquiry / application / student, searchable), course, teacher,
classroom, date, time (end time auto-filled), mode, meeting URL; **a live clash panel** that calls
`admin.timetable.check-clash` on change and names the conflicting class or demo before the user can save.
**Empty state.** "No demo classes scheduled" + Schedule demo.

### 8.10 Teachers and classrooms

**Teachers index.** Filters: status, course, branch, specialization, public/not, employee-linked/not.
Columns: photo · name + `teacher_code` · specialization · courses (chips) · active batches · weekly hours
· phone · **salary** (only with `teachers.view_financial`) · status · employee link badge · actions.
**Teacher show.** Tabs: Profile (with the employee-link panel: link/unlink with a reason, and a note that
identity fields are synced one-way from the employee) · Courses · Batches · Timetable (their week) ·
Students · Workload report · Activity.
**Classrooms index.** A compact table: code · name · type badge · capacity · location · branch · active
toggle · this week's booked hours · actions. Deactivation names the scheduled sessions blocking it.
**Empty states.** "No teachers yet - a batch needs one", "No classrooms - online batches can run without
one".

### 8.11 Batches (`admin.batches.index|show`)

**Index components.** `x-ui.stat-card` row (enrolling / running / completed / seats free),
`x-ui.filter-bar`, `x-ui.table`, a capacity meter component (`x-ui.badge` + a bar), `x-ui.empty-state`.
**Filters.** status, course, teacher, classroom, branch, delivery mode, start-date range, has free seats,
near capacity.
**Columns.** code + name · course · teacher · schedule summary ("Mon/Wed/Fri 18:00-20:00") · classroom ·
start - end · **capacity meter** (`12 / 20`, amber at `batch_near_capacity_threshold`, red when full) ·
status badge · syllabus % · actions (view, roster, timetable, status, edit).
**Show tabs.** Overview (capacity meter, schedule, teacher, next session, counts) · **Roster** ·
Timetable · Sessions · Attendance · Progress · Fees summary (Phase 18) · Activity.
**Roster.** Columns: roll # · photo · name · `student_code` · enrolled on · status badge · attendance % ·
progress % · fee status (gated) · actions (transfer, drop, suspend, view). Bulk actions: mark completed,
transfer selected, print roster. An overbooked row carries an amber chip with the reason on hover.
**Empty state.** "No students enrolled yet" + Enroll student (disabled with a tooltip when the batch is
not `planned`/`enrolling`).

### 8.12 Enroll and transfer modal (wizard, 3 steps)

**Purpose.** The only place a seat is taken, and it must never overbook silently (INV-I6).
**Steps.** (1) student search (by code, name, phone) restricted to `registered`/`active` students whose
admission matches the batch's course, with their current batches listed; (2) the **capacity panel**:
`12 / 20 seats, 8 free`, the classroom's own capacity when lower, and a timetable-overlap warning listing
the student's clashing slots; (3) review + confirm. When the batch is full the confirm button is replaced
by an "Overbook" button that is visible only with `batches.assign` **and**
`institute.batch_allow_overbooking`, and that requires a reason before it enables.
**Transfer variant.** Step 2 shows source and target capacity side by side plus what moves (attendance
stays, progress carries over, charges are repointed by Phase 18) and requires a reason.

### 8.13 Timetable - the five views of §71 (`admin.timetable.index`)

One screen, one query object (`TimetableService::views()`), five renderings selected by a segmented
control; the choice persists in `users.preferences`.

| View | Rendering | Axes / grouping |
|---|---|---|
| **Daily** | calendar grid for one date | columns = classrooms (plus an "Online" column), rows = time from `timetable_day_start` to `timetable_day_end`; shows the dated `class_sessions`, so cancellations and substitutes are visible |
| **Weekly** | calendar grid for one week | columns = `timetable_working_days` honouring `localization.week_start`, rows = time; recurring entries, with a toggle to overlay the generated sessions |
| **Teacher-wise** | one row band per teacher | weekly grid per teacher with a weekly-hours total and a red outline on any clash the verifier found |
| **Batch-wise** | one row band per batch | the batch's week plus its student count and syllabus % |
| **Classroom-wise** | one row band per classroom | utilisation percentage per room per day - the view that answers "where can I put a new batch?" |

**Common chrome.** `x-ui.filter-bar` (branch, course, teacher, batch, classroom, delivery mode, date /
week picker), a "show cancelled" toggle, print and export actions, and a legend for the status colours.
Slots are colour-coded by course, striped when `cancelled`, and badged `SUB` when substituted. Clicking a
slot opens the entry drawer; dragging a slot is **not** supported in this phase (a drag means a clash
re-check plus a session regeneration, and a mis-drag would silently rewrite a schedule) - moves go through
the edit drawer with the clash panel. Mobile falls back to a grouped agenda list.
**Clash panel.** The create/edit drawer calls `admin.timetable.check-clash` on every field change and
renders each conflict as "Teacher Ayesha already has WEB-101-B7 on Monday 18:00-20:00 (from 2026-03-01)"
with a link. Saving with an unresolved teacher or classroom clash requires `timetable.change_status` and a
reason; a batch clash can never be saved.
**Empty state.** "No classes scheduled for this week" + Add slot / Seed from batch days.

### 8.14 Class session detail (`admin.class-sessions.show`)

Header: batch, course, date, time, `sequence_no` ("class 12 of 40"), status badge, teacher (with the
original teacher struck through when substituted), classroom or meeting link.
Panels: attendance summary (present/absent/leave/late with a link to mark or amend), the topic covered
(topic picker writing `course_topic_id` and firing the coverage fan-out), notes, and the audit trail.
Actions: mark held, cancel (reason select + detail), reschedule (clash-checked), substitute teacher.

### 8.15 Attendance marking (`admin.attendance.mark`, `teacher.attendance.mark`)

**Purpose.** Mark a full roster in under thirty seconds, on a phone at the classroom door.
**Components.** `x-ui.card`, `x-ui.avatar`, a four-state segmented control per row, `x-ui.badge`,
`x-ui.confirm`, `x-ui.toast`, `x-ui.skeleton`.
**Layout.** Sticky header with batch, date, time, expected count and four bulk buttons (all present / all
absent / reset / invert). One row per roster member: photo, roll #, name, the four-state control
(P / A / L / Lt with distinct colours **and** distinct letters, never colour alone), an optional check-in
time that auto-promotes `present` to `late` past the grace minutes, and a remarks field behind a note
icon. A live counter strip shows the four totals and the session percentage as it is marked.
**Keyboard.** `p` `a` `l` `t` mark the focused row and advance; `enter` submits. Every control is
reachable by tab, so a teacher never needs the mouse.
**Already marked.** Existing rows are pre-filled and the header says "marked by X at 18:12"; changing a
row after `attendance_lock_hours` opens the amend dialog with a mandatory reason (INV-I10).
**Guards shown, not hidden.** A cancelled session shows "this class was cancelled - attendance cannot be
recorded"; a student who joined after this date is simply absent from the roster, with a footnote
explaining why.
**Empty state.** "No students enrolled on this date" + Enroll students.

### 8.16 Attendance reports (`admin.attendance.reports.*`)

| Report | Rendering | Filters | Empty state |
|---|---|---|---|
| Daily | table of sessions for one date + four stat cards | date, branch, course, batch, teacher, classroom, "not marked only" | "No classes scheduled on this date" |
| Monthly | **matrix**: students down, session days across, P/A/L/Lt glyphs in cells, totals on both axes, the batch average in the corner | batch (required), month, include-cancelled toggle | "This batch held no classes in <month>" |
| Percentage | table, one row per enrollment, sortable on percentage, rows below `attendance_minimum_percentage` flagged | branch, course, batch, status, percentage range, date range | "No enrollments match" |
| Batch summary | table, one row per batch, with a sparkline of weekly attendance | branch, course, teacher, status, date range | "No batches in this range" |

Every report has print and export (CSV/Excel per §99) and states the filter in force in its footer, so a
printed sheet is never ambiguous. The matrix scrolls horizontally inside its own container with a frozen
first column.

### 8.17 Progress (`admin.progress.batch`, `admin.progress.student`)

**Batch progress board.** A **matrix** of students (rows) x topics (columns, grouped under their module
headers) with a four-state cell (pending / in progress / completed / skipped). Above it, the class-level
coverage row: marking a topic there fans out to every active enrollment (§6.10) and the cell shows a small
"manual" dot where an individual value was set by hand and will not be overwritten. Right rail: module
roll-up bars, the batch syllabus percentage, and the last session's covered topic.
**Components.** `x-ui.card`, `x-ui.badge`, a progress bar component, `x-ui.modal` (mark topic: status,
percentage, date, session, notes), `x-ui.confirm` (skip topic, reason mandatory), `x-ui.empty-state`.
**Student progress.** Module accordions with topic rows, each showing status, percentage, source
(`batch_coverage` / `manual` / `assessment`), who marked it and when; a header ring chart with the course
percentage; a Recompute action for a holder of `student_progress.edit`.
**Empty state.** "This course has no outline, so there is nothing to track" + Add outline.

### 8.18 Student panel (§74, `layouts/panel`)

| Screen | Purpose and content |
|---|---|
| Dashboard | stat cards: my courses, attendance %, syllabus %, next class (with a join button for online), pending fee (Phase 18 partial); lists: this week's classes, recent attendance, latest announcements (Phase 22) |
| My courses | one card per active enrollment: course, batch, teacher, schedule, progress ring, attendance %, a link to the outline (read-only, active nodes only, preview lectures playable) |
| My batch | batch details, teacher card, classmates **count only** (never a classmate list - §112), the weekly schedule, upcoming sessions, cancelled-session notices |
| Timetable | weekly calendar of the student's own sessions only, with cancelled sessions struck through and reschedules linked; ICS download |
| Attendance | month selector, a calendar heat strip of the four states, the four totals, the percentage against `attendance_minimum_percentage` with a plain-language warning when below, and a session-by-session list with remarks |
| Progress | the course ring plus module accordions with topic status - read-only |
| My teachers | name, photo, public bio and specialization of the teachers of their own batches only |

No screen in the student panel renders a collaborator, commission, cost or another student's data. Money
is limited to the student's own fee summary through Phase 18's partial.

### 8.19 Teacher panel (§73, `layouts/panel`)

| Screen | Purpose and content |
|---|---|
| Dashboard | today's classes with a one-tap "mark attendance" per session, unmarked-session warnings (the compliance nudge), my batches, student count, pending demo classes, this week's hours |
| My batches | card per batch: course, schedule, capacity meter, syllabus %, average attendance, next session; link to the roster |
| Batch roster | the restricted student list of §6.14 (`forTeacher`) - no CNIC, address, guardian phone or money |
| My timetable | weekly calendar of their own slots, with substitutions marked and an ICS download |
| Session detail | the same panels as §8.14 minus the admin actions: mark attendance, set the topic covered, request a reschedule (which notifies a coordinator rather than writing one) |
| Mark attendance | §8.15, identical component, gated by `teacher_portal.attendance_mark` |
| Batch progress | §8.17's board restricted to their batches, gated by `teacher_portal.progress_mark` for writes |
| Course outline | read-only tree of the courses they teach, with resources downloadable |
| Demo classes | their own scheduled demos, with mark attended / missed |
| Attendance report | percentage and monthly reports restricted to their batches |

### 8.20 Public screens (§89-90)

**Catalogue `/courses`.** A filter rail (category, level, delivery mode, certificate, fee range, search)
plus a responsive card grid: thumbnail, category chip, name, short description, duration, level, mode,
fee (or "Contact us" when the fee is 0), certificate badge, "seats left" when a batch is filling, and two
buttons - **Apply now** (built by `applyUrl()`) and WhatsApp. Featured courses pin to the top. Empty
state: "No courses match - clear filters". Paginated by
`institute.public_course_catalogue_per_page`, each page server-rendered with its own canonical URL.

**Landing page `/courses/{slug}`** - every element §90 lists, in this order: hero (name, image/video,
category, level, mode, duration, classes, certificate badge, fee block with the three components and the
installment note, **Apply now** + WhatsApp + "Download outline"); about (full description); learning
outcomes; requirements; **outline accordion** (modules > topics, lecture titles with a play icon on
`is_preview`, public resources listed); trainer cards (`is_public` only); **upcoming batches table**
(start date, days, time, mode, classroom/online, seats left, per-row Apply now carrying the batch);
student reviews (approved, Phase 4); FAQs accordion; a sticky mobile footer with Apply now; and a closing
CTA. SEO: the course's own title/description/OG/canonical and a `Course`-type JSON-LD block.
**Referral.** Every Apply/Inquire/WhatsApp link comes from `PublicCourseService::applyUrl()` /
`whatsappUrl()`, so `?ref=` survives every hop (§6.13). When a referral is captured, a discreet line
reads "Referred by Ahmed Traders" - evidence to the visitor that the attribution is recorded.

**Admission form `/admission`** (§67). One card, grouped fieldsets: You (name, father name, phone,
WhatsApp, email, city, education) · Course (course select pre-filled from the query, batch select showing
only enrolling batches with free seats - required when `admission_form_require_batch`, preferred timing,
online/physical) · Anything else (message) · Phase 9's `<x-site.referral-field>` rendering the read-only
referral chip and the hidden **visit token**, with a "have a referral code?" text disclosure when nothing
was captured. A honeypot field, a minimum fill time,
`throttle:5,1`, inline validation, a disabled-on-submit button, and a visible note that submitting is an
enquiry, not an enrolment. On success: redirect to a **signed** thank-you URL showing the application
number, what happens next, and the institute's phone/WhatsApp. When the form is closed, the route renders
"admissions are currently closed" with the contact block instead of a 404 dead end.

### 8.21 Dashboard widgets (registered into Phase 2's `DashboardRegistry`)

§88's institute dashboard, as classes in `app/Dashboard/Widgets/Institute/`. Each declares its
`permission()` and `module()`, so a user without the permission never sees it and a disabled module hides
it (Phase 2 §3).

| Widget | Data | Permission |
|---|---|---|
| `TotalStudentsWidget` | total / active / new this period, with the previous-period delta | `students.view_any` |
| `NewAdmissionsWidget` | admissions in range by stage | `admissions.view_any` |
| `ActiveCoursesWidget` | published courses, featured count | `courses.view_any` |
| `ActiveBatchesWidget` | enrolling / running, seats free | `batches.view_any` |
| `TodaysClassesWidget` | today's sessions with status and the unmarked-attendance count | `timetable.view_any` |
| `TeachersWidget` | active teachers, average weekly hours | `teachers.view_any` |
| `NewInquiriesWidget` | new, due follow-ups, overdue | `course_inquiries.view_any` |
| `InquiryConversionChartWidget` | funnel by status and by source | `course_inquiries.view_reports` |
| `MonthlyAdmissionsChartWidget` | 12-month line | `admissions.view_reports` |
| `CourseWiseStudentsChartWidget` | bar, top 10 courses by active enrollment | `students.view_reports` |
| `AttendanceTrendChartWidget` | daily percentage over the range | `student_attendance.view_reports` |
| `CollaboratorReferredStudentsWidget` | students with a current referral, by collaborator | `collaborator_referrals.view_any` |

Fee collected, pending fees, overdue fees and student referral commission are §88 cards too, but they are
**the spine's widgets** (its §8.12) and Phase 18's `FeeCollectedTodayWidget` / `PendingFeesWidget` /
`OverdueFeesWidget`, and are not re-implemented here.

---

## 9. Data isolation

Every rule is an Eloquent **global scope** plus a **Policy** - never a hidden form field
(`CLAUDE.md` §1.10) - and every rule has a feature test asserting the HTTP status **and** the absence of
the forbidden columns from the response body.

Three scopes are introduced:

| Scope | Applied to | Rule |
|---|---|---|
| `BelongsToAuthenticatedStudent` | `StudentAdmission`, `StudentBatchEnrollment`, `StudentAttendance`, `StudentCourseProgress`, `StudentModuleProgress`, `StudentTopicProgress`, `ClassSession` (through the enrollment) | `where student_id = auth()->user()->student->id`, or for sessions `whereIn batch_id (my active enrollment batch ids)` |
| `BelongsToAssignedTeacher` (`TeacherScope`) | `Batch`, `ClassSession`, `StudentBatchEnrollment`, `StudentAttendance`, `BatchTopicCoverage`, progress models | `whereIn batch_id (TeacherScope::batchIds($teacher))` where `batchIds` = batches with `teacher_id = me` UNION batches of `timetable_entries.teacher_id = me` UNION batches of `class_sessions.teacher_id = me` (so a substitute sees exactly the session they were given, and keeps it afterwards for the attendance they marked) |
| `BranchScope` | `Student`, `Teacher`, `Classroom`, `Batch`, `CourseInquiry`, `StudentApplication`, `StudentAdmission`, `DemoClass`, `TimetableEntry`, `ClassSession`, `Course` | applied only when `users.branch_id` is set: `where branch_id IS NULL OR branch_id = :branch`. Bypassed for Super Admin |

| Role | Exact query scoping |
|---|---|
| **Super Admin** | unrestricted, still subject to module gating (a disabled module 403s Super Admin too, Phase 1 §6) |
| **Admin** | unrestricted within the permissions Phase 1 §5 grants; `BranchScope` applies if they have a branch |
| **Institute Manager** | every table in this phase, `BranchScope` applied. No access to any `collaborator_*` table except the referral **snapshot columns** rendered on a student row |
| **Course Coordinator** | as Institute Manager minus `view_financial`: the course fee columns, the admission money block and every fee figure are **omitted from the SELECT**, not just hidden in Blade |
| **Receptionist** | `course_inquiries`, `student_applications`, `demo_classes`: full within branch. `students` / `student_admissions`: create + read + edit within branch. `batches`: read + `assign`. `timetable`: read. No attendance write, no progress write, no money columns |
| **Sales Executive** | `course_inquiries` (own + unassigned by default: `where assigned_to = me OR assigned_to IS NULL`, widened to the branch only with `course_inquiries.view_any`), `demo_classes`, `courses` read, `batches` read |
| **Digital Marketer** | `course_inquiries` read/edit/export within branch; `student_applications` read. No student, admission, attendance or money access |
| **Accountant** | read on `students`, `student_admissions` (money columns need `admissions.view_financial`), `courses`, `batches`. No write anywhere in this phase; attendance and progress are 403 |
| **HR** | `teachers` read (salary only with `teachers.view_financial`), to reconcile with `employees`. Everything else 403 |
| **Teacher** (panel) | `BelongsToAssignedTeacher` everywhere. A batch, session, enrollment, attendance or progress row outside the scope returns **404, not 403**, so ids cannot be probed. The student payload is `StudentDirectoryService::forTeacher()` - no CNIC, address, guardian phone, referral or money column. Zero access to inquiries, applications, admissions, fees, collaborators |
| **Student** (panel) | `BelongsToAuthenticatedStudent` everywhere; another student's id is **404**. The SELECT list omits `collaborator_id`, `referral_code`, `referral_source`, `referral_date`, every admission money column except their own fee summary (served by Phase 18), and the panel never lists classmates - only a count (§112) |
| **Collaborator** | **403 on every route in this phase.** §57's referred-student list is `StudentDirectoryService::forCollaborator()` inside the collaborator panel (the spine's screen). Its scope is an **`active` `collaborator_referrals` row** (`subject_type = Student`, `collaborator_id = mine`) and nothing else: `students.collaborator_id` is a display snapshot and is **never** a scope (**D37**, resolutions R5). Each column is gated by its `collaborator_portal.*` permission |
| **Client** | 403 on every route in this phase |
| **Guest (public)** | only `site.*` routes; only `status = published` courses, `is_active` categories, active outline nodes, `is_public` resources, `is_public` teachers, and batches that pass `upcomingForPublic()`. A draft, archived or soft-deleted course is **404**, never a 403 that confirms it exists |
| **Module gating** | disabling `courses`, `course_outline`, `students`, `admissions`, `batches`, `timetable`, `student_attendance`, `student_progress`, `teachers`, `classrooms`, `course_inquiries`, `student_applications` or `demo_classes` 403s those routes for everyone through Phase 1's `Gate::before`, hides the sidebar items and the dashboard widgets, and **leaves every row intact** |

---

## 10. Events, notifications, jobs, scheduled tasks

### 10.1 Events (all dispatched through `DB::afterCommit()`)

`CourseCreated`, `CoursePublished`, `CourseUnpublished`, `CourseArchived`, `CourseOutlineChanged`,
`CourseInquiryReceived`, `CourseInquiryStatusChanged`, `CourseInquiryAssigned`, `FollowUpLogged`,
`StudentApplicationSubmitted`, `StudentApplicationConverted`, `StudentApplicationRejected`,
`StudentCreated`, `StudentStatusChanged`, `StudentRegistered`, `StudentActivated`,
`AdmissionCreated`, `AdmissionStageChanged`, `AdmissionCancelled`, `AdmissionWithdrawn`,
`StudentEnrolledInBatch`, `StudentTransferredBatch`, `StudentDroppedFromBatch`,
`BatchCreated`, `BatchStatusChanged`, `BatchNearCapacity`, `BatchFull`,
`TimetableEntryCreated`, `TimetableEntryChanged`, `TimetableEntryEnded`,
`ClassSessionsGenerated`, `ClassSessionCancelled`, `ClassSessionRescheduled`, `ClassSessionSubstituted`,
`ClassSessionHeld`, `AttendanceMarked`, `AttendanceAmended`, `TopicCoveredForBatch`,
`CourseProgressUpdated`, `DemoClassScheduled`, `DemoClassRescheduled`, `DemoClassOutcomeRecorded`,
`DemoClassConverted`, `TeacherEmployeeLinked`, `TeacherEmployeeUnlinked`, `TeacherStatusChanged`.

**Listeners this phase owns:** `SyncTeacherFromEmployee` (on Phase 7's `EmployeeUpdated`),
`StampAdmissionFeeCaches` (on Phase 18's `StudentFeeIssued` / `StudentFeePaymentRecorded` /
`PaymentReversalRecorded` - the only writer of the four admission money caches and of
`figures_locked_at`), `RecountBatchOnEnrollmentChange`, `OpenProgressOnEnrollment`,
`FanOutCoverageOnSessionHeld`, `AdvanceInquiryOnDemoOutcome`. The §37 student snapshot columns are
maintained by **Phase 9's** `SyncReferralSnapshot`, not here (INV-I3).

### 10.2 Queued jobs

| Job | Key properties |
|---|---|
| `GenerateClassSessions` | `ShouldBeUnique` (`sessions:batch:{id}`, `uniqueFor` 600), `$afterCommit = true`; idempotent by `uq_cs_generated`; chunked per batch |
| `RecountBatchStudents` | unique (`batch-recount:{id}`); the INV-I7 repair path |
| `RecountEnrollmentAttendance` | unique per enrollment; dispatched after marking, amending, a session cancellation and a transfer |
| `RecomputeStudentProgress` | unique per enrollment; dispatched after coverage, a manual mark and an outline change |
| `RecomputeCourseProgressForCourse` | chunks enrollments 200 at a time after an outline change |
| `DetectDuplicateApplications` | on submit, compares the fingerprint inside the window and sets `duplicate_of_application_id` |
| `NotifyBatchRoster` | one job per session-cancelled / rescheduled event, fanning notifications to the roster |
| `SendDemoClassReminder` | per demo, scheduled for 2 hours before; stamps `reminder_sent_at` so it cannot fire twice |

### 10.3 Notifications (database channel now, mail-ready - §97)

| To | Notifications |
|---|---|
| Student | `AdmissionConfirmed`, `RegistrationNumberIssued`, `BatchAssigned`, `StudentAccountCreated` (with the temporary password, never logged), `ClassCancelled`, `ClassRescheduled`, `LowAttendanceWarning` (below `attendance_minimum_percentage`), `DemoClassScheduled`, `DemoClassReminder` |
| Teacher | `BatchAssignedToTeacher`, `TimetableChanged`, `SessionSubstitutionAssigned`, `AttendanceNotMarked` (the evening nudge), `DemoClassAssigned` |
| Staff | `NewCourseInquiry` (to the assignee and to holders of `course_inquiries.view_any`), `NewAdmissionApplication` (to `student_applications.view_any`), `BatchNearCapacity` and `BatchFull` (to `batches.view_any`), `StaleInquiriesDigest` (daily, to the assignee), `UnmarkedAttendanceDigest` (to `student_attendance.view_reports`), `TimetableClashDetected` (to `timetable.view_any` when the verifier finds one) |
| Collaborator | `ReferredStudentAdmitted` - only when the student has a current referral and only to holders of `collaborator_portal.students`. It carries the student name, course and admission date and **no money**: commission notifications are the spine's |

### 10.4 Scheduler

| Command | Cadence | Purpose |
|---|---|---|
| `institute:generate-sessions` | daily 00:20 | materialise `class_sessions` `institute.session_generation_weeks_ahead` ahead, for every `enrolling`/`running` batch; idempotent |
| `batches:advance-status` | daily 00:30 | `enrolling -> running` when `start_date` is reached and an active enrollment exists; `running -> completed` when `end_date` passed and every session is terminal |
| `batches:recount-students` | daily 01:40 | the INV-I7 proof; logs and notifies on any drift |
| `attendance:flag-unmarked` | daily 20:00 | sessions `held`/past with `attendance_marked_at IS NULL`, notify the teacher and digest to staff; never marks anything |
| `attendance:recount` | daily 02:20 | recomputes the enrollment counters and percentages, reporting any row that differed (INV-I11) |
| `progress:recompute` | daily 02:40 | recomputes progress for batches whose coverage or outline changed in the last 24h |
| `inquiries:flag-stale` | daily 09:30 | inquiries open past `institute.inquiry_stale_days` - flag and digest, never auto-close |
| `inquiries:followup-reminders` | daily 08:30 | today's and overdue `follow_up_date` rows to their assignees |
| `demo-classes:flag-unmarked` | hourly | demos whose end time passed 24h ago and are still `scheduled` - flag for a human, never auto-mark |
| `demo-classes:remind` | hourly | dispatch `SendDemoClassReminder` for the next two hours |
| `timetable:verify-clashes` | daily 02:50 | brute-force overlap scan over active entries, sessions and demos; notifies on any clash that exists despite §6.7 (a seeder, an import or raw SQL) |
| `institute:verify-constraints` | daily 03:00 | asserts every unique index, CHECK and generated column of §2 still exists, in the spine's discipline; alerts loudly if one vanished |

---

## 11. Acceptance tests

`tests/Feature/Institute/`. Factories exist for every table. Each test names its assertion; every
authorization test asserts the HTTP status **and** that nothing was written.

**Courses, categories, outline (Phase 14)**

1. **FT-01** `test_category_reorder_accepts_any_permutation` - reversing 5 categories writes 5 rows in one transaction and reads back in the new order; ids outside the set are rejected 422.
2. **FT-02** `test_disabling_category_does_not_touch_courses` - course statuses identical before and after; with `cascade_courses` the published ones become `draft` and one activity row per course is written.
3. **FT-03** `test_category_with_courses_cannot_be_deleted` - 422 naming the count; the row survives.
4. **FT-04** `test_course_publish_requires_completeness` - publishing without a module, a fee or a category fails and names the missing field; archiving is refused while a batch is `enrolling`.
5. **FT-05** `test_course_code_and_slug_are_unique_and_slug_locks_after_publish` - duplicate code 422; slug change after publish requires a reason and writes an audit row with old and new.
6. **FT-06** `test_course_duplicate_copies_tree_but_no_student_data` - modules/topics/lectures/resources/blueprints counts match and the copy's `faqs` (`faqable`) rows match the original's, the copy is `draft`, and zero batches, enrollments, admissions or fee rows are created.
7. **FT-07** `test_outline_is_three_levels_and_cross_course_moves_are_rejected` - a lecture cannot take a `course_module_id`; a reorder payload containing another course's ids returns 403 and writes nothing (INV-I12).
8. **FT-08** `test_referenced_topic_cannot_be_deleted_but_can_be_deactivated` - delete 422 naming the coverage/progress references; deactivate succeeds and every affected progress percentage is recomputed (INV-I13).
9. **FT-09** `test_resource_upload_validates_by_content` - a `.php` renamed to `.pdf` is refused, an oversized file is refused, a URL-only resource is accepted, and the stored filename is hashed.
10. **FT-10** `test_fee_columns_require_view_financial` - index, show and form responses contain no fee figure without `courses.view_financial`, and a POSTed fee from such a user is ignored (the stored values are unchanged).

**Inquiries, applications, the public form (Phase 15)**

11. **FT-11** `test_public_inquiry_is_idempotent_and_throttled` - the same `idempotency_key` twice creates one row; a filled honeypot is silently dropped; the sixth submission in a minute is 429.
12. **FT-12** `test_follow_up_updates_counters_and_status` - `contact_attempts`, `last_contacted_at` and `follow_up_date` move; `FollowUpOutcome::NotInterested` drives the inquiry to `not_interested` and demands `lost_reason`.
13. **FT-13** `test_application_validation_and_duplicate_flagging` - a batch from another course is 422; a full batch is 422; a second application with the same fingerprint inside the window is accepted and flagged, not blocked; with `admission_form_enabled` off the route renders the closed page.
14. **FT-14** `test_application_conversion_is_one_transaction_and_idempotent` - a forced failure after student creation leaves **zero** students and zero admissions; re-running a converted application returns the same admission and creates nothing.

**Admissions, numbering, the money boundary, referral (Phase 15)**

15. **FT-15** `test_admission_creates_no_money_rows` - after `createFromApplication` and `register`, the row counts of `student_fees`, `student_fee_installments`, `student_fee_discounts`, `student_fee_payments` and `collaborator_commission_ledger_entries` are all zero (INV-I1).
16. **FT-16** `test_fee_collection_is_delegated` - `requestFees` produces charges **only** through `StudentFeeService` (asserted with a spy); no query from `App\Services\Institute\*` inserts into a `student_fee*` table (asserted with `DB::listen`).
17. **FT-17** `test_locked_admission_figures_cannot_move` - `updateFigures` before any charge succeeds and audits old/new; after `figures_locked_at` it throws `AdmissionFiguresLocked` and the row is byte-identical (INV-I2).
18. **FT-18** `test_referral_snapshot_is_not_written_by_this_phase` - after `ReferralAttached`, `students.collaborator_id` / `referral_code` / `referral_source` / `referral_date` / `referral_visit_id` match the referral row **because Phase 9's `SyncReferralSnapshot` ran**; a direct mass-assignment of those columns through `StudentService::update` is ignored; no listener in `App\Listeners\Institute\*` writes them (INV-I3).
19. **FT-19** `test_valid_referral_attaches_on_conversion_only` - the application stores the resolved code with `referral_code_valid = true` and **no** `collaborator_referrals` row exists until `convert()`; after conversion exactly one active referral exists with `source = admission_form` and the `ReferralContext` fields (`referral_visit_id`, `landing_url`, IP, UA) populated (INV-I4).
20. **FT-20** `test_hostile_referral_input_attaches_nobody` - a forged or expired visit token, an unknown typed code, a code of a `Pending`/`Suspended`/soft-deleted collaborator, a self-referral, and a posted `collaborator_id` each result in `collaborator_id = null`, `referral_code_valid = false`, the code stored verbatim, and zero referral rows; the application is still accepted (INV-I4, Phase 9 INV-R2).
21. **FT-21** `test_student_code_is_unique_under_concurrency` - 100 parallel creations yield 100 distinct codes; switching the period (year) resets the counter to 1 and rewrites `institute.student_id_period`; a forced 1062 retries once and succeeds (INV-I5).
22. **FT-22** `test_registration_number_is_issued_once_at_registration` - null before `register()`, set and unique after, unchanged by later updates, and two unregistered students coexist.
23. **FT-23** `test_pipeline_steps_cannot_be_skipped` - `activate()` on an `application`-stage admission throws `InvalidStatusTransition`, names the missing step, and changes no row.
24. **FT-24** `test_activation_honours_the_fee_precondition` - with `require_fee_before_activation = any_payment`, activation fails until a receipt exists; with `full_first_installment` it fails until the first installment is settled; with `none` it passes. The test reads Phase 18 rows, never writes them.
25. **FT-25** `test_one_live_admission_per_student_per_course` - a second live admission is refused by `uq_sadm_live`; after the first is `completed` or `cancelled`, a new one is accepted.

**Batches, enrollment, capacity (Phase 16)**

26. **FT-26** `test_capacity_is_enforced_and_overbooking_is_explicit` - enrolling into a full batch throws `BatchCapacityExceeded` and writes nothing; with `batch_allow_overbooking` off even `batches.assign` cannot override; with it on plus a reason the row is created with `is_overbooked = true` and the reason stored and logged (INV-I6).
27. **FT-27** `test_concurrent_enrollment_cannot_exceed_capacity` - two simultaneous requests for the last seat: exactly one 200, one `BatchCapacityExceeded`, `current_students` equals the active count (INV-I6).
28. **FT-28** `test_current_students_is_a_recomputable_cache` - after enroll, transfer, drop, suspend and reinstate the cache equals `COUNT(active)`; corrupting it by hand and running `batches:recount-students` restores it and reports the drift (INV-I7).
29. **FT-29** `test_transfer_moves_the_right_things` - old row `transferred_out` with reason, new row linked both ways, attendance rows still point at the old enrollment, progress percentages carry over, both batches recount, `StudentFeeService::reassignBatch` is called exactly once for a same-course move (and `transferPayment` for a course change), no `student_fee*` row is written by this phase's code, and a transfer without a reason is 422.
30. **FT-30** `test_duplicate_active_enrollment_is_impossible` - the second enroll of the same student in the same batch returns "already enrolled" and creates nothing; after a drop, re-enrollment succeeds and both rows exist.

**Timetable, clash detection, sessions (Phase 16)**

31. **FT-31** `test_teacher_double_booking_is_refused` - an overlapping slot on the same weekday with overlapping effective windows is 422 naming the conflicting batch and slot; a non-overlapping window is accepted (INV-I8).
32. **FT-32** `test_classroom_double_booking_is_refused` - same for a room; an `online` entry with no classroom and an entry in a `virtual` room never clash (INV-I8).
33. **FT-33** `test_batch_double_booking_can_never_be_overridden` - even with `timetable.change_status` and a reason, a batch clash is refused (INV-I8).
34. **FT-34** `test_overlap_is_half_open_and_honours_the_gap_setting` - 09:00-10:00 and 10:00-11:00 coexist; with `timetable_slot_gap_minutes = 15` the second is refused for the teacher and room dimensions but still allowed for a different teacher and room (INV-I8).
35. **FT-35** `test_concurrent_timetable_writes_serialise` - two simultaneous overlapping slots for one teacher: exactly one succeeds (parent row lock), and the exact-duplicate unique index catches an identical pair even if the detector is stubbed out (INV-I8).
36. **FT-36** `test_session_generation_is_idempotent_and_bounded` - running `generate` twice produces one session per date; nothing before `start_date`, after `end_date`, after `effective_to`, or for an `on_hold` batch; `sequence_no` is contiguous.
37. **FT-37** `test_session_lifecycle` - cancelling notifies the roster and refuses later attendance; rescheduling links both rows and clash-checks the new slot; substituting stores `original_teacher_id` and notifies both teachers; a cancel after attendance exists is refused.
38. **FT-38** `test_only_teaching_teachers_can_be_scheduled` - a `resigned` teacher is refused on an entry; moving an active teacher to `resigned` is refused while `scheduled` sessions exist, and the error names them.

**Attendance and progress (Phase 17)**

39. **FT-39** `test_attendance_requires_a_roster_membership` - marking a student with no active enrollment on that date is 422; a student enrolled on the 10th does not appear in (and cannot be marked for) a session held on the 3rd (INV-I9).
40. **FT-40** `test_attendance_cannot_be_double_marked` - submitting the same roster twice yields one row per student with the later values, and the session counters match the rows (INV-I9).
41. **FT-41** `test_attendance_amendment_is_locked_and_audited` - inside `attendance_lock_hours` a change needs only `create`; after it, a user without `student_attendance.edit` gets 403, a change without a reason is 422, and a valid amendment stamps `amended_at`/`amended_by`/`amendment_reason` and logs old and new status (INV-I10).
42. **FT-42** `test_grace_and_auto_absent_behaviour` - a `present` mark with a check-in past `attendance_grace_minutes` is stored as `late` with `minutes_late`; marking a session held fills unmarked students as `absent` with `marked_via = system` **only** when `attendance_auto_absent_on_close` is on.
43. **FT-43** `test_attendance_percentage_is_exact_and_recomputable` - cancelled and rescheduled sessions are excluded from both sides; `leave` enters the denominator only with the setting on; `2/3` stores `66.6700` in the `decimal(8,4)` column (computed half-up at 2, never a float - INV-I11 and `CLAUDE.md` §3's one percentage width); `attendance:recount` reproduces every stored value bit for bit (INV-I11).
44. **FT-44** `test_progress_rolls_up_and_respects_manual_marks` - marking a topic for a batch sets every active enrollee's topic row; a row with `source = manual` is not overwritten; skipping a topic removes its weight so the percentage **rises**; deactivating a topic recomputes; module and course percentages agree with the topic rows under both `topic_count` and `topic_weight` weighting; a course with no outline reports `0.00` and never divides by zero (INV-I11).

**Audit, authorization, isolation**

45. **FT-45** `test_every_reason_mandatory_transition_demands_a_reason` - a table-driven test over course archive, inquiry `not_interested`, application reject, student drop/suspend, admission cancel/withdraw, enrollment drop/suspend/transfer, batch on-hold/cancel, session cancel/reschedule/substitute, topic skip, attendance amend, teacher link/unlink and referral change: each is 422 without a reason and each success writes an activity row carrying old value, new value, actor, IP and the reason (INV-I14).
46. **FT-46** `test_authorization_matrix` - for every route in §7, a user holding no permission gets 403 (panel routes 404 where §9 says so) and the database is unchanged; granting exactly the named permission makes it 200/302.
47. **FT-47** `test_module_gating_blocks_everyone_and_preserves_data` - disabling `courses`, `batches`, `timetable`, `student_attendance` and `student_progress` 403s their routes for Super Admin, hides the sidebar items and the dashboard widgets, and leaves row counts identical after re-enabling.
48. **FT-48** `test_teacher_sees_only_assigned_batches` - another teacher's batch, session, roster, attendance and progress are 404; a substitution grants access to that one session and to the attendance marked for it, and to nothing else; the roster payload contains no CNIC, address, guardian phone, referral or money field (INV-I15).
49. **FT-49** `test_student_sees_only_own_data` - another student's enrollment, attendance and progress are 404; the response body contains no `collaborator_id`, no commission column, no classmate list and no other student's name; a student hitting any `/admin`, `/teacher`, `/collaborator` or `/client` route is 403 (INV-I15).
50. **FT-50** `test_collaborator_cannot_reach_institute_modules` - every route in §7.1-7.7 is 403 for a collaborator; `StudentDirectoryService::forCollaborator` returns only students with a current referral to that collaborator, omits contact and money columns without the matching `collaborator_portal.*` permissions, and a referral revoked yesterday removes the student from the list while leaving history intact.
51. **FT-51** `test_client_and_cross_panel_isolation` - a client, and every other panel role, gets 403 on every institute route; a teacher is 403 on fee, commission and payout routes.
52. **FT-52** `test_branch_scope_applies_everywhere` - a user with `branch_id = 2` sees only branch-2 and global rows in students, batches, sessions, attendance, reports and exports; a branch-1 id is 404; Super Admin sees all.

**Public surface, performance, migrations**

53. **FT-53** `test_public_pages_expose_only_published_content` - the catalogue lists only `published` courses in `is_active` categories; a draft, archived or trashed course is 404; inactive outline nodes, non-public resources and non-public teachers are absent from the HTML; a full batch is absent from "upcoming batches".
54. **FT-54** `test_referral_survives_the_whole_public_journey` - `/courses?ref=COL-1024` then `/courses/php-laravel` then "Apply now" lands on `/admission` with the attribution present via the session **and** (tested separately, session cleared) via the cookie, even when the `ref` query parameter is stripped from the link; every Apply/WhatsApp href on both pages contains the code; submitting the token-bearing form produces an application with `referral_code_valid = true`; conversion creates exactly one `collaborator_referrals` row; and the course pages are **never served from Phase 3's response cache with another visitor's referral chip** (the admission route is uncached and the chip is rendered per request).
55. **FT-55** `test_public_gates` - `maintenance.public_site_enabled` off returns Phase 3's holding page for `/courses` but `/admin` still works; `maintenance.admission_form_enabled` or `institute.admission_open` off renders the closed page and 422s the POST, while a user holding `admissions.create` still sees the form with the amber ribbon; the thank-you URL without a valid signature is 403 and an application number cannot be enumerated.
56. **FT-56** `test_query_budgets` - with `DB::listen`, the course landing page, the weekly timetable view, a 40-student batch roster, a monthly attendance matrix and the batch progress board each stay under their asserted query count (no N+1 across students, sessions or topics).
57. **FT-57** `test_migrations_and_constraints` - every migration runs forward and rolls back cleanly; a schema assertion verifies each unique index, CHECK constraint and generated column of §2 exists by name; inserting a row that violates `chk_sadm_discount_ceiling`, `chk_cr_*`, `chk_dc_one_subject` or a percentage CHECK raises a database error rather than being silently accepted.
58. **FT-58** `test_seeders_are_idempotent_and_demo_data_is_coherent` - running the institute seeders twice changes no counts; the demo dataset (§116) produces courses with outlines, published landing pages, batches with timetables and generated sessions, students with admissions in every stage, attendance for past sessions, progress rows that match their coverage, and at least one collaborator-referred student whose referral is a real `collaborator_referrals` row.

---

## 12. Risks and open questions

### 12.1 Risks accepted, with the mitigation

| # | Risk | Mitigation |
|---|---|---|
| R-1 | **Overlap cannot be a database constraint.** A clash can in principle be inserted by a seeder, an import or raw SQL. | The service path is the only write path in the app (`ScheduleClashDetector` under parent row locks); three exact-duplicate unique indexes catch identical submissions; `timetable:verify-clashes` reports any overlap nightly; FT-35 proves the concurrency case |
| R-2 | **Capacity is a recount, not a constraint.** A crafted concurrent load could in theory squeeze past. | `lockForUpdate` on the batch row plus a recount (never the cache) inside the transaction serialises every enrollment for that batch; FT-27 asserts it; `batches:recount-students` proves the cache nightly |
| R-3 | Five cached percentages and six cached counters can drift from their source rows. | Every cache has exactly one writer service, a recount command, a nightly scheduled proof and a test that corrupts then repairs it (FT-28, FT-43) |
| R-4 | `class_sessions` grows fast - 3 sessions/week x 40 weeks x 50 batches is ~6,000 rows a year, each with ~20 attendance rows. | Every report query is covered by a composite index listed in §2.23 / §2.24; generation is bounded by `session_generation_weeks_ahead`; the matrix reports are per batch per month, never unbounded; query budgets are asserted (FT-56) |
| R-5 | The admission wizard spans three phases (15 for the record, 18 for charges, 10 for receipts and commission). A half-built Phase 18 would strand step 4. | The stage machine allows 5 and 6 in either order and the wizard renders step 4 as a handover panel that degrades to "fee module not available" without blocking registration or batch assignment |
| R-6 | The public admission form is an unauthenticated write endpoint - the classic spam and enumeration target. | Honeypot + minimum fill time + `throttle:5,1` per IP + a daily per-phone cap + an idempotency key + a signed thank-you URL + no id in any response + server-side resolution of every reference (course, batch, referral) |
| R-7 | `net_payable` as the spine's collectible denominator includes the admission and registration fee heads even when those heads are not commissionable, so a prorated fixed commission releases slightly more slowly than a purist would expect. | `course_fee_net_payable` is provided as a generated column and offered to Phase 10 (§13); the behaviour is recorded here so a reviewer can see it was a decision, not an accident |
| R-8 | One document for four phases risks being built out of order (e.g. attendance before batches). | §1.3's ownership table plus four separate migration batches; every FK to a later-phase table is in a `Schema::hasTable()`-guarded migration |
| R-9 | Teacher access via substitution widens `TeacherScope` permanently for that session. | Deliberate: the teacher who marked a register must be able to see and amend it. Scoped to the single session, never the batch; FT-48 asserts the boundary |
| R-10 | `students.status`, `student_admissions.stage` and `course_inquiries.status` are three state columns describing one journey. | §2.31 is the single mapping table, `AdmissionService` is the only writer of the triple, and FT-23 asserts no step can be skipped |

### 12.2 Open questions for the client (defaults assumed, nothing blocked)

| # | Question | Default assumed |
|---|---|---|
| Q1 | Should course categories **nest** (sub-categories)? | No - §64 asks for a flat, reorderable list. Adding `parent_id` later is an additive migration plus a recursive renderer |
| Q2 | Is the public admission form an **enquiry** (staff convert it) or a **self-service enrolment**? | An enquiry: it creates a `student_applications` row and never a student, a login or a fee ([D-IN-7]), consistent with D15 |
| Q3 | When is the **registration number** issued - at admission or at registration? | At registration (§68's step 4), which is what makes it different from `student_code`. Format and counter scope are settings |
| Q4 | Does the institute want a **Kanban board** for inquiries or the admission pipeline? | No board; §86 asks for statuses and §18 is where the requirement asks for a Kanban. The inquiry index ships status tabs with counts instead ([D-IN-16]) |
| Q5 | May a batch be **overbooked**, and by whom? | Not by default (`institute.batch_allow_overbooking = false`); when enabled it needs `batches.assign` plus a reason, and the enrollment is flagged forever |
| Q6 | Does `leave` count against a student's attendance percentage? | No (`attendance_leave_counts_in_denominator = false`) - an approved leave should not punish the student. One setting flips it |
| Q7 | Should marking a class held **auto-mark** unmarked students absent? | No (`attendance_auto_absent_on_close = false`) - silently creating absences is how disputes start. One setting flips it |
| Q8 | Is course progress driven by the **class** (teacher ticks a topic for the batch) or per student? | Class-driven with per-student override, because a batch progresses together ([D-IN-10], §6.10); a manual mark is never overwritten by a class mark |
| Q9 | Are topics **equally weighted** for the completion percentage, or weighted? | Weighted (`progress_weighting = topic_weight`, every topic's weight defaulting to 1, which reproduces equal weighting until someone changes a weight) |
| Q10 | Should a **demo class** have a `cancelled` status, which §87 does not list? | Yes ([D-IN-11]) - the alternative is deleting the booking or lying with `missed` |
| Q11 | Must a teacher be linked to an **employee** record? | No (§72 says "may"); the link is optional and one-way, and an unlinked visiting trainer is a first-class record ([D-IN-9]) |
| Q12 | Do **branch-specific courses** exist, or is the catalogue shared? | Shared: `courses.branch_id` is nullable and null means every branch. No branch switcher UI in these phases (D11) |
| Q13 | How far ahead should the timetable materialise **dated sessions**? | 8 weeks (`session_generation_weeks_ahead`), regenerated nightly; a longer horizon only costs rows |

---

## 13. Requests to other phases

Stated as `table.column - why`, plus the behavioural asks.

### 13.1 Columns and tables this phase needs

| Request | Why |
|---|---|
| `EmployeeUpdated` (Phase 7 §10) must carry the changed attributes, and `employees.name`, `.phone`, `.email`, `.photo_path`, `.joining_date` must be readable through the model - Phase 7 | the one-way `SyncTeacherFromEmployee` listener ([D-IN-9]); if a field is named differently the listener maps it in one place |
| Phase 7's request that **`teachers.salary` be display-only** is accepted as written: it is NULL whenever `employee_id` is set, and a linked teacher's pay comes from the employee's salary structure (§2.17) | two salary figures for one person is how a payroll dispute starts |
| A Phase 7 **policy** blocking the deletion of an employee linked to a teacher with batches | my FK is `nullOnDelete`, which would silently orphan the link |
| `contact_inquiries.id` and `App\Enums\InquirySource` (11 cases) - **Phase 4**, which owns both | the nullable guarded FK behind `course_inquiries.contact_inquiry_id` + `uq_ci_inquiry` (F-3.8), and the single source enum `course_inquiries.source` casts to (F-5.3). Phase 4's `InquiryRouter` / `InquiryTarget` is the only caller that supplies the id (F-2.1) |
| `collaborators.id`, `.status`, `.referral_code`, `.name`, `.deleted_at` - Phase 8 | the referral snapshot columns and the "is this code usable" verdict |
| `ReferralAttributionResolver`, `ReferralLinkService::tokenForForm()` / `::displayCode()`, `<x-site.referral-field>`, the `CaptureReferral` middleware - Phase 9 | §6.4 and §6.13 delegate to all of them; this phase implements no precedence ladder of its own (Phase 9 INV-R2, INV-R3) |
| `ReferralService::resolveCode(string $code): ?ReferralResolution` - Phase 10 | INV-I4: the only code-to-collaborator path |
| **Satisfied.** `ReferralService::attach($subject, Collaborator $c, ReferralSource $source, ?string $code, string $on, ?ReferralContext $context = null): CollaboratorReferral` - the sixth parameter and `App\DataObjects\Collaborator\ReferralContext` (readonly `?int $referralVisitId`, `?string $landingUrl`, `?string $ipAddress`, `?string $userAgent`, `?CarbonInterface $referralDate`, `?string $notes`) are now in **spine §6.2 / §13.2** and phase-10-12 §6.3 (F-4.3) | called once, inside the conversion transaction, and it fills `referral_visit_id`, `landing_url`, `ip_address`, `user_agent` from the application row ([D-IN-13]). §6.4 passes a `ReferralContext`; no evidence column is written by this phase |
| Phase 9's `SyncReferralSnapshot` must also maintain **`students.referral_visit_id`** (it is in Phase 9's own §13.1 list, so this is a confirmation, not a new ask) | INV-I3; the column is defined in §2.14 |
| `collaborator_referral_visits.id` - Phase 9 | nullable guarded FK on `student_applications.referral_visit_id`, `students.referral_visit_id` **and `course_inquiries.referral_visit_id`** (F-3.14 - Phase 9 §13.1 asked for all three; the third is now delivered in §2.11) |
| **Satisfied - no ask.** `App\Services\Finance\DocumentNumberService::reserve(string $counterKey, ?string $periodKey = null, ?string $periodValue = null): int` and `::next(string $prefixKey, string $counterKey, string $pad = '%06d'): string` are shipped by **Phase 5** (phase-05 §6.10), not Phase 10 | §6.5 needs a counter reservation with a **period reset**; `reserve()` provides it on the same class (**D27**, F-4.1, F-4.12). The class exists four phases before this one, so `StudentNumberService` simply calls it and passes its own pad. The earlier "if Phase 10 declines, implement the `FOR UPDATE` logic locally" fallback and its tech-debt note are **withdrawn**: a second counter implementation is forbidden |
| `StudentFeeService::generateStructure(StudentAdmission $a, FeeStructureData $d): FeeStructureResult` - Phase 18 (already published in its §6.1) | the admission wizard's step 4; this phase must never insert a charge (INV-I1) |
| **Satisfied.** `StudentFeeService::summaryFor(Student\|StudentAdmission $subject): FeeSummary` - **published in Phase 18 §6.1**, with `App\DataObjects\Institute\FeeSummary` (readonly gross, discount, scholarship, net, paid, refunded, balance, status, nextDueDate) in phase-18 §13.2 (F-4.6) | the fee chips on the student index, the batch roster, the admission stepper, the student panel and the §57 collaborator list - so nothing here ever sums a payment |
| **Satisfied.** `StudentFeeService::reassignBatch(StudentBatchEnrollment $from, Batch $to): int` - **published in Phase 18 §6.1** (F-4.6); it repoints `student_fees.batch_id` only and writes no money | a **same-course** batch transfer must repoint `student_fees.batch_id` (spine §2.2: "a batch transfer updates this and nothing financial"); a **course** change uses `transferPayment()`, which this phase calls per Phase 18 §13.3. This phase writes neither column |
| **Satisfied.** `StudentFeeService::withinServiceContext(): bool` and `::outstandingFor(StudentAdmission\|StudentBatchEnrollment $subject): string` - **published in Phase 18 §6.1** (F-4.6) | the guard the `student_admissions` model uses to reject a cache write from anywhere else, and the one outstanding-balance figure this phase renders without recomputing it |
| A Phase 18 listener (or service step) stamping `student_admissions.figures_locked_at`, `charged_amount`, `paid_amount`, `refunded_amount`, `balance_amount` - Phase 18 | INV-I2 and §69's "paid / pending" fields. The columns are defined here; Phase 18 owns the writes |
| Phase 10 to confirm whether the commission denominator should be `student_admissions.net_payable` (as contracted) or the generated **`course_fee_net_payable`** when `commission_on_admission_fee` / `commission_on_registration_fee` are false | R-7: both columns exist, so this is a one-line change in `CommissionBaseResolver` - but it must be a decision, not a default |
| `assignments.course_topic_assignment_id` nullable FK, `assignments.course_topic_id` nullable FK - Phase 19 | so a teacher instantiates a §65 blueprint for a batch instead of retyping it ([D-IN-6]) |
| `course_materials.course_topic_id` nullable FK and `course_materials.batch_id` - Phase 19 | so §79 material can hang off the outline the student is already reading, and be scoped to a batch |
| Phase 20 to write `student_topic_progress` only through `CourseProgressService::markTopicForStudent(..., source: assessment)` | INV-I11: one writer per cache. `ProgressSource::Assessment` is reserved for it |
| Phase 21 to read `courses.certificate_available`, `student_batch_enrollments.attendance_percentage`, `student_course_progress.completion_percentage` and `institute.attendance_minimum_percentage` for eligibility | avoids a second attendance or progress calculation (§99's rule) |
| Phase 3: a `seo_meta` row keyed `route_key = site.courses.index` (its §6 already anticipates that key), `SitemapRegistry` registration for published courses and active categories, and a **public-cache invalidation hook** this phase can call on course publish / outline change | [D-IN-15]; Phase 3's `site`, `site.preview` and `site.cache` aliases are reused unchanged |
| Phase 3 to embed the course carousel and the upcoming-batch strip through `PublicCourseService` rather than querying `courses` / `batches` directly | one read model for the public site, so "published and open" is defined once (§6.2) |
| Phase 4's `student_reviews` to carry `course_id` and an approved scope | §90's landing page shows per-course reviews |
| Phase 22 to implement the notification classes of §10.3 on its channels | §97 |
| Phase 23 reports: every institute report must call `AttendanceReportService`, `CourseProgressService` and `StudentDirectoryService` and must never re-implement a percentage or a roster | INV-I11, §9 column gating |
| `branches.id` - Phase 1 | nullable `branch_id` per §2.2 (D11) |

### 13.2 Support classes

| Request | Why |
|---|---|
| **Satisfied by phase-01 §3.** `App\Support\Money` publishes one canonical surface - `add`, `sub`, `mul`, `div`, `percentage`, `percentageOf`, `compare`, `isZero`, `isNegative`, `abs`, `min`, `max`, `sum`, `round($v, $scale = 2)`, `roundTo`, `prorate`, `distribute`, `toMinor`, `fromMinor`, `format` - bcmath only, intermediate scale 6, final half-up at 2, strings in and out, never a float (F-4.11) | every percentage in §6.9 and §6.10 is defined in terms of `percentageOf()` and `round()` (INV-I11); this phase requests no addition |
| `tests/Support/index-manifest.php` (Phase 1 / Phase 24): **every FK index named in §2 has a row in it** - including §2.11's `contact_inquiry_id`, `referral_visit_id`, `converted_application_id`, `converted_student_id` and §2.15's `student_application_id`, `course_inquiry_id` (F-9.2) | `audit:manifest --check` is part of this phase's definition of done (**D60**) |
| `App\Support\PermissionRegistry` (Phase 1): the two module slugs of §4.1, the ability additions of §4.2, the portal permissions of §4.3 | the registry is the only place permission names exist (D4) |
| `App\Support\SettingsRegistry` (Phase 2): the 37 keys of §5 inside the existing `institute` group, including the two `readonly` period markers | settings definitions in code, values in the DB |
| `App\Support\DashboardRegistry` (Phase 2): the twelve widgets of §8.21 | §88 |
| Phase 1's `Sidebar`: an **Institute** group with the entries of §7, each gated by module + permission | §6 of Phase 1 |
| A shared `x-ui.calendar-grid` component (day/week axes, slot rendering, clash outline) and an `x-ui.capacity-meter` | used by the timetable's five views, the demo calendar, the batch cards and the enrollment modal; if Phase 1 has not shipped them, this phase adds them to `components/ui/` |

### 13.3 Documentation and log updates

Decision numbers are **allocated by `docs/design/resolutions.md` §4**, which is the only authority; this
contract cites them and claims none (F-10.1). The four this phase contributes are **D45-D48**.

| Request | Why |
|---|---|
| `DEVELOPMENT_LOG.md` §4: **D45** - the §68 pipeline is carried by `student_admissions.stage`, with `students.status` and `course_inquiries.status` advanced in lockstep by `AdmissionService` only (§2.31) | R-10: three status columns, one writer |
| `DEVELOPMENT_LOG.md` §4: **D46** - attendance and syllabus coverage attach to a dated `class_sessions` row, never to a recurring timetable rule ([D-IN-10]) | the decision every later phase (exams, certificates, reports) depends on |
| `DEVELOPMENT_LOG.md` §4: **D47** - schedule overlap is enforced by `ScheduleClashDetector` (one generic `check(SlotCandidate)`) under parent row locks plus a nightly verifier, because MariaDB cannot express a range constraint (R-1) | so a reviewer does not "fix" it with a unique index that cannot work |
| `DEVELOPMENT_LOG.md` §4: **D48** - capacity is enforced by a locked recount, and `batches.current_students` is a cache with no authority (INV-I6, INV-I7) | the same reason |
| `DEVELOPMENT_LOG.md` §4: this phase additionally **cites** (and does not re-state) **D19** (soft-delete categories, §2 [D-IN-2]), **D27** (`DocumentNumberService` is Phase 5's, §6.5), **D32** (`teachers.employee_id` is a duty, §2.17) and **D37** (`collaborator_referrals` is the only attribution authority, INV-I3) | one number per decision; no contract invents one |
| `CLAUDE.md` §5: add "no institute screen, service or job creates, edits or deletes a money row; the admission record holds agreed figures and Phase-18-written caches only" (INV-I1, INV-I2) | the boundary that keeps the commission engine honest |
| `CLAUDE.md` §6: add "every public Apply / Inquire / WhatsApp link is built by `PublicCourseService::applyUrl()` / `whatsappUrl()`, never hand-written, so a referral is never dropped" (§6.13) | a hand-written href silently destroys a collaborator's attribution |
| `DEVELOPMENT_LOG.md` §5: split the Phase 14-17 tracker rows to match §1.3's ownership table, and note that this one contract covers all four | so a future session does not look for `phase-14.md` |

### 13.4 Reconciliations already absorbed from the sibling contracts

These were conflicts between this contract's first draft and the peer contracts published the same day.
Each is resolved **here**, so no other phase has to change. Listed so a reviewer can see the reasoning
rather than rediscovering the clash.

| Sibling ask | Resolution in this contract |
|---|---|
| Phase 18 §13.1 asks for `student_admissions.status` | the column is `stage` (`AdmissionStage`, §2.31) because `students.status` already exists; the model exposes a `status` accessor/mutator and a `scopeLive()` alias, so Phase 18's published code compiles unchanged ([D-IN-17]) |
| Phase 18 §13.1 asks for `students.registration_no` | the column is `registration_number` (§66's own words); a `registration_no` accessor is provided |
| Phase 18 §13.1 asks for `courses.duration` | the columns are `duration_value` + `duration_unit` (a bare "duration" cannot be printed or compared); a `duration` accessor returns the formatted string ("8 weeks") |
| Phase 18 R-3 / Q4 asks for optional `courses.monthly_fee` and `student_admissions.monthly_fee` | **both columns are included** (§2.4, §2.15), so `fees:generate-monthly` never has to ask for an amount |
| Phase 18 §13.3 requires Phase 15 to call `generateStructure()` and `transferPayment()` and never insert money | adopted verbatim (INV-I1, §6.8); `reassignBatch()` is requested for the same-course batch move, which `transferPayment()` does not cover |
| Phase 9 §13 requires the admission form to render `<x-site.referral-field>`, resolve through `ReferralAttributionResolver`, and never write a snapshot column | adopted verbatim (§6.4, §8.20); this phase owns **no** snapshot listener, and `students.referral_visit_id` is added to the snapshot set |
| Phase 9 INV-R2: the browser posts a **visit token**, never a code | INV-I4 rewritten accordingly; FT-20 now includes a forged and an expired token |
| Phase 7 §13 asks that `teachers.salary` be display-only or dropped | kept but constrained: NULL whenever `employee_id` is set, never a payroll input, gated by `teachers.view_financial` (§2.17) |
| Phase 3 ships the `site` / `site.preview` / `site.cache` aliases | the first draft's `site.enabled` is dropped; §7.10 uses Phase 3's three aliases and adds only `admission.open`. The admission route is deliberately **not** cached |
| Phase 3 anticipates the route key `site.courses.index` | the `site.*` prefix is confirmed ([D-IN-15]) and the catalogue's SEO comes from Phase 3's `seo_meta` by `route_key` |
| Phase 10 / spine §13.1 asks for `students.branch_id`, `student_admissions.net_payable` and the no-force-delete rule | all three delivered (§2.14, §2.15); `course_fee_net_payable` is offered in addition for the non-commissionable-head case (R-7) |

---

## Convergence log (2026-09-12)

Applied from [`../design/resolutions.md`](../design/resolutions.md) §7's apply-map row for this file, plus
its §1 global rules and §2 ownership maps. Nothing outside those rows was restructured.

| Finding | Change made |
|---|---|
| **F-2.2** | `course_faqs` **deleted**: removed from §1.3 ownership, from §2.1 (row 8 dropped, table renumbered to 25 tables / 24 entities, §2 heading and the contents row updated), from §2.2's `branch_id` list, from §2.4's relationships (now `morphMany(App\Models\Cms\Faq::class, 'faqable')`), from §2.29's map, from §4.1's note, and from §6.2's `duplicate()`. §2.10 is now a one-line reference: course FAQs are Phase 3's `faqs` rows (`faqable_type = App\Models\Institute\Course`, `faqable_id = courses.id`) written by `FaqService::save()` under `courses.edit`. §7.2's three FAQ routes are kept but explicitly write Phase 3's `faqs` rows; FT-06 reworded. No index, permission or test row for a `course_faqs` table survives |
| **F-3.8** | §2.11 gains `contact_inquiry_id` unsignedBigInteger nullable, FK -> `contact_inquiries.id` `nullOnDelete` in a `Schema::hasTable()`-guarded migration, plus **`UNIQUE uq_ci_inquiry(contact_inquiry_id)`**; `idempotency_key` kept (a different guarantee). §6.12's `createFromPublic()` stores it and treats the 1062 as "already created". §1.2 and §13.1 name Phase 4 as the owner |
| **F-3.14** | §2.11 gains `referral_visit_id` unsignedBigInteger nullable, `INDEX (referral_visit_id)`, deferred guarded FK -> `collaborator_referral_visits.id` `nullOnDelete`, identical to §2.13 / §2.14. §13.1's Phase 9 row now names all three tables |
| **F-4.1** | `App\Services\Finance\DocumentNumberService` retargeted **Phase 10 -> Phase 5** in §1.2 (new dependency row), §6.5 and §13.1; `next(string $prefixKey, string $counterKey, string $pad = '%06d')` and `reserve(...)` recorded with "every caller passes its own pad"; **D27** cited |
| **F-4.3** | §6.4's `attach()` call and §13.1's row now cite `App\DataObjects\Collaborator\ReferralContext` (readonly, published in spine §13.2) as the sixth parameter; the ask is marked **satisfied** |
| **F-4.6** | §1.2, §2.15 and §13.1: `summaryFor()`, `reassignBatch()`, `withinServiceContext()` and `outstandingFor()` are marked **published in Phase 18 §6.1**, with `App\DataObjects\Institute\FeeSummary`; the "**Not yet in Phase 18's published method list**" note is deleted |
| **F-4.7** | §6.7 now publishes the generic `ScheduleClashDetector::check(SlotCandidate $c): ClashReport` with `App\DataObjects\Institute\SlotCandidate` (readonly `?int $teacherId`, `?int $classroomId`, `?int $batchId`, `CarbonInterface $startsAt`, `CarbonInterface $endsAt`, `?string $ignoreType`, `?int $ignoreId`) and `ClashReport` (readonly `bool $clean`, `array $conflicts`). The §71 recurring rule is served by four **additive, nullable, defaulted** extra properties (`dayOfWeek`, `effectiveFrom`, `effectiveTo`, `deliveryMode`) so a dated caller - an exam, a meeting - passes only the seven canonical ones. The three subject checks are thin wrappers, later-phase occupant tables register into the same scope, and **INV-I8 is restated to cover §81 exams and §95 meetings** |
| **F-4.12** | §6.5's `StudentNumberService` calls `reserve(string $counterKey, ?string $periodKey = null, ?string $periodValue = null): int` on that same class; the local-duplication `FOR UPDATE` fallback **and its tech-debt note are deleted**, here and in §13.1 |
| **F-5.3** | `CourseInquirySource` **deleted** from §3; `course_inquiries.source` casts to Phase 4's `App\Enums\InquirySource` (11 cases). §3 carries the note that §86's eight channels are identical strings, so there is no data migration; §1.2, §2.11 and §6.12 updated |
| **F-5.8** | §3's `Gender` row: "Phase 7 should reuse it rather than declare a second one" deleted; replaced with "declared here and nowhere else" |
| **F-5.9** | **No change** - `StudentAttendanceStatus` and HR's `AttendanceStatus` stay two deliberate names (resolutions §8 item 9) |
| **F-7.1** | INV-I11 rewritten to **`decimal(8,4)`** with no "reported percentage" exception, citing `CLAUDE.md` §3 and `Money::percentageOf()`. All seven columns changed: `batches.syllabus_completion_percentage`, `student_batch_enrollments.attendance_percentage`, `.progress_percentage`, `batch_topic_coverage.completion_percentage`, `student_course_progress.completion_percentage`, `student_module_progress.completion_percentage`, `student_topic_progress.completion_percentage`. FT-43's asserted value corrected to `66.6700` |
| **F-7.3** | §2.9 `course_topic_assignments.estimated_hours` -> **`decimal(10,2)`** (`estimated_marks` stays `decimal(8,2)` per F-7.2) |
| **F-9.1** | **[D-IN-2]** now cites **D19** (the `CLAUDE.md` §3 category rule) instead of claiming a local narrowing of the spine's D16; the five no-`deleted_at` tables are mapped onto D19's categories |
| **F-9.2** | §2.11 Keys gain `INDEX (referral_visit_id)`, `INDEX (converted_application_id)`, `INDEX (converted_student_id)`; §2.15 Keys gain `INDEX (student_application_id)`, `INDEX (course_inquiry_id)`; §13.2 gains the row "every FK index named in §2 has a row in `tests/Support/index-manifest.php`" (**D60**) |
| **F-11.1** | §2.17 states that `teachers.employee_id` -> `employees.id` is correct under **D32** because teaching is an organisational **duty**, while `teachers.user_id` -> `users.id` carries login and marking; the bridge is `employees.user_id`. Column unchanged |
| **F-10.1** | §13.3 renumbered: D19 -> **D45**, D20 -> **D46**, D21 -> **D47**, D22 -> **D48**, with an opening line stating that resolutions §4 is the only decision-number authority and a row listing the numbers this contract merely **cites** (D19, D27, D32, D37) |
| **F-4.11** *(resolutions §3 names this file's §13.2 although the §7 apply-map row omits it)* | §13.2's `Money` ask replaced with "**satisfied by phase-01 §3**" plus the canonical 20-method surface (bcmath only, intermediate scale 6, final half-up at 2, strings in and out) |
| **F-2.1** *(factual correction only)* | §2.11's "routed here by **Phase 3**" corrected to Phase 4, which owns `contact_inquiries`, `ContactInquirySubmitted` and the `InquiryRouter` / `InquiryTarget` routing |
| **R5 / D37** *(resolutions §1, the rule F-8.2 and F-12.2 enforce elsewhere)* | §9's Collaborator row and §6.14's `forCollaborator()` now scope on an **`active` `collaborator_referrals` row only**; `students.collaborator_id` is stated to be a display snapshot that is never a scope, so a hand-written snapshot grants nothing (already asserted by FT-50) |
