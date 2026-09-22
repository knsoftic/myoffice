<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Enums\ClassSessionStatus;
use App\Enums\StudentAttendanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teacher\Concerns\ResolvesTheSignedInTeacher;
use App\Models\Institute\ClassSession;
use App\Models\Institute\StudentAttendance;
use App\Services\Institute\AttendanceReportService;
use App\Services\Institute\AttendanceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The register, from the teacher's side — `teacher.attendance.*` (§73, phase-14-17 §7.9, §8.15).
 *
 * **Scoped to the classes this teacher owns**: as the batch teacher, as the teacher of the weekly
 * slot, or as the actual teacher of that one class after a substitution. Somebody else's class is a
 * **404**, because a 403 would confirm it exists.
 *
 * **`teacher_portal.attendance_mark` is separate from `.attendance`** (§4.3). A visiting trainer may
 * be allowed to see a roster without being allowed to write the register, and the two rights are
 * therefore two permissions rather than one.
 */
final class AttendanceController extends Controller
{
    use ResolvesTheSignedInTeacher;

    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AttendanceReportService $reports,
    ) {}

    /**
     * What still needs a register, and what was taken recently.
     */
    public function index(Request $request): View
    {
        $teacher = $this->teacher($request);

        return view('teacher.attendance.index', [
            'teacher' => $teacher,
            'unmarked' => $this->reports->unmarked(null, ['teacher_id' => $teacher->getKey()]),
            'recent' => ClassSession::query()
                ->where('teacher_id', $teacher->getKey())
                ->whereNotNull('attendance_marked_at')
                ->with(['batch:id,code,name'])
                ->orderByDesc('session_date')
                ->limit(20)
                ->get(),
        ]);
    }

    public function mark(Request $request, ClassSession $session): View
    {
        $this->assertMine($request, $session);

        return view('teacher.attendance.mark', [
            'session' => $session->load(['batch:id,code,name', 'course:id,name', 'classroom:id,code,name']),
            'roster' => $this->attendance->roster($session),
            'statuses' => StudentAttendanceStatus::cases(),
            'grace' => (int) setting('institute.attendance_grace_minutes', 15),
            'canBeMarked' => ! in_array($session->status, [ClassSessionStatus::Cancelled, ClassSessionStatus::Rescheduled], true),
        ]);
    }

    public function store(Request $request, ClassSession $session): RedirectResponse
    {
        $this->assertMine($request, $session);

        $validated = $request->validate([
            'marks' => ['required', 'array', 'min:1'],
            'marks.*.status' => ['required', Rule::enum(StudentAttendanceStatus::class)],
            'marks.*.check_in_time' => ['nullable', 'date_format:H:i,H:i:s'],
            'marks.*.remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->attendance->mark($session, $validated['marks'], [], $request->user());

        $message = sprintf('Register taken — %s%% present.', $result->percentage);

        if ($result->promoted !== []) {
            $message .= ' '.implode(', ', $result->promoted).' arrived past the grace window and are recorded as late.';
        }

        return redirect()
            ->route('teacher.attendance.mark', $session)
            ->with('toast', ['type' => 'success', 'message' => $message]);
    }

    public function update(Request $request, StudentAttendance $attendance): RedirectResponse
    {
        $teacher = $this->teacher($request);
        $session = $attendance->session;

        if (! $session instanceof ClassSession || ! $this->ownsSession($teacher->getKey(), $session)) {
            throw new NotFoundHttpException;
        }

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

        return back()->with('toast', ['type' => 'success', 'message' => 'Updated.']);
    }

    /**
     * §7.9's attendance report, for this teacher's own batches only.
     */
    public function report(Request $request): View
    {
        $teacher = $this->teacher($request);

        $from = Carbon::parse((string) $request->input('from', Carbon::today()->startOfMonth()->toDateString()));
        $to = Carbon::parse((string) $request->input('to', Carbon::today()->endOfMonth()->toDateString()));

        return view('teacher.reports.attendance', [
            'teacher' => $teacher,
            'from' => $from,
            'to' => $to,
            'report' => $this->reports->batchSummary($from, $to, ['teacher_id' => $teacher->getKey()]),
            'percentages' => $this->reports->percentage(['teacher_id' => $teacher->getKey()]),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function assertMine(Request $request, ClassSession $session): void
    {
        if (! $this->ownsSession($this->teacher($request)->getKey(), $session)) {
            throw new NotFoundHttpException;
        }
    }

    /**
     * Theirs if they are taking it, were supposed to before somebody covered, or teach the batch or
     * the weekly slot it came from.
     */
    private function ownsSession(int $teacherId, ClassSession $session): bool
    {
        if ((int) $session->teacher_id === $teacherId || (int) $session->original_teacher_id === $teacherId) {
            return true;
        }

        if ((int) ($session->batch?->teacher_id ?? 0) === $teacherId) {
            return true;
        }

        return (int) ($session->entry?->teacher_id ?? 0) === $teacherId;
    }
}
