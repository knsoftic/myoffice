{{--
    RecentActivityWidget body — a flush table, so the card reads as a list and not as a paragraph.

    Every row was eager-loaded with its causer in the widget, so nothing in this loop can issue a
    query. If you add a column here, add it to the widget's `data()` first: a Blade file that
    touches a model is how an N+1 gets back in.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state
        icon="clipboard-document-list"
        title="Activity log unavailable"
        message="The activity log table could not be read."
        :compact="true"
    />
@else
    <x-ui.table :is-empty="empty($data['entries'])" dense :flush="true" caption="Recent activity">
        <x-slot:head>
            <th class="px-4 py-3">Who</th>
            <th class="px-4 py-3">What happened</th>
            <th class="px-4 py-3">Module</th>
            <th class="px-4 py-3 text-right">When</th>
        </x-slot:head>

        @foreach ($data['entries'] as $entry)
            <tr>
                <td class="whitespace-nowrap">
                    @if ($entry['causer_name'])
                        <div class="flex items-center gap-2.5">
                            <x-ui.avatar :src="$entry['causer_avatar']" :name="$entry['causer_name']" size="sm" />
                            <span class="truncate font-medium text-slate-900 dark:text-white">{{ $entry['causer_name'] }}</span>
                        </div>
                    @else
                        <span class="inline-flex items-center gap-2 text-slate-500 dark:text-slate-400">
                            <x-ui.icon name="cog-6-tooth" class="h-4 w-4" />
                            System
                        </span>
                    @endif
                </td>

                <td class="max-w-xs">
                    @if ($entry['href'])
                        <a
                            href="{{ $entry['href'] }}"
                            class="block truncate font-medium text-slate-900 transition-colors hover:text-brand-600 dark:text-white dark:hover:text-brand-400"
                            title="{{ $entry['description'] }}"
                        >{{ $entry['description'] }}</a>
                    @else
                        <span class="block truncate font-medium text-slate-900 dark:text-white" title="{{ $entry['description'] }}">
                            {{ $entry['description'] }}
                        </span>
                    @endif

                    @if ($entry['event'])
                        <span class="text-xs text-slate-500 dark:text-slate-400">{{ $entry['event'] }}</span>
                    @endif
                </td>

                <td class="whitespace-nowrap">
                    @if ($entry['module_label'])
                        <x-ui.badge color="slate" size="sm">{{ $entry['module_label'] }}</x-ui.badge>
                    @else
                        <span class="text-slate-400 dark:text-slate-600">—</span>
                    @endif
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
                icon="clipboard-document-list"
                title="Nothing recorded in {{ $data['range_label'] }}"
                :message="$widget->emptyMessage"
                :compact="true"
            />
        </x-slot:empty>
    </x-ui.table>
@endif
