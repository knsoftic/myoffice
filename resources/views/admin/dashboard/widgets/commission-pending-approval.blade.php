{{--
    CommissionPendingApprovalWidget body. A queue, so it is about now rather than about the range —
    and the age of the oldest is the part somebody acts on.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="clock" title="Queue unavailable"
                      message="The commission ledger could not be read." :compact="true" />
@else
    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">
                {{ app_number($data['count']) }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                {{ \Illuminate\Support\Str::plural('commission', $data['count']) }} · {{ money($data['total']) }}
            </p>
        </div>

        @if ($data['count'] > 0)
            <div class="border-t border-slate-100 pt-3 text-sm dark:border-slate-800">
                @if (($data['oldest_days'] ?? 0) > 0)
                    <p @class([
                        'flex items-center gap-1.5',
                        'text-amber-600 dark:text-amber-400' => $data['oldest_days'] >= 7,
                        'text-slate-600 dark:text-slate-300' => $data['oldest_days'] < 7,
                    ])>
                        <x-ui.icon name="clock" class="h-4 w-4" />
                        oldest has waited {{ app_number($data['oldest_days']) }}
                        {{ \Illuminate\Support\Str::plural('day', $data['oldest_days']) }}
                    </p>
                @else
                    <p class="text-slate-600 dark:text-slate-300">All from today.</p>
                @endif
            </div>
        @else
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ $widget->emptyMessage }}</p>
        @endif
    </div>
@endif
