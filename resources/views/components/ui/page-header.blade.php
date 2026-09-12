@props([
    'title' => null,
    'subtitle' => null,
    'icon' => null,
    'breadcrumbs' => [],
    'back' => null,
    'badge' => null,
    'badgeColor' => 'slate',
])

{{--
    x-ui.page-header — the block every page puts in @section('header').

        @section('header')
            <x-ui.page-header title="Users" subtitle="Everyone with access to the system" icon="users">
                <x-slot:actions>
                    <x-ui.button icon="plus" :href="route('admin.users.create')">New user</x-ui.button>
                </x-slot:actions>
            </x-ui.page-header>
        @endsection

    `breadcrumbs` is only needed when the automatic bar under the topbar should be overridden.
--}}

<div {{ $attributes->class('flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between') }}>
    <div class="min-w-0">
        @if (! empty($breadcrumbs))
            <x-ui.breadcrumbs :items="$breadcrumbs" class="mb-2" />
        @endif

        <div class="flex items-start gap-3">
            @if ($back)
                <a
                    href="{{ $back }}"
                    class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-300 bg-white text-slate-500 shadow-sm transition-colors hover:bg-slate-50 hover:text-slate-900 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
                    aria-label="Back"
                >
                    <x-ui.icon name="chevron-left" class="h-4 w-4" />
                </a>
            @endif

            @if ($icon)
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600 ring-1 ring-inset ring-brand-100 dark:bg-brand-500/10 dark:text-brand-400 dark:ring-brand-500/20">
                    <x-ui.icon :name="$icon" class="h-5 w-5" />
                </span>
            @endif

            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="truncate text-xl font-semibold tracking-tight text-slate-900 sm:text-2xl dark:text-white">
                        {{ $title }}
                    </h1>

                    @if (filled($badge))
                        <x-ui.badge :color="$badgeColor" size="sm">{{ $badge }}</x-ui.badge>
                    @endif
                </div>

                @if (filled($subtitle))
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $subtitle }}</p>
                @endif

                {{ $slot }}
            </div>
        </div>
    </div>

    @isset($actions)
        <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
