<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Teacher\Concerns\ResolvesTheSignedInTeacher;
use App\Models\Institute\Batch;
use App\Models\Institute\StudentBatchEnrollment;
use App\Services\Institute\CertificateEligibilityService;
use App\Support\Institute\TeacherScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Who on a teacher's own batch could be certified — `teacher.certificates.candidates`
 * (phase-19-23 §7.10, §9.3).
 *
 * **Read only, and there is nothing on this panel that writes.** §9.3 is explicit: a teacher gets a
 * 403 on issuing, revoking, replacing and on templates. What they get here is the one thing that is
 * genuinely theirs to know — which of their students have met the rules and which have not, and by
 * how much — because they are the person who can do something about attendance or a missing exam.
 *
 * **The batch is scoped through `TeacherScope`, never through `batches.teacher_id`.** A teacher
 * reaches a batch four ways (D107): named teacher, a timetable entry, a session they taught, or a
 * session moved off them. Filtering on the batch's own teacher would hide a class they have been
 * covering all term.
 *
 * **A batch that is not theirs is a 404.** Not a 403: a 403 confirms the batch exists, which turns
 * an id into something worth guessing (phase-14-17 §9).
 */
final class CertificateController extends Controller
{
    use ResolvesTheSignedInTeacher;

    public function __construct(
        private readonly CertificateEligibilityService $eligibility,
    ) {}

    public function candidates(Request $request, Batch $batch): View
    {
        $teacher = $this->teacher($request);

        if (! TeacherScope::owns($teacher, $batch)) {
            throw new NotFoundHttpException;
        }

        $enrollments = StudentBatchEnrollment::query()
            ->with(['student:id,name,student_code'])
            ->where('batch_id', $batch->getKey())
            ->orderBy('id')
            ->paginate(50);

        return view('teacher.certificates.candidates', [
            'batch' => $batch->load('course:id,name,certificate_available'),
            'enrollments' => $enrollments,
            // Computed per row rather than filtered in SQL, for the same reason the admin screen
            // does it: the rules read four different services (INV-23-1), and a query reproducing
            // them would be a second opinion that could disagree with the one the office sees.
            'reports' => $enrollments->getCollection()->mapWithKeys(
                fn (StudentBatchEnrollment $e): array => [$e->getKey() => $this->eligibility->check($e)],
            ),
            'batches' => Batch::query()
                ->whereIn('id', TeacherScope::batchIds($teacher))
                ->orderByDesc('id')
                ->get(['id', 'code', 'name']),
        ]);
    }
}
