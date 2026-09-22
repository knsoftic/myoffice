<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Enums\ClassSessionStatus;
use App\Enums\StudentAttendanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesTheSignedInStudent;
use App\Models\Institute\StudentAttendance;
use App\Models\Institute\StudentBatchEnrollment;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * A student's own register — `student.attendance.index` (§74, phase-14-17 §7.8, §8.18).
 *
 * **Their own rows and nothing else (INV-I15).** No classmate's name, no classmate's percentage, not
 * even a class average — a student comparing themselves to a number they cannot see the basis of is
 * not information, and a name they should not have is a leak.
 *
 * The percentage shown is the one stored on the enrolment, which the attendance service is the only
 * writer of. Recomputing it here would be a second answer to the same question.
 */
final class AttendanceController extends Controller
{
    use ResolvesTheSignedInStudent;

    public function index(Request $request): View
    {
        $student = $this->student($request);

        $enrollments = StudentBatchEnrollment::query()
            ->where('student_id', $student->getKey())
            ->with(['batch:id,code,name,course_id', 'course:id,name'])
            ->orderByDesc('enrolled_on')
            ->get();

        $rows = StudentAttendance::query()
            ->where('student_id', $student->getKey())
            ->whereHas('session', static fn ($q) => $q->where('status', ClassSessionStatus::Held->value))
            ->with(['session:id,session_date,start_time,end_time,batch_id,course_topic_id', 'batch:id,code'])
            ->orderByDesc('id')
            ->paginate(per_page())
            ->withQueryString();

        $minimum = (string) setting('institute.attendance_minimum_percentage', 75);

        return view('student.attendance.index', [
            'student' => $student,
            'enrollments' => $enrollments->map(function (StudentBatchEnrollment $enrollment) use ($minimum): StudentBatchEnrollment {
                $enrollment->setAttribute(
                    'below_minimum',
                    (int) $enrollment->sessions_expected_count > 0
                        && Money::compare((string) $enrollment->attendance_percentage, $minimum) < 0,
                );

                return $enrollment;
            }),
            'rows' => $rows,
            'minimum' => $minimum,
            'statuses' => StudentAttendanceStatus::cases(),
        ]);
    }
}
