<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Workspace;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\TimeEntryStatus;
use App\Models\Project\TimeEntry;
use App\Support\DateRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * The signed-in person's own hours this week, and whether their clock is still ticking.
 *
 * **Scoped to `time_entries.user_id`, which is the worker — not `recorded_by`, which is the typist.**
 * `chk_te_one_worker` makes an entry belong to exactly one of `user_id` (staff) or
 * `collaborator_id`, and when a manager keys time on somebody's behalf the two columns differ: the
 * hours are the worker's and `recorded_by` merely says who typed them. Scoping to `recorded_by`
 * would show a manager their own admin and hide the worker's own week from them. The id comes from
 * `Auth::id()`, never from a request parameter (CLAUDE.md golden rule 10).
 *
 * **`Auth::id()` null means zero, never everybody.** A console render has no viewer, and falling
 * back to "all workers" would put the whole team's timesheet on a card labelled "my".
 *
 * **This week, whatever the date range says.** A timesheet card answers one question — am I behind
 * this week — and it has the same answer no matter which period the rest of the page is showing.
 * The week starts on the day `localization.week_start` names, via `DateRange::week()`, so the card
 * and the time screen cannot disagree about when Monday is.
 *
 * **The window scanned is a week wider than the week reported, and that is the point.** `work_date`
 * is the business date the session belongs to, so a timer started on Sunday night and still running
 * on Monday morning carries *last* week's date. Filtered to the week alone, the card would tell
 * somebody their clock was stopped while it was quietly running. Scanning back seven days and
 * selecting the week's figures with conditional sums catches it, still on the
 * `(user_id, work_date)` index and still in one query.
 *
 * **`duration_seconds` is the cached total and excludes the open segment (INV-P5).** A running
 * timer's live seconds are computed in the browser and never persisted, so "6h 40m" here means
 * time already banked; the running badge is what says there is more accruing.
 *
 * **`work_date` is a DATE column, so it is compared to date strings** — no `whereDate()`, which
 * would wrap the column in a function and lose the index. Discarded entries are soft-deleted and
 * `toBase()` applies that scope, so a discarded hour leaves the total exactly as the model promises.
 */
final class MyTimeThisWeekWidget extends Widget
{
    /** How far back the scan reaches beyond the week, to catch a timer started before it. */
    private const OVERHANG_DAYS = 7;

    public function key(): string
    {
        return 'my_time_this_week';
    }

    public function title(): string
    {
        return 'My time this week';
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
        return 25;
    }

    public function subtitle(): ?string
    {
        try {
            return DateRange::week()->label();
        } catch (Throwable) {
            return null;
        }
    }

    public function href(): ?string
    {
        try {
            $week = DateRange::week();
        } catch (Throwable) {
            return $this->routeUrl('admin.time.index');
        }

        return $this->routeUrlWithQuery('admin.time.index', [
            'from' => $week->start()->toDateString(),
            'to' => $week->end()->toDateString(),
        ]);
    }

    public function emptyMessage(): ?string
    {
        return 'No time logged yet this week.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        $viewer = Auth::id();

        if ($viewer === null) {
            return self::nobody();
        }

        try {
            $week = DateRange::week();
            $weekStart = $week->start()->toDateString();
            $weekEnd = $week->end()->toDateString();
            // The same timezone the week was built in — "today" has to be the same day the week
            // thinks it is, or Sunday's hours land in neither figure.
            $today = Carbon::now($week->timezone())->toDateString();
            $scanFrom = $week->start()->subDays(self::OVERHANG_DAYS)->toDateString();

            $row = TimeEntry::query()
                ->toBase()
                ->where('user_id', $viewer)
                ->whereBetween('work_date', [$scanFrom, $weekEnd])
                ->selectRaw(
                    'SUM(CASE WHEN work_date >= ? THEN duration_seconds ELSE 0 END) as week_seconds,'
                    .' SUM(CASE WHEN work_date = ? THEN duration_seconds ELSE 0 END) as today_seconds,'
                    .' COUNT(DISTINCT CASE WHEN work_date >= ? THEN work_date END) as days_logged,'
                    .' SUM(CASE WHEN work_date >= ? THEN 1 ELSE 0 END) as entries,'
                    // Counted across the whole scanned window, not just the week — see the class note.
                    .' SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as running,'
                    .' SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as paused',
                    [
                        $weekStart,
                        $today,
                        $weekStart,
                        $weekStart,
                        TimeEntryStatus::Running->value,
                        TimeEntryStatus::Paused->value,
                    ],
                )
                ->first();
        } catch (Throwable) {
            return ['available' => false];
        }

        $weekSeconds = (int) ($row->week_seconds ?? 0);
        $todaySeconds = (int) ($row->today_seconds ?? 0);

        return [
            'available' => true,
            'mine' => true,
            'week_hours' => intdiv($weekSeconds, 3600),
            'week_minutes' => intdiv($weekSeconds % 3600, 60),
            'today_hours' => intdiv($todaySeconds, 3600),
            'today_minutes' => intdiv($todaySeconds % 3600, 60),
            'week_seconds' => $weekSeconds,
            'today_seconds' => $todaySeconds,
            'days_logged' => (int) ($row->days_logged ?? 0),
            'entries' => (int) ($row->entries ?? 0),
            'running' => (int) ($row->running ?? 0) > 0,
            'paused' => (int) ($row->paused ?? 0) > 0,
        ];
    }

    /**
     * What a render with no signed-in viewer reports: zero, honestly, rather than the whole team's
     * timesheet on a card labelled "my".
     *
     * @return array<string, mixed>
     */
    private static function nobody(): array
    {
        return [
            'available' => true,
            'mine' => false,
            'week_hours' => 0,
            'week_minutes' => 0,
            'today_hours' => 0,
            'today_minutes' => 0,
            'week_seconds' => 0,
            'today_seconds' => 0,
            'days_logged' => 0,
            'entries' => 0,
            'running' => false,
            'paused' => false,
        ];
    }
}
