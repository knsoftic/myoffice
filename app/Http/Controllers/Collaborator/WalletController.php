<?php

declare(strict_types=1);

namespace App\Http\Controllers\Collaborator;

use App\Enums\CommissionStatus;
use App\Enums\PayoutStatus;
use App\Http\Controllers\Collaborator\Concerns\ResolvesOwnCollaborator;
use App\Http\Controllers\Controller;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Collaborator\CollaboratorPayout;
use App\Services\Collaborator\CollaboratorWalletService;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * What a partner is owed — `collaborator.wallet.index` (phase-10-12 §7.5, §8.10).
 *
 * The figures are the **derived** ones, not the cache. A partner is the last person who should be
 * shown a number the business already knows it cannot reproduce, and the cost of deriving is two
 * queries on one screen they open occasionally.
 *
 * The four buckets are explained in words beside their amounts. "Pending" and "available" are the same
 * money to the person waiting for it, and the difference between them is the entire content of most
 * support emails this screen exists to prevent.
 */
final class WalletController extends Controller
{
    use ResolvesOwnCollaborator;

    public function __construct(
        private readonly CollaboratorWalletService $wallets,
    ) {}

    public function index(Request $request): View
    {
        $collaborator = $this->ownCollaborator($request);
        $snapshot = $this->wallets->derive($collaborator);
        $wallet = $this->wallets->for($collaborator);

        return view('collaborator.wallet.index', [
            'collaborator' => $collaborator,
            'snapshot' => $snapshot,
            'wallet' => $wallet,
            'minimum' => Money::of((string) setting('collaborator.minimum_payout', '0.00')),
            'canRequest' => (bool) $request->user()?->can('collaborator_portal.payout_request')
                && (bool) setting('collaborator.payout_request_enabled', true),
            // 25, like the ledger strip below: **an owner `where` is not a row bound** (phase-24-25
            // section 6.4, PRF-05). In-flight means requested-or-approved-not-yet-paid, which is normally
            // one row, but nothing in the schema stops a collaborator from requesting every week for a
            // year while an approver looks away.
            'inFlight' => CollaboratorPayout::query()
                ->where('collaborator_id', $collaborator->getKey())
                ->inFlight()
                ->latest('id')
                ->limit(25)
                ->get(),
            'recent' => CollaboratorCommissionLedgerEntry::query()
                ->where('collaborator_id', $collaborator->getKey())
                ->whereNot('status', CommissionStatus::Cancelled->value)
                ->latest('transaction_date')
                ->latest('id')
                ->limit(10)
                ->get(),
            'lastPaidAt' => CollaboratorPayout::query()
                ->where('collaborator_id', $collaborator->getKey())
                ->where('status', PayoutStatus::Paid->value)
                ->max('paid_on'),
        ]);
    }
}
