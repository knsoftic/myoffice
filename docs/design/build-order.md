# BUILD ORDER — what gets built next, and in what sequence

**Status: binding.** This file decides **sequence only**. It creates no table, renames nothing, and
overrides no contract's content. Precedence above it:
[`finance-commission-spine.md`](finance-commission-spine.md) (anything touching money) >
[`resolutions.md`](resolutions.md) (every converged decision) > the phase contracts > this file.
Where this file and a phase contract disagree about **when** an artefact lands, this file wins and the
contract's §1.x dependency table is edited to match; where they disagree about **what** the artefact is,
the contract wins.

**The client's phase numbering in `DEVELOPMENT_LOG.md` §5 is FIXED.** Nothing below renumbers a phase.
Necessary reordering is expressed in exactly two forms:

| Form | Meaning | Register |
|---|---|---|
| **Phase N ships X early, on behalf of Phase M** (N < M) | the artefact is *created* in N's release; M reuses it and must not create it | §3 |
| **Phase M completes surface Y of Phase N** (N < M) | N writes the code behind a capability contract / module switch; the surface only becomes reachable and provable in M's release | §4 |

Inputs read: `docs/requirements.md`, `CLAUDE.md` §§1-8, `DEVELOPMENT_LOG.md` §5, all thirteen contracts,
`consistency-audit.md` §11 and §16, `resolutions.md` §§1-8 (R1, R2, D19, D27, D28, D37, F-4.1, F-4.6,
F-4.14, F-11.2, F-11.3, F-11.6 bind this file directly).

---

## Contents

| § | Contents |
|---|---|
| 1 | Release slices — the corrected build sequence |
| 2 | Dependency table — Needs / Blocks / Ships, per tracker phase |
| 3 | Early-ship register — "Phase N ships X early, on behalf of Phase M" (24 rows) |
| 4 | Deferred-surface register — a later phase's artefact an earlier phase needs |
| 5 | Deferred-FK completion ledger — who promotes which constraint |
| 6 | Shared foundations — one row each, with the owning phase |
| 7 | Ready-to-build verdict per phase |
| 8 | Per-phase definition-of-done delta (beyond `CLAUDE.md` §8) |
| 9 | Never-concurrent pairs and the three legitimate cycles |
| 10 | Owner-applied tracker notes, and what still needs a human |

---

## 1. Release slices — the corrected build sequence

One slice = one merged release. Inside a slice, the order of the lines is the migration order.
**No slice may be partially merged** where it contains a money migration set (spine R-13).

| Slice | Phases | What merges, in order | Why this boundary |
|---|---|---|---|
| **S0** | 1 | Phase 1 remediation; then the four contract items of §3 rows E1-E2 (`Money` surface + `RemainderPlacement`, `permissionModuleMap()` null + the four-panel test) | D20 is load-bearing for every later panel; the `Money` surface is load-bearing for every later money phase |
| **S1** | 2 | `modules.depends_on` + disable audit · `settings.updated_by`/`is_readonly` · `users.preferences` · `SettingsRegistry` · `DashboardRegistry` · `DateRange` · `Format` · `ModuleService` | Phases 3-25 all open with "needs Phase 2's registries" |
| **S2** | 3 | the 12 CMS tables + 2 pivots · `MediaService` · `RichText` · `SeoService` · `SitemapRegistry` · `site`/`site.cache`/`site.preview` · the four manifest files (§3 row E4) | shares `routes/web.php` + `layouts/site.blade.php` with Phase 4 — cannot run concurrently with it (F-11.6) |
| **S3** | 4 | the 20 catalogue/blog/careers/inquiry tables · `contact_inquiries` + `InquiryRouter` · `EmploymentType` (early) · `SlugGenerator` · `SpamGuard` | closes the 3↔4 cycle; Phase 5 and 14-17 both need `contact_inquiries` and `services` |
| **S4** | 5 | the 9 CRM tables · `DocumentNumberService` (`next` + `reserve`) · `routes/client.php` + `ClientPortalRegistry` + `client.context` · `CsvWriter` · the two Null capability bindings | D27: four phases need the counter before Phase 10; D31: one owner for every `client.*` name |
| **S5** | 6 | the 11 project tables (incl. `attachments`) · `CommissionCalculationType` · `Priority` · `AttachmentVisibility` / `CommentVisibility` · `ProjectValueService` · `add_project_fks_to_crm_tables` | the spine's project denominator and the one polymorphic file table both come from here |
| **S6** | 7 | the 24 HR tables · `PaymentMethod` + `LedgerEntryType` (early) · `EmploymentType` reuse · `PayslipService` · `add_employee_fks_to_{team_members,job_applications}` | the spine's two money enums must exist before the spine migrates (F-5.4) |
| **S7** | 8 **+ spine set** | (a) Phase 8's 3 tables + `activity_log.collaborator_id`; (b) **immediately after**, the spine's 21-file set + its 27 enums + `students` / `student_admissions` (§3 row E5); (c) `add_collaborator_fks_to_project_tables`; (d) `collaborators:backfill-wallets` + `collaborators:seed-initial-rules` | F-11.2 / [D-P8-1]: Phase 8's wallet observer and rule screens have no tables otherwise. (b) is one atomic unit |
| **S8** | 9 | `collaborator_referral_visits` · `ReferralAttributionResolver` · `CaptureReferral` · the promotion of `collaborator_referrals.referral_visit_id` and `leads.referral_visit_id` | Phase 9 needs the spine's `collaborator_referrals`, so it follows S7 |
| **S9** | 10 | the engine: `LedgerWriter` · `ReferralService` · `CommissionRuleService` · `CommissionBaseResolver` · `CommissionEntitlementService` · `CommissionApprovalService` · `CommissionReversalService` · `PaymentService` (student leg) · `StudentCommissionService` · the commission ledger / rule-timeline / skip screens · FT-01..FT-05, FT-09 | the migration set already landed in S7; this slice is code + screens. The student tests are provable here **because** S7 shipped `students` / `student_admissions` |
| **S10** | 11 | `PaymentService` (project leg) · `ProjectCommissionService` · project override resolution · project payment register + trail · FT-06..FT-08 | needs only Phase 6, which is long done |
| **S11** | 12 | `CollaboratorWalletService` · `PayoutService` · `CollaboratorStatementService` · `CommissionReconciliationService` · wallet / payout / statement / reconciliation screens · the collaborator panel money screens | needs ledger rows, so it is built after 10 and 11 |
| **S12** | 13 | the 7 finance tables · `InvoiceService` · `ExpenseService` / `IncomeService` · `RecordPayrollExpense` · **`ReportResult` + `ReportExporter` + `layouts/print.blade.php`** · `add_finance_foreign_keys_to_payment_tables` | the three reporting artefacts are needed by 18, 21 and 23 (F-4.14) |
| **S13** | 14 → 15 → 16 → 17 | four migration batches in tracker order; 15 **reuses** `students` / `student_admissions` and creates the rest; `add_institute_fks_to_fee_tables`; `ScheduleClashDetector::check()` | 14↔18 and 15↔18 are cycles broken by ordering; the fee handover is §4 row F3 |
| **S14** | 18 | `student_fee_reminders` (the only new table) · `StudentFeeService` + the four F-4.6 methods + `FeeSummary` · `InstallmentPlanCalculator` · `FeeSlipBuilder` · the fee screens · the three fee widgets · PH18-01..06 | Phase 18 creates no financial table (F-11.3); it completes §4 rows F3-F5 |
| **S15** | 19 → 20 → 21 → 22 → 23 | in tracker order; 19 ships `SecureFileService` first; 20 ships the guarded `courses.grade_scale_id`; 22 registers every earlier phase's notifications; 23 adds only `report_exports` and one `ExportFormat` case | every FK target belongs to an earlier phase, so only two guarded migrations exist here |
| **S16** | 24 (rolling) → 24 (close) → 25 | each phase 3-23 appends its four manifest rows as it ships; Phase 24 **closes** only after 23; Phase 25 last | a green matrix over 22 of 23 phases proves nothing about the 23rd (phase-24-25 §1.2) |

**Two corrections S1-S16 make to the literal tracker reading**

| # | Correction | Authority |
|---|---|---|
| C1 | The spine's migration set, its 27 enums **and** `students` / `student_admissions` merge inside **Phase 8's release**, not Phase 10's, and as one unit | F-11.2, [D-P8-1], [D-FS-1], spine §1.3; §3 rows E5-E7 |
| C2 | Phase 10's slot is **code and screens only** — its schema landed in S7 — and its project sibling (11) may be built in parallel with it once §6.3's shared services exist | phase-10-12 §1.3 |

---

## 2. Dependency table

`Needs` lists the artefact, not the whole phase. Core Phase 1 + Phase 2 plumbing (`users`, RBAC,
`PermissionRegistry`, `activity_log`, `Blameable`, `Money`, `x-ui.*`, `layouts/*`, `SettingsRegistry`,
`DashboardRegistry`, `DateRange`, `Format`) is a prerequisite of **every** phase 3-25 and is omitted
from each row.

| Phase | Needs (tables / services, from) | Blocks | Ships (tables, services) |
|---|---|---|---|
| **1** | — | every phase | 14 migrations · `users` `roles` `permissions` `modules` `settings` `branches` `login_histories` `activity_log` · `PermissionRegistry` · `Money` (full surface) · `RemainderPlacement` · `Blameable` · `LogsActivityWithContext` · `Device` · `Sidebar` · `x-ui.*` · 5 route files · `Gate::before` |
| **2** | Phase 1 (all) | 3-25 | `modules.depends_on` · `settings.updated_by`/`is_readonly` · `users.preferences` · `SettingsRegistry` · `SettingsService` · `ModuleService` · `DashboardRegistry` + `DashboardWidget` · `DateRange` · `Format` · `ConfigureFromSettings` |
| **3** | 2 (`settings.is_public`, `ConfigureFromSettings`) | 4, 14, 19-23 (public pages), 24 | 12 tables + 2 pivots (`website_sections` `website_section_items` `website_section_media` `menus` `menu_items` `pages` `cta_blocks` `faq_categories` `faqs` `faq_website_section` `seo_meta` `media_assets` `cms_revisions` `sitemap_generations`) · `MediaService` + `ImageProfile` · `RichText` · `SeoService` · `SitemapRegistry` · `WebsiteSectionRegistry` · `PublicCache` · `PreviewService` · `site`/`site.cache`/`site.preview` |
| **4** | 3 (`layouts/site`, `SitemapRegistry`, `MediaService`, `RichText`, `seo_meta`) | 5, 8, 14, 15 | 20 tables incl. `service_categories` `services` `technologies` `portfolio_items` `portfolio_item_media` `team_members` `testimonials` `student_reviews` `success_stories` `blog_*` `job_openings` `job_applications` `contact_inquiries` · `InquiryRouter` + `InquiryTarget` · `ContactInquirySubmitted` · `InquirySource` · `EmploymentType` (early) · `SlugGenerator` · `SpamGuard` · `site_module` |
| **5** | 4 (`contact_inquiries`, `services`, `InquirySource`) | 6, 7, 8, 10, 13, 14-18, 19-23 | 9 tables (`leads` `lead_activities` `lead_follow_ups` `lead_conversions` `lead_imports` `lead_import_rows` `clients` `client_contacts` `client_documents`) · **`DocumentNumberService`** · `routes/client.php` + every `client.*` name · `ClientPortalRegistry` + `ClientPortalSection` · `EnsureClientContext` / `client.context` · `CsvWriter` · `ContactNormalizer` · `NullReferralRecorder` / `NullProjectCreator` |
| **6** | 5 (`clients`, `leads`, `DocumentNumberService`, `routes/client.php`) | 8, 10, 11, 12, 13, 19-23 | 11 tables (`projects` `project_value_revisions` `project_members` `project_milestones` `tasks` `task_checklist_items` `task_comments` `task_comment_mentions` **`attachments`** `time_entries` `time_entry_segments`) · `ProjectValueService` · `ProjectProgressService` · `TimerService` · `TimeRollupService` · `ProjectNumberService` · `CommissionCalculationType` · `Priority` · `AttachmentVisibility` · `CommentVisibility` |
| **7** | 5 (`DocumentNumberService`) · 4 (`EmploymentType` file) | 13 (P&L), 14-17 (`teachers.employee_id`), 19-23 (HR reports) | 24 tables (`departments` `designations` `employees` + 21) · `WorkCalendarService` · `AttendanceSummaryService` · `LeaveBalanceService` · `PayrollService` · **`PayslipService`** · `PaymentMethod` · `LedgerEntryType` · `EmploymentType` (+2 methods) · `PayrollRunPaid` |
| **8** | 6 (`projects`, `project_members`) · 4 (`services`) · 5 (`DocumentNumberService`) · 7 (money enums) · **spine set, same release** | 9, 10, 11, 12, 14-17, 19-23 | `collaborators` `collaborator_skills` `collaborator_service` · `activity_log.collaborator_id` · `CollaboratorCodeService` · `CollaboratorService` · `CollaboratorOnboardingService` · `CollaboratorPayoutAccountService` · wallet-creating observer · `forceDelete` policy · `add_collaborator_fks_to_project_tables` |
| **9** | 8 · spine `collaborator_referrals` | 10, 14-17, 19-23 | `collaborator_referral_visits` · `ReferralLinkService` · `ReferralTrackingService` · `ReferralAttributionResolver` · `CaptureReferral` · `<x-site.referral-field>` · `SyncReferralSnapshot` · `MarkReferralVisitConverted` · the `referral_visit_id` FK promotions |
| **10** | 6, 7, 8 (hard) · 9 (soft) · 5 (`DocumentNumberService`) | 11, 12, 13, 18, 19-23, 24 | **the 21-file / 15-table spine set incl. the four fee tables and `students` + `student_admissions` (both early)** · 27 enums · `LedgerWriter` · `PaymentService` (student) · `ReferralService` · `CommissionRuleService` · `CommissionBaseResolver` · `CommissionEntitlementService` · `StudentCommissionService` · `CommissionReversalService` · `CommissionApprovalService` · `RefundData` · `ReferralContext` |
| **11** | 6 (`net_value`, `project_milestones.amount`, `project_value_revisions`) · 10 (shared services) | 12, 13 | `PaymentService` (project) · `ProjectCommissionService` · project-override resolution · project payment register + commission trail · project commission widgets |
| **12** | 10, 11 (ledger rows) · 8 (`collaborators.user_id`) | 13, 18, 19-23 | `CollaboratorWalletService` (+`payoutsPaidTotal`) · `PayoutService` · `CollaboratorStatementService` (+`commissionAccruedTotal`) · `CommissionReconciliationService` · wallet / payout / statement / reconciliation screens |
| **13** | 5 (`clients`) · 6 (`projects`, `project_milestones.name`) · 10-12 (payments, reversals, wallet totals) · 7 (`PayrollRunPaid`) | 18, 19-23, 24 | `payment_methods` `finance_categories` `invoices` `invoice_items` `expenses` `incomes` `finance_reversals` · `InvoiceService` · `ExpenseService` · `IncomeService` · `FinanceReportService` · **`ReportResult`** · **`ReportExporter`** · **`layouts/print.blade.php`** · `ExportFormat` · `FinanceVisibility` · `RecordPayrollExpense` · `add_finance_foreign_keys_to_payment_tables` |
| **14** | 3 (`seo_meta`, `SitemapRegistry`, `faqs`, section renderer) · 5 (`DocumentNumberService`) | 15, 16, 17, 18, 19-23 | `course_categories` `courses` `course_modules` `course_topics` `course_lectures` `course_topic_resources` `course_topic_assignments` · `CourseService` · `CourseOutlineService` · `PublicCourseService` |
| **15** | 14 (`courses`) · 4 (`contact_inquiries`, `InquirySource`) · 9 (referral capture) · 10 (`ReferralService`, `students`/`student_admissions` already created) · 5 (`reserve()`) | 16, 17, 18, 19-23 | `course_inquiries` `course_inquiry_follow_ups` `student_applications` `demo_classes` · **reuses** `students` + `student_admissions` · `CourseInquiryService` · `StudentApplicationService` · `StudentService` · `StudentNumberService` · `AdmissionService` · `DemoClassService` · `add_institute_fks_to_fee_tables` |
| **16** | 15 (`students`) · 14 (`courses`) · 7 (`employees`) | 17, 18, 19-23 | `teachers` `course_teacher` `classrooms` `batches` `student_batch_enrollments` `timetable_entries` `class_sessions` · `TeacherService` · `BatchService` · `BatchEnrollmentService` · `TimetableService` · **`ScheduleClashDetector` + `check(SlotCandidate)`** · `ClassSessionService` |
| **17** | 16 (`class_sessions`, `student_batch_enrollments`) | 19-23 | `student_attendances` `batch_topic_coverage` `student_course_progress` `student_module_progress` `student_topic_progress` · `AttendanceService` · `AttendanceReportService` · `CourseProgressService` |
| **18** | 10 (4 fee tables + `PaymentService`) · 12 (wallet services) · 13 (exporter + print layout) · 14, 15, 16 · 5 (`DocumentNumberService`) | 19-23, 24 | `student_fee_reminders` (only new table) · `StudentFeeService` (+ `summaryFor` `reassignBatch` `withinServiceContext` `outstandingFor`) · `FeeSummary` · `InstallmentPlanCalculator` · `FeeSlipBuilder` · `FeeReminderService` · the 3 fee widgets · `fees:mark-overdue` · `fees:verify-plan-integrity` |
| **19** | 14 (`course_topic_assignments`) · 16 (`roster()`) · 6 (`attachments`) | 20, 21, 22 | `course_materials` `course_material_targets` `course_material_downloads` `assignments` `assignment_submissions` `assignment_submission_files` · **`SecureFileService`** · `CourseMaterialService` · `MaterialAccessService` · `AssignmentService` · `AssignmentGradeCalculator` |
| **20** | 19 (`SecureFileService`) · 17 (`CourseProgressService`) · 16 (`check(SlotCandidate)`) | 21, 23 | `grade_scales` `grade_scale_bands` `exams` `exam_results` · `GradeScaleService` · `ExamService` · `ExamResultService` · `ResultCalculator` · `ResultCardBuilder` · the guarded `courses.grade_scale_id` |
| **21** | 20 (`exam_results`) · 17 (attendance/progress caches) · 18 (`outstandingFor`) · 13 (print layout) · 3 (`site`, `RichText`) | 23 | `print_templates` `certificates` `certificate_verifications` `student_id_cards` · `PrintTemplateService` · `PrintTokenRegistry` · `CertificateEligibilityService` · `CertificateService` · `StudentIdCardService` · `QrCodeService` |
| **22** | 6 (`attachments`, `task_comments`) · 5 (`ClientPortalRegistry`) · 16 (`check(SlotCandidate)`) · 19 (`SecureFileService`) | 23 | `ticket_departments` `support_tickets` `ticket_replies` `meetings` `meeting_participants` `conversations` `conversation_participants` `messages` `notifications` `notification_preferences` · `TicketService` · `TicketSlaService` · `MeetingService` · `ConversationService` · `MessagingMatrix` · `NotificationService` + `NotificationRegistry` · `UnreadCounters` |
| **23** | 13 (`ReportResult`, `ReportExporter`, `FinanceReportService`) · 7 (`PayslipService`) · 12 · 17 · 18 · every owning service | 24 | `report_exports` · `ReportRegistry` + 31 report classes · `ReportEngine` · `ReportExportService` · `AnalyticsService` · `ActivityLogService` · `AuditTrailService` · `GlobalSearchRegistry` + 11 providers · `ExportFormat::excel` |
| **24** | every phase's manifest rows; closes only after 23 | 25 | `integrity_check_runs` · the four manifests' drift check (`audit:manifest`) · hardening runtime · performance runtime · `golive:check` · `integrity:verify` · `shouldBeStrict()` |
| **25** | 24 | — | `backup_runs` `backup_restores` · backup + restore services · deploy runbook · queue/scheduler docs · the three MySQL users |

---

## 3. Early-ship register

Every case where an artefact is created by a phase **earlier** than the phase whose feature it serves.
Rows E5-E7 and E9 are new to this file; the rest are already decided in `resolutions.md` and are listed
so the sequence is complete and auditable in one place.

| # | Ships early | Shipped by | On behalf of | Authority | Mechanism |
|---|---|---|---|---|---|
| E1 | `App\Support\Money` full surface (20 methods, bcmath, scale 6 → half-up 2) | **1** (remediation) | 5, 7, 10-12, 13, 14-17, 18, 24 | F-4.11 | contract text in phase-01 §3; code by the Phase 1 remediation team |
| E2 | `App\Enums\RemainderPlacement` (`first`, `last`, `largest`) | **1** | 18 (`distribute()`) | F-4.11 | ships with `Money` |
| E3 | `Modules::permissionModuleMap()` null semantics + `Gate::before` fall-through | **1** | 3-25 (all four non-admin panels) | D20, F-12.1 | one Phase 1 test: four panels reachable with every other module disabled |
| E4 | the four manifest files (`screen-`, `route-guard-`, `upload-`, `index-manifest.php`) as empty skeletons | **3** | 4-23 append rows; **24** supplies `audit:manifest` | D60, phase-24-25 §13.2 | Phase 3 creates the files with its own rows; every later phase appends |
| E5 | **`students`** (phase-14-17 §2.14, verbatim) | **10** (inside S7) | **15** | *new* — §1 C1; mirror of F-11.3; [D-FS-2] rationale | `student_fees.student_id` is **NOT NULL** FK `students.id`; without the table the spine's own FT-01..05/FT-09 cannot be written, and untested money code is a weaker financial guarantee than an early table (rule 4). `course_id` / `current_batch_id` / `referral_visit_id` stay deferred guarded FKs. Phase 15 **reuses, does not create** |
| E6 | **`student_admissions`** (phase-14-17 §2.15, verbatim, incl. `net_payable`, `figures_locked_at`, the four money caches) | **10** (inside S7) | **15**, 18 | *new* — as E5 | it is the default commission document grain (spine §6.1.4); `student_application_id` / `course_inquiry_id` / `course_id` / `batch_id` deferred guarded |
| E7 | the spine's 15-table / 21-file migration set **and its 27 enums** | **10** | **8** (wallet observer, rule screen, payout accounts), **9** (`collaborator_referrals`) | F-11.2, [D-P8-1], [D-FS-1] | applied immediately after Phase 8's own migrations, as one unit; the spine-dependent Phase 8/9 surfaces stay behind their module switches until it lands; two idempotent backfills close the window |
| E8 | `student_fees` `student_fee_installments` `student_fee_discounts` `student_fee_payments` | **10** | **18** | F-11.3, resolutions §2.1 | Phase 18 creates no financial table; it ships screens, services and `student_fee_reminders` |
| E9 | `App\Enums\EmploymentType` — the **file**, with the seven canonical cases and both methods verbatim from resolutions §2.2 | **4** | **7** | *new* — R2 applied to F-5.2 | `job_openings.employment_type` casts to it at Phase 4, three phases before its semantic owner. Phase 7 **reuses unchanged**; only Phase 7 may ever change the case list or the two methods. A second declaration is a merge conflict (R3) |
| E10 | `App\Services\Finance\DocumentNumberService` (`next` + `reserve`) | **5** | 6, 7, 8, 10, 13, 14-17, 18, 19-23 | D27, F-4.1, F-4.12, F-11.4 | every caller passes its own pad (`%06d` 5/18, `%05d` 6/7, `%04d` 8); `ProjectNumberService` and `StudentNumberService` delegate from day one; no local `FOR UPDATE` fallback anywhere |
| E11 | `routes/client.php` + every `client.*` route name + `ClientPortalRegistry` + `ClientPortalSection` + `EnsureClientContext` | **5** | 6, 10-12, 13, 22 | D31, F-6.2, F-12.3 | later phases **append** screens through the registry; redeclaring a name is a live bug (last registration wins silently) |
| E12 | `App\Support\CsvWriter` (streaming + formula-injection escape) | **5** | 13, 19-23 | phase-05 §6.10, phase-19-23 §13.1 | one CSV writer; Phase 23's exports reuse it |
| E13 | `App\Contracts\Referrals\ReferralRecorder` + `App\Contracts\Projects\ProjectCreator` (Null bindings) | **5** | 6 rebinds `ProjectCreator`; 9/10 rebind `ReferralRecorder` | D28, [D-P5-1], [D-P5-6] | `isAvailable()`: an unavailable capability renders no control and its route 404s — never a fabricated number |
| E14 | `media_assets` + `website_section_media` + `MediaService` + `ImageProfile` | **3** | 4 (11 `*_media_id` columns + `portfolio_item_media`), 14-17 | D24, F-2.4 | mandatory for anything rendered on the public site; a bare `*_path` only for a private photo/document. Phase 4 ships no uploader |
| E15 | `seo_meta` + `SeoService` + `SitemapRegistry` + `SitemapUrlProvider` | **3** | 4 (7 models), 14 (`route_key`), 19-23 | D23, F-2.3 | no phase adds an SEO column to its own table; OG image is `seo_meta.og_image_media_id` |
| E16 | `App\Support\RichText::sanitize()` (`mews/purifier`), on write **and** on render | **3** | 4, 21 (`PrintTemplateService`), 24 (SEC-05) | D25, F-2.5 | one security control, one answer; `HtmlSanitizer` deleted |
| E17 | `faqs` + `faq_categories` + the `faqable_*` morph | **3** | 14 (course FAQs, §90) | F-2.2 | `course_faqs` does not exist |
| E18 | `cms_revisions` + `cta_blocks` | **3** | any later draft/publish entity | phase-03 §1.3 [D-W3-1] | `morphMany`, append-only (D19) |
| E19 | `contact_inquiries` + `ContactInquirySubmitted` + `InquiryRouter` + `InquiryTarget` | **4** | 5 (`CrmLeadInquiryTarget`), 14-17 (`course_inquiry`), 8-9 | F-2.1 | Phase 4 also owns the `contact` **section type** it registers into Phase 3's registry (§4 row F1) |
| E20 | `services` + `service_categories` + `technologies` | **4** | 5 (`leads.service_id`), 6 (`projects.service_id`), 8 (`collaborator_service`) | F-3.6, phase-08-09 §1.2 | the pivot and both FK columns target these |
| E21 | **`attachments`** (the one polymorphic file table) + its morph map incl. `Collaborator` and `Invoice` | **6** | 5 (client Files), 7 (documents, later), 8-9 (§59), **22** (§96), 19-23 | F-2.6, F-2.8, F-13.2 | the `files` **module slug** governs this table; no `files` table is ever created; visibility is `attachments.visibility` |
| E22 | `App\Enums\CommissionCalculationType` (`percentage`, `fixed`, `manual`) | **6** | spine, 10-12 | F-5.5 | marked "reused, not created" in phase-10-12 §3 |
| E23 | `App\Enums\Priority` (`low`…`urgent`) + `AttachmentVisibility` + `CommentVisibility` | **6** | 22 (`support_tickets.priority`), 5, 19-23 | F-5.7, F-2.6, F-3.2 | `TicketPriority` deleted; `slaMultiplier()` moves to `TicketSlaService` |
| E24 | `App\Enums\PaymentMethod` + `App\Enums\LedgerEntryType`, cases **verbatim from spine §3** | **7** | spine, 10-12, 13, 14-17, 18 | F-5.4 | Phase 7's leave ledger and advance disbursement need them before the spine migrates |
| E25 | `App\Support\ReportResult` + `ReportExporter::export(ReportResult, ExportFormat)` + `resources/views/layouts/print.blade.php` | **13** | 18 (fee slip, receipt), 21 (result card, certificate, ID card), 23 (31 reports) | F-4.14, F-11.5 | Phase 18 ships **no** fallback; the "or Phase 18 if 13 has not shipped it" clause is deleted |
| E26 | `ScheduleClashDetector::check(SlotCandidate): ClashReport` + both DTOs | **16** | 20 (exams), 22 (meetings) | F-4.7, D47 | the three subject checks become wrappers; one lock discipline, one nightly verifier |
| E27 | `App\Services\Files\SecureFileService` | **19** | 20, 21, 22 | phase-19-23 §1.3 | the one uploader the later institute/support phases reuse; D21 streaming |
| E28 | `students` / `student_admissions` money caches + `figures_locked_at` (columns) | **10** via E5/E6 | **18** writes them | phase-14-17 §6.8, INV-I2 | the columns live on the admission; only `StudentFeeService` may write them (`withinServiceContext()`) |

---

## 4. Deferred-surface register

Cases where an **earlier** phase needs a **later** phase's service. None is resolved by moving a table;
each uses machinery the contracts already have: a capability contract (D28), a registry, or a module
switch ([D-P8-1]). A deferred surface is **not** a blocker: the earlier phase ships, its surface renders
an empty state or 404s, and the later phase makes it reachable.

| # | Earlier phase | Needs from later | Mechanism while absent | Completed by | Authority |
|---|---|---|---|---|---|
| F1 | **3** — the `contact` section type | Phase 4's form, table and router | `WebsiteSectionRegistry::exists()` skips an unregistered type; the admin list flags it orphaned (INV-2) | **4** | F-2.1, INV-2 |
| F2 | **3** — the six hero statistics | `projects.status` (6), `clients.status` (5), `students.status` (15), `courses.status` (14), `team_members.is_public` (4) | `StatisticMetric` is module- **and** `Schema::hasTable`-guarded; an unresolved metric renders **nothing**, never `0` (INV-12) | 4, 5, 6, 14, 15 | phase-03 §13.3, INV-12 |
| F3 | **15** — the admission wizard's fee-collection step, and the `registration → fee_collection` transition | `StudentFeeService::generateStructure()` | the step is a **handover**: it renders Phase 18's charge form; the stage advances only after Phase 18 reports a charge. Until then the `student_fees` module switch is off and the wizard stops at `registration` | **18** | phase-14-17 §6.8, §2.31 step 5, D28 |
| F4 | **15/16** — batch and course transfer | `StudentFeeService::reassignBatch()` / `::transferPayment()` | `BatchEnrollmentService::transfer()` refuses a transfer that would need a fee repoint while the capability is unavailable; it never moves money itself | **18** | F-4.6, spine §6.6 row 7 |
| F5 | **14-17** — every fee chip, roster fee column and `§57` collaborator fee figure | `StudentFeeService::summaryFor()` → `FeeSummary` | the chip is permission- **and** module-gated; absent capability = absent chip. No phase sums `student_fee_payments` (INV-26) | **18** | F-4.6 |
| F6 | **5** — the client panel's projects / tasks / milestones / progress / files sections | Phase 6's `ClientPortalSection` registrations | unregistered section: nav item absent **and** route `abort(404)` | **6** | [D-P5-1], D28 |
| F7 | **5** — the client panel's invoices / payments / tickets / meetings / messages sections | 13, 10-12, 22 registrations | same | 10-12, 13, 22 | [D-P5-1], [D-P5-11] |
| F8 | **5** — recording a captured referral code | `ReferralService` | `NullReferralRecorder`; `crm:record-captured-referrals` backfills every code captured before the rebinding | **9/10** | [D-P5-6] |
| F9 | **5** — "create project from won lead" | Phase 6's `ProjectCreator` | `NullProjectCreator`; the control is not rendered and the route 404s | **6** | [D-P5-1] |
| F10 | **6** — the entitlement supersede on `ProjectValueRevised` | Phase 10/11's listener | Phase 6 records the revision and says nothing about commission; the spine decides | **10/11** | phase-06 §13.1, spine §6.6 case 8 |
| F11 | **7** — §99's P&L including salaries | Phase 13's `RecordPayrollExpense` on `PayrollRunPaid` | Phase 7 creates no `expenses` row; the event is emitted `afterCommit` and simply has no listener until 13 | **13** | D44, F-3.9 |
| F12 | **7** — `employee_documents.file_path` / `leave_requests.attachment_path` migrating to the general store | `attachments` (Phase 6 — **already exists**) | none needed; the ask in phase-07 §13.1 naming "Phase 22" is stale and resolves to Phase 6's `attachments` | **6** (already) | F-2.8, F-13.2 |
| F13 | **8** — wallet / rule / payout-account / commission screens | the spine's tables | the five spine module switches stay **disabled**: `Gate::before` 403s, `Sidebar` hides, `DashboardRegistry` drops the widgets | **spine set in the same release** | [D-P8-1] |
| F14 | **17** — `student_topic_progress` from an assessment | Phase 20 writing through `CourseProgressService(source: assessment)` | `institute.progress_from_assessment` stays **off**, and its help text says why | **20** | phase-19-23 §13.1 |
| F15 | **14** — `courses.grade_scale_id` | Phase 20's guarded migration | the grade fallback chain works without it (exam → global setting) | **20** | phase-19-23 §13.1 |
| F16 | **every phase 5-18 shipping a notification** | `NotificationRegistry` + `NotificationService` | each phase ships its notification class on the database channel; after 22 lands, **no phase calls `$user->notify()` directly** | **22** | D55, INV-22-7 |
| F17 | **13** — the **company-wide** (all-collaborator) payout and commission period totals for P&L block C | a spine method taking a nullable `Collaborator` or a sibling all-collaborator form | Phase 13 must never sum payouts or ledger rows itself (INV-26), so block C's memo is withheld until the spine publishes it | **10-12** (spine §6.2) | phase-13 §13.1 — **open; see §10** |
| F18 | **3-23** — the four manifests' drift check | Phase 24's `audit:manifest` | each phase appends its rows by hand; the CI gate arrives with 24 | **24** | D60 |

---

## 5. Deferred-FK completion ledger

A guarded `Schema::hasTable()` FK is skipped — **permanently** — when the release that runs it predates
its target. `migrate:fresh` hides this, incremental installs do not. Every deferred constraint therefore
needs a **named promoter**: the phase that ships a thin, idempotent, absent-constraint-checked migration
adding it. Phase 13 §2.10 is the pattern; the rows marked *new* close the same hole elsewhere.

| Deferred constraint | Column declared by | Target shipped by | Promoter | Note |
|---|---|---|---|---|
| `leads.service_id` → `services.id` · `leads.contact_inquiry_id` → `contact_inquiries.id` · `clients.lead_id` → `leads.id` | 5 | 4 / 4 / 5 | **5** | `add_crm_deferred_foreign_keys`; targets already exist |
| `leads.referral_visit_id` → `collaborator_referral_visits.id` | 5 | 9 | **9** | phase-05 §13.1; prune guard also checks it (INV-R6) |
| `lead_conversions.project_id` → `projects.id` | 5 | 6 | **6** *(new)* | add to Phase 6's `add_project_fks_to_crm_tables`; unassigned before this file |
| `lead_conversions.collaborator_referral_id` → `collaborator_referrals.id` | 5 | 10 (S7) | **10** *(new)* | add to the spine's file 20; unassigned before this file |
| `testimonials.client_id` · `portfolio_items.client_id` → `clients.id` | 4 | 5 | **5** | phase-04 §13 asks Phase 5 for exactly this |
| `team_members.employee_id` · `job_applications.employee_id` · `job_applications.department_id` | 4 | 7 | **7** | `add_employee_fks_to_<table>`, [D-HR-1] rule 2 |
| `student_reviews.*` / `success_stories.*` → `students` / `courses` / `batches` | 4 | 10 (E5/E6) / 14 / 16 | **14-17** *(new)* | fold into `add_institute_fks_to_fee_tables` or a sibling file |
| `projects.collaborator_id` · `project_members.collaborator_id` · `tasks.assigned_collaborator_id` · `time_entries.collaborator_id` · `time_entry_segments.collaborator_id` | 6 | 8 | **8** | `add_collaborator_fks_to_project_tables`, all `restrictOnDelete` ([D-P6-1]) |
| spine file 20 → `students` `student_admissions` `courses` `batches` | 10 | 10 (E5/E6) / 14 / 16 | **10** for `students`/`student_admissions`; **14-17** for `courses`/`batches` *(new)* | `add_institute_fks_to_fee_tables`, idempotent, absent-constraint-checked |
| spine file 21 → `collaborator_referral_visits` | 10 | 9 | **9** *(new)* | the spine set runs before Phase 9's table exists, so file 21's guard skips |
| spine file 21 → `invoices` `payment_methods` | 10 | 13 | **13** | `add_finance_foreign_keys_to_payment_tables` (phase-13 §2.10) — already named |
| `course_inquiries.contact_inquiry_id` / `.referral_visit_id` · `students.referral_visit_id` · `student_applications.referral_visit_id` | 14-17 | 4 / 9 | **14-17** | targets exist by S13; declared guarded for `migrate:fresh` order safety |
| `teachers.employee_id` → `employees.id` | 16 | 7 | **16** | inline; `employees` exists from S6 ([D-IN-1]) |
| `courses.grade_scale_id` → `grade_scales.id` | 20 | 20 | **20** | guarded migration on Phase 14's table; Phase 14 must not create the column |
| `assignments.course_topic_assignment_id` · `course_materials.course_topic_id` | 19 | 14 | **19** | inline |

**Rule.** A promoter migration creates **no column**, changes no definition, checks
`information_schema` before each `addForeignKey`, and its `down()` drops only what it added. A guard whose
target is missing **at its promoter's slice** fails loudly with an instruction — it never silently skips.

---

## 6. Shared foundations

One row per cross-phase foundation, with the phase that must ship it first. Anyone who re-creates one of
these has created a merge conflict, not a variant (R3).

| Foundation | Owning phase | Exact surface | Consumers | Authority |
|---|---|---|---|---|
| `App\Support\Money` + `RemainderPlacement` | **1** | `add` `sub` `mul` `div` `percentage` `percentageOf` `compare` `isZero` `isNegative` `abs` `min` `max` `sum(array)` `round($v,$scale=2)` `roundTo` `prorate` `distribute(...,RemainderPlacement)` `toMinor` `fromMinor` `format` — bcmath only, intermediate scale 6, final half-up at 2, strings in and out | 5, 6, 7, 10-12, 13, 14-17, 18, 19-23, 24 | F-4.11 |
| `PermissionRegistry` (the only place a permission name exists) | **1** | `{module_slug}.{ability}`; later phases **append** idempotently (final ≈112 modules) | every phase | D4, F-6.4, R8 |
| `SettingsRegistry` / `SettingsService` / `DashboardRegistry` / `DateRange` / `Format` / `ModuleService` | **2** | groups + typed fields; one declaration per widget key | 3-25 | phase-02 §2-§3, F-8.3 |
| **the media / attachment subsystem, tier 1** — `media_assets` + `MediaService` + `ImageProfile` + `website_section_media` | **3** | `store()`, 8 profiles, WebP derivatives, `srcset`, `recountUsage()` over every `*_media_id` and `portfolio_item_media` | 4, 14-17 | D24, F-2.4 |
| **the media / attachment subsystem, tier 2** — `attachments` + `AttachmentVisibility` | **6** | one polymorphic store; `visibility` ∈ `internal`/`team`/`client`; the `files` module slug governs it; five named specialised stores are the only exceptions | 5, 7, 8-9, 19-23, **22** | F-2.8, F-13.2 |
| **the HTML sanitiser** — `App\Support\RichText::sanitize()` | **3** | `mews/purifier`, applied on write **and** on render; every `{!! !!}` registered in `raw-output-allowlist.php` naming it | 4, 21, 24 | D25, F-2.5 |
| `SeoService` + `seo_meta` + `SitemapRegistry` | **3** | `save(Model\|string,array): SeoMeta`, `completeness()`; one `SitemapUrlProvider` per entity | 4, 14, 19-23 | D23, F-2.3 |
| **`DocumentNumberService`** | **5** | `next(string $prefixKey, string $counterKey, string $pad = '%06d'): string` + `reserve(string $counterKey, ?string $periodKey = null, ?string $periodValue = null): int`; `SELECT … FOR UPDATE` on the counter's `settings` row inside the caller's transaction, one retry on 1062 | 6, 7, 8, 10, 13, 14-17, 18, 19-23 | D27, F-4.1, F-4.12 |
| the client-panel spine — `routes/client.php`, `client.*` names, `ClientPortalRegistry`, `ClientPortalSection`, `client.context` | **5** | later phases append sections; **every** client route carries `client.context` | 6, 10-12, 13, 22 | D31, F-6.2, F-12.3 |
| `App\Support\CsvWriter` | **5** | streaming generator + `chunkById`; prefixes `=`,`+`,`-`,`@`,TAB,CR with `'` | 13, 19-23 | phase-05 §6.10 |
| **the shared Priority / source / status enums** | — | `Priority` (**6**) · `InquirySource` 11 cases (**4**) · `ContentStatus` 4 cases (**3**) · `EmploymentType` 7 cases (file **4**, semantics **7**) · `PaymentMethod` + `LedgerEntryType` (**7**) · `CommissionCalculationType` (**6**) · `CommissionRuleSource` 3 cases (**10**) · `Gender` (**14-17**) · `AttachmentVisibility` + `CommentVisibility` (**6**) · `ExportFormat` (**13**, `excel` case added by 23) · `AttendanceStatus` (7) and `StudentAttendanceStatus` (14-17) are **two names on purpose** | as listed | resolutions §2.2, F-5.1…F-5.10, E9 |
| **the report exporter + the print layout** — `ReportResult`, `ReportExporter::export(ReportResult, ExportFormat): StreamedResponse`, `resources/views/layouts/print.blade.php` | **13** | csv / xlsx / pdf, streamed, never `->get()` into memory; `FinanceReportService::report()` returns a `ReportResult` | 18, 21, 23 | F-4.14, F-11.5 |
| `PayslipService` | **7** | `render(PayrollRunItem): View`, `export(PayrollRun, string $format)` — **it already exists**; nobody adds a second | 23 | F-4.13 |
| the money write paths — `PaymentService`, `LedgerWriter`, `ReferralService`, `CommissionRuleService`, `CommissionEntitlementService`, `CollaboratorWalletService`, `CollaboratorStatementService`, `PayoutService` | **10** (12 for the last three) | the **only** insert paths for a payment, a ledger row, a referral or a payout; nobody else sums money | 8-9, 11, 13, 18, 19-23, 24 | D39, INV-21, INV-26 |
| `StudentFeeService` | **18** | `issue`, `generateStructure`, `transferPayment`, `addDiscount`, `summaryFor`, `reassignBatch`, `withinServiceContext`, `outstandingFor` + `FeeSummary` | 14-17, 19-23 | F-4.6 |
| `ScheduleClashDetector::check(SlotCandidate): ClashReport` | **16** | one generic check under parent row locks + a nightly verifier | 20, 22 | D47, F-4.7 |
| `SecureFileService` | **19** | the one uploader for 19-22; private disk, streamed by a controller re-running the permission chain (D21) | 20, 21, 22 | phase-19-23 §1.3 |
| `NotificationRegistry` + `NotificationService` | **22** | every notification declared; a disabled `notifications` module is a logged no-op that never rolls back a business write | 5-21 retrofit | D55 |
| the four manifests (`screen-`, `route-guard-`, `upload-`, `index-manifest.php`) | files **3**, drift check **24** | every phase appends its own rows; every FK named in a `Keys` block has an `index-manifest` row | 3-23 | D60, F-9.2, F-12.5 |
| the DTO namespaces | — | `App\DataObjects\Finance\RefundData` (**10**) · `App\DataObjects\Collaborator\ReferralContext` (**10**) · `App\DataObjects\Institute\SlotCandidate` + `ClashReport` (**16**) · `App\DataObjects\Institute\FeeSummary` (**18**) | as listed | F-4.2, F-4.3, F-4.7, F-4.6 |

---

## 7. Ready-to-build verdict per phase

Assessed against the state recorded in `DEVELOPMENT_LOG.md` §5 on 2026-09-12: Phase 1 `[~]`
(remediation in progress), Phase 2 contract written, 3-25 not started.

| Phase | Verdict | Exact prerequisite / note |
|---|---|---|
| **1** | **IN PROGRESS — must close first** | finish the 2 HIGH + 6 medium remediation findings; then the three unticked rows (browser light/dark pass, rollback of all 14 migrations against `my_office_test`) **and** the three contract items E1, E2, E3. Nothing else may start until the `Money` surface and D20 exist |
| **2** | **BLOCKED-BY Phase 1** | shares `Admin/DashboardController`, `Admin/ModuleController`, `routes/admin.php` and `tailwind.config.js` with Phase 1 — they cannot run concurrently |
| **3** | **BLOCKED-BY Phase 2** | `SettingsRegistry` + `SettingsService` + `settings.is_public` + `ConfigureFromSettings` + `Format::app_date()`; plus Phase 1's D20 fix (every public/panel route depends on it) |
| **4** | **BLOCKED-BY Phase 3** | `layouts/site.blade.php`, the public middleware stack, `SitemapRegistry`, `MediaService`, `RichText`, `seo_meta`, and the CMS catch-all ordered **after** Phase 4's public routes. Must not run concurrently with 3 (shared `routes/web.php`) |
| **5** | **BLOCKED-BY Phase 4** | `contact_inquiries` + `InquiryRouter` + `InquiryTarget` (F-2.1), `services.id` (`leads.service_id`), `InquirySource` |
| **6** | **BLOCKED-BY Phase 5** | `clients` (`id`, `user_id`), `leads.id`, `DocumentNumberService`, `routes/client.php` + `ClientPortalRegistry` |
| **7** | **READY after Phase 5** | needs **nothing** from Phase 6 (D32). Prerequisites: `DocumentNumberService` (5) and the `EmploymentType` file (4, E9). May be built in parallel with 6 if file ownership is partitioned — 6 and 7 share no file |
| **8** | **BLOCKED-BY Phase 6 + Phase 7** | 6: `projects`, `project_members`; 7: `PaymentMethod` + `LedgerEntryType`. **And** the spine's migration set must merge in the same release, immediately after Phase 8's own migrations (E7) |
| **9** | **BLOCKED-BY Phase 8 + the spine set** | `collaborator_referrals` must exist before `ReferralService::attach()` can be called; Phase 9 then ships two FK promotions (§5) |
| **10** | **BLOCKED-BY Phase 6, 7, 8** | schema: merged in S7. Code: the shared services need `projects` (6) and `collaborators` (8). Its student-side tests need E5/E6 — which is why Phase 10 ships `students` + `student_admissions` itself |
| **11** | **READY after Phase 10's shared services** | `projects.net_value`, `project_milestones.amount`, `project_value_revisions` (6) + §6.3's shared services (10). 10 and 11 may run in parallel once those services exist |
| **12** | **BLOCKED-BY Phase 10 + Phase 11** | needs ledger rows to reconcile against; `wallet == SUM(ledger)` cannot be proved on an empty ledger |
| **13** | **BLOCKED-BY Phase 12** | `CollaboratorWalletService` / `CollaboratorStatementService` for P&L block C, plus 10-12's payments and reversals. Also blocked on `project_milestones.name` (6) and `projects.project_manager_id` as a `users.id` (6, F-3.11) |
| **14** | **BLOCKED-BY Phase 13** *(sequence, not content)* | content prerequisites are 3 (`seo_meta`, `SitemapRegistry`, `faqs`, the section renderer) and 5 (`DocumentNumberService`); it sits after 13 only because the tracker orders it there. Could start once 3 and 5 are green if file ownership is partitioned |
| **15** | **BLOCKED-BY Phase 14** | `courses`; plus 4 (`contact_inquiries`, `InquirySource`), 9 (referral capture), 10 (`ReferralService::resolveCode()`, `students`, `student_admissions`), 5 (`reserve()`). Its fee-collection stage is deferred surface F3 |
| **16** | **BLOCKED-BY Phase 15** | `students`, `student_applications`; plus 14 (`courses`) and 7 (`employees`, `EmployeeUpdated`) |
| **17** | **BLOCKED-BY Phase 16** | `class_sessions`, `student_batch_enrollments`, `batches` |
| **18** | **BLOCKED-BY Phase 13, 16, 17** | 13: `ReportResult` + `ReportExporter` + `layouts/print.blade.php`; 16: `batches.start_date`; 17: nothing directly, but 18 follows it in the tracker. Also 10 (4 fee tables + `PaymentService`), 12 (wallet services), 14/15 (course + admission figures) |
| **19** | **BLOCKED-BY Phase 18** *(sequence)* | content prerequisites: 14 (`course_topic_assignments`, `course_topic_resources`), 16 (`roster()`), 6 (`attachments`), 17 (progress) |
| **20** | **BLOCKED-BY Phase 19** | `SecureFileService`; plus 17 (`CourseProgressService`) and 16 (`check(SlotCandidate)`) |
| **21** | **BLOCKED-BY Phase 20** | `exam_results`; plus 18 (`outstandingFor()`), 13 (print layout), 17 (attendance/progress caches), 3 (`site`, `RichText`) |
| **22** | **BLOCKED-BY Phase 21** *(sequence)* | content prerequisites: 6 (`attachments`, `task_comments`), 5 (`ClientPortalRegistry`), 16 (`check(SlotCandidate)`), 19 (`SecureFileService`) |
| **23** | **BLOCKED-BY Phase 22** | needs every owning service to exist (it may not define a figure of its own): 7 `PayslipService`, 12 wallet/statement, 13 `FinanceReportService` + the three reporting artefacts, 17 attendance/progress, 18 fee scopes, 22 unread counters |
| **24** | **READY NOW, rolling; CLOSES after Phase 23** | each phase appends its four manifest rows as it ships, so 24 accumulates from S2 onward. It cannot be ticked before 23 |
| **25** | **BLOCKED-BY Phase 24** | the hardening matrix must be green before a deploy runbook means anything |

**Summary.** Exactly one phase is buildable today: **Phase 1's close-out**. Two are one prerequisite
away: Phase 2 (after 1) and — once 5 is green — Phase 7 in parallel with 6.

---

## 8. Per-phase definition-of-done delta

`CLAUDE.md` §8's ten items apply to **every** phase and are not repeated. Three deltas apply to every
phase from 3 onward and are also not repeated per row:

- **(a)** its rows added to all four manifests of §6, and from Phase 24 onward `audit:manifest --check` green (**D60**);
- **(b)** every decision number **cited** from `resolutions.md` §4, never invented (**F-10.1**);
- **(c)** every FK named in its own `Keys` block has an `index-manifest.php` row (**F-9.2**).

| Phase | Definition-of-done delta |
|---|---|
| **1** | `Money`'s 20 methods + `RemainderPlacement`, tested (E1, E2) · `permissionModuleMap()` returns null for an unregistered prefix + the four-panel test (**D20**) · all 14 migrations rolled back **executed**, not merely asserted non-empty · browser light/dark pass with a clean console · `PermissionRegistry` documented as append-only (F-6.4) · `ModuleGroup` vs `is_core` distinction written down (F-6.5) |
| **2** | every `DashboardRegistry` key unique across all 25 phases · `SystemHealthWidget` stays Phase 2's (F-8.3) · `modules.depends_on` resolution proved with an impact preview and a cascade · a settings group reset is audited per key |
| **3** | INV-1…INV-16 · `cms:verify-published-snapshots` green · one `website` group, label "Website & Forms", sort **75**, 35 merged keys (F-6.3) · **no** public route carries `module:` or `can:`; `site_module` 404s (**D26**) · `RichText::sanitize()` on write **and** render (**D25**) · publish-by-snapshot + version-stamp invalidation (**D22**) · cites D19, D22, D23, D24, D25 · the four manifest files created (E4) |
| **4** | zero SEO columns, zero second uploader, zero second sanitiser (**D23**, **D24**, **D25**) · one `ContentStatus` (F-5.1) · `EmploymentType` shipped verbatim per resolutions §2.2 (E9) · `contact_inquiries` PII behind `view_logs`, Digital Marketer without `view_any`, per-opening scope for a hiring manager (F-12.4, **H7**) · `job_applications.cv_path` on the private disk with an `upload-manifest` row (**D21**) · `uq_leads_inquiry` honoured by `InquiryRouter` (F-3.7) |
| **5** | `DocumentNumberService` with `next()` **and** `reserve()`, concurrency-tested, no local fallback anywhere (**D27**) · owns `routes/client.php` and every `client.*` name; **every** client route carries `client.context` (**D31**, F-12.3) · `uq_leads_inquiry` is the idempotency guard — a 1062 returns the existing lead, no SELECT guard (F-3.7) · duplicates warned, never DB-blocked (**D29**) · `leads.view_any` vs `.view` (**D30**) · `CsvWriter` formula-injection test · both Null capability bindings with `isAvailable()` (**D28**) |
| **6** | INV-P1…INV-P17 · `projects:verify-constraints` green · raw-SQL migrations (STORED columns, CHECKs, triggers) **fail loudly** · a collaborator's project scope reads `project_members` **or** an active `collaborator_referrals` row — never a snapshot column, with a test asserting a hand-written snapshot grants nothing (**D37**, F-8.2) · client task visibility is the AND of `projects.client_can_see_tasks` and `tasks.is_client_visible` (F-3.1) · `attachments` morph map includes `Collaborator` and `Invoice`, each with a policy (F-13.2) · cites D19, D32, D33, D34, D35 |
| **7** | a payroll run is immutable from `lock()` — no unlock path exists in code (**D36**) · `hr:verify-constraints` + `hr:verify-leave-balances` green · the 11 append-only tables carry no `deleted_at` and cite **D19** (+ **D16** for payroll/advances) · `PaymentMethod` / `LedgerEntryType` cases **byte-identical** to spine §3 (E24, F-5.4) · duties point at `employees.id`, acts/assignment/timers at `users.id` (**D32**) · `PayslipService::render()` / `::export()` exist with the published signatures (F-4.13) · `schedule:work` documented as **required** |
| **8** | INV-C1…INV-C7 · the spine set merged in the same release + both backfills run (E7, **[D-P8-1]**) · **no `SUM(` over a spine table** anywhere in this phase, asserted statically (FT-C21, INV-26) · encrypted payout details never in a response body, log line, activity diff, export, mail or exception payload (INV-C6) · a collaborator with financial history is never force-deletable, with the reason (INV-C5) · `add_collaborator_fks_to_project_tables` idempotent |
| **9** | INV-R1…INV-R6 · the six-rank precedence ladder, with an authenticated staff pick always winning (**D38**) · the losing candidate preserved via the spine's `recordLosingCandidate()`, never written locally (F-4.4) · the browser never posts a code, only a visit token (INV-R2) · the prune guard checks **both** `collaborator_referrals.referral_visit_id` and `leads.referral_visit_id` (F-3.5, INV-R6) · the activity feed renders only `visibleProperties()` keys and never `reason`, with a test (F-12.7) · the two FK promotions of §5 |
| **10** | spine INV-1…INV-26 · the 21-file set merged **as one unit** (spine R-13) · `financial:verify-constraints` green · `student_fees.generation_key` + `uq_sf_generation` present; generators INSERT and treat 1062 as "already generated" — no SELECT-under-lock guard (F-3.15) · §120 **FT-01…FT-05, FT-09** by those exact method names with `@group financial-120` · every money test ends with `assertWalletMatchesLedger()` · exactly one calculation site, reached only via `afterCommit` → unique job chain (**D39**) · ships `students` + `student_admissions` (E5, E6) and two FK promotions (§5) · cites D16, D17, D18, D39 |
| **11** | §120 **FT-06…FT-08** by name, `@group financial-120` · reads `projects.net_value` and `project_milestones.amount`, never recomputes them (INV-P2) · a project-level override resolves as `rule_source = project_override`, with **no** silent fallback to a global default rate (F-5.6) · `assertWalletMatchesLedger()` after every test |
| **12** | §120 **FT-09** at wallet level · `CommissionReconciliationService`'s eight checks green, and `wallet.available == SUM(ledger)` asserted after **every** scenario · a payout allocates **named ledger entries with partial amounts**, never a running balance (**D18**) · `payoutsPaidTotal()` and `commissionAccruedTotal()` published and used by 13 and 23 (F-4.8) · `PaymentReversalRejected` handled (F-4.9) |
| **13** | `invoices.paid_amount` / `refunded_amount` / `balance_amount` derived from one canonical SQL; **nothing `increment()`s them** (**D40**) · `finance_reversals` append-only with a `BEFORE DELETE` trigger (**D41**) · an invoice number is assigned once at issue, never to a draft, never reused (**D42**) · `project_payments.invoice_id` movable only by `InvoiceService`, reason-mandatory, audited, double-gated, zero commission effect (**D43**) · one `approved` expense per paid payroll run, unique on `(source_type, source_id)` (**D44**) · `expenses.receipt_path` and `invoices.pdf_path` on the private `local` disk with `upload-manifest` rows (**D21**, F-12.5) · `ProjectManagerScope` covers `expenses` too (F-12.6) · ships `ReportResult` + `ReportExporter` + `layouts/print.blade.php` (E25) · `add_finance_foreign_keys_to_payment_tables` idempotent (§5) · `assertWalletMatchesLedger()` on every payment test |
| **14** | no `course_faqs` — course FAQs are `faqs` rows with `faqable_type = Course` (F-2.2) · `seo_meta` by `route_key` + a `SitemapUrlProvider` registered; zero SEO columns (**D23**) · every `*_percentage` / `*_rate` is `decimal(8,4)`; `estimated_hours` is `decimal(10,2)` (F-7.1, F-7.3) · the public catalogue renders through `PublicCourseService` only, and every Apply/Inquire/WhatsApp URL is built by it so `?ref=` is never dropped |
| **15** | the §68 pipeline carried by `student_admissions.stage`, with `students.status` and `course_inquiries.status` advanced in lockstep by `AdmissionService` alone (**D45**) · INV-I1 (no money row created) and INV-I2 (agreed figures frozen at the first charge) · **reuses** `students` + `student_admissions`, creates neither (E5, E6) · `course_inquiries.contact_inquiry_id` + `uq_ci_inquiry`, and `.referral_visit_id` (F-3.8, F-3.14) · `StudentNumberService` calls `DocumentNumberService::reserve()` — no local counter (F-4.12) · `add_institute_fks_to_fee_tables` (§5) · the fee-collection handover renders Phase 18's form and advances only on a real charge (F3) |
| **16** | attendance and coverage attach to a dated `class_sessions` row, never a timetable rule (**D46**) · overlap enforced by `ScheduleClashDetector` under parent row locks + a nightly verifier; **not** by a unique index (**D47**) · capacity by a locked recount; `batches.current_students` has no authority (**D48**) · `timetable:verify-clashes` green · publishes the generic `check(SlotCandidate)` + both DTOs (E26) · `teachers.employee_id` is a duty and `teachers.salary` is display-only/NULL when linked (**D32**) |
| **17** | one writer per progress cache (`CourseProgressService`) · `institute:verify-constraints` green · every percentage `decimal(8,4)` (F-7.1) · a cancelled/absent row is never silently counted as 0 |
| **18** | a plan's live lines minus waivers always equal the charge's net fee; discounts redistribute in reverse due-date order (**D49**) · installment numbers never reused or renumbered; a rebuild continues from `MAX + 1` (**D50**) · `fees:verify-plan-integrity` green · **PH18-01…PH18-06** through **real HTTP routes** with a real permission set, reusing the spine's exact method names and `@group financial-120` · creates **no** financial table (F-11.3) · both generators INSERT against `generation_key` (F-3.15) · publishes `summaryFor` / `reassignBatch` / `withinServiceContext` / `outstandingFor` + `FeeSummary` (F-4.6) · registers the three fee widgets, and Phase 10 registers none (F-8.3) · calls Phase 13's exporter and print layout; ships no second one (F-4.14) · `student_fee_payments.approve` registered (F-6.9) |
| **19** | every file streamed by a controller re-running the permission chain; nothing private on the `public` disk (**D21**) · `SecureFileService` is the only uploader for 19-22 (E27) · a mark's ceiling enforced three times — Form Request, service, DB CHECK over a snapshotted `total_marks` (**D51**) · `assignment_submissions.obtained_marks` (not `marks_obtained`) (F-5.10) · marks are `decimal(8,2)`, percentages `decimal(8,4)` (F-7.1, F-7.2) · material access gated by enrolment, never by URL knowledge |
| **20** | **D51** again for `exam_results` · the grade fallback chain exam → `courses.grade_scale_id` → global setting · writes `student_topic_progress` only through `CourseProgressService(source: assessment)` (F14) · ships the guarded `courses.grade_scale_id` (§5) · calls `check(SlotCandidate)` once, never three sequential checks |
| **21** | an issued certificate and ID card are **snapshots**; a wrong one is revoked and reissued, never edited (**D52**) · a print template is sanitised HTML with `{{tokens}}` rendered by a token replacer — never Blade, never `eval`, and `RichText::sanitize()` is the sanitiser (**D53**, **D25**) · public verification behind the `site` alias (F-6.8) · eligibility reads `outstandingFor()`, never its own fee sum |
| **22** | §94's six role pairs in `MessagingMatrix` + one multiselect setting, re-checked on **every** send (**D54**) · every notification declared in `NotificationRegistry` and delivered by `NotificationService`; a disabled module is a logged no-op that never rolls back a business write (**D55**) · SLA behind `support.sla_enabled` (default true), every clock column nullable, off = no sweeper/badge/multiplier (**H3**, F-13.5) · extends the `attachments` morph map only; creates no `files` table and no second client-visibility flag (F-2.6, F-2.8) · retrofits every phase 5-18 notification into the registry (F16) |
| **23** | a report is a `ReportRegistry` declaration delegating every figure to the owning service; **a `SUM()` in a report class is a review failure**, and a withheld column is absent from the query **and** the file (**D56**) · `ExportFormat` gains only the `excel` case; `ReportExporter` is promoted, never duplicated (F-4.14) · reads `payoutsPaidTotal()` / `commissionAccruedTotal()` / `PayslipService::export()` / `AttendanceReportService` / `CourseProgressService` — never a re-implementation (F-4.8, F-4.13) · `report_exports` pruned, never audited |
| **24** | `Model::shouldBeStrict()` outside production as the N+1 audit (**D59**) · the four manifests are the authority for every sweep and `audit:manifest --check` is part of every phase's DoD (**D60**) · **FIN-01** asserts `--group=financial-120` contains **exactly** the nine §120 method names · every fix lands additively in the owning phase's file and leaves that phase's own tests green · no test from another phase deleted, skipped, loosened or renamed |
| **25** | release deploys are directory swaps with a rename rollback (**D57**) · three separated MySQL users: runtime DML, migration DDL, backup read-only (**D58**) · a backup is not done until it has been **restored** to a scratch database and verified (F-13.11) · the queue workers and every scheduler entry documented as required, not optional |

---

## 9. Never-concurrent pairs, and the three legitimate cycles

| Pair | Why they cannot run concurrently | How the cycle is broken |
|---|---|---|
| **3 ↔ 4** | shared `routes/web.php`, `layouts/site.blade.php`, the `website` settings group, the Website sidebar group; and Phase 3's registry needs Phase 4's six section types | order: 3 then 4; Phase 3's unregistered types are skipped (INV-2), its statistics render nothing (INV-12) |
| **6 ↔ 8** | Phase 6 declares five `collaborator_id` columns Phase 8 promotes to FKs | order: 6 then 8; `add_collaborator_fks_to_project_tables` is `Schema::hasTable()`-guarded |
| **14-17 ↔ 18** | 15 calls `StudentFeeService`; 18 needs `students` / `student_admissions` / `courses` / `batches` | order: 14→17 then 18; the fee surfaces are deferred (F3-F5) and E5/E6 removed the schema half of the cycle |
| **1 ↔ 2** | shared `Admin/DashboardController`, `Admin/ModuleController`, `routes/admin.php`, `tailwind.config.js` | order: 1 verified, then 2 |
| **10 ∥ 11** | **these two may run in parallel**, once §6.3's shared services exist and file ownership is partitioned | phase-10-12 §1.3 |
| **6 ∥ 7** | **these two may run in parallel** after Phase 5 — under D32 they share no table, no enum and no file | D32, phase-07 §1.2 |

None of the three cycles is a defect and none may be "fixed" by moving a dependency
(`resolutions.md` §8 row 12).

---

## 10. Owner-applied tracker notes, and what still needs a human

### 10.1 Paste into `DEVELOPMENT_LOG.md` §5 (owner only)

`resolutions.md` §6.3 already supplies the Phase 8 and Phase 18 notes. These three are additional and
come from this file.

```markdown
PHASE 10 — note: this phase's *schema* merges inside Phase 8's release (spine §1.3, [D-P8-1]); the
Phase 10 slot itself is code and screens. The migration set additionally creates `students` and
`student_admissions` verbatim from phase-14-17 §2.14/§2.15, because `student_fees.student_id` is NOT NULL
and the §120 student tests cannot otherwise be written (build-order §3 E5, E6). Phase 15 reuses both
tables and creates neither. Phases 10 and 11 may be built in parallel once the shared services of
phase-10-12 §6.3 exist.

PHASE 15 — note: `students` and `student_admissions` are created by Phase 10's migration set
(build-order §3 E5, E6); Phase 15 reuses them and ships `course_inquiries`,
`course_inquiry_follow_ups`, `student_applications`, `demo_classes`, the services, the pipeline and the
public admission form. The admission wizard's fee-collection step is a handover to Phase 18
(build-order §4 F3) and the `registration -> fee_collection` transition is unreachable until Phase 18
ships.

PHASE 7 — note: under D32 Phase 7 needs nothing from Phase 6, so Phases 6 and 7 may be built in
parallel once Phase 5 is ticked, provided file ownership is partitioned.
```

### 10.2 Contract edits this file requires (apply-agent work, mechanical)

| # | File | Edit |
|---|---|---|
| B1 | `phase-10-12.md` §1.2 / §2.2 | add `students` and `student_admissions` to the migration set as files 00a / 00b, marked "created here on behalf of Phase 15, verbatim from phase-14-17 §2.14/§2.15"; change the 14-15 dependency row from "hard for Phase 10 screens" to "`courses` / `batches` hard for Phase 10 **screens** only; `students` / `student_admissions` shipped here" |
| B2 | `phase-14-17.md` §1.3 / §2.14 / §2.15 | Phase 15 **reuses** `students` and `student_admissions`; add them to its "Must NOT create" column |
| B3 | `phase-04.md` §3 | `App\Enums\EmploymentType` is **created here**, seven cases + `isSalaried()` + `leaveEligibleByDefault()` verbatim from resolutions §2.2; Phase 7 reuses and is the only phase that may change it |
| B4 | `phase-07.md` §3 / §13.1 | `EmploymentType` is "reused, not created — the file ships in Phase 4 (build-order E9)"; delete the stale `files` ask in §13.1 and retarget it to Phase 6's `attachments` (F-12 row) |
| B5 | `phase-06.md` §2 migration list | add `add_project_fks_to_crm_tables` promoting `lead_conversions.project_id` |
| B6 | `phase-09` block of `phase-08-09.md` §2 | add the promotion migration for the spine's `collaborator_referral_visits` FKs (spine file 21's guard skips at S7) |
| B7 | `phase-10-12.md` §2.2 file 20 | add `lead_conversions.collaborator_referral_id`; state that file 20 creates no column and checks `information_schema` per constraint |
| B8 | `phase-14-17.md` §2 migration list | add `add_institute_fks_to_fee_tables` promoting the spine's `courses` / `batches` constraints, and the Phase 4 `student_reviews` / `success_stories` constraints |
| B9 | `phase-03.md` §11 / §13.1 | creating the four manifest files (empty skeletons + Phase 3's own rows) is part of Phase 3's definition of done |

### 10.3 Needs human

| # | Question | Recommended default |
|---|---|---|
| B-H1 | **E5 / E6** — is the owner content for Phase 10's migration set to create `students` and `student_admissions`, so the student commission engine is written **and proved** in one release? The alternative is a split tick on Phase 10 and the §120 student tests first passing in the Phase 18 window, eight phases later. | **Yes, ship them early.** Untested money code is the weaker financial guarantee (golden rule 4); this is the same device F-11.3 already applies to the four fee tables. |
| B-H2 | **F17** — P&L block C needs **company-wide** payout and commission period totals, but the spine publishes only per-collaborator forms. Should the spine add a nullable-`Collaborator` parameter, or a sibling all-collaborator method? | **A sibling method** on each service (`payoutsPaidTotalAll(?DateRange)`, `commissionAccruedTotalAll(?DateRange)`), derived from spine §6.5.1's canonical SQL — a nullable parameter on a money total is easy to pass by accident. Until it exists, block C's memo is withheld, never estimated. |
| B-H3 | Should Phase 7 be built **in parallel** with Phase 6 (they share no file under D32) to shorten the path to Phase 8, or kept strictly sequential? | **Sequential by default**; parallel only with an explicit file-ownership partition, as Phase 1 used. |
