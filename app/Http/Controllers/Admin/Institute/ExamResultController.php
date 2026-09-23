<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\ExamAttendanceStatus;
use App\Enums\ExamStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Institute\SaveResultSheetRequest;
use App\Models\Institute\Batch;
use App\Models\Institute\Course;
use App\Models\Institute\Exam;
use App\Models\Institute\ExamResult;
use App\Services\Institute\ExamResultService;
use App\Support\CsvWriter;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Marking, checking and publishing — `admin.exam-results.*` (§81, phase-19-23 §7.3).
 *
 * **The sheet is the whole screen.** One GET builds the roster, one POST saves every row together, and
 * `SheetResult` decides whether that POST was a save or a list of complaints. There is no per-row
 * endpoint on purpose: a marker who can save one row at a time will leave a sheet half-entered, and
 * INV-20-6 says a sheet is all-or-nothing.
 *
 * **Verification and publishing are separate buttons because they are separate decisions**, taken by
 * (usually) different people. `ExamResultService` enforces the separation — a verifier who entered any
 * mark on the sheet is refused — and this controller only has to not collapse the two calls into one.
 */
final class ExamResultController extends Controller
{
    public function __construct(
        private readonly ExamResultService $results,
    ) {}

    /**
     * The marking board — every exam that has marks, needs them, or has had them published, with
     * where each one has got to.
     *
     * **Listed by exam rather than by result.** A page of thirty thousand individual marks answers no
     * question anybody has; "which sheets are still waiting to be checked" is the question, and its
     * unit is the paper. Individual rows live on the sheet behind each one.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', ExamResult::class);

        $exams = Exam::query()
            ->whereIn('status', [
                ExamStatus::Conducted->value,
                ExamStatus::Marking->value,
                ExamStatus::ResultsPublished->value,
            ])
            ->with(['course:id,name', 'batch:id,code,name', 'verifier:id,name', 'publisher:id,name'])
            ->when($request->string('q')->toString() !== '', fn (Builder $q) => $q->where('name', 'like', '%'.$request->string('q')->toString().'%'))
            ->when($request->integer('batch_id') > 0, fn (Builder $q) => $q->where('batch_id', $request->integer('batch_id')))
            ->when($request->integer('course_id') > 0, fn (Builder $q) => $q->where('course_id', $request->integer('course_id')))
            ->when($request->string('status')->toString() !== '', fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->orderByDesc('scheduled_date')
            ->paginate(20)
            ->withQueryString();

        return view('admin.exam-results.index', [
            'exams' => $exams,
            'courses' => Course::query()->orderBy('name')->get(['id', 'name']),
            'batches' => Batch::query()->orderByDesc('id')->get(['id', 'code', 'name']),
            // Three numbers over the whole board rather than the page, so a coordinator can see what
            // is outstanding without paging to the end to find out.
            'awaitingEntry' => Exam::query()->where('status', ExamStatus::Conducted->value)->count(),
            'awaitingCheck' => Exam::query()->where('status', ExamStatus::Marking->value)->whereNull('results_verified_at')->count(),
            'awaitingPublish' => Exam::query()->where('status', ExamStatus::Marking->value)->whereNotNull('results_verified_at')->count(),
        ]);
    }

    /**
     * The marking grid. `openSheet()` writes nothing, so opening this to look at it cannot move the
     * exam's status or stamp a row.
     */
    public function sheet(Request $request, Exam $exam): View
    {
        Gate::authorize('create', ExamResult::class);

        $sheet = $this->results->openSheet($exam);

        return view('admin.exam-results.sheet', [
            'exam' => $exam->load(['course:id,name', 'batch:id,code,name']),
            'scale' => $sheet['scale'],
            'rows' => $sheet['rows'],
            'readonly' => ! $exam->acceptsResultEntry(),
            'canVerify' => (bool) $request->user()?->can('verifyResults', $exam),
            'canPublish' => (bool) $request->user()?->can('publishResults', $exam),
        ]);
    }

    public function save(SaveResultSheetRequest $request, Exam $exam): RedirectResponse
    {
        $outcome = $this->results->saveSheet($exam, $request->validated('rows'), $request->user());

        if (! $outcome->succeeded()) {
            // Row-indexed messages go back as field errors so each one lands on the student who
            // caused it; a sheet of thirty with one bad mark should not need hunting.
            $bag = [];

            foreach ($outcome->errors as $index => $message) {
                $bag["rows.$index.obtained_marks"] = $message;
            }

            return back()
                ->withInput()
                ->withErrors($bag)
                ->with('toast', ['type' => 'error', 'message' => $outcome->summary()]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => $outcome->summary()]);
    }

    public function verify(Request $request, Exam $exam): RedirectResponse
    {
        Gate::authorize('verifyResults', $exam);

        $this->results->verify($exam, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Checked. The sheet can be published now.',
        ]);
    }

    public function publish(Request $request, Exam $exam): RedirectResponse
    {
        Gate::authorize('publishResults', $exam);

        $exam = $this->results->publish($exam, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf(
                'Published — %d student%s can see their result.',
                (int) $exam->getAttribute('appeared_count'),
                (int) $exam->getAttribute('appeared_count') === 1 ? '' : 's',
            ),
        ]);
    }

    public function unpublish(Request $request, Exam $exam): RedirectResponse
    {
        Gate::authorize('publishResults', $exam);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $this->results->unpublish($exam, $validated['reason'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Withdrawn. Students no longer see it, and the reason is on record.',
        ]);
    }

    /**
     * One row, corrected after publication. Amending is the only way a published mark ever changes:
     * the row is stamped `amended_at` / `amended_by` / `amendment_reason`, and the activity record
     * carries the old values, so a corrected 62 never looks like it was always a 62.
     *
     * The previous mark lives in the audit trail rather than in a column on the row — there is no
     * `original_marks`, and a screen wanting the before-and-after reads the activity log.
     */
    public function amend(Request $request, ExamResult $result): RedirectResponse
    {
        Gate::authorize('update', $result);

        $validated = $request->validate([
            'obtained_marks' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'attendance_status' => ['nullable', Rule::enum(ExamAttendanceStatus::class)],
            'remarks' => ['nullable', 'string', 'max:500'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        // The row's own `total_marks`, which INV-20-1 keeps as a snapshot of what the paper was out
        // of when it was marked — never `$result->exam->total_marks`. A paper re-scaled after
        // publication must not silently make an old mark legal, or illegal.
        $ceiling = (string) $result->getAttribute('total_marks');
        $marks = $validated['obtained_marks'] ?? null;

        if ($marks !== null && $marks !== '' && Money::compare((string) $marks, $ceiling) > 0) {
            return back()->withInput()->withErrors([
                'obtained_marks' => sprintf('That paper was out of %s.', $ceiling),
            ]);
        }

        $this->results->amend(
            $result,
            array_filter(
                $validated,
                static fn (string $key): bool => $key !== 'reason',
                ARRAY_FILTER_USE_KEY,
            ),
            $validated['reason'],
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Mark corrected, with the reason recorded.']);
    }

    public function export(Request $request, Exam $exam, string $format): StreamedResponse
    {
        Gate::authorize('export', ExamResult::class);

        abort_unless($format === 'csv', Response::HTTP_NOT_FOUND);

        return (new CsvWriter)->download(
            'results-'.$exam->getKey().'-'.app_date(now(), 'Y-m-d').'.csv',
            ['Roll', 'Student', 'Attendance', 'Marks', 'Out of', 'Percentage', 'Grade', 'Points', 'Passed', 'Position', 'Remarks'],
            CsvWriter::rowsFrom(
                $exam->results()->with('student:id,student_code,name')->orderBy('position_in_batch'),
                static fn (ExamResult $r): array => [
                    (string) $r->student?->getAttribute('student_code'),
                    (string) $r->student?->getAttribute('name'),
                    $r->attendance_status->label(),
                    (string) ($r->getAttribute('obtained_marks') ?? ''),
                    (string) $r->getAttribute('total_marks'),
                    (string) ($r->getAttribute('percentage') ?? ''),
                    (string) ($r->getAttribute('grade') ?? ''),
                    (string) ($r->getAttribute('grade_point') ?? ''),
                    $r->getAttribute('is_passed') ? 'Yes' : 'No',
                    (string) ($r->getAttribute('position_in_batch') ?? ''),
                    (string) ($r->getAttribute('remarks') ?? ''),
                ],
            ),
        );
    }
}
