@extends('layouts.admin')

@section('title', 'Edit '.$teacher->name)

@section('header')
    <x-ui.page-header :title="'Edit '.$teacher->name"
                      :subtitle="$teacher->teacher_code.' · the code and the status are changed elsewhere, each through the one thing that owns it.'"
                      icon="presentation-chart-bar"
                      :back="route('admin.teachers.show', $teacher)" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.teachers.update', $teacher) }}" class="space-y-4">
        @csrf
        @method('PUT')
        @include('admin.teachers._form')

        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="primary" icon="check">Save changes</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.teachers.show', $teacher)">Cancel</x-ui.button>
        </div>
    </form>
@endsection
