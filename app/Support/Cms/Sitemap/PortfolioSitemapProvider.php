<?php

declare(strict_types=1);

namespace App\Support\Cms\Sitemap;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\SitemapChangeFrequency;
use App\Models\Cms\PortfolioItem;
use Illuminate\Database\Query\Builder;

/**
 * `/portfolio/{slug}` for every published project — none while `website.portfolio_detail_enabled` is off,
 * because the detail route 404s then (phase-04 §5, §7.1).
 */
final class PortfolioSitemapProvider extends EntitySitemapProvider
{
    public function key(): string
    {
        return 'portfolio';
    }

    protected function module(): string
    {
        return 'portfolio';
    }

    protected function table(): string
    {
        return 'portfolio_items';
    }

    protected function morphClass(): ?string
    {
        return (new PortfolioItem)->getMorphClass();
    }

    protected function routeName(): string
    {
        return 'site.portfolio.show';
    }

    protected function routeParameter(): string
    {
        return 'portfolioItem';
    }

    protected function enabled(): bool
    {
        return $this->settingEnabled('website.portfolio_detail_enabled');
    }

    protected function constrain(Builder $query): void
    {
        $query->where('e.status', ContentStatus::Published->value);
    }

    protected function changefreq(): SitemapChangeFrequency
    {
        return SitemapChangeFrequency::Monthly;
    }

    protected function priority(): string
    {
        return '0.7';
    }
}
