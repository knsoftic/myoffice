{{--
    The public events board — site.events.index. A 404 before this view when the events module is
    disabled (site_module middleware) or website.events_page_enabled is off.

    Controller variables (Site\EventController@index, through ComposesContentPages::contentPage()):
      $site      the SitePayload (seo for route_key site.events.index)
      $page      array{title, slug}
      $upcoming  LengthAwarePaginator<App\Models\Cms\Event> — live events that have not finished,
                 soonest first. Page name: `upcoming`.
      $past      LengthAwarePaginator<App\Models\Cms\Event> — live events that have finished, most
                 recent first. Page name: `past`.

    Each event carries its `coverImage` relation eager-loaded — a media_assets row (D24), never a path,
    rendered only through <x-site.image>.

    Two lists, two orders, and that is the point. Somebody arriving here wants the thing they can
    still attend; a single list sorted either way buries one half. Both paginate, under separate page
    names, so paging one does not page the other.

    Three date shapes, decided per row and never guessed:
      · ends_at IS NULL     an announcement — one moment, printed once, never as a range;
      · is_all_day          the date only, because the editor entered no clock;
      · a span on one day   the date once, then the two times.
    Everything goes through app_date() / app_datetime() / app_time(), so the institute's timezone and
    the configured formats decide how it reads.

    capacity is printed as "Limited to N places" and nothing more. No table here records a booking, so
    a number of places left would be a guess printed as a fact.
--}}

@extends('site.layouts.public')

@php
    use App\Enums\Cms\ImageProfile;
    use App\Models\Cms\MediaAsset;

    $mediaService = app(\App\Services\Cms\MediaService::class);

    $coverOf = static function ($event) use ($mediaService): ?array {
        if (! $event->relationLoaded('coverImage')) {
            return null;
        }

        $asset = $event->getRelation('coverImage');

        if (! $asset instanceof MediaAsset || ! $asset->isImage()) {
            return null;
        }

        return rescue(static fn () => $mediaService->toSnapshot($asset, ImageProfile::Card), null, false);
    };

    // When it happens, in one line. An event with no end is a moment, so it prints once.
    $whenOf = static function ($event): string {
        $starts = $event->starts_at;
        $ends = $event->ends_at;

        if ((bool) $event->is_all_day) {
            $from = app_date($starts);

            if ($ends === null) {
                return $from;
            }

            $to = app_date($ends);

            return $from === $to ? $from : $from.' – '.$to;
        }

        $from = app_datetime($starts);

        if ($ends === null) {
            return $from;
        }

        // Same calendar day in the display timezone: the date once, then both times.
        return app_datetime($starts, 'Y-m-d') === app_datetime($ends, 'Y-m-d')
            ? $from.' – '.app_time($ends)
            : $from.' – '.app_datetime($ends);
    };

    // The machine-readable half of <time>. An all-day event has no clock to publish.
    $machineWhenOf = static fn ($event): string => (bool) $event->is_all_day
        ? app_date($event->starts_at, 'Y-m-d')
        : app_datetime($event->starts_at, 'Y-m-d\TH:i');

    $whereOf = static function ($event): ?string {
        $location = trim((string) ($event->location ?? ''));

        if (! (bool) $event->is_online) {
            return $location === '' ? null : $location;
        }

        return $location === '' ? 'Online' : 'Online, and at '.$location;
    };

    $heading = $heading ?? 'Events';
    $intro = $intro ?? 'Workshops, seminars, open days and announcements — what is coming up, and what has already been and gone.';

    $lists = [
        [
            'key' => 'upcoming',
            'label' => 'Upcoming events',
            'background' => 'surface',
            'heading' => 'Coming up',
            'intro' => 'Soonest first. Dates and times are the institute’s local clock.',
            'items' => $upcoming,
            'isPast' => false,
            'emptyIcon' => 'calendar-days',
            'emptyTitle' => 'Nothing is scheduled at the moment',
            'emptyMessage' => 'There is no upcoming event on the calendar right now. New dates are published here as soon as they are confirmed.',
        ],
        [
            'key' => 'past',
            'label' => 'Past events',
            'background' => 'muted',
            'heading' => 'Already happened',
            'intro' => 'Most recent first — what we have run before, kept here for the record.',
            'items' => $past,
            'isPast' => true,
            'emptyIcon' => 'history',
            'emptyTitle' => 'Nothing has been archived yet',
            'emptyMessage' => 'Past events move into this list once their date has passed.',
        ],
    ];
@endphp

@section('title', (string) data_get($page ?? null, 'title', $heading))

@section('content')
    @include('site.marketing.partials.page-hero', [
        'title' => (string) data_get($page ?? null, 'title', $heading),
        'subtitle' => $intro,
    ])

    @if ($upcoming->total() === 0 && $past->total() === 0)
        <x-site.section background="surface" label="Events">
            <h2 data-fx="rise" class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl dark:text-white">
                Nothing published yet
            </h2>
            <p data-fx="rise" data-fx-delay="1" class="mt-3 max-w-2xl text-base leading-relaxed text-slate-600 dark:text-slate-400">
                This page will fill up as soon as the first event is published.
            </p>

            <div class="mt-10 rounded-2xl border border-dashed border-slate-300 bg-white dark:border-white/10 dark:bg-slate-900">
                <x-ui.empty-state
                    icon="calendar-days"
                    level="h3"
                    title="No events have been published yet"
                    message="Workshops, seminars and announcements appear here once they go live. Please check back shortly, or contact the office to ask what is planned."
                />
            </div>
        </x-site.section>
    @else
        @foreach ($lists as $list)
            @continue ($list['isPast'] && $list['items']->total() === 0)

            <x-site.section :background="$list['background']" :label="$list['label']">
                <h2 data-fx="rise" class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl dark:text-white">
                    {{ $list['heading'] }}
                </h2>
                <p data-fx="rise" data-fx-delay="1" class="mt-3 max-w-2xl text-base leading-relaxed text-slate-600 dark:text-slate-400">
                    {{ $list['intro'] }}
                </p>

                @if ($list['items']->isEmpty())
                    <div class="mt-10 rounded-2xl border border-dashed border-slate-300 bg-white dark:border-white/10 dark:bg-slate-900">
                        <x-ui.empty-state
                            :icon="$list['emptyIcon']"
                            level="h3"
                            :title="$list['emptyTitle']"
                            :message="$list['emptyMessage']"
                        />
                    </div>
                @else
                    <ul role="list" class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($list['items'] as $event)
                            @php
                                $cover = $coverOf($event);
                                $where = $whereOf($event);
                                $capacity = (int) ($event->capacity ?? 0);
                                $eventUrl = route('site.events.show', $event->slug);
                            @endphp

                            <li data-fx="tilt" data-fx-delay="{{ ($loop->index % 3) + 1 }}">
                                <article class="flex h-full flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card transition hover:shadow-lg dark:border-white/10 dark:bg-slate-900">
                                    @if ($cover)
                                        <a href="{{ $eventUrl }}" tabindex="-1" aria-hidden="true" class="block overflow-hidden bg-slate-100 dark:bg-slate-800">
                                            <x-site.image :media="$cover" profile="card" ratio="16/9" :alt="$event->title" class="h-full w-full" />
                                        </a>
                                    @endif

                                    <div class="flex flex-1 flex-col p-5 sm:p-6">
                                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                            <p class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 dark:text-brand-300">
                                                <x-ui.icon name="calendar-days" class="h-4 w-4 shrink-0" />
                                                <time datetime="{{ $machineWhenOf($event) }}">{{ $whenOf($event) }}</time>
                                            </p>

                                            @if (! $list['isPast'] && (bool) $event->is_featured)
                                                <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/20">Featured</span>
                                            @endif
                                        </div>

                                        <h3 class="mt-3 text-lg font-semibold leading-snug tracking-tight text-slate-900 dark:text-white">
                                            <a href="{{ $eventUrl }}" class="rounded transition hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:hover:text-brand-300">{{ $event->title }}</a>
                                        </h3>

                                        @if (filled($event->summary))
                                            <p class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-400">{{ $event->summary }}</p>
                                        @endif

                                        {{-- A <div> inside a <dl> may hold only <dt> and <dd>, so the icon sits in the <dd>. --}}
                                        <dl class="mt-4 space-y-2 text-sm text-slate-500 dark:text-slate-400">
                                            @if (filled($where))
                                                <div>
                                                    <dt class="sr-only">Where</dt>
                                                    <dd class="flex items-start gap-2">
                                                        <x-ui.icon :name="(bool) $event->is_online ? 'video-camera' : 'building-office'" class="mt-0.5 h-4 w-4 shrink-0" />
                                                        <span class="min-w-0">{{ $where }}</span>
                                                    </dd>
                                                </div>
                                            @endif

                                            @if ($capacity > 0)
                                                <div>
                                                    <dt class="sr-only">Places</dt>
                                                    {{-- A capacity, never a seats-left figure. --}}
                                                    <dd class="flex items-start gap-2">
                                                        <x-ui.icon name="users" class="mt-0.5 h-4 w-4 shrink-0" />
                                                        <span class="min-w-0">Limited to {{ app_number($capacity) }} places</span>
                                                    </dd>
                                                </div>
                                            @endif
                                        </dl>

                                        <p class="mt-5 pt-1">
                                            <a href="{{ $eventUrl }}" class="inline-flex items-center gap-1.5 rounded text-sm font-semibold text-brand-700 transition hover:gap-2.5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-brand-300">
                                                <span>{{ $list['isPast'] ? 'What happened' : 'Event details' }}</span>
                                                <x-ui.icon name="arrow-right" class="h-4 w-4" />
                                                <span class="sr-only">— {{ $event->title }}</span>
                                            </a>
                                        </p>
                                    </div>
                                </article>
                            </li>
                        @endforeach
                    </ul>

                    @if ($list['items']->hasPages())
                        <nav class="mt-10" aria-label="{{ $list['label'] }} pagination">
                            {{ $list['items']->links() }}
                        </nav>
                    @endif
                @endif
            </x-site.section>
        @endforeach
    @endif
@endsection
