@extends('layouts.admin')

@section('title', 'Finance reports')

@php
    $routeFor = static fn (\App\Enums\FinanceReportType $type): string
        => route('admin.reports.finance.'.str_replace('_', '-', $type->value));
@endphp

@section('header')
    <x-ui.page-header title="Finance reports"
                      subtitle="All four are on a cash basis: they count money that moved, not money that was promised."
                      icon="chart-bar" />
@endsection

@section('content')
    <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-300">
        Only the reports you may actually open are listed. A card for a report the route would refuse is
        worse than no card — it advertises something and then says no, which reads as a bug rather than
        as a decision.
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        @forelse ($reports as $report)
            <x-ui.card :hover="true">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900 dark:text-white">{{ $report->label() }}</h3>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ $report->description() }}</p>
                    </div>
                    <x-ui.badge :color="$report->color()" size="xs">cash</x-ui.badge>
                </div>

                <x-slot:footer>
                    <div class="flex flex-wrap items-center gap-2">
                        <x-ui.button size="sm" variant="secondary" icon="arrow-right"
                                     :href="$routeFor($report).'?'.http_build_query(['preset' => $range->preset()])">
                            Open for {{ strtolower($range->label()) }}
                        </x-ui.button>
                        <x-ui.button size="sm" variant="ghost" :href="$routeFor($report).'?preset=year'">
                            This year
                        </x-ui.button>
                    </div>
                </x-slot:footer>
            </x-ui.card>
        @empty
            <div class="sm:col-span-2">
                <x-ui.card>
                    <x-ui.empty-state icon="chart-bar"
                                      title="No report is open to you"
                                      message="Each one needs both the source module's view_reports and its view_financial — a finance report without amounts is not a report." />
                </x-ui.card>
            </div>
        @endforelse
    </div>
@endsection
