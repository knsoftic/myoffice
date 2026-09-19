<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

/**
 * Technologies — `admin.technologies.*` (phase-04 §2.4, §7.2, §8.1): `module:technologies`, `can:technologies.*`. Deleting one only detaches pivot rows (§6.2).
 */
final class TechnologyController extends TaxonomyController
{
    protected function module(): string
    {
        return 'technologies';
    }
}
