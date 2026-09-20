@extends('layouts.admin')

@section('title', $item->slip_number)

@section('header')
    <x-ui.page-header :title="$item->slip_number" :subtitle="$run?->periodLabel()" icon="document-text">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.my.payslips.index')">My slips</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.my.payslips.print', $item)" icon="printer">Print</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card>
        @include('admin.hr.payslips._slip')
    </x-ui.card>
@endsection
