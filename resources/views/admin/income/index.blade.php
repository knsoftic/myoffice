@extends('layouts.admin')

@section('title', 'Other income')

@section('header')
    <x-ui.page-header title="Other income"
                      subtitle="Money the business received that belongs to no other register."
                      icon="arrow-trending-up">
        <x-slot:actions>
            @can('income.export')
                <x-ui.button variant="secondary" icon="arrow-down-tray"
                             :href="route('admin.income.export', ['format' => 'csv'] + request()->query())">
                    Export
                </x-ui.button>
            @endcan
            @if ($canCreate)
                <x-ui.button variant="primary" :href="route('admin.income.create')" icon="plus">Record income</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-300">
        Project payments and student fees are recorded in their own registers and reach the income report
        automatically. Record something here only if it is neither — a refund from a supplier, a rented
        desk, an equipment sale.
    </div>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <x-ui.stat-card label="Entries" :value="$incomes->total()" icon="arrow-trending-up" color="slate"
                        :delta-label="$range->label()" />
        @if ($recordedTotal !== null)
            <x-ui.stat-card label="Received, net of refunds" :value="money($recordedTotal)" icon="banknotes"
                            color="emerald" delta-label="what this range actually brought in" />
        @endif
    </div>

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.form.input type="date" name="from" label="From" :value="request('from', app_date($range->start(), 'Y-m-d'))" />
            <x-ui.form.input type="date" name="to" label="To" :value="request('to', app_date($range->end(), 'Y-m-d'))" />
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Number, title or payer" />

            <x-ui.form.select name="category" label="Category" placeholder="Any category">
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected(request('category') == $category->id)>{{ $category->name }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="context" label="Business" placeholder="Both businesses">
                @foreach ($contexts as $context)
                    <option value="{{ $context->value }}" @selected(request('context') === $context->value)>{{ $context->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="status" label="Status" placeholder="Any status">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.income.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    @unless ($fields->seesMoney)
        <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-300">
            You can see which entries exist and where they stand. The amounts need
            <code>income.view_financial</code>, which is a separate permission.
        </div>
    @endunless

    <x-ui.card :title="$incomes->total() . ' ' . \Illuminate\Support\Str::plural('entry', $incomes->total())"
               :subtitle="$range->label()">
        <x-ui.table :is-empty="$incomes->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Entry</th>
                <th class="px-4 py-3 text-left font-semibold">Category</th>
                <th class="px-4 py-3 text-left font-semibold">From</th>
                <th class="px-4 py-3 text-left font-semibold">Received</th>
                @if ($fields->seesMoney)
                    <th class="px-4 py-3 text-right font-semibold">Amount</th>
                    <th class="px-4 py-3 text-right font-semibold">Refunded</th>
                    <th class="px-4 py-3 text-right font-semibold">Net</th>
                @endif
                <th class="px-4 py-3 text-left font-semibold">Status</th>
                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($incomes as $income)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.income.show', $income) }}"
                           class="block font-mono text-xs font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                            {{ $income->income_no }}
                        </a>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">
                            {{ \Illuminate\Support\Str::limit($income->title, 44) }}
                        </span>
                    </td>

                    <td class="px-4 py-3">
                        <span class="block text-slate-900 dark:text-white">{{ $income->category?->name ?: '—' }}</span>
                        <x-ui.badge :color="$income->context->color()" size="xs">{{ $income->context->label() }}</x-ui.badge>
                    </td>

                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                        {{ $income->received_from ?: ($income->client?->company_name ?: $income->client?->name) ?: '—' }}
                        @if ($income->project)
                            <span class="block font-mono text-xs text-slate-500 dark:text-slate-400">{{ $income->project->code }}</span>
                        @endif
                    </td>

                    <td class="px-4 py-3 text-xs text-slate-600 dark:text-slate-300">
                        <span class="block">{{ app_date($income->received_on) }}</span>
                        <span class="block text-slate-500 dark:text-slate-400">{{ $income->payment_method->label() }}</span>
                    </td>

                    @if ($fields->seesMoney)
                        <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">
                            {{ money($income->amount) }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-slate-500 dark:text-slate-400">
                            {{ bccomp((string) $income->refunded_amount, '0.00', 2) === 1 ? money($income->refunded_amount) : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">
                            {{ money($income->net_amount) }}
                        </td>
                    @endif

                    <td class="px-4 py-3">
                        <x-ui.badge :color="$income->status->color()" size="xs">{{ $income->status->label() }}</x-ui.badge>
                    </td>

                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-1">
                            @if ($income->receipt_path)
                                @can('income.download')
                                    <x-ui.icon-button icon="paper-clip" label="Download the receipt"
                                                      :href="route('admin.income.receipt', $income)" />
                                @endcan
                            @endif
                            <x-ui.icon-button icon="eye" label="Open {{ $income->income_no }}"
                                              :href="route('admin.income.show', $income)" />
                        </div>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="arrow-trending-up"
                                  title="No income in this range"
                                  message="Money from projects and courses is recorded in its own register and is not repeated here." />
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$incomes" label="entries" />
            </x-slot:footer>
        </x-ui.table>
    </x-ui.card>
@endsection
