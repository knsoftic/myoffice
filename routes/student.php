<?php

declare(strict_types=1);

use App\Http\Controllers\Student\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Student panel routes (phase-01 §8)
|--------------------------------------------------------------------------
|
| Loaded by bootstrap/app.php inside the `web` middleware group, so the prefix,
| the route-name prefix and the panel middleware are declared here.
|
|   auth                  — a session is required
|   active                — status must be Active
|   panel:student         — one of the user's roles must belong to this panel
|   can:student_portal.*  — the exact portal permission, per route
|
| Phase 1 registers the dashboard only, so panel isolation is testable from day
| one. Later institute phases add courses, timetable, attendance, materials,
| assignments, exams, results, certificates and fees here; every row they expose
| is scoped to the authenticated student (CLAUDE.md rule 10).
|
*/

Route::prefix('student')
    ->name('student.')
    ->middleware(['auth', 'active', 'panel:student'])
    ->group(function (): void {

        Route::get('/', [DashboardController::class, 'index'])
            ->middleware('can:student_portal.dashboard')
            ->name('dashboard');
    });
