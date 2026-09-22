@extends('layouts.panel')

@section('title', 'Attendance')

@section('header')
    <x-ui.page-header title="Attendance"
                      subtitle="Registers still to take, and the ones you have taken. Nothing is filled in for you — who was in the room is yours to say."
                      icon="clipboard-document-check" />
@endsection

@section('content')
    @if ($unmarked->isNotEmpty())
        <x-ui.card class="mb-4 border-amber-200 dark:border-amber-500/30">
            <div class="flex items-start gap-3">
                <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
                <div class="min-w-0 flex-1">
                    <p class="font-medium text-slate-700 dark:text-slate-200">
                        {{ $unmarked->count() }} {{ \Illuminate\Support\Str::plural('class', $unmarked->count()) }} without a register
                    </p>
                    <p class="mt-1 text-sm text-slate-500">
                        An unmarked class is missing from every attendance report until it is taken.
                    </p>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card title="To take" class="mb-4" :padded="false">
            <x-ui.table>
                <x-slot:head>
                    <th class="px-4 py-3 text-left font-semibold">When</th>
                    <th class="px-4 py-3 text-left font-semibold">Batch</th>
                    <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                </x-slot:head>

                @foreach ($unmarked as $session)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-medium text-slate-700 dark:text-slate-200">{{ app_date($session->session_date) }}</div>
                            <div class="text-xs text-slate-400">{{ app_clock($session->start_time) }} – {{ app_clock($session->end_time) }}</div>
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $session->batch_code }}</td>
                        <td class="px-4 py-3 text-right">
                            <x-ui.button variant="primary" size="sm" icon="clipboard-document-check"
                                         :href="route('teacher.attendance.mark', $session->id)">Take the register</x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    <x-ui.card title="Recently taken" :padded="false">
        <x-ui.table :is-empty="$recent->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">When</th>
                <th class="px-4 py-3 text-left font-semibold">Batch</th>
                <th class="px-4 py-3 text-left font-semibold">P / A / L / Lt</th>
                <th class="px-4 py-3 text-left font-semibold">Taken</th>
            </x-slot:head>

            @foreach ($recent as $session)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('teacher.attendance.mark', $session) }}"
                           class="font-medium text-slate-700 hover:underline dark:text-slate-200">{{ app_date($session->session_date) }}</a>
                        <div class="text-xs text-slate-400">{{ app_clock($session->start_time) }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $session->batch?->code }}</td>
                    <td class="px-4 py-3 text-sm">
                        <span class="text-emerald-600 dark:text-emerald-400">{{ app_number($session->present_count) }}</span>
                        <span class="text-slate-300">/</span>
                        <span class="text-rose-600 dark:text-rose-400">{{ app_number($session->absent_count) }}</span>
                        <span class="text-slate-300">/</span>
                        <span class="text-sky-600 dark:text-sky-400">{{ app_number($session->leave_count) }}</span>
                        <span class="text-slate-300">/</span>
                        <span class="text-amber-600 dark:text-amber-400">{{ app_number($session->late_count) }}</span>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ app_datetime($session->attendance_marked_at) }}</td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="clipboard-document-check" title="No registers yet"
                                  description="Once you take one, it appears here." />
            </x-slot:empty>
        </x-ui.table>
    </x-ui.card>
@endsection
