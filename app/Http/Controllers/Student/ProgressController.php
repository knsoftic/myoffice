<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesTheSignedInStudent;
use App\Models\Institute\CourseModule;
use App\Models\Institute\StudentBatchEnrollment;
use App\Models\Institute\StudentCourseProgress;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * A student's own syllabus — `student.progress.index` (§74, phase-14-17 §7.8, §8.18).
 *
 * Read-only, and their own rows only (INV-I15). A student sees how far they have got and where each
 * value came from; they do not see who else has finished what, because that is somebody else's
 * record and comparing the two is not theirs to do here.
 */
final class ProgressController extends Controller
{
    use ResolvesTheSignedInStudent;

    public function index(Request $request): View
    {
        $student = $this->student($request);

        // 50 enrolments, and one progress row per enrolment: **an owner `where` is not a row bound**
        // (phase-24-25 section 6.4, PRF-05), and this screen renders every row it is handed — there is no
        // paginator underneath it to stop at a page.
        $enrollments = StudentBatchEnrollment::query()
            ->where('student_id', $student->getKey())
            ->with(['batch:id,code,name,course_id', 'course:id,name'])
            ->orderByDesc('enrolled_on')
            ->limit(50)
            ->get();

        $progressRows = StudentCourseProgress::query()
            ->where('student_id', $student->getKey())
            ->with(['moduleProgress', 'topicProgress'])
            ->limit(50)
            ->get()
            ->keyBy('student_batch_enrollment_id');

        // The outline, per course, so a module heading has topics under it rather than ids. **A `whereIn`
        // on a foreign key inherits nothing**: 50 courses times a syllabus of any depth is 500 here, which
        // is more modules than the outline of fifty courses can plausibly hold.
        $modules = CourseModule::query()
            ->whereIn('course_id', $enrollments->pluck('course_id')->filter()->unique())
            ->where('is_active', true)
            ->with(['topics' => static fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->limit(500)
            ->get()
            ->groupBy('course_id');

        return view('student.progress.index', [
            'student' => $student,
            'enrollments' => $enrollments,
            'progressRows' => $progressRows,
            'modules' => $modules,
        ]);
    }
}
