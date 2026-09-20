@extends('layouts.admin')

@section('title', 'Referral visits')

@php
    $user = auth()->user();
    $canReport = (bool) $user?->can('collaborator_referral_visits.view_reports');
    $controller = app(\App\Http\Controllers\Admin\Collaborator\ReferralVisitController::class);
@endphp

@section('header')
    <x-ui.page-header title="Referral visits" subtitle="Every click on a partner's link. Nobody edits one." icon="cursor-arrow-rays">
        <x-slot:actions>
            @if ($canReport)
                <x-ui.button variant="secondary" :href="route('admin.referral-visits.report', request()->query())" icon="chart-bar">Conversion report</x-ui.button>
                <x-ui.button variant="secondary" :href="route('admin.referral-visits.export', ['format' => 'csv'] + request()->query())" icon="arrow-down-tray">Export</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.form.input type="date" name="from" label="From" :value="request('from', app_date($range->start(), 'Y-m-d'))" />
            <x-ui.form.input type="date" name="to" label="To" :value="request('to', app_date($range->end(), 'Y-m-d'))" />
            <x-ui.form.select name="collaborator_id" label="Collaborator" placeholder="Everybody">
                @foreach ($collaborators as $collaborator)
                    <option value="{{ $collaborator->id }}" @selected(request('collaborator_id') == $collaborator->id)>
                        {{ $collaborator->company_name ?: $collaborator->name }} ({{ $collaborator->collaborator_code }})
                    </option>
                @endforeach
            </x-ui.form.select>
            <x-ui.form.select name="outcome" label="Outcome" :options="$outcomes" :selected="request('outcome')" placeholder="Every outcome" />
            <x-ui.form.input name="code" label="Code" :value="request('code')" placeholder="COL-1001" />
            <x-ui.form.input name="landing_path" label="Landing page" :value="request('landing_path')" placeholder="/admission" />
            <x-ui.form.select name="converted" label="Converted" :options="['yes' => 'Yes', 'no' => 'No']" :selected="request('converted')" placeholder="Either" />
            <x-ui.form.select name="bot" label="Crawler" :options="['yes' => 'Yes', 'no' => 'No']" :selected="request('bot')" placeholder="Either" />
            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.referral-visits.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :title="$visits->total() . ' ' . \Illuminate\Support\Str::plural('visit', $visits->total())"
               :subtitle="$range->label()">
        <x-ui.table :is-empty="$visits->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Seen</th>
                <th class="px-4 py-3 text-left font-semibold">Code</th>
                <th class="px-4 py-3 text-left font-semibold">Landing page</th>
                <th class="px-4 py-3 text-right font-semibold">Clicks</th>
                <th class="px-4 py-3 text-left font-semibold">Visitor</th>
                <th class="px-4 py-3 text-left font-semibold">Outcome</th>
            </x-slot:head>

            @foreach ($visits as $visit)
                <tr>
                    <td class="px-4 py-3 tabular-nums text-slate-600 dark:text-slate-300">
                        <a href="{{ route('admin.referral-visits.show', $visit) }}"
                           class="block font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                            {{ app_datetime($visit->last_seen_at) }}
                        </a>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">
                            first {{ app_datetime($visit->first_seen_at) }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <span class="block font-mono text-xs text-slate-900 dark:text-white">{{ $visit->referral_code }}</span>
                        @if ($visit->collaborator)
                            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $visit->collaborator->displayName() }}</span>
                        @else
                            <x-ui.badge color="rose" size="xs">unknown code</x-ui.badge>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $visit->landing_path }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $visit->visits_count }}</td>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                        <span class="block text-xs">{{ $visit->device }} · {{ $visit->platform }} · {{ $visit->browser }}</span>
                        <span class="block font-mono text-xs text-slate-500 dark:text-slate-400">
                            {{ $controller->ip($visit, $seesRawIp) ?? '—' }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <x-ui.badge :color="$visit->outcome->color()" size="xs" :title="$visit->outcome_detail">
                            {{ $visit->outcome->label() }}
                        </x-ui.badge>
                        @if ($visit->converted_at)
                            <span class="block text-xs text-slate-500 dark:text-slate-400">
                                {{ $visit->converted_subject_type?->label() }} · {{ app_date($visit->converted_at) }}
                            </span>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="cursor-arrow-rays" title="No referral visits in this range"
                    description="A visit appears the moment somebody opens a link carrying a partner's code." />
            </x-slot:empty>
        </x-ui.table>

        @if ($visits->hasPages())
            <div class="mt-4">{{ $visits->links() }}</div>
        @endif
    </x-ui.card>

    @unless ($seesRawIp)
        <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
            IP addresses are shown as a /24 range. The full address needs
            <code>collaborator_referral_visits.view_logs</code>.
        </p>
    @endunless
@endsection
