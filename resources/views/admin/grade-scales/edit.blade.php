@extends('layouts.admin')

@section('title', 'Edit '.$scale->name)

@section('header')
    <x-ui.page-header :title="$scale->name" :subtitle="'Editing '.$scale->code" icon="academic-cap">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.grade-scales.show', $scale)">Back to the scale</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.grade-scales.update', $scale) }}">
        @csrf
        @method('PUT')

        @include('admin.grade-scales._form')

        <div class="mt-6 flex justify-end gap-2">
            <x-ui.button variant="ghost" :href="route('admin.grade-scales.show', $scale)">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="check">Save changes</x-ui.button>
        </div>
    </form>
@endsection
