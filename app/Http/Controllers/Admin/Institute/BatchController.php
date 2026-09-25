<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\BatchStatus;
use App\Enums\DeliveryMode;
use App\Enums\EnrollmentStatus;
use App\Enums\Weekday;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Institute\Batch;
use App\Models\Institute\Classroom;
use App\Models\Institute\Course;
use App\Models\Institute\Teacher;
use App\Services\Institute\BatchEnrollmentService;
use App\Services\Institute\BatchService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Batches — `admin.batches.*` (§70, phase-14-17 §7.5, §8.11).
 *
 * **The capacity meter reads one function.** `BatchService::capacitySnapshot()` is what the meter, the
 * "seats left" label and the near-capacity notice all render, so a batch cannot look full on one
 * screen and open on another.
 */
final class BatchController extends Controller
{
    public function __construct(
        private readonly BatchService $batches,
        private readonly BatchEnrollmentService $enrollments,
    ) {}

    public function index(Request $request): View
    {
        return view('admin.batches.index', [
            'batches' => $this->filtered($request)
                ->with(['course:id,name', 'teacher:id,name', 'classroom:id,code,name'])
                ->orderByDesc('start_date')
                ->paginate(per_page())
                ->withQueryString(),
            'statuses' => BatchStatus::options(),
            'courses' => Course::query()->orderBy('name')->pluck('name', 'id'),
            'teachers' => Teacher::query()->teaching()->orderBy('name')->pluck('name', 'id'),
            'filters' => $request->only(['status', 'course_id', 'teacher_id', 'q']),
            'counts' => $this->counts($request),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.batches.create', $this->formData($request) + [
            'batch' => new Batch([
                'student_capacity' => (int) setting('institute.batch_default_capacity', 20),
                'delivery_mode' => DeliveryMode::Physical->value,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $batch = $this->batches->create($this->validated($request), $request->user());

        return redirect()
            ->route('admin.batches.show', $batch)
            ->with('toast', ['type' => 'success', 'message' => $batch->label().' has been created. Set its timetable next.']);
    }

    public function show(Request $request, Batch $batch): View
    {
        return view('admin.batches.show', [
            'batch' => $batch->load(['course:id,name', 'teacher:id,name', 'classroom:id,code,name', 'branch:id,name']),
            'capacity' => $this->batches->capacitySnapshot($batch),
            'roster' => $this->enrollments->roster($batch),
            'entries' => $batch->timetableEntries()->active()->with('teacher:id,name', 'classroom:id,code')->orderBy('day_of_week')->orderBy('start_time')->get(),
            'upcoming' => $batch->sessions()
                ->where('status', 'scheduled')
                ->whereDate('session_date', '>=', Carbon::today()->toDateString())
                ->orderBy('session_date')
                ->limit(10)
                ->get(),
            'statuses' => BatchStatus::options(),
            'weekdays' => Weekday::ordered(),
            'teachers' => Teacher::query()->teaching()->orderBy('name')->pluck('name', 'id'),
            'classrooms' => Classroom::query()->active()->orderBy('code')->get(),
            'enrollmentStatuses' => EnrollmentStatus::options(),
            'otherBatches' => Batch::query()->live()->where('id', '!=', $batch->getKey())->orderBy('code')->pluck('code', 'id'),
        ]);
    }

    public function edit(Request $request, Batch $batch): View
    {
        return view('admin.batches.edit', $this->formData($request) + ['batch' => $batch]);
    }

    public function update(Request $request, Batch $batch): RedirectResponse
    {
        $this->batches->update($batch, $this->validated($request, $batch), $request->user());

        return redirect()
            ->route('admin.batches.show', $batch)
            ->with('toast', ['type' => 'success', 'message' => 'Saved.']);
    }

    public function destroy(Batch $batch): RedirectResponse
    {
        $batch->delete();

        return redirect()
            ->route('admin.batches.index')
            ->with('toast', ['type' => 'success', 'message' => $batch->label().' has been removed.']);
    }

    public function status(Request $request, Batch $batch): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(BatchStatus::class)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $to = BatchStatus::from($validated['status']);

        $this->batches->changeStatus($batch, $to, $validated['reason'] ?? null, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => $batch->label().' is now '.$to->label().'.',
        ]);
    }

    public function roster(Request $request, Batch $batch): View
    {
        $on = $request->filled('on') ? Carbon::parse((string) $request->input('on')) : null;

        return view('admin.batches.roster', [
            'batch' => $batch,
            'roster' => $this->enrollments->roster($batch, $on),
            'on' => $on,
            'capacity' => $this->batches->capacitySnapshot($batch),
            'enrollmentStatuses' => EnrollmentStatus::options(),
            'otherBatches' => Batch::query()->live()->where('id', '!=', $batch->getKey())->orderBy('code')->pluck('code', 'id'),
        ]);
    }

    public function printRoster(Request $request, Batch $batch): View
    {
        return view('admin.batches.print-roster', [
            'batch' => $batch->load(['course:id,name', 'teacher:id,name']),
            'roster' => $this->enrollments->roster($batch),
            'printedAt' => Carbon::now(),
        ]);
    }

    /** INV-I7 by hand: the cache is repaired by recounting, never by editing it. */
    public function recount(Batch $batch): RedirectResponse
    {
        $students = $this->batches->recountStudents($batch);
        $this->batches->recountSessions($batch);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Recounted: '.$students.' active '.($students === 1 ? 'student' : 'students').'.',
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse
    {
        abort_unless(in_array($format, ['csv'], true), 404);

        $rows = $this->filtered($request)->with(['course:id,name', 'teacher:id,name'])->orderBy('code')->get();

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, ['Code', 'Name', 'Course', 'Teacher', 'Starts', 'Ends', 'Status', 'Students', 'Capacity']);

            foreach ($rows as $batch) {
                fputcsv($handle, [
                    $batch->code,
                    $batch->name,
                    $batch->course?->name,
                    $batch->teacher?->name,
                    $batch->start_date?->toDateString(),
                    $batch->end_date?->toDateString(),
                    $batch->status->label(),
                    $batch->current_students,
                    $batch->student_capacity,
                ]);
            }

            fclose($handle);
        }, 'batches-'.Carbon::now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function filtered(Request $request): Builder
    {
        return Batch::query()
            ->forBranch($request->user()?->branch_id === null ? null : (int) $request->user()->branch_id)
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('course_id'), fn (Builder $q) => $q->where('course_id', $request->integer('course_id')))
            ->when($request->filled('teacher_id'), fn (Builder $q) => $q->where('teacher_id', $request->integer('teacher_id')))
            ->when($request->filled('q'), function (Builder $q) use ($request): void {
                $term = '%'.$request->string('q').'%';

                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('code', 'like', $term)->orWhere('name', 'like', $term);
                });
            });
    }

    /**
     * @return array<string, int>
     */
    private function counts(Request $request): array
    {
        // **One grouped count, not one per case plus a total**: the per-case version ran the same
        // `count(*)` once per `BatchStatus` case — six identical statements, where phase-24-25
        // section 11.7 (PRF-02) allows a statement to repeat three times before it is a loop. `all`
        // is the sum of the groups, which is what the separate seventh query was measuring.
        $grouped = $this->filtered($request)
            ->toBase()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $counts = ['all' => array_sum(array_map(static fn (mixed $total): int => (int) $total, $grouped))];

        foreach (BatchStatus::cases() as $status) {
            $counts[$status->value] = (int) ($grouped[$status->value] ?? 0);
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request): array
    {
        return [
            'courses' => Course::query()->orderBy('name')->pluck('name', 'id'),
            'teachers' => Teacher::query()->teaching()->orderBy('name')->pluck('name', 'id'),
            'classrooms' => Classroom::query()->active()->orderBy('code')->get(),
            'branches' => Branch::query()->orderBy('name')->pluck('name', 'id'),
            'modes' => DeliveryMode::options(),
            'weekdays' => Weekday::ordered(),
            'workingDays' => (array) setting('institute.timetable_working_days', []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Batch $batch = null): array
    {
        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:32',
                Rule::unique('batches', 'code')->ignore($batch?->getKey())->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:150'],
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
            'teacher_id' => ['nullable', 'integer', Rule::exists('teachers', 'id')->whereNull('deleted_at')],
            'classroom_id' => ['nullable', 'integer', Rule::exists('classrooms', 'id')->whereNull('deleted_at')],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'days' => ['nullable', 'array'],
            'days.*' => [Rule::enum(Weekday::class)],
            'start_time' => ['nullable', 'date_format:H:i,H:i:s'],
            'end_time' => ['nullable', 'date_format:H:i,H:i:s', 'after:start_time'],
            'delivery_mode' => ['required', Rule::enum(DeliveryMode::class)],
            'meeting_url' => ['nullable', 'url', 'max:500'],
            'student_capacity' => ['required', 'integer', 'min:1', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $validated['days'] = array_values($validated['days'] ?? []);

        return $validated;
    }
}
