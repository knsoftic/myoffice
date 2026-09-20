<?php

declare(strict_types=1);

namespace App\Policies\Hr;

use App\Policies\Hr\Concerns\CataloguePolicy;

/**
 * Who may configure designations (phase-07 §4.1, §9).
 *
 * Job titles are configuration. Removing one is refused by the service while anybody holds it, so this
 * policy answers only the permission question.
 */
final class DesignationPolicy
{
    use CataloguePolicy;

    public const MODULE = 'designations';
}
