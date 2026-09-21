<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Institute\Batch;
use App\Models\Institute\Student;
use App\Models\Institute\StudentAdmission;
use App\Models\Institute\StudentBatchEnrollment;
use App\Services\Institute\BatchEnrollmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Seats — `admin.batches.enrollments.*` and `admin.enrollments.*` (phase-14-17 §7.5, §8.12).
 *
 * **Every capacity decision is the service's.** This controller validates the shape of the request
 * and hands it over; it never counts students, never reads `current_students`, and never decides
 * whether there is room. A second opinion about capacity is how two people get the last seat.
 */
final class EnrollmentController extends Controller
{
    public function __construct(
        private readonly BatchEnrollmentService $enrollments,
    ) {}

    public function store(Request $request, Batch $batch): RedirectResponse
    {
        $validated = $request->validate([
            'student_id' => ['required', 'integer', Rule::exists('students', 'id')->whereNull('deleted_at')],
            'student_admission_id' => ['nullable', 'integer', Rule::exists('student_admissions', 'id')->whereNull('deleted_at')],
            'enrolled_on' => ['nullable', 'date'],
            'roll_number' => ['nullable', 'string', 'max:16'],
            'overbook' => ['nullable', 'boolean'],
            'overbook_reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $student = Student::query()->findOrFail($validated['student_id']);
        $admission = isset($validated['student_admission_id'])
            ? StudentAdmission::query()->find($validated['student_admission_id'])
            : null;

        $enrollment = $this->enrollments->enroll($student, $batch, $admission, [
            'enrolled_on' => $validated['enrolled_on'] ?? null,
            'roll_number' => $validated['roll_number'] ?? null,
            'overbook' => $request->boolean('overbook'),
            'overbook_reason' => $validated['overbook_reason'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ], $request->user());

        // A clash with the student's other batches is a warning, never a refusal (§6.6 step 11).
        $clashes = $this->enrollments->overlapsWithOtherBatches($student, $batch);

        return back()->with('toast', $clashes === []
            ? ['type' => 'success', 'message' => $student->name.' has been enrolled as roll number '.$enrollment->roll_number.'.']
            : ['type' => 'warning', 'message' => $student->name.' has been enrolled, but this batch overlaps '
                .implode(', ', array_map(static fn (array $c): string => $c['batch'].' ('.$c['day'].' '.$c['window'].')', $clashes))
                .'. Check they can attend both.']);
    }

    public function transfer(Request $request, StudentBatchEnrollment $enrollment): RedirectResponse
    {
        $validated = $request->validate([
            'batch_id' => ['required', 'integer', Rule::exists('batches', 'id')->whereNull('deleted_at')],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $target = Batch::query()->findOrFail($validated['batch_id']);

        $successor = $this->enrollments->transfer($enrollment, $target, $validated['reason'], $request->user());

        return redirect()
            ->route('admin.batches.show', $target)
            ->with('toast', [
                'type' => 'success',
                'message' => 'Transferred to '.$target->label().' as roll number '.$successor->roll_number
                    .'. The attendance stays with the old batch, where it happened.',
            ]);
    }

    public function status(Request $request, StudentBatchEnrollment $enrollment): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(EnrollmentStatus::class)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $to = EnrollmentStatus::from($validated['status']);

        $this->enrollments->changeStatus($enrollment, $to, $validated['reason'] ?? null, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'The enrolment is now '.$to->label().'.',
        ]);
    }
}
