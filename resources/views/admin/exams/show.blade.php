@extends('layouts.admin')

@section('title', $exam->name)

@section('header')
    <x-ui.page-header :title="$exam->name" icon="document-chart-bar"
                      :subtitle="$exam->exam_type->label().' · '.($exam->batch?->code ?? 'no batch').' · '.app_date($exam->scheduled_date)">
        <x-slot:actions>
            @if ($canEnterResults && $exam->acceptsResultEntry())
                <x-ui.button icon="pencil-square" :href="route('admin.exam-results.sheet', $exam)">Enter marks</x-ui.button>
            @elseif ($exam->isPublished())
                <x-ui.button variant="secondary" icon="trophy" :href="route('admin.exam-results.sheet', $exam)">See the results</x-ui.button>
            @endif

            @if ($canEdit)
                <x-ui.button variant="secondary" icon="pencil" :href="route('admin.exams.edit', $exam)">Edit</x-ui.button>
            @endif

            @if ($canDelete)
                <x-ui.confirm :action="route('admin.exams.destroy', $exam)"
                              title="Remove this exam?"
                              message="Nobody has a result against it, so it can go. Once anybody is marked, an exam is cancelled with a reason instead."
                              confirm-label="Remove exam">
                    <x-slot:trigger>
                        <x-ui.icon-button icon="trash" label="Remove exam" variant="danger" />
                    </x-slot:trigger>
                </x-ui.confirm>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 grid gap-6">
            <x-ui.card>
                <x-ui.section-heading title="The paper" />

                <dl class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Status</dt>
                        <dd class="mt-1"><x-ui.badge :color="$exam->status->color()" :dot="true">{{ $exam->status->label() }}</x-ui.badge></dd>
                        <p class="mt-1 text-xs text-slate-400">{{ $exam->status->description() }}</p>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Out of</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-700 dark:text-slate-200">{{ app_number($exam->total_marks, 2) }}</dd>
                        <p class="mt-1 text-xs text-slate-400">pass at {{ app_number($exam->passing_marks, 2) }}</p>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Grade scale</dt>
                        <dd class="mt-1 text-sm text-slate-700 dark:text-slate-200">
                            @if ($scale)
                                {{ $scale->code }}
                                @unless ($exam->grade_scale_id)
                                    <span class="text-xs text-slate-400">(the institute's default)</span>
                                @endunless
                            @else
                                <span class="text-rose-500">None resolved</span>
                            @endif
                        </dd>
                        @unless ($scale)
                            <p class="mt-1 text-xs text-rose-500">Marks cannot be graded until this exam names a scale, or the institute sets a default.</p>
                        @endunless
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">When</dt>
                        <dd class="mt-1 text-sm text-slate-700 dark:text-slate-200">
                            {{ app_date($exam->scheduled_date) }}
                            @if ($exam->start_time)
                                <span class="text-slate-400">{{ app_time($exam->start_time) }}@if ($exam->end_time) – {{ app_time($exam->end_time) }}@endif</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Where</dt>
                        <dd class="mt-1 text-sm text-slate-700 dark:text-slate-200">
                            {{ $exam->delivery_mode->label() }}
                            @if ($exam->classroom)
                                <span class="text-slate-400">· {{ $exam->classroom->code }}</span>
                            @endif
                        </dd>
                        @if ($exam->meeting_url)
                            <a href="{{ $exam->meeting_url }}" rel="noopener noreferrer" target="_blank"
                               class="mt-1 block truncate text-xs text-brand-600 hover:underline dark:text-brand-400">{{ $exam->meeting_url }}</a>
                        @endif
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Examiner</dt>
                        <dd class="mt-1 text-sm text-slate-700 dark:text-slate-200">{{ $exam->teacher?->name ?? '—' }}</dd>
                        @if ($exam->topic)
                            <p class="mt-1 text-xs text-slate-400">assesses “{{ $exam->topic->title }}”</p>
                        @endif
                    </div>
                </dl>

                @if ($exam->instructions)
                    <div class="mt-6 border-t border-slate-100 pt-4 dark:border-slate-700/60">
                        <h4 class="text-xs uppercase tracking-wide text-slate-400">Instructions</h4>
                        <p class="mt-2 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $exam->instructions }}</p>
                    </div>
                @endif

                @if ($exam->cancellation_reason)
                    <div class="mt-6 rounded-lg bg-rose-50 p-4 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                        <span class="font-medium">Called off:</span> {{ $exam->cancellation_reason }}
                    </div>
                @endif
            </x-ui.card>

            @if ($exam->results_entered_count > 0)
                <x-ui.card>
                    <x-ui.section-heading title="How the class did"
                                          description="Re-derived from the rows every time a sheet is saved — never edited by hand." />

                    <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
                        <x-ui.stat-card label="On the roster" :value="app_number($exam->expected_count)" color="slate" />
                        <x-ui.stat-card label="Sat it" :value="app_number($exam->appeared_count)" color="sky" />
                        <x-ui.stat-card label="Absent" :value="app_number($exam->absent_count)" color="amber" />
                        <x-ui.stat-card label="Passed" :value="app_number($exam->passed_count)" color="emerald" />
                        <x-ui.stat-card label="Failed" :value="app_number($exam->failed_count)" color="rose" />
                        <x-ui.stat-card label="Average"
                                        :value="$exam->average_percentage === null ? '—' : app_number($exam->average_percentage, 2).'%'"
                                        color="indigo" />
                    </div>

                    @if ($exam->highest_marks !== null)
                        <p class="mt-4 text-xs text-slate-400">
                            Highest {{ app_number($exam->highest_marks, 2) }} · lowest {{ app_number($exam->lowest_marks, 2) }}
                            · mean {{ app_number($exam->average_marks, 2) }} out of {{ app_number($exam->total_marks, 2) }}
                        </p>
                    @endif
                </x-ui.card>
            @endif
        </div>

        <div class="grid gap-6">
            @if ($canChangeStatus)
                <x-ui.card>
                    <x-ui.section-heading title="Where it has got to" />

                    <div class="grid gap-2">
                        @if ($exam->status === App\Enums\ExamStatus::Draft)
                            <form method="POST" action="{{ route('admin.exams.status', $exam) }}">
                                @csrf
                                <input type="hidden" name="status" value="scheduled">
                                <x-ui.button type="submit" icon="calendar-days" class="w-full">Schedule it</x-ui.button>
                            </form>
                            <p class="text-xs text-slate-400">Checks the batch, the examiner and the room against everything else booked then.</p>
                        @endif

                        @if ($exam->status === App\Enums\ExamStatus::Scheduled)
                            <form method="POST" action="{{ route('admin.exams.status', $exam) }}">
                                @csrf
                                <input type="hidden" name="status" value="ongoing">
                                <x-ui.button type="submit" variant="secondary" icon="play" class="w-full">It is under way</x-ui.button>
                            </form>
                        @endif

                        @if (in_array($exam->status, [App\Enums\ExamStatus::Scheduled, App\Enums\ExamStatus::Ongoing], true))
                            <form method="POST" action="{{ route('admin.exams.status', $exam) }}">
                                @csrf
                                <input type="hidden" name="status" value="conducted">
                                <x-ui.button type="submit" icon="check" class="w-full">It happened — open the sheet</x-ui.button>
                            </form>
                        @endif

                        @if (! $exam->status->isTerminal() && $exam->status !== App\Enums\ExamStatus::Conducted && $exam->status !== App\Enums\ExamStatus::Marking)
                            <x-ui.button variant="ghost" icon="x-circle" class="w-full"
                                         x-on:click="$dispatch('open-modal', 'cancel-exam')">Call it off</x-ui.button>
                        @endif
                    </div>
                </x-ui.card>

                <x-ui.modal name="cancel-exam" title="Call off this exam?" icon="x-circle">
                    <form method="POST" action="{{ route('admin.exams.status', $exam) }}">
                        @csrf
                        <input type="hidden" name="status" value="cancelled">

                        <p class="mb-4 text-sm text-slate-600 dark:text-slate-300">
                            The exam keeps its date and stays visible to the batch, marked as called off — the slot it
                            held is released for a replacement. Nothing is deleted.
                        </p>

                        <x-ui.form.textarea name="reason" label="Why" rows="3" required
                                            help="Shown to the batch. Say enough that nobody has to ask." />

                        <div class="mt-4 flex justify-end gap-2">
                            <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'cancel-exam')">Keep it</x-ui.button>
                            <x-ui.button type="submit" variant="danger" icon="x-circle">Call it off</x-ui.button>
                        </div>
                    </form>
                </x-ui.modal>

                @unless ($exam->status->isTerminal())
                    <x-ui.card>
                        <x-ui.section-heading title="Move it"
                                              description="Clash-checked again, and recorded with the reason." />

                        <form method="POST" action="{{ route('admin.exams.reschedule', $exam) }}" class="grid gap-3">
                            @csrf

                            <x-ui.form.input type="date" name="scheduled_date" label="New date" required
                                             :value="app_input_date($exam->scheduled_date)" />

                            <div class="grid grid-cols-2 gap-3">
                                <x-ui.form.input type="time" name="start_time" label="Starts"
                                                 :value="app_time($exam->start_time, 'H:i')" />
                                <x-ui.form.input type="time" name="end_time" label="Ends"
                                                 :value="app_time($exam->end_time, 'H:i')" />
                            </div>

                            <x-ui.form.select name="classroom_id" label="Room" placeholder="No room">
                                @foreach ($classrooms as $classroom)
                                    <option value="{{ $classroom->id }}" @selected((int) $exam->classroom_id === (int) $classroom->id)>
                                        {{ $classroom->code }}
                                    </option>
                                @endforeach
                            </x-ui.form.select>

                            <x-ui.form.textarea name="reason" label="Why it is moving" rows="2" required />

                            <x-ui.button type="submit" variant="secondary" icon="arrow-path" class="w-full">Move the exam</x-ui.button>
                        </form>
                    </x-ui.card>
                @endunless
            @endif

            @if ($exam->results_verified_at || $exam->results_published_at)
                <x-ui.card>
                    <x-ui.section-heading title="The paper trail" />

                    <dl class="grid gap-3 text-sm">
                        @if ($exam->results_verified_at)
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-slate-400">Checked</dt>
                                <dd class="text-slate-700 dark:text-slate-200">
                                    {{ app_datetime($exam->results_verified_at) }}
                                    <span class="text-slate-400">by {{ $exam->verifier?->name ?? 'somebody since removed' }}</span>
                                </dd>
                            </div>
                        @endif
                        @if ($exam->results_published_at)
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-slate-400">Published</dt>
                                <dd class="text-slate-700 dark:text-slate-200">
                                    {{ app_datetime($exam->results_published_at) }}
                                    <span class="text-slate-400">by {{ $exam->publisher?->name ?? 'somebody since removed' }}</span>
                                </dd>
                            </div>
                        @endif
                    </dl>

                    @if ($exam->isPublished())
                        <x-ui.button variant="ghost" icon="printer" class="mt-4 w-full"
                                     :href="route('admin.result-cards.index', $exam)">Result cards</x-ui.button>
                    @endif
                </x-ui.card>
            @endif
        </div>
    </div>
@endsection
