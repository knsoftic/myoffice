@extends('layouts.admin')

@section('title', 'Leave balances')

@section('header')
    <x-ui.page-header title="Leave balances" :subtitle="'Leave year ' . $year" icon="scale" />
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <p class="text-sm text-slate-600 dark:text-slate-300">
            Every figure here is a <strong class="font-semibold text-slate-900 dark:text-white">cache over a
            ledger</strong>. Nothing writes a balance column directly: a grant, an accrual, a reservation, a
            consumption and an adjustment are all rows with a reason and a date, and the available days are
            exactly their sum.
        </p>
    </x-ui.card>

    <div class="grid gap-4 lg:grid-cols-4">
        <div class="lg:col-span-3">
            <x-ui.card :title="$balances->total() . ' balance(s)'">
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
                        <th class="px-4 py-3 text-left font-semibold">Employee</th>
                        <th class="px-4 py-3 text-left font-semibold">Type</th>
                        <th class="px-4 py-3 text-right font-semibold">Entitled</th>
                        <th class="px-4 py-3 text-right font-semibold">Used</th>
                        <th class="px-4 py-3 text-right font-semibold">Reserved</th>
                        <th class="px-4 py-3 text-right font-semibold">Available</th>
                    </x-slot:head>

                    @foreach ($balances as $balance)
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.leave-balances.show', ['employee' => $balance->employee_id, 'year' => $year]) }}"
                                   class="block font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                                    {{ $balance->employee?->name }}
                                </a>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $balance->employee?->employee_code }}</span>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $balance->leaveType?->name }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                {{ app_number((float) $balance->entitled_days + (float) $balance->carried_forward_days + (float) $balance->accrued_days, 2) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ app_number((float) $balance->consumed_days, 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ app_number((float) $balance->pending_days, 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums font-semibold {{ (float) $balance->available_days < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-white' }}">
                                {{ app_number((float) $balance->available_days, 2) }}
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="scale" title="No balances for this year"
                            description="Grant the year's quota to create them." />
                    </x-slot:empty>
                </x-ui.table>

                @if ($balances->hasPages())
                    <div class="mt-4">{{ $balances->links() }}</div>
                @endif
            </x-ui.card>
        </div>

        @if ($canAdjust)
            <x-ui.card title="Grant the year" subtitle="Idempotent — a second run writes nothing.">
                <form method="POST" action="{{ route('admin.leave-balances.grant-year') }}" class="space-y-3">
                    @csrf
                    <x-ui.form.input type="number" name="leave_year" label="Leave year" required :value="$year" />
                    <x-ui.button type="submit" variant="secondary" class="w-full" icon="gift">Credit quotas</x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>
@endsection
