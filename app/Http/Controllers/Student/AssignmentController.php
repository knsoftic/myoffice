<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesTheSignedInStudent;
use App\Http\Requests\Student\SubmitAssignmentRequest;
use App\Models\Institute\Assignment;
use App\Models\Institute\AssignmentSubmission;
use App\Models\Institute\AssignmentSubmissionFile;
use App\Models\Institute\Student;
use App\Models\Institute\StudentBatchEnrollment;
use App\Services\Institute\AssignmentService;
use App\Services\Institute\AssignmentSubmissionService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A student's own assignments — `student.assignments.*` / `student.submissions.*`
 * (§74, §80, phase-19-23 §7.9, §8.9).
 *
 * **Every row is reached through the student's own enrollments**, so another student's assignment or
 * submission is a **404**. A 403 would confirm the id exists, which is half of what somebody probing
 * ids wanted to know.
 *
 * **A student sees a `closed` assignment**, and that is deliberate (§2.28.2). Closed stops collection;
 * it does not hide the brief, the deadline or the mark they were given. Only `archived` does that.
 *
 * **Marks are gated three ways**, all of which must hold: the row is graded, the assignment is willing
 * to show marks, and this row has been released. `AssignmentSubmission::marksVisibleToStudent()` is the
 * one place that asks, so the list, the detail page and the feedback download cannot disagree.
 */
final class AssignmentController extends Controller
{
    use ResolvesTheSignedInStudent;

    public function __construct(
        private readonly AssignmentSubmissionService $submissions,
        private readonly AssignmentService $assignments,
    ) {}

    public function index(Request $request): View
    {
        $student = $this->student($request);

        $assignments = $this->reachable($student)
            ->with(['course:id,name', 'batch:id,code,name'])
            ->when($request->string('status')->toString() !== '', fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->orderByDesc('deadline_at')
            ->paginate(20)
            ->withQueryString();

        return view('student.assignments.index', [
            'assignments' => $assignments,
            // Keyed by assignment so the list can show "handed in" / "marked" without N queries.
            'mine' => $this->liveSubmissions($student, $assignments->pluck('id')->all()),
            'canSubmit' => (bool) $request->user()?->can('student_portal.assignment_submit'),
        ]);
    }

    public function show(Request $request, Assignment $assignment): View
    {
        $student = $this->student($request);
        $this->assertReachable($student, $assignment);

        $live = $assignment->liveSubmissions()->where('student_id', $student->getKey())->first();

        return view('student.assignments.show', [
            'assignment' => $assignment->load(['course:id,name', 'batch:id,code,name']),
            'submission' => $live?->load('files'),
            'attempts' => AssignmentSubmission::query()
                ->where('assignment_id', $assignment->getKey())
                ->where('student_id', $student->getKey())
                ->orderBy('attempt_no')
                ->get(),
            'acceptsWork' => $assignment->acceptsSubmissionAt(now()),
            'marksVisible' => $live?->marksVisibleToStudent() ?? false,
            'canSubmit' => (bool) $request->user()?->can('student_portal.assignment_submit'),
        ]);
    }

    public function brief(Request $request, Assignment $assignment): StreamedResponse
    {
        $this->assertReachable($this->student($request), $assignment);

        return $this->assignments->streamBrief($assignment);
    }

    /** Start a draft. Returns the existing one when there already is one — this is not a second attempt. */
    public function start(Request $request, Assignment $assignment): RedirectResponse
    {
        $student = $this->student($request);
        $this->assertReachable($student, $assignment);

        $this->submissions->draft($assignment, $student, $request->user());

        return redirect()->route('student.assignments.show', $assignment);
    }

    public function submit(SubmitAssignmentRequest $request, AssignmentSubmission $submission): RedirectResponse
    {
        $this->assertMine($this->student($request), $submission);

        $this->submissions->submit(
            $submission,
            $request->validated(),
            array_values($request->file('files') ?? []),
            $request->user(),
        );

        return redirect()
            ->route('student.assignments.show', $submission->getAttribute('assignment_id'))
            ->with('toast', ['type' => 'success', 'message' => 'Handed in.']);
    }

    /** A fresh attempt, which supersedes the current one rather than replacing it (INV-19-5). */
    public function resubmit(SubmitAssignmentRequest $request, Assignment $assignment): RedirectResponse
    {
        $student = $this->student($request);
        $this->assertReachable($student, $assignment);

        $live = $assignment->liveSubmissions()->where('student_id', $student->getKey())->first();

        abort_unless($live instanceof AssignmentSubmission, Response::HTTP_NOT_FOUND);

        $this->submissions->resubmit(
            $live,
            $request->validated(),
            array_values($request->file('files') ?? []),
            $request->user(),
        );

        return redirect()
            ->route('student.assignments.show', $assignment)
            ->with('toast', ['type' => 'success', 'message' => 'New attempt handed in. Your previous one is kept.']);
    }

    /** Taking back a draft — the only deletion this phase permits, and only of work never handed in. */
    public function withdraw(Request $request, AssignmentSubmission $submission): RedirectResponse
    {
        $this->assertMine($this->student($request), $submission);

        $assignmentId = $submission->getAttribute('assignment_id');
        $this->submissions->withdraw($submission, $request->user());

        return redirect()
            ->route('student.assignments.show', $assignmentId)
            ->with('toast', ['type' => 'success', 'message' => 'Draft discarded.']);
    }

    public function file(Request $request, AssignmentSubmission $submission, AssignmentSubmissionFile $file): StreamedResponse
    {
        $this->assertMine($this->student($request), $submission);

        abort_unless(
            (int) $file->getAttribute('assignment_submission_id') === (int) $submission->getKey(),
            Response::HTTP_NOT_FOUND,
        );

        return $this->submissions->streamFile($file);
    }

    /** The teacher's feedback file — **only after `marks_released_at`**, which the service re-checks. */
    public function feedback(Request $request, AssignmentSubmission $submission): StreamedResponse
    {
        $this->assertMine($this->student($request), $submission);

        return $this->submissions->streamFeedbackFile($submission, $request->user());
    }

    // -------------------------------------------------------------------------------------------

    /**
     * Assignments on batches this student is enrolled on, in a status students may see.
     *
     * Deliberately not narrowed to *active* enrollments: a student who has completed the batch still
     * has a right to the work they were marked on.
     */
    private function reachable(Student $student): Builder
    {
        $batchIds = StudentBatchEnrollment::query()
            ->where('student_id', $student->getKey())
            ->pluck('batch_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return Assignment::query()
            ->visibleToStudents()
            ->whereIn('batch_id', $batchIds === [] ? [0] : $batchIds);
    }

    private function assertReachable(Student $student, Assignment $assignment): void
    {
        abort_unless(
            $this->reachable($student)->whereKey($assignment->getKey())->exists(),
            Response::HTTP_NOT_FOUND,
        );
    }

    private function assertMine(Student $student, AssignmentSubmission $submission): void
    {
        abort_unless(
            (int) $submission->getAttribute('student_id') === (int) $student->getKey(),
            Response::HTTP_NOT_FOUND,
        );
    }

    /**
     * @param  list<int>  $assignmentIds
     * @return Collection<int, AssignmentSubmission>
     */
    private function liveSubmissions(Student $student, array $assignmentIds)
    {
        if ($assignmentIds === []) {
            return collect();
        }

        return AssignmentSubmission::query()
            ->where('student_id', $student->getKey())
            ->whereIn('assignment_id', $assignmentIds)
            ->whereNotNull('current_guard')
            ->get()
            ->keyBy('assignment_id');
    }
}
