@props([
    'showValidationErrors' => true,
])

{{--
    x-ui.toast — the toast region. Rendered once per layout, near the end of <body>.

    Server side:
        session()->flash('toast', ['type' => 'success', 'message' => 'User updated.']);
        session()->flash('toast', ['type' => 'error', 'title' => 'Upload failed', 'message' => '…']);

    Also picks up Laravel's conventional 'status' / 'success' / 'error' flash keys, and turns a
    validation failure into one summary toast (the fields keep their own inline errors).

    Client side:
        window.toasts.success('Saved');
        window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'error', message: '…' } }));

    Stacks bottom-right on desktop, top on mobile; auto-dismisses after 5s.
--}}

@php
    $queued = [];

    $flash = session('toast');

    if (is_array($flash)) {
        // A single toast, or a list of them.
        $queued = array_is_list($flash) ? $flash : [$flash];
    } elseif (is_string($flash) && $flash !== '') {
        $queued[] = ['type' => 'info', 'message' => $flash];
    }

    foreach (['success' => 'success', 'error' => 'error', 'warning' => 'warning', 'status' => 'info', 'message' => 'info'] as $key => $type) {
        $value = session($key);

        if (is_string($value) && $value !== '') {
            $queued[] = ['type' => $type, 'message' => $value];
        }
    }

    if ($showValidationErrors && $errors->any() && $queued === []) {
        $queued[] = [
            'type' => 'error',
            'title' => 'Please check the form',
            'message' => $errors->count() === 1
                ? $errors->first()
                : $errors->count().' fields need attention.',
        ];
    }

@endphp

<div
    x-data
    @if ($queued !== []) x-init="$store.toasts.hydrate(@js(array_values($queued)))" @endif
    class="pointer-events-none fixed inset-x-0 top-0 z-toast flex flex-col items-center gap-2 px-4 py-4 sm:inset-x-auto sm:bottom-0 sm:right-0 sm:top-auto sm:items-end sm:px-6 sm:py-6"
    role="region"
    aria-label="Notifications"
>
    <template x-for="toast in $store.toasts.items" :key="toast.id">
        <div
            x-show="toast.show"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 -translate-y-2 sm:translate-y-2 sm:translate-x-4"
            x-transition:enter-end="opacity-100 translate-y-0 sm:translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-x-0 scale-100"
            x-transition:leave-end="opacity-0 sm:translate-x-4 scale-95"
            class="pointer-events-auto relative flex w-full max-w-sm gap-3 overflow-hidden rounded-xl bg-white pl-4 pr-2 py-3 shadow-dropdown ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700"
            role="status"
            aria-live="polite"
        >
            {{-- Accent bar --}}
            <span
                class="absolute inset-y-0 left-0 w-1"
                :class="{
                    'bg-emerald-500': toast.type === 'success',
                    'bg-rose-500': toast.type === 'error',
                    'bg-amber-500': toast.type === 'warning',
                    'bg-sky-500': toast.type === 'info',
                }"
                aria-hidden="true"
            ></span>

            <span
                class="mt-0.5 shrink-0"
                :class="{
                    'text-emerald-600 dark:text-emerald-400': toast.type === 'success',
                    'text-rose-600 dark:text-rose-400': toast.type === 'error',
                    'text-amber-600 dark:text-amber-400': toast.type === 'warning',
                    'text-sky-600 dark:text-sky-400': toast.type === 'info',
                }"
            >
                <template x-if="toast.type === 'success'"><x-ui.icon name="check-circle" class="h-5 w-5" /></template>
                <template x-if="toast.type === 'error'"><x-ui.icon name="x-circle" class="h-5 w-5" /></template>
                <template x-if="toast.type === 'warning'"><x-ui.icon name="exclamation-triangle" class="h-5 w-5" /></template>
                <template x-if="toast.type === 'info'"><x-ui.icon name="information-circle" class="h-5 w-5" /></template>
            </span>

            <div class="min-w-0 flex-1 py-0.5">
                <p
                    x-show="toast.title"
                    x-text="toast.title"
                    class="text-sm font-semibold tracking-tight text-slate-900 dark:text-white"
                ></p>
                <p
                    x-text="toast.message"
                    class="text-sm text-slate-600 dark:text-slate-300"
                    :class="toast.title ? 'mt-0.5' : ''"
                ></p>
            </div>

            <button
                type="button"
                x-on:click="$store.toasts.dismiss(toast.id)"
                class="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                aria-label="Dismiss notification"
            >
                <x-ui.icon name="x-mark" class="h-4 w-4" />
            </button>
        </div>
    </template>
</div>
