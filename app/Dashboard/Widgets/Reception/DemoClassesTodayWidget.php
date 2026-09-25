<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Reception;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\DemoClassStatus;
use App\Models\Institute\DemoClass;
use App\Support\DateRange;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Today's demo classes, and the next one to walk through the door.
 *
 * **Today only, whatever the date range says.** A demo is an appointment: the front desk needs to
 * know who is expected in the next hour, and that question has exactly one useful answer no matter
 * which period the rest of the dashboard is showing. Applying the range here would let somebody
 * switch to "this month" and quietly lose the appointment happening at four o'clock.
 *
 * **`missed` is separated from `scheduled` because it is a different job.** A scheduled demo is
 * something to prepare for; a missed one is somebody to ring today while they still remember
 * booking it. Rolled into one "today" count, the second disappears behind the first.
 *
 * The times come back as strings from the database and are rendered as stored — a demo booked for
 * 16:00 is 16:00 on the institute's wall clock, and re-interpreting it through a timezone
 * conversion here would move every appointment by the server's offset.
 */
final class DemoClassesTodayWidget extends Widget
{
    public function key(): string
    {
        return 'reception_demos_today';
    }

    public function title(): string
    {
        return 'Demo classes today';
    }

    public function icon(): string
    {
        return 'presentation-chart-bar';
    }

    public function permission(): ?string
    {
        return 'demo_classes.view_any';
    }

    public function module(): ?string
    {
        return 'demo_classes';
    }

    public function group(): string
    {
        return WidgetGroup::FRONT_DESK;
    }

    public function sort(): int
    {
        return 30;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.demo-classes.index');
    }

    public function emptyMessage(): ?string
    {
        return 'Nothing booked for today.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $today = Carbon::now(config('app.timezone'))->toDateString();

            $counts = DemoClass::query()
                ->toBase()
                ->where('scheduled_on', $today)
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all();

            // The next one still expected. Ordered by start_time so "next" means next on the
            // clock, not next by insertion.
            $next = DemoClass::query()
                ->toBase()
                ->where('scheduled_on', $today)
                ->where('status', DemoClassStatus::Scheduled->value)
                ->orderBy('start_time')
                ->first(['attendee_name', 'start_time']);
        } catch (Throwable) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'total' => array_sum($counts),
            'scheduled' => (int) ($counts[DemoClassStatus::Scheduled->value] ?? 0),
            'attended' => (int) ($counts[DemoClassStatus::Attended->value] ?? 0),
            'missed' => (int) ($counts[DemoClassStatus::Missed->value] ?? 0),
            'converted' => (int) ($counts[DemoClassStatus::Converted->value] ?? 0),
            'next_name' => $next->attendee_name ?? null,
            'next_time' => $next->start_time ?? null,
        ];
    }
}
