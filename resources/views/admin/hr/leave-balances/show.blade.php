@extends('layouts.admin')

@section('title', $employee->name . ' — leave statement')

@section('header')
    <x-ui.page-header :title="$employee->name . ' — leave statement'" :subtitle="'Leave year ' . $year" icon="scale">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.leave-balances.index', ['year' => $year])">All balances</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.employees.show', $employee)">Profile</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="Where the days stand">
                <form method="GET" class="mb-4 flex items-end gap-3">
                    <div class="w-36">
                        <x-ui.form.select name="year" label="Leave year">
                            @foreach ($years as $option)
                                <option value="{{ $option }}" @selected($option === $year)>{{ $option }}</option>
                            @endforeach
                        </x-ui.form.select>
                    </div>
                    <x-ui.button type="submit" variant="secondary">Show</x-ui.button>
                </form>

                <x-ui.table :is-empty="$balances->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Type</th>
                        <th class="px-4 py-3 text-right font-semibold">Entitled</th>
                        <th class="px-4 py-3 text-right font-semibold">Carried</th>
                        <th class="px-4 py-3 text-right font-semibold">Accrued</th>
                        <th class="px-4 py-3 text-right font-semibold">Adjusted</th>
                        <th class="px-4 py-3 text-right font-semibold">Used</th>
                        <th class="px-4 py-3 text-right font-semibold">Reserved</th>
                        <th class="px-4 py-3 text-right font-semibold">Expired</th>
                        <th class="px-4 py-3 text-right font-semibold">Available</th>
                    </x-slot:head>

                    @foreach ($balances as $balance)
                        <tr>
                            <td class="px-4 py-3 font-medium text-slate-900 dark:text-white">{{ $balance->leaveType?->name }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ app_number((float) $balance->entitled_days, 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ app_number((float) $balance->carried_forward_days, 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ app_number((float) $balance->accrued_days, 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ app_number((float) $balance->adjusted_days, 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ app_number((float) $balance->consumed_days, 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ app_number((float) $balance->pending_days, 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ app_number((float) $balance->expired_days, 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums font-semibold text-slate-900 dark:text-white">{{ app_number((float) $balance->available_days, 2) }}</td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="scale" title="No balances for this year" />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="Statement" subtitle="Every movement, newest first — like a bank statement.">
                <x-ui.table :is-empty="$ledger->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Date</th>
                        <th class="px-4 py-3 text-left font-semibold">Type</th>
                        <th class="px-4 py-3 text-left font-semibold">Why</th>
                        <th class="px-4 py-3 text-right font-semibold">Days</th>
                        <th class="px-4 py-3 text-right font-semibold">Balance after</th>
                    </x-slot:head>

                    @foreach ($ledger as $entry)
                        <tr>
                            <td class="px-4 py-3 tabular-nums text-slate-600 dark:text-slate-300">{{ app_date($entry->occurred_on) }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $entry->leaveType?->name }}</td>
                            <td class="px-4 py-3">
                                <span class="block text-sm text-slate-900 dark:text-white">{{ $entry->reason->label() }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">
                                    @if ($entry->leaveRequest) {{ $entry->leaveRequest->request_number }} @endif
                                    @if ($entry->notes) · {{ $entry->notes }} @endif
                                    @if ($entry->performer) · {{ $entry->performer->name }} @endif
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums {{ (float) $entry->signed_days < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                {{ app_number((float) $entry->signed_days, 2) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                {{ $entry->balance_after_days === null ? '—' : app_number((float) $entry->balance_after_days, 2) }}
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="list-bullet" title="Nothing has moved yet" />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>
        </div>

        @if ($canAdjust)
            <x-ui.card title="Adjust" subtitle="Posts a ledger row — never an edit of a balance.">
                <form method="POST" action="{{ route('admin.leave-balances.adjust') }}" class="space-y-3">
                    @csrf
                    <input type="hidden" name="employee_id" value="{{ $employee->id }}">
                    <x-ui.form.select name="leave_type_id" label="Leave type" required placeholder="Choose a type">
                        @foreach ($types as $type)
                            <option value="{{ $type->id }}">{{ $type->name }}</option>
                        @endforeach
                    </x-ui.form.select>
                    <x-ui.form.input type="number" step="0.0001" name="signed_days" label="Days" required
                        help="Negative takes days away, positive adds them." />
                    <x-ui.form.input type="date" name="occurred_on" label="Value date" :value="now()->toDateString()" />
                    <x-ui.form.textarea name="notes" label="Why" rows="3" required
                        help="This row is the only explanation the employee will ever see." />
                    <x-ui.button type="submit" class="w-full">Post adjustment</x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>
@endsection
