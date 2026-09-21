<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * The single implementation of "may this person see an amount" (phase-13 §4.5 rules 2 and 3).
 *
 * Every finance controller builds its SELECT list and every finance view builds its columns from what
 * this returns. One place, because the alternative is each screen deciding for itself — and the screen
 * that decides wrongly is always the export nobody looks at until it has been emailed.
 *
 * The rule is the same everywhere: the module's own `view_financial` gates its money columns.
 * `invoices.view_any` opens the register; `invoices.view_financial` fills in the figures.
 */
final class FinanceVisibility
{
    /**
     * `module => [every column in display order, the money subset]`.
     *
     * @var array<string, array{0: list<string>, 1: list<string>}>
     */
    private const SHAPES = [
        'invoices' => [
            ['invoice_number', 'client', 'project', 'issue_date', 'due_date', 'status',
                'subtotal_amount', 'total_discount_amount', 'tax_amount', 'total_amount',
                'paid_amount', 'balance_amount'],
            ['subtotal_amount', 'total_discount_amount', 'tax_amount', 'total_amount',
                'paid_amount', 'balance_amount'],
        ],
        'expenses' => [
            ['expense_no', 'category', 'context', 'title', 'paid_to', 'expense_date',
                'payment_method', 'status', 'amount', 'refunded_amount', 'net_amount'],
            ['amount', 'refunded_amount', 'net_amount'],
        ],
        'income' => [
            ['income_no', 'category', 'context', 'title', 'received_from', 'received_on',
                'payment_method', 'status', 'amount', 'refunded_amount', 'net_amount'],
            ['amount', 'refunded_amount', 'net_amount'],
        ],
        'project_payments' => [
            ['receipt_no', 'client', 'project', 'paid_on', 'payment_method', 'status',
                'amount', 'refunded_amount', 'net_received_amount'],
            ['amount', 'refunded_amount', 'net_received_amount'],
        ],
        'payments' => [
            ['reference', 'source', 'payer', 'paid_on', 'payment_method', 'status',
                'amount', 'refunded_amount', 'net_received_amount'],
            ['amount', 'refunded_amount', 'net_received_amount'],
        ],
    ];

    public static function for(?User $user, string $module): FinanceFieldSet
    {
        [$all, $money] = self::SHAPES[$module] ?? [[], []];

        $seesMoney = $user?->can($module.'.view_financial') === true;

        if ($seesMoney) {
            return new FinanceFieldSet($all, $money, true);
        }

        // The money columns are removed from the list entirely, so a view iterating `columns()` never
        // renders a header for something it has no cell to fill.
        return new FinanceFieldSet(
            array_values(array_diff($all, $money)),
            $money,
            false,
        );
    }

    /**
     * Every module this class knows the shape of — what a test iterates so a new finance screen cannot
     * quietly ship without a visibility rule.
     *
     * @return list<string>
     */
    public static function modules(): array
    {
        return array_keys(self::SHAPES);
    }
}
