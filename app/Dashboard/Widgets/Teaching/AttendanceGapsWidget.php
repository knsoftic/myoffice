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
 * Registers from the last fortnight that nobody filled in (T57).
 *
 * **An unmarked register is invisible until somebody needs a report out of it**, and by then the
 * class was three weeks ago and nobody remembers who was in the room. Attendance is corrected,
 * never invented (INV-I10), so a gap that is found late is a gap that stays. This card exists to
 * find it while the answer still exists in somebody's head.
 *
 * **"Marked" is a timestamp, not an inference.** `class_sessions.attendance_marked_at` being NULL
 * is the definition of unmarked — the migration says so, `ClassSession::scopeUnmarked()` says so,
 * and `isAttendanceMarked()` says so. It is deliberately *not* derived from whether any
 * `student_attendances` rows exist: a register where every student was absent is a marked register
 * with no present rows, and a roster that was opened and abandoned can leave rows behind without
 * ever having been completed. `present_count` is likewise no answer — a genuinely empty class marks
 * zero present.
 *
 * **Only `held` sessions can have a gap.** A cancelled class is nobody's absence and a rescheduled
 * one happened under a different row, which is exactly what `ClassSessionStatus::countsInAttendance()`
 * means. Counting either would invent work that does not exist.
 *
 * **One query, and it keeps its own denominator.** `scopeUnmarked()` is the predicate this card is
 * about, but applying it as a filter throws away the number that gives the headline its meaning:
 * three unmarked out of four classes is a teacher who never marks, three out of a hundred and
 * twenty is a bad week. So the same two conditions the scope applies are used as a `CASE`, over a
 * `WHERE` of "held, in the window" — one pass for the gaps, the total, the stale ones, the oldest
 * date and the number of batches involved.
 *
 * `session_date` is a `DATE` column and `ClassSession::scopeBetween()` compares it to date strings,
 * so `idx_cs_day` is usable; `whereDate()` would wrap the column in a function and lose it.
 *
 * **No student reaches this card.** The register itself is never read — only the session rows'
 * status, date, batch id and marking timestamp. A count of who is missing is a number; who they are
 * is a screen the coordinator opens, not a line on a dashboard.
 */
final class AttendanceGapsWidget extends Widget
{
    /** The window, in days, counting today — a fortnight. */
    private const WINDOW_DAYS = 14;

    /** Older than this and the gap has stopped being an oversight. */
    private const STALE_AFTER_DAYS = 7;

    public function key(): string
    {
        return 'teaching_attendance_gaps';
    }

    public function title(): string
    {
        return 'Registers not marked';
    }

    public function icon(): string
    {
        return 'clipboard-document-check';
    }

    public function permission(): ?string
    {
        return 'student_attendance.view_any';
    }

    public function module(): ?string
    {
        return 'student_attendance';
    }

    public function group(): string
    {
        return WidgetGroup::INSTITUTE;
    }

    public function sort(): int
    {
        return 30;
    }

    /**
     * The register's own landing screen, already filtered to what has not been marked. It is guarded
     * by `student_attendance.view_any` — this card's permission — so the link can never hand its own
     * reader a 403, which `admin.class-sessions.index` (a `timetable` route) could.
     */
    public function href(): ?string
    {
        return $this->routeUrlWithQuery('admin.student-attendance.index', ['unmarked_only' => 1]);
    }

    public function emptyMessage(): ?string
    {
        return 'Every register is filled in.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $today = Carbon::now(Format::timezone())->startOfDay();
            $from = $today->copy()->subDays(self::WINDOW_DAYS - 1);
            $staleBefore = $today->copy()->subDays(self::STALE_AFTER_DAYS);

            $row = ClassSession::query()
                // The model's own window scope: whereBetween on the DATE column, dates to dates.
                ->between($from, $today)
                // The one status that goes into anybody's attendance, named by the enum.
                ->where('status', ClassSessionStatus::Held->value)
                ->toBase()
                ->selectRaw(
                    'COUNT(*) as held,'
                    .' SUM(CASE WHEN attendance_marked_at IS NULL THEN 1 ELSE 0 END) as unmarked,'
                    .' SUM(CASE WHEN attendance_marked_at IS NULL AND session_date < ? THEN 1 ELSE 0 END) as stale,'
                    .' MIN(CASE WHEN attendance_marked_at IS NULL THEN session_date END) as oldest,'
                    .' COUNT(DISTINCT CASE WHEN attendance_marked_at IS NULL THEN batch_id END) as batches',
                    [$staleBefore->toDateString()],
                )
                ->first();
        } catch (Throwable) {
            return ['available' => false];
        }

        $heldCount = (int) ($row->held ?? 0);
        $unmarked = (int) ($row->unmarked ?? 0);

        return [
            'available' => true,
            'window_days' => self::WINDOW_DAYS,
            'stale_after_days' => self::STALE_AFTER_DAYS,
            // Classes actually held in the window — the denominator that gives the gap a size.
            'held' => $heldCount,
            'unmarked' => $unmarked,
            'marked' => max(0, $heldCount - $unmarked),
            // Unmarked and more than a week old: the ones nobody is going to remember.
            'stale' => (int) ($row->stale ?? 0),
            // 'YYYY-MM-DD' from the DATE column, or null when there is no gap at all.
            'oldest' => $row->oldest ?? null,
            'batches' => (int) ($row->batches ?? 0),
        ];
    }
}
