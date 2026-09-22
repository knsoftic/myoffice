<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Finance;

use App\DataObjects\Finance\RecordPaymentData;
use App\DataObjects\Finance\RefundData;
use App\DataObjects\Institute\FeeSlipOptions;
use App\Enums\CommissionProcessingState;
use App\Enums\PaymentMethod;
use App\Enums\ReceivedPaymentStatus;
use App\Enums\ReversalType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Finance\RecordFeePaymentRequest;
use App\Models\Collaborator\Collaborator;
use App\Models\Institute\StudentFee;
use App\Models\Institute\StudentFeePayment;
use App\Services\Finance\PaymentService;
use App\Services\Institute\FeeSlipBuilder;
use App\Support\CsvWriter;
use App\Support\DateRange;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The student payments register and the record-payment path — `admin.fee-payments.*`
 * (phase-10-12 §7.1, §8.1, §8.2).
 *
 * **Phase 10 owns the receipt; Phase 18 owns the charge.** The screens that create, edit and cancel a
 * fee charge belong to the institute phase. What lives here is the money: taking it, showing it back,
 * and the two ways it goes out again.
 *
 * **There is no edit and no delete**, and there never will be (INV-8). A mis-keyed receipt is voided
 * and re-entered with a fresh idempotency key, which leaves three rows telling the whole story rather
 * than one row that was quietly corrected.
 */
final class FeePaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    /**
     * The register (§8.2).
     */
    public function index(Request $request): View
    {
        $filtered = $this->filtered($request);

        $totals = (clone $filtered)
            ->selectRaw('COALESCE(SUM(amount), 0) as amount')
            ->selectRaw('COALESCE(SUM(refunded_amount), 0) as refunded')
            ->selectRaw('COALESCE(SUM(net_received_amount), 0) as net')
            ->first();

        return view('admin.fee-payments.index', [
            'payments' => $filtered
                ->with([
                    'fee:id,fee_number,fee_type,student_id',
                    'collaborator:id,collaborator_code,name,company_name',
                    'receivedBy:id,name',
                ])
                ->latest('paid_on')
                ->latest('id')
                ->paginate(30)
                ->withQueryString(),
            'totals' => [
                'amount' => Money::of((string) ($totals->amount ?? '0.00')),
                'refunded' => Money::of((string) ($totals->refunded ?? '0.00')),
                'net' => Money::of((string) ($totals->net ?? '0.00')),
            ],
            'methods' => PaymentMethod::cases(),
            'statuses' => ReceivedPaymentStatus::cases(),
            'states' => CommissionProcessingState::cases(),
            'collaborators' => Collaborator::query()->orderBy('name')->get(['id', 'name', 'company_name', 'collaborator_code']),
            'range' => $this->range($request),
            'dateColumn' => $this->dateColumn($request),
        ]);
    }

    public function show(StudentFeePayment $payment): View
    {
        return view('admin.fee-payments.show', [
            'payment' => $payment->load([
                'fee:id,fee_number,fee_type,student_id,net_amount,paid_amount,balance_amount',
                'installment:id,installment_no,amount,due_date',
                'collaborator:id,collaborator_code,name,company_name',
                'referral:id,referral_code,referral_source,effective_from',
                'receivedBy:id,name',
                'reversals',
                'commissionEntries',
            ]),
        ]);
    }

    /**
     * Step 4 of the wizard: what this receipt **would** earn, before anybody commits.
     *
     * Read-only, and it runs the same guards and the same pure calculator the engine runs — so the
     * figure on the screen and the figure on the resulting entry cannot disagree.
     */
    public function preview(Request $request, StudentFee $fee): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'paid_on' => ['nullable', 'date'],
            'student_fee_installment_id' => ['nullable', 'integer'],
        ]);

        $preview = $this->payments->dryRun($fee, new RecordPaymentData(
            amount: (string) $validated['amount'],
            method: PaymentMethod::Cash,
            paidOn: isset($validated['paid_on']) ? Carbon::parse((string) $validated['paid_on']) : null,
            installmentId: isset($validated['student_fee_installment_id'])
                ? (int) $validated['student_fee_installment_id']
                : null,
        ));

        return response()->json([
            'earns' => $preview->earns,
            'amount' => $preview->amount,
            'formatted' => $preview->amount === null ? null : Money::format($preview->amount),
            'base_amount' => $preview->baseAmount,
            'collaborator' => $preview->collaborator === null ? null : [
                'code' => (string) $preview->collaborator->collaborator_code,
                'name' => $preview->collaborator->displayName(),
            ],
            'rule' => $preview->rule === null ? null : [
                'version' => $preview->rule->rule?->version,
                'rate' => $preview->rule->rate,
                'fixed_amount' => $preview->rule->fixedAmount,
                'base' => $preview->rule->base->label(),
            ],
            'skip_reason' => $preview->skip?->value,
            'sentence' => $preview->sentence(),
            'duplicate' => $this->duplicateWarning($fee, $validated),
        ]);
    }

    public function store(RecordFeePaymentRequest $request, StudentFee $fee): RedirectResponse
    {
        $result = $this->payments->recordStudentFeePayment($fee, $request->toPaymentData());

        return redirect()
            ->route('admin.fee-payments.show', $result->payment)
            ->with('toast', [
                'type' => $result->created ? 'success' : 'info',
                'message' => $result->created
                    ? sprintf('Receipt %s recorded for %s. Commission is evaluated in the background.',
                        (string) $result->payment->receipt_no, Money::format((string) $result->payment->amount))
                    : sprintf('This was already recorded as receipt %s — nothing was taken twice.',
                        (string) $result->payment->receipt_no),
            ]);
    }

    public function refund(Request $request, StudentFeePayment $payment): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'type' => ['required', 'string'],
            'refund_method' => ['nullable', 'string', 'max:32'],
            'reference_no' => ['nullable', 'string', 'max:64'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64'],
        ]);

        $reversal = $this->payments->refund($payment, new RefundData(
            amount: (string) $validated['amount'],
            reason: (string) $validated['reason'],
            type: ReversalType::from((string) $validated['type']),
            method: $validated['refund_method'] ?? null,
            idempotencyKey: (string) $validated['idempotency_key'],
            referenceNo: $validated['reference_no'] ?? null,
        ));

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s returned on %s. %s',
                Money::format((string) $reversal->amount),
                (string) $reversal->reversal_no,
                $reversal->mayReverseCommission()
                    ? 'Any commission it earned is being undone.'
                    : 'Nothing is undone until somebody approves it.'),
        ]);
    }

    /**
     * The only "edit" a receipt has (INV-8): void it and enter the corrected one afresh.
     */
    public function void(Request $request, StudentFeePayment $payment): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $this->payments->void($payment, (string) $validated['reason']);

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('Receipt %s is voided. Enter the corrected one as a new receipt — the '
                .'trail then shows what was taken, what was voided and why, and what replaced it.',
                (string) $payment->receipt_no),
        ]);
    }

    /**
     * **Phase 18 §6.7.2 owns this document now, and both panels print the same one.**
     *
     * Phase 10 shipped a receipt on the admin layout, before there was a print layout or a
     * `FeeSlipBuilder` to build one. It could not meet the rules §6.7.2 later stated — both dates
     * labelled, a balance carrying its own print timestamp, a VOID watermark with the reversal number,
     * a REPRINT stamp — and a second template for one receipt is where those eventually get forgotten
     * in one copy and not the other. `resources/views/fees/receipt.blade.php` is the document; the
     * staff and student routes differ only in the `FeeSlipOptions` they construct.
     */
    public function receipt(Request $request, StudentFeePayment $payment, FeeSlipBuilder $slips): View
    {
        $printCount = max(1, (int) $request->integer('print_count', 1));

        return view('fees.receipt', [
            'receipt' => $slips->forReceipt($payment, FeeSlipOptions::forStaff(
                viewerMaySeeCommission: (bool) $request->user()?->can('collaborator_commissions.view_financial'),
                isReprint: $printCount > 1,
                printCount: $printCount,
            )),
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse|RedirectResponse
    {
        if ($format !== 'csv') {
            return back()->with('toast', ['type' => 'error', 'message' => 'Only CSV export is available.']);
        }

        $rows = $this->filtered($request)
            ->with('fee:id,fee_number', 'collaborator:id,collaborator_code')
            ->latest('paid_on')
            ->limit(5000)
            ->get();

        return (new CsvWriter)->download(
            'fee-payments-'.app_date(now(), 'Y-m-d').'.csv',
            ['Receipt', 'Paid on', 'Recorded', 'Charge', 'Method', 'Amount', 'Refunded', 'Net received', 'Status', 'Commission', 'Partner'],
            $rows->map(static fn (StudentFeePayment $p): array => [
                (string) $p->receipt_no,
                $p->paid_on->toDateString(),
                (string) $p->recorded_at?->toDateTimeString(),
                (string) $p->fee?->fee_number,
                $p->payment_method->label(),
                (string) $p->amount,
                (string) $p->refunded_amount,
                (string) $p->net_received_amount,
                $p->status->label(),
                $p->commission_state->label().($p->commission_skip_reason === null ? '' : ': '.$p->commission_skip_reason->label()),
                (string) $p->collaborator?->collaborator_code,
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
        $query = StudentFeePayment::query()
            ->when($request->filled('method'), fn (Builder $q) => $q->where('payment_method', $request->string('method')->toString()))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('commission_state'), fn (Builder $q) => $q->where('commission_state', $request->string('commission_state')->toString()))
            ->when($request->filled('collaborator'), fn (Builder $q) => $q->where('collaborator_id', $request->integer('collaborator')))
            ->when($request->filled('branch'), fn (Builder $q) => $q->where('branch_id', $request->integer('branch')))
            ->when($request->boolean('has_refund'), fn (Builder $q) => $q->where('refunded_amount', '>', 0))
            ->when($request->filled('min_amount'), fn (Builder $q) => $q->where('amount', '>=', $request->string('min_amount')->toString()))
            ->when($request->filled('max_amount'), fn (Builder $q) => $q->where('amount', '<=', $request->string('max_amount')->toString()))
            ->when($request->filled('q'), function (Builder $q) use ($request): void {
                $term = '%'.$request->string('q')->toString().'%';

                $q->where(fn (Builder $inner) => $inner
                    ->where('receipt_no', 'like', $term)
                    ->orWhere('reference_no', 'like', $term)
                    ->orWhereHas('fee', fn (Builder $fee) => $fee->where('fee_number', 'like', $term)));
            });

        $this->range($request)->applyDates($query, $this->dateColumn($request));

        return $query;
    }

    /**
     * The register filters on the **value date** by default, with a toggle to the system date.
     *
     * Both are always stored, and which one a report means is a real question: "money taken in March"
     * and "money entered in March" are different numbers the moment anybody back-dates a receipt.
     */
    private function dateColumn(Request $request): string
    {
        return $request->string('date_column')->toString() === 'recorded_at' ? 'recorded_at' : 'paid_on';
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
     * §8.1's amber step 4: an identical receipt already exists, named so the cashier can look at it.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>|null
     */
    private function duplicateWarning(StudentFee $fee, array $input): ?array
    {
        $twin = StudentFeePayment::query()
            ->where('student_fee_id', $fee->getKey())
            ->where('amount', Money::of((string) $input['amount']))
            ->whereDate('paid_on', Carbon::parse((string) ($input['paid_on'] ?? now()))->toDateString())
            ->whereNot('status', ReceivedPaymentStatus::Voided->value)
            ->first(['id', 'receipt_no']);

        return $twin === null ? null : [
            'receipt_no' => (string) $twin->receipt_no,
            'message' => sprintf('Receipt %s already records this amount against this charge on that day. '
                .'If this is a genuine second payment, confirm it — both receipts are kept and the '
                .'override is logged.', (string) $twin->receipt_no),
        ];
    }
}
