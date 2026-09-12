@props([
    'icon' => null,
])

{{-- x-ui.form.help — the quiet line under a field explaining what it is for. --}}

<p {{ $attributes->class('mt-1.5 flex items-start gap-1.5 text-xs text-slate-500 dark:text-slate-400') }}>
    @if ($icon)
        <x-ui.icon :name="$icon" class="mt-px h-3.5 w-3.5 shrink-0 opacity-70" />
    @endif
    <span>{{ $slot }}</span>
</p>
