@props([
    'for' => null,
    'bag' => 'default',
    'message' => null,
])

{{--
    x-ui.form.error — the validation message for one field.

        <x-ui.form.error for="email" />
        <x-ui.form.error message="Passwords do not match." />

    Resolves dotted and bracketed names, so `items[0][qty]` and `items.0.qty` both work.
--}}

@php
    $messages = [];

    if (filled($message)) {
        $messages = [$message];
    } elseif (filled($for)) {
        // items[0][qty] => items.0.qty
        $key = str_replace(['][', '[', ']'], ['.', '.', ''], (string) $for);
        $messages = $errors->getBag($bag)->get($key);
    }
@endphp

@if (! empty($messages))
    <p {{ $attributes->class('mt-1.5 flex items-start gap-1.5 text-xs font-medium text-rose-600 dark:text-rose-400') }}>
        <x-ui.icon name="exclamation-circle" class="mt-px h-3.5 w-3.5 shrink-0" />
        <span>{{ $messages[0] }}</span>
    </p>
@endif
