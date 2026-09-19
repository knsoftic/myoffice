<?php

declare(strict_types=1);

namespace App\Support\Cms\Sections;

use App\Enums\Cms\ImageProfile;
use App\Models\Cms\PortfolioCategory;
use App\Models\Cms\PortfolioItem;
use App\Models\Cms\Technology;
use App\Support\SettingsRepository;

/**
 * `portfolio` section: published projects with their cover, client name, category, year and technologies.
 * The detail link is omitted while `website.portfolio_detail_enabled` is off (the route 404s then).
 */
final class PortfolioSectionProvider extends MarketingSectionProvider
{
    public function key(): string
    {
        return 'portfolio';
    }

    protected function module(): string
    {
        return 'portfolio';
    }

    protected function build(array $options): array
    {
        $details = filter_var(app(SettingsRepository::class)->get('website.portfolio_detail_enabled', true), FILTER_VALIDATE_BOOLEAN);

        $query = PortfolioItem::query()->public()
            ->with(['cover', 'category', 'technologies' => static fn ($technologies) => $technologies->where('technologies.is_active', true)])
            ->when($options['featured_only'], static fn ($builder) => $builder->where('portfolio_items.is_featured', true))
            ->featuredFirst();

        if ($options['category'] !== null) {
            $query->whereHas('category', static fn ($category) => $category->public()->where('slug', $options['category']));
        }

        $items = $query->limit($options['limit'])->get()->map(fn (PortfolioItem $item): array => [
            'id' => (int) $item->getKey(),
            'title' => (string) $item->title,
            'slug' => (string) $item->slug,
            'url' => $details ? $this->url('site.portfolio.show', ['portfolioItem' => $item->slug]) : null,
            'client_name' => $item->client_name,
            'summary' => $item->summary,
            'cover' => $this->image($item->cover, ImageProfile::Card),
            'completion_date' => $this->date($item->completion_date),
            'is_featured' => (bool) $item->is_featured,
            'category' => $item->category instanceof PortfolioCategory && $item->category->isActive()
                ? ['name' => (string) $item->category->name, 'slug' => (string) $item->category->slug]
                : null,
            'technologies' => $item->technologies->map(static fn (Technology $technology): array => [
                'name' => (string) $technology->name,
                'slug' => (string) $technology->slug,
                'color' => $technology->color,
            ])->values()->all(),
        ])->values()->all();

        return [
            'items' => $items,
            'index_url' => $this->url('site.portfolio.index'),
        ];
    }
}
