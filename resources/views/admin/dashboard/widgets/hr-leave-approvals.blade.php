{{--
    LeaveApprovalsWidget body.

    The count is the headline and the oldest wait is the line under it: six filed this morning is a
    busy day, one that has sat for nine days is somebody who has already booked a flight. A pending
    request whose leave has already begun gets its own colour, because it is the one row in the
    queue that cannot wait for tomorrow.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="calendar" title="Leave queue unavailable"
                      message="The leave register could not be read." :compact="true" />
@elseif (($data['waiting'] ?? 0) === 0)
    <x-ui.empty-state icon="check-circle" title="Nothing to decide"
                      message="Every leave request has an answer." :compact="true" />
@else
    @php
        $started = $data['already_started'] ?? 0;
        $waited = $data['oldest_wait_days'] ?? null;
        $oldest = $data['oldest_applied_on'] ?? null;
        $stale = $started > 0 || ($waited !== null && $waited >= 3);
    @endphp

    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight tabular-nums {{ $stale ? 'text-amber-600 dark:text-amber-400' : 'text-slate-900 dark:text-white' }}">
                {{ $data['waiting'] }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                waiting for a decision
            </p>
        </div>

        <dl class="space-y-1 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
            @if ($started > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-rose-600 dark:text-rose-400">Leave has already begun</dt>
                    <dd class="font-semibold tabular-nums text-rose-600 dark:text-rose-400">{{ $started }}</dd>
                </div>
            @endif
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Starts within {{ $data['soon_days'] }} days</dt>
                <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $data['starting_soon'] }}</dd>
            </div>
            @if (($data['part_approved'] ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">Past the first approver</dt>
                    <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $data['part_approved'] }}</dd>
                </div>
            @endif
        </dl>

        @if ($oldest !== null)
            <p class="text-xs {{ $stale ? 'text-amber-600 dark:text-amber-400' : 'text-slate-500 dark:text-slate-400' }}">
                @if ($waited === 0)
                    The oldest was filed today ({{ app_date($oldest) }}).
                @else
                    The oldest has waited
                    <span class="font-semibold tabular-nums">{{ $waited }}</span>
                    {{ \Illuminate\Support\Str::plural('day', $waited) }},
                    since {{ app_date($oldest) }}.
                @endif
            </p>
        @endif
    </div>
@endif
