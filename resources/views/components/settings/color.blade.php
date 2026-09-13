@props([
    'field' => [],
])

{{--
    x-settings.color — the `color` type.

    A native colour picker and a hex field bound to the same Alpine value, so an operator can
    pick or paste. Only the text input carries the field `name`: one key, one posted value, and
    the registry's `regex:/^#[0-9A-Fa-f]{6}$/` stays the single source of truth about what is
    valid.

    For `branding.brand_color` / `branding.accent_color` the value is pushed into
    `window.settingsBrand` on every keystroke, which repaints the whole shell through the
    `--brand-*` CSS variables (phase-02 §5's live preview). The hook is emitted only for those
    two keys; every other colour setting is a plain field.
--}}

@php
    $f = $field;

    $disabled = (bool) ($f['disabled'] ?? false);
    $current = old($f['error_key'], $f['value'] ?? $f['default']);
    $current = is_string($current) && $current !== '' ? $current : '#4f46e5';

    // Which live-preview channel this field drives, if any.
    $channel = match ((string) ($f['group'] ?? '').'.'.(string) ($f['key'] ?? '')) {
        'branding.brand_color' => 'brand',
        'branding.accent_color' => 'accent',
        default => null,
    };

    $presets = [
        '#4f46e5' => 'Indigo',
        '#2563eb' => 'Blue',
        '#0ea5e9' => 'Sky',
        '#059669' => 'Emerald',
        '#d97706' => 'Amber',
        '#e11d48' => 'Rose',
        '#7c3aed' => 'Violet',
        '#0f172a' => 'Slate',
    ];
@endphp

<div
    x-data="{ value: @js($current) }"
    @if ($channel)
        x-effect="window.settingsBrand && window.settingsBrand.{{ $channel }}(value)"
    @endif
>
    <div class="flex items-center gap-2">
        <label
            class="relative h-10 w-12 shrink-0 overflow-hidden rounded-lg ring-1 ring-slate-300 dark:ring-slate-700 {{ $disabled ? 'opacity-60' : 'cursor-pointer' }}"
            x-bind:style="{ backgroundColor: value }"
        >
            <input
                type="color"
                x-model="value"
                @disabled($disabled)
                {{-- -inset-2 already stretches it past the swatch, so a click anywhere opens the picker. --}}
                class="absolute -inset-2 cursor-pointer border-0 bg-transparent p-0 opacity-0"
                aria-label="{{ $f['label'] }} picker"
                tabindex="-1"
            />
        </label>

        <x-ui.form.input
            :name="$f['name']"
            :id="$f['id']"
            type="text"
            :value="$current"
            :placeholder="$f['placeholder'] ?? '#4f46e5'"
            :disabled="$disabled"
            maxlength="7"
            spellcheck="false"
            autocomplete="off"
            class="max-w-[10rem] font-mono uppercase"
            x-model="value"
        />
    </div>

    @unless ($disabled)
        <div class="mt-2 flex flex-wrap items-center gap-1.5">
            @foreach ($presets as $hex => $name)
                <button
                    type="button"
                    x-on:click="value = @js($hex)"
                    title="{{ $name }}"
                    class="h-5 w-5 rounded-full ring-1 ring-inset ring-black/10 transition-transform hover:scale-110 dark:ring-white/15"
                    style="background-color: {{ $hex }}"
                    x-bind:class="value.toLowerCase() === @js($hex) ? 'ring-2 ring-offset-2 ring-slate-400 dark:ring-offset-slate-900' : ''"
                >
                    <span class="sr-only">{{ $name }}</span>
                </button>
            @endforeach

            @if ($channel === 'brand')
                <button
                    type="button"
                    x-on:click="value = @js((string) ($f['default'] ?? '#4f46e5'))"
                    class="ml-1 inline-flex items-center gap-1 rounded-md px-1.5 py-1 text-2xs font-semibold text-slate-500 transition-colors hover:text-slate-800 dark:hover:text-slate-200"
                >
                    <x-ui.icon name="arrow-path" class="h-3 w-3" />
                    Reset
                </button>
            @endif
        </div>
    @endunless

    @if (filled($f['help'] ?? null))
        <x-ui.form.help>{{ $f['help'] }}</x-ui.form.help>
    @endif
</div>
