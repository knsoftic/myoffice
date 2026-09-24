@props([
    'action' => null,
    'method' => 'DELETE',
    'title' => 'Are you sure?',
    'message' => 'This action cannot be undone.',
    'confirmLabel' => 'Delete',
    'cancelLabel' => 'Cancel',
    'requireText' => null,
    'icon' => 'exclamation-triangle',
    'variant' => 'danger',
])

{{--
    x-ui.confirm — wraps a destructive form behind a dialog. The form is only ever submitted
    from inside the dialog, so a stray click can never delete anything.

        <x-ui.confirm
            :action="route('admin.users.destroy', $user)"
            title="Delete {{ $user->name }}?"
            message="Their login is revoked immediately. Records they created stay in place."
            confirm-label="Delete user"
        >
            <x-slot:trigger>
                <x-ui.icon-button icon="trash" label="Delete user" variant="danger" />
            </x-slot:trigger>
        </x-ui.confirm>

    Pass `require-text` (e.g. the record's name) to demand the user types it before the
    confirm button unlocks — use it for anything irreversible at scale.

    Authorization still belongs on the backend: this is UX, not security.
--}}

@php
    $tone = $variant === 'danger'
        ? ['bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400', 'danger']
        : ['bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400', 'primary'];

    [$iconTone, $buttonVariant] = $tone;
    $titleId = 'confirm-title-'.uniqid();
@endphp

<div x-data="uiConfirm(@js($requireText))" class="inline-flex">
    <div x-on:click="show()" class="inline-flex">
        @isset($trigger)
            {{ $trigger }}
        @else
            <x-ui.button variant="danger" size="sm" icon="trash">{{ $confirmLabel }}</x-ui.button>
        @endisset
    </div>

    <div
        x-show="open"
        x-cloak
        x-on:keydown.escape.window="hide()"
        class="fixed inset-0 z-modal overflow-y-auto"
        role="dialog"
        aria-modal="true"
        aria-labelledby="{{ $titleId }}"
        style="display: none"
    >
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
                class="relative w-full overflow-hidden rounded-xl bg-white text-left shadow-modal ring-1 ring-slate-200 sm:max-w-md dark:bg-slate-900 dark:ring-slate-800"
            >
                <div class="flex gap-4 px-5 py-5">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full {{ $iconTone }}">
                        <x-ui.icon :name="$icon" class="h-5 w-5" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <h2 id="{{ $titleId }}" class="text-base font-semibold tracking-tight text-slate-900 dark:text-white">
                            {{ $title }}
                        </h2>

                        @if (filled($message))
                            <p class="mt-1.5 text-sm text-slate-500 dark:text-slate-400">{{ $message }}</p>
                        @endif

                        {{ $slot }}

                        @if (filled($requireText))
                            @php
                                // Unique per dialog: a settings screen renders this component once
                                // per group, and duplicate ids would point every label at the first
                                // input on the page.
                                $confirmInputId = 'confirm-text-'.\Illuminate\Support\Str::random(8);
                            @endphp

                            <div class="mt-4">
                                {{-- `for` is the association, not the proximity. Without it this is
                                     an unnamed text box on the one dialog whose whole purpose is to
                                     make somebody stop and read. --}}
                                <label for="{{ $confirmInputId }}" class="block text-xs font-medium text-slate-600 dark:text-slate-300">
                                    Type <span class="select-all font-semibold text-slate-900 dark:text-white">{{ $requireText }}</span> to confirm
                                </label>
                                <input
                                    id="{{ $confirmInputId }}"
                                    type="text"
                                    x-model="typed"
                                    x-on:keydown.enter.prevent="submit()"
                                    autocomplete="off"
                                    spellcheck="false"
                                    data-autofocus
                                    class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-rose-500 focus:ring-rose-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white"
                                />
                            </div>
                        @endif
                    </div>
                </div>

                <div class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50/70 px-5 py-4 sm:flex-row sm:justify-end dark:border-slate-800 dark:bg-slate-900/60">
                    <x-ui.button variant="secondary" size="md" x-on:click="hide()">{{ $cancelLabel }}</x-ui.button>

                    <form
                        x-ref="form"
                        method="POST"
                        action="{{ $action }}"
                        x-on:submit.prevent="submit()"
                        {{ $attributes }}
                    >
                        @csrf
                        @method($method)

                        @isset($fields)
                            {{ $fields }}
                        @endisset

                        <x-ui.button
                            type="submit"
                            :variant="$buttonVariant"
                            size="md"
                            block
                            x-bind:disabled="! confirmed || submitting"
                            x-bind:aria-busy="submitting"
                            x-bind:class="{ 'cursor-not-allowed opacity-60': ! confirmed || submitting }"
                        >
                            {{ $confirmLabel }}
                        </x-ui.button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
