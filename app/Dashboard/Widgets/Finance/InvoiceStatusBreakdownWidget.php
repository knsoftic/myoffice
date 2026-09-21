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
 * Where every invoice raised in the range currently stands (phase-13 §8.15).
 *
 * Every status is listed, including the ones with nothing in them: a card that hid its zeroes would
 * silently change shape month to month, and "no drafts" is information.
 */
final class InvoiceStatusBreakdownWidget extends Widget
{
    public function __construct(
        private readonly FinanceReportService $reports,
    ) {}

    public function key(): string
    {
        return 'finance_invoice_statuses';
    }

    public function title(): string
    {
        return 'Invoices by status';
    }

    public function icon(): string
    {
        return 'rectangle-stack';
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

    public function span(): int
    {
        return 2;
    }

    public function sort(): int
    {
        return 60;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.invoices.index');
    }

    public function emptyMessage(): ?string
    {
        return 'No invoices were raised in this period.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $data = $this->reports->invoiceStatusBreakdown(
                $range,
                request() instanceof Request ? request()->user() : null,
            );
        } catch (Throwable) {
            return ['available' => false, 'rows' => [], 'total' => 0];
        }

        foreach ($data['rows'] as $index => $row) {
            $data['rows'][$index]['href'] = $this->routeUrlWithQuery('admin.invoices.index', [
                'status' => $row['status']->value,
                'from' => $range->start()->toDateString(),
                'to' => $range->end()->toDateString(),
            ]);
        }

        $data['range_label'] = $range->label();

        return $data;
    }
}
