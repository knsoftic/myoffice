<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\AdmissionStage;
use App\Enums\DeliveryMode;
use App\Enums\PreferredTiming;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Institute\StoreAdmissionRequest;
use App\Models\Institute\Course;
use App\Models\Institute\Student;
use App\Models\Institute\StudentAdmission;
use App\Models\User;
use App\Services\Institute\AdmissionService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * §68's pipeline as a stepper — `admin.admissions.*` (§69, phase-14-17 §7.4, §8.8).
 *
 * **Each action is one step, and the service asserts the step before it.** Nothing here computes a
 * stage or writes one directly; calling `activate` on an admission at `application` throws and names
 * the step that was skipped, which is the whole reason the pipeline lives in one column.
 *
 * **The money panel is read-only once locked, and this controller does not decide that either.**
 * `StudentAdmissionPolicy::updateFigures()` is false after the first charge, so the route is a 403
 * and the screen renders the fields disabled with the reason (INV-I2).
 */
final class AdmissionController extends Controller
{
    public function __construct(
        private readonly AdmissionService $admissions,
    ) {}

    public function index(Request $request): View
    {
        $query = $this->filtered($request);

        return view('admin.admissions.index', [
            'admissions' => $query->clone()
                ->with(['student:id,name,student_code,phone', 'course:id,name', 'counselor:id,name'])
                ->orderByDesc('admission_date')
                ->orderByDesc('id')
                ->paginate(per_page())
                ->withQueryString(),
            'stages' => AdmissionStage::options(),
            'courses' => Course::query()->orderBy('name')->pluck('name', 'id'),
            'counselors' => User::query()->permission('admissions.create')->orderBy('name')->pluck('name', 'id'),
            'filters' => $request->only(['stage', 'course_id', 'counselor_id', 'q']),
            'canSeeMoney' => $request->user()?->can('admissions.view_financial') ?? false,
            'stats' => $this->stats($request),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.admissions.create', [
            'students' => Student::query()->orderByDesc('id')->limit(50)->get(['id', 'name', 'student_code', 'phone']),
            /*
            | The category name comes back on this same statement, by join.
            |
            | `admin.admissions.create` carries a measured `query_budget` of 20 in
            | tests/Support/screen-manifest.php, and PRF-01 compares against it as a ceiling. Eager
            | loading the relation would be a second query for a label; a left join is a wider row on
            | the query that was already being run. 34 courses across 8 categories is a list worth
            | grouping, and it costs nothing to group it.
            */
            'courses' => Course::query()
                ->published()
                ->leftJoin('course_categories', 'course_categories.id', '=', 'courses.course_category_id')
                ->orderBy('course_categories.name')
                ->orderBy('courses.name')
                ->get([
                    'courses.id', 'courses.name', 'courses.course_fee', 'courses.admission_fee',
                    'courses.registration_fee', 'courses.monthly_fee', 'courses.installment_available',
                    'courses.max_installments',
                    'course_categories.name as category_name',
                ]),
            'modes' => DeliveryMode::options(),
            'timings' => PreferredTiming::options(),
            'counselors' => User::query()->permission('admissions.create')->orderBy('name')->pluck('name', 'id'),
            'selectedStudent' => $request->filled('student_id')
                ? Student::query()->find($request->integer('student_id'))
                : null,
        ]);
    }

    /**
     * One student, one or more courses, one submit ([D172]).
     *
     * `createMany()` holds every rule; this method's only judgement is where to send the operator
     * afterwards. One course behaves exactly as it did before — that admission's own page — because
     * with one admission there is nothing to compare and the stepper is the next thing they want.
     * Several go to the list filtered to the student, which is the only screen that can show a basket.
     */
    public function store(StoreAdmissionRequest $request): RedirectResponse
    {
        $student = Student::query()->findOrFail($request->integer('student_id'));

        $admissions = $this->admissions->createMany(
            $student,
            $request->lines(),
            $request->shared(),
            $request->user(),
        );

        if ($admissions->count() === 1) {
            $only = $admissions->first();

            return redirect()
                ->route('admin.admissions.show', $only)
                ->with('toast', ['type' => 'success', 'message' => sprintf('Admission %s started.', $only->admission_number)]);
        }

        return redirect()
            ->route('admin.admissions.index', ['q' => $student->student_code])
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf(
                    '%d admissions started for %s: %s. Each one has its own fee, batch and certificate, '
                    .'and its own steps to walk through.',
                    $admissions->count(),
                    $student->name,
                    $admissions->pluck('admission_number')->implode(', '),
                ),
            ]);
    }

    public function show(Request $request, StudentAdmission $admission): View
    {
        $admission->load([
            'student:id,name,student_code,registration_number,phone,email,status,branch_id',
            'course:id,name,installment_available,max_installments',
            'branch:id,name', 'counselor:id,name', 'collaborator:id,name,referral_code',
            'application:id,application_number,status', 'inquiry:id,inquiry_number',
        ]);

        return view('admin.admissions.show', [
            'admission' => $admission,
            'stages' => AdmissionStage::cases(),
            'canSeeMoney' => $request->user()?->can('admissions.view_financial') ?? false,
            // The checklist §8.8's last step renders, each item linked to the step that fills it.
            'activationGaps' => $this->admissions->activationGaps($admission),
            'feeRule' => (string) setting('institute.require_fee_before_activation', 'any_payment'),
            'modes' => DeliveryMode::options(),
            'timings' => PreferredTiming::options(),
        ]);
    }

    public function figures(Request $request, StudentAdmission $admission): RedirectResponse
    {
        $validated = $request->validate([
            'course_fee' => ['required', 'numeric', 'min:0'],
            'admission_fee' => ['required', 'numeric', 'min:0'],
            'registration_fee' => ['required', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'scholarship_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
            'monthly_fee' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $this->admissions->updateFigures($admission, $validated, $validated['reason'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Agreed figures updated.']);
    }

    public function register(Request $request, StudentAdmission $admission): RedirectResponse
    {
        $admission = $this->admissions->register($admission, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('Registered as %s.', (string) $admission->student->registration_number),
        ]);
    }

    public function fees(Request $request, StudentAdmission $admission): RedirectResponse
    {
        $validated = $request->validate([
            'installments' => ['nullable', 'integer', 'min:0', 'max:36'],
            'first_due_date' => ['nullable', 'date'],
        ]);

        $this->admissions->requestFees($admission, $validated, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Fee structure requested.']);
    }

    public function batch(Request $request, StudentAdmission $admission): RedirectResponse
    {
        $validated = $request->validate([
            'batch_id' => ['required', 'integer'],
            'overbook' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->admissions->assignBatch($admission, (int) $validated['batch_id'], $validated, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Batch assigned.']);
    }

    public function activate(Request $request, StudentAdmission $admission): RedirectResponse
    {
        $this->admissions->activate($admission, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'The student is now active.']);
    }

    public function complete(Request $request, StudentAdmission $admission): RedirectResponse
    {
        $this->admissions->complete($admission, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Marked complete.']);
    }

    public function cancel(Request $request, StudentAdmission $admission): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->admissions->cancel($admission, $validated['reason'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Admission cancelled.']);
    }

    public function withdraw(Request $request, StudentAdmission $admission): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->admissions->withdraw($admission, $validated['reason'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Recorded as withdrawn.']);
    }

    public function transfer(Request $request, StudentAdmission $admission): RedirectResponse
    {
        $request->validate([
            'batch_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        // A transfer moves a seat, carries progress and hands the fee side to Phase 18 — all of which
        // belongs to BatchEnrollmentService (§6.6). Saying so is better than half of it.
        return back()->with('toast', [
            'type' => 'warning',
            'message' => 'Transfers move a seat, carry progress across and hand the fee side to the fee '
                .'service. That is BatchEnrollmentService, which ships with Phase 16.',
        ]);
    }

    public function print(Request $request, StudentAdmission $admission): View|Response
    {
        $admission->load(['student', 'course:id,name,code', 'branch:id,name', 'counselor:id,name']);

        return view('admin.admissions.print', [
            'admission' => $admission,
            'canSeeMoney' => $request->user()?->can('admissions.view_financial') ?? false,
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse
    {
        abort_unless($format === 'csv', 404);

        $canSeeMoney = $request->user()?->can('admissions.view_financial') ?? false;

        // A finance export without the figures is a list of reference numbers, so it is refused
        // outright rather than served with the columns removed (the Phase 13 rule, §8.13).
        abort_unless($canSeeMoney, 403);

        $rows = $this->filtered($request)->with(['student:id,name,student_code', 'course:id,name'])->get();

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');

            fputcsv($out, ['Admission #', 'Date', 'Student code', 'Student', 'Course', 'Stage', 'Total', 'Discount', 'Scholarship', 'Net payable', 'Charged', 'Paid', 'Balance']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->admission_number,
                    app_date($row->admission_date),
                    (string) $row->student?->student_code,
                    (string) $row->student?->name,
                    (string) $row->course?->name,
                    $row->stage->label(),
                    $row->total_amount,
                    $row->discount_amount,
                    $row->scholarship_amount,
                    $row->net_payable,
                    $row->charged_amount,
                    $row->paid_amount,
                    $row->balance_amount,
                ]);
            }

            fclose($out);
        }, 'admissions-'.app_date(Carbon::now(), 'Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return Builder<StudentAdmission>
     */
    private function filtered(Request $request)
    {
        return StudentAdmission::query()
            ->forBranch($request->user()?->branch_id === null ? null : (int) $request->user()->branch_id)
            ->when($request->filled('stage'), fn ($q) => $q->where('stage', $request->string('stage')->toString()))
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->integer('course_id')))
            ->when($request->filled('counselor_id'), fn ($q) => $q->where('counselor_id', $request->integer('counselor_id')))
            ->when($request->filled('q'), function ($q) use ($request): void {
                $like = '%'.$request->string('q')->toString().'%';

                $q->where(function ($inner) use ($like): void {
                    $inner->where('admission_number', 'like', $like)
                        ->orWhereHas('student', fn ($s) => $s->where('name', 'like', $like)
                            ->orWhere('student_code', 'like', $like)
                            ->orWhere('phone', 'like', $like));
                });
            });
    }

    /**
     * @return array<string, int>
     */
    private function stats(Request $request): array
    {
        $branchId = $request->user()?->branch_id === null ? null : (int) $request->user()->branch_id;
        $scoped = fn () => StudentAdmission::query()->forBranch($branchId);

        return [
            'live' => (int) $scoped()->live()->count(),
            'this_month' => (int) $scoped()->whereBetween('admission_date', [
                Carbon::now()->startOfMonth()->toDateString(), Carbon::now()->endOfMonth()->toDateString(),
            ])->count(),
            'awaiting_registration' => (int) $scoped()->atStage(AdmissionStage::Application)->count(),
            'awaiting_activation' => (int) $scoped()->atStage(AdmissionStage::BatchAssignment)->count(),
        ];
    }
}
