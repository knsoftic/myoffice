{{--
    SupportTicketQueueWidget body.

    The open count is the headline, but the two lines under it are the ones somebody acts on: a
    ticket nobody has claimed and a ticket already past its promise are different failures, and
    rolled into one "open" number both disappear.

    The breach line is coloured only when there is a breach. A card that is permanently red teaches
    people to stop looking at it.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="lifebuoy" title="Queue unavailable"
                      message="The support queue could not be read." :compact="true" />
@elseif (($data['open'] ?? 0) === 0)
    <x-ui.empty-state icon="check-circle" title="The queue is clear"
                      message="Nothing is open. Every ticket has had its answer." :compact="true" />
@else
    @php
        $breached = $data['breached'] ?? 0;
        $unassigned = $data['unassigned'] ?? 0;
        $oldest = $data['oldest_at'] ?? null;
    @endphp

    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p @class([
                'text-3xl font-semibold tracking-tight tabular-nums',
                'text-rose-600 dark:text-rose-400' => $breached > 0,
                'text-slate-900 dark:text-white' => $breached === 0,
            ])>
                {{ $data['open'] }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                open · {{ $data['unanswered'] }} still waiting on a first reply
            </p>
        </div>

        <dl class="space-y-1 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">
                    @if (($data['unassigned_link'] ?? null) && $unassigned > 0)
                        <a href="{{ $data['unassigned_link'] }}" class="hover:underline">Nobody has claimed</a>
                    @else
                        Nobody has claimed
                    @endif
                </dt>
                <dd @class([
                    'tabular-nums',
                    'font-semibold text-amber-600 dark:text-amber-400' => $unassigned > 0,
                    'text-slate-700 dark:text-slate-200' => $unassigned === 0,
                ])>{{ $unassigned }}</dd>
            </div>

            @if ($breached > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-rose-600 dark:text-rose-400">
                        @if ($data['breached_link'] ?? null)
                            <a href="{{ $data['breached_link'] }}" class="hover:underline">Past its SLA</a>
                        @else
                            Past its SLA
                        @endif
                    </dt>
                    <dd class="font-semibold tabular-nums text-rose-600 dark:text-rose-400">{{ $breached }}</dd>
                </div>
            @endif
        </dl>

        @if ($oldest !== null)
            <p class="text-xs text-slate-500 dark:text-slate-400">
                Oldest open ticket has waited {{ $oldest->diffForHumans(null, true) }}.
            </p>
        @endif
    </div>
@endif
