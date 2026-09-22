<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\DataObjects\Institute\FeeSlipData;
use App\DataObjects\Institute\FeeSlipOptions;
use App\DataObjects\Institute\ReceiptData;
use App\Enums\ReversalApprovalStatus;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Finance\PaymentReversal;
use App\Models\Institute\StudentAdmission;
use App\Models\Institute\StudentFee;
use App\Models\Institute\StudentFeePayment;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The printable documents (phase-18 §6.7, requirement §41, §76, §77).
 *
 * **It writes nothing.** Every figure is read at print time from the rows that already hold it — the
 * balance from the charge's caches, the commission from the entitlement and the ledger. Storing a
 * snapshot per document was the alternative and §109 forbids duplicating financial state: two copies
 * of a number are two numbers, and the printed one would be the one nobody could reconcile.
 *
 * **§6.7.1 — the commission block has three independent gates, all `AND`ed, and the student panel
 * cannot reach any of them.** §41 lists commission among the slip's fields and §112 forbids showing a
 * student a rate or an amount; both hold at once only if the staff copy and the student copy are
 * genuinely different documents. They are:
 *
 *   1. the viewer holds `collaborator_commissions.view_financial`;
 *   2. `institute.fee_slip_show_commission` is on;
 *   3. the request is not a student copy — and `FeeSlipOptions::studentCopy()` is constructed by the
 *      panel route itself, so no query parameter can turn it off.
 *
 * When the block is withheld it comes back as `null`, never as zeroes. A row of zeroes is a claim that
 * the referral earned nothing, which is a different statement from "you are not being shown this" and
 * is usually false.
 *
 * The student's copy still names the collaborator (§41, Q9). The student already knows who referred
 * them; it is the money that stays hidden.
 */
final class FeeSlipBuilder
{
    /**
     * One charge's slip.
     */
    public function forCharge(StudentFee $charge, FeeSlipOptions $options): FeeSlipData
    {
        $charge->loadMissing(['student', 'course', 'batch', 'collaborator', 'branch']);

        return new FeeSlipData(
            charge: $charge,
            installments: $charge->installments()->get(),
            payments: $charge->payments()->orderBy('paid_on')->get(),
            discounts: $charge->discounts()->get(),
            printedAt: CarbonImmutable::parse(Carbon::now()->toDateTimeString()),
            options: $options,
            commission: $this->commissionFor($charge, $options),
            collaboratorName: $charge->collaborator?->name,
            footerNote: $this->footer(),
        );
    }

    /**
     * The admission-level structure slip: every charge of one admission with a grand total, and the
     * proof line `SUM(net) = net payable` printed as a statement rather than assumed (§6.7, Q3).
     *
     * @return array{charges: Collection<int, StudentFee>, total: string, net_payable: string, balances: bool, printed_at: CarbonImmutable, options: FeeSlipOptions, footer: string|null}
     */
    public function forAdmission(StudentAdmission $admission, FeeSlipOptions $options): array
    {
        $charges = StudentFee::query()
            ->where('student_admission_id', $admission->getKey())
            ->with(['student', 'course', 'batch'])
            ->orderBy('id')
            ->get();

        $total = Money::sum($charges->map(static fn (StudentFee $c): string => (string) $c->net_amount)->all());
        $netPayable = Money::of((string) $admission->net_payable);

        return [
            'charges' => $charges,
            'total' => $total,
            'net_payable' => $netPayable,
            // Printed in rose when it does not hold. The generator asserts this before it writes, so a
            // slip that disagrees means somebody changed a figure afterwards — which is worth seeing.
            'balances' => Money::compare($total, $netPayable) === 0,
            'printed_at' => CarbonImmutable::parse(Carbon::now()->toDateTimeString()),
            'options' => $options,
            'footer' => $this->footer(),
        ];
    }

    /**
     * One receipt (§6.7.2).
     *
     * The balance is recomputed here and stamped with the print time; see `ReceiptData` for why that
     * is the honest choice rather than storing a snapshot on the payment row.
     */
    public function forReceipt(StudentFeePayment $payment, FeeSlipOptions $options): ReceiptData
    {
        // `student` is deliberately absent: a receipt has no direct student relation, and adding
        // one would be a second path to the same fact. It reaches the student through its charge,
        // which is the relation the charge's own scoping already governs.
        $payment->loadMissing(['fee', 'fee.student', 'installment', 'receivedBy']);

        $reversal = PaymentReversal::query()
            ->where('student_fee_payment_id', $payment->getKey())
            ->whereNot('approval_status', ReversalApprovalStatus::Rejected->value)
            ->orderByDesc('id')
            ->first();

        return new ReceiptData(
            payment: $payment,
            printedAt: CarbonImmutable::parse(Carbon::now()->toDateTimeString()),
            balanceAtPrint: (string) ($payment->fee?->balance_amount ?? Money::ZERO),
            options: $options,
            reversalNumber: $reversal?->reversal_no,
            footerNote: $this->footer(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * §6.7.1's three gates, then the figures — read from the ledger, never from a duplicated column.
     *
     * @return array{collaborator: string, base: string, base_amount: string, rate: string, amount: string}|null
     */
    private function commissionFor(StudentFee $charge, FeeSlipOptions $options): ?array
    {
        // Gate 3 (the student copy) and gate 1 (the permission) both live on the options object; gate 2
        // is configuration. All three, or nothing — and "nothing" means null.
        if (! $options->mayRenderCommission()) {
            return null;
        }

        if (! (bool) setting('institute.fee_slip_show_commission', false)) {
            return null;
        }

        $entries = CollaboratorCommissionLedgerEntry::query()
            ->where('student_fee_id', $charge->getKey())
            ->get();

        if ($entries->isEmpty()) {
            return null;
        }

        // Signed, so a reversal shows as having taken the credit back rather than as a second earning.
        $amount = Money::sum($entries->map(static fn ($e): string => (string) $e->signed_amount)->all());
        $first = $entries->first();

        return [
            'collaborator' => (string) ($charge->collaborator?->name ?? '—'),
            'base' => (string) ($first->commission_base ?? '—'),
            'base_amount' => (string) ($first->base_amount ?? Money::ZERO),
            'rate' => (string) ($first->commission_rate ?? '0.0000'),
            'amount' => $amount,
        ];
    }

    private function footer(): ?string
    {
        $note = setting('institute.fee_slip_footer_note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
