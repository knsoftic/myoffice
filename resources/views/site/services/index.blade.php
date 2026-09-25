{{--
    The public service catalogue — site.services.index (phase-04 §8.11, §9.2; requirement §11).

    Controller variables (Site\ServiceController@index):
      $site              the SitePayload of ComposesSite (header, footer, seo = SeoService::for('site.services.index'))
      $services          LengthAwarePaginator<App\Models\Cms\Service> — Service::public() only, featured first then
                         sort_order, per page = website.services_per_page; category, technologies (active only) and the
                         image relation (image) eager-loaded
      $categories        Collection<App\Models\Cms\ServiceCategory> — ServiceCategory::public(), ordered, only those
                         holding at least one public service
      $activeCategory    ?App\Models\Cms\ServiceCategory   from ?category={slug} (unknown or inactive slug = 404)
      $heading, $intro   optional strings (default "Services" and a one-line standfirst)
      $lazyImages        optional bool (website.image_lazy_loading, default true)

    Prices go through money() and only when price_visible; a null price reads "Price on request".
--}}

@extends('site.layouts.public')

@php
    use App\Enums\Cms\ImageProfile;
    use Illuminate\Support\Facades\Route;

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
    $activeCategory = $activeCategory ?? null;
    $lazy = (bool) ($lazyImages ?? true);
    $heading = $heading ?? 'Services';
    $intro = $intro ?? 'What we design, build and look after — and where each engagement starts.';
    $indexUrl = route('site.services.index');
    $contactUrl = Route::has('site.contact.index') ? route('site.contact.index', ['type' => 'service']) : null;
@endphp

@section('title', $activeCategory ? $activeCategory->name.' services' : $heading)

@section('content')
    @include('site.marketing.partials.page-hero', [
        'title' => $activeCategory ? $activeCategory->name : $heading,
        'subtitle' => $activeCategory ? ($activeCategory->description ?: $intro) : $intro,
        'crumbs' => $activeCategory ? [['label' => $heading, 'url' => $indexUrl]] : [],
    ])

    <x-site.section background="surface" padding="tight">
        @if ($categories->isNotEmpty())
            <nav aria-label="Service categories" class="-mx-1 flex flex-wrap gap-2">
                <a
                    href="{{ $indexUrl }}"
                    @if (! $activeCategory) aria-current="page" @endif
                    @class([
                        'inline-flex items-center rounded-full px-4 py-2 text-sm font-medium ring-1 ring-inset transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60',
                        'bg-brand-600 text-white ring-brand-600 dark:bg-brand-500 dark:ring-brand-500' => ! $activeCategory,
                        'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-200 dark:ring-white/10 dark:hover:bg-slate-800' => (bool) $activeCategory,
                    ])
                >All services</a>
                @foreach ($categories as $category)
                    @php $isActive = $activeCategory && (int) $activeCategory->getKey() === (int) $category->getKey(); @endphp
                    <a
                        href="{{ route('site.services.index', ['category' => $category->slug]) }}"
                        @if ($isActive) aria-current="page" @endif
                        @class([
                            'inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-medium ring-1 ring-inset transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60',
                            'bg-brand-600 text-white ring-brand-600 dark:bg-brand-500 dark:ring-brand-500' => $isActive,
                            'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-200 dark:ring-white/10 dark:hover:bg-slate-800' => ! $isActive,
                        ])
                    >
                        @if (filled($category->icon))
                            <x-ui.icon :name="$category->icon" class="h-4 w-4" />
                        @endif
                        {{ $category->name }}
                    </a>
                @endforeach
            </nav>
        @endif

        @if ($services->isEmpty())
            <div class="mx-auto mt-12 max-w-xl rounded-2xl border border-dashed border-slate-300 px-6 py-14 text-center dark:border-white/10">
                <x-ui.icon name="wrench-screwdriver" class="mx-auto h-8 w-8 text-slate-400" />
                <h2 class="mt-4 text-lg font-semibold text-slate-900 dark:text-white">{{ $activeCategory ? 'Nothing in this category yet' : 'Our service catalogue is on its way' }}</h2>
                <p class="mt-2 text-slate-600 dark:text-slate-400">Tell us what you need and we will tell you honestly whether and how we can build it.</p>
                @if ($contactUrl)
                    <div class="mt-6 flex justify-center">
                        <x-site.button label="Tell us about your project" :url="$contactUrl" style="primary" icon="arrow-right" />
                    </div>
                @endif
            </div>
        @else
            <ul role="list" @class(['grid gap-6 sm:grid-cols-2 lg:grid-cols-3', 'mt-10' => $categories->isNotEmpty()])>
                @foreach ($services as $service)
                    @php
                        $media = $snapshot($service, ['imageAsset', 'image', 'imageMedia'], ImageProfile::Card);
                        $technologies = $service->relationLoaded('technologies') ? $service->technologies : collect();
                        $showUrl = route('site.services.show', $service->slug);
                    @endphp
                    <li data-fx="tilt" data-fx-delay="{{ ($loop->index % 4) + 1 }}" class="group relative flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card transition duration-200 hover:-translate-y-0.5 hover:shadow-card-hover focus-within:ring-2 focus-within:ring-brand-500/50 dark:border-white/10 dark:bg-slate-900">
                        @if ($media)
                            <div class="aspect-video overflow-hidden bg-slate-100 dark:bg-slate-800">
                                <x-site.image :media="$media" profile="card" :lazy="$lazy" class="h-full w-full transition duration-300 group-hover:scale-[1.02]" />
                            </div>
                        @endif

                        <div class="flex flex-1 flex-col p-6">
                            <div class="flex items-start justify-between gap-3">
                                @if (! $media)
                                    <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600 ring-1 ring-inset ring-brand-100 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/20">
                                        <x-ui.icon :name="filled($service->icon) ? $service->icon : 'wrench-screwdriver'" class="h-5 w-5" />
                                    </span>
                                @endif
                                @if ($service->is_featured)
                                    <span class="ml-auto inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/25">
                                        <x-ui.icon name="star" class="h-3.5 w-3.5" /> Featured
                                    </span>
                                @endif
                            </div>

                            @if ($service->relationLoaded('category') && $service->category)
                                <p class="mt-4 text-xs font-semibold uppercase tracking-wider text-brand-600 dark:text-brand-400">{{ $service->category->name }}</p>
                            @endif

                            <h2 class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">
                                <a href="{{ $showUrl }}" class="after:absolute after:inset-0 focus-visible:outline-none">{{ $service->name }}</a>
                            </h2>

                            @if (filled($service->short_description))
                                <p class="mt-2 line-clamp-3 text-sm leading-relaxed text-slate-600 dark:text-slate-400">{{ $service->short_description }}</p>
                            @endif

                            @if ($technologies->isNotEmpty())
                                <ul role="list" class="mt-4 flex flex-wrap gap-1.5" aria-label="Technologies">
                                    @foreach ($technologies->take(4) as $technology)
                                        <li class="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-white/5 dark:text-slate-300">{{ $technology->name }}</li>
                                    @endforeach
                                    @if ($technologies->count() > 4)
                                        <li class="rounded-md px-1 py-0.5 text-xs text-slate-500 dark:text-slate-400">+{{ $technologies->count() - 4 }}</li>
                                    @endif
                                </ul>
                            @endif

                            <div class="mt-auto flex items-end justify-between gap-3 pt-6">
                                <p class="text-sm">
                                    @if ($service->price_visible && $service->starting_price !== null)
                                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $service->price_note ?: 'Starting from' }}</span>
                                        <span class="text-base font-semibold tabular-nums text-slate-900 dark:text-white">{{ money((string) $service->starting_price) }}</span>
                                    @else
                                        <span class="text-slate-500 dark:text-slate-400">Price on request</span>
                                    @endif
                                </p>
                                <span class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 group-hover:text-brand-700 dark:text-brand-400 dark:group-hover:text-brand-300" aria-hidden="true">
                                    Learn more <x-ui.icon name="arrow-right" class="h-4 w-4 transition group-hover:translate-x-0.5" />
                                </span>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>

            @include('site.marketing.partials.pagination', ['paginator' => $services, 'label' => 'services'])
        @endif
    </x-site.section>

    @if ($contactUrl && $services->isNotEmpty())
        <x-site.section background="muted" padding="default">
            <div data-fx="rise" class="flex flex-col items-start gap-6 rounded-2xl bg-white p-8 shadow-card ring-1 ring-slate-200 sm:flex-row sm:items-center sm:justify-between dark:bg-slate-900 dark:ring-white/10">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Not sure which service fits?</h2>
                    <p class="mt-2 text-slate-600 dark:text-slate-300">Describe the problem. We will suggest the shortest path to a working product.</p>
                </div>
                <x-site.button label="Start a conversation" :url="$contactUrl" style="primary" icon="arrow-right" size="lg" class="shrink-0" />
            </div>
        </x-site.section>
    @endif
@endsection
