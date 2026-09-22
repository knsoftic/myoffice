@extends('layouts.admin')

@section('title', 'Course materials')

@section('header')
    <x-ui.page-header title="Course materials"
                      subtitle="What a teacher handed out — targeted, time-windowed and download-tracked. This is distribution, not the published syllabus."
                      icon="folder-open">
        <x-slot:actions>
            @if ($canCreate)
                <x-ui.button icon="plus" :href="route('admin.course-materials.create')">Share material</x-ui.button>
            @endif
            @can('export', App\Models\Institute\CourseMaterial::class)
                <x-ui.button variant="ghost" icon="arrow-down-tray"
                             :href="route('admin.course-materials.export', ['format' => 'csv', ...request()->query()])">Export</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($statuses as $status)
            <x-ui.stat-card :label="$status->label()"
                            :value="app_number($counts[$status->value] ?? 0)"
                            :color="$status->color()" />
        @endforeach
    </div>

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Title or description" />

            <x-ui.form.select name="course_id" label="Course" placeholder="Any course">
                @foreach ($courses as $course)
                    <option value="{{ $course->id }}" @selected((int) request('course_id') === (int) $course->id)>{{ $course->name }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="type" label="Kind" placeholder="Any kind">
                @foreach ($types as $type)
                    <option value="{{ $type->value }}" @selected(request('type') === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="status" label="Status" placeholder="Any status">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="branch_id" label="Branch" placeholder="Any branch">
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected((int) request('branch_id') === (int) $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </x-ui.form.select>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.course-materials.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$materials->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Material</th>
                <th class="px-4 py-3 text-left font-semibold">Course</th>
                <th class="px-4 py-3 text-left font-semibold">Audience</th>
                <th class="px-4 py-3 text-left font-semibold">Available</th>
                <th class="px-4 py-3 text-right font-semibold">Opened</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
            </x-slot:head>

            @foreach ($materials as $material)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.course-materials.show', $material) }}"
                           class="text-sm font-medium text-slate-700 hover:underline dark:text-slate-200">{{ $material->title }}</a>
                        <div class="text-xs text-slate-400">
                            <x-ui.badge :color="$material->type->color()" size="xs">{{ $material->type->label() }}</x-ui.badge>
                            @if ($material->isLink())
                                · external link
                            @elseif (! $material->is_downloadable)
                                {{-- §6.5 is plain that this is deterrence, not DRM. --}}
                                · view only
                            @endif
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $material->course?->name ?? '—' }}
                        @if ($material->teacher)
                            <div class="text-xs text-slate-400">shared by {{ $material->teacher->employee_id }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <x-ui.badge :color="$material->audience_scope->color()" size="xs">{{ $material->audience_scope->label() }}</x-ui.badge>
                        <div class="text-xs text-slate-400">{{ app_number($material->targets_count) }} target{{ $material->targets_count === 1 ? '' : 's' }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        @if ($material->available_from || $material->available_until)
                            {{ $material->available_from ? app_date($material->available_from) : 'now' }}
                            &rarr;
                            {{ $material->available_until ? app_date($material->available_until) : 'no end' }}
                        @else
                            <span class="text-slate-400">whenever it is published</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-600 dark:text-slate-300">
                        {{ app_number($material->unique_students_count) }}
                        <div class="text-xs text-slate-400">
                            {{ app_number($material->download_count) }} download{{ $material->download_count === 1 ? '' : 's' }}
                        </div>
                    </td>
                    <td class="px-4 py-3"><x-ui.badge :color="$material->status->color()" size="xs">{{ $material->status->label() }}</x-ui.badge></td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="folder-open" title="Nothing shared yet"
                                  description="A material is a file or a link aimed at a course, a batch or one student. It stays invisible until it is published and inside its release window." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$materials" label="materials" />
    </x-ui.card>
@endsection
