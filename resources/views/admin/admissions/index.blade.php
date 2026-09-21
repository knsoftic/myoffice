@extends('layouts.admin')

@section('title', 'Admissions')

@section('header')
    <x-ui.page-header title="Admissions"
                      subtitle="Each row is one student on one course, somewhere in the seven-step pipeline."
                      icon="user-plus">
        <x-slot:actions>
            @if ($canSeeMoney)
                @can('admissions.export')
                    <x-ui.button variant="ghost" icon="arrow-down-tray"
                                 :href="route('admin.admissions.export', ['format' => 'csv', ...request()->query()])">Export</x-ui.button>
                @endcan
            @endif
            @can('admissions.create')
                <x-ui.button variant="primary" icon="plus" :href="route('admin.admissions.create')">New admission</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Live admissions" :value="app_number($stats['live'])" icon="user-plus" color="emerald" />
        <x-ui.stat-card label="This month" :value="app_number($stats['this_month'])" icon="calendar-days" color="sky" />
        <x-ui.stat-card label="Awaiting registration" :value="app_number($stats['awaiting_registration'])"
                        icon="identification" color="amber" />
        <x-ui.stat-card label="Awaiting activation" :value="app_number($stats['awaiting_activation'])"
                        icon="bolt" color="violet" />
    </div>

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Admission #, student or code" />

            <x-ui.form.select name="stage" label="Stage" placeholder="Any stage">
                @foreach ($stages as $value => $label)
                    <option value="{{ $value }}" @selected(request('stage') === $value)>{{ $label }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="course_id" label="Course" placeholder="Any course">
                @foreach ($courses as $id => $name)
                    <option value="{{ $id }}" @selected((int) request('course_id') === (int) $id)>{{ $name }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="counselor_id" label="Counsellor" placeholder="Anybody">
                @foreach ($counselors as $id => $name)
                    <option value="{{ $id }}" @selected((int) request('counselor_id') === (int) $id)>{{ $name }}</option>
                @endforeach
            </x-ui.form.select>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.admissions.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card>
        <x-ui.table :is-empty="$admissions->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Admission</th>
                <th class="px-4 py-3 text-left font-semibold">Student</th>
                <th class="px-4 py-3 text-left font-semibold">Course</th>
                <th class="px-4 py-3 text-left font-semibold">Stage</th>
                <th class="px-4 py-3 text-left font-semibold">Counsellor</th>
                @if ($canSeeMoney)
                    <th class="px-4 py-3 text-right font-semibold">Net payable</th>
                    <th class="px-4 py-3 text-right font-semibold">Balance</th>
                @endif
            </x-slot:head>

            @foreach ($admissions as $admission)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.admissions.show', $admission) }}"
                           class="font-medium text-sky-700 hover:underline dark:text-sky-400">{{ $admission->admission_number }}</a>
                        <div class="text-xs text-slate-400">{{ app_date($admission->admission_date) }}</div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-slate-700 dark:text-slate-200">{{ $admission->student?->name }}</div>
                        <div class="font-mono text-xs text-slate-400">{{ $admission->student?->student_code }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $admission->course?->name }}</td>
                    <td class="px-4 py-3"><x-ui.badge :color="$admission->stage->color()">{{ $admission->stage->label() }}</x-ui.badge></td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $admission->counselor?->name ?? '—' }}</td>
                    @if ($canSeeMoney)
                        <td class="px-4 py-3 text-right tabular-nums">{{ money($admission->net_payable) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ money($admission->balance_amount) }}</td>
                    @endif
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="user-plus" title="No admissions yet"
                                  description="An admission starts from a converted application, or directly from a student record." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$admissions" label="admissions" />
    </x-ui.card>
@endsection
