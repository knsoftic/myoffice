<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Enums\MilestoneStatus;
use App\Events\Project\MilestoneDeleted;
use App\Events\Project\MilestoneSaved;
use App\Events\Project\MilestoneStatusChanged;
use App\Models\Project\Project;
use App\Models\Project\ProjectMilestone;
use App\Models\Project\Task;
use App\Models\User;
use App\Services\Project\Exceptions\InvalidStatusTransition;
use App\Services\Project\Exceptions\ProjectRuleException;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Milestones (phase-06 §6.1, requirement §21).
 *
 * A milestone's dates are checked against the project's window as a **warning, not a block** (§6.1): a
 * milestone that overruns the project deadline is a fact worth showing, and refusing to record it would
 * only push the truth off the system. {@see datesWarning()} returns that message for the screen.
 *
 * Deleting is refused outright while a `project_payment` references the milestone, and the message names
 * the payment — once Phase 10 exists, that row is the `milestone` commission base and money has been
 * booked against it. The soft delete keeps `project_payments.project_milestone_id` resolvable, and the
 * milestone's tasks are **detached** (`project_milestone_id = null`), never deleted: the work is still
 * real, it just no longer belongs to a milestone.
 */
final readonly class MilestoneService
{
    public function __construct(private ProjectProgressService $progress) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Project $project, array $attributes, ?User $actor = null): ProjectMilestone
    {
        return DB::transaction(function () use ($project, $attributes, $actor): ProjectMilestone {
            $this->lockProject($project);

            $this->assertWeight($attributes['weight'] ?? '1.0000');

            $milestone = new ProjectMilestone;
            $milestone->forceFill(array_merge($this->writable($attributes), [
                'project_id' => $project->getKey(),
                'status' => MilestoneStatus::Pending->value,
                'sort_order' => $attributes['sort_order']
                    ?? ((int) ProjectMilestone::query()->where('project_id', $project->getKey())->max('sort_order') + 1),
            ]))->save();

            $this->progress->forget();
            $this->progress->recalculateMilestone($milestone);

            MilestoneSaved::dispatch($milestone, $actor?->getKey());

            return $milestone->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(ProjectMilestone $milestone, array $attributes, ?User $actor = null): ProjectMilestone
    {
        return DB::transaction(function () use ($milestone, $attributes, $actor): ProjectMilestone {
            $this->lockMilestone($milestone);

            if (array_key_exists('weight', $attributes)) {
                $this->assertWeight($attributes['weight']);
            }

            $previousWeight = (string) $milestone->weight;

            $milestone->forceFill($this->writable($attributes))->save();

            if (Money::compare($previousWeight, (string) $milestone->weight) !== 0) {
                $this->progress->forget();
                $this->progress->recalculateProject($milestone->project);
            }

            MilestoneSaved::dispatch($milestone, $actor?->getKey());

            return $milestone->refresh();
        });
    }

    /**
     * Reorder a project's milestones. Ids that do not belong to the project are rejected outright rather
     * than skipped — a partially applied order is worse than none.
     *
     * @param  list<int>  $idsInOrder
     */
    public function reorder(Project $project, array $idsInOrder, ?User $actor = null): void
    {
        DB::transaction(function () use ($project, $idsInOrder, $actor): void {
            $this->lockProject($project);

            $owned = ProjectMilestone::query()
                ->where('project_id', $project->getKey())
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $foreign = array_diff($idsInOrder, $owned);

            if ($foreign !== []) {
                throw ProjectRuleException::refuse('order', 'That list contains a milestone from another project.');
            }

            foreach (array_values($idsInOrder) as $index => $id) {
                DB::table('project_milestones')->where('id', $id)->update(['sort_order' => $index + 1]);
            }

            MilestoneSaved::dispatch($project->milestones()->first() ?? new ProjectMilestone, $actor?->getKey());
        });
    }

    public function changeStatus(
        ProjectMilestone $milestone,
        MilestoneStatus $to,
        ?string $reason = null,
        ?User $actor = null,
    ): ProjectMilestone {
        return DB::transaction(function () use ($milestone, $to, $reason, $actor): ProjectMilestone {
            $this->lockMilestone($milestone);

            $from = $milestone->status;

            if ($from === $to) {
                return $milestone;
            }

            if (! $from->canTransitionTo($to)) {
                throw InvalidStatusTransition::between($from, $to, $from->allowedTransitions());
            }

            if ($from->requiresReasonFor($to) && trim((string) $reason) === '') {
                throw ProjectRuleException::reasonRequired('reason', match (true) {
                    $to === MilestoneStatus::OnHold => 'Say why the milestone is being put on hold.',
                    $to === MilestoneStatus::Cancelled => 'Say why the milestone is being cancelled.',
                    default => 'Say why the milestone is being reopened.',
                });
            }

            $attributes = ['status' => $to->value];

            if ($to === MilestoneStatus::Completed) {
                $attributes['completed_on'] = now()->toDateString();
            } elseif ($from === MilestoneStatus::Completed) {
                $attributes['completed_on'] = null;
            }

            $milestone->forceFill($attributes)->save();

            $this->progress->forget();
            $this->progress->recalculateMilestone($milestone->refresh());

            MilestoneStatusChanged::dispatch($milestone, $from, $to, $reason, $actor?->getKey());

            return $milestone->refresh();
        });
    }

    /**
     * Soft delete, with the tasks detached rather than removed.
     */
    public function delete(ProjectMilestone $milestone, ?User $actor = null): void
    {
        DB::transaction(function () use ($milestone, $actor): void {
            $this->lockMilestone($milestone);

            $blocking = $this->blockingPayment($milestone);

            if ($blocking !== null) {
                throw ProjectRuleException::milestoneHasPayment($blocking);
            }

            $project = $milestone->project;

            Task::query()
                ->where('project_milestone_id', $milestone->getKey())
                ->update(['project_milestone_id' => null]);

            $milestone->delete();

            $this->progress->forget();
            $this->progress->recalculateProject($project);

            MilestoneDeleted::dispatch($milestone, $actor?->getKey());
        });
    }

    /**
     * The §6.1 warning: a milestone whose deadline falls outside the project's window is recorded, and the
     * screen says so.
     */
    public function datesWarning(Project $project, ?string $startDate, ?string $deadline): ?string
    {
        if ($deadline === null || $project->deadline === null) {
            return null;
        }

        if ($deadline > $project->deadline->toDateString()) {
            return 'This milestone is due after the project deadline.';
        }

        if ($startDate !== null && $project->start_date !== null && $startDate < $project->start_date->toDateString()) {
            return 'This milestone starts before the project does.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function writable(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip([
            'name', 'description', 'start_date', 'deadline', 'amount', 'weight', 'sort_order',
        ]));
    }

    private function assertWeight(mixed $weight): void
    {
        if (Money::compare((string) $weight, '0') <= 0) {
            throw ProjectRuleException::refuse('weight', 'A milestone weight has to be greater than zero.');
        }
    }

    /**
     * The payment reference blocking a delete, or null. Answers null while Phase 10 has not shipped the
     * table — an absent consumer is not a blocker.
     */
    private function blockingPayment(ProjectMilestone $milestone): ?string
    {
        if (! Schema::hasTable('project_payments')) {
            return null;
        }

        $row = DB::table('project_payments')
            ->where('project_milestone_id', $milestone->getKey())
            ->whereNull('deleted_at')
            ->first();

        if ($row === null) {
            return null;
        }

        return (string) ($row->payment_no ?? $row->reference ?? ('#'.$row->id));
    }

    private function lockProject(Project $project): void
    {
        DB::table('projects')->where('id', $project->getKey())->lockForUpdate()->value('id');
    }

    private function lockMilestone(ProjectMilestone $milestone): void
    {
        DB::table('project_milestones')->where('id', $milestone->getKey())->lockForUpdate()->value('id');
        $milestone->refresh();
    }
}
