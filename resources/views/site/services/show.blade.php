{{--
    One service — site.services.show (phase-04 §8.11, §9.2; requirement §11). A draft service is a 404 before this view
    is reached (Service::public() in the controller, never here).

    Controller variables (Site\ServiceController@show, through ComposesContentPages::contentPage()):
      $site                    the SitePayload, seo = SeoService::for($service) (falls back to seo.* settings)
      $page                    array{title, slug}
      $service                 App\Models\Cms\Service with category, technologies (public) and image; starting_price nulled
                               on the instance when price_visible is false
      $relatedServices         Collection<Service>        other public services in the same category (max 3), image loaded
      $relatedPortfolio        Collection<PortfolioItem>  public items sharing its technologies (max 3), cover + category loaded
      $portfolioDetailEnabled  bool     website.portfolio_detail_enabled
      $inquiryUrl              ?string  /contact?type=service&service={id}
      $lazyImages              optional bool

    The inquiry CTA links to /contact?type=service&service={id}, which pre-selects this service in the form.
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

    $lazy = (bool) ($lazyImages ?? true);
    $image = $snapshot($service, ['imageAsset', 'image', 'imageMedia'], ImageProfile::Banner);
    $category = $service->relationLoaded('category') ? $service->category : null;
    $technologies = $service->relationLoaded('technologies') ? $service->technologies : collect();
    $features = collect((array) ($service->features ?? []))->filter(fn ($f) => is_string($f) && trim($f) !== '')->values();
    $relatedServices = collect($relatedServices ?? []);
    $relatedPortfolio = collect($relatedPortfolio ?? []);
    $portfolioDetail = (bool) ($portfolioDetailEnabled ?? true);
    $contactUrl = $inquiryUrl ?? (Route::has('site.contact.index') ? route('site.contact.index', ['type' => 'service', 'service' => $service->getKey()]) : null);
    $hasPrice = $service->price_visible && $service->starting_price !== null;
@endphp

@section('title', $service->name)

@section('content')
    @include('site.marketing.partials.page-hero', [
        'title' => $service->name,
        'subtitle' => $service->short_description,
        'eyebrow' => $category?->name,
        'crumbs' => array_values(array_filter([
            ['label' => 'Services', 'url' => route('site.services.index')],
            $category ? ['label' => $category->name, 'url' => route('site.services.index', ['category' => $category->slug])] : null,
        ])),
    ])

    <x-site.section background="surface">
        <div class="lg:grid lg:grid-cols-12 lg:gap-12">
            <article class="lg:col-span-8">
                @if ($image)
                    <div data-fx="deck" class="mb-10 overflow-hidden rounded-2xl bg-slate-100 shadow-card ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-white/10">
                        <x-site.image :media="$image" profile="banner" :eager="true" :alt="$service->name" class="h-full w-full" />
                    </div>
                @endif

                @if (filled($service->full_description))
                    <x-site.prose :html="$service->full_description" size="lg" />
                @endif

                @if ($features->isNotEmpty())
                    <section class="mt-12" aria-labelledby="service-features">
                        <h2 id="service-features" class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">What is included</h2>
                        <ul role="list" class="mt-6 grid gap-3 sm:grid-cols-2">
                            @foreach ($features as $feature)
                                <li class="flex items-start gap-3 rounded-xl bg-slate-50 p-4 text-slate-700 ring-1 ring-inset ring-slate-200 dark:bg-white/[0.03] dark:text-slate-200 dark:ring-white/10">
                                    <x-ui.icon name="check-circle" class="mt-0.5 h-5 w-5 shrink-0 text-emerald-500" />
                                    <span>{{ $feature }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($technologies->isNotEmpty())
                    <section class="mt-12" aria-labelledby="service-technologies">
                        <h2 id="service-technologies" class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Technologies we use</h2>
                        <ul role="list" class="mt-6 flex flex-wrap gap-2">
                            @foreach ($technologies as $technology)
                                @php $colour = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $technology->color) === 1 ? $technology->color : null; @endphp
                                <li class="inline-flex items-center gap-2 rounded-full bg-white px-3.5 py-1.5 text-sm font-medium text-slate-700 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-200 dark:ring-white/10">
                                    @if ($colour)
                                        <span class="h-2.5 w-2.5 rounded-full" style="background-color: {{ $colour }}" aria-hidden="true"></span>
                                    @endif
                                    {{ $technology->name }}
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </article>

            <aside class="mt-12 lg:col-span-4 lg:mt-0" aria-label="Start this service">
                <div data-fx="right" class="rounded-2xl border border-slate-200 bg-slate-50 p-6 lg:sticky lg:top-28 dark:border-white/10 dark:bg-white/[0.03]">
                    @if ($hasPrice)
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ $service->price_note ?: 'Starting from' }}</p>
                        <p class="mt-1 text-3xl font-bold tracking-tight tabular-nums text-slate-900 dark:text-white">{{ money((string) $service->starting_price) }}</p>
                    @else
                        <p class="text-lg font-semibold text-slate-900 dark:text-white">Price on request</p>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Every project is quoted on its scope.</p>
                    @endif

                    @if ($contactUrl)
                        <div class="mt-6">
                            <x-site.button label="Ask about this service" :url="$contactUrl" style="primary" icon="arrow-right" :block="true" size="lg" />
                        </div>
                        <p class="mt-3 text-center text-xs text-slate-500 dark:text-slate-400">A real person replies — usually within one working day.</p>
                    @endif
                </div>
            </aside>
        </div>
    </x-site.section>

    @if ($relatedPortfolio->isNotEmpty())
        <x-site.section background="muted" label="Related work">
            <x-site.heading title="Work we have delivered" subtitle="Projects built with this kind of service." align="left" />
            <ul role="list" class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($relatedPortfolio as $item)
                    @php
                        $cover = $snapshot($item, ['coverAsset', 'cover', 'coverMedia'], ImageProfile::Card);
                        $itemUrl = $portfolioDetail && Route::has('site.portfolio.show') ? route('site.portfolio.show', $item->slug) : null;
                    @endphp
                    <li data-fx="tilt" data-fx-delay="{{ ($loop->index % 4) + 1 }}" class="group relative overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card transition hover:shadow-card-hover dark:border-white/10 dark:bg-slate-900">
                        <div class="aspect-[4/3] overflow-hidden bg-slate-100 dark:bg-slate-800">
                            @if ($cover)
                                <x-site.image :media="$cover" profile="card" :lazy="$lazy" :alt="$item->title" class="h-full w-full transition duration-300 group-hover:scale-[1.02]" />
                            @endif
                        </div>
                        <div class="p-5">
                            <h3 class="font-semibold text-slate-900 dark:text-white">
                                @if ($itemUrl)
                                    <a href="{{ $itemUrl }}" class="after:absolute after:inset-0 focus-visible:outline-none">{{ $item->title }}</a>
                                @else
                                    {{ $item->title }}
                                @endif
                            </h3>
                            @if (filled($item->client_name))
                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $item->client_name }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-site.section>
    @endif

    @if ($relatedServices->isNotEmpty())
        <x-site.section background="surface" label="Related services">
            <x-site.heading title="Related services" align="left" />
            <ul role="list" class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($relatedServices as $related)
                    <li data-fx="tilt" data-fx-delay="{{ ($loop->index % 4) + 1 }}" class="group relative rounded-2xl border border-slate-200 bg-white p-6 shadow-card transition hover:-translate-y-0.5 hover:shadow-card-hover dark:border-white/10 dark:bg-slate-900">
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
                            <x-ui.icon :name="filled($related->icon) ? $related->icon : 'wrench-screwdriver'" class="h-5 w-5" />
                        </span>
                        <h3 class="mt-4 font-semibold text-slate-900 dark:text-white">
                            <a href="{{ route('site.services.show', $related->slug) }}" class="after:absolute after:inset-0 focus-visible:outline-none">{{ $related->name }}</a>
                        </h3>
                        @if (filled($related->short_description))
                            <p class="mt-2 line-clamp-2 text-sm text-slate-600 dark:text-slate-400">{{ $related->short_description }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-site.section>
    @endif

    {{-- Sticky inquiry CTA on small screens (the aside card carries it on large ones). --}}
    @if ($contactUrl)
        <div class="sticky bottom-0 z-20 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur lg:hidden dark:border-white/10 dark:bg-slate-950/95">
            <div class="mx-auto flex max-w-screen-xl items-center justify-between gap-3">
                <p class="min-w-0 truncate text-sm font-medium text-slate-900 dark:text-white">{{ $service->name }}</p>
                <x-site.button label="Ask about it" :url="$contactUrl" style="primary" size="sm" class="shrink-0" />
            </div>
        </div>
    @endif
@endsection
