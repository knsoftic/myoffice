<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LoginHistoryController;
use App\Http\Controllers\Admin\ModuleController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin panel routes (phase-01 §8)
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
        */

        Route::get('/', [DashboardController::class, 'index'])
            ->middleware('can:dashboard.view_any')
            ->name('dashboard');

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
        | Modules
        |------------------------------------------------------------------
        */

        Route::get('modules', [ModuleController::class, 'index'])
            ->middleware('can:modules.view_any')
            ->name('modules.index');

        Route::post('modules/{module}/toggle', [ModuleController::class, 'toggle'])
            ->middleware('can:modules.change_status')
            ->name('modules.toggle');

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
