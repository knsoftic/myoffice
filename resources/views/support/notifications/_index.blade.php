{{--
    The notification list, shared by every panel (phase-19-23 §7.7, §6.19).

    **One partial, four panel files that include it.** §7.7 registers the same routes under each
    prefix precisely so the bell behaves identically everywhere; four copies of this markup would
    drift, and the one that drifted would be the portal nobody opens by hand. Each panel's file
    supplies its own layout and nothing else.

    `$panel` comes from the controller, read from the route name rather than the URL — so every
    `route()` below resolves to the caller's own panel without this file knowing which one it is.

    **Archiving is not deleting** (§2.25). The row leaves the list and stays in the table: it is the
    evidence that the person was told, and the first question after any dispute is exactly that.
--}}

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <x-ui.form.select name="event" label="Event" placeholder="Any">
                @foreach ($events as $key => $event)
                    <option value="{{ $key }}" @selected(request('event') === $key)>{{ $event->title }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="level" label="Level" placeholder="Any">
                <option value="critical" @selected(request('level') === 'critical')>Critical</option>
                <option value="warning" @selected(request('level') === 'warning')>Warning</option>
                <option value="success" @selected(request('level') === 'success')>Success</option>
                <option value="info" @selected(request('level') === 'info')>Info</option>
            </x-ui.form.select>

            <label class="flex items-end gap-2 pb-2 text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="unread" value="1" @checked(request()->boolean('unread'))
                       class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                Unread only
            </label>

            <label class="flex items-end gap-2 pb-2 text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="archived" value="1" @checked(request()->boolean('archived'))
                       class="rounded border-slate-300 text-slate-500 focus:ring-slate-400 dark:border-slate-600 dark:bg-slate-800">
                Archived
            </label>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route($panel.'.notifications.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :padded="false">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 p-3 dark:border-slate-700">
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ app_number($unread) }} unread
            </p>

            <div class="flex gap-2">
                <form method="POST" action="{{ route($panel.'.notifications.read-all') }}">
                    @csrf
                    <x-ui.button type="submit" variant="ghost" size="sm">Mark all read</x-ui.button>
                </form>

                <form method="POST" action="{{ route($panel.'.notifications.archive-all') }}">
                    @csrf
                    <x-ui.button type="submit" variant="ghost" size="sm">Archive all</x-ui.button>
                </form>

                <x-ui.button variant="secondary" size="sm" :href="route($panel.'.notifications.preferences')">Preferences</x-ui.button>
            </div>
        </div>

        @if ($notifications->isEmpty())
            <x-ui.empty-state icon="bell" title="Nothing here"
                              description="Notifications arrive as things happen. What you receive is yours to choose, under Preferences." />
        @else
            <ul class="divide-y divide-slate-200 dark:divide-slate-700">
                @foreach ($notifications as $row)
                    @php($data = json_decode((string) $row->data, true) ?: [])

                    <li @class(['flex items-start gap-3 p-4', 'bg-brand-50/40 dark:bg-brand-950/10' => $row->read_at === null])>
                        <span @class([
                            'mt-1 h-2 w-2 shrink-0 rounded-full',
                            'bg-rose-500' => $row->level === 'critical',
                            'bg-amber-500' => $row->level === 'warning',
                            'bg-emerald-500' => $row->level === 'success',
                            'bg-sky-500' => $row->level === 'info',
                        ])></span>

                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium text-slate-700 dark:text-slate-200">
                                @if ($row->url)
                                    <a href="{{ route($panel.'.notifications.go', $row->id) }}" class="hover:underline">
                                        {{ $data['title'] ?? $row->event_key }}
                                    </a>
                                @else
                                    {{ $data['title'] ?? $row->event_key }}
                                @endif
                            </div>

                            <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ $data['body'] ?? '' }}</p>

                            <p class="mt-1 text-xs text-slate-400">
                                {{ \Illuminate\Support\Carbon::parse($row->created_at)->diffForHumans() }}
                                @if ($row->archived_at)
                                    · archived
                                @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 gap-1">
                            @if ($row->read_at === null)
                                <form method="POST" action="{{ route($panel.'.notifications.read', $row->id) }}">
                                    @csrf
                                    <x-ui.button type="submit" variant="ghost" size="sm">Mark read</x-ui.button>
                                </form>
                            @endif

                            @if ($row->archived_at === null)
                                <form method="POST" action="{{ route($panel.'.notifications.archive', $row->id) }}">
                                    @csrf
                                    <x-ui.button type="submit" variant="ghost" size="sm">Archive</x-ui.button>
                                </form>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        <x-ui.pagination-summary :paginator="$notifications" label="notifications" />
    </x-ui.card>
@endsection
