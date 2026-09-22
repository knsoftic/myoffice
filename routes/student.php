<?php

declare(strict_types=1);

use App\Http\Controllers\Student\AttendanceController;
use App\Http\Controllers\Student\BatchController;
use App\Http\Controllers\Student\DashboardController;
use App\Http\Controllers\Student\FeeController;
use App\Http\Controllers\Student\ProgressController;
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

        /*
        |----------------------------------------------------------------------
        | phase-14-17 §7.8 — their own register and their own syllabus
        |----------------------------------------------------------------------
        |
        | Read-only, and scoped to the signed-in student's own enrolments. A
        | student sees their own attendance and progress and nothing about a
        | classmate — not a name, not a percentage, not a count (INV-I15).
        |
        */
        Route::get('attendance', [AttendanceController::class, 'index'])
            ->middleware(['module:student_attendance', 'can:student_portal.attendance'])
            ->name('attendance.index');

        Route::get('progress', [ProgressController::class, 'index'])
            ->middleware(['module:student_progress', 'can:student_portal.progress'])
            ->name('progress.index');

        /*
        |----------------------------------------------------------------------
        | phase-18 §8.9 — their own fees, and nothing else
        |----------------------------------------------------------------------
        |
        | Every lookup starts from the `students` row this user *is*, and a
        | charge that is not theirs is a **404 rather than a 403** (§9): a 403
        | confirms the row exists, which turns an id into something worth
        | guessing.
        |
        | The slip route forces `FeeSlipOptions::studentCopy()` in the
        | controller, so no query parameter can talk it into printing a
        | commission rate or amount (§6.7.1, §112).
        |
        */
        Route::middleware('module:student_fees')->group(static function (): void {
            Route::get('fees', [FeeController::class, 'index'])
                ->middleware('can:student_portal.fees')
                ->name('fees.index');

            Route::get('fees/{fee}', [FeeController::class, 'show'])
                ->whereNumber('fee')
                ->middleware('can:student_portal.fees')
                ->name('fees.show');

            Route::get('fees/{fee}/slip', [FeeController::class, 'slip'])
                ->whereNumber('fee')
                ->middleware('can:student_portal.fees')
                ->name('fees.slip');

            Route::get('payments/{payment}/receipt', [FeeController::class, 'receipt'])
                ->whereNumber('payment')
                ->middleware('can:student_portal.payments')
                ->name('payments.receipt');
        });
    });
