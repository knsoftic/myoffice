<?php

declare(strict_types=1);

namespace App\Policies\Hr;

use App\Policies\Hr\Concerns\CataloguePolicy;

/**
 * Who may configure leave types (phase-07 §4.1, §9).
 *
 * Leave types are configuration: quotas, carry-forward and approval levels are business rules stored as
 * data. Who may *take* leave of a type is a different question, asked by `LeaveRequestPolicy`.
 */
final class LeaveTypePolicy
{
    use CataloguePolicy;

    public const MODULE = 'leave_types';
}
