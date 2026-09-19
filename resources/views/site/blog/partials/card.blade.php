{{--
    One blog post card (phase-04 §8.11 blog grid).

    @include('site.blog.partials.card', ['post' => $post, 'lazy' => true, 'headingTag' => 'h2'])

    Reads the eager-loaded category, author and featured image relations only (never a lazy load): the listing
    controllers load them with the page, and §11 test 32 bounds the queries.
--}}

@php
    $mediaService = app(\App\Services\Cms\MediaService::class);
    $image = null;
    foreach (['featuredImageAsset', 'featuredImage', 'featuredImageMedia'] as $relation) {
        if ($post->relationLoaded($relation) && $post->getRelation($relation) instanceof \App\Models\Cms\MediaAsset) {
            $image = rescue(static fn () => $mediaService->toSnapshot($post->getRelation($relation), \App\Enums\Cms\ImageProfile::Card), null, false);
            break;
        }
    }
    $category = $post->relationLoaded('category') ? $post->category : null;
    $author = $post->relationLoaded('author') ? $post->author : null;
    $tag = in_array($headingTag ?? 'h2', ['h2', 'h3'], true) ? ($headingTag ?? 'h2') : 'h2';
    $url = route('site.blog.show', $post->slug);
    $alt = filled($post->featured_image_alt) ? $post->featured_image_alt : null;
@endphp

<article class="group relative flex h-full flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card transition duration-200 hover:-translate-y-0.5 hover:shadow-card-hover focus-within:ring-2 focus-within:ring-brand-500/50 dark:border-white/10 dark:bg-slate-900">
    <div class="aspect-video overflow-hidden bg-slate-100 dark:bg-slate-800">
        @if ($image)
            <x-site.image :media="$alt ? array_merge($image, ['alt' => $alt]) : $image" profile="card" :lazy="$lazy ?? true" :alt="$post->title" class="h-full w-full transition duration-300 group-hover:scale-[1.02]" />
        @else
            <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-brand-50 to-slate-100 text-brand-300 dark:from-brand-500/10 dark:to-slate-900 dark:text-brand-500/40" aria-hidden="true">
                <x-ui.icon name="newspaper" class="h-10 w-10" />
            </div>
        @endif
    </div>

    <div class="flex flex-1 flex-col p-6">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
            @if ($category)
                <a href="{{ route('site.blog.category', $category->slug) }}" class="relative z-10 font-semibold uppercase tracking-wider text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">{{ $category->name }}</a>
            @endif
            @if ($post->published_at)
                <time datetime="{{ app_date($post->published_at, 'Y-m-d') }}">{{ app_date($post->published_at) }}</time>
            @endif
            @if ($post->reading_minutes)
                <span>{{ app_number((int) $post->reading_minutes) }} min read</span>
            @endif
        </div>

        <{{ $tag }} class="mt-2 text-lg font-semibold leading-snug text-slate-900 dark:text-white">
            <a href="{{ $url }}" class="after:absolute after:inset-0 focus-visible:outline-none">{{ $post->title }}</a>
        </{{ $tag }}>

        @if (filled($post->excerpt))
            <p class="mt-2 line-clamp-3 text-sm leading-relaxed text-slate-600 dark:text-slate-400">{{ $post->excerpt }}</p>
        @endif

        <p class="mt-auto pt-5 text-sm text-slate-500 dark:text-slate-400">By <span class="font-medium text-slate-700 dark:text-slate-200">{{ $author?->name ?? ($companyName ?? 'our team') }}</span></p>
    </div>
</article>
