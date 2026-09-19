<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

/**
 * Blog categories — `admin.blog-categories.*` (phase-04 §2.13, §7.2, §8.1): `module:blog_categories`, `can:blog_categories.*`. Flat list, no nesting.
 */
final class BlogCategoryController extends TaxonomyController
{
    protected function module(): string
    {
        return 'blog_categories';
    }
}
