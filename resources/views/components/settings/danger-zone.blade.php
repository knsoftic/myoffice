@props([
    'group' => [],
])

{{--
    x-settings.danger-zone — "Reset group to defaults", behind x-ui.confirm (phase-02 §5).

    Deliberately rendered **outside** the settings form: x-ui.confirm carries its own <form>, and a
    nested form is invalid HTML that browsers silently drop.

    The dialog demands the group's name be typed before the button unlocks. A reset is not a small
    thing — it restores every key in the group to its registry default and clears the files those
    keys point at — and it is the one action on this screen that cannot be undone by pressing
    "Discard".
--}}

@php
    $slug = (string) ($group['slug'] ?? '');
    $label = (string) ($group['label'] ?? $slug);
@endphp

<x-ui.card class="border border-rose-200 dark:border-rose-500/30">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex min-w-0 gap-3">
            <span class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400">
                <x-ui.icon name="exclamation-triangle" class="h-4 w-4" />
            </span>

            <div class="min-w-0">
                <h3 class="text-sm font-semibold tracking-tight text-slate-900 dark:text-white">Reset {{ $label }} to defaults</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    Every value in this group goes back to what a fresh install ships with, and any file uploaded
                    through it is cleared. Other groups are untouched, and the change is written to the activity log.
                </p>
            </div>
        </div>

        <div class="shrink-0 sm:pt-1">
            <x-ui.confirm
                :action="route('admin.settings.reset', $slug)"
                method="POST"
                :title="'Reset '.$label.' to defaults?'"
                message="This cannot be undone from the screen. The previous values are only recoverable from the activity log."
                :confirm-label="'Reset '.$label"
                :require-text="$label"
                icon="arrow-path"
            >
                <x-slot:trigger>
                    <x-ui.button variant="secondary" size="sm" icon="arrow-path"
                        class="border-rose-300 text-rose-700 hover:bg-rose-50 dark:border-rose-500/40 dark:text-rose-300 dark:hover:bg-rose-500/10">
                        Reset to defaults
                    </x-ui.button>
                </x-slot:trigger>
            </x-ui.confirm>
        </div>
    </div>
</x-ui.card>
