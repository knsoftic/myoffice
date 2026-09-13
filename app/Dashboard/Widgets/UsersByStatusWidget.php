<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets;

use App\Dashboard\Concerns\ComparesRanges;
use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\UserStatus;
use App\Models\User;
use App\Support\DateRange;
use Throwable;

/**
 * Who is in the system, by account state (phase-02 §3).
 *
 * Two different facts in one card, and they are deliberately not the same shape:
 *
 *  · the **breakdown** is a standing total — "there are four suspended accounts" is true today
 *    regardless of which period the selector is on;
 *  · the **new accounts** figure is range-scoped and compares against the previous window, so
 *    the date selector genuinely changes a number here.
 *
 * One query. The three aggregates are conditional sums over the same grouped scan, so adding the
 * comparison window costs nothing, and soft-deleted users are excluded by the model's global
 * scope rather than by a hand-written `whereNull`.
 */
final class UsersByStatusWidget extends Widget
{
    use ComparesRanges;

    public function key(): string
    {
        return 'users_by_status';
    }

    public function title(): string
    {
        return 'Users by status';
    }

    public function icon(): string
    {
        return 'users';
    }

    public function permission(): ?string
    {
        return 'users.view_any';
    }

    public function module(): ?string
    {
        return 'users';
    }

    public function span(): int
    {
        return 4;
    }

    public function group(): string
    {
        return WidgetGroup::OVERVIEW;
    }

    public function sort(): int
    {
        return 10;
    }

    public function skeleton(): string
    {
        return 'text';
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.users.index');
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        $previous = $range->previous();

        $counts = [];

        foreach (UserStatus::cases() as $case) {
            $counts[$case->value] = ['total' => 0, 'new' => 0, 'previous' => 0];
        }

        try {
            $rows = User::query()
                ->selectRaw('status as status_value')
                ->selectRaw('count(*) as total')
                ->selectRaw('sum(case when created_at between ? and ? then 1 else 0 end) as new_in_range', [
                    $range->storageStart()->format('Y-m-d H:i:s'),
                    $range->storageEnd()->format('Y-m-d H:i:s'),
                ])
                ->selectRaw('sum(case when created_at between ? and ? then 1 else 0 end) as new_in_previous', [
                    $previous->storageStart()->format('Y-m-d H:i:s'),
                    $previous->storageEnd()->format('Y-m-d H:i:s'),
                ])
                ->groupBy('status')
                ->get();
        } catch (Throwable) {
            return $this->unavailable($range);
        }

        foreach ($rows as $row) {
            $status = (string) $row->status_value;

            $counts[$status] = [
                'total' => (int) $row->total,
                'new' => (int) $row->new_in_range,
                'previous' => (int) $row->new_in_previous,
            ];
        }

        $total = array_sum(array_column($counts, 'total'));
        $newInRange = array_sum(array_column($counts, 'new'));
        $newInPrevious = array_sum(array_column($counts, 'previous'));

        $statuses = [];

        foreach (UserStatus::cases() as $case) {
            $count = $counts[$case->value]['total'] ?? 0;

            $statuses[] = [
                'value' => $case->value,
                'label' => $case->label(),
                'color' => $case->color(),
                'count' => $count,
                // Integer percent of the bar; 0 when there are no users at all.
                'share' => $total > 0 ? (int) round(($count / $total) * 100) : 0,
                'can_login' => $case->canLogin(),
                'href' => $this->routeUrlWithQuery('admin.users.index', ['status' => $case->value]),
            ];
        }

        return [
            'available' => true,
            'total' => $total,
            'statuses' => $statuses,
            'new_in_range' => $newInRange,
            'delta' => $this->delta($newInRange, $newInPrevious),
            'range_label' => $range->label(),
            'previous_label' => $previous->label(),
        ];
    }

    /**
     * The shape the view renders when the table cannot be read — an honest empty card, never a
     * zero presented as a measurement.
     *
     * @return array<string, mixed>
     */
    private function unavailable(DateRange $range): array
    {
        return [
            'available' => false,
            'total' => 0,
            'statuses' => [],
            'new_in_range' => 0,
            'delta' => $this->delta(0, 0),
            'range_label' => $range->label(),
            'previous_label' => $range->previous()->label(),
        ];
    }

    public function emptyMessage(): ?string
    {
        return 'No accounts have been created yet.';
    }
}
