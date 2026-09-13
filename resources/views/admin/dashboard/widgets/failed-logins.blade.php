{{--
    FailedLoginsWidget body.

    Failed and blocked are kept apart because they mean different things: a wrong password versus a
    correct password on an account whose status forbids login. Zero is shown in slate, not red — a
    quiet card should look quiet.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state
        icon="exclamation-triangle"
        title="Login history unavailable"
        message="The login history table could not be read."
        :compact="true"
    />
@else
    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p @class([
                'text-3xl font-semibold tracking-tight tabular-nums',
                'text-rose-600 dark:text-rose-400' => $data['failed'] > 0,
                'text-slate-900 dark:text-white' => $data['failed'] === 0,
            ])>
                {{ app_number($data['failed']) }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                failed attempts · {{ $data['range_label'] }}
            </p>
        </div>

        @if ($data['total'] > 0)
            <div class="space-y-1.5 text-xs">
                @if ($data['blocked'] > 0)
                    <p class="flex items-center justify-between gap-2">
                        <span class="inline-flex items-center gap-1.5 text-slate-600 dark:text-slate-300">
                            <x-ui.badge color="amber" size="xs" :dot="true">blocked</x-ui.badge>
                            account state refused a correct password
                        </span>

                        @if ($data['blocked_href'] ?? null)
                            <a href="{{ $data['blocked_href'] }}" class="shrink-0 font-semibold tabular-nums text-amber-600 hover:underline dark:text-amber-400">
                                {{ app_number($data['blocked']) }}
                            </a>
                        @else
                            <span class="shrink-0 font-semibold tabular-nums text-slate-900 dark:text-white">{{ app_number($data['blocked']) }}</span>
                        @endif
                    </p>
                @endif

                <p class="flex items-center justify-between gap-2 text-slate-600 dark:text-slate-300">
                    <span class="inline-flex items-center gap-1.5">
                        <x-ui.icon name="identification" class="h-3.5 w-3.5 text-slate-400 dark:text-slate-500" />
                        distinct accounts or addresses
                    </span>
                    <span class="shrink-0 font-semibold tabular-nums text-slate-900 dark:text-white">
                        {{ app_number($data['identities']) }}
                    </span>
                </p>
            </div>
        @else
            <p class="inline-flex items-center gap-1.5 text-sm text-emerald-600 dark:text-emerald-400">
                <x-ui.icon name="check-circle" class="h-4 w-4" />
                {{ $widget->emptyMessage }}
            </p>
        @endif

        <div class="border-t border-slate-100 pt-3 dark:border-slate-800">
            @include('admin.dashboard.partials.delta', [
                'delta' => $data['delta'],
                'against' => $data['previous_label'],
            ])
        </div>
    </div>
@endif
