<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Contracts\Referrals\ReferralRecorder;
use App\Events\Project\ProjectReferralChanged;
use App\Events\Project\ProjectReferralCleared;
use App\Events\Project\ProjectReferralLinked;
use App\Models\Project\Project;
use App\Models\User;
use App\Services\Project\Exceptions\ProjectRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who a project is attributed to (phase-06 §6.1, INV-P13).
 *
 * **The three columns on `projects` are a display snapshot, not the authority** (R5, D37, F-8.2). The
 * authority is an `active` `collaborator_referrals` row, which Phase 9/10 ships. Nothing here grants a
 * collaborator access to anything, and no scope reads `projects.collaborator_id` — a stale or hand-edited
 * value must never be able to hand one partner's project, client name or contract value to another.
 *
 * **Attribution moves freely until there is evidence, and then it does not.** While the project has no
 * referral row and no payment, {@see link()} simply writes the snapshot. Once either exists, changing it
 * is `ReferralService::change()`'s business — supersede the old attribution, open a new one, re-point no
 * ledger row — and {@see change()} **refuses rather than guessing** while that class is absent. A wrong
 * guess here would be a commission paid to the wrong person.
 */
final readonly class ProjectReferralService
{
    public function __construct(private ReferralRecorder $recorder) {}

    /**
     * Attribute a project for the first time.
     */
    public function link(
        Project $project,
        int $collaboratorId,
        ?string $code = null,
        ?Carbon $on = null,
        ?User $actor = null,
    ): Project {
        return DB::transaction(function () use ($project, $collaboratorId, $code, $on, $actor): Project {
            $this->lock($project);

            if ($this->hasEvidence($project)) {
                throw ProjectRuleException::refuse(
                    'collaborator_id',
                    'This project already has attribution on record. Use the referral change screen, which supersedes it with a reason.'
                );
            }

            Project::unlock(Project::GROUP_REFERRAL, function () use ($project, $collaboratorId, $code, $on): void {
                $project->forceFill([
                    'collaborator_id' => $collaboratorId,
                    'referral_code' => $code,
                    'referral_date' => ($on ?? now())->toDateString(),
                ])->save();
            });

            ProjectReferralLinked::dispatch($project->refresh(), null, $actor?->getKey());

            return $project;
        });
    }

    /**
     * Move attribution to a different collaborator, with a reason.
     *
     * Once evidence exists this only ever forwards to the spine, which supersedes rather than mutates.
     * Without that class it refuses: inventing the behaviour would risk paying the wrong person.
     */
    public function change(
        Project $project,
        int $collaboratorId,
        string $reason,
        ?User $actor = null,
    ): Project {
        if (trim($reason) === '') {
            throw ProjectRuleException::reasonRequired('reason', 'Say why the attribution is changing.');
        }

        if ($this->hasEvidence($project) && ! $this->recorder->isAvailable()) {
            throw ProjectRuleException::refuse(
                'collaborator_id',
                'Attribution has already earned commission or evidence. Changing it needs the referral engine, which is not installed yet.'
            );
        }

        return DB::transaction(function () use ($project, $collaboratorId, $reason, $actor): Project {
            $this->lock($project);

            Project::unlock(Project::GROUP_REFERRAL, function () use ($project, $collaboratorId): void {
                $project->forceFill([
                    'collaborator_id' => $collaboratorId,
                    'referral_date' => $project->referral_date?->toDateString() ?? now()->toDateString(),
                ])->save();
            });

            ProjectReferralChanged::dispatch($project->refresh(), $reason, $actor?->getKey());

            return $project;
        });
    }

    /**
     * Remove attribution, with a reason. Same rule as {@see change()}.
     */
    public function clear(Project $project, string $reason, ?User $actor = null): Project
    {
        if (trim($reason) === '') {
            throw ProjectRuleException::reasonRequired('reason', 'Say why the attribution is being removed.');
        }

        if ($this->hasEvidence($project) && ! $this->recorder->isAvailable()) {
            throw ProjectRuleException::refuse(
                'collaborator_id',
                'Attribution has already earned commission or evidence. Removing it needs the referral engine, which is not installed yet.'
            );
        }

        return DB::transaction(function () use ($project, $reason, $actor): Project {
            $this->lock($project);

            Project::unlock(Project::GROUP_REFERRAL, function () use ($project): void {
                $project->forceFill([
                    'collaborator_id' => null,
                    'referral_code' => null,
                    'referral_date' => null,
                ])->save();
            });

            ProjectReferralCleared::dispatch($project->refresh(), $reason, $actor?->getKey());

            return $project;
        });
    }

    /**
     * Is there anything on record that a change would have to supersede rather than overwrite?
     *
     * Answers false while neither table exists — an absent consumer is not evidence.
     */
    private function hasEvidence(Project $project): bool
    {
        foreach ([
            ['collaborator_referrals', 'subject_id'],
            ['project_payments', 'project_id'],
        ] as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $exists = DB::table($table)
                ->where($column, $project->getKey())
                ->when(
                    $table === 'collaborator_referrals' && Schema::hasColumn($table, 'subject_type'),
                    fn ($query) => $query->where('subject_type', 'project')
                )
                ->exists();

            if ($exists) {
                return true;
            }
        }

        return false;
    }

    private function lock(Project $project): void
    {
        DB::table('projects')->where('id', $project->getKey())->lockForUpdate()->value('id');
        $project->refresh();
    }
}
