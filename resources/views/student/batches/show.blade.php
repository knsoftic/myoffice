@extends('layouts.panel')

@section('title', $batch?->code ?? 'My batch')

@section('header')
    <x-ui.page-header :title="$batch?->code ?? 'My batch'"
                      :subtitle="($batch?->name ?? '').' · '.($enrollment->course?->name ?? '')"
                      icon="squares-2x2"
                      :badge="$enrollment->status->label()"
                      :badge-color="$enrollment->status->color()"
                      :back="route('student.timetable.index')" />
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <x-ui.stat-card label="Roll number" :value="$enrollment->roll_number ?: '—'" icon="identification" color="brand" />
        <x-ui.stat-card label="Enrolled" :value="app_date($enrollment->enrolled_on)" icon="calendar-days" color="slate" />
        <x-ui.stat-card label="Classes held" :value="app_number($batch?->sessions_held_count ?? 0)" icon="check" color="emerald" />
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="Next classes" :padded="false">
                <x-ui.table :is-empty="$upcoming->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">When</th>
                        <th class="px-4 py-3 text-left font-semibold">Class</th>
                        <th class="px-4 py-3 text-left font-semibold">Status</th>
                    </x-slot:head>

                    @foreach ($upcoming as $session)
                        <tr>
                            <td class="px-4 py-3">
                                <div class="font-medium text-slate-700 dark:text-slate-200">{{ app_date($session->session_date) }}</div>
                                <div class="text-xs text-slate-400">{{ app_time($session->startsAt()) }} – {{ app_time($session->endsAt()) }}</div>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $session->displayTitle() }}</td>
                            <td class="px-4 py-3"><x-ui.badge :color="$session->status->color()" size="xs">{{ $session->status->label() }}</x-ui.badge></td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="calendar-days" title="Nothing scheduled yet"
                                          description="Classes appear here once this batch has a timetable." />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>
        </div>

        <div class="space-y-4">
            <x-ui.card title="When it meets">
                @if ($entries->isEmpty())
                    <p class="text-sm text-slate-500">No timetable set for this batch yet.</p>
                @else
                    <ul class="space-y-2 text-sm">
                        @foreach ($entries as $entry)
                            <li class="flex items-start justify-between gap-2">
                                <span class="text-slate-600 dark:text-slate-300">{{ $entry->day_of_week->label() }}</span>
                                <span class="text-right">
                                    <span class="font-medium text-slate-700 dark:text-slate-200">
                                        {{ app_clock($entry->start_time) }}
                                        – {{ app_clock($entry->end_time) }}
                                    </span>
                                    @if ($entry->classroom)
                                        <span class="block text-xs text-slate-400">{{ $entry->classroom->name }}</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            @if ($batch?->teacher)
                <x-ui.card title="Your teacher">
                    <div class="flex items-start gap-3">
                        <x-ui.avatar :name="$batch->teacher->name" size="md" />
                        <div>
                            <div class="font-medium text-slate-700 dark:text-slate-200">{{ $batch->teacher->name }}</div>
                            @if (filled($batch->teacher->public_bio))
                                <p class="mt-1 text-sm text-slate-500">{{ $batch->teacher->public_bio }}</p>
                            @endif
                        </div>
                    </div>
                </x-ui.card>
            @endif

            @if ($batch && filled($batch->meeting_url))
                <x-ui.card title="Joining link">
                    <a href="{{ $batch->meeting_url }}" target="_blank" rel="noopener"
                       class="break-all text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $batch->meeting_url }}</a>
                </x-ui.card>
            @endif
        </div>
    </div>
@endsection
