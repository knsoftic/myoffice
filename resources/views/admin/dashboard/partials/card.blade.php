{{--
    One widget card: the chrome, the three body states, and the customise controls.

    The card is generic — it renders whatever `$card['descriptor']` declares. The three body states
    are all present in the markup from the start, which is the point:

      data-widget-skeleton   an x-ui.skeleton matched to this card's real body (T18's first use)
      data-widget-body       the rendered figures
      data-widget-error      a retryable failure

    Because the skeleton is server-rendered once and merely shown or hidden, a refresh re-uses it
    instead of JavaScript having to reproduce the card's shape — there is no second markup
    vocabulary anywhere in this feature.

    Expects: $card (descriptor/data/deferred/hidden/failed), $range, $widgetUrlTemplate.
--}}

@php
    /** @var \App\Dashboard\WidgetDescriptor $widget */
    $widget = $card['descriptor'];
    $key = $widget->key;

    $widgetUrl = $widgetUrlTemplate === null
        ? null
        : str_replace('__key__', $key, $widgetUrlTemplate);

    // Deferred (expensive, or switched off by this user) starts on the skeleton; failed starts on
    // the error state; everything else has its figures already in the HTML.
    $state = $card['failed'] ? 'error' : ($card['deferred'] ? 'loading' : 'ready');

    $bodyPadding = $widget->padded ? '' : 'p-4 sm:p-5';
@endphp

<article
    id="widget-{{ $key }}"
    data-widget-key="{{ $key }}"
    data-widget-state="{{ $state }}"
    @if ($widgetUrl) data-widget-url="{{ $widgetUrl }}" @endif
    @class([
        $widget->spanClasses(),
        'group/widget relative transition-opacity duration-150',
        'hidden' => $card['hidden'],
    ])
    x-bind:class="cardClasses(keyOf($el))"
    x-bind:draggable="customising ? 'true' : 'false'"
    x-on:dragstart="onDragStart($event, keyOf($el))"
    x-on:dragover.prevent="onDragOver($event, keyOf($el))"
    x-on:dragend="onDragEnd()"
    x-on:drop.prevent="onDragEnd()"
>
    <x-ui.card
        :title="$widget->title"
        :subtitle="$widget->subtitle"
        :icon="$widget->icon"
        :padded="$widget->padded"
        class="flex h-full flex-col"
    >
        <x-slot:actions>
            {{-- ── Customise mode: reorder and hide ──────────────────────────────────────── --}}
            <div class="flex items-center gap-0.5" x-show="customising" style="display: none">
                <x-ui.icon-button
                    icon="chevron-left"
                    label="Move earlier"
                    size="xs"
                    variant="secondary"
                    x-on:click="move(keyOf($el), -1)"
                />
                <x-ui.icon-button
                    icon="chevron-right"
                    label="Move later"
                    size="xs"
                    variant="secondary"
                    x-on:click="move(keyOf($el), 1)"
                />
                {{-- Two buttons rather than one bound icon: a Blade prop is resolved on the
                     server, so an icon name cannot be an Alpine expression. --}}
                <span x-show="! isHidden(keyOf($el))">
                    <x-ui.icon-button
                        icon="eye-slash"
                        label="Hide this card"
                        size="xs"
                        variant="secondary"
                        x-on:click="toggleHidden(keyOf($el))"
                    />
                </span>

                <span x-show="isHidden(keyOf($el))" style="display: none">
                    <x-ui.icon-button
                        icon="eye"
                        label="Show this card"
                        size="xs"
                        variant="primary"
                        x-on:click="toggleHidden(keyOf($el))"
                    />
                </span>
            </div>

            {{-- ── Normal mode: refresh and "view all" ───────────────────────────────────── --}}
            <div class="flex items-center gap-0.5" x-show="! customising">
                @if ($widgetUrl)
                    <x-ui.icon-button
                        icon="arrow-path"
                        label="Refresh {{ $widget->title }}"
                        size="xs"
                        x-on:click="load(keyOf($el), true)"
                        x-bind:disabled="isLoading(keyOf($el))"
                    />
                @endif

                @if ($widget->href)
                    <x-ui.icon-button
                        icon="arrow-top-right-on-square"
                        label="Open {{ $widget->title }}"
                        size="xs"
                        :href="$widget->href"
                    />
                @endif
            </div>
        </x-slot:actions>

        <div
            class="relative flex-1"
            @if ($widget->minHeight) style="min-height: {{ $widget->minHeight }}px" @endif
        >
            {{-- ── Loading ───────────────────────────────────────────────────────────────── --}}
            <div
                data-widget-skeleton
                @class([$bodyPadding, 'hidden' => $state !== 'loading'])
                aria-hidden="true"
            >
                @if ($widget->skeleton === 'row')
                    <table class="w-full"><tbody><x-ui.skeleton variant="row" :count="5" :columns="4" /></tbody></table>
                @else
                    <x-ui.skeleton :variant="$widget->skeleton" :count="$widget->skeleton === 'card' ? 2 : 4" />
                @endif
            </div>

            {{-- ── Ready ─────────────────────────────────────────────────────────────────── --}}
            <div
                data-widget-body
                @class(['hidden' => $state !== 'ready'])
                aria-live="polite"
                aria-atomic="true"
            >
                @if ($state === 'ready')
                    @include('admin.dashboard.partials.body', [
                        'widget' => $widget,
                        'data' => $card['data'] ?? [],
                        'range' => $range,
                    ])
                @endif
            </div>

            {{-- ── Failed ────────────────────────────────────────────────────────────────── --}}
            <div data-widget-error @class([$bodyPadding, 'hidden' => $state !== 'error'])>
                <x-ui.empty-state
                    icon="exclamation-triangle"
                    title="This card could not be loaded"
                    :compact="true"
                >
                    <span data-widget-error-message>
                        The query behind it failed. Nothing else on the dashboard is affected.
                    </span>

                    @if ($widgetUrl)
                        <x-slot:action>
                            <x-ui.button size="sm" variant="secondary" icon="arrow-path" x-on:click="load(keyOf($el), true)">
                                Try again
                            </x-ui.button>
                        </x-slot:action>
                    @endif
                </x-ui.empty-state>
            </div>

            {{--
                No JavaScript and a deferred card: the figures were never fetched, so offer the
                one URL that renders every card inline instead of pretending the card is loading.
            --}}
            @if ($state === 'loading')
                <noscript>
                    <div class="{{ $bodyPadding ?: '' }}">
                        <x-ui.empty-state
                            :icon="$widget->icon"
                            title="Loads on demand"
                            message="This card is measured only when asked for, to keep the dashboard fast."
                            :compact="true"
                        >
                            <x-slot:action>
                                <x-ui.button
                                    size="sm"
                                    variant="secondary"
                                    icon="arrow-path"
                                    :href="request()->fullUrlWithQuery(['eager' => 1])"
                                >
                                    Load it now
                                </x-ui.button>
                            </x-slot:action>
                        </x-ui.empty-state>
                    </div>
                </noscript>
            @endif
        </div>

        @if ($widget->href)
            <x-slot:footer>
                <a
                    href="{{ $widget->href }}"
                    class="inline-flex items-center gap-1.5 font-medium text-slate-600 transition-colors hover:text-brand-600 dark:text-slate-300 dark:hover:text-brand-400"
                >
                    View all
                    <x-ui.icon name="arrow-right" class="h-3.5 w-3.5" />
                </a>
            </x-slot:footer>
        @endif
    </x-ui.card>

    {{-- Drag affordance, only while customising. --}}
    <div
        class="pointer-events-none absolute inset-0 rounded-xl ring-2 ring-brand-500/0 transition-all"
        x-bind:class="customising ? 'ring-brand-500/30' : ''"
        aria-hidden="true"
    ></div>
</article>
