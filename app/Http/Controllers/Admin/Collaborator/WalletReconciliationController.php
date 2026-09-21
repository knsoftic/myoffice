<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Collaborator;

use App\Enums\ReconciliationStatus;
use App\Http\Controllers\Controller;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorWalletReconciliation;
use App\Services\Collaborator\CommissionReconciliationService;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The reconciliation history — `admin.wallet-reconciliations.*` (phase-10-12 §7.4, §8.9).
 *
 * §50 asks that no total rely on a stored balance. These rows are how that promise is kept: each one
 * is a dated record that a wallet was re-derived from its ledger and the two agreed — or did not, and
 * by exactly how much.
 *
 * **Clean rows are listed too, and that is the point.** A table holding only failures proves nothing
 * about the days it says nothing about, and "was this checked?" is the first question an auditor asks.
 */
final class WalletReconciliationController extends Controller
{
    public function __construct(
        private readonly CommissionReconciliationService $reconciler,
    ) {}

    public function index(Request $request): View
    {
        $rows = $this->filtered($request)
            ->with(['collaborator' => fn ($q) => $q->withTrashed()->select([
                'id', 'collaborator_code', 'name', 'company_name', 'deleted_at',
            ])])
            ->latest('checked_at')
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        // The most recent run, whatever it was: the header says when the wallets were last proved
        // correct, which is the thing somebody actually wants to know on opening this screen.
        $latest = CollaboratorWalletReconciliation::query()->latest('checked_at')->latest('id')->first();

        $inLatestRun = $latest === null
            ? null
            : CollaboratorWalletReconciliation::query()->where('run_uuid', $latest->run_uuid);

        return view('admin.wallet-reconciliations.index', [
            'rows' => $rows,
            'statuses' => ReconciliationStatus::cases(),
            'latest' => $latest,
            'latestChecked' => $inLatestRun?->count() ?? 0,
            'latestProblems' => $inLatestRun === null ? 0 : (clone $inLatestRun)->drifting()->count(),
            'openProblems' => CollaboratorWalletReconciliation::query()->drifting()->count(),
            'canRun' => (bool) $request->user()?->can('wallet_reconciliation.change_status'),
            'collaborators' => Collaborator::query()
                ->withTrashed()
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'collaborator_code', 'name', 'company_name']),
        ]);
    }

    public function show(CollaboratorWalletReconciliation $reconciliation): View
    {
        $reconciliation->load([
            'collaborator' => fn ($q) => $q->withTrashed(),
            'wallet',
            'repairedBy:id,name',
        ]);

        return view('admin.wallet-reconciliations.show', [
            'row' => $reconciliation,
            'differences' => $reconciliation->differences(),
            'findings' => $reconciliation->details['findings'] ?? [],
            // The run this row belonged to, so "was it only this partner?" is answerable without
            // going back to the list and filtering by a uuid nobody can read out loud.
            'siblings' => CollaboratorWalletReconciliation::query()
                ->where('run_uuid', $reconciliation->run_uuid)
                ->whereKeyNot($reconciliation->getKey())
                ->drifting()
                ->with(['collaborator' => fn ($q) => $q->withTrashed()->select(['id', 'name', 'company_name'])])
                ->limit(25)
                ->get(),
        ]);
    }

    /**
     * Run the eight checks now, for everyone or for one partner.
     *
     * **`--repair` is a separate, explicit tick.** The scheduled job never repairs (§6.5.4), and a
     * button that quietly did would erase the evidence of whatever caused the drift — which is the part
     * worth keeping.
     */
    public function run(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'collaborator_id' => ['nullable', 'integer', 'exists:collaborators,id'],
            'repair' => ['nullable', 'boolean'],
        ]);

        $collaborator = $validated['collaborator_id'] ?? null;

        $reports = $this->reconciler->run(
            $collaborator === null ? null : Collaborator::withTrashed()->findOrFail($collaborator),
            'manual',
            (bool) ($validated['repair'] ?? false),
        );

        $failed = 0;
        $drifted = 0;
        $drift = Money::ZERO;

        foreach ($reports as $report) {
            if ($report->passed()) {
                continue;
            }

            $drift = Money::add($drift, $report->driftTotal);

            if ($report->structural() !== []) {
                $failed++;

                continue;
            }

            $drifted++;
        }

        if ($failed === 0 && $drifted === 0) {
            return back()->with('toast', [
                'type' => 'success',
                'message' => sprintf('%d wallet(s) checked. Every one matches its ledger.', count($reports)),
            ]);
        }

        return back()->with('toast', [
            'type' => $failed > 0 ? 'error' : 'warning',
            'message' => sprintf(
                '%d wallet(s) checked: %d drifted (%s) and %d disagree with their own ledger. '
                .'Drift is repaired from the wallet screen; a structural failure is not repaired by '
                .'anything — it needs a manual adjustment with a written reason.',
                count($reports), $drifted, Money::format($drift), $failed,
            ),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return Builder<CollaboratorWalletReconciliation>
     */
    private function filtered(Request $request): Builder
    {
        return CollaboratorWalletReconciliation::query()
            ->when($request->filled('status'), fn (Builder $q) => $q
                ->where('status', (string) $request->input('status')))
            ->when($request->boolean('problems'), fn (Builder $q) => $q->drifting())
            ->when($request->filled('collaborator'), fn (Builder $q) => $q
                ->where('collaborator_id', (int) $request->input('collaborator')))
            ->when($request->filled('run'), fn (Builder $q) => $q
                ->where('run_uuid', (string) $request->input('run')))
            ->when($request->filled('q'), function (Builder $query) use ($request): void {
                $term = '%'.trim((string) $request->input('q')).'%';

                $query->whereHas('collaborator', fn ($q) => $q
                    ->withTrashed()
                    ->where(fn ($inner) => $inner
                        ->where('name', 'like', $term)
                        ->orWhere('company_name', 'like', $term)
                        ->orWhere('collaborator_code', 'like', $term)));
            });
    }
}
