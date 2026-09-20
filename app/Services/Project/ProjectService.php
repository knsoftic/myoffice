<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\DataObjects\Project\ProjectData;
use App\Enums\ProgressBasis;
use App\Enums\ProjectMemberRole;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Enums\TimerStopReason;
use App\Events\Project\ProjectArchived;
use App\Events\Project\ProjectCreated;
use App\Events\Project\ProjectRestored;
use App\Events\Project\ProjectStatusChanged;
use App\Events\Project\ProjectTeamChanged;
use App\Events\Project\ProjectUpdated;
use App\Models\Project\Project;
use App\Models\Project\ProjectMember;
use App\Models\Project\Task;
use App\Models\Project\TimeEntry;
use App\Models\User;
use App\Services\Project\Exceptions\InvalidStatusTransition;
use App\Services\Project\Exceptions\ProjectRuleException;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Registering and running a project (phase-06 §6.1).
 *
 * Every write runs in one `DB::transaction()` and takes locks in the fixed order **project -> milestone ->
 * task -> time entry -> segment**, so no two code paths in this phase can deadlock against each other.
 * Events go out through `DB::afterCommit()` — every event class here implements
 * `ShouldDispatchAfterCommit`, so a listener never sees a project that a later rollback erased.
 *
 * **What this service deliberately cannot do.** `update()` refuses the five value and commission columns
 * (INV-P1), the attribution snapshot (INV-P13) and every progress column (INV-P8) — each has its own
 * service, and the model hooks enforce that whatever a caller passes. A project value therefore never
 * changes without a reason and an append-only revision row beside it.
 *
 * The one exception is on **create**: the opening `project_value` is written with the project and recorded
 * as revision 1 when it is non-zero, because a project that starts at 260,000 with no revision row would
 * have a first number nobody could explain.
 */
final readonly class ProjectService
{
    public function __construct(
        private ProjectNumberService $numbers,
        private ProjectProgressService $progress,
        private ProjectValueService $values,
    ) {}

    /**
     * Register a project. The code is reserved inside this transaction, so two concurrent creates cannot
     * take the same one (§6.1, D27).
     */
    public function create(ProjectData $data, ?User $actor = null): Project
    {
        return DB::transaction(function () use ($data, $actor): Project {
            $attributes = $data->attributes();

            $attributes['code'] = $this->numbers->next();
            $attributes['progress_basis'] ??= (string) setting(
                'projects.default_progress_basis',
                ProgressBasis::Milestones->value
            );
            $attributes['currency'] ??= (string) setting('localization.currency', 'PKR');

            $openingValue = $data->projectValue;
            unset($attributes['project_value']);

            $project = new Project;
            $project->forceFill($attributes)->save();

            // The manager is a team member too — §6.1. Without this the team tab would contradict the
            // header on every new project.
            if ($project->project_manager_id !== null) {
                $this->syncManagerMembership($project, null, $actor);
            }

            if ($openingValue !== null && Money::compare($openingValue, '0') !== 0) {
                $this->values->setOpeningValue($project, $openingValue, $actor);
            }

            $this->progress->recalculateProject($project->refresh());

            ProjectCreated::dispatch($project, $actor?->getKey());

            return $project->refresh();
        });
    }

    /**
     * Edit a project's ordinary details. Value, commission, attribution and progress are not ordinary
     * details and are refused here by the model hooks whatever is passed.
     */
    public function update(Project $project, ProjectData $data, ?User $actor = null): Project
    {
        // `project_value` and friends are not silently dropped: a caller that passed one gets told which
        // service owns it (INV-P1, INV-P8, INV-P13).
        $owned = array_diff($data->serviceOwned, ['status']);

        if ($owned !== []) {
            $column = (string) reset($owned);

            throw ProjectRuleException::refuse($column, sprintf(
                '%s is written by %s, not by editing the project.',
                $column,
                ProjectData::SERVICE_OWNED[$column]
            ));
        }

        return DB::transaction(function () use ($project, $data, $actor): Project {
            $this->lock($project);

            $previousManager = $project->project_manager_id;
            $previousBasis = $project->progress_basis;

            $project->forceFill($data->attributes(onlyProvided: true))->save();

            if ($project->project_manager_id !== $previousManager) {
                $this->syncManagerMembership($project, $previousManager, $actor);
            }

            if ($project->progress_basis !== $previousBasis) {
                $this->progress->forget($project);
                $this->progress->recalculateProject($project);
            }

            ProjectUpdated::dispatch($project, $actor?->getKey());

            return $project->refresh();
        });
    }

    /**
     * Move a project between the eight statuses of §20, by the §2.13.1 table only.
     *
     * Three side effects the contract asks for, all inside the one transaction: `completed` stamps
     * `completed_on` and forces progress to 100; `completed`, `cancelled` and `on_hold` stop every running
     * timer on the project's tasks, because hours logged against work that has stopped are not hours
     * anybody worked; and every move writes its reason where §2.13.1 marks one mandatory.
     */
    public function changeStatus(
        Project $project,
        ProjectStatus $to,
        ?string $reason = null,
        ?User $actor = null,
    ): Project {
        return DB::transaction(function () use ($project, $to, $reason, $actor): Project {
            $this->lock($project);

            $from = $project->status;

            if ($from === $to) {
                return $project;
            }

            if (! $from->canTransitionTo($to)) {
                throw InvalidStatusTransition::between($from, $to, $from->allowedTransitions());
            }

            if ($from->requiresReasonFor($to) && trim((string) $reason) === '') {
                throw ProjectRuleException::reasonRequired('reason', match (true) {
                    $to === ProjectStatus::OnHold => 'Say why the project is being put on hold.',
                    $to === ProjectStatus::Cancelled => 'Say why the project is being cancelled.',
                    default => 'Say why the project is being reopened.',
                });
            }

            $attributes = ['status' => $to->value];

            if ($to === ProjectStatus::InProgress && $project->start_date === null) {
                $attributes['start_date'] = now()->toDateString();
            }

            if ($to === ProjectStatus::Completed) {
                $attributes['completed_on'] = now()->toDateString();
            }

            $project->forceFill($attributes)->save();

            if (! $to->allowsTimeLogging() || $to === ProjectStatus::OnHold) {
                $this->stopRunningTimers($project, $actor);
            }

            $this->progress->forget($project);
            $this->progress->recalculateProject($project->refresh());

            ProjectStatusChanged::dispatch($project, $from, $to, $reason, $actor?->getKey());

            return $project->refresh();
        });
    }

    /**
     * Put somebody on the team. At most one active membership per person is the database's decision
     * (`uq_pm_*`, INV-P11) — a 1062 here means a double-submitted form, not a lost race.
     */
    public function addMember(
        Project $project,
        ?User $user,
        ?int $collaboratorId,
        ProjectMemberRole $role,
        ?string $notes = null,
        ?User $actor = null,
    ): ProjectMember {
        if (($user === null) === ($collaboratorId === null)) {
            throw ProjectRuleException::refuse('user_id', 'Choose either a staff member or a collaborator.');
        }

        if ($collaboratorId !== null && ! $role->canBeCollaborator()) {
            throw ProjectRuleException::collaboratorCannotManage();
        }

        return DB::transaction(function () use ($project, $user, $collaboratorId, $role, $notes, $actor): ProjectMember {
            $this->lock($project);

            try {
                $member = new ProjectMember;
                $member->forceFill([
                    'project_id' => $project->getKey(),
                    'user_id' => $user?->getKey(),
                    'collaborator_id' => $collaboratorId,
                    'role' => $role->value,
                    'notes' => $notes,
                ])->save();
            } catch (UniqueConstraintViolationException) {
                throw ProjectRuleException::alreadyOnTheTeam();
            }

            ProjectTeamChanged::dispatch($project, $actor?->getKey());

            return $member;
        });
    }

    /**
     * Change a member's role. The last manager cannot be demoted while the project header still points at
     * them — the project would be left with nobody accountable and a header that lies.
     */
    public function updateMemberRole(ProjectMember $member, ProjectMemberRole $role, ?User $actor = null): ProjectMember
    {
        return DB::transaction(function () use ($member, $role, $actor): ProjectMember {
            $project = $member->project;
            $this->lock($project);

            if ($member->collaborator_id !== null && ! $role->canBeCollaborator()) {
                throw ProjectRuleException::collaboratorCannotManage();
            }

            $isManager = $member->role === ProjectMemberRole::Manager;
            $pointsAtThem = $member->user_id !== null && $project->project_manager_id === $member->user_id;

            if ($isManager && $pointsAtThem && $role !== ProjectMemberRole::Manager) {
                throw ProjectRuleException::lastManager();
            }

            $member->forceFill(['role' => $role->value])->save();

            ProjectTeamChanged::dispatch($project, $actor?->getKey());

            return $member->refresh();
        });
    }

    /**
     * Take somebody off the team — a soft delete, so March's team stays answerable (INV-P11).
     *
     * Refused while they have a running timer on the project. Their open tasks are **returned to the
     * caller** for re-assignment rather than silently unassigned: a task with nobody on it and no warning
     * is how work disappears.
     *
     * @return Collection<int, Task> the open tasks that still point at the removed member
     */
    public function removeMember(ProjectMember $member, ?User $actor = null): Collection
    {
        return DB::transaction(function () use ($member, $actor): Collection {
            $project = $member->project;
            $this->lock($project);

            $running = TimeEntry::query()
                ->where('project_id', $project->getKey())
                ->when($member->user_id !== null, fn ($query) => $query->where('user_id', $member->user_id))
                ->when($member->collaborator_id !== null, fn ($query) => $query->where('collaborator_id', $member->collaborator_id))
                ->whereIn('status', ['running', 'paused'])
                ->exists();

            if ($running) {
                throw ProjectRuleException::memberHasRunningTimer();
            }

            $orphaned = Task::query()
                ->where('project_id', $project->getKey())
                ->whereNotIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value])
                ->when($member->user_id !== null, fn ($query) => $query->where('assigned_user_id', $member->user_id))
                ->when($member->collaborator_id !== null, fn ($query) => $query->where('assigned_collaborator_id', $member->collaborator_id))
                ->get();

            $member->delete();

            ProjectTeamChanged::dispatch($project, $actor?->getKey());

            return $orphaned;
        });
    }

    /**
     * Archive a project. The soft delete cascades nothing: its tasks, time entries and value revisions all
     * stay exactly where they are, because the work really happened.
     */
    public function archive(Project $project, ?User $actor = null): Project
    {
        return DB::transaction(function () use ($project, $actor): Project {
            $this->lock($project);
            $this->stopRunningTimers($project, $actor);

            $project->delete();

            ProjectArchived::dispatch($project, $actor?->getKey());

            return $project;
        });
    }

    public function restore(Project $project, ?User $actor = null): Project
    {
        return DB::transaction(function () use ($project, $actor): Project {
            $project->restore();

            $this->progress->forget($project);
            $this->progress->recalculateProject($project->refresh());

            ProjectRestored::dispatch($project, $actor?->getKey());

            return $project->refresh();
        });
    }

    /**
     * Keep the `manager` membership in step with `projects.project_manager_id` (§6.1).
     *
     * The old manager keeps their seat on the team, demoted to `lead`: they were on the project, and
     * removing them would rewrite history to say they never were.
     */
    private function syncManagerMembership(Project $project, ?int $previousManagerId, ?User $actor): void
    {
        if ($previousManagerId !== null) {
            ProjectMember::query()
                ->where('project_id', $project->getKey())
                ->where('user_id', $previousManagerId)
                ->where('role', ProjectMemberRole::Manager->value)
                ->get()
                ->each(fn (ProjectMember $member) => $member->forceFill([
                    'role' => ProjectMemberRole::Lead->value,
                ])->save());
        }

        if ($project->project_manager_id === null) {
            return;
        }

        $existing = ProjectMember::query()
            ->where('project_id', $project->getKey())
            ->where('user_id', $project->project_manager_id)
            ->first();

        if ($existing !== null) {
            $existing->forceFill(['role' => ProjectMemberRole::Manager->value])->save();

            return;
        }

        $member = new ProjectMember;
        $member->forceFill([
            'project_id' => $project->getKey(),
            'user_id' => $project->project_manager_id,
            'role' => ProjectMemberRole::Manager->value,
        ])->save();

        ProjectTeamChanged::dispatch($project, $actor?->getKey());
    }

    /**
     * Close every live timer on the project's work — used when the project stops (§2.13.1).
     */
    private function stopRunningTimers(Project $project, ?User $actor): void
    {
        $timer = app(TimerService::class);

        TimeEntry::query()
            ->where('project_id', $project->getKey())
            ->whereIn('status', ['running', 'paused'])
            ->get()
            ->each(fn (TimeEntry $entry) => $timer->stop($entry, TimerStopReason::Stop, $actor));
    }

    private function lock(Project $project): void
    {
        DB::table('projects')->where('id', $project->getKey())->lockForUpdate()->value('id');
    }
}
