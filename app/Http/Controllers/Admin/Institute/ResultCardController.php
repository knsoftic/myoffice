<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Http\Controllers\Controller;
use App\Models\Institute\Exam;
use App\Models\Institute\ExamResult;
use App\Models\Institute\StudentBatchEnrollment;
use App\Support\Institute\ResultSummary;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The printed result card — `admin.result-cards.*` (§81, phase-19-23 §7.3, §5.1).
 *
 * **Published results only, on the office's copy as much as on the student's.** A card is a document
 * that leaves the building; printing an unverified mark on one and correcting it afterwards is how an
 * institute ends up with two versions of a student's record in circulation.
 *
 * **`result_card_show_all_exams` decides the shape, and it is read here.** On, the card consolidates
 * every published result for the enrolment — which is what a parent asking "how is she doing?" wants.
 * Off, it is one exam per card. The same `ResultSummary` produces the totals either way, so the two
 * shapes can never disagree about what the marks add up to (D107).
 */
final class ResultCardController extends Controller
{
    /**
     * Who to print for: the roster of one exam, so a coordinator can run off a class set.
     */
    public function index(Request $request, Exam $exam): View
    {
        Gate::authorize('viewReports', $exam);

        $results = $exam->results()
            ->published()
            ->with(['student:id,student_code,name', 'band', 'enrollment:id,student_id,batch_id'])
            ->orderBy('position_in_batch')
            ->paginate(30)
            ->withQueryString();

        return view('admin.result-cards.index', [
            'exam' => $exam->load(['course:id,name', 'batch:id,code,name']),
            'results' => $results,
            'published' => $exam->isPublished(),
        ]);
    }

    /**
     * One card. Consolidated across the enrolment or limited to a single exam, per §5.1.
     */
    public function show(Request $request, StudentBatchEnrollment $enrollment): View
    {
        $student = $enrollment->student;

        if ($student === null) {
            throw new NotFoundHttpException;
        }

        $consolidated = (bool) setting('institute.result_card_show_all_exams', true);
        $examId = $request->integer('exam_id');

        $query = ExamResult::query()
            ->where('student_batch_enrollment_id', $enrollment->getKey())
            ->published()
            ->with(['exam:id,name,exam_type,scheduled_date,weight_percentage', 'band']);

        // A single-exam card still has to be *asked* for one; with the setting off and no exam named,
        // the most recent published result is the only sensible card to print.
        if (! $consolidated || $examId > 0) {
            $query->when($examId > 0, fn ($q) => $q->where('exam_id', $examId));
        }

        $results = ResultSummary::inPrintOrder($query->get());

        if (! $consolidated && $examId <= 0) {
            $results = $results->take(-1)->values();
        }

        if ($results->isEmpty()) {
            throw new NotFoundHttpException;
        }

        // Authorised against a row rather than the class, because `print` is a per-result ability and
        // the branch scope hangs off the exam behind it.
        Gate::authorize('print', $results->first());

        return view('admin.result-cards.show', [
            'student' => $student,
            'enrollment' => $enrollment->load(['batch:id,code,name,course_id', 'batch.course:id,name']),
            'results' => $results,
            'summary' => ResultSummary::of($results),
            'consolidated' => $consolidated && $examId <= 0,
            'showPosition' => (bool) setting('institute.result_card_show_position', true),
            'showAttendance' => (bool) setting('institute.result_card_show_attendance', true),
        ]);
    }
}
