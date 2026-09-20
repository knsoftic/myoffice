<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Collaborator;

use App\Enums\CommissionProcessingState;
use App\Enums\CommissionScope;
use App\Enums\CommissionSkipReason;
use App\Enums\CommissionSourceType;
use App\Enums\CommissionStatus;
use App\Enums\LedgerEntryPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Collaborator\BulkCommissionRequest;
use App\Http\Requests\Admin\Collaborator\StoreCommissionAdjustmentRequest;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Institute\StudentFeePayment;
use App\Services\Collaborator\CommissionApprovalService;
use App\Services\Collaborator\CommissionReversalService;
use App\Services\Collaborator\StudentCommissionService;
use App\Support\CsvWriter;
use App\Support\DateRange;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The commission ledger — `admin.commissions.*` (phase-10-12 §7.4, §8.3).
 *
 * **Nothing here computes a commission.** Every figure on every screen was written by
 * `StudentCommissionService` and is read back; the controller filters, paginates and renders. That is
 * the whole of [D-IMP-3]: one calculation site, and a static scan that keeps it that way.
 *
 * **Bulk actions send explicit ids, never a filter.** A filter re-evaluated on the server is a set
 * whose contents the person pressing the button never saw. Rows that moved since the page loaded come
 * back in a toast naming them, and are never forced.
 */
final class CommissionController extends Controller
{
    public function __construct(
        private readonly CommissionApprovalService $approvals,
        private readonly CommissionReversalService $reversals,
        private readonly StudentCommissionService $studentEngine,
    ) {}

    /**
     * One screen, two presets: everything, and what is waiting for a decision.
     */
    public function index(Request $request): View
    {
        $tab = $request->string('tab')->toString() === 'pending' ? 'pending' : 'all';

        $query = $this->filtered($request);

        if ($tab === 'pending') {
            // Oldest first: a queue is worked from the front, and the entry that has been waiting
            // longest is the one somebody is most likely to be asking about.
            $query->where('status', CommissionStatus::Pending->value)->oldest('transaction_date')->oldest('id');
        } else {
            $query->latest('transaction_date')->latest('id');
        }

        $entries = $query
            ->with([
                'collaborator:id,collaborator_code,name,company_name',
                'rule:id,version,commission_for',
            ])
            ->paginate(30)
            ->withQueryString();

        return view('admin.commissions.index', [
            'entries' => $entries,
            'tab' => $tab,
            'pendingCount' => (clone $this->filtered($request))->where('status', CommissionStatus::Pending->value)->count(),
            'pendingTotal' => Money::of((string) ((clone $this->filtered($request))
                ->where('status', CommissionStatus::Pending->value)->sum('amount') ?: Money::ZERO)),
            'collaborators' => $this->collaboratorOptions(),
            'statuses' => CommissionStatus::cases(),
            'purposes' => LedgerEntryPurpose::cases(),
            'sourceTypes' => CommissionSourceType::cases(),
            'scopes' => CommissionScope::cases(),
            'range' => $this->range($request),
            'canApprove' => (bool) $request->user()?->can('collaborator_commissions.approve'),
            'canReject' => (bool) $request->user()?->can('collaborator_commissions.reject'),
        ]);
    }

    /**
     * One entry, and the three questions anybody asks about it: how it was worked out, what it relates
     * to, and what has happened to it since.
     */
    public function show(CollaboratorCommissionLedgerEntry $entry): View
    {
        $entry->load([
            'collaborator:id,collaborator_code,name,company_name,status',
            'rule', 'entitlement', 'referral', 'approver:id,name', 'canceller:id,name',
            'studentFeePayment:id,receipt_no,amount,paid_on,status,student_fee_id',
            'original:id,amount,transaction_date,status',
            'reversals:id,reverses_entry_id,amount,purpose,status,transaction_date,payment_reversal_id',
        ]);

        return view('admin.commissions.show', [
            'entry' => $entry,
            'trace' => $this->trace($entry),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Decisions
    |--------------------------------------------------------------------------
    */

    public function approve(Request $request, CollaboratorCommissionLedgerEntry $entry): RedirectResponse
    {
        $updated = $this->approvals->approve($entry, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s is now %s.', (string) $updated->reference, strtolower($updated->status->label())),
        ]);
    }

    /**
     * One route, two transitions. `pending`/`approved` is a rejection and `available` is a
     * cancellation; they are the same decision from the operator's side — "this should not be paid" —
     * and the service picks the transition the ledger allows.
     */
    public function reject(Request $request, CollaboratorCommissionLedgerEntry $entry): RedirectResponse
    {
        $reason = trim((string) $request->input('reason', ''));

        $updated = $entry->status === CommissionStatus::Available
            ? $this->approvals->cancel($entry, $reason, $request->user())
            : $this->approvals->reject($entry, $reason, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s was cancelled. The row stays, with the reason on it.', (string) $updated->reference),
        ]);
    }

    public function bulkApprove(BulkCommissionRequest $request): RedirectResponse
    {
        $result = $this->approvals->approveMany($request->entryIds(), $request->user());

        return back()->with('toast', $this->bulkToast($result->count(), $result->skipped, $result->total, 'approved'));
    }

    public function bulkReject(BulkCommissionRequest $request): RedirectResponse
    {
        $reason = $request->reason();

        if ($reason === '') {
            return back()->withErrors(['reason' => 'Rejecting commission needs a reason. It is what the partner is shown in place of the money.']);
        }

        $result = $this->approvals->rejectMany($request->entryIds(), $reason, $request->user());

        return back()->with('toast', $this->bulkToast($result->count(), $result->skipped, $result->total, 'cancelled'));
    }

    /**
     * The manual adjustment and the write-off — the only way a figure reaches the ledger by hand, and
     * still an append (spine §6.3).
     */
    public function storeAdjustment(StoreCommissionAdjustmentRequest $request): RedirectResponse
    {
        $collaborator = Collaborator::query()->findOrFail($request->integer('collaborator_id'));

        $entry = $this->reversals->adjust(
            $collaborator,
            $request->signedAmount(),
            $request->reason(),
            null,
            $request->isWriteOff(),
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s posted %s to %s.',
                $entry->purpose->label(),
                Money::format((string) $entry->signed_amount),
                (string) $collaborator->collaborator_code),
        ]);
    }

    /**
     * The audited manual re-evaluation of §6.6 row 1 — a reinstated partner, a rule added after the
     * fact, a setting corrected.
     *
     * **A skip is a decision, not a failure** (§6.2), so the sweeper never undoes one. This does, for
     * exactly the receipts somebody named, with a mandatory reason — which is what stops a reinstated
     * collaborator being silently back-paid.
     */
    public function evaluate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'payments' => ['required', 'array', 'min:1', 'max:200'],
            'payments.*' => ['integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $processed = 0;
        $skipped = 0;

        foreach (StudentFeePayment::query()->whereIn('id', $validated['payments'])->get() as $payment) {
            $this->resetForReevaluation($payment, (string) $validated['reason'], $request);

            $outcome = $this->studentEngine->handlePayment($payment->refresh(), true);

            $outcome->isProcessed() ? $processed++ : $skipped++;
        }

        return back()->with('toast', [
            'type' => $processed > 0 ? 'success' : 'info',
            'message' => sprintf('%d receipt%s earned commission; %d still did not.',
                $processed, $processed === 1 ? '' : 's', $skipped),
        ]);
    }

    /**
     * Why receipts earned nothing, grouped so the pattern is visible (§8.8).
     *
     * This is how a misconfigured collaborator is found **before** the partner complains: eleven
     * receipts skipped for "fee type not commissionable" is a settings conversation, three for
     * "collaborator inactive" is a different one.
     */
    public function skips(Request $request): View
    {
        $range = $this->range($request);

        $base = StudentFeePayment::query()
            ->whereNotNull('commission_skip_reason')
            ->when($request->filled('collaborator'), fn (Builder $q) => $q->where('collaborator_id', $request->integer('collaborator')))
            ->when($request->filled('reason'), fn (Builder $q) => $q->where('commission_skip_reason', $request->string('reason')->toString()));

        $range->applyDates($base, 'paid_on');

        $groups = (clone $base)
            ->selectRaw('commission_skip_reason, COUNT(*) as receipts, SUM(amount) as total')
            ->groupBy('commission_skip_reason')
            ->orderByDesc('receipts')
            ->get();

        return view('admin.commissions.skips', [
            'groups' => $groups,
            'receipts' => (clone $base)
                ->with('collaborator:id,collaborator_code,name', 'fee:id,fee_number,fee_type')
                ->latest('paid_on')
                ->paginate(30)
                ->withQueryString(),
            'reasons' => CommissionSkipReason::cases(),
            'collaborators' => $this->collaboratorOptions(),
            'range' => $range,
            'canEvaluate' => (bool) $request->user()?->can('collaborator_commissions.approve'),
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse|RedirectResponse
    {
        if ($format !== 'csv') {
            return back()->with('toast', ['type' => 'error', 'message' => 'Only CSV export is available.']);
        }

        $rows = $this->filtered($request)
            ->with('collaborator:id,collaborator_code,name')
            ->latest('transaction_date')
            ->limit(5000)
            ->get();

        return (new CsvWriter)->download(
            'commissions-'.app_date(now(), 'Y-m-d').'.csv',
            ['Reference', 'Date', 'Collaborator', 'Purpose', 'Source', 'Base', 'Base amount', 'Rate', 'Amount', 'Signed', 'Status'],
            $rows->map(static fn (CollaboratorCommissionLedgerEntry $e): array => [
                (string) $e->reference,
                $e->transaction_date->toDateString(),
                (string) $e->collaborator?->collaborator_code,
                $e->purpose->label(),
                $e->source_type->label(),
                $e->commission_base->label(),
                (string) $e->base_amount,
                (string) ($e->commission_rate ?? ''),
                (string) $e->amount,
                (string) $e->signed_amount,
                $e->status->label(),
            ])->all(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function filtered(Request $request): Builder
    {
        $query = CollaboratorCommissionLedgerEntry::query()
            ->when($request->filled('collaborator'), fn (Builder $q) => $q->where('collaborator_id', $request->integer('collaborator')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('purpose'), fn (Builder $q) => $q->where('purpose', $request->string('purpose')->toString()))
            ->when($request->filled('source_type'), fn (Builder $q) => $q->where('source_type', $request->string('source_type')->toString()))
            ->when($request->filled('student'), fn (Builder $q) => $q->where('student_id', $request->integer('student')))
            ->when($request->filled('project'), fn (Builder $q) => $q->where('project_id', $request->integer('project')))
            ->when($request->filled('min_amount'), fn (Builder $q) => $q->where('amount', '>=', $request->string('min_amount')->toString()))
            ->when($request->filled('max_amount'), fn (Builder $q) => $q->where('amount', '<=', $request->string('max_amount')->toString()))
            ->when($request->boolean('reversed_only'), fn (Builder $q) => $q->where('reversed_amount', '>', 0))
            ->when($request->boolean('adjustments_only'), fn (Builder $q) => $q->whereIn('purpose', [
                LedgerEntryPurpose::ManualAdjustment->value,
                LedgerEntryPurpose::WriteOff->value,
            ]));

        if ($request->filled('scope')) {
            $scope = $request->string('scope')->toString();

            $query->whereIn('purpose', $scope === CommissionScope::Student->value
                ? [LedgerEntryPurpose::StudentCommission->value]
                : [LedgerEntryPurpose::ProjectCommission->value]);
        }

        // "Unapproved older than N days" — the question the approval queue exists to answer.
        if ($request->filled('stale_days')) {
            $query->where('status', CommissionStatus::Pending->value)
                ->whereDate('transaction_date', '<=', now()->subDays(max(1, $request->integer('stale_days')))->toDateString());
        }

        $this->range($request)->applyDates($query, 'transaction_date');

        return $query;
    }

    private function range(Request $request): DateRange
    {
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        if ($from !== '' && $to !== '') {
            return DateRange::custom($from, $to);
        }

        return DateRange::lastDays(90);
    }

    /**
     * @return Collection<int, Collaborator>
     */
    private function collaboratorOptions()
    {
        return Collaborator::query()
            ->orderBy('name')
            ->get(['id', 'name', 'company_name', 'collaborator_code']);
    }

    /**
     * The `rule_snapshot` calculation trace, flattened for the detail page.
     *
     * Read from the row rather than recomputed — the entry is what it says it is, and a page that
     * re-derived the figures would be a second opinion about a past fact.
     *
     * @return array<string, mixed>
     */
    private function trace(CollaboratorCommissionLedgerEntry $entry): array
    {
        $snapshot = $entry->rule_snapshot;

        return is_array($snapshot['calculation'] ?? null) ? $snapshot['calculation'] : [];
    }

    /**
     * Put a settled receipt back in the queue so `--force` can re-run it.
     *
     * `$force` bypasses G2 only, deliberately — it does **not** reach past G0. Undoing a skip is an
     * explicit, permissioned, audited act, and this is where the audit row is written.
     */
    private function resetForReevaluation(StudentFeePayment $payment, string $reason, Request $request): void
    {
        StudentFeePayment::allowDirectWrites(function () use ($payment): void {
            $payment->forceFill([
                'commission_state' => CommissionProcessingState::Queued->value,
                'commission_skip_reason' => null,
                'commission_skip_detail' => null,
            ])->save();
        });

        activity()
            ->performedOn($payment)
            ->causedBy($request->user())
            ->withProperties(['receipt_no' => (string) $payment->receipt_no])
            ->event('commission.re_evaluated')
            ->log(sprintf('Re-evaluated for commission: %s', $reason))
            ->forceFill(['module' => 'collaborator_commissions', 'reason' => $reason])
            ->save();
    }

    /**
     * @param  array<int, string>  $skipped
     * @return array<string, string>
     */
    private function bulkToast(int $changed, array $skipped, string $total, string $verb): array
    {
        if ($changed === 0 && $skipped !== []) {
            return [
                'type' => 'warning',
                'message' => sprintf('Nothing was %s — %d row%s had already moved. %s',
                    $verb, count($skipped), count($skipped) === 1 ? '' : 's', implode(' ', $skipped)),
            ];
        }

        $message = sprintf('%d commission %s %s, totalling %s.',
            $changed, $changed === 1 ? 'entry' : 'entries', $verb, Money::format($total));

        if ($skipped !== []) {
            $message .= sprintf(' %d skipped: %s', count($skipped), implode(' ', $skipped));
        }

        return ['type' => $skipped === [] ? 'success' : 'warning', 'message' => $message];
    }
}
