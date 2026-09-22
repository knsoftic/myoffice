@extends('layouts.admin')

@section('title', 'Edit material')

@section('header')
    <x-ui.page-header :title="$material->title" subtitle="Editing the material" icon="folder-open">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.course-materials.show', $material)">Back</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.course-materials.update', $material) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        @include('admin.course-materials._form')

        <div class="mt-6 flex justify-end gap-2">
            <x-ui.button variant="ghost" :href="route('admin.course-materials.show', $material)">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="check">Save changes</x-ui.button>
        </div>
    </form>
@endsection
