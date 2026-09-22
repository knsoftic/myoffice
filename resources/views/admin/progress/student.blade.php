@extends('layouts.admin')

@section('title', ($enrollment->student?->name ?? 'Student').' — progress')

@section('header')
    <x-ui.page-header :title="($enrollment->student?->name ?? 'Student').' — syllabus'"
                      :subtitle="($enrollment->batch?->code ?? '').' · '.($enrollment->course?->name ?? '')"
                      icon="chart-bar"
                      :back="route('admin.student-progress.batch', $enrollment->batch_id)">
        <x-slot:actions>
            @can('student_progress.edit')
                <form method="POST" action="{{ route('admin.student-progress.recompute', $enrollment) }}">
                    @csrf
                    <x-ui.button type="submit" variant="ghost" icon="arrow-path">Recompute</x-ui.button>
                </form>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            @if ($modules->isEmpty())
                <x-ui.card>
                    <x-ui.empty-state icon="chart-bar" title="This course has no outline"
                                      description="There is nothing to track until the course has modules and topics." />
                </x-ui.card>
            @else
                @foreach ($modules as $module)
                    @php($moduleRow = $moduleRows->get($module->id))
                    <x-ui.card :title="$module->title"
                               :subtitle="$moduleRow ? app_number($moduleRow->completion_percentage).'% · '.$moduleRow->topics_completed.' of '.$moduleRow->topics_total.' topics' : 'not started'"
                               :padded="false">
                        <x-slot:actions>
                            <x-ui.badge :color="$moduleRow?->status?->color() ?? 'slate'" size="xs">
                                {{ $moduleRow?->status?->label() ?? 'Not started' }}
                            </x-ui.badge>
                        </x-slot:actions>

                        <x-ui.table :is-empty="$module->topics->isEmpty()">
                            <x-slot:head>
                                <th class="px-4 py-3 text-left font-semibold">Topic</th>
                                <th class="px-4 py-3 text-left font-semibold">Status</th>
                                <th class="px-4 py-3 text-left font-semibold">%</th>
                                <th class="px-4 py-3 text-left font-semibold">Where it came from</th>
                                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                            </x-slot:head>

                            @foreach ($module->topics as $topic)
                                @php($row = $topicRows->get($topic->id))
                                <tr>
                                    <td class="px-4 py-3">
                                        <div class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $topic->title }}</div>
                                        <div class="text-xs text-slate-400">weight {{ app_number($topic->weight) }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <x-ui.badge :color="$row?->status?->color() ?? 'slate'" size="xs">
                                            {{ $row?->status?->label() ?? 'Not started' }}
                                        </x-ui.badge>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                                        {{ app_number($row?->completion_percentage ?? 0) }}%
                                    </td>
                                    <td class="px-4 py-3 text-xs text-slate-500">
                                        @if ($row)
                                            <x-ui.badge :color="$row->source->color()" size="xs">{{ $row->source->label() }}</x-ui.badge>
                                            @if ($row->marked_at)
                                                <div class="mt-0.5">
                                                    {{ $row->marker?->name ?? 'the system' }} · {{ app_date($row->marked_at) }}
                                                </div>
                                            @endif
                                        @else
                                            <span class="text-slate-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        @can('student_progress.edit')
                                            <x-ui.button variant="ghost" size="sm" icon="pencil"
                                                         x-on:click="$dispatch('open-modal', 'mark-student-topic-{{ $topic->id }}')">Set</x-ui.button>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach

                            <x-slot:empty>
                                <x-ui.empty-state icon="queue-list" title="No topics in this module"
                                                  description="A module with no topics stands for itself and can be marked directly." />
                            </x-slot:empty>
                        </x-ui.table>
                    </x-ui.card>
                @endforeach
            @endif
        </div>

        <div class="space-y-4">
            <x-ui.card title="Overall">
                <div class="flex items-baseline justify-between">
                    <span class="text-4xl font-semibold text-slate-800 dark:text-slate-100">
                        {{ app_number($progress->completion_percentage) }}<span class="text-lg">%</span>
                    </span>
                    <x-ui.badge :color="$progress->status->color()">{{ $progress->status->label() }}</x-ui.badge>
                </div>

                <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                    <div class="h-full rounded-full bg-brand-500" style="width: {{ min(100, (float) $progress->completion_percentage) }}%"></div>
                </div>

                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-slate-400">Topics</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $progress->topicsCaption() }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-400">Modules</dt>
                        <dd class="text-slate-700 dark:text-slate-200">
                            {{ app_number($progress->modules_completed) }} of {{ app_number($progress->modules_total) }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-400">Weight</dt>
                        <dd class="text-slate-700 dark:text-slate-200">
                            {{ app_number($progress->weight_completed) }} of {{ app_number($progress->weight_total) }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-400">Last change</dt>
                        <dd class="text-slate-700 dark:text-slate-200">
                            {{ $progress->last_activity_at ? app_datetime($progress->last_activity_at) : '—' }}
                        </dd>
                    </div>
                </dl>

                <x-ui.form.help class="mt-4">
                    Every number here is recomputed from the topic rows — it is never typed, and the
                    nightly recompute reproduces it.
                </x-ui.form.help>
            </x-ui.card>
        </div>
    </div>

    @can('student_progress.edit')
        @foreach ($modules as $module)
            @foreach ($module->topics as $topic)
                @php($row = $topicRows->get($topic->id))
                <x-ui.modal name="mark-student-topic-{{ $topic->id }}" :title="$topic->title" icon="pencil">
                    <form method="POST" action="{{ route('admin.student-progress.student.topic', [$enrollment, $topic]) }}" class="space-y-4">
                        @csrf
                        <p class="text-sm text-slate-600 dark:text-slate-300">
                            Setting this for {{ $enrollment->student?->name }} marks it as theirs: the next
                            class-level mark will not overwrite it.
                        </p>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-ui.form.select name="status" label="Status">
                                @foreach ($statuses as $value => $label)
                                    <option value="{{ $value }}" @selected(($row?->status?->value ?? 'completed') === $value)>{{ $label }}</option>
                                @endforeach
                            </x-ui.form.select>

                            <x-ui.form.input name="completion_percentage" label="Covered %" type="number" min="0" max="100" step="0.01"
                                             :value="$row?->completion_percentage ?? 100" />
                        </div>

                        <x-ui.form.input name="remarks" label="Remarks" :value="$row?->remarks" />

                        <div class="flex justify-end gap-2">
                            <x-ui.button type="button" variant="ghost"
                                         x-on:click="$dispatch('close-modal', 'mark-student-topic-{{ $topic->id }}')">Cancel</x-ui.button>
                            <x-ui.button type="submit" variant="primary">Set for this student</x-ui.button>
                        </div>
                    </form>
                </x-ui.modal>
            @endforeach
        @endforeach
    @endcan
@endsection
