@extends('layouts.admin')

@section('title', 'Edit ' . $employee->name)

@section('header')
    <x-ui.page-header :title="'Edit ' . $employee->name" :subtitle="$employee->employee_code" icon="pencil">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.employees.show', $employee)">Back to profile</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.employees.update', $employee) }}" class="space-y-4">
        @csrf
        @method('PUT')
        @include('admin.hr.employees._form', ['employee' => $employee])

        <div class="flex justify-end gap-2">
            <x-ui.button variant="secondary" :href="route('admin.employees.show', $employee)">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="check">Save employee</x-ui.button>
        </div>
    </form>
@endsection
