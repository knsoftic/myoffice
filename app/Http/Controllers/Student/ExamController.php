<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Enums\ExamStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesTheSignedInStudent;
use App\Models\Institute\Exam;
use App\Models\Institute\StudentBatchEnrollment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A student's own exam calendar — `student.exams.*` (phase-19-23 §7.4).
 *
 * **Scoped by the batches this student is enrolled in, never by an id in the URL.** The panel's rule
 * (phase-14-17 §9): an exam that is not theirs is a 404, because a 403 confirms it exists.
 *
 * **A draft is not on the calendar and a cancellation stays on it.** `onTheCalendar()` hides drafts —
 * a coordinator sketching next month's papers has not told anybody anything yet — but keeps cancelled
 * ones visible, because a student who had it in their diary needs to see that it is off.
 */
final class ExamController extends Controller
{
    use ResolvesTheSignedInStudent;

    public function index(Request $request): View
    {
        $student = $this->student($request);
        $batchIds = $this->batchIds($student->getKey());

        $exams = Exam::query()
            ->whereIn('batch_id', $batchIds)
            ->onTheCalendar()
            ->with(['course:id,name', 'batch:id,code,name', 'classroom:id,code,name', 'teacher:id,employee_id'])
            ->when($request->string('when')->toString() === 'past', fn ($q) => $q->where('scheduled_date', '<', now()->toDateString())->reorder('scheduled_date', 'desc'))
            ->when($request->string('when')->toString() !== 'past', fn ($q) => $q->where('scheduled_date', '>=', now()->toDateString()))
            ->paginate(15)
            ->withQueryString();

        return view('student.exams.index', [
            'exams' => $exams,
            'when' => $request->string('when')->toString() === 'past' ? 'past' : 'upcoming',
            // Counted over the whole calendar rather than the current page, so the tab does not change
            // its number as somebody pages through.
            'upcomingCount' => Exam::query()
                ->whereIn('batch_id', $batchIds)
                ->onTheCalendar()
                ->where('scheduled_date', '>=', now()->toDateString())
                ->count(),
        ]);
    }

    public function show(Request $request, Exam $exam): View
    {
        $student = $this->student($request);

        if (! in_array((int) $exam->getAttribute('batch_id'), $this->batchIds($student->getKey()), true)) {
            throw new NotFoundHttpException;
        }

        // A draft was never announced, so as far as this panel is concerned it does not exist.
        if ($exam->status === ExamStatus::Draft) {
            throw new NotFoundHttpException;
        }

        $result = $exam->results()
            ->where('student_id', $student->getKey())
            ->published()
            ->with('band')
            ->first();

        return view('student.exams.show', [
            'exam' => $exam->load(['course:id,name', 'batch:id,code,name', 'classroom:id,code,name', 'teacher:id,employee_id', 'topic:id,title']),
            // Present only once the sheet is published; before that the page says so rather than
            // showing an empty marks panel that looks like a zero.
            'result' => $result,
        ]);
    }

    /**
     * Every batch this student is or was enrolled in. Past enrolments count: a student who finished a
     * course in March still has the right to look at the exam they sat in February.
     *
     * @return list<int>
     */
    private function batchIds(mixed $studentId): array
    {
        return StudentBatchEnrollment::query()
            ->where('student_id', $studentId)
            ->pluck('batch_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
