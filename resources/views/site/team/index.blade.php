{{--
    The public team page — site.team.index (phase-04 §8.11, §2.9, §9.2; requirement §13). A 404 before this view when
    website.team_page_enabled is false or the `team` module is disabled.

    Controller variables (Site\TeamController@index, through ComposesContentPages::contentPage()):
      $site        the SitePayload (seo for route_key site.team.index)
      $page        array{title, slug}
      $members     Collection<App\Models\Cms\TeamMember> — TeamMember::public() only (status = published AND
                   is_public = true), ordered by sort_order; photo eager-loaded
      $groups      array<string, list<TeamMember>>  department label => members; the '' key (no department) is last
      $heading     optional string (default "Our team")
      $intro       optional string
      $lazyImages  optional bool

    Members are grouped by `department` (the snapshot label); members without one are listed last. Each card's anchor is
    the member's slug, so /team#ayesha-khan scrolls to it. The bio opens in an accessible Alpine dialog.
--}}

@extends('site.layouts.public')

@php
    use App\Enums\Cms\ImageProfile;

    $mediaService = app(\App\Services\Cms\MediaService::class);
    $photoOf = static function ($member) use ($mediaService): ?array {
        foreach (['photoAsset', 'photo', 'photoMedia'] as $relation) {
            if ($member->relationLoaded($relation) && $member->getRelation($relation) instanceof \App\Models\Cms\MediaAsset) {
                return rescue(static fn () => $mediaService->toSnapshot($member->getRelation($relation), ImageProfile::Thumbnail), null, false);
            }
        }

        return null;
    };

    $members = collect($members ?? []);
    $groups = isset($groups) && is_array($groups)
        ? collect($groups)->map(static fn ($list) => collect($list))
        : $members->groupBy(static fn ($member): string => trim((string) $member->department))
            ->sortKeysUsing(static fn (string $a, string $b): int => ($a === '') <=> ($b === ''));
    $lazy = (bool) ($lazyImages ?? true);
    $heading = $heading ?? 'Our team';
    $intro = $intro ?? 'The people who design, build and support your product.';
    $safeUrl = static fn ($url): ?string => is_string($url) && preg_match('~^https?://~i', $url) === 1 ? $url : null;
@endphp

@section('title', $heading)

@section('content')
    @include('site.marketing.partials.page-hero', ['title' => $heading, 'subtitle' => $intro])

    <x-site.section background="surface">
        @if ($members->isEmpty())
            <div class="mx-auto max-w-xl rounded-2xl border border-dashed border-slate-300 px-6 py-14 text-center dark:border-white/10">
                <x-ui.icon name="user-group" class="mx-auto h-8 w-8 text-slate-400" />
                <h2 class="mt-4 text-lg font-semibold text-slate-900 dark:text-white">Team profiles are being written</h2>
                <p class="mt-2 text-slate-600 dark:text-slate-400">Check back soon to meet the people behind the work.</p>
            </div>
        @else
            <div
                class="space-y-16"
                x-data="{ member: null, opener: null, open(data, el) { this.member = data; this.opener = el; this.$nextTick(() => this.$refs.closeBio?.focus()); }, close() { this.member = null; this.$nextTick(() => this.opener?.focus()); } }"
                x-on:keydown.escape.window="if (member) close()"
            >
                @foreach ($groups as $department => $departmentMembers)
                    <section aria-labelledby="team-group-{{ $loop->index }}">
                        <h2 id="team-group-{{ $loop->index }}" class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">{{ $department !== '' ? $department : ($groups->count() > 1 ? 'Also on the team' : 'The team') }}</h2>

                        <ul role="list" class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            @foreach ($departmentMembers as $member)
                                @php
                                    $photo = $photoOf($member);
                                    $skills = collect((array) ($member->skills ?? []))->filter()->values();
                                    $links = collect((array) ($member->social_links ?? []))
                                        ->map(fn ($url) => $safeUrl($url))
                                        ->filter();
                                    $experience = filled($member->experience_label)
                                        ? $member->experience_label
                                        : ($member->experience_years !== null ? app_number((int) $member->experience_years).' '.((int) $member->experience_years === 1 ? 'year' : 'years').' of experience' : null);
                                    $portfolio = $safeUrl($member->portfolio_url);
                                @endphp
                                <li id="{{ $member->slug }}" class="scroll-mt-28">
                                    <article class="flex h-full flex-col rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-card dark:border-white/10 dark:bg-slate-900">
                                        <div class="mx-auto h-28 w-28 overflow-hidden rounded-full bg-slate-100 ring-4 ring-white dark:bg-slate-800 dark:ring-slate-900">
                                            @if ($photo)
                                                <x-site.image :media="$photo" profile="thumbnail" :lazy="$lazy" :alt="$member->name" ratio="1/1" class="h-full w-full" />
                                            @else
                                                <span class="flex h-full w-full items-center justify-center text-2xl font-semibold text-slate-400 dark:text-slate-500" aria-hidden="true">{{ \Illuminate\Support\Str::upper(mb_substr((string) $member->name, 0, 1)) }}</span>
                                            @endif
                                        </div>

                                        <h3 class="mt-5 text-lg font-semibold text-slate-900 dark:text-white">{{ $member->name }}</h3>
                                        <p class="text-sm font-medium text-brand-600 dark:text-brand-400">{{ $member->designation }}</p>
                                        @if ($experience)
                                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $experience }}</p>
                                        @endif

                                        @if ($skills->isNotEmpty())
                                            <ul role="list" class="mt-4 flex flex-wrap justify-center gap-1.5" aria-label="Skills">
                                                @foreach ($skills->take(3) as $skill)
                                                    <li class="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-white/5 dark:text-slate-300">{{ $skill }}</li>
                                                @endforeach
                                                @if ($skills->count() > 3)
                                                    <li class="px-1 text-xs text-slate-500 dark:text-slate-400">+{{ $skills->count() - 3 }}</li>
                                                @endif
                                            </ul>
                                        @endif

                                        <div class="mt-auto pt-5">
                                            @if ($links->isNotEmpty() || $portfolio)
                                                <ul role="list" class="flex flex-wrap items-center justify-center gap-1.5">
                                                    @foreach ($links as $platform => $url)
                                                        <li>
                                                            <a href="{{ $url }}" target="_blank" rel="nofollow noopener noreferrer" class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 ring-1 ring-inset ring-slate-200 transition hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-slate-400 dark:ring-white/10 dark:hover:bg-white/10 dark:hover:text-white">
                                                                @include('site.marketing.partials.social-glyph', ['platform' => $platform, 'class' => 'h-4 w-4'])
                                                                <span class="sr-only">{{ $member->name }} on {{ \Illuminate\Support\Str::headline((string) $platform) }} (opens in a new tab)</span>
                                                            </a>
                                                        </li>
                                                    @endforeach
                                                    @if ($portfolio)
                                                        <li>
                                                            <a href="{{ $portfolio }}" target="_blank" rel="nofollow noopener noreferrer" class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 ring-1 ring-inset ring-slate-200 transition hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-slate-400 dark:ring-white/10 dark:hover:bg-white/10 dark:hover:text-white">
                                                                <x-ui.icon name="briefcase" class="h-4 w-4" />
                                                                <span class="sr-only">Portfolio of {{ $member->name }} (opens in a new tab)</span>
                                                            </a>
                                                        </li>
                                                    @endif
                                                </ul>
                                            @endif

                                            @if (filled($member->bio))
                                                <button
                                                    type="button"
                                                    x-on:click="open({ name: @js((string) $member->name), designation: @js((string) $member->designation), bio: @js((string) $member->bio), skills: @js($skills->all()) }, $el)"
                                                    class="mt-4 inline-flex items-center gap-1 rounded text-sm font-semibold text-brand-600 hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-brand-400 dark:hover:text-brand-300"
                                                >Read bio <x-ui.icon name="arrow-right" class="h-4 w-4" /></button>
                                            @endif
                                        </div>
                                    </article>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach

                {{-- Bio dialog --}}
                <div x-show="member" x-cloak class="fixed inset-0 z-modal flex items-end justify-center p-4 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="team-bio-title" style="display: none">
                    <div class="fixed inset-0 bg-slate-950/60 backdrop-blur-sm" x-on:click="close()" aria-hidden="true"></div>
                    <div class="relative max-h-[85vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-modal ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-white/10" x-trap.noscroll="member">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h2 id="team-bio-title" class="text-xl font-bold text-slate-900 dark:text-white" x-text="member?.name"></h2>
                                <p class="text-sm font-medium text-brand-600 dark:text-brand-400" x-text="member?.designation"></p>
                            </div>
                            <button type="button" x-ref="closeBio" x-on:click="close()" class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-slate-400 dark:hover:bg-white/10 dark:hover:text-white">
                                <x-ui.icon name="x-mark" class="h-5 w-5" /><span class="sr-only">Close</span>
                            </button>
                        </div>
                        <p class="mt-4 whitespace-pre-line text-base leading-relaxed text-slate-600 dark:text-slate-300" x-text="member?.bio"></p>
                        <ul role="list" class="mt-5 flex flex-wrap gap-1.5" x-show="member?.skills?.length">
                            <template x-for="skill in (member?.skills || [])" :key="skill">
                                <li class="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-white/5 dark:text-slate-300" x-text="skill"></li>
                            </template>
                        </ul>
                    </div>
                </div>
            </div>
        @endif
    </x-site.section>
@endsection
