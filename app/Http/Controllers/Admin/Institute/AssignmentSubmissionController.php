<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\SubmissionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Institute\GradeSubmissionRequest;
use App\Models\Institute\Assignment;
use App\Models\Institute\AssignmentSubmission;
use App\Models\Institute\AssignmentSubmissionFile;
use App\Models\Institute\Student;
use App\Services\Institute\AssignmentService;
use App\Services\Institute\AssignmentSubmissionService;
use App\Support\CsvWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Marking — `admin.assignment-submissions.*` (§80, phase-19-23 §7.2, §8.6–8.7).
 *
 * **Grading is its own module, and this controller is why that matters.** Everything here is gated by
 * `assignment_submissions.*` rather than `assignments.*`, so a coordinator can be given marking without
 * being given the right to publish new work — and a visiting trainer the reverse.
 *
 * **Nothing here deletes.** The policy returns false for `delete` and `forceDelete` for every role and
 * the model refuses the act even for a Super Admin; there is no destroy action to call.
 */
final class AssignmentSubmissionController extends Controller
{
    public function __construct(
        private readonly AssignmentSubmissionService $submissions,
        private readonly AssignmentService $assignments,
    ) {}

    /** The grading grid for one assignment (§8.7). */
    public function index(Request $request, Assignment $assignment): View
    {
        Gate::authorize('viewAny', AssignmentSubmission::class);
        Gate::authorize('view', $assignment);

        $rows = $assignment->submissions()
            ->with(['student:id,name,student_code', 'grader:id,name'])
            ->when(
                $request->string('status')->toString() !== '',
                fn ($q) => $q->where('status', $request->string('status')->toString()),
                // The default view is the live attempts: a superseded row is history, and showing it
                // beside its successor is how a teacher marks the wrong one.
                fn ($q) => $q->whereNotNull('current_guard'),
            )
            ->orderByDesc('submitted_at')
            ->paginate(30)
            ->withQueryString();

        return view('admin.assignment-submissions.index', [
            'assignment' => $assignment->load(['course:id,name', 'batch:id,code,name']),
            'submissions' => $rows,
            'stats' => $this->assignments->statistics($assignment),
            'statuses' => SubmissionStatus::cases(),
            'canGrade' => (bool) $request->user()?->can('update', $assignment->submissions()->getModel()),
            'canRelease' => (bool) $request->user()?->can('changeStatus', $assignment->submissions()->getModel()),
        ]);
    }

    public function show(Request $request, AssignmentSubmission $submission): View
    {
        Gate::authorize('view', $submission);

        return view('admin.assignment-submissions.show', [
            'submission' => $submission->load(['assignment:id,title,total_marks,passing_marks,batch_id', 'student:id,name,student_code', 'files', 'grader:id,name', 'amender:id,name']),
            // The whole chain, so "which attempt was this?" is answerable on the page rather than
            // through a query somebody has to write.
            'attempts' => AssignmentSubmission::query()
                ->where('assignment_id', $submission->getAttribute('assignment_id'))
                ->where('student_id', $submission->getAttribute('student_id'))
                ->orderBy('attempt_no')
                ->get(),
            'duplicateChecksums' => $this->duplicateChecksums($submission),
            'canGrade' => (bool) $request->user()?->can('update', $submission),
            'canAmend' => (bool) $request->user()?->can('amend', $submission),
            'canDownload' => (bool) $request->user()?->can('download', $submission),
        ]);
    }

    public function grade(GradeSubmissionRequest $request, AssignmentSubmission $submission): RedirectResponse
    {
        $released = $submission->getAttribute('marks_released_at') !== null;

        // A mark the student has already seen is amended, not re-graded: the difference is a reason
        // and an activity row carrying old and new.
        $submission = $released
            ? $this->submissions->amend($submission, $request->validated(), (string) $request->input('reason', ''), $request->user())
            : $this->submissions->grade($submission, $request->validated(), $request->user(), $request->file('feedback_file'));

        return back()->with('toast', [
            'type' => 'success',
            'message' => $released ? 'Mark amended, and the change is recorded.' : 'Marked.',
        ]);
    }

    public function returnForRework(Request $request, AssignmentSubmission $submission): RedirectResponse
    {
        Gate::authorize('update', $submission);

        $validated = $request->validate([
            'feedback' => ['required', 'string', 'min:5', 'max:5000'],
        ]);

        $this->submissions->returnForRework($submission, $validated['feedback'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Returned to the student for rework.']);
    }

    public function amend(GradeSubmissionRequest $request, AssignmentSubmission $submission): RedirectResponse
    {
        Gate::authorize('amend', $submission);

        $this->submissions->amend(
            $submission,
            $request->validated(),
            (string) $request->input('reason', ''),
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Mark amended, and the change is recorded.']);
    }

    /** The grid, saved in one go. All of it or none of it (§6.8). */
    public function gradeBulk(Request $request, Assignment $assignment): RedirectResponse
    {
        Gate::authorize('update', $assignment->submissions()->getModel());

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

    /** For a teacher who marked the batch privately and wants the class to see it together. */
    public function release(Request $request, Assignment $assignment): RedirectResponse
    {
        Gate::authorize('changeStatus', $assignment->submissions()->getModel());

        $count = $this->submissions->releaseMarks($assignment, $request->user());

        return back()->with('toast', [
            'type' => $count > 0 ? 'success' : 'info',
            'message' => $count > 0
                ? $count.' '.($count === 1 ? 'mark is' : 'marks are').' now visible to students.'
                : 'There was nothing waiting to be released.',
        ]);
    }

    public function markMissed(Request $request, Assignment $assignment): RedirectResponse
    {
        Gate::authorize('changeStatus', $assignment->submissions()->getModel());

        $count = $this->submissions->markMissed($assignment);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $count > 0
                ? $count.' '.($count === 1 ? 'student was' : 'students were').' recorded as having missed this.'
                : 'Everybody on the roster has handed something in.',
        ]);
    }

    /** Recording work a student handed in on paper (§4.1 — the only reason `create` exists). */
    public function store(Request $request, Assignment $assignment): RedirectResponse
    {
        Gate::authorize('create', AssignmentSubmission::class);

        $validated = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'submission_text' => ['nullable', 'string', 'max:50000'],
        ]);

        $student = Student::query()->findOrFail($validated['student_id']);
        $draft = $this->submissions->draft($assignment, $student, $request->user());

        $this->submissions->submit($draft, $validated, [], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Offline submission recorded.']);
    }

    public function downloadFile(Request $request, AssignmentSubmission $submission, AssignmentSubmissionFile $file): StreamedResponse
    {
        Gate::authorize('download', $submission);

        // A file id from another submission is a 404, never a 403.
        abort_unless(
            (int) $file->getAttribute('assignment_submission_id') === (int) $submission->getKey(),
            Response::HTTP_NOT_FOUND,
        );

        return $this->submissions->streamFile($file);
    }

    public function downloadFeedback(Request $request, AssignmentSubmission $submission): StreamedResponse
    {
        Gate::authorize('download', $submission);

        return $this->submissions->streamFeedbackFile($submission, $request->user());
    }

    public function export(Request $request, Assignment $assignment, string $format): StreamedResponse
    {
        Gate::authorize('export', AssignmentSubmission::class);

        abort_unless($format === 'csv', Response::HTTP_NOT_FOUND);

        return (new CsvWriter)->download(
            'submissions-'.$assignment->getKey().'-'.app_date(now(), 'Y-m-d').'.csv',
            ['Student', 'Roll', 'Attempt', 'Status', 'Submitted', 'Late', 'Minutes late', 'Marks', 'Penalty', 'Final', 'Percentage', 'Passed', 'Marked by'],
            CsvWriter::rowsFrom(
                $assignment->submissions()->with(['student:id,name,student_code', 'grader:id,name']),
                static fn (AssignmentSubmission $s): array => [
                    (string) $s->student?->getAttribute('name'),
                    (string) $s->student?->getAttribute('student_code'),
                    (string) $s->getAttribute('attempt_no'),
                    $s->status->label(),
                    $s->getAttribute('submitted_at') ? app_datetime($s->getAttribute('submitted_at')) : '',
                    $s->getAttribute('is_late') ? 'Yes' : 'No',
                    (string) ($s->getAttribute('minutes_late') ?? ''),
                    (string) ($s->getAttribute('obtained_marks') ?? ''),
                    (string) $s->getAttribute('penalty_marks'),
                    (string) ($s->getAttribute('final_marks') ?? ''),
                    (string) ($s->getAttribute('percentage') ?? ''),
                    $s->getAttribute('is_passed') === null ? '' : ($s->getAttribute('is_passed') ? 'Yes' : 'No'),
                    (string) $s->grader?->getAttribute('name'),
                ],
            ),
        );
    }

    // -------------------------------------------------------------------------------------------

    /**
     * Checksums this submission shares with somebody else's on the same assignment.
     *
     * §2.8 is emphatic about what this is: a **warning on the grading screen**, never an accusation and
     * never a block. Two students handing in the same provided template is the ordinary case, and a
     * system that refused it would be wrong far more often than right.
     *
     * @return list<string>
     */
    private function duplicateChecksums(AssignmentSubmission $submission): array
    {
        $mine = $submission->files()->whereNotNull('checksum_sha256')->pluck('checksum_sha256')->all();

        if ($mine === []) {
            return [];
        }

        return AssignmentSubmissionFile::query()
            ->whereIn('checksum_sha256', $mine)
            ->whereHas('submission', static fn ($q) => $q
                ->where('assignment_id', $submission->getAttribute('assignment_id'))
                ->whereKeyNot($submission->getKey()))
            ->pluck('checksum_sha256')
            ->unique()
            ->values()
            ->map(static fn (mixed $c): string => (string) $c)
            ->all();
    }
}
