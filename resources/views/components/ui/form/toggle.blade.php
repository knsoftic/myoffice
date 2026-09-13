@props([
    'name' => null,
    'id' => null,
    'label' => null,
    'description' => null,
    'checked' => false,
    'disabled' => false,
    'value' => 1,
    'size' => 'md',
    'reverse' => false,
    'errorBag' => 'default',
])

{{--
    x-ui.form.toggle — a switch for boolean settings.

        <x-ui.form.toggle name="is_enabled" label="Module enabled"
                          description="Disabling hides it everywhere and denies its permissions."
                          :checked="$module->is_enabled" />

    An enabled switch always posts a value: a hidden 0 precedes the checkbox, so an "off" switch
    submits 0 rather than nothing. A **disabled** switch posts nothing at all — neither the hidden 0
    nor the checkbox — exactly like any other disabled control. Emitting the hidden 0 there made a
    read-only key (security.two_factor_enabled) ride along on every save, so the server refused the
    whole group. No JavaScript — the switch is pure CSS driven by `peer-checked`, with the real
    checkbox kept accessible but visually hidden.
--}}

@php
    $id = $id ?? ($name ? 'toggle-'.str_replace(['[', ']', '.'], ['-', '', '-'], (string) $name) : 'toggle-'.uniqid());
    $errorKey = $name ? str_replace(['][', '[', ']'], ['.', '.', ''], (string) $name) : null;

    $isChecked = $name && old($errorKey) !== null
        ? (string) old($errorKey) === (string) $value
        : (bool) $checked;

    // [track, knob, knob offset, travel]
    $sizes = [
        'sm' => ['h-5 w-9', 'h-3.5 w-3.5', 'left-[3px]', 'peer-checked:translate-x-4'],
        'md' => ['h-6 w-11', 'h-[1.125rem] w-[1.125rem]', 'left-[3px]', 'peer-checked:translate-x-5'],
    ];

    [$track, $knob, $offset, $travel] = $sizes[$size] ?? $sizes['md'];
@endphp

<div {{ $attributes->only('class')->class('w-full') }}>
    <div class="flex items-start gap-3 {{ $reverse ? 'flex-row-reverse justify-between' : '' }}">
        {{-- The input, track and knob are siblings so peer-checked: reaches both visuals. --}}
        <label
            class="relative inline-flex shrink-0 items-center {{ $disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer' }}"
        >
            @if ($name && ! $disabled)
                <input type="hidden" name="{{ $name }}" value="0" />
            @endif

            <input
                type="checkbox"
                id="{{ $id }}"
                @if ($name) name="{{ $name }}" @endif
                value="{{ $value }}"
                class="peer sr-only"
                @checked($isChecked)
                @disabled($disabled)
                {{ $attributes->except('class') }}
            />

            <span
                class="{{ $track }} rounded-full bg-slate-200 transition-colors duration-150 peer-checked:bg-brand-600 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/40 peer-focus-visible:ring-offset-2 dark:bg-slate-700 dark:peer-checked:bg-brand-500 dark:peer-focus-visible:ring-offset-slate-950"
                aria-hidden="true"
            ></span>

            <span
                class="{{ $knob }} {{ $offset }} {{ $travel }} pointer-events-none absolute translate-x-0 rounded-full bg-white shadow-sm transition-transform duration-150"
                aria-hidden="true"
            ></span>

            <span class="sr-only">{{ $label ?? 'Toggle' }}</span>
        </label>

        @if (filled($label) || filled($description) || trim($slot->toHtml()) !== '')
            <div class="min-w-0 text-sm">
                @if (filled($label))
                    <label for="{{ $id }}" class="font-medium text-slate-700 {{ $disabled ? 'opacity-60' : 'cursor-pointer' }} dark:text-slate-200">
                        {{ $label }}
                    </label>
                @endif

                @if (filled($description))
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ $description }}</p>
                @endif

                {{ $slot }}
            </div>
        @endif
    </div>

    @if ($name)
        <x-ui.form.error :for="$name" :bag="$errorBag" />
    @endif
</div>
