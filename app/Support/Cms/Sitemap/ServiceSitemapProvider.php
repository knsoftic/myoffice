<?php

declare(strict_types=1);

namespace App\Support\Cms\Sitemap;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\SitemapChangeFrequency;
use App\Models\Cms\Service;
use Illuminate\Database\Query\Builder;

/**
 * `/services/{slug}` for every published service (phase-04 §9.2 `Service::scopePublic()`).
 */
final class ServiceSitemapProvider extends EntitySitemapProvider
{
    public function key(): string
    {
        return 'services';
    }

    protected function module(): string
    {
        return 'services';
    }

    protected function table(): string
    {
        return 'services';
    }

    protected function morphClass(): ?string
    {
        return (new Service)->getMorphClass();
    }

    protected function routeName(): string
    {
        return 'site.services.show';
    }

    protected function routeParameter(): string
    {
        return 'service';
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
        return '0.8';
    }
}
