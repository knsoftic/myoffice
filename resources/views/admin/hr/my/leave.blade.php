@extends('layouts.admin')

@section('title', 'My leave')

@section('header')
    <x-ui.page-header title="My leave" :subtitle="'Leave year ' . $year" icon="calendar">
        <x-slot:actions>
            <x-ui.button :href="route('admin.my.leave.create')" icon="plus">Apply for leave</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-ui.card :title="$requests->count() . ' request(s)'">
                <x-ui.table :is-empty="$requests->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Request</th>
                        <th class="px-4 py-3 text-left font-semibold">Dates</th>
                        <th class="px-4 py-3 text-right font-semibold">Days</th>
                        <th class="px-4 py-3 text-left font-semibold">Status</th>
                    </x-slot:head>

                    @foreach ($requests as $leave)
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.my.leave.show', $leave) }}"
                                   class="block font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $leave->request_number }}</a>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $leave->leaveType?->name }}</span>
                            </td>
                            <td class="px-4 py-3 tabular-nums text-slate-600 dark:text-slate-300">
                                {{ app_date($leave->from_date) }}
                                @unless ($leave->from_date->equalTo($leave->to_date)) – {{ app_date($leave->to_date) }} @endunless
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ app_number((float) $leave->total_days, 2) }}</td>
                            <td class="px-4 py-3"><x-ui.badge :color="$leave->status->color()" size="xs">{{ $leave->status->label() }}</x-ui.badge></td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="calendar" title="You have not applied for leave yet" />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>
        </div>

        <x-ui.card title="My balance">
            @if ($balances->isEmpty())
                <p class="text-sm text-slate-500 dark:text-slate-400">No quota has been granted for this leave year yet.</p>
            @else
                <ul class="space-y-3">
                    @foreach ($balances as $balance)
                        <li class="flex items-center justify-between gap-3">
                            <div>
                                <span class="block text-sm text-slate-900 dark:text-white">{{ $balance->leaveType?->name }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">
                                    {{ app_number((float) $balance->consumed_days, 2) }} taken
                                    @if ((float) $balance->pending_days > 0)
                                        · {{ app_number((float) $balance->pending_days, 2) }} awaiting approval
                                    @endif
                                </span>
                            </div>
                            <span class="text-lg font-semibold tabular-nums text-slate-900 dark:text-white">
                                {{ app_number((float) $balance->available_days, 2) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>
@endsection
