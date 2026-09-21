<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Collaborator;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\ReceivedPaymentStatus;
use App\Models\Finance\ProjectPayment;
use App\Support\DateRange;
use App\Support\Money;
use Throwable;

/**
 * Client money actually received on projects, over the range (phase-10-12 §8.12).
 *
 * **Received, not invoiced.** The figure counts payment rows whose status says the money arrived, less
 * what has since been refunded, because every commission in this system follows money received and a
 * dashboard that counted invoices would disagree with the commission beside it.
 */
final class ProjectPaymentsThisMonthWidget extends Widget
{
    public function key(): string
    {
        return 'project_payments_received';
    }

    public function title(): string
    {
        return 'Project payments received';
    }

    public function icon(): string
    {
        return 'banknotes';
    }

    public function permission(): ?string
    {
        return 'project_payments.view_reports';
    }

    public function module(): ?string
    {
        return 'project_payments';
    }

    public function group(): string
    {
        return WidgetGroup::FINANCE;
    }

    public function sort(): int
    {
        return 20;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.project-payments.index');
    }

    public function emptyMessage(): ?string
    {
        return 'No project payments in this period.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $row = ProjectPayment::query()
                ->whereIn('status', array_map(
                    static fn (ReceivedPaymentStatus $status): string => $status->value,
                    array_filter(ReceivedPaymentStatus::cases(), static fn (ReceivedPaymentStatus $s): bool => $s->countsAsReceived()),
                ))
                ->tap(fn ($q) => $range->applyDates($q, 'paid_on'))
                ->selectRaw('COUNT(*) as payments')
                ->selectRaw('COALESCE(SUM(amount), 0) as gross')
                ->selectRaw('COALESCE(SUM(refunded_amount), 0) as refunded')
                ->first();
        } catch (Throwable) {
            return ['available' => false, 'range_label' => $range->label()];
        }

        $gross = Money::of((string) ($row->gross ?? Money::ZERO));
        $refunded = Money::of((string) ($row->refunded ?? Money::ZERO));

        return [
            'available' => true,
            'count' => (int) ($row->payments ?? 0),
            'gross' => $gross,
            'refunded' => $refunded,
            'net' => Money::sub($gross, $refunded),
            'range_label' => $range->label(),
        ];
    }
}
