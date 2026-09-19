{{--
    Section type `portfolio` — declared by Phase 4 (phase-04 §8.11, §9.2; requirement §12): recent or featured case studies.

    Receives:
      $section   the published snapshot (id, anchor, provider)
      $content   heading, description, view_all_link (optional)
      $section['provider']  App\Support\Cms\Sections\PortfolioSectionProvider — ONLY PortfolioItem::public() rows, featured
                 first, as plain arrays:
                   available: bool
                   items: list<array{id: int, title: string, slug: string, url: ?string (null when detail pages are off),
                          client_name: ?string, summary: ?string, cover: ?array (card media array), completion_date: ?string
                          ('Y-m-d'), is_featured: bool, category: ?array{name, slug}, technologies: list<array{name, slug, color}>}>
                   index_url: ?string

    Renders nothing at all while there is no public project.
--}}

@php
    $fields = (array) ($content ?? []);
    $provider = data_get($section ?? null, 'provider');
    $cards = collect(is_array(data_get($provider, 'items')) ? data_get($provider, 'items') : [])
        ->filter(static fn ($card): bool => filled(data_get($card, 'title')))
        ->take(12)
        ->values();

    $heading = trim((string) data_get($fields, 'heading', ''));
    $heading = $heading !== '' ? $heading : 'Our work';
    $description = trim((string) data_get($fields, 'description', ''));
    $viewAll = data_get($fields, 'view_all_link');
    $safeUrl = static fn ($url): ?string => is_string($url) && preg_match('~^(https?://|/)~i', trim($url)) === 1 ? trim($url) : null;
    $indexUrl = $safeUrl(data_get($provider, 'index_url'));
@endphp

@if ($cards->isNotEmpty())
    <x-site.section :anchor="data_get($section ?? null, 'anchor')" background="surface" :label="$heading">
        <div class="flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
            <x-site.heading :title="$heading" :subtitle="$description !== '' ? $description : null" align="left" />
            @if (is_array($viewAll) && filled($viewAll['label'] ?? null) && filled($viewAll['url'] ?? null))
                <x-site.button :link="$viewAll" icon="arrow-right" class="shrink-0" />
            @elseif ($indexUrl)
                <x-site.button label="See all work" :url="$indexUrl" style="outline" icon="arrow-right" class="shrink-0" />
            @endif
        </div>

        <ul role="list" class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($cards as $card)
                @php
                    $cardUrl = $safeUrl(data_get($card, 'url'));
                    $cover = data_get($card, 'cover');
                    $meta = collect([
                        data_get($card, 'category.name'),
                        filled(data_get($card, 'completion_date')) ? app_date(data_get($card, 'completion_date'), 'Y') : null,
                    ])->filter()->implode(' · ');
                @endphp
                <li class="group relative overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card transition duration-200 hover:-translate-y-0.5 hover:shadow-card-hover focus-within:ring-2 focus-within:ring-brand-500/50 dark:border-white/10 dark:bg-slate-900">
                    <div class="aspect-[4/3] overflow-hidden bg-slate-100 dark:bg-slate-800">
                        @if (filled(data_get($cover, 'url')))
                            <x-site.image :media="$cover" profile="card" :alt="data_get($card, 'title')" class="h-full w-full transition duration-300 group-hover:scale-[1.02]" />
                        @endif
                    </div>
                    <div class="p-5">
                        @if ($meta !== '')
                            <p class="text-xs font-semibold uppercase tracking-wider text-brand-600 dark:text-brand-400">{{ $meta }}</p>
                        @endif
                        <h3 class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">
                            @if ($cardUrl)
                                <a href="{{ $cardUrl }}" class="after:absolute after:inset-0 focus-visible:outline-none">{{ data_get($card, 'title') }}</a>
                            @else
                                {{ data_get($card, 'title') }}
                            @endif
                        </h3>
                        @if (filled(data_get($card, 'client_name')))
                            <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-300">{{ data_get($card, 'client_name') }}</p>
                        @endif
                        @if (filled(data_get($card, 'summary')))
                            <p class="mt-2 line-clamp-2 text-sm text-slate-600 dark:text-slate-400">{{ data_get($card, 'summary') }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </x-site.section>
@endif
