@props([
    'items' => [],
    'home' => null,
])

{{--
    x-ui.breadcrumbs

        <x-ui.breadcrumbs :items="[
            ['label' => 'Users', 'url' => route('admin.users.index')],
            ['label' => $user->name],
        ]" />

    Each item: ['label' => string, 'url' => ?string, 'icon' => ?string].
    The last item is always rendered as the current page.
--}}

@php
    $items = collect($items)
        ->map(function (mixed $item): ?array {
            if (is_string($item)) {
                return ['label' => $item, 'url' => null, 'icon' => null];
            }

            if (! is_array($item) || ! filled($item['label'] ?? null)) {
                return null;
            }

            return [
                'label' => (string) $item['label'],
                'url' => $item['url'] ?? null,
                'icon' => $item['icon'] ?? null,
            ];
        })
        ->filter()
        ->values();

    $last = $items->count() - 1;
@endphp

@if ($items->isNotEmpty() || $home)
    <nav {{ $attributes->merge(['aria-label' => 'Breadcrumb']) }}>
        <ol class="flex flex-wrap items-center gap-1 text-xs text-slate-500 dark:text-slate-400">
            @if ($home)
                <li class="flex items-center">
                    <a
                        href="{{ $home }}"
                        class="inline-flex items-center gap-1 rounded px-1 py-0.5 transition-colors hover:text-slate-900 dark:hover:text-white"
                        aria-label="Home"
                    >
                        <x-ui.icon name="home" class="h-3.5 w-3.5" />
                    </a>
                </li>
            @endif

            @foreach ($items as $index => $item)
                @if ($home || $index > 0)
                    <li aria-hidden="true" class="text-slate-300 dark:text-slate-600">
                        <x-ui.icon name="chevron-right" class="h-3 w-3" />
                    </li>
                @endif

                <li class="flex min-w-0 items-center">
                    @if ($item['url'] && $index !== $last)
                        <a
                            href="{{ $item['url'] }}"
                            class="inline-flex max-w-[12rem] items-center gap-1 truncate rounded px-1 py-0.5 transition-colors hover:text-slate-900 sm:max-w-none dark:hover:text-white"
                        >
                            @if ($item['icon'])
                                <x-ui.icon :name="$item['icon']" class="h-3.5 w-3.5" />
                            @endif
                            {{ $item['label'] }}
                        </a>
                    @else
                        <span
                            class="inline-flex max-w-[14rem] items-center gap-1 truncate px-1 py-0.5 font-medium text-slate-900 sm:max-w-none dark:text-white"
                            @if ($index === $last) aria-current="page" @endif
                        >
                            @if ($item['icon'])
                                <x-ui.icon :name="$item['icon']" class="h-3.5 w-3.5" />
                            @endif
                            {{ $item['label'] }}
                        </span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif
