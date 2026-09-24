@extends('layouts.admin')

@section('title', 'Reports')

{{--
    The report hub — admin.reports.index (phase-19-23 §7.8, §99).

    Every tile here is a report the viewer may actually open: `ReportRegistry::groupedFor()` has
    already applied the module gate and the full permission stack, so there is nothing on this page
    that leads to a 403. A hub that listed everything and let the click fail would be a hub that
    teaches people to distrust it.

    An unavailable report is shown greyed rather than hidden ([D-P5-1]). Hiding it would make the hub
    look complete; showing it with zeroes would be worse still, because somebody would read the zero
    as the answer.
--}}

@section('header')
    <x-ui.page-header title="Reports"
                      subtitle="Every figure here comes from the service that owns it, so a report and the screen it reports on can never disagree."
                      icon="chart-pie">
        <x-slot:actions>
            @can('reports.export')
                <x-ui.button variant="secondary" icon="arrow-down-tray" :href="route('admin.report-exports.index')">
                    My exports
                </x-ui.button>
            @endcan
            @can('reports.view_reports')
                <x-ui.button variant="secondary" icon="chart-bar" :href="route('admin.analytics.index')">
                    Analytics
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($recentExports->isNotEmpty())
        <x-ui.card class="mb-6">
            <x-ui.section-heading title="Recently exported"
                                  subtitle="Files you asked for. They are kept for a limited time, then the file goes and the record stays." />

            <ul class="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($recentExports as $export)
                    <li class="flex flex-wrap items-center gap-3 py-2 text-sm">
                        <x-ui.badge :color="$export->status->color()">{{ $export->status->label() }}</x-ui.badge>

                        <span class="font-medium text-slate-700 dark:text-slate-200">
                            {{ \App\Support\ReportRegistry::definition($export->report_key)?->title() ?? $export->report_key }}
                        </span>

                        <span class="text-slate-500 dark:text-slate-400">
                            {{ $export->format->label() }} · {{ app_datetime($export->created_at) }}
                            @if ($export->row_count !== null)
                                · {{ app_number($export->row_count) }} rows
                            @endif
                        </span>

                        @if ($export->isDownloadable())
                            <x-ui.button size="sm" variant="link" icon="arrow-down-tray"
                                         :href="route('admin.report-exports.download', $export)">
                                Download
                            </x-ui.button>
                        @elseif ($export->status === \App\Enums\ExportStatus::Failed)
                            {{-- The real reason, shown. A queued job that failed silently is somebody
                                 refreshing a page for ten minutes. --}}
                            <span class="text-rose-600 dark:text-rose-400">{{ $export->failureReason() }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    @forelse ($groups as $slug => $group)
        <section class="mb-8" aria-labelledby="report-group-{{ $slug }}">
            <x-ui.section-heading :title="$group['group']->label()"
                                  :subtitle="$group['group']->description()"
                                  :id="'report-group-' . $slug" />

            <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($group['reports'] as $report)
                    @php($available = $report->isAvailable())

                    <x-ui.card :class="$available ? '' : 'opacity-60'">
                        <div class="flex items-start gap-3">
                            <span @class([
                                'shrink-0 rounded-lg p-2',
                                'bg-' . $group['group']->color() . '-50 text-' . $group['group']->color() . '-600',
                                'dark:bg-' . $group['group']->color() . '-500/10 dark:text-' . $group['group']->color() . '-400',
                            ])>
                                <x-ui.icon :name="$report->icon()" class="h-5 w-5" />
                            </span>

                            <div class="min-w-0 flex-1">
                                <h3 class="truncate font-semibold text-slate-800 dark:text-slate-100">
                                    {{ $report->title() }}
                                </h3>

                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                    {{ $report->description() }}
                                </p>

                                @if ($available)
                                    <div class="mt-3 flex flex-wrap items-center gap-2">
                                        <x-ui.button size="sm" :href="route('admin.reports.show', $report->key())">
                                            Open
                                        </x-ui.button>

                                        @if ($report->dateFilter())
                                            <span class="text-xs text-slate-400 dark:text-slate-500">
                                                Measured on {{ mb_strtolower($report->dateFilter()->label) }}
                                            </span>
                                        @endif
                                    </div>
                                @else
                                    {{-- [D-P5-1]: say which phase brings it, rather than showing a
                                         report of zeroes somebody will read as the answer. --}}
                                    <p class="mt-3 rounded-md bg-amber-50 px-2 py-1 text-xs text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">
                                        {{ $report->unavailableReason() }}
                                    </p>
                                @endif
                            </div>
                        </div>
                    </x-ui.card>
                @endforeach
            </div>
        </section>
    @empty
        <x-ui.empty-state icon="chart-pie"
                          title="No reports are available to you"
                          description="Reports appear here when you hold the reporting permission for a module and that module is switched on." />
    @endforelse
@endsection
