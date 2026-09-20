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
use App\Http\Controllers\Admin\DashboardController;
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
use App\Http\Controllers\Admin\Hr\SelfService\AttendanceController as MyAttendanceController;
use App\Http\Controllers\Admin\Hr\SelfService\LeaveController as MyLeaveController;
use App\Http\Controllers\Admin\Hr\SelfService\PayslipController as MyPayslipController;
use App\Http\Controllers\Admin\Hr\SelfService\ProfileController as MyProfileController;
use App\Http\Controllers\Admin\Hr\SalaryStructureController;
use App\Http\Controllers\Admin\Hr\WorkShiftController;
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
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TaskBoardController;
use App\Http\Controllers\Admin\TaskChecklistController;
use App\Http\Controllers\Admin\TaskController;
use App\Http\Controllers\Admin\TimeTrackingController;
use App\Http\Controllers\Admin\UserController;
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
            ->middleware(['can:dashboard.view', 'throttle:60,1,dashboard-widget'])
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
    });
