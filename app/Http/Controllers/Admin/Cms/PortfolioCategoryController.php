<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

/**
 * Portfolio categories — `admin.portfolio-categories.*` (phase-04 §2.6, §7.2, §8.1): `module:portfolio_categories`, `can:portfolio_categories.*`.
 */
final class PortfolioCategoryController extends TaxonomyController
{
    protected function module(): string
    {
        return 'portfolio_categories';
    }
}
