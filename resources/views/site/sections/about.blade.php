{{--
    Section type `about` (placements home, page) — requirement §10, phase-03 §6.1, §8.8.

    Receives:
      $section  the published snapshot (anchor)
      $content  heading, tabs_enabled, company_intro, software_house_intro, institute_intro, mission,
                vision, history_intro (all rich text, already sanitised and re-sanitised by
                <x-site.prose>), why_choose_us_heading
      $items    why_choose_us => [icon, title, text], history => [year, title, text], statistic => [...]
      $media    image_1, image_2 (card profile)

    Layout, top to bottom, each block rendered only when it has content:
      1. heading + the three introductions — as accessible tabs (role=tablist, arrow keys, Home/End)
         when `tabs_enabled` and at least two are filled, otherwise stacked — beside the image pair;
      2. mission and vision cards;
      3. the statistics panel (same engine as the hero, <x-site.stats>);
      4. why choose us;
      5. the history timeline.

    Short structural labels (the tab names, "Mission", "Vision", "History") come from the registry's
    own field labels, so the words an editor sees in the form are the words a visitor sees here.
--}}

@php
    use App\Support\Cms\SectionRegistry;

    $fields = (array) ($content ?? []);
    $media = (array) ($media ?? []);
    $items = (array) ($items ?? []);

    $label = static function (string $field, string $fallback): string {
        $definition = SectionRegistry::exists('about') ? SectionRegistry::field('about', $field) : null;
        $text = trim((string) ($definition['label'] ?? $fallback));

        return trim((string) preg_replace('/\s+introduction$/i', '', $text)) ?: $fallback;
    };

    $filledHtml = static fn (mixed $html): bool => is_string($html) && (trim(strip_tags($html)) !== '' || preg_match('/<(?:img|iframe)/i', $html) === 1);

    $heading = trim((string) data_get($fields, 'heading', ''));

    $intros = collect([
        'company_intro' => 'Company',
        'software_house_intro' => 'Software house',
        'institute_intro' => 'Institute',
    ])
        ->map(static fn (string $fallback, string $field): array => [
            'field' => $field,
            'label' => $label($field, $fallback),
            'html' => data_get($fields, $field),
        ])
        ->filter(static fn (array $intro): bool => $filledHtml($intro['html']))
        ->values();

    $useTabs = (bool) data_get($fields, 'tabs_enabled', true) && $intros->count() > 1;

    $mission = data_get($fields, 'mission');
    $vision = data_get($fields, 'vision');
    $pillars = collect([
        ['label' => $label('mission', 'Mission'), 'html' => $mission, 'icon' => 'flag'],
        ['label' => $label('vision', 'Vision'), 'html' => $vision, 'icon' => 'eye'],
    ])->filter(static fn (array $pillar): bool => $filledHtml($pillar['html']))->values();

    $images = collect([data_get($media, 'image_1'), data_get($media, 'image_2')])
        ->filter(static fn ($image): bool => is_array($image) && filled($image['url'] ?? null))
        ->values();

    $reasons = collect($items['why_choose_us'] ?? [])
        ->filter(static fn ($item): bool => filled(data_get($item, 'content.title')))
        ->values();

    $milestones = collect($items['history'] ?? [])
        ->filter(static fn ($item): bool => filled(data_get($item, 'content.title')))
        ->values();

    $historyIntro = data_get($fields, 'history_intro');
    $whyHeading = trim((string) data_get($fields, 'why_choose_us_heading', ''));
    $statistics = (array) ($items['statistic'] ?? []);

    $lazy = filter_var(site_setting('website.image_lazy_loading', true), FILTER_VALIDATE_BOOLEAN);
    $uid = 'about-'.(data_get($section ?? null, 'id') ?? 'x');
@endphp

<x-site.section :anchor="data_get($section ?? null, 'anchor')" background="surface" :label="$heading !== '' ? $heading : null">
    @if ($heading !== '' || $intros->isNotEmpty() || $images->isNotEmpty())
        <div @class([
            'grid gap-12 lg:grid-cols-12 lg:gap-16',
            'items-center' => $images->isNotEmpty(),
        ])>
            <div @class([
                'lg:col-span-7' => $images->isNotEmpty(),
                'lg:col-span-12 lg:max-w-4xl' => $images->isEmpty(),
            ])>
                <x-site.heading :title="$heading" align="left" />

                @if ($useTabs)
                    <div
                        class="mt-8"
                        x-data="{
                            tab: 0,
                            count: {{ $intros->count() }},
                            select(index) {
                                this.tab = (index + this.count) % this.count;
                                this.$nextTick(() => this.$refs['tab' + this.tab]?.focus());
                            },
                        }"
                    >
                        <div
                            role="tablist"
                            aria-label="{{ $heading !== '' ? $heading : 'About' }}"
                            class="inline-flex max-w-full gap-1 overflow-x-auto rounded-xl bg-slate-100 p-1 ring-1 ring-inset ring-slate-200/70 dark:bg-white/5 dark:ring-white/10"
                            x-on:keydown.arrow-right.prevent="select(tab + 1)"
                            x-on:keydown.arrow-left.prevent="select(tab - 1)"
                            x-on:keydown.home.prevent="select(0)"
                            x-on:keydown.end.prevent="select(count - 1)"
                        >
                            @foreach ($intros as $i => $intro)
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
                                        ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-800 dark:text-white'
                                        : 'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'"
                                    class="whitespace-nowrap rounded-lg px-4 py-2 text-sm font-semibold transition duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60"
                                >{{ $intro['label'] }}</button>
                            @endforeach
                        </div>

                        @foreach ($intros as $i => $intro)
                            <div
                                role="tabpanel"
                                id="{{ $uid }}-panel-{{ $i }}"
                                aria-labelledby="{{ $uid }}-tab-{{ $i }}"
                                tabindex="0"
                                x-show="tab === {{ $i }}"
                                @if ($i > 0) x-cloak @endif
                                class="mt-6 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40"
                            >
                                <x-site.prose :html="$intro['html']" size="lg" />
                            </div>
                        @endforeach
                    </div>
                @elseif ($intros->isNotEmpty())
                    <div class="mt-8 space-y-8">
                        @foreach ($intros as $intro)
                            <div>
                                @if ($intros->count() > 1)
                                    <h3 class="text-sm font-semibold uppercase tracking-[0.12em] text-brand-600 dark:text-brand-400">{{ $intro['label'] }}</h3>
                                @endif
                                <x-site.prose :html="$intro['html']" size="lg" @class(['mt-3' => $intros->count() > 1]) />
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($images->isNotEmpty())
                <div class="lg:col-span-5">
                    @if ($images->count() > 1)
                        <div class="relative pb-16 pl-10 sm:pl-16">
                            <div class="aspect-[4/5] overflow-hidden rounded-2xl bg-slate-100 shadow-xl ring-1 ring-slate-900/5 dark:bg-slate-800 dark:ring-white/10">
                                <x-site.image :media="$images[0]" profile="card" :lazy="$lazy" class="h-full w-full" />
                            </div>
                            <div class="absolute bottom-0 left-0 w-1/2 overflow-hidden rounded-2xl bg-slate-100 shadow-2xl ring-8 ring-white dark:bg-slate-800 dark:ring-slate-950">
                                <div class="aspect-square">
                                    <x-site.image :media="$images[1]" profile="card" :lazy="$lazy" class="h-full w-full" />
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="aspect-[4/3] overflow-hidden rounded-2xl bg-slate-100 shadow-xl ring-1 ring-slate-900/5 dark:bg-slate-800 dark:ring-white/10">
                            <x-site.image :media="$images[0]" profile="card" :lazy="$lazy" class="h-full w-full" />
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @endif

    @if ($pillars->isNotEmpty())
        <div @class([
            'mt-16 grid gap-6 lg:mt-20',
            'md:grid-cols-2' => $pillars->count() > 1,
        ])>
            @foreach ($pillars as $pillar)
                <div class="relative overflow-hidden rounded-2xl border border-slate-200 bg-slate-50/70 p-8 dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="absolute -right-10 -top-10 h-32 w-32 rounded-full bg-brand-500/10 blur-2xl" aria-hidden="true"></div>
                    <div class="relative flex items-center gap-3">
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-600 text-white shadow-sm dark:bg-brand-500">
                            <x-ui.icon :name="$pillar['icon']" class="h-5 w-5" />
                        </span>
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white">{{ $pillar['label'] }}</h3>
                    </div>
                    <x-site.prose :html="$pillar['html']" class="relative mt-4" />
                </div>
            @endforeach
        </div>
    @endif

    @if ($statistics !== [])
        <x-site.stats :items="$statistics" class="mt-16 lg:mt-20" />
    @endif

    @if ($reasons->isNotEmpty())
        <div class="mt-20 lg:mt-28">
            @if ($whyHeading !== '')
                <h3 class="max-w-2xl text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl dark:text-white">{{ $whyHeading }}</h3>
            @endif

            <ul role="list" @class([
                'grid gap-6 sm:grid-cols-2 lg:grid-cols-3',
                'mt-10' => $whyHeading !== '',
            ])>
                @foreach ($reasons as $reason)
                    <li class="group rounded-2xl border border-slate-200 bg-white p-6 shadow-card transition duration-200 hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-card-hover dark:border-white/10 dark:bg-slate-900/60 dark:hover:border-brand-500/40">
                        <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600 ring-1 ring-inset ring-brand-100 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/20">
                            <x-ui.icon :name="data_get($reason, 'content.icon') ?: 'check-badge'" class="h-5 w-5" />
                        </span>
                        <h4 class="mt-5 text-base font-semibold text-slate-900 dark:text-white">{{ data_get($reason, 'content.title') }}</h4>
                        @if (filled(data_get($reason, 'content.text')))
                            <p class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-400">{{ data_get($reason, 'content.text') }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($milestones->isNotEmpty() || $filledHtml($historyIntro))
        <div class="mt-20 grid gap-12 lg:mt-28 lg:grid-cols-12 lg:gap-16">
            <div class="lg:col-span-5">
                <h3 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl dark:text-white">{{ $label('history_intro', 'History') }}</h3>
                @if ($filledHtml($historyIntro))
                    <x-site.prose :html="$historyIntro" class="mt-4" />
                @endif
            </div>

            @if ($milestones->isNotEmpty())
                <ol role="list" class="relative space-y-10 border-l border-slate-200 pl-8 lg:col-span-7 dark:border-white/10">
                    @foreach ($milestones as $milestone)
                        <li class="relative">
                            <span class="absolute -left-[calc(2.4375rem+0.5px)] top-1 flex h-3.5 w-3.5 items-center justify-center rounded-full bg-brand-600 ring-4 ring-white dark:bg-brand-400 dark:ring-slate-950" aria-hidden="true"></span>
                            @if (filled(data_get($milestone, 'content.year')))
                                <p class="text-sm font-semibold tabular-nums text-brand-600 dark:text-brand-400">{{ data_get($milestone, 'content.year') }}</p>
                            @endif
                            <h4 class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">{{ data_get($milestone, 'content.title') }}</h4>
                            @if (filled(data_get($milestone, 'content.text')))
                                <p class="mt-2 max-w-xl text-sm leading-relaxed text-slate-600 dark:text-slate-400">{{ data_get($milestone, 'content.text') }}</p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    @endif
</x-site.section>
