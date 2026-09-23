<?php

declare(strict_types=1);

use App\Http\Controllers\Teacher\AssignmentController as TeacherAssignmentController;
use App\Http\Controllers\Teacher\AttendanceController;
use App\Http\Controllers\Teacher\BatchController;
use App\Http\Controllers\Teacher\DashboardController;
use App\Http\Controllers\Teacher\DemoClassController;
use App\Http\Controllers\Teacher\ExamController as TeacherExamController;
use App\Http\Controllers\Teacher\MaterialController as TeacherMaterialController;
use App\Http\Controllers\Teacher\ProgressController;
use App\Http\Controllers\Teacher\ResultController as TeacherResultController;
use App\Http\Controllers\Teacher\SubmissionController;
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

        /*
        |----------------------------------------------------------------------
        | Materials - phase-19-23 sec 7.10
        |----------------------------------------------------------------------
        |
        | Scoped by TeacherScope, which counts all four ways a teacher reaches a
        | batch - named teacher, timetable entry, taught a session, or was the
        | teacher a session was moved off. Leaving one out means a substitute who
        | actually took the class cannot hand out the handout they used.
        |
        */
        Route::middleware('module:course_materials')->group(static function (): void {
            Route::get('materials', [TeacherMaterialController::class, 'index'])->middleware('can:teacher_portal.materials')->name('materials.index');
            Route::get('materials/create', [TeacherMaterialController::class, 'create'])->middleware('can:teacher_portal.materials_upload')->name('materials.create');
            Route::post('materials', [TeacherMaterialController::class, 'store'])->middleware(['can:teacher_portal.materials_upload', 'throttle:30,1'])->name('materials.store');
            Route::put('materials/{material}', [TeacherMaterialController::class, 'update'])->whereNumber('material')->middleware('can:teacher_portal.materials_upload')->name('materials.update');
            Route::post('materials/{material}/targets', [TeacherMaterialController::class, 'targets'])->whereNumber('material')->middleware('can:teacher_portal.materials_upload')->name('materials.targets');
            Route::post('materials/{material}/status', [TeacherMaterialController::class, 'status'])->whereNumber('material')->middleware('can:teacher_portal.materials_upload')->name('materials.status');
            Route::get('materials/{material}/download', [TeacherMaterialController::class, 'download'])->whereNumber('material')->middleware('can:teacher_portal.materials')->name('materials.download');
        });

        /*
        |----------------------------------------------------------------------
        | Assignments and marking - phase-19-23 sec 7.10
        |----------------------------------------------------------------------
        |
        | `assignment_grade` is separate from `assignments` for the same reason
        | phase-14-17 split `attendance_mark`: setting work and judging it are
        | different rights, and a teaching assistant commonly holds exactly one.
        |
        */
        Route::middleware('module:assignments')->group(static function (): void {
            Route::get('assignments', [TeacherAssignmentController::class, 'index'])->middleware('can:teacher_portal.assignments')->name('assignments.index');
            Route::get('assignments/create', [TeacherAssignmentController::class, 'create'])->middleware('can:teacher_portal.assignments')->name('assignments.create');
            Route::post('assignments', [TeacherAssignmentController::class, 'store'])->middleware(['can:teacher_portal.assignments', 'throttle:30,1'])->name('assignments.store');
            Route::put('assignments/{assignment}', [TeacherAssignmentController::class, 'update'])->whereNumber('assignment')->middleware('can:teacher_portal.assignments')->name('assignments.update');
            Route::post('assignments/{assignment}/status', [TeacherAssignmentController::class, 'status'])->whereNumber('assignment')->middleware('can:teacher_portal.assignments')->name('assignments.status');
        });

        Route::middleware('module:assignment_submissions')->group(static function (): void {
            Route::get('assignments/{assignment}/submissions', [SubmissionController::class, 'index'])->whereNumber('assignment')->middleware('can:teacher_portal.assignments')->name('submissions.index');
            Route::post('assignments/{assignment}/grade-bulk', [SubmissionController::class, 'gradeBulk'])->whereNumber('assignment')->middleware('can:teacher_portal.assignment_grade')->name('submissions.grade-bulk');
            Route::post('assignments/{assignment}/release-marks', [SubmissionController::class, 'release'])->whereNumber('assignment')->middleware('can:teacher_portal.assignment_grade')->name('submissions.release');
            Route::get('submissions/{submission}', [SubmissionController::class, 'show'])->whereNumber('submission')->middleware('can:teacher_portal.assignments')->name('submissions.show');
            Route::post('submissions/{submission}/grade', [SubmissionController::class, 'grade'])->whereNumber('submission')->middleware(['can:teacher_portal.assignment_grade', 'throttle:60,1'])->name('submissions.grade');
            Route::get('submissions/{submission}/files/{file}', [SubmissionController::class, 'file'])->whereNumber('submission')->whereNumber('file')->middleware('can:teacher_portal.assignments')->name('submissions.file');
        });

        /*
        |----------------------------------------------------------------------
        | Exams and marking - phase-19-23 sec 7.5
        |----------------------------------------------------------------------
        |
        | `results_entry` is separate from `results` for the same reason
        | phase-14-17 split `attendance_mark`: reading a class's marks and
        | writing them are different rights, and a visiting trainer commonly
        | holds only the first.
        |
        | There is no verify route and no publish route on this panel, and that
        | is sec 2.28.4 rather than an omission. The person who marked a sheet is
        | not the person who signs it off; ExamResultService::verify() refuses a
        | verifier who entered any row, so a button here would be one that always
        | failed. Leaving it out says the true thing instead.
        |
        | Scoping goes through TeacherScope, never through `exams.teacher_id` - a
        | teacher reaches a batch four ways (D107), and filtering on the exam's
        | own teacher would hide a paper their own students are sitting because a
        | colleague was named examiner.
        |
        */
        Route::middleware('module:exams')->group(static function (): void {
            Route::get('exams', [TeacherExamController::class, 'index'])->middleware('can:teacher_portal.exams')->name('exams.index');
            Route::get('exams/{exam}', [TeacherExamController::class, 'show'])->whereNumber('exam')->middleware('can:teacher_portal.exams')->name('exams.show');
        });

        Route::middleware('module:results')->group(static function (): void {
            Route::get('results', [TeacherResultController::class, 'index'])->middleware('can:teacher_portal.results')->name('results.index');
            Route::get('exams/{exam}/results', [TeacherResultController::class, 'sheet'])->whereNumber('exam')->middleware('can:teacher_portal.results_entry')->name('results.sheet');
            Route::post('exams/{exam}/results', [TeacherResultController::class, 'save'])->whereNumber('exam')->middleware(['can:teacher_portal.results_entry', 'throttle:30,1'])->name('results.save');
        });

    });
