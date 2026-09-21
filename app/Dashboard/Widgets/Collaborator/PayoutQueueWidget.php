<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Collaborator;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\PayoutStatus;
use App\Models\Collaborator\CollaboratorPayout;
use App\Support\DateRange;
use App\Support\Money;
use Throwable;

/**
 * Payouts waiting for somebody to do something (phase-10-12 §8.12).
 *
 * Two queues, because they need two different people: **approve** is a decision, and **pay** is a
 * trip to the bank. Collapsing them into one "pending" figure would hide which of the two is stuck.
 */
final class PayoutQueueWidget extends Widget
{
    public function key(): string
    {
        return 'payout_queue';
    }

    public function title(): string
    {
        return 'Payout queue';
    }

    public function icon(): string
    {
        return 'queue-list';
    }

    public function permission(): ?string
    {
        return 'collaborator_payouts.view_any';
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
        return 60;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.payouts.index');
    }

    public function emptyMessage(): ?string
    {
        return 'No payouts are waiting.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $rows = CollaboratorPayout::query()
                ->whereIn('status', [
                    PayoutStatus::Requested->value, PayoutStatus::Pending->value, PayoutStatus::Approved->value,
                ])
                ->groupBy('status')
                ->selectRaw('status, COUNT(*) as entries, COALESCE(SUM(amount), 0) as total')
                ->get()
                ->keyBy('status');
        } catch (Throwable) {
            return ['available' => false, 'range_label' => $range->label()];
        }

        $bucket = static function (array $statuses) use ($rows): array {
            $count = 0;
            $total = Money::ZERO;

            foreach ($statuses as $status) {
                $row = $rows->get($status);

                if ($row === null) {
                    continue;
                }

                $count += (int) $row->entries;
                $total = Money::add($total, Money::of((string) $row->total));
            }

            return ['count' => $count, 'total' => $total];
        };

        $toApprove = $bucket([PayoutStatus::Requested->value, PayoutStatus::Pending->value]);
        $toPay = $bucket([PayoutStatus::Approved->value]);

        return [
            'available' => true,
            'to_approve' => $toApprove,
            'to_pay' => $toPay,
            'count' => $toApprove['count'] + $toPay['count'],
            'total' => Money::add($toApprove['total'], $toPay['total']),
            'range_label' => 'right now',
            'approve_href' => $this->routeUrlWithQuery('admin.payouts.index', ['status' => PayoutStatus::Requested->value]),
            'pay_href' => $this->routeUrlWithQuery('admin.payouts.index', ['status' => PayoutStatus::Approved->value]),
        ];
    }
}
