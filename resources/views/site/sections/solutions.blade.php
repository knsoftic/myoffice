{{--
    Section type `solutions` (LandingSectionTypes) — the software house and the training institute side by
    side, as two tabs of feature cards.

    Receives:
      $section  the published snapshot (id, anchor)
      $content  eyebrow, heading, description, software_house_label, institute_label,
                software_house_intro, institute_intro
      $items    feature => [persona, icon, title, text]

    A tab renders only when it has a card, and with only one tab left there is no tab bar at all, just the
    cards. The tab bar is a real tablist (arrow keys, Home / End), the same pattern as the about section.
--}}

@php
    use App\Support\Cms\Sections\LandingSectionTypes;

    $fields = (array) ($content ?? []);
    $eyebrow = trim((string) data_get($fields, 'eyebrow', ''));
    $heading = trim((string) data_get($fields, 'heading', ''));
    $description = trim((string) data_get($fields, 'description', ''));

    $features = collect(data_get($items ?? [], 'feature', []))
        ->filter(static fn ($item): bool => filled(data_get($item, 'content.title')))
        ->groupBy(static fn ($item): string => (string) (data_get($item, 'content.persona') ?: 'software_house'));

    $tabs = collect(array_keys(LandingSectionTypes::PERSONAS))
        ->filter(static fn (string $persona): bool => $features->has($persona))
        ->map(static fn (string $persona): array => [
            'key' => $persona,
            'label' => trim((string) data_get($fields, $persona.'_label', '')) ?: LandingSectionTypes::PERSONAS[$persona],
            'intro' => trim((string) data_get($fields, $persona.'_intro', '')),
            'cards' => $features->get($persona),
            // The two halves keep their own accent, so a glance tells them apart.
            'tile' => $persona === 'institute' ? 'from-cyan-500 to-brand-600 shadow-cyan-500/25' : 'from-brand-500 to-violet-600 shadow-brand-600/25',
        ])
        ->values();

    $uid = 'solutions-'.(data_get($section ?? null, 'id') ?? 'x');
@endphp

@if ($tabs->isNotEmpty())
    <x-site.section :anchor="data_get($section ?? null, 'anchor')" background="none" :label="$heading !== '' ? $heading : null" class="isolate overflow-hidden">
        <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
            <div class="absolute inset-0 bg-white dark:bg-slate-950"></div>
            <div class="absolute left-1/2 top-0 h-80 w-[48rem] -translate-x-1/2 rounded-full bg-brand-400/10 blur-3xl dark:bg-brand-500/10"></div>
        </div>

        <x-site.heading data-fx="rise" :eyebrow="$eyebrow" :title="$heading" :subtitle="$description" align="center" />

        <div
            @class(['mt-12' => $heading !== '' || $description !== ''])
            x-data="{
                tab: 0,
                count: {{ $tabs->count() }},
                select(index) {
                    this.tab = (index + this.count) % this.count;
                    this.$nextTick(() => this.$refs['tab' + this.tab]?.focus());
                },
            }"
        >
            @if ($tabs->count() > 1)
                <div class="flex justify-center">
                    <div
                        role="tablist"
                        aria-label="{{ $heading !== '' ? $heading : 'Solutions' }}"
                        data-fx="rise"
                        data-fx-delay="1"
                        class="inline-flex max-w-full gap-1 overflow-x-auto rounded-full border border-slate-200 bg-white/80 p-1 shadow-sm backdrop-blur dark:border-white/10 dark:bg-white/5"
                        x-on:keydown.arrow-right.prevent="select(tab + 1)"
                        x-on:keydown.arrow-left.prevent="select(tab - 1)"
                        x-on:keydown.home.prevent="select(0)"
                        x-on:keydown.end.prevent="select(count - 1)"
                    >
                        @foreach ($tabs as $i => $tab)
                            <button
                                type="button"
                                role="tab"
                                id="{{ $uid }}-tab-{{ $i }}"
                                x-ref="tab{{ $i }}"
                                aria-controls="{{ $uid }}-panel-{{ $i }}"
                                aria-selected="{{ $i === 0 ? 'true' : 'false' }}"
                                tabindex="{{ $i === 0 ? '0' : '-1' }}"
                                x-bind:aria-selected="tab === {{ $i }} ? 'true' : 'false'"
                                x-bind:tabindex="tab === {{ $i }} ? 0 : -1"
                                x-on:click="tab = {{ $i }}"
                                x-bind:class="tab === {{ $i }}
                                    ? 'bg-gradient-to-r from-brand-600 to-violet-600 text-white shadow-md shadow-brand-600/25'
                                    : 'text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white'"
                                class="whitespace-nowrap rounded-full px-5 py-2.5 text-sm font-semibold transition duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60"
                            >{{ $tab['label'] }}</button>
                        @endforeach
                    </div>
                </div>
            @endif

            @foreach ($tabs as $i => $tab)
                <div
                    @if ($tabs->count() > 1)
                        role="tabpanel"
                        id="{{ $uid }}-panel-{{ $i }}"
                        aria-labelledby="{{ $uid }}-tab-{{ $i }}"
                        tabindex="0"
                        x-show="tab === {{ $i }}"
                        x-transition:enter="transition ease-out duration-300"
                        x-transition:enter-start="opacity-0 translate-y-2"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        @if ($i > 0) x-cloak @endif
                    @endif
                    @class(['rounded-2xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40', 'mt-10' => $tabs->count() > 1])
                >
                    @if ($tab['intro'] !== '')
                        <p class="mx-auto mb-10 max-w-2xl text-center text-base text-slate-600 dark:text-slate-300">{{ $tab['intro'] }}</p>
                    @endif

                    <ul role="list" @class([
                        'grid gap-5 sm:grid-cols-2',
                        'lg:grid-cols-3' => $tab['cards']->count() !== 4,
                        'lg:grid-cols-4' => $tab['cards']->count() === 4,
                    ])>
                        @foreach ($tab['cards'] as $card)
                            <li data-fx="tilt" data-fx-delay="{{ ($loop->index % 4) + 1 }}" class="group relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white/80 p-6 shadow-sm backdrop-blur transition duration-300 hover:-translate-y-1 hover:border-brand-300 hover:shadow-card-hover dark:border-white/10 dark:bg-white/[0.03] dark:hover:border-brand-400/40">
                                <span class="pointer-events-none absolute inset-0 bg-gradient-to-br from-brand-500/[0.06] via-transparent to-transparent opacity-0 transition duration-300 group-hover:opacity-100" aria-hidden="true"></span>
                                <span class="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-brand-500/60 to-transparent opacity-0 transition duration-300 group-hover:opacity-100" aria-hidden="true"></span>
                                <span class="relative inline-flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br {{ $tab['tile'] }} text-white shadow-lg ring-1 ring-inset ring-white/10">
                                    <x-ui.icon :name="data_get($card, 'content.icon') ?: 'check-badge'" class="h-5 w-5" />
                                </span>
                                <h3 class="relative mt-5 text-base font-semibold text-slate-900 dark:text-white">{{ data_get($card, 'content.title') }}</h3>
                                @if (filled(data_get($card, 'content.text')))
                                    <p class="relative mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-400">{{ data_get($card, 'content.text') }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </x-site.section>
@endif
