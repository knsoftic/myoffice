<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Collaborator;

use App\Enums\CommissionStatus;
use App\Enums\PayoutStatus;
use App\Enums\ReconciliationStatus;
use App\Http\Controllers\Controller;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Collaborator\CollaboratorPayout;
use App\Models\Collaborator\CollaboratorWallet;
use App\Services\Collaborator\CollaboratorWalletService;
use App\Services\Collaborator\CommissionReconciliationService;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Partner wallets — `admin.wallets.*` (phase-10-12 §7.4, §8.5).
 *
 * **Every figure on these screens comes from `CollaboratorWalletService`** (INV-26). The stored wallet
 * row is a cache, and a screen that summed the ledger its own way would be a second definition of a
 * balance — which is the one thing this module is built to prevent.
 *
 * **A drifted wallet shows the derived figures behind a banner** (§6.5.4). The cached numbers stay
 * visible beside them, because "the cache says 12,400 and the ledger says 12,350" is the sentence
 * somebody needs; hiding the wrong one would leave them with a number and no way to check it.
 */
final class WalletController extends Controller
{
    public function __construct(
        private readonly CollaboratorWalletService $wallets,
        private readonly CommissionReconciliationService $reconciler,
    ) {}

    public function index(Request $request): View
    {
        $wallets = $this->filtered($request)
            ->with(['collaborator' => fn ($q) => $q->withTrashed()->select([
                'id', 'collaborator_code', 'name', 'company_name', 'status', 'deleted_at',
            ])])
            ->paginate(25)
            ->withQueryString();

        // The register's own totals, from the cached columns — this is the one place that is honest,
        // because the list is *about* the caches and says so when one of them is wrong.
        // `reorder()` and `toBase()`: the filter carries an ORDER BY for the list, and MariaDB refuses
        // to mix aggregates with a bare ordering column. The totals want the same WHERE and none of
        // the presentation.
        $totals = $this->filtered($request)
            ->reorder()
            ->toBase()
            ->selectRaw('COALESCE(SUM(available_balance), 0) as available')
            ->selectRaw('COALESCE(SUM(pending_balance), 0) as pending')
            ->selectRaw('COALESCE(SUM(reserved_balance), 0) as reserved')
            ->selectRaw('COALESCE(SUM(lifetime_earned), 0) as lifetime')
            ->first();

        return view('admin.collaborator-wallets.index', [
            'wallets' => $wallets,
            'statuses' => ReconciliationStatus::cases(),
            'totals' => [
                'available' => Money::of((string) ($totals->available ?? Money::ZERO)),
                'pending' => Money::of((string) ($totals->pending ?? Money::ZERO)),
                'reserved' => Money::of((string) ($totals->reserved ?? Money::ZERO)),
                'lifetime' => Money::of((string) ($totals->lifetime ?? Money::ZERO)),
            ],
            'driftingCount' => CollaboratorWallet::query()
                ->whereIn('reconciliation_status', [
                    ReconciliationStatus::Drift->value,
                    ReconciliationStatus::Failed->value,
                ])->count(),
            'frozenCount' => CollaboratorWallet::query()->where('is_frozen', true)->count(),
            'lastRunAt' => $this->reconciler->lastRunAt(),
            'canRecalculate' => (bool) $request->user()?->can('wallet_reconciliation.change_status'),
            'canFreeze' => (bool) $request->user()?->can('collaborator_payouts.change_status'),
            'sort' => $request->string('sort', 'available')->toString(),
            'direction' => $request->string('direction')->lower()->toString() === 'asc' ? 'asc' : 'desc',
        ]);
    }

    /**
     * One partner's wallet, with the derivation beside the cache.
     *
     * The check is run live rather than read from the last nightly row: somebody opening this screen is
     * usually opening it *because* a figure is being questioned, and telling them what was true at
     * 01:30 is not an answer.
     */
    public function show(Request $request, Collaborator $collaborator): View
    {
        $report = $this->reconciler->check($collaborator);

        // A partner who has never earned has no wallet row at all — that is the design, not a gap, so
        // the screen shows the derivation (all zeros) rather than creating a row to have something to
        // print.
        $wallet = $this->wallets->for($collaborator);

        return view('admin.collaborator-wallets.show', [
            'collaborator' => $collaborator,
            'wallet' => $wallet,
            'report' => $report,
            'snapshot' => $report->snapshot,
            'differences' => $wallet === null
                ? []
                : $report->snapshot->differencesFrom($wallet->only(array_keys($report->snapshot->columns()))),
            'recent' => CollaboratorCommissionLedgerEntry::query()
                ->where('collaborator_id', $collaborator->getKey())
                ->latest('transaction_date')->latest('id')
                ->limit(15)
                ->get(),
            'payouts' => CollaboratorPayout::query()
                ->where('collaborator_id', $collaborator->getKey())
                ->latest('id')
                ->limit(10)
                ->get(),
            'pendingApproval' => CollaboratorCommissionLedgerEntry::query()
                ->where('collaborator_id', $collaborator->getKey())
                ->where('status', CommissionStatus::Pending->value)
                ->count(),
            'inflightPayouts' => CollaboratorPayout::query()
                ->where('collaborator_id', $collaborator->getKey())
                ->whereIn('status', [
                    PayoutStatus::Requested->value, PayoutStatus::Pending->value, PayoutStatus::Approved->value,
                ])->count(),
            'canRecalculate' => (bool) $request->user()?->can('wallet_reconciliation.change_status'),
            'canFreeze' => (bool) $request->user()?->can('collaborator_payouts.change_status'),
        ]);
    }

    /**
     * Rewrite the cache from the ledger — the button §6.5.4 leaves for a human.
     *
     * It refuses on a structural failure. Recomputing a cache derived from a ledger that disagrees with
     * itself would replace a visible problem with an invisible one, and the person pressing the button
     * would reasonably believe it had been fixed.
     */
    public function recalculate(Request $request, Collaborator $collaborator): RedirectResponse
    {
        $report = $this->reconciler->check($collaborator);

        if ($report->structural() !== []) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'Nothing was recalculated. '.$report->summary().' A cache is only a copy of '
                    .'the ledger, so recomputing it cannot fix a ledger that disagrees with itself — this '
                    .'needs a manual adjustment with a written reason.',
            ]);
        }

        $before = $report->driftTotal;
        $this->wallets->recalculate($collaborator);

        return back()->with('toast', [
            'type' => 'success',
            'message' => Money::isZero($before)
                ? 'The wallet already matched the ledger. It was recomputed anyway and is unchanged.'
                : sprintf('The wallet was recomputed from the ledger, correcting %s of drift.', Money::format($before)),
        ]);
    }

    /**
     * Stop new payouts without stopping earning (§6.5).
     *
     * The two are genuinely separate: a partner under investigation keeps accruing what they are owed
     * and simply is not paid it yet. The reason is mandatory because it is what they are told when they
     * ask, and there is no second place to look it up.
     */
    public function freeze(Request $request, Collaborator $collaborator): RedirectResponse
    {
        $frozen = $request->boolean('frozen');

        $validated = $request->validate([
            'frozen' => ['required', 'boolean'],
            'reason' => [$frozen ? 'required' : 'nullable', 'string', 'min:5', 'max:255'],
        ], [
            'reason.required' => 'Freezing a wallet stops a partner being paid. Say why — it is what they '
                .'are shown when they ask.',
        ]);

        $this->wallets->freeze($collaborator, $frozen, $validated['reason'] ?? null);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $frozen
                ? 'The wallet is frozen. Commission still accrues; no new payout can be requested or approved.'
                : 'The wallet is unfrozen. The reason it was frozen is kept on the record.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return Builder<CollaboratorWallet>
     */
    private function filtered(Request $request): Builder
    {
        return CollaboratorWallet::query()
            ->when($request->filled('q'), function (Builder $query) use ($request): void {
                $term = '%'.trim((string) $request->input('q')).'%';

                $query->whereHas('collaborator', fn ($q) => $q
                    ->withTrashed()
                    ->where(fn ($inner) => $inner
                        ->where('name', 'like', $term)
                        ->orWhere('company_name', 'like', $term)
                        ->orWhere('collaborator_code', 'like', $term)));
            })
            ->when($request->filled('status'), fn (Builder $q) => $q
                ->where('reconciliation_status', (string) $request->input('status')))
            ->when($request->boolean('frozen'), fn (Builder $q) => $q->where('is_frozen', true))
            ->when($request->boolean('owing'), fn (Builder $q) => $q->where('available_balance', '<', 0))
            ->when($request->filled('sort'), fn (Builder $q) => $this->sorted($q, $request), fn (Builder $q) => $q
                ->orderByDesc('available_balance'));
    }

    /**
     * @param  Builder<CollaboratorWallet>  $query
     * @return Builder<CollaboratorWallet>
     */
    private function sorted(Builder $query, Request $request): Builder
    {
        $column = (string) $request->input('sort');
        $direction = $request->string('direction')->lower()->toString() === 'asc' ? 'asc' : 'desc';

        $allowed = [
            'available' => 'available_balance',
            'pending' => 'pending_balance',
            'reserved' => 'reserved_balance',
            'paid' => 'paid_balance',
            'lifetime' => 'lifetime_earned',
            'drift' => 'drift_amount',
            'reconciled' => 'last_reconciled_at',
        ];

        return $query->orderBy($allowed[$column] ?? 'available_balance', $direction);
    }
}
