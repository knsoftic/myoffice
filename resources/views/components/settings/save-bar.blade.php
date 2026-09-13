@props([
    'label' => 'Save changes',
])

{{--
    x-settings.save-bar — the sticky bar that appears only once the form is dirty (phase-02 §5).

    It reads `dirty` and `saving` from the enclosing Alpine scope (the form in
    `admin/settings/index.blade.php` owns them) and submits that form, so it has no state of its
    own and can sit anywhere inside it.

    "Discard" resets the native form — every input returns to the value the server rendered — and
    clears the dirty flag, which also disarms the navigate-away warning.
--}}

<div
    x-show="dirty"
    x-cloak
    x-transition:enter="ease-out duration-150"
    x-transition:enter-start="opacity-0 translate-y-2"
    x-transition:enter-end="opacity-100 translate-y-0"
    class="sticky bottom-0 z-topbar -mx-4 mt-5 border-t border-slate-200 bg-white/95 px-4 py-3 shadow-[0_-4px_16px_-8px_rgb(15_23_42/0.25)] backdrop-blur sm:-mx-6 sm:px-6 dark:border-slate-800 dark:bg-slate-900/95"
    style="display: none"
>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
            <span class="relative flex h-2 w-2 shrink-0">
                <span class="absolute inline-flex h-full w-full animate-pulse-soft rounded-full bg-amber-400"></span>
            </span>
            <span>You have unsaved changes.</span>
        </p>

        <div class="flex items-center gap-2">
            <x-ui.button
                type="button"
                variant="ghost"
                size="md"
                x-on:click="discard()"
            >
                Discard
            </x-ui.button>

            <x-ui.button
                type="submit"
                icon="check"
                size="md"
                x-bind:disabled="saving"
                x-bind:aria-busy="saving"
            >
                <span x-show="! saving">{{ $label }}</span>
                <span x-show="saving" x-cloak>Saving…</span>
            </x-ui.button>
        </div>
    </div>
</div>
