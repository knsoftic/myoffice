@props([
    'items' => [],
    'openFirst' => true,
    'columns' => 1,
    'idPrefix' => 'faq',
])

{{--
    x-site.accordion — the FAQ list (§100, and the `faq` section type).

        <x-site.accordion :items="$faqs" :open-first="$siteSetting('website.faq_accordion_open_first', true)" />

    `items` is a list of published FAQ arrays: `['id' => 7, 'question' => '…', 'answer' => '<p>…</p>']`.
    The answer is rich text and goes through `<x-site.prose>`, which is the only sanitised output path
    (INV-13).

    Accessibility is the point of this component, so it is built the boring, correct way:
      · each row is a `<button aria-expanded aria-controls>` inside an `<h3>` — a screen reader can
        list the questions and knows which are open;
      · the panel is a plain `<div role="region" aria-labelledby>` that is removed from the tree by
        `x-show` (display:none), so nothing hidden is focusable;
      · keyboard: the control is a real button, so Enter and Space work with no JS of our own, and the
        chevron is `aria-hidden`.

    One Alpine scope holds the open index, so only one answer is open at a time and "open the first
    one" (`website.faq_accordion_open_first`) is a single initial value rather than per-row state.
--}}

@php
    $rows = collect($items)
        ->map(static fn ($row): array => [
            'id' => data_get($row, 'id'),
            'question' => (string) (data_get($row, 'question') ?? ''),
            'answer' => (string) (data_get($row, 'answer') ?? ''),
        ])
        ->filter(static fn (array $row): bool => $row['question'] !== '')
        ->values();

    $gridClass = ((int) $columns) >= 2
        ? 'grid grid-cols-1 gap-3 lg:grid-cols-2 lg:gap-4'
        : 'space-y-3';
@endphp

@if ($rows->isNotEmpty())
    <div
        x-data="{ open: {{ $openFirst ? 0 : 'null' }} }"
        {{ $attributes->class($gridClass) }}
    >
        @foreach ($rows as $index => $row)
            @php
                $panelId = $idPrefix.'-panel-'.($row['id'] ?? $index);
                $buttonId = $idPrefix.'-button-'.($row['id'] ?? $index);
            @endphp

            <div class="h-fit overflow-hidden rounded-xl border border-slate-200 bg-white transition duration-150 hover:border-slate-300 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-slate-700">
                <h3>
                    <button
                        type="button"
                        id="{{ $buttonId }}"
                        aria-controls="{{ $panelId }}"
                        x-bind:aria-expanded="open === {{ $index }} ? 'true' : 'false'"
                        x-on:click="open = (open === {{ $index }} ? null : {{ $index }})"
                        class="flex w-full items-center justify-between gap-4 px-5 py-4 text-left text-[0.9375rem] font-semibold text-slate-900 transition hover:text-brand-700 dark:text-white dark:hover:text-brand-300"
                    >
                        <span>{{ $row['question'] }}</span>

                        <x-ui.icon
                            name="chevron-down"
                            class="h-5 w-5 shrink-0 text-slate-400 transition-transform duration-150"
                            x-bind:class="open === {{ $index }} ? 'rotate-180' : ''"
                        />
                    </button>
                </h3>

                <div
                    id="{{ $panelId }}"
                    role="region"
                    aria-labelledby="{{ $buttonId }}"
                    x-show="open === {{ $index }}"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-cloak
                >
                    <div class="border-t border-slate-100 px-5 py-4 dark:border-slate-800">
                        <x-site.prose :html="$row['answer']" size="sm" />
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endif
