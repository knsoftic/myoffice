@props([
    'name' => null,
    'id' => null,
    'type' => 'text',
    'label' => null,
    'value' => null,
    'placeholder' => null,
    'help' => null,
    'icon' => null,
    'prefix' => null,
    'suffix' => null,
    'required' => false,
    'optional' => false,
    'disabled' => false,
    'readonly' => false,
    'size' => 'md',
    'hasError' => null,
    'errorBag' => 'default',
])

{{--
    x-ui.form.input

        <x-ui.form.input name="email" type="email" label="Email address" required icon="mail" />
        <x-ui.form.input name="fee" label="Monthly fee" prefix="Rs" inputmode="decimal" />
        <x-ui.form.input name="phone" label="Phone" :value="$user->phone" help="Used for SMS alerts." />

    Pulls old() automatically, shows the field's validation error, and wires
    aria-invalid / aria-describedby for screen readers.
--}}

@php
    $id = $id ?? ($name ? 'field-'.str_replace(['[', ']', '.'], ['-', '', '-'], (string) $name) : null);
    $errorKey = $name ? str_replace(['][', '[', ']'], ['.', '.', ''], (string) $name) : null;
    $invalid = $hasError ?? ($errorKey ? $errors->getBag($errorBag)->has($errorKey) : false);

    $resolved = $name && $type !== 'password' ? old($errorKey, $value) : $value;

    $sizes = [
        'sm' => 'py-1.5 text-xs',
        'md' => 'py-2 text-sm',
        'lg' => 'py-2.5 text-sm',
    ];

    $field = implode(' ', array_filter([
        'block w-full rounded-lg border bg-white shadow-sm transition duration-150',
        'text-slate-900 placeholder:text-slate-400',
        'disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500',
        'read-only:bg-slate-50 read-only:text-slate-600',
        'dark:bg-slate-950/40 dark:text-white dark:placeholder:text-slate-500 dark:disabled:bg-slate-900 dark:read-only:bg-slate-900/60',
        $sizes[$size] ?? $sizes['md'],
        $invalid
            ? 'border-rose-400 focus:border-rose-500 focus:ring-2 focus:ring-rose-500/20 dark:border-rose-500/60'
            : 'border-slate-300 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-slate-700 dark:focus:border-brand-500',
        $icon || $prefix ? 'pl-9' : 'pl-3',
        $suffix ? 'pr-12' : 'pr-3',
    ]));

    $describedBy = array_filter([
        filled($help) ? $id.'-help' : null,
        $invalid ? $id.'-error' : null,
    ]);
@endphp

<div {{ $attributes->only('class')->class('w-full') }}>
    @if (filled($label))
        <x-ui.form.label :for="$id" :required="$required" :optional="$optional" class="mb-1.5">
            {{ $label }}
        </x-ui.form.label>
    @endif

    <div class="relative">
        @if ($icon)
            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400 dark:text-slate-500">
                <x-ui.icon :name="$icon" class="h-4 w-4" />
            </span>
        @elseif (filled($prefix))
            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-xs font-medium text-slate-500 dark:text-slate-400">
                {{ $prefix }}
            </span>
        @endif

        <input
            type="{{ $type }}"
            @if ($id) id="{{ $id }}" @endif
            @if ($name) name="{{ $name }}" @endif
            @if ($resolved !== null && $resolved !== false) value="{{ $resolved }}" @endif
            @if (filled($placeholder)) placeholder="{{ $placeholder }}" @endif
            @required($required)
            @disabled($disabled)
            @readonly($readonly)
            @if ($invalid) aria-invalid="true" @endif
            @if ($describedBy !== []) aria-describedby="{{ implode(' ', $describedBy) }}" @endif
            {{ $attributes->except('class')->class($field) }}
        />

        @if (filled($suffix))
            <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-xs font-medium text-slate-500 dark:text-slate-400">
                {{ $suffix }}
            </span>
        @endif
    </div>

    @if (filled($help))
        <x-ui.form.help :id="$id.'-help'">{{ $help }}</x-ui.form.help>
    @endif

    @if ($name)
        <x-ui.form.error :for="$name" :bag="$errorBag" :id="$id.'-error'" />
    @endif

    {{ $slot }}
</div>
