{{--
    PendingFeesWidget body (phase-18 §8.10).

    Outstanding, what lands this week, and credit balances — three numbers rather than one, because a
    desk needs to know what is arriving, not only what is owed in aggregate. **Advances are counted
    separately and never netted off**: "outstanding 200,000" when 40,000 of it is somebody else's
    credit sends people to chase the wrong students.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="clock" title="Fees unavailable"
                      message="The charges could not be read." :compact="true" />
@else
    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">
                {{ money($data['outstanding']) }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                across {{ app_number($data['charges']) }} {{ (int) $data['charges'] === 1 ? 'charge' : 'charges' }}
            </p>
        </div>

        <div class="space-y-1.5 border-t border-slate-100 pt-3 text-sm dark:border-slate-800">
            <p class="flex items-center justify-between text-slate-600 dark:text-slate-300">
                <span>Due in the next 7 days</span>
                <span class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($data['due_this_week']) }}</span>
            </p>
            @if ((int) $data['advances_count'] > 0)
                <p class="flex items-center justify-between text-emerald-600 dark:text-emerald-400">
                    <span>In advance ({{ app_number($data['advances_count']) }})</span>
                    <span class="font-semibold tabular-nums">{{ money($data['advances']) }}</span>
                </p>
            @endif
        </div>
    </div>
@endif
