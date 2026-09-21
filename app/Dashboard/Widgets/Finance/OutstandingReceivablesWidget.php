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
 * What clients owe right now, and what they have already paid that nobody has applied (phase-13 §8.15).
 *
 * The credits line is the point. A business holding an unapplied advance is owed less than its invoice
 * balances say, and a card that showed only the debt would send somebody chasing money already in the
 * account.
 *
 * It is **as at today**, not over the range: a debt is a state, not a period.
 */
final class OutstandingReceivablesWidget extends Widget
{
    public function __construct(
        private readonly FinanceReportService $reports,
    ) {}

    public function key(): string
    {
        return 'finance_outstanding_receivables';
    }

    public function title(): string
    {
        return 'Outstanding';
    }

    public function icon(): string
    {
        return 'document-text';
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
        return 40;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.reports.finance.receivables-aging');
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            return $this->reports->outstandingReceivables(
                request() instanceof Request ? request()->user() : null,
            );
        } catch (Throwable) {
            return ['available' => false];
        }
    }
}
