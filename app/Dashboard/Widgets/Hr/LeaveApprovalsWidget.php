<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Hr;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\LeaveRequestStatus;
use App\Models\Hr\LeaveRequest;
use App\Support\DateRange;
use App\Support\Format;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Leave waiting for a decision, and how long the oldest has waited (requirement §27, HR-7, T57).
 *
 * **A state, not a period.** The dashboard's range is deliberately ignored: a request filed three
 * weeks ago is *more* urgent than one filed this morning, and a card filtered to "today" would
 * report zero on the exact morning the queue most needed reading.
 *
 * **The wait is the number that matters, not the count.** Six requests filed this morning is a busy
 * day; one that has sat for nine days is somebody who has already booked a flight. So the headline
 * is the count and the line under it is the oldest wait — those two together are the only pair that
 * says whether to open the screen now.
 *
 * **"Already started" is the emergency.** A pending request whose `from_date` is today or earlier is
 * somebody who is either absent without an approved leave or at work when they expected not to be,
 * and it is the one row in the queue that cannot wait for tomorrow. It is counted separately rather
 * than folded into the total, where it would disappear.
 *
 * **One query.** Every figure is an aggregate over the same pending rows, counted in a single pass
 * with conditional sums — `GET /admin` is measured against a query ceiling. `from_date` and
 * `applied_on` are `date` columns and are compared against plain date strings; `whereDate()` on a
 * DATE column only discards the `(status, from_date)` index.
 *
 * `isOpen()` on the enum decides what "waiting" means rather than a status string written here
 * (golden rule 8) — the leave screen, the approval chain and this card have to agree.
 *
 * Nothing here is scoped to the viewer's reporting line: the card is gated on `leaves.view_any`,
 * which *is* the permission to see everybody's requests. A user without it never reaches this
 * class, because the registry filters cards before any query runs.
 */
final class LeaveApprovalsWidget extends Widget
{
    /** A request starting inside this many days is the next thing to decide after the overdue ones. */
    private const SOON_DAYS = 7;

    public function key(): string
    {
        return 'hr_leave_approvals';
    }

    public function title(): string
    {
        return 'Leave awaiting a decision';
    }

    public function icon(): string
    {
        return 'calendar';
    }

    public function permission(): ?string
    {
        return 'leaves.view_any';
    }

    public function module(): ?string
    {
        return 'leaves';
    }

    public function group(): string
    {
        return WidgetGroup::PEOPLE;
    }

    public function sort(): int
    {
        return 30;
    }

    public function href(): ?string
    {
        return $this->routeUrlWithQuery('admin.leaves.index', [
            'status' => LeaveRequestStatus::Pending->value,
        ]);
    }

    public function emptyMessage(): ?string
    {
        return 'Nothing is waiting for a decision.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        $today = Carbon::now(Format::timezone())->startOfDay();
        $todayDate = $today->toDateString();
        $soonDate = $today->copy()->addDays(self::SOON_DAYS)->toDateString();

        $open = array_values(array_map(
            static fn (LeaveRequestStatus $status): string => $status->value,
            array_filter(
                LeaveRequestStatus::cases(),
                static fn (LeaveRequestStatus $status): bool => $status->isOpen(),
            ),
        ));

        try {
            $row = LeaveRequest::query()
                ->toBase()
                ->whereIn('status', $open)
                ->selectRaw(
                    'COUNT(*) as waiting,'
                    .' SUM(CASE WHEN from_date <= ? THEN 1 ELSE 0 END) as already_started,'
                    .' SUM(CASE WHEN from_date > ? AND from_date <= ? THEN 1 ELSE 0 END) as starting_soon,'
                    .' SUM(CASE WHEN current_approval_level > 1 THEN 1 ELSE 0 END) as part_approved,'
                    .' MIN(applied_on) as oldest_applied',
                    [$todayDate, $todayDate, $soonDate],
                )
                ->first();
        } catch (Throwable) {
            return ['available' => false];
        }

        $oldestApplied = ($row->oldest_applied ?? null) === null
            ? null
            : Carbon::parse((string) $row->oldest_applied)->startOfDay();

        return [
            'available' => true,
            'waiting' => (int) ($row->waiting ?? 0),
            // The leave has begun and nobody has decided — the row that cannot wait.
            'already_started' => (int) ($row->already_started ?? 0),
            'starting_soon' => (int) ($row->starting_soon ?? 0),
            'soon_days' => self::SOON_DAYS,
            // Past its first approver and stuck at a later level: a different bottleneck, and
            // knowing which one it is saves chasing the wrong person.
            'part_approved' => (int) ($row->part_approved ?? 0),
            'oldest_applied_on' => $oldestApplied,
            // Whole days, computed here rather than in the view: a decimal day count and a
            // hand-rolled format are both the wrong tool for "how long has this sat?".
            'oldest_wait_days' => $oldestApplied === null
                ? null
                : max(0, (int) $oldestApplied->diffInDays($today)),
        ];
    }
}
