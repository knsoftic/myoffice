<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\LoginStatus;
use App\Models\LoginHistory;
use App\Support\DateRange;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Sign-ins per day, successful against failed — the dashboard's one chart (phase-02 §3).
 *
 * **What it plots.** The contract calls this a 14-day line, and §5 says the global range selector
 * feeds every widget; those two only agree if 14 days is a floor rather than a fixed window. So the
 * window is derived from the selection in three steps, and the card states which one applied:
 *
 *  1. **clamped to today** — "this month" runs to the 30th, and eighteen days of future zeros is a
 *     lie dressed as a flat line, so the window never extends past now;
 *  2. **widened to 14 days** when what is left is shorter than that, because a one-day selection is
 *     not a trend;
 *  3. **trimmed to its last 90 days** when it is longer, so a daily x-axis stays readable and the
 *     row count stays bounded.
 *
 * One grouped query returns at most 2 × 90 rows. Days with no sign-ins are filled from
 * `DateRange::dateKeys()` in PHP, so a quiet Sunday renders as a zero instead of disappearing and
 * shortening the line.
 */
final class LoginTrendChartWidget extends Widget
{
    /** The contract's 14-day line: the minimum window this card will ever draw. */
    private const MINIMUM_DAYS = 14;

    /** Beyond this a daily x-axis is unreadable, so the window is trimmed to its last 90 days. */
    private const MAXIMUM_DAYS = 90;

    public function key(): string
    {
        return 'login_trend_chart';
    }

    public function title(): string
    {
        return 'Login trend';
    }

    public function icon(): string
    {
        return 'chart-bar';
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
        return 6;
    }

    public function group(): string
    {
        return WidgetGroup::SECURITY;
    }

    public function sort(): int
    {
        return 30;
    }

    public function skeleton(): string
    {
        return 'text';
    }

    public function minHeight(): ?int
    {
        return 300;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.login-history.index');
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        [$window, $note] = $this->window($range);

        $empty = [
            'available' => false,
            'labels' => [],
            'series' => [],
            'rows' => [],
            'totals' => ['success' => 0, 'failed' => 0],
            'peak' => null,
            'window_label' => $window->label(),
            'days' => $window->days(),
            'note' => $note,
        ];

        try {
            $rows = LoginHistory::query()
                ->selectRaw('date(created_at) as day')
                ->selectRaw('sum(case when status = ? then 1 else 0 end) as successful', [LoginStatus::Success->value])
                ->selectRaw('sum(case when status in (?, ?) then 1 else 0 end) as failed', [
                    LoginStatus::Failed->value,
                    LoginStatus::Blocked->value,
                ])
                ->whereBetween('created_at', [
                    $window->storageStart()->format('Y-m-d H:i:s'),
                    $window->storageEnd()->format('Y-m-d H:i:s'),
                ])
                ->groupByRaw('date(created_at)')
                ->orderByRaw('date(created_at)')
                ->get();
        } catch (Throwable) {
            return $empty;
        }

        $successByDay = [];
        $failedByDay = [];

        foreach ($rows as $row) {
            $day = substr((string) $row->day, 0, 10);
            $successByDay[$day] = (int) $row->successful;
            $failedByDay[$day] = (int) $row->failed;
        }

        $labels = [];
        $success = [];
        $failed = [];
        $table = [];
        $peak = null;

        foreach ($window->dateKeys() as $day) {
            $successCount = $successByDay[$day] ?? 0;
            $failedCount = $failedByDay[$day] ?? 0;

            $labels[] = Format::date($day, 'd M');
            $success[] = $successCount;
            $failed[] = $failedCount;

            $table[] = [
                'day' => $day,
                'label' => Format::date($day),
                'successful' => $successCount,
                'failed' => $failedCount,
            ];

            if ($peak === null || $successCount > $peak['successful']) {
                $peak = ['day' => $day, 'label' => Format::date($day), 'successful' => $successCount];
            }
        }

        $totalSuccess = array_sum($success);
        $totalFailed = array_sum($failed);

        return [
            'available' => true,
            'labels' => $labels,
            'series' => [
                [
                    'label' => 'Successful',
                    'data' => $success,
                    'color' => LoginStatus::Success->color(),
                ],
                [
                    'label' => 'Failed or blocked',
                    'data' => $failed,
                    'color' => LoginStatus::Failed->color(),
                ],
            ],
            'rows' => $table,
            'totals' => ['success' => $totalSuccess, 'failed' => $totalFailed],
            // A per-day mean reads better than a total on a trend card.
            'average' => $window->days() > 0 ? round($totalSuccess / $window->days(), 1) : 0.0,
            'peak' => $totalSuccess > 0 ? $peak : null,
            'window_label' => $window->label(),
            'days' => $window->days(),
            'note' => $note,
        ];
    }

    public function subtitle(): ?string
    {
        return 'Sign-ins per day';
    }

    public function emptyMessage(): ?string
    {
        return 'No sign-ins have been recorded in this window.';
    }

    /**
     * The window actually drawn, and a one-line note when it is not the selection itself.
     *
     * @return array{0: DateRange, 1: string|null}
     */
    private function window(DateRange $range): array
    {
        $timezone = $range->timezone();
        $now = CarbonImmutable::now($timezone);

        $window = $range;
        $note = null;

        // 1. Never plot the future.
        if ($range->end()->greaterThan($now)) {
            $window = $range->start()->greaterThan($now)
                ? DateRange::custom($now, $now, $timezone)
                : DateRange::custom($range->start(), $now, $timezone);

            $note = 'to today';
        }

        // 2. A window shorter than the contract's 14 days is not a trend.
        if ($window->days() < self::MINIMUM_DAYS) {
            return [DateRange::lastDays(self::MINIMUM_DAYS, $timezone), 'last '.self::MINIMUM_DAYS.' days'];
        }

        // 3. Keep the most recent slice rather than thinning the whole period: the end of the
        //    window is what somebody looking at a dashboard is asking about.
        if ($window->days() > self::MAXIMUM_DAYS) {
            return [
                DateRange::custom(
                    $window->end()->subDays(self::MAXIMUM_DAYS - 1),
                    $window->end(),
                    $timezone,
                ),
                'last '.self::MAXIMUM_DAYS.' days',
            ];
        }

        return [$window, $note];
    }
}
