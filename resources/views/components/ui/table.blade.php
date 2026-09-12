@props([
    'isEmpty' => false,
    'dense' => false,
    'hover' => true,
    'maxHeight' => null,
    'flush' => false,
])

{{--
    x-ui.table — responsive table shell.

        <x-ui.table :is-empty="$users->isEmpty()">
            <x-slot:head>
                <x-ui.th-sortable column="name" :sort="$sort" :direction="$direction">Name</x-ui.th-sortable>
                <th class="px-4 py-3 text-left font-semibold">Roles</th>
                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($users as $user)
                <tr>
                    <td class="px-4 py-3">{{ $user->name }}</td>
                    …
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="users" title="No users yet" />
            </x-slot:empty>
        </x-ui.table>

    The wrapper scrolls horizontally on its own, so the page body never does.
    Pass max-height="60vh" to make the header stick while the body scrolls vertically.
--}}

@php
    $wrapper = $maxHeight
        ? 'overflow-auto'
        : 'overflow-x-auto';
@endphp

<div {{ $attributes->class([
    'rounded-xl bg-white shadow-sm ring-1 ring-slate-200/70 dark:bg-slate-900 dark:ring-slate-800' => ! $flush,
    'overflow-hidden' => ! $flush,
]) }}>
    <div
        class="{{ $wrapper }}"
        @if ($maxHeight) style="max-height: {{ $maxHeight }}" @endif
    >
        <table class="min-w-full border-separate border-spacing-0 text-sm">
            @isset($head)
                <thead>
                    <tr class="sticky top-0 z-10 bg-slate-50/95 backdrop-blur supports-[backdrop-filter]:bg-slate-50/80 dark:bg-slate-900/95 dark:supports-[backdrop-filter]:bg-slate-900/80 [&>*]:border-b [&>*]:border-slate-200 [&>*]:text-left [&>*]:text-xs [&>*]:font-semibold [&>*]:uppercase [&>*]:tracking-wider [&>*]:text-slate-500 dark:[&>*]:border-slate-800 dark:[&>*]:text-slate-400">
                        {{ $head }}
                    </tr>
                </thead>
            @endisset

            <tbody @class([
                '[&>tr>*]:border-b [&>tr>*]:border-slate-100 dark:[&>tr>*]:border-slate-800/80 [&>tr:last-child>*]:border-0',
                '[&>tr>*]:px-4 [&>tr>*]:py-2.5' => $dense,
                '[&>tr>*]:px-4 [&>tr>*]:py-3.5' => ! $dense,
                '[&>tr]:transition-colors [&>tr:hover]:bg-slate-50/80 dark:[&>tr:hover]:bg-slate-800/40' => $hover,
                'text-slate-700 dark:text-slate-300',
            ])>
                {{ $slot }}
            </tbody>

            @isset($foot)
                <tfoot class="[&>tr>*]:border-t [&>tr>*]:border-slate-200 [&>tr>*]:bg-slate-50/70 [&>tr>*]:px-4 [&>tr>*]:py-3 [&>tr>*]:text-xs [&>tr>*]:font-semibold [&>tr>*]:text-slate-600 dark:[&>tr>*]:border-slate-800 dark:[&>tr>*]:bg-slate-900/60 dark:[&>tr>*]:text-slate-300">
                    {{ $foot }}
                </tfoot>
            @endisset
        </table>
    </div>

    @if ($isEmpty)
        <div class="border-t border-slate-200 dark:border-slate-800">
            @isset($empty)
                {{ $empty }}
            @else
                <x-ui.empty-state />
            @endisset
        </div>
    @endif

    @isset($footer)
        <div class="border-t border-slate-200 bg-slate-50/70 px-4 py-3 dark:border-slate-800 dark:bg-slate-900/60">
            {{ $footer }}
        </div>
    @endisset
</div>
