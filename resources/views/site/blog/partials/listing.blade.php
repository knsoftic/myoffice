{{--
    The blog grid shared by site.blog.index, site.blog.category and site.blog.tag (phase-04 §8.11): an optional featured
    hero, the card grid, a sidebar with the category list (post counts), the tag cloud (active tags only) and a search
    box, and pagination that preserves the query string.

    @include('site.blog.partials.listing', [
        'posts' => $posts,              // LengthAwarePaginator<BlogPost> — BlogPost::public() only
        'featured' => $featured,        // ?BlogPost — index page 1 only, excluded from $posts
        'categories' => $sidebarCategories,  // Collection<BlogCategory> — active, with public_posts_count
        'tags' => $tagCloud,                 // Collection<BlogTag> — active, with public_posts_count
        'search' => $search,            // ?string (?search=)
        'activeCategory' => null, 'activeTag' => null,
        'companyName' => $companyName,  // ?string author fallback, passed by the controller (never read from settings here)
        'lazy' => true,
    ])
--}}

@php
    use App\Enums\Cms\ImageProfile;

    $categories = collect($categories ?? []);
    $tags = collect($tags ?? []);
    $featured = $featured ?? null;
    $activeCategory = $activeCategory ?? null;
    $activeTag = $activeTag ?? null;
    $lazy = (bool) ($lazy ?? true);
    $search = trim((string) ($search ?? ''));

    $featuredImage = null;
    if ($featured) {
        foreach (['featuredImageAsset', 'featuredImage', 'featuredImageMedia'] as $relation) {
            if ($featured->relationLoaded($relation) && $featured->getRelation($relation) instanceof \App\Models\Cms\MediaAsset) {
                $featuredImage = rescue(static fn () => app(\App\Services\Cms\MediaService::class)->toSnapshot($featured->getRelation($relation), ImageProfile::Banner), null, false);
                break;
            }
        }
    }
    $maxTagCount = max(1, (int) $tags->max(static fn ($tag): int => (int) ($tag->getAttributes()['public_posts_count'] ?? $tag->getAttributes()['posts_count'] ?? 0)));
@endphp

<x-site.section background="surface" padding="tight">
    @if ($featured)
        <article class="group relative mb-12 grid overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-card transition hover:shadow-card-hover lg:grid-cols-2 dark:border-white/10 dark:bg-slate-900">
            <div class="aspect-video overflow-hidden bg-slate-100 lg:aspect-auto dark:bg-slate-800">
                @if ($featuredImage)
                    <x-site.image :media="filled($featured->featured_image_alt) ? array_merge($featuredImage, ['alt' => $featured->featured_image_alt]) : $featuredImage" profile="banner" :eager="true" :alt="$featured->title" class="h-full w-full transition duration-500 group-hover:scale-[1.02]" />
                @endif
            </div>
            <div class="flex flex-col justify-center p-8 lg:p-12">
                <p class="inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-[0.14em] text-amber-600 dark:text-amber-400"><x-ui.icon name="star" class="h-4 w-4" /> Featured</p>
                <h2 class="mt-3 text-balance text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl dark:text-white">
                    <a href="{{ route('site.blog.show', $featured->slug) }}" class="after:absolute after:inset-0 focus-visible:outline-none">{{ $featured->title }}</a>
                </h2>
                @if (filled($featured->excerpt))
                    <p class="mt-4 text-pretty text-lg leading-relaxed text-slate-600 dark:text-slate-300">{{ $featured->excerpt }}</p>
                @endif
                <p class="mt-6 text-sm text-slate-500 dark:text-slate-400">
                    @if ($featured->published_at)
                        <time datetime="{{ app_date($featured->published_at, 'Y-m-d') }}">{{ app_date($featured->published_at) }}</time>
                    @endif
                    @if ($featured->reading_minutes)
                        · {{ app_number((int) $featured->reading_minutes) }} min read
                    @endif
                </p>
            </div>
        </article>
    @endif

    <div class="lg:grid lg:grid-cols-12 lg:gap-12">
        <div class="lg:col-span-8">
            @if ($search !== '')
                <p class="mb-6 text-sm text-slate-600 dark:text-slate-300">
                    {{ app_number($posts->total()) }} {{ $posts->total() === 1 ? 'result' : 'results' }} for “<span class="font-semibold text-slate-900 dark:text-white">{{ $search }}</span>”.
                    <a href="{{ url()->current() }}" class="font-semibold text-brand-600 hover:underline dark:text-brand-400">Clear search</a>
                </p>
            @endif

            @if ($posts->isEmpty())
                <div class="rounded-2xl border border-dashed border-slate-300 px-6 py-14 text-center dark:border-white/10">
                    <x-ui.icon name="newspaper" class="mx-auto h-8 w-8 text-slate-400" />
                    <h2 class="mt-4 text-lg font-semibold text-slate-900 dark:text-white">{{ $search !== '' ? 'No posts match that search' : 'No posts here yet' }}</h2>
                    @if ($search !== '' || $activeCategory || $activeTag)
                        <p class="mt-2"><a href="{{ route('site.blog.index') }}" class="font-semibold text-brand-600 hover:underline dark:text-brand-400">See every post</a></p>
                    @endif
                </div>
            @else
                <ul role="list" class="grid gap-6 sm:grid-cols-2">
                    @foreach ($posts as $post)
                        <li>@include('site.blog.partials.card', ['post' => $post, 'lazy' => $lazy, 'companyName' => $companyName ?? null])</li>
                    @endforeach
                </ul>

                @include('site.marketing.partials.pagination', ['paginator' => $posts, 'label' => 'posts'])
            @endif
        </div>

        <aside class="mt-12 space-y-8 lg:col-span-4 lg:mt-0" aria-label="Blog navigation">
            <form method="GET" action="{{ route('site.blog.index') }}" role="search" class="relative">
                <label for="blog-search" class="sr-only">Search the blog</label>
                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400"><x-ui.icon name="magnifying-glass" class="h-4 w-4" /></span>
                <input id="blog-search" type="search" name="search" value="{{ $search }}" maxlength="100" placeholder="Search posts…" class="block w-full rounded-xl border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-brand-500 focus:ring-brand-500/30 dark:border-white/10 dark:bg-slate-900 dark:text-white">
            </form>

            @if ($categories->isNotEmpty())
                <nav aria-labelledby="blog-categories-heading">
                    <h2 id="blog-categories-heading" class="text-sm font-semibold uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400">Categories</h2>
                    <ul role="list" class="mt-4 space-y-1">
                        @foreach ($categories as $category)
                            @php $isActive = $activeCategory && (int) $activeCategory->getKey() === (int) $category->getKey(); @endphp
                            <li>
                                <a href="{{ route('site.blog.category', $category->slug) }}" @if ($isActive) aria-current="page" @endif @class([
                                    'flex items-center justify-between rounded-lg px-3 py-2 text-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60',
                                    'bg-brand-50 font-semibold text-brand-700 dark:bg-brand-500/10 dark:text-brand-300' => $isActive,
                                    'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/5' => ! $isActive,
                                ])>
                                    <span>{{ $category->name }}</span>
                                    <span class="tabular-nums text-xs text-slate-400">{{ app_number((int) ($category->getAttributes()['public_posts_count'] ?? $category->getAttributes()['posts_count'] ?? 0)) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endif

            @if ($tags->isNotEmpty())
                <nav aria-labelledby="blog-tags-heading">
                    <h2 id="blog-tags-heading" class="text-sm font-semibold uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400">Tags</h2>
                    <ul role="list" class="mt-4 flex flex-wrap gap-2">
                        @foreach ($tags as $tagItem)
                            @php
                                $isActive = $activeTag && (int) $activeTag->getKey() === (int) $tagItem->getKey();
                                $weight = (int) ($tagItem->getAttributes()['public_posts_count'] ?? $tagItem->getAttributes()['posts_count'] ?? 0) / $maxTagCount;
                            @endphp
                            <li>
                                <a href="{{ route('site.blog.tag', $tagItem->slug) }}" @if ($isActive) aria-current="page" @endif @class([
                                    'inline-flex items-center rounded-full px-3 py-1 ring-1 ring-inset transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60',
                                    'text-xs' => $weight < 0.5,
                                    'text-sm' => $weight >= 0.5,
                                    'bg-brand-600 text-white ring-brand-600 dark:bg-brand-500 dark:ring-brand-500' => $isActive,
                                    'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-white/10 dark:hover:bg-slate-800' => ! $isActive,
                                ])>#{{ $tagItem->name }}</a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endif
        </aside>
    </div>
</x-site.section>
