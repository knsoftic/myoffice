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
 * Claims waiting for somebody to agree them (phase-13 §8.15).
 *
 * Its own card rather than a line on the expenses one, because this is **work**, not a figure: money
 * awaiting a decision is real, it is in no report, and a queue nobody can see from the dashboard is a
 * queue that grows.
 */
final class PendingExpenseApprovalsWidget extends Widget
{
    public function __construct(
        private readonly FinanceReportService $reports,
    ) {}

    public function key(): string
    {
        return 'finance_pending_expense_approvals';
    }

    public function title(): string
    {
        return 'Waiting for approval';
    }

    public function icon(): string
    {
        return 'clock';
    }

    public function permission(): ?string
    {
        return 'expenses.view_financial';
    }

    public function module(): ?string
    {
        return 'expenses';
    }

    public function group(): string
    {
        return WidgetGroup::FINANCE;
    }

    public function sort(): int
    {
        return 90;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.expenses.approvals');
    }

    public function emptyMessage(): ?string
    {
        return 'Every claim has been decided.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            return $this->reports->pendingExpenseApprovals(
                request() instanceof Request ? request()->user() : null,
            );
        } catch (Throwable) {
            return ['available' => false, 'count' => 0];
        }
    }
}
