@extends('layouts.panel')

@section('title', $batch->code)

@section('header')
    <x-ui.page-header :title="$batch->code"
                      :subtitle="$batch->name.' · '.($batch->course?->name ?? 'no course')"
                      icon="squares-2x2"
                      :badge="$batch->status->label()"
                      :badge-color="$batch->status->color()"
                      :back="route('teacher.batches.index')" />
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="Your students" :subtitle="$roster->count().' on the roster'" :padded="false">
                <x-ui.table :is-empty="$roster->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Roll</th>
                        <th class="px-4 py-3 text-left font-semibold">Student</th>
                        <th class="px-4 py-3 text-left font-semibold">Enrolled</th>
                    </x-slot:head>

                    @foreach ($roster as $enrollment)
                        <tr>
                            <td class="px-4 py-3 text-sm font-medium text-slate-700 dark:text-slate-200">{{ $enrollment->roll_number ?: '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <x-ui.avatar :name="$enrollment->student?->name" size="sm" />
                                    <div>
                                        <div class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $enrollment->student?->name ?? 'Unknown' }}</div>
                                        <div class="text-xs text-slate-400">{{ $enrollment->student?->student_code }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ app_date($enrollment->enrolled_on) }}</td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="users" title="Nobody enrolled yet"
                                          description="Students appear here as they are seated in this batch." />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="Next classes" :padded="false">
                <x-ui.table :is-empty="$upcoming->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">When</th>
                        <th class="px-4 py-3 text-left font-semibold">Class</th>
                        <th class="px-4 py-3 text-left font-semibold">Room</th>
                    </x-slot:head>

                    @foreach ($upcoming as $session)
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('teacher.sessions.show', $session) }}"
                                   class="font-medium text-slate-700 hover:underline dark:text-slate-200">{{ app_date($session->session_date) }}</a>
                                <div class="text-xs text-slate-400">{{ app_time($session->startsAt()) }} – {{ app_time($session->endsAt()) }}</div>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $session->displayTitle() }}</td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $session->classroom?->code ?? '—' }}</td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="calendar-days" title="Nothing scheduled"
                                          description="Classes appear once the batch has a timetable." />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>
        </div>

        <x-ui.card title="When it meets">
            @if ($entries->isEmpty())
                <p class="text-sm text-slate-500">No timetable set for this batch yet.</p>
            @else
                <ul class="space-y-2 text-sm">
                    @foreach ($entries as $entry)
                        <li class="flex items-center justify-between gap-2">
                            <span class="text-slate-600 dark:text-slate-300">{{ $entry->day_of_week->label() }}</span>
                            <span class="font-medium text-slate-700 dark:text-slate-200">
                                {{ \Illuminate\Support\Carbon::parse($entry->start_time)->format('H:i') }}
                                – {{ \Illuminate\Support\Carbon::parse($entry->end_time)->format('H:i') }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <dl class="mt-4 space-y-3 border-t border-slate-200/70 pt-4 text-sm dark:border-slate-800">
                <div>
                    <dt class="text-slate-400">Room</dt>
                    <dd class="text-slate-700 dark:text-slate-200">{{ $batch->classroom?->label() ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-400">Mode</dt>
                    <dd><x-ui.badge :color="$batch->delivery_mode->color()" size="xs">{{ $batch->delivery_mode->label() }}</x-ui.badge></dd>
                </div>
                <div>
                    <dt class="text-slate-400">Classes held</dt>
                    <dd class="text-slate-700 dark:text-slate-200">{{ app_number($batch->sessions_held_count) }}</dd>
                </div>
            </dl>
        </x-ui.card>
    </div>
@endsection
