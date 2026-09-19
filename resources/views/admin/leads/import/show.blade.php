@extends('layouts.admin')

@section('title', 'Import · '.$import->original_filename)

{{--
    Lead import · steps 2-4 — admin.leads.import.show {import} (phase-05 §8.6). LeadImportPolicy::view has passed.
    With `Accept: application/json` the same route answers the progress poll (see $progress below).

    Controller variables (Admin\LeadImportController@show):
      $import           App\Models\Crm\LeadImport with creator
      $step             int  2 | 3 | 4 — the step to open (?step=, clamped to what the status allows)
      $headers          list<string>   the CSV header row as read (BOM stripped)
      $columnMap        array<int, ?string>  header position => lead field; the saved map, else the suggested one
      $targetFields     array<string, array{label: string, required: bool}>  the importable lead fields
      $previewRows      list<list<?string>>  the first five data rows, positional like $headers
      $previousImport   ?LeadImport   an earlier import of the same file_hash
      $sourceOptions    array<string, string>  InquirySource::options()
      $defaultStatusOptions array<string, string>  the statuses a new row may start in
      $assigneeOptions  array<int, string>
      $strategyOptions  array<string, string>  LeadImportDuplicateStrategy::options()
      $validation       ?array{total: int, valid: int, invalid: int, duplicates: int, would_create: int, would_update: int,
                        would_skip: int}  the dry run's counts, once status is validated or later
      $rowErrors        ?LengthAwarePaginator<LeadImportRow>  rows with errors (skipped_invalid / failed), row_number asc
      $progress         array{status: string, status_label: string, total_rows: int, processed: int, created_count: int,
                        updated_count: int, skipped_count: int, failed_count: int, percent: int, finished: bool,
                        message: ?string}  — exactly what the JSON poll returns
    Writes: PUT admin.leads.import.mapping (column_map[<position>] = field or '', defaults[source|assigned_to|status],
    duplicate_strategy, stop_after_errors); POST admin.leads.import.validate; POST admin.leads.import.run;
    POST admin.leads.import.cancel {reason}; GET admin.leads.import.errors (the error CSV, private disk).
--}}

@php
    $statusValue = $import->status instanceof \BackedEnum ? $import->status->value : (string) $import->status;
    $headers = array_values((array) ($headers ?? []));
    $columnMap = (array) ($columnMap ?? (array) ($import->column_map ?? []));
    $targetFields = (array) ($targetFields ?? []);
    $previewRows = array_values((array) ($previewRows ?? []));
    $defaults = (array) ($import->defaults ?? []);
    $strategyOptions = (array) ($strategyOptions ?? (enum_exists(\App\Enums\LeadImportDuplicateStrategy::class) ? \App\Enums\LeadImportDuplicateStrategy::options() : []));
    $strategyValue = $import->duplicate_strategy instanceof \BackedEnum ? $import->duplicate_strategy->value : (string) ($import->duplicate_strategy ?: 'import_and_flag');
    $strategyHelp = [
        'skip' => 'A row that matches an existing lead or client is not imported. Nothing is created or changed for it.',
        'import_and_flag' => 'The row is imported and linked to the lead it matches, so someone can review the pair.',
        'update_existing' => 'The matching lead gets the values this file supplies. Status, owner and budget are never changed.',
    ];
    $editable = in_array($statusValue, ['pending', 'mapping', 'validated'], true);
    $running = in_array($statusValue, ['validating', 'processing'], true);
    $finished = in_array($statusValue, ['completed', 'completed_with_errors', 'failed', 'cancelled'], true);
    $reached = match (true) {
        $finished, $running, $statusValue === 'validated' => 4,
        $statusValue === 'mapping' => 4,
        default => 3,
    };
    $step = max(2, min($reached, (int) ($step ?? request('step', $running || $finished || $statusValue === 'validated' ? 4 : 2))));
    $validation = $validation ?? null;
    $rowErrors = $rowErrors ?? null;
    $progress = (array) ($progress ?? [
        'status' => $statusValue,
        'status_label' => $import->status instanceof \BackedEnum && method_exists($import->status, 'label') ? $import->status->label() : \Illuminate\Support\Str::headline($statusValue),
        'total_rows' => (int) $import->total_rows,
        'processed' => (int) $import->created_count + (int) $import->updated_count + (int) $import->skipped_count + (int) $import->failed_count,
        'created_count' => (int) $import->created_count,
        'updated_count' => (int) $import->updated_count,
        'skipped_count' => (int) $import->skipped_count,
        'failed_count' => (int) $import->failed_count,
        'percent' => (int) $import->total_rows > 0 ? (int) floor(((int) $import->created_count + (int) $import->updated_count + (int) $import->skipped_count + (int) $import->failed_count) * 100 / (int) $import->total_rows) : 0,
        'finished' => $finished,
        'message' => null,
    ]);
    $initialColumnMap = [];
    foreach ($headers as $position => $header) {
        $initialColumnMap[(string) $position] = (string) old('column_map.'.$position, $columnMap[$position] ?? ($columnMap[$header] ?? ''));
    }
    $requiredFields = collect($targetFields)->filter(static fn ($field): bool => (bool) data_get($field, 'required'))->keys()->values()->all();
    $fieldLabels = collect($targetFields)->map(static fn ($field, $key): string => (string) data_get($field, 'label', \Illuminate\Support\Str::headline((string) $key)))->all();
    $fieldClass = 'block w-full rounded-lg border-slate-300 py-1.5 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white';
@endphp

@section('header')
    <x-ui.page-header :title="$import->original_filename" :subtitle="'Uploaded '.app_datetime($import->created_at).($import->relationLoaded('creator') && $import->creator ? ' by '.$import->creator->name : '')" icon="document-text" :back="route('admin.leads.import.index')">
        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
            @include('admin.crm.partials.enum-badge', ['value' => $import->status])
            <span>{{ app_number((int) $import->total_rows) }} rows</span>
            <span>· delimiter {{ $import->delimiter === "\t" ? 'tab' : '“'.$import->delimiter.'”' }}</span>
            <span>· {{ $import->encoding }}</span>
        </div>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.crm.partials.scripts')

    <div class="space-y-6">
        @include('admin.leads.import.partials.steps', ['current' => $step, 'reached' => $reached, 'import' => $import])

        @if ($previousImport ?? null)
            <div class="flex items-start gap-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25">
                <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0" />
                <p>
                    This exact file was imported on {{ app_datetime($previousImport->created_at) }}
                    ({{ app_number((int) $previousImport->created_count) }} leads created). Running it again imports its rows a second time, subject to the duplicate strategy.
                    <a href="{{ route('admin.leads.import.show', $previousImport) }}" class="font-semibold underline-offset-2 hover:underline">Open that import</a>
                </p>
            </div>
        @endif

        @if ($step === 2 || $step === 3)
            @if (! $editable)
                <x-ui.card :padded="false">
                    <x-ui.empty-state icon="lock-closed" title="The mapping is locked" message="This import has already started, so its columns and options can no longer change." :compact="true">
                        <x-slot:action>
                            <x-ui.button variant="secondary" :href="route('admin.leads.import.show', ['import' => $import, 'step' => 4])">See the results</x-ui.button>
                        </x-slot:action>
                    </x-ui.empty-state>
                </x-ui.card>
            @else
                <form
                    method="POST"
                    action="{{ route('admin.leads.import.mapping', $import) }}"
                    class="space-y-6"
                    x-data="{
                        step: {{ $step }},
                        map: {{ \Illuminate\Support\Js::from($initialColumnMap) }},
                        headers: {{ \Illuminate\Support\Js::from($headers) }},
                        labels: {{ \Illuminate\Support\Js::from($fieldLabels) }},
                        required: {{ \Illuminate\Support\Js::from($requiredFields) }},
                        get mapped() { return Object.values(this.map).filter((field) => field !== '') },
                        get missing() { return this.required.filter((field) => ! this.mapped.includes(field)) },
                        get twice() { return this.mapped.filter((field, index, all) => all.indexOf(field) !== index) },
                        get ignored() { return this.headers.filter((header, index) => (this.map[String(index)] || '') === '') },
                        next() {
                            if (this.missing.length || this.twice.length) { window.Alpine?.store('toasts')?.push({ type: 'error', message: this.missing.length ? 'Map every required field first.' : 'Each lead field may be mapped from one column only.' }); return; }
                            this.step = 3;
                            window.scrollTo({ top: 0, behavior: 'smooth' });
                        },
                    }"
                >
                    @csrf
                    @method('PUT')

                    {{-- Step 2 · Map columns --}}
                    <div x-show="step === 2" @if ($step !== 2) x-cloak @endif class="space-y-6">
                        <x-ui.card title="Map columns" subtitle="Tell the import which lead field each column holds. Suggestions come from the header names." icon="adjustments-horizontal">
                            @if ($headers === [])
                                <x-ui.empty-state icon="document-text" title="No header row was found" message="The first line of the file must name its columns. Download the template to see the expected headers." :compact="true" />
                            @else
                                <div class="overflow-x-auto">
                                    <table class="min-w-full text-sm">
                                        <thead>
                                            <tr class="border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-500 dark:border-slate-800 dark:text-slate-400">
                                                <th scope="col" class="py-2 pr-4">Column in the file</th>
                                                <th scope="col" class="py-2 pr-4">First value</th>
                                                <th scope="col" class="py-2">Imports into</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                            @foreach ($headers as $position => $header)
                                                <tr>
                                                    <td class="py-2 pr-4 font-medium text-slate-900 dark:text-white">{{ $header !== '' ? $header : 'Column '.($position + 1) }}</td>
                                                    <td class="max-w-[14rem] truncate py-2 pr-4 text-slate-500 dark:text-slate-400">{{ $previewRows[0][$position] ?? '—' }}</td>
                                                    <td class="py-2">
                                                        <label for="column-map-{{ $position }}" class="sr-only">Lead field for {{ $header }}</label>
                                                        <select id="column-map-{{ $position }}" name="column_map[{{ $position }}]" x-model="map['{{ $position }}']" class="{{ $fieldClass }} min-w-[12rem]">
                                                            <option value="">Ignore this column</option>
                                                            @foreach ($targetFields as $fieldKey => $field)
                                                                <option value="{{ $fieldKey }}">{{ data_get($field, 'label', \Illuminate\Support\Str::headline((string) $fieldKey)) }}{{ data_get($field, 'required') ? ' *' : '' }}</option>
                                                            @endforeach
                                                        </select>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                <x-ui.form.error for="column_map" />

                                <div class="mt-4 space-y-2 text-sm">
                                    <p x-show="missing.length" class="text-rose-700 dark:text-rose-300">
                                        Required and not mapped yet: <span class="font-semibold" x-text="missing.map((field) => labels[field] || field).join(', ')"></span>
                                    </p>
                                    <p x-show="twice.length" class="text-rose-700 dark:text-rose-300">
                                        Mapped from more than one column: <span class="font-semibold" x-text="[...new Set(twice)].map((field) => labels[field] || field).join(', ')"></span>
                                    </p>
                                    <p class="text-slate-500 dark:text-slate-400">
                                        Ignored columns: <span class="font-medium text-slate-700 dark:text-slate-200" x-text="ignored.length ? ignored.join(', ') : 'none'"></span>
                                    </p>
                                </div>
                            @endif
                        </x-ui.card>

                        @if ($previewRows !== [])
                            <x-ui.card title="The first rows, as they will be read" :padded="false" icon="table-cells">
                                <div class="overflow-x-auto">
                                    <table class="min-w-full text-sm">
                                        <thead>
                                            <tr class="border-b border-slate-200 bg-slate-50/80 text-left text-xs font-semibold text-slate-500 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-400">
                                                <th scope="col" class="px-4 py-2">Row</th>
                                                @foreach ($headers as $position => $header)
                                                    <th scope="col" class="whitespace-nowrap px-4 py-2" x-bind:class="(map['{{ $position }}'] || '') === '' ? 'opacity-50' : ''">
                                                        <span class="block uppercase tracking-wider">{{ $header }}</span>
                                                        <span class="block font-normal normal-case text-brand-700 dark:text-brand-300" x-text="map['{{ $position }}'] ? '→ ' + (labels[map['{{ $position }}']] || map['{{ $position }}']) : 'ignored'"></span>
                                                    </th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 text-slate-700 dark:divide-slate-800 dark:text-slate-200">
                                            @foreach ($previewRows as $rowIndex => $row)
                                                <tr>
                                                    <td class="px-4 py-2 tabular-nums text-slate-400 dark:text-slate-500">{{ app_number($rowIndex + 1) }}</td>
                                                    @foreach ($headers as $position => $header)
                                                        <td class="max-w-[14rem] truncate whitespace-nowrap px-4 py-2" x-bind:class="(map['{{ $position }}'] || '') === '' ? 'opacity-50' : ''">{{ $row[$position] ?? '' }}</td>
                                                    @endforeach
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </x-ui.card>
                        @endif

                        <div class="flex justify-end">
                            <x-ui.button icon-trailing="arrow-right" x-on:click="next()" :disabled="$headers === []">Continue to options</x-ui.button>
                        </div>
                    </div>

                    {{-- Step 3 · Options --}}
                    <div x-show="step === 3" @if ($step !== 3) x-cloak @endif class="space-y-6">
                        <x-ui.card title="Defaults for every row" subtitle="Used when a row leaves the field empty." icon="adjustments-horizontal">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <x-ui.form.select name="defaults[source]" label="Source" :options="$sourceOptions ?? []" :selected="old('defaults.source', $defaults['source'] ?? null)" placeholder="Required in the file" />
                                <x-ui.form.select name="defaults[assigned_to]" label="Owner" :options="collect($assigneeOptions ?? [])->all()" :selected="old('defaults.assigned_to', $defaults['assigned_to'] ?? null)" placeholder="Auto-assignment rules" />
                                <x-ui.form.select name="defaults[status]" label="Status" :options="$defaultStatusOptions ?? []" :selected="old('defaults.status', $defaults['status'] ?? 'new')" />
                            </div>
                        </x-ui.card>

                        <x-ui.card title="When a row matches an existing lead or client" icon="link">
                            <div class="space-y-3" role="radiogroup" aria-label="Duplicate strategy">
                                @foreach ($strategyOptions as $strategyKey => $strategyLabel)
                                    <label class="flex cursor-pointer items-start gap-3 rounded-lg p-3 ring-1 ring-slate-200 transition hover:bg-slate-50 has-[:checked]:bg-brand-50 has-[:checked]:ring-brand-300 dark:ring-slate-700 dark:hover:bg-slate-800/60 dark:has-[:checked]:bg-brand-500/10 dark:has-[:checked]:ring-brand-500/40">
                                        <input type="radio" name="duplicate_strategy" value="{{ $strategyKey }}" @checked(old('duplicate_strategy', $strategyValue) === $strategyKey) class="mt-0.5 h-4 w-4 border-slate-300 text-brand-600 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800">
                                        <span class="text-sm">
                                            <span class="block font-semibold text-slate-900 dark:text-white">{{ $strategyLabel }}</span>
                                            <span class="text-slate-500 dark:text-slate-400">{{ $strategyHelp[$strategyKey] ?? '' }}</span>
                                        </span>
                                    </label>
                                @endforeach
                                <x-ui.form.error for="duplicate_strategy" />
                            </div>
                        </x-ui.card>

                        <x-ui.card title="Errors" icon="exclamation-circle">
                            <x-ui.form.input name="stop_after_errors" type="number" label="Stop after this many invalid rows" :value="old('stop_after_errors', $defaults['stop_after_errors'] ?? 0)" min="0" max="100000" step="1" inputmode="numeric" help="0 never stops: every valid row is imported and every invalid one is reported." class="sm:max-w-xs" />
                        </x-ui.card>

                        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-between">
                            <x-ui.button variant="secondary" icon="arrow-left" x-on:click="step = 2">Back to columns</x-ui.button>
                            <x-ui.button type="submit" icon="check">Save and continue</x-ui.button>
                        </div>
                    </div>
                </form>
            @endif
        @else
            {{-- Step 4 · Validate and run --}}
            <div class="space-y-6">
                @if (in_array($statusValue, ['pending', 'mapping'], true))
                    <x-ui.card title="Check every row first" subtitle="A dry run: every row is validated with the same rules as the lead form and matched for duplicates. No lead is created." icon="clipboard-document-check">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-sm text-slate-600 dark:text-slate-300">{{ app_number((int) $import->total_rows) }} rows will be checked.</p>
                            <div class="flex items-center gap-2">
                                <x-ui.button variant="secondary" icon="arrow-left" :href="route('admin.leads.import.show', ['import' => $import, 'step' => 2])">Change mapping</x-ui.button>
                                <form method="POST" action="{{ route('admin.leads.import.validate', $import) }}" x-data="{ busy: false }" x-on:submit="busy = true">
                                    @csrf
                                    <x-ui.button type="submit" icon="clipboard-document-check" x-bind:disabled="busy">Validate rows</x-ui.button>
                                </form>
                            </div>
                        </div>
                    </x-ui.card>
                @endif

                @if ($validation)
                    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                        <x-ui.stat-card label="Rows" :value="app_number((int) data_get($validation, 'total', 0))" icon="table-cells" color="slate" />
                        <x-ui.stat-card label="Valid" :value="app_number((int) data_get($validation, 'valid', 0))" icon="check-circle" color="emerald" />
                        <x-ui.stat-card label="Invalid" :value="app_number((int) data_get($validation, 'invalid', 0))" icon="x-circle" color="rose" />
                        <x-ui.stat-card label="Duplicates" :value="app_number((int) data_get($validation, 'duplicates', 0))" icon="link" color="amber" />
                    </div>
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        Running it would create <span class="font-semibold tabular-nums">{{ app_number((int) data_get($validation, 'would_create', 0)) }}</span>,
                        update <span class="font-semibold tabular-nums">{{ app_number((int) data_get($validation, 'would_update', 0)) }}</span>
                        and skip <span class="font-semibold tabular-nums">{{ app_number((int) data_get($validation, 'would_skip', 0)) }}</span> leads.
                    </p>
                @endif

                @if ($running || $finished)
                    <x-ui.card title="Progress" icon="arrow-path">
                        <div x-data="crmImportProgress({{ \Illuminate\Support\Js::from(['url' => route('admin.leads.import.show', $import), 'state' => $progress, 'finished' => ! $running]) }})" class="space-y-4" aria-live="polite">
                            <div class="flex items-center justify-between gap-3 text-sm">
                                <span class="font-medium text-slate-900 dark:text-white" x-text="state.status_label">{{ $progress['status_label'] ?? '' }}</span>
                                <span class="tabular-nums text-slate-500 dark:text-slate-400">
                                    <span x-text="state.processed">{{ app_number((int) ($progress['processed'] ?? 0)) }}</span> /
                                    <span x-text="state.total_rows">{{ app_number((int) ($progress['total_rows'] ?? 0)) }}</span> rows
                                </span>
                            </div>
                            <div class="h-2.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" role="progressbar" aria-valuemin="0" aria-valuemax="100" x-bind:aria-valuenow="state.percent" aria-valuenow="{{ (int) ($progress['percent'] ?? 0) }}">
                                <div class="h-full rounded-full bg-brand-600 transition-all duration-500 dark:bg-brand-500" style="width: {{ (int) ($progress['percent'] ?? 0) }}%" x-bind:style="`width: ${state.percent}%`"></div>
                            </div>
                            <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                                <div><dt class="text-xs text-slate-500 dark:text-slate-400">Created</dt><dd class="font-semibold tabular-nums text-emerald-700 dark:text-emerald-400" x-text="state.created_count">{{ app_number((int) ($progress['created_count'] ?? 0)) }}</dd></div>
                                <div><dt class="text-xs text-slate-500 dark:text-slate-400">Updated</dt><dd class="font-semibold tabular-nums text-slate-900 dark:text-white" x-text="state.updated_count">{{ app_number((int) ($progress['updated_count'] ?? 0)) }}</dd></div>
                                <div><dt class="text-xs text-slate-500 dark:text-slate-400">Skipped</dt><dd class="font-semibold tabular-nums text-amber-700 dark:text-amber-400" x-text="state.skipped_count">{{ app_number((int) ($progress['skipped_count'] ?? 0)) }}</dd></div>
                                <div><dt class="text-xs text-slate-500 dark:text-slate-400">Failed</dt><dd class="font-semibold tabular-nums text-rose-700 dark:text-rose-400" x-text="state.failed_count">{{ app_number((int) ($progress['failed_count'] ?? 0)) }}</dd></div>
                            </dl>
                            @if (filled($import->failure_message))
                                <p class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800 ring-1 ring-inset ring-rose-200 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/25">{{ $import->failure_message }}</p>
                            @endif
                            @if ($import->finished_at)
                                <p class="text-xs text-slate-500 dark:text-slate-400">Finished {{ app_datetime($import->finished_at) }}.</p>
                            @endif
                        </div>
                    </x-ui.card>
                @endif

                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-end">
                    @if ($statusValue === 'validated')
                        <x-ui.button variant="secondary" icon="arrow-left" :href="route('admin.leads.import.show', ['import' => $import, 'step' => 2])">Change mapping</x-ui.button>
                    @endif
                    @if (($validation && (int) data_get($validation, 'invalid', 0) > 0) || filled($import->error_report_path))
                        <x-ui.button variant="secondary" icon="arrow-down-tray" :href="route('admin.leads.import.errors', $import)">Download error CSV</x-ui.button>
                    @endif
                    @if ($statusValue === 'processing')
                        <x-ui.confirm
                            :action="route('admin.leads.import.cancel', $import)"
                            method="POST"
                            title="Cancel this import?"
                            message="No further rows are imported. Leads already created are kept — undoing an import never deletes real data."
                            confirm-label="Cancel import"
                            id="import-cancel-form"
                        >
                            <x-slot:trigger>
                                <x-ui.button variant="danger" icon="x-mark">Cancel import</x-ui.button>
                            </x-slot:trigger>
                            <div class="mt-3">
                                <label for="import-cancel-reason" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Reason <span class="text-rose-500">*</span></label>
                                <input id="import-cancel-reason" type="text" name="reason" form="import-cancel-form" required maxlength="255" class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-rose-500 focus:ring-rose-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                            </div>
                        </x-ui.confirm>
                    @endif
                    @if ($statusValue === 'validated')
                        <form method="POST" action="{{ route('admin.leads.import.run', $import) }}" x-data="{ busy: false }" x-on:submit="busy = true">
                            @csrf
                            <x-ui.button type="submit" icon="arrow-up-tray" x-bind:disabled="busy">Run import</x-ui.button>
                        </form>
                    @endif
                    @if ($finished)
                        <x-ui.button variant="secondary" icon="funnel" :href="route('admin.leads.index')">Open leads</x-ui.button>
                    @endif
                </div>

                @if ($rowErrors instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
                    <div class="space-y-3">
                        <x-ui.section-heading title="Rows with errors" subtitle="Fix them in the file and import it again, or download the error CSV." icon="exclamation-circle" />
                        <x-ui.table :is-empty="$rowErrors->isEmpty()" :columns="4" :dense="true">
                            <x-slot:head>
                                <th scope="col" class="px-4 py-3">Row</th>
                                <th scope="col" class="px-4 py-3">Status</th>
                                <th scope="col" class="px-4 py-3">Values</th>
                                <th scope="col" class="px-4 py-3">What is wrong</th>
                            </x-slot:head>
                            @foreach ($rowErrors as $row)
                                @php
                                    $rowFieldErrors = (array) ($row->errors ?? []);
                                    $raw = (array) ($row->raw ?? []);
                                    // `raw` may be keyed by lead field, by header or by position: read whichever the row carries.
                                    $offending = collect($rowFieldErrors)->keys()->mapWithKeys(static function ($field) use ($raw, $columnMap, $headers): array {
                                        $position = array_search((string) $field, array_map('strval', $columnMap), true);
                                        $value = $raw[$field] ?? ($position !== false ? ($raw[$headers[$position] ?? ''] ?? ($raw[$position] ?? null)) : null);

                                        return [(string) $field => $value];
                                    });
                                @endphp
                                <tr class="align-top">
                                    <td class="whitespace-nowrap tabular-nums">{{ app_number((int) $row->row_number) }}</td>
                                    <td class="whitespace-nowrap">@include('admin.crm.partials.enum-badge', ['value' => $row->status, 'size' => 'xs'])</td>
                                    <td class="min-w-[12rem] text-xs">
                                        @forelse ($offending as $field => $value)
                                            <span class="block"><span class="text-slate-500 dark:text-slate-400">{{ $fieldLabels[$field] ?? \Illuminate\Support\Str::headline((string) $field) }}:</span> <span class="font-mono">{{ filled($value) ? (is_scalar($value) ? $value : json_encode($value)) : 'empty' }}</span></span>
                                        @empty
                                            —
                                        @endforelse
                                    </td>
                                    <td class="min-w-[14rem] text-xs text-rose-700 dark:text-rose-300">
                                        @foreach ($rowFieldErrors as $field => $messages)
                                            @foreach ((array) $messages as $message)
                                                <span class="block">{{ $message }}</span>
                                            @endforeach
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                            <x-slot:empty>
                                <x-ui.empty-state icon="check-circle" title="No row has an error" :compact="true" />
                            </x-slot:empty>
                            <x-slot:footer>
                                <x-ui.pagination-summary :paginator="$rowErrors->appends(['step' => 4])" label="rows" />
                            </x-slot:footer>
                        </x-ui.table>
                    </div>
                @endif
            </div>
        @endif
    </div>
@endsection
