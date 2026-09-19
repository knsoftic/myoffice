<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Models\Cms\TeamMember;
use App\Models\User;
use App\Policies\Cms\Concerns\AuthorizesContentModule;

/**
 * Who may manage the public team page (phase-04 §4, §7.2): `team` = `CRUD_FULL` + `STATUS` + `FILES`.
 *
 * Website content, not an account (D32): no row-level scope (§9.1). Publishing and the public/hidden
 * switch are both `team.change_status`.
 */
final class TeamMemberPolicy
{
    use AuthorizesContentModule;

    private const MODULE = 'team';

    /**
     * `admin.team.visibility` — `team.change_status`.
     */
    public function togglePublic(User $user, TeamMember $member): bool
    {
        return $this->changeStatus($user, $member);
    }
}
