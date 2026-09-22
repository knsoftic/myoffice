@extends('layouts.admin')

@section('title', 'Attendance — monthly matrix')

@section('header')
    <x-ui.page-header title="Attendance reports"
                      subtitle="Students down, class days across. A cell is empty only where somebody was not on the roster that day."
                      icon="table-cells"
                      :back="route('admin.student-attendance.index')" />
@endsection

@section('content')
    <x-ui.card class="mb-4" :padded="false">
        @include('admin.attendance.reports._tabs', ['active' => 'monthly'])

        <form method="GET" class="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.form.select name="batch_id" label="Batch" required placeholder="Pick a batch">
                @foreach ($batches as $id => $code)
                    <option value="{{ $id }}" @selected((int) request('batch_id') === (int) $id)>{{ $code }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="month" label="Month">
                @foreach (range(1, 12) as $m)
                    <option value="{{ $m }}" @selected($month === $m)>{{ app_date(\Illuminate\Support\Carbon::create(2000, $m, 1), 'F') }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.input name="year" label="Year" type="number" min="2000" max="2100" :value="$year" />

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Show</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    @if ($batch === null)
        <x-ui.card>
            <x-ui.empty-state icon="table-cells" title="Pick a batch"
                              description="A student-by-day matrix across every batch in the institute is not a report, it is a wall. Choose one batch above." />
        </x-ui.card>
    @elseif ($report['sessions']->isEmpty())
        <x-ui.card>
            <x-ui.empty-state icon="calendar-days"
                              :title="'This batch held no classes in '.app_date($report['from'], 'F Y')"
                              description="Only classes that were actually held appear here — a cancelled one is nobody's absence." />
        </x-ui.card>
    @else
        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <x-ui.stat-card label="Classes held" :value="app_number($report['sessions']->count())" icon="check" color="emerald" />
            <x-ui.stat-card label="Students" :value="app_number($report['rows']->count())" icon="users" color="slate" />
            <x-ui.stat-card label="Batch average" :value="$report['average'].'%'" icon="chart-bar" color="brand" />
        </div>

        <x-ui.card :title="$batch->code.' · '.app_date($report['from'], 'F Y')" :padded="false">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-900/60 dark:text-slate-400">
                        <tr>
                            {{-- The frozen first column: a matrix you cannot read the names of is unusable. --}}
                            <th class="sticky left-0 z-10 bg-slate-50 px-4 py-3 text-left font-semibold dark:bg-slate-900">Student</th>
                            @foreach ($report['sessions'] as $session)
                                <th class="px-2 py-3 text-center font-semibold" title="{{ app_date($session->session_date) }} {{ app_clock($session->start_time) }}">
                                    {{ app_date($session->session_date, 'd') }}
                                </th>
                            @endforeach
                            <th class="px-3 py-3 text-center font-semibold">P</th>
                            <th class="px-3 py-3 text-center font-semibold">A</th>
                            <th class="px-3 py-3 text-center font-semibold">L</th>
                            <th class="px-3 py-3 text-center font-semibold">Lt</th>
                            <th class="px-3 py-3 text-center font-semibold">%</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200/70 dark:divide-slate-800">
                        @foreach ($report['rows'] as $row)
                            <tr>
                                <td class="sticky left-0 z-10 bg-white px-4 py-2.5 dark:bg-slate-900">
                                    <div class="font-medium text-slate-700 dark:text-slate-200">
                                        {{ $row->roll_number ? $row->roll_number.' · ' : '' }}{{ $row->student_name }}
                                    </div>
                                    <div class="text-xs text-slate-400">{{ $row->student_code }}</div>
                                </td>

                                @foreach ($report['sessions'] as $session)
                                    @php($cell = $row->cells[(int) $session->id] ?? null)
                                    <td class="px-2 py-2.5 text-center">
                                        @if ($cell === null || ! $cell['on_roster'])
                                            {{-- Not on the roster that day: no cell, not a blank that reads like an absence. --}}
                                            <span class="text-slate-200 dark:text-slate-700" title="Not enrolled on this date">—</span>
                                        @elseif ($cell['status'] === null)
                                            <span class="text-amber-400" title="Not marked">·</span>
                                        @else
                                            <x-ui.badge :color="$cell['status']->color()" size="xs">{{ $cell['status']->glyph() }}</x-ui.badge>
                                        @endif
                                    </td>
                                @endforeach

                                <td class="px-3 py-2.5 text-center text-emerald-600 dark:text-emerald-400">{{ $row->counts['present'] }}</td>
                                <td class="px-3 py-2.5 text-center text-rose-600 dark:text-rose-400">{{ $row->counts['absent'] }}</td>
                                <td class="px-3 py-2.5 text-center text-sky-600 dark:text-sky-400">{{ $row->counts['leave'] }}</td>
                                <td class="px-3 py-2.5 text-center text-amber-600 dark:text-amber-400">{{ $row->counts['late'] }}</td>
                                <td class="px-3 py-2.5 text-center font-medium text-slate-700 dark:text-slate-200">{{ $row->percentage }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-slate-50 text-xs dark:bg-slate-900/60">
                        <tr>
                            <td class="sticky left-0 z-10 bg-slate-50 px-4 py-2.5 font-semibold text-slate-600 dark:bg-slate-900 dark:text-slate-300">
                                Class on the day
                            </td>
                            @foreach ($report['sessions'] as $session)
                                <td class="px-2 py-2.5 text-center text-slate-500">
                                    {{ $report['columns'][(int) $session->id]['percentage'] }}%
                                </td>
                            @endforeach
                            <td colspan="4"></td>
                            <td class="px-3 py-2.5 text-center font-semibold text-slate-700 dark:text-slate-200">{{ $report['average'] }}%</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="border-t border-slate-200/70 px-4 py-3 text-xs text-slate-400 dark:border-slate-800">
                {{ $batch->code }} · {{ app_date($report['from'], 'F Y') }} ·
                {{ $report['sessions']->count() }} classes held ·
                P present, A absent, L leave, Lt late; — means not enrolled that day, · means not marked.
            </div>
        </x-ui.card>
    @endif
@endsection
