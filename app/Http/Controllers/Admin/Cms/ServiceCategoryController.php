<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

/**
 * Service categories — `admin.service-categories.*` (phase-04 §2.2, §7.2, §8.1): `module:service_categories`, `can:service_categories.*`.
 */
final class ServiceCategoryController extends TaxonomyController
{
    protected function module(): string
    {
        return 'service_categories';
    }
}
