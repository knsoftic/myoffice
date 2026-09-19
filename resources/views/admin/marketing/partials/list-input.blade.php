{{--
    An ordered list of short strings — service `features`, team and job `skills`, blog `tags`
    (phase-04 §2.3, §2.9, §2.18, §6.4 invariant 2, §6.7 invariant 4).

    @include('admin.marketing.partials.list-input', [
        'name' => 'features',            // posts features[] in the order shown
        'label' => 'Features',
        'values' => $service->features ?? [],
        'mode' => 'list',                // list = one text row per entry, drag + move buttons
                                         // tags = chips; Enter or comma adds, Backspace removes the last
        'max' => 20,                     // entries (the Form Request enforces it too)
        'maxLength' => 150,              // characters per entry
        'placeholder' => 'Add a feature',
        'suggestions' => [],             // tags mode: offered through a <datalist> (create-on-type still works)
        'help' => null,
        'readonly' => false,
    ])

    Blank entries are dropped in the browser and again by the service. An empty list posts `name` = ''
    so a cleared list is saved as null rather than silently keeping the old value. Per-entry errors
    (`features.3`) are listed under the field.
--}}

@php
    $errorKey = str_replace(['][', '[', ']'], ['.', '.', ''], (string) $name);
    $initial = old($errorKey, $values ?? []);
    $initial = collect(is_array($initial) ? $initial : (is_string($initial) && $initial !== '' ? explode(',', $initial) : []))
        ->map(static fn ($entry): string => trim((string) $entry))
        ->filter(static fn (string $entry): bool => $entry !== '')
        ->values()
        ->all();

    $mode = ($mode ?? 'list') === 'tags' ? 'tags' : 'list';
    $max = (int) ($max ?? 20);
    $maxLength = (int) ($maxLength ?? 150);
    $readonly = (bool) ($readonly ?? false);
    $fieldId = 'list-'.str_replace(['[', ']', '.'], ['-', '', '-'], (string) $name);
    $suggestions = array_values(array_unique(array_map('strval', (array) ($suggestions ?? []))));

    $itemErrors = collect($errors->getMessages())
        ->filter(static fn ($messages, string $key): bool => $key === $errorKey || str_starts_with($key, $errorKey.'.'))
        ->flatten()
        ->unique()
        ->values();
@endphp

<div
    x-data="{
        items: @js($initial),
        draft: '',
        max: {{ $max }},
        dragging: null,
        add(value) {
            const clean = String(value ?? this.draft).trim().slice(0, {{ $maxLength }});
            if (clean === '' || this.items.length >= this.max) { this.draft = ''; return; }
            if (@js($mode === 'tags') && this.items.some((item) => item.toLowerCase() === clean.toLowerCase())) { this.draft = ''; return; }
            this.items.push(clean);
            this.draft = '';
            this.$dispatch('input');
        },
        addFromDraft() {
            String(this.draft).split(',').forEach((part) => this.add(part));
        },
        remove(index) { this.items.splice(index, 1); this.$dispatch('input'); },
        move(index, step) {
            const target = index + step;
            if (target < 0 || target >= this.items.length) return;
            const [entry] = this.items.splice(index, 1);
            this.items.splice(target, 0, entry);
            this.$dispatch('input');
        },
        dropOn(index) {
            if (this.dragging === null || this.dragging === index) { this.dragging = null; return; }
            this.move(this.dragging, index - this.dragging);
            this.dragging = null;
        },
    }"
    class="w-full"
>
    @if (filled($label ?? null))
        <div class="mb-1.5 flex items-center justify-between gap-2">
            <x-ui.form.label :for="$fieldId">{{ $label }}</x-ui.form.label>
            <span class="text-2xs tabular-nums text-slate-400 dark:text-slate-500"><span x-text="items.length">{{ count($initial) }}</span> / {{ $max }}</span>
        </div>
    @endif

    {{-- What posts: one hidden input per entry, in order; '' when empty so a cleared list is saved. --}}
    <template x-for="(item, index) in items" :key="'post-' + index">
        <input type="hidden" name="{{ $name }}[]" x-bind:value="item">
    </template>
    <template x-if="items.length === 0">
        <input type="hidden" name="{{ $name }}" value="">
    </template>

    @if ($mode === 'tags')
        <div class="flex min-h-[2.625rem] flex-wrap items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-2 py-1.5 shadow-sm focus-within:border-brand-500 focus-within:ring-2 focus-within:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40">
            <template x-for="(item, index) in items" :key="'chip-' + index + item">
                <span class="inline-flex items-center gap-1 rounded-md bg-brand-50 py-0.5 pl-2 pr-1 text-xs font-medium text-brand-700 ring-1 ring-inset ring-brand-200 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/25">
                    <span x-text="item"></span>
                    @unless ($readonly)
                        <button type="button" x-on:click="remove(index)" class="inline-flex h-4 w-4 items-center justify-center rounded text-brand-500 hover:bg-brand-100 hover:text-brand-800 dark:hover:bg-brand-500/20 dark:hover:text-brand-100" x-bind:aria-label="'Remove ' + item">
                            <x-ui.icon name="x-mark" class="h-3 w-3" />
                        </button>
                    @endunless
                </span>
            </template>

            @unless ($readonly)
                <input
                    id="{{ $fieldId }}"
                    type="text"
                    x-model="draft"
                    x-on:keydown.enter.prevent="addFromDraft()"
                    x-on:keydown.comma.prevent="addFromDraft()"
                    x-on:blur="addFromDraft()"
                    x-on:keydown.backspace="if (draft === '' && items.length) remove(items.length - 1)"
                    x-bind:disabled="items.length >= max"
                    maxlength="{{ $maxLength }}"
                    placeholder="{{ $placeholder ?? 'Type and press Enter' }}"
                    @if ($suggestions !== []) list="{{ $fieldId }}-suggestions" @endif
                    data-dirty-ignore
                    autocomplete="off"
                    class="min-w-[8rem] flex-1 border-0 bg-transparent p-1 text-sm text-slate-900 placeholder:text-slate-400 focus:ring-0 disabled:cursor-not-allowed dark:text-white dark:placeholder:text-slate-500"
                >
                @if ($suggestions !== [])
                    <datalist id="{{ $fieldId }}-suggestions">
                        @foreach ($suggestions as $suggestion)
                            <option value="{{ $suggestion }}"></option>
                        @endforeach
                    </datalist>
                @endif
            @endunless
        </div>
    @else
        <ol class="space-y-2" x-show="items.length > 0" x-cloak>
            <template x-for="(item, index) in items" :key="'row-' + index">
                <li
                    class="flex items-center gap-2"
                    x-on:dragover.prevent
                    x-on:drop.prevent="dropOn(index)"
                >
                    @unless ($readonly)
                        <button
                            type="button"
                            draggable="true"
                            x-on:dragstart="dragging = index; $event.dataTransfer.effectAllowed = 'move'"
                            x-on:dragend="dragging = null"
                            class="inline-flex h-9 w-7 shrink-0 cursor-grab items-center justify-center rounded text-slate-400 hover:bg-slate-100 hover:text-slate-700 active:cursor-grabbing dark:text-slate-500 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                            x-bind:aria-label="'Drag entry ' + (index + 1)"
                        >
                            <x-ui.icon name="bars-3" class="h-4 w-4" />
                        </button>
                    @endunless

                    <span class="w-6 shrink-0 text-right text-xs tabular-nums text-slate-400 dark:text-slate-500" x-text="(index + 1) + '.'"></span>

                    <input
                        type="text"
                        x-model="items[index]"
                        maxlength="{{ $maxLength }}"
                        @readonly($readonly)
                        x-bind:aria-label="@js($label ?? 'Entry') + ' ' + (index + 1)"
                        class="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 read-only:bg-slate-50 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white dark:read-only:bg-slate-900/60"
                    >

                    @unless ($readonly)
                        <x-ui.icon-button icon="chevron-up" size="sm" label="Move up" x-on:click="move(index, -1)" x-bind:disabled="index === 0" />
                        <x-ui.icon-button icon="chevron-down" size="sm" label="Move down" x-on:click="move(index, 1)" x-bind:disabled="index === items.length - 1" />
                        <x-ui.icon-button icon="trash" size="sm" variant="danger" label="Remove entry" x-on:click="remove(index)" />
                    @endunless
                </li>
            </template>
        </ol>

        @unless ($readonly)
            <div class="mt-2 flex items-center gap-2" x-show="items.length < max">
                <input
                    id="{{ $fieldId }}"
                    type="text"
                    x-model="draft"
                    x-on:keydown.enter.prevent="add()"
                    maxlength="{{ $maxLength }}"
                    placeholder="{{ $placeholder ?? 'Add an entry' }}"
                    data-dirty-ignore
                    autocomplete="off"
                    class="block w-full rounded-lg border border-dashed border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white dark:placeholder:text-slate-500"
                >
                <x-ui.button variant="secondary" size="sm" icon="plus" x-on:click="add()">Add</x-ui.button>
            </div>
        @endunless
    @endif

    @if (filled($help ?? null))
        <x-ui.form.help>{{ $help }}</x-ui.form.help>
    @endif

    @foreach ($itemErrors as $itemError)
        <p class="mt-1.5 flex items-start gap-1.5 text-xs font-medium text-rose-600 dark:text-rose-400" role="alert">
            <x-ui.icon name="exclamation-circle" class="mt-px h-3.5 w-3.5" />
            <span>{{ $itemError }}</span>
        </p>
    @endforeach
</div>
