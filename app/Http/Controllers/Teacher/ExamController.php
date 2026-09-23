<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Enums\ExamStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teacher\Concerns\ResolvesTheSignedInTeacher;
use App\Models\Institute\Exam;
use App\Models\Institute\ExamResult;
use App\Support\Institute\TeacherScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A teacher's exams — `teacher.exams.*` (phase-19-23 §7.5).
 *
 * **Scoped through `TeacherScope`, not through `exams.teacher_id`.** A teacher reaches a batch four
 * ways (D107): named on it, on its timetable, teaching its sessions, or covering one as a substitute.
 * Filtering on the exam's own `teacher_id` would show a class teacher nothing for a paper a colleague
 * was named examiner on — which is a paper their own students are sitting. Phase 19 wrote the union
 * down once so that this phase does not get its own fifth answer.
 *
 * **Drafts are visible here and not on the student panel.** A teacher is one of the people setting the
 * paper; a student learning about an exam from a draft would be learning about one that may not happen.
 */
final class ExamController extends Controller
{
    use ResolvesTheSignedInTeacher;

    public function index(Request $request): View
    {
        $teacher = $this->teacher($request);
        $batchIds = TeacherScope::batchIds($teacher);

        $exams = Exam::query()
            ->whereIn('batch_id', $batchIds)
            ->where('status', '!=', ExamStatus::Cancelled->value)
            ->with(['course:id,name', 'batch:id,code,name', 'classroom:id,code,name'])
            ->when($request->integer('batch_id') > 0 && in_array($request->integer('batch_id'), $batchIds, true),
                fn ($q) => $q->where('batch_id', $request->integer('batch_id')))
            ->when($request->string('status')->toString() !== '', fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->string('when')->toString() === 'past',
                fn ($q) => $q->where('scheduled_date', '<', now()->toDateString())->orderByDesc('scheduled_date'),
                fn ($q) => $q->orderBy('scheduled_date'))
            ->paginate(15)
            ->withQueryString();

        return view('teacher.exams.index', [
            'exams' => $exams,
            'statuses' => ExamStatus::cases(),
            // Counted over the scope, not the page — the badge should not move as somebody pages.
            'awaitingMarks' => Exam::query()
                ->whereIn('batch_id', $batchIds)
                ->whereIn('status', [ExamStatus::Conducted->value, ExamStatus::Marking->value])
                ->count(),
        ]);
    }

    public function show(Request $request, Exam $exam): View
    {
        $teacher = $this->teacher($request);

        if (! TeacherScope::owns($teacher, (int) $exam->getAttribute('batch_id'))) {
            throw new NotFoundHttpException;
        }

        return view('teacher.exams.show', [
            'exam' => $exam->load(['course:id,name', 'batch:id,code,name', 'classroom:id,code,name', 'topic:id,title', 'scale:id,code,name']),
            // Whether the marking sheet button appears at all. The sheet itself re-checks; this only
            // decides whether to offer a link that would 403.
            'canMark' => $exam->acceptsResultEntry()
                && $request->user()?->can('create', ExamResult::class) === true,
        ]);
    }
}
