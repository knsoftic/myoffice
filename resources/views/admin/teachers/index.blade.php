@extends('layouts.admin')

@section('title', 'Teachers')

@section('header')
    <x-ui.page-header title="Teachers"
                      subtitle="Who takes the classes. A teacher on a timetable cannot be retired until their classes are handed on."
                      icon="presentation-chart-bar">
        <x-slot:actions>
            @can('teachers.export')
                <x-ui.button variant="ghost" icon="arrow-down-tray" :href="route('admin.teachers.export', 'csv')">Export</x-ui.button>
            @endcan
            @can('create', \App\Models\Institute\Teacher::class)
                <x-ui.button icon="plus" :href="route('admin.teachers.create')">Add teacher</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="All teachers" :value="app_number($counts['all'])" icon="users" color="slate" />
        <x-ui.stat-card label="Teaching" :value="app_number($counts['active'] ?? 0)" icon="check" color="emerald" />
        <x-ui.stat-card label="On leave" :value="app_number($counts['on_leave'] ?? 0)" icon="clock" color="amber" />
        <x-ui.stat-card label="Resigned" :value="app_number($counts['resigned'] ?? 0)" icon="arrow-right-start-on-rectangle" color="slate" />
    </div>

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Name, code, email or subject" />

            <x-ui.form.select name="status" label="Status" placeholder="Any status">
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="course_id" label="Teaches" placeholder="Any course">
                @foreach ($courses as $id => $name)
                    <option value="{{ $id }}" @selected((int) request('course_id') === (int) $id)>{{ $name }}</option>
                @endforeach
            </x-ui.form.select>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.teachers.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card>
        <x-ui.table :is-empty="$teachers->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Teacher</th>
                <th class="px-4 py-3 text-left font-semibold">Contact</th>
                <th class="px-4 py-3 text-left font-semibold">Subject</th>
                <th class="px-4 py-3 text-left font-semibold">Courses</th>
                <th class="px-4 py-3 text-left font-semibold">Batches</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($teachers as $teacher)
                <tr>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <x-ui.avatar :name="$teacher->name" size="sm" />
                            <div>
                                <a href="{{ route('admin.teachers.show', $teacher) }}"
                                   class="font-medium text-slate-700 hover:underline dark:text-slate-200">{{ $teacher->name }}</a>
                                <div class="text-xs text-slate-400">{{ $teacher->teacher_code }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        @if (filled($teacher->phone))
                            <div>{{ $teacher->phone }}</div>
                        @endif
                        @if (filled($teacher->email))
                            <div class="text-xs text-slate-400">{{ $teacher->email }}</div>
                        @endif
                        @if (blank($teacher->phone) && blank($teacher->email))
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $teacher->specialization ?: '—' }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ app_number($teacher->courses_count) }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ app_number($teacher->batches_count) }}</td>
                    <td class="px-4 py-3">
                        <x-ui.badge :color="$teacher->status->color()">{{ $teacher->status->label() }}</x-ui.badge>
                        @if ($teacher->is_public)
                            <x-ui.badge color="sky" size="xs" class="ml-1">On the site</x-ui.badge>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        @can('teachers.view_reports')
                            <x-ui.button variant="ghost" size="sm" icon="chart-bar" :href="route('admin.teachers.workload', $teacher)">Workload</x-ui.button>
                        @endcan
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="presentation-chart-bar" title="No teachers yet"
                                  description="Add the people who take the classes, then put them on a timetable." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$teachers" label="teachers" />
    </x-ui.card>
@endsection
