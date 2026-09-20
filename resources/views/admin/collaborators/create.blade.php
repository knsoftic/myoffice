@extends('layouts.admin')

@section('title', 'Add a collaborator')

@section('header')
    <x-ui.page-header title="Add a collaborator" subtitle="A partner who refers work, or works with you on it." icon="user-plus">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.collaborators.index')">Cancel</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.collaborators.store') }}" class="space-y-4">
        @csrf
        @include('admin.collaborators._form', ['collaborator' => null])

        <div class="flex justify-end gap-2">
            <x-ui.button variant="secondary" :href="route('admin.collaborators.index')">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="plus">Register collaborator</x-ui.button>
        </div>
    </form>
@endsection
