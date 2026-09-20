@extends('layouts.admin')

@section('title', 'Add an employee')

@section('header')
    <x-ui.page-header title="Add an employee" subtitle="The employee code is issued automatically and never changes." icon="user-plus">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.employees.index')">Back to employees</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.employees.store') }}" class="space-y-4">
        @csrf
        @include('admin.hr.employees._form', ['employee' => null])

        <div class="flex justify-end gap-2">
            <x-ui.button variant="secondary" :href="route('admin.employees.index')">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="check">Add employee</x-ui.button>
        </div>
    </form>
@endsection
