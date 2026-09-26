{{--
    TaskLoadWidget body.

    Unassigned leads: an overdue task has somebody to ask, an unowned one has nobody. The open
    total sits under it, and blocked is kept apart from overdue because it is stopped work rather
    than late work.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="check-circle" title="Tasks unavailable"
                      message="The board could not be read." :compact="true" />
@elseif (($data['open'] ?? 0) === 0)
    <x-ui.empty-state icon="check-circle" title="Board clear"
                      message="No task is open on a live project." :compact="true" />
@else
    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight tabular-nums {{ ($data['unassigned'] ?? 0) > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-white' }}">
                {{ $data['unassigned'] }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                unassigned · {{ $data['open'] }} open
            </p>
        </div>

        <dl class="space-y-1 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
            <div class="flex justify-between gap-3">
                <dt class="{{ ($data['overdue'] ?? 0) > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-slate-500 dark:text-slate-400' }}">
                    Overdue
                </dt>
                <dd class="tabular-nums {{ ($data['overdue'] ?? 0) > 0 ? 'font-semibold text-amber-600 dark:text-amber-400' : 'text-slate-700 dark:text-slate-200' }}">
                    {{ $data['overdue'] }}
                </dd>
            </div>
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Due today</dt>
                <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $data['due_today'] }}</dd>
            </div>
            @if (($data['blocked'] ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">Blocked</dt>
                    <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $data['blocked'] }}</dd>
                </div>
            @endif
        </dl>

        @if (($data['unassigned'] ?? 0) > 0)
            <p class="text-xs text-rose-600 dark:text-rose-400">
                {{ $data['unassigned'] }} open {{ Str::plural('task', $data['unassigned']) }}
                {{ $data['unassigned'] === 1 ? 'has' : 'have' }} nobody working on {{ $data['unassigned'] === 1 ? 'it' : 'them' }}.
            </p>
        @endif
    </div>
@endif
