<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\ClassCancellationReason;
use App\Enums\ClassSessionStatus;
use App\Enums\DeliveryMode;
use App\Http\Controllers\Controller;
use App\Models\Institute\Batch;
use App\Models\Institute\ClassSession;
use App\Models\Institute\Classroom;
use App\Models\Institute\Teacher;
use App\Services\Institute\BatchEnrollmentService;
use App\Services\Institute\ClassSessionService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Dated classes — `admin.class-sessions.*` (phase-14-17 §7.6, §8.14).
 *
 * **Generation is a button, not a risk.** `generate` is idempotent by index, so pressing it twice, or
 * pressing it while the nightly job is running, creates nothing the second time.
 */
final class ClassSessionController extends Controller
{
    public function __construct(
        private readonly ClassSessionService $sessions,
        private readonly BatchEnrollmentService $enrollments,
    ) {}

    public function index(Request $request): View
    {
        $from = Carbon::parse((string) $request->input('from', Carbon::today()->startOfWeek()->toDateString()));
        $to = Carbon::parse((string) $request->input('to', $from->copy()->addDays(13)->toDateString()));

        return view('admin.class-sessions.index', [
            'sessions' => $this->filtered($request, $from, $to)
                ->with(['batch:id,code,name', 'teacher:id,name', 'classroom:id,code', 'course:id,name'])
                ->orderBy('session_date')
                ->orderBy('start_time')
                ->paginate(per_page())
                ->withQueryString(),
            'from' => $from,
            'to' => $to,
            'statuses' => ClassSessionStatus::options(),
            'batches' => Batch::query()->live()->orderBy('code')->pluck('code', 'id'),
            'teachers' => Teacher::query()->teaching()->orderBy('name')->pluck('name', 'id'),
            'filters' => $request->only(['status', 'batch_id', 'teacher_id', 'unmarked']),
            'unmarked' => ClassSession::query()->unmarked()
                ->whereDate('session_date', '>=', $from->toDateString())
                ->count(),
        ]);
    }

    public function show(ClassSession $session): View
    {
        $session->load(['batch:id,code,name,course_id', 'teacher:id,name', 'originalTeacher:id,name',
            'classroom:id,code,name', 'course:id,name', 'entry:id,day_of_week,start_time,end_time',
            'rescheduledTo:id,session_date,start_time', 'rescheduledFrom:id,session_date,start_time']);

        return view('admin.class-sessions.show', [
            'session' => $session,
            'roster' => $session->batch instanceof Batch
                ? $this->enrollments->roster($session->batch, Carbon::parse($session->session_date->toDateString()))
                : collect(),
            'reasons' => ClassCancellationReason::options(),
            'teachers' => Teacher::query()->teaching()->where('id', '!=', $session->teacher_id)->orderBy('name')->pluck('name', 'id'),
            'classrooms' => Classroom::query()->active()->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'batch_id' => ['required', 'integer', Rule::exists('batches', 'id')->whereNull('deleted_at')],
            'teacher_id' => ['nullable', 'integer', Rule::exists('teachers', 'id')->whereNull('deleted_at')],
            'classroom_id' => ['nullable', 'integer', Rule::exists('classrooms', 'id')->whereNull('deleted_at')],
            'session_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i,H:i:s'],
            'end_time' => ['required', 'date_format:H:i,H:i:s', 'after:start_time'],
            'delivery_mode' => ['nullable', Rule::enum(DeliveryMode::class)],
            'meeting_url' => ['nullable', 'url', 'max:500'],
            'title' => ['nullable', 'string', 'max:180'],
            'notes' => ['nullable', 'string', 'max:500'],
            'clash_override_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $batch = Batch::query()->findOrFail($validated['batch_id']);

        $session = $this->sessions->createOneOff($batch, $validated, $request->user());

        return redirect()
            ->route('admin.class-sessions.show', $session)
            ->with('toast', ['type' => 'success', 'message' => 'The extra class has been scheduled.']);
    }

    public function generate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'batch_id' => ['nullable', 'integer', Rule::exists('batches', 'id')->whereNull('deleted_at')],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $batch = isset($validated['batch_id']) ? Batch::query()->find($validated['batch_id']) : null;
        $from = Carbon::parse((string) ($validated['from'] ?? Carbon::today()->toDateString()));
        $to = Carbon::parse((string) ($validated['to'] ?? Carbon::today()
            ->addWeeks(max(1, (int) setting('institute.session_generation_weeks_ahead', 8)))->toDateString()));

        $made = $this->sessions->generate($batch, $from, $to, $request->user());

        return back()->with('toast', [
            'type' => $made > 0 ? 'success' : 'info',
            'message' => $made > 0
                ? $made.' '.($made === 1 ? 'class was' : 'classes were').' generated.'
                : 'Nothing new to generate — every class in that window already exists.',
        ]);
    }

    public function cancel(Request $request, ClassSession $session): RedirectResponse
    {
        $validated = $request->validate([
            'cancellation_reason' => ['required', Rule::enum(ClassCancellationReason::class)],
            'cancellation_detail' => ['required', 'string', 'max:255'],
        ]);

        $this->sessions->cancel(
            $session,
            ClassCancellationReason::from($validated['cancellation_reason']),
            $validated['cancellation_detail'],
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'The class has been cancelled and the roster told.']);
    }

    public function reschedule(Request $request, ClassSession $session): RedirectResponse
    {
        $validated = $request->validate([
            'session_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i,H:i:s'],
            'end_time' => ['required', 'date_format:H:i,H:i:s', 'after:start_time'],
            'teacher_id' => ['nullable', 'integer', Rule::exists('teachers', 'id')->whereNull('deleted_at')],
            'classroom_id' => ['nullable', 'integer', Rule::exists('classrooms', 'id')->whereNull('deleted_at')],
            'reason' => ['required', 'string', 'max:255'],
            'clash_override_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $successor = $this->sessions->reschedule($session, $validated, $validated['reason'], $request->user());

        return redirect()
            ->route('admin.class-sessions.show', $successor)
            ->with('toast', ['type' => 'success', 'message' => 'Moved. The original is kept, pointing here.']);
    }

    public function substitute(Request $request, ClassSession $session): RedirectResponse
    {
        $validated = $request->validate([
            'teacher_id' => ['required', 'integer', Rule::exists('teachers', 'id')->whereNull('deleted_at')],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $substitute = Teacher::query()->findOrFail($validated['teacher_id']);

        $this->sessions->substituteTeacher($session, $substitute, $validated['reason'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => $substitute->name.' will take this class. The original teacher is still recorded against it.',
        ]);
    }

    public function held(Request $request, ClassSession $session): RedirectResponse
    {
        $this->sessions->markHeld($session, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Marked as held. The register is next.']);
    }

    private function filtered(Request $request, Carbon $from, Carbon $to): Builder
    {
        return ClassSession::query()
            ->forBranch($request->user()?->branch_id === null ? null : (int) $request->user()->branch_id)
            ->between($from, $to)
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('batch_id'), fn (Builder $q) => $q->where('batch_id', $request->integer('batch_id')))
            ->when($request->filled('teacher_id'), fn (Builder $q) => $q->where('teacher_id', $request->integer('teacher_id')))
            ->when($request->boolean('unmarked'), fn (Builder $q) => $q->unmarked());
    }
}
