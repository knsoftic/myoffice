<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Delivery;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\TimeEntrySource;
use App\Enums\TimeEntryStatus;
use App\Models\Project\TimeEntry;
use App\Support\DateRange;
use App\Support\Money;
use Throwable;

/**
 * Hours logged against delivery over the selected period.
 *
 * **This is the one delivery card that honours the dashboard's range, and the other three do not.**
 * Hours are a period measure — "how much work went in" is meaningless without a window, and it is the
 * one figure on this row somebody compares against last month. Live projects, open tasks and standing
 * milestones are *states*: filtering them to a past month would report a backlog that no longer exists.
 * {@see \App\Dashboard\Widgets\Institute\ActiveCoursesWidget} makes the same distinction for the
 * institute row.
 *
 * **Counted on `work_date`, never on `created_at` or `started_at`.** `work_date` is the business date
 * in the worker's own timezone, it is what the model's docblock says every rollup groups on, and it is
 * what keeps a 23:50–00:10 session on the day the worker means. An entry keyed on Monday for Friday's
 * work belongs to Friday. It is a DATE column, so `DateRange::applyDates()` compares it to date strings
 * — no `whereDate()`, which would forfeit the `work_date` index.
 *
 * **Three numbers under the total, each of them a question about the hours rather than a restatement
 * of them.** Time booked to a project but to no task is time nobody can attribute to a deliverable.
 * Manual entries are hours somebody typed rather than timed, which is exactly the set a manager
 * reviews. A timer still live — running or merely paused, both hold the `uq_te_running` slot — is
 * usually a timer somebody forgot on Friday afternoon, and it contributes nothing to the total until it
 * is stopped, because `duration_seconds` is the sum of *closed* segments (INV-P5).
 *
 * **There is no billable split to report.** `time_entries` carries no `is_billable` column and no rate:
 * phase-06 R-10 rules both out on purpose, leaving billing to Phase 13's invoicing model rather than
 * inventing a second one here. When that column arrives the unbilled figure is one more `SUM(CASE …)`
 * in the same single query.
 *
 * Hours are seconds divided by 3,600 and that division goes through `App\Support\Money` (bcmath): the
 * quotient stays an exact decimal string from the query to the view, and never becomes the one number
 * on the dashboard that does not add up.
 */
final class TimeLoggedWidget extends Widget
{
    /** Seconds in an hour, as the decimal string `Money` divides by. */
    private const SECONDS_PER_HOUR = '3600';

    public function key(): string
    {
        return 'delivery_time_logged';
    }

    public function title(): string
    {
        return 'Hours logged';
    }

    public function icon(): string
    {
        return 'clock';
    }

    public function permission(): ?string
    {
        return 'time_tracking.view_any';
    }

    public function module(): ?string
    {
        return 'time_tracking';
    }

    public function group(): string
    {
        return WidgetGroup::OPERATIONS;
    }

    public function sort(): int
    {
        return 40;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.time.index');
    }

    public function emptyMessage(): ?string
    {
        return 'No hours were logged in this period.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $query = TimeEntry::query()->toBase();

            // The scope convention of `DateRange`: a DATE column goes through applyDates(), which
            // compares dates to dates and so never loses a row to a time component that is not there.
            $range->applyDates($query, 'work_date');

            // **One query.** Five figures over the same rows; discarded entries are already excluded by
            // the soft-delete scope `toBase()` applied.
            $row = $query
                ->selectRaw(
                    'COUNT(*) as entries,'
                    .' COALESCE(SUM(duration_seconds), 0) as total_seconds,'
                    .' COALESCE(SUM(CASE WHEN task_id IS NULL THEN duration_seconds ELSE 0 END), 0) as untasked_seconds,'
                    .' COALESCE(SUM(CASE WHEN source = ? THEN duration_seconds ELSE 0 END), 0) as manual_seconds,'
                    .' SUM(CASE WHEN status <> ? THEN 1 ELSE 0 END) as live_entries',
                    [TimeEntrySource::Manual->value, TimeEntryStatus::Stopped->value],
                )
                ->first();

            $hours = $this->hours($row?->total_seconds ?? 0);
            $untasked = $this->hours($row?->untasked_seconds ?? 0);
            $manual = $this->hours($row?->manual_seconds ?? 0);
        } catch (Throwable) {
            return ['available' => false, 'range_label' => $range->label()];
        }

        return [
            'available' => true,
            'range_label' => $range->label(),
            'hours' => $hours,
            'untasked_hours' => $untasked,
            'manual_hours' => $manual,
            'entries' => (int) ($row?->entries ?? 0),
            'live_entries' => (int) ($row?->live_entries ?? 0),
        ];
    }

    /**
     * Whole seconds as a decimal-string count of hours: '12345' -> '3.43'.
     *
     * Through `Money::div()` rather than `/` so the value is exact at two places and never round-trips
     * through a float on its way to the card.
     */
    private function hours(mixed $seconds): string
    {
        return Money::div((string) (int) $seconds, self::SECONDS_PER_HOUR);
    }
}
