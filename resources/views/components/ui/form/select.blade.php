@props([
    'name' => null,
    'id' => null,
    'label' => null,
    'options' => [],
    'selected' => null,
    'placeholder' => null,
    'help' => null,
    'icon' => null,
    'required' => false,
    'optional' => false,
    'disabled' => false,
    'multiple' => false,
    'size' => 'md',
    'hasError' => null,
    'errorBag' => 'default',
])

{{--
    x-ui.form.select

        <x-ui.form.select name="status" label="Status"
                          :options="App\Enums\UserStatus::options()" :selected="$user->status->value" />

        <x-ui.form.select name="role" label="Role" placeholder="Choose a role"
                          :options="$roles->pluck('label', 'name')" />

        // or supply <option> markup yourself
        <x-ui.form.select name="branch_id" label="Branch">
            @foreach ($branches as $branch)
                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
            @endforeach
        </x-ui.form.select>

    `options` accepts value => label, or a list of ['value' => …, 'label' => …],
    or a Collection of either.
--}}

@php
    $id = $id ?? ($name ? 'field-'.str_replace(['[', ']', '.'], ['-', '', '-'], (string) $name) : null);
    $errorKey = $name ? str_replace(['][', '[', ']'], ['.', '.', ''], (string) $name) : null;
    $invalid = $hasError ?? ($errorKey ? $errors->getBag($errorBag)->has($errorKey) : false);

    $current = $name ? old($errorKey, $selected) : $selected;
    $currentValues = array_map('strval', is_array($current) ? $current : ($current === null ? [] : [$current]));

    // Normalise every accepted shape into value => label.
    $normalised = [];

    foreach ($options instanceof \Illuminate\Support\Collection ? $options->all() : (array) $options as $key => $option) {
        if (is_array($option) && array_key_exists('value', $option)) {
            $normalised[(string) $option['value']] = (string) ($option['label'] ?? $option['value']);
        } elseif ($option instanceof \Illuminate\Support\Collection || is_array($option)) {
            continue;
        } elseif (is_object($option) && method_exists($option, 'label') && property_exists($option, 'value')) {
            $normalised[(string) $option->value] = $option->label();
        } else {
            $normalised[(string) $key] = (string) $option;
        }
    }

    $sizes = [
        'sm' => 'py-1.5 text-xs',
        'md' => 'py-2 text-sm',
        'lg' => 'py-2.5 text-sm',
    ];

    $field = implode(' ', array_filter([
        // appearance-none + bg-none suppress the arrow @tailwindcss/forms paints, so our own
        // chevron is the only one visible.
        'block w-full appearance-none rounded-lg border bg-white bg-none shadow-sm transition duration-150',
        'text-slate-900 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500',
        'dark:bg-slate-950/40 dark:text-white dark:disabled:bg-slate-900',
        $sizes[$size] ?? $sizes['md'],
        $invalid
            ? 'border-rose-400 focus:border-rose-500 focus:ring-2 focus:ring-rose-500/20 dark:border-rose-500/60'
            : 'border-slate-300 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-slate-700',
        $icon ? 'pl-9' : 'pl-3',
        'pr-9',
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
        @endif

        <select
            @if ($id) id="{{ $id }}" @endif
            @if ($name) name="{{ $name }}{{ $multiple ? '[]' : '' }}" @endif
            @required($required)
            @disabled($disabled)
            @if ($multiple) multiple @endif
            @if ($invalid) aria-invalid="true" @endif
            @if ($describedBy !== []) aria-describedby="{{ implode(' ', $describedBy) }}" @endif
            {{ $attributes->except('class')->class($field) }}
        >
            @if (filled($placeholder) && ! $multiple)
                <option value="">{{ $placeholder }}</option>
            @endif

            @foreach ($normalised as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected(in_array((string) $optionValue, $currentValues, true))>
                    {{ $optionLabel }}
                </option>
            @endforeach

            {{ $slot }}
        </select>

        @unless ($multiple)
            <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2.5 text-slate-400 dark:text-slate-500">
                <x-ui.icon name="chevron-down" class="h-4 w-4" />
            </span>
        @endunless
    </div>

    @if (filled($help))
        <x-ui.form.help :id="$id.'-help'">{{ $help }}</x-ui.form.help>
    @endif

    @if ($name)
        <x-ui.form.error :for="$name" :bag="$errorBag" :id="$id.'-error'" />
    @endif
</div>
