@extends('layouts.admin')

@section('title', 'Set work')

@section('header')
    <x-ui.page-header title="Set work"
                      subtitle="Saved as a draft. The batch sees nothing until you publish it."
                      icon="clipboard-document">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.assignments.index')">Cancel</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.assignments.store') }}" enctype="multipart/form-data">
        @csrf

        @include('admin.assignments._form')

        <div class="mt-6 flex justify-end gap-2">
            <x-ui.button variant="ghost" :href="route('admin.assignments.index')">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="check">Save as draft</x-ui.button>
        </div>
    </form>
@endsection
