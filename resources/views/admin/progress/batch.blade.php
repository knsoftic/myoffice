@extends('layouts.admin')

@section('title', $batch->code.' — progress')

@section('header')
    <x-ui.page-header :title="$batch->code.' — syllabus'"
                      :subtitle="($batch->course?->name ?? '').' · marking a topic for the class reaches every active student, except anybody whose row was set by hand'"
                      icon="chart-bar"
                      :back="route('admin.student-progress.index')">
        <x-slot:actions>
            <x-ui.button variant="ghost" icon="squares-2x2" :href="route('admin.batches.show', $batch)">Batch</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($modules->isEmpty())
        <x-ui.card>
            <x-ui.empty-state icon="chart-bar" title="This course has no outline, so there is nothing to track"
                              description="Add modules and topics to the course, then come back and mark them as the class covers them.">
                @can('course_outline.create')
                    <x-ui.button icon="plus" :href="route('admin.course-outline.index', $batch->course_id)">Add outline</x-ui.button>
                @endcan
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat-card label="Syllabus covered" :value="app_number($batch->syllabus_completion_percentage).'%'" icon="chart-bar" color="violet" />
            <x-ui.stat-card label="Topics" :value="app_number($modules->sum(fn ($m) => $m->topics->count()))" icon="queue-list" color="slate" />
            <x-ui.stat-card label="Students" :value="app_number($enrollments->count())" icon="users" color="brand" />
            <x-ui.stat-card label="Weighting" :value="$weighting === 'topic_weight' ? 'By weight' : 'Every topic equally'" icon="scale" color="sky" />
        </div>

        <x-ui.card :padded="false" title="The class, topic by topic"
                   subtitle="The top row is what the class has covered. Each cell below is one student — a dot marks a value somebody set by hand, which the class-level mark leaves alone.">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-500 dark:bg-slate-900/60 dark:text-slate-400">
                        <tr>
                            <th class="sticky left-0 z-10 bg-slate-50 px-4 py-3 text-left font-semibold dark:bg-slate-900">Student</th>
                            @foreach ($modules as $module)
                                @foreach ($module->topics as $topic)
                                    <th class="px-2 py-3 text-center font-normal" style="min-width:3rem;">
                                        <div class="truncate text-[10px] uppercase tracking-wide text-slate-400" title="{{ $module->title }}">
                                            {{ \Illuminate\Support\Str::limit($module->title, 10) }}
                                        </div>
                                        <div class="truncate font-semibold text-slate-600 dark:text-slate-300" title="{{ $topic->title }} (weight {{ $topic->weight }})">
                                            {{ \Illuminate\Support\Str::limit($topic->title, 12) }}
                                        </div>
                                    </th>
                                @endforeach
                            @endforeach
                            <th class="px-3 py-3 text-center font-semibold">%</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-200/70 dark:divide-slate-800">
                        {{-- The class-level row: this is what a teacher touches. --}}
                        <tr class="bg-violet-50/40 dark:bg-violet-500/5">
                            <td class="sticky left-0 z-10 bg-violet-50/40 px-4 py-2.5 font-semibold text-slate-700 dark:bg-slate-900 dark:text-slate-200">
                                The class
                            </td>
                            @foreach ($modules as $module)
                                @foreach ($module->topics as $topic)
                                    @php($row = $coverage->get($topic->id))
                                    <td class="px-2 py-2.5 text-center">
                                        @can('student_progress.create')
                                            <button type="button" x-on:click="$dispatch('open-modal', 'mark-topic-{{ $topic->id }}')"
                                                    class="rounded px-1 transition hover:bg-white dark:hover:bg-slate-800"
                                                    title="{{ $row?->status?->label() ?? 'Not started' }}{{ $row?->covered_on ? ' · '.app_date($row->covered_on) : '' }}">
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
                                    <a href="{{ route('admin.student-progress.student', $enrollment) }}"
                                       class="font-medium text-slate-700 hover:underline dark:text-slate-200">
                                        {{ $enrollment->roll_number ? $enrollment->roll_number.' · ' : '' }}{{ $enrollment->student?->name }}
                                    </a>
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
                                                    {{-- Set by hand: the class-level mark will not overwrite it. --}}
                                                    <span class="absolute -right-0.5 -top-0.5 h-1.5 w-1.5 rounded-full bg-amber-500"
                                                          title="Set for this student ({{ $cell->source->label() }})"></span>
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
                An amber dot means a value was set for that student by hand and the class-level mark
                leaves it alone.
            </div>
        </x-ui.card>

        <x-ui.card title="By module" class="mt-4">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($modules as $module)
                    @php($covered = $module->topics->filter(fn ($t) => $coverage->get($t->id)?->status?->isDone()))
                    @php($share = $module->topics->count() > 0 ? ($covered->count() / $module->topics->count()) * 100 : 0)
                    <div>
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="truncate text-sm font-medium text-slate-700 dark:text-slate-200">{{ $module->title }}</span>
                            <span class="text-xs text-slate-500">{{ $covered->count() }}/{{ $module->topics->count() }}</span>
                        </div>
                        <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                            <div class="h-full rounded-full bg-violet-500" style="width: {{ $share }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        @can('student_progress.create')
            @foreach ($modules as $module)
                @foreach ($module->topics as $topic)
                    @php($row = $coverage->get($topic->id))
                    <x-ui.modal name="mark-topic-{{ $topic->id }}" :title="$topic->title" icon="check" size="lg">
                        <form method="POST" action="{{ route('admin.student-progress.batch.topic', [$batch, $topic]) }}" class="space-y-4">
                            @csrf
                            <p class="text-sm text-slate-600 dark:text-slate-300">
                                This reaches every active student in the batch — except anybody whose row for
                                this topic was set by hand, which stays as it is.
                            </p>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-ui.form.select name="status" label="Status">
                                    @foreach ($statuses as $value => $label)
                                        @continue($value === 'skipped')
                                        <option value="{{ $value }}" @selected(($row?->status?->value ?? 'completed') === $value)>{{ $label }}</option>
                                    @endforeach
                                </x-ui.form.select>

                                <x-ui.form.input name="completion_percentage" label="Covered %" type="number" min="0" max="100" step="0.01"
                                                 :value="$row?->completion_percentage ?? 100"
                                                 help="A class that ran out of time can record a partial figure." />

                                <x-ui.form.input name="covered_on" label="Covered on" type="date"
                                                 :value="$row?->covered_on?->toDateString() ?? now()->toDateString()" />

                                <x-ui.form.select name="class_session_id" label="In which class" placeholder="Not tied to a class">
                                    @foreach ($sessions as $session)
                                        <option value="{{ $session->id }}" @selected((int) ($row?->class_session_id) === (int) $session->id)>
                                            {{ app_date($session->session_date) }} {{ app_clock($session->start_time) }}
                                        </option>
                                    @endforeach
                                </x-ui.form.select>
                            </div>

                            <x-ui.form.textarea name="notes" label="Notes" rows="2" :value="$row?->notes" />

                            <div class="flex items-center justify-between gap-2">
                                @can('student_progress.change_status')
                                    <x-ui.button type="button" variant="ghost" size="sm"
                                                 x-on:click="$dispatch('close-modal', 'mark-topic-{{ $topic->id }}'); $dispatch('open-modal', 'skip-topic-{{ $topic->id }}')">
                                        Drop this topic
                                    </x-ui.button>
                                @else
                                    <span></span>
                                @endcan

                                <div class="flex gap-2">
                                    <x-ui.button type="button" variant="ghost"
                                                 x-on:click="$dispatch('close-modal', 'mark-topic-{{ $topic->id }}')">Cancel</x-ui.button>
                                    <x-ui.button type="submit" variant="primary">Record it</x-ui.button>
                                </div>
                            </div>
                        </form>
                    </x-ui.modal>

                    @can('student_progress.change_status')
                        <x-ui.modal name="skip-topic-{{ $topic->id }}" :title="'Drop '.$topic->title.'?'" icon="minus-circle">
                            <form method="POST" action="{{ route('admin.student-progress.batch.skip', [$batch, $topic]) }}" class="space-y-4">
                                @csrf
                                <p class="text-sm text-slate-600 dark:text-slate-300">
                                    Its weight leaves every denominator, so this <span class="font-medium">raises</span>
                                    every student's percentage — which is what should happen when work comes out
                                    of a syllabus. Say why, so the number can be explained later.
                                </p>
                                <x-ui.form.textarea name="reason" label="Why?" required rows="2" />
                                <div class="flex justify-end gap-2">
                                    <x-ui.button type="button" variant="ghost"
                                                 x-on:click="$dispatch('close-modal', 'skip-topic-{{ $topic->id }}')">Keep it</x-ui.button>
                                    <x-ui.button type="submit" variant="secondary">Drop the topic</x-ui.button>
                                </div>
                            </form>
                        </x-ui.modal>
                    @endcan
                @endforeach
            @endforeach
        @endcan
    @endif
@endsection
