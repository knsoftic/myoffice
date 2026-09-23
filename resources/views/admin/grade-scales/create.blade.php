@extends('layouts.admin')

@section('title', 'New grade scale')

@section('header')
    <x-ui.page-header title="New grade scale"
                      subtitle="Bands have to be contiguous and cover exactly 0–100. Two rows is the minimum, one of them a fail."
                      icon="academic-cap">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.grade-scales.index')">Cancel</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.grade-scales.store') }}">
        @csrf

        @include('admin.grade-scales._form')

        <div class="mt-6 flex justify-end gap-2">
            <x-ui.button variant="ghost" :href="route('admin.grade-scales.index')">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="check">Create scale</x-ui.button>
        </div>
    </form>
@endsection
