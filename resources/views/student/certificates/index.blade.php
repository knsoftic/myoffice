@extends('layouts.panel')

@section('title', 'Certificates')

@section('header')
    <x-ui.page-header title="Your certificates"
                      subtitle="Every certificate the institute has issued you. Each one can be checked by anybody from the code printed on it."
                      icon="check-badge" />
@endsection

@section('content')
    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$certificates->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Certificate</th>
                <th class="px-4 py-3 text-left font-semibold">Course</th>
                <th class="px-4 py-3 text-left font-semibold">Completed</th>
                <th class="px-4 py-3 text-right font-semibold">Result</th>
                <th class="px-4 py-3 text-right font-semibold"><span class="sr-only">Download</span></th>
            </x-slot:head>

            @foreach ($certificates as $certificate)
                <tr>
                    <td class="px-4 py-3">
                        <span class="font-mono text-sm font-medium text-slate-700 dark:text-slate-200">
                            {{ $certificate->certificate_number }}
                        </span>
                        <div class="text-xs text-slate-400">issued {{ app_date($certificate->issued_on) }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $certificate->course_name_snapshot }}
                        @if ($certificate->batch_name_snapshot)
                            <div class="text-xs text-slate-400">{{ $certificate->batch_name_snapshot }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ app_date($certificate->completion_date) }}
                    </td>
                    <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-600 dark:text-slate-300">
                        {{ $certificate->grade ?? '—' }}
                        @if ($certificate->percentage !== null)
                            <div class="text-xs text-slate-400">{{ app_number($certificate->percentage, 2) }}%</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        <x-ui.button size="sm" variant="secondary" icon="document-arrow-down" target="_blank"
                                     :href="route('student.certificates.pdf', $certificate)">
                            Download
                        </x-ui.button>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="check-badge" title="Nothing yet"
                                  message="A certificate appears here once the institute has issued it. Finishing a course is not the same thing as being issued one — attendance, fees and results are checked first." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$certificates" label="certificates" />
    </x-ui.card>
@endsection
