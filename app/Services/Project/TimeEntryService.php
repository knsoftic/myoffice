<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Enums\TimeEntrySource;
use App\Enums\TimeEntryStatus;
use App\Enums\TimerStopReason;
use App\Events\Project\TimeEntryDiscarded;
use App\Events\Project\TimeEntryRecorded;
use App\Events\Project\TimeEntryUpdated;
use App\Models\Project\Project;
use App\Models\Project\Task;
use App\Models\Project\TimeEntry;
use App\Models\Project\TimeEntrySegment;
use App\Models\User;
use App\Services\Project\Exceptions\ProjectRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Time entered by hand, and the correction of any entry (phase-06 §6.1).
 *
 * A manual entry is created already `stopped`, with **one closed segment**, so every figure in the system
 * still comes from `time_entry_segments` (INV-P5) and there is exactly one way to sum hours — no branch
 * anywhere asks "was this a timer or was it typed?".
 *
 * **A timer entry is never edited.** It is evidence of when work happened: correcting it is a discard with
 * a reason plus a fresh manual entry, which is the same "void and re-enter" discipline the finance spine
 * applies to a mis-keyed receipt. `update()` refuses a timer entry outright.
 *
 * Two limits the business sets rather than the database: entries dated further back than
 * `projects.manual_time_backdate_limit_days` need a written reason, and a worker's live entries for one
 * `work_date` may not pass `projects.manual_time_max_hours_per_day`. The day cap is checked **under the
 * worker's row lock for that date**, so two tabs cannot each squeeze in the last hour.
 */
final readonly class TimeEntryService
{
    public function __construct(
        private TimerService $timers,
        private TimeRollupService $rollups,
    ) {}

    /**
     * Key in time that was already worked.
     */
    public function createManual(
        ?User $user,
        ?int $collaboratorId,
        Project $project,
        ?Task $task,
        string $startedAt,
        string $endedAt,
        ?string $description = null,
        ?string $manualReason = null,
        ?User $actor = null,
    ): TimeEntry {
        if (($user === null) === ($collaboratorId === null)) {
            throw ProjectRuleException::refuse('worker', 'A time entry belongs to exactly one person.');
        }

        if (! $project->status->allowsTimeLogging()) {
            throw ProjectRuleException::projectClosedToTime();
        }

        if ($task === null && ! (bool) setting('projects.allow_time_without_task', true)) {
            throw ProjectRuleException::timeNeedsATask();
        }

        $start = Carbon::parse($startedAt);
        $end = Carbon::parse($endedAt);

        $this->assertWindow($start, $end);
        $this->assertBackdating($start, $manualReason);

        return DB::transaction(function () use (
            $user, $collaboratorId, $project, $task, $start, $end, $description, $manualReason, $actor
        ): TimeEntry {
            $seconds = (int) $end->diffInSeconds($start, absolute: true);

            $this->assertDayCap($user, $collaboratorId, $start->toDateString(), $seconds);

            $entry = new TimeEntry;
            $entry->forceFill([
                'project_id' => $project->getKey(),
                'task_id' => $task?->getKey(),
                'user_id' => $user?->getKey(),
                'collaborator_id' => $collaboratorId,
                'recorded_by' => $actor?->getKey() ?? $user?->getKey(),
                'source' => TimeEntrySource::Manual->value,
                'status' => TimeEntryStatus::Stopped->value,
                'started_at' => $start,
                'ended_at' => $end,
                'work_date' => $start->toDateString(),
                'description' => $description,
                'manual_reason' => $manualReason,
            ])->save();

            // One closed segment, so the seconds come from the same place as a timer's (INV-P5).
            $segment = new TimeEntrySegment;
            $segment->forceFill([
                'time_entry_id' => $entry->getKey(),
                'user_id' => $entry->user_id,
                'collaborator_id' => $entry->collaborator_id,
                'started_at' => $start,
                'ended_at' => $end,
                'end_reason' => TimerStopReason::Stop->value,
            ])->save();

            $this->syncDuration($entry);
            $this->rollups->syncFor($project, $task);

            TimeEntryRecorded::dispatch($entry, $actor?->getKey());

            return $entry->refresh();
        });
    }

    /**
     * Correct a manual entry. A timer entry is refused — discard it instead.
     */
    public function update(
        TimeEntry $entry,
        string $startedAt,
        string $endedAt,
        ?string $description = null,
        ?string $manualReason = null,
        ?User $actor = null,
    ): TimeEntry {
        if ($entry->source !== TimeEntrySource::Manual) {
            throw ProjectRuleException::timerEntryNotEditable();
        }

        $start = Carbon::parse($startedAt);
        $end = Carbon::parse($endedAt);

        $this->assertWindow($start, $end);
        $this->assertBackdating($start, $manualReason);

        return DB::transaction(function () use ($entry, $start, $end, $description, $manualReason, $actor): TimeEntry {
            DB::table('time_entries')->where('id', $entry->getKey())->lockForUpdate()->value('id');
            $entry->refresh();

            $seconds = (int) $end->diffInSeconds($start, absolute: true);

            $this->assertDayCap(
                $entry->worker,
                $entry->collaborator_id,
                $start->toDateString(),
                $seconds,
                ignoreEntryId: (int) $entry->getKey(),
            );

            $entry->forceFill([
                'started_at' => $start,
                'ended_at' => $end,
                'work_date' => $start->toDateString(),
                'description' => $description,
                'manual_reason' => $manualReason,
            ])->save();

            // The segment is append-only and already closed, so a correction replaces it: the old row is
            // removed with the entry's own force-delete path and a fresh closed segment is written.
            $entry->segments()->each(static function (TimeEntrySegment $segment): void {
                DB::table('time_entry_segments')->where('id', $segment->getKey())->delete();
            });

            $segment = new TimeEntrySegment;
            $segment->forceFill([
                'time_entry_id' => $entry->getKey(),
                'user_id' => $entry->user_id,
                'collaborator_id' => $entry->collaborator_id,
                'started_at' => $start,
                'ended_at' => $end,
                'end_reason' => TimerStopReason::Stop->value,
            ])->save();

            $this->syncDuration($entry);
            $this->rollups->syncFor($entry->project, $entry->task);

            TimeEntryUpdated::dispatch($entry, $actor?->getKey());

            return $entry->refresh();
        });
    }

    /**
     * Discard an entry with a reason. Stops it first if it is live, so the `uq_te_running` slot is never
     * held by a trashed row, then recomputes the caches so it leaves every rollup immediately.
     */
    public function discard(TimeEntry $entry, string $reason, ?User $actor = null): void
    {
        if (trim($reason) === '') {
            throw ProjectRuleException::reasonRequired('discard_reason', 'Say why this entry is being discarded.');
        }

        DB::transaction(function () use ($entry, $reason, $actor): void {
            if ($entry->status->isLive()) {
                $this->timers->stop($entry, TimerStopReason::Stop, $actor);
                $entry->refresh();
            }

            $project = $entry->project;
            $task = $entry->task;

            $entry->forceFill(['discard_reason' => $reason])->save();
            $entry->delete();

            $this->rollups->syncFor($project, $task);

            TimeEntryDiscarded::dispatch($entry, $reason, $actor?->getKey());
        });
    }

    private function assertWindow(Carbon $start, Carbon $end): void
    {
        if ($end->lessThanOrEqualTo($start) || $start->isFuture() || $end->isFuture()) {
            throw ProjectRuleException::timeWindowInvalid();
        }
    }

    private function assertBackdating(Carbon $start, ?string $manualReason): void
    {
        $limit = (int) setting('projects.manual_time_backdate_limit_days', 7);

        if ($start->lessThan(now()->subDays($limit)->startOfDay()) && trim((string) $manualReason) === '') {
            throw ProjectRuleException::backdatedReasonRequired($limit);
        }
    }

    /**
     * §5's `projects.manual_time_max_hours_per_day`, checked under the lock so two tabs cannot both fit.
     */
    private function assertDayCap(
        ?User $user,
        ?int $collaboratorId,
        string $workDate,
        int $addingSeconds,
        ?int $ignoreEntryId = null,
    ): void {
        $capHours = (int) setting('projects.manual_time_max_hours_per_day', 16);

        $existing = (int) TimeEntry::query()
            ->where('work_date', $workDate)
            ->when($user !== null, fn ($query) => $query->where('user_id', $user->getKey()))
            ->when($user === null, fn ($query) => $query->where('collaborator_id', $collaboratorId))
            ->when($ignoreEntryId !== null, fn ($query) => $query->whereKeyNot($ignoreEntryId))
            ->lockForUpdate()
            ->sum('duration_seconds');

        if ($existing + $addingSeconds > $capHours * 3600) {
            throw ProjectRuleException::dayCapExceeded($capHours);
        }
    }

    private function syncDuration(TimeEntry $entry): void
    {
        DB::table('time_entries')->where('id', $entry->getKey())->update([
            'duration_seconds' => DB::table('time_entry_segments')
                ->where('time_entry_id', $entry->getKey())
                ->sum('duration_seconds'),
        ]);

        $entry->refresh();
    }
}
