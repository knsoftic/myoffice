@props([
    'align' => 'right',
    'width' => 'w-56',
    'label' => 'Open menu',
    'icon' => 'ellipsis-vertical',
])

{{--
    x-ui.dropdown — anchored, keyboard-navigable menu.

        <x-ui.dropdown label="Row actions">
            <x-ui.dropdown-item :href="route('admin.users.edit', $user)" icon="pencil">Edit</x-ui.dropdown-item>
            <x-ui.dropdown-item icon="trash" variant="danger" x-on:click="$dispatch('open-modal', 'delete-user')">
                Delete
            </x-ui.dropdown-item>
        </x-ui.dropdown>

    Pass a <x-slot:trigger> to replace the default icon button.
    Keyboard: ArrowDown/Up to move, Home/End to jump, Escape to close, Tab to leave.
--}}

@php
    $alignment = match ($align) {
        'left' => 'left-0 origin-top-left',
        'center' => 'left-1/2 -translate-x-1/2 origin-top',
        default => 'right-0 origin-top-right',
    };
@endphp

<div
    x-data="uiDropdown()"
    x-on:keydown.escape.prevent.stop="close()"
    {{ $attributes->class('relative inline-block text-left') }}
>
    <div
        x-ref="trigger"
        x-on:click="toggle()"
        x-on:keydown="onTriggerKeydown($event)"
    >
        @isset($trigger)
            {{ $trigger }}
        @else
            <x-ui.icon-button
                :icon="$icon"
                :label="$label"
                size="sm"
                x-bind:aria-expanded="open"
                aria-haspopup="menu"
            />
        @endisset
    </div>

    <div
        x-ref="menu"
        x-show="open"
        x-cloak
        x-on:click.outside="close(false)"
        x-on:keydown="onMenuKeydown($event)"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        role="menu"
        aria-orientation="vertical"
        tabindex="-1"
        class="absolute z-dropdown mt-2 {{ $width }} {{ $alignment }} max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl bg-white p-1 shadow-dropdown ring-1 ring-slate-200 focus:outline-none dark:bg-slate-900 dark:ring-slate-700"
    >
        {{ $slot }}
    </div>
</div>
