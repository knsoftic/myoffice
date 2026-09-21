@extends('layouts.admin')

@section('title', $collaborator->displayName() . ' — statement')

@php
    $query = request()->query();

    $groupLabels = [
        'student_commissions' => 'Student commissions',
        'project_commissions' => 'Project commissions',
        'adjustments' => 'Adjustments and write-offs',
        'reversals' => 'Reversals and clawbacks',
        'payouts' => 'Payouts',
    ];
@endphp

@section('header')
    <x-ui.page-header :title="$collaborator->displayName()"
                      :subtitle="'Statement · ' . $range->label()"
                      icon="document-text"
                      :back="route('admin.wallets.show', $collaborator)">
        <x-slot:actions>
            @can('collaborator_commissions.export')
                <x-ui.button variant="secondary" target="_blank"
                             :href="route('admin.statements.export', ['collaborator' => $collaborator, 'format' => 'print'] + $query)"
                             icon="printer">Print</x-ui.button>
                <x-ui.button variant="secondary"
                             :href="route('admin.statements.export', ['collaborator' => $collaborator, 'format' => 'csv'] + $query)"
                             icon="arrow-down-tray">CSV</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <x-ui.stat-card label="Opening" :value="money($statement->opening)" icon="flag" color="slate" />
        <x-ui.stat-card label="Credits" :value="money($statement->credits)" icon="plus-circle" color="emerald" />
        <x-ui.stat-card label="Debits" :value="money($statement->debits)" icon="minus-circle" color="amber" />
        <x-ui.stat-card label="Payouts" :value="money($statement->payouts)" icon="banknotes" color="sky" />
        <x-ui.stat-card label="Closing" :value="money($statement->closing)" icon="check-circle" color="brand" />
    </div>

    {{-- §8.7's proof footer, at the top as well: it is the reason to trust the numbers above it, and a
         reader should not have to scroll past 200 rows to find out whether they add up. --}}
    <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-200">
        <span class="font-semibold">It balances:</span>
        <span class="tabular-nums">{{ $statement->proof() }}</span>
    </div>

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.form.input type="date" name="from" label="From" :value="request('from', app_date($range->start(), 'Y-m-d'))" />
            <x-ui.form.input type="date" name="to" label="To" :value="request('to', app_date($range->end(), 'Y-m-d'))" />

            <x-ui.form.select name="purpose" label="Type" placeholder="Every movement">
                @foreach ($purposes as $purpose)
                    <option value="{{ $purpose->value }}" @selected(request('purpose') === $purpose->value)>{{ $purpose->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="status" label="Status" placeholder="Any status">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.input name="student" label="Student id" :value="request('student')" />
            <x-ui.form.input name="project" label="Project id" :value="request('project')" />
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Reference or description" />

            <div class="flex flex-col justify-end gap-1">
                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" name="technical" value="1" @checked($filters->showTechnicalRows)
                           class="rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
                    Show technical rows
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" name="payouts" value="1" @checked($filters->showPayouts)
                           class="rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
                    Show payouts
                </label>
            </div>

            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.statements.show', $collaborator)">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    @if ($statement->isFiltered)
        <div class="mb-4 rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900 dark:border-sky-900/50 dark:bg-sky-950/40 dark:text-sky-200">
            A filter is narrowing the rows below. The opening and closing balances are still this partner's real
            balances, and each visible row carries its true running balance — so the column will not add up to the
            footer, and is not supposed to.
        </div>
    @endif

    <x-ui.card title="Movements" :subtitle="$range->label()">
        <x-ui.table :is-empty="$statement->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Date</th>
                <th class="px-4 py-3 text-left font-semibold">Reference</th>
                <th class="px-4 py-3 text-left font-semibold">Description</th>
                <th class="px-4 py-3 text-right font-semibold">Rate / base</th>
                <th class="px-4 py-3 text-right font-semibold">Credit</th>
                <th class="px-4 py-3 text-right font-semibold">Debit</th>
                <th class="px-4 py-3 text-right font-semibold">Balance</th>
            </x-slot:head>

            <tr class="bg-slate-50 dark:bg-slate-900/60">
                <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ app_date($range->start()) }}</td>
                <td class="px-4 py-3" colspan="5"><span class="font-medium text-slate-700 dark:text-slate-200">Opening balance</span></td>
                <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($statement->opening) }}</td>
            </tr>

            @foreach ($statement->lines as $line)
                <tr>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ app_date($line->date) }}</td>
                    <td class="px-4 py-3">
                        <span class="font-mono text-xs text-slate-900 dark:text-white">{{ $line->reference }}</span>
                        @if ($line->status)
                            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $line->status }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-slate-700 dark:text-slate-200">
                        {{ $line->description }}
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $line->purpose?->label() ?? 'Payout' }}</span>
                    </td>
                    <td class="px-4 py-3 text-right text-xs tabular-nums text-slate-500 dark:text-slate-400">
                        @if ($line->rate !== null)
                            {{ rtrim(rtrim($line->rate, '0'), '.') }}%
                        @endif
                        @if ($line->base !== null && bccomp($line->base, '0.00', 2) === 1)
                            <span class="block">on {{ money($line->base) }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums text-emerald-700 dark:text-emerald-400">
                        {{ bccomp($line->credit, '0.00', 2) === 0 ? '' : money($line->credit) }}
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums text-rose-600 dark:text-rose-400">
                        {{ bccomp($line->debit, '0.00', 2) === 0 ? '' : money($line->debit) }}
                    </td>
                    <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">
                        {{ money($line->balance) }}
                    </td>
                </tr>
            @endforeach

            <tr class="bg-slate-50 dark:bg-slate-900/60">
                <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ app_date($range->end()) }}</td>
                <td class="px-4 py-3" colspan="5"><span class="font-medium text-slate-700 dark:text-slate-200">Closing balance</span></td>
                <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($statement->closing) }}</td>
            </tr>

            <x-slot:empty>
                <x-ui.empty-state icon="document-text" title="Nothing moved" :message="$statement->emptyMessage()" />
            </x-slot:empty>
        </x-ui.table>

        <x-slot:footer>
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                @foreach (\App\DataObjects\Collaborator\StatementData::GROUPS as $group)
                    <div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-900/60">
                        <p class="text-xs uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ $groupLabels[$group] }}</p>
                        <p class="mt-0.5 font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($statement->subtotal($group)) }}</p>
                    </div>
                @endforeach
            </div>
        </x-slot:footer>
    </x-ui.card>
@endsection
