{{--
    DemoClassesTodayWidget body.

    "Still to come" is the headline because it is the only number the front desk can still act on.
    Missed sits on its own line: that is somebody to ring today, not a statistic.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="presentation-chart-bar" title="Demos unavailable"
                      message="Today's demo classes could not be read." :compact="true" />
@elseif (($data['total'] ?? 0) === 0)
    <x-ui.empty-state icon="calendar" title="Nothing booked today"
                      message="No demo class is scheduled for today." :compact="true" />
@else
    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">
                {{ $data['scheduled'] }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                still to come · {{ $data['total'] }} booked today
            </p>
        </div>

        <dl class="space-y-1 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Attended</dt>
                <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $data['attended'] }}</dd>
            </div>
            @if (($data['missed'] ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-amber-600 dark:text-amber-400">No-show</dt>
                    <dd class="font-semibold tabular-nums text-amber-600 dark:text-amber-400">{{ $data['missed'] }}</dd>
                </div>
            @endif
            @if (($data['converted'] ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-emerald-600 dark:text-emerald-400">Became an admission</dt>
                    <dd class="font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $data['converted'] }}</dd>
                </div>
            @endif
        </dl>

        @if (($data['next_name'] ?? null) !== null)
            <p class="truncate text-xs text-slate-500 dark:text-slate-400">
                Next:
                <span class="font-medium text-slate-700 dark:text-slate-200">{{ $data['next_name'] }}</span>
                @if (($data['next_time'] ?? null) !== null)
                    at {{ \Illuminate\Support\Str::of($data['next_time'])->substr(0, 5) }}
                @endif
            </p>
        @endif
    </div>
@endif
