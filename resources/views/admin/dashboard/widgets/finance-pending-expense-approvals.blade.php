{{--
    PendingExpenseApprovalsWidget body. Work, not a figure: money awaiting a decision is real, it is in
    no report, and a queue nobody can see is a queue that grows.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="clock" title="Approvals unavailable"
                      message="The expense register could not be read." :compact="true" />
@elseif (($data['count'] ?? 0) === 0)
    <x-ui.empty-state icon="check-circle" title="Nothing is waiting"
                      message="Every claim has been decided." :compact="true" />
@else
    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight text-amber-600 tabular-nums dark:text-amber-400">
                {{ $data['count'] }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                worth {{ money($data['amount']) }} · in no report until agreed
            </p>
        </div>

        @if ($data['oldest'] ?? null)
            <p class="border-t border-slate-100 pt-3 text-sm text-slate-600 dark:border-slate-800 dark:text-slate-300">
                The oldest has been waiting since {{ app_date($data['oldest']) }}.
            </p>
        @endif
    </div>
@endif
