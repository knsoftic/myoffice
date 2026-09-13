@props([
    'field' => [],
])

{{--
    x-settings.password — the `password` type (phase-02 §5: "password fields render masked with a
    'change' toggle and never echo the stored value").

    The stored secret does not reach this component at all: the controller hands over a boolean
    saying whether one exists, and the mask below is `Setting::MASK`, not a value. Grepping the
    rendered HTML for the SMTP password finds nothing — there is nothing to find.

    Until "Change" is pressed the real input carries `disabled`, so it posts no key at all and the
    service leaves the stored secret exactly where it is. An emptied field is also "unchanged"
    (SettingsService::meansUnchanged), so the help text says how a stored secret really is removed:
    "Reset to defaults" in the danger zone. (It used to promise that saving an empty field removed
    it, which the service never did — an operator revoking a leaked credential was misled.)
--}}

@php
    $f = $field;

    $disabled = (bool) ($f['disabled'] ?? false);
    $hasSecret = (bool) ($f['has_secret'] ?? false);
    $mask = \App\Models\Setting::MASK;

    $shell = 'flex w-full items-center justify-between gap-3 rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900/60';
@endphp

<div x-data="{ changing: false, revealed: false }">
    {{-- Stored state ------------------------------------------------------------------- --}}
    <div x-show="! changing" class="{{ $shell }}">
        <span class="flex min-w-0 items-center gap-2">
            <x-ui.icon
                :name="$hasSecret ? 'lock-closed' : 'lock-open'"
                class="h-4 w-4 shrink-0 {{ $hasSecret ? 'text-emerald-500' : 'text-slate-400 dark:text-slate-500' }}"
            />

            @if ($hasSecret)
                <span class="select-none font-mono tracking-widest text-slate-500 dark:text-slate-400">{{ $mask }}</span>
                <span class="text-xs text-slate-400 dark:text-slate-500">stored, encrypted</span>
            @else
                <span class="text-slate-500 dark:text-slate-400">Not set</span>
            @endif
        </span>

        @unless ($disabled)
            <button
                type="button"
                x-on:click="changing = true; $nextTick(() => $refs.secret.focus())"
                class="shrink-0 rounded-md px-2 py-1 text-xs font-semibold text-brand-600 transition-colors hover:bg-white dark:text-brand-400 dark:hover:bg-slate-800"
            >
                {{ $hasSecret ? 'Change' : 'Set' }}
            </button>
        @endunless
    </div>

    {{-- Change state ------------------------------------------------------------------- --}}
    <div x-show="changing" x-cloak class="space-y-1.5">
        <div class="flex items-center gap-2">
            <div class="relative flex-1">
                <input
                    x-ref="secret"
                    id="{{ $f['id'] }}"
                    name="{{ $f['name'] }}"
                    type="password"
                    x-bind:type="revealed ? 'text' : 'password'"
                    x-bind:disabled="! changing"
                    disabled
                    autocomplete="new-password"
                    autocapitalize="off"
                    spellcheck="false"
                    placeholder="{{ $hasSecret ? 'Enter the new value' : 'Enter the value' }}"
                    @class([
                        'block w-full rounded-lg border bg-white py-2 pl-3 pr-10 text-sm shadow-sm transition duration-150',
                        'text-slate-900 placeholder:text-slate-400 dark:bg-slate-950/40 dark:text-white dark:placeholder:text-slate-500',
                        'border-rose-400 focus:border-rose-500 focus:ring-2 focus:ring-rose-500/20 dark:border-rose-500/60' => $errors->has($f['error_key']),
                        'border-slate-300 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-slate-700' => ! $errors->has($f['error_key']),
                    ])
                />

                <button
                    type="button"
                    x-on:click="revealed = ! revealed"
                    class="absolute inset-y-0 right-0 flex items-center px-3 text-slate-400 transition-colors hover:text-slate-700 dark:hover:text-slate-200"
                    x-bind:aria-label="revealed ? 'Hide the value' : 'Show the value'"
                >
                    <x-ui.icon name="eye" class="h-4 w-4" x-show="! revealed" />
                    <x-ui.icon name="eye-slash" class="h-4 w-4" x-show="revealed" x-cloak />
                </button>
            </div>

            <button
                type="button"
                x-on:click="changing = false; revealed = false; $refs.secret.value = ''"
                class="shrink-0 rounded-md px-2 py-1 text-xs font-semibold text-slate-500 transition-colors hover:text-slate-800 dark:hover:text-slate-200"
            >
                Cancel
            </button>
        </div>
    </div>

    <x-ui.form.help icon="information-circle">
        {{ $f['help'] ?? 'Encrypted at rest and never shown again.' }}
        @if ($hasSecret)
            <span x-show="changing" x-cloak>Leaving it empty keeps the stored value; to remove it, reset this group to its defaults.</span>
        @endif
    </x-ui.form.help>

    <x-ui.form.error :for="$f['error_key']" />
</div>
