@props([
    'groups' => [],
])

{{--
    x-settings.tab-rail — the group navigation (phase-02 §5: "a vertical tab rail (group icon +
    label, active state, mobile: a select) beside a form card").

    Under `lg` the rail would eat the whole first screen, so it collapses to a select that
    navigates on change. Both render the same list from SettingsRegistry::groups(), in `sort`
    order, and both mark the group whose form is on screen.

    A group the user may not edit keeps its link — the form renders read-only — and shows a lock,
    so "why can I not change this" is answered before the click rather than after it.
--}}

@php
    $options = [];

    foreach ($groups as $group) {
        $options[$group['url']] = $group['label'].($group['locked'] ? ' (read-only)' : '');
    }

    $currentUrl = collect($groups)->firstWhere('active', true)['url'] ?? null;
@endphp

<nav aria-label="Settings groups">
    {{-- Mobile ------------------------------------------------------------------------- --}}
    <div class="lg:hidden">
        <x-ui.form.select
            id="settings-group-select"
            label="Settings group"
            icon="cog-6-tooth"
            :options="$options"
            :selected="$currentUrl"
            x-on:change="window.location.href = $event.target.value"
        />
    </div>

    {{-- Desktop ------------------------------------------------------------------------ --}}
    <ul class="hidden space-y-0.5 lg:block" role="list">
        @foreach ($groups as $group)
            @php
                $active = (bool) $group['active'];

                $linkClass = $active
                    ? 'bg-brand-50 font-semibold text-brand-700 ring-1 ring-inset ring-brand-200 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/25'
                    : 'font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white';

                $iconClass = $active
                    ? 'mt-0.5 h-4 w-4 shrink-0 text-brand-600 dark:text-brand-400'
                    : 'mt-0.5 h-4 w-4 shrink-0 text-slate-400 group-hover:text-slate-600 dark:text-slate-500 dark:group-hover:text-slate-300';
            @endphp

            <li>
                <a
                    href="{{ $group['url'] }}"
                    class="group flex items-start gap-2.5 rounded-lg px-3 py-2 text-sm transition-colors {{ $linkClass }}"
                    @if ($active) aria-current="page" @endif
                >
                    <x-ui.icon :name="$group['icon']" :class="$iconClass" />

                    <span class="min-w-0 flex-1 truncate">{{ $group['label'] }}</span>

                    @if ($group['locked'])
                        <x-ui.icon name="lock-closed" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-500" label="Read-only for you" />
                    @endif
                </a>
            </li>
        @endforeach
    </ul>
</nav>
