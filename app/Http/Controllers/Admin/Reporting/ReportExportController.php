<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reporting;

use App\Http\Controllers\Controller;
use App\Models\Reporting\ReportExport;
use App\Services\Reporting\ReportExportService;
use App\Support\ReportRegistry;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The export register (phase-19-23 7.8).
 *
 * **There is no "all exports" screen, not even for an administrator.** 9.5 says the register is
 * scoped `requested_by = auth()->id()` for everyone, and the reason is the file rather than the
 * row: its contents were shaped by one person's permissions and scope, so listing somebody else's
 * exports would be the first step towards handing one over.
 */
final class ReportExportController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ReportExportService $exports,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ReportExport::class);

        $user = $request->user();

        $query = ReportExport::query()->requestedBy($user);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('report')) {
            $query->forReport($request->string('report')->toString());
        }

        return view('admin.report-exports.index', [
            'exports' => $query->latest('id')->paginate(25)->withQueryString(),
            // Only the reports this person can actually run, so the filter never offers a key they
            // could not have produced a file for.
            'reports' => ReportRegistry::visibleTo($user),
        ]);
    }

    /**
     * Serve the bytes.
     *
     * The policy and the service both check ownership and live permissions. That is deliberate
     * duplication: `Gate::before` allows a Super Admin past a policy, so the service's own check is
     * the one that actually holds the line, and the policy is what keeps the route honest.
     */
    public function download(Request $request, ReportExport $export): StreamedResponse
    {
        $this->authorize('download', $export);

        return $this->exports->download($export, $request->user());
    }

    /**
     * Remove the file. The row stays - 2.26.
     */
    public function destroy(Request $request, ReportExport $export): RedirectResponse
    {
        $this->authorize('delete', $export);

        $this->exports->expire($export);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'The file has been removed. The record of the export is kept.',
        ]);
    }
}
