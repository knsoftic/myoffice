@props([
    'name' => null,
    'id' => null,
    'label' => null,
    'description' => null,
    'value' => 1,
    'checked' => false,
    'disabled' => false,
    'required' => false,
    'withHidden' => false,
    'hasError' => null,
    'errorBag' => 'default',
])

{{--
    x-ui.form.checkbox

        <x-ui.form.checkbox name="is_default" label="Default role"
                            description="New users get this role automatically." :checked="$role->is_default" />

        // a group
        @foreach ($permissions as $permission)
            <x-ui.form.checkbox name="permissions[]" :value="$permission->name"
                                :label="$permission->label" :checked="in_array($permission->name, $held)" />
        @endforeach

    :with-hidden="true" adds a 0-value hidden input so an unchecked box still posts a value —
    use it for single boolean fields, never for arrays.
--}}

@php
    $id = $id ?? ($name
        ? 'field-'.str_replace(['[', ']', '.'], ['-', '', '-'], (string) $name).'-'.\Illuminate\Support\Str::slug((string) $value, '-')
        : null);

    $errorKey = $name ? str_replace(['][', '[', ']'], ['.', '.', ''], (string) $name) : null;
    $invalid = $hasError ?? ($errorKey ? $errors->getBag($errorBag)->has($errorKey) : false);

    // old() wins after a failed validation, but only when the form was actually submitted.
    $isArray = $name && str_contains((string) $name, '[]');

    if ($isArray) {
        $isChecked = (bool) $checked;
    } elseif ($name && old($errorKey) !== null) {
        $isChecked = (string) old($errorKey) === (string) $value;
    } else {
        $isChecked = (bool) $checked;
    }
@endphp

<div {{ $attributes->only('class')->class('relative') }}>
    @if ($withHidden && $name && ! $isArray)
        <input type="hidden" name="{{ $name }}" value="0" />
    @endif

    <div class="flex items-start gap-2.5">
    <div class="flex h-5 items-center">
        <input
            type="checkbox"
            @if ($id) id="{{ $id }}" @endif
            @if ($name) name="{{ $name }}" @endif
            value="{{ $value }}"
            @checked($isChecked)
            @disabled($disabled)
            @required($required)
            @if ($invalid) aria-invalid="true" @endif
            {{ $attributes->except('class')->class([
                'h-4 w-4 rounded border-slate-300 text-brand-600 shadow-sm transition focus:ring-2 focus:ring-brand-500/30 focus:ring-offset-0 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-600 dark:bg-slate-900 dark:checked:border-brand-500 dark:checked:bg-brand-500',
                'border-rose-400 dark:border-rose-500/60' => $invalid,
            ]) }}
        />
    </div>

    @if (filled($label) || filled($description) || trim($slot->toHtml()) !== '')
        <div class="min-w-0 text-sm leading-5">
            @if (filled($label))
                <label for="{{ $id }}" class="font-medium text-slate-700 {{ $disabled ? 'opacity-60' : 'cursor-pointer' }} dark:text-slate-200">
                    {{ $label }}
                    @if ($required)
                        <span class="text-rose-500" aria-hidden="true">*</span>
                    @endif
                </label>
            @endif

            @if (filled($description))
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $description }}</p>
            @endif

            {{ $slot }}
        </div>
    @endif
    </div>

    @if ($name && ! $isArray)
        <x-ui.form.error :for="$name" :bag="$errorBag" />
    @endif
</div>
