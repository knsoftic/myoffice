{{--
    ModulesEnabledWidget body.

    The number that matters is not "how many modules exist" but "which ones are off", because a
    disabled module denies its permissions to everyone, Super Admin included (D5). So the disabled
    list is named explicitly rather than summarised as a count.
--}}

@if (! ($data['available'] ?? false) || ($data['total'] ?? 0) === 0)
    <x-ui.empty-state
        icon="puzzle-piece"
        title="No modules registered"
        :message="$widget->emptyMessage"
        :compact="true"
    />
@else
    <div class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">
                    {{ app_number($data['enabled']) }}<span class="text-lg text-slate-400 dark:text-slate-500">/{{ app_number($data['total']) }}</span>
                </p>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                    modules switched on · {{ app_number($data['core']) }} core and never disableable
                </p>
            </div>

            <x-ui.badge :color="$data['disabled'] === 0 ? 'emerald' : 'amber'" size="sm" :dot="true">
                {{ $data['disabled'] === 0 ? 'all on' : app_number($data['disabled']).' off' }}
            </x-ui.badge>
        </div>

        @include('admin.dashboard.partials.meter', [
            'segments' => [
                ['color' => 'emerald', 'share' => $data['share'], 'label' => 'Enabled'],
            ],
            'height' => 'h-2.5',
        ])

        <ul class="space-y-2">
            @foreach ($data['groups'] as $group)
                <li class="flex items-center gap-3">
                    <span class="w-28 shrink-0 truncate text-xs text-slate-600 dark:text-slate-300">{{ $group['label'] }}</span>

                    <span class="min-w-0 flex-1">
                        @include('admin.dashboard.partials.meter', [
                            'segments' => [
                                ['color' => $group['color'], 'share' => $group['share'], 'label' => $group['label']],
                            ],
                            'height' => 'h-1.5',
                        ])
                    </span>

                    <span class="w-12 shrink-0 text-right text-xs text-slate-500 tabular-nums dark:text-slate-400">
                        {{ app_number($group['enabled']) }}/{{ app_number($group['total']) }}
                    </span>
                </li>
            @endforeach
        </ul>

        @if (! empty($data['disabled_modules']))
            <div class="border-t border-slate-100 pt-3 dark:border-slate-800">
                <p class="mb-2 text-2xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                    Currently off
                </p>

                <div class="flex flex-wrap gap-1.5">
                    @foreach ($data['disabled_modules'] as $module)
                        <x-ui.badge :color="$module['color']" size="xs" variant="outline">{{ $module['name'] }}</x-ui.badge>
                    @endforeach

                    @if ($data['disabled_more'] > 0)
                        <x-ui.badge color="slate" size="xs">+{{ app_number($data['disabled_more']) }} more</x-ui.badge>
                    @endif
                </div>
            </div>
        @endif
    </div>
@endif
