<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesTheSignedInStudent;
use App\Models\Institute\ExamResult;
use App\Models\Institute\StudentBatchEnrollment;
use App\Support\Institute\ResultSummary;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A student's own results — `student.results.*` (phase-19-23 §7.4, §3.2).
 *
 * **`published()` on every query, with no way to turn it off.** §3.2 gives exactly one status in which
 * a student may see a mark, and this panel has no filter, no flag and no admin override that widens
 * it. A result that has been withdrawn disappears again, which is the point of withdrawing it.
 *
 * **Position is shown only when the institute prints it.** `result_card_show_position` is read here
 * rather than in the view, because a screen that decides for itself what to show is a screen that can
 * disagree with the printed card.
 *
 * The totals come from `ResultSummary`, which the admin card also uses — the figure a student sees and
 * the figure the office prints are the same arithmetic, not two copies of it (D107).
 */
final class ResultController extends Controller
{
    use ResolvesTheSignedInStudent;

    public function index(Request $request): View
    {
        $student = $this->student($request);

        $results = ExamResult::query()
            ->where('exam_results.student_id', $student->getKey())
            ->published()
            ->with(['exam:id,name,exam_type,scheduled_date,course_id,batch_id', 'exam.course:id,name', 'band'])
            ->join('exams', 'exams.id', '=', 'exam_results.exam_id')
            ->when($request->integer('course_id') > 0, fn ($q) => $q->where('exams.course_id', $request->integer('course_id')))
            ->orderByDesc('exams.scheduled_date')
            ->select('exam_results.*')
            ->paginate(15)
            ->withQueryString();

        return view('student.results.index', [
            'results' => $results,
            // Over every published result, not over the current page: a summary strip that changed as
            // somebody paged would be worse than no strip at all.
            // **This total is over every published result, so it must not be capped**: a `limit()` here
            // would print a wrong percentage rather than a slow one, which is the one outcome a transcript
            // cannot have. `ResultSummary::of()` folds any iterable, so the rows stream through
            // `lazyById()` in chunks of 500 and only one chunk is ever resident — phase-24-25 section 6.4's
            // rule for an aggregate, the same `chunkById` walk the exports use (PRF-05). A student with
            // fewer than 500 results still costs exactly one query. `id` joins the select list because a
            // keyset walk pages by it.
            'summary' => ResultSummary::of(
                ExamResult::query()
                    ->where('student_id', $student->getKey())
                    ->published()
                    ->select(['id', 'exam_id', 'attendance_status', 'obtained_marks', 'total_marks', 'grade_point', 'is_passed'])
                    ->lazyById(500),
            ),
            // 50: an owner `where` is not a row bound, and one student's enrolment history grows with
            // every course they ever buy (phase-24-25 section 6.4, PRF-05).
            'enrollments' => StudentBatchEnrollment::query()
                ->where('student_id', $student->getKey())
                ->with(['batch:id,code,name,course_id', 'batch.course:id,name'])
                ->limit(50)
                ->get(),
            'showPosition' => (bool) setting('institute.result_card_show_position', true),
        ]);
    }

    public function show(Request $request, ExamResult $result): View
    {
        $student = $this->student($request);

        // Not theirs, or not published: the same 404 either way. A 403 on the second would tell them
        // the mark exists, which is precisely what an unpublished sheet is hiding.
        if ((int) $result->getAttribute('student_id') !== (int) $student->getKey() || ! $result->isPublished()) {
            throw new NotFoundHttpException;
        }

        return view('student.results.show', [
            'result' => $result->load(['exam.course:id,name', 'exam.batch:id,code,name', 'band', 'scale']),
            'showPosition' => (bool) setting('institute.result_card_show_position', true),
        ]);
    }

    /**
     * The printable card — the same one the office prints, rendered for the student themselves.
     */
    public function card(Request $request, StudentBatchEnrollment $enrollment): View
    {
        $student = $this->student($request);

        if ((int) $enrollment->getAttribute('student_id') !== (int) $student->getKey()) {
            throw new NotFoundHttpException;
        }

        $results = ResultSummary::inPrintOrder(
            ExamResult::query()
                ->where('student_batch_enrollment_id', $enrollment->getKey())
                ->published()
                ->with(['exam:id,name,exam_type,scheduled_date,weight_percentage', 'band'])
                ->get(),
        );

        // Nothing published for this enrolment yet. A blank card is worse than no card: it looks like
        // a record of having sat nothing.
        if ($results->isEmpty()) {
            throw new NotFoundHttpException;
        }

        return view('student.results.card', [
            'student' => $student,
            'enrollment' => $enrollment->load(['batch:id,code,name,course_id', 'batch.course:id,name']),
            'results' => $results,
            'summary' => ResultSummary::of($results),
            'showPosition' => (bool) setting('institute.result_card_show_position', true),
            'showAttendance' => (bool) setting('institute.result_card_show_attendance', true),
        ]);
    }
}
