<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Enums\TaskStatus;
use App\Enums\TimeEntrySource;
use App\Enums\TimeEntryStatus;
use App\Enums\TimerStopReason;
use App\Events\Project\TimeEntryRecorded;
use App\Events\Project\TimerAutoStopped;
use App\Events\Project\TimerPaused;
use App\Events\Project\TimerResumed;
use App\Events\Project\TimerStarted;
use App\Events\Project\TimerStopped;
use App\Models\Project\Project;
use App\Models\Project\Task;
use App\Models\Project\TimeEntry;
use App\Models\Project\TimeEntrySegment;
use App\Models\User;
use App\Services\Project\Exceptions\ProjectRuleException;
use App\Services\Project\Exceptions\TimerAlreadyRunningException;
use App\Support\Device;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The start / pause / stop timer (phase-06 §6.4).
 *
 * **Nothing here ever writes a running total.** A timer run is one `time_entries` row plus N append-only
 * `time_entry_segments` rows — one per start, closed on pause or stop (INV-P5). An open segment's
 * generated `duration_seconds` is **zero**, so no stored number ticks, and the live figure on screen is
 * `duration_seconds + (now − open segment start)` computed in the browser from a server timestamp. That is
 * why a crashed tab, a lost request or a double-clicked Stop cannot inflate anything.
 *
 * **One running timer per worker is the database's decision, not this service's** (INV-P4). `start()` does
 * not look before it inserts: it INSERTs and lets `uq_te_running` reject the second one, then re-reads the
 * live entry and throws {@see TimerAlreadyRunningException} naming the work it is on. A
 * SELECT-then-INSERT would race between its own two statements and leave two clocks running, which nobody
 * would notice until the hours were read back.
 *
 * **Pause holds the slot.** A paused entry has no open segment but is still live, so a worker may hold
 * several paused entries and exactly one running one — §23 asks only that one timer *runs*.
 *
 * The worker identity in both guards is `u:{user_id}` or `c:{collaborator_id}`, so a collaborator is bound
 * by the same single-timer rule as an employee.
 */
final readonly class TimerService
{
    public function __construct(private TimeRollupService $rollups) {}

    /**
     * Start the clock on a project, optionally on one of its tasks.
     */
    public function start(
        ?User $user,
        ?int $collaboratorId,
        Project $project,
        ?Task $task = null,
        ?string $description = null,
    ): TimeEntry {
        $this->assertOneWorker($user, $collaboratorId);
        $this->assertOpenForTime($project, $task);

        if ($task === null && ! (bool) setting('projects.allow_time_without_task', true)) {
            throw ProjectRuleException::timeNeedsATask();
        }

        return DB::transaction(function () use ($user, $collaboratorId, $project, $task, $description): TimeEntry {
            $startedAt = now()->startOfSecond();

            try {
                $entry = new TimeEntry;
                $entry->forceFill([
                    'project_id' => $project->getKey(),
                    'task_id' => $task?->getKey(),
                    'user_id' => $user?->getKey(),
                    'collaborator_id' => $collaboratorId,
                    'recorded_by' => $user?->getKey(),
                    'source' => TimeEntrySource::Timer->value,
                    'status' => TimeEntryStatus::Running->value,
                    'started_at' => $startedAt,
                    'work_date' => $startedAt->toDateString(),
                    'description' => $description,
                ])->save();

                $this->openSegment($entry, $startedAt);
            } catch (UniqueConstraintViolationException) {
                $live = $this->current($user, $collaboratorId);

                throw TimerAlreadyRunningException::on(
                    $live?->getKey(),
                    $live?->task?->title ?? $live?->project?->name,
                );
            }

            // §2.13.3: starting a timer on a task that is still `todo` moves it into progress — the board
            // should never show somebody working on a card that says nobody has started.
            if ($task !== null && $task->status === TaskStatus::Todo) {
                app(TaskService::class)->changeStatus($task, TaskStatus::InProgress, null, $user);
            }

            TimerStarted::dispatch($entry, $user?->getKey());

            return $entry->refresh();
        });
    }

    /**
     * Close the open segment and leave the entry live. The worker keeps their single-timer slot.
     */
    public function pause(TimeEntry $entry, ?User $actor = null): TimeEntry
    {
        return DB::transaction(function () use ($entry, $actor): TimeEntry {
            $this->lock($entry);

            if ($entry->status !== TimeEntryStatus::Running) {
                throw ProjectRuleException::refuse('timer', 'That timer is not running.');
            }

            $this->closeOpenSegment($entry, TimerStopReason::Pause);

            $entry->forceFill(['status' => TimeEntryStatus::Paused->value])->save();
            $this->syncDuration($entry);

            TimerPaused::dispatch($entry, $actor?->getKey());

            return $entry->refresh();
        });
    }

    /**
     * Open a new segment. Never reopens the closed one — the clock record is append-only.
     */
    public function resume(TimeEntry $entry, ?User $actor = null): TimeEntry
    {
        return DB::transaction(function () use ($entry, $actor): TimeEntry {
            $this->lock($entry);

            if ($entry->status !== TimeEntryStatus::Paused) {
                throw ProjectRuleException::refuse('timer', 'That timer is not paused.');
            }

            $this->assertOpenForTime($entry->project, $entry->task);

            try {
                $entry->forceFill(['status' => TimeEntryStatus::Running->value])->save();
                $this->openSegment($entry, now()->startOfSecond());
            } catch (UniqueConstraintViolationException) {
                $live = $this->current($entry->worker, $entry->collaborator_id);

                throw TimerAlreadyRunningException::on(
                    $live?->getKey(),
                    $live?->task?->title ?? $live?->project?->name,
                );
            }

            TimerResumed::dispatch($entry, $actor?->getKey());

            return $entry->refresh();
        });
    }

    /**
     * Stop the clock: close the open segment if there is one, stamp `ended_at`, then recompute every
     * cache by SUM — the entry, its task and its project — inside this one transaction.
     */
    public function stop(TimeEntry $entry, TimerStopReason $reason = TimerStopReason::Stop, ?User $actor = null): TimeEntry
    {
        return DB::transaction(function () use ($entry, $reason, $actor): TimeEntry {
            $this->lock($entry);

            if ($entry->status === TimeEntryStatus::Stopped) {
                return $entry;
            }

            $lastEnd = $this->closeOpenSegment($entry, $reason);

            $entry->forceFill([
                'status' => TimeEntryStatus::Stopped->value,
                'ended_at' => $lastEnd ?? $entry->segments()->max('ended_at') ?? now(),
            ])->save();

            $this->syncDuration($entry);
            $this->rollups->syncFor($entry->project, $entry->task);

            TimerStopped::dispatch($entry, $reason, $actor?->getKey());
            TimeEntryRecorded::dispatch($entry, $actor?->getKey());

            return $entry->refresh();
        });
    }

    /**
     * Move the clock to other work atomically.
     *
     * The only safe way to do it: a separate stop and start would leave a window in which the guard is
     * free, and two tabs racing through that window would end up with two timers.
     */
    public function switchTo(
        ?User $user,
        ?int $collaboratorId,
        Project $project,
        ?Task $task = null,
        ?string $description = null,
    ): TimeEntry {
        return DB::transaction(function () use ($user, $collaboratorId, $project, $task, $description): TimeEntry {
            $live = $this->current($user, $collaboratorId);

            if ($live !== null) {
                $this->stop($live, TimerStopReason::Switched, $user);
            }

            return $this->start($user, $collaboratorId, $project, $task, $description);
        });
    }

    /**
     * The worker's live entry — running or paused — with its open segment, for the topbar widget.
     */
    public function current(?User $user, ?int $collaboratorId = null): ?TimeEntry
    {
        return TimeEntry::query()
            ->with(['project:id,name,code', 'task:id,title', 'segments'])
            ->when($user !== null, fn ($query) => $query->where('user_id', $user->getKey()))
            ->when($user === null, fn ($query) => $query->where('collaborator_id', $collaboratorId))
            ->whereIn('status', [TimeEntryStatus::Running->value, TimeEntryStatus::Paused->value])
            ->orderByDesc('started_at')
            ->first();
    }

    /**
     * Every live entry for a worker — one running, any number paused (§6.4).
     *
     * @return Collection<int, TimeEntry>
     */
    public function live(?User $user, ?int $collaboratorId = null): Collection
    {
        return TimeEntry::query()
            ->with(['project:id,name,code', 'task:id,title'])
            ->when($user !== null, fn ($query) => $query->where('user_id', $user->getKey()))
            ->when($user === null, fn ($query) => $query->where('collaborator_id', $collaboratorId))
            ->whereIn('status', [TimeEntryStatus::Running->value, TimeEntryStatus::Paused->value])
            ->orderByDesc('started_at')
            ->get();
    }

    /**
     * The scheduled sweep: close any timer that has been running longer than the limit (§6.4).
     *
     * Bounded and idempotent — it only ever touches entries that are still `running`, so running it twice
     * in a minute stops nothing twice. Every owner is notified: a timer that vanished without a word
     * reads as lost work.
     */
    public function autoStopStale(int $maxHours, int $limit = 200): int
    {
        $cutoff = now()->subHours(max($maxHours, 1));

        $stale = TimeEntry::query()
            ->where('status', TimeEntryStatus::Running->value)
            ->whereHas('segments', fn ($query) => $query->whereNull('ended_at')->where('started_at', '<', $cutoff))
            ->limit($limit)
            ->get();

        foreach ($stale as $entry) {
            $this->stop($entry, TimerStopReason::AutoStop);

            TimerAutoStopped::dispatch($entry->refresh(), $maxHours);
        }

        return $stale->count();
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function openSegment(TimeEntry $entry, Carbon $at): TimeEntrySegment
    {
        $segment = new TimeEntrySegment;
        $segment->forceFill([
            'time_entry_id' => $entry->getKey(),
            'user_id' => $entry->user_id,
            'collaborator_id' => $entry->collaborator_id,
            'started_at' => $at,
            'ip_address' => $this->ip(),
            'device' => $this->device(),
        ])->save();

        return $segment;
    }

    /**
     * Close the one open segment, if there is one. Returns when it closed.
     */
    private function closeOpenSegment(TimeEntry $entry, TimerStopReason $reason): ?Carbon
    {
        $open = $entry->segments()->whereNull('ended_at')->orderByDesc('started_at')->first();

        if ($open === null) {
            return null;
        }

        // Compare at the precision the column actually stores. `started_at` comes back from a DATETIME
        // with whole seconds while `now()` carries microseconds, so a start and a stop inside the same
        // second look ordered in PHP and identical in the database — which is exactly what
        // chk_tes_window refuses.
        $endedAt = now()->startOfSecond();
        $startedAt = $open->started_at->copy()->startOfSecond();

        if ($endedAt->lessThanOrEqualTo($startedAt)) {
            $endedAt = $startedAt->copy()->addSecond();
        }

        $open->forceFill(['ended_at' => $endedAt, 'end_reason' => $reason->value])->save();

        return $endedAt;
    }

    /**
     * Recompute the entry's own cache from its segments — never increment (INV-P6).
     */
    private function syncDuration(TimeEntry $entry): void
    {
        DB::table('time_entries')->where('id', $entry->getKey())->update([
            'duration_seconds' => DB::table('time_entry_segments')
                ->where('time_entry_id', $entry->getKey())
                ->sum('duration_seconds'),
        ]);

        $entry->refresh();
    }

    private function assertOneWorker(?User $user, ?int $collaboratorId): void
    {
        if (($user === null) === ($collaboratorId === null)) {
            throw ProjectRuleException::refuse('worker', 'A timer belongs to exactly one person.');
        }
    }

    private function assertOpenForTime(?Project $project, ?Task $task): void
    {
        if ($project === null || ! $project->status->allowsTimeLogging()) {
            throw ProjectRuleException::projectClosedToTime();
        }

        if ($task !== null && $task->status->isTerminal()) {
            throw ProjectRuleException::refuse('task_id', 'That task is finished, so no more time can be logged against it.');
        }
    }

    private function lock(TimeEntry $entry): void
    {
        DB::table('time_entries')->where('id', $entry->getKey())->lockForUpdate()->value('id');
        $entry->refresh();
    }

    private function ip(): ?string
    {
        return app()->bound(Request::class) ? app(Request::class)->ip() : null;
    }

    private function device(): ?string
    {
        if (! app()->bound(Request::class)) {
            return null;
        }

        $agent = (string) app(Request::class)->userAgent();

        return $agent === '' ? null : substr(Device::device($agent), 0, 64);
    }
}
