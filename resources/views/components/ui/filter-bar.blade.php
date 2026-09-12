@props([
    'action' => null,
    'method' => 'GET',
    'searchName' => 'search',
    'placeholder' => 'Search…',
    'searchValue' => null,
    'reset' => null,
    'autoSubmit' => true,
    'debounce' => 400,
])

{{--
    x-ui.filter-bar — search + filters that submit themselves.

        <x-ui.filter-bar placeholder="Search users…">
            <x-ui.form.select name="status" :options="App\Enums\UserStatus::options()" placeholder="Any status" />
            <x-ui.form.select name="role" :options="$roles" placeholder="Any role" />
        </x-ui.filter-bar>

    Typing debounces (400ms by default) and any <select> change submits immediately.
    Hidden inputs preserve sort state so filtering never silently resets the order.
    The Reset link only appears when something is actually filtered.
--}}

@php
    $searchValue = $searchValue ?? request($searchName);
    $action = $action ?? url()->current();

    // Everything except the filters themselves (so sort order survives a filter change).
    $preserved = collect(request()->query())
        ->except([$searchName, 'page'])
        ->filter(fn (mixed $value, string $key): bool => in_array($key, ['sort', 'direction'], true));

    $hasFilters = collect(request()->query())->except('page')->filter(fn ($v) => filled($v))->isNotEmpty();
@endphp

<form
    method="{{ $method }}"
    action="{{ $action }}"
    x-data="uiFilterBar()"
    x-ref="form"
    {{ $attributes->class('rounded-xl bg-white p-3 ring-1 ring-slate-200/70 shadow-sm dark:bg-slate-900 dark:ring-slate-800') }}
>
    @foreach ($preserved as $key => $value)
        <input type="hidden" name="{{ $key }}" value="{{ $value }}" />
    @endforeach

    <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
        <div class="relative flex-1 lg:max-w-sm">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400 dark:text-slate-500">
                <x-ui.icon name="search" class="h-4 w-4" />
            </span>

            <input
                type="search"
                name="{{ $searchName }}"
                value="{{ $searchValue }}"
                placeholder="{{ $placeholder }}"
                aria-label="{{ $placeholder }}"
                class="block w-full rounded-lg border-slate-300 bg-white py-2 pl-9 pr-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white dark:placeholder:text-slate-500"
                @if ($autoSubmit)
                    x-on:input.debounce.{{ (int) $debounce }}ms="submit()"
                    x-on:search="submit()"
                @endif
            />
        </div>

        @if (trim($slot->toHtml()) !== '')
            <div
                class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:flex lg:flex-1 lg:flex-wrap lg:items-center"
                @if ($autoSubmit) x-on:change="submit()" @endif
            >
                {{ $slot }}
            </div>
        @endif

        <div class="flex items-center gap-2 lg:ml-auto">
            @isset($actions)
                {{ $actions }}
            @endisset

            <x-ui.button type="submit" variant="secondary" size="sm" icon="filter" class="lg:hidden">
                Filter
            </x-ui.button>

            @if ($hasFilters)
                <a
                    href="{{ $reset ?? $action }}"
                    class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
                >
                    <x-ui.icon name="x-mark" class="h-3.5 w-3.5" />
                    Reset
                </a>
            @endif
        </div>
    </div>

    {{-- Keyboard/no-JS fallback: Enter submits. --}}
    <button type="submit" class="sr-only">Apply filters</button>
</form>
