<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

/**
 * Blog tags — `admin.blog-tags.*` (phase-04 §2.14, §7.2, §8.1): `module:blog_tags`, `can:blog_tags.*`. An inactive tag keeps its posts but leaves the public tag cloud.
 */
final class BlogTagController extends TaxonomyController
{
    protected function module(): string
    {
        return 'blog_tags';
    }
}
