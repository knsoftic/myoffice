@extends('layouts.admin')

@section('title', 'Ticket targets')

{{--
    The SLA desk — admin.tickets.sla (phase-19-23 §93, §7.6).

    Three figures and the list behind the one that matters. A target that can be missed quietly is
    not a target, so what is late sits on the same page as how much is late, ordered by how late.

    Every count is scoped exactly as the queue is, through the controller's `scoped()`: this screen
    cannot show a number that includes tickets the viewer could not open.
--}}

@section('header')
    <x-ui.page-header title="Ticket targets" subtitle="How the desk is doing against the times it promised." icon="chart-bar">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.tickets.index')">Back to the queue</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <x-ui.stat-card label="Open" :value="app_number($open)" icon="inbox" />
        <x-ui.stat-card label="Past target" :value="app_number($breaching)" icon="exclamation-triangle" />
        <x-ui.stat-card label="Nobody assigned" :value="app_number($unassigned)" icon="user-minus" />
    </div>

    <x-ui.card class="mb-4">
        <x-ui.section-heading title="Open by desk" />
        <div class="mt-3 space-y-2">
            @forelse ($departments as $department)
                @php($count = (int) ($byDepartment[$department->id] ?? 0))
                <div class="flex items-center justify-between text-sm">
                    <a href="{{ route('admin.tickets.index', ['department' => $department->id]) }}"
                       class="text-slate-600 hover:underline dark:text-slate-300">{{ $department->name }}</a>
                    <span class="tabular-nums text-slate-500 dark:text-slate-400">{{ app_number($count) }}</span>
                </div>
            @empty
                <p class="text-sm text-slate-500 dark:text-slate-400">No desks are configured yet.</p>
            @endforelse
        </div>
    </x-ui.card>

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$breachingTickets->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Ticket</th>
                <th class="px-4 py-3 text-left font-semibold">Desk</th>
                <th class="px-4 py-3 text-left font-semibold">Assignee</th>
                <th class="px-4 py-3 text-left font-semibold">Due</th>
            </x-slot:head>

            @foreach ($breachingTickets as $ticket)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.tickets.show', $ticket) }}"
                           class="text-sm font-medium text-slate-700 hover:underline dark:text-slate-200">{{ $ticket->subject }}</a>
                        <div class="text-xs text-slate-400">{{ $ticket->ticket_number }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $ticket->department?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $ticket->assignee?->name ?? 'Nobody' }}</td>
                    <td class="px-4 py-3 text-sm text-rose-500">
                        {{ $ticket->resolution_due_at?->diffForHumans() ?? $ticket->first_response_due_at?->diffForHumans() ?? '—' }}
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="check-circle" title="Nothing is late"
                                  description="Every open ticket is inside the time its desk promised." />
            </x-slot:empty>
        </x-ui.table>
    </x-ui.card>
@endsection
