<?php

declare(strict_types=1);

use App\Http\Controllers\Teacher\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Teacher panel routes (phase-01 §8)
|--------------------------------------------------------------------------
|
| Loaded by bootstrap/app.php inside the `web` middleware group, so the prefix,
| the route-name prefix and the panel middleware are declared here.
|
|   auth                  — a session is required
|   active                — status must be Active
|   panel:teacher         — one of the user's roles must belong to this panel
|   can:teacher_portal.*  — the exact portal permission, per route
|
| Phase 1 registers the dashboard only, so panel isolation is testable from day
| one. Later institute phases add batches, students, timetable, attendance
| marking, materials, assignments, exams and results here; every row they expose
| is scoped to the authenticated teacher (CLAUDE.md rule 10).
|
*/

Route::prefix('teacher')
    ->name('teacher.')
    ->middleware(['auth', 'active', 'panel:teacher'])
    ->group(function (): void {

        Route::get('/', [DashboardController::class, 'index'])
            ->middleware('can:teacher_portal.dashboard')
            ->name('dashboard');
    });
