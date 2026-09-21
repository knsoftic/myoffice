@extends('layouts.admin')

@section('title', 'Batches')

@section('header')
    <x-ui.page-header title="Batches"
                      subtitle="A group of students taking one course together. Capacity is decided by a recount, never by the number on this screen."
                      icon="squares-2x2">
        <x-slot:actions>
            @can('batches.export')
                <x-ui.button variant="ghost" icon="arrow-down-tray" :href="route('admin.batches.export', 'csv')">Export</x-ui.button>
            @endcan
            @can('create', \App\Models\Institute\Batch::class)
                <x-ui.button icon="plus" :href="route('admin.batches.create')">New batch</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="All batches" :value="app_number($counts['all'])" icon="squares-2x2" color="slate" />
        <x-ui.stat-card label="Enrolling" :value="app_number($counts['enrolling'] ?? 0)" icon="user-plus" color="sky" />
        <x-ui.stat-card label="Running" :value="app_number($counts['running'] ?? 0)" icon="play" color="emerald" />
        <x-ui.stat-card label="On hold" :value="app_number($counts['on_hold'] ?? 0)" icon="pause" color="amber" />
    </div>

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Code or name" />

            <x-ui.form.select name="status" label="Status" placeholder="Any status">
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="course_id" label="Course" placeholder="Any course">
                @foreach ($courses as $id => $name)
                    <option value="{{ $id }}" @selected((int) request('course_id') === (int) $id)>{{ $name }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="teacher_id" label="Teacher" placeholder="Anybody">
                @foreach ($teachers as $id => $name)
                    <option value="{{ $id }}" @selected((int) request('teacher_id') === (int) $id)>{{ $name }}</option>
                @endforeach
            </x-ui.form.select>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.batches.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card>
        <x-ui.table :is-empty="$batches->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Batch</th>
                <th class="px-4 py-3 text-left font-semibold">Course</th>
                <th class="px-4 py-3 text-left font-semibold">Teacher</th>
                <th class="px-4 py-3 text-left font-semibold">Runs</th>
                <th class="px-4 py-3 text-left font-semibold">Seats</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
            </x-slot:head>

            @foreach ($batches as $batch)
                @php($taken = $batch->student_capacity > 0 ? min(100, ($batch->current_students / $batch->student_capacity) * 100) : 0)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.batches.show', $batch) }}"
                           class="font-medium text-slate-700 hover:underline dark:text-slate-200">{{ $batch->code }}</a>
                        <div class="text-xs text-slate-400">{{ $batch->name }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $batch->course?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $batch->teacher?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ app_date($batch->start_date) }}
                        <div class="text-xs text-slate-400">
                            {{ $batch->end_date ? 'to '.app_date($batch->end_date) : 'open-ended' }}
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="text-sm text-slate-600 dark:text-slate-300">
                            {{ app_number($batch->current_students) }} / {{ app_number($batch->student_capacity) }}
                        </div>
                        <div class="mt-1 h-1.5 w-24 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                            <div class="h-full rounded-full {{ $taken >= 100 ? 'bg-rose-500' : ($taken >= 90 ? 'bg-amber-500' : 'bg-emerald-500') }}"
                                 style="width: {{ $taken }}%"></div>
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <x-ui.badge :color="$batch->status->color()">{{ $batch->status->label() }}</x-ui.badge>
                        <x-ui.badge :color="$batch->delivery_mode->color()" size="xs" class="ml-1">{{ $batch->delivery_mode->label() }}</x-ui.badge>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="squares-2x2" title="No batches yet"
                                  description="Open a batch, give it a timetable, then start seating students." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$batches" label="batches" />
    </x-ui.card>
@endsection
