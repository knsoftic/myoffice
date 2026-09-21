@extends('layouts.admin')

@section('title', $teacher->name)

@section('header')
    <x-ui.page-header :title="$teacher->name"
                      :subtitle="$teacher->teacher_code.($teacher->specialization ? ' · '.$teacher->specialization : '')"
                      icon="presentation-chart-bar"
                      :badge="$teacher->status->label()"
                      :badge-color="$teacher->status->color()"
                      :back="route('admin.teachers.index')">
        <x-slot:actions>
            @can('teachers.view_reports')
                <x-ui.button variant="ghost" icon="chart-bar" :href="route('admin.teachers.workload', $teacher)">Workload</x-ui.button>
            @endcan
            @can('update', $teacher)
                <x-ui.button icon="pencil" :href="route('admin.teachers.edit', $teacher)">Edit</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @unless ($teacher->canTeach())
        <x-ui.card class="mb-4 border-amber-200 dark:border-amber-500/30">
            <div class="flex items-start gap-3">
                <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
                <div>
                    <p class="font-medium text-slate-700 dark:text-slate-200">
                        {{ $teacher->name }} is {{ $teacher->status->label() }} and cannot be put on a timetable.
                    </p>
                    @if (filled($teacher->status_reason))
                        <p class="mt-1 text-sm text-slate-500">{{ $teacher->status_reason }}</p>
                    @endif
                </div>
            </div>
        </x-ui.card>
    @endunless

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="Upcoming classes">
                <x-ui.table :is-empty="$upcoming->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">When</th>
                        <th class="px-4 py-3 text-left font-semibold">Batch</th>
                        <th class="px-4 py-3 text-left font-semibold">Status</th>
                    </x-slot:head>

                    @foreach ($upcoming as $session)
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.class-sessions.show', $session) }}"
                                   class="font-medium text-slate-700 hover:underline dark:text-slate-200">{{ app_date($session->session_date) }}</a>
                                <div class="text-xs text-slate-400">{{ app_time($session->startsAt()) }} – {{ app_time($session->endsAt()) }}</div>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $session->batch?->code ?? '—' }}</td>
                            <td class="px-4 py-3"><x-ui.badge :color="$session->status->color()" size="xs">{{ $session->status->label() }}</x-ui.badge></td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="calendar-days" title="Nothing scheduled"
                                          description="Classes appear here once this teacher is on a timetable." />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="Batches">
                <x-ui.table :is-empty="$batches->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Batch</th>
                        <th class="px-4 py-3 text-left font-semibold">Course</th>
                        <th class="px-4 py-3 text-left font-semibold">Runs</th>
                        <th class="px-4 py-3 text-left font-semibold">Students</th>
                        <th class="px-4 py-3 text-left font-semibold">Status</th>
                    </x-slot:head>

                    @foreach ($batches as $batch)
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.batches.show', $batch) }}"
                                   class="font-medium text-slate-700 hover:underline dark:text-slate-200">{{ $batch->code }}</a>
                                <div class="text-xs text-slate-400">{{ $batch->name }}</div>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $batch->course?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                                {{ app_date($batch->start_date) }}@if ($batch->end_date) – {{ app_date($batch->end_date) }}@endif
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ app_number($batch->current_students) }} / {{ app_number($batch->student_capacity) }}</td>
                            <td class="px-4 py-3"><x-ui.badge :color="$batch->status->color()" size="xs">{{ $batch->status->label() }}</x-ui.badge></td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="squares-2x2" title="No batches"
                                          description="This teacher has not been assigned a batch yet." />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>
        </div>

        <div class="space-y-4">
            <x-ui.card title="Details">
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-slate-400">Phone</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $teacher->phone ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Email</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $teacher->email ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Qualification</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $teacher->qualification ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Experience</dt>
                        <dd class="text-slate-700 dark:text-slate-200">
                            {{ $teacher->experience_years !== null ? app_number($teacher->experience_years).' years' : '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Joined</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $teacher->joining_date ? app_date($teacher->joining_date) : '—' }}</dd>
                    </div>
                    @if ($canSeeSalary)
                        <div>
                            <dt class="text-slate-400">Salary</dt>
                            <dd class="font-medium text-slate-700 dark:text-slate-200">{{ $teacher->salary !== null ? app_money($teacher->salary) : '—' }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-slate-400">Branch</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $teacher->branch?->name ?? 'Every branch' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Login</dt>
                        <dd class="text-slate-700 dark:text-slate-200">
                            @if ($teacher->user)
                                {{ $teacher->user->email }}
                                <x-ui.badge :color="$teacher->user->status->color()" size="xs">{{ $teacher->user->status->label() }}</x-ui.badge>
                            @else
                                <span class="text-slate-400">None</span>
                                @can('update', $teacher)
                                    <form method="POST" action="{{ route('admin.teachers.login.store', $teacher) }}" class="mt-2">
                                        @csrf
                                        <x-ui.button type="submit" variant="ghost" size="sm" icon="key">Create a login</x-ui.button>
                                    </form>
                                @endcan
                            @endif
                        </dd>
                    </div>
                </dl>
            </x-ui.card>

            @can('assign', $teacher)
                <x-ui.card title="Courses">
                    <form method="POST" action="{{ route('admin.teachers.courses', $teacher) }}" class="space-y-3">
                        @csrf
                        <div class="max-h-60 space-y-2 overflow-y-auto pr-1">
                            @foreach ($courses as $id => $name)
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" name="course_ids[]" value="{{ $id }}"
                                           @checked($teacher->courses->contains('id', $id))
                                           class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-900">
                                    <span class="text-slate-600 dark:text-slate-300">{{ $name }}</span>
                                </label>
                            @endforeach
                        </div>

                        <x-ui.form.select name="primary_course_id" label="Main course" placeholder="None in particular">
                            @foreach ($courses as $id => $name)
                                <option value="{{ $id }}"
                                    @selected($teacher->courses->firstWhere('id', $id)?->pivot?->is_primary)>{{ $name }}</option>
                            @endforeach
                        </x-ui.form.select>

                        <x-ui.button type="submit" variant="secondary" size="sm" icon="check">Save courses</x-ui.button>
                    </form>
                </x-ui.card>
            @endcan

            @can('changeStatus', $teacher)
                <x-ui.card title="Status">
                    <form method="POST" action="{{ route('admin.teachers.status', $teacher) }}" class="space-y-3">
                        @csrf
                        <x-ui.form.select name="status" label="Move to" required>
                            @foreach ($statuses as $value => $label)
                                <option value="{{ $value }}" @selected($teacher->status->value === $value)>{{ $label }}</option>
                            @endforeach
                        </x-ui.form.select>

                        <x-ui.form.textarea name="reason" label="Reason" rows="2"
                                            help="Required for suspended and resigned. It goes on the record." />

                        <x-ui.button type="submit" variant="secondary" size="sm" icon="arrow-path">Change status</x-ui.button>

                        <p class="text-xs text-slate-400">
                            Moving somebody off active is refused while they still own a scheduled class —
                            the refusal names the classes so they can be handed on first.
                        </p>
                    </form>
                </x-ui.card>
            @endcan
        </div>
    </div>
@endsection
