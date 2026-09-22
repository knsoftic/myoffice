@extends('layouts.panel')

@section('title', 'Materials')

@section('header')
    <x-ui.page-header title="Materials"
                      subtitle="Everything your teachers have shared with your courses and batches."
                      icon="folder-open" />
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Title" />
            <x-ui.button type="submit" variant="secondary" icon="magnifying-glass">Search</x-ui.button>
            <x-ui.button variant="ghost" :href="route('student.materials.index')">Clear</x-ui.button>
        </form>
    </x-ui.card>

    <div class="grid gap-3">
        @forelse ($materials as $material)
            <x-ui.card>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <a href="{{ route('student.materials.show', $material) }}"
                           class="text-base font-medium text-slate-800 hover:underline dark:text-slate-100">{{ $material->title }}</a>
                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-400">
                            <x-ui.badge :color="$material->type->color()" size="xs">{{ $material->type->label() }}</x-ui.badge>
                            <span>{{ $material->course?->name }}</span>
                            @if ($material->topic)
                                <span>· {{ $material->topic->title }}</span>
                            @endif
                            @if ($material->published_at)
                                <span>· shared {{ app_date($material->published_at) }}</span>
                            @endif
                        </div>
                        @if ($material->description)
                            <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $material->description }}</p>
                        @endif
                    </div>

                    @if ($canDownload)
                        @if ($material->isLink())
                            <x-ui.button variant="secondary" size="sm" icon="arrow-top-right-on-square"
                                         :href="route('student.materials.open', $material)">Open the link</x-ui.button>
                        @elseif ($material->is_downloadable)
                            <x-ui.button variant="secondary" size="sm" icon="arrow-down-tray"
                                         :href="route('student.materials.download', $material)">Download</x-ui.button>
                        @else
                            <x-ui.button variant="ghost" size="sm" icon="eye"
                                         :href="route('student.materials.download', $material)">View</x-ui.button>
                        @endif
                    @endif
                </div>
            </x-ui.card>
        @empty
            <x-ui.card>
                <x-ui.empty-state icon="folder-open" title="Nothing shared yet"
                                  description="Material appears here as your teachers hand it out." />
            </x-ui.card>
        @endforelse
    </div>

    <x-ui.pagination-summary :paginator="$materials" label="materials" class="mt-4" />
@endsection
