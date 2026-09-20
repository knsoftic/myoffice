<?php

declare(strict_types=1);

namespace App\Services\Collaborator;

use App\Enums\CollaboratorStatus;
use App\Enums\UserStatus;
use App\Models\Collaborator\Collaborator;
use App\Models\Role;
use App\Models\User;
use App\Services\Collaborator\Exceptions\CollaboratorRuleException;
use App\Services\Collaborator\Exceptions\InvalidStatusTransition;
use App\Services\Core\UserService;
use App\Support\Modules;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Approving a partner, moving them between the four states, and giving them a login (phase-08-09 §6.2).
 *
 * **Eligibility is the status and nothing else** (INV-C4). Nothing here writes a `commission_eligible`
 * flag, a wallet balance or a ledger row: the spine's guard asks `status->earnsCommission()` per
 * payment, so there is exactly one expression of the fact and exactly one thing that can be wrong.
 *
 * **Suspension has to reach the session, not only the row.** A suspended partner whose browser still
 * holds a valid session would keep reading their own dashboard until it expired. The suspension
 * therefore mirrors onto `users.status`, which Phase 1's `active` middleware enforces on the next
 * request, and deletes the user's `sessions` rows so there is no next request to enforce it on.
 */
final class CollaboratorOnboardingService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly UserService $users,
    ) {}

    /**
     * Onboard a `pending` application.
     *
     * Any other state throws: approving twice is not a no-op to be swallowed, because the second
     * approval would re-stamp `approved_at` and lose when the decision actually happened.
     */
    public function approve(
        Collaborator $collaborator,
        User $actor,
        ?string $comment = null,
        ?Carbon $joiningDate = null,
        bool $provisionLogin = true,
    ): Collaborator {
        if ($collaborator->status !== CollaboratorStatus::Pending) {
            throw InvalidStatusTransition::between($collaborator->status->value, CollaboratorStatus::Active->value);
        }

        return $this->db->transaction(function () use ($collaborator, $actor, $comment, $joiningDate, $provisionLogin): Collaborator {
            $collaborator->withReason($comment ?? 'Application approved')->forceFill([
                'status' => CollaboratorStatus::Active->value,
                'status_reason' => $comment,
                'status_changed_at' => now(),
                'status_changed_by' => $actor->getKey(),
                'approved_at' => now(),
                'approved_by' => $actor->getKey(),
                'joining_date' => ($joiningDate ?? $collaborator->joining_date ?? now())->toDateString(),
            ])->save();

            if ($provisionLogin && $collaborator->email !== null) {
                $this->provisionUser($collaborator->fresh(), $actor);
            }

            $this->seedInitialCommissionRules($collaborator->fresh(), $actor);

            return $collaborator->fresh();
        });
    }

    /**
     * Refuse an application.
     *
     * It lands on `inactive`, not on a fifth status: §34 names four, and "rejected" and "the
     * relationship ended" are the same fact about whether this partner is currently working with the
     * business. The reason is what distinguishes them, and it is mandatory.
     */
    public function reject(Collaborator $collaborator, string $reason, User $actor): Collaborator
    {
        if ($collaborator->status !== CollaboratorStatus::Pending) {
            throw InvalidStatusTransition::between($collaborator->status->value, CollaboratorStatus::Inactive->value);
        }

        return $this->changeStatus($collaborator, CollaboratorStatus::Inactive, $reason, $actor);
    }

    /**
     * Move between two of §6.2.2's states.
     *
     * A reason is mandatory for `inactive` and `suspended` — the two that stop somebody working — and
     * optional for `active`, because reinstating needs no justification beyond the decision itself.
     */
    public function changeStatus(
        Collaborator $collaborator,
        CollaboratorStatus $target,
        ?string $reason,
        User $actor,
    ): Collaborator {
        $from = $collaborator->status;

        if ($from === $target) {
            return $collaborator;
        }

        if (! $from->canTransitionTo($target)) {
            throw InvalidStatusTransition::between($from->value, $target->value);
        }

        if ($target->requiresReason() && trim((string) $reason) === '') {
            throw CollaboratorRuleException::reasonRequired('status_reason', sprintf(
                'Say why this collaborator is being made %s. It is what the audit trail shows whoever '
                .'asks months later, and %s stops new business flowing to them.',
                $target->label(),
                $target->label(),
            ));
        }

        return $this->db->transaction(function () use ($collaborator, $from, $target, $reason, $actor): Collaborator {
            $collaborator->withReason($reason ?? sprintf('Status changed to %s', $target->label()))->forceFill([
                'status' => $target->value,
                'status_reason' => $reason,
                'status_changed_at' => now(),
                'status_changed_by' => $actor->getKey(),
            ])->save();

            $this->mirrorOntoLogin($collaborator->fresh(), $target, $reason);

            logger()->info('phase-08 §6.2.2: collaborator status changed.', [
                'collaborator' => $collaborator->collaborator_code,
                'from' => $from->value,
                'to' => $target->value,
                'actor' => $actor->getKey(),
            ]);

            return $collaborator->fresh();
        });
    }

    /**
     * Give a partner their panel account (D15: staff-created, never self-registration).
     *
     * Re-running returns the existing user rather than creating a second one — `uq_col_user` would
     * refuse it anyway, and an approval that was clicked twice should not be an error.
     *
     * **The password is random and is never logged, never mailed and never returned.** The invitation
     * is Phase 1's password-reset mail, so the only person who ever sees a usable credential is the
     * collaborator.
     */
    public function provisionUser(Collaborator $collaborator, User $actor): User
    {
        if ($collaborator->user_id !== null) {
            /** @var User $existing */
            $existing = User::query()->findOrFail($collaborator->user_id);

            return $existing;
        }

        if ($collaborator->email === null) {
            throw CollaboratorRuleException::refuse('email',
                'A panel account needs an email address. Add one to the profile first — it becomes the '
                .'login, and the invitation is sent to it.');
        }

        return $this->db->transaction(function () use ($collaborator, $actor): User {
            $existing = User::withTrashed()->where('email', $collaborator->email)->first();

            if ($existing !== null) {
                throw CollaboratorRuleException::refuse('email', sprintf(
                    'Somebody already signs in with %s. Use a different address for this collaborator, '
                    .'or link the existing account deliberately.',
                    $collaborator->email,
                ));
            }

            $role = Role::query()->where('name', 'Collaborator')->first();

            if ($role === null) {
                throw CollaboratorRuleException::refuse('user_id',
                    'The Collaborator role does not exist, so a panel account created now would reach '
                    .'nothing. Run the role seeder first.');
            }

            // Phase 1's own path, so there is one place a user is created, one audit row for the role
            // grant and one password rule. The actor is deliberately **not** passed: `UserService`
            // would otherwise refuse the grant unless the approver personally held every
            // `collaborator_portal.*` permission, and approving a partner is authorised by
            // `collaborators.approve`, not by being able to do a collaborator's job.
            $user = $this->users->create([
                'name' => $collaborator->displayName(),
                'email' => $collaborator->email,
                'phone' => $collaborator->phone,
                'whatsapp' => $collaborator->whatsapp,
                'status' => UserStatus::Active->value,
                // Random, and never logged, mailed or returned: the reset mail below is the only path
                // to a usable credential, so nobody but the collaborator ever holds one.
                'password' => Str::random(40),
                'must_change_password' => true,
                'roles' => [$role->getKey()],
            ]);

            $collaborator->withReason(sprintf('Panel account provisioned by %s', $actor->name))
                ->forceFill(['user_id' => $user->getKey()])->save();

            // Phase 1's reset mail *is* the invitation: it proves the address and sets the first
            // password in one step, and nothing in this process ever holds a usable credential.
            $this->sendInvitation($collaborator->email);

            return $user->fresh();
        });
    }

    /**
     * `CollaboratorStatus::canLogin()` mirrored onto `users.status`, which Phase 1's `active`
     * middleware is the single enforcement point for. There is no second authentication path to keep
     * in step, and a suspended partner's live session is ended rather than left to expire.
     */
    private function mirrorOntoLogin(Collaborator $collaborator, CollaboratorStatus $target, ?string $reason): void
    {
        if ($collaborator->user_id === null) {
            return;
        }

        $user = User::query()->find($collaborator->user_id);

        if ($user === null) {
            return;
        }

        $user->withReason($reason ?? sprintf('Collaborator is now %s', $target->label()))
            ->forceFill(['status' => ($target->canLogin() ? UserStatus::Active : UserStatus::Inactive)->value])
            ->save();

        if ($target->canLogin()) {
            return;
        }

        if (Schema::hasTable('sessions')) {
            $this->db->table('sessions')->where('user_id', $user->getKey())->delete();
        }
    }

    /**
     * §6.2's "seed the first two commission rule versions".
     *
     * The rule table and `CommissionRuleService` are the **spine's**, and the spine ships with Phase 10
     * (§1.4). Until it does, this skips with a warning rather than writing the table itself — and
     * `collaborators:seed-initial-rules` is the idempotent backfill that closes the gap for everybody
     * onboarded in the meantime.
     */
    private function seedInitialCommissionRules(Collaborator $collaborator, User $actor): void
    {
        if (! (bool) setting('collaborator.seed_commission_rules_on_approval', true)) {
            return;
        }

        if (! Modules::enabled('collaborator_commission_settings') || ! Schema::hasTable('collaborator_commission_settings')) {
            logger()->warning(
                'phase-08 §6.2: the first commission rules were not seeded — the commission spine is '
                .'not installed yet. Run collaborators:seed-initial-rules once it is.',
                ['collaborator' => $collaborator->collaborator_code],
            );

            return;
        }

        // The spine publishes CommissionRuleService::createVersion(); this phase calls it and never
        // writes the rule table itself (§1.3 "must NOT create").
        $service = 'App\\Services\\Collaborator\\CommissionRuleService';

        if (! class_exists($service)) {
            logger()->warning(
                'phase-08 §6.2: CommissionRuleService is not available, so no initial rule was created.',
                ['collaborator' => $collaborator->collaborator_code],
            );

            return;
        }

        foreach (['student', 'project'] as $scope) {
            app($service)->createVersion(
                collaborator: $collaborator,
                scope: $scope,
                calculationType: (string) setting('collaborator.default_'.$scope.'_commission_type', 'percentage'),
                rate: (string) setting('collaborator.default_'.$scope.'_commission_rate', '0'),
                effectiveFrom: now()->startOfDay(),
                reason: 'initial rule at onboarding',
                actor: $actor,
            );
        }
    }

    /**
     * Send the password-reset mail that doubles as the invitation. A mail transport that is not
     * configured must not roll back an approval that is otherwise complete.
     */
    private function sendInvitation(string $email): void
    {
        try {
            Password::broker()->sendResetLink(['email' => $email]);
        } catch (\Throwable $e) {
            logger()->warning('phase-08 §6.2.1: the collaborator invitation mail could not be sent.', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
