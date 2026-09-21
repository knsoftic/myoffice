@extends('layouts.admin')

@section('title', 'Edit '.$batch->code)

@section('header')
    <x-ui.page-header :title="'Edit '.$batch->code"
                      :subtitle="$batch->name.' · the status and the student count are changed elsewhere, each through the one thing that owns it.'"
                      icon="squares-2x2"
                      :back="route('admin.batches.show', $batch)" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.batches.update', $batch) }}" class="space-y-4">
        @csrf
        @method('PUT')
        @include('admin.batches._form')

        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="primary" icon="check">Save changes</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.batches.show', $batch)">Cancel</x-ui.button>
        </div>
    </form>
@endsection
