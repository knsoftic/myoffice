{{--
    The public trainers page — site.trainers.index. A 404 before this view when
    website.trainers_page_enabled is false.

    Controller variables (Site\TrainerController@index, through ComposesContentPages::contentPage()):
      $site       the SitePayload (seo for route_key site.trainers.index)
      $page       array{title, slug}
      $trainers   Collection<App\Models\Institute\Teacher> — Teacher::public() AND Teacher::teaching()
                  (is_public + slug, status = active), ordered by sort_order then name
      $heading    optional string (default "Our trainers")
      $intro      optional string

    **Only public columns reach this view.** The controller selects id, slug, name, specialization,
    qualification, experience_years, experience_note, skills, public_bio, social_links and sort_order —
    so phone, whatsapp, email, salary, joining_date, gender, notes and the internal `bio` are not even
    loaded, and no edit to this file can print one. `photo_path` is deliberately absent too: it is a
    private-disk path with no public serving route, so a trainer is drawn with their initials.

    Each card's anchor is the trainer's slug, so /trainers#ayesha-khan scrolls to it. The public bio
    opens in an accessible Alpine dialog, the same pattern the team page uses.
--}}

@extends('site.layouts.public')

@php
    $trainers = collect($trainers ?? []);

    $heading = $heading ?? 'Our trainers';
    $intro = $intro ?? 'The people who teach our courses — what they specialise in, and what they have done before.';

    $safeUrl = static fn ($url): ?string => is_string($url) && preg_match('~^https?://~i', $url) === 1 ? $url : null;
@endphp

@section('title', $heading)

@section('content')
    @include('site.marketing.partials.page-hero', ['title' => $heading, 'subtitle' => $intro])

    <x-site.section background="surface">
        @if ($trainers->isEmpty())
            <div class="mx-auto max-w-xl rounded-2xl border border-dashed border-slate-300 dark:border-white/10">
                <x-ui.empty-state
                    icon="academic-cap"
                    title="Trainer profiles are on their way"
                    message="Our teaching staff are being added to the site. Ask us about a course and we will tell you who runs it."
                />
            </div>
        @else
            <div
                x-data="{ trainer: null, opener: null, open(data, el) { this.trainer = data; this.opener = el; this.$nextTick(() => this.$refs.closeBio?.focus()); }, close() { this.trainer = null; this.$nextTick(() => this.opener?.focus()); } }"
                x-on:keydown.escape.window="if (trainer) close()"
            >
                <h2 data-fx="rise" class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Meet the team that teaches</h2>
                <p data-fx="rise" data-fx-delay="1" class="mt-2 max-w-2xl text-slate-600 dark:text-slate-400">
                    {{ $trainers->count() }} {{ \Illuminate\Support\Str::plural('trainer', $trainers->count()) }} currently taking classes.
                </p>

                <ul role="list" class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach ($trainers as $trainer)
                        @php
                            $skills = collect((array) ($trainer->skills ?? []))->filter()->values();
                            $links = collect((array) ($trainer->social_links ?? []))
                                ->map(fn ($url) => $safeUrl($url))
                                ->filter();
                            $experience = filled($trainer->experience_note)
                                ? $trainer->experience_note
                                : ($trainer->experience_years !== null
                                    ? app_number((int) $trainer->experience_years).' '.((int) $trainer->experience_years === 1 ? 'year' : 'years').' of experience'
                                    : null);
                        @endphp
                        <li id="{{ $trainer->slug }}" class="scroll-mt-28">
                            <article data-fx="tilt" data-fx-delay="{{ ($loop->index % 4) + 1 }}" class="flex h-full flex-col rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-card transition duration-200 hover:-translate-y-0.5 hover:shadow-card-hover dark:border-white/10 dark:bg-slate-900">
                                <span class="mx-auto flex h-24 w-24 items-center justify-center rounded-full bg-brand-50 text-2xl font-semibold uppercase tracking-tight text-brand-700 ring-4 ring-white dark:bg-brand-500/10 dark:text-brand-300 dark:ring-slate-900" aria-hidden="true">
                                    {{ $trainer->initials() }}
                                </span>

                                <h3 class="mt-5 text-lg font-semibold text-slate-900 dark:text-white">{{ $trainer->name }}</h3>

                                @if (filled($trainer->specialization))
                                    <p class="text-sm font-medium text-brand-600 dark:text-brand-400">{{ $trainer->specialization }}</p>
                                @endif

                                @if (filled($trainer->qualification))
                                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $trainer->qualification }}</p>
                                @endif

                                @if (filled($experience))
                                    <p class="mt-2 inline-flex items-center justify-center gap-1 self-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600 dark:bg-white/5 dark:text-slate-300">
                                        <x-ui.icon name="briefcase" class="h-3.5 w-3.5" />
                                        {{ $experience }}
                                    </p>
                                @endif

                                @if ($skills->isNotEmpty())
                                    <ul role="list" class="mt-4 flex flex-wrap justify-center gap-1.5" aria-label="What {{ $trainer->name }} teaches">
                                        @foreach ($skills->take(3) as $skill)
                                            <li class="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-white/5 dark:text-slate-300">{{ $skill }}</li>
                                        @endforeach
                                        @if ($skills->count() > 3)
                                            <li class="px-1 text-xs text-slate-500 dark:text-slate-400">+{{ $skills->count() - 3 }}</li>
                                        @endif
                                    </ul>
                                @endif

                                <div class="mt-auto pt-5">
                                    @if ($links->isNotEmpty())
                                        <ul role="list" class="flex flex-wrap items-center justify-center gap-1.5">
                                            @foreach ($links as $platform => $url)
                                                <li>
                                                    <a href="{{ $url }}" target="_blank" rel="nofollow noopener noreferrer" class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 ring-1 ring-inset ring-slate-200 transition hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-slate-400 dark:ring-white/10 dark:hover:bg-white/10 dark:hover:text-white">
                                                        @include('site.marketing.partials.social-glyph', ['platform' => $platform, 'class' => 'h-4 w-4'])
                                                        <span class="sr-only">{{ $trainer->name }} on {{ \Illuminate\Support\Str::headline((string) $platform) }} (opens in a new tab)</span>
                                                    </a>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    @if (filled($trainer->public_bio))
                                        <button
                                            type="button"
                                            x-on:click="open({ name: @js((string) $trainer->name), specialization: @js((string) $trainer->specialization), bio: @js((string) $trainer->public_bio), skills: @js($skills->all()) }, $el)"
                                            class="mt-4 inline-flex items-center gap-1 rounded text-sm font-semibold text-brand-600 hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-brand-400 dark:hover:text-brand-300"
                                        >Read profile <x-ui.icon name="arrow-right" class="h-4 w-4" /></button>
                                    @endif
                                </div>
                            </article>
                        </li>
                    @endforeach
                </ul>

                {{-- Profile dialog --}}
                <div x-show="trainer" x-cloak class="fixed inset-0 z-modal flex items-end justify-center p-4 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="trainer-bio-title" style="display: none">
                    <div class="fixed inset-0 bg-slate-950/60 backdrop-blur-sm" x-on:click="close()" aria-hidden="true"></div>
                    <div class="relative max-h-[85vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-modal ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-white/10" x-trap.noscroll="trainer">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h2 id="trainer-bio-title" class="text-xl font-bold text-slate-900 dark:text-white" x-text="trainer?.name"></h2>
                                <p class="text-sm font-medium text-brand-600 dark:text-brand-400" x-text="trainer?.specialization"></p>
                            </div>
                            <button type="button" x-ref="closeBio" x-on:click="close()" class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-slate-400 dark:hover:bg-white/10 dark:hover:text-white">
                                <x-ui.icon name="x-mark" class="h-5 w-5" /><span class="sr-only">Close</span>
                            </button>
                        </div>
                        <p class="mt-4 whitespace-pre-line text-base leading-relaxed text-slate-600 dark:text-slate-300" x-text="trainer?.bio"></p>
                        <ul role="list" class="mt-5 flex flex-wrap gap-1.5" x-show="trainer?.skills?.length">
                            <template x-for="skill in (trainer?.skills || [])" :key="skill">
                                <li class="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-white/5 dark:text-slate-300" x-text="skill"></li>
                            </template>
                        </ul>
                    </div>
                </div>
            </div>
        @endif
    </x-site.section>
@endsection
