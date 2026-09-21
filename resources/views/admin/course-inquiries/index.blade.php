@extends('layouts.admin')

@section('title', 'Course inquiries')

@section('header')
    <x-ui.page-header title="Course inquiries"
                      subtitle="The counsellor's queue. Sorted by when somebody is next due a call — overdue first."
                      icon="question-mark-circle">
        <x-slot:actions>
            @can('course_inquiries.view_reports')
                <x-ui.button variant="ghost" icon="chart-bar" :href="route('admin.course-inquiries.funnel')">Funnel</x-ui.button>
            @endcan
            @can('course_inquiries.export')
                <x-ui.button variant="ghost" icon="arrow-down-tray"
                             :href="route('admin.course-inquiries.export', ['format' => 'csv', ...request()->query()])">Export</x-ui.button>
            @endcan
            @can('course_inquiries.create')
                <x-ui.button variant="primary" icon="plus" :href="route('admin.course-inquiries.create')">Add inquiry</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="New today" :value="app_number($stats['new_today'])" icon="sparkles" color="sky" />
        <x-ui.stat-card label="Due today" :value="app_number($stats['due_today'])" icon="phone" color="brand" />
        <x-ui.stat-card label="Overdue" :value="app_number($stats['overdue'])" icon="exclamation-triangle"
                        :color="$stats['overdue'] > 0 ? 'rose' : 'slate'" />
        <x-ui.stat-card label="Converted this month" :value="app_number($stats['converted_this_month'])"
                        icon="check-badge" color="emerald" />
    </div>

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Name, phone or inquiry #" />

            <x-ui.form.select name="status" label="Status" placeholder="Any status">
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="source" label="Source" placeholder="Any source">
                @foreach ($sources as $value => $label)
                    <option value="{{ $value }}" @selected(request('source') === $value)>{{ $label }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="course_id" label="Course" placeholder="Any course">
                @foreach ($courses as $id => $name)
                    <option value="{{ $id }}" @selected((int) request('course_id') === (int) $id)>{{ $name }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="assigned_to" label="Assigned to" placeholder="Anybody">
                @foreach ($assignees as $id => $name)
                    <option value="{{ $id }}" @selected((int) request('assigned_to') === (int) $id)>{{ $name }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="due" label="Follow-up" placeholder="Any date">
                <option value="overdue" @selected(request('due') === 'overdue')>Overdue</option>
                <option value="today" @selected(request('due') === 'today')>Today</option>
                <option value="week" @selected(request('due') === 'week')>This week</option>
            </x-ui.form.select>

            <div class="flex items-end gap-2 sm:col-span-2 xl:col-span-6">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.course-inquiries.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card>
        <x-ui.table :is-empty="$inquiries->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Inquiry</th>
                <th class="px-4 py-3 text-left font-semibold">Contact</th>
                <th class="px-4 py-3 text-left font-semibold">Course</th>
                <th class="px-4 py-3 text-left font-semibold">Source</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
                <th class="px-4 py-3 text-left font-semibold">Assigned</th>
                <th class="px-4 py-3 text-left font-semibold">Next follow-up</th>
                <th class="px-4 py-3 text-right font-semibold">Tries</th>
            </x-slot:head>

            @foreach ($inquiries as $inquiry)
                @php($stale = $inquiry->isStale())
                <tr @class([
                    'border-l-4',
                    'border-l-amber-400' => $stale,
                    'border-l-transparent' => ! $stale,
                ])
                    @if ($stale) title="Untouched for {{ $staleDays }} days or more. Flagged, never closed automatically." @endif>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.course-inquiries.show', $inquiry) }}"
                           class="font-medium text-sky-700 hover:underline dark:text-sky-400">{{ $inquiry->inquiry_number }}</a>
                        <div class="text-xs text-slate-400">{{ app_date($inquiry->created_at) }}</div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-slate-700 dark:text-slate-200">{{ $inquiry->name }}</div>
                        <div class="flex items-center gap-2 text-xs text-slate-500">
                            <a href="tel:{{ $inquiry->phone }}" class="hover:underline">{{ $inquiry->phone }}</a>
                            @if (filled($inquiry->whatsapp))
                                <a href="https://wa.me/{{ ltrim($inquiry->whatsapp, '+') }}" target="_blank" rel="noopener noreferrer"
                                   class="text-emerald-600 hover:underline dark:text-emerald-400">WhatsApp</a>
                            @endif
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $inquiry->course?->name ?? '—' }}</td>
                    <td class="px-4 py-3"><x-ui.badge :color="$inquiry->source->color()" size="xs">{{ $inquiry->source->label() }}</x-ui.badge></td>
                    <td class="px-4 py-3"><x-ui.badge :color="$inquiry->status->color()">{{ $inquiry->status->label() }}</x-ui.badge></td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $inquiry->assignee?->name ?? 'Unassigned' }}</td>
                    <td class="px-4 py-3 text-sm">
                        @if ($inquiry->follow_up_date)
                            <span @class(['font-medium text-rose-600 dark:text-rose-400' => $inquiry->followUpIsOverdue()])>
                                {{ app_date($inquiry->follow_up_date) }}
                            </span>
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-500">{{ app_number($inquiry->contact_attempts) }}</td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="question-mark-circle"
                                  title="No inquiries in this view"
                                  description="Either nothing matches the filters, or nobody has asked yet.">
                    @can('course_inquiries.create')
                        <x-ui.button variant="primary" icon="plus" :href="route('admin.course-inquiries.create')">Add inquiry</x-ui.button>
                    @endcan
                </x-ui.empty-state>
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$inquiries" />
    </x-ui.card>
@endsection
