@extends('layouts.admin')

@section('title', 'Students')

@section('header')
    <x-ui.page-header title="Students"
                      subtitle="Everybody the institute has a record for — enquiring, registered, active or finished."
                      icon="users">
        <x-slot:actions>
            @can('students.export')
                <x-ui.button variant="ghost" icon="arrow-down-tray"
                             :href="route('admin.students.export', ['format' => 'csv', ...request()->query()])">Export</x-ui.button>
            @endcan
            @can('students.create')
                <x-ui.button variant="primary" icon="plus" :href="route('admin.students.create')">Add student</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Total" :value="app_number($stats['total'])" icon="users" color="slate" />
        <x-ui.stat-card label="Active" :value="app_number($stats['active'])" icon="check-badge" color="emerald" />
        <x-ui.stat-card label="New this month" :value="app_number($stats['new_this_month'])" icon="sparkles" color="sky" />
        <x-ui.stat-card label="Dropped" :value="app_number($stats['dropped'])" icon="user-minus"
                        :color="$stats['dropped'] > 0 ? 'amber' : 'slate'" />
    </div>

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <x-ui.form.input name="q" label="Search" :value="request('q')"
                             placeholder="Name, code, registration #, phone or CNIC" />

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

            <x-ui.form.select name="branch_id" label="Branch" placeholder="Any branch">
                @foreach ($branches as $id => $name)
                    <option value="{{ $id }}" @selected((int) request('branch_id') === (int) $id)>{{ $name }}</option>
                @endforeach
            </x-ui.form.select>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.students.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card>
        <x-ui.table :is-empty="$students->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Student</th>
                <th class="px-4 py-3 text-left font-semibold">Code</th>
                <th class="px-4 py-3 text-left font-semibold">Registration</th>
                <th class="px-4 py-3 text-left font-semibold">Contact</th>
                <th class="px-4 py-3 text-right font-semibold">Admissions</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
                <th class="px-4 py-3 text-left font-semibold">Joined</th>
            </x-slot:head>

            @foreach ($students as $student)
                <tr>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <x-ui.avatar :name="$student->name" :src="$student->photo_path" size="sm" />
                            <div class="min-w-0">
                                <a href="{{ route('admin.students.show', $student) }}"
                                   class="font-medium text-sky-700 hover:underline dark:text-sky-400">{{ $student->name }}</a>
                                @if (filled($student->father_name))
                                    <div class="truncate text-xs text-slate-400">s/o {{ $student->father_name }}</div>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-300">{{ $student->student_code }}</td>
                    <td class="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-300">
                        {{ $student->registration_number ?: '—' }}
                    </td>
                    <td class="px-4 py-3 text-sm">
                        <a href="tel:{{ $student->phone }}" class="text-slate-600 hover:underline dark:text-slate-300">{{ $student->phone }}</a>
                        @if (filled($student->city))
                            <div class="text-xs text-slate-400">{{ $student->city }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-500">{{ app_number($student->admissions_count) }}</td>
                    <td class="px-4 py-3"><x-ui.badge :color="$student->status->color()">{{ $student->status->label() }}</x-ui.badge></td>
                    <td class="px-4 py-3 text-sm text-slate-500">
                        {{ $student->joining_date ? app_date($student->joining_date) : '—' }}
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="users" title="No students yet"
                                  description="A student record is created by converting an application, or added here directly.">
                    @can('students.create')
                        <x-ui.button variant="primary" icon="plus" :href="route('admin.students.create')">Add student</x-ui.button>
                    @endcan
                </x-ui.empty-state>
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$students" label="students" />
    </x-ui.card>
@endsection
