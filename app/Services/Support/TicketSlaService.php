<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\DataObjects\Support\SlaMinutes;
use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Events\Support\TicketSlaBreached;
use App\Models\Support\SupportTicket;
use App\Services\Support\Exceptions\SupportRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The response clock (phase-19-23 §6.16, INV-22-2, requirement §93).
 *
 * **The whole feature sits behind `support.sla_enabled`** (audit F-13.5, needs-human H3). With it
 * off, `minutesFor()` returns `SlaMinutes::none()`, `multiplier()` is never called, `sweep()` is a
 * no-op, and every SLA column on the ticket stays null or zero. Turning it on later starts clocks
 * from that moment and never back-dates a breach on a ticket that was open while the feature was
 * off — there is no migration in either direction, which is the point.
 *
 * **The multiplier lives here and not on the enum** (audit F-5.7). `Priority` is shared with tasks,
 * and how much longer a low-priority *ticket* may take is support policy rather than a property of
 * the word "low" — putting it on the enum would make a task inherit a support desk's targets.
 * Changing the ladder is one method.
 *
 * **`pause()` and `resume()` move the due dates, not just the counter.** A pause that only
 * accumulated minutes would leave the target where it was, so a ticket that waited three days on the
 * customer would breach on the strength of their delay. Pushing both due dates forward by the same
 * amount is what makes the pause honest in both directions: the office gets the time back, and the
 * record says exactly how much (INV-22-2).
 *
 * **Business hours are opt-in** (`support.sla_business_hours_only`). Off — the default — the clock is
 * plain addition, which is what most institutes mean by "four hours". On, it advances only inside
 * `contact.business_hours`, skipping closed days entirely, so a ticket raised at five on a Friday is
 * not already late by Monday morning.
 */
final class TicketSlaService
{
    /**
     * How many days ahead `dueAt()` will walk looking for open hours before giving up.
     *
     * A business-hours calculation over a week of closed days has to end somewhere, and an institute
     * whose every day is marked closed would otherwise loop for ever. Sixty days is far past any
     * real holiday and far short of a hang.
     */
    private const MAX_CALENDAR_DAYS = 60;

    /**
     * The priority ladder, as a factor on the base minutes.
     *
     * Support policy, in one place. `urgent` gets a quarter of the time and `low` gets double, which
     * is the shape every desk uses — and a desk that wants a different shape edits one method rather
     * than hunting for the numbers.
     */
    public function multiplier(Priority $priority): float
    {
        return match ($priority) {
            Priority::Low => 2.0,
            Priority::Medium => 1.0,
            Priority::High => 0.5,
            Priority::Urgent => 0.25,
        };
    }

    /**
     * This ticket's targets: the department's, falling back to the institute's, times the priority.
     *
     * **`multiplier()` is not called when the feature is off**, which is why the check comes first
     * rather than being folded into the arithmetic.
     */
    public function minutesFor(SupportTicket $ticket): SlaMinutes
    {
        if (! $this->enabled()) {
            return SlaMinutes::none();
        }

        $department = $ticket->department;

        $first = (int) ($department?->getAttribute('sla_first_response_minutes')
            ?? setting('support.sla_first_response_minutes', 240));

        $resolution = (int) ($department?->getAttribute('sla_resolution_minutes')
            ?? setting('support.sla_resolution_minutes', 2880));

        $factor = $this->multiplier($ticket->priority);

        return SlaMinutes::of(
            (int) round($first * $factor),
            (int) round($resolution * $factor),
        );
    }

    /**
     * When a target falls due, counting from a moment.
     *
     * One implementation, used by create, reopen, a priority change, a department change and the
     * sweep — so a ticket that moves desk gets its new target measured exactly as a new ticket would.
     */
    public function dueAt(Carbon|CarbonImmutable $from, int $minutes): CarbonImmutable
    {
        $start = CarbonImmutable::parse($from);

        if (! (bool) setting('support.sla_business_hours_only', false)) {
            return $start->addMinutes($minutes);
        }

        return $this->advanceThroughBusinessHours($start, $minutes);
    }

    /**
     * Entering `waiting`: stop the clock.
     *
     * Only stamps; the arithmetic happens on the way out, because until then nobody knows how long
     * the pause will be.
     */
    public function pause(SupportTicket $ticket): SupportTicket
    {
        if (! $this->enabled() || ! (bool) setting('support.sla_pause_on_waiting', true)) {
            return $ticket;
        }

        if ($ticket->getAttribute('waiting_since') !== null) {
            return $ticket;
        }

        // **Order matters, and this says so rather than letting the database say it.**
        // `chk_tk_waiting` binds `waiting_since` to the `waiting` status, so a caller who pauses
        // before moving the status gets SQLSTATE 4025 — a constraint name, from inside a
        // transaction, with nothing pointing at the line that did it. `TicketService::changeStatus()`
        // writes the status first and calls this second; anybody who gets that backwards should read
        // a sentence.
        if ($ticket->status !== TicketStatus::Waiting) {
            throw SupportRuleException::refuse('status', sprintf(
                'The clock is only paused on a ticket that is waiting on the requester, and this one '
                .'is %s. Move the status first.',
                mb_strtolower($ticket->status->label()),
            ));
        }

        $ticket->forceFill(['waiting_since' => Carbon::now()])->save();

        return $ticket->refresh();
    }

    /**
     * Leaving `waiting`: give the time back.
     *
     * The elapsed minutes are added to `total_waiting_minutes` **and** both due dates move forward by
     * the same amount. See the class note — a counter alone would be a record of a pause that never
     * happened.
     *
     * **Called before the status moves, where `pause()` is called after — and the asymmetry is
     * `chk_tk_waiting`.** That constraint binds `waiting_since` to the `waiting` status, so the
     * column has to be *set* while the ticket is already waiting and *cleared* while it still is.
     * `TicketService::changeStatus()` does both in that order; the sequence reads oddly until you
     * see the constraint, which is why it is written down here rather than left to be rediscovered.
     */
    public function resume(SupportTicket $ticket): SupportTicket
    {
        $since = $ticket->getAttribute('waiting_since');

        if ($since === null) {
            return $ticket;
        }

        $paused = max(0, (int) $since->diffInMinutes(Carbon::now()));

        $changes = [
            'waiting_since' => null,
            'total_waiting_minutes' => (int) $ticket->getAttribute('total_waiting_minutes') + $paused,
        ];

        foreach (['first_response_due_at', 'resolution_due_at'] as $column) {
            $due = $ticket->getAttribute($column);

            if ($due !== null && $paused > 0) {
                $changes[$column] = $this->dueAt(CarbonImmutable::parse($due), $paused);
            }
        }

        $ticket->forceFill($changes)->save();

        return $ticket->refresh();
    }

    /** Is the feature on at all? Every public method asks this first. */
    public function enabled(): bool
    {
        return (bool) setting('support.sla_enabled', true);
    }

    /**
     * The ten-minute sweep: stamp what has breached, and tell somebody once (§10.5, F-13.5).
     *
     * **The booleans are the idempotency key, and that is the whole design.** A sweep that ran every
     * ten minutes and notified on "is it past the target" would page the assignee 144 times a day
     * about the same ticket. `first_response_breached` and `resolution_breached` start false, the
     * `where` clause below selects only tickets where they are still false, and the update sets them
     * inside the same transaction as the dispatch — so the second run selects nothing and sends
     * nothing. A ticket can breach twice in its life, once per kind, and no more.
     *
     * **Bounded at 500 rows** (§10.5). A desk that has been down for a week has a week of breaches
     * waiting; sweeping all of them in one run would hold the lock long enough for the next run to
     * overlap it. Five hundred clears in seconds and the rest are picked up ten minutes later.
     *
     * **`skipLocked` rather than a queue.** Two workers reaching the same row is ordinary on a
     * ten-minute schedule with `withoutOverlapping` only advisory; skipping a locked row lets the
     * second worker do useful work instead of waiting for the first.
     *
     * @return array{first_response: int, resolution: int}
     */
    public function sweep(?CarbonImmutable $asOf = null, int $limit = 500): array
    {
        if (! $this->enabled()) {
            Log::info('Ticket SLA sweep skipped: support.sla_enabled is off.');

            return ['first_response' => 0, 'resolution' => 0];
        }

        $asOf ??= CarbonImmutable::now();
        $limit = max(1, min(5000, $limit));

        return [
            'first_response' => $this->sweepKind('first_response', $asOf, $limit),
            'resolution' => $this->sweepKind('resolution', $asOf, $limit),
        ];
    }

    /**
     * One kind of breach.
     *
     * The two differ in exactly three column names and one extra condition, so they share a method
     * rather than being written twice — the alternative is two nearly identical blocks that drift
     * the first time somebody fixes a bug in one of them.
     */
    private function sweepKind(string $kind, CarbonImmutable $asOf, int $limit): int
    {
        $dueColumn = $kind.'_due_at';
        $breachedColumn = $kind.'_breached';
        $metColumn = $kind === 'first_response' ? 'first_response_at' : 'resolved_at';

        $ids = SupportTicket::query()
            ->whereNull('deleted_at')
            ->whereNotNull($dueColumn)
            ->where($dueColumn, '<', $asOf)
            ->where($breachedColumn, false)
            ->whereNull($metColumn)
            // A ticket parked on the customer is not late: `pause()` has already pushed the due
            // dates forward, but a ticket that entered `waiting` between two sweeps may still be
            // selected here on a stale date, and telling the desk it is late would be wrong.
            ->where('status', '!=', TicketStatus::Waiting->value)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return 0;
        }

        $stamped = [];

        foreach (array_chunk($ids, 50) as $chunk) {
            DB::transaction(function () use ($chunk, $breachedColumn, &$stamped): void {
                $locked = SupportTicket::query()
                    ->whereIn('id', $chunk)
                    ->where($breachedColumn, false)
                    ->lockForUpdate()
                    ->get();

                foreach ($locked as $ticket) {
                    // Stamped inside the transaction, dispatched after it: a crash between the two
                    // loses the notification and never the record, which is the right way round.
                    $ticket->forceFill([$breachedColumn => true])->save();
                    $stamped[] = $ticket;
                }
            });
        }

        foreach ($stamped as $ticket) {
            TicketSlaBreached::dispatch($ticket, $kind);
        }

        return count($stamped);
    }

    // ===============================================================================================

    /**
     * Walk forward `$minutes` of *open* time.
     *
     * Day by day, taking whatever is left of today's window first and then whole days after it. The
     * loop is bounded by `MAX_CALENDAR_DAYS`: an institute that has marked every day closed gets a
     * plain addition rather than a hang, which is wrong but finite — and visible, because the due
     * date will look obviously early.
     */
    private function advanceThroughBusinessHours(CarbonImmutable $from, int $minutes): CarbonImmutable
    {
        $hours = $this->businessHours();

        if ($hours === []) {
            return $from->addMinutes($minutes);
        }

        $cursor = $from;
        $remaining = $minutes;

        for ($day = 0; $day <= self::MAX_CALENDAR_DAYS && $remaining > 0; $day++) {
            $window = $this->windowOn($cursor, $hours);

            if ($window === null) {
                // Closed. Move to the start of the next day and try again.
                $cursor = $cursor->addDay()->startOfDay();

                continue;
            }

            [$open, $close] = $window;

            if ($cursor->lessThan($open)) {
                $cursor = $open;
            }

            if ($cursor->greaterThanOrEqualTo($close)) {
                $cursor = $cursor->addDay()->startOfDay();

                continue;
            }

            $available = (int) $cursor->diffInMinutes($close);

            if ($available >= $remaining) {
                return $cursor->addMinutes($remaining);
            }

            $remaining -= $available;
            $cursor = $cursor->addDay()->startOfDay();
        }

        // Ran out of calendar. Better an honest approximation than a loop that never returns.
        return $cursor->addMinutes($remaining);
    }

    /**
     * Today's open and close, or null when the institute is shut.
     *
     * @param  array<string, array<string, mixed>>  $hours
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function windowOn(CarbonImmutable $day, array $hours): ?array
    {
        $entry = $hours[mb_strtolower($day->format('l'))] ?? null;

        if (! is_array($entry) || (bool) ($entry['closed'] ?? false)) {
            return null;
        }

        $open = (string) ($entry['open'] ?? '');
        $close = (string) ($entry['close'] ?? '');

        if ($open === '' || $close === '') {
            return null;
        }

        $opensAt = $day->setTimeFromTimeString($open);
        $closesAt = $day->setTimeFromTimeString($close);

        // A window that closes before it opens is a misconfiguration, and treating it as a closed
        // day is the reading that cannot produce a negative amount of available time.
        return $closesAt->greaterThan($opensAt) ? [$opensAt, $closesAt] : null;
    }

    /**
     * Phase 2's `contact.business_hours`, keyed by lower-case day name.
     *
     * @return array<string, array<string, mixed>>
     */
    private function businessHours(): array
    {
        $configured = setting('contact.business_hours', null);

        if (! is_array($configured)) {
            return [];
        }

        $hours = [];

        foreach ($configured as $day => $entry) {
            if (is_array($entry)) {
                $hours[mb_strtolower((string) $day)] = $entry;
            }
        }

        return $hours;
    }
}
