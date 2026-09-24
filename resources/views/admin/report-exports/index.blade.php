@extends('layouts.admin')

@section('title', 'My exports')

{{--
    The export register - admin.report-exports.index (phase-19-23 7.8, 9.5).

    **There is no "all exports" view, not even for an administrator.** The register is scoped to the
    person who asked, because the FILE was shaped by their permissions and their scope - listing
    somebody else's exports is the first step towards handing one over.

    "Delete" removes the file, never the row. 2.26 keeps the record of what left the building for
    ever, which is why the button says "Remove file" and not "Delete".
--}}

@section('header')
    <x-ui.page-header title="My exports"
                      subtitle="Files you have asked this system to build. Each one is kept for a limited time, then the file is removed and the record of it stays."
                      icon="arrow-down-tray">
        <x-slot:actions>
            <x-ui.button variant="ghost" icon="arrow-left" :href="route('admin.reports.index')">Reports</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.form.select name="status" label="Status" placeholder="Any">
                @foreach (\App\Enums\ExportStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="report" label="Report" placeholder="Any">
                @foreach ($reports as $report)
                    <option value="{{ $report->key() }}" @selected(request('report') === $report->key())>{{ $report->title() }}</option>
                @endforeach
            </x-ui.form.select>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" icon="funnel">Apply</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.report-exports.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card>
        @if ($exports->isEmpty())
            <x-ui.empty-state icon="arrow-down-tray"
                              title="Nothing exported yet"
                              description="When a report is too large to show on screen, it is built here and you are told when it is ready." />
        @else
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Report</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Period</th>
                        <th class="px-3 py-2 text-center text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Format</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Rows</th>
                        <th class="px-3 py-2 text-center text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Status</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Asked for</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Actions</th>
                    </tr>
                </x-slot:head>

                @foreach ($exports as $export)
                    @php($definition = \App\Support\ReportRegistry::definition($export->report_key))

                    <tr class="border-t border-slate-100 dark:border-slate-800">
                        <td class="px-3 py-2 text-sm">
                            <span class="font-medium text-slate-800 dark:text-slate-100">
                                {{ $definition?->title() ?? $export->report_key }}
                            </span>

                            @if ($export->status === \App\Enums\ExportStatus::Failed)
                                {{-- 2.26: the real exception text, shown to the requester. --}}
                                <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $export->failureReason() }}</p>
                            @endif
                        </td>

                        <td class="px-3 py-2 text-sm text-slate-600 dark:text-slate-300">
                            {{ app_date($export->date_from) }} &ndash; {{ app_date($export->date_to) }}
                        </td>

                        <td class="px-3 py-2 text-center text-sm">
                            <x-ui.badge :color="$export->format->color()">{{ $export->format->label() }}</x-ui.badge>
                        </td>

                        <td class="px-3 py-2 text-right text-sm tabular-nums text-slate-600 dark:text-slate-300">
                            {{ $export->row_count === null ? '—' : app_number($export->row_count) }}
                        </td>

                        <td class="px-3 py-2 text-center text-sm">
                            <x-ui.badge :color="$export->status->color()">{{ $export->status->label() }}</x-ui.badge>

                            @if ($export->status === \App\Enums\ExportStatus::Completed && $export->expires_at)
                                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">
                                    until {{ app_date($export->expires_at) }}
                                </p>
                            @endif
                        </td>

                        <td class="px-3 py-2 text-sm text-slate-600 dark:text-slate-300">
                            {{ app_datetime($export->created_at) }}

                            @if ($export->durationSeconds() !== null)
                                <span class="block text-xs text-slate-400 dark:text-slate-500">
                                    built in {{ $export->durationSeconds() }}s
                                </span>
                            @endif
                        </td>

                        <td class="px-3 py-2 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if ($export->isDownloadable())
                                    <x-ui.button size="sm" variant="secondary" icon="arrow-down-tray"
                                                 :href="route('admin.report-exports.download', $export)">
                                        Download
                                    </x-ui.button>

                                    <x-ui.confirm :action="route('admin.report-exports.destroy', $export)"
                                                  method="DELETE"
                                                  title="Remove this file?"
                                                  message="The file is deleted. The record that you exported it is kept."
                                                  confirm-label="Remove file">
                                        <x-slot:trigger>
                                            <x-ui.button size="sm" variant="ghost" icon="trash">Remove file</x-ui.button>
                                        </x-slot:trigger>
                                    </x-ui.confirm>
                                @elseif ($export->isPending())
                                    <span class="text-xs text-slate-400 dark:text-slate-500">Being built&hellip;</span>
                                @elseif ($export->hasExpired())
                                    <span class="text-xs text-slate-400 dark:text-slate-500">File removed</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>

            <x-ui.pagination-summary :paginator="$exports" class="mt-4" />
        @endif
    </x-ui.card>
@endsection
