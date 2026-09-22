@extends('layouts.panel')

@section('title', 'Materials')

@section('header')
    <x-ui.page-header title="Materials"
                      subtitle="What you have shared, and what others have shared with your batches."
                      icon="folder-open">
        <x-slot:actions>
            @if ($canUpload)
                <x-ui.button icon="plus" :href="route('teacher.materials.create')">Share material</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Title" />
            <x-ui.form.select name="status" label="Status" placeholder="Any status">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.form.select>
            <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
            <x-ui.button variant="ghost" :href="route('teacher.materials.index')">Clear</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$materials->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Material</th>
                <th class="px-4 py-3 text-left font-semibold">Course</th>
                <th class="px-4 py-3 text-right font-semibold">Opened</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
                <th class="px-4 py-3"></th>
            </x-slot:head>

            @foreach ($materials as $material)
                <tr>
                    <td class="px-4 py-3">
                        <div class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $material->title }}</div>
                        <div class="text-xs text-slate-400">
                            <x-ui.badge :color="$material->type->color()" size="xs">{{ $material->type->label() }}</x-ui.badge>
                            @if ($material->topic)
                                · {{ $material->topic->title }}
                            @endif
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $material->course?->name }}</td>
                    <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-600 dark:text-slate-300">
                        {{ app_number($material->unique_students_count) }} students
                    </td>
                    <td class="px-4 py-3"><x-ui.badge :color="$material->status->color()" size="xs">{{ $material->status->label() }}</x-ui.badge></td>
                    <td class="px-4 py-3 text-right">
                        @if ($material->isFile())
                            <x-ui.button variant="ghost" size="sm" icon="arrow-down-tray"
                                         :href="route('teacher.materials.download', $material)">Open</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="folder-open" title="Nothing shared yet"
                                  description="Share a handout, a recording or a link with one of your batches." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$materials" label="materials" />
    </x-ui.card>
@endsection
