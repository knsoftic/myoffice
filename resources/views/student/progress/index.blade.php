@extends('layouts.panel')

@section('title', 'My progress')

@section('header')
    <x-ui.page-header title="My progress"
                      subtitle="How far through the syllabus you are, module by module."
                      icon="chart-bar" />
@endsection

@section('content')
    @if ($enrollments->isEmpty())
        <x-ui.card>
            <x-ui.empty-state icon="chart-bar" title="Nothing to show yet"
                              description="Once you are seated in a batch, your syllabus appears here." />
        </x-ui.card>
    @else
        @foreach ($enrollments as $enrollment)
            @php($progress = $progressRows->get($enrollment->id))
            @php($courseModules = $modules->get($enrollment->course_id) ?? collect())
            @php($moduleRows = $progress?->moduleProgress->keyBy('course_module_id') ?? collect())
            @php($topicRows = $progress?->topicProgress->keyBy('course_topic_id') ?? collect())

            <x-ui.card class="mb-4"
                       :title="($enrollment->batch?->code ?? '').' · '.($enrollment->course?->name ?? '')"
                       :subtitle="$progress ? $progress->topicsCaption() : 'not started'">
                <x-slot:actions>
                    <span class="text-2xl font-semibold text-slate-800 dark:text-slate-100">
                        {{ app_number($progress?->completion_percentage ?? 0) }}<span class="text-sm">%</span>
                    </span>
                </x-slot:actions>

                <div class="mb-4 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                    <div class="h-full rounded-full bg-brand-500"
                         style="width: {{ min(100, (float) ($progress?->completion_percentage ?? 0)) }}%"></div>
                </div>

                @if ($courseModules->isEmpty())
                    <p class="text-sm text-slate-500">This course has no outline yet, so there is nothing to track.</p>
                @else
                    <div class="space-y-4">
                        @foreach ($courseModules as $module)
                            @php($moduleRow = $moduleRows->get($module->id))
                            <div>
                                <div class="flex items-baseline justify-between gap-2">
                                    <span class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $module->title }}</span>
                                    <span class="text-xs text-slate-500">
                                        {{ app_number($moduleRow?->completion_percentage ?? 0) }}%
                                    </span>
                                </div>

                                <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                                    <div class="h-full rounded-full bg-violet-500"
                                         style="width: {{ min(100, (float) ($moduleRow?->completion_percentage ?? 0)) }}%"></div>
                                </div>

                                <ul class="mt-2 space-y-1">
                                    @foreach ($module->topics as $topic)
                                        @php($row = $topicRows->get($topic->id))
                                        <li class="flex items-center justify-between gap-2 text-sm">
                                            <span class="flex min-w-0 items-center gap-2">
                                                <x-ui.badge :color="$row?->status?->color() ?? 'slate'" size="xs">
                                                    {{ $row?->status?->glyph() ?? '·' }}
                                                </x-ui.badge>
                                                <span class="truncate text-slate-600 dark:text-slate-300">{{ $topic->title }}</span>
                                            </span>
                                            <span class="shrink-0 text-xs text-slate-400">
                                                {{ $row?->status?->label() ?? 'Not started' }}
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>
        @endforeach
    @endif
@endsection
