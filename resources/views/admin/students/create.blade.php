@extends('layouts.admin')

@section('title', 'Add student')

@section('header')
    <x-ui.page-header title="Add student"
                      subtitle="A code is issued on save. The registration number comes later, at the registration step."
                      icon="users"
                      :back="route('admin.students.index')" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.students.store') }}" class="space-y-4">
        @csrf
        @include('admin.students._form')

        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="primary" icon="check">Save student</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.students.index')">Cancel</x-ui.button>
        </div>
    </form>
@endsection
