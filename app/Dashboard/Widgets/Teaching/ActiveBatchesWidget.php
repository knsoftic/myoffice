<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Teaching;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\BatchStatus;
use App\Models\Institute\Batch;
use App\Support\DateRange;
use App\Support\Format;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * What the institute is teaching right now, and what is about to stop (T57).
 *
 * **A state, not a period.** How many batches are running does not depend on the dashboard's date
 * range, so the range is deliberately ignored rather than quietly applied to `created_at` — which
 * would drop the number to zero the moment somebody chose "today".
 *
 * **`planned` and `enrolling` are counted apart because the difference is a promise.** A planned
 * batch is an intention; an enrolling one is a class the institute has said it will run, which is
 * why the move requires a teacher and a timetable. Rolled together, the number that tells a
 * coordinator where the seats are disappears.
 *
 * **Two figures beyond the brief, because they are the ones that need doing something about.**
 * *Finishing soon* is the certificate run, the feedback form and the next batch's intake, all of
 * which are late if they start on the last day. *Past its end date and still live* is a status
 * nobody moved on: the batch shows as running, its sessions keep generating, and the completion it
 * never received is missing from every report downstream.
 *
 * **Seats are the cache, and are labelled as such.** `batches.current_students` is written only by
 * `BatchService::recountStudents()` and is explicitly not what a decision is made on (INV-I7, D48).
 * A rough "seats left" on a dashboard is the legitimate use of it; the enrolment screen recounts
 * under a row lock. Capacity and occupancy are summed separately and subtracted in PHP — both
 * columns are `UNSIGNED SMALLINT`, and an over-filled batch would make the subtraction underflow in
 * SQL rather than simply read zero.
 *
 * **One query.** Eight aggregates over the same table in one pass, each a `SUM(CASE …)` naming its
 * status through `BatchStatus`. `end_date` is a `DATE` column and is compared to date strings —
 * `whereDate()` would wrap it in a function and lose `idx_ba_upcoming`.
 *
 * **No student reaches this card.** Only statuses, dates and two integer columns are read; the
 * roster is never joined.
 */
final class ActiveBatchesWidget extends Widget
{
    /** How far ahead "finishing soon" looks, in days. */
    private const HORIZON_DAYS = 30;

    public function key(): string
    {
        return 'teaching_active_batches';
    }

    public function title(): string
    {
        return 'Batches running';
    }

    public function icon(): string
    {
        return 'rectangle-stack';
    }

    public function permission(): ?string
    {
        return 'batches.view_any';
    }

    public function module(): ?string
    {
        return 'batches';
    }

    public function group(): string
    {
        return WidgetGroup::INSTITUTE;
    }

    public function sort(): int
    {
        return 40;
    }

    /**
     * The batch list narrowed to the running ones. `admin.batches.index` reads `status` and is
     * guarded by `batches.view_any` — this card's own permission.
     */
    public function href(): ?string
    {
        return $this->routeUrlWithQuery('admin.batches.index', [
            'status' => BatchStatus::Running->value,
        ]);
    }

    public function emptyMessage(): ?string
    {
        return 'Nothing is running yet.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            // One instant, two boundaries: read twice, a midnight crossing between them would put
            // the horizon a day away from the day it is measured from.
            $from = Carbon::now(Format::timezone())->startOfDay();
            $today = $from->toDateString();
            $horizon = $from->copy()->addDays(self::HORIZON_DAYS)->toDateString();

            $planned = BatchStatus::Planned->value;
            $enrolling = BatchStatus::Enrolling->value;
            $running = BatchStatus::Running->value;
            $onHold = BatchStatus::OnHold->value;

            $row = Batch::query()
                ->toBase()
                ->selectRaw(
                    'COUNT(*) as total,'
                    .' SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as running,'
                    .' SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as enrolling,'
                    .' SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as planned,'
                    .' SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as on_hold,'
                    // Live (the three statuses `Batch::scopeLive()` names) and ending inside the horizon.
                    .' SUM(CASE WHEN status IN (?, ?, ?) AND end_date IS NOT NULL'
                    .' AND end_date >= ? AND end_date <= ? THEN 1 ELSE 0 END) as finishing,'
                    // Live, and its own end date has already gone past: a status nobody moved on.
                    .' SUM(CASE WHEN status IN (?, ?, ?) AND end_date IS NOT NULL'
                    .' AND end_date < ? THEN 1 ELSE 0 END) as overrunning,'
                    // Summed apart, subtracted in PHP: UNSIGNED columns underflow, they do not clamp.
                    .' SUM(CASE WHEN status = ? THEN student_capacity ELSE 0 END) as open_capacity,'
                    .' SUM(CASE WHEN status = ? THEN current_students ELSE 0 END) as open_seated',
                    [
                        $running,
                        $enrolling,
                        $planned,
                        $onHold,
                        $planned, $enrolling, $running, $today, $horizon,
                        $planned, $enrolling, $running, $today,
                        $enrolling,
                        $enrolling,
                    ],
                )
                ->first();
        } catch (Throwable) {
            return ['available' => false];
        }

        $runningCount = (int) ($row->running ?? 0);
        $enrollingCount = (int) ($row->enrolling ?? 0);
        $plannedCount = (int) ($row->planned ?? 0);
        $onHoldCount = (int) ($row->on_hold ?? 0);

        return [
            'available' => true,
            'horizon_days' => self::HORIZON_DAYS,
            // Every batch ever, completed and cancelled included: it tells "nothing set up yet"
            // apart from "everything has finished", which are two different empty states.
            'total' => (int) ($row->total ?? 0),
            'live' => $runningCount + $enrollingCount + $plannedCount + $onHoldCount,
            'running' => $runningCount,
            'enrolling' => $enrollingCount,
            'planned' => $plannedCount,
            'on_hold' => $onHoldCount,
            'finishing' => (int) ($row->finishing ?? 0),
            'overrunning' => (int) ($row->overrunning ?? 0),
            // Approximate by construction — `current_students` is a cache (INV-I7).
            'seats_left' => max(0, (int) ($row->open_capacity ?? 0) - (int) ($row->open_seated ?? 0)),
        ];
    }
}
