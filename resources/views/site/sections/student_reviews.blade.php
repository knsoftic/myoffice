{{--
    Section type `student_reviews` — declared by Phase 4 (phase-04 §8.11, §9.2; requirement §91).

    Receives:
      $section   the published snapshot (id, anchor, provider)
      $content   heading, description, view_all_link (optional)
      $section['provider']  from Phase 4's StudentReviewsSectionProvider — ONLY StudentReview::public() rows (approved),
                 featured first, as plain arrays:
                   items: list<array{id: int, student_name: string, course_name: ?string, rating: ?int, review: string,
                          video_embed_url: ?string (VideoUrl::embedUrl()), photo: ?array, is_featured: bool}>
                 (App\Support\Cms\Sections\StudentReviewsSectionProvider)
                 Phase 14 may pass a course-filtered list for a course landing page (§13).

    Renders nothing without an approved review. A video URL is embedded only from a parsed YouTube / Vimeo id
    (site/marketing/partials/video-embed). Anchor: id="student-review-{id}".
--}}

@php
    $fields = (array) ($content ?? []);
    $provider = data_get($section ?? null, 'provider');
    $cards = collect(is_array(data_get($provider, 'items')) ? data_get($provider, 'items') : (is_array($provider) && array_is_list($provider) ? $provider : []))
        ->filter(static fn ($card): bool => filled(data_get($card, 'student_name')) && filled(data_get($card, 'review')))
        ->take(12)
        ->values();

    $heading = trim((string) data_get($fields, 'heading', ''));
    $heading = $heading !== '' ? $heading : 'What our students say';
    $description = trim((string) data_get($fields, 'description', ''));
    $viewAll = data_get($fields, 'view_all_link');
@endphp

@if ($cards->isNotEmpty())
    <x-site.section :anchor="data_get($section ?? null, 'anchor')" background="surface" :label="$heading">
        <x-site.heading data-fx="rise" :title="$heading" :subtitle="$description !== '' ? $description : null" align="center" />

        <ul role="list" class="mt-12 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            @foreach ($cards as $card)
                @php $photo = data_get($card, 'photo'); @endphp
                <li data-fx="rise" data-fx-delay="{{ ($loop->index % 3) + 1 }}" id="student-review-{{ (int) data_get($card, 'id') }}" class="scroll-mt-28">
                    <figure class="flex h-full flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card dark:border-white/10 dark:bg-slate-900">
                        @if (filled(data_get($card, 'video_embed_url')) || filled(data_get($card, 'video_url')))
                            @include('site.marketing.partials.video-embed', ['embedUrl' => data_get($card, 'video_embed_url'), 'url' => data_get($card, 'video_url'), 'title' => 'Video review by '.data_get($card, 'student_name')])
                        @endif
                        <div class="flex flex-1 flex-col p-6">
                            @include('site.marketing.partials.stars', ['rating' => data_get($card, 'rating')])
                            <blockquote class="mt-4 flex-1 text-base leading-relaxed text-slate-700 dark:text-slate-200">
                                <p class="whitespace-pre-line">“{{ data_get($card, 'review') }}”</p>
                            </blockquote>
                            <figcaption class="mt-6 flex items-center gap-3">
                                <span class="h-11 w-11 shrink-0 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                                    @if (filled(data_get($photo, 'url')))
                                        <x-site.image :media="$photo" profile="thumbnail" :alt="data_get($card, 'student_name')" ratio="1/1" class="h-full w-full" />
                                    @else
                                        <span class="flex h-full w-full items-center justify-center text-sm font-semibold text-slate-500 dark:text-slate-400" aria-hidden="true">{{ \Illuminate\Support\Str::upper(mb_substr((string) data_get($card, 'student_name'), 0, 1)) }}</span>
                                    @endif
                                </span>
                                <span class="min-w-0">
                                    <span class="block truncate font-semibold text-slate-900 dark:text-white">{{ data_get($card, 'student_name') }}</span>
                                    @if (filled(data_get($card, 'course_name')))
                                        <span class="block truncate text-sm text-slate-500 dark:text-slate-400">{{ data_get($card, 'course_name') }}</span>
                                    @endif
                                </span>
                            </figcaption>
                        </div>
                    </figure>
                </li>
            @endforeach
        </ul>

        @if (is_array($viewAll) && filled($viewAll['label'] ?? null) && filled($viewAll['url'] ?? null))
            <div data-fx="rise" class="mt-10 text-center">
                <x-site.button :link="$viewAll" icon="arrow-right" />
            </div>
        @endif
    </x-site.section>
@endif
