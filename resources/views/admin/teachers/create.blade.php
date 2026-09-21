@extends('layouts.admin')

@section('title', 'Add teacher')

@section('header')
    <x-ui.page-header title="Add teacher"
                      subtitle="A code is issued on save. Courses and the timetable come next."
                      icon="presentation-chart-bar"
                      :back="route('admin.teachers.index')" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.teachers.store') }}" class="space-y-4">
        @csrf
        @include('admin.teachers._form')

        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="primary" icon="check">Save teacher</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.teachers.index')">Cancel</x-ui.button>
        </div>
    </form>
@endsection
