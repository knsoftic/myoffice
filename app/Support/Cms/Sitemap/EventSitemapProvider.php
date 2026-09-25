<?php

declare(strict_types=1);

namespace App\Support\Cms\Sitemap;

use App\Enums\Cms\ContentStatus;
use App\Models\Cms\Event;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * `/events/{slug}` for every event that is published **and** whose `published_at` has passed.
 *
 * **A past event stays in the sitemap, and that is deliberate.** The date it happened on is content:
 * somebody searching for last year's open day should find the page that describes it, and dropping
 * the URL the week after the event turns a page that earned links into a 404. What expires is the
 * event's place in the *upcoming* list on the site, not its existence.
 *
 * **`published_at` must be non-null and past, which is stricter than the page itself needs.** The
 * asymmetry is on purpose: a sitemap that lists a URL answering 404 is a cost paid on every crawl,
 * while a public page missing from the sitemap is found by the crawler anyway, one link later. When
 * the two rules can disagree, the sitemap takes the conservative side. It also matches
 * `BlogPostSitemapProvider` exactly, so the two content types cannot drift into different ideas of
 * what "live" means.
 *
 * `lastmod` is the later of `published_at` and `updated_at`, which `EntitySitemapProvider` works out.
 */
final class EventSitemapProvider extends EntitySitemapProvider
{
    public function key(): string
    {
        return 'events';
    }

    protected function module(): string
    {
        return 'events';
    }

    protected function table(): string
    {
        return 'events';
    }

    protected function morphClass(): ?string
    {
        return (new Event)->getMorphClass();
    }

    protected function routeName(): string
    {
        return 'site.events.show';
    }

    protected function routeParameter(): string
    {
        return 'event';
    }

    protected function lastmodColumn(): ?string
    {
        return 'published_at';
    }

    protected function constrain(Builder $query): void
    {
        $query->where('e.status', ContentStatus::Published->value)
            ->whereNotNull('e.published_at')
            ->where('e.published_at', '<=', Carbon::now());
    }

    protected function priority(): string
    {
        // Below a blog post's 0.7: an event page is worth indexing and is rarely the page somebody
        // was looking for when they searched for the institute.
        return '0.6';
    }
}
