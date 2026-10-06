<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How an admission's fees stand, as Advanced Reports buckets them (D177).
 *
 * **Four answers where `StudentFeeStatus` has seven, and the reduction is deliberate.** Somebody
 * filtering a report wants "who has paid, who has started paying, who has not, who is late"; overpaid,
 * refunded and paid are the same answer to that question, and cancelled charges are not counted at
 * all. Evaluated **per admission**, over its live (non-cancelled, non-trashed) charges and its fee
 * caches (`charged_amount`, `paid_amount − refunded_amount`, `balance_amount`), first match wins:
 *
 *  1. any live charge stored `overdue` that still owes (balance > 0)   => Overdue
 *  2. no live charges at all                                          => Unpaid
 *  3. remaining (`balance_amount`) <= 0 — nothing left to pay,
 *     a fully discounted fee and an advance included                  => Paid
 *  4. net received > 0 and something still remaining                  => Partially Paid
 *  5. nothing received and something remaining                        => Unpaid
 *
 * **Why not `rollUpStatus()`'s "any pending charge => pending".** On an instalment plan every
 * student mid-course has paid instalments and future pending ones; reading the worst charge would
 * call somebody who paid 33,500 of 36,500 "Unpaid". The question here is about money received, so
 * the answer is read from it.
 *
 * It is computed once, as a SQL CASE in `AdvancedStudentReportService` over
 * `StudentFeeService::admissionChargeRollup()` and the admission caches, and used for the filter, the
 * counts, the sort, the table badge and the detail page alike (the detail page shows the selected
 * admission's row, from the same CASE) — there is no second derivation to disagree with it. The
 * "Overdue Payments" card counts the admissions in bucket 1 and sums their overdue balances.
 *
 * **Overdue lags by up to a day.** A charge whose due date passed today still reads `pending` until
 * the 01:00 `fees:mark-overdue` sweep — the same lag every other fee screen and widget has, which is
 * the point: the report says what the fee desk says.
 */
enum ReportPaymentStatus: string
{
    use HasOptions;

    case Paid = 'paid';
    case Partial = 'partial';
    case Unpaid = 'unpaid';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Paid',
            self::Partial => 'Partially Paid',
            self::Unpaid => 'Unpaid',
            self::Overdue => 'Overdue',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Paid => 'emerald',
            self::Partial => 'amber',
            self::Unpaid => 'slate',
            self::Overdue => 'rose',
        };
    }
}
