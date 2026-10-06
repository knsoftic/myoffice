@extends('layouts.admin')

@section('title', 'Advanced Reports')

{{--
    Advanced Reports — admin.advanced-reports.index (D177).

    One row per admission: a student on one course (D172). A student on two courses is two rows; the
    cards count people with COUNT(DISTINCT student) and admissions as rows, so both questions are
    answered.

    The cards, the table and every export are built from the same `AdvancedReportFilters` and the same
    base query (`AdvancedStudentReportService::query()`), so the Excel file, the CSV, the PDF and the
    printed sheet contain exactly the rows this screen is filtered to, in this order. The export links
    carry `$filters->toQuery()` — the filters as the server understood them, not as they were typed.

    **Money is withheld, not blanked** (INV-23-2). Without `advanced_reports.view_financial` the money
    columns are absent from the HTML, the money cards are not computed, and the Overdue card shows the
    count only. The page never asks for that permission; it only hides.

    Controller variables (AdvancedReportController@index):
      $filters         AdvancedReportFilters
      $rows            LengthAwarePaginator<AdvancedReportRow>
      $summary         array  AdvancedStudentReportService::summary()
      $canSeeMoney     bool
      $periodOptions, $courseOptions, $batchOptions, $statusOptions, $paymentOptions   value => label
      $breadcrumbs     list  Dashboard › Reports › Advanced Reports
--}}

@php
    use App\Enums\ExportFormat;
    use App\Support\Money;
    use Illuminate\Support\Str;

    // The filters as the server applied them — every link on this page (export, print, sort,
    // pagination) carries these, never the raw query string.
    $query = $filters->toQuery();
    $columnCount = 12 + ($canSeeMoney ? 3 : 0);
@endphp

@section('header')
    <x-ui.page-header title="Advanced Reports"
                      subtitle="Students, enrolments and fees — filter, analyse and export"
                      icon="document-chart-bar">
        <x-slot:actions>
            @canany(['advanced_reports.export', 'advanced_reports.print'])
                <x-ui.dropdown align="right" width="w-48" label="Export this report">
                    <x-slot:trigger>
                        <x-ui.button variant="secondary" icon="arrow-down-tray" icon-trailing="chevron-down"
                                     aria-haspopup="menu">Export</x-ui.button>
                    </x-slot:trigger>

                    @can('advanced_reports.export')
                        @foreach ([ExportFormat::Excel, ExportFormat::Csv, ExportFormat::Pdf] as $format)
                            <x-ui.dropdown-item :href="route('admin.advanced-reports.export', ['format' => $format->value] + $query)"
                                                :icon="$format->icon()">
                                {{ $format->label() }}
                            </x-ui.dropdown-item>
                        @endforeach
                    @endcan

                    @can('advanced_reports.print')
                        <x-ui.dropdown-item :href="route('admin.advanced-reports.print', $query)" icon="printer"
                                            target="_blank" rel="noopener">
                            {{ ExportFormat::Print->label() }}
                        </x-ui.dropdown-item>
                    @endcan
                </x-ui.dropdown>
            @endcanany
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div x-data="{ navigating: false }" x-on:submit.window="if ($event.target?.method === 'get') navigating = true">
        @include('admin.advanced-reports._filters', ['total' => $rows->total()])

        {{-- The cards answer for the filtered set, not for the page: one aggregate over the same query. --}}
        <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat-card label="Total Students" :value="app_number($summary['total_students'])" icon="users" color="slate"
                            delta-label="people, each counted once" />
            <x-ui.stat-card label="Active Students" :value="app_number($summary['active_students'])" icon="check-badge" color="emerald" />
            <x-ui.stat-card label="Completed Students" :value="app_number($summary['completed_students'])" icon="academic-cap" color="sky" />
            <x-ui.stat-card label="Total Enrolled" :value="app_number($summary['total_enrolled'])" icon="clipboard-document-list" color="violet"
                            delta-label="admissions — one per student per course" />

            @if ($canSeeMoney)
                @php($remaining = $summary['total_remaining'])

                {{-- The period picks *students* (by joining date); the money is everything those
                     students owe and paid to date, not what was collected inside the period. --}}
                <x-ui.stat-card label="Total Fees" :value="money($summary['total_fees'])" icon="document-text" color="slate"
                                delta-label="final fee after discounts, for these students" />
                <x-ui.stat-card label="Total Paid" :value="money($summary['total_paid'])" icon="banknotes" color="emerald"
                                delta-label="received to date, less refunds" />
                {{-- The sign is said in words: a negative remaining is money held in advance, and a minus
                     sign beside "remaining" reads as a debt to most people. --}}
                <x-ui.stat-card label="Total Remaining" :value="money(Money::abs($remaining))" icon="clock"
                                :color="Money::isPositive($remaining) ? 'rose' : (Money::isNegative($remaining) ? 'emerald' : 'slate')"
                                :delta-label="Money::isPositive($remaining) ? 'outstanding' : (Money::isNegative($remaining) ? 'in advance overall' : 'nothing outstanding')" />
                <x-ui.stat-card label="Overdue Payments" :value="money($summary['overdue_amount'])" icon="exclamation-triangle"
                                :color="$summary['overdue_count'] > 0 ? 'rose' : 'slate'"
                                :delta-label="app_number($summary['overdue_count']).' '.Str::plural('admission', $summary['overdue_count']).' overdue'" />
            @else
                <x-ui.stat-card label="Overdue Payments" :value="app_number($summary['overdue_count'])" icon="exclamation-triangle"
                                :color="$summary['overdue_count'] > 0 ? 'rose' : 'slate'"
                                :delta-label="Str::plural('admission', $summary['overdue_count']).' with an overdue charge'" />
            @endif

            <p class="text-xs text-slate-500 dark:text-slate-400 sm:col-span-2 xl:col-span-4">
                @if ($canSeeMoney)
                    Fee totals are for the filtered enrolments and include all payments to date, not only those made in the chosen period.
                @endif
                Overdue is refreshed nightly.
            </p>
        </div>

        <x-ui.table loading="navigating" :is-empty="$rows->isEmpty()" :columns="$columnCount"
                    caption="Students, one row per admission">
            {{-- Sort links are built from the sanitised filters (`_sort-header`), not the raw query. --}}
            <x-slot:head>
                @include('admin.advanced-reports._sort-header', ['column' => 'student_code', 'label' => 'Student ID'])
                @include('admin.advanced-reports._sort-header', ['column' => 'name', 'label' => 'Student Name'])
                <th scope="col" class="px-4 py-3">Phone</th>
                <th scope="col" class="px-4 py-3">Email</th>
                @include('admin.advanced-reports._sort-header', ['column' => 'course', 'label' => 'Course'])
                @include('admin.advanced-reports._sort-header', ['column' => 'batch', 'label' => 'Batch'])
                @include('admin.advanced-reports._sort-header', ['column' => 'joining', 'label' => 'Joining Date', 'default' => 'desc'])
                <th scope="col" class="px-4 py-3">Course Duration</th>
                @include('admin.advanced-reports._sort-header', ['column' => 'completion', 'label' => 'Course Completion Date'])
                @if ($canSeeMoney)
                    @include('admin.advanced-reports._sort-header', ['column' => 'total', 'label' => 'Total Fees', 'default' => 'desc', 'align' => 'right', 'numeric' => true])
                    @include('admin.advanced-reports._sort-header', ['column' => 'paid', 'label' => 'Paid Amount', 'default' => 'desc', 'align' => 'right', 'numeric' => true])
                    @include('admin.advanced-reports._sort-header', ['column' => 'remaining', 'label' => 'Remaining Amount', 'default' => 'desc', 'align' => 'right', 'numeric' => true])
                @endif
                @include('admin.advanced-reports._sort-header', ['column' => 'payment', 'label' => 'Payment Status'])
                @include('admin.advanced-reports._sort-header', ['column' => 'status', 'label' => 'Student Status'])
                <th scope="col" class="px-4 py-3 text-right">Action</th>
            </x-slot:head>

            @foreach ($rows as $row)
                @php($detailUrl = route('admin.advanced-reports.students.show', ['student' => $row->studentId, 'admission' => $row->admissionId]))
                <tr>
                    <td class="whitespace-nowrap font-mono text-xs text-slate-500 dark:text-slate-400">{{ $row->studentCode }}</td>
                    <td class="min-w-[12rem]">
                        <a href="{{ $detailUrl }}" class="font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $row->studentName }}</a>
                        <div class="text-xs text-slate-400 dark:text-slate-500">{{ $row->admissionNumber }}</div>
                    </td>
                    <td class="whitespace-nowrap text-sm">{{ $row->phone ?? '—' }}</td>
                    <td class="max-w-[14rem] truncate text-sm" @if ($row->email) title="{{ $row->email }}" @endif>{{ $row->email ?? '—' }}</td>
                    <td class="min-w-[10rem]">
                        <div class="text-sm text-slate-700 dark:text-slate-200">{{ $row->courseName }}</div>
                        @if ($row->courseCode)
                            <div class="font-mono text-xs text-slate-400 dark:text-slate-500">{{ $row->courseCode }}</div>
                        @endif
                    </td>
                    <td class="whitespace-nowrap">
                        @if ($row->batchLabel() !== null)
                            <div class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $row->batchCode ?? $row->batchName }}</div>
                            @if ($row->batchCode !== null && $row->batchName !== null)
                                <div class="max-w-[12rem] truncate text-xs text-slate-400 dark:text-slate-500" title="{{ $row->batchName }}">{{ $row->batchName }}</div>
                            @endif
                        @else
                            <span class="text-xs text-slate-400 dark:text-slate-500">Not assigned</span>
                        @endif
                    </td>
                    <td class="whitespace-nowrap text-sm">{{ app_date($row->joiningDate) }}</td>
                    <td class="whitespace-nowrap text-sm">{{ $row->courseDuration ?? '—' }}</td>
                    <td class="whitespace-nowrap text-sm">
                        @if ($row->completionDate !== null)
                            {{ app_date($row->completionDate) }}
                            @if ($row->completionIsExpected)
                                <div class="text-xs text-slate-400 dark:text-slate-500">expected</div>
                            @endif
                        @else
                            <span class="text-slate-400 dark:text-slate-500">—</span>
                        @endif
                    </td>
                    @if ($canSeeMoney)
                        <td class="whitespace-nowrap text-right tabular-nums">{{ money($row->totalFees) }}</td>
                        <td class="whitespace-nowrap text-right tabular-nums">{{ money($row->paid) }}</td>
                        <td class="whitespace-nowrap text-right tabular-nums">
                            @if ($row->isInAdvance())
                                <span class="text-emerald-600 dark:text-emerald-400">{{ money(Money::abs($row->remaining)) }}</span>
                                <div class="text-xs text-emerald-600 dark:text-emerald-400">in advance</div>
                            @elseif (Money::isPositive($row->remaining))
                                <span class="font-medium text-rose-600 dark:text-rose-400">{{ money($row->remaining) }}</span>
                            @else
                                {{ money($row->remaining) }}
                            @endif
                        </td>
                    @endif
                    <td class="whitespace-nowrap">
                        <x-ui.badge :color="$row->payment->color()" size="sm">{{ $row->payment->label() }}</x-ui.badge>
                    </td>
                    <td class="whitespace-nowrap">
                        <x-ui.badge :color="$row->status->color()" size="sm">{{ $row->status->label() }}</x-ui.badge>
                    </td>
                    <td class="whitespace-nowrap text-right">
                        <x-ui.button size="sm" variant="link" icon="eye" :href="$detailUrl"
                                     aria-label="View details for {{ $row->studentName }}, {{ $row->courseName }}">View details</x-ui.button>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                @if ($filters->isFiltered())
                    <x-ui.empty-state icon="funnel" title="No students match these filters"
                                      message="Widen the period or clear a filter — every admission inside the filters is listed here.">
                        <x-slot:action>
                            <x-ui.button variant="secondary" icon="arrow-path" :href="route('admin.advanced-reports.index')">Reset Filters</x-ui.button>
                        </x-slot:action>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state icon="document-chart-bar" title="No admissions yet"
                                      message="A row appears here for every admission — one per student per course — as soon as students are admitted." />
                @endif
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$rows" label="records" />
            </x-slot:footer>
        </x-ui.table>
    </div>
@endsection
