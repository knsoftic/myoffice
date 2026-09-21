<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\DataObjects\Institute\SlotCandidate;
use App\Enums\DeliveryMode;
use App\Enums\Weekday;
use App\Http\Controllers\Controller;
use App\Models\Institute\Batch;
use App\Models\Institute\Classroom;
use App\Models\Institute\Course;
use App\Models\Institute\Teacher;
use App\Models\Institute\TimetableEntry;
use App\Services\Institute\ScheduleClashDetector;
use App\Services\Institute\TimetableService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The weekly pattern — `admin.timetable.*` (§71, phase-14-17 §7.6, §8.13).
 *
 * **Five views, one query.** `TimetableService::views()` groups the same rows by whichever axis was
 * asked for, so a slot cannot appear on the room view and be missing from the teacher view.
 *
 * **`check-clash` writes nothing.** It is what the form calls while somebody is still choosing an
 * hour, so it is a GET and it is throttled; the real check happens again inside the transaction that
 * writes, under a row lock, because an answer given before the write is only ever advisory.
 */
final class TimetableController extends Controller
{
    public function __construct(
        private readonly TimetableService $timetable,
        private readonly ScheduleClashDetector $detector,
    ) {}

    public function index(Request $request, string $view = 'weekly'): View
    {
        $filters = $this->filters($request);

        return view('admin.timetable.index', $this->pageData($request) + [
            'view' => $view,
            'grid' => $this->timetable->views($view, $filters),
            'filters' => $request->only(['batch_id', 'teacher_id', 'classroom_id', 'course_id', 'day']),
        ]);
    }

    public function print(Request $request, string $view): View
    {
        abort_unless(in_array($view, ['daily', 'weekly', 'teacher', 'batch', 'classroom'], true), 404);

        return view('admin.timetable.print', [
            'view' => $view,
            'grid' => $this->timetable->views($view, $this->filters($request)),
            'printedAt' => Carbon::now(),
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse
    {
        abort_unless(in_array($format, ['csv'], true), 404);

        $grid = $this->timetable->views('weekly', $this->filters($request));

        return response()->streamDownload(function () use ($grid): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, ['Day', 'From', 'To', 'Batch', 'Course', 'Teacher', 'Room', 'Mode', 'From date', 'To date']);

            foreach ($grid['entries'] as $entry) {
                fputcsv($handle, [
                    $entry->day_of_week->label(),
                    Carbon::parse($entry->start_time)->format('H:i'),
                    Carbon::parse($entry->end_time)->format('H:i'),
                    $entry->batch?->code,
                    $entry->batch?->course?->name,
                    $entry->teacher?->name,
                    $entry->classroom?->code,
                    $entry->delivery_mode->label(),
                    $entry->effective_from?->toDateString(),
                    $entry->effective_to?->toDateString(),
                ]);
            }

            fclose($handle);
        }, 'timetable-'.Carbon::now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * "Is anybody using this hour?" — asked while the form is still open, answered in full.
     */
    public function checkClash(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'teacher_id' => ['nullable', 'integer'],
            'classroom_id' => ['nullable', 'integer'],
            'batch_id' => ['nullable', 'integer'],
            'day_of_week' => ['nullable', Rule::enum(Weekday::class)],
            'session_date' => ['nullable', 'date'],
            'start_time' => ['required', 'date_format:H:i,H:i:s'],
            'end_time' => ['required', 'date_format:H:i,H:i:s', 'after:start_time'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'delivery_mode' => ['nullable', Rule::enum(DeliveryMode::class)],
            'ignore_type' => ['nullable', 'string', 'in:timetable_entry,class_session,demo_class'],
            'ignore_id' => ['nullable', 'integer'],
        ]);

        $day = isset($validated['day_of_week']) ? Weekday::from($validated['day_of_week']) : null;
        $anchor = Carbon::parse((string) ($validated['session_date'] ?? $validated['effective_from'] ?? Carbon::today()->toDateString()));

        $report = $this->detector->check(new SlotCandidate(
            teacherId: isset($validated['teacher_id']) ? (int) $validated['teacher_id'] : null,
            classroomId: isset($validated['classroom_id']) ? (int) $validated['classroom_id'] : null,
            batchId: isset($validated['batch_id']) ? (int) $validated['batch_id'] : null,
            startsAt: Carbon::parse($anchor->toDateString().' '.$validated['start_time']),
            endsAt: Carbon::parse($anchor->toDateString().' '.$validated['end_time']),
            ignoreType: $validated['ignore_type'] ?? null,
            ignoreId: isset($validated['ignore_id']) ? (int) $validated['ignore_id'] : null,
            dayOfWeek: $day,
            effectiveFrom: isset($validated['effective_from']) ? Carbon::parse($validated['effective_from']) : null,
            effectiveTo: isset($validated['effective_to']) ? Carbon::parse($validated['effective_to']) : null,
            deliveryMode: isset($validated['delivery_mode']) ? DeliveryMode::from($validated['delivery_mode']) : null,
        ));

        return response()->json($report->toArray());
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        $batch = Batch::query()->findOrFail($validated['batch_id']);

        $this->authorize('update', $batch);

        $this->timetable->create($batch, $validated, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'The slot has been added, and its classes generated.']);
    }

    public function seed(Request $request, Batch $batch): RedirectResponse
    {
        $entries = $this->timetable->seedFromBatch($batch, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => $entries->count().' '.($entries->count() === 1 ? 'slot' : 'slots').' created from the batch days.',
        ]);
    }

    public function update(Request $request, TimetableEntry $entry): RedirectResponse
    {
        $this->timetable->update($entry, $this->validated($request, $entry), $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Saved. Classes still to come were rebuilt; the ones already held were left alone.',
        ]);
    }

    public function end(Request $request, TimetableEntry $entry): RedirectResponse
    {
        $validated = $request->validate([
            'effective_to' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $this->timetable->end($entry, Carbon::parse($validated['effective_to']), $validated['reason'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'The slot has ended. Classes after that date were cancelled with your reason.',
        ]);
    }

    public function destroy(Request $request, TimetableEntry $entry): RedirectResponse
    {
        $this->timetable->delete($entry, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'The slot has been removed.']);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $filters = [];

        foreach (['batch_id', 'teacher_id', 'classroom_id', 'course_id'] as $key) {
            if ($request->filled($key)) {
                $filters[$key] = $request->integer($key);
            }
        }

        if ($request->filled('day')) {
            $filters['day'] = (string) $request->string('day');
        }

        $branch = $request->user()?->branch_id;

        if ($branch !== null) {
            $filters['branch_id'] = (int) $branch;
        }

        return $filters;
    }

    /**
     * @return array<string, mixed>
     */
    private function pageData(Request $request): array
    {
        return [
            'batches' => Batch::query()->live()->orderBy('code')->get(['id', 'code', 'name', 'course_id', 'teacher_id', 'classroom_id', 'delivery_mode', 'start_date', 'end_date']),
            'teachers' => Teacher::query()->teaching()->orderBy('name')->pluck('name', 'id'),
            'classrooms' => Classroom::query()->active()->orderBy('code')->get(),
            'courses' => Course::query()->orderBy('name')->pluck('name', 'id'),
            'weekdays' => Weekday::ordered(),
            'modes' => DeliveryMode::options(),
            'workingDays' => (array) setting('institute.timetable_working_days', []),
            'dayStart' => (string) setting('institute.timetable_day_start', '08:00'),
            'dayEnd' => (string) setting('institute.timetable_day_end', '22:00'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?TimetableEntry $entry = null): array
    {
        return $request->validate([
            'batch_id' => [$entry === null ? 'required' : 'nullable', 'integer', Rule::exists('batches', 'id')->whereNull('deleted_at')],
            'teacher_id' => ['nullable', 'integer', Rule::exists('teachers', 'id')->whereNull('deleted_at')],
            'classroom_id' => ['nullable', 'integer', Rule::exists('classrooms', 'id')->whereNull('deleted_at')],
            'day_of_week' => ['required', Rule::enum(Weekday::class)],
            'start_time' => ['required', 'date_format:H:i,H:i:s'],
            'end_time' => ['required', 'date_format:H:i,H:i:s', 'after:start_time'],
            'delivery_mode' => ['nullable', Rule::enum(DeliveryMode::class)],
            'meeting_url' => ['nullable', 'url', 'max:500'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:500'],
            // [D-IN-14]: a teacher or room clash can be accepted with a reason, and the reason is
            // recorded. A batch clash cannot be accepted at all, whatever is typed here.
            'clash_override_reason' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
