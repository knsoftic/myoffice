{{--
    Section type `faq` (placements home, page; repeatable) — requirement §100, phase-03 §6.1, §6.13.

    Receives:
      $section  the published snapshot (id, anchor)
      $content  heading, description, source, faq_category_ref, columns (1-2), show_all_link (link)
      $faqs     the questions the snapshot resolved for that source: [['id', 'question', 'answer'], ...]
                — published FAQs only, answers sanitised (and sanitised again by <x-site.accordion>)

    With no published question the section renders nothing: a heading above an empty list would be a
    promise the page cannot keep. `website.faq_accordion_open_first` decides whether the first answer
    starts open.
--}}

@php
    $fields = (array) ($content ?? []);

    $questions = collect($faqs ?? [])
        ->filter(static fn ($faq): bool => filled(data_get($faq, 'question')))
        ->values()
        ->all();

    $heading = trim((string) data_get($fields, 'heading', ''));
    $description = trim((string) data_get($fields, 'description', ''));
    $columns = (int) data_get($fields, 'columns', 1) >= 2 && count($questions) > 3 ? 2 : 1;
    $showAll = data_get($fields, 'show_all_link');
    $openFirst = filter_var(site_setting('website.faq_accordion_open_first', true), FILTER_VALIDATE_BOOLEAN);
@endphp

@if ($questions !== [])
    @php
        // One column with a heading: the heading beside the questions on a wide screen, held in view while
        // the reader scrolls the answers. Two columns keep the centred heading above the grid.
        $split = $columns === 1 && ($heading !== '' || $description !== '');
        $hasShowAll = is_array($showAll) && filled($showAll['label'] ?? null) && filled($showAll['url'] ?? null);
    @endphp
    <x-site.section :anchor="data_get($section ?? null, 'anchor')" background="muted" :label="$heading !== '' ? $heading : null">
        @if ($split)
            <div class="grid gap-10 lg:grid-cols-12 lg:gap-16">
                <div class="lg:col-span-5">
                    <div class="lg:sticky lg:top-28">
                        <x-site.heading data-fx="rise" :title="$heading" :subtitle="$description" align="left" :accent="true" />
                        @if ($hasShowAll)
                            <div data-fx="rise" class="mt-8">
                                <x-site.button :link="$showAll" icon="arrow-right" />
                            </div>
                        @endif
                    </div>
                </div>
                <x-site.accordion
                    data-fx="rise"
                    data-fx-delay="1"
                    :items="$questions"
                    :columns="1"
                    :open-first="$openFirst"
                    :id-prefix="'faq-'.(data_get($section ?? null, 'id') ?? 'x')"
                    class="lg:col-span-7"
                />
            </div>
        @else
            <x-site.heading data-fx="rise" :title="$heading" :subtitle="$description" align="center" :accent="true" />
            <x-site.accordion
                data-fx="rise"
                data-fx-delay="1"
                :items="$questions"
                :columns="$columns"
                :open-first="$openFirst"
                :id-prefix="'faq-'.(data_get($section ?? null, 'id') ?? 'x')"
                @class([
                    'mx-auto',
                    'mt-12' => $heading !== '' || $description !== '',
                    'max-w-3xl' => $columns === 1,
                    'max-w-6xl' => $columns === 2,
                ])
            />
            @if ($hasShowAll)
                <div data-fx="rise" class="mt-10 text-center">
                    <x-site.button :link="$showAll" icon="arrow-right" />
                </div>
            @endif
        @endif
    </x-site.section>
@endif
