<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Models\Cms\Technology;
use App\Models\User;
use App\Policies\Cms\Concerns\AuthorizesContentModule;

/**
 * Who may manage technologies (phase-04 §4, §6.2): `technologies` = `CRUD` + `STATUS` + `FILES`.
 *
 * No row-level scope (§9.1). Deleting a technology only detaches pivot rows. The inline "create a
 * technology from the service form" combobox needs `technologies.create` (§8.2), i.e. `create()` here.
 */
final class TechnologyPolicy
{
    use AuthorizesContentModule;

    private const MODULE = 'technologies';

    /**
     * The immediate active/inactive toggle (§8.1): `technologies.change_status`.
     */
    public function toggleActive(User $user, Technology $technology): bool
    {
        return $this->changeStatus($user, $technology);
    }
}
