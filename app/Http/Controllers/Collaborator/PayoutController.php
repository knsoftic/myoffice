<?php

declare(strict_types=1);

namespace App\Http\Controllers\Collaborator;

use App\DataObjects\Collaborator\PayoutRequestData;
use App\Enums\PayoutMethod;
use App\Enums\PayoutStatus;
use App\Http\Controllers\Collaborator\Concerns\ResolvesOwnCollaborator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Collaborator\RequestPayoutRequest;
use App\Models\Collaborator\CollaboratorPayout;
use App\Models\Collaborator\CollaboratorPayoutAccount;
use App\Services\Collaborator\CollaboratorWalletService;
use App\Services\Collaborator\PayoutService;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Asking to be paid — `collaborator.payouts.*` (phase-10-12 §7.5, §8.10).
 *
 * **Asking is not approving.** `PayoutService::request()` decides whether the request auto-approves
 * against `collaborator.payout_auto_approve_below`, and a partner cannot reach `approve()` from here
 * at all: the route does not exist in this panel.
 *
 * A partner may withdraw their own request while it is still `requested` — §7.5's narrow window. Once
 * staff have approved it, withdrawing is a staff decision, because somebody has already acted on it.
 */
final class PayoutController extends Controller
{
    use ResolvesOwnCollaborator;

    public function __construct(
        private readonly PayoutService $payouts,
        private readonly CollaboratorWalletService $wallets,
    ) {}

    public function index(Request $request): View
    {
        $collaborator = $this->ownCollaborator($request);

        return view('collaborator.payouts.index', [
            'collaborator' => $collaborator,
            'payouts' => CollaboratorPayout::query()
                ->where('collaborator_id', $collaborator->getKey())
                ->latest('id')
                ->paginate(20),
            'snapshot' => $this->wallets->derive($collaborator),
            'totalPaid' => $this->wallets->payoutsPaidTotal($collaborator),
            'canRequest' => (bool) $request->user()?->can('collaborator_portal.payout_request')
                && (bool) setting('collaborator.payout_request_enabled', true),
        ]);
    }

    public function show(Request $request, CollaboratorPayout $payout): View
    {
        $collaborator = $this->ownCollaborator($request);

        $this->assertOwn($collaborator, $payout);

        $payout->load([
            'account',
            'allocations' => fn ($q) => $q->orderBy('id')->with('entry'),
        ]);

        return view('collaborator.payouts.show', [
            'collaborator' => $collaborator,
            'payout' => $payout,
            // §12.2 Q-G: the allocation detail is shown, because hiding it invites "which commissions
            // did you pay me for?" and there is no other screen that answers it.
            'live' => $payout->allocations->where('is_released', false),
            'released' => $payout->allocations->where('is_released', true),
        ]);
    }

    public function create(Request $request): View
    {
        $collaborator = $this->ownCollaborator($request);
        $snapshot = $this->wallets->derive($collaborator);

        return view('collaborator.payouts.create', [
            'collaborator' => $collaborator,
            'snapshot' => $snapshot,
            'minimum' => Money::of((string) setting('collaborator.minimum_payout', '0.00')),
            'methods' => PayoutMethod::cases(),
            'accounts' => CollaboratorPayoutAccount::query()
                ->where('collaborator_id', $collaborator->getKey())
                ->usable()
                ->orderByDesc('is_default')
                ->get(),
            'inFlight' => CollaboratorPayout::query()
                ->where('collaborator_id', $collaborator->getKey())
                ->inFlight()
                ->first(),
            'singleInFlight' => (bool) setting('collaborator.payout_single_inflight', true),
        ]);
    }

    public function store(RequestPayoutRequest $request): RedirectResponse
    {
        $collaborator = $this->ownCollaborator($request);

        $payout = $this->payouts->request($collaborator, new PayoutRequestData(
            requestedAmount: (string) $request->input('amount'),
            method: PayoutMethod::from((string) $request->input('method')),
            payoutAccountId: $request->filled('payout_account_id') ? $request->integer('payout_account_id') : null,
            notes: $request->input('notes'),
            idempotencyKey: $request->input('idempotency_key'),
        ), $request->user());

        return redirect()->route('collaborator.payouts.show', $payout)->with('toast', [
            'type' => 'success',
            'message' => $payout->status === PayoutStatus::Approved
                ? sprintf('%s for %s is approved and waiting to be paid.', $payout->payout_no, Money::format((string) $payout->amount))
                : sprintf('%s for %s has been requested. You will be told when it is approved.',
                    $payout->payout_no, Money::format((string) $payout->amount)),
        ]);
    }

    /**
     * Withdraw an own request, while nobody has acted on it yet.
     */
    public function cancel(Request $request, CollaboratorPayout $payout): RedirectResponse
    {
        $collaborator = $this->ownCollaborator($request);

        $this->assertOwn($collaborator, $payout);

        if ($payout->status !== PayoutStatus::Requested) {
            // Approved means staff have already acted. Undoing their decision is theirs to do.
            return back()->with('toast', [
                'type' => 'error',
                'message' => sprintf('%s is %s and can no longer be withdrawn here. Ask the office.',
                    $payout->payout_no, $payout->status->label()),
            ]);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'reason.required' => 'Say why you are withdrawing it — the office sees this.',
        ]);

        $this->payouts->cancel($payout, $validated['reason'], $request->user());

        return redirect()->route('collaborator.payouts.index')->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s was withdrawn. The commissions it claimed are available again.', $payout->payout_no),
        ]);
    }
}
