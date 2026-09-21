@extends('layouts.admin')

@section('title', 'Expenses')

@section('header')
    <x-ui.page-header title="Expenses"
                      subtitle="What the business spent. Only an approved expense counts in a report — a pending claim is somebody's opinion until it is agreed."
                      icon="banknotes">
        <x-slot:actions>
            @if ($canApprove)
                <x-ui.button variant="secondary" :href="route('admin.expenses.approvals')" icon="inbox-stack">
                    Approvals @if ($pendingCount > 0) ({{ $pendingCount }}) @endif
                </x-ui.button>
            @endif
            @can('expenses.export')
                <x-ui.button variant="secondary" icon="arrow-down-tray"
                             :href="route('admin.expenses.export', ['format' => 'csv'] + request()->query())">
                    Export
                </x-ui.button>
            @endcan
            @if ($canCreate)
                <x-ui.button variant="primary" :href="route('admin.expenses.create')" icon="plus">Record expense</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <x-ui.stat-card label="Expenses" :value="$expenses->total()" icon="banknotes" color="slate"
                        :delta-label="$range->label()" />
        <x-ui.stat-card label="Waiting for approval" :value="$pendingCount" icon="clock"
                        :color="$pendingCount > 0 ? 'amber' : 'slate'"
                        delta-label="not in any report yet"
                        :href="$canApprove ? route('admin.expenses.approvals') : null" />
        @if ($approvedTotal !== null)
            <x-ui.stat-card label="Approved, net of refunds" :value="money($approvedTotal)" icon="check-circle"
                            color="rose" delta-label="what this range actually cost" />
        @endif
    </div>

    @include('admin.expenses._filters')

    @unless ($fields->seesMoney)
        <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-300">
            You can see which expenses exist and where they stand. The amounts need
            <code>expenses.view_financial</code>, which is a separate permission.
        </div>
    @endunless

    <x-ui.card :title="$expenses->total() . ' ' . \Illuminate\Support\Str::plural('expense', $expenses->total())"
               :subtitle="$range->label()">
        @include('admin.expenses._list', ['showApprovals' => false])
    </x-ui.card>
@endsection
