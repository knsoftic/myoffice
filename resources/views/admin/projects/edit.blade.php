@extends('layouts.admin')

@section('title', 'Edit ' . $project->code)

@section('header')
    <x-ui.page-header :title="'Edit ' . $project->name" :subtitle="$project->code" icon="folder">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.projects.show', $project)">Cancel</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.projects.update', $project) }}" class="space-y-4">
        @csrf
        @method('PUT')
        <x-ui.card>
            @include('admin.projects._form')
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button variant="secondary" :href="route('admin.projects.show', $project)">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="check">Save changes</x-ui.button>
        </div>
    </form>
@endsection
