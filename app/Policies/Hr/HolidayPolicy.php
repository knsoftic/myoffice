<?php

declare(strict_types=1);

namespace App\Policies\Hr;

use App\Models\Hr\Holiday;
use App\Policies\Hr\Concerns\CataloguePolicy;

/**
 * Who may configure holidays (phase-07 §4.1, §9).
 *
 * The calendar is configuration. Declaring or removing a holiday re-resolves the days it covers, and the
 * **service** refuses a date already locked by payroll — this policy answers the permission question.
 */
final class HolidayPolicy
{
    use CataloguePolicy;

    public const MODULE = 'holidays';
}
