@props([
    'name' => null,
    'title' => null,
    'subtitle' => null,
    'icon' => null,
    'size' => 'md',
    'closeable' => true,
    'show' => false,
])

{{--
    x-ui.modal — Alpine dialog with a focus trap, Escape and backdrop close.

        <x-ui.button x-on:click="$dispatch('open-modal', 'invite-user')">Invite</x-ui.button>

        <x-ui.modal name="invite-user" title="Invite a user" icon="user-plus">
            <form …>…</form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="hide(true)">Cancel</x-ui.button>
                <x-ui.button type="submit" form="invite-form">Send invite</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>

    Close from anywhere with $dispatch('close-modal', 'invite-user').
--}}

@php
    $sizes = [
        'sm' => 'sm:max-w-sm',
        'md' => 'sm:max-w-lg',
        'lg' => 'sm:max-w-2xl',
        'xl' => 'sm:max-w-4xl',
        'full' => 'sm:max-w-[calc(100vw-4rem)]',
    ];

    $maxWidth = $sizes[$size] ?? $sizes['md'];
    $titleId = 'modal-title-'.($name ?? uniqid());
@endphp

<div
    x-data="uiModal(@js($name), @js((bool) $closeable))"
    @if ($show) x-init="show()" @endif
    x-on:open-modal.window="onWindowOpen($event)"
    x-on:close-modal.window="onWindowClose($event)"
    x-on:keydown.escape.window="hide()"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-modal overflow-y-auto"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $titleId }}"
    style="display: none"
>
    {{-- Backdrop --}}
    <div
        x-show="open"
        x-transition:enter="ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-on:click="hide()"
        class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm dark:bg-slate-950/70"
        aria-hidden="true"
    ></div>

    <div class="flex min-h-full items-end justify-center p-4 sm:items-center sm:p-6">
        <div
            x-show="open"
            x-trap.noscroll="open"
            x-transition:enter="ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave="ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            tabindex="-1"
            {{ $attributes->class("relative w-full {$maxWidth} overflow-hidden rounded-xl bg-white shadow-modal ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800") }}
        >
            @if (filled($title) || $closeable)
                <div class="flex items-start gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                    @if ($icon)
                        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                            <x-ui.icon :name="$icon" class="h-[1.125rem] w-[1.125rem]" />
                        </span>
                    @endif

                    <div class="min-w-0 flex-1">
                        <h2 id="{{ $titleId }}" class="text-base font-semibold tracking-tight text-slate-900 dark:text-white">
                            {{ $title }}
                        </h2>

                        @if (filled($subtitle))
                            <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ $subtitle }}</p>
                        @endif
                    </div>

                    @if ($closeable)
                        <x-ui.icon-button
                            icon="x-mark"
                            label="Close dialog"
                            size="sm"
                            class="-mr-1.5 -mt-1"
                            x-on:click="hide(true)"
                        />
                    @endif
                </div>
            @endif

            <div class="max-h-[70vh] overflow-y-auto px-5 py-4 text-sm text-slate-600 dark:text-slate-300">
                {{ $slot }}
            </div>

            @isset($footer)
                <div class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50/70 px-5 py-4 sm:flex-row sm:justify-end dark:border-slate-800 dark:bg-slate-900/60">
                    {{ $footer }}
                </div>
            @endisset
        </div>
    </div>
</div>
