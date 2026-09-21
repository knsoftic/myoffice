<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Finance;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Services\Finance\FinanceReportService;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Throwable;

/**
 * Invoices past their due date and still unpaid (phase-13 §8.15).
 *
 * The oldest one is named, because "four overdue" is a statistic and "INV000007, 43 days, Acme" is
 * something somebody can act on this morning.
 */
final class OverdueInvoicesWidget extends Widget
{
    public function __construct(
        private readonly FinanceReportService $reports,
    ) {}

    public function key(): string
    {
        return 'finance_overdue_invoices';
    }

    public function title(): string
    {
        return 'Overdue invoices';
    }

    public function icon(): string
    {
        return 'exclamation-triangle';
    }

    public function permission(): ?string
    {
        return 'invoices.view_financial';
    }

    public function module(): ?string
    {
        return 'invoices';
    }

    public function group(): string
    {
        return WidgetGroup::FINANCE;
    }

    public function sort(): int
    {
        return 50;
    }

    public function href(): ?string
    {
        return $this->routeUrlWithQuery('admin.invoices.index', ['status' => 'overdue', 'preset' => 'year']);
    }

    public function emptyMessage(): ?string
    {
        return 'Nothing is overdue.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $data = $this->reports->overdueInvoices(
                request() instanceof Request ? request()->user() : null,
            );
        } catch (Throwable) {
            return ['available' => false];
        }

        $worst = $data['worst'] ?? null;

        $data['worst_link'] = $worst === null
            ? null
            : $this->routeUrl('admin.invoices.show', (int) $worst->id);

        return $data;
    }
}
