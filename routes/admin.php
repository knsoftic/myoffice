<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ActivityLogController;
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
    });
