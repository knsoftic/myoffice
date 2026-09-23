<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\CertificateStatus;
use App\Enums\PrintTemplateType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Institute\DraftCertificateRequest;
use App\Models\Institute\Batch;
use App\Models\Institute\Certificate;
use App\Models\Institute\Course;
use App\Models\Institute\PrintTemplate;
use App\Models\Institute\StudentBatchEnrollment;
use App\Services\Institute\CertificateEligibilityService;
use App\Services\Institute\CertificateService;
use App\Support\CsvWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The certificate register — `admin.certificates.*` (§84, phase-19-23 §7.6).
 *
 * **The eligibility screen is the one that matters.** "Why can't I issue this?" is answered by the
 * UI, with each failing rule showing its actual value — attendance 68.50% against 75.00% needed —
 * rather than by a developer reading a log. That is why `check()` returns a list of rows instead of a
 * boolean, and why this controller shows the report before anybody presses anything.
 *
 * **Issuing re-runs eligibility rather than trusting the draft's report.** A fee cleared in March may
 * be outstanding again in June, and the certificate that matters is the one being handed over today.
 * An override is possible for somebody holding `certificates.approve`, and it is recorded with the
 * failing rules intact plus a mandatory reason — never silently.
 */
final class CertificateController extends Controller
{
    public function __construct(
        private readonly CertificateService $certificates,
        private readonly CertificateEligibilityService $eligibility,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Certificate::class);

        $certificates = $this->filtered($request)
            ->with(['student:id,name,student_code', 'course:id,name', 'batch:id,code', 'issuer:id,name'])
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.certificates.index', [
            'certificates' => $certificates,
            'statuses' => CertificateStatus::cases(),
            'courses' => Course::query()->orderBy('name')->get(['id', 'name']),
            'batches' => Batch::query()->orderByDesc('id')->get(['id', 'code', 'name']),
            'counts' => $this->statusCounts($request),
            'canCreate' => (bool) $request->user()?->can('create', Certificate::class),
        ]);
    }

    /**
     * The candidate list: enrolments that could be certified, each with its eligibility verdict.
     *
     * Eligibility is computed per row here rather than filtered in SQL, deliberately — the rules read
     * four different services (INV-23-1), and a query that reproduced them would be a second opinion
     * that could disagree with the one shown when somebody presses issue.
     */
    public function candidates(Request $request): View
    {
        Gate::authorize('create', Certificate::class);

        $enrollments = StudentBatchEnrollment::query()
            ->with(['student:id,name,student_code', 'batch:id,code,name,course_id', 'batch.course:id,name,certificate_available'])
            ->when($request->integer('batch_id') > 0, fn (Builder $q) => $q->where('batch_id', $request->integer('batch_id')))
            ->when($request->integer('course_id') > 0, function (Builder $q) use ($request): void {
                $q->whereHas('batch', fn (Builder $b) => $b->where('course_id', $request->integer('course_id')));
            })
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.certificates.candidates', [
            'enrollments' => $enrollments,
            'reports' => $enrollments->getCollection()->mapWithKeys(
                fn (StudentBatchEnrollment $e): array => [$e->getKey() => $this->eligibility->check($e)],
            ),
            'courses' => Course::query()->orderBy('name')->get(['id', 'name']),
            'batches' => Batch::query()->orderByDesc('id')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(DraftCertificateRequest $request): RedirectResponse
    {
        $enrollment = StudentBatchEnrollment::query()
            ->findOrFail($request->integer('student_batch_enrollment_id'));

        $certificate = $this->certificates->draft(
            $enrollment,
            $request->safe()->except(['student_batch_enrollment_id']),
            $request->user(),
        );

        return redirect()
            ->route('admin.certificates.show', $certificate)
            ->with('toast', [
                'type' => 'success',
                'message' => 'Drafted. It has no number until it is issued.',
            ]);
    }

    public function show(Request $request, Certificate $certificate): View
    {
        Gate::authorize('view', $certificate);

        $enrollment = $certificate->enrollment;

        return view('admin.certificates.show', [
            'certificate' => $certificate->load([
                'student:id,name,student_code', 'course:id,name', 'batch:id,code,name',
                'teacher:id,name', 'branch:id,name', 'template:id,code,name',
                'issuer:id,name', 'revoker:id,name', 'reissueOf:id,certificate_number',
            ]),
            // Re-run live, so the screen shows today's answer rather than the draft's.
            'report' => $enrollment === null ? null : $this->eligibility->check($enrollment),
            'canEdit' => (bool) $request->user()?->can('update', $certificate),
            'canIssue' => (bool) $request->user()?->can('changeStatus', $certificate),
            'canApprove' => (bool) $request->user()?->can('approve', $certificate),
            'canPrint' => (bool) $request->user()?->can('print', $certificate),
            'canDelete' => (bool) $request->user()?->can('delete', $certificate),
            'canViewLogs' => (bool) $request->user()?->can('viewLogs', $certificate),
            'templates' => PrintTemplate::query()
                ->ofType(PrintTemplateType::Certificate)
                ->active()
                ->ordered()
                ->get(['id', 'code', 'name']),
        ]);
    }

    /**
     * Issue it.
     *
     * An override reason is accepted and only *used* when eligibility fails — the service decides,
     * because it is the thing that re-runs the check. Requiring one here would demand a reason from
     * somebody issuing a perfectly eligible certificate.
     */
    public function issue(Request $request, Certificate $certificate): RedirectResponse
    {
        Gate::authorize('changeStatus', $certificate);

        $validated = $request->validate([
            'override_reason' => ['nullable', 'string', 'min:10', 'max:255'],
        ]);

        $override = (string) ($validated['override_reason'] ?? '');

        // An override is a different decision from issuing, and §4.2 gates it separately.
        if ($override !== '') {
            Gate::authorize('approve', $certificate);
        }

        $issued = $this->certificates->issue($certificate, $request->user(), $override);

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('Issued as %s.', (string) $issued->getAttribute('certificate_number')),
        ]);
    }

    public function revoke(Request $request, Certificate $certificate): RedirectResponse
    {
        Gate::authorize('changeStatus', $certificate);

        $validated = $request->validate([
            // Longer minimum than most reasons here: this one is published on a page anybody with
            // the code can read, so "wrong" is not an explanation.
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $this->certificates->revoke($certificate, $validated['reason'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Revoked. Anyone who scans it now reads the reason.',
        ]);
    }

    public function reissue(Request $request, Certificate $certificate): RedirectResponse
    {
        Gate::authorize('changeStatus', $certificate);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ]);

        $replacement = $this->certificates->reissue($certificate, $validated['reason'], $request->user());

        return redirect()
            ->route('admin.certificates.show', $replacement)
            ->with('toast', [
                'type' => 'success',
                'message' => 'A replacement draft is ready, with fresh details. Issue it when you are happy.',
            ]);
    }

    public function destroy(Request $request, Certificate $certificate): RedirectResponse
    {
        Gate::authorize('delete', $certificate);

        $certificate->delete();

        return redirect()
            ->route('admin.certificates.index')
            ->with('toast', ['type' => 'success', 'message' => 'Draft removed.']);
    }

    public function export(Request $request, string $format): StreamedResponse
    {
        Gate::authorize('export', Certificate::class);

        abort_unless($format === 'csv', Response::HTTP_NOT_FOUND);

        return (new CsvWriter)->download(
            'certificates-'.app_date(now(), 'Y-m-d').'.csv',
            ['Number', 'Student', 'Roll', 'Course', 'Batch', 'Completed', 'Grade', 'Percentage', 'Status', 'Issued', 'Prints', 'Verifications'],
            CsvWriter::rowsFrom(
                $this->filtered($request)->with(['student:id,name,student_code', 'course:id,name', 'batch:id,code']),
                static fn (Certificate $c): array => [
                    (string) ($c->getAttribute('certificate_number') ?? ''),
                    (string) $c->getAttribute('student_name_snapshot'),
                    (string) $c->getAttribute('student_code_snapshot'),
                    (string) $c->getAttribute('course_name_snapshot'),
                    (string) ($c->getAttribute('batch_name_snapshot') ?? ''),
                    app_date($c->getAttribute('completion_date')),
                    (string) ($c->getAttribute('grade') ?? ''),
                    (string) ($c->getAttribute('percentage') ?? ''),
                    $c->status->label(),
                    app_date($c->getAttribute('issued_on')),
                    (string) $c->getAttribute('print_count'),
                    (string) $c->getAttribute('verification_count'),
                ],
            ),
        );
    }

    // -------------------------------------------------------------------------------------------

    /**
     * The one filtered query the index, the counts and the export all read, so a figure at the top of
     * the page cannot disagree with the rows underneath it.
     */
    private function filtered(Request $request): Builder
    {
        return Certificate::query()
            ->when($request->string('q')->toString() !== '', function (Builder $query) use ($request): void {
                $term = $request->string('q')->toString();
                $query->where(function (Builder $q) use ($term): void {
                    $q->where('certificate_number', 'like', "%$term%")
                        ->orWhere('student_name_snapshot', 'like', "%$term%")
                        ->orWhere('student_code_snapshot', 'like', "%$term%")
                        ->orWhere('verification_code', 'like', "%$term%");
                });
            })
            ->when($request->string('status')->toString() !== '', fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->integer('course_id') > 0, fn (Builder $q) => $q->where('course_id', $request->integer('course_id')))
            ->when($request->integer('batch_id') > 0, fn (Builder $q) => $q->where('batch_id', $request->integer('batch_id')))
            ->when($request->boolean('trashed'), fn (Builder $q) => $q->onlyTrashed());
    }

    /** @return array<string, int> */
    private function statusCounts(Request $request): array
    {
        $counts = [];

        foreach (CertificateStatus::cases() as $status) {
            $counts[$status->value] = (clone $this->filtered($request))->where('status', $status->value)->count();
        }

        return $counts;
    }
}
