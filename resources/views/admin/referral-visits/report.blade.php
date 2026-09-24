@extends('layouts.admin')

@section('title', 'Referral conversion report')

@section('header')
    <x-ui.page-header title="Conversion report" :subtitle="$range->label()" icon="chart-bar">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.referral-visits.index', request()->query())" icon="cursor-arrow-rays">The register</x-ui.button>
            <x-ui.button variant="secondary" :href="route('admin.referral-visits.export', ['format' => 'csv'] + request()->query())" icon="arrow-down-tray">Export</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <div class="w-44">
                <x-ui.form.input type="date" name="from" label="From" :value="request('from', app_date($range->start(), 'Y-m-d'))" />
            </div>
            <div class="w-44">
                <x-ui.form.input type="date" name="to" label="To" :value="request('to', app_date($range->end(), 'Y-m-d'))" />
            </div>
            <div class="w-52">
                <x-ui.form.select name="outcome" label="Outcome" :options="$outcomes" :selected="request('outcome')" placeholder="Every outcome" />
            </div>
            <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.referral-visits.report')">Clear</x-ui.button>
        </form>
    </x-ui.card>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="By partner and code">
                <x-ui.table :is-empty="$rows->isEmpty()" caption="Referral clicks by partner and code">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Code</th>
                        <th class="px-4 py-3 text-right font-semibold">Clicks</th>
                        <th class="px-4 py-3 text-right font-semibold">Visitors</th>
                        <th class="px-4 py-3 text-right font-semibold">Attributable</th>
                        <th class="px-4 py-3 text-right font-semibold">Converted</th>
                        <th class="px-4 py-3 text-right font-semibold">Rate</th>
                    </x-slot:head>

                    @foreach ($rows as $row)
                        @php
                            $partner = $row->collaborator_id ? $collaborators->get($row->collaborator_id) : null;
                            $rate = (int) $row->visits > 0 ? ((int) $row->conversions / (int) $row->visits) * 100 : 0;
                        @endphp
                        <tr>
                            <td class="px-4 py-3">
                                <span class="block font-mono text-xs text-slate-900 dark:text-white">{{ $row->referral_code }}</span>
                                @if ($partner)
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $partner->company_name ?: $partner->name }}</span>
                                @else
                                    <x-ui.badge color="rose" size="xs">unknown code</x-ui.badge>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ app_number((float) $row->clicks, 0) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ app_number((float) $row->visits, 0) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ app_number((float) $row->attributable, 0) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums font-semibold text-slate-900 dark:text-white">{{ app_number((float) $row->conversions, 0) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ app_number($rate, 1) }}%</td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="chart-bar" title="Nothing in this range"
                            description="Pick a wider date range, or wait for the first click on a partner's link." />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="Codes nobody owns"
                       subtitle="Clicks on a code that resolves to no partner — an old flyer, a typo on a banner, or a partner who was removed.">
                <x-ui.table :is-empty="$deadCodes->isEmpty()" caption="Clicks on codes that resolve to no partner">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Code</th>
                        <th class="px-4 py-3 text-right font-semibold">Clicks</th>
                        <th class="px-4 py-3 text-left font-semibold">Last used</th>
                    </x-slot:head>

                    @foreach ($deadCodes as $dead)
                        <tr>
                            <td class="px-4 py-3 font-mono text-xs text-slate-900 dark:text-white">{{ $dead->referral_code }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ app_number((float) $dead->clicks, 0) }}</td>
                            <td class="px-4 py-3 tabular-nums text-slate-600 dark:text-slate-300">{{ app_datetime($dead->last_seen) }}</td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="check-circle" title="Every code in use resolves"
                            description="Nobody is clicking a link that leads nowhere." />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>
        </div>

        <x-ui.card title="Why a visit did not attribute">
            <ul class="space-y-2">
                @forelse ($byOutcome as $value => $count)
                    @php $outcome = \App\Enums\ReferralVisitOutcome::tryFrom((string) $value); @endphp
                    <li class="flex items-center justify-between gap-3">
                        <x-ui.badge :color="$outcome?->color() ?? 'slate'" size="xs">{{ $outcome?->label() ?? $value }}</x-ui.badge>
                        <span class="tabular-nums text-sm font-semibold text-slate-900 dark:text-white">{{ app_number((float) $count, 0) }}</span>
                    </li>
                @empty
                    <li class="text-sm text-slate-500 dark:text-slate-400">Nothing in this range.</li>
                @endforelse
            </ul>

            <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
                Every refusal is a kept row rather than a missing one, so "people are still using a dead code"
                is something this report can say.
            </p>
        </x-ui.card>
    </div>
@endsection
