{{--
    Section type `team` — declared by Phase 4 (phase-04 §8.11, §2.9, §9.2; requirement §13): a compact team strip for the
    home or about page, linking to /team.

    Receives:
      $section   the published snapshot (id, anchor, provider)
      $content   heading, description, view_all_link (optional; defaults to the team page when it is enabled)
      $section['provider']  from Phase 4's TeamSectionProvider — ONLY TeamMember::public() rows (published AND is_public), in
                 display order, as plain arrays:
                   items: list<array{id: int, name: string, slug: string, designation: string, department: ?string, bio: ?string,
                          skills: list<string>, experience_years: ?int, experience_label: ?string, photo: ?array,
                          portfolio_url: ?string, social_links: list<array{platform, label, icon, url}>}>
                   index_url: ?string   route('site.team.index') when the team page is enabled, else null
                 (App\Support\Cms\Sections\TeamSectionProvider)

    Renders nothing without a public member.
--}}

@php
    $fields = (array) ($content ?? []);
    $provider = data_get($section ?? null, 'provider');
    $members = collect(is_array(data_get($provider, 'items')) ? data_get($provider, 'items') : (is_array($provider) && array_is_list($provider) ? $provider : []))
        ->filter(static fn ($member): bool => filled(data_get($member, 'name')))
        ->take(8)
        ->values();

    $heading = trim((string) data_get($fields, 'heading', ''));
    $heading = $heading !== '' ? $heading : 'Meet the team';
    $description = trim((string) data_get($fields, 'description', ''));
    $viewAll = data_get($fields, 'view_all_link');
    $teamUrl = data_get($provider, 'index_url') ?? data_get($provider, 'url');
    $teamUrl = is_string($teamUrl) && preg_match('~^(https?://|/)~i', $teamUrl) === 1 ? $teamUrl : null;
@endphp

@if ($members->isNotEmpty())
    <x-site.section :anchor="data_get($section ?? null, 'anchor')" background="surface" :label="$heading">
        <div class="flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
            <x-site.heading data-fx="rise" :title="$heading" :subtitle="$description !== '' ? $description : null" align="left" />

            @if (is_array($viewAll) && filled($viewAll['label'] ?? null) && filled($viewAll['url'] ?? null))
                <x-site.button data-fx="rise" data-fx-delay="1" :link="$viewAll" icon="arrow-right" class="shrink-0" />
            @elseif ($teamUrl)
                <x-site.button data-fx="rise" data-fx-delay="1" label="Meet everyone" :url="$teamUrl" style="outline" icon="arrow-right" class="shrink-0" />
            @endif
        </div>

        <ul role="list" class="mt-12 grid grid-cols-2 gap-6 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($members as $member)
                @php
                    $photo = data_get($member, 'photo');
                    $memberUrl = $teamUrl && filled(data_get($member, 'slug')) ? $teamUrl.'#'.data_get($member, 'slug') : null;
                @endphp
                <li data-fx="rise" data-fx-delay="{{ ($loop->index % 4) + 1 }}" class="group relative text-center">
                    <div class="mx-auto aspect-square w-full max-w-[12rem] overflow-hidden rounded-2xl bg-slate-100 dark:bg-slate-800">
                        @if (filled(data_get($photo, 'url')))
                            <x-site.image :media="$photo" profile="thumbnail" :alt="data_get($member, 'name')" ratio="1/1" class="h-full w-full transition duration-300 group-hover:scale-[1.03]" />
                        @else
                            <span class="flex h-full w-full items-center justify-center text-4xl font-semibold text-slate-400 dark:text-slate-500" aria-hidden="true">{{ \Illuminate\Support\Str::upper(mb_substr((string) data_get($member, 'name'), 0, 1)) }}</span>
                        @endif
                    </div>
                    <h3 class="mt-4 font-semibold text-slate-900 dark:text-white">
                        @if ($memberUrl)
                            <a href="{{ $memberUrl }}" class="after:absolute after:inset-0 focus-visible:outline-none">{{ data_get($member, 'name') }}</a>
                        @else
                            {{ data_get($member, 'name') }}
                        @endif
                    </h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ data_get($member, 'designation') }}</p>
                </li>
            @endforeach
        </ul>
    </x-site.section>
@endif
