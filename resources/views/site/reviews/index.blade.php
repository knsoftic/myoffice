{{--
    The public student reviews page — site.reviews.index. A 404 before this view when
    website.reviews_page_enabled is false.

    Controller variables (Site\StudentReviewController@index, through ComposesContentPages::contentPage()):
      $site     the SitePayload (seo for route_key site.reviews.index)
      $page     array{title, slug}
      $reviews  LengthAwarePaginator<App\Models\Cms\StudentReview> — StudentReview::public() only
                (status = approved, not soft-deleted), featured first then sort_order; studentPhoto
                eager-loaded. Page size: website.reviews_per_page.

    ONLY these columns were selected, so only these can be printed:
      id, student_name, student_photo_media_id, course_name, rating, review, video_url, is_featured, sort_order

    student_id, course_id, submitted_by_user_id, approved_by, approved_at, rejection_reason, source and
    ip_address are never loaded. This page shows what moderation approved and cannot widen it.

    The video is rebuilt from a parsed YouTube / Vimeo id by site.marketing.partials.video-embed — the
    stored string is never used as a src. Stars come from site.marketing.partials.stars, which renders
    nothing for a null rating rather than zero stars.
--}}

@extends('site.layouts.public')

@php
    use App\Enums\Cms\ImageProfile;

    $mediaService = app(\App\Services\Cms\MediaService::class);
    $photoOf = static function ($review) use ($mediaService): ?array {
        if (! $review->relationLoaded('studentPhoto')) {
            return null;
        }

        $asset = $review->getRelation('studentPhoto');

        if (! $asset instanceof \App\Models\Cms\MediaAsset) {
            return null;
        }

        return rescue(static fn () => $mediaService->toSnapshot($asset, ImageProfile::Thumbnail), null, false);
    };

    $heading = $heading ?? 'Student reviews';
    $intro = $intro ?? 'What our students say about studying here, in their own words.';
@endphp

@section('title', $heading)

@section('content')
    @include('site.marketing.partials.page-hero', ['title' => $heading, 'subtitle' => $intro])

    <x-site.section background="surface" :label="$heading">
        <h2 data-fx="rise" class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl dark:text-white">
            In their own words
        </h2>
        <p data-fx="rise" data-fx-delay="1" class="mt-3 max-w-2xl text-base leading-relaxed text-slate-600 dark:text-slate-400">
            Every review below was submitted by a student and approved before it was published. Nothing here is edited
            for content.
        </p>

        @if ($reviews->isEmpty())
            <div data-fx="rise" class="mt-10 rounded-2xl border border-dashed border-slate-300 bg-white dark:border-white/10 dark:bg-slate-900">
                <x-ui.empty-state
                    icon="chat-bubble-left-right"
                    level="h3"
                    title="No reviews have been published yet"
                    message="As soon as students share how their course went, their reviews will appear here."
                />
            </div>
        @else
            <ul role="list" class="mt-10 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($reviews as $review)
                    @php $photo = $photoOf($review); @endphp
                    <li
                        data-fx="rise"
                        data-fx-delay="{{ ($loop->index % 3) + 1 }}"
                        id="student-review-{{ (int) $review->getKey() }}"
                        class="scroll-mt-28"
                    >
                        <figure class="flex h-full flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card dark:border-white/10 dark:bg-slate-900">
                            @if (filled($review->video_url))
                                @include('site.marketing.partials.video-embed', [
                                    'url' => $review->video_url,
                                    'embedUrl' => null,
                                    'title' => 'Video review by '.$review->student_name,
                                ])
                            @endif

                            <div class="flex flex-1 flex-col p-6">
                                @include('site.marketing.partials.stars', ['rating' => $review->rating])

                                <blockquote class="mt-4 flex-1 text-base leading-relaxed text-slate-700 dark:text-slate-200">
                                    <p class="whitespace-pre-line">&ldquo;{{ $review->review }}&rdquo;</p>
                                </blockquote>

                                <figcaption class="mt-6 flex items-center gap-3">
                                    <span class="h-11 w-11 shrink-0 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                                        @if (filled($photo['url'] ?? null))
                                            <x-site.image :media="$photo" profile="thumbnail" :alt="$review->student_name" ratio="1/1" class="h-full w-full" />
                                        @else
                                            <span class="flex h-full w-full items-center justify-center text-sm font-semibold text-slate-500 dark:text-slate-400" aria-hidden="true">{{ \Illuminate\Support\Str::upper(mb_substr((string) $review->student_name, 0, 1)) }}</span>
                                        @endif
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block truncate font-semibold text-slate-900 dark:text-white">{{ $review->student_name }}</span>
                                        @if (filled($review->course_name))
                                            <span class="block truncate text-sm text-slate-500 dark:text-slate-400">{{ $review->course_name }}</span>
                                        @endif
                                    </span>
                                </figcaption>
                            </div>
                        </figure>
                    </li>
                @endforeach
            </ul>

            @if ($reviews->hasPages())
                {{-- A plain div: the paginator view renders its own <nav> landmark, and nesting one
                     inside another gives a screen reader two navigation regions for one control. --}}
                <div class="mt-10">
                    {{ $reviews->links() }}
                </div>
            @endif
        @endif
    </x-site.section>
@endsection
