@props([
    'icon' => 'inbox-stack',
    'title' => 'Nothing here yet',
    'message' => null,
    'compact' => false,
])

{{--
    x-ui.empty-state — what a list shows instead of an empty table.

        <x-ui.empty-state icon="users" title="No users match those filters"
                          message="Try clearing the search, or invite someone new.">
            <x-slot:action>
                <x-ui.button icon="plus" :href="route('admin.users.create')">Add user</x-ui.button>
            </x-slot:action>
        </x-ui.empty-state>
--}}

<div {{ $attributes->class([
    'flex flex-col items-center justify-center px-6 text-center',
    $compact ? 'py-8' : 'py-14',
]) }}>
    <div class="relative mb-4">
        <span class="absolute inset-0 -m-2 rounded-full bg-brand-500/5 blur-md dark:bg-brand-400/10" aria-hidden="true"></span>
        <span class="relative inline-flex h-12 w-12 items-center justify-center rounded-xl bg-slate-100 text-slate-400 ring-1 ring-inset ring-slate-200 dark:bg-slate-800 dark:text-slate-500 dark:ring-slate-700">
            <x-ui.icon :name="$icon" class="h-6 w-6" />
        </span>
    </div>

    <h3 class="text-sm font-semibold tracking-tight text-slate-900 dark:text-white">{{ $title }}</h3>

    @if (filled($message))
        <p class="mt-1.5 max-w-sm text-sm text-slate-500 dark:text-slate-400">{{ $message }}</p>
    @endif

    @if (trim($slot->toHtml()) !== '')
        <div class="mt-2 max-w-sm text-sm text-slate-500 dark:text-slate-400">{{ $slot }}</div>
    @endif

    @isset($action)
        <div class="mt-5 flex flex-wrap items-center justify-center gap-2">{{ $action }}</div>
    @endisset
</div>
