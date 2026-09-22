@extends('layouts.admin')

@section('title', 'Engagement — '.$material->title)

@section('header')
    <x-ui.page-header :title="$material->title"
                      subtitle="Who opened it, and when. Every row was written before the bytes moved, so a download that failed half way is still here."
                      icon="chart-bar">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.course-materials.show', $material)">Back</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$log->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">When</th>
                <th class="px-4 py-3 text-left font-semibold">Who</th>
                <th class="px-4 py-3 text-left font-semibold">Panel</th>
                <th class="px-4 py-3 text-left font-semibold">What</th>
                <th class="px-4 py-3 text-right font-semibold">Sent</th>
            </x-slot:head>

            @foreach ($log as $entry)
                <tr>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ app_datetime($entry->created_at) }}</td>
                    <td class="px-4 py-3">
                        <div class="text-sm text-slate-700 dark:text-slate-200">
                            {{ $entry->student?->name ?? $entry->user?->name ?? 'Someone' }}
                        </div>
                        @if ($entry->student)
                            <div class="text-xs text-slate-400">{{ $entry->student->student_code }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $entry->panel->label() }}</td>
                    <td class="px-4 py-3"><x-ui.badge :color="$entry->action->color()" size="xs">{{ $entry->action->label() }}</x-ui.badge></td>
                    <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-600 dark:text-slate-300">
                        @if ($entry->bytes_sent === null)
                            <span class="text-slate-400" title="The stream did not finish">—</span>
                        @else
                            {{ app_number($entry->bytes_sent / 1024, 0) }} KB
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="chart-bar" title="Nobody has opened it"
                                  description="Either it has only just been published, or the audience has not looked yet." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$log" label="opens" />
    </x-ui.card>
@endsection
