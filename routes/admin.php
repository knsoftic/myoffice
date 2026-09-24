<?php

declare(strict_types=1);

use App\Enums\Cms\SectionPlacement;
use App\Enums\LeadStatus;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\ClientContactController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\ClientDocumentController;
use App\Http\Controllers\Admin\Cms\BlogCategoryController;
use App\Http\Controllers\Admin\Cms\BlogPostController;
use App\Http\Controllers\Admin\Cms\BlogTagController;
use App\Http\Controllers\Admin\Cms\ContactInquiryController;
use App\Http\Controllers\Admin\Cms\CtaBlockController;
use App\Http\Controllers\Admin\Cms\FaqCategoryController;
use App\Http\Controllers\Admin\Cms\FaqController;
use App\Http\Controllers\Admin\Cms\JobApplicationController;
use App\Http\Controllers\Admin\Cms\JobOpeningController;
use App\Http\Controllers\Admin\Cms\MediaController;
use App\Http\Controllers\Admin\Cms\MenuController;
use App\Http\Controllers\Admin\Cms\MenuItemController;
use App\Http\Controllers\Admin\Cms\PageController;
use App\Http\Controllers\Admin\Cms\PageRevisionController;
use App\Http\Controllers\Admin\Cms\PortfolioCategoryController;
use App\Http\Controllers\Admin\Cms\PortfolioImageController;
use App\Http\Controllers\Admin\Cms\PortfolioItemController;
use App\Http\Controllers\Admin\Cms\PublicCacheController;
use App\Http\Controllers\Admin\Cms\SectionController;
use App\Http\Controllers\Admin\Cms\SectionItemController;
use App\Http\Controllers\Admin\Cms\SectionRevisionController;
use App\Http\Controllers\Admin\Cms\SeoController;
use App\Http\Controllers\Admin\Cms\ServiceCategoryController;
use App\Http\Controllers\Admin\Cms\ServiceController;
use App\Http\Controllers\Admin\Cms\SitemapController;
use App\Http\Controllers\Admin\Cms\StatisticController;
use App\Http\Controllers\Admin\Cms\StudentReviewController;
use App\Http\Controllers\Admin\Cms\SuccessStoryController;
use App\Http\Controllers\Admin\Cms\TeamMemberController;
use App\Http\Controllers\Admin\Cms\TechnologyController;
use App\Http\Controllers\Admin\Cms\TestimonialController;
use App\Http\Controllers\Admin\Cms\WebsiteOverviewController;
use App\Http\Controllers\Admin\Collaborator\CollaboratorController;
use App\Http\Controllers\Admin\Collaborator\CommissionController;
use App\Http\Controllers\Admin\Collaborator\CommissionDiscrepancyController;
use App\Http\Controllers\Admin\Collaborator\CommissionRuleController;
use App\Http\Controllers\Admin\Collaborator\PayoutAccountController;
use App\Http\Controllers\Admin\Collaborator\PayoutController;
use App\Http\Controllers\Admin\Collaborator\ReferralVisitController;
use App\Http\Controllers\Admin\Collaborator\StatementController;
use App\Http\Controllers\Admin\Collaborator\WalletController;
use App\Http\Controllers\Admin\Collaborator\WalletReconciliationController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\Finance\ExpenseController;
use App\Http\Controllers\Admin\Finance\FeePaymentController;
use App\Http\Controllers\Admin\Finance\FinanceCategoryController;
use App\Http\Controllers\Admin\Finance\FinanceReportController;
use App\Http\Controllers\Admin\Finance\IncomeController;
use App\Http\Controllers\Admin\Finance\InvoiceController;
use App\Http\Controllers\Admin\Finance\PaymentMethodController;
use App\Http\Controllers\Admin\Finance\PaymentRegisterController;
use App\Http\Controllers\Admin\Finance\ProjectPaymentController;
use App\Http\Controllers\Admin\Hr\AttendanceController;
use App\Http\Controllers\Admin\Hr\AttendanceCorrectionController;
use App\Http\Controllers\Admin\Hr\AttendanceSummaryController;
use App\Http\Controllers\Admin\Hr\DepartmentController;
use App\Http\Controllers\Admin\Hr\DesignationController;
use App\Http\Controllers\Admin\Hr\EmployeeAdvanceController;
use App\Http\Controllers\Admin\Hr\EmployeeController;
use App\Http\Controllers\Admin\Hr\HolidayController;
use App\Http\Controllers\Admin\Hr\LeaveBalanceController;
use App\Http\Controllers\Admin\Hr\LeaveRequestController;
use App\Http\Controllers\Admin\Hr\LeaveTypeController;
use App\Http\Controllers\Admin\Hr\PayrollRunController;
use App\Http\Controllers\Admin\Hr\PayrollRunItemController;
use App\Http\Controllers\Admin\Hr\PayslipController;
use App\Http\Controllers\Admin\Hr\SalaryComponentController;
use App\Http\Controllers\Admin\Hr\SalaryStructureController;
use App\Http\Controllers\Admin\Hr\SelfService\AttendanceController as MyAttendanceController;
use App\Http\Controllers\Admin\Hr\SelfService\LeaveController as MyLeaveController;
use App\Http\Controllers\Admin\Hr\SelfService\PayslipController as MyPayslipController;
use App\Http\Controllers\Admin\Hr\SelfService\ProfileController as MyProfileController;
use App\Http\Controllers\Admin\Hr\WorkShiftController;
use App\Http\Controllers\Admin\Institute\AdmissionController;
use App\Http\Controllers\Admin\Institute\AssignmentController;
use App\Http\Controllers\Admin\Institute\AssignmentSubmissionController;
use App\Http\Controllers\Admin\Institute\AttendanceController as StudentAttendanceController;
use App\Http\Controllers\Admin\Institute\AttendanceReportController as StudentAttendanceReportController;
use App\Http\Controllers\Admin\Institute\BatchController;
use App\Http\Controllers\Admin\Institute\CertificateController;
use App\Http\Controllers\Admin\Institute\ClassroomController;
use App\Http\Controllers\Admin\Institute\ClassSessionController;
use App\Http\Controllers\Admin\Institute\CourseCategoryController;
use App\Http\Controllers\Admin\Institute\CourseController;
use App\Http\Controllers\Admin\Institute\CourseInquiryController;
use App\Http\Controllers\Admin\Institute\CourseMaterialController;
use App\Http\Controllers\Admin\Institute\CourseOutlineController;
use App\Http\Controllers\Admin\Institute\DemoClassController;
use App\Http\Controllers\Admin\Institute\EnrollmentController;
use App\Http\Controllers\Admin\Institute\ExamController;
use App\Http\Controllers\Admin\Institute\ExamResultController;
use App\Http\Controllers\Admin\Institute\FeeCollectionController;
use App\Http\Controllers\Admin\Institute\FeeDiscountController;
use App\Http\Controllers\Admin\Institute\FeeReminderController;
use App\Http\Controllers\Admin\Institute\FeeStructureController;
use App\Http\Controllers\Admin\Institute\GradeScaleController;
use App\Http\Controllers\Admin\Institute\InstallmentPlanController;
use App\Http\Controllers\Admin\Institute\PrintTemplateController;
use App\Http\Controllers\Admin\Institute\ProgressController as StudentProgressController;
use App\Http\Controllers\Admin\Institute\ResultCardController;
use App\Http\Controllers\Admin\Institute\StudentApplicationController;
use App\Http\Controllers\Admin\Institute\StudentController;
use App\Http\Controllers\Admin\Institute\StudentFeeController;
use App\Http\Controllers\Admin\Institute\StudentIdCardController;
use App\Http\Controllers\Admin\Institute\TeacherController;
use App\Http\Controllers\Admin\Institute\TimetableController;
use App\Http\Controllers\Admin\LeadActivityController;
use App\Http\Controllers\Admin\LeadBoardController;
use App\Http\Controllers\Admin\LeadController;
use App\Http\Controllers\Admin\LeadConversionController;
use App\Http\Controllers\Admin\LeadFollowUpController;
use App\Http\Controllers\Admin\LeadImportController;
use App\Http\Controllers\Admin\LoginHistoryController;
use App\Http\Controllers\Admin\MilestoneController;
use App\Http\Controllers\Admin\ModuleController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\ProjectController;
use App\Http\Controllers\Admin\ProjectMemberController;
use App\Http\Controllers\Admin\Reporting\AnalyticsController;
use App\Http\Controllers\Admin\Reporting\AuditTrailController;
use App\Http\Controllers\Admin\Reporting\GlobalSearchController;
use App\Http\Controllers\Admin\Reporting\ReportController;
use App\Http\Controllers\Admin\Reporting\ReportExportController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\Support\ConversationController;
use App\Http\Controllers\Admin\Support\MeetingController;
use App\Http\Controllers\Admin\Support\TicketController;
use App\Http\Controllers\Admin\Support\TicketDepartmentController;
use App\Http\Controllers\Admin\TaskBoardController;
use App\Http\Controllers\Admin\TaskChecklistController;
use App\Http\Controllers\Admin\TaskController;
use App\Http\Controllers\Admin\TimeTrackingController;
use App\Http\Controllers\Admin\UserController;
use App\Models\Collaborator\Collaborator;
use App\Models\Hr\Attendance;
use App\Models\Hr\AttendanceCorrection;
use App\Models\Hr\Employee;
use App\Models\Hr\EmployeeAdvance;
use App\Models\Hr\LeaveBalance;
use App\Models\Hr\LeaveRequest;
use App\Models\Hr\PayrollRun;
use App\Models\Hr\PayrollRunItem;
use App\Models\Hr\SalaryStructure;
use App\Models\Project\Project;
use App\Services\Core\SettingsService;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin panel routes (phase-01 §8, phase-02 §4)
|--------------------------------------------------------------------------
|
| Loaded by bootstrap/app.php inside the `web` middleware group, so the prefix,
| the route-name prefix and the panel middleware are declared here.
|
| Layered authorization (CLAUDE.md §4, rule 7):
|   auth            — a session is required
|   active          — status must be Active; also forces a pending password change
|   panel:admin     — one of the user's roles must belong to the admin panel
|   can:<ability>   — the exact permission for that action, per route
|
| Gate::before denies any ability whose module is disabled (non-core modules
| only) before the Super Admin bypass, so an explicit `module:` middleware is
| redundant for the modules below: every Phase 1 admin module is core
| (dashboard, users, roles, permissions, modules, activity_log, login_history).
| Later phases add `module:<slug>` for their non-core modules.
|
| The /account screens (profile, password, sessions, theme) are registered in
| routes/auth.php and are shared by all five panels.
|
*/

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'active', 'panel:admin'])
    ->group(function (): void {

        /*
        |------------------------------------------------------------------
        | Dashboard
        |------------------------------------------------------------------
        | The landing page sits at the panel root; the widget framework's two
        | endpoints (phase-02 §4) live under `dashboard/` so they can never be
        | confused with a panel resource.
        |
        |   layout  — PUT, persists the per-user widget order + hidden keys into
        |             users.preferences (phase-02 §1).
        |   widget  — GET, returns one widget's JSON for the async grid. Every
        |             card on the page is one request, so it is throttled per
        |             user rather than left unbounded.
        */

        // `dashboard.view`, not `dashboard.view_any`: phase-02 §4 names the same ability for all
        // three rows of this block, and the page and its two endpoints have to demand the same
        // one. While the landing page asked for `view_any` and the widget endpoint for `view`, a
        // role holding only `view_any` could load the grid and then get a 403 from every deferred
        // card, and a role holding only `view` could pull widget JSON for a page it may not open.
        // Both abilities exist on the `dashboard` module (the READ preset) and every one of the 14
        // roles that holds either holds both, so no seeded role's access changes.
        Route::get('/', [DashboardController::class, 'index'])
            ->middleware('can:dashboard.view')
            ->name('dashboard');

        Route::put('dashboard/layout', [DashboardController::class, 'layout'])
            ->middleware('can:dashboard.view')
            ->name('dashboard.layout');

        Route::get('dashboard/widget/{key}', [DashboardController::class, 'widget'])
            // The third argument is the limiter prefix. An unnamed `throttle:x,y` keys its counter
            // on the user alone, so every such route shared one bucket and five card refreshes
            // used up the mail test's three-a-minute allowance.
            //
            // **The ceiling scales with the number of cards, not with a round number.** One dashboard
            // load is one request per visible widget, so 60 a minute was three loads when there were
            // twenty cards — and phase-10-12 §8.12 added eight more. A Super Admin who reloads and
            // then changes the date range would have been rate-limited out of their own dashboard.
            // The per-widget query budget is what bounds the cost here; the limiter is only there to
            // stop a loop.
            ->middleware(['can:dashboard.view', 'throttle:240,1,dashboard-widget'])
            ->name('dashboard.widget');

        /*
        |------------------------------------------------------------------
        | Users
        |------------------------------------------------------------------
        | The two extra actions are declared before the resource so the
        | literal segments always win over {user}.
        */

        Route::patch('users/{user}/status', [UserController::class, 'updateStatus'])
            ->middleware('can:users.change_status')
            ->name('users.status');

        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])
            ->middleware('can:users.edit')
            ->name('users.reset-password');

        Route::resource('users', UserController::class)
            ->middlewareFor('index', 'can:users.view_any')
            ->middlewareFor(['create', 'store'], 'can:users.create')
            ->middlewareFor('show', 'can:users.view')
            ->middlewareFor(['edit', 'update'], 'can:users.edit')
            ->middlewareFor('destroy', 'can:users.delete');

        /*
        |------------------------------------------------------------------
        | Roles
        |------------------------------------------------------------------
        */

        Route::resource('roles', RoleController::class)
            ->middlewareFor('index', 'can:roles.view_any')
            ->middlewareFor(['create', 'store'], 'can:roles.create')
            ->middlewareFor('show', 'can:roles.view')
            ->middlewareFor(['edit', 'update'], 'can:roles.edit')
            ->middlewareFor('destroy', 'can:roles.delete');

        /*
        |------------------------------------------------------------------
        | Permissions (read-only viewer)
        |------------------------------------------------------------------
        */

        Route::get('permissions', [PermissionController::class, 'index'])
            ->middleware('can:permissions.view_any')
            ->name('permissions.index');

        /*
        |------------------------------------------------------------------
        | Settings (phase-02 §4)
        |------------------------------------------------------------------
        | `{group}` is a SettingsRegistry group slug; the index's parameter is
        | optional and falls back to the first group. The two literal-segment
        | actions (`mail/test`, `cache/clear`) are declared first so neither can
        | ever be read as a group slug.
        |
        | Reading is `settings.view`, every write is `settings.edit` — one rule
        | per action, stated here and in the controller (phase-01 §10).
        */

        Route::post('settings/mail/test', [SettingsController::class, 'testMail'])
            ->middleware([
                'can:settings.edit',
                // The narrow Super-Admin-only gate: `settings.edit_mail` is now declared in
                // PermissionRegistry and withheld from Admin by RoleSeeder, so this replaces the
                // `role:Super Admin` stopgap Phase 2 shipped with. SettingsService enforces the
                // same string on the write path, and SettingsController reads it from there.
                'can:'.SettingsService::EDIT_MAIL_PERMISSION,
                // Its own limiter prefix, so dashboard widget requests never count against it.
                'throttle:3,1,settings-mail-test',
            ])
            ->name('settings.mail.test');

        Route::post('settings/cache/clear', [SettingsController::class, 'clearCache'])
            ->middleware('can:settings.edit')
            ->name('settings.cache.clear');

        Route::get('settings/{group?}', [SettingsController::class, 'index'])
            ->middleware('can:settings.view')
            ->name('settings.index');

        Route::put('settings/{group}', [SettingsController::class, 'update'])
            ->middleware('can:settings.edit')
            ->name('settings.update');

        Route::delete('settings/{group}/file/{key}', [SettingsController::class, 'destroyFile'])
            ->middleware('can:settings.edit')
            ->name('settings.file.destroy');

        Route::post('settings/{group}/reset', [SettingsController::class, 'reset'])
            ->middleware('can:settings.edit')
            ->name('settings.reset');

        /*
        |------------------------------------------------------------------
        | Modules
        |------------------------------------------------------------------
        | `bulk-toggle` is declared before the `{module}` actions so the literal
        | segment always wins; `impact` is the read-only preview the confirm
        | modal loads before a module is switched off (phase-02 §5).
        */

        Route::get('modules', [ModuleController::class, 'index'])
            ->middleware('can:modules.view_any')
            ->name('modules.index');

        Route::post('modules/bulk-toggle', [ModuleController::class, 'bulkToggle'])
            ->middleware('can:modules.change_status')
            ->name('modules.bulk-toggle');

        Route::post('modules/{module}/toggle', [ModuleController::class, 'toggle'])
            ->middleware('can:modules.change_status')
            ->name('modules.toggle');

        Route::get('modules/{module}/impact', [ModuleController::class, 'impact'])
            ->middleware('can:modules.view')
            ->name('modules.impact');

        /*
        |------------------------------------------------------------------
        | Activity log
        |------------------------------------------------------------------
        | `export` precedes `{activity}` so the literal segment wins.
        */

        Route::get('activity-log', [ActivityLogController::class, 'index'])
            ->middleware('can:activity_log.view_logs')
            ->name('activity-log.index');

        Route::get('activity-log/export', [ActivityLogController::class, 'export'])
            ->middleware('can:activity_log.export')
            ->name('activity-log.export');

        Route::get('activity-log/{activity}', [ActivityLogController::class, 'show'])
            ->middleware('can:activity_log.view_logs')
            ->name('activity-log.show');

        /*
        |------------------------------------------------------------------
        | Login history
        |------------------------------------------------------------------
        */

        Route::get('login-history', [LoginHistoryController::class, 'index'])
            ->middleware('can:login_history.view_logs')
            ->name('login-history.index');

        Route::get('login-history/export', [LoginHistoryController::class, 'export'])
            ->middleware('can:login_history.export')
            ->name('login-history.export');

        /*
        |------------------------------------------------------------------
        | Website CMS (phase-03 §7.1-§7.5)
        |------------------------------------------------------------------
        | Every route: auth + active + panel:admin (this group), module:<slug> (its block), and the
        | exact can:<permission> of the contract; each controller repeats that permission and then asks
        | the policy for the record rule. Literal segments are declared before the parameter routes that
        | could swallow them. {placement} is limited to SectionPlacement::values() and enum-bound by the
        | controller type-hint; {section} {item} {revision} {page} {menu} {ctaBlock} {faq} {category}
        | {asset} are implicit model bindings (the parameter names are the controllers' argument names).
        | A revision that belongs to another target is a 404 (ListsRevisions::assertRevisionOf).
        | Throttles carry their own limiter prefix, as the Phase 2 routes above do.
        */
        Route::prefix('website')->name('website.')->group(function (): void {

            // §7.1 Sections
            Route::middleware('module:website_sections')->group(function (): void {
                Route::get('/', [WebsiteOverviewController::class, 'index'])
                    ->middleware('can:website_sections.view_any')->name('index');

                Route::get('statistics', [StatisticController::class, 'index'])
                    ->middleware('can:website_sections.view_any')->name('statistics.index');

                Route::post('cache/flush', [PublicCacheController::class, 'flush'])
                    ->middleware(['can:website_sections.change_status', 'throttle:6,1,cms-cache-flush'])->name('cache.flush');

                Route::post('sections/reorder', [SectionController::class, 'reorder'])
                    ->middleware('can:website_sections.edit')->name('sections.reorder');

                Route::get('sections/{placement}', [SectionController::class, 'index'])
                    ->whereIn('placement', SectionPlacement::values())
                    ->middleware('can:website_sections.view_any')->name('sections.index');

                Route::get('sections/{placement}/available', [SectionController::class, 'available'])
                    ->whereIn('placement', SectionPlacement::values())
                    ->middleware('can:website_sections.create')->name('sections.available');

                Route::post('sections/{placement}', [SectionController::class, 'store'])
                    ->whereIn('placement', SectionPlacement::values())
                    ->middleware('can:website_sections.create')->name('sections.store');

                Route::get('sections/{section}/edit', [SectionController::class, 'edit'])
                    ->whereNumber('section')->middleware('can:website_sections.view')->name('sections.edit');

                Route::put('sections/{section}', [SectionController::class, 'update'])
                    ->whereNumber('section')->middleware('can:website_sections.edit')->name('sections.update');

                Route::post('sections/{section}/publish', [SectionController::class, 'publish'])
                    ->whereNumber('section')->middleware('can:website_sections.change_status')->name('sections.publish');

                Route::post('sections/{section}/unpublish', [SectionController::class, 'unpublish'])
                    ->whereNumber('section')->middleware('can:website_sections.change_status')->name('sections.unpublish');

                Route::post('sections/{section}/toggle', [SectionController::class, 'toggle'])
                    ->whereNumber('section')->middleware('can:website_sections.change_status')->name('sections.toggle');

                Route::post('sections/{section}/duplicate', [SectionController::class, 'duplicate'])
                    ->whereNumber('section')->middleware('can:website_sections.create')->name('sections.duplicate');

                Route::delete('sections/{section}', [SectionController::class, 'destroy'])
                    ->whereNumber('section')->middleware('can:website_sections.delete')->name('sections.destroy');

                Route::get('sections/{section}/revisions', [SectionRevisionController::class, 'index'])
                    ->whereNumber('section')->middleware('can:website_sections.view_logs')->name('sections.revisions.index');

                Route::post('sections/{section}/revisions/{revision}/revert', [SectionRevisionController::class, 'revert'])
                    ->whereNumber(['section', 'revision'])->middleware('can:website_sections.change_status')->name('sections.revisions.revert');

                Route::post('sections/{section}/items', [SectionItemController::class, 'store'])
                    ->whereNumber('section')->middleware('can:website_sections.edit')->name('sections.items.store');

                Route::post('sections/{section}/items/{group}/reorder', [SectionItemController::class, 'reorder'])
                    ->whereNumber('section')->where('group', '[a-z][a-z0-9_]*')
                    ->middleware('can:website_sections.edit')->name('sections.items.reorder');

                Route::put('section-items/{item}', [SectionItemController::class, 'update'])
                    ->whereNumber('item')->middleware('can:website_sections.edit')->name('section-items.update');

                Route::post('section-items/{item}/toggle', [SectionItemController::class, 'toggle'])
                    ->whereNumber('item')->middleware('can:website_sections.edit')->name('section-items.toggle');

                Route::delete('section-items/{item}', [SectionItemController::class, 'destroy'])
                    ->whereNumber('item')->middleware('can:website_sections.edit')->name('section-items.destroy');
            });

            // §7.2 Menus
            Route::middleware('module:menus')->group(function (): void {
                Route::get('menus', [MenuController::class, 'index'])
                    ->middleware('can:menus.view_any')->name('menus.index');

                Route::get('menus/{menu}', [MenuController::class, 'show'])
                    ->whereNumber('menu')->middleware('can:menus.view')->name('menus.show');

                Route::put('menus/{menu}', [MenuController::class, 'update'])
                    ->whereNumber('menu')->middleware('can:menus.edit')->name('menus.update');

                Route::post('menus/{menu}/items', [MenuItemController::class, 'store'])
                    ->whereNumber('menu')->middleware('can:menus.create')->name('menus.items.store');

                Route::post('menus/{menu}/reorder', [MenuController::class, 'reorder'])
                    ->whereNumber('menu')->middleware('can:menus.edit')->name('menus.reorder');

                Route::get('menus/{menu}/link-check', [MenuController::class, 'linkCheck'])
                    ->whereNumber('menu')->middleware('can:menus.view')->name('menus.link-check');

                Route::put('menu-items/{item}', [MenuItemController::class, 'update'])
                    ->whereNumber('item')->middleware('can:menus.edit')->name('menu-items.update');

                Route::post('menu-items/{item}/toggle', [MenuItemController::class, 'toggle'])
                    ->whereNumber('item')->middleware('can:menus.change_status')->name('menu-items.toggle');

                Route::delete('menu-items/{item}', [MenuItemController::class, 'destroy'])
                    ->whereNumber('item')->middleware('can:menus.delete')->name('menu-items.destroy');
            });

            // §7.3 Pages
            Route::middleware('module:pages')->group(function (): void {
                Route::get('pages', [PageController::class, 'index'])
                    ->middleware('can:pages.view_any')->name('pages.index');

                Route::get('pages/create', [PageController::class, 'create'])
                    ->middleware('can:pages.create')->name('pages.create');

                Route::get('pages/export', [PageController::class, 'export'])
                    ->middleware('can:pages.export')->name('pages.export');

                Route::post('pages', [PageController::class, 'store'])
                    ->middleware('can:pages.create')->name('pages.store');

                Route::get('pages/{page}/edit', [PageController::class, 'edit'])
                    ->whereNumber('page')->middleware('can:pages.view')->name('pages.edit');

                Route::put('pages/{page}', [PageController::class, 'update'])
                    ->whereNumber('page')->middleware('can:pages.edit')->name('pages.update');

                Route::post('pages/{page}/publish', [PageController::class, 'publish'])
                    ->whereNumber('page')->middleware('can:pages.change_status')->name('pages.publish');

                Route::post('pages/{page}/schedule', [PageController::class, 'schedule'])
                    ->whereNumber('page')->middleware('can:pages.change_status')->name('pages.schedule');

                Route::post('pages/{page}/unpublish', [PageController::class, 'unpublish'])
                    ->whereNumber('page')->middleware('can:pages.change_status')->name('pages.unpublish');

                Route::post('pages/{page}/duplicate', [PageController::class, 'duplicate'])
                    ->whereNumber('page')->middleware('can:pages.create')->name('pages.duplicate');

                Route::delete('pages/{page}', [PageController::class, 'destroy'])
                    ->whereNumber('page')->middleware('can:pages.delete')->name('pages.destroy');

                Route::post('pages/{page}/restore', [PageController::class, 'restore'])
                    ->whereNumber('page')->withTrashed()->middleware('can:pages.restore')->name('pages.restore');

                Route::get('pages/{page}/revisions', [PageRevisionController::class, 'index'])
                    ->whereNumber('page')->middleware('can:pages.view_logs')->name('pages.revisions.index');

                Route::post('pages/{page}/revisions/{revision}/revert', [PageRevisionController::class, 'revert'])
                    ->whereNumber(['page', 'revision'])->middleware('can:pages.change_status')->name('pages.revisions.revert');

                Route::get('pages/{page}/preview-link', [PageController::class, 'previewLink'])
                    ->whereNumber('page')->middleware('can:pages.view')->name('pages.preview-link');
            });

            // §7.4 CTA blocks
            Route::middleware('module:website_cta_blocks')->group(function (): void {
                Route::get('cta-blocks', [CtaBlockController::class, 'index'])
                    ->middleware('can:website_cta_blocks.view_any')->name('cta-blocks.index');

                Route::post('cta-blocks', [CtaBlockController::class, 'store'])
                    ->middleware('can:website_cta_blocks.create')->name('cta-blocks.store');

                Route::get('cta-blocks/{ctaBlock}/edit', [CtaBlockController::class, 'edit'])
                    ->whereNumber('ctaBlock')->middleware('can:website_cta_blocks.view')->name('cta-blocks.edit');

                Route::put('cta-blocks/{ctaBlock}', [CtaBlockController::class, 'update'])
                    ->whereNumber('ctaBlock')->middleware('can:website_cta_blocks.edit')->name('cta-blocks.update');

                Route::post('cta-blocks/{ctaBlock}/toggle', [CtaBlockController::class, 'toggle'])
                    ->whereNumber('ctaBlock')->middleware('can:website_cta_blocks.change_status')->name('cta-blocks.toggle');

                Route::get('cta-blocks/{ctaBlock}/usage', [CtaBlockController::class, 'usage'])
                    ->whereNumber('ctaBlock')->middleware('can:website_cta_blocks.view')->name('cta-blocks.usage');

                Route::delete('cta-blocks/{ctaBlock}', [CtaBlockController::class, 'destroy'])
                    ->whereNumber('ctaBlock')->middleware('can:website_cta_blocks.delete')->name('cta-blocks.destroy');
            });

            // §7.4 FAQs
            Route::middleware('module:faqs')->group(function (): void {
                Route::get('faqs', [FaqController::class, 'index'])
                    ->middleware('can:faqs.view_any')->name('faqs.index');

                Route::post('faqs/reorder', [FaqController::class, 'reorder'])
                    ->middleware('can:faqs.edit')->name('faqs.reorder');

                Route::post('faqs', [FaqController::class, 'store'])
                    ->middleware('can:faqs.create')->name('faqs.store');

                Route::put('faqs/{faq}', [FaqController::class, 'update'])
                    ->whereNumber('faq')->middleware('can:faqs.edit')->name('faqs.update');

                Route::post('faqs/{faq}/toggle', [FaqController::class, 'toggle'])
                    ->whereNumber('faq')->middleware('can:faqs.change_status')->name('faqs.toggle');

                Route::delete('faqs/{faq}', [FaqController::class, 'destroy'])
                    ->whereNumber('faq')->middleware('can:faqs.delete')->name('faqs.destroy');
            });

            // §7.4 FAQ categories
            Route::middleware('module:faq_categories')->group(function (): void {
                Route::get('faq-categories', [FaqCategoryController::class, 'index'])
                    ->middleware('can:faq_categories.view_any')->name('faq-categories.index');

                Route::post('faq-categories/reorder', [FaqCategoryController::class, 'reorder'])
                    ->middleware('can:faq_categories.edit')->name('faq-categories.reorder');

                Route::post('faq-categories', [FaqCategoryController::class, 'store'])
                    ->middleware('can:faq_categories.create')->name('faq-categories.store');

                Route::put('faq-categories/{category}', [FaqCategoryController::class, 'update'])
                    ->whereNumber('category')->middleware('can:faq_categories.edit')->name('faq-categories.update');

                Route::delete('faq-categories/{category}', [FaqCategoryController::class, 'destroy'])
                    ->whereNumber('category')->middleware('can:faq_categories.delete')->name('faq-categories.destroy');
            });

            // §7.5 SEO
            Route::middleware('module:seo')->group(function (): void {
                Route::get('seo', [SeoController::class, 'index'])
                    ->middleware('can:seo.view_any')->name('seo.index');

                Route::get('seo/edit', [SeoController::class, 'edit'])
                    ->middleware('can:seo.view')->name('seo.edit');

                Route::get('seo/export', [SeoController::class, 'export'])
                    ->middleware('can:seo.export')->name('seo.export');

                Route::get('seo/robots/preview', [SeoController::class, 'robotsPreview'])
                    ->middleware('can:seo.view')->name('seo.robots.preview');

                Route::put('seo', [SeoController::class, 'update'])
                    ->middleware('can:seo.edit')->name('seo.update');

                Route::post('seo/bulk-robots', [SeoController::class, 'bulkRobots'])
                    ->middleware('can:seo.edit')->name('seo.bulk-robots');

                Route::post('seo/sitemap/regenerate', [SitemapController::class, 'regenerate'])
                    ->middleware(['can:seo.edit', 'throttle:6,1,cms-sitemap-regenerate'])->name('seo.sitemap.regenerate');

                Route::get('seo/sitemap/history', [SitemapController::class, 'history'])
                    ->middleware('can:seo.view')->name('seo.sitemap.history');
            });

            // §7.5 Media library
            Route::middleware('module:website_media')->group(function (): void {
                Route::get('media', [MediaController::class, 'index'])
                    ->middleware('can:website_media.view_any')->name('media.index');

                Route::post('media', [MediaController::class, 'store'])
                    ->middleware(['can:website_media.upload', 'throttle:60,1,cms-media-upload'])->name('media.store');

                Route::get('media/{asset}', [MediaController::class, 'show'])
                    ->whereNumber('asset')->middleware('can:website_media.view')->name('media.show');

                Route::put('media/{asset}', [MediaController::class, 'update'])
                    ->whereNumber('asset')->middleware('can:website_media.edit')->name('media.update');

                Route::get('media/{asset}/usage', [MediaController::class, 'usage'])
                    ->whereNumber('asset')->middleware('can:website_media.view')->name('media.usage');

                // Until GenerateImageDerivatives ships, regeneration decodes and re-encodes inline (GD, up to
                // 40 MP, every width in two formats): throttled per user like the other heavy CMS writes.
                Route::post('media/{asset}/regenerate', [MediaController::class, 'regenerate'])
                    ->whereNumber('asset')->middleware(['can:website_media.edit', 'throttle:6,1,cms-media-regenerate'])->name('media.regenerate');

                Route::delete('media/{asset}', [MediaController::class, 'destroy'])
                    ->whereNumber('asset')->middleware('can:website_media.delete')->name('media.destroy');
            });
        });

        /*
        |------------------------------------------------------------------
        | phase-04 §7.2 — software-house marketing modules
        |------------------------------------------------------------------
        | One `module:` per block and exactly one `can:` per route. Literal segments (create, export,
        | reorder, calendar, bulk-approve, route-pending) are declared before their {parameter} sibling
        | and every parameter is whereNumber. No restore routes (§7.2 last paragraph).
        */

        // §8.1 Service categories
        Route::middleware('module:service_categories')->group(function (): void {
            Route::get('service-categories', [ServiceCategoryController::class, 'index'])->middleware('can:service_categories.view_any')->name('service-categories.index');
            Route::get('service-categories/create', [ServiceCategoryController::class, 'create'])->middleware('can:service_categories.create')->name('service-categories.create');
            Route::post('service-categories', [ServiceCategoryController::class, 'store'])->middleware('can:service_categories.create')->name('service-categories.store');
            Route::post('service-categories/reorder', [ServiceCategoryController::class, 'reorder'])->middleware('can:service_categories.edit')->name('service-categories.reorder');
            Route::get('service-categories/{term}', [ServiceCategoryController::class, 'show'])->whereNumber('term')->middleware('can:service_categories.view')->name('service-categories.show');
            Route::get('service-categories/{term}/edit', [ServiceCategoryController::class, 'edit'])->whereNumber('term')->middleware('can:service_categories.edit')->name('service-categories.edit');
            Route::put('service-categories/{term}', [ServiceCategoryController::class, 'update'])->whereNumber('term')->middleware('can:service_categories.edit')->name('service-categories.update');
            Route::post('service-categories/{term}/toggle', [ServiceCategoryController::class, 'toggle'])->whereNumber('term')->middleware('can:service_categories.change_status')->name('service-categories.toggle');
            Route::delete('service-categories/{term}', [ServiceCategoryController::class, 'destroy'])->whereNumber('term')->middleware('can:service_categories.delete')->name('service-categories.destroy');
        });

        // §8.1 Technologies
        Route::middleware('module:technologies')->group(function (): void {
            Route::get('technologies', [TechnologyController::class, 'index'])->middleware('can:technologies.view_any')->name('technologies.index');
            Route::get('technologies/create', [TechnologyController::class, 'create'])->middleware('can:technologies.create')->name('technologies.create');
            Route::post('technologies', [TechnologyController::class, 'store'])->middleware('can:technologies.create')->name('technologies.store');
            Route::get('technologies/{term}', [TechnologyController::class, 'show'])->whereNumber('term')->middleware('can:technologies.view')->name('technologies.show');
            Route::get('technologies/{term}/edit', [TechnologyController::class, 'edit'])->whereNumber('term')->middleware('can:technologies.edit')->name('technologies.edit');
            Route::put('technologies/{term}', [TechnologyController::class, 'update'])->whereNumber('term')->middleware('can:technologies.edit')->name('technologies.update');
            Route::post('technologies/{term}/toggle', [TechnologyController::class, 'toggle'])->whereNumber('term')->middleware('can:technologies.change_status')->name('technologies.toggle');
            Route::delete('technologies/{term}', [TechnologyController::class, 'destroy'])->whereNumber('term')->middleware('can:technologies.delete')->name('technologies.destroy');
        });

        // §8.1 Portfolio categories
        Route::middleware('module:portfolio_categories')->group(function (): void {
            Route::get('portfolio-categories', [PortfolioCategoryController::class, 'index'])->middleware('can:portfolio_categories.view_any')->name('portfolio-categories.index');
            Route::get('portfolio-categories/create', [PortfolioCategoryController::class, 'create'])->middleware('can:portfolio_categories.create')->name('portfolio-categories.create');
            Route::post('portfolio-categories', [PortfolioCategoryController::class, 'store'])->middleware('can:portfolio_categories.create')->name('portfolio-categories.store');
            Route::post('portfolio-categories/reorder', [PortfolioCategoryController::class, 'reorder'])->middleware('can:portfolio_categories.edit')->name('portfolio-categories.reorder');
            Route::get('portfolio-categories/{term}', [PortfolioCategoryController::class, 'show'])->whereNumber('term')->middleware('can:portfolio_categories.view')->name('portfolio-categories.show');
            Route::get('portfolio-categories/{term}/edit', [PortfolioCategoryController::class, 'edit'])->whereNumber('term')->middleware('can:portfolio_categories.edit')->name('portfolio-categories.edit');
            Route::put('portfolio-categories/{term}', [PortfolioCategoryController::class, 'update'])->whereNumber('term')->middleware('can:portfolio_categories.edit')->name('portfolio-categories.update');
            Route::post('portfolio-categories/{term}/toggle', [PortfolioCategoryController::class, 'toggle'])->whereNumber('term')->middleware('can:portfolio_categories.change_status')->name('portfolio-categories.toggle');
            Route::delete('portfolio-categories/{term}', [PortfolioCategoryController::class, 'destroy'])->whereNumber('term')->middleware('can:portfolio_categories.delete')->name('portfolio-categories.destroy');
        });

        // §8.1 Blog categories
        Route::middleware('module:blog_categories')->group(function (): void {
            Route::get('blog-categories', [BlogCategoryController::class, 'index'])->middleware('can:blog_categories.view_any')->name('blog-categories.index');
            Route::get('blog-categories/create', [BlogCategoryController::class, 'create'])->middleware('can:blog_categories.create')->name('blog-categories.create');
            Route::post('blog-categories', [BlogCategoryController::class, 'store'])->middleware('can:blog_categories.create')->name('blog-categories.store');
            Route::get('blog-categories/{term}', [BlogCategoryController::class, 'show'])->whereNumber('term')->middleware('can:blog_categories.view')->name('blog-categories.show');
            Route::get('blog-categories/{term}/edit', [BlogCategoryController::class, 'edit'])->whereNumber('term')->middleware('can:blog_categories.edit')->name('blog-categories.edit');
            Route::put('blog-categories/{term}', [BlogCategoryController::class, 'update'])->whereNumber('term')->middleware('can:blog_categories.edit')->name('blog-categories.update');
            Route::post('blog-categories/{term}/toggle', [BlogCategoryController::class, 'toggle'])->whereNumber('term')->middleware('can:blog_categories.change_status')->name('blog-categories.toggle');
            Route::delete('blog-categories/{term}', [BlogCategoryController::class, 'destroy'])->whereNumber('term')->middleware('can:blog_categories.delete')->name('blog-categories.destroy');
        });

        // §8.1 Blog tags
        Route::middleware('module:blog_tags')->group(function (): void {
            Route::get('blog-tags', [BlogTagController::class, 'index'])->middleware('can:blog_tags.view_any')->name('blog-tags.index');
            Route::get('blog-tags/create', [BlogTagController::class, 'create'])->middleware('can:blog_tags.create')->name('blog-tags.create');
            Route::post('blog-tags', [BlogTagController::class, 'store'])->middleware('can:blog_tags.create')->name('blog-tags.store');
            Route::get('blog-tags/{term}', [BlogTagController::class, 'show'])->whereNumber('term')->middleware('can:blog_tags.view')->name('blog-tags.show');
            Route::get('blog-tags/{term}/edit', [BlogTagController::class, 'edit'])->whereNumber('term')->middleware('can:blog_tags.edit')->name('blog-tags.edit');
            Route::put('blog-tags/{term}', [BlogTagController::class, 'update'])->whereNumber('term')->middleware('can:blog_tags.edit')->name('blog-tags.update');
            Route::post('blog-tags/{term}/toggle', [BlogTagController::class, 'toggle'])->whereNumber('term')->middleware('can:blog_tags.change_status')->name('blog-tags.toggle');
            Route::delete('blog-tags/{term}', [BlogTagController::class, 'destroy'])->whereNumber('term')->middleware('can:blog_tags.delete')->name('blog-tags.destroy');
        });

        // §8.2 Services
        Route::middleware('module:services')->group(function (): void {
            Route::get('services', [ServiceController::class, 'index'])->middleware('can:services.view_any')->name('services.index');
            Route::get('services/export', [ServiceController::class, 'export'])->middleware('can:services.export')->name('services.export');
            Route::get('services/create', [ServiceController::class, 'create'])->middleware('can:services.create')->name('services.create');
            Route::post('services', [ServiceController::class, 'store'])->middleware('can:services.create')->name('services.store');
            Route::post('services/reorder', [ServiceController::class, 'reorder'])->middleware('can:services.edit')->name('services.reorder');
            Route::get('services/{service}', [ServiceController::class, 'show'])->whereNumber('service')->middleware('can:services.view')->name('services.show');
            Route::get('services/{service}/edit', [ServiceController::class, 'edit'])->whereNumber('service')->middleware('can:services.edit')->name('services.edit');
            Route::put('services/{service}', [ServiceController::class, 'update'])->whereNumber('service')->middleware('can:services.edit')->name('services.update');
            Route::delete('services/{service}', [ServiceController::class, 'destroy'])->whereNumber('service')->middleware('can:services.delete')->name('services.destroy');
            Route::post('services/{service}/status', [ServiceController::class, 'status'])->whereNumber('service')->middleware('can:services.change_status')->name('services.status');
            Route::post('services/{service}/featured', [ServiceController::class, 'featured'])->whereNumber('service')->middleware('can:services.change_status')->name('services.featured');
        });

        // §8.3 Portfolio + gallery manager
        Route::middleware('module:portfolio')->group(function (): void {
            Route::get('portfolio', [PortfolioItemController::class, 'index'])->middleware('can:portfolio.view_any')->name('portfolio.index');
            Route::get('portfolio/create', [PortfolioItemController::class, 'create'])->middleware('can:portfolio.create')->name('portfolio.create');
            Route::post('portfolio', [PortfolioItemController::class, 'store'])->middleware('can:portfolio.create')->name('portfolio.store');
            Route::post('portfolio/reorder', [PortfolioItemController::class, 'reorder'])->middleware('can:portfolio.edit')->name('portfolio.reorder');
            Route::get('portfolio/{item}', [PortfolioItemController::class, 'show'])->whereNumber('item')->middleware('can:portfolio.view')->name('portfolio.show');
            Route::get('portfolio/{item}/edit', [PortfolioItemController::class, 'edit'])->whereNumber('item')->middleware('can:portfolio.edit')->name('portfolio.edit');
            Route::put('portfolio/{item}', [PortfolioItemController::class, 'update'])->whereNumber('item')->middleware('can:portfolio.edit')->name('portfolio.update');
            Route::delete('portfolio/{item}', [PortfolioItemController::class, 'destroy'])->whereNumber('item')->middleware('can:portfolio.delete')->name('portfolio.destroy');
            Route::post('portfolio/{item}/status', [PortfolioItemController::class, 'status'])->whereNumber('item')->middleware('can:portfolio.change_status')->name('portfolio.status');
            Route::post('portfolio/{item}/featured', [PortfolioItemController::class, 'featured'])->whereNumber('item')->middleware('can:portfolio.change_status')->name('portfolio.featured');
            Route::post('portfolio/{item}/images', [PortfolioImageController::class, 'store'])->whereNumber('item')->middleware('can:portfolio.upload')->name('portfolio.images.store');
            Route::post('portfolio/{item}/images/reorder', [PortfolioImageController::class, 'reorder'])->whereNumber('item')->middleware('can:portfolio.edit')->name('portfolio.images.reorder');
            Route::post('portfolio/{item}/images/{image}/cover', [PortfolioImageController::class, 'cover'])->whereNumber(['item', 'image'])->middleware('can:portfolio.edit')->name('portfolio.images.cover');
            Route::put('portfolio/{item}/images/{image}', [PortfolioImageController::class, 'caption'])->whereNumber(['item', 'image'])->middleware('can:portfolio.edit')->name('portfolio.images.update');
            Route::delete('portfolio/{item}/images/{image}', [PortfolioImageController::class, 'destroy'])->whereNumber(['item', 'image'])->middleware('can:portfolio.edit')->name('portfolio.images.destroy');
        });

        // §8.4 Team
        Route::middleware('module:team')->group(function (): void {
            Route::get('team', [TeamMemberController::class, 'index'])->middleware('can:team.view_any')->name('team.index');
            Route::get('team/create', [TeamMemberController::class, 'create'])->middleware('can:team.create')->name('team.create');
            Route::post('team', [TeamMemberController::class, 'store'])->middleware('can:team.create')->name('team.store');
            Route::post('team/reorder', [TeamMemberController::class, 'reorder'])->middleware('can:team.edit')->name('team.reorder');
            Route::get('team/{member}', [TeamMemberController::class, 'show'])->whereNumber('member')->middleware('can:team.view')->name('team.show');
            Route::get('team/{member}/edit', [TeamMemberController::class, 'edit'])->whereNumber('member')->middleware('can:team.edit')->name('team.edit');
            Route::put('team/{member}', [TeamMemberController::class, 'update'])->whereNumber('member')->middleware('can:team.edit')->name('team.update');
            Route::delete('team/{member}', [TeamMemberController::class, 'destroy'])->whereNumber('member')->middleware('can:team.delete')->name('team.destroy');
            Route::post('team/{member}/status', [TeamMemberController::class, 'status'])->whereNumber('member')->middleware('can:team.change_status')->name('team.status');
            Route::post('team/{member}/visibility', [TeamMemberController::class, 'visibility'])->whereNumber('member')->middleware('can:team.change_status')->name('team.visibility');
        });

        // §8.5 Testimonials (moderation queue)
        Route::middleware('module:testimonials')->group(function (): void {
            Route::get('testimonials', [TestimonialController::class, 'index'])->middleware('can:testimonials.view_any')->name('testimonials.index');
            Route::get('testimonials/create', [TestimonialController::class, 'create'])->middleware('can:testimonials.create')->name('testimonials.create');
            Route::post('testimonials', [TestimonialController::class, 'store'])->middleware('can:testimonials.create')->name('testimonials.store');
            Route::post('testimonials/bulk-approve', [TestimonialController::class, 'bulkApprove'])->middleware('can:testimonials.approve')->name('testimonials.bulk-approve');
            Route::get('testimonials/{testimonial}', [TestimonialController::class, 'show'])->whereNumber('testimonial')->middleware('can:testimonials.view')->name('testimonials.show');
            Route::get('testimonials/{testimonial}/edit', [TestimonialController::class, 'edit'])->whereNumber('testimonial')->middleware('can:testimonials.edit')->name('testimonials.edit');
            Route::put('testimonials/{testimonial}', [TestimonialController::class, 'update'])->whereNumber('testimonial')->middleware('can:testimonials.edit')->name('testimonials.update');
            Route::delete('testimonials/{testimonial}', [TestimonialController::class, 'destroy'])->whereNumber('testimonial')->middleware('can:testimonials.delete')->name('testimonials.destroy');
            Route::post('testimonials/{testimonial}/approve', [TestimonialController::class, 'approve'])->whereNumber('testimonial')->middleware('can:testimonials.approve')->name('testimonials.approve');
            Route::post('testimonials/{testimonial}/reject', [TestimonialController::class, 'reject'])->whereNumber('testimonial')->middleware('can:testimonials.reject')->name('testimonials.reject');
            Route::post('testimonials/{testimonial}/featured', [TestimonialController::class, 'featured'])->whereNumber('testimonial')->middleware('can:testimonials.change_status')->name('testimonials.featured');
        });

        // §8.5 Student reviews (moderation queue)
        Route::middleware('module:student_reviews')->group(function (): void {
            Route::get('student-reviews', [StudentReviewController::class, 'index'])->middleware('can:student_reviews.view_any')->name('student-reviews.index');
            Route::get('student-reviews/create', [StudentReviewController::class, 'create'])->middleware('can:student_reviews.create')->name('student-reviews.create');
            Route::post('student-reviews', [StudentReviewController::class, 'store'])->middleware('can:student_reviews.create')->name('student-reviews.store');
            Route::post('student-reviews/bulk-approve', [StudentReviewController::class, 'bulkApprove'])->middleware('can:student_reviews.approve')->name('student-reviews.bulk-approve');
            Route::get('student-reviews/{review}', [StudentReviewController::class, 'show'])->whereNumber('review')->middleware('can:student_reviews.view')->name('student-reviews.show');
            Route::get('student-reviews/{review}/edit', [StudentReviewController::class, 'edit'])->whereNumber('review')->middleware('can:student_reviews.edit')->name('student-reviews.edit');
            Route::put('student-reviews/{review}', [StudentReviewController::class, 'update'])->whereNumber('review')->middleware('can:student_reviews.edit')->name('student-reviews.update');
            Route::delete('student-reviews/{review}', [StudentReviewController::class, 'destroy'])->whereNumber('review')->middleware('can:student_reviews.delete')->name('student-reviews.destroy');
            Route::post('student-reviews/{review}/approve', [StudentReviewController::class, 'approve'])->whereNumber('review')->middleware('can:student_reviews.approve')->name('student-reviews.approve');
            Route::post('student-reviews/{review}/reject', [StudentReviewController::class, 'reject'])->whereNumber('review')->middleware('can:student_reviews.reject')->name('student-reviews.reject');
            Route::post('student-reviews/{review}/featured', [StudentReviewController::class, 'featured'])->whereNumber('review')->middleware('can:student_reviews.change_status')->name('student-reviews.featured');
        });

        // §8.6 Success stories
        Route::middleware('module:success_stories')->group(function (): void {
            Route::get('success-stories', [SuccessStoryController::class, 'index'])->middleware('can:success_stories.view_any')->name('success-stories.index');
            Route::get('success-stories/create', [SuccessStoryController::class, 'create'])->middleware('can:success_stories.create')->name('success-stories.create');
            Route::post('success-stories', [SuccessStoryController::class, 'store'])->middleware('can:success_stories.create')->name('success-stories.store');
            Route::post('success-stories/reorder', [SuccessStoryController::class, 'reorder'])->middleware('can:success_stories.edit')->name('success-stories.reorder');
            Route::get('success-stories/{story}', [SuccessStoryController::class, 'show'])->whereNumber('story')->middleware('can:success_stories.view')->name('success-stories.show');
            Route::get('success-stories/{story}/edit', [SuccessStoryController::class, 'edit'])->whereNumber('story')->middleware('can:success_stories.edit')->name('success-stories.edit');
            Route::put('success-stories/{story}', [SuccessStoryController::class, 'update'])->whereNumber('story')->middleware('can:success_stories.edit')->name('success-stories.update');
            Route::delete('success-stories/{story}', [SuccessStoryController::class, 'destroy'])->whereNumber('story')->middleware('can:success_stories.delete')->name('success-stories.destroy');
            Route::post('success-stories/{story}/status', [SuccessStoryController::class, 'status'])->whereNumber('story')->middleware('can:success_stories.change_status')->name('success-stories.status');
            Route::post('success-stories/{story}/featured', [SuccessStoryController::class, 'featured'])->whereNumber('story')->middleware('can:success_stories.change_status')->name('success-stories.featured');
        });

        // §8.7 Blog posts
        Route::middleware('module:blog_posts')->group(function (): void {
            Route::get('blog-posts', [BlogPostController::class, 'index'])->middleware('can:blog_posts.view_any')->name('blog-posts.index');
            Route::get('blog-posts/calendar', [BlogPostController::class, 'calendar'])->middleware('can:blog_posts.view_any')->name('blog-posts.calendar');
            Route::get('blog-posts/create', [BlogPostController::class, 'create'])->middleware('can:blog_posts.create')->name('blog-posts.create');
            Route::post('blog-posts', [BlogPostController::class, 'store'])->middleware('can:blog_posts.create')->name('blog-posts.store');
            Route::get('blog-posts/{post}', [BlogPostController::class, 'show'])->whereNumber('post')->middleware('can:blog_posts.view')->name('blog-posts.show');
            Route::get('blog-posts/{post}/edit', [BlogPostController::class, 'edit'])->whereNumber('post')->middleware('can:blog_posts.edit')->name('blog-posts.edit');
            Route::put('blog-posts/{post}', [BlogPostController::class, 'update'])->whereNumber('post')->middleware('can:blog_posts.edit')->name('blog-posts.update');
            Route::delete('blog-posts/{post}', [BlogPostController::class, 'destroy'])->whereNumber('post')->middleware('can:blog_posts.delete')->name('blog-posts.destroy');
            Route::post('blog-posts/{post}/publish', [BlogPostController::class, 'publish'])->whereNumber('post')->middleware('can:blog_posts.change_status')->name('blog-posts.publish');
            Route::post('blog-posts/{post}/schedule', [BlogPostController::class, 'schedule'])->whereNumber('post')->middleware('can:blog_posts.change_status')->name('blog-posts.schedule');
            Route::post('blog-posts/{post}/unpublish', [BlogPostController::class, 'unpublish'])->whereNumber('post')->middleware('can:blog_posts.change_status')->name('blog-posts.unpublish');
            Route::post('blog-posts/{post}/archive', [BlogPostController::class, 'archive'])->whereNumber('post')->middleware('can:blog_posts.change_status')->name('blog-posts.archive');
            Route::get('blog-posts/{post}/stats', [BlogPostController::class, 'stats'])->whereNumber('post')->middleware('can:blog_posts.view_reports')->name('blog-posts.stats');
            Route::get('blog-posts/{post}/preview-link', [BlogPostController::class, 'previewLink'])->whereNumber('post')->middleware('can:blog_posts.view')->name('blog-posts.preview-link');
        });

        // §8.8 Jobs (table job_openings — R1)
        Route::middleware('module:jobs')->group(function (): void {
            Route::get('jobs', [JobOpeningController::class, 'index'])->middleware('can:jobs.view_any')->name('jobs.index');
            Route::get('jobs/create', [JobOpeningController::class, 'create'])->middleware('can:jobs.create')->name('jobs.create');
            Route::post('jobs', [JobOpeningController::class, 'store'])->middleware('can:jobs.create')->name('jobs.store');
            Route::post('jobs/reorder', [JobOpeningController::class, 'reorder'])->middleware('can:jobs.edit')->name('jobs.reorder');
            Route::get('jobs/{job}', [JobOpeningController::class, 'show'])->whereNumber('job')->middleware('can:jobs.view')->name('jobs.show');
            Route::get('jobs/{job}/edit', [JobOpeningController::class, 'edit'])->whereNumber('job')->middleware('can:jobs.edit')->name('jobs.edit');
            Route::put('jobs/{job}', [JobOpeningController::class, 'update'])->whereNumber('job')->middleware('can:jobs.edit')->name('jobs.update');
            Route::delete('jobs/{job}', [JobOpeningController::class, 'destroy'])->whereNumber('job')->middleware('can:jobs.delete')->name('jobs.destroy');
            Route::post('jobs/{job}/status', [JobOpeningController::class, 'status'])->whereNumber('job')->middleware('can:jobs.change_status')->name('jobs.status');
        });

        // §8.9 Job applications — index is `view` (not view_any): §9.1.3 reviewers see their own slice
        Route::middleware('module:job_applications')->group(function (): void {
            Route::get('job-applications', [JobApplicationController::class, 'index'])->middleware('can:job_applications.view')->name('job-applications.index');
            Route::get('job-applications/export', [JobApplicationController::class, 'export'])->middleware('can:job_applications.export')->name('job-applications.export');
            Route::get('job-applications/{application}', [JobApplicationController::class, 'show'])->whereNumber('application')->middleware('can:job_applications.view')->name('job-applications.show');
            Route::put('job-applications/{application}', [JobApplicationController::class, 'update'])->whereNumber('application')->middleware('can:job_applications.edit')->name('job-applications.update');
            Route::delete('job-applications/{application}', [JobApplicationController::class, 'destroy'])->whereNumber('application')->middleware('can:job_applications.delete')->name('job-applications.destroy');
            Route::delete('job-applications/{application}/force', [JobApplicationController::class, 'forceDestroy'])->whereNumber('application')->middleware('can:job_applications.delete')->name('job-applications.force-destroy');
            Route::post('job-applications/{application}/status', [JobApplicationController::class, 'status'])->whereNumber('application')->middleware('can:job_applications.change_status')->name('job-applications.status');
            Route::post('job-applications/{application}/assign', [JobApplicationController::class, 'assign'])->whereNumber('application')->middleware('can:job_applications.assign')->name('job-applications.assign');
            Route::get('job-applications/{application}/cv', [JobApplicationController::class, 'cv'])->whereNumber('application')->middleware('can:job_applications.download')->name('job-applications.cv');
        });

        // §8.10 Contact inquiries — index is `view` (not view_any): §9.1.2 reviewers see what is assigned to them
        Route::middleware('module:contact_inquiries')->group(function (): void {
            Route::get('contact-inquiries', [ContactInquiryController::class, 'index'])->middleware('can:contact_inquiries.view')->name('contact-inquiries.index');
            Route::get('contact-inquiries/export', [ContactInquiryController::class, 'export'])->middleware('can:contact_inquiries.export')->name('contact-inquiries.export');
            Route::post('contact-inquiries/route-pending', [ContactInquiryController::class, 'routePending'])->middleware('can:contact_inquiries.change_status')->name('contact-inquiries.route-pending');
            Route::get('contact-inquiries/{inquiry}', [ContactInquiryController::class, 'show'])->whereNumber('inquiry')->middleware('can:contact_inquiries.view')->name('contact-inquiries.show');
            Route::put('contact-inquiries/{inquiry}', [ContactInquiryController::class, 'update'])->whereNumber('inquiry')->middleware('can:contact_inquiries.edit')->name('contact-inquiries.update');
            Route::delete('contact-inquiries/{inquiry}', [ContactInquiryController::class, 'destroy'])->whereNumber('inquiry')->middleware('can:contact_inquiries.delete')->name('contact-inquiries.destroy');
            Route::post('contact-inquiries/{inquiry}/status', [ContactInquiryController::class, 'status'])->whereNumber('inquiry')->middleware('can:contact_inquiries.change_status')->name('contact-inquiries.status');
            Route::post('contact-inquiries/{inquiry}/route', [ContactInquiryController::class, 'route'])->whereNumber('inquiry')->middleware('can:contact_inquiries.change_status')->name('contact-inquiries.route');
            Route::post('contact-inquiries/{inquiry}/assign', [ContactInquiryController::class, 'assign'])->whereNumber('inquiry')->middleware('can:contact_inquiries.assign')->name('contact-inquiries.assign');
            Route::post('contact-inquiries/{inquiry}/spam', [ContactInquiryController::class, 'spam'])->whereNumber('inquiry')->middleware('can:contact_inquiries.change_status')->name('contact-inquiries.spam');
            Route::post('contact-inquiries/{inquiry}/not-spam', [ContactInquiryController::class, 'notSpam'])->whereNumber('inquiry')->middleware('can:contact_inquiries.change_status')->name('contact-inquiries.not-spam');
        });

        /*
        |------------------------------------------------------------------
        | phase-05 §7 — CRM: leads (list, board, follow-ups, import, conversion)
        |------------------------------------------------------------------
        | {lead} binds through LeadVisibilityScope, so another rep's lead is a 404 before any action runs (D30).
        | Controllers repeat the can: and add the policy's record rule.
        */
        Route::middleware('module:leads')->group(static function (): void {
            Route::get('leads', [LeadController::class, 'index'])->middleware('can:leads.view')->name('leads.index');
            Route::get('leads/board', [LeadBoardController::class, 'index'])->middleware('can:leads.view')->name('leads.board');
            Route::get('leads/board/column/{status}', [LeadBoardController::class, 'column'])->whereIn('status', LeadStatus::values())->middleware('can:leads.view')->name('leads.board.column');
            Route::get('leads/follow-ups', [LeadFollowUpController::class, 'index'])->middleware('can:leads.view')->name('leads.follow-ups.index');
            Route::get('leads/create', [LeadController::class, 'create'])->middleware('can:leads.create')->name('leads.create');
            Route::post('leads', [LeadController::class, 'store'])->middleware('can:leads.create')->name('leads.store');
            Route::post('leads/duplicate-check', [LeadController::class, 'duplicateCheck'])->middleware(['can:leads.create', 'throttle:60,1,crm-duplicate-check'])->name('leads.duplicate-check');
            Route::get('leads/export', [LeadController::class, 'export'])->middleware('can:leads.export')->name('leads.export');
            // C.7 #8: the link BuildCrmExport puts in the "export ready" notification.
            Route::get('leads/export/download', [LeadController::class, 'exportDownload'])->middleware('can:leads.export')->name('leads.export.download');
            Route::post('leads/bulk/assign', [LeadController::class, 'bulkAssign'])->middleware('can:leads.assign')->name('leads.bulk.assign');
            Route::post('leads/bulk/status', [LeadController::class, 'bulkStatus'])->middleware('can:leads.change_status')->name('leads.bulk.status');
            Route::post('leads/bulk/destroy', [LeadController::class, 'bulkDestroy'])->middleware('can:leads.delete')->name('leads.bulk.destroy');

            Route::get('leads/import', [LeadImportController::class, 'index'])->middleware('can:leads.import')->name('leads.import.index');
            Route::get('leads/import/template', [LeadImportController::class, 'template'])->middleware('can:leads.import')->name('leads.import.template');
            Route::post('leads/import', [LeadImportController::class, 'store'])->middleware('can:leads.import')->name('leads.import.store');
            Route::get('leads/import/{import}', [LeadImportController::class, 'show'])->whereNumber('import')->middleware('can:leads.import')->name('leads.import.show');
            Route::put('leads/import/{import}/mapping', [LeadImportController::class, 'mapping'])->whereNumber('import')->middleware('can:leads.import')->name('leads.import.mapping');
            Route::post('leads/import/{import}/validate', [LeadImportController::class, 'validateRows'])->whereNumber('import')->middleware('can:leads.import')->name('leads.import.validate');
            Route::post('leads/import/{import}/run', [LeadImportController::class, 'run'])->whereNumber('import')->middleware('can:leads.import')->name('leads.import.run');
            Route::post('leads/import/{import}/cancel', [LeadImportController::class, 'cancel'])->whereNumber('import')->middleware('can:leads.import')->name('leads.import.cancel');
            Route::get('leads/import/{import}/errors', [LeadImportController::class, 'errors'])->whereNumber('import')->middleware('can:leads.import')->name('leads.import.errors');

            // C.7 #1: the contract's `can:convert,lead` cannot resolve — this URI has no {lead}.
            Route::post('leads/conversions/{conversion}/supersede', [LeadConversionController::class, 'supersede'])->whereNumber('conversion')->middleware('can:supersede,conversion')->name('leads.conversions.supersede');

            Route::get('leads/{lead}', [LeadController::class, 'show'])->whereNumber('lead')->middleware('can:view,lead')->name('leads.show');
            Route::get('leads/{lead}/edit', [LeadController::class, 'edit'])->whereNumber('lead')->middleware('can:update,lead')->name('leads.edit');
            Route::put('leads/{lead}', [LeadController::class, 'update'])->whereNumber('lead')->middleware('can:update,lead')->name('leads.update');
            Route::delete('leads/{lead}', [LeadController::class, 'destroy'])->whereNumber('lead')->middleware('can:delete,lead')->name('leads.destroy');
            Route::post('leads/{lead}/restore', [LeadController::class, 'restore'])->whereNumber('lead')->withTrashed()->middleware('can:leads.restore')->name('leads.restore');
            Route::get('leads/{lead}/print', [LeadController::class, 'print'])->whereNumber('lead')->middleware('can:leads.print')->name('leads.print');
            Route::patch('leads/{lead}/status', [LeadController::class, 'status'])->whereNumber('lead')->middleware('can:changeStatus,lead')->name('leads.status');
            Route::patch('leads/{lead}/assign', [LeadController::class, 'assign'])->whereNumber('lead')->middleware('can:assign,lead')->name('leads.assign');
            Route::patch('leads/{lead}/board-move', [LeadBoardController::class, 'move'])->whereNumber('lead')->middleware(['can:leads.change_status', 'throttle:120,1,crm-board-move'])->name('leads.board.move');
            Route::post('leads/{lead}/duplicate-link', [LeadController::class, 'duplicateLink'])->whereNumber('lead')->middleware('can:update,lead')->name('leads.duplicate-link');

            Route::post('leads/{lead}/activities', [LeadActivityController::class, 'store'])->whereNumber('lead')->middleware('can:update,lead')->name('leads.activities.store');
            Route::put('leads/{lead}/activities/{activity}', [LeadActivityController::class, 'update'])->whereNumber(['lead', 'activity'])->middleware('can:update,activity')->name('leads.activities.update');
            Route::delete('leads/{lead}/activities/{activity}', [LeadActivityController::class, 'destroy'])->whereNumber(['lead', 'activity'])->middleware('can:delete,activity')->name('leads.activities.destroy');

            Route::post('leads/{lead}/follow-ups', [LeadFollowUpController::class, 'store'])->whereNumber('lead')->middleware('can:update,lead')->name('leads.follow-ups.store');
            Route::patch('leads/{lead}/follow-ups/{followUp}/complete', [LeadFollowUpController::class, 'complete'])->whereNumber(['lead', 'followUp'])->middleware('can:complete,followUp')->name('leads.follow-ups.complete');
            Route::patch('leads/{lead}/follow-ups/{followUp}/reschedule', [LeadFollowUpController::class, 'reschedule'])->whereNumber(['lead', 'followUp'])->middleware('can:complete,followUp')->name('leads.follow-ups.reschedule');
            Route::patch('leads/{lead}/follow-ups/{followUp}/cancel', [LeadFollowUpController::class, 'cancel'])->whereNumber(['lead', 'followUp'])->middleware('can:complete,followUp')->name('leads.follow-ups.cancel');

            Route::get('leads/{lead}/convert', [LeadConversionController::class, 'create'])->whereNumber('lead')->middleware('can:convert,lead')->name('leads.convert.form');
            Route::post('leads/{lead}/convert', [LeadConversionController::class, 'store'])->whereNumber('lead')->middleware('can:convert,lead')->name('leads.convert.store');
        });

        /*
        |------------------------------------------------------------------
        | phase-05 §7 — CRM: clients, contacts
        |------------------------------------------------------------------
        | Money is withheld, not hidden: the financial figures are computed and passed only for clients.view_financial.
        */
        Route::middleware('module:clients')->group(static function (): void {
            Route::get('clients', [ClientController::class, 'index'])->middleware('can:clients.view_any')->name('clients.index');
            Route::get('clients/create', [ClientController::class, 'create'])->middleware('can:clients.create')->name('clients.create');
            Route::post('clients', [ClientController::class, 'store'])->middleware('can:clients.create')->name('clients.store');
            Route::get('clients/export', [ClientController::class, 'export'])->middleware('can:clients.export')->name('clients.export');
            // C.7 #8: the link BuildCrmExport puts in the "export ready" notification.
            Route::get('clients/export/download', [ClientController::class, 'exportDownload'])->middleware('can:clients.export')->name('clients.export.download');
            Route::get('clients/{client}', [ClientController::class, 'show'])->whereNumber('client')->middleware('can:view,client')->name('clients.show');
            Route::get('clients/{client}/edit', [ClientController::class, 'edit'])->whereNumber('client')->middleware('can:update,client')->name('clients.edit');
            Route::put('clients/{client}', [ClientController::class, 'update'])->whereNumber('client')->middleware('can:update,client')->name('clients.update');
            Route::delete('clients/{client}', [ClientController::class, 'destroy'])->whereNumber('client')->middleware('can:delete,client')->name('clients.destroy');
            Route::post('clients/{client}/restore', [ClientController::class, 'restore'])->whereNumber('client')->withTrashed()->middleware('can:clients.restore')->name('clients.restore');
            Route::get('clients/{client}/print', [ClientController::class, 'print'])->whereNumber('client')->middleware('can:clients.print')->name('clients.print');
            Route::patch('clients/{client}/status', [ClientController::class, 'status'])->whereNumber('client')->middleware('can:changeStatus,client')->name('clients.status');
            Route::patch('clients/{client}/account-manager', [ClientController::class, 'accountManager'])->whereNumber('client')->middleware('can:assign,client')->name('clients.account-manager');
            Route::get('clients/{client}/financials', [ClientController::class, 'financials'])->whereNumber('client')->middleware('can:viewFinancial,client')->name('clients.financials');
            Route::post('clients/{client}/portal/enable', [ClientController::class, 'enablePortal'])->whereNumber('client')->middleware('can:managePortal,client')->name('clients.portal.enable');
            Route::post('clients/{client}/portal/disable', [ClientController::class, 'disablePortal'])->whereNumber('client')->middleware('can:managePortal,client')->name('clients.portal.disable');

            Route::post('clients/{client}/contacts', [ClientContactController::class, 'store'])->whereNumber('client')->middleware('can:update,client')->name('clients.contacts.store');
            Route::put('clients/{client}/contacts/{contact}', [ClientContactController::class, 'update'])->whereNumber(['client', 'contact'])->middleware('can:update,contact')->name('clients.contacts.update');
            Route::patch('clients/{client}/contacts/{contact}/primary', [ClientContactController::class, 'primary'])->whereNumber(['client', 'contact'])->middleware('can:update,contact')->name('clients.contacts.primary');
            Route::delete('clients/{client}/contacts/{contact}', [ClientContactController::class, 'destroy'])->whereNumber(['client', 'contact'])->middleware('can:delete,contact')->name('clients.contacts.destroy');
        });

        /*
        |------------------------------------------------------------------
        | phase-05 §7 — CRM: private client documents (D21)
        |------------------------------------------------------------------
        | The list (view_any) and the download (download) are independent grants (test 70).
        */
        Route::middleware('module:client_documents')->group(static function (): void {
            Route::get('clients/{client}/documents', [ClientDocumentController::class, 'index'])->whereNumber('client')->middleware('can:client_documents.view_any')->name('clients.documents.index');
            Route::post('clients/{client}/documents', [ClientDocumentController::class, 'store'])->whereNumber('client')->middleware('can:client_documents.upload')->name('clients.documents.store');
            Route::put('clients/{client}/documents/{document}', [ClientDocumentController::class, 'update'])->whereNumber(['client', 'document'])->middleware('can:update,document')->name('clients.documents.update');
            Route::patch('clients/{client}/documents/{document}/visibility', [ClientDocumentController::class, 'visibility'])->whereNumber(['client', 'document'])->middleware('can:changeVisibility,document')->name('clients.documents.visibility');
            Route::delete('clients/{client}/documents/{document}', [ClientDocumentController::class, 'destroy'])->whereNumber(['client', 'document'])->middleware('can:delete,document')->name('clients.documents.destroy');
            Route::get('client-documents/{document}/download', [ClientDocumentController::class, 'download'])->whereNumber('document')->middleware('can:download,document')->name('client-documents.download');
        });

        /*
        |------------------------------------------------------------------
        | phase-06 §7.1 — Projects
        |------------------------------------------------------------------
        | `restore` takes a raw id rather than a bound model: the binding would 404 on a trashed row
        | before the policy could allow it. Ownership failures answer 404, not 403 (INV-P15).
        */
        Route::middleware('module:projects')->group(static function (): void {
            Route::get('projects', [ProjectController::class, 'index'])->middleware('can:viewAny,'.Project::class)->name('projects.index');
            Route::get('projects/create', [ProjectController::class, 'create'])->middleware('can:projects.create')->name('projects.create');
            Route::post('projects', [ProjectController::class, 'store'])->middleware('can:projects.create')->name('projects.store');
            Route::get('projects/{project}', [ProjectController::class, 'show'])->whereNumber('project')->middleware('can:view,project')->name('projects.show');
            Route::get('projects/{project}/edit', [ProjectController::class, 'edit'])->whereNumber('project')->middleware('can:update,project')->name('projects.edit');
            Route::put('projects/{project}', [ProjectController::class, 'update'])->whereNumber('project')->middleware('can:update,project')->name('projects.update');
            Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->whereNumber('project')->middleware('can:delete,project')->name('projects.destroy');
            Route::post('projects/{project}/restore', [ProjectController::class, 'restore'])->whereNumber('project')->middleware('can:projects.restore')->name('projects.restore');
            Route::post('projects/{project}/status', [ProjectController::class, 'status'])->whereNumber('project')->middleware('can:projects.change_status')->name('projects.status');
            Route::post('projects/{project}/progress', [ProjectController::class, 'progress'])->whereNumber('project')->middleware('can:projects.edit')->name('projects.progress');
            Route::get('projects/{project}/value', [ProjectController::class, 'value'])->whereNumber('project')->middleware('can:viewFinancial,project')->name('projects.value.index');
            Route::post('projects/{project}/value', [ProjectController::class, 'storeValue'])->whereNumber('project')->middleware('can:revise,project')->name('projects.value.store');

            Route::get('projects/{project}/members', [ProjectMemberController::class, 'index'])->whereNumber('project')->middleware('can:view,project')->name('projects.members.index');
            Route::post('projects/{project}/members', [ProjectMemberController::class, 'store'])->whereNumber('project')->middleware('can:assign,project')->name('projects.members.store');
            Route::put('projects/{project}/members/{member}', [ProjectMemberController::class, 'update'])->whereNumber(['project', 'member'])->middleware('can:update,member')->name('projects.members.update');
            Route::delete('projects/{project}/members/{member}', [ProjectMemberController::class, 'destroy'])->whereNumber(['project', 'member'])->middleware('can:delete,member')->name('projects.members.destroy');
        });

        /*
        |------------------------------------------------------------------
        | phase-06 §7.2 — Milestones
        |------------------------------------------------------------------
        | `amount` has no ability of its own: it is money, gated by `projects.view_financial` (§4.2).
        */
        Route::middleware('module:project_milestones')->group(static function (): void {
            Route::get('projects/{project}/milestones', [MilestoneController::class, 'index'])->whereNumber('project')->middleware('can:project_milestones.view_any')->name('milestones.index');
            Route::post('projects/{project}/milestones', [MilestoneController::class, 'store'])->whereNumber('project')->middleware('can:project_milestones.create')->name('milestones.store');
            Route::post('projects/{project}/milestones/reorder', [MilestoneController::class, 'reorder'])->whereNumber('project')->middleware('can:project_milestones.edit')->name('milestones.reorder');
            Route::get('milestones/{milestone}', [MilestoneController::class, 'show'])->whereNumber('milestone')->middleware('can:view,milestone')->name('milestones.show');
            Route::put('milestones/{milestone}', [MilestoneController::class, 'update'])->whereNumber('milestone')->middleware('can:update,milestone')->name('milestones.update');
            Route::delete('milestones/{milestone}', [MilestoneController::class, 'destroy'])->whereNumber('milestone')->middleware('can:delete,milestone')->name('milestones.destroy');
            Route::post('milestones/{milestone}/status', [MilestoneController::class, 'status'])->whereNumber('milestone')->middleware('can:project_milestones.change_status')->name('milestones.status');
        });

        /*
        |------------------------------------------------------------------
        | phase-06 §7.3 — Tasks and the Kanban board
        |------------------------------------------------------------------
        | `move` is throttled because it is a drag handler: a stuck pointer should cost one 429, not a
        | thousand writes. The board lives under `projects` too, so a project page can open its own.
        */
        Route::middleware('module:tasks')->group(static function (): void {
            Route::get('tasks', [TaskController::class, 'index'])->middleware('can:tasks.view_any')->name('tasks.index');
            Route::get('tasks/board', [TaskBoardController::class, 'index'])->middleware('can:tasks.view_any')->name('tasks.board');
            Route::get('tasks/my', [TaskController::class, 'mine'])->middleware('can:tasks.view')->name('tasks.my');
            Route::get('tasks/create', [TaskController::class, 'create'])->middleware('can:tasks.create')->name('tasks.create');
            Route::post('tasks', [TaskController::class, 'store'])->middleware('can:tasks.create')->name('tasks.store');
            Route::get('tasks/{task}', [TaskController::class, 'show'])->whereNumber('task')->middleware('can:view,task')->name('tasks.show');
            Route::put('tasks/{task}', [TaskController::class, 'update'])->whereNumber('task')->middleware('can:update,task')->name('tasks.update');
            Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->whereNumber('task')->middleware('can:delete,task')->name('tasks.destroy');
            Route::post('tasks/{task}/restore', [TaskController::class, 'restore'])->whereNumber('task')->middleware('can:tasks.restore')->name('tasks.restore');
            Route::post('tasks/{task}/status', [TaskController::class, 'status'])->whereNumber('task')->middleware('can:changeStatus,task')->name('tasks.status');
            Route::post('tasks/{task}/assign', [TaskController::class, 'assign'])->whereNumber('task')->middleware('can:assign,task')->name('tasks.assign');
            Route::post('tasks/{task}/move', [TaskBoardController::class, 'move'])->whereNumber('task')->middleware(['can:changeStatus,task', 'throttle:120,1'])->name('tasks.move');
            Route::post('tasks/{task}/subtasks', [TaskController::class, 'storeSubtask'])->whereNumber('task')->middleware('can:tasks.create')->name('tasks.subtasks.store');
            Route::post('tasks/{task}/checklist', [TaskChecklistController::class, 'store'])->whereNumber('task')->middleware('can:update,task')->name('checklist.store');
            Route::put('checklist-items/{item}', [TaskChecklistController::class, 'update'])->whereNumber('item')->middleware('can:update,item')->name('checklist.update');
            Route::delete('checklist-items/{item}', [TaskChecklistController::class, 'destroy'])->whereNumber('item')->middleware('can:delete,item')->name('checklist.destroy');

            Route::get('projects/{project}/board', [TaskBoardController::class, 'forProject'])->whereNumber('project')->middleware('can:tasks.view_any')->name('projects.board');
        });

        /*
        |------------------------------------------------------------------
        | phase-06 §7.5 — Time tracking
        |------------------------------------------------------------------
        | Starting, pausing, resuming and stopping are `create`: the worker is logging their own time.
        | Editing somebody else's entry needs `edit` plus `view_any`, which the policy asks for.
        */
        Route::middleware('module:time_tracking')->group(static function (): void {
            Route::get('time', [TimeTrackingController::class, 'index'])->middleware('can:time_tracking.view')->name('time.index');
            Route::post('time', [TimeTrackingController::class, 'store'])->middleware('can:time_tracking.create')->name('time.store');
            Route::put('time/{entry}', [TimeTrackingController::class, 'update'])->whereNumber('entry')->middleware('can:update,entry')->name('time.update');
            Route::delete('time/{entry}', [TimeTrackingController::class, 'destroy'])->whereNumber('entry')->middleware('can:delete,entry')->name('time.destroy');
            Route::post('timer/start', [TimeTrackingController::class, 'start'])->middleware('can:time_tracking.create')->name('timer.start');
            Route::post('timer/{entry}/pause', [TimeTrackingController::class, 'pause'])->whereNumber('entry')->middleware('can:runTimer,entry')->name('timer.pause');
            Route::post('timer/{entry}/resume', [TimeTrackingController::class, 'resume'])->whereNumber('entry')->middleware('can:runTimer,entry')->name('timer.resume');
            Route::post('timer/{entry}/stop', [TimeTrackingController::class, 'stop'])->whereNumber('entry')->middleware('can:runTimer,entry')->name('timer.stop');
        });

        /*
        |------------------------------------------------------------------
        | phase-07 §7.1 — Employees
        |------------------------------------------------------------------
        | `restore` takes a raw id rather than a bound model: the binding would 404 on an archived row
        | before the policy could allow it. Falling outside the §9 window answers 404, never 403 — the
        | ids being probed here are people's salaries.
        */
        Route::middleware('module:employees')->group(static function (): void {
            Route::get('employees', [EmployeeController::class, 'index'])->middleware('can:viewAny,'.Employee::class)->name('employees.index');
            Route::get('employees/create', [EmployeeController::class, 'create'])->middleware('can:employees.create')->name('employees.create');
            Route::post('employees', [EmployeeController::class, 'store'])->middleware('can:employees.create')->name('employees.store');
            Route::get('employees/export/{format}', [EmployeeController::class, 'export'])->middleware('can:employees.export')->name('employees.export');
            Route::get('employees/{employee}', [EmployeeController::class, 'show'])->whereNumber('employee')->middleware('can:view,employee')->name('employees.show');
            Route::get('employees/{employee}/edit', [EmployeeController::class, 'edit'])->whereNumber('employee')->middleware('can:update,employee')->name('employees.edit');
            Route::put('employees/{employee}', [EmployeeController::class, 'update'])->whereNumber('employee')->middleware('can:update,employee')->name('employees.update');
            Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])->whereNumber('employee')->middleware('can:delete,employee')->name('employees.destroy');
            Route::post('employees/{employee}/restore', [EmployeeController::class, 'restore'])->whereNumber('employee')->middleware('can:employees.restore')->name('employees.restore');
            Route::post('employees/{employee}/status', [EmployeeController::class, 'status'])->whereNumber('employee')->middleware('can:changeStatus,employee')->name('employees.status');
            Route::post('employees/{employee}/assignments', [EmployeeController::class, 'assignments'])->whereNumber('employee')->middleware('can:assign,employee')->name('employees.assignments');
            Route::post('employees/{employee}/user', [EmployeeController::class, 'linkUser'])->whereNumber('employee')->middleware('can:update,employee')->name('employees.user.link');
            Route::delete('employees/{employee}/user', [EmployeeController::class, 'unlinkUser'])->whereNumber('employee')->middleware('can:update,employee')->name('employees.user.unlink');
            Route::get('employees/{employee}/print', [EmployeeController::class, 'print'])->whereNumber('employee')->middleware('can:print,employee')->name('employees.print');
        });

        /*
        |------------------------------------------------------------------
        | phase-07 §7.7 — Employee self-service (/admin/my/*)
        |------------------------------------------------------------------
        | Every one of these resolves auth()->user()->employee and queries only through its relations. No
        | id from the request ever reaches a where, and a user with no employee record gets a 404 rather
        | than a 403 — a 403 would confirm that the area exists and that they are simply not staff.
        |
        | Punching is throttled: a button somebody leans on should cost one 429, not sixty rows.
        */
        Route::middleware('module:employee_self_service')->prefix('my')->name('my.')->group(static function (): void {
            Route::get('hr-profile', [MyProfileController::class, 'show'])->middleware('can:employee_self_service.view')->name('profile');

            Route::get('attendance', [MyAttendanceController::class, 'index'])->middleware('can:employee_self_service.view')->name('attendance.index');
            Route::post('attendance/check-in', [MyAttendanceController::class, 'checkIn'])->middleware(['can:employee_self_service.create', 'throttle:10,1'])->name('attendance.check-in');
            Route::post('attendance/check-out', [MyAttendanceController::class, 'checkOut'])->middleware(['can:employee_self_service.create', 'throttle:10,1'])->name('attendance.check-out');
            Route::post('attendance/corrections', [MyAttendanceController::class, 'requestCorrection'])->middleware('can:employee_self_service.create')->name('attendance.corrections.store');

            Route::get('leave', [MyLeaveController::class, 'index'])->middleware('can:employee_self_service.view')->name('leave.index');
            Route::get('leave/create', [MyLeaveController::class, 'create'])->middleware('can:employee_self_service.create')->name('leave.create');
            Route::post('leave', [MyLeaveController::class, 'store'])->middleware('can:employee_self_service.create')->name('leave.store');
            Route::get('leave/{leaveRequest}', [MyLeaveController::class, 'show'])->whereNumber('leaveRequest')->middleware('can:employee_self_service.view')->name('leave.show');
            Route::post('leave/{leaveRequest}/cancel', [MyLeaveController::class, 'cancel'])->whereNumber('leaveRequest')->middleware('can:employee_self_service.create')->name('leave.cancel');

            Route::get('payslips', [MyPayslipController::class, 'index'])->middleware('can:employee_self_service.view_financial')->name('payslips.index');
            Route::get('payslips/{item}', [MyPayslipController::class, 'show'])->whereNumber('item')->middleware('can:employee_self_service.view_financial')->name('payslips.show');
            Route::get('payslips/{item}/print', [MyPayslipController::class, 'print'])->whereNumber('item')->middleware('can:employee_self_service.print')->name('payslips.print');

            Route::get('approvals', [MyLeaveController::class, 'approvals'])->middleware('can:leaves.approve')->name('approvals.index');
        });

        /*
        |------------------------------------------------------------------
        | phase-07 §7.3 — Attendance, corrections and the monthly summaries
        |------------------------------------------------------------------
        | `punch` is throttled because a kiosk is a button somebody leans on. Nothing here writes a status
        | directly: a manual change goes through a correction, so both paths leave the same evidence.
        */
        Route::middleware('module:attendance')->group(static function (): void {
            Route::get('attendance', [AttendanceController::class, 'index'])->middleware('can:viewAny,'.Attendance::class)->name('attendance.index');
            Route::get('attendance/monthly', [AttendanceController::class, 'monthly'])->middleware('can:attendance.view_any')->name('attendance.monthly');
            Route::get('attendance/summaries', [AttendanceSummaryController::class, 'index'])->middleware('can:attendance.view_reports')->name('attendance-summaries.index');
            Route::post('attendance/summaries/rebuild', [AttendanceSummaryController::class, 'rebuild'])->middleware('can:attendance.change_status')->name('attendance-summaries.rebuild');
            Route::get('attendance/summaries/export/{format}', [AttendanceSummaryController::class, 'export'])->middleware('can:attendance.export')->name('attendance-summaries.export');
            Route::get('attendance/export/{format}', [AttendanceController::class, 'export'])->middleware('can:attendance.export')->name('attendance.export');
            Route::post('attendance/punch', [AttendanceController::class, 'punch'])->middleware(['can:attendance.create', 'throttle:60,1'])->name('attendance.punch');
            Route::post('attendance/mark', [AttendanceController::class, 'mark'])->middleware('can:attendance.create')->name('attendance.mark');
            Route::post('attendance/close', [AttendanceController::class, 'close'])->middleware('can:attendance.create')->name('attendance.close');
            Route::get('attendance/{attendance}', [AttendanceController::class, 'show'])->whereNumber('attendance')->middleware('can:view,attendance')->name('attendance.show');
            Route::post('attendance/{attendance}/recompute', [AttendanceController::class, 'recompute'])->whereNumber('attendance')->middleware('can:update,attendance')->name('attendance.recompute');
            Route::post('attendance/{attendance}/corrections', [AttendanceCorrectionController::class, 'store'])->whereNumber('attendance')->middleware('can:update,attendance')->name('attendance.corrections.store');

            Route::get('attendance-corrections', [AttendanceCorrectionController::class, 'index'])->middleware('can:viewAny,'.AttendanceCorrection::class)->name('attendance-corrections.index');
            Route::post('attendance-corrections/{correction}/approve', [AttendanceCorrectionController::class, 'approve'])->whereNumber('correction')->middleware('can:approve,correction')->name('attendance-corrections.approve');
            Route::post('attendance-corrections/{correction}/reject', [AttendanceCorrectionController::class, 'reject'])->whereNumber('correction')->middleware('can:reject,correction')->name('attendance-corrections.reject');
        });

        /*
        |------------------------------------------------------------------
        | phase-07 §7.4 — Leave requests and balances
        |------------------------------------------------------------------
        | Approving and rejecting are decided by the policy, not by a permission alone: the named approver
        | may act without holding `leaves.approve`, and nobody may act on their own request.
        */
        Route::middleware('module:leaves')->group(static function (): void {
            Route::get('leaves', [LeaveRequestController::class, 'index'])->middleware('can:viewAny,'.LeaveRequest::class)->name('leaves.index');
            Route::get('leaves/calendar', [LeaveRequestController::class, 'calendar'])->middleware('can:leaves.view_any')->name('leaves.calendar');
            Route::get('leaves/create', [LeaveRequestController::class, 'create'])->middleware('can:leaves.create')->name('leaves.create');
            Route::post('leaves', [LeaveRequestController::class, 'store'])->middleware('can:leaves.create')->name('leaves.store');
            Route::get('leaves/{leaveRequest}', [LeaveRequestController::class, 'show'])->whereNumber('leaveRequest')->middleware('can:view,leaveRequest')->name('leaves.show');
            Route::post('leaves/{leaveRequest}/approve', [LeaveRequestController::class, 'approve'])->whereNumber('leaveRequest')->middleware('can:approve,leaveRequest')->name('leaves.approve');
            Route::post('leaves/{leaveRequest}/reject', [LeaveRequestController::class, 'reject'])->whereNumber('leaveRequest')->middleware('can:reject,leaveRequest')->name('leaves.reject');
            Route::post('leaves/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])->whereNumber('leaveRequest')->middleware('can:changeStatus,leaveRequest')->name('leaves.cancel');
        });

        Route::middleware('module:leave_balances')->group(static function (): void {
            Route::get('leave-balances', [LeaveBalanceController::class, 'index'])->middleware('can:viewAny,'.LeaveBalance::class)->name('leave-balances.index');
            Route::post('leave-balances/adjust', [LeaveBalanceController::class, 'adjust'])->middleware('can:leave_balances.create')->name('leave-balances.adjust');
            Route::post('leave-balances/grant-year', [LeaveBalanceController::class, 'grantYear'])->middleware('can:leave_balances.create')->name('leave-balances.grant-year');
            Route::get('leave-balances/export/{format}', [LeaveBalanceController::class, 'export'])->middleware('can:leave_balances.export')->name('leave-balances.export');
            Route::get('leave-balances/{employee}', [LeaveBalanceController::class, 'show'])->whereNumber('employee')->middleware('can:view,employee')->name('leave-balances.show');
        });

        /*
        |------------------------------------------------------------------
        | phase-07 §7.5 — Salary structures and advances
        |------------------------------------------------------------------
        | Neither module has an `edit` or a `delete` (§4.1): a rate is never updated and an advance is
        | never deleted. The overview route is this phase's own addition — a sidebar entry needs a URL
        | with no parameters in it.
        */
        Route::middleware('module:salary_structures')->group(static function (): void {
            Route::get('salary-structures', [SalaryStructureController::class, 'overview'])->middleware('can:viewAny,'.SalaryStructure::class)->name('salary-structures.index');
            Route::get('employees/{employee}/salary-structures', [SalaryStructureController::class, 'index'])->whereNumber('employee')->middleware('can:view,employee')->name('employees.salary-structures.index');
            Route::get('employees/{employee}/salary-structures/create', [SalaryStructureController::class, 'create'])->whereNumber('employee')->middleware('can:salary_structures.create')->name('employees.salary-structures.create');
            Route::post('employees/{employee}/salary-structures', [SalaryStructureController::class, 'store'])->whereNumber('employee')->middleware('can:salary_structures.create')->name('employees.salary-structures.store');
            Route::post('salary-structures/{structure}/cancel', [SalaryStructureController::class, 'cancel'])->whereNumber('structure')->middleware('can:changeStatus,structure')->name('salary-structures.cancel');
        });

        Route::middleware('module:employee_advances')->group(static function (): void {
            Route::get('advances', [EmployeeAdvanceController::class, 'index'])->middleware('can:viewAny,'.EmployeeAdvance::class)->name('advances.index');
            Route::get('advances/create', [EmployeeAdvanceController::class, 'create'])->middleware('can:employee_advances.create')->name('advances.create');
            Route::post('advances', [EmployeeAdvanceController::class, 'store'])->middleware('can:employee_advances.create')->name('advances.store');
            Route::get('advances/{advance}', [EmployeeAdvanceController::class, 'show'])->whereNumber('advance')->middleware('can:view,advance')->name('advances.show');
            Route::post('advances/{advance}/approve', [EmployeeAdvanceController::class, 'approve'])->whereNumber('advance')->middleware('can:approve,advance')->name('advances.approve');
            Route::post('advances/{advance}/reject', [EmployeeAdvanceController::class, 'reject'])->whereNumber('advance')->middleware('can:reject,advance')->name('advances.reject');
            Route::post('advances/{advance}/disburse', [EmployeeAdvanceController::class, 'disburse'])->whereNumber('advance')->middleware('can:changeStatus,advance')->name('advances.disburse');
            Route::post('advances/{advance}/recovery', [EmployeeAdvanceController::class, 'recovery'])->whereNumber('advance')->middleware('can:changeStatus,advance')->name('advances.recovery');
            Route::post('advances/{advance}/waive', [EmployeeAdvanceController::class, 'waive'])->whereNumber('advance')->middleware('can:waive,advance')->name('advances.waive');
            Route::post('advances/{advance}/cancel', [EmployeeAdvanceController::class, 'cancel'])->whereNumber('advance')->middleware('can:changeStatus,advance')->name('advances.cancel');
        });

        /*
        |------------------------------------------------------------------
        | phase-07 §7.6 — Payroll runs and salary slips
        |------------------------------------------------------------------
        | `generate` is throttled: rebuilding a four-hundred-slip run is expensive and a double click
        | should cost one 429 rather than two runs. There is no unlock route, because there is no unlock.
        */
        Route::middleware('module:payroll')->group(static function (): void {
            Route::get('payroll', [PayrollRunController::class, 'index'])->middleware('can:viewAny,'.PayrollRun::class)->name('payroll-runs.index');
            Route::get('payroll/create', [PayrollRunController::class, 'create'])->middleware('can:payroll.create')->name('payroll-runs.create');
            Route::post('payroll', [PayrollRunController::class, 'store'])->middleware('can:payroll.create')->name('payroll-runs.store');
            Route::post('payroll/items/{item}/pay', [PayrollRunItemController::class, 'pay'])->whereNumber('item')->middleware('can:pay,item')->name('payroll-items.pay');
            Route::post('payroll/items/{item}/hold', [PayrollRunItemController::class, 'hold'])->whereNumber('item')->middleware('can:hold,item')->name('payroll-items.hold');
            Route::post('payroll/items/{item}/release', [PayrollRunItemController::class, 'release'])->whereNumber('item')->middleware('can:hold,item')->name('payroll-items.release');
            Route::post('payroll/items/{item}/correction', [PayrollRunItemController::class, 'correction'])->whereNumber('item')->middleware('can:correct,item')->name('payroll-items.correction');
            Route::get('payroll/{run}', [PayrollRunController::class, 'show'])->whereNumber('run')->middleware('can:view,run')->name('payroll-runs.show');
            Route::post('payroll/{run}/generate', [PayrollRunController::class, 'generate'])->whereNumber('run')->middleware(['can:generate,run', 'throttle:6,1'])->name('payroll-runs.generate');
            Route::get('payroll/{run}/preview/{employee}', [PayrollRunController::class, 'preview'])->whereNumber(['run', 'employee'])->middleware('can:viewFinancial,run')->name('payroll-runs.preview');
            Route::post('payroll/{run}/lock', [PayrollRunController::class, 'lock'])->whereNumber('run')->middleware('can:approve,run')->name('payroll-runs.lock');
            Route::post('payroll/{run}/cancel', [PayrollRunController::class, 'cancel'])->whereNumber('run')->middleware('can:cancel,run')->name('payroll-runs.cancel');
            Route::get('payroll/{run}/export/{format}', [PayrollRunController::class, 'export'])->whereNumber('run')->middleware('can:payroll.export')->name('payroll-runs.export');
            Route::get('payroll/{run}/register/print', [PayrollRunController::class, 'register'])->whereNumber('run')->middleware('can:payroll.print')->name('payroll-runs.register.print');
        });

        Route::middleware('module:salary_slips')->group(static function (): void {
            Route::get('payslips', [PayslipController::class, 'index'])->middleware('can:viewAny,'.PayrollRunItem::class)->name('payslips.index');
            Route::get('payslips/export/{format}', [PayslipController::class, 'export'])->middleware('can:salary_slips.export')->name('payslips.export');
            Route::get('payslips/{item}', [PayslipController::class, 'show'])->whereNumber('item')->middleware('can:view,item')->name('payslips.show');
            Route::get('payslips/{item}/print', [PayslipController::class, 'print'])->whereNumber('item')->middleware('can:print,item')->name('payslips.print');
        });

        /*
        |------------------------------------------------------------------
        | phase-07 §7.1, §7.2, §7.4, §7.5 — HR setup
        |------------------------------------------------------------------
        | Six catalogues: the org chart, the working patterns, the calendar, the leave rules and the
        | salary component list. Each is its own module slug, so a business that does not run shifts can
        | switch that one off without losing departments.
        */
        Route::middleware('module:departments')->group(static function (): void {
            Route::get('departments', [DepartmentController::class, 'index'])->middleware('can:departments.view_any')->name('departments.index');
            Route::post('departments', [DepartmentController::class, 'store'])->middleware('can:departments.create')->name('departments.store');
            Route::put('departments/{department}', [DepartmentController::class, 'update'])->whereNumber('department')->middleware('can:update,department')->name('departments.update');
            Route::post('departments/{department}/head', [DepartmentController::class, 'head'])->whereNumber('department')->middleware('can:assign,department')->name('departments.head');
            Route::post('departments/{department}/toggle', [DepartmentController::class, 'toggle'])->whereNumber('department')->middleware('can:changeStatus,department')->name('departments.toggle');
            Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])->whereNumber('department')->middleware('can:delete,department')->name('departments.destroy');
        });

        Route::middleware('module:designations')->group(static function (): void {
            Route::get('designations', [DesignationController::class, 'index'])->middleware('can:designations.view_any')->name('designations.index');
            Route::post('designations', [DesignationController::class, 'store'])->middleware('can:designations.create')->name('designations.store');
            Route::put('designations/{designation}', [DesignationController::class, 'update'])->whereNumber('designation')->middleware('can:update,designation')->name('designations.update');
            Route::post('designations/{designation}/toggle', [DesignationController::class, 'toggle'])->whereNumber('designation')->middleware('can:changeStatus,designation')->name('designations.toggle');
            Route::delete('designations/{designation}', [DesignationController::class, 'destroy'])->whereNumber('designation')->middleware('can:delete,designation')->name('designations.destroy');
        });

        Route::middleware('module:work_shifts')->group(static function (): void {
            Route::get('work-shifts', [WorkShiftController::class, 'index'])->middleware('can:work_shifts.view_any')->name('work-shifts.index');
            Route::post('work-shifts', [WorkShiftController::class, 'store'])->middleware('can:work_shifts.create')->name('work-shifts.store');
            Route::put('work-shifts/{shift}', [WorkShiftController::class, 'update'])->whereNumber('shift')->middleware('can:update,shift')->name('work-shifts.update');
            Route::post('work-shifts/{shift}/toggle', [WorkShiftController::class, 'toggle'])->whereNumber('shift')->middleware('can:changeStatus,shift')->name('work-shifts.toggle');
            Route::post('work-shifts/{shift}/assign', [WorkShiftController::class, 'assign'])->whereNumber('shift')->middleware('can:assign,shift')->name('work-shifts.assign');
            Route::delete('work-shifts/{shift}', [WorkShiftController::class, 'destroy'])->whereNumber('shift')->middleware('can:delete,shift')->name('work-shifts.destroy');
        });

        Route::middleware('module:holidays')->group(static function (): void {
            Route::get('holidays', [HolidayController::class, 'index'])->middleware('can:holidays.view_any')->name('holidays.index');
            Route::get('holidays/calendar', [HolidayController::class, 'calendar'])->middleware('can:holidays.view_any')->name('holidays.calendar');
            Route::post('holidays', [HolidayController::class, 'store'])->middleware('can:holidays.create')->name('holidays.store');
            Route::post('holidays/copy-year', [HolidayController::class, 'copyYear'])->middleware('can:holidays.create')->name('holidays.copy-year');
            Route::put('holidays/{holiday}', [HolidayController::class, 'update'])->whereNumber('holiday')->middleware('can:update,holiday')->name('holidays.update');
            Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy'])->whereNumber('holiday')->middleware('can:delete,holiday')->name('holidays.destroy');
        });

        Route::middleware('module:leave_types')->group(static function (): void {
            Route::get('leave-types', [LeaveTypeController::class, 'index'])->middleware('can:leave_types.view_any')->name('leave-types.index');
            Route::post('leave-types', [LeaveTypeController::class, 'store'])->middleware('can:leave_types.create')->name('leave-types.store');
            Route::put('leave-types/{leaveType}', [LeaveTypeController::class, 'update'])->whereNumber('leaveType')->middleware('can:update,leaveType')->name('leave-types.update');
            Route::post('leave-types/{leaveType}/toggle', [LeaveTypeController::class, 'toggle'])->whereNumber('leaveType')->middleware('can:changeStatus,leaveType')->name('leave-types.toggle');
            Route::delete('leave-types/{leaveType}', [LeaveTypeController::class, 'destroy'])->whereNumber('leaveType')->middleware('can:delete,leaveType')->name('leave-types.destroy');
        });

        Route::middleware('module:salary_components')->group(static function (): void {
            Route::get('salary-components', [SalaryComponentController::class, 'index'])->middleware('can:salary_components.view_any')->name('salary-components.index');
            Route::post('salary-components', [SalaryComponentController::class, 'store'])->middleware('can:salary_components.create')->name('salary-components.store');
            Route::put('salary-components/{component}', [SalaryComponentController::class, 'update'])->whereNumber('component')->middleware('can:update,component')->name('salary-components.update');
            Route::post('salary-components/{component}/toggle', [SalaryComponentController::class, 'toggle'])->whereNumber('component')->middleware('can:changeStatus,component')->name('salary-components.toggle');
            Route::delete('salary-components/{component}', [SalaryComponentController::class, 'destroy'])->whereNumber('component')->middleware('can:delete,component')->name('salary-components.destroy');
        });

        /*
        |----------------------------------------------------------------------
        | Collaborators — phase-08-09 §7.1
        |----------------------------------------------------------------------
        |
        | Order matters: `pending`, `options` and `export` are literal segments
        | and must be declared before `{collaborator}`, or a request for
        | /admin/collaborators/pending would bind "pending" as a model key.
        | `whereNumber` on the binding makes that a 404 rather than a 500, but
        | the ordering is what makes the literal routes reachable at all.
        |
        */
        Route::middleware('module:collaborators')->group(static function (): void {
            Route::get('collaborators', [CollaboratorController::class, 'index'])->middleware('can:viewAny,'.Collaborator::class)->name('collaborators.index');
            Route::get('collaborators/create', [CollaboratorController::class, 'create'])->middleware('can:collaborators.create')->name('collaborators.create');
            Route::post('collaborators', [CollaboratorController::class, 'store'])->middleware('can:collaborators.create')->name('collaborators.store');
            Route::get('collaborators/pending', [CollaboratorController::class, 'pending'])->middleware('can:collaborators.approve')->name('collaborators.pending');
            // The §9 picker: five columns, admin panel only, throttled because it is the one collaborator
            // endpoint a non-admin role can reach and a name list is worth rate limiting.
            Route::get('collaborators/options', [CollaboratorController::class, 'options'])->middleware(['can:collaborator_referrals.create', 'throttle:60,1'])->name('collaborators.options');
            Route::get('collaborators/export/{format}', [CollaboratorController::class, 'export'])->middleware('can:collaborators.export')->name('collaborators.export');

            Route::get('collaborators/{collaborator}', [CollaboratorController::class, 'show'])->whereNumber('collaborator')->middleware('can:view,collaborator')->name('collaborators.show');
            Route::get('collaborators/{collaborator}/edit', [CollaboratorController::class, 'edit'])->whereNumber('collaborator')->middleware('can:update,collaborator')->name('collaborators.edit');
            Route::put('collaborators/{collaborator}', [CollaboratorController::class, 'update'])->whereNumber('collaborator')->middleware('can:update,collaborator')->name('collaborators.update');
            Route::delete('collaborators/{collaborator}', [CollaboratorController::class, 'destroy'])->whereNumber('collaborator')->middleware('can:delete,collaborator')->name('collaborators.destroy');
            Route::post('collaborators/{collaborator}/restore', [CollaboratorController::class, 'restore'])->whereNumber('collaborator')->withTrashed()->middleware('can:restore,collaborator')->name('collaborators.restore');

            Route::post('collaborators/{collaborator}/approve', [CollaboratorController::class, 'approve'])->whereNumber('collaborator')->middleware('can:approve,collaborator')->name('collaborators.approve');
            Route::post('collaborators/{collaborator}/reject', [CollaboratorController::class, 'reject'])->whereNumber('collaborator')->middleware('can:reject,collaborator')->name('collaborators.reject');
            Route::post('collaborators/{collaborator}/status', [CollaboratorController::class, 'status'])->whereNumber('collaborator')->middleware('can:changeStatus,collaborator')->name('collaborators.status');
            Route::post('collaborators/{collaborator}/user', [CollaboratorController::class, 'storeUser'])->whereNumber('collaborator')->middleware(['can:update,collaborator', 'can:users.create'])->name('collaborators.user.store');
            // Throttled: a referral code is the public lookup key, and an unthrottled change endpoint is
            // a way to probe which codes are free one request at a time.
            Route::post('collaborators/{collaborator}/referral-code', [CollaboratorController::class, 'referralCode'])->whereNumber('collaborator')->middleware(['can:update,collaborator', 'throttle:10,1'])->name('collaborators.referral-code');
            Route::get('collaborators/{collaborator}/referral-links', [CollaboratorController::class, 'referralLinks'])->whereNumber('collaborator')->middleware('can:view,collaborator')->name('collaborators.referral-links');
            Route::get('collaborators/{collaborator}/activity', [CollaboratorController::class, 'activity'])->whereNumber('collaborator')->middleware('can:viewLogs,collaborator')->name('collaborators.activity');
        });

        /*
        |----------------------------------------------------------------------
        | Referral visits — phase-08-09 §7.3
        |----------------------------------------------------------------------
        |
        | Read-only: there is no store, update or destroy, because nobody edits a
        | click. `report` and `export` sit before `{visit}` for the same reason
        | the collaborator literals do.
        |
        */
        Route::middleware('module:collaborator_referral_visits')->group(static function (): void {
            Route::get('referral-visits', [ReferralVisitController::class, 'index'])->middleware('can:collaborator_referral_visits.view_any')->name('referral-visits.index');
            Route::get('referral-visits/report', [ReferralVisitController::class, 'report'])->middleware('can:collaborator_referral_visits.view_reports')->name('referral-visits.report');
            Route::get('referral-visits/export/{format}', [ReferralVisitController::class, 'export'])->middleware('can:collaborator_referral_visits.view_reports')->name('referral-visits.export');
            Route::get('referral-visits/{visit}', [ReferralVisitController::class, 'show'])->whereNumber('visit')->middleware('can:collaborator_referral_visits.view')->name('referral-visits.show');
        });

        /*
        |----------------------------------------------------------------------
        | Fee receipts — phase-10-12 §7.1
        |----------------------------------------------------------------------
        |
        | **Phase 10 owns the receipt; Phase 18 owns the charge.** The screens
        | that create, edit and cancel a fee charge belong to the institute
        | phase; what lives here is the money — taking it, showing it back, and
        | the two ways it goes out again.
        |
        | There is no `edit` and no `destroy`, and there never will be (INV-8).
        | A mis-keyed receipt is voided and re-entered with a fresh idempotency
        | key: three rows telling the whole story, rather than one row quietly
        | corrected.
        |
        | `export` sits before `{payment}` so the literal wins the match.
        |
        */
        Route::middleware('module:student_fee_payments')->group(static function (): void {
            Route::get('fee-payments', [FeePaymentController::class, 'index'])->middleware('can:student_fee_payments.view_any')->name('fee-payments.index');
            Route::get('fee-payments/export/{format}', [FeePaymentController::class, 'export'])->middleware('can:student_fee_payments.export')->name('fee-payments.export');
            Route::get('fee-payments/{payment}', [FeePaymentController::class, 'show'])->whereNumber('payment')->middleware('can:student_fee_payments.view')->name('fee-payments.show');
            Route::get('fee-payments/{payment}/receipt', [FeePaymentController::class, 'receipt'])->whereNumber('payment')->middleware('can:student_fee_payments.print')->name('fee-payments.receipt');
            // Throttled: one request takes money, and a wedged client retrying is the case the
            // idempotency key already covers — this bounds the cost of it.
            Route::post('student-fees/{fee}/payments', [FeePaymentController::class, 'store'])->whereNumber('fee')->middleware(['can:student_fee_payments.create', 'throttle:20,1'])->name('fee-payments.store');
            // Wizard step 4. Read-only, and it writes nothing — asserted by a test that counts rows.
            Route::get('student-fees/{fee}/payments/preview', [FeePaymentController::class, 'preview'])->whereNumber('fee')->middleware(['can:student_fee_payments.create', 'throttle:60,1'])->name('fee-payments.preview');
            Route::post('fee-payments/{payment}/void', [FeePaymentController::class, 'void'])->whereNumber('payment')->middleware('can:student_fee_payments.change_status')->name('fee-payments.void');
            // A refund is a `payment_reversals` row, so it carries that module's create permission
            // rather than the receipt's.
            Route::post('fee-payments/{payment}/refund', [FeePaymentController::class, 'refund'])->whereNumber('payment')->middleware('can:payment_reversals.create')->name('fee-payments.refund');
        });

        /*
        |----------------------------------------------------------------------
        | phase-18 §7 — the fee document side
        |----------------------------------------------------------------------
        |
        | Phase 10 shipped the money path (the eight `fee-payments.*` routes
        | above); this is what raises, reduces, schedules and prints the charge
        | the money is paid against.
        |
        | **The literal segments sit before `{fee}` so they win the match**, the
        | same reason `fee-payments/export` does. `student-fees/create` and
        | `student-fees/export/csv` would otherwise both be read as a fee id.
        |
        | **A preview writes nothing and says so in its name.** Both of them are
        | GET, both are throttled, and both exist so the wizard can show the
        | arithmetic before anybody commits — a plan whose lines do not sum to
        | the net fee, or a structure that does not match what the student
        | agreed, is better caught on screen than by a 422.
        |
        | There is no `edit`, no `update` and no `destroy` for a charge: an
        | amount that moved after a commission was computed from it would
        | silently change what a partner earned, so a correction is a discount
        | row and a charge with money against it is cancelled, never deleted.
        |
        */
        Route::middleware('module:student_fees')->group(static function (): void {
            Route::get('student-fees', [StudentFeeController::class, 'index'])->middleware('can:student_fees.view_any')->name('student-fees.index');
            Route::get('student-fees/create', [StudentFeeController::class, 'create'])->middleware('can:student_fees.create')->name('student-fees.create');
            Route::post('student-fees', [StudentFeeController::class, 'store'])->middleware('can:student_fees.create')->name('student-fees.store');
            Route::get('student-fees/export/{format}', [StudentFeeController::class, 'export'])->middleware('can:student_fees.export')->name('student-fees.export');
            Route::post('student-fees/generate-monthly', [FeeStructureController::class, 'generateMonthly'])->middleware(['can:student_fees.create', 'throttle:5,1'])->name('student-fees.generate-monthly');
            Route::get('student-fees/{fee}', [StudentFeeController::class, 'show'])->whereNumber('fee')->middleware('can:view,fee')->name('student-fees.show');
            Route::get('student-fees/{fee}/slip', [StudentFeeController::class, 'slip'])->whereNumber('fee')->middleware('can:student_fees.print')->name('student-fees.slip');
            Route::post('student-fees/{fee}/cancel', [StudentFeeController::class, 'cancel'])->whereNumber('fee')->middleware('can:changeStatus,fee')->name('student-fees.cancel');
            Route::post('student-fees/{fee}/reopen', [StudentFeeController::class, 'reopen'])->whereNumber('fee')->middleware('can:changeStatus,fee')->name('student-fees.reopen');

            // The structure wizard (§8.3). `preview` is read-only; `store` is duplicate-proof by
            // INSERT, so the throttle bounds the cost of a wedged client rather than guarding money.
            Route::get('admissions/{admission}/fee-structure/preview', [FeeStructureController::class, 'preview'])->whereNumber('admission')->middleware(['can:student_fees.create', 'throttle:60,1'])->name('fee-structures.preview');
            Route::post('admissions/{admission}/fee-structure', [FeeStructureController::class, 'store'])->whereNumber('admission')->middleware(['can:student_fees.create', 'throttle:10,1'])->name('fee-structures.store');
            Route::get('admissions/{admission}/fee-structure/slip', [FeeStructureController::class, 'slip'])->whereNumber('admission')->middleware('can:student_fees.print')->name('fee-structures.slip');

            // The cashier's worklist. It reads and never writes; collecting goes through the spine's
            // modal, so the one money path stays the one money path.
            Route::get('fee-collection', [FeeCollectionController::class, 'index'])->middleware('can:student_fees.view_reports')->name('fee-collection.index');
        });

        /*
        |----------------------------------------------------------------------
        | phase-18 §7 — plans, discounts and waivers
        |----------------------------------------------------------------------
        |
        | A plan and a discount are separate modules from the charge because
        | they are separate rights: a Course Coordinator may read a fee and must
        | not reduce one.
        |
        | **`installments.waive` carries `change_status` and the policy demands
        | `fee_discounts.approve` on top** (§6.1, spine §2.18.2). A waiver parks
        | an amount on the line *and* writes a discount row that lowers the net
        | fee, so the line permission alone would let somebody give money away
        | one installment at a time.
        |
        | **A discount is reversed, never edited or deleted**, so there is no
        | route for either — `trg_sfd_no_delete` and `FinancialRow` back that at
        | the database and the model, and `reverse` needs `approve` because
        | undoing a discount is an authority question, not a typo question.
        |
        */
        Route::middleware('module:installments')->group(static function (): void {
            Route::get('student-fees/{fee}/installments/preview', [InstallmentPlanController::class, 'preview'])->whereNumber('fee')->middleware(['can:installments.create', 'throttle:60,1'])->name('installments.preview');
            Route::post('student-fees/{fee}/installments', [InstallmentPlanController::class, 'store'])->whereNumber('fee')->middleware('can:installments.create')->name('student-fees.installments.store');
            Route::put('student-fees/{fee}/installments', [InstallmentPlanController::class, 'rebuild'])->whereNumber('fee')->middleware('can:installments.edit')->name('student-fees.installments.rebuild');
            Route::post('installments/{installment}/waive', [InstallmentPlanController::class, 'waive'])->whereNumber('installment')->middleware('can:changeStatus,installment')->name('installments.waive');
        });

        Route::middleware('module:fee_discounts')->group(static function (): void {
            Route::post('student-fees/{fee}/discounts', [FeeDiscountController::class, 'store'])->whereNumber('fee')->middleware('can:fee_discounts.create')->name('student-fees.discounts.store');
            Route::post('fee-discounts/{discount}/reverse', [FeeDiscountController::class, 'reverse'])->whereNumber('discount')->middleware('can:fee_discounts.approve')->name('fee-discounts.reverse');
        });

        /*
        |----------------------------------------------------------------------
        | phase-18 §4.1 — telling a student they owe money
        |----------------------------------------------------------------------
        |
        | Its own module, because chasing and charging are different rights: a
        | Receptionist may send a reminder and must not edit a fee. There is no
        | edit and no destroy — a sent message cannot be un-sent, and the row is
        | the evidence somebody disputing being chased would want (D19).
        |
        */
        Route::middleware('module:fee_reminders')->group(static function (): void {
            Route::get('fee-reminders', [FeeReminderController::class, 'index'])->middleware('can:fee_reminders.view_any')->name('fee-reminders.index');
            Route::post('student-fees/{fee}/reminders', [FeeReminderController::class, 'store'])->whereNumber('fee')->middleware(['can:fee_reminders.create', 'throttle:30,1'])->name('fee-reminders.store');
        });

        /*
        |----------------------------------------------------------------------
        | Project payments — phase-11 §7.2
        |----------------------------------------------------------------------
        |
        | Every route carries `module:project_payments`, **not** the `payments`
        | umbrella (F-6.1): the umbrella belongs to phase-13's cross-source
        | finance register, and switching that off must not take this one with
        | it.
        |
        | No `edit` and no `destroy`, exactly as on the student side (INV-8).
        |
        */
        Route::middleware('module:project_payments')->group(static function (): void {
            Route::get('project-payments', [ProjectPaymentController::class, 'index'])->middleware('can:project_payments.view_any')->name('project-payments.index');
            Route::get('project-payments/export/{format}', [ProjectPaymentController::class, 'export'])->middleware('can:project_payments.export')->name('project-payments.export');
            Route::get('project-payments/{payment}', [ProjectPaymentController::class, 'show'])->whereNumber('payment')->middleware('can:project_payments.view')->name('project-payments.show');
            Route::get('project-payments/{payment}/receipt', [ProjectPaymentController::class, 'receipt'])->whereNumber('payment')->middleware('can:project_payments.print')->name('project-payments.receipt');
            Route::post('projects/{project}/payments', [ProjectPaymentController::class, 'store'])->whereNumber('project')->middleware(['can:project_payments.create', 'throttle:20,1'])->name('project-payments.store');
            Route::post('project-payments/{payment}/void', [ProjectPaymentController::class, 'void'])->whereNumber('payment')->middleware('can:project_payments.change_status')->name('project-payments.void');
            // A refund is a `payment_reversals` row, so it carries that module's create permission
            // rather than the payment's — exactly as on the student side.
            Route::post('project-payments/{payment}/refund', [ProjectPaymentController::class, 'refund'])->whereNumber('payment')->middleware('can:payment_reversals.create')->name('project-payments.refund');
        });

        /*
        |----------------------------------------------------------------------
        | Commission ledger — phase-10-12 §7.4
        |----------------------------------------------------------------------
        |
        | **No `edit` and no `destroy`, ever.** A wrong commission is corrected by
        | a reversing entry that references it (CLAUDE.md rule 3) — the model
        | refuses both writes, and a route that offered them would be a promise
        | the ledger cannot keep.
        |
        | `create` exists on this module for one path only: `adjustments`, the
        | manual adjustment and write-off. It is still an append.
        |
        | The literal segments are declared before `{entry}`, or a request for
        | /admin/commissions/bulk-approve would bind "bulk-approve" as a key.
        |
        */
        Route::middleware('module:collaborator_commissions')->group(static function (): void {
            Route::get('commissions', [CommissionController::class, 'index'])->middleware('can:collaborator_commissions.view_any')->name('commissions.index');
            Route::get('commissions/export/{format}', [CommissionController::class, 'export'])->middleware('can:collaborator_commissions.export')->name('commissions.export');
            // Throttled: a bulk action is one request that moves up to 500 money rows.
            Route::post('commissions/bulk-approve', [CommissionController::class, 'bulkApprove'])->middleware(['can:collaborator_commissions.approve', 'throttle:10,1'])->name('commissions.bulk-approve');
            Route::post('commissions/bulk-reject', [CommissionController::class, 'bulkReject'])->middleware(['can:collaborator_commissions.reject', 'throttle:10,1'])->name('commissions.bulk-reject');
            Route::post('commissions/adjustments', [CommissionController::class, 'storeAdjustment'])->middleware('can:collaborator_commissions.create')->name('commissions.adjustments.store');
            // The audited re-evaluation of §6.6 row 1. `approve` rather than `create`: it undoes a
            // decision somebody made, which is the heavier of the two rights.
            Route::post('commissions/evaluate', [CommissionController::class, 'evaluate'])->middleware(['can:collaborator_commissions.approve', 'throttle:5,1'])->name('commissions.evaluate');
            Route::get('commissions/{entry}', [CommissionController::class, 'show'])->whereNumber('entry')->middleware('can:collaborator_commissions.view')->name('commissions.show');
            Route::post('commissions/{entry}/approve', [CommissionController::class, 'approve'])->whereNumber('entry')->middleware('can:collaborator_commissions.approve')->name('commissions.approve');
            // One route, two transitions: pending/approved is a rejection, available is a
            // cancellation. The service picks the one the ledger allows.
            Route::post('commissions/{entry}/reject', [CommissionController::class, 'reject'])->whereNumber('entry')->middleware('can:collaborator_commissions.reject')->name('commissions.reject');

            // §8.8. `view_reports` rather than `view_any`: a skip report is about the *absence* of
            // money and reads across every partner at once.
            Route::get('commission-skips', [CommissionController::class, 'skips'])->middleware('can:collaborator_commissions.view_reports')->name('commission-skips.index');

            // phase-10-12 sec 8.8. `accept` is the action that moves **no** money: it notes a decision
            // and closes the row. Without it `over_released_amount` could never be cleared and the
            // queue would grow for ever (spine R-6).
            Route::get('commission-discrepancies', [CommissionDiscrepancyController::class, 'index'])->middleware('can:collaborator_commissions.view_any')->name('commission-discrepancies.index');
            Route::post('commission-discrepancies/{entitlement}/accept', [CommissionDiscrepancyController::class, 'accept'])->whereNumber('entitlement')->middleware('can:collaborator_commissions.approve')->name('commission-discrepancies.accept');
        });

        /*
        |----------------------------------------------------------------------
        | Commission rule versions — phase-10-12 §7.3
        |----------------------------------------------------------------------
        |
        | There is no `update` and no `destroy`. A rate change is a new version
        | (INV-17); `close` only ever moves `effective_to` from NULL to a date.
        |
        */
        Route::middleware('module:collaborator_commission_settings')->group(static function (): void {
            Route::get('collaborators/{collaborator}/commission-rules', [CommissionRuleController::class, 'index'])->whereNumber('collaborator')->middleware('can:collaborator_commission_settings.view')->name('commission-rules.index');
            // Read-only, and declared before the store so the literal wins.
            Route::get('collaborators/{collaborator}/commission-rules/preview', [CommissionRuleController::class, 'preview'])->whereNumber('collaborator')->middleware(['can:collaborator_commission_settings.create', 'throttle:60,1'])->name('commission-rules.preview');
            Route::post('collaborators/{collaborator}/commission-rules', [CommissionRuleController::class, 'store'])->whereNumber('collaborator')->middleware('can:collaborator_commission_settings.create')->name('commission-rules.store');
            Route::post('commission-rules/{rule}/close', [CommissionRuleController::class, 'close'])->whereNumber('rule')->middleware('can:collaborator_commission_settings.create')->name('commission-rules.close');
        });

        /*
        |----------------------------------------------------------------------
        | Invoices - phase-13 sec 7.1
        |----------------------------------------------------------------------
        |
        | `delete` exists and is narrowed by the controller to an unissued draft
        | with no payment: an issued invoice is cancelled and keeps its number,
        | because a reused number makes two documents answer to one reference.
        |
        | Emailing is `change_status`, not an invented `send` ability - it IS the
        | act that moves a draft to sent, and the spine set that precedent by
        | mapping "run a reconciliation" onto the same case.
        |
        | The two apply routes carry the **pair** D43 names: `invoices.edit` and
        | `project_payments.link_invoice`. There is no `project_payments.edit` in
        | the registry and this phase does not ask for one.
        |
        */
        Route::middleware('module:invoices')->group(static function (): void {
            Route::get('invoices', [InvoiceController::class, 'index'])->middleware('can:invoices.view_any')->name('invoices.index');
            Route::get('invoices/create', [InvoiceController::class, 'create'])->middleware('can:invoices.create')->name('invoices.create');
            // Computes and writes nothing, so the figure being typed and the figure stored are one number.
            Route::post('invoices/totals/preview', [InvoiceController::class, 'previewTotals'])->middleware(['can:invoices.create', 'throttle:60,1'])->name('invoices.totals.preview');
            Route::get('invoices/export/{format}', [InvoiceController::class, 'export'])->middleware(['can:invoices.export', 'can:invoices.view_financial'])->name('invoices.export');
            Route::post('invoices', [InvoiceController::class, 'store'])->middleware('can:invoices.create')->name('invoices.store');

            Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice')->middleware('can:invoices.view')->name('invoices.show');
            Route::get('invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->whereNumber('invoice')->middleware('can:invoices.edit')->name('invoices.edit');
            Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])->whereNumber('invoice')->middleware('can:invoices.edit')->name('invoices.update');
            Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->whereNumber('invoice')->middleware('can:invoices.delete')->name('invoices.destroy');

            Route::get('invoices/{invoice}/print', [InvoiceController::class, 'print'])->whereNumber('invoice')->middleware(['can:invoices.print', 'can:invoices.view_financial'])->name('invoices.print');

            Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->whereNumber('invoice')->middleware('can:invoices.change_status')->name('invoices.issue');
            Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])->whereNumber('invoice')->middleware(['can:invoices.change_status', 'throttle:10,1'])->name('invoices.send');
            Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->whereNumber('invoice')->middleware('can:invoices.change_status')->name('invoices.cancel');
            Route::post('invoices/{invoice}/replace', [InvoiceController::class, 'replace'])->whereNumber('invoice')->middleware('can:invoices.create')->name('invoices.replace');
            Route::post('invoices/{invoice}/duplicate', [InvoiceController::class, 'duplicate'])->whereNumber('invoice')->middleware('can:invoices.create')->name('invoices.duplicate');
            Route::post('invoices/{invoice}/public-link/rotate', [InvoiceController::class, 'rotatePublicLink'])->whereNumber('invoice')->middleware('can:invoices.change_status')->name('invoices.public-link.rotate');

            Route::post('invoices/{invoice}/payments/{payment}/apply', [InvoiceController::class, 'applyPayment'])->whereNumber('invoice')->whereNumber('payment')->middleware(['can:invoices.edit', 'can:project_payments.link_invoice'])->name('invoices.payments.apply');
            Route::delete('invoices/{invoice}/payments/{payment}/apply', [InvoiceController::class, 'unapplyPayment'])->whereNumber('invoice')->whereNumber('payment')->middleware(['can:invoices.edit', 'can:project_payments.link_invoice'])->name('invoices.payments.unapply');
        });

        /*
        |----------------------------------------------------------------------
        | Expenses - phase-13 sec 7.2
        |----------------------------------------------------------------------
        |
        | Three abilities for three genuinely different jobs: `create` is anybody
        | claiming a cost, `approve` is somebody agreeing it is the company's,
        | and `change_status` is voiding one already agreed. The receipt is
        | streamed by its own route, which re-runs the permission chain (D21).
        |
        */
        Route::middleware('module:expenses')->group(static function (): void {
            Route::get('expenses', [ExpenseController::class, 'index'])->middleware('can:expenses.view_any')->name('expenses.index');
            Route::get('expenses/approvals', [ExpenseController::class, 'approvals'])->middleware('can:expenses.approve')->name('expenses.approvals');
            Route::get('expenses/create', [ExpenseController::class, 'create'])->middleware('can:expenses.create')->name('expenses.create');
            Route::get('expenses/export/{format}', [ExpenseController::class, 'export'])->middleware(['can:expenses.export', 'can:expenses.view_financial'])->name('expenses.export');
            Route::post('expenses', [ExpenseController::class, 'store'])->middleware('can:expenses.create')->name('expenses.store');
            Route::post('expenses/bulk-approve', [ExpenseController::class, 'bulkApprove'])->middleware(['can:expenses.approve', 'throttle:10,1'])->name('expenses.bulk-approve');

            Route::get('expenses/{expense}', [ExpenseController::class, 'show'])->whereNumber('expense')->middleware('can:expenses.view')->name('expenses.show');
            Route::get('expenses/{expense}/edit', [ExpenseController::class, 'edit'])->whereNumber('expense')->middleware('can:expenses.edit')->name('expenses.edit');
            Route::put('expenses/{expense}', [ExpenseController::class, 'update'])->whereNumber('expense')->middleware('can:expenses.edit')->name('expenses.update');
            Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->whereNumber('expense')->middleware('can:expenses.delete')->name('expenses.destroy');
            Route::get('expenses/{expense}/receipt', [ExpenseController::class, 'receipt'])->whereNumber('expense')->middleware('can:expenses.download')->name('expenses.receipt');

            Route::post('expenses/{expense}/approve', [ExpenseController::class, 'approve'])->whereNumber('expense')->middleware('can:expenses.approve')->name('expenses.approve');
            Route::post('expenses/{expense}/reject', [ExpenseController::class, 'reject'])->whereNumber('expense')->middleware('can:expenses.reject')->name('expenses.reject');
            Route::post('expenses/{expense}/void', [ExpenseController::class, 'void'])->whereNumber('expense')->middleware('can:expenses.change_status')->name('expenses.void');
            Route::post('expenses/{expense}/reversals', [ExpenseController::class, 'storeReversal'])->whereNumber('expense')->middleware('can:expenses.change_status')->name('expenses.reversals.store');
        });

        /*
        |----------------------------------------------------------------------
        | Other income - phase-13 sec 7.3
        |----------------------------------------------------------------------
        |
        | Money in that is neither a project payment nor a student fee. Those
        | belong to the spine and fire the commission engine; a row here fires
        | nothing, which is why the category list holds neither kind.
        |
        */
        Route::middleware('module:income')->group(static function (): void {
            Route::get('income', [IncomeController::class, 'index'])->middleware('can:income.view_any')->name('income.index');
            Route::get('income/create', [IncomeController::class, 'create'])->middleware('can:income.create')->name('income.create');
            Route::get('income/export/{format}', [IncomeController::class, 'export'])->middleware(['can:income.export', 'can:income.view_financial'])->name('income.export');
            Route::post('income', [IncomeController::class, 'store'])->middleware('can:income.create')->name('income.store');

            Route::get('income/{income}', [IncomeController::class, 'show'])->whereNumber('income')->middleware('can:income.view')->name('income.show');
            Route::get('income/{income}/edit', [IncomeController::class, 'edit'])->whereNumber('income')->middleware('can:income.edit')->name('income.edit');
            Route::put('income/{income}', [IncomeController::class, 'update'])->whereNumber('income')->middleware('can:income.edit')->name('income.update');
            Route::delete('income/{income}', [IncomeController::class, 'destroy'])->whereNumber('income')->middleware('can:income.delete')->name('income.destroy');
            Route::get('income/{income}/receipt', [IncomeController::class, 'receipt'])->whereNumber('income')->middleware('can:income.download')->name('income.receipt');

            Route::post('income/{income}/void', [IncomeController::class, 'void'])->whereNumber('income')->middleware('can:income.change_status')->name('income.void');
            Route::post('income/{income}/reversals', [IncomeController::class, 'storeReversal'])->whereNumber('income')->middleware('can:income.change_status')->name('income.reversals.store');
        });

        /*
        |----------------------------------------------------------------------
        | Payment methods and finance categories - phase-13 sec 7.4
        |----------------------------------------------------------------------
        |
        | Neither module declares `view_financial`, and neither holds an amount.
        | The encrypted gateway config is reachable through `edit` alone and is
        | rendered nowhere: a screen that could show it would make the
        | encryption decorative.
        |
        */
        Route::middleware('module:payment_methods')->group(static function (): void {
            Route::get('payment-methods', [PaymentMethodController::class, 'index'])->middleware('can:payment_methods.view_any')->name('payment-methods.index');
            Route::get('payment-methods/create', [PaymentMethodController::class, 'create'])->middleware('can:payment_methods.create')->name('payment-methods.create');
            Route::post('payment-methods', [PaymentMethodController::class, 'store'])->middleware('can:payment_methods.create')->name('payment-methods.store');
            Route::get('payment-methods/{method}/edit', [PaymentMethodController::class, 'edit'])->whereNumber('method')->middleware('can:payment_methods.edit')->name('payment-methods.edit');
            Route::put('payment-methods/{method}', [PaymentMethodController::class, 'update'])->whereNumber('method')->middleware('can:payment_methods.edit')->name('payment-methods.update');
            Route::delete('payment-methods/{method}', [PaymentMethodController::class, 'destroy'])->whereNumber('method')->middleware('can:payment_methods.delete')->name('payment-methods.destroy');
            Route::post('payment-methods/{method}/toggle', [PaymentMethodController::class, 'toggle'])->whereNumber('method')->middleware('can:payment_methods.change_status')->name('payment-methods.toggle');
            Route::post('payment-methods/{method}/default', [PaymentMethodController::class, 'setDefault'])->whereNumber('method')->middleware('can:payment_methods.edit')->name('payment-methods.default');
        });

        Route::middleware('module:finance_categories')->group(static function (): void {
            Route::get('finance-categories', [FinanceCategoryController::class, 'index'])->middleware('can:finance_categories.view_any')->name('finance-categories.index');
            Route::post('finance-categories', [FinanceCategoryController::class, 'store'])->middleware('can:finance_categories.create')->name('finance-categories.store');
            Route::post('finance-categories/reorder', [FinanceCategoryController::class, 'reorder'])->middleware('can:finance_categories.edit')->name('finance-categories.reorder');
            Route::put('finance-categories/{category}', [FinanceCategoryController::class, 'update'])->whereNumber('category')->middleware('can:finance_categories.edit')->name('finance-categories.update');
            Route::delete('finance-categories/{category}', [FinanceCategoryController::class, 'destroy'])->whereNumber('category')->middleware('can:finance_categories.delete')->name('finance-categories.destroy');
            Route::post('finance-categories/{category}/toggle', [FinanceCategoryController::class, 'toggle'])->whereNumber('category')->middleware('can:finance_categories.change_status')->name('finance-categories.toggle');
        });

        /*
        |----------------------------------------------------------------------
        | The cross-source payments register - phase-13 sec 7.5
        |----------------------------------------------------------------------
        |
        | Read-only, and gated by its own umbrella module. Every write action on
        | a row deep-links to the register that owns it: the rules about voiding
        | a project payment live with the spine and the rules about refunding a
        | fee receipt live with fees, and a second place to do either would
        | eventually disagree with the first.
        |
        | `view_financial` is required to open it at all, because the register's
        | whole content is amounts - a version of it without them would be a
        | list of reference numbers.
        |
        */
        Route::middleware('module:payments')->group(static function (): void {
            Route::get('payments', [PaymentRegisterController::class, 'index'])->middleware(['can:payments.view_any', 'can:payments.view_financial'])->name('payments.index');
            Route::get('payments/export/{format}', [PaymentRegisterController::class, 'export'])->middleware(['can:payments.export', 'can:payments.view_financial'])->name('payments.export');
        });

        /*
        |----------------------------------------------------------------------
        | Finance reports - phase-13 sec 7.6
        |----------------------------------------------------------------------
        |
        | Double-gated (sec 4.5 rule 4): the hub needs `reports.view_reports`,
        | and each report additionally needs its source module's `view_reports`
        | **and** `view_financial`, resolved from
        | `FinanceReportType::permissions()` so the routes and the hub cards read
        | one definition. The literal `{report}` values are checked by the
        | controller, which 404s an unknown one.
        |
        */
        Route::middleware('module:reports')->group(static function (): void {
            Route::get('reports/finance', [FinanceReportController::class, 'index'])->middleware('can:reports.view_reports')->name('reports.finance.index');
            Route::get('reports/finance/income', [FinanceReportController::class, 'show'])->defaults('report', 'income')->middleware(['can:income.view_reports', 'can:income.view_financial'])->name('reports.finance.income');
            Route::get('reports/finance/expenses', [FinanceReportController::class, 'show'])->defaults('report', 'expenses')->middleware(['can:expenses.view_reports', 'can:expenses.view_financial'])->name('reports.finance.expenses');
            Route::get('reports/finance/profit-loss', [FinanceReportController::class, 'show'])->defaults('report', 'profit-loss')->middleware(['can:income.view_reports', 'can:income.view_financial', 'can:expenses.view_financial'])->name('reports.finance.profit-loss');
            Route::get('reports/finance/receivables-aging', [FinanceReportController::class, 'show'])->defaults('report', 'receivables-aging')->middleware(['can:invoices.view_reports', 'can:invoices.view_financial'])->name('reports.finance.receivables-aging');
            Route::get('reports/finance/{report}/export/{format}', [FinanceReportController::class, 'export'])->middleware(['can:reports.view_reports', 'throttle:20,1'])->name('reports.finance.export');
        });

        /*
        |----------------------------------------------------------------------
        | Course categories and courses - phase-14-17 sec 7.1
        |----------------------------------------------------------------------
        |
        | `courses.view_financial` gates the three fee columns everywhere a
        | course is rendered - the index, the form, the export. The Form Request
        | strips them from the payload too, so hiding the Fees tab is the
        | courtesy and the strip is the control (FT-10).
        |
        | A course is created as a draft and moves only through the sec 2.30.1
        | transition table, which is why publish / unpublish / archive / revive
        | are their own routes rather than a `status` field on the update.
        |
        */
        Route::middleware('module:course_categories')->group(static function (): void {
            Route::get('course-categories', [CourseCategoryController::class, 'index'])->middleware('can:course_categories.view_any')->name('course-categories.index');
            Route::post('course-categories', [CourseCategoryController::class, 'store'])->middleware('can:course_categories.create')->name('course-categories.store');
            Route::post('course-categories/reorder', [CourseCategoryController::class, 'reorder'])->middleware('can:course_categories.edit')->name('course-categories.reorder');
            Route::get('course-categories/{category}/edit', [CourseCategoryController::class, 'edit'])->middleware('can:course_categories.edit')->name('course-categories.edit');
            Route::put('course-categories/{category}', [CourseCategoryController::class, 'update'])->middleware('can:course_categories.edit')->name('course-categories.update');
            Route::post('course-categories/{category}/toggle', [CourseCategoryController::class, 'toggle'])->middleware('can:course_categories.change_status')->name('course-categories.toggle');
            Route::delete('course-categories/{category}', [CourseCategoryController::class, 'destroy'])->middleware('can:course_categories.delete')->name('course-categories.destroy');
        });

        Route::middleware('module:courses')->group(static function (): void {
            Route::get('courses', [CourseController::class, 'index'])->middleware('can:courses.view_any')->name('courses.index');
            Route::get('courses/create', [CourseController::class, 'create'])->middleware('can:courses.create')->name('courses.create');
            Route::post('courses', [CourseController::class, 'store'])->middleware('can:courses.create')->name('courses.store');
            Route::post('courses/reorder', [CourseController::class, 'reorder'])->middleware('can:courses.edit')->name('courses.reorder');
            Route::get('courses/export/{format}', [CourseController::class, 'export'])->middleware('can:courses.export')->name('courses.export');

            Route::get('courses/{course}', [CourseController::class, 'show'])->middleware('can:view,course')->name('courses.show');
            Route::get('courses/{course}/edit', [CourseController::class, 'edit'])->middleware('can:update,course')->name('courses.edit');
            Route::put('courses/{course}', [CourseController::class, 'update'])->middleware('can:update,course')->name('courses.update');
            Route::delete('courses/{course}', [CourseController::class, 'destroy'])->middleware('can:delete,course')->name('courses.destroy');

            Route::post('courses/{course}/publish', [CourseController::class, 'publish'])->middleware('can:changeStatus,course')->name('courses.publish');
            Route::post('courses/{course}/unpublish', [CourseController::class, 'unpublish'])->middleware('can:changeStatus,course')->name('courses.unpublish');
            Route::post('courses/{course}/archive', [CourseController::class, 'archive'])->middleware('can:changeStatus,course')->name('courses.archive');
            Route::post('courses/{course}/revive', [CourseController::class, 'revive'])->middleware('can:changeStatus,course')->name('courses.revive');
            Route::post('courses/{course}/featured', [CourseController::class, 'featured'])->middleware('can:changeStatus,course')->name('courses.featured');
            Route::post('courses/{course}/duplicate', [CourseController::class, 'duplicate'])->middleware('can:duplicate,course')->name('courses.duplicate');
        });

        /*
        |----------------------------------------------------------------------
        | The course outline - phase-14-17 sec 7.2
        |----------------------------------------------------------------------
        |
        | Every parent is a route binding, never a request field: a topic is
        | posted to its module and a lecture to its topic, so the ids the row is
        | written with were never in the body (INV-I12). Reorder and move
        | re-verify ownership in the service - the client's word is never taken.
        |
        | `change_status` is deliberately separate from `edit`: switching a node
        | off is the alternative to deleting one that has been taught from, and
        | somebody trusted to reword a topic is not automatically trusted to
        | take it out of every student's denominator (INV-I13).
        |
        */
        Route::middleware('module:course_outline')->group(static function (): void {
            Route::get('courses/{course}/outline', [CourseOutlineController::class, 'index'])->middleware('can:course_outline.view')->name('course-outline.index');
            Route::post('courses/{course}/outline/reorder', [CourseOutlineController::class, 'reorder'])->middleware('can:course_outline.edit')->name('course-outline.reorder');
            Route::post('courses/{course}/outline/{level}/{node}/toggle', [CourseOutlineController::class, 'toggle'])->whereNumber('node')->middleware('can:course_outline.change_status')->name('course-outline.toggle');

            Route::post('courses/{course}/modules', [CourseOutlineController::class, 'storeModule'])->middleware('can:course_outline.create')->name('course-modules.store');
            Route::put('course-modules/{module}', [CourseOutlineController::class, 'updateModule'])->whereNumber('module')->middleware('can:course_outline.edit')->name('course-modules.update');
            Route::delete('course-modules/{module}', [CourseOutlineController::class, 'destroyModule'])->whereNumber('module')->middleware('can:delete,module')->name('course-modules.destroy');
            Route::post('course-modules/{module}/duplicate', [CourseOutlineController::class, 'duplicateModule'])->whereNumber('module')->middleware('can:course_outline.create')->name('course-modules.duplicate');

            Route::post('course-modules/{module}/topics', [CourseOutlineController::class, 'storeTopic'])->whereNumber('module')->middleware('can:course_outline.create')->name('course-topics.store');
            Route::put('course-topics/{topic}', [CourseOutlineController::class, 'updateTopic'])->whereNumber('topic')->middleware('can:course_outline.edit')->name('course-topics.update');
            Route::delete('course-topics/{topic}', [CourseOutlineController::class, 'destroyTopic'])->whereNumber('topic')->middleware('can:delete,topic')->name('course-topics.destroy');
            Route::post('course-topics/{topic}/move', [CourseOutlineController::class, 'moveTopic'])->whereNumber('topic')->middleware('can:course_outline.edit')->name('course-topics.move');

            Route::post('course-topics/{topic}/lectures', [CourseOutlineController::class, 'storeLecture'])->whereNumber('topic')->middleware('can:course_outline.create')->name('course-lectures.store');
            Route::put('course-lectures/{lecture}', [CourseOutlineController::class, 'updateLecture'])->whereNumber('lecture')->middleware('can:course_outline.edit')->name('course-lectures.update');
            Route::delete('course-lectures/{lecture}', [CourseOutlineController::class, 'destroyLecture'])->whereNumber('lecture')->middleware('can:delete,lecture')->name('course-lectures.destroy');

            Route::post('course-topics/{topic}/resources', [CourseOutlineController::class, 'storeResource'])->whereNumber('topic')->middleware('can:course_outline.upload')->name('course-resources.store');
            Route::get('course-resources/{resource}/download', [CourseOutlineController::class, 'downloadResource'])->whereNumber('resource')->middleware('can:course_outline.download')->name('course-resources.download');
            Route::put('course-resources/{resource}', [CourseOutlineController::class, 'updateResource'])->whereNumber('resource')->middleware('can:course_outline.edit')->name('course-resources.update');
            Route::delete('course-resources/{resource}', [CourseOutlineController::class, 'destroyResource'])->whereNumber('resource')->middleware('can:course_outline.delete')->name('course-resources.destroy');

            Route::post('course-topics/{topic}/assignments', [CourseOutlineController::class, 'storeAssignment'])->whereNumber('topic')->middleware('can:course_outline.create')->name('course-topic-assignments.store');
            Route::put('course-topic-assignments/{blueprint}', [CourseOutlineController::class, 'updateAssignment'])->whereNumber('blueprint')->middleware('can:course_outline.edit')->name('course-topic-assignments.update');
            Route::delete('course-topic-assignments/{blueprint}', [CourseOutlineController::class, 'destroyAssignment'])->whereNumber('blueprint')->middleware('can:course_outline.delete')->name('course-topic-assignments.destroy');
        });

        /*
        |----------------------------------------------------------------------
        | Course inquiries — phase-14-17 §7.3, §8.5
        |----------------------------------------------------------------------
        |
        | The counsellor's queue. `promote` and `convert` carry the permission of
        | the module they cross into rather than this one: promoting writes a
        | `student_applications` row and converting writes a student and an
        | admission, and a permission that opened a door into another module
        | would make that module's boundary decorative.
        |
        | The funnel report is `view_reports`, not `view_any`: the conversion
        | rate is a management number, and working the queue is a job.
        |
        */
        Route::middleware('module:course_inquiries')->group(static function (): void {
            Route::get('course-inquiries', [CourseInquiryController::class, 'index'])->middleware('can:course_inquiries.view_any')->name('course-inquiries.index');
            Route::get('course-inquiries/create', [CourseInquiryController::class, 'create'])->middleware('can:course_inquiries.create')->name('course-inquiries.create');
            Route::post('course-inquiries', [CourseInquiryController::class, 'store'])->middleware('can:course_inquiries.create')->name('course-inquiries.store');
            // Declared before `{inquiry}` so "reports" and "export" are never read as an id.
            Route::get('course-inquiries/reports/funnel', [CourseInquiryController::class, 'funnel'])->middleware('can:course_inquiries.view_reports')->name('course-inquiries.funnel');
            Route::get('course-inquiries/export/{format}', [CourseInquiryController::class, 'export'])->middleware('can:course_inquiries.export')->name('course-inquiries.export');

            Route::get('course-inquiries/{inquiry}', [CourseInquiryController::class, 'show'])->whereNumber('inquiry')->middleware('can:view,inquiry')->name('course-inquiries.show');
            Route::put('course-inquiries/{inquiry}', [CourseInquiryController::class, 'update'])->whereNumber('inquiry')->middleware('can:update,inquiry')->name('course-inquiries.update');
            Route::delete('course-inquiries/{inquiry}', [CourseInquiryController::class, 'destroy'])->whereNumber('inquiry')->middleware('can:delete,inquiry')->name('course-inquiries.destroy');

            Route::post('course-inquiries/{inquiry}/follow-ups', [CourseInquiryController::class, 'storeFollowUp'])->whereNumber('inquiry')->middleware('can:logFollowUp,inquiry')->name('course-inquiries.follow-ups.store');
            Route::post('course-inquiries/{inquiry}/assign', [CourseInquiryController::class, 'assign'])->whereNumber('inquiry')->middleware('can:assign,inquiry')->name('course-inquiries.assign');
            Route::post('course-inquiries/{inquiry}/status', [CourseInquiryController::class, 'status'])->whereNumber('inquiry')->middleware('can:changeStatus,inquiry')->name('course-inquiries.status');
            Route::post('course-inquiries/{inquiry}/promote', [CourseInquiryController::class, 'promote'])->whereNumber('inquiry')->middleware('can:promote,inquiry')->name('course-inquiries.promote');
            Route::post('course-inquiries/{inquiry}/convert', [CourseInquiryController::class, 'convert'])->whereNumber('inquiry')->middleware('can:convert,inquiry')->name('course-inquiries.convert');
        });

        /*
        |----------------------------------------------------------------------
        | The §67 application inbox — phase-14-17 §7.3, §8.6
        |----------------------------------------------------------------------
        |
        | Its own module (§4.1) so a receptionist can triage without holding
        | `students.create`. There is no destroy route and there never will be:
        | the module declares no `delete` ability, because a public submission is
        | evidence somebody asked and is rejected or marked duplicate, not
        | removed.
        |
        */
        Route::middleware('module:student_applications')->group(static function (): void {
            Route::get('student-applications', [StudentApplicationController::class, 'index'])->middleware('can:student_applications.view_any')->name('student-applications.index');
            Route::get('student-applications/export/{format}', [StudentApplicationController::class, 'export'])->middleware('can:student_applications.export')->name('student-applications.export');
            Route::get('student-applications/{application}', [StudentApplicationController::class, 'show'])->whereNumber('application')->middleware('can:view,application')->name('student-applications.show');

            Route::post('student-applications/{application}/claim', [StudentApplicationController::class, 'claim'])->whereNumber('application')->middleware('can:claim,application')->name('student-applications.claim');
            Route::post('student-applications/{application}/duplicate', [StudentApplicationController::class, 'duplicate'])->whereNumber('application')->middleware('can:markDuplicate,application')->name('student-applications.duplicate');
            Route::post('student-applications/{application}/reject', [StudentApplicationController::class, 'reject'])->whereNumber('application')->middleware('can:reject,application')->name('student-applications.reject');
            Route::post('student-applications/{application}/withdraw', [StudentApplicationController::class, 'withdraw'])->whereNumber('application')->middleware('can:withdraw,application')->name('student-applications.withdraw');
            Route::post('student-applications/{application}/convert', [StudentApplicationController::class, 'convert'])->whereNumber('application')->middleware('can:convert,application')->name('student-applications.convert');
        });

        /*
        |----------------------------------------------------------------------
        | Demo classes — phase-14-17 §7.3, §8.9
        |----------------------------------------------------------------------
        |
        | A demo holds a teacher and a room for an hour, so booking one is a
        | scheduling act and the two `active_guard` unique indexes are what stop
        | two people arriving at one door. `slip` is `print`, which is a
        | different right from booking.
        |
        */
        Route::middleware('module:demo_classes')->group(static function (): void {
            Route::get('demo-classes', [DemoClassController::class, 'index'])->middleware('can:demo_classes.view_any')->name('demo-classes.index');
            Route::get('demo-classes/calendar', [DemoClassController::class, 'calendar'])->middleware('can:demo_classes.view_any')->name('demo-classes.calendar');
            Route::post('demo-classes', [DemoClassController::class, 'store'])->middleware('can:demo_classes.create')->name('demo-classes.store');

            Route::get('demo-classes/{demo}/slip', [DemoClassController::class, 'slip'])->whereNumber('demo')->middleware('can:print,demo')->name('demo-classes.slip');
            Route::put('demo-classes/{demo}', [DemoClassController::class, 'update'])->whereNumber('demo')->middleware('can:update,demo')->name('demo-classes.update');
            Route::post('demo-classes/{demo}/reschedule', [DemoClassController::class, 'reschedule'])->whereNumber('demo')->middleware('can:reschedule,demo')->name('demo-classes.reschedule');
            Route::post('demo-classes/{demo}/status', [DemoClassController::class, 'status'])->whereNumber('demo')->middleware('can:changeStatus,demo')->name('demo-classes.status');
            Route::post('demo-classes/{demo}/convert', [DemoClassController::class, 'convert'])->whereNumber('demo')->middleware('can:convert,demo')->name('demo-classes.convert');
        });

        /*
        |----------------------------------------------------------------------
        | Students — phase-14-17 §7.4, §8.7
        |----------------------------------------------------------------------
        |
        | A student with fees, receipts, enrolments or attendance is never
        | deletable: four foreign keys refuse it, and the policy refuses first so
        | the screen can explain. `merge` carries `delete` rather than `edit`,
        | because one of the two records stops existing.
        |
        */
        Route::middleware('module:students')->group(static function (): void {
            Route::get('students', [StudentController::class, 'index'])->middleware('can:students.view_any')->name('students.index');
            Route::get('students/create', [StudentController::class, 'create'])->middleware('can:students.create')->name('students.create');
            Route::post('students', [StudentController::class, 'store'])->middleware('can:students.create')->name('students.store');
            Route::get('students/export/{format}', [StudentController::class, 'export'])->middleware('can:students.export')->name('students.export');
            Route::post('students/import', [StudentController::class, 'import'])->middleware('can:students.import')->name('students.import');

            Route::get('students/{student}', [StudentController::class, 'show'])->whereNumber('student')->middleware('can:view,student')->name('students.show');
            Route::get('students/{student}/edit', [StudentController::class, 'edit'])->whereNumber('student')->middleware('can:update,student')->name('students.edit');
            Route::put('students/{student}', [StudentController::class, 'update'])->whereNumber('student')->middleware('can:update,student')->name('students.update');
            Route::delete('students/{student}', [StudentController::class, 'destroy'])->whereNumber('student')->middleware('can:delete,student')->name('students.destroy');

            Route::post('students/{student}/status', [StudentController::class, 'status'])->whereNumber('student')->middleware('can:changeStatus,student')->name('students.status');
            Route::post('students/{student}/login', [StudentController::class, 'createLogin'])->whereNumber('student')->middleware('can:createLogin,student')->name('students.login.store');
            Route::post('students/{student}/merge', [StudentController::class, 'merge'])->whereNumber('student')->middleware('can:merge,student')->name('students.merge');
        });

        /*
        |----------------------------------------------------------------------
        | Admissions — phase-14-17 §7.4, §8.8
        |----------------------------------------------------------------------
        |
        | §68's pipeline as a stepper. Each step carries the permission of what
        | it actually does: `fees` is `student_fees.create` because Phase 18
        | writes the charge, and `batch` / `transfer` are `batches.assign`
        | because Phase 16 owns the seat. This phase holds the stage column and
        | delegates both.
        |
        | `figures` is `admissions.edit` and is refused by the policy once the
        | figures are locked — a correction after the first charge is a fee
        | adjustment, which leaves its own row (INV-I2).
        |
        */
        Route::middleware('module:admissions')->group(static function (): void {
            Route::get('admissions', [AdmissionController::class, 'index'])->middleware('can:admissions.view_any')->name('admissions.index');
            Route::get('admissions/create', [AdmissionController::class, 'create'])->middleware('can:admissions.create')->name('admissions.create');
            Route::post('admissions', [AdmissionController::class, 'store'])->middleware('can:admissions.create')->name('admissions.store');
            Route::get('admissions/export/{format}', [AdmissionController::class, 'export'])->middleware('can:admissions.export')->name('admissions.export');

            Route::get('admissions/{admission}', [AdmissionController::class, 'show'])->whereNumber('admission')->middleware('can:view,admission')->name('admissions.show');
            Route::get('admissions/{admission}/print', [AdmissionController::class, 'print'])->whereNumber('admission')->middleware('can:print,admission')->name('admissions.print');
            Route::put('admissions/{admission}/figures', [AdmissionController::class, 'figures'])->whereNumber('admission')->middleware('can:updateFigures,admission')->name('admissions.figures');

            Route::post('admissions/{admission}/register', [AdmissionController::class, 'register'])->whereNumber('admission')->middleware('can:changeStatus,admission')->name('admissions.register');
            Route::post('admissions/{admission}/fees', [AdmissionController::class, 'fees'])->whereNumber('admission')->middleware('can:student_fees.create')->name('admissions.fees');
            Route::post('admissions/{admission}/batch', [AdmissionController::class, 'batch'])->whereNumber('admission')->middleware('can:batches.assign')->name('admissions.batch');
            Route::post('admissions/{admission}/activate', [AdmissionController::class, 'activate'])->whereNumber('admission')->middleware('can:changeStatus,admission')->name('admissions.activate');
            Route::post('admissions/{admission}/complete', [AdmissionController::class, 'complete'])->whereNumber('admission')->middleware('can:changeStatus,admission')->name('admissions.complete');
            Route::post('admissions/{admission}/cancel', [AdmissionController::class, 'cancel'])->whereNumber('admission')->middleware('can:cancel,admission')->name('admissions.cancel');
            Route::post('admissions/{admission}/withdraw', [AdmissionController::class, 'withdraw'])->whereNumber('admission')->middleware('can:withdraw,admission')->name('admissions.withdraw');
            Route::post('admissions/{admission}/transfer', [AdmissionController::class, 'transfer'])->whereNumber('admission')->middleware('can:batches.assign')->name('admissions.transfer');
        });

        /*
        |----------------------------------------------------------------------
        | Teachers — phase-14-17 §7.5, §8.10
        |----------------------------------------------------------------------
        |
        | `workload` is `view_reports` rather than `view`: a teacher's week, the
        | classes they held and whether they filled in the register is a report
        | about a person, and reading a profile is not the same right.
        |
        | The employee link is `edit` on both sides. It copies identity and
        | salary across and locks the fields, so it is an edit of the teacher —
        | not an `assign`, which here means attaching courses.
        |
        */
        Route::middleware('module:teachers')->group(static function (): void {
            Route::get('teachers', [TeacherController::class, 'index'])->middleware('can:teachers.view_any')->name('teachers.index');
            Route::get('teachers/create', [TeacherController::class, 'create'])->middleware('can:teachers.create')->name('teachers.create');
            Route::post('teachers', [TeacherController::class, 'store'])->middleware('can:teachers.create')->name('teachers.store');
            Route::get('teachers/export/{format}', [TeacherController::class, 'export'])->middleware('can:teachers.export')->name('teachers.export');

            Route::get('teachers/{teacher}', [TeacherController::class, 'show'])->whereNumber('teacher')->middleware('can:view,teacher')->name('teachers.show');
            Route::get('teachers/{teacher}/edit', [TeacherController::class, 'edit'])->whereNumber('teacher')->middleware('can:update,teacher')->name('teachers.edit');
            Route::put('teachers/{teacher}', [TeacherController::class, 'update'])->whereNumber('teacher')->middleware('can:update,teacher')->name('teachers.update');
            Route::delete('teachers/{teacher}', [TeacherController::class, 'destroy'])->whereNumber('teacher')->middleware('can:delete,teacher')->name('teachers.destroy');

            Route::get('teachers/{teacher}/workload', [TeacherController::class, 'workload'])->whereNumber('teacher')->middleware('can:teachers.view_reports')->name('teachers.workload');
            Route::post('teachers/{teacher}/courses', [TeacherController::class, 'courses'])->whereNumber('teacher')->middleware('can:assign,teacher')->name('teachers.courses');
            Route::post('teachers/{teacher}/status', [TeacherController::class, 'status'])->whereNumber('teacher')->middleware('can:changeStatus,teacher')->name('teachers.status');
            Route::post('teachers/{teacher}/login', [TeacherController::class, 'createLogin'])->whereNumber('teacher')->middleware('can:update,teacher')->name('teachers.login.store');
            Route::post('teachers/{teacher}/employee-link', [TeacherController::class, 'linkEmployee'])->whereNumber('teacher')->middleware('can:update,teacher')->name('teachers.employee-link.store');
            Route::delete('teachers/{teacher}/employee-link', [TeacherController::class, 'unlinkEmployee'])->whereNumber('teacher')->middleware('can:update,teacher')->name('teachers.employee-link.destroy');
        });

        /*
        |----------------------------------------------------------------------
        | Classrooms — phase-14-17 §7.5, §8.10
        |----------------------------------------------------------------------
        |
        | Its own module (§4.1) so a branch administrator can be given the rooms
        | without the batches that fill them. Everything is done from the index:
        | a room has six fields and does not need a page of its own.
        |
        */
        Route::middleware('module:classrooms')->group(static function (): void {
            Route::get('classrooms', [ClassroomController::class, 'index'])->middleware('can:classrooms.view_any')->name('classrooms.index');
            Route::post('classrooms', [ClassroomController::class, 'store'])->middleware('can:classrooms.create')->name('classrooms.store');
            Route::put('classrooms/{classroom}', [ClassroomController::class, 'update'])->whereNumber('classroom')->middleware('can:update,classroom')->name('classrooms.update');
            Route::post('classrooms/{classroom}/toggle', [ClassroomController::class, 'toggle'])->whereNumber('classroom')->middleware('can:changeStatus,classroom')->name('classrooms.toggle');
            Route::delete('classrooms/{classroom}', [ClassroomController::class, 'destroy'])->whereNumber('classroom')->middleware('can:delete,classroom')->name('classrooms.destroy');
        });

        /*
        |----------------------------------------------------------------------
        | Batches and enrolment — phase-14-17 §7.5, §8.11, §8.12
        |----------------------------------------------------------------------
        |
        | **`batches.assign` is the enrolment ability** (§4.2). Seating a student
        | and transferring one both carry it; changing an enrolment's *status* is
        | `students.change_status`, because dropping or suspending somebody is a
        | decision about the student rather than about the batch.
        |
        | There is no `enrollments.destroy`: a seat that was a mistake is
        | `cancelled`, and one that ended is `dropped`. Both keep the register.
        |
        */
        Route::middleware('module:batches')->group(static function (): void {
            Route::get('batches', [BatchController::class, 'index'])->middleware('can:batches.view_any')->name('batches.index');
            Route::get('batches/create', [BatchController::class, 'create'])->middleware('can:batches.create')->name('batches.create');
            Route::post('batches', [BatchController::class, 'store'])->middleware('can:batches.create')->name('batches.store');
            Route::get('batches/export/{format}', [BatchController::class, 'export'])->middleware('can:batches.export')->name('batches.export');

            Route::get('batches/{batch}', [BatchController::class, 'show'])->whereNumber('batch')->middleware('can:view,batch')->name('batches.show');
            Route::get('batches/{batch}/edit', [BatchController::class, 'edit'])->whereNumber('batch')->middleware('can:update,batch')->name('batches.edit');
            Route::put('batches/{batch}', [BatchController::class, 'update'])->whereNumber('batch')->middleware('can:update,batch')->name('batches.update');
            Route::delete('batches/{batch}', [BatchController::class, 'destroy'])->whereNumber('batch')->middleware('can:delete,batch')->name('batches.destroy');

            Route::post('batches/{batch}/status', [BatchController::class, 'status'])->whereNumber('batch')->middleware('can:changeStatus,batch')->name('batches.status');
            Route::get('batches/{batch}/roster', [BatchController::class, 'roster'])->whereNumber('batch')->middleware('can:view,batch')->name('batches.roster');
            Route::get('batches/{batch}/print-roster', [BatchController::class, 'printRoster'])->whereNumber('batch')->middleware('can:print,batch')->name('batches.print-roster');
            Route::post('batches/{batch}/recount', [BatchController::class, 'recount'])->whereNumber('batch')->middleware('can:update,batch')->name('batches.recount');

            Route::post('batches/{batch}/enrollments', [EnrollmentController::class, 'store'])->whereNumber('batch')->middleware('can:assign,batch')->name('batches.enrollments.store');
            Route::post('enrollments/{enrollment}/transfer', [EnrollmentController::class, 'transfer'])->whereNumber('enrollment')->middleware('can:batches.assign')->name('enrollments.transfer');
            Route::post('enrollments/{enrollment}/status', [EnrollmentController::class, 'status'])->whereNumber('enrollment')->middleware('can:students.change_status')->name('enrollments.status');
        });

        /*
        |----------------------------------------------------------------------
        | Timetable and classes — phase-14-17 §7.6, §8.13, §8.14
        |----------------------------------------------------------------------
        |
        | `check-clash` is a **GET**: it writes nothing, it is what the form calls
        | as somebody picks an hour, and making it a POST would mean a CSRF token
        | on a question. It carries `timetable.create` because knowing who is free
        | is knowing the timetable.
        |
        | The literal segments (`print`, `export`, `generate`) are declared before
        | the `{entry}` and `{session}` routes so a numeric constraint is never the
        | thing deciding which one matched.
        |
        | All four session moves are `timetable.change_status` (§4.2): each one
        | tells the roster something, and being allowed to build a timetable is not
        | the same as being allowed to call a class off the night before.
        |
        */
        Route::middleware('module:timetable')->group(static function (): void {
            Route::get('timetable/check-clash', [TimetableController::class, 'checkClash'])->middleware(['can:timetable.create', 'throttle:120,1'])->name('timetable.check-clash');
            Route::get('timetable/print/{view}', [TimetableController::class, 'print'])->middleware('can:timetable.print')->name('timetable.print');
            Route::get('timetable/export/{format}', [TimetableController::class, 'export'])->middleware('can:timetable.export')->name('timetable.export');
            Route::get('timetable/{view?}', [TimetableController::class, 'index'])->where('view', 'daily|weekly|teacher|batch|classroom')->middleware('can:timetable.view_any')->name('timetable.index');

            Route::post('timetable', [TimetableController::class, 'store'])->middleware('can:timetable.create')->name('timetable.store');
            Route::post('batches/{batch}/timetable/seed', [TimetableController::class, 'seed'])->whereNumber('batch')->middleware('can:timetable.create')->name('timetable.seed');
            Route::put('timetable/{entry}', [TimetableController::class, 'update'])->whereNumber('entry')->middleware('can:update,entry')->name('timetable.update');
            Route::post('timetable/{entry}/end', [TimetableController::class, 'end'])->whereNumber('entry')->middleware('can:changeStatus,entry')->name('timetable.end');
            Route::delete('timetable/{entry}', [TimetableController::class, 'destroy'])->whereNumber('entry')->middleware('can:delete,entry')->name('timetable.destroy');

            Route::get('class-sessions', [ClassSessionController::class, 'index'])->middleware('can:timetable.view_any')->name('class-sessions.index');
            Route::post('class-sessions', [ClassSessionController::class, 'store'])->middleware('can:timetable.create')->name('class-sessions.store');
            Route::post('class-sessions/generate', [ClassSessionController::class, 'generate'])->middleware('can:timetable.create')->name('class-sessions.generate');
            Route::get('class-sessions/{session}', [ClassSessionController::class, 'show'])->whereNumber('session')->middleware('can:view,session')->name('class-sessions.show');
            Route::post('class-sessions/{session}/cancel', [ClassSessionController::class, 'cancel'])->whereNumber('session')->middleware('can:changeStatus,session')->name('class-sessions.cancel');
            Route::post('class-sessions/{session}/reschedule', [ClassSessionController::class, 'reschedule'])->whereNumber('session')->middleware('can:changeStatus,session')->name('class-sessions.reschedule');
            Route::post('class-sessions/{session}/substitute', [ClassSessionController::class, 'substitute'])->whereNumber('session')->middleware('can:changeStatus,session')->name('class-sessions.substitute');
            Route::post('class-sessions/{session}/held', [ClassSessionController::class, 'held'])->whereNumber('session')->middleware('can:changeStatus,session')->name('class-sessions.held');
        });

        /*
        |----------------------------------------------------------------------
        | Attendance — phase-14-17 §7.7, §8.15, §8.16
        |----------------------------------------------------------------------
        |
        | **`edit` is the amendment right, and it is not `create`.** Taking a
        | register at the classroom door is `student_attendance.create`; revising
        | one after `institute.attendance_lock_hours` needs `edit` **and** a
        | reason, and the old value is kept either way (INV-I10). There is no
        | destroy route and there never will be: attendance is corrected, never
        | removed, and the policy answers false to `delete` for everybody.
        |
        | The literal segments (`reports`, `print`, `export`, `import`) are
        | declared before `{attendance}` so a numeric constraint is never the
        | thing deciding which route matched.
        |
        */
        Route::middleware('module:student_attendance')->group(static function (): void {
            Route::get('student-attendance', [StudentAttendanceController::class, 'index'])->middleware('can:student_attendance.view_any')->name('student-attendance.index');

            Route::get('student-attendance/reports/daily', [StudentAttendanceReportController::class, 'daily'])->middleware('can:student_attendance.view_reports')->name('student-attendance.reports.daily');
            Route::get('student-attendance/reports/monthly', [StudentAttendanceReportController::class, 'monthly'])->middleware('can:student_attendance.view_reports')->name('student-attendance.reports.monthly');
            Route::get('student-attendance/reports/percentage', [StudentAttendanceReportController::class, 'percentage'])->middleware('can:student_attendance.view_reports')->name('student-attendance.reports.percentage');
            Route::get('student-attendance/reports/batch', [StudentAttendanceReportController::class, 'batchSummary'])->middleware('can:student_attendance.view_reports')->name('student-attendance.reports.batch');
            Route::get('student-attendance/print/{report}', [StudentAttendanceReportController::class, 'print'])->where('report', 'daily|monthly|percentage|batch')->middleware('can:student_attendance.print')->name('student-attendance.print');
            Route::get('student-attendance/export/{report}/{format}', [StudentAttendanceReportController::class, 'export'])->where('report', 'daily|monthly|percentage|batch')->middleware('can:student_attendance.export')->name('student-attendance.export');
            Route::post('student-attendance/import', [StudentAttendanceController::class, 'import'])->middleware('can:student_attendance.import')->name('student-attendance.import');

            Route::get('student-attendance/sessions/{session}', [StudentAttendanceController::class, 'mark'])->whereNumber('session')->middleware('can:student_attendance.create')->name('student-attendance.mark');
            Route::post('student-attendance/sessions/{session}', [StudentAttendanceController::class, 'store'])->whereNumber('session')->middleware(['can:student_attendance.create', 'throttle:30,1'])->name('student-attendance.store');
            Route::post('student-attendance/sessions/{session}/bulk', [StudentAttendanceController::class, 'bulk'])->whereNumber('session')->middleware('can:student_attendance.create')->name('student-attendance.bulk');

            Route::put('student-attendance/{attendance}', [StudentAttendanceController::class, 'update'])->whereNumber('attendance')->middleware('can:update,attendance')->name('student-attendance.update');
        });

        /*
        |----------------------------------------------------------------------
        | Progress — phase-14-17 §7.7, §8.17
        |----------------------------------------------------------------------
        |
        | **`create` is the class-level mark and `edit` is the individual one.**
        | Marking a topic covered for a batch is a teaching act; overriding it for
        | one student is a judgement about that student, and the two are grantable
        | apart so a visiting trainer can do the first without the second.
        |
        | `skip` is `change_status`, because dropping a topic takes its weight out
        | of every denominator and therefore RAISES everybody's percentage — a
        | change to a published number, which takes a reason.
        |
        | There is no destroy route: every row here is derived and re-derivable by
        | `progress:recompute`, so deleting one would destroy nothing and repair
        | nothing (§4.2 declares no `delete` on the module at all).
        |
        */
        Route::middleware('module:student_progress')->group(static function (): void {
            Route::get('student-progress', [StudentProgressController::class, 'index'])->middleware('can:student_progress.view_any')->name('student-progress.index');
            Route::get('student-progress/export/{format}', [StudentProgressController::class, 'export'])->middleware('can:student_progress.export')->name('student-progress.export');

            Route::get('batches/{batch}/progress', [StudentProgressController::class, 'batch'])->whereNumber('batch')->middleware('can:student_progress.view')->name('student-progress.batch');
            Route::post('batches/{batch}/progress/topics/{topic}', [StudentProgressController::class, 'markForBatch'])->whereNumber('batch')->whereNumber('topic')->middleware('can:student_progress.create')->name('student-progress.batch.topic');
            Route::post('batches/{batch}/progress/topics/{topic}/skip', [StudentProgressController::class, 'skip'])->whereNumber('batch')->whereNumber('topic')->middleware('can:student_progress.change_status')->name('student-progress.batch.skip');

            Route::get('enrollments/{enrollment}/progress', [StudentProgressController::class, 'student'])->whereNumber('enrollment')->middleware('can:student_progress.view')->name('student-progress.student');
            Route::post('enrollments/{enrollment}/progress/topics/{topic}', [StudentProgressController::class, 'markForStudent'])->whereNumber('enrollment')->whereNumber('topic')->middleware('can:student_progress.edit')->name('student-progress.student.topic');
            Route::post('enrollments/{enrollment}/progress/recompute', [StudentProgressController::class, 'recompute'])->whereNumber('enrollment')->middleware('can:student_progress.edit')->name('student-progress.recompute');
        });

        /*
        |----------------------------------------------------------------------
        | Partner wallets — phase-10-12 §7.4, §8.5
        |----------------------------------------------------------------------
        |
        | A wallet is a cache of the ledger, so there is no create, no edit and
        | no destroy: the only two writes are "recompute it from the ledger" and
        | "stop paying this partner for now", and each carries the permission
        | that matches what it actually does. Recalculate is
        | `wallet_reconciliation.change_status` because it *is* a repair; freeze
        | is `collaborator_payouts.change_status` because it stops payouts and
        | touches no figure at all.
        |
        */
        Route::middleware('module:collaborator_wallets')->group(static function (): void {
            Route::get('collaborator-wallets', [WalletController::class, 'index'])->middleware('can:collaborator_wallets.view_any')->name('wallets.index');
            // `withTrashed`: a partner can be soft-deleted while money still references them (spine §6.6 row 2).
            // The debt does not disappear when the relationship does, so the wallet stays reachable.
            Route::get('collaborator-wallets/{collaborator}', [WalletController::class, 'show'])->whereNumber('collaborator')->withTrashed()->middleware('can:collaborator_wallets.view')->name('wallets.show');
            Route::post('collaborator-wallets/{collaborator}/recalculate', [WalletController::class, 'recalculate'])->whereNumber('collaborator')->withTrashed()->middleware('can:wallet_reconciliation.change_status')->name('wallets.recalculate');
            Route::post('collaborator-wallets/{collaborator}/freeze', [WalletController::class, 'freeze'])->whereNumber('collaborator')->withTrashed()->middleware('can:collaborator_payouts.change_status')->name('wallets.freeze');
        });

        /*
        |----------------------------------------------------------------------
        | Reconciliation history — phase-10-12 §7.4, §8.9
        |----------------------------------------------------------------------
        |
        | `run` is throttled hard: eight checks per partner over the whole table
        | is the most expensive read in this module, and the button is one
        | somebody presses again when the page feels slow.
        |
        */
        Route::middleware('module:wallet_reconciliation')->group(static function (): void {
            Route::get('wallet-reconciliations', [WalletReconciliationController::class, 'index'])->middleware('can:wallet_reconciliation.view_any')->name('wallet-reconciliations.index');
            Route::post('wallet-reconciliations/run', [WalletReconciliationController::class, 'run'])->middleware(['can:wallet_reconciliation.change_status', 'throttle:3,1'])->name('wallet-reconciliations.run');
            Route::get('wallet-reconciliations/{reconciliation}', [WalletReconciliationController::class, 'show'])->whereNumber('reconciliation')->middleware('can:wallet_reconciliation.view')->name('wallet-reconciliations.show');
        });

        /*
        |----------------------------------------------------------------------
        | Payouts - phase-10-12 sec 7.4, 8.6
        |----------------------------------------------------------------------
        |
        | There is no `destroy`: sec 120.9 needs payout history to survive, and
        | withdrawing one is a **status** that releases its allocations. The
        | literal paths (`create`, `plan`, `export`) are declared before
        | `{payout}` so the numeric constraint is never the thing deciding.
        |
        | `cancel-after-payment` carries two permissions because it is the only
        | backward money transition in the design (spine R-7): settled money
        | re-entering a spendable balance.
        |
        */
        Route::middleware('module:collaborator_payouts')->group(static function (): void {
            Route::get('payouts', [PayoutController::class, 'index'])->middleware('can:collaborator_payouts.view_any')->name('payouts.index');
            Route::get('payouts/create', [PayoutController::class, 'create'])->middleware('can:collaborator_payouts.create')->name('payouts.create');
            // Read-only: it claims nothing and writes nothing, so it is deliberately a GET.
            Route::get('payouts/plan', [PayoutController::class, 'plan'])->middleware(['can:collaborator_payouts.create', 'throttle:60,1'])->name('payouts.plan');
            Route::get('payouts/export/{format}', [PayoutController::class, 'export'])->middleware('can:collaborator_payouts.export')->name('payouts.export');
            Route::post('payouts', [PayoutController::class, 'store'])->middleware('can:collaborator_payouts.create')->name('payouts.store');
            Route::get('payouts/{payout}', [PayoutController::class, 'show'])->whereNumber('payout')->middleware('can:collaborator_payouts.view')->name('payouts.show');
            Route::get('payouts/{payout}/voucher', [PayoutController::class, 'voucher'])->whereNumber('payout')->middleware('can:collaborator_payouts.print')->name('payouts.voucher');
            Route::post('payouts/{payout}/approve', [PayoutController::class, 'approve'])->whereNumber('payout')->middleware('can:collaborator_payouts.approve')->name('payouts.approve');
            Route::post('payouts/{payout}/reject', [PayoutController::class, 'reject'])->whereNumber('payout')->middleware('can:collaborator_payouts.reject')->name('payouts.reject');
            Route::post('payouts/{payout}/mark-paid', [PayoutController::class, 'markPaid'])->whereNumber('payout')->middleware('can:collaborator_payouts.change_status')->name('payouts.mark-paid');
            Route::post('payouts/{payout}/cancel', [PayoutController::class, 'cancel'])->whereNumber('payout')->middleware('can:collaborator_payouts.change_status')->name('payouts.cancel');
            Route::post('payouts/{payout}/cancel-after-payment', [PayoutController::class, 'cancelAfterPayment'])->whereNumber('payout')->middleware(['can:collaborator_payouts.change_status', 'can:collaborator_payouts.approve'])->name('payouts.cancel-after-payment');
        });

        /*
        |----------------------------------------------------------------------
        | Payout destinations - phase-10-12 sec 7.4, spine sec 2.15
        |----------------------------------------------------------------------
        |
        | Masked display only. No ability anywhere decrypts `details_encrypted`
        | (INV-C6), which is why the module declares no `view_financial`: there
        | is nothing here to unmask.
        |
        */
        Route::middleware('module:collaborator_payout_accounts')->group(static function (): void {
            Route::get('collaborators/{collaborator}/payout-accounts', [PayoutAccountController::class, 'index'])->whereNumber('collaborator')->middleware('can:collaborator_payouts.view')->name('payout-accounts.index');
            Route::post('payout-accounts/{account}/verify', [PayoutAccountController::class, 'verify'])->whereNumber('account')->middleware('can:collaborator_payouts.approve')->name('payout-accounts.verify');
        });

        /*
        |----------------------------------------------------------------------
        | Statements - phase-10-12 sec 7.4, 8.7
        |----------------------------------------------------------------------
        |
        | One builder, four presenters ([D-IMP-7]). `view_financial` gates the
        | screen and `export` gates the three files, because taking a partner's
        | balances out of the system is a different act from looking at them.
        |
        */
        Route::middleware('module:collaborator_commissions')->group(static function (): void {
            Route::get('collaborators/{collaborator}/statement', [StatementController::class, 'show'])->whereNumber('collaborator')->withTrashed()->middleware('can:collaborator_commissions.view_financial')->name('statements.show');
            Route::get('collaborators/{collaborator}/statement/export/{format}', [StatementController::class, 'export'])->whereNumber('collaborator')->withTrashed()->middleware('can:collaborator_commissions.export')->name('statements.export');
        });

        /*
        |----------------------------------------------------------------------
        | Course materials - phase-19-23 sec 7.1
        |----------------------------------------------------------------------
        |
        | `assign` is the targeting ability and is deliberately separate from
        | `edit`: deciding who receives a file is a different act from correcting
        | its title. `download` is separate again, because reading the library is
        | not the same as taking the bytes - a reviewer auditing what was shared
        | needs the list and has no business with the files.
        |
        | Every download goes through MaterialAccessService, which logs before it
        | streams (INV-19-4) and serves through SecureFileService's hardened
        | headers. No route here reaches a file any other way.
        |
        */
        Route::middleware('module:course_materials')->group(static function (): void {
            Route::get('course-materials', [CourseMaterialController::class, 'index'])->middleware('can:course_materials.view_any')->name('course-materials.index');
            Route::get('course-materials/create', [CourseMaterialController::class, 'create'])->middleware('can:course_materials.create')->name('course-materials.create');
            Route::post('course-materials', [CourseMaterialController::class, 'store'])->middleware(['can:course_materials.create', 'throttle:30,1'])->name('course-materials.store');
            Route::get('course-materials/export/{format}', [CourseMaterialController::class, 'export'])->middleware('can:course_materials.export')->name('course-materials.export');
            Route::get('course-materials/{material}', [CourseMaterialController::class, 'show'])->whereNumber('material')->withTrashed()->middleware('can:course_materials.view')->name('course-materials.show');
            Route::get('course-materials/{material}/edit', [CourseMaterialController::class, 'edit'])->whereNumber('material')->middleware('can:course_materials.edit')->name('course-materials.edit');
            Route::put('course-materials/{material}', [CourseMaterialController::class, 'update'])->whereNumber('material')->middleware('can:course_materials.edit')->name('course-materials.update');
            Route::post('course-materials/{material}/file', [CourseMaterialController::class, 'replaceFile'])->whereNumber('material')->middleware(['can:course_materials.upload', 'throttle:20,1'])->name('course-materials.file.replace');
            Route::get('course-materials/{material}/download', [CourseMaterialController::class, 'download'])->whereNumber('material')->middleware('can:course_materials.download')->name('course-materials.download');
            Route::post('course-materials/{material}/targets', [CourseMaterialController::class, 'storeTargets'])->whereNumber('material')->middleware('can:course_materials.assign')->name('course-materials.targets.store');
            Route::delete('course-materials/{material}/targets/{target}', [CourseMaterialController::class, 'destroyTarget'])->whereNumber('material')->whereNumber('target')->middleware('can:course_materials.assign')->name('course-materials.targets.destroy');
            Route::post('course-materials/{material}/status', [CourseMaterialController::class, 'status'])->whereNumber('material')->middleware('can:course_materials.change_status')->name('course-materials.status');
            Route::delete('course-materials/{material}', [CourseMaterialController::class, 'destroy'])->whereNumber('material')->middleware('can:course_materials.delete')->name('course-materials.destroy');
            Route::get('course-materials/{material}/engagement', [CourseMaterialController::class, 'engagement'])->whereNumber('material')->middleware('can:course_materials.view_reports')->name('course-materials.engagement');
        });

        /*
        |----------------------------------------------------------------------
        | Assignments - phase-19-23 sec 7.2
        |----------------------------------------------------------------------
        |
        | There is no `restore` route: a soft-deleted assignment is one nobody
        | ever submitted to (the policy refuses the delete otherwise), so the
        | recovery path is the trashed filter on the index, not a second action.
        |
        */
        Route::middleware('module:assignments')->group(static function (): void {
            Route::get('assignments', [AssignmentController::class, 'index'])->middleware('can:assignments.view_any')->name('assignments.index');
            Route::get('assignments/create', [AssignmentController::class, 'create'])->middleware('can:assignments.create')->name('assignments.create');
            Route::post('assignments', [AssignmentController::class, 'store'])->middleware(['can:assignments.create', 'throttle:30,1'])->name('assignments.store');
            Route::get('assignments/{assignment}', [AssignmentController::class, 'show'])->whereNumber('assignment')->withTrashed()->middleware('can:assignments.view')->name('assignments.show');
            Route::get('assignments/{assignment}/edit', [AssignmentController::class, 'edit'])->whereNumber('assignment')->middleware('can:assignments.edit')->name('assignments.edit');
            Route::put('assignments/{assignment}', [AssignmentController::class, 'update'])->whereNumber('assignment')->middleware('can:assignments.edit')->name('assignments.update');
            Route::post('assignments/{assignment}/status', [AssignmentController::class, 'status'])->whereNumber('assignment')->middleware('can:assignments.change_status')->name('assignments.status');
            Route::post('assignments/{assignment}/duplicate', [AssignmentController::class, 'duplicate'])->whereNumber('assignment')->middleware('can:assignments.create')->name('assignments.duplicate');
            Route::get('assignments/{assignment}/brief', [AssignmentController::class, 'downloadBrief'])->whereNumber('assignment')->middleware('can:assignments.download')->name('assignments.brief.download');
            Route::get('assignments/{assignment}/print', [AssignmentController::class, 'print'])->whereNumber('assignment')->middleware('can:assignments.print')->name('assignments.print');
            Route::delete('assignments/{assignment}', [AssignmentController::class, 'destroy'])->whereNumber('assignment')->middleware('can:assignments.delete')->name('assignments.destroy');
        });

        /*
        |----------------------------------------------------------------------
        | Assignment submissions - phase-19-23 sec 7.2, sec 4.1
        |----------------------------------------------------------------------
        |
        | A module of its own because grading is not authoring: a visiting trainer
        | may read a roster's work without marking it, and a coordinator may mark
        | without being able to publish new assignments. `edit` IS the mark-and-
        | feedback ability.
        |
        | There is no destroy route, and there never will be. The ability is not
        | registered, the policy returns false for every role, and the model
        | refuses the act even for a Super Admin.
        |
        */
        Route::middleware('module:assignment_submissions')->group(static function (): void {
            Route::get('assignments/{assignment}/submissions', [AssignmentSubmissionController::class, 'index'])->whereNumber('assignment')->middleware('can:assignment_submissions.view_any')->name('assignment-submissions.index');
            Route::post('assignments/{assignment}/submissions', [AssignmentSubmissionController::class, 'store'])->whereNumber('assignment')->middleware('can:assignment_submissions.create')->name('assignment-submissions.store');
            Route::post('assignments/{assignment}/grade-bulk', [AssignmentSubmissionController::class, 'gradeBulk'])->whereNumber('assignment')->middleware(['can:assignment_submissions.edit', 'throttle:10,1'])->name('assignment-submissions.grade-bulk');
            Route::post('assignments/{assignment}/release-marks', [AssignmentSubmissionController::class, 'release'])->whereNumber('assignment')->middleware('can:assignment_submissions.change_status')->name('assignment-submissions.release');
            Route::post('assignments/{assignment}/mark-missed', [AssignmentSubmissionController::class, 'markMissed'])->whereNumber('assignment')->middleware('can:assignment_submissions.change_status')->name('assignment-submissions.mark-missed');
            Route::get('assignments/{assignment}/export/{format}', [AssignmentSubmissionController::class, 'export'])->whereNumber('assignment')->middleware('can:assignment_submissions.export')->name('assignment-submissions.export');
            Route::get('assignment-submissions/{submission}', [AssignmentSubmissionController::class, 'show'])->whereNumber('submission')->middleware('can:assignment_submissions.view')->name('assignment-submissions.show');
            Route::post('assignment-submissions/{submission}/grade', [AssignmentSubmissionController::class, 'grade'])->whereNumber('submission')->middleware(['can:assignment_submissions.edit', 'throttle:60,1'])->name('assignment-submissions.grade');
            Route::post('assignment-submissions/{submission}/return', [AssignmentSubmissionController::class, 'returnForRework'])->whereNumber('submission')->middleware('can:assignment_submissions.edit')->name('assignment-submissions.return');
            Route::post('assignment-submissions/{submission}/amend', [AssignmentSubmissionController::class, 'amend'])->whereNumber('submission')->middleware('can:assignment_submissions.edit')->name('assignment-submissions.amend');
            Route::get('assignment-submissions/{submission}/files/{file}', [AssignmentSubmissionController::class, 'downloadFile'])->whereNumber('submission')->whereNumber('file')->middleware('can:assignment_submissions.download')->name('assignment-submissions.file.download');
            Route::get('assignment-submissions/{submission}/feedback-file', [AssignmentSubmissionController::class, 'downloadFeedback'])->whereNumber('submission')->middleware('can:assignment_submissions.download')->name('assignment-submissions.feedback.download');
        });

        /*
        |----------------------------------------------------------------------
        | Grade scales - phase-19-23 sec 7.3, sec 4.1
        |----------------------------------------------------------------------
        |
        | Its own module, granted separately from `results`: somebody maintains
        | the ladder that turns 87% into an A, and that is not the same right as
        | publishing a class's marks.
        |
        | `deactivate` is a change_status route, not a delete one. A scale that
        | has graded anybody is retired, never removed - a printed result card
        | names a grade, and the band it came from has to still exist to explain
        | it (INV-20-4). The destroy route below is for a scale nobody used.
        |
        */
        Route::middleware('module:grade_scales')->group(static function (): void {
            Route::get('grade-scales', [GradeScaleController::class, 'index'])->middleware('can:grade_scales.view_any')->name('grade-scales.index');
            Route::get('grade-scales/create', [GradeScaleController::class, 'create'])->middleware('can:grade_scales.create')->name('grade-scales.create');
            Route::post('grade-scales', [GradeScaleController::class, 'store'])->middleware(['can:grade_scales.create', 'throttle:30,1'])->name('grade-scales.store');
            Route::get('grade-scales/{grade_scale}', [GradeScaleController::class, 'show'])->whereNumber('grade_scale')->withTrashed()->middleware('can:grade_scales.view')->name('grade-scales.show');
            Route::get('grade-scales/{grade_scale}/edit', [GradeScaleController::class, 'edit'])->whereNumber('grade_scale')->middleware('can:grade_scales.edit')->name('grade-scales.edit');
            Route::put('grade-scales/{grade_scale}', [GradeScaleController::class, 'update'])->whereNumber('grade_scale')->middleware('can:grade_scales.edit')->name('grade-scales.update');
            Route::post('grade-scales/{grade_scale}/default', [GradeScaleController::class, 'setDefault'])->whereNumber('grade_scale')->middleware('can:grade_scales.change_status')->name('grade-scales.default');
            Route::post('grade-scales/{grade_scale}/deactivate', [GradeScaleController::class, 'deactivate'])->whereNumber('grade_scale')->middleware('can:grade_scales.change_status')->name('grade-scales.deactivate');
            Route::delete('grade-scales/{grade_scale}', [GradeScaleController::class, 'destroy'])->whereNumber('grade_scale')->middleware('can:grade_scales.delete')->name('grade-scales.destroy');
        });

        /*
        |----------------------------------------------------------------------
        | Exams - phase-19-23 sec 7.3, sec 4.2
        |----------------------------------------------------------------------
        |
        | `status` and `reschedule` are separate routes although both sit behind
        | change_status: rescheduling takes a date, a room and a reason and
        | re-runs the clash check, and folding it into the status endpoint would
        | mean one handler doing two jobs badly.
        |
        | There is no `restore` route even though the ability exists: an exam is
        | cancelled rather than deleted, so the trashed filter on the index is
        | the only place a deleted one appears at all.
        |
        */
        Route::middleware('module:exams')->group(static function (): void {
            Route::get('exams', [ExamController::class, 'index'])->middleware('can:exams.view_any')->name('exams.index');
            Route::get('exams/create', [ExamController::class, 'create'])->middleware('can:exams.create')->name('exams.create');
            Route::post('exams', [ExamController::class, 'store'])->middleware(['can:exams.create', 'throttle:30,1'])->name('exams.store');
            Route::get('exams/export/{format}', [ExamController::class, 'export'])->middleware('can:exams.export')->name('exams.export');
            Route::get('exams/{exam}', [ExamController::class, 'show'])->whereNumber('exam')->withTrashed()->middleware('can:exams.view')->name('exams.show');
            Route::get('exams/{exam}/edit', [ExamController::class, 'edit'])->whereNumber('exam')->middleware('can:exams.edit')->name('exams.edit');
            Route::put('exams/{exam}', [ExamController::class, 'update'])->whereNumber('exam')->middleware('can:exams.edit')->name('exams.update');
            Route::post('exams/{exam}/status', [ExamController::class, 'status'])->whereNumber('exam')->middleware('can:exams.change_status')->name('exams.status');
            Route::post('exams/{exam}/reschedule', [ExamController::class, 'reschedule'])->whereNumber('exam')->middleware('can:exams.change_status')->name('exams.reschedule');
            Route::delete('exams/{exam}', [ExamController::class, 'destroy'])->whereNumber('exam')->middleware('can:exams.delete')->name('exams.destroy');
        });

        /*
        |----------------------------------------------------------------------
        | Exam results - phase-19-23 sec 7.3, sec 4.2
        |----------------------------------------------------------------------
        |
        | One GET for the sheet and one POST for the whole of it. There is no
        | per-row save endpoint, deliberately: INV-20-6 says a sheet is all-or-
        | nothing, and an endpoint that writes one row invites a half-entered
        | class that nobody notices until the averages look wrong.
        |
        | `verify` and `publish` are separate routes behind separate abilities
        | because sec 2.28.4 says they are separate people. The service refuses a
        | verifier who entered any mark on the sheet; the routes only have to not
        | pretend the two decisions are one.
        |
        | There is NO destroy route, and there never will be. `results.delete` is
        | not a registered ability, the policy returns false for every role, and
        | the model refuses the act - including for a Super Admin, who skips
        | policies entirely.
        |
        */
        Route::middleware('module:results')->group(static function (): void {
            Route::get('results', [ExamResultController::class, 'index'])->middleware('can:results.view_any')->name('results.index');
            Route::get('exams/{exam}/results', [ExamResultController::class, 'sheet'])->whereNumber('exam')->middleware('can:results.create')->name('exam-results.sheet');
            Route::post('exams/{exam}/results', [ExamResultController::class, 'save'])->whereNumber('exam')->middleware(['can:results.create', 'throttle:30,1'])->name('exam-results.save');
            Route::post('exams/{exam}/results/verify', [ExamResultController::class, 'verify'])->whereNumber('exam')->middleware('can:results.approve')->name('exam-results.verify');
            Route::post('exams/{exam}/results/publish', [ExamResultController::class, 'publish'])->whereNumber('exam')->middleware('can:results.change_status')->name('exam-results.publish');
            Route::post('exams/{exam}/results/unpublish', [ExamResultController::class, 'unpublish'])->whereNumber('exam')->middleware('can:results.change_status')->name('exam-results.unpublish');
            Route::get('exams/{exam}/results/export/{format}', [ExamResultController::class, 'export'])->whereNumber('exam')->middleware('can:results.export')->name('exam-results.export');
            Route::get('exams/{exam}/result-cards', [ResultCardController::class, 'index'])->whereNumber('exam')->middleware('can:results.view_reports')->name('result-cards.index');
            Route::put('exam-results/{result}', [ExamResultController::class, 'amend'])->whereNumber('result')->middleware('can:results.edit')->name('exam-results.amend');
            Route::get('enrollments/{enrollment}/result-card', [ResultCardController::class, 'show'])->whereNumber('enrollment')->middleware('can:results.print')->name('result-cards.show');
        });

        /*
        |----------------------------------------------------------------------
        | Print templates - phase-19-23 sec 7.6, sec 4.1
        |----------------------------------------------------------------------
        |
        | A module of its own because `body_html` is powerful. sec 4.1 makes it
        | separately grantable so a designer can be given the certificate layout
        | with no sight of a student record - and so somebody who issues
        | certificates all day is not thereby handed the ability that decides
        | what HTML a PDF renderer receives.
        |
        | `preview` is behind `print` and renders with PrintTokenRegistry's
        | example values, never a real student's data. That is what makes the
        | whole module safe to grant on its own.
        |
        | A used template is retired, never deleted: a document has to stay
        | re-printable byte-identically. The destroy route is for one nobody has
        | printed with.
        |
        */
        Route::middleware('module:print_templates')->group(static function (): void {
            Route::get('print-templates', [PrintTemplateController::class, 'index'])->middleware('can:print_templates.view_any')->name('print-templates.index');
            Route::get('print-templates/create', [PrintTemplateController::class, 'create'])->middleware('can:print_templates.create')->name('print-templates.create');
            Route::post('print-templates', [PrintTemplateController::class, 'store'])->middleware(['can:print_templates.create', 'throttle:30,1'])->name('print-templates.store');
            Route::get('print-templates/{print_template}', [PrintTemplateController::class, 'show'])->whereNumber('print_template')->withTrashed()->middleware('can:print_templates.view')->name('print-templates.show');
            Route::get('print-templates/{print_template}/edit', [PrintTemplateController::class, 'edit'])->whereNumber('print_template')->middleware('can:print_templates.edit')->name('print-templates.edit');
            Route::put('print-templates/{print_template}', [PrintTemplateController::class, 'update'])->whereNumber('print_template')->middleware('can:print_templates.edit')->name('print-templates.update');
            Route::get('print-templates/{print_template}/preview', [PrintTemplateController::class, 'preview'])->whereNumber('print_template')->middleware('can:print_templates.view')->name('print-templates.preview');
            Route::post('print-templates/{print_template}/duplicate', [PrintTemplateController::class, 'duplicate'])->whereNumber('print_template')->middleware('can:print_templates.create')->name('print-templates.duplicate');
            Route::get('print-templates/{print_template}/tokens', [PrintTemplateController::class, 'tokens'])->whereNumber('print_template')->middleware('can:print_templates.view')->name('print-templates.tokens');
            Route::post('print-templates/{print_template}/default', [PrintTemplateController::class, 'setDefault'])->whereNumber('print_template')->middleware('can:print_templates.change_status')->name('print-templates.default');
            Route::post('print-templates/{print_template}/deactivate', [PrintTemplateController::class, 'deactivate'])->whereNumber('print_template')->middleware('can:print_templates.change_status')->name('print-templates.deactivate');
            Route::delete('print-templates/{print_template}', [PrintTemplateController::class, 'destroy'])->whereNumber('print_template')->middleware('can:print_templates.delete')->name('print-templates.destroy');
        });

        /*
        |----------------------------------------------------------------------
        | Certificates - phase-19-23 sec 7.6, sec 4.2
        |----------------------------------------------------------------------
        |
        | `candidates` is the screen the phase exists for: enrolments that could
        | be certified, each showing every eligibility rule with its actual
        | value. "Why can't I issue this?" is answered by the UI rather than by
        | a developer reading a log.
        |
        | `issue`, `revoke` and `reissue` are all change_status - sec 4.2 puts
        | them behind one ability because they are the same person's job. The
        | approve ability is separate and is what gates an OVERRIDE of a failing
        | eligibility report; the controller authorises it only when an override
        | reason is actually present, so an eligible certificate never demands
        | a second right.
        |
        | The destroy route is for a DRAFT. An issued or revoked certificate is
        | never deleted (INV-21-1) - the policy narrows the ability to drafts and
        | the model refuses anything that gets past it, Super Admin included.
        |
        */
        Route::middleware('module:certificates')->group(static function (): void {
            Route::get('certificates', [CertificateController::class, 'index'])->middleware('can:certificates.view_any')->name('certificates.index');
            Route::get('certificates/eligible', [CertificateController::class, 'eligible'])->middleware('can:certificates.create')->name('certificates.eligible');
            Route::get('enrollments/{enrollment}/certificate/eligibility', [CertificateController::class, 'eligibility'])->whereNumber('enrollment')->middleware('can:certificates.create')->name('certificates.eligibility');
            Route::get('certificates/create', [CertificateController::class, 'create'])->middleware('can:certificates.create')->name('certificates.create');
            Route::post('certificates', [CertificateController::class, 'store'])->middleware(['can:certificates.create', 'throttle:30,1'])->name('certificates.store');
            Route::get('certificates/export/{format}', [CertificateController::class, 'export'])->middleware('can:certificates.export')->name('certificates.export');
            Route::get('certificates/{certificate}', [CertificateController::class, 'show'])->whereNumber('certificate')->withTrashed()->middleware('can:certificates.view')->name('certificates.show');
            Route::put('certificates/{certificate}', [CertificateController::class, 'update'])->whereNumber('certificate')->middleware('can:certificates.edit')->name('certificates.update');
            Route::post('certificates/{certificate}/issue', [CertificateController::class, 'issue'])->whereNumber('certificate')->middleware('can:certificates.change_status')->name('certificates.issue');
            Route::post('certificates/bulk-issue', [CertificateController::class, 'bulkIssue'])->middleware(['can:certificates.change_status', 'throttle:5,1'])->name('certificates.bulk-issue');
            Route::post('certificates/{certificate}/revoke', [CertificateController::class, 'revoke'])->whereNumber('certificate')->middleware('can:certificates.change_status')->name('certificates.revoke');
            Route::post('certificates/{certificate}/reissue', [CertificateController::class, 'reissue'])->whereNumber('certificate')->middleware('can:certificates.create')->name('certificates.reissue');
            Route::get('certificates/{certificate}/print', [CertificateController::class, 'print'])->whereNumber('certificate')->middleware('can:certificates.print')->name('certificates.print');
            Route::get('certificates/{certificate}/pdf', [CertificateController::class, 'pdf'])->whereNumber('certificate')->middleware('can:certificates.print')->name('certificates.pdf');
            Route::post('certificates/{certificate}/regenerate-pdf', [CertificateController::class, 'regeneratePdf'])->whereNumber('certificate')->middleware('can:certificates.edit')->name('certificates.pdf.regenerate');
            Route::get('certificates/{certificate}/verifications', [CertificateController::class, 'verifications'])->whereNumber('certificate')->middleware('can:certificates.view_logs')->name('certificates.verifications');
            Route::delete('certificates/{certificate}', [CertificateController::class, 'destroy'])->whereNumber('certificate')->middleware('can:certificates.delete')->name('certificates.destroy');
        });

        /*
        |----------------------------------------------------------------------
        | Student ID cards - phase-19-23 sec 7.6, sec 4.2
        |----------------------------------------------------------------------
        |
        | There is NO destroy route, and `student_id_cards.delete` is not a
        | registered ability. A certificate has a draft - a document nobody has
        | been given - and a card does not: it is numbered, snapshotted and
        | printed in one step, so every row is a card that existed in the world.
        | A card that was issued stays on the register, marked lost, damaged,
        | replaced or revoked.
        |
        | `print` gates batch printing as well as one card, bounded by
        | institute.id_card_batch_print_max - a ceiling the service enforces,
        | because a route cannot count.
        |
        */
        Route::middleware('module:student_id_cards')->group(static function (): void {
            Route::get('student-id-cards', [StudentIdCardController::class, 'index'])->middleware('can:student_id_cards.view_any')->name('student-id-cards.index');
            Route::get('student-id-cards/create', [StudentIdCardController::class, 'create'])->middleware('can:student_id_cards.create')->name('student-id-cards.create');
            Route::post('student-id-cards', [StudentIdCardController::class, 'store'])->middleware(['can:student_id_cards.create', 'throttle:30,1'])->name('student-id-cards.store');
            Route::get('student-id-cards/export/{format}', [StudentIdCardController::class, 'export'])->middleware('can:student_id_cards.export')->name('student-id-cards.export');
            Route::get('student-id-cards/{card}', [StudentIdCardController::class, 'show'])->whereNumber('card')->withTrashed()->middleware('can:student_id_cards.view')->name('student-id-cards.show');
            Route::post('student-id-cards/{card}/status', [StudentIdCardController::class, 'status'])->whereNumber('card')->middleware('can:student_id_cards.change_status')->name('student-id-cards.status');
            Route::post('student-id-cards/{card}/replace', [StudentIdCardController::class, 'replace'])->whereNumber('card')->middleware('can:student_id_cards.create')->name('student-id-cards.replace');
            Route::get('student-id-cards/{card}/print', [StudentIdCardController::class, 'print'])->whereNumber('card')->middleware('can:student_id_cards.print')->name('student-id-cards.print');
            Route::post('student-id-cards/batch-print', [StudentIdCardController::class, 'batchPrint'])->middleware(['can:student_id_cards.print', 'throttle:10,1'])->name('student-id-cards.batch-print');
            Route::post('student-id-cards/bulk-issue', [StudentIdCardController::class, 'bulkIssue'])->middleware(['can:student_id_cards.create', 'throttle:5,1'])->name('student-id-cards.bulk-issue');
            Route::get('student-id-cards/{card}/pdf', [StudentIdCardController::class, 'pdf'])->whereNumber('card')->middleware('can:student_id_cards.print')->name('student-id-cards.pdf');
        });

        /*
        |----------------------------------------------------------------------
        | Support desks - phase-19-23 sec 7.6
        |----------------------------------------------------------------------
        |
        | One screen carries the list and the form. There is no create page and
        | no edit page: a desk is five fields an administrator touches twice a
        | year, and the row being edited comes back through ?edit=.
        |
        | `destroy` exists but the policy offers it only for a desk that has
        | never held a ticket - retiring is what people mean, and it is the
        | button beside it.
        |
        */
        Route::middleware('module:ticket_departments')->group(static function (): void {
            Route::get('ticket-departments', [TicketDepartmentController::class, 'index'])->middleware('can:ticket_departments.view_any')->name('ticket-departments.index');
            Route::post('ticket-departments', [TicketDepartmentController::class, 'store'])->middleware('can:ticket_departments.create')->name('ticket-departments.store');
            Route::put('ticket-departments/{department}', [TicketDepartmentController::class, 'update'])->whereNumber('department')->middleware('can:ticket_departments.edit')->name('ticket-departments.update');
            Route::post('ticket-departments/{department}/status', [TicketDepartmentController::class, 'status'])->whereNumber('department')->middleware('can:ticket_departments.change_status')->name('ticket-departments.status');
            Route::delete('ticket-departments/{department}', [TicketDepartmentController::class, 'destroy'])->whereNumber('department')->middleware('can:ticket_departments.delete')->name('ticket-departments.destroy');
        });

        /*
        |----------------------------------------------------------------------
        | Support tickets - phase-19-23 sec 7.6, sec 9.4
        |----------------------------------------------------------------------
        |
        | There is NO destroy route, because nothing deletes a ticket
        | (INV-22-1): a wrong one is closed, a wrong reply is corrected by
        | another reply, and the model refuses both below the gate.
        |
        | `sla` is declared BEFORE `{ticket}` so the word is never read as an
        | id - the numeric constraint would 404 it, which is a confusing way to
        | discover the route order was wrong.
        |
        | The index is gated on the POLICY, not on a permission string. Laravel's
        | `can:` middleware takes ONE ability and has no OR syntax - the pipe
        | that used to be here was read as a single ability named
        | `support_tickets.view_any|support_tickets.view`, which exists in no
        | permissions table, so the route denied everybody except Super Admin
        | (who passes through `Gate::before` before any of this is consulted).
        | The Support Agent whose entire job is this queue could not open it.
        |
        | `viewAny()` on the policy already expresses the OR, and expressing it
        | once is the point: sec 9.4 gives `view_any` the queue
        | and `view` their own, and the controller applies the difference to the
        | query rather than to the button.
        |
        */
        Route::middleware('module:support_tickets')->group(static function (): void {
            Route::get('tickets', [TicketController::class, 'index'])->middleware('can:viewAny,App\Models\Support\SupportTicket')->name('tickets.index');
            Route::get('tickets/create', [TicketController::class, 'create'])->middleware('can:support_tickets.create')->name('tickets.create');
            Route::post('tickets', [TicketController::class, 'store'])->middleware(['can:support_tickets.create', 'throttle:20,1'])->name('tickets.store');
            Route::get('tickets/sla', [TicketController::class, 'sla'])->middleware('can:support_tickets.view_reports')->name('tickets.sla');
            Route::get('tickets/{ticket}', [TicketController::class, 'show'])->whereNumber('ticket')->middleware('can:view,ticket')->name('tickets.show');
            Route::post('tickets/{ticket}/replies', [TicketController::class, 'reply'])->whereNumber('ticket')->middleware(['can:reply,ticket', 'throttle:60,1'])->name('tickets.replies.store');
            Route::post('tickets/{ticket}/assign', [TicketController::class, 'assign'])->whereNumber('ticket')->middleware('can:support_tickets.assign')->name('tickets.assign');
            Route::post('tickets/{ticket}/status', [TicketController::class, 'status'])->whereNumber('ticket')->middleware('can:changeStatus,ticket')->name('tickets.status');
            Route::post('tickets/{ticket}/priority', [TicketController::class, 'priority'])->whereNumber('ticket')->middleware('can:support_tickets.edit')->name('tickets.priority');
            Route::post('tickets/{ticket}/department', [TicketController::class, 'department'])->whereNumber('ticket')->middleware('can:support_tickets.edit')->name('tickets.department');
        });

        /*
        |----------------------------------------------------------------------
        | Meetings - phase-19-23 sec 7.6, sec 6.17
        |----------------------------------------------------------------------
        |
        | `calendar` and `create` come before `{meeting}` for the same reason as
        | the ticket routes.
        |
        | `respond` is gated on the policy and not on a permission, because
        | answering an invitation is what being in the room means: a client
        | holds no `meetings.*` permission at all and must still be able to
        | accept.
        |
        | `status` is cancelling, and nothing else. A meeting does not walk a
        | status path from a screen; it is rescheduled, which writes a
        | successor, or it is cancelled with a reason.
        |
        */
        Route::middleware('module:meetings')->group(static function (): void {
            Route::get('meetings', [MeetingController::class, 'index'])->middleware('can:viewAny,App\Models\Support\Meeting')->name('meetings.index');
            Route::get('meetings/calendar', [MeetingController::class, 'calendar'])->middleware('can:viewAny,App\Models\Support\Meeting')->name('meetings.calendar');
            Route::get('meetings/create', [MeetingController::class, 'create'])->middleware('can:meetings.create')->name('meetings.create');
            Route::post('meetings', [MeetingController::class, 'store'])->middleware('can:meetings.create')->name('meetings.store');
            Route::get('meetings/{meeting}', [MeetingController::class, 'show'])->whereNumber('meeting')->middleware('can:view,meeting')->name('meetings.show');
            Route::put('meetings/{meeting}', [MeetingController::class, 'update'])->whereNumber('meeting')->middleware('can:update,meeting')->name('meetings.update');
            Route::post('meetings/{meeting}/status', [MeetingController::class, 'status'])->whereNumber('meeting')->middleware('can:cancel,meeting')->name('meetings.status');
            Route::post('meetings/{meeting}/reschedule', [MeetingController::class, 'reschedule'])->whereNumber('meeting')->middleware('can:reschedule,meeting')->name('meetings.reschedule');
            Route::post('meetings/{meeting}/participants', [MeetingController::class, 'addParticipants'])->whereNumber('meeting')->middleware('can:assign,meeting')->name('meetings.participants.store');
            Route::delete('meetings/{meeting}/participants/{participant}', [MeetingController::class, 'removeParticipant'])->whereNumber('meeting')->whereNumber('participant')->middleware('can:assign,meeting')->name('meetings.participants.destroy');
            Route::post('meetings/{meeting}/respond', [MeetingController::class, 'respond'])->whereNumber('meeting')->middleware('can:respond,meeting')->name('meetings.respond');
            Route::post('meetings/{meeting}/attendance', [MeetingController::class, 'attendance'])->whereNumber('meeting')->middleware('can:markAttendance,meeting')->name('meetings.attendance');
            Route::put('meetings/{meeting}/notes', [MeetingController::class, 'notes'])->whereNumber('meeting')->middleware('can:saveNotes,meeting')->name('meetings.notes');
            Route::get('meetings/{meeting}/ics', [MeetingController::class, 'ics'])->whereNumber('meeting')->middleware('can:downloadIcs,meeting')->name('meetings.ics');
            Route::delete('meetings/{meeting}', [MeetingController::class, 'destroy'])->whereNumber('meeting')->middleware('can:delete,meeting')->name('meetings.destroy');
        });

        /*
        |----------------------------------------------------------------------
        | Messages - phase-19-23 sec 7.6, sec 6.18, sec 9.4
        |----------------------------------------------------------------------
        |
        | `recipients` is the matrix-filtered picker (sec 8.13) and is declared
        | before `{conversation}` so the word is never read as an id.
        |
        | `send` is gated on the policy, which re-asks MessagingMatrix on every
        | request (INV-22-4) - removing a pair from the settings silences
        | existing threads, and that only works if the answer is asked again.
        | The service enforces the rate limit as well as this middleware,
        | because a limit only one layer knows is a limit a queued job skips.
        |
        | There is NO destroy route: a message is the record of what was said,
        | and a wrong one is corrected by another message.
        |
        */
        Route::middleware('module:messages')->group(static function (): void {
            Route::get('messages', [ConversationController::class, 'index'])->middleware('can:messages.view')->name('messages.index');
            Route::get('messages/recipients', [ConversationController::class, 'recipients'])->middleware(['can:messages.create', 'throttle:60,1'])->name('messages.recipients');
            Route::post('messages', [ConversationController::class, 'store'])->middleware(['can:messages.create', 'throttle:20,1'])->name('messages.store');
            Route::get('messages/{conversation}', [ConversationController::class, 'show'])->whereNumber('conversation')->middleware('can:view,conversation')->name('messages.show');
            Route::post('messages/{conversation}/send', [ConversationController::class, 'send'])->whereNumber('conversation')->middleware(['can:send,conversation', 'throttle:60,1'])->name('messages.send');
            Route::post('messages/{conversation}/read', [ConversationController::class, 'read'])->whereNumber('conversation')->middleware('can:view,conversation')->name('messages.read');
            Route::post('messages/{conversation}/participants', [ConversationController::class, 'addParticipant'])->whereNumber('conversation')->middleware('can:addParticipant,conversation')->name('messages.participants.store');
            Route::post('messages/{conversation}/leave', [ConversationController::class, 'leave'])->whereNumber('conversation')->middleware('can:leave,conversation')->name('messages.leave');
            Route::post('messages/{conversation}/close', [ConversationController::class, 'close'])->whereNumber('conversation')->middleware('can:close,conversation')->name('messages.close');
        });

        /*
        |----------------------------------------------------------------------
        | The bell - phase-19-23 sec 7.7
        |----------------------------------------------------------------------
        |
        | One file for all five panels, included inside this group so it picks
        | up the prefix, the name prefix and the panel middleware. The contract
        | says the bell behaves identically everywhere, and the only honest way
        | to guarantee that is not to write it five times.
        |
        */
        /*
        |----------------------------------------------------------------------
        | Reports, analytics, logs and search - phase-19-23 sec 7.8
        |----------------------------------------------------------------------
        |
        | The route table says every report needs `can:reports.view_reports`
        | PLUS its own `permissions()`. No static middleware string can say
        | that: the list differs per report and changes when a report's columns
        | do. `ReportEngine::authorise()` resolves it from `ReportRegistry` on
        | every request, and 404s an unknown or unpermitted key rather than
        | 403ing it - telling somebody a report exists that they may not open
        | is a small disclosure with no upside.
        |
        | `{report}` is constrained to `{group}.{name}` so a dotted key does not
        | swallow the sibling segments: without the constraint `/reports/x/schema`
        | would match `show` with a report called "x/schema".
        |
        | The export route is throttled at 20/minute because each request runs
        | the report to count it before deciding how to deliver it - it is the
        | one read in this group that is never cheap.
        |
        */
        Route::middleware('module:reports')->group(static function (): void {
            Route::prefix('reports')->name('reports.')->group(static function (): void {
                Route::get('/', [ReportController::class, 'index'])
                    ->middleware('can:reports.view_reports')
                    ->name('index');

                Route::get('{report}', [ReportController::class, 'show'])
                    ->where('report', '[a-z]+\.[a-z0-9_]+')
                    ->middleware('can:reports.view_reports')
                    ->name('show');

                Route::get('{report}/schema', [ReportController::class, 'schema'])
                    ->where('report', '[a-z]+\.[a-z0-9_]+')
                    ->middleware('can:reports.view_reports')
                    ->name('schema');

                Route::get('{report}/export/{format}', [ReportController::class, 'export'])
                    ->where('report', '[a-z]+\.[a-z0-9_]+')
                    ->middleware(['can:reports.export', 'throttle:20,1'])
                    ->name('export');

                Route::get('{report}/print', [ReportController::class, 'print'])
                    ->where('report', '[a-z]+\.[a-z0-9_]+')
                    ->middleware('can:reports.print')
                    ->name('print');
            });

            /*
            |------------------------------------------------------------------
            | The export register
            |------------------------------------------------------------------
            |
            | Bound on `uuid`, never on the id: sec 2.26 keeps the sequential key
            | out of every URL, so a download link cannot be walked backwards.
            |
            | `destroy` removes the FILE. The row is kept for ever - it is the
            | record of what left the building - which is why the service's
            | method is called `expire()` and not `delete()`.
            |
            */
            Route::prefix('report-exports')->name('report-exports.')->group(static function (): void {
                Route::get('/', [ReportExportController::class, 'index'])
                    ->middleware('can:reports.export')
                    ->name('index');

                Route::get('{export:uuid}/download', [ReportExportController::class, 'download'])
                    ->middleware(['can:reports.export', 'can:download,export'])
                    ->name('download');

                Route::delete('{export:uuid}', [ReportExportController::class, 'destroy'])
                    ->middleware(['can:reports.export', 'can:delete,export'])
                    ->name('destroy');
            });

            /*
            |------------------------------------------------------------------
            | Analytics
            |------------------------------------------------------------------
            |
            | One request per chart rather than nine in the page: the slowest
            | chart would otherwise decide how long everybody waits, and one
            | that failed would take the page with it. The per-chart permission
            | is checked inside `AnalyticsService`, because the name is a route
            | parameter and middleware cannot read it into an ability string.
            |
            */
            Route::prefix('analytics')->name('analytics.')->group(static function (): void {
                Route::get('/', [AnalyticsController::class, 'index'])
                    ->middleware('can:reports.view_reports')
                    ->name('index');

                Route::get('chart/{chart}', [AnalyticsController::class, 'chart'])
                    ->middleware(['can:reports.view_reports', 'throttle:60,1'])
                    ->name('chart');
            });
        });

        /*
        |----------------------------------------------------------------------
        | The audit trail - sec 107
        |----------------------------------------------------------------------
        |
        | Its own module, separate from `activity_log`, because sec 4.1 made
        | old-and-new values a different right from the operational feed: a
        | compliance reader may be given one without the other, in either
        | direction.
        |
        | **The sec 106 viewer is NOT redeclared here.** Phase 1 already ships
        | `admin.activity-log.index|show|export` and a filter request that
        | covers most of sec 6.22's list; a second controller on the same path
        | would have shadowed it, and two screens over one table is how they
        | drift. Phase 23's `ActivityLogService` is reached instead through the
        | `sys.activity_log` report, which is what made the log exportable
        | through the same engine as everything else.
        |
        | Read-only. There is no store, no update and no destroy route here,
        | and there must never be one (INV-23-5): a log that can be edited is a
        | record of what somebody was willing to leave.
        |
        | `export` is placed before `{activity}` so the word is never read as
        | an id.
        |
        */
        Route::middleware('module:audit_trail')->prefix('audit-trail')->name('audit-trail.')->group(static function (): void {
            Route::get('/', [AuditTrailController::class, 'index'])
                ->middleware('can:audit_trail.view_logs')
                ->name('index');

            Route::get('export/{format}', [AuditTrailController::class, 'export'])
                ->middleware(['can:audit_trail.export', 'throttle:10,1'])
                ->name('export');

            Route::get('{activity}', [AuditTrailController::class, 'show'])
                ->whereNumber('activity')
                ->middleware('can:audit_trail.view_logs')
                ->name('show');
        });

        /*
        |----------------------------------------------------------------------
        | Global search - sec 108
        |----------------------------------------------------------------------
        |
        | `suggest` is throttled at 60/minute per user: with the debounce that
        | is fast enough to type through, and slow enough that the endpoint
        | cannot be used to walk a table one letter at a time.
        |
        | Nothing here caches. Two people searching one word are running eleven
        | differently-scoped queries, and one shared entry would hand the
        | narrower viewer the wider answer.
        |
        */
        Route::middleware('module:global_search')->prefix('search')->name('search.')->group(static function (): void {
            Route::get('/', [GlobalSearchController::class, 'index'])
                ->middleware('can:global_search.view_any')
                ->name('index');

            Route::get('suggest', [GlobalSearchController::class, 'suggest'])
                ->middleware(['can:global_search.view_any', 'throttle:60,1'])
                ->name('suggest');

            Route::post('open', [GlobalSearchController::class, 'open'])
                ->middleware(['can:global_search.view_any', 'throttle:120,1'])
                ->name('open');
        });

        $ability = 'notifications.view_any';
        require __DIR__.'/notifications.php';

    });
