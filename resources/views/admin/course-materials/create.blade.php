@extends('layouts.admin')

@section('title', 'Share material')

@section('header')
    <x-ui.page-header title="Share material"
                      subtitle="It is saved as a draft. Nothing reaches a student until you publish it."
                      icon="folder-open">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.course-materials.index')">Cancel</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.course-materials.store') }}" enctype="multipart/form-data">
        @csrf

        @include('admin.course-materials._form')

        <div class="mt-6 flex justify-end gap-2">
            <x-ui.button variant="ghost" :href="route('admin.course-materials.index')">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="check">Save as draft</x-ui.button>
        </div>
    </form>
@endsection
