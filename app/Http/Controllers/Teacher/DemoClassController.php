<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Enums\DemoClassStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teacher\Concerns\ResolvesTheSignedInTeacher;
use App\Models\Institute\DemoClass;
use App\Services\Institute\DemoClassService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The demos a teacher is booked to take — `teacher.demo-classes.*` (§87, phase-14-17 §7.9).
 *
 * **Marking one attended or missed is the teacher's to do**, because they are the only person who
 * knows. Converting it into an admission is not: that is the front desk's act, and it is not on this
 * panel at all.
 */
final class DemoClassController extends Controller
{
    use ResolvesTheSignedInTeacher;

    public function __construct(
        private readonly DemoClassService $demos,
    ) {}

    public function index(Request $request): View
    {
        $teacher = $this->teacher($request);

        return view('teacher.demo-classes.index', [
            'teacher' => $teacher,
            'demos' => DemoClass::query()
                ->where('teacher_id', $teacher->getKey())
                ->with(['course:id,name', 'classroom:id,code,name'])
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                ->orderByDesc('scheduled_on')
                ->orderBy('start_time')
                ->paginate(per_page())
                ->withQueryString(),
            'statuses' => DemoClassStatus::options(),
            'filters' => $request->only(['status']),
            'unmarked' => DemoClass::query()
                ->where('teacher_id', $teacher->getKey())
                ->scheduled()
                ->whereDate('scheduled_on', '<=', Carbon::today()->toDateString())
                ->count(),
        ]);
    }

    public function status(Request $request, DemoClass $demo): RedirectResponse
    {
        $teacher = $this->teacher($request);

        if ((int) $demo->teacher_id !== (int) $teacher->getKey()) {
            throw new NotFoundHttpException;
        }

        $validated = $request->validate([
            // A teacher says whether somebody turned up. Cancelling and converting are not theirs.
            'status' => ['required', 'string', 'in:attended,missed'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $validated['status'] === 'attended'
            ? $this->demos->markAttended($demo, $validated['remarks'] ?? null, $request->user())
            : $this->demos->markMissed($demo, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Marked as '.DemoClassStatus::from($validated['status'])->label().'.',
        ]);
    }
}
