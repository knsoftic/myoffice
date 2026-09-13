{{--
    One repeater of a section (phase-03 §8.5 `x-cms.repeater`, §8.7 statistics).

    @include('admin.cms.sections.partials.repeater', [
        'section' => $section,
        'group' => 'statistic',
        'repeater' => SectionRegistry::repeater($key, 'statistic'),
        'items' => $draft['items']['statistic'] ?? [],   // canonical item arrays, sort_order asc, disabled included
        'refs' => $refs,
        'statistics' => $statistics,                     // StatisticsProvider::all(): metric value => ?string
        'canEdit' => $canEdit,
    ])

    min / max are enforced here AND in SectionService (upsertItem / deleteItem). Reordering posts the
    group's full id list as `order` to admin.website.sections.items.reorder {section, group}. The enable
    switch posts `enabled` to admin.website.section-items.toggle {item}; a disabled item stays listed and
    leaves the next published snapshot (FT-45).

    Every item form posts the same `item[...]` names, so after a refused save the flashed input and the
    errors belong to exactly one form (named by `_item`): they are hidden from the others while those
    render, then restored.
--}}

@php
    use App\Enums\Cms\StatisticMetric;
    use App\Enums\Cms\StatisticValueMode;
    use Illuminate\Support\ViewErrorBag;

    $items = collect($items ?? [])->values();
    $count = $items->count();
    $min = (int) ($repeater['min'] ?? 0);
    $max = (int) ($repeater['max'] ?? 20);
    $noun = (string) ($repeater['item_label'] ?? 'item');
    $labelField = (string) ($repeater['item_label_field'] ?? 'label');
    $canEdit = (bool) ($canEdit ?? false);
    $sortable = $canEdit && $count > 1;
    $statistics = $statistics ?? [];
    $failedForm = old('_item');
    $newKey = 'new_'.$group;

    $emptyErrors = new ViewErrorBag();
    // The store old() reads is the request's session; `_old_input` is already flagged as flash data, so
    // restoring it here does not keep it past this request.
    $store = request()->hasSession() ? request()->session() : null;
    $isolate = static function (bool $own) use ($store) {
        if ($own) {
            return null;
        }

        $saved = ['errors' => view()->shared('errors'), 'old' => $store?->get('_old_input')];
        view()->share('errors', new ViewErrorBag());
        $store?->forget('_old_input');

        return $saved;
    };
    $restore = static function (?array $saved) use ($store): void {
        if ($saved === null) {
            return;
        }

        view()->share('errors', $saved['errors'] ?? new ViewErrorBag());

        if ($saved['old'] !== null) {
            $store?->put('_old_input', $saved['old']);
        }
    };
@endphp

<section class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200/70 dark:bg-slate-900 dark:ring-slate-800" aria-labelledby="repeater-{{ $group }}">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200/80 px-4 py-3.5 sm:px-5 dark:border-slate-800">
        <div class="min-w-0">
            <h3 id="repeater-{{ $group }}" class="text-sm font-semibold text-slate-900 dark:text-white">
                {{ $repeater['label'] ?? \Illuminate\Support\Str::headline($group) }}
                <span class="ml-1 text-xs font-normal text-slate-500 dark:text-slate-400">{{ $count }} of {{ $max }}</span>
            </h3>
            @if (filled($repeater['help'] ?? null))
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ $repeater['help'] }}</p>
            @endif
        </div>

        @if ($min > 0)
            <x-ui.badge color="slate" variant="outline" size="sm">At least {{ $min }}</x-ui.badge>
        @endif
    </div>

    @if ($count === 0)
        <x-ui.empty-state
            :compact="true"
            icon="rectangle-stack"
            :title="'No '.\Illuminate\Support\Str::plural($noun).' yet'"
            :message="'Add up to '.$max.'.'"
        />
    @else
        <div
            x-data="cmsSortable(@js([
                'url' => route('admin.website.sections.items.reorder', ['section' => $section, 'group' => $group]),
                'key' => 'order',
                'noun' => \Illuminate\Support\Str::ucfirst($noun),
                'disabled' => ! $sortable,
            ]))"
        >
            <p class="sr-only" aria-live="polite" x-text="announcement"></p>

            <ul data-sortable-list class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($items as $item)
                    @php
                        $itemId = (int) $item['id'];
                        $itemContent = is_array($item['content'] ?? null) ? $item['content'] : [];
                        $enabled = (bool) ($item['is_enabled'] ?? true);
                        $summary = trim((string) ($itemContent[$labelField] ?? '')) ?: ucfirst($noun).' #'.$itemId;
                        $isOpen = $failedForm === (string) $itemId;
                        $metric = StatisticMetric::tryFrom((string) ($item['metric'] ?? ''));
                        $isLive = StatisticValueMode::tryFrom((string) ($item['value_mode'] ?? '')) === StatisticValueMode::Auto;
                        $manual = $item['manual_value'] ?? null;
                        $liveValue = $isLive && $metric !== null ? ($statistics[$metric->value] ?? null) : null;
                    @endphp

                    <li
                        data-sortable-id="{{ $itemId }}"
                        data-sortable-label="{{ $summary }}"
                        x-data="{ open: @js($isOpen) }"
                        x-on:dragstart="dragStart($event)"
                        x-on:dragover.prevent="dragOver($event)"
                        x-on:drop.prevent="drop()"
                        x-on:dragend="dragEnd($event)"
                        @class(['px-4 py-3 sm:px-5', 'bg-slate-50/60 dark:bg-slate-800/30' => ! $enabled])
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                x-on:pointerdown="arm($event)"
                                @disabled(! $sortable)
                                aria-roledescription="drag handle"
                                aria-label="Reorder {{ $summary }}"
                                @class([
                                    'inline-flex h-8 w-6 shrink-0 items-center justify-center rounded-md text-slate-400 dark:text-slate-500',
                                    'cursor-grab hover:bg-slate-100 active:cursor-grabbing dark:hover:bg-slate-800' => $sortable,
                                    'cursor-not-allowed opacity-40' => ! $sortable,
                                ])
                            >
                                <x-ui.icon name="ellipsis-vertical" class="h-5 w-5" />
                            </button>

                            <button
                                type="button"
                                x-on:click="open = ! open"
                                x-bind:aria-expanded="open ? 'true' : 'false'"
                                class="flex min-w-0 flex-1 items-center gap-2 text-left"
                            >
                                <x-ui.icon name="chevron-right" class="h-4 w-4 shrink-0 text-slate-400 transition-transform" x-bind:class="open ? 'rotate-90' : ''" />
                                <span @class(['truncate text-sm font-medium', 'text-slate-900 dark:text-white' => $enabled, 'text-slate-500 line-through dark:text-slate-400' => ! $enabled])>{{ $summary }}</span>
                            </button>

                            {{-- Statistics: the resolved value inline (§8.7, INV-12) --}}
                            @if ($group === 'statistic')
                                <span class="flex flex-wrap items-center gap-1.5 text-xs">
                                    @if ($isLive)
                                        @if ($liveValue !== null)
                                            <x-ui.badge color="emerald" size="sm" :dot="true">Live</x-ui.badge>
                                            <span class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ app_number((string) $liveValue) }}</span>
                                            @if ($metric)
                                                <span class="text-slate-500 dark:text-slate-400">from {{ $metric->defaultLabel() }}</span>
                                            @endif
                                        @else
                                            <x-ui.badge color="amber" size="sm" icon="exclamation-triangle">Not resolvable</x-ui.badge>
                                            <span class="text-amber-700 dark:text-amber-400">
                                                @if ($metric?->module())
                                                    The {{ \Illuminate\Support\Str::headline((string) $metric->module()) }} module is not available yet.
                                                @else
                                                    The live count is unavailable.
                                                @endif
                                                @if ($manual !== null)
                                                    The manual value {{ app_number((string) $manual) }} is shown instead.
                                                @else
                                                    Nothing is shown until it resolves or a manual value is entered.
                                                @endif
                                            </span>
                                        @endif
                                    @elseif ($manual !== null)
                                        <span class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ app_number((string) $manual) }}</span>
                                    @else
                                        <span class="text-amber-700 dark:text-amber-400">No number — renders nothing</span>
                                    @endif
                                </span>
                            @endif

                            <div class="ml-auto flex items-center gap-1">
                                @if ($sortable)
                                    <x-ui.icon-button icon="chevron-up" size="xs" label="Move {{ $summary }} up" x-on:click="move($el, -1)" />
                                    <x-ui.icon-button icon="chevron-down" size="xs" label="Move {{ $summary }} down" x-on:click="move($el, 1)" />
                                @endif

                                @if ($canEdit)
                                    <form method="POST" action="{{ route('admin.website.section-items.toggle', ['item' => $itemId]) }}">
                                        @csrf
                                        <input type="hidden" name="enabled" value="{{ $enabled ? 0 : 1 }}">
                                        <x-ui.icon-button type="submit" :icon="$enabled ? 'eye' : 'eye-slash'" size="xs" :label="($enabled ? 'Hide ' : 'Show ').$summary" />
                                    </form>

                                    @if ($count <= $min)
                                        <x-ui.icon-button icon="trash" size="xs" :label="'This list needs at least '.$min" :disabled="true" />
                                    @else
                                        <x-ui.confirm
                                            :action="route('admin.website.section-items.destroy', ['item' => $itemId])"
                                            :title="'Delete '.$summary.'?'"
                                            message="It leaves the draft now and the public site at the next publish. The row goes to the trash and stays in the revision history."
                                            :confirm-label="'Delete '.$noun"
                                        >
                                            <x-slot:trigger>
                                                <x-ui.icon-button icon="trash" variant="danger" size="xs" label="Delete {{ $summary }}" />
                                            </x-slot:trigger>
                                        </x-ui.confirm>
                                    @endif
                                @endif
                            </div>
                        </div>

                        <div x-show="open" x-cloak class="mt-3 border-t border-slate-100 pt-3 dark:border-slate-800">
                            @php $saved = $isolate($isOpen); @endphp
                            @include('admin.cms.sections.partials.item-form', [
                                'section' => $section,
                                'group' => $group,
                                'repeater' => $repeater,
                                'item' => $item,
                                'refs' => $refs ?? [],
                                'readonly' => ! $canEdit,
                                'errors' => $isOpen ? $errors : $emptyErrors,
                            ])
                            @php $restore($saved); @endphp
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($canEdit)
        <div class="border-t border-slate-200/80 px-4 py-3 sm:px-5 dark:border-slate-800" x-data="{ adding: @js($failedForm === $newKey) }">
            @if ($count >= $max)
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    This list is full ({{ $max }} {{ \Illuminate\Support\Str::plural($noun, $max) }}). Delete one to add another.
                </p>
            @else
                <x-ui.button size="sm" variant="secondary" icon="plus" x-show="! adding" x-on:click="adding = true">
                    Add {{ $noun }}
                </x-ui.button>

                <div x-show="adding" x-cloak>
                    @php $savedNew = $isolate($failedForm === $newKey); @endphp
                    @include('admin.cms.sections.partials.item-form', [
                        'section' => $section,
                        'group' => $group,
                        'repeater' => $repeater,
                        'item' => null,
                        'refs' => $refs ?? [],
                        'readonly' => false,
                        'errors' => $failedForm === $newKey ? $errors : $emptyErrors,
                    ])
                    @php $restore($savedNew); @endphp
                    <x-ui.button size="sm" variant="ghost" class="mt-2" x-on:click="adding = false">Cancel</x-ui.button>
                </div>
            @endif
        </div>
    @endif
</section>
