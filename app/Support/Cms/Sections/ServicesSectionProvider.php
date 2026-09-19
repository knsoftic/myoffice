<?php

declare(strict_types=1);

namespace App\Support\Cms\Sections;

use App\Enums\Cms\ImageProfile;
use App\Models\Cms\Service;
use App\Models\Cms\ServiceCategory;
use App\Models\Cms\Technology;

/**
 * `services` section: published services, featured first then `sort_order`, with category and technology
 * chips. A hidden price (`price_visible = false`) is **not** included in the data at all.
 */
final class ServicesSectionProvider extends MarketingSectionProvider
{
    public function key(): string
    {
        return 'services';
    }

    protected function module(): string
    {
        return 'services';
    }

    protected function build(array $options): array
    {
        $query = Service::query()->public()
            ->with(['image', 'category', 'technologies' => static fn ($technologies) => $technologies->where('technologies.is_active', true)])
            ->when($options['featured_only'], static fn ($builder) => $builder->where('services.is_featured', true))
            ->featuredFirst();

        if ($options['category'] !== null) {
            $query->whereHas('category', static fn ($category) => $category->public()->where('slug', $options['category']));
        }

        $items = $query->limit($options['limit'])->get()->map(function (Service $service): array {
            $image = $this->image($service->image, ImageProfile::Card);
            $category = $service->category instanceof ServiceCategory && $service->category->isActive() ? $service->category : null;

            return [
                // Phase 3's shared teaser card (`site/sections/partials/teaser`) reads title / excerpt / media / meta.
                'title' => (string) $service->name,
                'excerpt' => $service->short_description,
                'media' => $image,
                'meta' => $category === null ? null : (string) $category->name,
                'id' => (int) $service->getKey(),
                'name' => (string) $service->name,
                'slug' => (string) $service->slug,
                'url' => $this->url('site.services.show', ['service' => $service->slug]),
                'short_description' => $service->short_description,
                'icon' => $service->icon,
                'image' => $image,
                'starting_price' => $service->showsPrice() ? (string) $service->starting_price : null,
                'price_note' => $service->showsPrice() ? $service->price_note : null,
                'is_featured' => (bool) $service->is_featured,
                'category' => $category === null ? null : ['name' => (string) $category->name, 'slug' => (string) $category->slug],
                'technologies' => $service->technologies->map(static fn (Technology $technology): array => [
                    'name' => (string) $technology->name,
                    'slug' => (string) $technology->slug,
                    'color' => $technology->color,
                ])->values()->all(),
            ];
        })->values()->all();

        return [
            'items' => $items,
            'index_url' => $this->url('site.services.index'),
        ];
    }
}
