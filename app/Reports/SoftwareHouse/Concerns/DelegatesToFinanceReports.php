<?php

declare(strict_types=1);

namespace App\Reports\SoftwareHouse\Concerns;

use App\DataObjects\Reporting\ReportRequest;
use App\Enums\FinanceReportType;
use App\Models\User;
use App\Services\Finance\FinanceReportService;
use App\Support\ReportResult;

/**
 * For the reports Phase 13 already answers in full (phase-19-23 6.20, INV-23-1).
 *
 * `FinanceReportService::report()` returns a complete `ReportResult` - rows, totals and the meta
 * that names the basis. These three reports therefore do not shape anything: they hand over the
 * range, the viewer and the filters, and pass the answer through.
 *
 * **That is the point, not a shortcut.** 13.2 already established that the finance figures have
 * one owner, and 99 lists the same reports again under a different hub. Re-deriving them here
 * would give the business two answers to "what did we earn in September", and the version on the
 * newer screen would be the one nobody reconciled.
 *
 * The viewer is passed straight through because the service does its own withholding: a source the
 * reader may not see is **named** in `meta.omitted_sources` rather than silently dropped, so a
 * partial total is never read as a full one. The engine's column strip sits on top of that and the
 * two agree, which is what INV-23-2 wants.
 */
trait DelegatesToFinanceReports
{
    abstract protected function financeReportType(): FinanceReportType;

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        return app(FinanceReportService::class)->report(
            $this->financeReportType(),
            $request->range,
            $viewer,
            $request->filters,
        );
    }
}
