@extends('layouts.admin')

@section('title', 'Edit '.$student->name)

@section('header')
    <x-ui.page-header :title="'Edit ' . $student->name"
                      :subtitle="$student->student_code"
                      icon="users"
                      :back="route('admin.students.show', $student)" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.students.update', $student) }}" class="space-y-4">
        @csrf
        @method('PUT')
        @include('admin.students._form')

        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="primary" icon="check">Save changes</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.students.show', $student)">Cancel</x-ui.button>
        </div>
    </form>
@endsection
