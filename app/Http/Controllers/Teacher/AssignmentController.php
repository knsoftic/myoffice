<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Enums\AssignmentStatus;
use App\Enums\SubmissionType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teacher\Concerns\ResolvesTheSignedInTeacher;
use App\Http\Requests\Admin\Institute\StoreAssignmentRequest;
use App\Models\Institute\Assignment;
use App\Models\Institute\Batch;
use App\Services\Institute\AssignmentService;
use App\Support\Institute\TeacherScope;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A teacher setting work for their own batches — `teacher.assignments.*`
 * (§73, §80, phase-19-23 §7.10).
 *
 * **The batch is validated against `TeacherScope`, never taken from the form.** A batch id somebody
 * else's class owns is a 404, and the picker only ever offers batches this teacher reaches.
 *
 * Marking lives in `SubmissionController` under `teacher_portal.assignment_grade`, which is a separate
 * right — a teaching assistant commonly holds one of the two and not the other.
 */
final class AssignmentController extends Controller
{
    use ResolvesTheSignedInTeacher;

    public function __construct(
        private readonly AssignmentService $assignments,
    ) {}

    public function index(Request $request): View
    {
        $assignments = $this->mine($request)
            ->with(['course:id,name', 'batch:id,code,name'])
            ->when($request->string('status')->toString() !== '', fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->orderByDesc('deadline_at')
            ->paginate(20)
            ->withQueryString();

        return view('teacher.assignments.index', [
            'assignments' => $assignments,
            'statuses' => AssignmentStatus::cases(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('teacher.assignments.create', $this->formData($request));
    }

    public function store(StoreAssignmentRequest $request): RedirectResponse
    {
        $teacher = $this->teacher($request);
        $batchId = (int) $request->input('batch_id');

        // The form's batch is checked against the scope, not trusted.
        abort_unless(in_array($batchId, TeacherScope::batchIds($teacher), true), Response::HTTP_NOT_FOUND);

        $assignment = $this->assignments->create(
            array_merge($request->safe()->except(['brief', 'reason']), [
                'teacher_id' => $teacher->getKey(),
            ]),
            $request->file('brief'),
            $request->user(),
        );

        return redirect()
            ->route('teacher.assignments.index')
            ->with('toast', [
                'type' => 'success',
                'message' => 'Saved as a draft. Publish it when the batch should see it.',
            ]);
    }

    public function update(StoreAssignmentRequest $request, Assignment $assignment): RedirectResponse
    {
        $this->assertMine($request, $assignment);

        $this->assignments->update(
            $assignment,
            $request->safe()->except(['brief', 'reason', 'batch_id', 'total_marks', 'deadline_at', 'submission_type']),
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Assignment updated.']);
    }

    public function status(Request $request, Assignment $assignment): RedirectResponse
    {
        $this->assertMine($request, $assignment);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:published,closed'],
            'reason' => ['nullable', 'string', 'min:5', 'max:255'],
        ]);

        $actor = $request->user();

        $assignment = match ($validated['status']) {
            'published' => $assignment->status === AssignmentStatus::Closed
                ? $this->assignments->reopen($assignment, (string) ($validated['reason'] ?? 'Reopened by the teacher'), $actor)
                : $this->assignments->publish($assignment, $actor),
            'closed' => $this->assignments->close($assignment, $actor),
        };

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Assignment is now '.$assignment->status->label().'.',
        ]);
    }

    // -------------------------------------------------------------------------------------------

    private function mine(Request $request): Builder
    {
        $batchIds = TeacherScope::batchIds($this->teacher($request));

        return Assignment::query()->whereIn('batch_id', $batchIds === [] ? [0] : $batchIds);
    }

    private function assertMine(Request $request, Assignment $assignment): void
    {
        abort_unless(
            in_array((int) $assignment->getAttribute('batch_id'), TeacherScope::batchIds($this->teacher($request)), true),
            Response::HTTP_NOT_FOUND,
        );
    }

    /** @return array<string, mixed> */
    private function formData(Request $request): array
    {
        return [
            'batches' => Batch::query()
                ->whereIn('id', TeacherScope::batchIds($this->teacher($request)))
                ->with('course:id,name')
                ->get(['id', 'code', 'name', 'course_id']),
            'submissionTypes' => SubmissionType::cases(),
        ];
    }
}
