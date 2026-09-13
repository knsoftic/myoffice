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
    $openFirst = filter_var(rescue(static fn () => site_setting('website.faq_accordion_open_first', true), true), FILTER_VALIDATE_BOOLEAN);
@endphp

@if ($questions !== [])
    <x-site.section :anchor="data_get($section ?? null, 'anchor')" background="muted" :label="$heading !== '' ? $heading : null">
        <x-site.heading :title="$heading" :subtitle="$description" align="center" />

        <x-site.accordion
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

        @if (is_array($showAll) && filled($showAll['label'] ?? null) && filled($showAll['url'] ?? null))
            <div class="mt-10 text-center">
                <x-site.button :link="$showAll" icon="arrow-right" />
            </div>
        @endif
    </x-site.section>
@endif
