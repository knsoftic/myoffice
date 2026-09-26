<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Workspace;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\DeliveryMode;
use App\Models\Support\Meeting;
use App\Support\DateRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * The signed-in person's own diary: what is next, and how much of it is today.
 *
 * **Ownership here is not a column, so it is not scoped by one.** A meeting is the viewer's when
 * they organise it (`meetings.organizer_id`) *or* when they are in the room
 * (`meeting_participants.user_id`) — two different things the model already joins into one
 * question, `scopeInvolving()`, which §9.4 names as the scope for `meetings.view`. This card asks
 * that scope rather than re-deriving it, so an invitation somebody accepted cannot appear on their
 * calendar and be missing from their dashboard. The id comes from `Auth::id()`, never from a
 * request parameter (CLAUDE.md golden rule 10).
 *
 * **`Auth::id()` null means an empty diary, never everybody's.** A console render has no viewer,
 * and a card that fell back to "all meetings" would put the whole company's afternoon — titles,
 * clients, interview subjects — on a page labelled "my".
 *
 * **Nothing about another person is printed.** The next meeting is named by its own title and time;
 * the organiser, the other attendees and their responses are all deliberately absent. Rule 7 is
 * easy to break here by accident, because `participants_count` is sitting right there on the row.
 *
 * **Upcoming, not "in range".** A diary card filtered to the dashboard's period would hide
 * tomorrow's nine o'clock the moment somebody switched the range to "today".
 *
 * **Two queries, which is the allowance this card was given.** The counts are one pass of
 * conditional sums; the second fetches the single next row, because "three meetings today" is a
 * statistic and "Design review at 3:00 PM" is something somebody can act on. `scheduled_at` is a
 * DATETIME, so today's boundary is an end-of-day moment converted into the storage timezone — not
 * a `whereDate()`, which would wrap the column and throw away `idx_me_calendar`.
 */
final class MyMeetingsWidget extends Widget
{
    public function key(): string
    {
        return 'my_meetings';
    }

    public function title(): string
    {
        return 'My meetings';
    }

    public function icon(): string
    {
        return 'video-camera';
    }

    public function permission(): ?string
    {
        return 'meetings.view_any';
    }

    public function module(): ?string
    {
        return 'meetings';
    }

    public function group(): string
    {
        return WidgetGroup::OPERATIONS;
    }

    public function sort(): int
    {
        return 30;
    }

    public function href(): ?string
    {
        // `mine=1` is the list screen's own `involving()` filter, so the link lands on exactly the
        // rows this card counted.
        return $this->routeUrlWithQuery('admin.meetings.index', ['mine' => 1]);
    }

    public function emptyMessage(): ?string
    {
        return 'Nothing in your diary.';
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
            $now = Carbon::now();
            $today = DateRange::today();
            $endOfToday = $today->storageEnd()->toDateTimeString();

            $row = Meeting::query()
                ->involving((int) $viewer)
                ->upcoming($now)
                ->toBase()
                ->selectRaw(
                    'COUNT(*) as upcoming_total,'
                    .' SUM(CASE WHEN scheduled_at <= ? THEN 1 ELSE 0 END) as today_total',
                    [$endOfToday],
                )
                ->first();

            // The single next one. `toBase()` keeps it a plain row, so there is no relation to
            // lazy-load and `preventLazyLoading` has nothing to object to.
            $next = Meeting::query()
                ->involving((int) $viewer)
                ->upcoming($now)
                ->orderBy('scheduled_at')
                ->toBase()
                ->first(['id', 'title', 'scheduled_at', 'delivery_mode', 'location']);
        } catch (Throwable) {
            return ['available' => false];
        }

        $nextAt = ($next->scheduled_at ?? null) === null ? null : Carbon::parse($next->scheduled_at);

        return [
            'available' => true,
            'mine' => true,
            'upcoming' => (int) ($row->upcoming_total ?? 0),
            'today' => (int) ($row->today_total ?? 0),
            'next_title' => $next->title ?? null,
            'next_at' => $nextAt,
            'next_is_today' => $nextAt !== null && $nextAt->lessThanOrEqualTo($today->storageEnd()),
            'next_online' => ($next->delivery_mode ?? null) === DeliveryMode::Online->value,
            'next_location' => $next->location ?? null,
            'next_link' => ($next->id ?? null) === null
                ? null
                : $this->routeUrl('admin.meetings.show', (int) $next->id),
        ];
    }

    /**
     * What a render with no signed-in viewer reports: an empty diary, rather than everybody's.
     *
     * @return array<string, mixed>
     */
    private static function nobody(): array
    {
        return [
            'available' => true,
            'mine' => false,
            'upcoming' => 0,
            'today' => 0,
            'next_title' => null,
            'next_at' => null,
            'next_is_today' => false,
            'next_online' => false,
            'next_location' => null,
            'next_link' => null,
        ];
    }
}
