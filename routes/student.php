<?php

declare(strict_types=1);

use App\Http\Controllers\Student\AssignmentController as StudentAssignmentController;
use App\Http\Controllers\Student\AttendanceController;
use App\Http\Controllers\Student\BatchController;
use App\Http\Controllers\Student\CertificateController as StudentCertificateController;
use App\Http\Controllers\Student\DashboardController;
use App\Http\Controllers\Student\ExamController;
use App\Http\Controllers\Student\FeeController;
use App\Http\Controllers\Student\MaterialController;
use App\Http\Controllers\Student\ProgressController;
use App\Http\Controllers\Student\ResultController;
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

        /*
        |----------------------------------------------------------------------
        | Materials - phase-19-23 sec 7.9
        |----------------------------------------------------------------------
        |
        | `material_download` is a separate permission from `materials` so a
        | fee-blocked student can still be shown the library and told why the
        | files are unavailable. Hiding the list as well would leave them
        | guessing what they were missing.
        |
        | Every action re-runs MaterialAccessService::grantFor() against live
        | state (sec 6.2 D-19-4): a student suspended, un-enrolled or fee-blocked
        | five minutes ago does not still get the bytes because the link was on a
        | page they were once allowed to see.
        |
        */
        Route::middleware('module:course_materials')->group(static function (): void {
            Route::get('materials', [MaterialController::class, 'index'])->middleware('can:student_portal.materials')->name('materials.index');
            Route::get('materials/{material}', [MaterialController::class, 'show'])->whereNumber('material')->middleware('can:student_portal.materials')->name('materials.show');
            Route::get('materials/{material}/download', [MaterialController::class, 'download'])->whereNumber('material')->middleware(['can:student_portal.material_download', 'throttle:60,1'])->name('materials.download');
            Route::get('materials/{material}/open', [MaterialController::class, 'open'])->whereNumber('material')->middleware('can:student_portal.material_download')->name('materials.open');
        });

        /*
        |----------------------------------------------------------------------
        | Assignments - phase-19-23 sec 7.9
        |----------------------------------------------------------------------
        |
        | `assignment_submit` is separate from `assignments`: reading the brief
        | and handing work in are different rights, and a student whose account
        | is restricted may keep the first without the second.
        |
        | The withdraw route is the only deletion a student has, and it reaches
        | drafts only - work handed in is never withdrawn.
        |
        */
        Route::middleware('module:assignments')->group(static function (): void {
            Route::get('assignments', [StudentAssignmentController::class, 'index'])->middleware('can:student_portal.assignments')->name('assignments.index');
            Route::get('assignments/{assignment}', [StudentAssignmentController::class, 'show'])->whereNumber('assignment')->middleware('can:student_portal.assignments')->name('assignments.show');
            Route::get('assignments/{assignment}/brief', [StudentAssignmentController::class, 'brief'])->whereNumber('assignment')->middleware('can:student_portal.assignments')->name('assignments.brief');
            Route::post('assignments/{assignment}/submission', [StudentAssignmentController::class, 'start'])->whereNumber('assignment')->middleware('can:student_portal.assignment_submit')->name('assignments.submission.start');
            Route::post('assignments/{assignment}/resubmit', [StudentAssignmentController::class, 'resubmit'])->whereNumber('assignment')->middleware(['can:student_portal.assignment_submit', 'throttle:10,1'])->name('submissions.resubmit');
            Route::post('submissions/{submission}/submit', [StudentAssignmentController::class, 'submit'])->whereNumber('submission')->middleware(['can:student_portal.assignment_submit', 'throttle:10,1'])->name('submissions.submit');
            Route::delete('submissions/{submission}', [StudentAssignmentController::class, 'withdraw'])->whereNumber('submission')->middleware('can:student_portal.assignment_submit')->name('submissions.withdraw');
            Route::get('submissions/{submission}/files/{file}', [StudentAssignmentController::class, 'file'])->whereNumber('submission')->whereNumber('file')->middleware('can:student_portal.assignment_submit')->name('submissions.file');
            Route::get('submissions/{submission}/feedback-file', [StudentAssignmentController::class, 'feedback'])->whereNumber('submission')->middleware('can:student_portal.assignments')->name('submissions.feedback');
        });

        /*
        |----------------------------------------------------------------------
        | Exams - phase-19-23 sec 7.4
        |----------------------------------------------------------------------
        |
        | Scoped to the batches this student is enrolled in, past ones included:
        | somebody who finished a course in March still has the right to look at
        | the paper they sat in February.
        |
        | A draft exam is a 404 here even for a student whose batch it belongs
        | to. A draft has not been announced, and a student learning about an
        | exam from one would be learning about an exam that may never happen.
        |
        */
        Route::middleware('module:exams')->group(static function (): void {
            Route::get('exams', [ExamController::class, 'index'])
                ->middleware('can:student_portal.exams')
                ->name('exams.index');

            Route::get('exams/{exam}', [ExamController::class, 'show'])
                ->whereNumber('exam')
                ->middleware('can:student_portal.exams')
                ->name('exams.show');
        });

        /*
        |----------------------------------------------------------------------
        | Results - phase-19-23 sec 7.4, sec 3.2
        |----------------------------------------------------------------------
        |
        | Every query on this panel is `published()`, with no filter, flag or
        | parameter that widens it. sec 3.2 names exactly one status in which a
        | student may see a mark; a withdrawn sheet disappears again, which is
        | the entire point of being able to withdraw one.
        |
        | The card route takes an ENROLMENT, not a student: the id in the URL is
        | checked against the signed-in student's own rows and is a 404 when it
        | is not theirs. Nothing here reads a student id from the request.
        |
        */
        Route::middleware('module:results')->group(static function (): void {
            Route::get('results', [ResultController::class, 'index'])
                ->middleware('can:student_portal.results')
                ->name('results.index');

            Route::get('results/{result}', [ResultController::class, 'show'])
                ->whereNumber('result')
                ->middleware('can:student_portal.results')
                ->name('results.show');

            Route::get('enrollments/{enrollment}/result-card', [ResultController::class, 'card'])
                ->whereNumber('enrollment')
                ->middleware('can:student_portal.results')
                ->name('results.card');
        });

        /*
        |----------------------------------------------------------------------
        | Certificates and the ID card - phase-19-23 sec 7.9, sec 9.3
        |----------------------------------------------------------------------
        |
        | Issued certificates only. A draft is a document nobody has decided to
        | give them yet, and a revoked one is one the institute has withdrawn -
        | both are 404 here, and so is anybody else's, so nothing is learned
        | from the difference.
        |
        | The card route takes no id at all: a student holds one live card and
        | the panel resolves it from the signed-in student's own row.
        |
        */
        Route::middleware('module:certificates')->group(static function (): void {
            Route::get('certificates', [StudentCertificateController::class, 'index'])
                ->middleware('can:student_portal.certificates')
                ->name('certificates.index');

            Route::get('certificates/{certificate}/pdf', [StudentCertificateController::class, 'pdf'])
                ->whereNumber('certificate')
                ->middleware('can:student_portal.certificates')
                ->name('certificates.pdf');
        });

        Route::middleware('module:student_id_cards')->group(static function (): void {
            Route::get('id-card', [StudentCertificateController::class, 'card'])
                ->middleware('can:student_portal.id_card')
                ->name('id-card.show');

            Route::get('id-card/pdf', [StudentCertificateController::class, 'cardPdf'])
                ->middleware('can:student_portal.id_card')
                ->name('id-card.pdf');
        });

        /*
        |----------------------------------------------------------------------
        | The bell - phase-19-23 sec 7.7
        |----------------------------------------------------------------------
        |
        | One file for all five panels, included inside this group so it picks
        | up the prefix, the name prefix and the panel middleware. The contract
        | says the bell behaves identically everywhere, and the only honest way
        | to guarantee that is not to write it five times.
        |
        */

        /*
        |----------------------------------------------------------------------
        | Phase 22 - tickets, meetings and messages (sec 7.6, sec 8)
        |----------------------------------------------------------------------
        |
        | One shared file for all four portals, included here so it picks up this
        | panel's prefix, name prefix and middleware. See its own header for why
        | there is one rather than four.
        |
        */
        $portal = 'student_portal';
        require __DIR__.'/portal-support.php';

        $ability = 'student_portal.notifications';
        require __DIR__.'/notifications.php';

    });
