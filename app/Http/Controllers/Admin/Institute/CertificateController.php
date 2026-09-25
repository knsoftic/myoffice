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
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Support\CsvWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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
            // 500, not every row: **a filter `<select>` over a table that grows every term is a page
            // that grows for ever** (phase-24-25 section 6.4, PRF-05). Same ceiling as the finance pickers.
            'courses' => Course::query()->orderBy('name')->limit(500)->get(['id', 'name']),
            'batches' => Batch::query()->orderByDesc('id')->limit(500)->get(['id', 'code', 'name']),
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
    public function eligible(Request $request): View
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

        return view('admin.certificates.eligible', [
            'enrollments' => $enrollments,
            'reports' => $enrollments->getCollection()->mapWithKeys(
                fn (StudentBatchEnrollment $e): array => [$e->getKey() => $this->eligibility->check($e)],
            ),
            'courses' => Course::query()->orderBy('name')->get(['id', 'name']),
            'batches' => Batch::query()->orderByDesc('id')->get(['id', 'code', 'name']),
        ]);
    }

    /**
     * The eligibility report for one enrolment, on its own.
     *
     * **It writes nothing**, which is why it is a GET behind `certificates.create` rather than
     * anything stronger: it is the question "could I certify this student?", asked from a student's
     * page or a batch register without leaving it. The same service answers it as answers it at
     * issue time, so the two can never disagree.
     */
    public function eligibility(Request $request, StudentBatchEnrollment $enrollment): View
    {
        Gate::authorize('create', Certificate::class);

        return view('admin.certificates.eligibility', [
            'enrollment' => $enrollment->load([
                'student:id,name,student_code',
                'batch:id,code,name,course_id',
                'batch.course:id,name,certificate_available',
            ]),
            'report' => $this->eligibility->check($enrollment),
            'existing' => Certificate::query()
                ->where('student_batch_enrollment_id', $enrollment->getKey())
                ->orderByDesc('id')
                ->get(['id', 'certificate_number', 'status', 'issued_on']),
        ]);
    }

    /**
     * The standalone draft form.
     *
     * The eligibility list is where most certificates start, because it shows the verdict beside the
     * name. This exists for the other case: somebody who already knows which enrolment they want and
     * arrived from the student's own page.
     */
    public function create(Request $request): View
    {
        Gate::authorize('create', Certificate::class);

        $enrollment = $request->integer('enrollment') > 0
            ? StudentBatchEnrollment::query()
                ->with(['student:id,name,student_code', 'batch:id,code,name,course_id', 'batch.course:id,name'])
                ->find($request->integer('enrollment'))
            : null;

        return view('admin.certificates.create', [
            'enrollment' => $enrollment,
            'report' => $enrollment === null ? null : $this->eligibility->check($enrollment),
            'batches' => Batch::query()->orderByDesc('id')->get(['id', 'code', 'name']),
            'templates' => PrintTemplate::query()
                ->ofType(PrintTemplateType::Certificate)
                ->active()
                ->ordered()
                ->get(['id', 'code', 'name']),
        ]);
    }

    /**
     * Edit a draft.
     *
     * The policy allows `update` only on a draft and the service refuses anything else, which is
     * two layers for one rule on purpose: `Gate::before` hands a Super Admin past the policy before
     * it runs (D124), so the service is the one that actually holds.
     */
    public function update(Request $request, Certificate $certificate): RedirectResponse
    {
        Gate::authorize('update', $certificate);

        $validated = $request->validate([
            'print_template_id' => ['nullable', 'integer', Rule::exists('print_templates', 'id')->withoutTrashed()],
            'grade_scale_id' => ['nullable', 'integer', Rule::exists('grade_scales', 'id')->withoutTrashed()],
            'completion_date' => ['nullable', 'date'],
            'grade' => ['nullable', 'string', 'max:8'],
            'grade_point' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:10'],
            'percentage' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->certificates->updateDraft($certificate, $validated, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Draft updated.']);
    }

    /**
     * Issue a set of drafts in one go.
     *
     * **Each one is authorised and issued on its own**, and a refusal stops that certificate rather
     * than the batch: an office issuing thirty certificates should not lose twenty-nine because the
     * thirtieth student's fee is outstanding. The result says how many went through and names what
     * stopped the rest, because "24 of 30 issued" with no list is a worse answer than none.
     *
     * No override is accepted here at all. An override is a judgement about one student, recorded
     * with a reason; a reason pasted across thirty certificates is not a judgement, it is a formality.
     */
    public function bulkIssue(Request $request): RedirectResponse
    {
        // No class-level gate here: `CertificatePolicy::changeStatus()` asks about a *certificate*,
        // so there is nothing to ask it at class level. The route carries
        // `can:certificates.change_status`, and each row below is authorised on its own — which is
        // the check that matters, because it is the one that knows about branches.
        $max = max(1, (int) setting('institute.certificate_bulk_issue_max', 100));

        $validated = $request->validate([
            'certificate_ids' => ['required', 'array', 'min:1', 'max:'.$max],
            'certificate_ids.*' => ['integer', Rule::exists('certificates', 'id')],
        ]);

        $issued = 0;
        $refused = [];

        foreach (Certificate::query()->whereIn('id', $validated['certificate_ids'])->get() as $certificate) {
            if ($request->user()?->cannot('changeStatus', $certificate)) {
                $refused[] = sprintf('%s — not yours to issue', $certificate->getAttribute('student_name_snapshot'));

                continue;
            }

            try {
                $this->certificates->issue($certificate, $request->user(), '');
                $issued++;
            } catch (CourseRuleException $e) {
                $refused[] = sprintf(
                    '%s — %s',
                    $certificate->getAttribute('student_name_snapshot'),
                    implode(' ', $e->validator->errors()->all()),
                );
            }
        }

        return back()->with('toast', [
            'type' => $refused === [] ? 'success' : 'warning',
            'message' => $refused === []
                ? sprintf('%s issued.', app_number($issued).' '.($issued === 1 ? 'certificate' : 'certificates'))
                : sprintf(
                    '%d issued. %d left as drafts: %s',
                    $issued,
                    count($refused),
                    implode('; ', array_slice($refused, 0, 5)).(count($refused) > 5 ? ' …' : ''),
                ),
        ]);
    }

    /**
     * Rebuild the stored PDF from the certificate's own snapshots.
     *
     * **It cannot change what the document says.** Everything it renders from is frozen, so this is
     * for an operational accident — a cleared disk, a template whose logo file moved — rather than a
     * correction. A correction is a revocation and a replacement.
     */
    public function regeneratePdf(Request $request, Certificate $certificate): RedirectResponse
    {
        // **`view`, not `update`.** `CertificatePolicy::update()` refuses anything but a draft, and an
        // issued certificate is exactly the case this exists for. The ability comes from the route's
        // `can:certificates.edit`; what the policy is asked here is the branch question.
        Gate::authorize('view', $certificate);

        $this->certificates->regeneratePdf($certificate);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'The PDF has been rebuilt from the stored details. Nothing it says has changed.',
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
            // Grouped for reading aloud down a phone line. Formatted here rather than in the view,
            // because a Blade file reaching into the container for a service is a second place the
            // grouping could be decided.
            'displayCode' => $this->certificates->displayCodeFor($certificate),
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

    /**
     * Print it — rendered HTML, straight to the browser's print dialog.
     *
     * **HTML rather than the PDF**, because a browser prints a page the user can see first, and the
     * PDF route exists for saving and emailing. Both render from the same stored snapshots through
     * the same template, so the two cannot say different things.
     *
     * The print count is bumped here and on `pdf()`: both hand somebody a copy, and the "Reprint #n"
     * line on the sheet is what stops two copies circulating as though both were the original.
     */
    public function print(Request $request, Certificate $certificate): Response
    {
        Gate::authorize('print', $certificate);

        $this->certificates->markPrinted($certificate, $request->user());

        $certificate->refresh();

        return response()->view('admin.certificates.print', [
            'certificate' => $certificate,
            // The template comes with it, because the page has to print at the paper the template
            // chose and under the stylesheet it carries. Resolving it in the view would be a second
            // answer to "which template?" that could disagree with the renderer's.
            'template' => $this->certificates->templateFor($certificate),
            'body' => $this->certificates->renderHtml($certificate),
        ]);
    }

    /**
     * Stream the PDF, generating it on demand when the row has none.
     *
     * **Generated on demand rather than refused**, because a missing `pdf_path` is an operational
     * accident — a cleared disk, a job that never ran — and not a reason to withhold a document
     * somebody is entitled to. It is regenerated from the stored snapshots, so it is byte-identical
     * in content to the original whatever the template has done since.
     */
    public function pdf(Request $request, Certificate $certificate): Response
    {
        Gate::authorize('download', $certificate);

        $this->certificates->markPrinted($certificate, $request->user());

        $bytes = $this->certificates->renderPdf($certificate);

        return response($bytes, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            // `inline`, so it opens in the viewer rather than landing in Downloads. The filename is
            // still set, because "save as" should not offer `pdf.pdf`.
            'Content-Disposition' => sprintf(
                'inline; filename="%s.pdf"',
                (string) ($certificate->getAttribute('certificate_number') ?? 'certificate'),
            ),
            // A certificate names a student. No shared cache, ever (D21's reasoning, applied to a
            // response rather than to a disk).
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Who has scanned this certificate, and from where.
     *
     * Behind `certificates.view_logs` rather than `view`, because it is a different question: that
     * somebody tried four hundred codes from one address is an operational fact about the
     * verification endpoint, not part of a student's record.
     */
    public function verifications(Request $request, Certificate $certificate): View
    {
        Gate::authorize('viewLogs', $certificate);

        return view('admin.certificates.verifications', [
            'certificate' => $certificate,
            'attempts' => $certificate->verifications()
                ->orderByDesc('created_at')
                ->paginate(50),
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

    /**
     * One grouped count for the filter cards, not one count per case.
     *
     * **Four `count(*)`s that differ only in the status they test are a loop, not four screens' worth
     * of work** — phase-24-25 section 11.7 (PRF-02) allows a statement to repeat three times. Every
     * case is still keyed, zero included, so the card row keeps its shape when a status is empty.
     *
     * @return array<string, int>
     */
    private function statusCounts(Request $request): array
    {
        $grouped = $this->filtered($request)
            ->toBase()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $counts = [];

        foreach (CertificateStatus::cases() as $status) {
            $counts[$status->value] = (int) ($grouped[$status->value] ?? 0);
        }

        return $counts;
    }
}
