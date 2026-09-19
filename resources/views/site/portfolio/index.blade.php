{{--
    The public portfolio — site.portfolio.index (phase-04 §8.11, §9.2; requirement §12).

    Controller variables (Site\PortfolioController@index):
      $site                the SitePayload (seo for route_key site.portfolio.index)
      $items               LengthAwarePaginator<App\Models\Cms\PortfolioItem> — PortfolioItem::public(), featured first then
                           sort_order, per page = website.portfolio_per_page; category and cover eager-loaded
      $categories          Collection<PortfolioCategory>  public, holding at least one public item
      $technologies        Collection<Technology>         public, used by at least one public item
      $activeCategory      ?PortfolioCategory  (?category={slug})
      $activeTechnology    ?Technology         (?technology={slug})
      $detailEnabled       bool   website.portfolio_detail_enabled — false: cards link to nothing
      $heading, $intro     optional strings
      $lazyImages          optional bool
--}}

@extends('site.layouts.public')

@php
    use App\Enums\Cms\ImageProfile;

    $snapshot = static function ($model, array $relations, ImageProfile $profile): ?array {
        if (! $model instanceof \Illuminate\Database\Eloquent\Model) {
            return null;
        }
        foreach ($relations as $relation) {
            if ($model->relationLoaded($relation) && $model->getRelation($relation) instanceof \App\Models\Cms\MediaAsset) {
                return rescue(static fn () => app(\App\Services\Cms\MediaService::class)->toSnapshot($model->getRelation($relation), $profile), null, false);
            }
        }

        return null;
    };

    $categories = collect($categories ?? []);
    $technologies = collect($technologies ?? []);
    $activeCategory = $activeCategory ?? null;
    $activeTechnology = $activeTechnology ?? null;
    $detailEnabled = (bool) ($detailEnabled ?? true);
    $lazy = (bool) ($lazyImages ?? true);
    $heading = $heading ?? 'Portfolio';
    $intro = $intro ?? 'A selection of products we have designed, built and shipped.';
    $filterUrl = static fn (array $query): string => route('site.portfolio.index', array_filter($query, static fn ($value) => filled($value)));
    $chip = 'inline-flex items-center rounded-full px-4 py-2 text-sm font-medium ring-1 ring-inset transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60';
    $chipOn = 'bg-brand-600 text-white ring-brand-600 dark:bg-brand-500 dark:ring-brand-500';
    $chipOff = 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-200 dark:ring-white/10 dark:hover:bg-slate-800';
@endphp

@section('title', $activeCategory ? $activeCategory->name.' projects' : $heading)

@section('content')
    @include('site.marketing.partials.page-hero', [
        'title' => $activeCategory ? $activeCategory->name : $heading,
        'subtitle' => $activeCategory ? ($activeCategory->description ?: $intro) : $intro,
        'crumbs' => $activeCategory ? [['label' => $heading, 'url' => route('site.portfolio.index')]] : [],
    ])

    <x-site.section background="surface" padding="tight">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            @if ($categories->isNotEmpty())
                <nav aria-label="Portfolio categories" class="flex flex-wrap gap-2">
                    <a href="{{ $filterUrl(['technology' => $activeTechnology?->slug]) }}" @if (! $activeCategory) aria-current="page" @endif class="{{ $chip }} {{ $activeCategory ? $chipOff : $chipOn }}">All work</a>
                    @foreach ($categories as $category)
                        @php $isActive = $activeCategory && (int) $activeCategory->getKey() === (int) $category->getKey(); @endphp
                        <a href="{{ $filterUrl(['category' => $category->slug, 'technology' => $activeTechnology?->slug]) }}" @if ($isActive) aria-current="page" @endif class="{{ $chip }} {{ $isActive ? $chipOn : $chipOff }}">{{ $category->name }}</a>
                    @endforeach
                </nav>
            @endif

            @if ($technologies->isNotEmpty())
                <form method="GET" action="{{ route('site.portfolio.index') }}" class="flex items-center gap-2" x-data>
                    @if ($activeCategory)
                        <input type="hidden" name="category" value="{{ $activeCategory->slug }}">
                    @endif
                    <label for="portfolio-technology" class="text-sm text-slate-600 dark:text-slate-400">Technology</label>
                    <select id="portfolio-technology" name="technology" x-on:change="$el.form.submit()" class="rounded-lg border-slate-300 py-2 pl-3 pr-9 text-sm text-slate-700 shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-white/10 dark:bg-slate-900 dark:text-slate-200">
                        <option value="">Any</option>
                        @foreach ($technologies as $technology)
                            <option value="{{ $technology->slug }}" @selected($activeTechnology && (int) $activeTechnology->getKey() === (int) $technology->getKey())>{{ $technology->name }}</option>
                        @endforeach
                    </select>
                    <noscript><button type="submit" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white dark:bg-white dark:text-slate-900">Filter</button></noscript>
                </form>
            @endif
        </div>

        @if ($items->isEmpty())
            <div class="mx-auto mt-12 max-w-xl rounded-2xl border border-dashed border-slate-300 px-6 py-14 text-center dark:border-white/10">
                <x-ui.icon name="photo" class="mx-auto h-8 w-8 text-slate-400" />
                <h2 class="mt-4 text-lg font-semibold text-slate-900 dark:text-white">{{ $activeCategory || $activeTechnology ? 'No projects match that filter yet' : 'Case studies are coming soon' }}</h2>
                @if ($activeCategory || $activeTechnology)
                    <p class="mt-2"><a href="{{ route('site.portfolio.index') }}" class="font-semibold text-brand-600 hover:underline dark:text-brand-400">See all work</a></p>
                @endif
            </div>
        @else
            <ul role="list" class="mt-10 columns-1 gap-6 sm:columns-2 lg:columns-3 [&>li]:mb-6">
                @foreach ($items as $item)
                    @php
                        $cover = $snapshot($item, ['coverAsset', 'cover', 'coverMedia'], ImageProfile::Card);
                        $url = $detailEnabled ? route('site.portfolio.show', $item->slug) : null;
                        $category = $item->relationLoaded('category') ? $item->category : null;
                    @endphp
                    <li class="group relative break-inside-avoid overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card transition duration-200 hover:shadow-card-hover focus-within:ring-2 focus-within:ring-brand-500/50 dark:border-white/10 dark:bg-slate-900">
                        @if ($cover)
                            <div class="overflow-hidden bg-slate-100 dark:bg-slate-800">
                                <x-site.image :media="$cover" profile="card" :lazy="$lazy" :alt="$item->title" img-class="h-auto w-full object-cover transition duration-300 group-hover:scale-[1.02]" />
                            </div>
                        @endif
                        <div class="p-5">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-medium text-slate-500 dark:text-slate-400">
                                @if ($category)
                                    <span class="font-semibold uppercase tracking-wider text-brand-600 dark:text-brand-400">{{ $category->name }}</span>
                                @endif
                                @if ($item->completion_date)
                                    <span>{{ app_date($item->completion_date, 'Y') }}</span>
                                @endif
                                @if ($item->is_featured)
                                    <span class="inline-flex items-center gap-1 text-amber-600 dark:text-amber-400"><x-ui.icon name="star" class="h-3.5 w-3.5" /> Featured</span>
                                @endif
                            </div>
                            <h2 class="mt-1.5 text-lg font-semibold text-slate-900 dark:text-white">
                                @if ($url)
                                    <a href="{{ $url }}" class="after:absolute after:inset-0 focus-visible:outline-none">{{ $item->title }}</a>
                                @else
                                    {{ $item->title }}
                                @endif
                            </h2>
                            @if (filled($item->client_name))
                                <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-300">{{ $item->client_name }}</p>
                            @endif
                            @if (filled($item->summary))
                                <p class="mt-2 line-clamp-3 text-sm leading-relaxed text-slate-600 dark:text-slate-400">{{ $item->summary }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>

            @include('site.marketing.partials.pagination', ['paginator' => $items, 'label' => 'projects'])
        @endif
    </x-site.section>
@endsection
