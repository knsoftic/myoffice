<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets;

use App\Dashboard\Concerns\ComparesRanges;
use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\LoginStatus;
use App\Models\LoginHistory;
use App\Support\DateRange;
use Throwable;

/**
 * Successful sign-ins in the selected period, against the period before it (phase-02 §3).
 *
 * Named `logins_today` because that is the key the contract fixes and keys are permanent — but
 * the figure follows the global range selector, which opens on "this month", so the card's title
 * says what it is actually counting instead of hardcoding "today". On the `today` preset the two
 * readings coincide.
 *
 * One query: the current window, the comparison window and the distinct-people count are three
 * conditional aggregates over a single scan bounded by the outer edges of both windows, so the
 * composite index on (`user_id`, `created_at`) still does the work and the comparison is free.
 */
final class LoginsTodayWidget extends Widget
{
    use ComparesRanges;

    public function key(): string
    {
        return 'logins_today';
    }

    public function title(): string
    {
        return 'Logins';
    }

    public function subtitle(): ?string
    {
        return 'Successful sign-ins';
    }

    public function icon(): string
    {
        return 'arrow-left-on-rectangle';
    }

    public function permission(): ?string
    {
        return 'login_history.view_logs';
    }

    public function module(): ?string
    {
        return 'login_history';
    }

    public function span(): int
    {
        return 3;
    }

    public function group(): string
    {
        return WidgetGroup::SECURITY;
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
        return $this->routeUrlWithQuery('admin.login-history.index', [
            'status' => LoginStatus::Success->value,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        $previous = $range->previous();

        $currentStart = $range->storageStart()->format('Y-m-d H:i:s');
        $currentEnd = $range->storageEnd()->format('Y-m-d H:i:s');
        $previousStart = $previous->storageStart()->format('Y-m-d H:i:s');
        $previousEnd = $previous->storageEnd()->format('Y-m-d H:i:s');

        try {
            $row = LoginHistory::query()
                ->where('status', LoginStatus::Success->value)
                // One scan covering both windows; the presets are contiguous, so the outer
                // boundary is the previous window's start and this window's end.
                ->whereBetween('created_at', [min($previousStart, $currentStart), max($previousEnd, $currentEnd)])
                ->selectRaw('sum(case when created_at between ? and ? then 1 else 0 end) as current_total', [$currentStart, $currentEnd])
                ->selectRaw('sum(case when created_at between ? and ? then 1 else 0 end) as previous_total', [$previousStart, $previousEnd])
                ->selectRaw('count(distinct case when created_at between ? and ? then user_id end) as current_people', [$currentStart, $currentEnd])
                ->first();
        } catch (Throwable) {
            return [
                'available' => false,
                'current' => 0,
                'people' => 0,
                'delta' => $this->delta(0, 0),
                'range_label' => $range->label(),
                'previous_label' => $previous->label(),
            ];
        }

        $current = (int) ($row->current_total ?? 0);
        $previousTotal = (int) ($row->previous_total ?? 0);
        $people = (int) ($row->current_people ?? 0);

        return [
            'available' => true,
            'current' => $current,
            'people' => $people,
            // Sign-ins per person, to one decimal: the honest way to say "busy" without a second
            // query. Zero people means no sign-ins at all, so the ratio is simply not shown.
            'per_person' => $people > 0 ? round($current / $people, 1) : null,
            'delta' => $this->delta($current, $previousTotal),
            'range_label' => $range->label(),
            'previous_label' => $previous->label(),
        ];
    }

    public function emptyMessage(): ?string
    {
        return 'Nobody signed in during this period.';
    }
}
