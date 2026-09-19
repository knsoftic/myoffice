{{--
    NewApplicationsWidget body (phase-04 §8.12) — App\Dashboard\Cms\NewApplicationsWidget, key `new_applications`, module
    job_applications, permission .view_any.

    $data (the widget's data()):
      available       bool
      total           int     applications received in the range
      funnel          list<array{value: string, label: string, color: string, count: int, href: ?string}>  per stage, in
                      pipeline order
      delta           array   against the previous window
      range_label     string
      previous_label  ?string
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="document-text" title="Applications unavailable" :compact="true" />
@else
    @php
        $stages = collect($data['funnel'] ?? []);
        $max = max(1, (int) $stages->max('count'));
        $bar = ['sky' => 'bg-sky-500', 'indigo' => 'bg-indigo-500', 'violet' => 'bg-violet-500', 'purple' => 'bg-purple-500', 'amber' => 'bg-amber-500', 'emerald' => 'bg-emerald-500', 'green' => 'bg-green-500', 'rose' => 'bg-rose-500', 'red' => 'bg-red-500', 'slate' => 'bg-slate-400', 'blue' => 'bg-blue-500', 'teal' => 'bg-teal-500'];
    @endphp
    <div class="space-y-4">
        <div class="flex items-end justify-between gap-3">
            <div>
                <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">{{ app_number((int) ($data['total'] ?? 0)) }}</p>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">received in {{ $data['range_label'] ?? 'this period' }}</p>
            </div>
            @if (\Illuminate\Support\Facades\Route::has('admin.job-applications.index'))
                <x-ui.button variant="secondary" size="sm" :href="route('admin.job-applications.index')" icon-trailing="arrow-right">Pipeline</x-ui.button>
            @endif
        </div>

        @if ($stages->isEmpty() || (int) $stages->sum('count') === 0)
            <p class="text-sm text-slate-500 dark:text-slate-400">No candidates in this period.</p>
        @else
            <ul class="space-y-2" aria-label="Candidates per stage">
                @foreach ($stages as $stage)
                    @php $share = (int) round(((int) ($stage['count'] ?? 0) / $max) * 100); @endphp
                    <li>
                        <div class="flex items-center justify-between text-xs">
                            @if (filled($stage['href'] ?? null))
                                <a href="{{ $stage['href'] }}" class="text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">{{ $stage['label'] ?? '' }}</a>
                            @else
                                <span class="text-slate-600 dark:text-slate-300">{{ $stage['label'] ?? '' }}</span>
                            @endif
                            <span class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ app_number((int) ($stage['count'] ?? 0)) }}</span>
                        </div>
                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" aria-hidden="true">
                            <div class="h-full rounded-full {{ $bar[$stage['color'] ?? ''] ?? 'bg-brand-500' }}" style="width: {{ $share }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        @if (isset($data['delta']))
            <div class="border-t border-slate-100 pt-3 dark:border-slate-800">
                @include('admin.dashboard.partials.delta', ['delta' => $data['delta'], 'against' => $data['previous_label'] ?? null])
            </div>
        @endif
    </div>
@endif
