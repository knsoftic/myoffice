<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reporting;

use App\Http\Controllers\Controller;
use App\Services\Reporting\AnalyticsService;
use App\Support\DateRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The 98 analytics screen (phase-19-23 7.8).
 *
 * **Charts are fetched one at a time over JSON**, not rendered into the page. Nine aggregates in
 * one request is a slow page where the slowest chart decides how long everybody waits; nine
 * requests is nine tiles that fill in as they arrive, and one that fails leaves the other eight
 * alone. The route is throttled at 60/minute per user for that reason.
 */
final class AnalyticsController extends Controller
{
    public function __construct(
        private readonly AnalyticsService $analytics,
    ) {}

    public function index(Request $request): View
    {
        return view('admin.analytics.index', [
            'charts' => AnalyticsService::catalogue(),
            'range' => $this->rangeFrom($request),
        ]);
    }

    /**
     * One chart's data.
     *
     * An unknown chart name is a 404 rather than an empty payload: a tile asking for something that
     * does not exist is a bug, and answering it with valid-looking emptiness hides it.
     */
    public function chart(Request $request, string $chart): JsonResponse
    {
        abort_unless(array_key_exists($chart, AnalyticsService::catalogue()), 404);

        $payload = $this->analytics->{$chart}(
            $this->rangeFrom($request),
            $request->user(),
            (array) $request->input('filters', []),
        );

        // A chart the viewer may not see is a 403 on its own endpoint, so the tile can say so
        // rather than rendering an empty box that reads as "no data".
        return response()->json($payload, ($payload['available'] ?? true) ? 200 : 403);
    }

    private function rangeFrom(Request $request): DateRange
    {
        return DateRange::make(
            preset: $request->string('preset')->toString() ?: (string) setting('reports.default_date_preset', 'month'),
            from: $request->input('from'),
            to: $request->input('to'),
        );
    }
}
