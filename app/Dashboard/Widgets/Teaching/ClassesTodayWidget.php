<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Teaching;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\ClassSessionStatus;
use App\Models\Institute\ClassSession;
use App\Support\DateRange;
use App\Support\Format;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Today's teaching, and the part of it that has not happened yet (T57).
 *
 * **Today only, whatever the date range says.** "How much teaching is left today" has exactly one
 * useful answer, and applying the dashboard's range to it would let somebody switch to "this month"
 * and lose the class starting at four o'clock.
 *
 * **Driven off `class_sessions`, not `timetable_entries` — the opposite choice from the public
 * page, for the opposite reason.** `Site\TimetableController` reads the weekly *rule* because the
 * session row carries `present_count`, `absent_count` and the teacher, and a marketing page has no
 * business one join away from a register. Here the reader is the person who runs the timetable, and
 * the three figures asked for — scheduled, happened, remaining — are *statuses*, which only the
 * dated row has. A rule cannot know that this morning's nine o'clock was cancelled, that Tuesday's
 * was moved, or that somebody added a one-off revision class; a card built on the rule would report
 * a class that nobody held and miss one that somebody did.
 *
 * **What the rule would have caught, and this does not.** Sessions are generated ahead by a job. If
 * that job has not run, `class_sessions` is empty for today while the timetable says there are four
 * classes — and this card would say "nothing on today". Answering that honestly needs a second
 * query against `timetable_entries`, which the dashboard's query budget does not have room for, so
 * the empty state names both possibilities instead of asserting the wrong one.
 *
 * **One query.** Every figure is a `SUM(CASE …)` over the same day's rows, including "the next one
 * to start", which is a `MIN(start_time)` in the same pass rather than a second `ORDER BY … LIMIT 1`.
 *
 * `session_date` is a `DATE` column, so it is compared to a date string directly — `whereDate()`
 * would wrap the column in a function and lose `idx_cs_day`. `start_time` and `end_time` are `TIME`
 * columns holding a wall clock, so they are compared against the wall clock and printed as stored.
 *
 * **No student reaches this card.** The counters `class_sessions` carries are never selected: the
 * only columns read are the status, the two times and the date.
 */
final class ClassesTodayWidget extends Widget
{
    public function key(): string
    {
        return 'teaching_classes_today';
    }

    public function title(): string
    {
        return 'Classes today';
    }

    public function icon(): string
    {
        return 'calendar-days';
    }

    public function permission(): ?string
    {
        return 'timetable.view_any';
    }

    public function module(): ?string
    {
        return 'timetable';
    }

    public function group(): string
    {
        return WidgetGroup::INSTITUTE;
    }

    public function sort(): int
    {
        return 20;
    }

    /**
     * The session list, already narrowed to today. `admin.class-sessions.index` reads `from` / `to`
     * and is guarded by `timetable.view_any` — the same permission this card is, so the link can
     * never land its own reader on a 403.
     */
    public function href(): ?string
    {
        $today = Carbon::now(Format::timezone())->toDateString();

        return $this->routeUrlWithQuery('admin.class-sessions.index', [
            'from' => $today,
            'to' => $today,
        ]);
    }

    public function emptyMessage(): ?string
    {
        return 'Nothing is on today.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $now = Carbon::now(Format::timezone());
            $today = $now->toDateString();
            // 'HH:MM:SS' on the institute's own clock, which is what the TIME columns hold. Not
            // pushed through a timezone: a class at 09:00 is 09:00 wherever it is read from.
            $clock = $now->toTimeString();

            $held = ClassSessionStatus::Held->value;
            $scheduled = ClassSessionStatus::Scheduled->value;
            $cancelled = ClassSessionStatus::Cancelled->value;
            $moved = ClassSessionStatus::Rescheduled->value;

            $row = ClassSession::query()
                ->toBase()
                // A DATE column compared to a date: indexable, and `whereDate()` here would not be.
                ->where('session_date', $today)
                ->selectRaw(
                    'COUNT(*) as total,'
                    .' SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as held,'
                    .' SUM(CASE WHEN status = ? AND end_time > ? THEN 1 ELSE 0 END) as remaining,'
                    .' SUM(CASE WHEN status = ? AND start_time <= ? AND end_time > ? THEN 1 ELSE 0 END) as in_progress,'
                    // Over, and still not marked held — the register nobody closed off.
                    .' SUM(CASE WHEN status = ? AND end_time <= ? THEN 1 ELSE 0 END) as unclosed,'
                    .' SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled,'
                    .' SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as moved,'
                    // "Next" means next on the clock, and only one that has not begun.
                    .' MIN(CASE WHEN status = ? AND start_time > ? THEN start_time END) as next_start',
                    [
                        $held,
                        $scheduled, $clock,
                        $scheduled, $clock, $clock,
                        $scheduled, $clock,
                        $cancelled,
                        $moved,
                        $scheduled, $clock,
                    ],
                )
                ->first();
        } catch (Throwable) {
            return ['available' => false];
        }

        $heldCount = (int) ($row->held ?? 0);
        $remaining = (int) ($row->remaining ?? 0);
        $unclosed = (int) ($row->unclosed ?? 0);

        return [
            'available' => true,
            // Every row dated today, cancellations included — what decides the empty state.
            'total' => (int) ($row->total ?? 0),
            // What still stands: a cancelled class is not teaching anybody.
            'standing' => $heldCount + $remaining + $unclosed,
            'held' => $heldCount,
            'remaining' => $remaining,
            'in_progress' => (int) ($row->in_progress ?? 0),
            'unclosed' => $unclosed,
            'cancelled' => (int) ($row->cancelled ?? 0),
            'moved' => (int) ($row->moved ?? 0),
            // A raw 'HH:MM:SS' from the TIME column; the view renders it with app_clock().
            'next_start' => $row->next_start ?? null,
        ];
    }
}
