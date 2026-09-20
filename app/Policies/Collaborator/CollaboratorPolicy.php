<?php

declare(strict_types=1);

namespace App\Policies\Collaborator;

use App\Enums\Ability;
use App\Models\Collaborator\Collaborator;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who may read and change a partner record (phase-08-09 §9).
 *
 * Permission names are built from the {@see Ability} enum rather than typed as literals, so a renamed
 * ability is a compile-time problem instead of a silent 403. Every question goes through `$user->can()`,
 * which re-enters `Gate::before` — so **a disabled module denies here too, Super Admin included** (D5).
 *
 * **404, not 403, when the ability is held but the row is out of reach.** A collaborator reaching for
 * another collaborator's id must not be able to learn that the id exists by watching the status code,
 * and the ids being probed here name people who are owed money.
 *
 * **`forceDelete()` is refused outright** (INV-C5). A partner with any financial history is never hard
 * deleted: the ledger, the payouts and the attributions all point back here with RESTRICT, and a debt
 * does not disappear with the relationship. Soft delete is allowed and the engine treats it as inactive.
 */
class CollaboratorPolicy
{
    private const MODULE = 'collaborators';

    /**
     * Tables whose rows mean "this partner has financial history". Each is checked for existence first,
     * because the spine's arrive with Phase 10 — and a missing table must not read as "no history".
     *
     * @var array<string, string>
     */
    private const FINANCIAL = [
        'collaborator_commission_ledger_entries' => 'collaborator_id',
        'collaborator_payouts' => 'collaborator_id',
        'collaborator_referrals' => 'collaborator_id',
    ];

    public function viewAny(User $user): bool
    {
        return $this->holds($user, Ability::ViewAny);
    }

    public function view(User $user, Collaborator $collaborator): bool|Response
    {
        return $this->reaches($user, Ability::View, $this->sees($user, $collaborator));
    }

    public function create(User $user): bool
    {
        return $this->holds($user, Ability::Create);
    }

    public function update(User $user, Collaborator $collaborator): bool|Response
    {
        if ($collaborator->trashed()) {
            return false;
        }

        return $this->reaches($user, Ability::Edit, $this->sees($user, $collaborator));
    }

    public function delete(User $user, Collaborator $collaborator): bool|Response
    {
        if ($collaborator->trashed()) {
            return false;
        }

        if (! $this->holds($user, Ability::Delete)) {
            return false;
        }

        if (! $this->sees($user, $collaborator)) {
            return Response::denyAsNotFound();
        }

        // A partner who is owed money is not removed, even softly, because the screens that chase the
        // debt are the ones that would stop showing them.
        if ($this->hasInFlightMoney($collaborator)) {
            return Response::deny(
                'This collaborator has commission or a payout still in flight. Settle or cancel it '
                .'first — removing the record now would hide a debt rather than clear it.'
            );
        }

        return true;
    }

    public function restore(User $user, Collaborator $collaborator): bool
    {
        return $collaborator->trashed() && $this->holds($user, Ability::Restore);
    }

    /**
     * Never, for anybody, Super Admin included (INV-C5).
     */
    public function forceDelete(User $user, Collaborator $collaborator): Response
    {
        return Response::deny(
            'A collaborator record is never destroyed. Commission entries, payouts and attributions all '
            .'point back at it, and a deleted partner would make those rows unreadable. Deactivate them '
            .'instead — the commission engine already treats an inactive partner as earning nothing.'
        );
    }

    public function approve(User $user, Collaborator $collaborator): bool|Response
    {
        return $this->reaches($user, Ability::Approve, $this->sees($user, $collaborator));
    }

    public function reject(User $user, Collaborator $collaborator): bool|Response
    {
        return $this->reaches($user, Ability::Reject, $this->sees($user, $collaborator));
    }

    public function changeStatus(User $user, Collaborator $collaborator): bool|Response
    {
        if ($collaborator->trashed()) {
            return false;
        }

        return $this->reaches($user, Ability::ChangeStatus, $this->sees($user, $collaborator));
    }

    /**
     * Money on a collaborator screen is a separate decision from the record itself (§9): a Sales
     * Executive picks the right partner at the desk without ever learning what that partner earns.
     */
    public function viewFinancial(User $user, ?Collaborator $collaborator = null): bool
    {
        return $this->holds($user, Ability::ViewFinancial);
    }

    public function viewLogs(User $user, Collaborator $collaborator): bool|Response
    {
        return $this->reaches($user, Ability::ViewLogs, $this->sees($user, $collaborator));
    }

    public function export(User $user): bool
    {
        return $this->holds($user, Ability::Export);
    }

    private function holds(User $user, Ability $ability): bool
    {
        return $user->can(self::MODULE.'.'.$ability->value);
    }

    /**
     * Hold the ability, or 403; then reach the row, or 404.
     */
    private function reaches(User $user, Ability $ability, bool $reachesRow): bool|Response
    {
        if (! $this->holds($user, $ability)) {
            return false;
        }

        return $reachesRow ? true : Response::denyAsNotFound();
    }

    /**
     * Is this record inside the user's window?
     *
     * There is no per-row ownership on the admin side — a collaborator is visible to anybody who may see
     * the module — so the only narrowing is the collaborator's own record in their own panel.
     */
    private function sees(User $user, Collaborator $collaborator): bool
    {
        if ($this->holds($user, Ability::ViewAny)) {
            return true;
        }

        return $collaborator->user_id !== null && (int) $collaborator->user_id === (int) $user->getKey();
    }

    private function hasInFlightMoney(Collaborator $collaborator): bool
    {
        foreach (self::FINANCIAL as $table => $column) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (DB::table($table)->where($column, $collaborator->getKey())->exists()) {
                return true;
            }
        }

        return false;
    }
}
