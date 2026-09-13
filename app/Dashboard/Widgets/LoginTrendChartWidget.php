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
 * **Which day a sign-in belongs to (D61).** Rows are stored in UTC; the x-axis is the window's own
 * calendar days in the range's (display) timezone. Grouping by `date(created_at)` produced UTC dates, so
 * in Asia/Karachi a 02:30 sign-in counted on the previous day and the first day's 00:00–05:00 slice was
 * keyed to a date outside the axis and silently dropped. Each day is instead bucketed by the UTC instant
 * its local midnight falls on — computed in PHP by Carbon, so a daylight-saving day of 23 or 25 hours and
 * a half-hour offset are exact — and MariaDB's `INTERVAL()` (a binary search over those sorted
 * boundaries) turns `unix_timestamp(created_at)` into the day's index. `unix_timestamp()` of a TIMESTAMP
 * column is the stored instant, so the bucketing does not depend on the session time zone either.
 *
 * One grouped query returns at most 90 rows. Days with no sign-ins are filled from
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

        $dayKeys = $window->dateKeys();

        try {
            [$dayIndex, $dayIndexBindings] = $this->dayIndexExpression($dayKeys, $window->timezone());

            $rows = LoginHistory::query()
                ->selectRaw($dayIndex.' as day_index', $dayIndexBindings)
                ->selectRaw('sum(case when status = ? then 1 else 0 end) as successful', [LoginStatus::Success->value])
                ->selectRaw('sum(case when status in (?, ?) then 1 else 0 end) as failed', [
                    LoginStatus::Failed->value,
                    LoginStatus::Blocked->value,
                ])
                ->whereBetween('created_at', [
                    $window->storageStart()->format('Y-m-d H:i:s'),
                    $window->storageEnd()->format('Y-m-d H:i:s'),
                ])
                ->groupBy('day_index')
                ->orderBy('day_index')
                ->get();
        } catch (Throwable) {
            return $empty;
        }

        $successByDay = [];
        $failedByDay = [];

        foreach ($rows as $row) {
            // Every row inside the window maps to an index of $dayKeys; anything else is ignored
            // rather than keyed to a day the axis does not have.
            $day = $dayKeys[(int) $row->day_index] ?? null;

            if ($day === null) {
                continue;
            }

            $successByDay[$day] = (int) $row->successful;
            $failedByDay[$day] = (int) $row->failed;
        }

        $labels = [];
        $success = [];
        $failed = [];
        $table = [];
        $peak = null;

        foreach ($dayKeys as $day) {
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
     * SQL that maps a row to the index of its display-timezone day in `$dayKeys`, with its bindings.
     *
     * `INTERVAL(n, b1, …, bk)` returns 0 when n < b1, i when bi <= n < bi+1, and k when n >= bk. The
     * boundaries are the unix instants of local midnight for the 2nd … last day, so the result is the
     * day's position in `$dayKeys` — the query's `whereBetween` already keeps everything before the
     * first midnight and after the last day's end out. A missing local midnight (a daylight-saving
     * jump at 00:00) resolves to the first instant that exists that day, which is where that day
     * starts.
     *
     * @param  list<string>  $dayKeys  'Y-m-d', ascending
     * @return array{0: string, 1: list<int>}
     */
    private function dayIndexExpression(array $dayKeys, string $timezone): array
    {
        $boundaries = [];

        foreach (array_slice($dayKeys, 1) as $day) {
            $boundaries[] = CarbonImmutable::createFromFormat('!Y-m-d', $day, $timezone)->getTimestamp();
        }

        if ($boundaries === []) {
            return ['0', []];
        }

        return [
            'interval(unix_timestamp(created_at), '.implode(', ', array_fill(0, count($boundaries), '?')).')',
            $boundaries,
        ];
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
