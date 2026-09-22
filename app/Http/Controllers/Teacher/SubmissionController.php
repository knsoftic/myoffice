<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Enums\SubmissionStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teacher\Concerns\ResolvesTheSignedInTeacher;
use App\Http\Requests\Admin\Institute\GradeSubmissionRequest;
use App\Models\Institute\Assignment;
use App\Models\Institute\AssignmentSubmission;
use App\Models\Institute\AssignmentSubmissionFile;
use App\Services\Institute\AssignmentService;
use App\Services\Institute\AssignmentSubmissionService;
use App\Support\Institute\TeacherScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A teacher marking their own batches' work — `teacher.submissions.*`
 * (§73, §80, phase-19-23 §7.10, §8.11).
 *
 * **`teacher_portal.assignment_grade` is separate from `.assignments` on purpose** — the same reasoning
 * as phase-14-17's split of `attendance_mark`. Setting work and judging it are different rights, and a
 * teaching assistant commonly holds exactly one of them.
 *
 * Every row is reached through `TeacherScope`, so another teacher's batch is a **404**.
 */
final class SubmissionController extends Controller
{
    use ResolvesTheSignedInTeacher;

    public function __construct(
        private readonly AssignmentSubmissionService $submissions,
        private readonly AssignmentService $assignments,
    ) {}

    public function index(Request $request, Assignment $assignment): View
    {
        $this->assertMine($request, $assignment);

        $rows = $assignment->submissions()
            ->with(['student:id,name,student_code'])
            ->when(
                $request->string('status')->toString() !== '',
                fn ($q) => $q->where('status', $request->string('status')->toString()),
                // Live attempts by default: a superseded row beside its successor is how somebody
                // marks the wrong one.
                fn ($q) => $q->whereNotNull('current_guard'),
            )
            ->orderByDesc('submitted_at')
            ->paginate(30)
            ->withQueryString();

        return view('teacher.submissions.index', [
            'assignment' => $assignment->load(['course:id,name', 'batch:id,code,name']),
            'submissions' => $rows,
            'stats' => $this->assignments->statistics($assignment),
            'statuses' => SubmissionStatus::cases(),
            'canGrade' => (bool) $request->user()?->can('teacher_portal.assignment_grade'),
        ]);
    }

    public function show(Request $request, AssignmentSubmission $submission): View
    {
        $this->assertMineSubmission($request, $submission);

        return view('teacher.submissions.show', [
            'submission' => $submission->load(['assignment:id,title,total_marks,passing_marks,batch_id', 'student:id,name,student_code', 'files']),
            'attempts' => AssignmentSubmission::query()
                ->where('assignment_id', $submission->getAttribute('assignment_id'))
                ->where('student_id', $submission->getAttribute('student_id'))
                ->orderBy('attempt_no')
                ->get(),
            'canGrade' => (bool) $request->user()?->can('teacher_portal.assignment_grade'),
        ]);
    }

    public function grade(GradeSubmissionRequest $request, AssignmentSubmission $submission): RedirectResponse
    {
        $this->assertMineSubmission($request, $submission);

        $released = $submission->getAttribute('marks_released_at') !== null;

        $released
            ? $this->submissions->amend($submission, $request->validated(), (string) $request->input('reason', ''), $request->user())
            : $this->submissions->grade($submission, $request->validated(), $request->user(), $request->file('feedback_file'));

        return back()->with('toast', [
            'type' => 'success',
            'message' => $released ? 'Mark amended, and the change is recorded.' : 'Marked.',
        ]);
    }

    public function gradeBulk(Request $request, Assignment $assignment): RedirectResponse
    {
        $this->assertMine($request, $assignment);

        $validated = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:200'],
            'rows.*.submission_id' => ['required', 'integer'],
            'rows.*.obtained_marks' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'rows.*.feedback' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = $this->submissions->bulkGrade($assignment, $validated['rows'], $request->user());

        return back()
            ->withErrors($result->errors)
            ->with('toast', [
                'type' => $result->succeeded() ? 'success' : 'error',
                'message' => $result->summary(),
            ]);
    }

    public function release(Request $request, Assignment $assignment): RedirectResponse
    {
        $this->assertMine($request, $assignment);

        $count = $this->submissions->releaseMarks($assignment, $request->user());

        return back()->with('toast', [
            'type' => $count > 0 ? 'success' : 'info',
            'message' => $count > 0
                ? $count.' '.($count === 1 ? 'mark is' : 'marks are').' now visible to the class.'
                : 'There was nothing waiting to be released.',
        ]);
    }

    public function file(Request $request, AssignmentSubmission $submission, AssignmentSubmissionFile $file): StreamedResponse
    {
        $this->assertMineSubmission($request, $submission);

        abort_unless(
            (int) $file->getAttribute('assignment_submission_id') === (int) $submission->getKey(),
            Response::HTTP_NOT_FOUND,
        );

        return $this->submissions->streamFile($file);
    }

    // -------------------------------------------------------------------------------------------

    private function assertMine(Request $request, Assignment $assignment): void
    {
        abort_unless(
            in_array((int) $assignment->getAttribute('batch_id'), TeacherScope::batchIds($this->teacher($request)), true),
            Response::HTTP_NOT_FOUND,
        );
    }

    private function assertMineSubmission(Request $request, AssignmentSubmission $submission): void
    {
        abort_unless(
            in_array((int) $submission->getAttribute('batch_id'), TeacherScope::batchIds($this->teacher($request)), true),
            Response::HTTP_NOT_FOUND,
        );
    }
}
