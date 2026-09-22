<?php

declare(strict_types=1);

namespace App\Dashboard\Institute;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\StudentFeeStatus;
use App\Models\Institute\StudentFee;
use App\Support\DateRange;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * What is late, and how late (phase-18 §8.10, requirement §88).
 *
 * The buckets are §8.1's: 1–7 days, 8–30, and over 30. Three numbers rather than one total, because
 * "410,000 overdue" and "410,000 overdue, of which 380,000 is more than a month old" call for
 * completely different afternoons.
 *
 * It counts only what the nightly sweep has actually marked, not what the dates imply. A charge that is
 * past due but still reads `pending` has not been swept yet, and showing it here would make the card
 * disagree with the list it links to — the honest fix for that is to run `fees:mark-overdue`, which is
 * what the empty state says.
 */
final class OverdueFeesWidget extends Widget
{
    public function key(): string
    {
        return 'overdue_fees';
    }

    public function title(): string
    {
        return 'Overdue fees';
    }

    public function icon(): string
    {
        return 'exclamation-triangle';
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
        return 30;
    }

    public function href(): ?string
    {
        return $this->routeUrlWithQuery('admin.fee-collection.index', ['tab' => 'overdue']);
    }

    public function emptyMessage(): ?string
    {
        return 'No overdue fees — the institute is current.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $overdue = StudentFee::query()
                ->where('status', StudentFeeStatus::Overdue->value)
                ->where('balance_amount', '>', 0)
                ->get(['balance_amount', 'due_date']);
        } catch (Throwable) {
            return ['available' => false, 'range_label' => $range->label()];
        }

        $today = Carbon::today();

        $bucket = static function (StudentFee $charge) use ($today): string {
            $days = $charge->due_date === null ? 0 : (int) $charge->due_date->diffInDays($today);

            return match (true) {
                $days <= 7 => 'recent',
                $days <= 30 => 'month',
                default => 'old',
            };
        };

        $grouped = $overdue->groupBy($bucket);

        $total = static fn (string $key): string => Money::sum(
            ($grouped[$key] ?? collect())->map(static fn (StudentFee $c): string => (string) $c->balance_amount)->all(),
        );

        return [
            'available' => true,
            'total' => Money::sum($overdue->map(static fn (StudentFee $c): string => (string) $c->balance_amount)->all()),
            'count' => $overdue->count(),
            'buckets' => [
                ['label' => '1–7 days', 'amount' => $total('recent'), 'count' => ($grouped['recent'] ?? collect())->count()],
                ['label' => '8–30 days', 'amount' => $total('month'), 'count' => ($grouped['month'] ?? collect())->count()],
                ['label' => 'Over 30 days', 'amount' => $total('old'), 'count' => ($grouped['old'] ?? collect())->count()],
            ],
            'range_label' => $range->label(),
        ];
    }
}
