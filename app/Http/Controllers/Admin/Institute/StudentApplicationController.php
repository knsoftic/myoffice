<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\StudentApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\Institute\Course;
use App\Models\Institute\Student;
use App\Models\Institute\StudentApplication;
use App\Services\Institute\StudentApplicationService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The §67 inbox — `admin.student-applications.*` (phase-14-17 §7.3, §8.6).
 *
 * **There is no destroy action, and no route for one.** The module declares no `delete` ability: a
 * public submission is evidence somebody asked, and it is rejected, marked duplicate or withdrawn —
 * each with a reason that goes on the record.
 *
 * **The review screen shows the referral verdict in words.** "COL-1024 — Ahmed Traders, active — will
 * be attached on conversion" or "code not recognised — nobody will be attached". A reviewer who
 * cannot see what will happen to the attribution is one who finds out afterwards, from the partner.
 */
final class StudentApplicationController extends Controller
{
    public function __construct(
        private readonly StudentApplicationService $applications,
    ) {}

    public function index(Request $request): View
    {
        $query = $this->filtered($request);

        return view('admin.student-applications.index', [
            'applications' => $query->clone()
                ->with(['course:id,name', 'reviewer:id,name', 'collaborator:id,name,referral_code'])
                ->orderByDesc('created_at')
                ->paginate(per_page())
                ->withQueryString(),
            'counts' => $this->counts($request),
            'statuses' => StudentApplicationStatus::options(),
            'courses' => Course::query()->orderBy('name')->pluck('name', 'id'),
            'filters' => $request->only(['status', 'course_id', 'referral', 'q']),
            'duplicateWindow' => (int) setting('institute.application_duplicate_window_days', 7),
            'formOpen' => (bool) setting('institute.admission_open', true)
                && (bool) setting('maintenance.admission_form_enabled', true),
        ]);
    }

    public function show(StudentApplication $application): View
    {
        $application->load([
            'course:id,name,course_fee,admission_fee,registration_fee,monthly_fee,installment_available,max_installments',
            'branch:id,name', 'inquiry:id,inquiry_number,status', 'collaborator:id,name,referral_code,status',
            'reviewer:id,name', 'duplicateOf:id,application_number,created_at,status',
            'convertedStudent:id,name,student_code', 'convertedAdmission:id,admission_number,stage',
        ]);

        return view('admin.student-applications.show', [
            'application' => $application,
            // The flag, never a refusal: the same person really does re-apply months later.
            'duplicates' => $application->possibleDuplicates()
                ->with('course:id,name')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(),
            'referralVerdict' => $this->referralVerdict($application),
            // Offered on the review screen so a reviewer can link an existing person rather than
            // creating a second record for them.
            'existingStudents' => Student::query()
                ->where('phone', $application->phone)
                ->orderBy('name')
                ->limit(5)
                ->get(['id', 'name', 'student_code', 'phone', 'status']),
        ]);
    }

    public function claim(Request $request, StudentApplication $application): RedirectResponse
    {
        $this->applications->claim($application, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'You are reviewing this application.']);
    }

    public function duplicate(Request $request, StudentApplication $application): RedirectResponse
    {
        $validated = $request->validate([
            'duplicate_of_application_id' => ['required', 'integer', Rule::exists('student_applications', 'id')],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $this->applications->markDuplicate(
            $application,
            StudentApplication::query()->findOrFail($validated['duplicate_of_application_id']),
            $validated['reason'],
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Marked as a duplicate.']);
    }

    public function reject(Request $request, StudentApplication $application): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->applications->reject($application, $validated['reason'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Application rejected.']);
    }

    public function withdraw(Request $request, StudentApplication $application): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->applications->withdraw($application, $validated['reason'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Recorded as withdrawn.']);
    }

    /** §2.31 step 3: student, admission and attribution in one transaction, or none of them. */
    public function convert(Request $request, StudentApplication $application): RedirectResponse
    {
        $validated = $request->validate([
            'course_id' => ['nullable', 'integer', Rule::exists('courses', 'id')],
            'existing_student_id' => ['nullable', 'integer', Rule::exists('students', 'id')],
            'counselor_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'admission_date' => ['nullable', 'date'],
            'course_fee' => ['nullable', 'numeric', 'min:0'],
            'admission_fee' => ['nullable', 'numeric', 'min:0'],
            'registration_fee' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'scholarship_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $overrides = array_filter($validated, static fn (mixed $v, string $k): bool => $v !== null && $k !== 'existing_student_id', ARRAY_FILTER_USE_BOTH);

        $admission = isset($validated['existing_student_id'])
            ? $this->applications->linkExistingStudent(
                $application,
                Student::query()->findOrFail($validated['existing_student_id']),
                $overrides,
                $request->user(),
            )
            : $this->applications->convert($application, $overrides, $request->user());

        return redirect()
            ->route('admin.admissions.show', $admission)
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf('Converted. Admission %s is at step one of the pipeline.', $admission->admission_number),
            ]);
    }

    public function export(Request $request, string $format): StreamedResponse
    {
        abort_unless($format === 'csv', 404);

        $rows = $this->filtered($request)->with(['course:id,name', 'collaborator:id,name'])->get();

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');

            fputcsv($out, ['Application #', 'Submitted', 'Name', 'Phone', 'Course', 'Status', 'Referral code', 'Referral valid', 'Partner']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->application_number,
                    app_datetime($row->created_at),
                    $row->name,
                    $row->phone,
                    (string) $row->course?->name,
                    $row->status->label(),
                    (string) $row->referral_code,
                    $row->referral_code_valid ? 'yes' : 'no',
                    (string) $row->collaborator?->name,
                ]);
            }

            fclose($out);
        }, 'applications-'.app_date(Carbon::now(), 'Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return Builder<StudentApplication>
     */
    private function filtered(Request $request)
    {
        return StudentApplication::query()
            ->forBranch($request->user()?->branch_id === null ? null : (int) $request->user()->branch_id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->integer('course_id')))
            ->when($request->input('referral') === 'valid', fn ($q) => $q->where('referral_code_valid', true))
            ->when($request->input('referral') === 'invalid', fn ($q) => $q->whereNotNull('referral_code')->where('referral_code_valid', false))
            ->when($request->input('referral') === 'none', fn ($q) => $q->whereNull('referral_code'))
            ->search($request->string('q')->toString());
    }

    /**
     * @return array<string, int>
     */
    private function counts(Request $request): array
    {
        $branchId = $request->user()?->branch_id === null ? null : (int) $request->user()->branch_id;

        $rows = StudentApplication::query()
            ->forBranch($branchId)
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = [];

        foreach (StudentApplicationStatus::cases() as $case) {
            $counts[$case->value] = (int) ($rows[$case->value] ?? 0);
        }

        return $counts;
    }

    /**
     * What will happen to the attribution if this is converted — in words, before it happens.
     *
     * @return array{tone: string, message: string}
     */
    private function referralVerdict(StudentApplication $application): array
    {
        $code = trim((string) $application->referral_code);

        if ($code === '') {
            return ['tone' => 'slate', 'message' => 'No referral code was quoted. Nobody will be attached.'];
        }

        if (! $application->hasValidReferral()) {
            return [
                'tone' => 'amber',
                'message' => sprintf(
                    '"%s" was quoted but did not resolve to an active partner. It is kept on the record '
                    .'as submitted, and nobody will be attached.',
                    $code,
                ),
            ];
        }

        return [
            'tone' => 'emerald',
            'message' => sprintf(
                '%s — %s, %s. Will be attached on conversion.',
                $code,
                (string) $application->collaborator?->name,
                (string) ($application->collaborator?->status?->label() ?? 'active'),
            ),
        ];
    }
}
