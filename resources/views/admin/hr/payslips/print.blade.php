@extends('layouts.admin')

@section('title', $item->slip_number . ' — salary slip')

@section('header')
    <x-ui.page-header :title="'Salary slip ' . $item->slip_number" :subtitle="$employee?->name" icon="printer">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.payslips.show', $item)">Back</x-ui.button>
            <x-ui.button onclick="window.print()" icon="printer">Print this page</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card>
        @include('admin.hr.payslips._slip')
    </x-ui.card>
@endsection
