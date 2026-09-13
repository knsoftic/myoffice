{{--
    LoginsTodayWidget body. One figure, its comparison, and the distinct-people reading that keeps
    "200 sign-ins" from being mistaken for "200 people".
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state
        icon="finger-print"
        title="Login history unavailable"
        message="The login history table could not be read."
        :compact="true"
    />
@else
    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">
                {{ app_number($data['current']) }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                successful sign-ins · {{ $data['range_label'] }}
            </p>
        </div>

        @if ($data['current'] > 0)
            <div class="flex items-baseline gap-1.5 text-sm">
                <span class="inline-flex items-center gap-1.5 text-slate-600 dark:text-slate-300">
                    <x-ui.icon name="users" class="h-4 w-4 text-slate-400 dark:text-slate-500" />
                    <span class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ app_number($data['people']) }}</span>
                    {{ \Illuminate\Support\Str::plural('account', $data['people']) }}
                </span>

                @if ($data['per_person'] !== null && $data['per_person'] > 1)
                    <span class="text-xs text-slate-400 dark:text-slate-500">
                        · {{ app_number($data['per_person'], 1) }} each
                    </span>
                @endif
            </div>
        @else
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ $widget->emptyMessage }}</p>
        @endif

        <div class="border-t border-slate-100 pt-3 dark:border-slate-800">
            @include('admin.dashboard.partials.delta', [
                'delta' => $data['delta'],
                'against' => $data['previous_label'],
            ])
        </div>
    </div>
@endif
