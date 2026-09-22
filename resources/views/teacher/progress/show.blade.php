@extends('layouts.panel')

@section('title', $batch->code.' — syllabus')

@section('header')
    <x-ui.page-header :title="$batch->code.' — syllabus'"
                      :subtitle="($batch->course?->name ?? '').' · marking a topic reaches every student, except anybody you have set individually'"
                      icon="chart-bar"
                      :back="route('teacher.batches.show', $batch)" />
@endsection

@section('content')
    @if ($modules->isEmpty())
        <x-ui.card>
            <x-ui.empty-state icon="chart-bar" title="This course has no outline"
                              description="There is nothing to mark until the course has modules and topics." />
        </x-ui.card>
    @else
        <x-ui.card :padded="false" title="Topic by topic"
                   subtitle="Mark a topic on the top row once the class has covered it. An amber dot is a value set for one student, which stays as it is.">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-500 dark:bg-slate-900/60 dark:text-slate-400">
                        <tr>
                            <th class="sticky left-0 z-10 bg-slate-50 px-4 py-3 text-left font-semibold dark:bg-slate-900">Student</th>
                            @foreach ($modules as $module)
                                @foreach ($module->topics as $topic)
                                    <th class="px-2 py-3 text-center font-normal" style="min-width:3rem;">
                                        <div class="truncate font-semibold text-slate-600 dark:text-slate-300" title="{{ $module->title }} — {{ $topic->title }}">
                                            {{ \Illuminate\Support\Str::limit($topic->title, 12) }}
                                        </div>
                                    </th>
                                @endforeach
                            @endforeach
                            <th class="px-3 py-3 text-center font-semibold">%</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-200/70 dark:divide-slate-800">
                        <tr class="bg-violet-50/40 dark:bg-violet-500/5">
                            <td class="sticky left-0 z-10 bg-violet-50/40 px-4 py-2.5 font-semibold text-slate-700 dark:bg-slate-900 dark:text-slate-200">
                                The class
                            </td>
                            @foreach ($modules as $module)
                                @foreach ($module->topics as $topic)
                                    @php($row = $coverage->get($topic->id))
                                    <td class="px-2 py-2.5 text-center">
                                        @can('teacher_portal.progress_mark')
                                            <button type="button" x-on:click="$dispatch('open-modal', 'teacher-topic-{{ $topic->id }}')"
                                                    class="rounded px-1 transition hover:bg-white dark:hover:bg-slate-800"
                                                    title="{{ $row?->status?->label() ?? 'Not started' }}">
                                                <x-ui.badge :color="$row?->status?->color() ?? 'slate'" size="xs">
                                                    {{ $row?->status?->glyph() ?? '·' }}
                                                </x-ui.badge>
                                            </button>
                                        @else
                                            <x-ui.badge :color="$row?->status?->color() ?? 'slate'" size="xs">
                                                {{ $row?->status?->glyph() ?? '·' }}
                                            </x-ui.badge>
                                        @endcan
                                    </td>
                                @endforeach
                            @endforeach
                            <td class="px-3 py-2.5 text-center font-semibold text-slate-700 dark:text-slate-200">
                                {{ app_number($batch->syllabus_completion_percentage) }}%
                            </td>
                        </tr>

                        @foreach ($enrollments as $enrollment)
                            @php($progress = $progressRows->get($enrollment->id))
                            @php($topicRows = $progress ? ($cells->get($progress->id) ?? collect())->keyBy('course_topic_id') : collect())
                            <tr>
                                <td class="sticky left-0 z-10 bg-white px-4 py-2.5 dark:bg-slate-900">
                                    <div class="font-medium text-slate-700 dark:text-slate-200">
                                        {{ $enrollment->roll_number ? $enrollment->roll_number.' · ' : '' }}{{ $enrollment->student?->name }}
                                    </div>
                                </td>

                                @foreach ($modules as $module)
                                    @foreach ($module->topics as $topic)
                                        @php($cell = $topicRows->get($topic->id))
                                        <td class="px-2 py-2.5 text-center">
                                            <span class="relative inline-block">
                                                <x-ui.badge :color="$cell?->status?->color() ?? 'slate'" size="xs">
                                                    {{ $cell?->status?->glyph() ?? '·' }}
                                                </x-ui.badge>
                                                @if ($cell?->isProtectedFromBatchMark())
                                                    <span class="absolute -right-0.5 -top-0.5 h-1.5 w-1.5 rounded-full bg-amber-500"
                                                          title="Set for this student"></span>
                                                @endif
                                            </span>
                                        </td>
                                    @endforeach
                                @endforeach

                                <td class="px-3 py-2.5 text-center text-sm font-medium text-slate-700 dark:text-slate-200">
                                    {{ app_number($progress?->completion_percentage ?? 0) }}%
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-200/70 px-4 py-3 text-xs text-slate-400 dark:border-slate-800">
                ● completed · ◐ in progress · — skipped · · not started.
            </div>
        </x-ui.card>

        @can('teacher_portal.progress_mark')
            @foreach ($modules as $module)
                @foreach ($module->topics as $topic)
                    @php($row = $coverage->get($topic->id))
                    <x-ui.modal name="teacher-topic-{{ $topic->id }}" :title="$topic->title" icon="check">
                        <form method="POST" action="{{ route('teacher.progress.store', [$batch, $topic]) }}" class="space-y-4">
                            @csrf
                            <p class="text-sm text-slate-600 dark:text-slate-300">
                                This records the topic for the whole class. Anybody whose row you set
                                individually keeps their own value.
                            </p>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-ui.form.select name="status" label="Status">
                                    @foreach ($statuses as $value => $label)
                                        @continue($value === 'skipped')
                                        <option value="{{ $value }}" @selected(($row?->status?->value ?? 'completed') === $value)>{{ $label }}</option>
                                    @endforeach
                                </x-ui.form.select>

                                <x-ui.form.input name="completion_percentage" label="Covered %" type="number" min="0" max="100" step="0.01"
                                                 :value="$row?->completion_percentage ?? 100" />
                            </div>

                            <x-ui.form.textarea name="notes" label="Notes" rows="2" :value="$row?->notes" />

                            <div class="flex justify-end gap-2">
                                <x-ui.button type="button" variant="ghost"
                                             x-on:click="$dispatch('close-modal', 'teacher-topic-{{ $topic->id }}')">Cancel</x-ui.button>
                                <x-ui.button type="submit" variant="primary">Record it</x-ui.button>
                            </div>
                        </form>
                    </x-ui.modal>
                @endforeach
            @endforeach
        @endcan
    @endif
@endsection
