<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\ExamStatus;
use App\Enums\ExamType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Institute\StoreExamRequest;
use App\Models\Branch;
use App\Models\Institute\Batch;
use App\Models\Institute\Classroom;
use App\Models\Institute\Course;
use App\Models\Institute\Exam;
use App\Models\Institute\ExamResult;
use App\Models\Institute\GradeScale;
use App\Models\Institute\Teacher;
use App\Services\Institute\ExamService;
use App\Support\CsvWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The exam calendar and its papers — `admin.exams.*` (§81, phase-19-23 §7.3).
 *
 * Nothing here decides anything: `ExamService` owns the clash check, the status ladder and the caches.
 * The controller picks which service call the request meant and hands back a toast.
 *
 * **Cancelling and rescheduling take a reason**, and the service refuses without one — so the screen
 * offers the field rather than the controller inventing a default. A reason nobody typed is worse than
 * no reason at all, because it looks like one.
 */
final class ExamController extends Controller
{
    public function __construct(
        private readonly ExamService $exams,
    ) {}

    public function index(Request $request): View
    {
        $exams = $this->filtered($request)
            ->with(['course:id,name', 'batch:id,code,name', 'teacher:id,name', 'classroom:id,code'])
            ->orderByDesc('scheduled_date')
            ->paginate(20)
            ->withQueryString();

        return view('admin.exams.index', [
            'exams' => $exams,
            // 500, not every row: **a filter `<select>` over a table that grows every term is a page
            // that grows for ever** (phase-24-25 section 6.4, PRF-05). Same ceiling as the finance
            // pickers. Branches are a small reference table and load whole.
            'courses' => Course::query()->orderBy('name')->limit(500)->get(['id', 'name']),
            'batches' => Batch::query()->orderByDesc('id')->limit(500)->get(['id', 'code', 'name']),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => ExamStatus::cases(),
            'types' => ExamType::cases(),
            'counts' => $this->statusCounts($request),
            'canCreate' => (bool) $request->user()?->can('create', Exam::class),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Exam::class);

        return view('admin.exams.create', $this->formData());
    }

    public function store(StoreExamRequest $request): RedirectResponse
    {
        $exam = $this->exams->create($request->safe()->except(['reason']), $request->user());

        return redirect()
            ->route('admin.exams.show', $exam)
            ->with('toast', [
                'type' => 'success',
                'message' => 'Saved as a draft. Schedule it when the batch should be told.',
            ]);
    }

    public function show(Request $request, Exam $exam): View
    {
        Gate::authorize('view', $exam);

        return view('admin.exams.show', [
            'exam' => $exam->load(['course:id,name', 'batch:id,code,name', 'teacher:id,name', 'classroom:id,code,name', 'topic:id,title', 'scale:id,code,name', 'publisher:id,name', 'verifier:id,name']),
            'scale' => $this->resolvedScale($exam),
            'canEdit' => (bool) $request->user()?->can('update', $exam),
            'canChangeStatus' => (bool) $request->user()?->can('changeStatus', $exam),
            'canDelete' => (bool) $request->user()?->can('delete', $exam),
            'canEnterResults' => (bool) $request->user()?->can('create', ExamResult::class),
            // The reschedule panel needs somewhere to move it to.
            'classrooms' => Classroom::query()->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function edit(Request $request, Exam $exam): View
    {
        Gate::authorize('update', $exam);

        return view('admin.exams.edit', array_merge($this->formData($exam), [
            'exam' => $exam,
            // The screen greys these out rather than pretending they are editable and failing on save.
            'frozen' => $exam->isPublished()
                ? ['total_marks', 'passing_marks', 'grade_scale_id', 'scheduled_date']
                : [],
        ]));
    }

    public function update(StoreExamRequest $request, Exam $exam): RedirectResponse
    {
        $this->exams->update($exam, $request->safe()->except(['reason', 'batch_id']), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Exam updated.']);
    }

    /**
     * The status ladder of §2.28.4. Which service call a status maps to is decided here because the
     * service exposes them as separate, differently-guarded methods: `cancel()` demands a reason,
     * `markConducted()` opens the sheet, and `schedule()` re-runs the clash check. Collapsing them
     * into one handler would lose all three distinctions.
     *
     * (Creating and rescheduling clash-check too — every path that gives an exam a time does. The
     * point here is that the *guards differ*, not that only one of them has any.)
     */
    public function status(Request $request, Exam $exam): RedirectResponse
    {
        Gate::authorize('changeStatus', $exam);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:scheduled,ongoing,conducted,cancelled'],
            'reason' => ['nullable', 'string', 'min:5', 'max:255'],
        ]);

        $actor = $request->user();
        $reason = (string) ($validated['reason'] ?? '');

        $exam = match ($validated['status']) {
            'scheduled' => $this->exams->schedule($exam, $actor),
            'ongoing' => $this->exams->markOngoing($exam, $actor),
            'conducted' => $this->exams->markConducted($exam, $actor),
            'cancelled' => $this->exams->cancel($exam, $reason, $actor),
        };

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Exam is now '.mb_strtolower($exam->status->label()).'.',
        ]);
    }

    public function reschedule(StoreExamRequest $request, Exam $exam): RedirectResponse
    {
        Gate::authorize('changeStatus', $exam);

        $this->exams->reschedule(
            $exam,
            $request->safe()->only(['scheduled_date', 'start_time', 'end_time', 'classroom_id']),
            (string) $request->input('reason', ''),
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Exam moved, and the change is recorded.']);
    }

    public function destroy(Request $request, Exam $exam): RedirectResponse
    {
        Gate::authorize('delete', $exam);

        $exam->delete();

        return redirect()
            ->route('admin.exams.index')
            ->with('toast', ['type' => 'success', 'message' => 'Exam removed.']);
    }

    public function export(Request $request, string $format): StreamedResponse
    {
        Gate::authorize('export', Exam::class);

        abort_unless($format === 'csv', Response::HTTP_NOT_FOUND);

        return (new CsvWriter)->download(
            'exams-'.app_date(now(), 'Y-m-d').'.csv',
            ['Name', 'Kind', 'Course', 'Batch', 'Date', 'Out of', 'Pass mark', 'Status', 'Sat', 'Passed', 'Average'],
            CsvWriter::rowsFrom(
                $this->filtered($request)->with(['course:id,name', 'batch:id,code']),
                static fn (Exam $e): array => [
                    (string) $e->getAttribute('name'),
                    $e->exam_type->label(),
                    (string) $e->course?->getAttribute('name'),
                    (string) $e->batch?->getAttribute('code'),
                    app_date($e->getAttribute('scheduled_date')),
                    (string) $e->getAttribute('total_marks'),
                    (string) $e->getAttribute('passing_marks'),
                    $e->status->label(),
                    (string) $e->getAttribute('appeared_count'),
                    (string) $e->getAttribute('passed_count'),
                    (string) ($e->getAttribute('average_marks') ?? ''),
                ],
            ),
        );
    }

    // -------------------------------------------------------------------------------------------

    /**
     * The one filtered query the index, the counts and the export all read, so a figure at the top of
     * the page cannot disagree with the rows underneath it.
     */
    private function filtered(Request $request): Builder
    {
        return Exam::query()
            ->when($request->string('q')->toString() !== '', function (Builder $q) use ($request): void {
                $q->where('name', 'like', '%'.$request->string('q')->toString().'%');
            })
            ->when($request->integer('course_id') > 0, fn (Builder $q) => $q->where('course_id', $request->integer('course_id')))
            ->when($request->integer('batch_id') > 0, fn (Builder $q) => $q->where('batch_id', $request->integer('batch_id')))
            ->when($request->integer('branch_id') > 0, fn (Builder $q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->string('status')->toString() !== '', fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->string('exam_type')->toString() !== '', fn (Builder $q) => $q->where('exam_type', $request->string('exam_type')->toString()))
            ->when($request->boolean('upcoming'), fn (Builder $q) => $q->where('scheduled_date', '>=', now()->toDateString()))
            ->when($request->boolean('trashed'), fn (Builder $q) => $q->onlyTrashed());
    }

    /**
     * One grouped count for the filter cards, not one count per case.
     *
     * **Seven `count(*)`s that differ only in the status they test are a loop, not seven screens' worth
     * of work**: the per-case version ran the same statement once per `ExamStatus` case and PRF-02's
     * sweep saw it seven times on one request (phase-24-25 section 11.7). Every case is still keyed,
     * zero included, so the card row keeps its shape when a status is empty.
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

        foreach (ExamStatus::cases() as $status) {
            $out[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $out;
    }

    /**
     * The scale this exam will actually grade against — resolved rather than read off the row, because
     * a null `grade_scale_id` means "the institute's default" and the screen should say which that is.
     * A misconfiguration shows as null here instead of throwing on a page nobody is grading from.
     */
    private function resolvedScale(Exam $exam): ?GradeScale
    {
        try {
            return $this->exams->scaleFor($exam);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The dropdowns a create or edit form needs.
     *
     * **The teacher and scale lists include whoever this exam already names, active or not.** A
     * dropdown built only from `teaching()` would drop an examiner who has since left, and saving the
     * form would post no `teacher_id` at all — silently unassigning them while reporting success.
     * That is the D121/D131 shape: a save that quietly declines part of what it was given. The same
     * applies to a retired grade scale, which INV-20-4 keeps alive precisely so an old exam stays
     * explicable.
     *
     * @return array<string, mixed>
     */
    private function formData(?Exam $exam = null): array
    {
        return [
            'batches' => Batch::query()->with('course:id,name')->orderByDesc('id')->get(['id', 'code', 'name', 'course_id']),
            'teachers' => Teacher::query()
                ->where(function (Builder $query) use ($exam): void {
                    $query->teaching();

                    if ($exam?->getAttribute('teacher_id') !== null) {
                        $query->orWhere('id', $exam->getAttribute('teacher_id'));
                    }
                })
                ->orderBy('name')
                ->get(['id', 'name']),
            'classrooms' => Classroom::query()->orderBy('code')->get(['id', 'code', 'name']),
            'scales' => GradeScale::query()
                ->where(function (Builder $query) use ($exam): void {
                    $query->active();

                    if ($exam?->getAttribute('grade_scale_id') !== null) {
                        $query->orWhere('id', $exam->getAttribute('grade_scale_id'));
                    }
                })
                ->ordered()
                ->get(['id', 'code', 'name']),
            'types' => ExamType::cases(),
        ];
    }
}
