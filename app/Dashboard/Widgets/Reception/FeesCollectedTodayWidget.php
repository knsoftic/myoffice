<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Reception;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\ReceivedPaymentStatus;
use App\Models\Institute\StudentFeePayment;
use App\Support\DateRange;
use App\Support\Format;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * What the front desk took today, and how much of it this person took.
 *
 * **Today only, and net of refunds.** The money question at a front desk at six o'clock is "does
 * the drawer agree with the system", and that is a question about today. It is also a question
 * about *net*: a receipt written for 20,000 and refunded by 5,000 is 15,000 in the drawer, so the
 * card subtracts `refunded_amount` rather than reporting the gross and letting somebody reconcile
 * against a number that was never true.
 *
 * **Voided and bounced are excluded; fully refunded is not.** Voided and bounced money never
 * arrived, so counting it would invent cash. A fully refunded receipt nets to zero on its own
 * arithmetic, so including it needs no special case and keeps the receipt count honest — the desk
 * did write that receipt today.
 *
 * **Mine versus everyone's.** Several people can be on the desk in a day, and "did I bank what I
 * took" is a personal question. `received_by` is the user the payment screen stamped, so the split
 * is the real one rather than a guess from `created_by`.
 *
 * Every figure is summed by the **database** on `decimal(15,2)` columns and the one subtraction
 * happens in `Money` (bcmath). No amount is ever a PHP float — golden rule 4.
 */
final class FeesCollectedTodayWidget extends Widget
{
    public function key(): string
    {
        return 'reception_fees_today';
    }

    public function title(): string
    {
        return 'Fees collected today';
    }

    public function icon(): string
    {
        return 'banknotes';
    }

    public function permission(): ?string
    {
        return 'student_fee_payments.view_any';
    }

    public function module(): ?string
    {
        return 'student_fee_payments';
    }

    public function group(): string
    {
        return WidgetGroup::FRONT_DESK;
    }

    public function sort(): int
    {
        return 40;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.fee-payments.index');
    }

    public function emptyMessage(): ?string
    {
        return 'No fee has been taken today.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $today = Carbon::now(Format::timezone())->toDateString();

            // Money that actually arrived. Voided and bounced rows are not cash.
            $holding = [
                ReceivedPaymentStatus::Cleared->value,
                ReceivedPaymentStatus::PartiallyRefunded->value,
                ReceivedPaymentStatus::Refunded->value,
            ];

            $userId = Auth::id();

            // **One query for both totals.** "Everyone's" and "mine" are the same rows counted two
            // ways, so the viewer's share is a conditional sum rather than a second round trip.
            // `?` for the user id even when it is null: the CASE then matches nothing and the
            // personal figures come back zero, which is the right answer for a console run.
            $row = StudentFeePayment::query()
                ->toBase()
                // `where`, not `whereDate`: `paid_on` is already a DATE column and carries its own
                // index, and wrapping it in DATE() would make the comparison unindexable on the
                // one table that grows with every receipt the institute ever writes.
                ->where('paid_on', $today)
                ->whereIn('status', $holding)
                ->selectRaw(
                    'COALESCE(SUM(amount), 0) as gross,'
                    .' COALESCE(SUM(refunded_amount), 0) as refunded,'
                    .' COUNT(*) as receipts,'
                    .' COALESCE(SUM(CASE WHEN received_by = ? THEN amount ELSE 0 END), 0) as mine_gross,'
                    .' COALESCE(SUM(CASE WHEN received_by = ? THEN refunded_amount ELSE 0 END), 0) as mine_refunded,'
                    .' SUM(CASE WHEN received_by = ? THEN 1 ELSE 0 END) as mine_receipts',
                    [$userId, $userId, $userId],
                )
                ->first();
        } catch (Throwable) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'net' => Money::sub((string) ($row->gross ?? '0'), (string) ($row->refunded ?? '0')),
            'receipts' => (int) ($row->receipts ?? 0),
            'mine_net' => $userId === null
                ? null
                : Money::sub((string) ($row->mine_gross ?? '0'), (string) ($row->mine_refunded ?? '0')),
            'mine_receipts' => $userId === null ? null : (int) ($row->mine_receipts ?? 0),
            'refunded' => (string) ($row->refunded ?? '0'),
        ];
    }
}
