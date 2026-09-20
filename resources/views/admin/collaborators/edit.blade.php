@extends('layouts.admin')

@section('title', 'Edit ' . $collaborator->displayName())

@section('header')
    <x-ui.page-header :title="'Edit ' . $collaborator->displayName()" :subtitle="$collaborator->collaborator_code" icon="pencil">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.collaborators.show', $collaborator)">Cancel</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.collaborators.update', $collaborator) }}" class="space-y-4">
        @csrf
        @method('PUT')
        @include('admin.collaborators._form')

        <div class="flex justify-end gap-2">
            <x-ui.button variant="secondary" :href="route('admin.collaborators.show', $collaborator)">Cancel</x-ui.button>
            <x-ui.button type="submit">Save profile</x-ui.button>
        </div>
    </form>
@endsection
