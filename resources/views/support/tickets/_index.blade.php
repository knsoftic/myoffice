{{--
    A portal's ticket list, shared by all four panels (phase-19-23 §8, §9.4).

    The scope is the controller's `mine()`, which is `SupportTicketPolicy`'s answer applied to a
    query rather than a second opinion — a list that showed a row the detail page then refused to
    open would be worse than showing nothing.

    There is no priority filter and no assignee column. A requester does not set a priority
    (§12.2 Q6) and has no reason to care which agent has it; what they want to know is whether
    anybody has answered.
--}}

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-3">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Subject or number" />

            <x-ui.form.select name="status" label="Status" placeholder="Any">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route($panel.'.tickets.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$tickets->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Ticket</th>
                <th class="px-4 py-3 text-left font-semibold">Desk</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
                <th class="px-4 py-3 text-left font-semibold">Last activity</th>
            </x-slot:head>

            @foreach ($tickets as $ticket)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route($panel.'.tickets.show', $ticket) }}"
                           class="text-sm font-medium text-slate-700 hover:underline dark:text-slate-200">{{ $ticket->subject }}</a>
                        <div class="text-xs text-slate-400">{{ $ticket->ticket_number }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $ticket->department?->name ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <x-ui.badge :color="$ticket->status->color()" size="sm">{{ $ticket->status->label() }}</x-ui.badge>
                        @if ($ticket->awaitingUs())
                            <div class="mt-1 text-xs text-slate-400">with the team</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400">
                        {{ ($ticket->last_reply_at ?? $ticket->created_at)?->diffForHumans() }}
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="lifebuoy" title="No tickets"
                                  description="When you ask us for help, it appears here — with every reply, in order." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$tickets" label="tickets" />
    </x-ui.card>
@endsection
