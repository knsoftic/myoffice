@props([
    'title' => null,
    'subtitle' => null,
    'icon' => null,
    'level' => 'h2',
    'divider' => false,
])

{{--
    x-ui.section-heading — a labelled break inside a page or a long form.

        <x-ui.section-heading title="Contact details" subtitle="How we reach this person" :divider="true">
            <x-slot:actions><x-ui.button size="sm" variant="ghost" icon="plus">Add</x-ui.button></x-slot:actions>
        </x-ui.section-heading>
--}}

<div {{ $attributes->class([
    'flex flex-wrap items-end justify-between gap-3',
    'border-b border-slate-200 pb-3 dark:border-slate-800' => $divider,
]) }}>
    <div class="min-w-0">
        <{{ $level }} class="flex items-center gap-2 text-sm font-semibold tracking-tight text-slate-900 dark:text-white">
            @if ($icon)
                <x-ui.icon :name="$icon" class="h-4 w-4 text-slate-400 dark:text-slate-500" />
            @endif
            {{ $title }}{{ $slot }}
        </{{ $level }}>

        @if (filled($subtitle))
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
