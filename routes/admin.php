<?php

declare(strict_types=1);

use App\Enums\Cms\SectionPlacement;
use App\Http\Controllers\Admin\ActivityLogController;
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
use App\Http\Controllers\Admin\LoginHistoryController;
use App\Http\Controllers\Admin\ModuleController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
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
    });
