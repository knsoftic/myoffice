@extends('layouts.admin')

@section('title', $batch->code.' — roster')

@section('header')
    <x-ui.page-header :title="$batch->code.' — roster'"
                      subtitle="Who is in this batch. Pick a date to see who was in it then — somebody who joined in week six was never absent for week two."
                      icon="users"
                      :back="route('admin.batches.show', $batch)">
        <x-slot:actions>
            @can('print', $batch)
                <x-ui.button variant="ghost" icon="printer" :href="route('admin.batches.print-roster', $batch)">Print</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-3">
            <x-ui.form.input name="on" label="As it stood on" type="date" :value="$on?->toDateString()"
                             help="Leave empty for the roster as it is now." />
            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Show</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.batches.roster', $batch)">Now</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <x-ui.stat-card label="On the roster" :value="app_number($roster->count())" icon="users" color="brand" />
        <x-ui.stat-card label="Seats" :value="app_number($capacity['effective_capacity'])" icon="squares-2x2" color="slate" />
        <x-ui.stat-card label="Free" :value="app_number($capacity['free'])" icon="user-plus" color="emerald" />
    </div>

    <x-ui.card>
        @include('admin.batches._roster-table')
    </x-ui.card>
@endsection
