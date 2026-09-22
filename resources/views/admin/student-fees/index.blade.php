@extends('layouts.admin')

@section('title', 'Student fees')

@section('header')
    <x-ui.page-header title="Student fees"
                      subtitle="What the institute has charged, what has come in, and what is still owed."
                      icon="banknotes">
        <x-slot:actions>
            @can('student_fees.view_reports')
                <x-ui.button variant="ghost" icon="calculator" :href="route('admin.fee-collection.index')">Collection desk</x-ui.button>
            @endcan
            @can('student_fees.export')
                <x-ui.button variant="ghost" icon="arrow-down-tray" :href="route('admin.student-fees.export', ['format' => 'csv', ...request()->query()])">Export</x-ui.button>
            @endcan
            @can('student_fees.create')
                <x-ui.button icon="plus" :href="route('admin.student-fees.create')">New charge</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    {{-- The four cards describe the FILTERED set, and say so — a total that silently describes
         everything while the table below shows one batch is how a number gets quoted wrongly. --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Net billed" :value="money($totals['net'])" icon="document-text" color="slate" />
        <x-ui.stat-card label="Collected" :value="money($totals['paid'])" icon="banknotes" color="emerald" />
        <x-ui.stat-card label="Outstanding" :value="money($totals['balance'])" icon="clock"
                        :color="(float) $totals['balance'] > 0 ? 'amber' : 'slate'" />
        <x-ui.stat-card label="Reduced" :value="money(\App\Support\Money::add($totals['discount'], $totals['scholarship']))"
                        icon="receipt-percent" color="violet" />
    </div>

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Fee number, student, registration no" />

            <x-ui.form.select name="status" label="Status" placeholder="Any status">
                {{-- The four requirement statuses first; the three system ones after a rule, because
                     a report that filters on four would otherwise under-count without saying so (R-7). --}}
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="fee_type" label="Head" placeholder="Any head">
                @foreach ($feeTypes as $type)
                    <option value="{{ $type->value }}" @selected(request('fee_type') === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="course_id" label="Course" placeholder="Any course">
                @foreach ($pickers['courses'] as $id => $name)
                    <option value="{{ $id }}" @selected((int) request('course_id') === (int) $id)>{{ $name }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="batch_id" label="Batch" placeholder="Any batch">
                @foreach ($pickers['batches'] as $id => $code)
                    <option value="{{ $id }}" @selected((int) request('batch_id') === (int) $id)>{{ $code }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="overdue_bucket" label="How late" placeholder="Any">
                <option value="1-7" @selected(request('overdue_bucket') === '1-7')>1–7 days</option>
                <option value="8-30" @selected(request('overdue_bucket') === '8-30')>8–30 days</option>
                <option value="30+" @selected(request('overdue_bucket') === '30+')>Over 30 days</option>
            </x-ui.form.select>

            <div class="flex items-end gap-3">
                <x-ui.form.checkbox name="has_plan" label="Has a plan" :checked="request()->filled('has_plan')" value="1" />
                <x-ui.form.checkbox name="has_discount" label="Discounted" :checked="request()->filled('has_discount')" value="1" />
            </div>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.student-fees.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$charges->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Fee #</th>
                <th class="px-4 py-3 text-left font-semibold">Student</th>
                <th class="px-4 py-3 text-left font-semibold">Head</th>
                <th class="px-4 py-3 text-right font-semibold">Net</th>
                <th class="px-4 py-3 text-right font-semibold">Paid</th>
                <th class="px-4 py-3 text-right font-semibold">Balance</th>
                <th class="px-4 py-3 text-left font-semibold">Due</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($charges as $charge)
                @php($late = $charge->due_date !== null && $charge->status->isOpen() && $charge->due_date->isPast())
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.student-fees.show', $charge) }}"
                           class="font-medium text-slate-700 hover:underline dark:text-slate-200">{{ $charge->fee_number }}</a>
                        @if ($charge->has_installment_plan)
                            <div class="mt-0.5">
                                <x-ui.badge color="sky" size="xs">{{ app_number($charge->installment_count) }} installments</x-ui.badge>
                            </div>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $charge->student?->name ?? '—' }}</div>
                        <div class="text-xs text-slate-400">{{ $charge->student?->student_code }}</div>
                    </td>
                    <td class="px-4 py-3">
                        <x-ui.badge :color="$charge->fee_type->color()" size="xs">{{ $charge->fee_type->label() }}</x-ui.badge>
                        <div class="mt-0.5 text-xs text-slate-400">{{ $charge->course?->name }} {{ $charge->batch?->code ? '· '.$charge->batch->code : '' }}</div>
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-700 dark:text-slate-200">{{ money($charge->net_amount) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money($charge->paid_amount) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums font-medium
                        {{ (float) $charge->balance_amount > 0 ? 'text-rose-600 dark:text-rose-400' : ((float) $charge->balance_amount < 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-500') }}">
                        {{ money($charge->balance_amount) }}
                        {{-- A negative balance is an advance, not a debt, and the word is what stops a
                             minus sign being read as one. --}}
                        @if ((float) $charge->balance_amount < 0)
                            <div class="text-[10px] font-normal uppercase tracking-wide">in advance</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm {{ $late ? 'text-rose-600 dark:text-rose-400' : 'text-slate-600 dark:text-slate-300' }}">
                        {{ $charge->due_date ? app_date($charge->due_date) : '—' }}
                        @if ($late)
                            <div class="text-xs">{{ app_number($charge->due_date->diffInDays(now())) }} days late</div>
                        @endif
                    </td>
                    <td class="px-4 py-3"><x-ui.badge :color="$charge->status->color()" size="xs">{{ $charge->status->label() }}</x-ui.badge></td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex justify-end gap-1">
                            @can('student_fees.print')
                                <x-ui.icon-button icon="printer" label="Fee slip" :href="route('admin.student-fees.slip', $charge)" />
                            @endcan
                            <x-ui.icon-button icon="eye" label="Open" :href="route('admin.student-fees.show', $charge)" />
                        </div>
                    </td>
                </tr>
            @endforeach

            <x-slot:footer>
                <tr class="bg-slate-50 font-medium dark:bg-slate-900/60">
                    <td class="px-4 py-3 text-xs uppercase tracking-wide text-slate-500" colspan="3">
                        {{ app_number($totals['count']) }} charges {{ request()->hasAny(['q', 'status', 'fee_type', 'course_id', 'batch_id', 'overdue_bucket', 'has_plan', 'has_discount']) ? 'matching this filter' : 'in total' }}
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ money($totals['net']) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ money($totals['paid']) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ money($totals['balance']) }}</td>
                    <td class="px-4 py-3" colspan="3"></td>
                </tr>
            </x-slot:footer>

            <x-slot:empty>
                <x-ui.empty-state icon="banknotes" title="No fee charges match this filter"
                                  description="A charge is normally raised by the fee-structure wizard when an admission is confirmed.">
                    @can('student_fees.create')
                        <x-ui.button icon="plus" :href="route('admin.student-fees.create')">New charge</x-ui.button>
                    @endcan
                </x-ui.empty-state>
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$charges" label="charges" />
    </x-ui.card>
@endsection
