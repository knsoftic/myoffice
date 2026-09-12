{{--
    Submit button with a loading state, shared by every auth form.

        <form x-data="{ busy: false }" x-on:submit="busy = true">
            …
            @include('auth.partials.submit', ['label' => 'Sign in', 'busyLabel' => 'Signing in…'])
        </form>

    The form owns the `busy` flag (one Alpine scope per form); this partial only reacts to it, so
    a double click cannot post twice and the user can see that something is happening. With
    JavaScript disabled the button is an ordinary submit button.
--}}

@php
    $submitLabel = $label ?? 'Continue';
    $submitBusyLabel = $busyLabel ?? $submitLabel;
@endphp

<x-ui.button
    type="submit"
    size="lg"
    :block="true"
    x-bind:disabled="busy"
    x-bind:aria-busy="busy"
    x-bind:class="busy ? 'cursor-wait opacity-70' : ''"
>
    <span class="inline-flex items-center justify-center gap-2">
        <svg
            x-show="busy"
            x-cloak
            class="h-4 w-4 animate-spin"
            viewBox="0 0 24 24"
            fill="none"
            aria-hidden="true"
            style="display: none"
        >
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
            <path class="opacity-90" d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
        </svg>

        <span x-text="busy ? {{ \Illuminate\Support\Js::from($submitBusyLabel) }} : {{ \Illuminate\Support\Js::from($submitLabel) }}">{{ $submitLabel }}</span>
    </span>
</x-ui.button>
