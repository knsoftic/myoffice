@extends('layouts.admin')

@section('title', 'New project')

@section('header')
    <x-ui.page-header title="New project" subtitle="Register a piece of delivery work against a client." icon="folder">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.projects.index')">Cancel</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.projects.store') }}" class="space-y-4">
        @csrf
        <x-ui.card>
            @include('admin.projects._form', ['project' => null])
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button variant="secondary" :href="route('admin.projects.index')">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="check">Create project</x-ui.button>
        </div>
    </form>
@endsection
