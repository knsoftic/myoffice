<?php

declare(strict_types=1);

namespace App\Support\Cms\Sitemap;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\SitemapChangeFrequency;
use App\Services\Cms\Data\SitemapEntry;
use App\Support\Modules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * `/team` — the one team URL (a member has no page of its own, phase-04 §2.9). Listed only while the
 * `team` module and `website.team_page_enabled` are on and at least one member is public; `lastmod` is
 * the newest public member change. The page's own SEO is the `site.team.index` route-key row, which
 * Phase 3's generator may list as well — the generator de-duplicates by location.
 */
final class TeamSitemapProvider
{
    public function key(): string
    {
        return 'team';
    }

    /**
     * @return iterable<int, SitemapEntry>
     */
    public function urls(): iterable
    {
        try {
            if (! Modules::enabled('team') || ! Route::has('site.team.index')) {
                return [];
            }

            $enabled = filter_var(setting('website.team_page_enabled', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

            if (! $enabled) {
                return [];
            }

            $latest = DB::table('team_members')
                ->whereNull('deleted_at')
                ->where('status', ContentStatus::Published->value)
                ->where('is_public', true)
                ->max('updated_at');

            if ($latest === null) {
                return [];
            }

            return [new SitemapEntry(
                loc: route('site.team.index'),
                lastmod: Carbon::parse((string) $latest, 'UTC'),
                changefreq: SitemapChangeFrequency::Monthly,
                priority: '0.5',
                provider: $this->key(),
            )];
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }
}
