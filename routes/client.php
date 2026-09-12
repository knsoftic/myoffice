<?php

declare(strict_types=1);

use App\Http\Controllers\Client\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Client panel routes (phase-01 §8)
|--------------------------------------------------------------------------
|
| Loaded by bootstrap/app.php inside the `web` middleware group, so the prefix,
| the route-name prefix and the panel middleware are declared here.
|
|   auth                 — a session is required
|   active               — status must be Active
|   panel:client         — one of the user's roles must belong to this panel
|   can:client_portal.*  — the exact portal permission, per route
|
| Phase 1 registers the dashboard only, so panel isolation is testable from day
| one. Phases 5–6 and 13 add projects, milestones, tasks, invoices, payments and
| files here; every row they expose is scoped to the authenticated client
| (CLAUDE.md rule 10).
|
*/

Route::prefix('client')
    ->name('client.')
    ->middleware(['auth', 'active', 'panel:client'])
    ->group(function (): void {

        Route::get('/', [DashboardController::class, 'index'])
            ->middleware('can:client_portal.dashboard')
            ->name('dashboard');
    });
