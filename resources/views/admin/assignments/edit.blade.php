@extends('layouts.admin')

@section('title', 'Edit assignment')

@section('header')
    <x-ui.page-header :title="$assignment->title" subtitle="Editing the assignment" icon="clipboard-document">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.assignments.show', $assignment)">Back</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.assignments.update', $assignment) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        @include('admin.assignments._form')

        <div class="mt-6 flex justify-end gap-2">
            <x-ui.button variant="ghost" :href="route('admin.assignments.show', $assignment)">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="check">Save changes</x-ui.button>
        </div>
    </form>
@endsection
