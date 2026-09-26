{{--
    MyOpenTasksWidget body — the viewer's own tasks and nobody else's.

    An empty plate is genuinely good news, so the empty state says so rather than showing a zero:
    a dashboard that looks broken when the work is done is a dashboard people stop trusting.

    A render with no signed-in viewer (a console warm-up) lands in the same empty branch, because
    the widget reports zero rather than everybody's rows when it cannot tell who is reading.

    Nothing here names another person — these are the viewer's own cards.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="check-circle" title="Tasks unavailable"
                      message="Your task list could not be read." :compact="true" />
@elseif (($data['open'] ?? 0) === 0)
    <x-ui.empty-state icon="sparkles" title="Nothing on your plate"
                      message="No open task is assigned to you. Enjoy it." :compact="true" />
@else
    @php
        $overdue = $data['overdue'] ?? 0;
        $dueToday = $data['due_today'] ?? 0;
        $blocked = $data['blocked'] ?? 0;
        $nextDue = $data['next_due'] ?? null;
    @endphp

    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p @class([
                'text-3xl font-semibold tracking-tight tabular-nums',
                'text-rose-600 dark:text-rose-400' => $overdue > 0,
                'text-slate-900 dark:text-white' => $overdue === 0,
            ])>
                {{ $data['open'] }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                assigned to you and still open
            </p>
        </div>

        <dl class="space-y-1 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
            @if ($overdue > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-rose-600 dark:text-rose-400">Past its due date</dt>
                    <dd class="font-semibold tabular-nums text-rose-600 dark:text-rose-400">{{ $overdue }}</dd>
                </div>
            @endif

            <div class="flex justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Due today</dt>
                <dd @class([
                    'tabular-nums',
                    'font-semibold text-amber-600 dark:text-amber-400' => $dueToday > 0,
                    'text-slate-700 dark:text-slate-200' => $dueToday === 0,
                ])>{{ $dueToday }}</dd>
            </div>

            @if ($blocked > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-amber-600 dark:text-amber-400">Blocked</dt>
                    <dd class="font-semibold tabular-nums text-amber-600 dark:text-amber-400">{{ $blocked }}</dd>
                </div>
            @endif
        </dl>

        @if ($overdue === 0 && $dueToday === 0 && $nextDue !== null)
            <p class="text-xs text-slate-500 dark:text-slate-400">
                Nothing is due today — the next deadline is {{ app_date($nextDue) }}.
            </p>
        @endif
    </div>
@endif
