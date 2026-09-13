@props([
    'readonly' => false,
    'public' => false,
])

{{--
    x-settings.field-badges — the two things an operator needs to know about a setting before
    they touch it: whether they are allowed to change it, and whether the value leaves the
    building.

    Rendered beside the label by x-settings.field; nothing else should use it.
--}}

@if ($readonly || $public)
    <div {{ $attributes->class('flex flex-wrap items-center gap-1.5') }}>
        @if ($readonly)
            <x-ui.badge color="amber" size="sm" icon="lock-closed">
                <span title="Changed from the console or the environment only.">Read-only</span>
            </x-ui.badge>
        @endif

        @if ($public)
            <x-ui.badge color="slate" size="sm" icon="globe-alt">
                <span title="Readable by the public website.">Public</span>
            </x-ui.badge>
        @endif
    </div>
@endif
