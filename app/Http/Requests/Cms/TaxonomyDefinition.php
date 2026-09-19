<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Models\Cms\BlogCategory;
use App\Models\Cms\BlogTag;
use App\Models\Cms\PortfolioCategory;
use App\Models\Cms\ServiceCategory;
use App\Models\Cms\Technology;

/**
 * The five small lists everything else hangs off (phase-04 §2.2, §2.4, §2.6, §2.13, §2.14, §6.2, §8.1),
 * declared once for the taxonomy requests and the taxonomy controllers.
 *
 * Which columns a list has decides which fields its form may post: a blog tag is only a name, a slug and
 * an active flag; a technology has a logo and a colour instead of a description; the three category
 * lists carry an image and an SEO block (D23 — the SEO values live in the one SEO store, never on the row).
 */
final class TaxonomyDefinition
{
    /**
     * `children` are `withCount()` entries, `'relation as {count_key}'`, named the way the list renders them.
     *
     * @var array<string, array{
     *     model: class-string,
     *     table: string,
     *     label: string,
     *     plural: string,
     *     name_max: int,
     *     description: bool,
     *     icon: bool,
     *     color: bool,
     *     sortable: bool,
     *     image_field: string|null,
     *     image_column: string|null,
     *     seo: bool,
     *     children: list<string>,
     *     route: string,
     * }>
     */
    public const DEFINITIONS = [
        'service_categories' => [
            'model' => ServiceCategory::class,
            'table' => 'service_categories',
            'label' => 'service category',
            'plural' => 'service categories',
            'name_max' => 150,
            'description' => true,
            'icon' => true,
            'color' => false,
            'sortable' => true,
            'image_field' => 'image',
            'image_column' => 'image_media_id',
            'seo' => true,
            'children' => ['services as services_count'],
            'route' => 'admin.service-categories',
        ],
        'portfolio_categories' => [
            'model' => PortfolioCategory::class,
            'table' => 'portfolio_categories',
            'label' => 'portfolio category',
            'plural' => 'portfolio categories',
            'name_max' => 150,
            'description' => true,
            'icon' => true,
            'color' => false,
            'sortable' => true,
            'image_field' => 'image',
            'image_column' => 'image_media_id',
            'seo' => true,
            'children' => ['items as portfolio_items_count'],
            'route' => 'admin.portfolio-categories',
        ],
        'blog_categories' => [
            'model' => BlogCategory::class,
            'table' => 'blog_categories',
            'label' => 'blog category',
            'plural' => 'blog categories',
            'name_max' => 150,
            'description' => true,
            'icon' => true,
            'color' => false,
            'sortable' => true,
            'image_field' => 'image',
            'image_column' => 'image_media_id',
            'seo' => true,
            'children' => ['posts as blog_posts_count'],
            'route' => 'admin.blog-categories',
        ],
        'blog_tags' => [
            'model' => BlogTag::class,
            'table' => 'blog_tags',
            'label' => 'blog tag',
            'plural' => 'blog tags',
            'name_max' => 100,
            'description' => false,
            'icon' => false,
            'color' => false,
            'sortable' => false,
            'image_field' => null,
            'image_column' => null,
            'seo' => false,
            'children' => ['posts as blog_posts_count'],
            'route' => 'admin.blog-tags',
        ],
        'technologies' => [
            'model' => Technology::class,
            'table' => 'technologies',
            'label' => 'technology',
            'plural' => 'technologies',
            'name_max' => 100,
            'description' => false,
            'icon' => true,
            'color' => true,
            'sortable' => true,
            'image_field' => 'logo',
            'image_column' => 'logo_media_id',
            'seo' => false,
            'children' => ['services as services_count', 'portfolioItems as portfolio_items_count'],
            'route' => 'admin.technologies',
        ],
    ];

    /**
     * @return array<string, mixed>|null
     */
    public static function for(?string $module): ?array
    {
        return $module !== null && isset(self::DEFINITIONS[$module]) ? self::DEFINITIONS[$module] : null;
    }
}
