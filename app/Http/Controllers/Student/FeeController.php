<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\DataObjects\Institute\FeeSlipOptions;
use App\Enums\ReceivedPaymentStatus;
use App\Enums\StudentFeeStatus;
use App\Enums\StudentFeeType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesTheSignedInStudent;
use App\Models\Institute\Student;
use App\Models\Institute\StudentFee;
use App\Models\Institute\StudentFeePayment;
use App\Services\Institute\FeeSlipBuilder;
use App\Services\Institute\StudentFeeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * A student's own fees — `student.fees.*` (phase-18 §8.9, §9, requirement §74).
 *
 * **Somebody else's charge is a 404, never a 403** (§9). A 403 confirms the row exists, which turns an
 * id into something worth guessing; a 404 says nothing at all. Every lookup here goes through
 * `ownCharge()`, so there is one place that decision is made.
 *
 * **The response body omits every commission column**, not just the template. `collaborator_id`,
 * `collaborator_referral_id`, `commission_state`, `commission_skip_reason`, `commission_skip_detail`
 * and the ledger figures are selected away rather than hidden, because a view that "does not print" a
 * column still ships it in the payload — and PH18-32 asserts against the body.
 *
 * Discounts **are** shown, with their type, amount, reason and date. A student whose fee changed is
 * entitled to know why; it is the partner's earnings that stay private, not the student's own bill.
 */
final class FeeController extends Controller
{
    use ResolvesTheSignedInStudent;

    /**
     * The columns a student may see. An explicit list rather than `$hidden`, so a column added to the
     * table later is invisible here until somebody decides otherwise — the safe direction.
     *
     * @var list<string>
     */
    private const VISIBLE = [
        'id', 'fee_number', 'student_id', 'course_id', 'batch_id', 'fee_type', 'title',
        'gross_amount', 'discount_amount', 'scholarship_amount', 'net_amount',
        'paid_amount', 'refunded_amount', 'balance_amount',
        'has_installment_plan', 'installment_count', 'due_date', 'status', 'created_at',
    ];

    public function __construct(
        private readonly StudentFeeService $fees,
        private readonly FeeSlipBuilder $slips,
    ) {}

    public function index(Request $request): View
    {
        $student = $this->student($request);

        $charges = StudentFee::query()
            ->select(self::VISIBLE)
            ->where('student_id', $student->getKey())
            ->whereNot('status', StudentFeeStatus::Cancelled->value)
            ->with(['course:id,name', 'batch:id,code'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('fee_type'), fn ($q) => $q->where('fee_type', $request->string('fee_type')))
            ->orderByDesc('id')
            ->paginate(per_page())
            ->withQueryString();

        return view('student.fees.index', [
            'charges' => $charges,
            // The one place a student's whole position is computed, and it is not computed here:
            // F-4.6 publishes it so no screen sums money itself (INV-26).
            'summary' => $this->fees->summaryFor($student),
            'statuses' => StudentFeeStatus::cases(),
            'feeTypes' => StudentFeeType::cases(),
            'filters' => $request->only(['status', 'fee_type']),
        ]);
    }

    public function show(Request $request, StudentFee $fee): View
    {
        $charge = $this->ownCharge($request, $fee);

        return view('student.fees.show', [
            'charge' => $charge,
            'installments' => $charge->installments()->get([
                'id', 'student_fee_id', 'installment_no', 'amount', 'due_date',
                'paid_amount', 'waived_amount', 'paid_on', 'status',
            ]),
            'payments' => $charge->payments()
                ->whereNot('status', ReceivedPaymentStatus::Voided->value)
                ->orderByDesc('paid_on')
                ->get(['id', 'student_fee_id', 'receipt_no', 'amount', 'refunded_amount', 'net_received_amount', 'paid_on', 'payment_method', 'status']),
            // Type, amount, reason and date — the student is entitled to know why their fee changed.
            'discounts' => $charge->discounts()->get(['id', 'student_fee_id', 'type', 'amount', 'percentage', 'reason', 'effective_on']),
        ]);
    }

    public function slip(Request $request, StudentFee $fee): View
    {
        $charge = $this->ownCharge($request, $fee);

        return view('student.fees.slip', [
            // `studentCopy()` is constructed here and no query parameter reaches it: the three gates of
            // §6.7.1 are all AND-ed and this is the one the panel can never turn off.
            'slip' => $this->slips->forCharge($charge, FeeSlipOptions::studentCopy()),
        ]);
    }

    public function receipt(Request $request, StudentFeePayment $payment): View
    {
        $student = $this->student($request);

        abort_unless((int) $payment->student_id === (int) $student->getKey(), 404);

        return view('student.fees.receipt', [
            'receipt' => $this->slips->forReceipt($payment, FeeSlipOptions::studentCopy()),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * One charge of this student's, with only the columns a student may see — or a 404.
     */
    private function ownCharge(Request $request, StudentFee $fee): StudentFee
    {
        $student = $this->student($request);

        abort_unless((int) $fee->student_id === (int) $student->getKey(), 404);

        /** @var StudentFee $charge */
        $charge = StudentFee::query()
            ->select(self::VISIBLE)
            ->with(['course:id,name', 'batch:id,code'])
            ->whereKey($fee->getKey())
            ->firstOrFail();

        return $charge;
    }
}
