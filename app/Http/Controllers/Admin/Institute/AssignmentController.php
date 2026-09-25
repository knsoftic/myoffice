<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\AssignmentStatus;
use App\Enums\SubmissionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Institute\StoreAssignmentRequest;
use App\Models\Branch;
use App\Models\Institute\Assignment;
use App\Models\Institute\Batch;
use App\Models\Institute\Course;
use App\Models\Institute\Teacher;
use App\Services\Institute\AssignmentService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Setting work — `admin.assignments.*` (§80, phase-19-23 §7.2, §8.4–8.5).
 *
 * **The three frozen columns are passed to `amend()`, not to `update()`, once anything is graded.**
 * The controller decides which of the two to call by asking whether the request carries any of them and
 * whether the assignment has graded work; the model refuses the wrong one regardless, so a mistake here
 * is a clear exception rather than a silent rewrite of what a class was marked out of.
 */
final class AssignmentController extends Controller
{
    /** The columns that freeze once anything has been graded (§2.6). */
    private const FROZEN = ['total_marks', 'deadline_at', 'submission_type'];

    public function __construct(
        private readonly AssignmentService $assignments,
    ) {}

    public function index(Request $request): View
    {
        $assignments = $this->filtered($request)
            ->with(['course:id,name', 'batch:id,code,name', 'teacher:id,employee_id'])
            ->orderByDesc('deadline_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.assignments.index', [
            'assignments' => $assignments,
            // 500, not every row: **a filter `<select>` over a table that grows every term is a page
            // that grows for ever** (phase-24-25 section 6.4, PRF-05). Same ceiling as the finance
            // pickers. Branches are a small reference table and load whole.
            'courses' => Course::query()->orderBy('name')->limit(500)->get(['id', 'name']),
            'batches' => Batch::query()->orderByDesc('id')->limit(500)->get(['id', 'code', 'name']),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => AssignmentStatus::cases(),
            'counts' => $this->statusCounts($request),
            'canCreate' => (bool) $request->user()?->can('create', Assignment::class),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Assignment::class);

        return view('admin.assignments.create', $this->formData());
    }

    public function store(StoreAssignmentRequest $request): RedirectResponse
    {
        $assignment = $this->assignments->create(
            $request->safe()->except(['brief', 'reason']),
            $request->file('brief'),
            $request->user(),
        );

        return redirect()
            ->route('admin.assignments.show', $assignment)
            ->with('toast', [
                'type' => 'success',
                'message' => 'Assignment saved as a draft. Publish it when the batch should see it.',
            ]);
    }

    public function show(Request $request, Assignment $assignment): View
    {
        Gate::authorize('view', $assignment);

        return view('admin.assignments.show', [
            'assignment' => $assignment->load(['course:id,name', 'batch:id,code,name', 'teacher:id,employee_id', 'topic:id,title']),
            'stats' => $this->assignments->statistics($assignment),
            'canEdit' => (bool) $request->user()?->can('update', $assignment),
            'canPublish' => (bool) $request->user()?->can('changeStatus', $assignment),
            'canDelete' => (bool) $request->user()?->can('delete', $assignment),
            'canPrint' => (bool) $request->user()?->can('print', $assignment),
            'canDownloadBrief' => (bool) $request->user()?->can('download', $assignment),
            'hasGradedWork' => $assignment->hasGradedWork(),
        ]);
    }

    public function edit(Request $request, Assignment $assignment): View
    {
        Gate::authorize('update', $assignment);

        return view('admin.assignments.edit', array_merge($this->formData(), [
            'assignment' => $assignment,
            // The screen greys the three out and asks for a reason instead of pretending they are
            // editable and failing on save.
            'frozen' => $assignment->hasGradedWork() ? self::FROZEN : [],
        ]));
    }

    public function update(StoreAssignmentRequest $request, Assignment $assignment): RedirectResponse
    {
        $data = $request->safe()->except(['brief', 'reason']);
        $frozen = array_intersect_key($data, array_flip(self::FROZEN));

        // Ordinary edits go through `update()`; a change to one of the three after marking has started
        // goes through `amend()`, which insists on a reason and logs old and new.
        if ($frozen !== [] && $assignment->hasGradedWork()) {
            $this->assignments->amend($assignment, $frozen, (string) $request->input('reason', ''), $request->user());
        }

        $this->assignments->update($assignment, array_diff_key($data, array_flip(self::FROZEN)), $request->user());

        if ($request->hasFile('brief')) {
            $assignment->getAttribute('attachment_path') === null
                ? $this->assignments->attachBrief($assignment, $request->file('brief'))
                : $this->assignments->replaceBrief($assignment, $request->file('brief'), $request->user());
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Assignment updated.']);
    }

    public function status(Request $request, Assignment $assignment): RedirectResponse
    {
        Gate::authorize('changeStatus', $assignment);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:published,closed,archived'],
            'reason' => ['nullable', 'string', 'min:5', 'max:255'],
        ]);

        $reason = (string) ($validated['reason'] ?? '');
        $actor = $request->user();

        $assignment = match ($validated['status']) {
            'published' => $assignment->status === AssignmentStatus::Closed
                ? $this->assignments->reopen($assignment, $reason, $actor)
                : $this->assignments->publish($assignment, $actor),
            'closed' => $this->assignments->close($assignment, $actor),
            'archived' => $this->assignments->archive($assignment, $reason, $actor),
        };

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Assignment is now '.$assignment->status->label().'.',
        ]);
    }

    /**
     * The same work for several batches. **The brief is referenced, not copied**, so one file serves
     * every duplicate — which is why nothing in this phase deletes bytes when one assignment goes.
     */
    public function duplicate(Request $request, Assignment $assignment): RedirectResponse
    {
        Gate::authorize('create', Assignment::class);

        $validated = $request->validate([
            'batch_ids' => ['required', 'array', 'min:1', 'max:20'],
            'batch_ids.*' => ['integer', 'exists:batches,id'],
            'deadlines' => ['nullable', 'array'],
            'deadlines.*' => ['nullable', 'date'],
        ]);

        $made = $this->assignments->duplicateToBatches(
            $assignment,
            array_map('intval', $validated['batch_ids']),
            $validated['deadlines'] ?? [],
            $request->user(),
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => $made->count().' draft '.($made->count() === 1 ? 'copy' : 'copies')
                .' created. The brief is shared, not duplicated.',
        ]);
    }

    public function downloadBrief(Request $request, Assignment $assignment): StreamedResponse
    {
        Gate::authorize('download', $assignment);

        return $this->assignments->streamBrief($assignment);
    }

    public function print(Request $request, Assignment $assignment): View
    {
        Gate::authorize('print', $assignment);

        return view('admin.assignments.print', [
            'assignment' => $assignment->load(['course:id,name', 'batch:id,code,name', 'teacher:id,employee_id']),
        ]);
    }

    public function destroy(Request $request, Assignment $assignment): RedirectResponse
    {
        Gate::authorize('delete', $assignment);

        $assignment->delete();

        return redirect()
            ->route('admin.assignments.index')
            ->with('toast', ['type' => 'success', 'message' => 'Assignment removed.']);
    }

    // -------------------------------------------------------------------------------------------

    private function filtered(Request $request): Builder
    {
        return Assignment::query()
            ->when($request->string('q')->toString() !== '', function (Builder $q) use ($request): void {
                $q->where('title', 'like', '%'.$request->string('q')->toString().'%');
            })
            ->when($request->integer('course_id') > 0, fn (Builder $q) => $q->where('course_id', $request->integer('course_id')))
            ->when($request->integer('batch_id') > 0, fn (Builder $q) => $q->where('batch_id', $request->integer('batch_id')))
            ->when($request->integer('branch_id') > 0, fn (Builder $q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->string('status')->toString() !== '', fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->boolean('overdue'), fn (Builder $q) => $q->overdueBy(now()))
            ->when($request->boolean('trashed'), fn (Builder $q) => $q->onlyTrashed());
    }

    /**
     * One grouped count for the filter cards, not one count per case.
     *
     * **Four `count(*)`s that differ only in the status they test are a loop, not four screens' worth
     * of work**: the per-case version ran the same statement once per `AssignmentStatus` case and
     * PRF-02's sweep saw it four times on one request (phase-24-25 section 11.7). Every case is still
     * keyed, zero included, because a card that vanished at zero would change the row's shape and
     * "no drafts" is information.
     *
     * @return array<string, int>
     */
    private function statusCounts(Request $request): array
    {
        $counts = $this->filtered($request)
            ->toBase()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $out = [];

        foreach (AssignmentStatus::cases() as $status) {
            $out[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'batches' => Batch::query()->with('course:id,name')->orderByDesc('id')->get(['id', 'code', 'name', 'course_id']),
            'teachers' => Teacher::query()->orderBy('id')->get(['id', 'employee_id']),
            'submissionTypes' => SubmissionType::cases(),
        ];
    }
}
