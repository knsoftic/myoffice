<?php

declare(strict_types=1);

namespace App\Services\Collaborator;

use App\DataObjects\Collaborator\BaseResolution;
use App\DataObjects\Collaborator\CommissionOutcome;
use App\DataObjects\Collaborator\RuleResolution;
use App\Enums\CommissionBase;
use App\Enums\CommissionScope;
use App\Enums\CommissionSkipReason;
use App\Enums\CommissionSourceType;
use App\Enums\CommissionStatus;
use App\Enums\LedgerEntryPurpose;
use App\Enums\ReferralSubject;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Collaborator\CollaboratorReferral;
use App\Models\Finance\ProjectPayment;
use App\Models\Project\Project;
use App\Services\Collaborator\Concerns\RunsCommissionGuards;
use App\Support\Collaborator\CommissionSettings;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * **The only place a project commission amount is computed** (spine §6.1, phase-11 §1.3, [D-IMP-3]).
 *
 * It runs the identical sequence its student twin runs — G0 to G11, then C1 to C8 — through
 * {@see RunsCommissionGuards}. Four things differ, and they are the four this class supplies:
 *
 *   1. **The attribution is on the project**, not on the client. A client referred once does not earn
 *      a partner commission on every project that client ever commissions; each engagement carries its
 *      own referral row, which is what makes "the partner who brought us this job" answerable.
 *   2. **A per-project override exists** (§45). `projects.commission_type` is an explicit admin act on
 *      one engagement and authorises commission on its own — but a collaborator rule that says
 *      `is_enabled = false` still wins, because an explicit no outranks an implicit yes.
 *   3. **G10 is about milestones.** On the `milestone` base a payment has to name the milestone it is
 *      against, and the rule has to allow it.
 *   4. Two more base modes, `total_value` and `milestone`, which the shared resolver already knows.
 */
final class ProjectCommissionService
{
    use RunsCommissionGuards;

    public function __construct(
        private readonly CommissionEngineContext $engine,
    ) {}

    /**
     * Evaluate one project payment. Idempotent under unlimited replay; `$force` bypasses G2 only.
     */
    public function handlePayment(ProjectPayment $payment, bool $force = false): CommissionOutcome
    {
        return $this->process($payment, $force);
    }

    /*
    |--------------------------------------------------------------------------
    | What the shared sequence asks of the project side
    |--------------------------------------------------------------------------
    */

    protected function engine(): CommissionEngineContext
    {
        return $this->engine;
    }

    protected function scope(): CommissionScope
    {
        return CommissionScope::Project;
    }

    protected function purpose(): LedgerEntryPurpose
    {
        return LedgerEntryPurpose::ProjectCommission;
    }

    /**
     * The attribution is on the **project**.
     *
     * A client attribution exists and is deliberately not consulted here: it records who brought the
     * client in, which is a different fact from who brought in this engagement. Reading it would pay a
     * partner on work they had nothing to do with, years after the introduction.
     */
    protected function referralFor(Model $payment): ?CollaboratorReferral
    {
        return $this->engine->referrals->effectiveOnSubject(
            ReferralSubject::Project,
            (int) $payment->project_id,
            $payment->paid_on,
        );
    }

    protected function ruleProject(Model $payment): ?Project
    {
        return $payment->relationLoaded('project') ? $payment->project : $payment->project()->first();
    }

    /**
     * G10, project form: when the base is `milestone`, the payment must name a milestone the rule
     * allows (spine §6.1, step 6).
     *
     * A milestone-based rule with no milestone on the payment has nothing to be a percentage *of*, and
     * guessing — "the next unpaid one" — would move the promise every time somebody reordered a plan.
     *
     * @return array{0: CommissionSkipReason, 1: string}|null
     */
    protected function scopeGuard(Model $payment, RuleResolution $rule, CommissionSettings $settings): ?array
    {
        if ($rule->base !== CommissionBase::Milestone) {
            return null;
        }

        if ($payment->project_milestone_id === null) {
            return [
                CommissionSkipReason::MilestoneNotCommissionable,
                'This rule pays on milestones, and the payment is not recorded against one. Record it '
                .'against the milestone it settles, or put the partner on a value-based rule.',
            ];
        }

        $allowed = $rule->appliesToMilestoneIds;

        if ($allowed !== null && ! in_array((int) $payment->project_milestone_id, array_map('intval', $allowed), true)) {
            return [
                CommissionSkipReason::MilestoneNotCommissionable,
                'This partner\'s rule names the milestones it pays on, and this is not one of them.',
            ];
        }

        return null;
    }

    protected function resolveDocument(Model $payment, CommissionBase $base, RuleResolution $rule): BaseResolution
    {
        return $this->engine->bases->forProjectPayment($payment, $base, $rule);
    }

    protected function sourceTypeFor(Model $payment): CommissionSourceType
    {
        return CommissionSourceType::ProjectPayment;
    }

    /**
     * @return array<string, int|null>
     */
    protected function subjectColumnsFor(Model $payment): array
    {
        return [
            'project_id' => (int) $payment->project_id,
            'project_milestone_id' => $payment->project_milestone_id === null
                ? null
                : (int) $payment->project_milestone_id,
        ];
    }

    protected function paymentColumn(): string
    {
        return 'project_payment_id';
    }

    /**
     * Published so a screen can show what a project has already earned without summing the ledger
     * itself (INV-26). It reads the entries, it never recomputes a rate.
     */
    public function earnedOn(Project $project): string
    {
        return Money::of((string) (
            CollaboratorCommissionLedgerEntry::query()
                ->where('project_id', $project->getKey())
                ->whereNot('status', CommissionStatus::Cancelled->value)
                ->sum('signed_amount') ?: Money::ZERO
        ));
    }
}
