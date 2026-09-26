{{--
    HeadcountWidget body.

    The headline is the people on the roll — active plus probation — because that is who the
    business can call on. Movement over the selected range sits directly under it: "three joined,
    one left" is an induction and a handover, while the status split below is the reference.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="identification" title="Headcount unavailable"
                      message="The employee register could not be read." :compact="true" />
@elseif (($data['total'] ?? 0) === 0)
    <x-ui.empty-state icon="user-plus" title="No employees yet"
                      message="Nobody has been added to the register, so there is no headcount to report."
                      :compact="true" />
@else
    @php
        $joined = $data['joined'] ?? 0;
        $departed = $data['departed'] ?? 0;
        $probation = $data['probation'] ?? 0;
        $suspended = $data['suspended'] ?? 0;
    @endphp

    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight tabular-nums text-slate-900 dark:text-white">
                {{ $data['on_roll'] }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                on the roll · {{ $data['total'] }} records in all
            </p>
        </div>

        <dl class="space-y-1 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Active</dt>
                <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $data['active'] }}</dd>
            </div>
            @if ($probation > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-sky-600 dark:text-sky-400">On probation</dt>
                    <dd class="font-semibold tabular-nums text-sky-600 dark:text-sky-400">{{ $probation }}</dd>
                </div>
            @endif
            @if ($suspended > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-amber-600 dark:text-amber-400">Suspended</dt>
                    <dd class="font-semibold tabular-nums text-amber-600 dark:text-amber-400">{{ $suspended }}</dd>
                </div>
            @endif
            @if (($data['inactive'] ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">Inactive</dt>
                    <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $data['inactive'] }}</dd>
                </div>
            @endif
            @if (($data['exited'] ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">Left the business</dt>
                    <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $data['exited'] }}</dd>
                </div>
            @endif
        </dl>

        <p class="text-xs text-slate-500 dark:text-slate-400">
            @if ($joined === 0 && $departed === 0)
                Nobody joined or left in {{ $data['range_label'] }}.
            @else
                <span class="font-medium text-emerald-600 dark:text-emerald-400">{{ $joined }} joined</span>
                ·
                <span class="font-medium {{ $departed > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-600 dark:text-slate-300' }}">{{ $departed }} left</span>
                in {{ $data['range_label'] }}.
            @endif
        </p>
    </div>
@endif
