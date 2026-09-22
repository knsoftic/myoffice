<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Institute\StoreFeeDiscountRequest;
use App\Models\Institute\StudentFee;
use App\Models\Institute\StudentFeeDiscount;
use App\Services\Institute\StudentFeeService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Reducing what a student owes — `admin.student-fees.discounts.*`, `admin.fee-discounts.reverse`
 * (phase-18 §6.5, §8.5).
 *
 * **There is no update and no destroy, here or anywhere.** A discount row is append-only: the database
 * refuses a DELETE through `trg_sfd_no_delete` and the model refuses an UPDATE through `FinancialRow`.
 * That is not caution for its own sake — the net fee on the day a payment arrived has to stay
 * answerable, because a commission was computed from it and money may already have moved.
 *
 * The only correction is `reverse()`, which mirrors the row rather than editing it, and needs
 * `approve` rather than `edit`: undoing a discount is an authority question, not a typo question.
 */
final class FeeDiscountController extends Controller
{
    public function __construct(
        private readonly StudentFeeService $fees,
    ) {}

    public function store(StoreFeeDiscountRequest $request, StudentFee $fee): RedirectResponse
    {
        $before = (string) $fee->net_amount;

        $discount = $this->fees->addDiscount($fee, $request->toData(), $request->user());

        $after = (string) $fee->refresh()->net_amount;

        return back()->with('toast', [
            'type' => 'success',
            // Both figures, because the user typed one of them and the other is what actually changed
            // — and with a percentage discount they are never the same number.
            'message' => sprintf(
                '%s recorded. The net fee moves from %s to %s.',
                $discount->type->label(),
                Money::format($before),
                Money::format($after),
            ),
        ]);
    }

    public function reverse(Request $request, StudentFeeDiscount $discount): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'reason.required' => 'Say why this discount is being undone. The original row is kept for ever either way — that is what makes this a correction rather than a rewrite.',
        ]);

        $fee = $discount->fee;
        $before = $fee === null ? Money::ZERO : (string) $fee->net_amount;

        $reversal = $this->fees->reverseDiscount($discount, (string) $validated['reason'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf(
                'Reversed. %s goes back onto the fee — the original row stays exactly as it was.',
                Money::format(Money::abs((string) $reversal->amount)),
            ),
        ]);
    }
}
