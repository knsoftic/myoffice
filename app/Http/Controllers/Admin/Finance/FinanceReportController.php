<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Finance;

use App\Enums\ExportFormat;
use App\Enums\FinanceReportType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Finance\FinanceReportService;
use App\Services\Reporting\ReportExporter;
use App\Support\DateRange;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The finance report hub and its four reports — `admin.reports.finance.*` (§99, phase-13 §7.6).
 *
 * **Double-gated** (§4.5 rule 4): the hub needs `reports.view_reports`, and each report additionally
 * needs its source module's `view_reports` **and** `view_financial`. An Institute Manager holding the
 * hub permission still cannot open the profit-and-loss statement — the route refuses it, not the menu.
 *
 * Every screen and every export renders one `ReportResult`, so they cannot disagree about a figure or
 * about what the figure covers. `meta` travels with it: the basis, the date column and the filters in
 * force are printed on the page and written into the file.
 */
final class FinanceReportController extends Controller
{
    public function __construct(
        private readonly FinanceReportService $reports,
        private readonly ReportExporter $exporter,
    ) {}

    /**
     * The hub: which reports this person may actually open.
     *
     * A card for a report the route would refuse is worse than no card — it advertises something and
     * then says no, which reads as a bug rather than as a decision.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('admin.reports.finance.index', [
            'reports' => array_values(array_filter(
                FinanceReportType::cases(),
                fn (FinanceReportType $type): bool => $this->mayOpen($user, $type),
            )),
            'range' => $this->range($request),
        ]);
    }

    public function show(Request $request, string $report): View
    {
        $type = $this->resolve($report);
        $range = $this->range($request);
        $filters = $this->filters($request);

        $result = $this->reports->report($type, $range, $request->user(), $filters);

        return view('admin.reports.finance.show', [
            'type' => $type,
            'result' => $result,
            'range' => $range,
            'filters' => $filters,
            'formats' => ExportFormat::cases(),
            // Above this, an export is built in the background rather than holding a request open
            // until it times out.
            'syncLimit' => (int) setting('finance.report_sync_row_limit', 5000),
        ]);
    }

    /**
     * Every format goes through the one `ReportExporter` (§6.7.5, F-4.14).
     *
     * A controller that wrote its own CSV would be the second implementation of "what a report file
     * contains", and the first thing to diverge would be whether the meta block — the period, the date
     * column, the sources the reader may not see — travelled with the file.
     */
    public function export(Request $request, string $report, string $format): StreamedResponse|View
    {
        $type = $this->resolve($report);
        $exportFormat = ExportFormat::tryFrom($format);

        abort_if($exportFormat === null, 404);

        $range = $this->range($request);
        $result = $this->reports->report($type, $range, $request->user(), $this->filters($request));

        return $this->exporter->export(
            $result,
            $exportFormat,
            $type->value,
            'admin.reports.finance.print',
            ['type' => $type, 'range' => $range, 'generatedAt' => now()],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function resolve(string $report): FinanceReportType
    {
        // The URI uses hyphens; the enum uses underscores, because it is also a settings-safe key.
        $type = FinanceReportType::tryFrom(str_replace('-', '_', $report));

        abort_if($type === null, 404);

        return $type;
    }

    private function mayOpen(?User $user, FinanceReportType $type): bool
    {
        if ($user === null) {
            return false;
        }

        foreach ($type->permissions() as $permission) {
            if (! $user->can($permission)) {
                return false;
            }

            // The pair: `view_reports` opens the report, `view_financial` fills in the amounts, and a
            // finance report without amounts is not a report.
            $financial = str_replace('.view_reports', '.view_financial', $permission);

            if ($financial !== $permission && ! $user->can($financial)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return array_filter([
            'category' => $request->input('category'),
            'context' => $request->input('context'),
            'project' => $request->input('project'),
            'client' => $request->input('client'),
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    private function range(Request $request): DateRange
    {
        return DateRange::make($request->input('preset'), $request->input('from'), $request->input('to'));
    }
}
