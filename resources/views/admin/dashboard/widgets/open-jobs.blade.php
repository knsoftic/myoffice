{{--
    OpenJobsWidget body (phase-04 §8.12) — App\Dashboard\Cms\OpenJobsWidget, key `open_jobs`, module jobs, permission
    jobs.view_any.

    $data (the widget's data()):
      available           bool
      open                int   open openings (standing)
      closing_soon        int   open openings whose deadline falls within the next 7 days
      expired_still_open  int   open openings whose deadline has passed (closed by careers:close-expired at 00:10)
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="briefcase" title="Job openings unavailable" :compact="true" />
@elseif ((int) ($data['open'] ?? 0) === 0)
    <x-ui.empty-state icon="briefcase" title="No open positions" message="The careers page shows its “no current openings” message." :compact="true" />
@else
    @php $jobsUrl = \Illuminate\Support\Facades\Route::has('admin.jobs.index') ? route('admin.jobs.index', ['status' => 'open']) : null; @endphp
    <div class="space-y-4">
        <div class="flex items-end justify-between gap-3">
            <div>
                <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">{{ app_number((int) $data['open']) }}</p>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">open {{ (int) $data['open'] === 1 ? 'position' : 'positions' }}</p>
            </div>
            @if ($jobsUrl)
                <x-ui.button variant="secondary" size="sm" :href="$jobsUrl" icon-trailing="arrow-right">Openings</x-ui.button>
            @endif
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div @class([
                'rounded-xl p-3 ring-1 ring-inset',
                'bg-amber-50 ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/25' => (int) ($data['closing_soon'] ?? 0) > 0,
                'bg-slate-50 ring-slate-200 dark:bg-slate-800/40 dark:ring-slate-700' => (int) ($data['closing_soon'] ?? 0) === 0,
            ])>
                <p class="text-xl font-semibold tabular-nums text-slate-900 dark:text-white">{{ app_number((int) ($data['closing_soon'] ?? 0)) }}</p>
                <p class="text-xs text-slate-600 dark:text-slate-300">closing within 7 days</p>
            </div>
            <div @class([
                'rounded-xl p-3 ring-1 ring-inset',
                'bg-rose-50 ring-rose-200 dark:bg-rose-500/10 dark:ring-rose-500/25' => (int) ($data['expired_still_open'] ?? 0) > 0,
                'bg-slate-50 ring-slate-200 dark:bg-slate-800/40 dark:ring-slate-700' => (int) ($data['expired_still_open'] ?? 0) === 0,
            ])>
                <p class="text-xl font-semibold tabular-nums text-slate-900 dark:text-white">{{ app_number((int) ($data['expired_still_open'] ?? 0)) }}</p>
                <p class="text-xs text-slate-600 dark:text-slate-300">past deadline, closing tonight</p>
            </div>
        </div>
    </div>
@endif
