@extends('layouts.panel')

@section('title', $session->displayTitle())

@section('header')
    <x-ui.page-header :title="$session->displayTitle()"
                      :subtitle="($session->batch?->code ?? '—').' · '.app_date($session->session_date).' · '.app_time($session->startsAt()).'–'.app_time($session->endsAt())"
                      icon="calendar-days"
                      :badge="$session->status->label()"
                      :badge-color="$session->status->color()"
                      :back="route('teacher.timetable.index')" />
@endsection

@section('content')
    @if ($session->status->value === 'cancelled')
        <x-ui.card class="mb-4 border-rose-200 dark:border-rose-500/30">
            <p class="text-sm text-slate-600 dark:text-slate-300">
                <span class="font-medium">This class was cancelled.</span>
                {{ $session->cancellation_detail }}
            </p>
        </x-ui.card>
    @endif

    @if ($session->wasTaughtBySubstitute())
        <x-ui.card class="mb-4 border-amber-200 dark:border-amber-500/30">
            <p class="text-sm text-slate-600 dark:text-slate-300">
                This class was handed over. It was scheduled for {{ $session->originalTeacher?->name ?? 'somebody else' }}.
            </p>
        </x-ui.card>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <x-ui.card title="Who is expected" :subtitle="$roster->count().' on the roster that day'"
                   class="lg:col-span-2" :padded="false">
            <x-ui.table :is-empty="$roster->isEmpty()">
                <x-slot:head>
                    <th class="px-4 py-3 text-left font-semibold">Roll</th>
                    <th class="px-4 py-3 text-left font-semibold">Student</th>
                </x-slot:head>

                @foreach ($roster as $enrollment)
                    <tr>
                        <td class="px-4 py-3 text-sm font-medium text-slate-700 dark:text-slate-200">{{ $enrollment->roll_number ?: '—' }}</td>
                        <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $enrollment->student?->name ?? 'Unknown' }}</td>
                    </tr>
                @endforeach

                <x-slot:empty>
                    <x-ui.empty-state icon="users" title="Nobody was enrolled on that date"
                                      description="The roster is read as it stood on the day of the class." />
                </x-slot:empty>
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="This class">
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-slate-400">Batch</dt>
                    <dd>
                        <a href="{{ route('teacher.batches.show', $session->batch_id) }}"
                           class="text-slate-700 hover:underline dark:text-slate-200">{{ $session->batch?->code ?? '—' }}</a>
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-400">Course</dt>
                    <dd class="text-slate-700 dark:text-slate-200">{{ $session->course?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-400">Room</dt>
                    <dd class="text-slate-700 dark:text-slate-200">{{ $session->classroom?->label() ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-400">Mode</dt>
                    <dd><x-ui.badge :color="$session->delivery_mode->color()" size="xs">{{ $session->delivery_mode->label() }}</x-ui.badge></dd>
                </div>
                @if ($session->topic)
                    <div>
                        <dt class="text-slate-400">Topic</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $session->topic->title }}</dd>
                    </div>
                @endif
                <div>
                    <dt class="text-slate-400">Register</dt>
                    <dd class="text-slate-700 dark:text-slate-200">
                        {{ $session->isAttendanceMarked() ? app_datetime($session->attendance_marked_at) : 'Not taken yet' }}
                    </dd>
                </div>
            </dl>

            @if (filled($session->meeting_url))
                <div class="mt-4 border-t border-slate-200/70 pt-4 dark:border-slate-800">
                    <a href="{{ $session->meeting_url }}" target="_blank" rel="noopener"
                       class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">Join the class</a>
                </div>
            @endif
        </x-ui.card>
    </div>
@endsection
