@extends('layouts.admin')

@section('title', 'Salary advances')

@php
    $user = auth()->user();
    $canCreate = (bool) $user?->can('employee_advances.create');
@endphp

@section('header')
    <x-ui.page-header title="Salary advances" subtitle="What was lent, what has come back, and what is left." icon="credit-card">
        <x-slot:actions>
            @if ($canCreate)
                <x-ui.button :href="route('admin.advances.create')" icon="plus">New advance</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <div class="w-56">
                <x-ui.form.select name="status" label="Status" :options="$statuses" :selected="request('status')" placeholder="Every status" />
            </div>
            <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.advances.index')">Clear</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card :title="$advances->total() . ' advance(s)'">
        <x-ui.table :is-empty="$advances->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Advance</th>
                <th class="px-4 py-3 text-left font-semibold">Employee</th>
                <th class="px-4 py-3 text-right font-semibold">Amount</th>
                <th class="px-4 py-3 text-right font-semibold">Recovered</th>
                <th class="px-4 py-3 text-right font-semibold">Outstanding</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
            </x-slot:head>

            @foreach ($advances as $advance)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.advances.show', $advance) }}"
                           class="block font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $advance->advance_number }}</a>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">
                            {{ app_date($advance->requested_on) }} · {{ $advance->installment_count }} installment(s)
                        </span>
                    </td>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                        <span class="block">{{ $advance->employee?->name }}</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $advance->employee?->employee_code }}</span>
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money((string) $advance->amount) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                        {{ money((string) $advance->recovered_amount) }}
                        @if ((float) $advance->waived_amount > 0)
                            <span class="block text-xs text-amber-600 dark:text-amber-400">{{ money((string) $advance->waived_amount) }} waived</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums font-semibold text-slate-900 dark:text-white">{{ money((string) $advance->outstanding_amount) }}</td>
                    <td class="px-4 py-3"><x-ui.badge :color="$advance->status->color()" size="xs">{{ $advance->status->label() }}</x-ui.badge></td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="credit-card" title="No advances"
                    description="Nobody has asked, or the filters exclude everything." />
            </x-slot:empty>
        </x-ui.table>

        @if ($advances->hasPages())
            <div class="mt-4">{{ $advances->links() }}</div>
        @endif
    </x-ui.card>
@endsection
