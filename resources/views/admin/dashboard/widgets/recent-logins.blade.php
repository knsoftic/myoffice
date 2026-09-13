{{--
    RecentLoginsWidget body.

    Failed and blocked rows carry a tint, because the sequence is the reading: three failures then a
    success from one address is a different story from three failures from three.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state
        icon="finger-print"
        title="Login history unavailable"
        message="The login history table could not be read."
        :compact="true"
    />
@else
    <x-ui.table :is-empty="empty($data['entries'])" dense :flush="true">
        <x-slot:head>
            <th class="px-4 py-3">Account</th>
            <th class="px-4 py-3">Outcome</th>
            <th class="px-4 py-3">Address · device</th>
            <th class="px-4 py-3 text-right">When</th>
        </x-slot:head>

        @foreach ($data['entries'] as $entry)
            <tr @class([
                'bg-rose-50/60 dark:bg-rose-500/5' => $entry['is_failure'],
                'bg-amber-50/60 dark:bg-amber-500/5' => $entry['is_blocked'],
            ])>
                <td class="whitespace-nowrap">
                    @if ($entry['is_known'])
                        <div class="flex items-center gap-2.5">
                            <x-ui.avatar :src="$entry['avatar']" :name="$entry['name']" size="sm" />
                            <span class="truncate font-medium text-slate-900 dark:text-white">{{ $entry['name'] }}</span>
                        </div>
                    @else
                        <span class="truncate text-slate-500 dark:text-slate-400" title="No account matched this attempt">
                            {{ $entry['name'] }}
                        </span>
                    @endif
                </td>

                <td class="whitespace-nowrap">
                    <x-ui.badge :color="$entry['status_color']" size="sm" :dot="true">
                        {{ $entry['status_label'] }}
                    </x-ui.badge>
                </td>

                <td class="whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">
                    <span class="font-medium tabular-nums text-slate-700 dark:text-slate-300">
                        {{ $entry['ip_address'] ?? '—' }}
                    </span>
                    <span class="mx-1 text-slate-300 dark:text-slate-700">·</span>
                    <span class="inline-flex items-center gap-1">
                        <x-ui.icon :name="$entry['device_icon']" class="h-3.5 w-3.5" />
                        {{ $entry['device'] }}
                    </span>
                </td>

                <td class="whitespace-nowrap text-right text-xs text-slate-500 tabular-nums dark:text-slate-400">
                    @if ($entry['at'])
                        <time datetime="{{ $entry['at'] }}" title="{{ $entry['at_label'] }}">{{ $entry['ago'] }}</time>
                    @else
                        —
                    @endif
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state
                icon="finger-print"
                title="No attempts in {{ $data['range_label'] }}"
                :message="$widget->emptyMessage"
                :compact="true"
            />
        </x-slot:empty>
    </x-ui.table>
@endif
