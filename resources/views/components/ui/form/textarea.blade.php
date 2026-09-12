@props([
    'name' => null,
    'id' => null,
    'label' => null,
    'value' => null,
    'placeholder' => null,
    'help' => null,
    'rows' => 4,
    'required' => false,
    'optional' => false,
    'disabled' => false,
    'readonly' => false,
    'maxlength' => null,
    'counter' => false,
    'hasError' => null,
    'errorBag' => 'default',
])

{{--
    x-ui.form.textarea

        <x-ui.form.textarea name="description" label="Description" :rows="5"
                            :maxlength="255" :counter="true" />
--}}

@php
    $id = $id ?? ($name ? 'field-'.str_replace(['[', ']', '.'], ['-', '', '-'], (string) $name) : null);
    $errorKey = $name ? str_replace(['][', '[', ']'], ['.', '.', ''], (string) $name) : null;
    $invalid = $hasError ?? ($errorKey ? $errors->getBag($errorBag)->has($errorKey) : false);

    $resolved = $name ? old($errorKey, $value) : $value;

    $field = implode(' ', array_filter([
        'block w-full rounded-lg border bg-white px-3 py-2 text-sm shadow-sm transition duration-150',
        'text-slate-900 placeholder:text-slate-400',
        'disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500',
        'dark:bg-slate-950/40 dark:text-white dark:placeholder:text-slate-500 dark:disabled:bg-slate-900',
        $invalid
            ? 'border-rose-400 focus:border-rose-500 focus:ring-2 focus:ring-rose-500/20 dark:border-rose-500/60'
            : 'border-slate-300 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-slate-700',
    ]));

    $describedBy = array_filter([
        filled($help) ? $id.'-help' : null,
        $invalid ? $id.'-error' : null,
    ]);
@endphp

<div
    {{ $attributes->only('class')->class('w-full') }}
    @if ($counter) x-data="{ length: @js(mb_strlen((string) $resolved)) }" @endif
>
    @if (filled($label) || ($counter && $maxlength))
        <div class="mb-1.5 flex items-end justify-between gap-2">
            @if (filled($label))
                <x-ui.form.label :for="$id" :required="$required" :optional="$optional">{{ $label }}</x-ui.form.label>
            @else
                <span></span>
            @endif

            @if ($counter && $maxlength)
                <span class="text-2xs text-slate-400 tabular-nums dark:text-slate-500">
                    <span x-text="length">{{ mb_strlen((string) $resolved) }}</span>/{{ $maxlength }}
                </span>
            @endif
        </div>
    @endif

    <textarea
        @if ($id) id="{{ $id }}" @endif
        @if ($name) name="{{ $name }}" @endif
        rows="{{ (int) $rows }}"
        @if (filled($placeholder)) placeholder="{{ $placeholder }}" @endif
        @if ($maxlength) maxlength="{{ (int) $maxlength }}" @endif
        @required($required)
        @disabled($disabled)
        @readonly($readonly)
        @if ($invalid) aria-invalid="true" @endif
        @if ($describedBy !== []) aria-describedby="{{ implode(' ', $describedBy) }}" @endif
        @if ($counter) x-on:input="length = $el.value.length" @endif
        {{ $attributes->except('class')->class($field) }}
    >{{ $resolved }}</textarea>

    @if (filled($help))
        <x-ui.form.help :id="$id.'-help'">{{ $help }}</x-ui.form.help>
    @endif

    @if ($name)
        <x-ui.form.error :for="$name" :bag="$errorBag" :id="$id.'-error'" />
    @endif
</div>
