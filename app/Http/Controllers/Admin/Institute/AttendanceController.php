<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\ClassSessionStatus;
use App\Enums\StudentAttendanceStatus;
use App\Http\Controllers\Controller;
use App\Models\Institute\Batch;
use App\Models\Institute\ClassSession;
use App\Models\Institute\StudentAttendance;
use App\Models\Institute\Teacher;
use App\Services\Institute\AttendanceReportService;
use App\Services\Institute\AttendanceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The register — `admin.attendance.*` (§75, phase-14-17 §7.7, §8.15).
 *
 * **The controller decides nothing about the register.** It validates the shape of a submission and
 * hands it to `AttendanceService`, which owns the roster check (INV-I9), the grace promotion, the
 * counters and the percentage (INV-I11). A second opinion about any of those is how a screen comes to
 * disagree with a report.
 *
 * **There is no destroy action, and there will not be one.** Attendance is corrected, never removed
 * (INV-I10): `update()` is the amendment, and the policy answers false to `delete` for everybody.
 */
final class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AttendanceReportService $reports,
    ) {}

    /**
     * The landing screen: today's classes and whoever has not filled in a register.
     */
    public function index(Request $request): View
    {
        $date = Carbon::parse((string) $request->input('date', Carbon::today()->toDateString()));

        return view('admin.attendance.index', [
            'date' => $date,
            'report' => $this->reports->daily($date, $this->filters($request)),
            'unmarked' => $this->reports->unmarked(),
            // Branch-scoped: a dropdown listing every batch code in the institute is a leak of what
            // runs elsewhere, hiding behind a table that IS correctly scoped.
            'batches' => Batch::query()
                ->forBranch($request->user()?->branch_id === null ? null : (int) $request->user()->branch_id)
                ->live()->orderBy('code')->pluck('code', 'id'),
            'teachers' => Teacher::query()
                ->forBranch($request->user()?->branch_id === null ? null : (int) $request->user()->branch_id)
                ->teaching()->orderBy('name')->pluck('name', 'id'),
            'filters' => $request->only(['batch_id', 'teacher_id', 'course_id', 'unmarked_only']),
        ]);
    }

    /**
     * The marking screen — a full roster in under thirty seconds, on a phone at the door.
     */
    public function mark(Request $request, ClassSession $session): View
    {
        return view('admin.attendance.mark', [
            'session' => $session->load(['batch:id,code,name', 'course:id,name', 'teacher:id,name', 'classroom:id,code,name']),
            'roster' => $this->attendance->roster($session),
            'statuses' => StudentAttendanceStatus::cases(),
            'grace' => (int) setting('institute.attendance_grace_minutes', 15),
            'canBeMarked' => ! in_array($session->status, [ClassSessionStatus::Cancelled, ClassSessionStatus::Rescheduled], true),
        ]);
    }

    public function store(Request $request, ClassSession $session): RedirectResponse
    {
        $validated = $request->validate([
            'marks' => ['required', 'array', 'min:1'],
            'marks.*.status' => ['required', Rule::enum(StudentAttendanceStatus::class)],
            'marks.*.check_in_time' => ['nullable', 'date_format:H:i,H:i:s'],
            'marks.*.remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->attendance->mark($session, $validated['marks'], [], $request->user());

        return redirect()
            ->route('admin.student-attendance.mark', $session)
            ->with('toast', [
                'type' => 'success',
                'message' => $this->summarise($result->toArray()),
            ]);
    }

    public function bulk(Request $request, ClassSession $session): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(StudentAttendanceStatus::class)],
        ]);

        $result = $this->attendance->bulk(
            $session,
            StudentAttendanceStatus::from($validated['status']),
            $request->user(),
        );

        return redirect()
            ->route('admin.student-attendance.mark', $session)
            ->with('toast', ['type' => 'success', 'message' => $this->summarise($result->toArray())]);
    }

    /**
     * The amendment (INV-I10). The window and the reason are the service's to enforce.
     */
    public function update(Request $request, StudentAttendance $attendance): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(StudentAttendanceStatus::class)],
            'amendment_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->attendance->amend(
            $attendance,
            StudentAttendanceStatus::from($validated['status']),
            $validated['amendment_reason'] ?? null,
            $request->user(),
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => $attendance->refresh()->wasAmended()
                ? 'Corrected. The old value is on the record with your reason.'
                : 'Updated.',
        ]);
    }

    /**
     * §6.9's importer — deferred, and it says so.
     */
    public function import(Request $request): RedirectResponse
    {
        return back()->with('toast', [
            'type' => 'info',
            'message' => 'Importing a register is not built yet. A partial importer that dropped rows '
                .'quietly would be worse than none — mark the classes from the register screen, which '
                .'checks every student against the roster for that date.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function summarise(array $result): string
    {
        $message = sprintf(
            '%d marked, %d updated — %s%% present.',
            (int) $result['created'],
            (int) $result['updated'],
            $result['percentage'],
        );

        if ($result['promoted'] !== []) {
            $message .= sprintf(
                ' %s arrived past the grace window, so %s recorded as late.',
                implode(', ', $result['promoted']),
                count($result['promoted']) === 1 ? 'that is' : 'those are',
            );
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $filters = [];

        foreach (['batch_id', 'teacher_id', 'course_id', 'classroom_id'] as $key) {
            if ($request->filled($key)) {
                $filters[$key] = $request->integer($key);
            }
        }

        if ($request->boolean('unmarked_only')) {
            $filters['unmarked_only'] = true;
        }

        $branch = $request->user()?->branch_id;

        if ($branch !== null) {
            $filters['branch_id'] = (int) $branch;
        }

        return $filters;
    }
}
