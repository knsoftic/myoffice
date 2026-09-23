<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Models\Institute\StudentIdCard;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;

/**
 * Who may issue, retire, replace and print a student's ID card
 * (phase-19-23 §9.3, §4.2, requirement §85).
 *
 * **`delete` is not here at all**, unlike a certificate. A certificate has a draft — a document
 * nobody has been given, which is reasonable to discard. A card does not: it is numbered, snapshotted
 * and printed in one step, so every row is a card that existed in the world. A card that was issued
 * stays on the register, marked lost, damaged, replaced or revoked, and the model refuses the delete
 * outright. `student_id_cards.delete` is not a registered ability either, so there is nothing to
 * grant by mistake.
 *
 * **The invariants are in the model, not here.** `Gate::before` waves a Super Admin past every policy
 * and a soft delete is an UPDATE no foreign key sees — D124. This file answers "may this role, on
 * this branch, do this ordinary thing"; the model answers "may this happen at all".
 */
final class StudentIdCardPolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'student_id_cards';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, StudentIdCard $card): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            && $this->sharesBranch($user, $this->branchOf($card));
    }

    /** Issuing a card, and issuing a replacement — both create a row. */
    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    /**
     * Editing the few things that are editable: the note, the template, the expiry.
     *
     * Every snapshot is refused by the service rather than by this method — a card's printed fields
     * are what it said when it was handed over, and a policy cannot express "these eight columns".
     */
    public function update(User $user, StudentIdCard $card): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && ! $card->status->isTerminal()
            && ! $this->isTrashed($card)
            && $this->sharesBranch($user, $this->branchOf($card));
    }

    /**
     * Marking a card expired, lost, damaged, replaced or revoked.
     *
     * A terminal card — replaced or revoked — is refused, because nothing further happens to one and
     * offering the control would produce a button that always fails.
     */
    public function changeStatus(User $user, StudentIdCard $card): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && ! $card->status->isTerminal()
            && ! $this->isTrashed($card)
            && $this->sharesBranch($user, $this->branchOf($card));
    }

    /**
     * Printing one card — and, per §4.2, **batch printing**, which is the same ability because it is
     * the same act repeated. The ceiling is `institute.id_card_batch_print_max`, enforced by the
     * service: a policy cannot count.
     */
    public function print(User $user, StudentIdCard $card): bool
    {
        return $this->holds($user, self::MODULE, Ability::Print)
            && $this->sharesBranch($user, $this->branchOf($card));
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }

    public function viewLogs(User $user, StudentIdCard $card): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewLogs)
            && $this->sharesBranch($user, $this->branchOf($card));
    }

    /** Never — see the class note. The model refuses it too, which is the part that matters. */
    public function delete(User $user, StudentIdCard $card): bool
    {
        return false;
    }

    /** Never. */
    public function forceDelete(User $user, StudentIdCard $card): bool
    {
        return false;
    }

    /** Never — there is nothing to restore, because nothing is ever deleted. */
    public function restore(User $user, StudentIdCard $card): bool
    {
        return false;
    }

    private function branchOf(StudentIdCard $card): ?int
    {
        $branchId = $card->getAttribute('branch_id');

        return $branchId === null ? null : (int) $branchId;
    }
}
