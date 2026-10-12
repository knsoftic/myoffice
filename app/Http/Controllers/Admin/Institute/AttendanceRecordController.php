<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\ClassSessionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Institute\RecordStudentAttendanceRequest;
use App\Models\Institute\Batch;
use App\Models\Institute\ClassSession;
use App\Services\Institute\ClassSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * "Record attendance" — the manual way into a register from the attendance screen: a batch and a date.
 *
 * Attendance is always taken against a class (`uq_sa_session_student`), so this decides which class:
 *
 *   one class that day      -> its register opens
 *   several classes         -> the day's list, filtered to the batch, to pick one
 *   no class that day       -> an extra one-off class is added (`ClassSessionService::createOneOff()`,
 *                              with its clash checks), then its register opens
 *
 * Nothing is marked here. The register screen does the marking, so every rule it enforces still applies;
 * saving a register is what turns the class into a held one that counts in every percentage. Adding a
 * class is scheduling, so it also needs `timetable.create`.
 */
final class AttendanceRecordController extends Controller
{
    public function __construct(
        private readonly ClassSessionService $sessions,
    ) {}

    public function store(RecordStudentAttendanceRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $branch = $request->user()?->branch_id === null ? null : (int) $request->user()->branch_id;

        $batch = Batch::query()->forBranch($branch)->find((int) $validated['record_batch_id']);

        if ($batch === null) {
            throw ValidationException::withMessages(['record_batch_id' => 'Pick a batch from the list.']);
        }

        $date = Carbon::parse($validated['record_date'])->startOfDay();

        if ($batch->start_date !== null && $date->lessThan($batch->start_date)) {
            throw ValidationException::withMessages([
                'record_date' => sprintf('%s starts on %s — there is nobody on its roster before that.', $batch->code, app_date($batch->start_date)),
            ]);
        }

        $classes = ClassSession::query()
            ->forBranch($branch)
            ->where('batch_id', $batch->getKey())
            ->whereDate('session_date', $date->toDateString())
            ->whereIn('status', [ClassSessionStatus::Scheduled->value, ClassSessionStatus::Held->value])
            ->orderBy('start_time')
            ->get();

        if ($classes->count() === 1) {
            return redirect()
                ->route('admin.student-attendance.mark', $classes->first())
                ->with('toast', ['type' => 'info', 'message' => sprintf('The register for %s on %s.', $batch->code, app_date($date))]);
        }

        if ($classes->count() > 1) {
            return redirect()
                ->route('admin.student-attendance.index', ['date' => $date->toDateString(), 'batch_id' => $batch->getKey()])
                ->with('toast', ['type' => 'info', 'message' => sprintf(
                    '%s has %d classes on %s — open the register of the one you are recording.',
                    $batch->code, $classes->count(), app_date($date),
                )]);
        }

        if (! $request->user()?->can('timetable.create')) {
            throw ValidationException::withMessages([
                'record_date' => sprintf(
                    '%s has no class on %s, and adding one needs permission to schedule classes. Ask whoever keeps the timetable, or pick a day with a class.',
                    $batch->code, app_date($date),
                ),
            ]);
        }

        $start = $validated['record_start_time'] ?? $batch->start_time;
        $end = $validated['record_end_time'] ?? $batch->end_time;

        if ($start === null || $end === null) {
            throw ValidationException::withMessages([
                'record_start_time' => sprintf('%s has no class times on file — give this class a start and an end time.', $batch->code),
            ]);
        }

        try {
            $session = $this->sessions->createOneOff($batch, [
                'session_date' => $date->toDateString(),
                'start_time' => $start,
                'end_time' => $end,
                'title' => $validated['record_title'] ?? 'Attendance recorded manually',
            ], $request->user());
        } catch (ValidationException $exception) {
            // A clash or a teacher who cannot teach: said on the form that asked, not on fields it does not have.
            throw ValidationException::withMessages([
                'record_start_time' => collect($exception->errors())->flatten()->all(),
            ]);
        }

        return redirect()
            ->route('admin.student-attendance.mark', $session)
            ->with('toast', ['type' => 'success', 'message' => sprintf(
                'Extra class added for %s on %s. Mark the register below — it counts once it is saved.',
                $batch->code, app_date($date),
            )]);
    }
}
