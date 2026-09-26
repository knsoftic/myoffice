{{--
    MyTimeThisWeekWidget body — the viewer's own hours, nobody else's.

    The running badge sits above the total on purpose. "Six hours logged" and "six hours logged and
    the clock is still going" are different facts, and the second is the one that changes what
    somebody does in the next minute — usually stopping a timer they left running over lunch.

    The total is banked time only: a running timer's live seconds are computed in the browser and
    never persisted (INV-P5), so this figure deliberately trails a running clock.

    A render with no signed-in viewer reports zero and lands in the empty branch, rather than
    showing the whole team's timesheet on a card labelled "my".
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="clock" title="Timesheet unavailable"
                      message="Your hours for this week could not be read." :compact="true" />
@elseif (($data['week_seconds'] ?? 0) === 0 && ! ($data['running'] ?? false) && ! ($data['paused'] ?? false))
    <x-ui.empty-state icon="clock" title="No time logged yet"
                      message="Nothing is on your timesheet for this week." :compact="true" />
@else
    @php
        $running = $data['running'] ?? false;
        $paused = $data['paused'] ?? false;
        $days = $data['days_logged'] ?? 0;
    @endphp

    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            @if ($running)
                <p class="mb-1 inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30">
                    <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500 dark:bg-emerald-400" aria-hidden="true"></span>
                    Your timer is running
                </p>
            @elseif ($paused)
                <p class="mb-1 inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30">
                    <span class="h-1.5 w-1.5 rounded-full bg-amber-500 dark:bg-amber-400" aria-hidden="true"></span>
                    Your timer is paused
                </p>
            @endif

            <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">
                {{ $data['week_hours'] }}<span class="text-xl font-medium text-slate-400 dark:text-slate-500">h</span>
                {{ $data['week_minutes'] }}<span class="text-xl font-medium text-slate-400 dark:text-slate-500">m</span>
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                logged across {{ $days }} {{ \Illuminate\Support\Str::plural('day', $days) }} this week
            </p>
        </div>

        <dl class="space-y-1 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Today</dt>
                <dd class="tabular-nums text-slate-700 dark:text-slate-200">
                    {{ $data['today_hours'] }}h {{ $data['today_minutes'] }}m
                </dd>
            </div>
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Entries</dt>
                <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $data['entries'] }}</dd>
            </div>
        </dl>

        @if ($running)
            <p class="text-xs text-slate-500 dark:text-slate-400">
                The total above is banked time — the running session is still adding to it.
            </p>
        @endif
    </div>
@endif
