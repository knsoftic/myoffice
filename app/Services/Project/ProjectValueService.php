<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Enums\CommissionCalculationType;
use App\Events\Project\ProjectValueRevised;
use App\Models\Project\Project;
use App\Models\Project\ProjectValueRevision;
use App\Models\User;
use App\Services\Project\Exceptions\ProjectRuleException;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of a project's value and commission override (phase-06 §6.1, INV-P1).
 *
 * Requirement §107 asks for "project value 200,000 -> 260,000" to be auditable. That is not a log line
 * beside an `update()`: it is an append-only `project_value_revisions` row with both ends, a signed delta,
 * a mandatory reason, the business date the new value applies from, and the actor's name snapshotted so a
 * deleted user cannot erase who did it. Months later the finance spine pays commission against a value it
 * can prove, and its §6.6 case 8 listener reads exactly these rows to decide what happens to an
 * entitlement that was promised against the old one.
 *
 * `Project` refuses a dirty value column unless this service opens the guard, so there is no path round it
 * — not a controller, not a console command, not a future phase.
 *
 * Two rules the database also enforces, checked here so the user gets a sentence rather than a 500:
 * a revision must actually change something (`chk_pvr_change`), and a commission override can never be
 * half-configured (`chk_projects_commission`) — a `percentage` with no rate is precisely what would make
 * the spine's `project_override` branch resolve a NULL.
 */
final readonly class ProjectValueService
{
    /**
     * Record the opening value of a brand-new project as revision 1.
     *
     * Called only by `ProjectService::create()`. It is a revision like any other so that the first number
     * on the audit screen is explained rather than appearing from nowhere.
     */
    public function setOpeningValue(Project $project, string $value, ?User $actor = null): ProjectValueRevision
    {
        return $this->revise(
            $project,
            ['project_value' => $value],
            'Opening contract value.',
            null,
            $actor,
        );
    }

    /**
     * Write one reasoned revision and move the project to match (§6.1).
     *
     * `$changes` may carry any of `project_value`, `discount_amount`, `commission_type`, `commission_rate`
     * and `commission_fixed_amount`; anything absent is left alone.
     *
     * @param  array<string, string|null>  $changes
     */
    public function revise(
        Project $project,
        array $changes,
        string $reason,
        ?string $effectiveOn = null,
        ?User $actor = null,
        ?string $notes = null,
    ): ProjectValueRevision {
        if (trim($reason) === '') {
            throw ProjectRuleException::reasonRequired('reason', 'Say why the project value is changing.');
        }

        $changes = array_intersect_key($changes, array_flip(Project::VALUE_COLUMNS));

        return DB::transaction(function () use ($project, $changes, $reason, $effectiveOn, $actor, $notes): ProjectValueRevision {
            DB::table('projects')->where('id', $project->getKey())->lockForUpdate()->value('id');
            $project->refresh();

            $before = $this->snapshot($project);
            $after = $this->apply($before, $changes);

            $this->assertSane($after);

            if (! $this->differs($before, $after)) {
                throw ProjectRuleException::noChange();
            }

            $oldNet = Money::sub($before['project_value'], $before['discount_amount']);
            $newNet = Money::sub($after['project_value'], $after['discount_amount']);

            $revision = new ProjectValueRevision;
            $revision->forceFill([
                'project_id' => $project->getKey(),
                'revision_no' => ((int) DB::table('project_value_revisions')
                    ->where('project_id', $project->getKey())
                    ->max('revision_no')) + 1,
                'old_project_value' => $before['project_value'],
                'new_project_value' => $after['project_value'],
                'old_discount_amount' => $before['discount_amount'],
                'new_discount_amount' => $after['discount_amount'],
                'old_net_value' => $oldNet,
                'new_net_value' => $newNet,
                'old_commission_type' => $before['commission_type'],
                'new_commission_type' => $after['commission_type'],
                'old_commission_rate' => $before['commission_rate'],
                'new_commission_rate' => $after['commission_rate'],
                'old_commission_fixed_amount' => $before['commission_fixed_amount'],
                'new_commission_fixed_amount' => $after['commission_fixed_amount'],
                'reason' => $reason,
                'effective_on' => $effectiveOn ?? now()->toDateString(),
                'changed_by' => $actor?->getKey(),
                // Snapshotted, so deleting the user later cannot erase who changed the contract value.
                'changed_by_name' => $actor?->name ?? 'System',
                'ip_address' => $this->ip(),
                'notes' => $notes,
            ])->save();

            Project::unlock(Project::GROUP_VALUE, function () use ($project, $after): void {
                $project->forceFill($after)->save();
            });

            DB::table('projects')->where('id', $project->getKey())->update([
                'value_revision_count' => DB::table('project_value_revisions')
                    ->where('project_id', $project->getKey())
                    ->count(),
            ]);

            $project->refresh();

            // delta_amount is a STORED generated column: the INSERT does not bring it back, so the row
            // has to be re-read before anyone — the event listener included — reads the signed delta.
            $revision->refresh();

            ProjectValueRevised::dispatch($project, $revision, $actor?->getKey());

            return $revision;
        });
    }

    /**
     * The audit screen's only read path — ordered oldest first, with the actor eager-loaded.
     *
     * @return Collection<int, ProjectValueRevision>
     */
    public function history(Project $project): Collection
    {
        return ProjectValueRevision::query()
            ->where('project_id', $project->getKey())
            ->with('changedBy:id,name')
            ->orderBy('revision_no')
            ->get();
    }

    /**
     * @return array<string, string|null>
     */
    private function snapshot(Project $project): array
    {
        return [
            'project_value' => (string) $project->project_value,
            'discount_amount' => (string) $project->discount_amount,
            'commission_type' => $project->commission_type?->value,
            'commission_rate' => $project->commission_rate === null ? null : (string) $project->commission_rate,
            'commission_fixed_amount' => $project->commission_fixed_amount === null
                ? null
                : (string) $project->commission_fixed_amount,
        ];
    }

    /**
     * @param  array<string, string|null>  $before
     * @param  array<string, string|null>  $changes
     * @return array<string, string|null>
     */
    private function apply(array $before, array $changes): array
    {
        $after = $before;

        foreach ($changes as $column => $value) {
            $after[$column] = $value === null || $value === '' ? null : (string) $value;
        }

        // The two money columns are never null: the table declares them not-null with a 0.00 default.
        $after['project_value'] = Money::of($after['project_value'] ?? '0');
        $after['discount_amount'] = Money::of($after['discount_amount'] ?? '0');

        return $after;
    }

    /**
     * @param  array<string, string|null>  $after
     */
    private function assertSane(array $after): void
    {
        if (Money::compare($after['project_value'], '0') < 0
            || Money::compare($after['discount_amount'], '0') < 0
            || Money::compare($after['discount_amount'], $after['project_value']) > 0) {
            throw ProjectRuleException::valueBelowZero();
        }

        $type = $after['commission_type'] === null
            ? null
            : CommissionCalculationType::tryFrom($after['commission_type']);

        if ($type === null) {
            return;
        }

        if ($type->requiresRate() && $after['commission_rate'] === null) {
            throw ProjectRuleException::halfConfiguredOverride();
        }

        if ($type->requiresFixedAmount() && $after['commission_fixed_amount'] === null) {
            throw ProjectRuleException::halfConfiguredOverride();
        }

        // `manual` is a ledger adjustment, not a project override — chk_projects_commission refuses it.
        if ($type === CommissionCalculationType::Manual) {
            throw ProjectRuleException::refuse(
                'commission_type',
                'A manual commission is an adjustment on a ledger entry, not a rule a project can carry.'
            );
        }

        if ($after['commission_rate'] !== null
            && (Money::compare($after['commission_rate'], '0') < 0
                || Money::compare($after['commission_rate'], '100') > 0)) {
            throw ProjectRuleException::refuse('commission_rate', 'A commission rate is between 0 and 100 percent.');
        }
    }

    /**
     * NULL-safe on the three nullable columns, matching `chk_pvr_change`'s `<=>`.
     *
     * @param  array<string, string|null>  $before
     * @param  array<string, string|null>  $after
     */
    private function differs(array $before, array $after): bool
    {
        if (Money::compare($before['project_value'], $after['project_value']) !== 0
            || Money::compare($before['discount_amount'], $after['discount_amount']) !== 0) {
            return true;
        }

        foreach (['commission_type', 'commission_rate', 'commission_fixed_amount'] as $column) {
            $old = $before[$column];
            $new = $after[$column];

            if (($old === null) !== ($new === null)) {
                return true;
            }

            if ($old !== null && $new !== null) {
                $same = $column === 'commission_type'
                    ? $old === $new
                    : Money::compare($old, $new) === 0;

                if (! $same) {
                    return true;
                }
            }
        }

        return false;
    }

    private function ip(): ?string
    {
        return app()->bound(Request::class) ? app(Request::class)->ip() : null;
    }
}
