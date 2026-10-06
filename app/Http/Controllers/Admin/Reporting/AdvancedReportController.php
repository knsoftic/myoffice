<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reporting;

use App\DataObjects\Reporting\AdvancedReportFilters;
use App\Enums\ExportFormat;
use App\Enums\ReportPaymentStatus;
use App\Enums\ReportStudentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reporting\AdvancedReportRequest;
use App\Models\Institute\Student;
use App\Models\User;
use App\Services\Reporting\AdvancedReportExporter;
use App\Services\Reporting\AdvancedStudentReportService;
use App\Support\Modules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Advanced Reports — students, enrolments and fees on one filterable screen (D177).
 *
 * **Thin on purpose.** The filters are read once, by `AdvancedReportRequest::toData()`, into the one
 * `AdvancedReportFilters` every action hands on; the rows, the cards and the detail page come from
 * `AdvancedStudentReportService`; the files come from `AdvancedReportExporter`. That is the whole
 * guarantee that an export is exactly the filtered set on the screen: there is no second place the
 * filters are interpreted.
 *
 * **Exactly one permission per action, and it is the route's.** SEC-21(b) hands a user only the
 * route's own `can:` ability and expects no 403, so nothing here authorises anything else. The money
 * ability (`advanced_reports.view_financial`) is *asked*, inside the service, and only hides the fee
 * columns, cards and payment history — it never refuses the page.
 *
 * **Its source module is a route gate, not a check in here.** Every route carries
 * `module:advanced_reports,students`, so with Students switched off the whole report answers the 403
 * every disabled module answers; with Student Fees off the service withholds the money
 * (`canSeeMoney()`).
 *
 * Another branch's student is a 404 (the service throws `ModelNotFoundException`): a 403 would
 * confirm the record exists.
 */
final class AdvancedReportController extends Controller
{
    public function __construct(
        private readonly AdvancedStudentReportService $reports,
        private readonly AdvancedReportExporter $exporter,
    ) {}

    /**
     * The screen: filters, summary cards, one page of rows, the export menu.
     */
    public function index(AdvancedReportRequest $request): View
    {
        $user = $request->user();
        $filters = $request->toData();

        // A course or batch this viewer cannot filter by (a stale bookmark, another branch's id) is
        // dropped by toData(); say so, rather than let the full list pass for the filtered one.
        if (($ignored = $request->ignoredFilters()) !== []) {
            session()->now('toast', [
                'type' => 'warning',
                'title' => 'Filter not applied',
                'message' => count($ignored) === 1
                    ? sprintf('The selected %s is not available to you, so that filter was ignored.', $ignored[0])
                    : 'The selected course and batch are not available to you, so those filters were ignored.',
            ]);
        }

        return view('admin.advanced-reports.index', [
            'filters' => $filters,
            'rows' => $this->reports->paginate($filters, $user),
            'summary' => $this->reports->summary($filters, $user),
            'canSeeMoney' => $this->reports->canSeeMoney($user),
            'periodOptions' => AdvancedReportFilters::PERIODS,
            'courseOptions' => $this->reports->courseOptions($user),
            // Narrowed to the chosen course on the server too, so the select is right without JS.
            'batchOptions' => $this->reports->batchOptions($filters->courseId, $user),
            'statusOptions' => ReportStudentStatus::options(),
            'paymentOptions' => ReportPaymentStatus::options(),
            'breadcrumbs' => $this->breadcrumbs($user),
        ]);
    }

    /**
     * One student: personal details, the selected admission's enrolment, progress and batch, and —
     * for a viewer who may see money — the fees and the payment history.
     *
     * `?admission=` picks the course; one that is not this student's is ignored, not obeyed.
     */
    public function show(Request $request, Student $student): View
    {
        $user = $request->user();
        $admission = $request->integer('admission');

        $detail = $this->reports->studentDetail($student, $admission > 0 ? $admission : null, $user);

        return view('admin.advanced-reports.show', $detail + [
            'canSeeMoney' => (bool) $detail['can_see_money'],
            'backUrl' => $this->backToIndex(),
            'breadcrumbs' => $this->breadcrumbs($user, (string) $student->name),
        ]);
    }

    /**
     * `[{id, label}]` — the batch select's options for a course, as JSON: the same list the page
     * renders on the server once a course is chosen. Branch-scoped by the service; a course the
     * viewer cannot see simply has no batches to offer.
     */
    public function batchOptions(AdvancedReportRequest $request): JsonResponse
    {
        $courseId = $request->validated('course_id');
        $options = $this->reports->batchOptions($courseId === null ? null : (int) $courseId, $request->user());

        return response()->json(array_map(
            static fn (int $id, string $label): array => ['id' => $id, 'label' => $label],
            array_keys($options),
            array_values($options),
        ));
    }

    /**
     * CSV, Excel or PDF of exactly the filtered set — or a redirect back to the same filters with a
     * warning when it is too large for that format.
     */
    public function export(AdvancedReportRequest $request, string $format): Response
    {
        $exportFormat = ExportFormat::tryFrom($format);

        abort_if($exportFormat === null || $exportFormat === ExportFormat::Print, 404);

        $user = $request->user();
        $filters = $request->toData();
        $rows = $this->reports->count($filters, $user);

        if (($refusal = $this->exporter->refusal($exportFormat, $rows, $user)) !== null) {
            return $this->refuse($filters, $refusal);
        }

        return match ($exportFormat) {
            ExportFormat::Csv => $this->exporter->csv($filters, $user, $rows),
            ExportFormat::Excel => $this->exporter->excel($filters, $user, $rows),
            default => $this->exporter->pdf($filters, $user, $rows),
        };
    }

    /**
     * The printed sheet — the same rows, filter lines and summary as the PDF, on the letterhead,
     * printing itself on load.
     */
    public function print(AdvancedReportRequest $request): View|RedirectResponse
    {
        $user = $request->user();
        $filters = $request->toData();
        $rows = $this->reports->count($filters, $user);

        if (($refusal = $this->exporter->refusal(ExportFormat::Print, $rows, $user)) !== null) {
            return $this->refuse($filters, $refusal);
        }

        return view('admin.advanced-reports.print', $this->exporter->document($filters, $user, $rows) + [
            'asPdf' => false,
            'backUrl' => route('admin.advanced-reports.index', $filters->toQuery()),
            'backLabel' => 'Back to the report',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /** Back to the screen with the same filters, saying why nothing was produced. */
    private function refuse(AdvancedReportFilters $filters, string $message): RedirectResponse
    {
        return redirect()
            ->route('admin.advanced-reports.index', $filters->toQuery())
            ->with('toast', ['type' => 'warning', 'title' => 'Too many rows', 'message' => $message]);
    }

    /**
     * Where the detail page's back button goes: the screen the person came from, filters and page
     * intact, when they came from it — otherwise the plain screen.
     *
     * The previous URL is a header the browser sends, so it is never echoed back as it came: only
     * its query string is kept, read through the same filter object as the screen and written out
     * again by `toQuery()`, plus a positive page number. A crafted referrer can therefore produce at
     * most a link to this report with some valid filters.
     *
     * Paths are compared rather than whole URLs: behind a proxy, or with `APP_URL` on another port,
     * the browser's referrer and `route()` disagree about the scheme and host while meaning the same
     * page. The referrer's host is never used, so comparing it would protect nothing.
     */
    private function backToIndex(): string
    {
        $index = route('admin.advanced-reports.index');
        $previous = (string) url()->previous();

        $path = static fn (string $url): string => rtrim((string) parse_url($url, PHP_URL_PATH), '/');

        if ($previous === '' || $path($previous) !== $path($index)) {
            return $index;
        }

        parse_str((string) parse_url($previous, PHP_URL_QUERY), $query);

        $parameters = AdvancedReportFilters::fromArray($query)->toQuery();
        $page = $query['page'] ?? null;

        if (is_string($page) && preg_match('/^[1-9]\d{0,5}$/', $page) === 1 && $page !== '1') {
            $parameters['page'] = (int) $page;
        }

        return route('admin.advanced-reports.index', $parameters);
    }

    /**
     * Dashboard › Reports › Advanced Reports (› student). "Reports" links to the hub only for a viewer
     * the hub would let in — a crumb that 403s is a dead end dressed as a way back.
     *
     * @return list<array{label: string, url: ?string, icon: null}>
     */
    private function breadcrumbs(User $user, ?string $current = null): array
    {
        $crumbs = [
            ['label' => 'Dashboard', 'url' => route('admin.dashboard'), 'icon' => null],
            [
                'label' => 'Reports',
                'url' => Modules::enabled('reports') && $user->can('reports.view_reports') ? route('admin.reports.index') : null,
                'icon' => null,
            ],
            ['label' => 'Advanced Reports', 'url' => $current === null ? null : route('admin.advanced-reports.index'), 'icon' => null],
        ];

        if ($current !== null) {
            $crumbs[] = ['label' => $current, 'url' => null, 'icon' => null];
        }

        return $crumbs;
    }
}
