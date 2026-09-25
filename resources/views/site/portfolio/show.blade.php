{{--
    One case study — site.portfolio.show (phase-04 §8.11, §2.7, §2.8; requirement §12). A 404 before this view when the item
    is not public or website.portfolio_detail_enabled is false.

    Controller variables (Site\PortfolioController@show):
      $site          the SitePayload, seo = SeoService::for($item)
      $page          array{title, slug}
      $item          App\Models\Cms\PortfolioItem with category, technologies (public) and cover
      $gallery       list<array{asset: MediaAsset, caption: ?string}> — portfolio_item_media in pivot order, cover first
      $previous      ?array{id: int, slug: string}   the previous public item in display order
      $next          ?array{id: int, slug: string}   the next public item
      $related       Collection<PortfolioItem>  public items in the same category (max 3), cover + category loaded
      $lazyImages    optional bool

    The gallery lightbox is Alpine: click to open, Escape to close, arrow keys to move, focus returned on close.
--}}

@extends('site.layouts.public')

@php
    use App\Enums\Cms\ImageProfile;
    use Illuminate\Support\Facades\Route;

    $mediaService = app(\App\Services\Cms\MediaService::class);
    $snapshot = static function ($model, array $relations, ImageProfile $profile) use ($mediaService): ?array {
        if (! $model instanceof \Illuminate\Database\Eloquent\Model) {
            return null;
        }
        foreach ($relations as $relation) {
            if ($model->relationLoaded($relation) && $model->getRelation($relation) instanceof \App\Models\Cms\MediaAsset) {
                return rescue(static fn () => $mediaService->toSnapshot($model->getRelation($relation), $profile), null, false);
            }
        }

        return null;
    };

    $lazy = (bool) ($lazyImages ?? true);
    $category = $item->relationLoaded('category') ? $item->category : null;
    $technologies = $item->relationLoaded('technologies') ? $item->technologies : collect();
    $coverId = (int) ($item->cover_media_id ?? 0);

    // Entries are {asset, caption} (the controller's shape, cover first) or bare MediaAsset rows with a pivot caption.
    $gallery = collect($gallery ?? [])
        ->map(static fn ($entry): array => $entry instanceof \App\Models\Cms\MediaAsset
            ? ['asset' => $entry, 'caption' => $entry->pivot?->caption]
            : ['asset' => data_get($entry, 'asset'), 'caption' => data_get($entry, 'caption')])
        ->filter(static fn (array $entry): bool => $entry['asset'] instanceof \App\Models\Cms\MediaAsset && $entry['asset']->isImage())
        ->sortBy(static fn (array $entry): int => (int) $entry['asset']->getKey() === $coverId ? 0 : 1)
        ->values();

    $slides = $gallery->map(static function (array $entry) use ($mediaService, $item): array {
        $asset = $entry['asset'];
        $media = rescue(static fn () => $mediaService->toSnapshot($asset, ImageProfile::Banner), null, false);
        $thumb = rescue(static fn () => $mediaService->toSnapshot($asset, ImageProfile::Card), null, false);

        return [
            'media' => $media,
            'thumb' => $thumb,
            'alt' => (string) ($asset->alt_text ?: $item->title),
            'caption' => (string) ($entry['caption'] ?? ''),
        ];
    })->filter(static fn (array $slide): bool => is_array($slide['media']))->values();

    if ($slides->isEmpty()) {
        $coverOnly = $snapshot($item, ['coverAsset', 'cover', 'coverMedia'], ImageProfile::Banner);
        if ($coverOnly) {
            $slides = collect([['media' => $coverOnly, 'thumb' => $coverOnly, 'alt' => $item->title, 'caption' => '']]);
        }
    }

    $projectUrl = is_string($item->project_url) && preg_match('~^https?://~i', $item->project_url) === 1 ? $item->project_url : null;
    $related = collect($related ?? []);
@endphp

@section('title', $item->title)

@section('content')
    @include('site.marketing.partials.page-hero', [
        'title' => $item->title,
        'subtitle' => $item->summary,
        'eyebrow' => $category?->name,
        'crumbs' => array_values(array_filter([
            ['label' => 'Portfolio', 'url' => route('site.portfolio.index')],
            $category ? ['label' => $category->name, 'url' => route('site.portfolio.index', ['category' => $category->slug])] : null,
        ])),
    ])

    <x-site.section background="surface">
        <div class="lg:grid lg:grid-cols-12 lg:gap-12">
            <div class="lg:col-span-8">
                @if ($slides->isNotEmpty())
                    <div
                        x-data="{
                            open: false,
                            index: 0,
                            count: {{ $slides->count() }},
                            opener: null,
                            show(i, el) { this.index = i; this.opener = el; this.open = true; this.$nextTick(() => this.$refs.close?.focus()); },
                            hide() { this.open = false; this.$nextTick(() => this.opener?.focus()); },
                            next() { this.index = (this.index + 1) % this.count; },
                            prev() { this.index = (this.index - 1 + this.count) % this.count; },
                        }"
                        x-on:keydown.escape.window="if (open) hide()"
                        x-on:keydown.arrow-right.window="if (open) next()"
                        x-on:keydown.arrow-left.window="if (open) prev()"
                    >
                        <button type="button" data-fx="deck" x-on:click="show(0, $el)" class="group block w-full overflow-hidden rounded-2xl bg-slate-100 shadow-card ring-1 ring-slate-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:bg-slate-800 dark:ring-white/10">
                            <x-site.image :media="$slides[0]['media']" profile="banner" :eager="true" :alt="$slides[0]['alt']" class="h-full w-full transition duration-300 group-hover:scale-[1.01]" />
                            <span class="sr-only">Open the gallery</span>
                        </button>

                        @if ($slides->count() > 1)
                            <ul role="list" class="mt-4 grid grid-cols-3 gap-3 sm:grid-cols-4">
                                @foreach ($slides as $i => $slide)
                                    @continue($i === 0)
                                    <li>
                                        <button type="button" x-on:click="show({{ $i }}, $el)" class="group block w-full overflow-hidden rounded-xl bg-slate-100 ring-1 ring-slate-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:bg-slate-800 dark:ring-white/10">
                                            <x-site.image :media="$slide['thumb'] ?? $slide['media']" profile="card" :lazy="$lazy" :alt="$slide['alt']" ratio="4/3" class="h-full w-full transition duration-300 group-hover:scale-[1.03]" />
                                            <span class="sr-only">Open image {{ $i + 1 }} of {{ $slides->count() }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        {{-- Lightbox --}}
                        <div
                            x-show="open"
                            x-cloak
                            x-transition.opacity
                            class="dark fixed inset-0 z-modal flex flex-col bg-slate-950/95 p-4 sm:p-8"
                            role="dialog"
                            aria-modal="true"
                            aria-label="Gallery of {{ $item->title }}"
                            style="display: none"
                        >
                            <div class="flex items-center justify-between text-sm text-slate-300">
                                <span class="tabular-nums" x-text="(index + 1) + ' / ' + count"></span>
                                <button type="button" x-ref="close" x-on:click="hide()" class="inline-flex h-10 w-10 items-center justify-center rounded-full text-slate-200 hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400">
                                    <x-ui.icon name="x-mark" class="h-6 w-6" />
                                    <span class="sr-only">Close the gallery</span>
                                </button>
                            </div>

                            <div class="relative flex min-h-0 flex-1 items-center justify-center py-4" x-on:click.self="hide()">
                                @foreach ($slides as $i => $slide)
                                    <figure x-show="index === {{ $i }}" class="flex max-h-full w-full max-w-6xl flex-col items-center">
                                        <x-site.image :media="$slide['media']" profile="banner" :alt="$slide['alt']" img-class="max-h-[75vh] w-auto max-w-full rounded-lg object-contain" />
                                        @if ($slide['caption'] !== '')
                                            <figcaption class="mt-3 text-center text-sm text-slate-300">{{ $slide['caption'] }}</figcaption>
                                        @endif
                                    </figure>
                                @endforeach

                                @if ($slides->count() > 1)
                                    <button type="button" x-on:click="prev()" class="absolute left-0 top-1/2 inline-flex h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400">
                                        <x-ui.icon name="chevron-left" class="h-6 w-6" /><span class="sr-only">Previous image</span>
                                    </button>
                                    <button type="button" x-on:click="next()" class="absolute right-0 top-1/2 inline-flex h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400">
                                        <x-ui.icon name="chevron-right" class="h-6 w-6" /><span class="sr-only">Next image</span>
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif

                @if (filled($item->description))
                    <x-site.prose :html="$item->description" size="lg" @class(['mt-12' => $slides->isNotEmpty()]) />
                @endif
            </div>

            <aside class="mt-12 lg:col-span-4 lg:mt-0" aria-label="Project facts">
                <div data-fx="right" class="space-y-6 rounded-2xl border border-slate-200 bg-slate-50 p-6 lg:sticky lg:top-28 dark:border-white/10 dark:bg-white/[0.03]">
                    <dl class="space-y-4 text-sm">
                        @if (filled($item->client_name))
                            <div>
                                <dt class="text-slate-500 dark:text-slate-400">Client</dt>
                                <dd class="mt-0.5 font-semibold text-slate-900 dark:text-white">{{ $item->client_name }}</dd>
                            </div>
                        @endif
                        @if ($category)
                            <div>
                                <dt class="text-slate-500 dark:text-slate-400">Category</dt>
                                <dd class="mt-0.5 font-semibold text-slate-900 dark:text-white">{{ $category->name }}</dd>
                            </div>
                        @endif
                        @if ($item->completion_date)
                            <div>
                                <dt class="text-slate-500 dark:text-slate-400">Completed</dt>
                                <dd class="mt-0.5 font-semibold text-slate-900 dark:text-white"><time datetime="{{ app_date($item->completion_date, 'Y-m-d') }}">{{ app_date($item->completion_date) }}</time></dd>
                            </div>
                        @endif
                        @if ($technologies->isNotEmpty() || filled($item->technologies_note))
                            <div>
                                <dt class="text-slate-500 dark:text-slate-400">Technologies</dt>
                                <dd class="mt-2 flex flex-wrap gap-1.5">
                                    @foreach ($technologies as $technology)
                                        <span class="rounded-md bg-white px-2 py-0.5 text-xs font-medium text-slate-700 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-200 dark:ring-white/10">{{ $technology->name }}</span>
                                    @endforeach
                                    @if (filled($item->technologies_note))
                                        <span class="text-xs text-slate-600 dark:text-slate-300">{{ $item->technologies_note }}</span>
                                    @endif
                                </dd>
                            </div>
                        @endif
                    </dl>

                    @if ($projectUrl)
                        <a href="{{ $projectUrl }}" target="_blank" rel="nofollow noopener noreferrer" class="{{ \App\Enums\Cms\ButtonStyle::Primary->classes() }} w-full">
                            <x-ui.icon name="arrow-top-right-on-square" class="h-4 w-4" />
                            <span>Visit the project</span>
                            <span class="sr-only">(opens in a new tab)</span>
                        </a>
                    @endif

                    @if (Route::has('site.contact.index'))
                        <x-site.button label="Start a similar project" :url="route('site.contact.index', ['type' => 'service'])" style="outline" icon="arrow-right" :block="true" />
                    @endif
                </div>
            </aside>
        </div>

        @php
            // {id, slug} arrays from the controller (or models); a title is shown only when one is present.
            $previousSlug = filled(data_get($previous ?? null, 'slug')) ? (string) data_get($previous, 'slug') : null;
            $nextSlug = filled(data_get($next ?? null, 'slug')) ? (string) data_get($next, 'slug') : null;
        @endphp
        @if ($previousSlug || $nextSlug)
            <nav class="mt-16 grid gap-4 border-t border-slate-200 pt-8 sm:grid-cols-2 dark:border-white/10" aria-label="More projects">
                <div>
                    @if ($previousSlug)
                        <a href="{{ route('site.portfolio.show', $previousSlug) }}" rel="prev" class="group inline-flex flex-col rounded-lg p-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60">
                            <span class="inline-flex items-center gap-1 text-sm font-semibold text-slate-700 group-hover:text-brand-700 dark:text-slate-200 dark:group-hover:text-brand-300"><x-ui.icon name="arrow-left" class="h-4 w-4" /> Previous project</span>
                            @if (filled(data_get($previous, 'title')))
                                <span class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ data_get($previous, 'title') }}</span>
                            @endif
                        </a>
                    @endif
                </div>
                <div class="sm:text-right">
                    @if ($nextSlug)
                        <a href="{{ route('site.portfolio.show', $nextSlug) }}" rel="next" class="group inline-flex flex-col rounded-lg p-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 sm:items-end">
                            <span class="inline-flex items-center gap-1 text-sm font-semibold text-slate-700 group-hover:text-brand-700 dark:text-slate-200 dark:group-hover:text-brand-300">Next project <x-ui.icon name="arrow-right" class="h-4 w-4" /></span>
                            @if (filled(data_get($next, 'title')))
                                <span class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ data_get($next, 'title') }}</span>
                            @endif
                        </a>
                    @endif
                </div>
            </nav>
        @endif
    </x-site.section>

    @if ($related->isNotEmpty())
        <x-site.section background="muted" label="Related projects">
            <x-site.heading title="More work like this" align="left" />
            <ul role="list" class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($related as $relatedItem)
                    @php $relatedCover = $snapshot($relatedItem, ['coverAsset', 'cover', 'coverMedia'], ImageProfile::Card); @endphp
                    <li data-fx="tilt" data-fx-delay="{{ ($loop->index % 4) + 1 }}" class="group relative overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card transition hover:shadow-card-hover dark:border-white/10 dark:bg-slate-900">
                        <div class="aspect-[4/3] overflow-hidden bg-slate-100 dark:bg-slate-800">
                            @if ($relatedCover)
                                <x-site.image :media="$relatedCover" profile="card" :lazy="$lazy" :alt="$relatedItem->title" class="h-full w-full transition duration-300 group-hover:scale-[1.02]" />
                            @endif
                        </div>
                        <div class="p-5">
                            <h3 class="font-semibold text-slate-900 dark:text-white">
                                <a href="{{ route('site.portfolio.show', $relatedItem->slug) }}" class="after:absolute after:inset-0 focus-visible:outline-none">{{ $relatedItem->title }}</a>
                            </h3>
                            @if (filled($relatedItem->client_name))
                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $relatedItem->client_name }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-site.section>
    @endif
@endsection
