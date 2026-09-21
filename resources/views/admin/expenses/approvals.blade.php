@extends('layouts.admin')

@section('title', 'Expense approvals')

@section('header')
    <x-ui.page-header title="Expense approvals"
                      subtitle="Oldest first. Nothing here counts in a report yet — an unapproved claim is a cost the business has not agreed to."
                      icon="inbox-stack"
                      :back="route('admin.expenses.index')">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.expenses.index')" icon="list-bullet">
                Full register
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200">
        Approving is agreeing that the company owes this money. A claim you entered yourself is shown
        with the approve control switched off — somebody else has to agree it, and the route refuses it
        as well, so the disabled button is the courtesy rather than the control.
    </div>

    @include('admin.expenses._filters', ['lockStatus' => true])

    <x-ui.card :title="$expenses->total() . ' waiting'"
               :subtitle="$range->label() . ' · oldest first'">
        @include('admin.expenses._list', ['showApprovals' => true])
    </x-ui.card>
@endsection
