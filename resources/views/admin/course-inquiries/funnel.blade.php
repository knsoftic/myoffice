@extends('layouts.admin')

@section('title', 'Inquiry funnel')

@section('header')
    <x-ui.page-header title="Inquiry funnel"
                      subtitle="Where inquiries stop, and which sources bring the ones that do not."
                      icon="chart-bar"
                      :back="route('admin.course-inquiries.index')" />
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-3">
            <x-ui.form.input name="from" label="From" type="date" :value="$from->toDateString()" />
            <x-ui.form.input name="to" label="To" type="date" :value="$to->toDateString()" />
            <div class="flex items-end">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Apply</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <x-ui.stat-card label="Inquiries in range" :value="app_number($total)" icon="question-mark-circle" color="sky" />
        <x-ui.stat-card label="Became admissions" :value="app_number($won)" icon="check-badge" color="emerald" />
        <x-ui.stat-card label="Conversion rate" :value="$conversionRate . '%'" icon="arrow-trending-up"
                        :color="$conversionRate >= 20 ? 'emerald' : 'amber'" />
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-ui.card title="By status" subtitle="Where they are now, not where they passed through.">
            <x-ui.table :is-empty="$byStatus->isEmpty()">
                <x-slot:head>
                    <th class="px-4 py-3 text-left font-semibold">Status</th>
                    <th class="px-4 py-3 text-right font-semibold">Count</th>
                    <th class="px-4 py-3 text-right font-semibold">Share</th>
                </x-slot:head>

                @foreach (\App\Enums\CourseInquiryStatus::cases() as $case)
                    @php($count = (int) ($byStatus[$case->value] ?? 0))
                    <tr>
                        <td class="px-4 py-3"><x-ui.badge :color="$case->color()">{{ $case->label() }}</x-ui.badge></td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ app_number($count) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-slate-500">
                            {{ $total > 0 ? round($count / $total * 100, 1) : 0 }}%
                        </td>
                    </tr>
                @endforeach

                <x-slot:empty>
                    <x-ui.empty-state icon="chart-bar" title="No inquiries in this range" />
                </x-slot:empty>
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="By source" subtitle="Which channels are worth the spend.">
            <x-ui.table :is-empty="$bySource->isEmpty()">
                <x-slot:head>
                    <th class="px-4 py-3 text-left font-semibold">Source</th>
                    <th class="px-4 py-3 text-right font-semibold">Count</th>
                    <th class="px-4 py-3 text-right font-semibold">Share</th>
                </x-slot:head>

                @foreach ($bySource as $source => $count)
                    <tr>
                        <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">
                            {{ \App\Enums\InquirySource::tryFrom((string) $source)?->label() ?? $source }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ app_number($count) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-slate-500">
                            {{ $total > 0 ? round($count / $total * 100, 1) : 0 }}%
                        </td>
                    </tr>
                @endforeach

                <x-slot:empty>
                    <x-ui.empty-state icon="chart-bar" title="No inquiries in this range" />
                </x-slot:empty>
            </x-ui.table>
        </x-ui.card>
    </div>
@endsection
