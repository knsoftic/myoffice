<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reporting;

use App\DataObjects\Reporting\ReportRequest;
use App\Enums\ExportFormat;
use App\Http\Controllers\Controller;
use App\Models\Reporting\ReportExport;
use App\Services\Reporting\Exceptions\ExportFormatUnavailableException;
use App\Services\Reporting\Exceptions\ExportTooLargeException;
use App\Services\Reporting\ReportEngine;
use App\Support\ReportRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The §99 report hub and one report (phase-19-23 §7.8).
 *
 * **Thin, because the engine is where the rules are.** `ReportEngine::run()` is §9.5's six steps in
 * order, and every action here goes through it rather than reaching for a query. The controller's
 * whole job is to turn a request into a `ReportRequest`, hand it over, and decide what to do with
 * an exception.
 *
 * **The per-report permission stack is resolved by the registry, not listed on the route.** §7.8's
 * route table says `can:reports.view_reports` **plus the report's own `permissions()`** — which no
 * static middleware string can express, because the list differs per report and changes when a
 * report's columns do. `ReportEngine::authorise()` is where that happens, and it 404s an unknown or
 * unpermitted key rather than 403ing it: telling somebody a report exists that they may not open is
 * a small disclosure with no upside.
 */
final class ReportController extends Controller
{
    public function __construct(
        private readonly ReportEngine $engine,
    ) {}

    /**
     * The hub: every report this person may open, grouped.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('admin.reports.index', [
            'groups' => ReportRegistry::groupedFor($user),
            'recentExports' => ReportExport::query()
                ->requestedBy($user)
                ->latest('id')
                ->limit(5)
                ->get(),
        ]);
    }

    /**
     * One report, run.
     */
    public function show(Request $request, string $report): View
    {
        $user = $request->user();

        // Resolves, authorises and 404s in one call — see the class note.
        $definition = $this->engine->authorise($report, $user);

        $reportRequest = ReportRequest::fromRequest($request);

        return view('admin.reports.show', [
            'definition' => $definition,
            'schema' => $this->engine->describe($report, $user),
            'result' => $this->engine->run($report, $reportRequest, $user),
            'request' => $reportRequest,
        ]);
    }

    /**
     * The JSON the filter bar and the column picker are built from.
     */
    public function schema(Request $request, string $report): JsonResponse
    {
        return response()->json(
            $this->engine->describe($report, $request->user())->toArray(),
        );
    }

    /**
     * Export: stream, queue, or refuse.
     *
     * The three outcomes reach the user as three different things — a file, a redirect saying it is
     * being built, and a redirect saying why it was refused. A refusal that looked like a failure
     * would have somebody retrying the same request.
     */
    public function export(Request $request, string $report, string $format): Response|RedirectResponse
    {
        $user = $request->user();
        $exportFormat = ExportFormat::tryFrom($format);

        if ($exportFormat === null) {
            return back()->with('toast', ['type' => 'error', 'message' => 'That is not a format this system produces.']);
        }

        try {
            $outcome = $this->engine->export($report, ReportRequest::fromRequest($request), $exportFormat, $user);
        } catch (ExportTooLargeException $exception) {
            // The message names the count and the limit, so it is worth showing verbatim.
            return back()->with('toast', ['type' => 'warning', 'message' => $exception->getMessage()]);
        } catch (ExportFormatUnavailableException $exception) {
            return back()->with('toast', ['type' => 'warning', 'message' => $exception->getMessage()]);
        }

        if ($outcome instanceof ReportExport) {
            return redirect()
                ->route('admin.report-exports.index')
                ->with('toast', [
                    'type' => 'info',
                    'message' => 'That report is too large to build here, so it is being prepared. '
                        .'You will find it on this page when it is ready.',
                ]);
        }

        return $outcome;
    }

    /**
     * The printable page — the same Blade the PDF renders, so the two cannot drift.
     */
    public function print(Request $request, string $report): View
    {
        $user = $request->user();

        $this->engine->authorise($report, $user);

        return view('admin.reports.print', [
            'result' => $this->engine->run($report, ReportRequest::fromRequest($request), $user),
            'asPdf' => false,
        ]);
    }
}
