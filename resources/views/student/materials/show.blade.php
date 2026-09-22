@extends('layouts.panel')

@section('title', $material->title)

@section('header')
    <x-ui.page-header :title="$material->title" :subtitle="$material->course?->name" icon="folder-open">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('student.materials.index')">Back</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card>
        <div class="flex flex-wrap items-center gap-2">
            <x-ui.badge :color="$material->type->color()" size="xs">{{ $material->type->label() }}</x-ui.badge>
            @if ($material->topic)
                <x-ui.badge color="slate" size="xs">{{ $material->topic->title }}</x-ui.badge>
            @endif
        </div>

        @if ($material->description)
            <p class="mt-4 text-sm text-slate-600 dark:text-slate-300">{{ $material->description }}</p>
        @endif

        @if ($material->available_until)
            <x-ui.form.help class="mt-4">
                Available until {{ app_datetime($material->available_until) }}.
            </x-ui.form.help>
        @endif

        <div class="mt-6">
            @if (! $grant->allowed)
                {{-- The reason is a named constant; this is only the sentence for it. --}}
                <div class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                    {{ $grant->message() }}
                </div>
            @elseif ($material->isLink())
                <x-ui.button icon="arrow-top-right-on-square"
                             :href="route('student.materials.open', $material)">Open the link</x-ui.button>
            @elseif ($material->is_downloadable)
                <x-ui.button icon="arrow-down-tray"
                             :href="route('student.materials.download', $material)">Download</x-ui.button>
            @else
                <x-ui.button variant="secondary" icon="eye"
                             :href="route('student.materials.download', $material)">View</x-ui.button>
                <x-ui.form.help class="mt-2">Your teacher has shared this for viewing rather than keeping.</x-ui.form.help>
            @endif
        </div>
    </x-ui.card>
@endsection
