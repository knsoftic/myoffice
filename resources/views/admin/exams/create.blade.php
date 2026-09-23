@extends('layouts.admin')

@section('title', 'Set an exam')

@section('header')
    <x-ui.page-header title="Set an exam"
                      subtitle="Saved as a draft. The batch is told nothing until you schedule it."
                      icon="document-chart-bar">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.exams.index')">Cancel</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.exams.store') }}">
        @csrf

        @include('admin.exams._form')

        <div class="mt-6 flex justify-end gap-2">
            <x-ui.button variant="ghost" :href="route('admin.exams.index')">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="check">Save as draft</x-ui.button>
        </div>
    </form>
@endsection
