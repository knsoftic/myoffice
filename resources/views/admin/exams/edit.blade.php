@extends('layouts.admin')

@section('title', 'Edit exam')

@section('header')
    <x-ui.page-header :title="$exam->name" subtitle="Editing the paper" icon="document-chart-bar">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.exams.show', $exam)">Back to the exam</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($frozen !== [])
        <x-ui.card class="mb-4 border-amber-200 dark:border-amber-500/30">
            <div class="flex gap-3">
                <x-ui.icon name="lock-closed" class="h-5 w-5 shrink-0 text-amber-500" />
                <div class="text-sm text-slate-600 dark:text-slate-300">
                    <p class="font-medium text-slate-700 dark:text-slate-200">Four fields are locked.</p>
                    <p class="mt-1">
                        These results have been published, so what the class was measured against cannot move —
                        a card that was printed and handed over has to stay reproducible. Withdraw the results
                        with a reason first if one of them is genuinely wrong.
                    </p>
                </div>
            </div>
        </x-ui.card>
    @endif

    <form method="POST" action="{{ route('admin.exams.update', $exam) }}">
        @csrf
        @method('PUT')

        @include('admin.exams._form')

        <div class="mt-6 flex justify-end gap-2">
            <x-ui.button variant="ghost" :href="route('admin.exams.show', $exam)">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="check">Save changes</x-ui.button>
        </div>
    </form>
@endsection
