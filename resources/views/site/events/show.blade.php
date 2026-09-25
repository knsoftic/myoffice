{{--
    One event — site.events.show. A 404 before this view when the events module is disabled, when
    website.events_page_enabled is off, or when the row is a draft, a scheduled event whose moment has
    not arrived, or an archived one: the controller re-runs the listing's own query against the bound
    slug and returns the branded 404 rather than rendering.

    Controller variables (Site\EventController@show, through ComposesContentPages::contentPage()):
      $site         the SitePayload, seo = SeoService::for($event)
      $page         array{title, slug}
      $event        App\Models\Cms\Event — live only
      $cover        ?App\Models\Cms\MediaAsset  the eager-loaded `coverImage` relation (D24: a media
                    row, never a path), rendered only through <x-site.image>
      $description  string  RichText::sanitize($event->description) — sanitised again by
                    <x-site.prose> here, which is the only sanctioned raw echo on the public site (D25)
      $hasEnded     bool    the same boundary the listing splits upcoming from past on

    The date line has three shapes and never invents the missing one: an event with no ends_at is an
    announcement and prints as a single moment; an all-day event prints its date with no clock; a span
    inside one day prints the date once and then both times. All of it goes through app_date() /
    app_datetime() / app_time(), so the institute's timezone and the configured formats decide.

    capacity reads "Limited to N places" and never becomes places remaining — nothing in this schema
    records a booking, so a seats-left figure would be a guess printed as a fact.

    data-fx sits on the cover only. The body is what somebody came to read, and fading it in as they
    scroll fights the reader.
--}}

@extends('site.layouts.public')

@php
    use App\Enums\Cms\ImageProfile;
    use App\Models\Cms\MediaAsset;

    $mediaService = app(\App\Services\Cms\MediaService::class);

    $coverSnapshot = $cover instanceof MediaAsset && $cover->isImage()
        ? rescue(static fn () => $mediaService->toSnapshot($cover, ImageProfile::Banner), null, false)
        : null;

    $hasEnded = (bool) ($hasEnded ?? false);
    $isAllDay = (bool) $event->is_all_day;
    $isOnline = (bool) $event->is_online;
    $starts = $event->starts_at;
    $ends = $event->ends_at;
    $body = (string) ($description ?? '');

    // An event with no end is a moment, not a span, and prints once.
    if ($isAllDay) {
        $whenLine = $ends === null || app_date($starts) === app_date($ends)
            ? app_date($starts)
            : app_date($starts).' – '.app_date($ends);
    } elseif ($ends === null) {
        $whenLine = app_datetime($starts);
    } elseif (app_datetime($starts, 'Y-m-d') === app_datetime($ends, 'Y-m-d')) {
        // One calendar day in the display timezone: the date once, then both times.
        $whenLine = app_datetime($starts).' – '.app_time($ends);
    } else {
        $whenLine = app_datetime($starts).' – '.app_datetime($ends);
    }

    $machineWhen = $isAllDay ? app_date($starts, 'Y-m-d') : app_datetime($starts, 'Y-m-d\TH:i');

    $location = trim((string) ($event->location ?? ''));
    $whereLine = $isOnline
        ? ($location === '' ? 'Online' : 'Online, and at '.$location)
        : ($location === '' ? null : $location);

    $capacity = (int) ($event->capacity ?? 0);

    // A stored URL is only ever rendered as an href when it is http(s). Anything else — a scheme a
    // row acquired by hand, an empty string a form let through — is dropped rather than printed into
    // an attribute the browser will follow.
    $externalUrl = static function (mixed $value): ?string {
        $url = trim((string) ($value ?? ''));

        return $url !== '' && preg_match('~^https?://~i', $url) === 1 ? $url : null;
    };

    $registrationUrl = $hasEnded ? null : $externalUrl($event->registration_url ?? null);
    $meetingUrl = $hasEnded ? null : $externalUrl($event->meeting_url ?? null);
@endphp

@section('title', $event->title)

@section('content')
    @include('site.marketing.partials.page-hero', [
        'title' => $event->title,
        'subtitle' => $event->summary,
        'eyebrow' => $hasEnded ? 'Past event' : ($ends === null ? 'Announcement' : 'Event'),
        'crumbs' => [['label' => 'Events', 'url' => route('site.events.index')]],
    ])

    @if ($coverSnapshot)
        <div class="mx-auto -mb-4 mt-10 max-w-5xl px-4 sm:px-6 lg:px-8">
            <div data-fx="deck" class="overflow-hidden rounded-2xl bg-slate-100 shadow-card ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-white/10">
                <x-site.image :media="$coverSnapshot" profile="banner" :eager="true" :alt="$event->title" class="h-full w-full" />
            </div>
        </div>
    @endif

    <article class="mx-auto max-w-3xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
        {{-- A <div> inside a <dl> may hold only <dt> and <dd>, so each icon lives inside its own term. --}}
        <dl class="grid gap-5 rounded-2xl border border-slate-200 bg-slate-50 p-5 sm:grid-cols-2 sm:p-6 dark:border-white/10 dark:bg-slate-900/40">
            <div class="min-w-0">
                <dt class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                    <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white ring-1 ring-inset ring-slate-200 dark:bg-slate-800 dark:ring-white/10" aria-hidden="true">
                        <x-ui.icon name="calendar-days" class="h-4 w-4" />
                    </span>
                    <span>When</span>
                </dt>
                <dd class="mt-2 text-sm font-medium text-slate-900 dark:text-white">
                    <time datetime="{{ $machineWhen }}">{{ $whenLine }}</time>
                    @if ($isAllDay)
                        <span class="ml-1.5 text-xs font-normal text-slate-500 dark:text-slate-400">All day</span>
                    @endif
                </dd>
            </div>

            @if (filled($whereLine))
                <div class="min-w-0">
                    <dt class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white ring-1 ring-inset ring-slate-200 dark:bg-slate-800 dark:ring-white/10" aria-hidden="true">
                            <x-ui.icon :name="$isOnline ? 'video-camera' : 'building-office'" class="h-4 w-4" />
                        </span>
                        <span>Where</span>
                    </dt>
                    <dd class="mt-2 text-sm font-medium text-slate-900 dark:text-white">{{ $whereLine }}</dd>
                </div>
            @endif

            @if ($capacity > 0)
                <div class="min-w-0">
                    <dt class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white ring-1 ring-inset ring-slate-200 dark:bg-slate-800 dark:ring-white/10" aria-hidden="true">
                            <x-ui.icon name="users" class="h-4 w-4" />
                        </span>
                        <span>Places</span>
                    </dt>
                    {{-- A capacity, never a seats-left figure: no table here records a booking. --}}
                    <dd class="mt-2 text-sm font-medium text-slate-900 dark:text-white">Limited to {{ app_number($capacity) }} places</dd>
                </div>
            @endif
        </dl>

        @if ($hasEnded)
            <p class="mt-6 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600 dark:border-white/10 dark:bg-slate-900 dark:text-slate-300">
                This event has already taken place. Anything coming up is on the
                <a href="{{ route('site.events.index') }}" class="font-semibold text-brand-700 underline underline-offset-2 dark:text-brand-300">events page</a>.
            </p>
        @elseif ($registrationUrl !== null || $meetingUrl !== null)
            <div class="mt-6 flex flex-wrap items-center gap-3">
                @if ($registrationUrl !== null)
                    <a href="{{ $registrationUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:bg-brand-500 dark:hover:bg-brand-400">
                        <span>Register for this event</span>
                        <x-ui.icon name="arrow-top-right-on-square" class="h-4 w-4" />
                    </a>
                @endif

                @if ($meetingUrl !== null)
                    <a href="{{ $meetingUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-200 transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-slate-200 dark:ring-white/10 dark:hover:bg-white/10">
                        <span>Join online</span>
                        <x-ui.icon name="video-camera" class="h-4 w-4" />
                    </a>
                @endif
            </div>
        @endif

        @if (filled($body))
            {{-- Deliberately no data-fx: somebody arrived to read this. --}}
            <div class="mt-10">
                <x-site.prose :html="$body" size="lg" />
            </div>
        @endif

        <p class="mt-12 border-t border-slate-200 pt-8 dark:border-white/10">
            <a href="{{ route('site.events.index') }}" class="inline-flex items-center gap-1.5 rounded text-sm font-semibold text-brand-700 transition hover:gap-2.5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-brand-300">
                <x-ui.icon name="arrow-left" class="h-4 w-4" />
                <span>All events</span>
            </a>
        </p>
    </article>
@endsection
