<?php

declare(strict_types=1);

namespace App\Policies\Hr;

use App\Enums\Ability;
use App\Models\Hr\LeaveBalance;
use App\Models\User;
use App\Policies\Hr\Concerns\ChecksHrPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Who may read and move a leave balance (phase-07 §2.13, §4.1, §9).
 *
 * **The module has no `edit`** (§4.1): a balance column is never written directly. Every movement is a
 * ledger entry with a reason, and `create` is the ability to post one — which is why granting somebody
 * "leave balances" access is granting them the right to add an audited row, not to type a new number.
 *
 * An employee always sees their own balance; a line manager sees their reporting tree's, because approving
 * leave without seeing whether the days exist would be approving blind.
 */
final class LeaveBalancePolicy
{
    use ChecksHrPermissions;

    public const MODULE = 'leave_balances';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny)
            || $this->holds($user, self::MODULE, Ability::View);
    }

    public function view(User $user, LeaveBalance $balance): bool|Response
    {
        if ($this->isSelf($user, $balance->employee)) {
            return true;
        }

        return $this->reaches($user, self::MODULE, Ability::View, $this->seesEmployee($user, $balance->employee));
    }

    /**
     * Posting an adjustment, a grant or a carry-forward — all append-only ledger rows.
     */
    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }
}
