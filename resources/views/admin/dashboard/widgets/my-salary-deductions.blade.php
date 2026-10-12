{{--
    MySalaryDeductionsWidget body — the viewer's own attendance deductions, nobody else's.

    The pending figure is what payroll has still to take (each row by the run for its own month); the list underneath is the
    latest notices, each with its reason, amount, status and date, so an employee sees why before payday.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="receipt-percent" title="Deductions unavailable"
                      message="Your salary deductions could not be read." :compact="true" />
@elseif (($data['recent'] ?? []) === [])
    <x-ui.empty-state icon="receipt-percent" title="No salary deductions"
                      message="Nothing has been deducted from your salary for attendance." :compact="true" />
@else
    <div class="flex h-full flex-col gap-3">
        <div>
            <p @class([
                'text-3xl font-semibold tracking-tight tabular-nums',
                'text-amber-600 dark:text-amber-400' => ($data['pending_count'] ?? 0) > 0,
                'text-slate-900 dark:text-white' => ($data['pending_count'] ?? 0) === 0,
            ])>{{ money((string) $data['pending_total']) }}</p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                pending · {{ $data['pending_count'] }} deduction(s), taken at payroll
            </p>
        </div>

        <ul class="space-y-2 border-t border-slate-100 pt-3 dark:border-slate-800">
            @foreach ($data['recent'] as $item)
                <li class="text-sm">
                    <div class="flex items-center justify-between gap-2">
                        <x-ui.badge :color="$item['type_color']" size="xs">{{ $item['type'] }}</x-ui.badge>
                        <span class="flex items-center gap-2">
                            <span class="tabular-nums font-medium text-slate-900 dark:text-white">{{ money($item['amount']) }}</span>
                            <x-ui.badge :color="$item['status_color']" size="xs">{{ $item['status'] }}</x-ui.badge>
                        </span>
                    </div>
                    <p class="mt-1 text-xs text-slate-600 dark:text-slate-300">{{ $item['text'] }}</p>
                    @if ($item['at'])
                        <p class="text-xs text-slate-400 dark:text-slate-500">{{ app_date($item['at']) }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@endif
