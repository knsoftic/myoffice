<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\DataObjects\Institute\InstallmentLine;
use App\Enums\InstallmentInterval;
use App\Enums\InstallmentStatus;
use App\Enums\RemainderPlacement;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Institute\StoreInstallmentPlanRequest;
use App\Models\Institute\StudentFee;
use App\Models\Institute\StudentFeeInstallment;
use App\Services\Institute\InstallmentPlanCalculator;
use App\Services\Institute\StudentFeeService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Splitting a fee — `admin.installments.*` (phase-18 §6.2, §8.4, §77).
 *
 * **`preview()` and `store()` compute the plan the same way, through the same calculator.** The wizard
 * shows the amounts before anybody commits and the server builds them again on submit; a preview
 * produced by a second implementation is a preview that can disagree with what actually gets written,
 * and the disagreement would be in somebody's money.
 *
 * **A rebuild never reuses a number** ([D18-6], D50). New lines continue from `MAX + 1`, so a rebuilt
 * plan reads 1, 2, 5, 6. That looks like a bug and is the opposite: reusing 3 would make "the third
 * installment" ambiguous in exactly the conversation where it matters — a fee dispute.
 */
final class InstallmentPlanController extends Controller
{
    public function __construct(
        private readonly StudentFeeService $fees,
        private readonly InstallmentPlanCalculator $calculator,
    ) {}

    /**
     * The wizard's preview table. Writes nothing.
     */
    public function preview(Request $request, StudentFee $fee): JsonResponse
    {
        $validated = $request->validate([
            'count' => ['required', 'integer', 'between:'.InstallmentPlanCalculator::MIN_LINES.','.InstallmentPlanCalculator::MAX_LINES],
            'interval' => ['required', Rule::enum(InstallmentInterval::class)],
            'first_due_on' => ['required', 'date'],
            'placement' => ['nullable', Rule::enum(RemainderPlacement::class)],
        ]);

        $placement = $validated['placement'] ?? setting('institute.installment_remainder_placement', RemainderPlacement::Last->value);

        $lines = $this->calculator->generate(
            netAmount: (string) $fee->net_amount,
            count: (int) $validated['count'],
            firstDueOn: Carbon::parse((string) $validated['first_due_on']),
            interval: InstallmentInterval::from((string) $validated['interval']),
            placement: RemainderPlacement::from((string) $placement),
            startNumber: $this->nextNumber($fee),
        );

        $total = Money::sum(array_map(static fn (InstallmentLine $l): string => $l->amount, $lines));

        return response()->json([
            'lines' => array_map(static fn (InstallmentLine $l): array => $l->toArray(), $lines),
            'total' => $total,
            'net' => (string) $fee->net_amount,
            // P-1 holds by construction, so this is always true — it is on the payload because the
            // banner says so to the user, and a banner that is computed is a banner that stays honest
            // if somebody edits an amount in the table below it.
            'balances' => Money::compare($total, (string) $fee->net_amount) === 0,
            'received' => $fee->netReceived(),
            // [D18-4]: a plan cannot be built once money has arrived. Said here so the wizard can
            // explain it rather than submit and be refused.
            'can_build' => Money::isZero($fee->netReceived()),
        ]);
    }

    public function store(StoreInstallmentPlanRequest $request, StudentFee $fee): RedirectResponse
    {
        $lines = $this->fees->buildInstallmentPlan(
            $fee,
            $request->toLines($this->nextNumber($fee)),
            $request->user(),
        );

        return redirect()
            ->route('admin.student-fees.show', $fee)
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf(
                    '%d installments scheduled, totalling %s.',
                    $lines->count(),
                    Money::format(Money::sum($lines->map(static fn (StudentFeeInstallment $l): string => (string) $l->amount)->all())),
                ),
            ]);
    }

    public function rebuild(StoreInstallmentPlanRequest $request, StudentFee $fee): RedirectResponse
    {
        $lines = $this->fees->rebuildInstallmentPlan(
            $fee,
            $request->toLines($this->nextNumber($fee)),
            (string) $request->input('reason'),
            $request->user(),
        );

        return redirect()
            ->route('admin.student-fees.show', $fee)
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf(
                    '%d new installments scheduled. Numbers continue from where the old plan left off — '
                    .'they are never reused, so "the third installment" keeps meaning one thing.',
                    $lines->count(),
                ),
            ]);
    }

    /**
     * Waive part or all of one line (§6.3.4).
     *
     * Needs `installments.change_status` **and** `fee_discounts.approve`, which the policy enforces
     * together: a waiver parks an amount on the line and writes a discount row that lowers the net fee,
     * so the first ability alone would let somebody give money away one installment at a time.
     */
    public function waive(Request $request, StudentFeeInstallment $installment): RedirectResponse
    {
        $remaining = Money::sub(
            Money::sub((string) $installment->amount, (string) $installment->paid_amount),
            (string) $installment->waived_amount,
        );

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:'.$remaining],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'approved_by' => ['nullable', 'integer', 'exists:users,id'],
        ], [
            'amount.max' => sprintf('Only %s is still outstanding on this installment. A waiver can only release what is still owed.', Money::format($remaining)),
            'reason.required' => 'Say why. A waiver reduces the net fee by the same amount, and somebody will ask what it was for.',
        ]);

        $this->fees->waiveInstallment(
            $installment,
            (string) $validated['amount'],
            (string) $validated['reason'],
            $request->user(),
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf(
                '%s waived. The net fee falls by the same amount, so the plan stays in balance.',
                Money::format((string) $validated['amount']),
            ),
        ]);
    }

    /**
     * Where a rebuild's numbering continues from. `withTrashed()` because a soft-deleted line still
     * holds its number, and handing it out again would collide with `uq_sfi_no`.
     */
    private function nextNumber(StudentFee $fee): int
    {
        $live = $fee->installments()
            ->whereNot('status', InstallmentStatus::Cancelled->value)
            ->exists();

        return $live || $fee->installments()->withTrashed()->exists()
            ? (int) $fee->installments()->withTrashed()->max('installment_no') + 1
            : 1;
    }
}
