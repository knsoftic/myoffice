<?php

declare(strict_types=1);

namespace App\Services\Collaborator;

use App\DataObjects\Collaborator\RuleData;
use App\DataObjects\Collaborator\RuleResolution;
use App\Enums\CommissionBase;
use App\Enums\CommissionCalculationType;
use App\Enums\CommissionRuleSource;
use App\Enums\CommissionRuleStatus;
use App\Enums\CommissionScope;
use App\Enums\FixedCommissionRelease;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Collaborator\CollaboratorCommissionSetting;
use App\Models\Project\Project;
use App\Services\Collaborator\Exceptions\CollaboratorRuleException;
use App\Support\Format;
use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Which rule governed a payment, and how a new one is written (spine §6.1.1, §2.18.5, phase-10-12 §6.3).
 *
 * **A rate is never edited.** `createVersion()` closes the open version the day before the new one
 * starts and inserts a successor that points back at it. "What was this partner's rate on the day that
 * receipt arrived" is then answerable for ever by a query with a date in it, which is the only question
 * a commission dispute ever asks.
 *
 * **There is no global-default fallback** ([D-FS-9]). When no version covers a date the engine skips
 * with `no_effective_rule` and says so on the skip report. Phase 2's `default_*_commission_rate` keys
 * pre-fill the rule form and seed a partner's first version; they never authorise a payment on their
 * own, because paying a collaborator nobody configured is the one mistake that cannot be explained to a
 * client afterwards.
 *
 * **Resolution is by value date, never by `now()`** (INV-16), and every figure a calculation needs is
 * flattened into a {@see RuleResolution} here, so nothing downstream re-reads a rule row and nothing
 * downstream can grow a branch that treats the two rule sources differently.
 */
final class CommissionRuleService
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Resolution
    |--------------------------------------------------------------------------
    */

    /**
     * Zero or one rule for this collaborator, scope and **value date**.
     *
     * Precedence: a per-project override, then the collaborator's effective-dated version, then
     * nothing. The project override is an explicit admin act and authorises commission on its own — but
     * a collaborator rule that exists and says `is_enabled = false` is an explicit "no" about the same
     * partner, and an explicit no outranks an implicit yes, so the disable wins (spine §6.1.1).
     */
    public function resolve(
        Collaborator $collaborator,
        CommissionScope $scope,
        CarbonInterface $on,
        ?Project $project = null,
    ): ?RuleResolution {
        $date = $this->businessDate($on);
        $rule = $this->versionEffectiveOn($collaborator, $scope, $date);
        $override = $scope === CommissionScope::Project ? $this->projectOverride($project, $collaborator) : null;

        if ($override !== null) {
            return $rule !== null && ! $rule->is_enabled
                ? RuleResolution::fromRule($rule)
                : $override;
        }

        return $rule === null ? null : RuleResolution::fromRule($rule);
    }

    /**
     * The single version whose window covers `$date`.
     *
     * `uq_ccs_start` (no two versions begin on one day) and `uq_ccs_open` (at most one open-ended
     * version per collaborator per scope) mean this can never legitimately see two candidates. The
     * ordering is there so a half-migrated historical import degrades to "the version that started
     * latest" rather than to whichever row the storage engine happened to return first.
     */
    public function versionEffectiveOn(
        Collaborator $collaborator,
        CommissionScope $scope,
        CarbonInterface $on,
    ): ?CollaboratorCommissionSetting {
        return CollaboratorCommissionSetting::query()
            ->where('collaborator_id', $collaborator->getKey())
            ->where('commission_for', $scope->value)
            ->effectiveOn($this->businessDate($on))
            ->orderByDesc('version')
            ->first();
    }

    /**
     * The version a new payment would use today, and what the rule timeline marks as current.
     */
    public function currentVersion(Collaborator $collaborator, CommissionScope $scope): ?CollaboratorCommissionSetting
    {
        return CollaboratorCommissionSetting::query()
            ->where('collaborator_id', $collaborator->getKey())
            ->where('commission_for', $scope->value)
            ->whereIn('status', [CommissionRuleStatus::Active->value, CommissionRuleStatus::Scheduled->value])
            ->openEnded()
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Every version for a scope, newest first — the rule timeline (§8.4).
     *
     * @return Collection<int, CollaboratorCommissionSetting>
     */
    public function timeline(Collaborator $collaborator, CommissionScope $scope)
    {
        return CollaboratorCommissionSetting::query()
            ->where('collaborator_id', $collaborator->getKey())
            ->where('commission_for', $scope->value)
            ->with('approver:id,name')
            ->orderByDesc('version')
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Writing a version
    |--------------------------------------------------------------------------
    */

    /**
     * Close the open version and insert its successor. **Never an UPDATE of a rate.**
     *
     * The collaborator row is locked for the duration, so two administrators saving a new rate at the
     * same moment serialise rather than both closing the same version; `uq_ccs_open` is the backstop
     * that makes two open-ended versions impossible even if the lock were somehow skipped.
     */
    public function createVersion(
        Collaborator $collaborator,
        CommissionScope $scope,
        RuleData $data,
        string $reason,
    ): CollaboratorCommissionSetting {
        $reason = trim($reason);

        if ($reason === '') {
            throw CollaboratorRuleException::reasonRequired('reason',
                'A new rule version changes what a partner earns from its start date. The reason is '
                .'what the timeline shows beside it, and what somebody reads when they ask why the '
                .'rate moved.');
        }

        if ($data->scope !== $scope) {
            throw CollaboratorRuleException::refuse('commission_for', sprintf(
                'This version describes %s commission but is being saved against the %s rule. One of '
                .'the two is wrong, and guessing which would put a rate on the wrong side of the '
                .'business.',
                $data->scope->value,
                $scope->value,
            ));
        }

        $from = $this->businessDate($data->effectiveFrom);

        return $this->db->transaction(function () use ($collaborator, $scope, $data, $reason, $from): CollaboratorCommissionSetting {
            /** @var Collaborator $locked */
            $locked = Collaborator::query()->whereKey($collaborator->getKey())->lockForUpdate()->firstOrFail();

            $open = $this->currentVersion($locked, $scope);
            $closeOn = $from->copy()->subDay();

            if ($open !== null) {
                if ($open->effective_from->greaterThanOrEqualTo($from)) {
                    throw CollaboratorRuleException::refuse('effective_from', sprintf(
                        'Version %d already starts on %s. A new version starts after the one it '
                        .'replaces — two versions beginning on one day have no order, and a payment '
                        .'dated that day could resolve to either.',
                        (int) $open->version,
                        $open->effective_from->toDateString(),
                    ));
                }

                $this->assertNoLedgerBeyond($open, $closeOn);
                $this->closeRow($open, $closeOn, CommissionRuleStatus::Superseded, $reason);
            }

            $row = CollaboratorCommissionSetting::allowDirectWrites(
                function () use ($locked, $data, $from, $open, $reason): CollaboratorCommissionSetting {
                    $new = new CollaboratorCommissionSetting;

                    $new->forceFill(array_merge($data->columns(), [
                        'collaborator_id' => $locked->getKey(),
                        'effective_from' => $from->toDateString(),
                        'effective_to' => null,
                        'status' => $this->statusFor($from)->value,
                        'version' => $open === null ? 1 : (int) $open->version + 1,
                        'supersedes_id' => $open?->getKey(),
                        'change_reason' => mb_substr($reason, 0, 255),
                    ]));

                    $new->save();

                    return $new->refresh();
                }
            );

            if ($open !== null) {
                CollaboratorCommissionSetting::allowDirectWrites(function () use ($open): void {
                    $open->forceFill(['superseded_at' => now()])->save();
                });
            }

            return $row;
        });
    }

    /**
     * Close an open version without a successor — the partner's rule simply ends.
     */
    public function close(CollaboratorCommissionSetting $rule, CarbonInterface $on, string $reason): CollaboratorCommissionSetting
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw CollaboratorRuleException::reasonRequired();
        }

        $date = $this->businessDate($on);

        return $this->db->transaction(function () use ($rule, $date, $reason): CollaboratorCommissionSetting {
            $this->assertNoLedgerBeyond($rule, $date);
            $this->closeRow($rule, $date, CommissionRuleStatus::Expired, $reason);

            return $rule->refresh();
        });
    }

    /**
     * Withdraw a version that has not started yet.
     *
     * Refused the moment any ledger row quotes it — a cancelled version reads as "this never applied",
     * and a commission that quotes it would then be unexplainable.
     */
    public function cancelScheduled(CollaboratorCommissionSetting $rule, string $reason): CollaboratorCommissionSetting
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw CollaboratorRuleException::reasonRequired();
        }

        if ($rule->status !== CommissionRuleStatus::Scheduled) {
            throw CollaboratorRuleException::refuse('status', sprintf(
                'Only a version that has not started yet can be withdrawn. This one is %s: it is part '
                .'of the partner\'s history and is closed, never cancelled.',
                $rule->status->label(),
            ));
        }

        return $this->db->transaction(function () use ($rule, $reason): CollaboratorCommissionSetting {
            $this->assertNoLedgerAtAll($rule);

            // The window closes on the day it would have opened. Two reasons, and neither is cosmetic:
            //
            //   * it releases `open_guard`, which is what lets the predecessor reopen below. Leaving
            //     `effective_to` NULL would hold the single open-ended slot per collaborator and scope
            //     hostage to a version nobody ever used, and the reopen would hit a 1062;
            //   * `chk_ccs_dates` refuses an `effective_to` **before** `effective_from`, so a
            //     zero-length window is the shortest shape the table permits.
            //
            // Nothing can resolve to it regardless of the dates: `cancelled` is the one status
            // `canGovernAPayment()` refuses, and `scopeEffectiveOn()` excludes it outright.
            CollaboratorCommissionSetting::allowDirectWrites(function () use ($rule, $reason): void {
                $rule->forceFill([
                    'status' => CommissionRuleStatus::Cancelled->value,
                    'effective_to' => $rule->effective_from->toDateString(),
                    'superseded_at' => now(),
                    'notes' => $this->appendReason($rule, $reason),
                ])->save();
            });

            // The predecessor reopens: a version withdrawn before it ever applied must not leave the
            // partner with no rule at all, which would silently turn every later receipt into
            // `no_effective_rule`. `uq_ccs_open` permits it because the cancelled row closed.
            $previous = $rule->supersedes;

            if ($previous !== null && $previous->status === CommissionRuleStatus::Superseded) {
                CollaboratorCommissionSetting::allowDirectWrites(function () use ($previous): void {
                    $previous->forceFill([
                        'effective_to' => null,
                        'status' => $this->statusFor($previous->effective_from)->value,
                        'superseded_at' => null,
                    ])->save();
                });
            }

            return $rule->refresh();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The per-project override (§45): the project row carries the rate, and `rule_source` says so.
     *
     * It applies only to the collaborator the project is actually attributed to — a project override is
     * a statement about *this* engagement, and reading it for a different partner would pay somebody
     * else's rate.
     */
    private function projectOverride(?Project $project, Collaborator $collaborator): ?RuleResolution
    {
        if ($project === null || $project->commission_type === null) {
            return null;
        }

        if ((int) $project->collaborator_id !== (int) $collaborator->getKey()) {
            return null;
        }

        $type = $project->commission_type instanceof CommissionCalculationType
            ? $project->commission_type
            : CommissionCalculationType::tryFrom((string) $project->commission_type);

        if ($type === null || $type === CommissionCalculationType::Manual) {
            return null;
        }

        return new RuleResolution(
            source: CommissionRuleSource::ProjectOverride,
            scope: CommissionScope::Project,
            calculationType: $type,
            rate: $project->commission_rate === null ? null : (string) $project->commission_rate,
            fixedAmount: $project->commission_fixed_amount === null ? null : (string) $project->commission_fixed_amount,
            release: $this->configuredRelease(),
            base: $this->configuredBase(CommissionScope::Project),
            isEnabled: true,
        );
    }

    private function configuredBase(CommissionScope $scope): CommissionBase
    {
        $configured = (string) setting($scope->baseSettingKey(), CommissionBase::Paid->value);

        return CommissionBase::tryFrom($configured) ?? CommissionBase::Paid;
    }

    private function configuredRelease(): FixedCommissionRelease
    {
        $configured = (string) setting('collaborator.fixed_commission_release', FixedCommissionRelease::Prorated->value);

        return FixedCommissionRelease::tryFrom($configured) ?? FixedCommissionRelease::Prorated;
    }

    /**
     * A version created for a future date waits; one created for today or the past is live immediately.
     */
    private function statusFor(CarbonInterface $from): CommissionRuleStatus
    {
        return $this->businessDate($from)->greaterThan(Carbon::now(Format::timezone())->startOfDay())
            ? CommissionRuleStatus::Scheduled
            : CommissionRuleStatus::Active;
    }

    private function closeRow(
        CollaboratorCommissionSetting $rule,
        Carbon $on,
        CommissionRuleStatus $status,
        string $reason,
    ): void {
        CollaboratorCommissionSetting::allowDirectWrites(function () use ($rule, $on, $status, $reason): void {
            $rule->forceFill([
                'effective_to' => $on->toDateString(),
                'status' => $status->value,
                'notes' => $this->appendReason($rule, $reason),
            ])->save();
        });
    }

    /**
     * Refuse to close a version earlier than a commission it already produced.
     *
     * A ledger row dated after the proposed `effective_to` would then quote a version whose window does
     * not contain it — the row would still be correct about what was paid, but the rule beside it would
     * contradict it, and a contradiction in an audit trail is worse than a refusal in a form.
     */
    private function assertNoLedgerBeyond(CollaboratorCommissionSetting $rule, Carbon $closeOn): void
    {
        $latest = CollaboratorCommissionLedgerEntry::query()
            ->where('commission_setting_id', $rule->getKey())
            ->whereDate('transaction_date', '>', $closeOn->toDateString())
            ->orderByDesc('transaction_date')
            ->first(['id', 'transaction_date']);

        if ($latest === null) {
            return;
        }

        throw CollaboratorRuleException::refuse('effective_from', sprintf(
            'Version %d already paid commission on %s, which is after the %s this change would close '
            .'it on. Closing it would leave CLE-%s quoting a rule whose window does not contain it.',
            (int) $rule->version,
            $latest->transaction_date->toDateString(),
            $closeOn->toDateString(),
            (string) $latest->getKey(),
        ));
    }

    private function assertNoLedgerAtAll(CollaboratorCommissionSetting $rule): void
    {
        $count = CollaboratorCommissionLedgerEntry::query()
            ->where('commission_setting_id', $rule->getKey())
            ->count();

        if ($count === 0) {
            return;
        }

        throw CollaboratorRuleException::refuse('status', sprintf(
            'Version %d has already produced %d commission %s. Cancelling reads as "this never '
            .'applied", which those entries would contradict — close it with an end date instead.',
            (int) $rule->version,
            $count,
            $count === 1 ? 'entry' : 'entries',
        ));
    }

    /**
     * `change_reason` belongs to the version that *caused* the change, so a closing reason lands in the
     * closed row's notes rather than overwriting the reason it was created with.
     */
    private function appendReason(CollaboratorCommissionSetting $rule, string $reason): string
    {
        $existing = trim((string) $rule->notes);
        $line = sprintf('[%s] %s', Carbon::now(Format::timezone())->toDateString(), $reason);

        return $existing === '' ? $line : $existing."\n".$line;
    }

    private function businessDate(CarbonInterface $date): Carbon
    {
        return Carbon::instance($date->toDateTime())->startOfDay();
    }
}
