@extends('layouts.admin')

@section('title', $type->label())

@php
    $slug = str_replace('_', '-', $type->value);
    $query = array_filter(request()->only(['preset', 'from', 'to', 'category', 'context', 'project', 'client']));
    $meta = $result->meta;
    $overLimit = $result->rowCount() > $syncLimit;
@endphp

@section('header')
    <x-ui.page-header :title="$type->label()"
                      :subtitle="$type->description()"
                      icon="chart-bar"
                      badge="cash basis"
                      :badge-color="$type->color()"
                      :back="route('admin.reports.finance.index')">
        <x-slot:actions>
            @foreach ($formats as $format)
                <x-ui.button variant="secondary" size="sm" :icon="$format->icon()"
                             :target="$format === \App\Enums\ExportFormat::Csv ? null : '_blank'"
                             :href="route('admin.reports.finance.export', ['report' => $slug, 'format' => $format->value] + $query)">
                    {{ $format->label() }}
                </x-ui.button>
            @endforeach
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.form.select name="preset" label="Period">
                @foreach (\App\Support\DateRange::presets() as $value => $label)
                    <option value="{{ $value }}" @selected($range->preset() === $value)>{{ $label }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.input type="date" name="from" label="From" :value="request('from', app_date($range->start(), 'Y-m-d'))"
                             help="A from/to pair overrides the preset." />
            <x-ui.form.input type="date" name="to" label="To" :value="request('to', app_date($range->end(), 'Y-m-d'))" />

            @if ($type === \App\Enums\FinanceReportType::Expenses || $type === \App\Enums\FinanceReportType::ProfitLoss)
                <x-ui.form.select name="context" label="Business" placeholder="Both businesses">
                    @foreach (\App\Enums\FinanceContext::cases() as $context)
                        <option value="{{ $context->value }}" @selected(request('context') === $context->value)>
                            {{ $context->label() }}
                        </option>
                    @endforeach
                </x-ui.form.select>
            @endif

            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Run it</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.reports.finance.'.$slug)">Reset</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    @if ($result->omittedSources() !== [])
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200">
            <p class="font-semibold">This total is not the whole picture.</p>
            <p class="mt-1">
                Not included, because you may not see them:
                <strong>{{ implode(', ', $result->omittedSources()) }}</strong>. A partial total read as a
                full one is worse than a refusal, which is why it says so here rather than quietly
                leaving them out.
            </p>
        </div>
    @endif

    @if ($overLimit)
        <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-300">
            {{ app_number($result->rowCount()) }} rows — above the {{ app_number($syncLimit) }} an
            export is built inline for. Narrow the range before exporting, or expect it to take a while.
        </div>
    @endif

    <x-ui.card :title="$type->label()" :subtitle="$range->label()">
        <x-slot:actions>
            <x-ui.badge color="slate" size="xs">dated on {{ $meta['date_column'] ?? '—' }}</x-ui.badge>
        </x-slot:actions>

        @if ($result->isEmpty())
            <x-ui.empty-state icon="chart-bar"
                              :title="match ($type) {
                                  \App\Enums\FinanceReportType::Income => 'No income in this range',
                                  \App\Enums\FinanceReportType::Expenses => 'No approved expenses in this range',
                                  \App\Enums\FinanceReportType::ProfitLoss => 'Nothing to report in this range',
                                  \App\Enums\FinanceReportType::ReceivablesAging => 'Nothing outstanding — every invoice is settled',
                              }"
                              message="The period and the date column are printed below, so an empty report still says what it looked at." />
        @else
            <div class="overflow-x-auto">
                @include('admin.reports.finance._table', ['print' => false])
            </div>
        @endif

        <x-slot:footer>
            <dl class="grid gap-x-6 gap-y-1 text-xs text-slate-500 dark:text-slate-400 sm:grid-cols-2">
                <div class="flex gap-2">
                    <dt class="font-semibold">Basis</dt>
                    <dd>{{ $meta['basis'] ?? 'cash' }} — money that moved, not money that was promised</dd>
                </div>
                <div class="flex gap-2">
                    <dt class="font-semibold">Dated on</dt>
                    <dd>{{ $meta['date_column'] ?? '—' }}</dd>
                </div>
                <div class="flex gap-2">
                    <dt class="font-semibold">Period</dt>
                    <dd>{{ app_date($meta['from'] ?? null) }} – {{ app_date($meta['to'] ?? null) }}</dd>
                </div>
                <div class="flex gap-2">
                    <dt class="font-semibold">Generated</dt>
                    <dd>{{ $meta['generated_at'] ?? '' }}</dd>
                </div>
                @if (($meta['filters'] ?? []) !== [])
                    <div class="flex gap-2 sm:col-span-2">
                        <dt class="font-semibold">Filters</dt>
                        <dd>{{ collect($meta['filters'])->map(fn ($v, $k) => $k.' = '.$v)->implode(' · ') }}</dd>
                    </div>
                @endif
                @if (array_key_exists('includes_institute', $meta))
                    <div class="flex gap-2 sm:col-span-2">
                        <dt class="font-semibold">Institute</dt>
                        <dd>{{ $meta['includes_institute'] ? 'Included' : 'Excluded by the finance setting' }}</dd>
                    </div>
                @endif
                @if (filled($meta['note'] ?? null))
                    <div class="flex gap-2 sm:col-span-2">
                        <dt class="font-semibold">Note</dt>
                        <dd>{{ $meta['note'] }}</dd>
                    </div>
                @endif
            </dl>
        </x-slot:footer>
    </x-ui.card>
@endsection
