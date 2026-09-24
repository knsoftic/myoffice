{{--
    A portal's diary (phase-19-23 §8, §9.4).

    Upcoming ascending by default, because a diary is read forwards; switching to past flips both
    the filter and the order.

    The answer column is the point of this screen. Somebody scanning it wants to know which
    invitations are still waiting on them, which is why the pending ones carry a badge and the rest
    do not.
--}}

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <x-ui.form.select name="when" label="Show">
                <option value="upcoming" @selected(! $past)>Upcoming</option>
                <option value="past" @selected($past)>Past</option>
            </x-ui.form.select>

            <x-ui.button type="submit" variant="secondary" icon="funnel">Show</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$meetings->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Meeting</th>
                <th class="px-4 py-3 text-left font-semibold">When</th>
                <th class="px-4 py-3 text-left font-semibold">Where</th>
                <th class="px-4 py-3 text-left font-semibold">Your answer</th>
            </x-slot:head>

            @foreach ($meetings as $meeting)
                @php($mine = $meeting->participants->firstWhere('user_id', auth()->id()))

                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route($panel.'.meetings.show', $meeting) }}"
                           class="text-sm font-medium text-slate-700 hover:underline dark:text-slate-200">{{ $meeting->title }}</a>
                        <div class="text-xs text-slate-400">with {{ $meeting->organizer?->name ?? 'the team' }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ app_datetime($meeting->scheduled_at) }}
                        <div class="text-xs text-slate-400">{{ app_number($meeting->duration_minutes) }} minutes</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $meeting->classroom?->name ?? $meeting->location ?? $meeting->delivery_mode->label() }}
                    </td>
                    <td class="px-4 py-3">
                        @if ($meeting->status->isLive() && $mine)
                            <x-ui.badge :color="$mine->response->color()" size="sm">{{ $mine->response->label() }}</x-ui.badge>
                        @else
                            <x-ui.badge :color="$meeting->status->color()" size="sm">{{ $meeting->status->label() }}</x-ui.badge>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="video-camera" title="Nothing in the diary"
                                  description="Meetings you are invited to appear here, with Accept and Decline on each one." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$meetings" label="meetings" />
    </x-ui.card>
@endsection
