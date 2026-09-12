@props([
    'for' => null,
    'value' => null,
    'required' => false,
    'optional' => false,
])

{{-- x-ui.form.label — also used internally by every field component. --}}

<label
    @if ($for) for="{{ $for }}" @endif
    {{ $attributes->class('flex items-center gap-1.5 text-sm font-medium text-slate-700 dark:text-slate-200') }}
>
    <span>{{ $value ?? $slot }}</span>

    @if ($required)
        <span class="text-rose-500" aria-hidden="true">*</span>
        <span class="sr-only">(required)</span>
    @elseif ($optional)
        <span class="text-xs font-normal text-slate-400 dark:text-slate-500">(optional)</span>
    @endif
</label>
