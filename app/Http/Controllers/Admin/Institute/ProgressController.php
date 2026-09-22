<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\ProgressStatus;
use App\Http\Controllers\Controller;
use App\Models\Institute\Batch;
use App\Models\Institute\BatchTopicCoverage;
use App\Models\Institute\ClassSession;
use App\Models\Institute\Course;
use App\Models\Institute\CourseModule;
use App\Models\Institute\CourseTopic;
use App\Models\Institute\StudentBatchEnrollment;
use App\Models\Institute\StudentCourseProgress;
use App\Models\Institute\StudentTopicProgress;
use App\Services\Institute\CourseProgressService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The syllabus — `admin.progress.*` (§83, phase-14-17 §7.7, §8.17).
 *
 * **The controller writes no percentage.** `CourseProgressService::recompute()` is the only writer
 * (INV-I11); this validates a mark and hands it over. A controller that computed a roll-up would be a
 * second answer to "how far through is she", and the two would diverge the first time a topic was
 * deactivated.
 */
final class ProgressController extends Controller
{
    public function __construct(
        private readonly CourseProgressService $progress,
    ) {}

    /**
     * Every batch, with the share of its syllabus it has got through.
     */
    public function index(Request $request): View
    {
        return view('admin.progress.index', [
            'batches' => $this->filtered($request)
                ->with(['course:id,name', 'teacher:id,name'])
                ->orderByDesc('start_date')
                ->paginate(per_page())
                ->withQueryString(),
            'courses' => Course::query()->orderBy('name')->pluck('name', 'id'),
            'filters' => $request->only(['course_id', 'q']),
        ]);
    }

    /**
     * The §8.17 board: students down, topics across, grouped under their modules.
     */
    public function batch(Request $request, Batch $batch): View
    {
        $modules = CourseModule::query()
            ->where('course_id', $batch->course_id)
            ->where('is_active', true)
            ->with(['topics' => static fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        $coverage = BatchTopicCoverage::query()
            ->where('batch_id', $batch->getKey())
            ->get()
            ->keyBy('course_topic_id');

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

        // enrollment => topic => row
        $cells = StudentTopicProgress::query()
            ->whereIn('student_course_progress_id', $progressRows->pluck('id'))
            ->get()
            ->groupBy('student_course_progress_id');

        return view('admin.progress.batch', [
            'batch' => $batch->load(['course:id,name', 'teacher:id,name']),
            'modules' => $modules,
            'coverage' => $coverage,
            'enrollments' => $enrollments,
            'progressRows' => $progressRows,
            'cells' => $cells,
            'statuses' => ProgressStatus::options(),
            'sessions' => ClassSession::query()
                ->where('batch_id', $batch->getKey())
                ->whereIn('status', ['held', 'scheduled'])
                ->orderByDesc('session_date')
                ->limit(30)
                ->get(['id', 'session_date', 'start_time']),
            'weighting' => (string) setting('institute.progress_weighting', 'topic_weight'),
        ]);
    }

    public function markForBatch(Request $request, Batch $batch, CourseTopic $topic): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(ProgressStatus::class)],
            'completion_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'covered_on' => ['nullable', 'date'],
            'class_session_id' => ['nullable', 'integer', Rule::exists('class_sessions', 'id')->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->progress->markTopicForBatch($batch, $topic, $validated, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => '"'.$topic->title.'" recorded for the class. Anybody whose row was set by hand '
                .'keeps their own value.',
        ]);
    }

    public function skip(Request $request, Batch $batch, CourseTopic $topic): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->progress->skipTopic($batch, $topic, $validated['reason'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => '"'.$topic->title.'" has been dropped from this batch. Its weight leaves every '
                .'denominator, so the percentages went up.',
        ]);
    }

    /**
     * One student: module accordions, topic rows, and where each value came from.
     */
    public function student(Request $request, StudentBatchEnrollment $enrollment): View
    {
        $progress = StudentCourseProgress::query()
            ->where('student_batch_enrollment_id', $enrollment->getKey())
            ->first() ?? $this->progress->openFor($enrollment, $request->user());

        $modules = CourseModule::query()
            ->where('course_id', $enrollment->course_id)
            ->where('is_active', true)
            ->with(['topics' => static fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        return view('admin.progress.student', [
            'enrollment' => $enrollment->load(['student:id,name,student_code', 'batch:id,code,name', 'course:id,name']),
            'progress' => $progress,
            'modules' => $modules,
            'moduleRows' => $progress->moduleProgress()->get()->keyBy('course_module_id'),
            'topicRows' => $progress->topicProgress()->with('marker:id,name')->get()->keyBy('course_topic_id'),
            'statuses' => ProgressStatus::options(),
        ]);
    }

    public function markForStudent(Request $request, StudentBatchEnrollment $enrollment, CourseTopic $topic): RedirectResponse
    {
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

    public function recompute(Request $request, StudentBatchEnrollment $enrollment): RedirectResponse
    {
        $progress = StudentCourseProgress::query()
            ->where('student_batch_enrollment_id', $enrollment->getKey())
            ->first() ?? $this->progress->openFor($enrollment, $request->user());

        $this->progress->recompute($progress, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Recomputed from the topic rows — which is the only way any of these numbers is '
                .'ever written.',
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse
    {
        abort_unless(in_array($format, ['csv'], true), 404);

        $rows = DB::table('student_course_progress as scp')
            ->join('students as st', 'st.id', '=', 'scp.student_id')
            ->join('batches as b', 'b.id', '=', 'scp.batch_id')
            ->leftJoin('courses as c', 'c.id', '=', 'scp.course_id')
            ->whereNull('scp.deleted_at')
            ->when($request->filled('course_id'), fn ($q) => $q->where('scp.course_id', $request->integer('course_id')))
            ->when($request->user()?->branch_id !== null, fn ($q) => $q->where(function ($inner) use ($request): void {
                $inner->where('b.branch_id', $request->user()->branch_id)->orWhereNull('b.branch_id');
            }))
            ->orderBy('b.code')
            ->orderBy('st.name')
            ->get([
                'st.name as student_name', 'st.student_code', 'b.code as batch_code',
                'c.name as course_name', 'scp.status', 'scp.completion_percentage',
                'scp.topics_completed', 'scp.topics_total', 'scp.modules_completed',
                'scp.modules_total', 'scp.last_activity_at',
            ]);

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, ['Student', 'Code', 'Batch', 'Course', 'Status', '%', 'Topics done', 'Topics', 'Modules done', 'Modules', 'Last activity']);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->student_name, $row->student_code, $row->batch_code, $row->course_name,
                    $row->status, $row->completion_percentage,
                    $row->topics_completed, $row->topics_total,
                    $row->modules_completed, $row->modules_total, $row->last_activity_at,
                ]);
            }

            fclose($handle);
        }, 'progress-'.Carbon::now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function filtered(Request $request): Builder
    {
        return Batch::query()
            ->forBranch($request->user()?->branch_id === null ? null : (int) $request->user()->branch_id)
            ->when($request->filled('course_id'), fn (Builder $q) => $q->where('course_id', $request->integer('course_id')))
            ->when($request->filled('q'), function (Builder $q) use ($request): void {
                $term = '%'.$request->string('q').'%';

                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('code', 'like', $term)->orWhere('name', 'like', $term);
                });
            });
    }
}
