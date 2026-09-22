@extends('layouts.panel')

@section('title', 'My fees')

@section('header')
    <x-ui.page-header title="My fees"
                      subtitle="What has been charged, what you have paid, and what is still due."
                      icon="banknotes" />
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Total billed" :value="money($summary->net)" icon="document-text" color="slate" />
        <x-ui.stat-card label="Paid" :value="money($summary->paid)" icon="check-circle" color="emerald" />
        {{-- The caption does the work here. A student reading "−5,000.00" beside the word "outstanding"
             would reasonably think they owe it. --}}
        <x-ui.stat-card :label="$summary->isInAdvance() ? 'In advance' : 'Outstanding'"
                        :value="money(\App\Support\Money::abs($summary->balance))"
                        :icon="$summary->isInAdvance() ? 'arrow-trending-up' : 'clock'"
                        :color="$summary->owes() ? 'amber' : ($summary->isInAdvance() ? 'emerald' : 'slate')" />
        <x-ui.stat-card label="Next due"
                        :value="$summary->nextDueDate ? app_date($summary->nextDueDate) : '—'"
                        icon="calendar-days" color="sky"
                        :delta-label="$summary->nextDueDate ? null : 'nothing scheduled'" />
    </div>

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <x-ui.form.select name="status" label="Status" placeholder="Any status">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="fee_type" label="Head" placeholder="Any head">
                @foreach ($feeTypes as $type)
                    <option value="{{ $type->value }}" @selected(request('fee_type') === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('student.fees.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$charges->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Fee #</th>
                <th class="px-4 py-3 text-left font-semibold">What for</th>
                <th class="px-4 py-3 text-right font-semibold">Amount</th>
                <th class="px-4 py-3 text-right font-semibold">Paid</th>
                <th class="px-4 py-3 text-right font-semibold">Balance</th>
                <th class="px-4 py-3 text-left font-semibold">Due</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($charges as $charge)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('student.fees.show', $charge) }}"
                           class="font-medium text-slate-700 hover:underline dark:text-slate-200">{{ $charge->fee_number }}</a>
                    </td>
                    <td class="px-4 py-3">
                        <div class="text-sm text-slate-700 dark:text-slate-200">{{ $charge->title ?? $charge->fee_type->label() }}</div>
                        <div class="text-xs text-slate-400">{{ $charge->course?->name }} {{ $charge->batch?->code ? '· '.$charge->batch->code : '' }}</div>
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-700 dark:text-slate-200">{{ money($charge->net_amount) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money($charge->paid_amount) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums font-medium
                        {{ (float) $charge->balance_amount > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-500' }}">
                        {{ money(\App\Support\Money::abs($charge->balance_amount)) }}
                        @if ((float) $charge->balance_amount < 0)
                            <div class="text-[10px] font-normal uppercase tracking-wide text-emerald-600 dark:text-emerald-400">in advance</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $charge->due_date ? app_date($charge->due_date) : '—' }}</td>
                    <td class="px-4 py-3"><x-ui.badge :color="$charge->status->color()" size="xs">{{ $charge->status->label() }}</x-ui.badge></td>
                    <td class="px-4 py-3 text-right">
                        <x-ui.icon-button icon="printer" label="Fee slip" :href="route('student.fees.slip', $charge)" />
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="banknotes" title="No fees have been issued yet"
                                  description="Your fee charges appear here once the institute raises them." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$charges" label="charges" />
    </x-ui.card>
@endsection
