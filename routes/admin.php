<?php

declare(strict_types=1);

use App\Enums\Cms\SectionPlacement;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\Cms\CtaBlockController;
use App\Http\Controllers\Admin\Cms\FaqCategoryController;
use App\Http\Controllers\Admin\Cms\FaqController;
use App\Http\Controllers\Admin\Cms\MediaController;
use App\Http\Controllers\Admin\Cms\MenuController;
use App\Http\Controllers\Admin\Cms\MenuItemController;
use App\Http\Controllers\Admin\Cms\PageController;
use App\Http\Controllers\Admin\Cms\PageRevisionController;
use App\Http\Controllers\Admin\Cms\PublicCacheController;
use App\Http\Controllers\Admin\Cms\SectionController;
use App\Http\Controllers\Admin\Cms\SectionItemController;
use App\Http\Controllers\Admin\Cms\SectionRevisionController;
use App\Http\Controllers\Admin\Cms\SeoController;
use App\Http\Controllers\Admin\Cms\SitemapController;
use App\Http\Controllers\Admin\Cms\StatisticController;
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

                Route::post('media/{asset}/regenerate', [MediaController::class, 'regenerate'])
                    ->whereNumber('asset')->middleware('can:website_media.edit')->name('media.regenerate');

                Route::delete('media/{asset}', [MediaController::class, 'destroy'])
                    ->whereNumber('asset')->middleware('can:website_media.delete')->name('media.destroy');
            });
        });
    });
