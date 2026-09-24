<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Projects\ProjectCreator;
use App\Contracts\Referrals\ReferralRecorder;
use App\Dashboard\Cms\BlogActivityWidget;
use App\Dashboard\Cms\InquiryRoutingBacklogWidget;
use App\Dashboard\Cms\NewApplicationsWidget;
use App\Dashboard\Cms\NewInquiriesWidget;
use App\Dashboard\Cms\OpenJobsWidget;
use App\Dashboard\Cms\PendingModerationWidget;
use App\Dashboard\Cms\TopViewedPostsWidget;
use App\Dashboard\Institute\FeeCollectedTodayWidget;
use App\Dashboard\Institute\OverdueFeesWidget;
use App\Dashboard\Institute\PendingFeesWidget;
use App\Enums\ExamStatus;
use App\Enums\InquiryType;
use App\Enums\MeetingStatus;
use App\Events\ModuleStateChanged;
use App\Events\SettingsChanged;
use App\Models\Cms\BlogCategory;
use App\Models\Cms\BlogPost;
use App\Models\Cms\BlogTag;
use App\Models\Cms\CmsRevision;
use App\Models\Cms\ContactInquiry;
use App\Models\Cms\CtaBlock;
use App\Models\Cms\Faq;
use App\Models\Cms\FaqCategory;
use App\Models\Cms\JobApplication;
use App\Models\Cms\JobOpening;
use App\Models\Cms\MediaAsset;
use App\Models\Cms\Menu;
use App\Models\Cms\MenuItem;
use App\Models\Cms\Page;
use App\Models\Cms\PortfolioCategory;
use App\Models\Cms\PortfolioItem;
use App\Models\Cms\SeoMeta;
use App\Models\Cms\Service;
use App\Models\Cms\ServiceCategory;
use App\Models\Cms\SitemapGeneration;
use App\Models\Cms\StudentReview;
use App\Models\Cms\SuccessStory;
use App\Models\Cms\TeamMember;
use App\Models\Cms\Technology;
use App\Models\Cms\Testimonial;
use App\Models\Cms\WebsiteSection;
use App\Models\Cms\WebsiteSectionItem;
use App\Models\Collaborator\Collaborator;
use App\Models\Crm\Client;
use App\Models\Crm\ClientContact;
use App\Models\Crm\ClientDocument;
use App\Models\Crm\Lead;
use App\Models\Crm\LeadActivity;
use App\Models\Crm\LeadConversion;
use App\Models\Crm\LeadFollowUp;
use App\Models\Crm\LeadImport;
use App\Models\Finance\Expense;
use App\Models\Finance\FinanceCategory;
use App\Models\Finance\Income;
use App\Models\Finance\Invoice;
use App\Models\Finance\PaymentMethodOption;
use App\Models\Hr\Attendance;
use App\Models\Hr\AttendanceCorrection;
use App\Models\Hr\Department;
use App\Models\Hr\Designation;
use App\Models\Hr\Employee;
use App\Models\Hr\EmployeeAdvance;
use App\Models\Hr\EmployeeDocument;
use App\Models\Hr\Holiday;
use App\Models\Hr\LeaveBalance;
use App\Models\Hr\LeaveRequest;
use App\Models\Hr\LeaveType;
use App\Models\Hr\PayrollRun;
use App\Models\Hr\PayrollRunItem;
use App\Models\Hr\SalaryComponent;
use App\Models\Hr\SalaryStructure;
use App\Models\Hr\WorkShift;
use App\Models\Institute\Assignment;
use App\Models\Institute\AssignmentSubmission;
use App\Models\Institute\Batch;
use App\Models\Institute\Classroom;
use App\Models\Institute\ClassSession;
use App\Models\Institute\Course;
use App\Models\Institute\CourseCategory;
use App\Models\Institute\CourseInquiry;
use App\Models\Institute\CourseLecture;
use App\Models\Institute\CourseMaterial;
use App\Models\Institute\CourseModule;
use App\Models\Institute\CourseTopic;
use App\Models\Institute\CourseTopicAssignment;
use App\Models\Institute\CourseTopicResource;
use App\Models\Institute\DemoClass;
use App\Models\Institute\Student;
use App\Models\Institute\StudentAdmission;
use App\Models\Institute\StudentApplication;
use App\Models\Institute\StudentAttendance;
use App\Models\Institute\StudentCourseProgress;
use App\Models\Institute\StudentFee;
use App\Models\Institute\StudentFeeDiscount;
use App\Models\Institute\StudentFeeInstallment;
use App\Models\Institute\StudentFeeReminder;
use App\Models\Institute\Teacher;
use App\Models\Institute\TimetableEntry;
use App\Models\Module;
use App\Models\Project\Attachment;
use App\Models\Project\Project;
use App\Models\Project\ProjectMember;
use App\Models\Project\ProjectMilestone;
use App\Models\Project\ProjectValueRevision;
use App\Models\Project\Task;
use App\Models\Project\TaskChecklistItem;
use App\Models\Project\TaskComment;
use App\Models\Project\TimeEntry;
use App\Models\Reporting\ReportExport;
use App\Models\Role;
use App\Models\User;
use App\Policies\Cms\BlogCategoryPolicy;
use App\Policies\Cms\BlogPostPolicy;
use App\Policies\Cms\BlogTagPolicy;
use App\Policies\Cms\CmsRevisionPolicy;
use App\Policies\Cms\ContactInquiryPolicy;
use App\Policies\Cms\CtaBlockPolicy;
use App\Policies\Cms\FaqCategoryPolicy;
use App\Policies\Cms\FaqPolicy;
use App\Policies\Cms\JobApplicationPolicy;
use App\Policies\Cms\JobOpeningPolicy;
use App\Policies\Cms\MediaPolicy;
use App\Policies\Cms\MenuItemPolicy;
use App\Policies\Cms\MenuPolicy;
use App\Policies\Cms\PagePolicy;
use App\Policies\Cms\PortfolioCategoryPolicy;
use App\Policies\Cms\PortfolioItemPolicy;
use App\Policies\Cms\SeoMetaPolicy;
use App\Policies\Cms\ServiceCategoryPolicy;
use App\Policies\Cms\ServicePolicy;
use App\Policies\Cms\SitemapGenerationPolicy;
use App\Policies\Cms\StudentReviewPolicy;
use App\Policies\Cms\SuccessStoryPolicy;
use App\Policies\Cms\TeamMemberPolicy;
use App\Policies\Cms\TechnologyPolicy;
use App\Policies\Cms\TestimonialPolicy;
use App\Policies\Cms\WebsiteSectionItemPolicy;
use App\Policies\Cms\WebsiteSectionPolicy;
use App\Policies\Collaborator\CollaboratorPolicy;
use App\Policies\Crm\ClientContactPolicy;
use App\Policies\Crm\ClientDocumentPolicy;
use App\Policies\Crm\ClientPolicy;
use App\Policies\Crm\ClientPortalPolicy;
use App\Policies\Crm\LeadActivityPolicy;
use App\Policies\Crm\LeadConversionPolicy;
use App\Policies\Crm\LeadFollowUpPolicy;
use App\Policies\Crm\LeadImportPolicy;
use App\Policies\Crm\LeadPolicy;
use App\Policies\Finance\ExpensePolicy;
use App\Policies\Finance\FinanceCategoryPolicy;
use App\Policies\Finance\IncomePolicy;
use App\Policies\Finance\InvoicePolicy;
use App\Policies\Finance\PaymentMethodPolicy;
use App\Policies\Hr\AttendanceCorrectionPolicy;
use App\Policies\Hr\AttendancePolicy;
use App\Policies\Hr\DepartmentPolicy;
use App\Policies\Hr\DesignationPolicy;
use App\Policies\Hr\EmployeeAdvancePolicy;
use App\Policies\Hr\EmployeeDocumentPolicy;
use App\Policies\Hr\EmployeePolicy;
use App\Policies\Hr\HolidayPolicy;
use App\Policies\Hr\LeaveBalancePolicy;
use App\Policies\Hr\LeaveRequestPolicy;
use App\Policies\Hr\LeaveTypePolicy;
use App\Policies\Hr\PayrollRunItemPolicy;
use App\Policies\Hr\PayrollRunPolicy;
use App\Policies\Hr\SalaryComponentPolicy;
use App\Policies\Hr\SalaryStructurePolicy;
use App\Policies\Hr\WorkShiftPolicy;
use App\Policies\Institute\AssignmentPolicy;
use App\Policies\Institute\AssignmentSubmissionPolicy;
use App\Policies\Institute\BatchPolicy;
use App\Policies\Institute\ClassroomPolicy;
use App\Policies\Institute\ClassSessionPolicy;
use App\Policies\Institute\CourseCategoryPolicy;
use App\Policies\Institute\CourseInquiryPolicy;
use App\Policies\Institute\CourseMaterialPolicy;
use App\Policies\Institute\CourseOutlinePolicy;
use App\Policies\Institute\CoursePolicy;
use App\Policies\Institute\DemoClassPolicy;
use App\Policies\Institute\StudentAdmissionPolicy;
use App\Policies\Institute\StudentApplicationPolicy;
use App\Policies\Institute\StudentAttendancePolicy;
use App\Policies\Institute\StudentFeeDiscountPolicy;
use App\Policies\Institute\StudentFeeInstallmentPolicy;
use App\Policies\Institute\StudentFeePolicy;
use App\Policies\Institute\StudentFeeReminderPolicy;
use App\Policies\Institute\StudentPolicy;
use App\Policies\Institute\StudentProgressPolicy;
use App\Policies\Institute\TeacherPolicy;
use App\Policies\Institute\TimetableEntryPolicy;
use App\Policies\ModulePolicy;
use App\Policies\Project\AttachmentPolicy;
use App\Policies\Project\ProjectMemberPolicy;
use App\Policies\Project\ProjectMilestonePolicy;
use App\Policies\Project\ProjectPolicy;
use App\Policies\Project\ProjectValueRevisionPolicy;
use App\Policies\Project\TaskChecklistItemPolicy;
use App\Policies\Project\TaskCommentPolicy;
use App\Policies\Project\TaskPolicy;
use App\Policies\Project\TimeEntryPolicy;
use App\Policies\Reporting\ReportExportPolicy;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use App\Services\Cms\CacheVersion;
use App\Services\Cms\InquiryRouter;
use App\Services\Cms\PublicCache;
use App\Services\Cms\SitemapGenerator;
use App\Services\Hr\EmployeeScopeResolver;
use App\Services\Hr\WorkCalendarService;
use App\Services\Institute\ScheduleClashDetector;
use App\Services\Support\NotificationPreferenceService;
use App\Services\Support\NotificationService;
use App\Support\ClientPortalRegistry;
use App\Support\Cms\PublicFormRateLimits;
use App\Support\Ops\CspBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use App\Support\Cms\SectionRegistry;
use App\Support\Cms\Sections\MarketingSectionTypes;
use App\Support\Cms\Sitemap\BlogCategorySitemapProvider;
use App\Support\Cms\Sitemap\BlogPostSitemapProvider;
use App\Support\Cms\Sitemap\BlogTagSitemapProvider;
use App\Support\Cms\Sitemap\JobOpeningSitemapProvider;
use App\Support\Cms\Sitemap\PortfolioSitemapProvider;
use App\Support\Cms\Sitemap\ServiceSitemapProvider;
use App\Support\Cms\Sitemap\TeamSitemapProvider;
use App\Support\ConfigureFromSettings;
use App\Support\DashboardRegistry;
use App\Support\Inquiry\CrmLeadInquiryTarget;
use App\Support\Institute\Sitemap\CourseCategorySitemapProvider;
use App\Support\Institute\Sitemap\CourseSitemapProvider;
use App\Support\Modules;
use App\Support\ParticipantResolver;
use App\Support\Portal\Sections\DocumentsSection;
use App\Support\Portal\Sections\InvoicesSection;
use App\Support\Portal\Sections\MilestonesSection;
use App\Support\Portal\Sections\NotificationsSection;
use App\Support\Portal\Sections\ProjectsSection;
use App\Support\Portal\Sections\TasksSection;
use App\Support\Projects\NullProjectCreator;
use App\Support\Referrals\NullReferralRecorder;
use App\Support\SettingsRepository;
use App\Support\SiteSettings;
use App\Support\UnreadCounters;
use Closure;
use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Model => policy. Registered explicitly because `Role` extends a vendor model, so Laravel's
     * naming convention cannot discover it.
     *
     * @var array<class-string, class-string>
     */
    private const POLICIES = [
        User::class => UserPolicy::class,
        Role::class => RolePolicy::class,
        Module::class => ModulePolicy::class,

        // phase-03. MediaPolicy does not follow the {Model}Policy naming convention, so explicit
        // registration is required; the rest are listed here for the same one-place reason.
        WebsiteSection::class => WebsiteSectionPolicy::class,
        WebsiteSectionItem::class => WebsiteSectionItemPolicy::class,
        Menu::class => MenuPolicy::class,
        MenuItem::class => MenuItemPolicy::class,
        Page::class => PagePolicy::class,
        CtaBlock::class => CtaBlockPolicy::class,
        Faq::class => FaqPolicy::class,
        FaqCategory::class => FaqCategoryPolicy::class,
        SeoMeta::class => SeoMetaPolicy::class,
        MediaAsset::class => MediaPolicy::class,
        CmsRevision::class => CmsRevisionPolicy::class,
        SitemapGeneration::class => SitemapGenerationPolicy::class,

        // phase-04 §6.11: App\Models\Cms\X → App\Policies\Cms\XPolicy is not a discovery path, so all 15 are explicit.
        ServiceCategory::class => ServiceCategoryPolicy::class,
        Service::class => ServicePolicy::class,
        Technology::class => TechnologyPolicy::class,
        PortfolioCategory::class => PortfolioCategoryPolicy::class,
        PortfolioItem::class => PortfolioItemPolicy::class,
        TeamMember::class => TeamMemberPolicy::class,
        Testimonial::class => TestimonialPolicy::class,
        StudentReview::class => StudentReviewPolicy::class,
        SuccessStory::class => SuccessStoryPolicy::class,
        BlogCategory::class => BlogCategoryPolicy::class,
        BlogTag::class => BlogTagPolicy::class,
        BlogPost::class => BlogPostPolicy::class,
        JobOpening::class => JobOpeningPolicy::class,
        JobApplication::class => JobApplicationPolicy::class,
        ContactInquiry::class => ContactInquiryPolicy::class,
        // phase-05 §9.3: one policy per CRM model (LeadImportRow is authorised through its batch).
        Lead::class => LeadPolicy::class,
        LeadActivity::class => LeadActivityPolicy::class,
        LeadFollowUp::class => LeadFollowUpPolicy::class,
        LeadConversion::class => LeadConversionPolicy::class,
        LeadImport::class => LeadImportPolicy::class,
        Client::class => ClientPolicy::class,
        ClientContact::class => ClientContactPolicy::class,
        ClientDocument::class => ClientDocumentPolicy::class,
        // phase-06 §6.1: one policy per Project model. ProjectValueRevision has view methods only —
        // there is no update or delete ability to grant on an append-only table (INV-P3).
        Project::class => ProjectPolicy::class,
        ProjectMilestone::class => ProjectMilestonePolicy::class,
        ProjectMember::class => ProjectMemberPolicy::class,
        ProjectValueRevision::class => ProjectValueRevisionPolicy::class,
        Task::class => TaskPolicy::class,
        TaskChecklistItem::class => TaskChecklistItemPolicy::class,
        TaskComment::class => TaskCommentPolicy::class,
        Attachment::class => AttachmentPolicy::class,
        TimeEntry::class => TimeEntryPolicy::class,
        // phase-07 §7: one policy per HR model. Six of them are catalogues and share one trait — there is
        // no per-row visibility question for a shift or a holiday, only "does this user hold the ability".
        // salary_structures and employee_advances deliberately expose no edit or delete (§4.1).
        Department::class => DepartmentPolicy::class,
        Designation::class => DesignationPolicy::class,
        Employee::class => EmployeePolicy::class,
        EmployeeDocument::class => EmployeeDocumentPolicy::class,
        WorkShift::class => WorkShiftPolicy::class,
        Holiday::class => HolidayPolicy::class,
        Attendance::class => AttendancePolicy::class,
        AttendanceCorrection::class => AttendanceCorrectionPolicy::class,
        LeaveType::class => LeaveTypePolicy::class,
        LeaveBalance::class => LeaveBalancePolicy::class,
        LeaveRequest::class => LeaveRequestPolicy::class,
        SalaryComponent::class => SalaryComponentPolicy::class,
        SalaryStructure::class => SalaryStructurePolicy::class,
        EmployeeAdvance::class => EmployeeAdvancePolicy::class,
        PayrollRun::class => PayrollRunPolicy::class,
        PayrollRunItem::class => PayrollRunItemPolicy::class,

        // phase-08-09 §9. There is no per-row visibility question on the admin side — a collaborator is
        // visible to anybody who may see the module — so the narrowing that matters is the partner's own
        // record in their own panel, and `forceDelete` is refused outright for everybody (INV-C5).
        Collaborator::class => CollaboratorPolicy::class,

        // phase-13 §4.5: one policy per finance model. The permission opens the door; the document's
        // own state decides what is behind it — an issued invoice is cancelled rather than deleted, a
        // decided expense is voided rather than edited, and the reserved `salaries` category can be
        // neither removed nor switched off.
        Invoice::class => InvoicePolicy::class,
        Expense::class => ExpensePolicy::class,
        Income::class => IncomePolicy::class,
        PaymentMethodOption::class => PaymentMethodPolicy::class,
        FinanceCategory::class => FinanceCategoryPolicy::class,

        // phase-14-17 §4.2. The five outline models share ONE policy: the tree is one thing with one
        // permission, and five near-identical classes would be five places to forget INV-I13.
        CourseCategory::class => CourseCategoryPolicy::class,
        Course::class => CoursePolicy::class,
        CourseModule::class => CourseOutlinePolicy::class,
        CourseTopic::class => CourseOutlinePolicy::class,
        CourseLecture::class => CourseOutlinePolicy::class,
        CourseTopicResource::class => CourseOutlinePolicy::class,
        CourseTopicAssignment::class => CourseOutlinePolicy::class,

        // phase-15: the five records the §68 pipeline runs through. `CourseInquiryFollowUp` has no
        // policy of its own — a follow-up is never reached except through its enquiry, and the
        // enquiry's `logFollowUp` is what authorises writing one.
        CourseInquiry::class => CourseInquiryPolicy::class,
        StudentApplication::class => StudentApplicationPolicy::class,
        Student::class => StudentPolicy::class,
        StudentAdmission::class => StudentAdmissionPolicy::class,
        DemoClass::class => DemoClassPolicy::class,

        // phase-16: the five scheduling records. `StudentBatchEnrollment` has no policy of its own —
        // a seat is never reached except through its batch, and `BatchPolicy::assign` is what
        // authorises moving one.
        Teacher::class => TeacherPolicy::class,
        Classroom::class => ClassroomPolicy::class,
        Batch::class => BatchPolicy::class,
        TimetableEntry::class => TimetableEntryPolicy::class,
        ClassSession::class => ClassSessionPolicy::class,

        // phase-17: the register and the syllabus. `BatchTopicCoverage` and the two derived progress
        // tables have no policy of their own — none of them is ever reached except through the batch
        // or the enrolment whose progress row IS the thing being authorised.
        StudentAttendance::class => StudentAttendancePolicy::class,
        StudentCourseProgress::class => StudentProgressPolicy::class,

        // phase-18: the fee document side. `StudentFeePayment` and `PaymentReversal` keep the spine's
        // policies -- a receipt is money and its rules belong with the service that writes it.
        StudentFee::class => StudentFeePolicy::class,
        StudentFeeInstallment::class => StudentFeeInstallmentPolicy::class,
        StudentFeeDiscount::class => StudentFeeDiscountPolicy::class,
        StudentFeeReminder::class => StudentFeeReminderPolicy::class,

        // phase-19-23 §9.
        CourseMaterial::class => CourseMaterialPolicy::class,
        Assignment::class => AssignmentPolicy::class,
        AssignmentSubmission::class => AssignmentSubmissionPolicy::class,

        // Phase 23.
        ReportExport::class => ReportExportPolicy::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One settings payload per request, shared by the setting() helper (phase-01 §3).
        $this->app->singleton(SettingsRepository::class);

        // phase-03 (D22): one cache version stamp and one bump batch per request or queued job.
        $this->app->scoped(CacheVersion::class);

        // phase-24-25 6.3: one CSP nonce per request, and it must be ONE. The header carries the
        // nonce and every inline script in the layouts stamps it; a fresh builder per resolve would
        // hand the views a different value from the one the browser was told to accept, and every
        // inline script on every page would be refused.
        $this->app->scoped(CspBuilder::class);

        // phase-07 §6.1: one calendar per request. WorkCalendarService caches holidays so that resolving a
        // month is one query rather than fifteen hundred; a fresh instance per resolve would give each
        // service its own cache, and a holiday edited mid-request would be stale in one of them.
        $this->app->scoped(WorkCalendarService::class);
        $this->app->scoped(EmployeeScopeResolver::class);

        // phase-22 §6.17: one identity cache per request. A meeting screen renders the same twenty
        // participants in the list, the guest list and the attendance form; resolving each of them
        // against five profile tables three times over is fifteen queries to answer one question
        // that cannot have changed between them.
        $this->app->scoped(ParticipantResolver::class);

        // phase-22 §6.19: the topbar of all five panels asks for the same three counts, and the bell
        // for the same page of rows, several times per render. Both memoise per user; a fresh
        // instance per resolve would give the layout, the drawer and the mobile header a cache each.
        $this->app->scoped(UnreadCounters::class);
        $this->app->scoped(NotificationService::class);
        // The preference rows are a per-request cache with exactly one owner; a fresh instance per
        // resolve would give the reader and the writer a copy each, and the reader's would be stale.
        $this->app->scoped(NotificationPreferenceService::class);

        // phase-03 §5.3: the public views' only settings reader (stateless, so a singleton).
        $this->app->singleton(SiteSettings::class);

        // StatisticsProvider is deliberately left unbound (a fresh instance per resolve): its memo is
        // per instance, and sharing one across a request would outlive a publish's cache-stamp bump.

        // phase-04 §6.10.1: one router per process, so a target Phase 5 / 14-17 registers is the one routing sees.
        $this->app->singleton(InquiryRouter::class);
        // phase-05 [D-P5-1] / E13 / D28: the two write-side capability contracts. bindIf, so Phase 6 (ProjectCreator) and
        // Phase 9/10 (ReferralRecorder) rebind them with a plain bind() from their own provider, in either order.
        $this->app->bindIf(ReferralRecorder::class, NullReferralRecorder::class);
        $this->app->bindIf(ProjectCreator::class, NullProjectCreator::class);

        // phase-04 §8.11 / phase-03 §6.1: the nine public section types. Guarded, because the registry's runtime list
        // is static and outlives one application instance (every test boots a fresh one; a second register() throws).
        $knownSectionTypes = SectionRegistry::keys();

        foreach (MarketingSectionTypes::definitions() as $key => $definition) {
            if (! in_array($key, $knownSectionTypes, true)) {
                SectionRegistry::register($key, $definition);
            }
        }
    }

    /**
     * Bootstrap any application services.
     *
     * The Gate rules come first: registerGateRules() guarantees they are the *first* thing the
     * Gate consults, whatever else has already registered a before-callback by now.
     */
    public function boot(): void
    {
        $this->registerGateRules();
        $this->registerPolicies();
        $this->registerBladeDirectives();
        $this->registerPublicCacheInvalidation();
        $this->registerPhase04();
        $this->registerPhase05();
        $this->registerPhase20();
        $this->registerPhase22();
        $this->registerPhase24();
        $this->configureFromSettings();
    }

    /**
     * phase-24-25 6.4 - the performance runtime.
     *
     * @see self::enforceStrictModels()
     * @see self::logSlowQueries()
     */
    private function registerPhase24(): void
    {
        $this->enforceStrictModels();
        $this->logSlowQueries();
    }

    /**
     * Eloquent strict mode everywhere except production (phase-24-25 6.4).
     *
     * **This is the N+1 audit, and it is the whole of it.** `preventLazyLoading` turns every
     * unguarded relation access into an exception, which means the existing test suite - a thousand
     * tests that already render every screen - becomes an eager-loading test without a line being
     * written for it. There is no way to add a screen with an N+1 and have the suite stay green.
     *
     * The other two catch the mistakes that are worse for being silent:
     *
     *   - `preventSilentlyDiscardingAttributes` turns a `fill()` of a field that is not fillable
     *     into an error rather than a value that quietly did not save. That is the shape of a bug
     *     somebody finds a month later in a figure that was never written.
     *
     *   - `preventAccessingMissingAttributes` turns `$model->totl` into an error rather than null,
     *     and a null that reaches money arithmetic is a zero.
     *
     * **Off in production**, because a missed `with()` must not 500 a paying client. The violation
     * is still reported there - `LazyLoadingViolationException` is logged with the route and the
     * relation by the handler in bootstrap/app.php, and `ops:digest` collects them - so production
     * still tells you about an N+1; it simply does not break to do it.
     */
    private function enforceStrictModels(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
    }

    /**
     * Log a query that took longer than `ops.slow_query_ms` (phase-24-25 6.4).
     *
     * **Once per request, not once per query.** A page that runs the same slow statement forty
     * times would otherwise write forty lines, and the fortieth tells you nothing the first did
     * not. The route name is the part that matters and the part a stack trace does not make
     * obvious.
     *
     * **Bindings are never logged.** The SQL is the shape of the problem; the bindings are the row
     * data the query was about - an email address, a national ID, a salary - and a log file is the
     * one store in this system with no access control on it at all.
     */
    private function logSlowQueries(): void
    {
        // Registered in every environment, including production: this is a report, not a guard,
        // and production is where a slow query actually matters.
        DB::whenQueryingForLongerThan($this->slowQueryThreshold(), static function ($connection): void {
            Log::warning('Slow query threshold exceeded', [
                'connection' => $connection->getName(),
                'route' => Route::currentRouteName() ?? (request()?->path() ?? 'console'),
                'threshold_ms' => (int) setting('ops.slow_query_ms', 250),
                // The statements, shapes only. `getQueryLog()` is empty unless logging is on, which
                // it is in local and testing; in production this is the route and the threshold,
                // which is what a digest needs.
                'queries' => array_map(
                    static fn (array $entry): string => (string) ($entry['query'] ?? ''),
                    array_slice($connection->getQueryLog(), -5),
                ),
            ]);
        });
    }

    /**
     * The slow-query threshold in milliseconds, clamped.
     *
     * Read at boot and wrapped: this runs before a request has necessarily reached a database, and
     * a settings read that throws here would take the whole application down rather than leave one
     * log line unwritten.
     */
    private function slowQueryThreshold(): int
    {
        try {
            $value = setting('ops.slow_query_ms', 250);
        } catch (Throwable) {
            return 250;
        }

        return is_numeric($value) ? max(20, min(60000, (int) $value)) : 250;
    }

    /**
     * phase-03 §6.7 / INV-8: a saved setting the public pages embed (company, contact, SEO, website,
     * maintenance, ...) bumps the public cache version once per save, after commit. Event discovery is
     * off (EventListenerServiceProvider), so the listener is wired here explicitly.
     */
    private function registerPublicCacheInvalidation(): void
    {
        Event::listen(SettingsChanged::class, [PublicCache::class, 'settingsChanged']);

        // A module switch changes what the public pages may show (INV-12, D26): same bump, once per moved module.
        Event::listen(ModuleStateChanged::class, [PublicCache::class, 'moduleChanged']);
    }

    /**
     * Saved settings -> runtime configuration (phase-02 §3): mail transport and credentials (the
     * secret only once the mail manager is built), `app.locale`, and the derived brand palette.
     * Never `app.timezone`: storage is UTC and `localization.timezone` is display-only (D61).
     *
     * Registered last on purpose. It is the only thing in boot() that reads data, and nothing
     * above it may depend on it, so an install with no `settings` table — or with an unreadable
     * cache store — still boots with exactly the Phase 1 behaviour.
     *
     * `ConfigureFromSettings::apply()` already contains every step in its own try/catch and reads
     * through the cached settings payload rather than querying per key; this second net is here
     * because "the app must never fail to boot because of a setting" is worth stating twice.
     */
    private function configureFromSettings(): void
    {
        try {
            ConfigureFromSettings::apply();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * phase-04: the two public-form rate limiters (§6.9 — registered here, there is no RateLimitServiceProvider), the
     * seven content dashboard widgets (§8.12, explicit because they live in app/Dashboard/Cms, not the discovery path)
     * and one sitemap provider per public entity (§13 Phase 3 row, D23). The providers go through Phase 3's
     * SitemapGenerator::extend() seam, which replaces a key on re-registration; SitemapRegistry::register() refuses a
     * second instance under the same key, and its static list outlives each fresh application a test boots. Each
     * piece degrades on its own.
     */
    private function registerPhase04(): void
    {
        PublicFormRateLimits::register();

        /*
        | phase-24-25 6.3 - the three values every error page needs, shared before any of them
        | renders.
        |
        | A composer rather than the layout's own @php block, because Blade captures a child
        | template's sections BEFORE the layout runs: errors/419 needs `$errorBack` inside its
        | @section('actions'), and a variable the layout defines would not exist yet. Computing it
        | in both places would be two copies of the same-origin guard, and one of them would
        | eventually be the one somebody forgot.
        |
        | Everything here is best-effort. This runs while rendering the page that reports a
        | failure, and a settings read that throws would replace an error page with a blank one.
        */
        View::composer('errors.*', static function ($view): void {
            try {
                $name = (string) (setting('company.name') ?: config('app.name', 'My Office'));
            } catch (Throwable) {
                $name = (string) config('app.name', 'My Office');
            }

            try {
                $brand = (string) (setting('branding.brand_color') ?: '#2563eb');
            } catch (Throwable) {
                $brand = '#2563eb';
            }

            // Same origin only, and never the current URL: `url()->previous()` echoes the Referer
            // header, which the client chooses. A "go back" button that follows it is an open
            // redirect on every error page in the application, and one that points at the page you
            // are already on is a loop.
            $back = url()->previous();
            $back = is_string($back) && str_starts_with($back, url('/')) && $back !== url()->current()
                ? $back
                : url('/');

            $view->with([
                'errorAppName' => $name,
                'errorBrand' => preg_match('/^#[0-9a-fA-F]{6}$/', $brand) === 1 ? $brand : '#2563eb',
                'errorBack' => $back,
            ]);
        });

        try {
            DashboardRegistry::registerMany([
                NewInquiriesWidget::class,
                InquiryRoutingBacklogWidget::class,
                PendingModerationWidget::class,
                NewApplicationsWidget::class,
                OpenJobsWidget::class,
                BlogActivityWidget::class,
                TopViewedPostsWidget::class,

                // phase-18 §8.10 (F-8.3). Phase 18 owns these three keys and is the ONLY phase that
                // registers them: the spine and phase-10-12 both used to declare them, and a
                // DashboardRegistry key declared twice is two cards that look identical and disagree.
                FeeCollectedTodayWidget::class,
                PendingFeesWidget::class,
                OverdueFeesWidget::class,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }

        foreach ([
            new ServiceSitemapProvider,
            new PortfolioSitemapProvider,
            new BlogPostSitemapProvider,
            new BlogCategorySitemapProvider,
            new BlogTagSitemapProvider,
            new JobOpeningSitemapProvider,
            new TeamSitemapProvider,
            // phase-14-17 §7.10: the catalogue registers here like every other public entity and never
            // edits the generator (D23). `is_indexable = false` is honoured in the query, not only in a
            // meta tag — telling a crawler not to index a page and then listing it is two instructions
            // that contradict each other.
            new CourseSitemapProvider,
            new CourseCategorySitemapProvider,
        ] as $provider) {
            SitemapGenerator::extend($provider->key(), $provider);
        }
    }

    /**
     * phase-19-23 §2.11 — an exam is a thing that books a teacher, a room and a batch's hour, so it
     * has to be one of the rows `ScheduleClashDetector` scans.
     *
     * **It is registered here rather than added to `OCCUPANTS`**, which is what the detector's
     * `register()` hook exists for: Phase 16 owns that constant, and a later phase editing it is how
     * two phases come to share one list and disagree about it (D47).
     *
     * **Without this the check ran, found no exam source and reported clean** — `ExamService` was
     * already calling `check()` with `ignoreType: 'exam'`, so every exam was clash-checked against
     * classes and demos and against no other exam at all. Two papers could be booked on one batch at
     * overlapping times, and only an *exactly* equal start time was caught, by `uq_ex_batch_slot`.
     *
     * **`live` is every status except cancelled, derived from the enum.** That is deliberately the
     * same rule `active_guard` encodes, so the index and the detector cannot come to different views
     * of which exams hold a slot — and a status added later joins both without being listed twice. A
     * draft occupies its hour: the unique index already says so, and a coordinator sketching three
     * options for one slot is a coordinator who needs telling.
     */
    private function registerPhase20(): void
    {
        ScheduleClashDetector::register('exam', [
            'table' => 'exams',
            'recurring' => false,
            'teacher' => 'teacher_id',
            'classroom' => 'classroom_id',
            'batch' => 'batch_id',
            'date' => 'scheduled_date',
            'start' => 'start_time',
            'end' => 'end_time',
            'live' => [['status', 'in', array_map(
                static fn (ExamStatus $status): string => $status->value,
                array_values(array_filter(
                    ExamStatus::cases(),
                    static fn (ExamStatus $status): bool => $status->holdsTheSlot(),
                )),
            )]],
            'soft_deletes' => true,
        ]);
    }

    /**
     * phase-22 §6.17: a meeting holds the room it books, so the timetable can see it.
     *
     * **Without this, `MeetingService` would check meetings against classes and never against other
     * meetings** — the same hole Phase 20 found in exams and fixed with `registerPhase20()`. Two
     * meetings could book the one boardroom for the same hour and nothing would say so.
     *
     * **The columns are the generated ones**, not `scheduled_at`: the detector compares a date column
     * and two TIME columns, and `2026_09_19_100012_add_clash_columns_to_meetings_table` adds exactly
     * that shape so the detector needs no new branch (D47).
     *
     * **A meeting declares no teacher, and that is a real limitation, stated rather than hidden.** A
     * teacher attends a meeting as a row in `meeting_participants`, not as a column here, and the
     * detector scans one table. So a meeting blocks a *room* and a *batch*, and a teacher double-booked
     * between their class and a meeting is caught by neither. Expressing it would mean teaching the
     * detector to join — a change to Phase 16's class, which D47 puts out of this phase's reach.
     * `MeetingService` therefore warns on the participant clash itself.
     *
     * **`live` is `scheduled` alone.** Completed, cancelled, postponed and missed are all over; a
     * cancelled meeting that kept holding its room would make cancelling pointless.
     */
    private function registerPhase22(): void
    {
        ScheduleClashDetector::register('meeting', [
            'table' => 'meetings',
            'recurring' => false,
            'teacher' => null,
            'classroom' => 'classroom_id',
            'batch' => 'batch_id',
            'date' => 'meeting_date',
            'start' => 'meeting_start_time',
            'end' => 'meeting_end_time',
            'live' => [['status', 'in', array_map(
                static fn (MeetingStatus $status): string => $status->value,
                array_values(array_filter(
                    MeetingStatus::cases(),
                    static fn (MeetingStatus $status): bool => $status->isLive(),
                )),
            )]],
            'soft_deletes' => true,
        ]);
    }

    /**
     * phase-05: the only path from a contact inquiry to a lead (§6.10, F-2.1 — no listener on the inquiry event) and the
     * two client-panel sections Phase 5 owns ([D-P5-1]). Both registries are container singletons, so each fresh
     * application a test boots registers into its own instance; the guards make a second resolution a no-op.
     */
    private function registerPhase05(): void
    {
        $registerTarget = static function (InquiryRouter $router, Application $app): void {
            if ($router->target(InquiryType::TARGET_CRM_LEAD) === null) {
                $router->register($app->make(CrmLeadInquiryTarget::class));
            }
        };

        $registerSections = static function (ClientPortalRegistry $registry): void {
            // phase-06 §7.7 contributes its sections into Phase 5's registry rather than redeclaring a
            // `client.*` route name (D31). A section the registry does not hold answers 404 and shows no
            // nav item, which is how the panel stayed honest before this phase shipped.
            $sections = [
                new DocumentsSection,
                new NotificationsSection,
                new ProjectsSection,
                new MilestonesSection,
                new TasksSection,
                // phase-13 §8.12 contributes the same way: registered here, never by redeclaring a
                // `client.*` route name.
                new InvoicesSection,
            ];

            foreach ($sections as $section) {
                if (! $registry->has($section->key())) {
                    $registry->register($section);
                }
            }
        };

        $this->app->afterResolving(InquiryRouter::class, $registerTarget);
        $this->app->afterResolving(ClientPortalRegistry::class, $registerSections);

        if ($this->app->resolved(InquiryRouter::class)) {
            $registerTarget($this->app->make(InquiryRouter::class), $this->app);
        }

        if ($this->app->resolved(ClientPortalRegistry::class)) {
            $registerSections($this->app->make(ClientPortalRegistry::class));
        }
    }

    /**
     * Authorization policies (phase-01 §6.3).
     */
    private function registerPolicies(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // phase-05 §9.3: ClientPortalPolicy::section() answers for a section key, not a model —
        // Gate::allows('client-portal-section', 'documents'): unregistered or module-off = 404, no permission = 403.
        Gate::define(ClientPortalPolicy::ABILITY, [ClientPortalPolicy::class, 'section']);
    }

    /**
     * The global authorization pre-checks, in the order the contract fixes (phase-01 §6,
     * CLAUDE.md §4):
     *
     *   1. the ability belongs to a module that is disabled and not core → deny everyone,
     *      Super Admin included (D5: a disabled module is closed, its data untouched);
     *   2. the user holds the Super Admin role → allow;
     *   3. return null so spatie resolves the permission / the policy runs.
     *
     * The module is resolved two ways, and both have to work or rule 1 has a hole:
     *
     *   · from the ability name, for a dotted permission string (`projects.view_any`);
     *   · from the subject being authorized, for a policy-style ability (`$user->can('update',
     *     $project)` arrives here as the bare method name `update`). Without that second path a
     *     policy check on a disabled module's model would skip rule 1 entirely and a Super Admin
     *     would be allowed straight through by rule 2 — see Modules::moduleForAbility().
     *
     * Cost per check: in-memory lookups over payloads that are built once and memoised — the
     * permission => module map and the model class => module map (both derived from
     * PermissionRegistry, no query) and the slug => is_enabled map (one query, cached under
     * `modules.enabled.map` and flushed by the Module model on save).
     *
     * Registration order matters as much as the order inside the callback: spatie's
     * PermissionRegistrar adds its own `Gate::before` that returns TRUE as soon as the user holds
     * the permission, and Laravel's Gate stops at the first non-null before-result. A callback
     * that merely appends itself here (boot() runs after every package provider has booted) would
     * land behind spatie's and rule 1 would silently never fire for a permission holder — Super
     * Admin included. promoteToFirstBeforeCallback() is what keeps this one in front, so the
     * contract can have its rules in boot() and still have them decided first.
     */
    private function registerGateRules(): void
    {
        /** @var GateContract $gate */
        $gate = $this->app->make(GateContract::class);

        $rules = function (mixed $user, string $ability, array $arguments = []): ?bool {
            $module = Modules::moduleForAbility($ability, $arguments[0] ?? null);

            if ($module !== null && ! Modules::enabled($module)) {
                return false;
            }

            if ($user instanceof User && $user->isSuperAdmin()) {
                return true;
            }

            return null;
        };

        $gate->before($rules);

        $this->promoteToFirstBeforeCallback($gate, $rules);
    }

    /**
     * Move our before-callback to the front of the Gate's list, so the module denial is decided
     * ahead of spatie's permission resolution no matter which provider registered first.
     *
     * `Gate::before()` can only append, and the order is the whole point here (see
     * registerGateRules()), so the list itself is reordered. It is scoped to Laravel's own Gate
     * implementation and is a no-op for any other one; if the internals ever change shape, the
     * callback stays registered (just appended) and
     * tests/Feature/Modules/PortalModuleProtectionTest fails loudly instead of the rule quietly
     * going missing.
     */
    private function promoteToFirstBeforeCallback(GateContract $gate, Closure $rules): void
    {
        if (! $gate instanceof AccessGate) {
            return;
        }

        $promote = function () use ($rules): void {
            $others = array_values(array_filter(
                $this->beforeCallbacks,
                static fn (mixed $registered): bool => $registered !== $rules,
            ));

            $this->beforeCallbacks = [$rules, ...$others];
        };

        try {
            $bound = Closure::bind($promote, $gate, AccessGate::class);

            if ($bound !== null) {
                $bound();
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * `@module('projects') … @endmodule` — render a block only while the module is enabled
     * (also gives `@unlessmodule` / `@elsemodule`).
     */
    private function registerBladeDirectives(): void
    {
        Blade::if('module', fn (string $slug): bool => Modules::enabled($slug));
    }
}
