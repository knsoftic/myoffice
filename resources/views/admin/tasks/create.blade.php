@extends('layouts.admin')

@section('title', 'New task')

@section('header')
    <x-ui.page-header title="New task" subtitle="A piece of work inside a project." icon="check-circle">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.tasks.index')">Cancel</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.tasks.store') }}" class="space-y-4">
        @csrf
        <x-ui.card>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-ui.form.input name="title" label="Title" :value="old('title')" required maxlength="200" />
                </div>
                <x-ui.form.select name="project_id" label="Project" :options="$projects" :selected="old('project_id')" placeholder="Choose a project" required />
                <x-ui.form.select name="priority" label="Priority" :options="$priorities" :selected="old('priority', 'medium')" required />
                <x-ui.form.input type="date" name="start_date" label="Start date" :value="old('start_date')" />
                <x-ui.form.input type="date" name="due_date" label="Due date" :value="old('due_date')" />
                <x-ui.form.input type="number" min="1" name="estimated_minutes" label="Estimate (minutes)" :value="old('estimated_minutes')" help="Used as this task's weight in the progress average." />
                <div class="sm:col-span-2">
                    <x-ui.form.textarea name="description" label="Description" :value="old('description')" rows="4" maxlength="5000" />
                </div>
            </div>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button variant="secondary" :href="route('admin.tasks.index')">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="check">Create task</x-ui.button>
        </div>
    </form>
@endsection
