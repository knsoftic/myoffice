<?php

declare(strict_types=1);

namespace App\Support\Cms\Sitemap;

use App\Enums\Cms\SitemapChangeFrequency;
use App\Enums\JobOpeningStatus;
use App\Models\Cms\JobOpening;
use Illuminate\Database\Query\Builder;

/**
 * `/careers/{slug}` for every opening that is `open` with no deadline or a deadline of today or later
 * (phase-04 §9.2) — none while `website.careers_enabled` is off.
 */
final class JobOpeningSitemapProvider extends EntitySitemapProvider
{
    public function key(): string
    {
        return 'jobs';
    }

    protected function module(): string
    {
        return 'jobs';
    }

    protected function table(): string
    {
        return 'job_openings';
    }

    protected function morphClass(): ?string
    {
        return (new JobOpening)->getMorphClass();
    }

    protected function routeName(): string
    {
        return 'site.careers.show';
    }

    protected function routeParameter(): string
    {
        return 'jobOpening';
    }

    protected function enabled(): bool
    {
        return $this->settingEnabled('website.careers_enabled');
    }

    protected function constrain(Builder $query): void
    {
        $today = $this->today();

        $query->where('e.status', JobOpeningStatus::Open->value)
            ->where(static function ($deadline) use ($today): void {
                $deadline->whereNull('e.deadline')->orWhere('e.deadline', '>=', $today);
            });
    }

    protected function changefreq(): SitemapChangeFrequency
    {
        return SitemapChangeFrequency::Daily;
    }

    protected function priority(): string
    {
        return '0.6';
    }
}
