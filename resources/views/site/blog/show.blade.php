{{--
    One blog post — site.blog.show, and site.blog.preview for a signed draft preview (phase-04 §8.11, §6.7, §9.2).

    Controller variables (Site\BlogController@show / @preview, through ComposesContentPages::contentPage()):
      $site          the SitePayload, seo = SeoService::for($post); on the preview route `isPreview` = true (the layout
                     shows the preview ribbon and forces noindex; the controller sends X-Robots-Tag and counts no view)
      $page          array{title, slug}
      $post          App\Models\Cms\BlogPost with category, tags (public), author, featuredImage
      $content       string   RichText::sanitize($post->content) — sanitised again by <x-site.prose> here (D25)
      $related       Collection<BlogPost>  BlogService::related($post) — empty (and always empty on preview) hides the block
      $shareUrl      string   the absolute post URL (share links, copy link)
      $jsonLd        ?array   the BlogPosting object (null on preview); built here from the row when absent
      $isPreview     bool
      $companyName   optional ?string   byline when the post has no author
      $lazyImages    optional bool

    The view is counted by the controller (RecordBlogPostView dispatched after the response), never by this view.
    Share links are plain URLs — no third-party script. The JSON-LD BlogPosting is built from the row.
--}}

@extends('site.layouts.public')

@php
    use App\Enums\Cms\ImageProfile;

    $mediaService = app(\App\Services\Cms\MediaService::class);
    $image = null;
    foreach (['featuredImageAsset', 'featuredImage', 'featuredImageMedia'] as $relation) {
        if ($post->relationLoaded($relation) && $post->getRelation($relation) instanceof \App\Models\Cms\MediaAsset) {
            $image = rescue(static fn () => $mediaService->toSnapshot($post->getRelation($relation), ImageProfile::Banner), null, false);
            break;
        }
    }
    if ($image && filled($post->featured_image_alt)) {
        $image['alt'] = $post->featured_image_alt;
    }

    $category = $post->relationLoaded('category') ? $post->category : null;
    $tags = $post->relationLoaded('tags') ? $post->tags : collect();
    $author = $post->relationLoaded('author') ? $post->author : null;
    $related = collect($related ?? []);
    $byline = $author?->name ?? ($companyName ?? null);
    $shareUrl = (string) ($shareUrl ?? route('site.blog.show', $post->slug));
    $isPreview = (bool) ($isPreview ?? data_get($site ?? null, 'isPreview', false));
    $body = (string) ($content ?? $post->content);

    $shareLinks = [
        ['label' => 'Share on LinkedIn', 'url' => 'https://www.linkedin.com/sharing/share-offsite/?url='.rawurlencode($shareUrl), 'glyph' => 'linkedin'],
        ['label' => 'Share on Facebook', 'url' => 'https://www.facebook.com/sharer/sharer.php?u='.rawurlencode($shareUrl), 'glyph' => 'facebook'],
        ['label' => 'Share on X', 'url' => 'https://twitter.com/intent/tweet?url='.rawurlencode($shareUrl).'&text='.rawurlencode((string) $post->title), 'glyph' => 'x_twitter'],
        ['label' => 'Share on WhatsApp', 'url' => 'https://wa.me/?text='.rawurlencode($post->title.' '.$shareUrl), 'glyph' => 'whatsapp'],
        ['label' => 'Share by email', 'url' => 'mailto:?subject='.rawurlencode((string) $post->title).'&body='.rawurlencode($shareUrl), 'glyph' => 'email'],
    ];

    $jsonLd = is_array($jsonLd ?? null) ? $jsonLd : array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'BlogPosting',
        'headline' => \Illuminate\Support\Str::limit((string) $post->title, 110, ''),
        'description' => $post->excerpt ?: null,
        'datePublished' => $post->published_at?->toIso8601String(),
        'dateModified' => $post->updated_at?->toIso8601String(),
        'mainEntityOfPage' => $shareUrl,
        'url' => $shareUrl,
        'image' => $image['url'] ?? null,
        'wordCount' => null,
        'articleSection' => $category?->name,
        'keywords' => $tags->isNotEmpty() ? $tags->pluck('name')->implode(', ') : null,
        'author' => $byline ? ['@type' => $author ? 'Person' : 'Organization', 'name' => $byline] : null,
        'publisher' => filled($companyName ?? null) ? ['@type' => 'Organization', 'name' => $companyName] : null,
    ], static fn ($value) => $value !== null && $value !== '');
@endphp

@section('title', $post->title)

@unless ($isPreview)
    @push('head')
        <script type="application/ld+json">@json($jsonLd)</script>
    @endpush
@endunless

@section('content')
    <article>
        <header class="border-b border-slate-200/80 bg-slate-50 dark:border-white/10 dark:bg-slate-900/40">
            <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
                <nav aria-label="Breadcrumb">
                    <ol class="flex flex-wrap items-center gap-1.5 text-sm text-slate-500 dark:text-slate-400">
                        <li><a href="{{ route('site.blog.index') }}" class="rounded transition hover:text-slate-900 dark:hover:text-white">Blog</a></li>
                        @if ($category)
                            <li aria-hidden="true"><x-ui.icon name="chevron-right" class="h-3.5 w-3.5" /></li>
                            <li><a href="{{ route('site.blog.category', $category->slug) }}" class="rounded transition hover:text-slate-900 dark:hover:text-white">{{ $category->name }}</a></li>
                        @endif
                    </ol>
                </nav>

                <h1 class="mt-6 text-balance text-4xl font-bold tracking-tight text-slate-900 sm:text-5xl dark:text-white">{{ $post->title }}</h1>

                @if (filled($post->excerpt))
                    <p class="mt-5 text-pretty text-lg leading-relaxed text-slate-600 sm:text-xl dark:text-slate-300">{{ $post->excerpt }}</p>
                @endif

                <div class="mt-8 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-slate-500 dark:text-slate-400">
                    @if ($byline)
                        <span class="inline-flex items-center gap-2">
                            <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300" aria-hidden="true">{{ \Illuminate\Support\Str::upper(mb_substr((string) $byline, 0, 1)) }}</span>
                            <span class="font-medium text-slate-900 dark:text-white">{{ $byline }}</span>
                        </span>
                    @endif
                    @if ($post->published_at)
                        <time datetime="{{ app_date($post->published_at, 'Y-m-d') }}">{{ app_date($post->published_at) }}</time>
                    @elseif ($isPreview)
                        <span class="font-semibold text-amber-600 dark:text-amber-400">Not published yet</span>
                    @endif
                    @if ($post->reading_minutes)
                        <span class="inline-flex items-center gap-1"><x-ui.icon name="clock" class="h-4 w-4" /> {{ app_number((int) $post->reading_minutes) }} min read</span>
                    @endif
                </div>
            </div>
        </header>

        @if ($image)
            <div class="mx-auto -mb-4 mt-10 max-w-5xl px-4 sm:px-6 lg:px-8">
                <div class="overflow-hidden rounded-2xl bg-slate-100 shadow-card ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-white/10">
                    <x-site.image :media="$image" profile="banner" :eager="true" :alt="$post->title" class="h-full w-full" />
                </div>
            </div>
        @endif

        <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
            <x-site.prose :html="$body" size="lg" />

            @if ($tags->isNotEmpty())
                <ul role="list" class="mt-12 flex flex-wrap gap-2" aria-label="Tags">
                    @foreach ($tags as $postTag)
                        <li><a href="{{ route('site.blog.tag', $postTag->slug) }}" class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-700 transition hover:bg-slate-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:bg-white/5 dark:text-slate-300 dark:hover:bg-white/10">#{{ $postTag->name }}</a></li>
                    @endforeach
                </ul>
            @endif

            @unless ($isPreview)
                <div class="mt-10 flex flex-wrap items-center gap-3 border-t border-slate-200 pt-8 dark:border-white/10" x-data="{ copied: false }">
                    <span class="text-sm font-medium text-slate-700 dark:text-slate-200">Share</span>
                    @foreach ($shareLinks as $share)
                        <a href="{{ $share['url'] }}" @if (! str_starts_with($share['url'], 'mailto:')) target="_blank" rel="noopener noreferrer" @endif class="inline-flex h-10 w-10 items-center justify-center rounded-lg text-slate-500 ring-1 ring-inset ring-slate-200 transition hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-slate-400 dark:ring-white/10 dark:hover:bg-white/10 dark:hover:text-white">
                            @if ($share['glyph'] === 'whatsapp')
                                <x-ui.icon name="whatsapp" class="h-4 w-4" />
                            @elseif ($share['glyph'] === 'email')
                                <x-ui.icon name="envelope" class="h-4 w-4" />
                            @else
                                @include('site.marketing.partials.social-glyph', ['platform' => $share['glyph'], 'class' => 'h-4 w-4'])
                            @endif
                            <span class="sr-only">{{ $share['label'] }}</span>
                        </a>
                    @endforeach
                    <button type="button" x-on:click="navigator.clipboard?.writeText(@js($shareUrl)).then(() => { copied = true; setTimeout(() => copied = false, 2000); })" class="inline-flex h-10 items-center gap-2 rounded-lg px-3 text-sm font-medium text-slate-600 ring-1 ring-inset ring-slate-200 transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-slate-300 dark:ring-white/10 dark:hover:bg-white/10">
                        <x-ui.icon name="link" class="h-4 w-4" />
                        <span x-text="copied ? 'Link copied' : 'Copy link'">Copy link</span>
                    </button>
                </div>
            @endunless
        </div>
    </article>

    @if ($related->isNotEmpty())
        <x-site.section background="muted" label="Related posts">
            <x-site.heading title="Keep reading" align="left" />
            <ul role="list" class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($related as $relatedPost)
                    <li>@include('site.blog.partials.card', ['post' => $relatedPost, 'lazy' => $lazyImages ?? true, 'headingTag' => 'h3', 'companyName' => $companyName ?? null])</li>
                @endforeach
            </ul>
        </x-site.section>
    @endif
@endsection
