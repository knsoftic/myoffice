<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Enums\ProgressStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teacher\Concerns\ResolvesTheSignedInTeacher;
use App\Models\Institute\Batch;
use App\Models\Institute\BatchTopicCoverage;
use App\Models\Institute\CourseModule;
use App\Models\Institute\CourseTopic;
use App\Models\Institute\StudentBatchEnrollment;
use App\Models\Institute\StudentCourseProgress;
use App\Models\Institute\StudentTopicProgress;
use App\Services\Institute\CourseProgressService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The syllabus, from the teacher's side — `teacher.progress.*` (§73, phase-14-17 §7.9).
 *
 * **`teacher_portal.progress_mark` is separate from `.student_progress`** (§4.3): reading how far a
 * class has got and recording that it got there are two rights, and a visiting trainer may well hold
 * only the first.
 *
 * Scoped to this teacher's own batches, and somebody else's is a 404.
 */
final class ProgressController extends Controller
{
    use ResolvesTheSignedInTeacher;

    public function __construct(
        private readonly CourseProgressService $progress,
    ) {}

    public function show(Request $request, Batch $batch): View
    {
        $this->assertMine($request, $batch);

        $modules = CourseModule::query()
            ->where('course_id', $batch->course_id)
            ->where('is_active', true)
            ->with(['topics' => static fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        $enrollments = StudentBatchEnrollment::query()
            ->where('batch_id', $batch->getKey())
            ->active()
            ->with('student:id,name,student_code')
            ->orderByRaw('CAST(roll_number AS UNSIGNED)')
            ->get();

        $progressRows = StudentCourseProgress::query()
            ->whereIn('student_batch_enrollment_id', $enrollments->pluck('id'))
            ->get()
            ->keyBy('student_batch_enrollment_id');

        return view('teacher.progress.show', [
            'batch' => $batch->load('course:id,name'),
            'modules' => $modules,
            'coverage' => BatchTopicCoverage::query()
                ->where('batch_id', $batch->getKey())
                ->get()
                ->keyBy('course_topic_id'),
            'enrollments' => $enrollments,
            'progressRows' => $progressRows,
            'cells' => StudentTopicProgress::query()
                ->whereIn('student_course_progress_id', $progressRows->pluck('id'))
                ->get()
                ->groupBy('student_course_progress_id'),
            'statuses' => ProgressStatus::options(),
        ]);
    }

    public function markForBatch(Request $request, Batch $batch, CourseTopic $topic): RedirectResponse
    {
        $this->assertMine($request, $batch);

        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(ProgressStatus::class)],
            'completion_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->progress->markTopicForBatch($batch, $topic, $validated, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => '"'.$topic->title.'" recorded for the class.',
        ]);
    }

    public function markForStudent(Request $request, StudentBatchEnrollment $enrollment, CourseTopic $topic): RedirectResponse
    {
        $batch = $enrollment->batch;

        if (! $batch instanceof Batch) {
            throw new NotFoundHttpException;
        }

        $this->assertMine($request, $batch);

        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(ProgressStatus::class)],
            'completion_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $this->progress->markTopicForStudent($enrollment, $topic, $validated, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Set for this student. The class-level mark will not overwrite it.',
        ]);
    }

    private function assertMine(Request $request, Batch $batch): void
    {
        $teacherId = $this->teacher($request)->getKey();

        $mine = (int) $batch->teacher_id === (int) $teacherId
            || $batch->timetableEntries()->where('teacher_id', $teacherId)->exists()
            || $batch->sessions()->where('teacher_id', $teacherId)->exists();

        if (! $mine) {
            throw new NotFoundHttpException;
        }
    }
}
