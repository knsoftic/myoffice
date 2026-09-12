<?php

declare(strict_types=1);

use App\Http\Controllers\Collaborator\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Collaborator panel routes (phase-01 §8)
|--------------------------------------------------------------------------
|
| Loaded by bootstrap/app.php inside the `web` middleware group, so the prefix,
| the route-name prefix and the panel middleware are declared here.
|
|   auth                   — a session is required
|   active                 — status must be Active
|   panel:collaborator     — one of the user's roles must belong to this panel
|   module:collaborators   — the whole panel closes when the owning business
|                            module is disabled (phase-01 §8)
|   can:collaborator_portal.* — the exact portal permission, per route
|
| Phase 1 registers the dashboard only, so panel isolation is testable from day
| one. Phases 8–12 add students, projects, tasks, wallet, commissions, payouts
| and statements here; every row they expose is scoped to the authenticated
| collaborator (CLAUDE.md rule 10).
|
*/

Route::prefix('collaborator')
    ->name('collaborator.')
    ->middleware(['auth', 'active', 'panel:collaborator', 'module:collaborators'])
    ->group(function (): void {

        Route::get('/', [DashboardController::class, 'index'])
            ->middleware('can:collaborator_portal.dashboard')
            ->name('dashboard');
    });
