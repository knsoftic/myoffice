<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Enums\ExamStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teacher\Concerns\ResolvesTheSignedInTeacher;
use App\Http\Requests\Admin\Institute\SaveResultSheetRequest;
use App\Models\Institute\Exam;
use App\Models\Institute\ExamResult;
use App\Services\Institute\ExamResultService;
use App\Support\Institute\TeacherScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A teacher marking their own exam — `teacher.results.*` (phase-19-23 §7.5).
 *
 * **Entering marks, and nothing else.** There is no verify and no publish on this panel, and that is
 * the whole point of §2.28.4: the person who marked a sheet is not the person who signs it off.
 * `ExamResultService::verify()` refuses a verifier who entered any row on the sheet, so adding the
 * buttons here would only produce a screen whose buttons always fail — but leaving them out is the
 * honest version, because the teacher is not meant to be the second pair of eyes at all.
 *
 * **The same `SaveResultSheetRequest` as the admin panel.** One set of rules for one operation; a
 * teacher-specific copy would be a second opinion that drifts. Ownership is checked here, before the
 * service is reached, because the request cannot know about `TeacherScope`.
 */
final class ResultController extends Controller
{
    use ResolvesTheSignedInTeacher;

    public function __construct(
        private readonly ExamResultService $results,
    ) {}

    /**
     * The marking board for this teacher's own batches — which papers are waiting on them.
     *
     * A published exam stays on the list rather than dropping off it: a teacher who marked a sheet
     * last month still needs to be able to look at what was published, and hiding finished work is
     * how somebody ends up asking the office for a copy of marks they entered themselves.
     */
    public function index(Request $request): View
    {
        $batchIds = TeacherScope::batchIds($this->teacher($request));

        $exams = Exam::query()
            ->whereIn('batch_id', $batchIds)
            ->whereIn('status', [
                ExamStatus::Conducted->value,
                ExamStatus::Marking->value,
                ExamStatus::ResultsPublished->value,
            ])
            ->with(['course:id,name', 'batch:id,code,name'])
            ->when($request->integer('batch_id') > 0 && in_array($request->integer('batch_id'), $batchIds, true),
                fn ($q) => $q->where('batch_id', $request->integer('batch_id')))
            ->when($request->string('status')->toString() !== '', fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->orderByDesc('scheduled_date')
            ->paginate(15)
            ->withQueryString();

        return view('teacher.results.index', [
            'exams' => $exams,
            'awaitingMarks' => Exam::query()
                ->whereIn('batch_id', $batchIds)
                ->where('status', ExamStatus::Conducted->value)
                ->count(),
        ]);
    }

    /**
     * No `Gate::authorize('create', ExamResult::class)` here, and that is not an omission.
     *
     * `ExamResultPolicy::create()` asks for `results.create`, which is the **office's** ability. A
     * teacher does not hold it and is not meant to: the whole point of `teacher_portal.*` is that
     * somebody can be given their own screens without being given the admin panel's. Checking the
     * admin ability on this panel produced a 403 for the very teacher whose batch it was.
     *
     * What governs this screen is the route's `can:teacher_portal.results_entry`, and `mustOwn()`
     * below — the same shape every other teacher-panel controller uses (Phase 19's
     * `SubmissionController`, phase-14-17's attendance).
     */
    public function sheet(Request $request, Exam $exam): View
    {
        $this->mustOwn($request, $exam);

        $sheet = $this->results->openSheet($exam);

        return view('teacher.results.sheet', [
            'exam' => $exam->load(['course:id,name', 'batch:id,code,name']),
            'scale' => $sheet['scale'],
            'rows' => $sheet['rows'],
            'readonly' => ! $exam->acceptsResultEntry(),
        ]);
    }

    public function save(SaveResultSheetRequest $request, Exam $exam): RedirectResponse
    {
        $this->mustOwn($request, $exam);

        $outcome = $this->results->saveSheet($exam, $request->validated('rows'), $request->user());

        if (! $outcome->succeeded()) {
            $bag = [];

            foreach ($outcome->errors as $index => $message) {
                $bag["rows.$index.obtained_marks"] = $message;
            }

            return back()
                ->withInput()
                ->withErrors($bag)
                ->with('toast', ['type' => 'error', 'message' => $outcome->summary()]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => $outcome->summary().' Somebody else has to check it before it goes out.',
        ]);
    }

    /**
     * A 404, not a 403: this panel takes no id it did not give out, so an exam that is not theirs is
     * one they should not learn exists (phase-14-17 §9).
     */
    private function mustOwn(Request $request, Exam $exam): void
    {
        if (! TeacherScope::owns($this->teacher($request), (int) $exam->getAttribute('batch_id'))) {
            throw new NotFoundHttpException;
        }
    }
}
