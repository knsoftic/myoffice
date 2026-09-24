<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reporting;

use App\DataObjects\Reporting\ActivityLogFilters;
use App\Enums\ExportFormat;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Services\Audit\AuditTrailService;
use App\Support\CsvWriter;
use App\Support\Modules;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The 107 trail (phase-19-23 7.8).
 *
 * Its own module (`audit_trail`), separate from `activity_log`, because 4.1 made old-and-new values
 * a different right from the operational feed.
 */
final class AuditTrailController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly AuditTrailService $audit,
    ) {}

    public function index(Request $request): View
    {
        $filters = ActivityLogFilters::fromRequest($request);
        $user = $request->user();
        $entries = $this->audit->query($filters, $user, 50);

        // The diffs are built here rather than in the view, because each one needs the viewer to
        // decide what is withheld and a Blade template should not be making that decision.
        $diffs = [];

        foreach ($entries as $entry) {
            $diffs[$entry->getKey()] = $this->audit->diff($entry, $user);
        }

        return view('admin.audit-trail.index', [
            'filters' => $filters,
            'entries' => $entries,
            'diffs' => $diffs,
            'modules' => Modules::names(),
        ]);
    }

    public function show(Request $request, Activity $activity): View
    {
        $user = $request->user();

        return view('admin.audit-trail.show', [
            'activity' => $activity,
            'diff' => $this->audit->diff($activity, $user),
            'sensitivity' => $this->audit->sensitivityOf($activity),
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse|RedirectResponse
    {
        $this->authorize('export', Activity::class);

        $exportFormat = ExportFormat::tryFrom($format);

        if ($exportFormat === null || ! $exportFormat->isTabular()) {
            return back()->with('toast', ['type' => 'error', 'message' => 'The audit trail exports as CSV.']);
        }

        $user = $request->user();
        $query = $this->audit->exportQuery(ActivityLogFilters::fromRequest($request), $user);
        $service = $this->audit;

        return (new CsvWriter)->download(
            sprintf('audit-trail-%s.csv', now()->format('Y-m-d')),
            ['When', 'Who', 'Module', 'Record', 'Event', 'Sensitivity', 'What changed', 'Reason given'],
            static function () use ($query, $service, $user): iterable {
                foreach ($query->reorder('activity_log.id')->lazyById(300, 'activity_log.id') as $activity) {
                    // The withheld marker travels into the file - see AuditTrailService::row().
                    yield array_map(
                        static fn (mixed $v): string => (string) ($v ?? ''),
                        array_values($service->row($activity, $user)),
                    );
                }
            },
        );
    }
}
