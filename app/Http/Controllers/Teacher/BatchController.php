<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teacher\Concerns\ResolvesTheSignedInTeacher;
use App\Models\Institute\Batch;
use App\Models\Institute\StudentBatchEnrollment;
use App\Services\Institute\BatchEnrollmentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A teacher's own batches and students — `teacher.batches.*` (§73, phase-14-17 §7.9, §8.19).
 *
 * **Scoped by the teacher, not by the id in the URL.** Every query starts from the signed-in
 * teacher's rows, so another teacher's batch id is a 404 and not a 403 — a 403 would confirm the
 * batch exists, which is half of what somebody probing ids wanted to know.
 */
final class BatchController extends Controller
{
    use ResolvesTheSignedInTeacher;

    public function __construct(
        private readonly BatchEnrollmentService $enrollments,
    ) {}

    public function index(Request $request): View
    {
        $teacher = $this->teacher($request);

        return view('teacher.batches.index', [
            'teacher' => $teacher,
            'batches' => $this->ownBatches($teacher->getKey())
                ->with(['course:id,name', 'classroom:id,code,name'])
                ->orderByDesc('start_date')
                ->paginate(per_page())
                ->withQueryString(),
        ]);
    }

    public function show(Request $request, Batch $batch): View
    {
        $teacher = $this->teacher($request);

        $this->assertMine($teacher->getKey(), $batch);

        return view('teacher.batches.show', [
            'teacher' => $teacher,
            'batch' => $batch->load(['course:id,name', 'classroom:id,code,name']),
            'roster' => $this->enrollments->roster($batch),
            'entries' => $batch->timetableEntries()->active()->orderBy('day_of_week')->orderBy('start_time')->get(),
            'upcoming' => $batch->sessions()
                ->where('status', 'scheduled')
                ->whereDate('session_date', '>=', Carbon::today()->toDateString())
                ->orderBy('session_date')
                ->limit(10)
                ->get(),
        ]);
    }

    /** Everybody currently in one of this teacher's batches, and nobody else. */
    public function students(Request $request): View
    {
        $teacher = $this->teacher($request);

        $batchIds = $this->ownBatches($teacher->getKey())->pluck('id');

        return view('teacher.students.index', [
            'teacher' => $teacher,
            'enrollments' => StudentBatchEnrollment::query()
                ->whereIn('batch_id', $batchIds)
                ->where('status', EnrollmentStatus::Active->value)
                ->with(['student:id,name,student_code,phone,photo_path', 'batch:id,code,name'])
                ->when($request->filled('batch_id'), fn ($q) => $q->where('batch_id', $request->integer('batch_id')))
                ->when($request->filled('q'), function ($q) use ($request): void {
                    $term = '%'.$request->string('q').'%';

                    $q->whereHas('student', fn ($s) => $s->where('name', 'like', $term)
                        ->orWhere('student_code', 'like', $term));
                })
                ->orderBy('batch_id')
                ->orderByRaw('CAST(roll_number AS UNSIGNED)')
                ->paginate(per_page())
                ->withQueryString(),
            'batches' => Batch::query()->whereIn('id', $batchIds)->orderBy('code')->pluck('code', 'id'),
            'filters' => $request->only(['batch_id', 'q']),
        ]);
    }

    /**
     * A batch is this teacher's if they own it, or if they hold one of its slots or classes — a
     * substitute teaches a batch they were never assigned to, and still needs the roster.
     */
    private function ownBatches(int $teacherId)
    {
        return Batch::query()->where(function ($q) use ($teacherId): void {
            $q->where('teacher_id', $teacherId)
                ->orWhereHas('timetableEntries', fn ($e) => $e->where('teacher_id', $teacherId))
                ->orWhereHas('sessions', fn ($s) => $s->where('teacher_id', $teacherId));
        });
    }

    private function assertMine(int $teacherId, Batch $batch): void
    {
        $mine = $this->ownBatches($teacherId)->whereKey($batch->getKey())->exists();

        if (! $mine) {
            throw new NotFoundHttpException;
        }
    }
}
