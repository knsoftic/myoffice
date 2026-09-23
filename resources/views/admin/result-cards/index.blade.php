@extends('layouts.admin')

@section('title', 'Result cards — '.$exam->name)

@section('header')
    <x-ui.page-header :title="'Result cards — '.$exam->name" icon="printer"
                      :subtitle="($exam->batch?->code ?? 'no batch').' · '.app_date($exam->scheduled_date)">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.exam-results.sheet', $exam)">Back to the sheet</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @unless ($published)
        <x-ui.card class="mb-4 border-amber-200 dark:border-amber-500/30">
            <div class="flex gap-3">
                <x-ui.icon name="exclamation-triangle" class="h-5 w-5 shrink-0 text-amber-500" />
                <div class="text-sm text-slate-600 dark:text-slate-300">
                    <p class="font-medium text-slate-700 dark:text-slate-200">These results are not published.</p>
                    <p class="mt-1">
                        A card is a document that leaves the building. Printing an unchecked mark and correcting it
                        afterwards is how an institute ends up with two versions of a student's record in circulation,
                        so there is nothing to print until the sheet goes out.
                    </p>
                </div>
            </div>
        </x-ui.card>
    @endunless

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$results->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Position</th>
                <th class="px-4 py-3 text-left font-semibold">Student</th>
                <th class="px-4 py-3 text-right font-semibold">Marks</th>
                <th class="px-4 py-3 text-left font-semibold">Grade</th>
                <th class="px-4 py-3 text-right font-semibold"></th>
            </x-slot:head>

            @foreach ($results as $result)
                <tr>
                    <td class="px-4 py-3 text-sm tabular-nums text-slate-500 dark:text-slate-400">
                        {{ app_ordinal($result->position_in_batch) }}
                    </td>
                    <td class="px-4 py-3">
                        <div class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $result->student?->name ?? '—' }}</div>
                        <div class="text-xs text-slate-400">{{ $result->student?->student_code }}</div>
                    </td>
                    <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-600 dark:text-slate-300">
                        @if ($result->obtained_marks === null)
                            <span class="text-slate-400">{{ $result->attendance_status->label() }}</span>
                        @else
                            {{ app_number($result->obtained_marks, 2) }} / {{ app_number($result->total_marks, 2) }}
                            <div class="text-xs text-slate-400">{{ app_number($result->percentage, 2) }}%</div>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if ($result->grade)
                            <x-ui.badge :color="$result->band?->color ?? 'slate'" size="xs">{{ $result->grade }}</x-ui.badge>
                        @else
                            <span class="text-xs text-slate-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        @if ($result->enrollment)
                            <x-ui.button variant="ghost" size="sm" icon="printer"
                                         :href="route('admin.result-cards.show', $result->enrollment)">Card</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="printer" title="Nothing published yet"
                                  description="Cards are printed from published results only." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$results" label="students" />
    </x-ui.card>
@endsection
