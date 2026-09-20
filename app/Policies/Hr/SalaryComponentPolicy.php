<?php

declare(strict_types=1);

namespace App\Policies\Hr;

use App\Policies\Hr\Concerns\CataloguePolicy;

/**
 * Who may configure salary components (phase-07 §4.1, §9).
 *
 * The component catalogue is configuration and carries no money of its own — a default amount is a
 * suggestion, and the money lives on a structure or a slip. A **system** component cannot be deleted at
 * all; the service refuses that, whatever this policy says.
 */
final class SalaryComponentPolicy
{
    use CataloguePolicy;

    public const MODULE = 'salary_components';
}
