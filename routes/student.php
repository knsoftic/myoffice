<?php

declare(strict_types=1);

use App\Http\Controllers\Student\BatchController;
use App\Http\Controllers\Student\DashboardController;
use App\Http\Controllers\Student\TimetableController;
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

        /*
        |----------------------------------------------------------------------
        | phase-14-17 §7.8 — a student's own batch, timetable and teachers
        |----------------------------------------------------------------------
        |
        | `{enrollment}` is resolved against the authenticated student's own
        | enrolments, so another student's id is a 404 rather than a 403: a 403
        | confirms the row exists, which is half of what an id-prober wanted.
        |
        | `student.teachers.index` shows the name and PUBLIC bio of the people
        | who teach them, and nothing else — a phone number on a staff record is
        | not a student's to read.
        |
        */
        Route::get('batches/{enrollment}', [BatchController::class, 'show'])
            ->whereNumber('enrollment')
            ->middleware(['module:batches', 'can:student_portal.batches'])
            ->name('batches.show');

        Route::get('timetable', [TimetableController::class, 'index'])
            ->middleware(['module:timetable', 'can:student_portal.timetable'])
            ->name('timetable.index');

        Route::get('teachers', [BatchController::class, 'teachers'])
            ->middleware(['module:teachers', 'can:student_portal.teachers'])
            ->name('teachers.index');
    });
