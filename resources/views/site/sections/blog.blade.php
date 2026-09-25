{{--
    Section type `blog` — the blog teaser, declared by Phase 4 (phase-04 §8.11, §9.2; requirement §15).

    Receives:
      $section   the published snapshot (id, anchor, provider)
      $content   heading, description, view_all_link (optional)
      $section['provider']  App\Support\Cms\Sections\BlogSectionProvider (`is_live`: a scheduled post appears once the scheduler
                 publishes it) — ONLY BlogPost::public() rows, newest first:
                   available: bool
                   items: list<array{id: int, title: string, slug: string, url: string, excerpt: ?string, published_at: ?string
                          (ISO-8601), reading_minutes: ?int, image: ?array (card media array), image_alt: ?string,
                          author: ?string, category: ?array{name, slug, url}}>
                   index_url: ?string

    Renders nothing without a public post.
--}}

@php
    $fields = (array) ($content ?? []);
    $provider = data_get($section ?? null, 'provider');
    $posts = collect(is_array(data_get($provider, 'items')) ? data_get($provider, 'items') : [])
        ->filter(static fn ($post): bool => filled(data_get($post, 'title')))
        ->take(6)
        ->values();

    $heading = trim((string) data_get($fields, 'heading', ''));
    $heading = $heading !== '' ? $heading : 'From the blog';
    $description = trim((string) data_get($fields, 'description', ''));
    $viewAll = data_get($fields, 'view_all_link');
    $safeUrl = static fn ($url): ?string => is_string($url) && preg_match('~^(https?://|/)~i', trim($url)) === 1 ? trim($url) : null;
    $blogUrl = $safeUrl(data_get($provider, 'index_url'));
@endphp

@if ($posts->isNotEmpty())
    <x-site.section :anchor="data_get($section ?? null, 'anchor')" background="muted" :label="$heading">
        <div class="flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
            <x-site.heading data-fx="rise" :title="$heading" :subtitle="$description !== '' ? $description : null" align="left" />
            @if (is_array($viewAll) && filled($viewAll['label'] ?? null) && filled($viewAll['url'] ?? null))
                <x-site.button data-fx="rise" data-fx-delay="1" :link="$viewAll" icon="arrow-right" class="shrink-0" />
            @elseif ($blogUrl)
                <x-site.button data-fx="rise" data-fx-delay="1" label="All posts" :url="$blogUrl" style="outline" icon="arrow-right" class="shrink-0" />
            @endif
        </div>

        <ul role="list" class="mt-12 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            @foreach ($posts as $post)
                @php
                    $postUrl = $safeUrl(data_get($post, 'url'));
                    $image = data_get($post, 'image');
                    if (is_array($image) && filled(data_get($post, 'image_alt'))) {
                        $image['alt'] = (string) data_get($post, 'image_alt');
                    }
                    $categoryUrl = $safeUrl(data_get($post, 'category.url'));
                @endphp
                <li data-fx="tilt" data-fx-delay="{{ ($loop->index % 3) + 1 }}" class="group relative flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card transition duration-200 hover:-translate-y-0.5 hover:shadow-card-hover focus-within:ring-2 focus-within:ring-brand-500/50 dark:border-white/10 dark:bg-slate-900">
                    <div class="aspect-video overflow-hidden bg-slate-100 dark:bg-slate-800">
                        @if (filled(data_get($image, 'url')))
                            <x-site.image :media="$image" profile="card" :alt="data_get($post, 'title')" class="h-full w-full transition duration-300 group-hover:scale-[1.02]" />
                        @endif
                    </div>
                    <div class="flex flex-1 flex-col p-6">
                        <p class="flex flex-wrap items-center gap-x-2 text-xs text-slate-500 dark:text-slate-400">
                            @if (filled(data_get($post, 'category.name')))
                                @if ($categoryUrl)
                                    <a href="{{ $categoryUrl }}" class="relative z-10 font-semibold uppercase tracking-wider text-brand-600 hover:text-brand-700 dark:text-brand-400">{{ data_get($post, 'category.name') }}</a>
                                @else
                                    <span class="font-semibold uppercase tracking-wider text-brand-600 dark:text-brand-400">{{ data_get($post, 'category.name') }}</span>
                                @endif
                            @endif
                            @if (filled(data_get($post, 'published_at')))
                                <time datetime="{{ data_get($post, 'published_at') }}">{{ app_date(data_get($post, 'published_at')) }}</time>
                            @endif
                        </p>
                        <h3 class="mt-1.5 text-lg font-semibold leading-snug text-slate-900 dark:text-white">
                            @if ($postUrl)
                                <a href="{{ $postUrl }}" class="after:absolute after:inset-0 focus-visible:outline-none">{{ data_get($post, 'title') }}</a>
                            @else
                                {{ data_get($post, 'title') }}
                            @endif
                        </h3>
                        @if (filled(data_get($post, 'excerpt')))
                            <p class="mt-2 line-clamp-3 text-sm leading-relaxed text-slate-600 dark:text-slate-400">{{ data_get($post, 'excerpt') }}</p>
                        @endif
                        <p class="mt-auto pt-4 text-xs text-slate-500 dark:text-slate-400">
                            {{ collect([data_get($post, 'author'), (int) data_get($post, 'reading_minutes', 0) > 0 ? app_number((int) data_get($post, 'reading_minutes')).' min read' : null])->filter()->implode(' · ') }}
                        </p>
                    </div>
                </li>
            @endforeach
        </ul>
    </x-site.section>
@endif
