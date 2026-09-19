@extends('layouts.admin')

@section('title', 'Import leads')

{{--
    Lead import · step 1, Upload — admin.leads.import.index → POST admin.leads.import.store (phase-05 §8.6), plus the
    history of past imports. LeadImportPolicy: a user sees their own imports unless they hold leads.view_any (§9.1).

    Controller variables (Admin\LeadImportController@index):
      $imports        LengthAwarePaginator<LeadImport> newest first, with creator
      $maxRows        int     crm.import_max_rows
      $maxUploadMb    ?int    security.max_upload_mb
      $statusOptions  array<string, string>  LeadImportStatus::options() (the history filter)
      $filters        array<string, mixed>
      $sort, $direction  created_at (default desc) | original_filename | status
    Query: status, sort, direction, page.

    Upload (StoreLeadImportRequest, multipart): file (.csv), delimiter (auto | , | ; | tab | pipe), encoding
    (auto | UTF-8 | Windows-1252 | ISO-8859-1). LeadImportService::stage() sniffs the MIME from the content, stores the
    file on the private disk and redirects to admin.leads.import.show {import} at step 2.
--}}

@php
    $user = auth()->user();
    $sort = $sort ?? 'created_at';
    $direction = $direction ?? 'desc';
    $maxRows = (int) ($maxRows ?? 0);
    $hint = collect([
        'CSV only',
        $maxRows > 0 ? 'up to '.app_number($maxRows).' rows' : null,
        filled($maxUploadMb ?? null) ? app_number((int) $maxUploadMb).' MB at most' : null,
    ])->filter()->implode(' · ');
    $imports = $imports ?? new \Illuminate\Pagination\LengthAwarePaginator([], 0, 15);
@endphp

@section('header')
    <x-ui.page-header title="Import leads" subtitle="Bring a spreadsheet of leads in, check every row, then run it." icon="arrow-up-tray" :back="route('admin.leads.index')">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="arrow-down-tray" :href="route('admin.leads.import.template')">Download template</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="space-y-6">
        @include('admin.leads.import.partials.steps', ['current' => 1, 'reached' => 1, 'import' => null])

        <x-ui.card title="Upload a CSV file" subtitle="Nothing is imported yet: the next steps map the columns and check every row first." icon="arrow-up-tray">
            <form method="POST" action="{{ route('admin.leads.import.store') }}" enctype="multipart/form-data" class="space-y-5" x-data="{ busy: false }" x-on:submit="busy = true">
                @csrf
                <x-ui.form.file name="file" label="CSV file" accept=".csv,text/csv" :hint="$hint" icon="document-text" required />

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.form.select name="delimiter" label="Delimiter" :options="['auto' => 'Detect automatically', ',' => 'Comma ( , )', ';' => 'Semicolon ( ; )', 'tab' => 'Tab', 'pipe' => 'Pipe ( | )']" :selected="old('delimiter', 'auto')" />
                    <x-ui.form.select name="encoding" label="Encoding" :options="['auto' => 'Detect automatically', 'UTF-8' => 'UTF-8', 'Windows-1252' => 'Windows-1252 (Excel on Windows)', 'ISO-8859-1' => 'ISO-8859-1']" :selected="old('encoding', 'auto')" />
                </div>

                <div class="flex flex-col gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Start from the template so the headers match. A file that was imported before is flagged on the next step.
                    </p>
                    <x-ui.button type="submit" icon="arrow-right" x-bind:disabled="busy" x-bind:aria-busy="busy ? 'true' : 'false'">
                        <span x-text="busy ? 'Uploading…' : 'Upload and continue'">Upload and continue</span>
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <div class="space-y-3">
            <x-ui.section-heading title="Past imports" subtitle="Leads created by an import are kept even when the import is cancelled." icon="clock" />

            <x-ui.filter-bar placeholder="Search file names…" :reset="route('admin.leads.import.index')">
                <x-ui.form.select name="status" :options="(array) ($statusOptions ?? [])" :selected="request('status')" placeholder="Any status" size="sm" aria-label="Filter by status" />
            </x-ui.filter-bar>

            <x-ui.table :is-empty="$imports->isEmpty()" :columns="7">
                <x-slot:head>
                    <x-ui.th-sortable column="original_filename" :sort="$sort" :direction="$direction">File</x-ui.th-sortable>
                    <x-ui.th-sortable column="status" :sort="$sort" :direction="$direction">Status</x-ui.th-sortable>
                    <th scope="col" class="px-4 py-3 text-right">Rows</th>
                    <th scope="col" class="px-4 py-3">Result</th>
                    <th scope="col" class="px-4 py-3">By</th>
                    <x-ui.th-sortable column="created_at" :sort="$sort" :direction="$direction" default="desc">Uploaded</x-ui.th-sortable>
                    <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
                </x-slot:head>

                @foreach ($imports as $import)
                    @php
                        $creator = $import->relationLoaded('creator') ? $import->creator : null;
                        $statusValue = $import->status instanceof \BackedEnum ? $import->status->value : (string) $import->status;
                    @endphp
                    <tr>
                        <td class="min-w-[14rem]">
                            <a href="{{ route('admin.leads.import.show', $import) }}" class="block truncate font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $import->original_filename }}</a>
                            <span class="text-xs text-slate-500 dark:text-slate-400">{{ strtoupper((string) $import->encoding) }} · {{ $import->delimiter === "\t" ? 'tab' : $import->delimiter }}</span>
                        </td>
                        <td class="whitespace-nowrap">@include('admin.crm.partials.enum-badge', ['value' => $import->status])</td>
                        <td class="whitespace-nowrap text-right tabular-nums">{{ app_number((int) $import->total_rows) }}</td>
                        <td class="whitespace-nowrap text-xs tabular-nums text-slate-600 dark:text-slate-300">
                            <span class="text-emerald-700 dark:text-emerald-400">{{ app_number((int) $import->created_count) }} created</span> ·
                            {{ app_number((int) $import->updated_count) }} updated ·
                            {{ app_number((int) $import->skipped_count) }} skipped ·
                            <span @class(['text-rose-700 dark:text-rose-400' => (int) $import->failed_count > 0])>{{ app_number((int) $import->failed_count) }} failed</span>
                        </td>
                        <td class="whitespace-nowrap text-sm">{{ $creator?->name ?? '—' }}</td>
                        <td class="whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">{{ app_datetime($import->created_at) }}</td>
                        <td>
                            <div class="flex items-center justify-end gap-1">
                                @if (filled($import->error_report_path) && \Illuminate\Support\Facades\Route::has('admin.leads.import.errors'))
                                    <x-ui.icon-button icon="arrow-down-tray" size="sm" :href="route('admin.leads.import.errors', $import)" :label="'Download the error report of '.$import->original_filename" />
                                @endif
                                <x-ui.icon-button icon="arrow-right" size="sm" :href="route('admin.leads.import.show', $import)" :label="'Open the import '.$import->original_filename" />
                            </div>
                        </td>
                    </tr>
                @endforeach

                <x-slot:empty>
                    @if (filled(request('status')))
                        <x-ui.empty-state icon="funnel" title="No imports with this status" :compact="true">
                            <x-slot:action>
                                <x-ui.button variant="secondary" :href="route('admin.leads.import.index')">Clear filters</x-ui.button>
                            </x-slot:action>
                        </x-ui.empty-state>
                    @else
                        <x-ui.empty-state icon="arrow-up-tray" title="No imports yet" message="Upload a CSV above; download the template first to get the headers right." :compact="true" />
                    @endif
                </x-slot:empty>

                <x-slot:footer>
                    <x-ui.pagination-summary :paginator="$imports" label="imports" />
                </x-slot:footer>
            </x-ui.table>
        </div>
    </div>
@endsection
