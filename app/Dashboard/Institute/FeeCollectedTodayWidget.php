<?php

declare(strict_types=1);

namespace App\Dashboard\Institute;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\ReceivedPaymentStatus;
use App\Models\Institute\StudentFeePayment;
use App\Support\DateRange;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * What the desk actually took (phase-18 §8.10, requirement §88).
 *
 * **Phase 18 owns this widget key and is the only phase that registers it** (F-8.3). The spine and
 * phase-10-12 both used to declare it; a `DashboardRegistry` key declared twice is two cards that look
 * identical and disagree, so the spine dropped it and kept the eight commission and wallet widgets.
 *
 * `net_received_amount` is a generated column (`amount - refunded_amount`), so a refund cannot be
 * forgotten here the way it could if this summed `amount` and subtracted refunds in a second query.
 * Voided receipts are excluded outright — a void means the money never counted, which is different
 * from a refund and is the reason the two are separate states.
 *
 * It reads the fee tables and nothing else: no wallet, no ledger, no entitlement (INV-26).
 */
final class FeeCollectedTodayWidget extends Widget
{
    public function key(): string
    {
        return 'fee_collected_today';
    }

    public function title(): string
    {
        return 'Fees collected';
    }

    public function icon(): string
    {
        return 'banknotes';
    }

    public function permission(): ?string
    {
        return 'student_fees.view_reports';
    }

    public function module(): ?string
    {
        return 'student_fees';
    }

    public function group(): string
    {
        return WidgetGroup::INSTITUTE;
    }

    public function sort(): int
    {
        return 10;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.fee-payments.index');
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $live = StudentFeePayment::query()
                ->whereNot('status', ReceivedPaymentStatus::Voided->value);

            $today = (clone $live)
                ->whereDate('paid_on', Carbon::today()->toDateString())
                ->get(['net_received_amount']);

            // The range card sits beside the today card deliberately: "we took 40,000 today" means
            // very little without "and 310,000 this month" next to it.
            $inRange = (clone $live)
                ->whereBetween('paid_on', [$range->storageStart()->toDateString(), $range->storageEnd()->toDateString()])
                ->get(['net_received_amount']);
        } catch (Throwable) {
            return ['available' => false, 'range_label' => $range->label()];
        }

        return [
            'available' => true,
            'today' => Money::sum($today->map(static fn ($p): string => (string) $p->net_received_amount)->all()),
            'today_count' => $today->count(),
            'range' => Money::sum($inRange->map(static fn ($p): string => (string) $p->net_received_amount)->all()),
            'range_count' => $inRange->count(),
            'range_label' => $range->label(),
            'links' => [
                'today' => $this->routeUrlWithQuery('admin.fee-payments.index', ['date' => Carbon::today()->toDateString()]),
            ],
        ];
    }
}
