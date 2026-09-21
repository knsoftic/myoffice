<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Collaborator;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\LedgerEntryPurpose;
use App\Services\Collaborator\CollaboratorStatementService;
use App\Support\DateRange;
use App\Support\Money;
use Throwable;

/**
 * Where commission came from, over the range (phase-10-12 §8.12).
 *
 * Read through `CollaboratorStatementService::commissionAccruedByPurpose()` (INV-26) — the same
 * `SUM(signed_amount)` the published total uses, grouped — so the slices add up to that total by
 * construction rather than by two queries that happen to agree.
 *
 * Reversals and clawbacks are shown as their own slice rather than netted into the source they came
 * from. A month with 500,000 earned and 80,000 refunded is a different month from one with 420,000
 * earned, and the chart should say so.
 */
final class CommissionBySourceChartWidget extends Widget
{
    public function __construct(
        private readonly CollaboratorStatementService $statements,
    ) {}

    public function key(): string
    {
        return 'commission_by_source';
    }

    public function title(): string
    {
        return 'Commission by source';
    }

    public function icon(): string
    {
        return 'chart-pie';
    }

    public function permission(): ?string
    {
        return 'collaborator_commissions.view_reports';
    }

    public function module(): ?string
    {
        return 'collaborator_commissions';
    }

    public function group(): string
    {
        return WidgetGroup::FINANCE;
    }

    public function span(): int
    {
        return 2;
    }

    public function sort(): int
    {
        return 80;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.commissions.index');
    }

    public function emptyMessage(): ?string
    {
        return 'No commission in this period.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $totals = $this->statements->commissionAccruedByPurpose(null, $range);
            $net = $this->statements->commissionAccruedTotal(null, $range);
        } catch (Throwable) {
            return ['available' => false, 'range_label' => $range->label()];
        }

        $slices = [];

        foreach (LedgerEntryPurpose::cases() as $purpose) {
            $amount = Money::abs($totals[$purpose->value] ?? Money::ZERO);

            if (Money::isZero($amount)) {
                continue;
            }

            $slices[] = [
                'label' => $purpose->label(),
                'value' => (float) $amount,
                'amount' => $amount,
                'color' => $purpose->color(),
            ];
        }

        return [
            'available' => true,
            'slices' => $slices,
            'labels' => array_column($slices, 'label'),
            'values' => array_column($slices, 'value'),
            'net' => $net,
            'range_label' => $range->label(),
        ];
    }
}
