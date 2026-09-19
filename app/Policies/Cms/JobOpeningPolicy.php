<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Policies\Cms\Concerns\AuthorizesContentModule;

/**
 * Who may manage job openings (phase-04 §4, §7.2, §9.1): module `jobs` (table `job_openings`) =
 * `CRUD_FULL` + `STATUS`.
 *
 * Openings are global to whoever holds the permission (§9.1 "Jobs: global"); the per-opening
 * hiring-manager scope applies to their **applications**, in `JobApplicationPolicy`. `forceDelete` stays
 * refused: removing an opening for good is `JobOpeningService::purge()` (CV cleanup first), which has no
 * route in Phase 4.
 */
final class JobOpeningPolicy
{
    use AuthorizesContentModule;

    private const MODULE = 'jobs';
}
