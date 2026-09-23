@extends('layouts.admin')

@section('title', 'Support tickets')

{{--
    The queue — admin.tickets.index (phase-19-23 §8, §9.4).

    The default sort is not a column. An urgent ticket that has been waiting an hour belongs above a
    low one raised a minute ago, and no single column says that — so the controller orders by
    priority and then by last activity, and the sort menu offers the plain columns underneath.

    "Breaching" is a filter rather than a separate screen: a desk that has to navigate somewhere else
    to find what is late will find it late.
--}}

@section('header')
    <x-ui.page-header title="Support tickets"
                      subtitle="Everything anybody has asked for help with. A ticket is never deleted — a wrong one is closed, and a wrong reply is corrected by another reply."
                      icon="lifebuoy">
        <x-slot:actions>
            @if ($canViewReports)
                <x-ui.button variant="secondary" icon="chart-bar" :href="route('admin.tickets.sla')">SLA</x-ui.button>
            @endif
            @if ($canCreate)
                <x-ui.button icon="plus" :href="route('admin.tickets.create')">New ticket</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Subject or number" />

            <x-ui.form.select name="status" label="Status" placeholder="Any">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="priority" label="Priority" placeholder="Any">
                @foreach ($priorities as $priority)
                    <option value="{{ $priority->value }}" @selected(request('priority') === $priority->value)>{{ $priority->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="department" label="Desk" placeholder="Any">
                @foreach ($departments as $department)
                    <option value="{{ $department->id }}" @selected((int) request('department') === (int) $department->id)>{{ $department->name }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="assignee" label="Assignee" placeholder="Anyone">
                <option value="none" @selected(request('assignee') === 'none')>Nobody yet</option>
                @foreach ($agents as $agent)
                    <option value="{{ $agent->id }}" @selected((int) request('assignee') === (int) $agent->id)>{{ $agent->name }}</option>
                @endforeach
            </x-ui.form.select>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.tickets.index')">Clear</x-ui.button>
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-600 sm:col-span-2 dark:text-slate-300">
                <input type="checkbox" name="mine" value="1" @checked(request()->boolean('mine'))
                       class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                Assigned to me
            </label>

            <label class="flex items-center gap-2 text-sm text-slate-600 sm:col-span-2 dark:text-slate-300">
                <input type="checkbox" name="breaching" value="1" @checked(request()->boolean('breaching'))
                       class="rounded border-slate-300 text-rose-600 focus:ring-rose-500 dark:border-slate-600 dark:bg-slate-800">
                Past its target
            </label>
        </form>
    </x-ui.card>

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$tickets->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Ticket</th>
                <th class="px-4 py-3 text-left font-semibold">Desk</th>
                <th class="px-4 py-3 text-left font-semibold">Requester</th>
                <th class="px-4 py-3 text-left font-semibold">Assignee</th>
                <th class="px-4 py-3 text-left font-semibold">Priority</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
                <th class="px-4 py-3 text-left font-semibold">Activity</th>
            </x-slot:head>

            @foreach ($tickets as $ticket)
                <tr @class(['bg-rose-50/40 dark:bg-rose-950/20' => ($ticket->firstResponseOverdue() || $ticket->resolutionOverdue())])>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.tickets.show', $ticket) }}"
                           class="text-sm font-medium text-slate-700 hover:underline dark:text-slate-200">{{ $ticket->subject }}</a>
                        <div class="text-xs text-slate-400">
                            {{ $ticket->ticket_number }}
                            @if (($ticket->firstResponseOverdue() || $ticket->resolutionOverdue()))
                                · <span class="text-rose-500">past target</span>
                            @endif
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $ticket->department?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $ticket->requester?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $ticket->assignee?->name ?? '—' }}
                    </td>
                    <td class="px-4 py-3">
                        <x-ui.badge :color="$ticket->priority->color()" size="sm">{{ $ticket->priority->label() }}</x-ui.badge>
                    </td>
                    <td class="px-4 py-3">
                        <x-ui.badge :color="$ticket->status->color()" size="sm">{{ $ticket->status->label() }}</x-ui.badge>
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400">
                        {{ ($ticket->last_reply_at ?? $ticket->created_at)?->diffForHumans() }}
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="lifebuoy" title="Nothing on the queue"
                                  description="Either everything is answered, or the filters above are narrower than you meant." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$tickets" label="tickets" />
    </x-ui.card>
@endsection
