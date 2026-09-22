<?php

declare(strict_types=1);

use App\Http\Controllers\Teacher\AttendanceController;
use App\Http\Controllers\Teacher\BatchController;
use App\Http\Controllers\Teacher\DashboardController;
use App\Http\Controllers\Teacher\DemoClassController;
use App\Http\Controllers\Teacher\ProgressController;
use App\Http\Controllers\Teacher\TimetableController;
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

        /*
        |----------------------------------------------------------------------
        | phase-14-17 §7.9 — what a teacher can see of their own week
        |----------------------------------------------------------------------
        |
        | Every row here is scoped to the `teachers` row this user IS, not to a
        | batch id in the URL: a teacher who guesses somebody else's batch id
        | gets a 404, because the query never looked outside their own rows
        | (CLAUDE.md rule 10). The attendance and progress screens arrive with
        | Phase 17; these are the read-only ones the timetable makes possible.
        |
        */
        Route::middleware(['module:batches', 'can:teacher_portal.batches'])->group(static function (): void {
            Route::get('batches', [BatchController::class, 'index'])->name('batches.index');
            Route::get('batches/{batch}', [BatchController::class, 'show'])->whereNumber('batch')->name('batches.show');
        });

        Route::get('students', [BatchController::class, 'students'])
            ->middleware(['module:students', 'can:teacher_portal.students'])
            ->name('students.index');

        Route::middleware(['module:timetable', 'can:teacher_portal.timetable'])->group(static function (): void {
            Route::get('timetable', [TimetableController::class, 'index'])->name('timetable.index');
            Route::get('sessions/{session}', [TimetableController::class, 'session'])->whereNumber('session')->name('sessions.show');
        });

        Route::middleware(['module:demo_classes', 'can:teacher_portal.demo_classes'])->group(static function (): void {
            Route::get('demo-classes', [DemoClassController::class, 'index'])->name('demo-classes.index');
            Route::post('demo-classes/{demo}/status', [DemoClassController::class, 'status'])->whereNumber('demo')->name('demo-classes.status');
        });

        /*
        |----------------------------------------------------------------------
        | phase-14-17 §7.9 — the register, which is the teacher's to take
        |----------------------------------------------------------------------
        |
        | **`attendance_mark` is separate from `attendance` on purpose** (§4.3): a
        | visiting trainer may be allowed to see a roster without being allowed to
        | write the register. Both are scoped to the sessions this teacher owns —
        | as the batch teacher, as a slot's teacher, or as the actual teacher of
        | that one class after a substitution — and somebody else's session is a
        | 404, not a 403.
        |
        */
        Route::middleware('module:student_attendance')->group(static function (): void {
            Route::get('attendance', [AttendanceController::class, 'index'])->middleware('can:teacher_portal.attendance')->name('attendance.index');
            Route::get('sessions/{session}/attendance', [AttendanceController::class, 'mark'])->whereNumber('session')->middleware('can:teacher_portal.attendance_mark')->name('attendance.mark');
            Route::post('sessions/{session}/attendance', [AttendanceController::class, 'store'])->whereNumber('session')->middleware(['can:teacher_portal.attendance_mark', 'throttle:30,1'])->name('attendance.store');
            Route::put('attendance/{attendance}', [AttendanceController::class, 'update'])->whereNumber('attendance')->middleware('can:teacher_portal.attendance_mark')->name('attendance.update');
            Route::get('reports/attendance', [AttendanceController::class, 'report'])->middleware('can:teacher_portal.reports')->name('reports.attendance');
        });

        Route::middleware('module:student_progress')->group(static function (): void {
            Route::get('batches/{batch}/progress', [ProgressController::class, 'show'])->whereNumber('batch')->middleware('can:teacher_portal.student_progress')->name('progress.show');
            Route::post('batches/{batch}/progress/topics/{topic}', [ProgressController::class, 'markForBatch'])->whereNumber('batch')->whereNumber('topic')->middleware('can:teacher_portal.progress_mark')->name('progress.store');
            Route::post('enrollments/{enrollment}/progress/topics/{topic}', [ProgressController::class, 'markForStudent'])->whereNumber('enrollment')->whereNumber('topic')->middleware('can:teacher_portal.progress_mark')->name('progress.student');
        });
    });
