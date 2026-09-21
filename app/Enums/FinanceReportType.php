<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The four software-house finance reports (requirement §99, phase-13 §6.7).
 *
 * `permissions()` returns the exact `can:` pair each one needs, so the route definitions and the report
 * picker read **one** definition. A report whose route and whose menu entry disagreed about who may open
 * it is a permission bug nobody notices until the wrong person reads a profit figure.
 */
enum FinanceReportType: string
{
    use HasOptions;

    case Income = 'income';
    case Expenses = 'expenses';
    case ProfitLoss = 'profit_loss';
    case ReceivablesAging = 'receivables_aging';

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Income',
            self::Expenses => 'Expenses',
            self::ProfitLoss => 'Profit & loss',
            self::ReceivablesAging => 'Receivables aging',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Income => 'emerald',
            self::Expenses => 'rose',
            self::ProfitLoss => 'brand',
            self::ReceivablesAging => 'amber',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Income => 'Money received, by source and category.',
            self::Expenses => 'Money spent, by category and context. Approved expenses only.',
            self::ProfitLoss => 'Income less expenses for the period, with the commission memo.',
            self::ReceivablesAging => 'What clients owe, by how long it has been owed.',
        };
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Income => ['income.view_reports'],
            self::Expenses => ['expenses.view_reports'],
            self::ProfitLoss => ['income.view_reports', 'expenses.view_reports'],
            self::ReceivablesAging => ['invoices.view_reports'],
        };
    }
}
