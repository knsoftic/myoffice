<?php

declare(strict_types=1);

namespace App\Dashboard\Institute;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\StudentFeeStatus;
use App\Models\Institute\StudentFee;
use App\Support\DateRange;
use App\Support\Money;
use Throwable;

/**
 * What is still owed (phase-18 §8.10, requirement §88).
 *
 * Reads `balance_amount`, which is a cache — and deliberately so. It is recomputed from the rows under
 * the charge's own lock by `StudentFeeService::recomputeCaches()`, and `fees:verify-plan-integrity`
 * re-derives it nightly and **reports** any drift rather than repairing it. A dashboard that summed the
 * receipts itself would be a fourth opinion about the same number; INV-26 is the rule and this is what
 * following it looks like on a screen.
 *
 * The next-due figure is the one thing here that is not a total: a desk needs to know what is landing
 * this week, not only what is outstanding in aggregate.
 */
final class PendingFeesWidget extends Widget
{
    public function key(): string
    {
        return 'pending_fees';
    }

    public function title(): string
    {
        return 'Outstanding fees';
    }

    public function icon(): string
    {
        return 'clock';
    }

    public function permission(): ?string
    {
        return 'student_fees.view_reports';
    }

    public function module(): ?string
    {
        return 'student_fees';
    }

    public function group(): string
    {
        return WidgetGroup::INSTITUTE;
    }

    public function sort(): int
    {
        return 20;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.fee-collection.index');
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $open = StudentFee::query()
                ->whereIn('status', [
                    StudentFeeStatus::Pending->value,
                    StudentFeeStatus::Partial->value,
                    StudentFeeStatus::Overdue->value,
                ])
                ->where('balance_amount', '>', 0)
                ->get(['balance_amount', 'due_date']);

            $dueThisWeek = $open->filter(
                static fn (StudentFee $c): bool => $c->due_date !== null
                    && $c->due_date->betweenIncluded(now()->startOfDay(), now()->addDays(7)->endOfDay()),
            );

            // A negative balance is an advance, not a debt, and it is counted separately rather than
            // netted off — a desk that sees "outstanding 200,000" when 40,000 of it is somebody else's
            // credit balance will chase the wrong students.
            $advances = StudentFee::query()
                ->where('balance_amount', '<', 0)
                ->get(['balance_amount']);
        } catch (Throwable) {
            return ['available' => false, 'range_label' => $range->label()];
        }

        return [
            'available' => true,
            'outstanding' => Money::sum($open->map(static fn (StudentFee $c): string => (string) $c->balance_amount)->all()),
            'charges' => $open->count(),
            'due_this_week' => Money::sum($dueThisWeek->map(static fn (StudentFee $c): string => (string) $c->balance_amount)->all()),
            'due_this_week_count' => $dueThisWeek->count(),
            'advances' => Money::abs(Money::sum($advances->map(static fn (StudentFee $c): string => (string) $c->balance_amount)->all())),
            'advances_count' => $advances->count(),
            'range_label' => $range->label(),
        ];
    }
}
