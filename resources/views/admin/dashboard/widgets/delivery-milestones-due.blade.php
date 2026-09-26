{{--
    MilestonesDueWidget body.

    A missed milestone leads, because it is a promise to a client that has already been broken; the
    fortnight ahead is the workload underneath it. The oldest missed date is named so the reader has
    something concrete to weigh rather than a count.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="flag" title="Milestones unavailable"
                      message="The milestone list could not be read." :compact="true" />
@elseif (($data['open'] ?? 0) === 0)
    <x-ui.empty-state icon="flag" title="Nothing outstanding"
                      message="No live project has a milestone still to deliver." :compact="true" />
@else
    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight tabular-nums {{ ($data['missed'] ?? 0) > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-white' }}">
                {{ $data['missed'] }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                missed · {{ $data['due_fortnight'] }} due in the next {{ $data['horizon_days'] ?? 14 }} days
            </p>
        </div>

        <dl class="space-y-1 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
            <div class="flex justify-between gap-3">
                <dt class="{{ ($data['due_week'] ?? 0) > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-slate-500 dark:text-slate-400' }}">
                    Due this week
                </dt>
                <dd class="tabular-nums {{ ($data['due_week'] ?? 0) > 0 ? 'font-semibold text-amber-600 dark:text-amber-400' : 'text-slate-700 dark:text-slate-200' }}">
                    {{ $data['due_week'] }}
                </dd>
            </div>
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Still outstanding</dt>
                <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $data['open'] }}</dd>
            </div>
            @if (($data['undated'] ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">No date set</dt>
                    <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $data['undated'] }}</dd>
                </div>
            @endif
        </dl>

        @if (($data['missed'] ?? 0) > 0 && filled($data['oldest_missed'] ?? null))
            <p class="text-xs text-rose-600 dark:text-rose-400">
                The oldest was promised for {{ app_date($data['oldest_missed']) }}.
            </p>
        @endif
    </div>
@endif
