{{--
    The 12-column widget grid, one block per section.

    Sections come from `WidgetGroup::sort()`; the order inside a section is the user's own. Drag and
    drop is confined to a section — `data-widget-section` is the drop boundary — because a card
    dragged into another section would snap back on reload (the grouping is the registry's, not the
    user's), and a control that silently undoes itself is worse than one that refuses.

    Expects: $sections (from DashboardController::sections()), $range, $widgetUrlTemplate.
--}}

<div class="space-y-8">
    @foreach ($sections as $section)
        @php
            $sectionKeys = collect($section['widgets'])->pluck('descriptor.key')->all();
            // Every card in this section switched off: collapse it on the server too, so the
            // heading is not briefly visible above nothing before Alpine catches up.
            $sectionEmpty = collect($section['widgets'])->every(fn (array $card): bool => $card['hidden']);
        @endphp

        <section
            aria-labelledby="dashboard-section-{{ $section['group'] }}"
            {{-- Hide a whole section once every card inside it is switched off. --}}
            x-show="sectionHasVisible(@js($sectionKeys))"
            @if ($sectionEmpty) style="display: none" @endif
        >
            @if (count($sections) > 1)
                <h2
                    id="dashboard-section-{{ $section['group'] }}"
                    class="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400"
                >
                    {{ $section['label'] }}
                    <span class="h-px flex-1 bg-slate-200 dark:bg-slate-800" aria-hidden="true"></span>
                </h2>
            @else
                <h2 id="dashboard-section-{{ $section['group'] }}" class="sr-only">{{ $section['label'] }}</h2>
            @endif

            <div
                class="grid grid-cols-12 gap-4 sm:gap-5 lg:gap-6"
                data-widget-section="{{ $section['group'] }}"
            >
                @foreach ($section['widgets'] as $card)
                    @include('admin.dashboard.partials.card', ['card' => $card])
                @endforeach
            </div>
        </section>
    @endforeach
</div>
