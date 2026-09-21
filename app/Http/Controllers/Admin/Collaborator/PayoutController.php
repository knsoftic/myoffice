<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Collaborator;

use App\DataObjects\Collaborator\MarkPaidData;
use App\DataObjects\Collaborator\PayoutRequestData;
use App\Enums\PayoutMethod;
use App\Enums\PayoutStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Collaborator\MarkPayoutPaidRequest;
use App\Http\Requests\Admin\Collaborator\StorePayoutRequest;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorPayout;
use App\Models\Collaborator\CollaboratorPayoutAccount;
use App\Services\Collaborator\CollaboratorWalletService;
use App\Services\Collaborator\PayoutService;
use App\Support\CsvWriter;
use App\Support\DateRange;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Paying a partner — `admin.payouts.*` (phase-10-12 §7.4, §8.6).
 *
 * **Nothing here decides an amount.** `PayoutService` claims named ledger entries FIFO with a
 * compare-and-swap and derives the payout's `amount` from what it actually managed to claim (INV-22).
 * The controller collects a request, shows what the allocation would look like, and renders the result.
 *
 * **The wizard's preview is read-only and deliberately non-binding.** `plan()` claims nothing, so the
 * figure on screen can be out of date by the time the form is submitted — and when it is, the store
 * refuses with the shortfall named rather than paying less than was asked for.
 */
final class PayoutController extends Controller
{
    public function __construct(
        private readonly PayoutService $payouts,
        private readonly CollaboratorWalletService $wallets,
    ) {}

    public function index(Request $request): View
    {
        $payouts = $this->filtered($request)
            ->with(['collaborator' => fn ($q) => $q->withTrashed()->select([
                'id', 'collaborator_code', 'name', 'company_name', 'deleted_at',
            ])])
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.payouts.index', [
            'payouts' => $payouts,
            'statuses' => PayoutStatus::cases(),
            'methods' => PayoutMethod::cases(),
            'range' => $this->range($request),
            'awaitingApproval' => CollaboratorPayout::query()
                ->whereIn('status', [PayoutStatus::Requested->value, PayoutStatus::Pending->value])
                ->count(),
            'awaitingPayment' => CollaboratorPayout::query()
                ->where('status', PayoutStatus::Approved->value)
                ->count(),
            // Money that actually left the company over the range, from allocations and never from a
            // status somebody flipped (INV-23).
            'paidInRange' => $this->wallets->payoutsPaidTotal(null, $this->range($request)),
            'reserved' => Money::of((string) (CollaboratorPayout::query()
                ->whereIn('status', [
                    PayoutStatus::Requested->value, PayoutStatus::Pending->value, PayoutStatus::Approved->value,
                ])->sum('amount') ?: Money::ZERO)),
            'canCreate' => (bool) $request->user()?->can('collaborator_payouts.create'),
        ]);
    }

    /**
     * Step 1 of the wizard: who, and how much.
     */
    public function create(Request $request): View
    {
        $collaborator = $request->filled('collaborator')
            ? Collaborator::query()->find((int) $request->input('collaborator'))
            : null;

        return view('admin.payouts.create', [
            'collaborator' => $collaborator,
            'wallet' => $collaborator === null ? null : $this->wallets->for($collaborator),
            'snapshot' => $collaborator === null ? null : $this->wallets->derive($collaborator),
            // `usable()` is the model's own scope: active, and verified when the setting demands it.
            // §55's protection is worth nothing if the wizard can pick an unverified destination.
            'accounts' => $collaborator === null ? collect() : CollaboratorPayoutAccount::query()
                ->where('collaborator_id', $collaborator->getKey())
                ->usable()
                ->orderByDesc('is_default')
                ->get(),
            'methods' => PayoutMethod::cases(),
            'minimum' => Money::of((string) setting('collaborator.minimum_payout', '0.00')),
            'collaborators' => Collaborator::query()
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'collaborator_code', 'name', 'company_name']),
        ]);
    }

    /**
     * Step 2: the FIFO allocation preview.
     *
     * Read-only by construction — it claims nothing and writes nothing. It exists so the person about
     * to move money can see *which* commissions they are settling, which is the question the partner
     * asks afterwards.
     */
    public function plan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'collaborator_id' => ['required', 'integer', 'exists:collaborators,id'],
            'amount' => ['required', 'string'],
        ]);

        $collaborator = Collaborator::query()->findOrFail($validated['collaborator_id']);
        $plan = $this->payouts->plan($collaborator, (string) $validated['amount']);

        return response()->json([
            'requested' => $plan->requested,
            'allocatable' => $plan->allocatable,
            'shortfall' => $plan->shortfall,
            'satisfiable' => $plan->isSatisfiable(),
            'entry_count' => $plan->entryCount(),
            'slices' => $plan->slices,
            'note' => $plan->isSatisfiable()
                ? 'Oldest entries first. Nothing is claimed until the payout is created.'
                : sprintf('%s short. Only %s can be claimed from the ledger right now.',
                    Money::format($plan->shortfall), Money::format($plan->allocatable)),
        ]);
    }

    public function store(StorePayoutRequest $request): RedirectResponse
    {
        $collaborator = Collaborator::query()->findOrFail($request->integer('collaborator_id'));

        $payout = $this->payouts->createFor($collaborator, new PayoutRequestData(
            requestedAmount: (string) $request->input('amount'),
            method: PayoutMethod::from((string) $request->input('method')),
            payoutAccountId: $request->filled('payout_account_id') ? $request->integer('payout_account_id') : null,
            notes: $request->input('notes'),
            statementFrom: $request->filled('statement_from') ? Carbon::parse((string) $request->input('statement_from')) : null,
            statementTo: $request->filled('statement_to') ? Carbon::parse((string) $request->input('statement_to')) : null,
            idempotencyKey: $request->input('idempotency_key'),
        ), $request->user());

        return redirect()->route('admin.payouts.show', $payout)->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s raised for %s against %d commission %s.',
                $payout->payout_no,
                Money::format((string) $payout->amount),
                $payout->entry_count,
                Str::plural('entry', $payout->entry_count),
            ),
        ]);
    }

    public function show(CollaboratorPayout $payout): View
    {
        $payout->load([
            'collaborator' => fn ($q) => $q->withTrashed(),
            'account',
            'requestedBy:id,name', 'approvedBy:id,name', 'paidBy:id,name',
            'rejectedBy:id,name', 'cancelledBy:id,name',
            'allocations.entry',
        ]);

        return view('admin.payouts.show', [
            'payout' => $payout,
            'live' => $payout->allocations->where('is_released', false),
            'released' => $payout->allocations->where('is_released', true),
            'methods' => PayoutMethod::cases(),
            'referenceRequired' => (bool) setting('finance.payout_reference_required', true),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Decisions
    |--------------------------------------------------------------------------
    */

    public function approve(Request $request, CollaboratorPayout $payout): RedirectResponse
    {
        $this->payouts->approve($payout, $request->user(), $request->input('note'));

        return back()->with('toast', [
            'type' => 'success',
            'message' => $payout->payout_no.' is approved. The money has not moved yet — mark it paid when the bank confirms.',
        ]);
    }

    public function reject(Request $request, CollaboratorPayout $payout): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'reason.required' => 'Rejecting a payout needs a reason: somebody was expecting this money.',
        ]);

        $this->payouts->reject($payout, $validated['reason'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => $payout->payout_no.' was rejected and every commission it claimed is spendable again.',
        ]);
    }

    public function markPaid(MarkPayoutPaidRequest $request, CollaboratorPayout $payout): RedirectResponse
    {
        $this->payouts->markPaid($payout, new MarkPaidData(
            transactionId: $request->input('transaction_id'),
            paidOn: $request->filled('paid_on') ? Carbon::parse((string) $request->input('paid_on')) : null,
            receiptPath: $request->input('receipt_path'),
            notes: $request->input('notes'),
        ), $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s is marked paid. %s has left the company.',
                $payout->payout_no, Money::format((string) $payout->refresh()->amount)),
        ]);
    }

    public function cancel(Request $request, CollaboratorPayout $payout): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $this->payouts->cancel($payout, $validated['reason'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => $payout->payout_no.' was withdrawn and its claims released.',
        ]);
    }

    /**
     * The bank sent the money back — the one backward money transition in the design (spine R-7).
     *
     * Gated by two permissions at the route and a mandatory reason here, because it puts settled money
     * back into a spendable balance. Everything about it is recorded.
     */
    public function cancelAfterPayment(Request $request, CollaboratorPayout $payout): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ], [
            'reason.required' => 'Returning a settled payout puts money back into a spendable balance. '
                .'That is the one backward step in this system, and it is recorded with its reason or it is not taken.',
            'reason.min' => 'Say what happened — a few words will be read by whoever asks about this in a year.',
        ]);

        $this->payouts->cancelAfterPayment($payout, $validated['reason'], $request->user());

        return back()->with('toast', [
            'type' => 'warning',
            'message' => sprintf('%s was returned. %s is spendable again, and every entry it settled walked back to available.',
                $payout->payout_no, Money::format((string) $payout->amount)),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Paper
    |--------------------------------------------------------------------------
    */

    public function voucher(CollaboratorPayout $payout): View
    {
        $payout->load([
            'collaborator' => fn ($q) => $q->withTrashed(),
            'account',
            'approvedBy:id,name', 'paidBy:id,name',
            'allocations' => fn ($q) => $q->where('is_released', false)->with('entry'),
        ]);

        return view('admin.payouts.voucher', [
            'payout' => $payout,
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse
    {
        abort_unless($format === 'csv', 404);

        $range = $this->range($request);

        return (new CsvWriter)->download(
            sprintf('payouts-%s-to-%s.csv', $range->start()->toDateString(), $range->end()->toDateString()),
            ['Payout', 'Collaborator', 'Code', 'Requested', 'Amount', 'Entries', 'Method', 'Status', 'Paid on', 'Reference'],
            CsvWriter::rowsFrom(
                $this->filtered($request)->with(['collaborator' => fn ($q) => $q->withTrashed()]),
                static fn (CollaboratorPayout $payout): array => [
                    $payout->payout_no,
                    (string) $payout->collaborator?->displayName(),
                    (string) $payout->collaborator?->collaborator_code,
                    (string) $payout->requested_amount,
                    (string) $payout->amount,
                    (string) $payout->entry_count,
                    $payout->method->label(),
                    $payout->status->label(),
                    (string) $payout->paid_on?->toDateString(),
                    (string) $payout->transaction_id,
                ],
            ),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return Builder<CollaboratorPayout>
     */
    private function filtered(Request $request): Builder
    {
        $range = $this->range($request);

        return CollaboratorPayout::query()
            ->when($request->filled('q'), function (Builder $query) use ($request): void {
                $term = '%'.trim((string) $request->input('q')).'%';

                $query->where(fn (Builder $inner) => $inner
                    ->where('payout_no', 'like', $term)
                    ->orWhere('transaction_id', 'like', $term)
                    ->orWhereHas('collaborator', fn ($q) => $q
                        ->withTrashed()
                        ->where(fn ($c) => $c
                            ->where('name', 'like', $term)
                            ->orWhere('company_name', 'like', $term)
                            ->orWhere('collaborator_code', 'like', $term))));
            })
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->input('status')))
            ->when($request->filled('method'), fn (Builder $q) => $q->where('method', (string) $request->input('method')))
            ->when($request->filled('collaborator'), fn (Builder $q) => $q
                ->where('collaborator_id', (int) $request->input('collaborator')))
            // Dated on `requested_at`, not `paid_on`: the register is about payouts that exist, and a
            // range on the payment date would hide everything still waiting for one.
            ->whereBetween('requested_at', [$range->start(), $range->end()]);
    }

    private function range(Request $request): DateRange
    {
        return DateRange::make(
            $request->input('preset'),
            $request->input('from'),
            $request->input('to'),
        );
    }
}
