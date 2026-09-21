@extends('layouts.admin')

@section('title', 'New batch')

@section('header')
    <x-ui.page-header title="New batch"
                      subtitle="It starts as planned. Give it a teacher and a timetable, and it can then be opened for admission."
                      icon="squares-2x2"
                      :back="route('admin.batches.index')" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.batches.store') }}" class="space-y-4">
        @csrf
        @include('admin.batches._form')

        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="primary" icon="check">Create the batch</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.batches.index')">Cancel</x-ui.button>
        </div>
    </form>
@endsection
