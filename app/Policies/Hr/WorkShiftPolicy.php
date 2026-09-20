<?php

declare(strict_types=1);

namespace App\Policies\Hr;

use App\Enums\Ability;
use App\Models\Hr\WorkShift;
use App\Models\User;
use App\Policies\Hr\Concerns\CataloguePolicy;

/**
 * Who may configure work shifts (phase-07 §4.1, §9).
 *
 * A shift is configuration. Editing one changes nothing about past attendance (HR-2), which is why
 * editing is an ordinary `edit` and not a guarded act.
 */
final class WorkShiftPolicy
{
    use CataloguePolicy;

    public const MODULE = 'work_shifts';

    /**
     * Putting employees on a shift (§7.2) — a roster decision, separate from defining the shift itself.
     */
    public function assign(User $user, WorkShift $shift): bool
    {
        return $this->holds($user, self::MODULE, Ability::Assign);
    }
}
