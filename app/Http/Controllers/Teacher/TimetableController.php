<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Enums\Weekday;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teacher\Concerns\ResolvesTheSignedInTeacher;
use App\Models\Institute\ClassSession;
use App\Models\Institute\TimetableEntry;
use App\Services\Institute\BatchEnrollmentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A teacher's own week — `teacher.timetable.*` (§73, phase-14-17 §7.9, §8.19).
 *
 * The week is built from the dated classes rather than from the weekly rules, because what a teacher
 * needs to know is what is actually happening: a cancelled Tuesday should read "cancelled", not
 * disappear, and a class they are covering for somebody else belongs on their week even though no
 * rule of theirs produced it.
 */
final class TimetableController extends Controller
{
    use ResolvesTheSignedInTeacher;

    public function __construct(
        private readonly BatchEnrollmentService $enrollments,
    ) {}

    public function index(Request $request): View
    {
        $teacher = $this->teacher($request);

        $from = Carbon::parse((string) $request->input('from', Carbon::today()->startOfWeek()->toDateString()))->startOfDay();
        $to = $from->copy()->addDays(6)->endOfDay();

        return view('teacher.timetable.index', [
            'teacher' => $teacher,
            'from' => $from,
            'to' => $to,
            'days' => collect(range(0, 6))->map(fn (int $i) => $from->copy()->addDays($i)),
            'sessions' => ClassSession::query()
                ->where('teacher_id', $teacher->getKey())
                ->between($from, $to)
                ->with(['batch:id,code,name', 'classroom:id,code,name', 'course:id,name'])
                ->orderBy('session_date')
                ->orderBy('start_time')
                ->get()
                ->groupBy(fn (ClassSession $s): string => $s->session_date->toDateString()),
            // The weekly pattern beside the dated week, so "every Monday" is still visible.
            'entries' => TimetableEntry::query()
                ->active()
                ->where('teacher_id', $teacher->getKey())
                ->with(['batch:id,code,name', 'classroom:id,code'])
                ->orderBy('start_time')
                ->get()
                ->groupBy(fn (TimetableEntry $e): string => $e->day_of_week->value),
            'weekdays' => Weekday::ordered(),
        ]);
    }

    public function session(Request $request, ClassSession $session): View
    {
        $teacher = $this->teacher($request);

        // Theirs if they are taking it, or were supposed to before somebody covered for them.
        $mine = (int) $session->teacher_id === (int) $teacher->getKey()
            || (int) $session->original_teacher_id === (int) $teacher->getKey();

        if (! $mine) {
            throw new NotFoundHttpException;
        }

        return view('teacher.sessions.show', [
            'teacher' => $teacher,
            'session' => $session->load(['batch:id,code,name', 'classroom:id,code,name', 'course:id,name',
                'originalTeacher:id,name', 'topic:id,title']),
            'roster' => $session->batch !== null
                ? $this->enrollments->roster($session->batch, Carbon::parse($session->session_date->toDateString()))
                : collect(),
        ]);
    }
}
