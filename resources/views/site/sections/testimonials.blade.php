{{--
    Section type `testimonials` — declared by Phase 4 (phase-04 §8.11 "Testimonials / reviews / success stories", §9.2;
    requirement §14). Rendered through Phase 3's section renderer with the standard contract ($section, $content, …).

    Receives:
      $section   the published snapshot (id, anchor, provider)
      $content   heading, description, view_all_link (optional link field), layout (grid | slider, optional)
      $section['provider']  from Phase 4's TestimonialsSectionProvider — ONLY Testimonial::public() rows (approved), featured
                 first then sort_order, as plain arrays (a snapshot never holds a model):
                   items: list<array{id: int, type: string, author_name: string, author_designation: ?string,
                          author_company: ?string, course_name: ?string, rating: ?int, review: string, review_date: ?string,
                          photo: ?array (thumbnail media array), is_featured: bool}>
                 (App\Support\Cms\Sections\TestimonialsSectionProvider)

    With no approved testimonial the section renders nothing at all. Each card carries id="testimonial-{id}", the anchor the
    admin queue links to once a testimonial is approved. The review is plain text, escaped.
--}}

@php
    $fields = (array) ($content ?? []);
    $provider = data_get($section ?? null, 'provider');
    $cards = collect(is_array(data_get($provider, 'items')) ? data_get($provider, 'items') : (is_array($provider) && array_is_list($provider) ? $provider : []))
        ->filter(static fn ($card): bool => filled(data_get($card, 'author_name')) && filled(data_get($card, 'review')))
        ->take(12)
        ->values();

    $heading = trim((string) data_get($fields, 'heading', ''));
    $heading = $heading !== '' ? $heading : 'What our clients say';
    $description = trim((string) data_get($fields, 'description', ''));
    $viewAll = data_get($fields, 'view_all_link');
@endphp

@if ($cards->isNotEmpty())
    <x-site.section :anchor="data_get($section ?? null, 'anchor')" background="muted" :label="$heading">
        <x-site.heading :title="$heading" :subtitle="$description !== '' ? $description : null" align="center" />

        <ul role="list" class="mt-12 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            @foreach ($cards as $card)
                @php
                    $type = (string) data_get($card, 'type', 'client');
                    $meta = $type === 'student'
                        ? collect(['Student', data_get($card, 'course_name')])->filter()->implode(' · ')
                        : collect([data_get($card, 'author_designation'), data_get($card, 'author_company')])->filter()->implode(', ');
                    $photo = data_get($card, 'photo');
                @endphp
                <li id="testimonial-{{ (int) data_get($card, 'id') }}" class="scroll-mt-28">
                    <figure class="flex h-full flex-col rounded-2xl border border-slate-200 bg-white p-6 shadow-card dark:border-white/10 dark:bg-slate-900">
                        @include('site.marketing.partials.stars', ['rating' => data_get($card, 'rating')])
                        <blockquote class="mt-4 flex-1 text-base leading-relaxed text-slate-700 dark:text-slate-200">
                            <p class="whitespace-pre-line">“{{ data_get($card, 'review') }}”</p>
                        </blockquote>
                        <figcaption class="mt-6 flex items-center gap-3">
                            <span class="h-11 w-11 shrink-0 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                                @if (filled(data_get($photo, 'url')))
                                    <x-site.image :media="$photo" profile="thumbnail" :alt="data_get($card, 'author_name')" ratio="1/1" class="h-full w-full" />
                                @else
                                    <span class="flex h-full w-full items-center justify-center text-sm font-semibold text-slate-500 dark:text-slate-400" aria-hidden="true">{{ \Illuminate\Support\Str::upper(mb_substr((string) data_get($card, 'author_name'), 0, 1)) }}</span>
                                @endif
                            </span>
                            <span class="min-w-0">
                                <span class="block truncate font-semibold text-slate-900 dark:text-white">{{ data_get($card, 'author_name') }}</span>
                                @if ($meta !== '')
                                    <span class="block truncate text-sm text-slate-500 dark:text-slate-400">{{ $meta }}</span>
                                @endif
                            </span>
                        </figcaption>
                    </figure>
                </li>
            @endforeach
        </ul>

        @if (is_array($viewAll) && filled($viewAll['label'] ?? null) && filled($viewAll['url'] ?? null))
            <div class="mt-10 text-center">
                <x-site.button :link="$viewAll" icon="arrow-right" />
            </div>
        @endif
    </x-site.section>
@endif
