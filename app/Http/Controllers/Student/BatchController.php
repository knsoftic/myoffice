<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesTheSignedInStudent;
use App\Models\Institute\Batch;
use App\Models\Institute\ClassSession;
use App\Models\Institute\StudentBatchEnrollment;
use App\Models\Institute\Teacher;
use App\Models\Institute\TimetableEntry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A student's own batch and teachers — `student.batches.*`, `student.teachers.*` (§74, §7.8, §8.18).
 *
 * **`{enrollment}` is resolved against this student's own seats.** Somebody else's enrolment id is a
 * 404, because the query never left this student's rows.
 *
 * **The teacher list is name and public bio only.** A phone number on a staff record is not a
 * student's to read, and `public_bio` exists precisely so there is something that is.
 */
final class BatchController extends Controller
{
    use ResolvesTheSignedInStudent;

    public function show(Request $request, StudentBatchEnrollment $enrollment): View
    {
        $student = $this->student($request);

        if ((int) $enrollment->student_id !== (int) $student->getKey()) {
            throw new NotFoundHttpException;
        }

        $batch = $enrollment->batch;

        return view('student.batches.show', [
            'student' => $student,
            'enrollment' => $enrollment->load(['course:id,name']),
            'batch' => $batch?->load(['course:id,name', 'teacher:id,name,slug,public_bio,photo_path', 'classroom:id,code,name']),
            'entries' => $batch instanceof Batch
                ? $batch->timetableEntries()->active()->with('classroom:id,code,name')->orderBy('day_of_week')->orderBy('start_time')->get()
                : collect(),
            'upcoming' => $batch instanceof Batch
                ? $batch->sessions()
                    ->whereIn('status', ['scheduled', 'held'])
                    ->whereDate('session_date', '>=', Carbon::today()->toDateString())
                    ->orderBy('session_date')
                    ->limit(10)
                    ->get()
                : collect(),
        ]);
    }

    public function teachers(Request $request): View
    {
        $student = $this->student($request);

        $batchIds = StudentBatchEnrollment::query()
            ->where('student_id', $student->getKey())
            ->whereIn('status', [EnrollmentStatus::Active->value, EnrollmentStatus::Completed->value])
            ->pluck('batch_id');

        // The people who actually teach them: the batch teacher, whoever holds a slot, and whoever
        // took a class. A substitute who covered one Tuesday is one of their teachers.
        $teacherIds = collect()
            ->merge(Batch::query()->whereIn('id', $batchIds)->pluck('teacher_id'))
            ->merge(TimetableEntry::query()->whereIn('batch_id', $batchIds)->pluck('teacher_id'))
            ->merge(ClassSession::query()->whereIn('batch_id', $batchIds)->pluck('teacher_id'))
            ->filter()
            ->unique()
            ->values();

        return view('student.teachers.index', [
            'student' => $student,
            // Only the columns a student may read (§4.3).
            'teachers' => Teacher::query()
                ->whereIn('id', $teacherIds)
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'photo_path', 'public_bio', 'specialization', 'qualification']),
        ]);
    }
}
