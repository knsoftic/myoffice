<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Enums\EnrollmentStatus;
use App\Enums\Weekday;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesTheSignedInStudent;
use App\Models\Institute\ClassSession;
use App\Models\Institute\StudentBatchEnrollment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * A student's own week — `student.timetable.index` (§74, phase-14-17 §7.8, §8.18).
 *
 * Built from the dated classes, not the weekly rules: a cancelled class must say "cancelled" rather
 * than quietly vanish, which is the difference between a student knowing not to come in and a student
 * standing outside a locked door.
 */
final class TimetableController extends Controller
{
    use ResolvesTheSignedInStudent;

    public function index(Request $request): View
    {
        $student = $this->student($request);

        $from = Carbon::parse((string) $request->input('from', Carbon::today()->startOfWeek()->toDateString()))->startOfDay();
        $to = $from->copy()->addDays(6)->endOfDay();

        $enrollments = StudentBatchEnrollment::query()
            ->where('student_id', $student->getKey())
            ->where('status', EnrollmentStatus::Active->value)
            ->with('batch:id,code,name,course_id')
            ->get();

        $batchIds = $enrollments->pluck('batch_id');

        return view('student.timetable.index', [
            'student' => $student,
            'enrollments' => $enrollments,
            'from' => $from,
            'to' => $to,
            'days' => collect(range(0, 6))->map(fn (int $i) => $from->copy()->addDays($i)),
            'sessions' => ClassSession::query()
                ->whereIn('batch_id', $batchIds)
                ->between($from, $to)
                ->with(['batch:id,code,name', 'teacher:id,name', 'classroom:id,code,name', 'course:id,name'])
                ->orderBy('session_date')
                ->orderBy('start_time')
                ->get()
                ->groupBy(fn (ClassSession $s): string => $s->session_date->toDateString()),
            'weekdays' => Weekday::ordered(),
        ]);
    }
}
