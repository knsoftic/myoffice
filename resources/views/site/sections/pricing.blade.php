{{--
    Section type `pricing` (LandingSectionTypes) — plans side by side, a monthly / yearly switch, one
    highlighted plan.

    Receives:
      $section  the published snapshot (id, anchor)
      $content  eyebrow, heading, description, billing_toggle, monthly_label, yearly_label, yearly_badge,
                period_label, yearly_note, footnote
      $items    plan => [name, badge, tagline, monthly_price, yearly_price, highlighted, features, button]

    Prices are the editor's text, shown as typed ("$29", "Rs 4,999", "Custom"); `period_label` follows a
    price only when it has a digit in it, so "Custom" never reads "Custom/mo". The switch appears only when
    it changes something — the setting is on and at least one plan has a yearly price — and a plan with no
    yearly price shows its monthly one either way. Without JavaScript the monthly prices show.
--}}

@php
    $fields = (array) ($content ?? []);
    $eyebrow = trim((string) data_get($fields, 'eyebrow', ''));
    $heading = trim((string) data_get($fields, 'heading', ''));
    $description = trim((string) data_get($fields, 'description', ''));
    $monthlyLabel = trim((string) data_get($fields, 'monthly_label', '')) ?: 'Monthly';
    $yearlyLabel = trim((string) data_get($fields, 'yearly_label', '')) ?: 'Yearly';
    $yearlyBadge = trim((string) data_get($fields, 'yearly_badge', ''));
    $period = trim((string) data_get($fields, 'period_label', ''));
    $yearlyNote = trim((string) data_get($fields, 'yearly_note', ''));
    $footnote = trim((string) data_get($fields, 'footnote', ''));

    $plans = collect(data_get($items ?? [], 'plan', []))
        ->filter(static fn ($item): bool => filled(data_get($item, 'content.name')))
        ->map(static function ($item): array {
            $monthly = trim((string) data_get($item, 'content.monthly_price', ''));
            $yearly = trim((string) data_get($item, 'content.yearly_price', ''));
            $button = data_get($item, 'content.button');

            return [
                'name' => trim((string) data_get($item, 'content.name')),
                'badge' => trim((string) data_get($item, 'content.badge', '')),
                'tagline' => trim((string) data_get($item, 'content.tagline', '')),
                'monthly' => $monthly,
                'yearly' => $yearly !== '' ? $yearly : $monthly,
                'has_yearly' => $yearly !== '',
                'highlighted' => (bool) data_get($item, 'content.highlighted', false),
                'features' => collect(preg_split('/\R/', (string) data_get($item, 'content.features', '')) ?: [])
                    ->map(static fn ($line): string => trim((string) $line))
                    ->filter(static fn (string $line): bool => $line !== '')
                    ->values(),
                'button' => is_array($button) && filled($button['label'] ?? null) && filled($button['url'] ?? null) ? $button : null,
            ];
        })
        ->values();

    $showToggle = (bool) data_get($fields, 'billing_toggle', true) && $plans->contains('has_yearly', true);
    $numeric = static fn (string $price): bool => preg_match('/\d/', $price) === 1;

    $columns = match ($plans->count()) {
        1 => 'max-w-md',
        2 => 'max-w-4xl md:grid-cols-2',
        4 => 'max-w-7xl md:grid-cols-2 xl:grid-cols-4',
        default => 'max-w-6xl lg:grid-cols-3',
    };
@endphp

@if ($plans->isNotEmpty())
    <x-site.section :anchor="data_get($section ?? null, 'anchor')" background="none" :label="$heading !== '' ? $heading : null" class="isolate overflow-hidden">
        <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
            <div class="absolute inset-0 bg-slate-50 dark:bg-slate-900/40"></div>
            <div class="absolute -left-24 top-1/3 h-72 w-72 rounded-full bg-violet-400/15 blur-3xl dark:bg-violet-600/15"></div>
            <div class="absolute -right-24 bottom-10 h-72 w-72 rounded-full bg-cyan-300/15 blur-3xl dark:bg-cyan-500/10"></div>
        </div>

        <div x-data="{ yearly: false }">
            <x-site.heading data-fx="rise" :eyebrow="$eyebrow" :title="$heading" :subtitle="$description" align="center" />

            @if ($showToggle)
                <div data-fx="rise" data-fx-delay="1" class="mt-10 flex justify-center">
                    <div role="group" aria-label="Billing period" class="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-white/80 p-1 shadow-sm backdrop-blur dark:border-white/10 dark:bg-white/5">
                        <button
                            type="button"
                            x-on:click="yearly = false"
                            aria-pressed="true"
                            x-bind:aria-pressed="yearly ? 'false' : 'true'"
                            x-bind:class="! yearly ? 'bg-slate-900 text-white shadow dark:bg-white dark:text-slate-900' : 'text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white'"
                            class="rounded-full px-5 py-2 text-sm font-semibold transition duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60"
                        >{{ $monthlyLabel }}</button>
                        <button
                            type="button"
                            x-on:click="yearly = true"
                            aria-pressed="false"
                            x-bind:aria-pressed="yearly ? 'true' : 'false'"
                            x-bind:class="yearly ? 'bg-slate-900 text-white shadow dark:bg-white dark:text-slate-900' : 'text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white'"
                            class="inline-flex items-center gap-2 rounded-full px-5 py-2 text-sm font-semibold transition duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60"
                        >
                            {{ $yearlyLabel }}
                            @if ($yearlyBadge !== '')
                                <span class="rounded-full bg-gradient-to-r from-emerald-500 to-teal-500 px-2 py-0.5 text-[0.6875rem] font-bold text-white">{{ $yearlyBadge }}</span>
                            @endif
                        </button>
                    </div>
                </div>
            @endif

            <ul role="list" @class(['mx-auto grid items-stretch gap-6', $columns, 'mt-12' => ! $showToggle, 'mt-10' => $showToggle])>
                @foreach ($plans as $plan)
                    <li data-fx="tilt" data-fx-delay="{{ ($loop->index % 4) + 1 }}" @class([
                        'relative flex',
                        'rounded-3xl bg-gradient-to-b from-brand-500 via-violet-500 to-cyan-400 p-px shadow-2xl shadow-brand-600/20 lg:-my-3' => $plan['highlighted'],
                    ])>
                        @if ($plan['highlighted'] && $plan['badge'] !== '')
                            <span class="absolute -top-3.5 left-1/2 z-10 -translate-x-1/2 whitespace-nowrap rounded-full bg-gradient-to-r from-brand-600 to-violet-600 px-3.5 py-1 text-xs font-bold text-white shadow-lg shadow-brand-600/30">{{ $plan['badge'] }}</span>
                        @endif

                        <div @class([
                            'flex w-full flex-col p-7 sm:p-8',
                            'rounded-[calc(1.5rem-1px)] bg-white dark:bg-slate-950' => $plan['highlighted'],
                            'rounded-3xl border border-slate-200 bg-white/80 backdrop-blur transition duration-300 hover:-translate-y-1 hover:border-brand-300 hover:shadow-card-hover dark:border-white/10 dark:bg-white/[0.03] dark:hover:border-brand-400/40' => ! $plan['highlighted'],
                        ])>
                            <div class="flex items-center justify-between gap-3">
                                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">{{ $plan['name'] }}</h3>
                                @if (! $plan['highlighted'] && $plan['badge'] !== '')
                                    <span class="rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-semibold text-brand-700 ring-1 ring-inset ring-brand-100 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/20">{{ $plan['badge'] }}</span>
                                @endif
                            </div>
                            @if ($plan['tagline'] !== '')
                                <p class="mt-2 min-h-[2.5rem] text-sm leading-relaxed text-slate-600 dark:text-slate-400">{{ $plan['tagline'] }}</p>
                            @endif

                            <div class="mt-6">
                                @if ($plan['monthly'] !== '')
                                    <p class="flex items-baseline gap-1.5">
                                        <span x-show="! yearly" @class([
                                            'text-4xl font-extrabold tracking-tight tabular-nums sm:text-5xl',
                                            'bg-gradient-to-br from-brand-600 to-violet-600 bg-clip-text text-transparent dark:from-brand-300 dark:to-violet-300' => $plan['highlighted'],
                                            'text-slate-900 dark:text-white' => ! $plan['highlighted'],
                                        ])>{{ $plan['monthly'] }}</span>
                                        @if ($showToggle)
                                            <span x-show="yearly" x-cloak @class([
                                                'text-4xl font-extrabold tracking-tight tabular-nums sm:text-5xl',
                                                'bg-gradient-to-br from-brand-600 to-violet-600 bg-clip-text text-transparent dark:from-brand-300 dark:to-violet-300' => $plan['highlighted'],
                                                'text-slate-900 dark:text-white' => ! $plan['highlighted'],
                                            ])>{{ $plan['yearly'] }}</span>
                                        @endif
                                        @if ($period !== '' && $numeric($plan['monthly']))
                                            <span class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ $period }}</span>
                                        @endif
                                    </p>
                                    @if ($showToggle && $plan['has_yearly'] && $yearlyNote !== '')
                                        <p x-show="yearly" x-cloak class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $yearlyNote }}</p>
                                    @endif
                                @endif
                            </div>

                            @if ($plan['button'] !== null)
                                <div class="mt-7">
                                    <x-site.button :link="$plan['button']" :block="true" />
                                </div>
                            @endif

                            @if ($plan['features']->isNotEmpty())
                                <ul role="list" class="mt-8 space-y-3 border-t border-slate-200 pt-7 text-sm dark:border-white/10">
                                    @foreach ($plan['features'] as $feature)
                                        <li class="flex items-start gap-3 text-slate-700 dark:text-slate-300">
                                            <span @class([
                                                'mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full',
                                                'bg-gradient-to-br from-brand-500 to-violet-600 text-white' => $plan['highlighted'],
                                                'bg-brand-50 text-brand-600 ring-1 ring-inset ring-brand-100 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/20' => ! $plan['highlighted'],
                                            ]) aria-hidden="true">
                                                <x-ui.icon name="check" class="h-3 w-3" />
                                            </span>
                                            <span>{{ $feature }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>

            @if ($footnote !== '')
                <p data-fx="rise" class="mx-auto mt-10 max-w-2xl text-center text-sm text-slate-500 dark:text-slate-400">{{ $footnote }}</p>
            @endif
        </div>
    </x-site.section>
@endif
