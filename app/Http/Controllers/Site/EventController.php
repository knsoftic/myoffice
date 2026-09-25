<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Concerns\ComposesContentPages;
use App\Http\Controllers\Site\Concerns\ComposesSite;
use App\Http\Controllers\Site\Concerns\RendersPages;
use App\Models\Cms\Event;
use App\Support\DateRange;
use App\Support\RichText;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The public events board — `site.events.index` and `site.events.show`, behind
 * `site_module:events` and `website.events_page_enabled`.
 *
 * It is the blog's shape (a published-only paginated index plus a slug-bound detail page) with four
 * differences that are the whole reason the page exists:
 *
 *   · **Only a live event renders anywhere here.** "Live" is `Event::public()` — the model's own scope,
 *     identical to `BlogPost::public()`: `published`, a `published_at` that is set, and a `published_at`
 *     that is not in the future. `show` re-runs that query against the bound row, so a draft, a
 *     scheduled row whose moment has not arrived and an archived one are all a 404 rather than a page,
 *     exactly as `BlogController::show()` treats a post.
 *   · **The listing is two lists, not one.** Upcoming ascending (soonest first — the one somebody can
 *     still attend), past descending (most recent first), through the model's `soonestFirst()` and
 *     `mostRecentFirst()` so the public board and the admin list agree on an order. Both are paginated:
 *     events accumulate for ever.
 *   · **`is_all_day` moves the boundary between the two lists.** An all-day event stores `starts_at` at
 *     midnight, so comparing it against `now()` drops today's event into "past" at 00:01. See
 *     {@see window()} — this is the one place the page deliberately does not call `Event::upcoming()`.
 *   · **`ends_at IS NULL` is an announcement** — a moment, not a span — and nothing here invents an end
 *     for it. The views render it as a single date, never as a range with a missing half.
 *
 * `capacity` is never turned into seats remaining: no table in this schema records a booking, so a
 * number of places left would be a guess printed as a fact. The views say "Limited to N places".
 */
final class EventController extends Controller
{
    use ComposesContentPages;
    use ComposesSite;
    use RendersPages;

    /**
     * Per page, per list. `website.events_per_page` is not a registered setting today; `setting()`
     * hands back the default for a key it does not know, so this reads as 9 now and honours the key
     * the day the registry declares it — without this controller changing.
     */
    private const PER_PAGE = 9;

    public function index(): Response
    {
        if (! $this->siteFlag('website.events_page_enabled', false)) {
            return $this->notFound();
        }

        $perPage = $this->sitePerPage('website.events_per_page', self::PER_PAGE);

        // Two paginators on one page need two page names, or paging either list pages both.
        $upcoming = $this->window($this->liveQuery(), upcoming: true)
            ->soonestFirst()
            ->paginate($perPage, ['*'], 'upcoming')
            ->withQueryString();

        $past = $this->window($this->liveQuery(), upcoming: false)
            ->mostRecentFirst()
            ->paginate($perPage, ['*'], 'past')
            ->withQueryString();

        return $this->contentPage('site.events.index', [
            'upcoming' => $upcoming,
            'past' => $past,
        ], $this->routeSeo('site.events.index'), 'site-events', ['title' => 'Events', 'slug' => 'events']);
    }

    public function show(Event $event): Response
    {
        if (! $this->siteFlag('website.events_page_enabled', false)) {
            return $this->notFound();
        }

        // The binding found the row by slug; whether it may be seen is decided here, by the same
        // `public()` scope the listing runs. A draft, a scheduled row and an archived one fall out.
        $live = $this->liveQuery()->whereKey($event->getKey())->first();

        if (! $live instanceof Event) {
            return $this->notFound();
        }

        $path = route('site.events.show', ['event' => $live->slug], false);

        return $this->contentPage('site.events.show', [
            'event' => $live,
            'cover' => $live->relationLoaded('coverImage') ? $live->coverImage : null,
            // Sanitised again on render by <x-site.prose>; the database is not a trust boundary (D25).
            'description' => RichText::sanitize((string) $live->description),
            'hasEnded' => $this->hasEnded($live),
        ], $this->modelSeo($live, $path), 'site-event site-event-'.$live->slug, ['title' => $live->title, 'slug' => 'events/'.$live->slug]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Events a visitor may see, with the cover image a card renders.
     *
     * "Published" is the model's `public()` scope and never a `where('status', ...)` written here
     * (`ComposesContentPages`, §9.2), so one definition serves this board, the sitemap and every later
     * reader. `coverImage` is the declared `media_assets` relation (D24) — the page never reads a path.
     *
     * @return Builder<Event>
     */
    private function liveQuery(): Builder
    {
        return $this->eagerPublic(Event::query()->public(), ['coverImage']);
    }

    /**
     * Narrow a query to the events that have not finished yet, or to the ones that have.
     *
     * This is `Event::upcoming()` / `Event::past()` — the same two branches, `ends_at` when there is
     * one and `starts_at` when there is not — **plus** the one thing those scopes do not carry: an
     * all-day event is a calendar day, not a midnight. Its `starts_at` is stored at 00:00, so measured
     * against `now()` an all-day event dated today falls into "past" at one minute past midnight, on
     * the morning of the day it is supposed to be advertised. All-day rows are therefore measured
     * against the **start of today** in the institute's timezone, timed rows against this instant.
     *
     * Written as branches rather than `COALESCE(ends_at, starts_at)` for the reason the model gives:
     * a function wrapped around the column cannot use `idx_events_starts`. `ends_at >= ?` and
     * `ends_at < ?` are both false for a NULL end, so the two directions are exact complements and
     * every live event lands in exactly one of the two lists.
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    private function window(Builder $query, bool $upcoming): Builder
    {
        $now = CarbonImmutable::now();
        $dayStart = $this->businessDayStart();

        return $query->where(function (Builder $outer) use ($upcoming, $now, $dayStart): void {
            $outer
                ->where(function (Builder $timed) use ($upcoming, $now): void {
                    $timed->where('is_all_day', false);

                    $this->side($timed, $now, $upcoming);
                })
                ->orWhere(function (Builder $allDay) use ($upcoming, $dayStart): void {
                    $allDay->where('is_all_day', true);

                    $this->side($allDay, $dayStart, $upcoming);
                });
        });
    }

    /**
     * "Not over as of `$cutoff`", or its exact negation — `ends_at` when the event has an end, and
     * `starts_at` when it is an announcement with none.
     *
     * @param  Builder<Event>  $query
     */
    private function side(Builder $query, CarbonImmutable $cutoff, bool $upcoming): void
    {
        $operator = $upcoming ? '>=' : '<';

        $query->where(static function (Builder $inner) use ($cutoff, $operator): void {
            $inner->where('ends_at', $operator, $cutoff)
                ->orWhere(static function (Builder $moment) use ($cutoff, $operator): void {
                    $moment->whereNull('ends_at')->where('starts_at', $operator, $cutoff);
                });
        });
    }

    /**
     * Has this event finished? The same boundary {@see window()} splits the two lists on, for the
     * detail page — which has no list to tell it which side it is on. `Event::hasEnded()` measures
     * `effectiveEnd()` against `now()`, which is right for a timed event and a midnight too early for
     * an all-day one, so the all-day case is measured against the start of today here as well.
     *
     * A finished event still renders. It simply stops offering a registration link and a way to join a
     * session that is over.
     */
    private function hasEnded(Event $event): bool
    {
        $end = $event->effectiveEnd();

        if ($end === null) {
            return false;
        }

        return $event->is_all_day
            ? $end->lessThan($this->businessDayStart())
            : $end->lessThan(CarbonImmutable::now());
    }

    /**
     * Midnight of today in the institute's timezone, as an instant in the application's — the moment an
     * all-day event dated today is still "today" from, and the one a row dated yesterday is behind.
     */
    private function businessDayStart(): CarbonImmutable
    {
        $timezone = (string) config('app.timezone', 'UTC');

        try {
            return DateRange::today()->start->setTimezone($timezone);
        } catch (Throwable) {
            return CarbonImmutable::now()->startOfDay();
        }
    }
}
