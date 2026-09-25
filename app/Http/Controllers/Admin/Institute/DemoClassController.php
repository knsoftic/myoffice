<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\DeliveryMode;
use App\Enums\DemoClassStatus;
use App\Http\Controllers\Controller;
use App\Models\Institute\Course;
use App\Models\Institute\CourseInquiry;
use App\Models\Institute\DemoClass;
use App\Models\Institute\Student;
use App\Models\Institute\StudentApplication;
use App\Services\Institute\DemoClassService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Demo classes — `admin.demo-classes.*` (§87, phase-14-17 §7.3, §8.9).
 *
 * **Booking one is a scheduling act, not a note.** The service refuses a slot the teacher or the room
 * already holds, and two unique indexes refuse it again under a race. Phase 16's clash detector will
 * widen that to overlapping ranges across classes and demos; until then this checks what it can check
 * honestly rather than claiming to have looked.
 *
 * **The slip is `print`** — a different right from booking, because the slip is what an attendee is
 * handed at reception.
 */
final class DemoClassController extends Controller
{
    public function __construct(
        private readonly DemoClassService $demos,
    ) {}

    public function index(Request $request): View
    {
        $query = $this->filtered($request);

        return view('admin.demo-classes.index', [
            'demos' => $query->clone()
                ->with(['course:id,name', 'inquiry:id,inquiry_number', 'application:id,application_number', 'student:id,name,student_code'])
                ->orderByDesc('scheduled_on')
                ->orderBy('start_time')
                ->paginate(per_page())
                ->withQueryString(),
            'statuses' => DemoClassStatus::options(),
            'courses' => Course::query()->orderBy('name')->pluck('name', 'id'),
            'filters' => $request->only(['status', 'course_id', 'from', 'to', 'q']),
            'counts' => $this->counts($request),
            'unmarked' => $this->demos->unmarkedPast(),
        ]);
    }

    /** The day/week grid. Phase 16 shares this with the timetable so both are seen at once. */
    public function calendar(Request $request): View
    {
        $from = Carbon::parse((string) $request->input('from', Carbon::now()->startOfWeek()->toDateString()))->startOfDay();
        $to = $from->copy()->addDays(6)->endOfDay();

        return view('admin.demo-classes.calendar', [
            'from' => $from,
            'to' => $to,
            // The week is seven days wide whatever `from` says, but **a date window is not a row bound**
            // (phase-24-25 section 6.4, PRF-05): one branch could have any number of demos on one day, and
            // this grid renders every one it is handed. 500 is more than a week of slots can hold.
            'demos' => DemoClass::query()
                ->forBranch($request->user()?->branch_id === null ? null : (int) $request->user()->branch_id)
                ->between($from, $to)
                ->with(['course:id,name'])
                ->orderBy('scheduled_on')
                ->orderBy('start_time')
                ->limit(500)
                ->get()
                ->groupBy(fn (DemoClass $demo): string => $demo->scheduled_on->toDateString()),
            'days' => collect(range(0, 6))->map(fn (int $i) => $from->copy()->addDays($i)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'subject_type' => ['required', 'string', 'in:inquiry,application,student'],
            'subject_id' => ['required', 'integer'],
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
            'teacher_id' => ['nullable', 'integer'],
            'classroom_id' => ['nullable', 'integer'],
            'batch_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'delivery_mode' => ['required', Rule::enum(DeliveryMode::class)],
            'meeting_url' => ['nullable', 'url', 'max:500'],
            'scheduled_on' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $demo = $this->demos->schedule($this->subject($validated), $validated, $request->user());

        return redirect()
            ->route('admin.demo-classes.index')
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf('Demo booked for %s on %s.', $demo->attendee_name, app_date($demo->scheduled_on)),
            ]);
    }

    public function update(Request $request, DemoClass $demo): RedirectResponse
    {
        $validated = $request->validate([
            'meeting_url' => ['nullable', 'url', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
            'delivery_mode' => ['nullable', Rule::enum(DeliveryMode::class)],
        ]);

        $demo->fill($validated);
        $demo->updated_by = $request->user()?->getKey();
        $demo->save();

        return back()->with('toast', ['type' => 'success', 'message' => 'Demo updated.']);
    }

    public function reschedule(Request $request, DemoClass $demo): RedirectResponse
    {
        $validated = $request->validate([
            'scheduled_on' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'teacher_id' => ['nullable', 'integer'],
            'classroom_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $this->demos->reschedule($demo, $validated, $validated['reason'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Demo moved.']);
    }

    public function status(Request $request, DemoClass $demo): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(DemoClassStatus::class)],
            'remarks' => ['nullable', 'string', 'max:500'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $to = DemoClassStatus::from($validated['status']);

        match ($to) {
            DemoClassStatus::Attended => $this->demos->markAttended($demo, $validated['remarks'] ?? null, $request->user()),
            DemoClassStatus::Missed => $this->demos->markMissed($demo, $validated['remarks'] ?? null, $request->user()),
            DemoClassStatus::Cancelled => $this->demos->cancel($demo, (string) ($validated['reason'] ?? ''), $request->user()),
            default => $this->demos->changeStatus($demo, $to, $validated['reason'] ?? null, $request->user()),
        };

        return back()->with('toast', ['type' => 'success', 'message' => 'Demo updated.']);
    }

    public function convert(Request $request, DemoClass $demo): RedirectResponse
    {
        $validated = $request->validate([
            'admission_id' => ['required', 'integer', Rule::exists('student_admissions', 'id')],
        ]);

        $this->demos->markConverted($demo, (int) $validated['admission_id'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Recorded against the admission.']);
    }

    public function slip(DemoClass $demo): View
    {
        $demo->load(['course:id,name', 'branch:id,name']);

        return view('admin.demo-classes.slip', ['demo' => $demo]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $validated
     */
    private function subject(array $validated): Model
    {
        return match ($validated['subject_type']) {
            'inquiry' => CourseInquiry::query()->findOrFail($validated['subject_id']),
            'application' => StudentApplication::query()->findOrFail($validated['subject_id']),
            default => Student::query()->findOrFail($validated['subject_id']),
        };
    }

    /**
     * @return Builder<DemoClass>
     */
    private function filtered(Request $request)
    {
        return DemoClass::query()
            ->forBranch($request->user()?->branch_id === null ? null : (int) $request->user()->branch_id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->integer('course_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('scheduled_on', '>=', $request->string('from')->toString()))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('scheduled_on', '<=', $request->string('to')->toString()))
            ->when($request->filled('q'), function ($q) use ($request): void {
                $like = '%'.$request->string('q')->toString().'%';

                $q->where(fn ($inner) => $inner->where('attendee_name', 'like', $like)
                    ->orWhere('attendee_phone', 'like', $like));
            });
    }

    /**
     * @return array<string, int>
     */
    private function counts(Request $request): array
    {
        $branchId = $request->user()?->branch_id === null ? null : (int) $request->user()->branch_id;
        $today = Carbon::now()->toDateString();
        $scoped = fn () => DemoClass::query()->forBranch($branchId);

        return [
            'upcoming' => (int) $scoped()->scheduled()->whereDate('scheduled_on', '>=', $today)->count(),
            'today' => (int) $scoped()->whereDate('scheduled_on', $today)->count(),
            'attended' => (int) $scoped()->where('status', DemoClassStatus::Attended->value)->count(),
            'converted' => (int) $scoped()->where('status', DemoClassStatus::Converted->value)->count(),
        ];
    }
}
