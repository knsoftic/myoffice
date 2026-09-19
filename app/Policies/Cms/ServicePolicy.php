<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Models\Cms\Service;
use App\Models\User;
use App\Policies\Cms\Concerns\AuthorizesContentModule;

/**
 * Who may manage the service catalogue (phase-04 §4, §7.2): `services` = `CRUD_FULL` + `STATUS` + `FILES`.
 *
 * Content, not owned data: no row-level scope (§9.1). `status` and `featured` are both
 * `services.change_status` (§7.2); `export` is `services.export`. A user with no `services.*` permission
 * is refused every action (§11 test 7).
 */
final class ServicePolicy
{
    use AuthorizesContentModule;

    private const MODULE = 'services';

    /**
     * `admin.services.featured` — `services.change_status`.
     */
    public function toggleFeatured(User $user, Service $service): bool
    {
        return $this->changeStatus($user, $service);
    }
}
