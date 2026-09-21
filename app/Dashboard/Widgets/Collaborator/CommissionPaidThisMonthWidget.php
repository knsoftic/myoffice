<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Collaborator;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Services\Collaborator\CollaboratorWalletService;
use App\Support\DateRange;
use App\Support\Money;
use Throwable;

/**
 * Commission that actually left the company, over the range (phase-10-12 §8.12).
 *
 * Read through `CollaboratorWalletService::payoutsPaidTotal()` (INV-26), which derives it from live
 * allocations on paid payouts — never from `SUM(collaborator_payouts.amount)` and never from a status
 * somebody flipped (INV-23). The comparison against the previous period uses the identical call, so the
 * two figures cannot be computed two ways.
 */
final class CommissionPaidThisMonthWidget extends Widget
{
    public function __construct(
        private readonly CollaboratorWalletService $wallets,
    ) {}

    public function key(): string
    {
        return 'commission_paid_out';
    }

    public function title(): string
    {
        return 'Commission paid out';
    }

    public function icon(): string
    {
        return 'paper-airplane';
    }

    public function permission(): ?string
    {
        return 'collaborator_payouts.view_reports';
    }

    public function module(): ?string
    {
        return 'collaborator_payouts';
    }

    public function group(): string
    {
        return WidgetGroup::FINANCE;
    }

    public function sort(): int
    {
        return 40;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.payouts.index');
    }

    public function emptyMessage(): ?string
    {
        return 'Nothing was paid out in this period.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $current = $this->wallets->payoutsPaidTotal(null, $range);
            $previous = $this->wallets->payoutsPaidTotal(null, $range->previous());
        } catch (Throwable) {
            return ['available' => false, 'range_label' => $range->label()];
        }

        return [
            'available' => true,
            'current' => $current,
            'previous' => $previous,
            'change' => Money::sub($current, $previous),
            'range_label' => $range->label(),
            'previous_label' => $range->previous()->label(),
        ];
    }
}
