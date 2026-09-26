{{--
    AttendanceTodayWidget body.

    The headline is how many of the expected roll are actually in. The line that earns the card its
    place is the last one: people with no attendance row at all are not absent, they are
    unaccounted for, and that is somebody's job before the day ends.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="calendar-days" title="Attendance unavailable"
                      message="Today's register could not be read." :compact="true" />
@elseif (($data['expected'] ?? 0) === 0)
    <x-ui.empty-state icon="user-plus" title="Nobody is expected in"
                      message="No active employee had joined by today, so there is no register to keep."
                      :compact="true" />
@else
    @php
        $expected = $data['expected'];
        $unmarked = $data['unmarked'] ?? 0;
        $absent = $data['absent'] ?? 0;
    @endphp

    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight tabular-nums text-slate-900 dark:text-white">
                {{ $data['in_today'] }}<span class="text-lg font-normal text-slate-400 dark:text-slate-500">/{{ $expected }}</span>
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                in today · {{ app_date($data['date']) }}
            </p>
        </div>

        <dl class="space-y-1 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Present</dt>
                <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $data['present'] }}</dd>
            </div>
            @if (($data['late'] ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-amber-600 dark:text-amber-400">Late</dt>
                    <dd class="font-semibold tabular-nums text-amber-600 dark:text-amber-400">{{ $data['late'] }}</dd>
                </div>
            @endif
            <div class="flex justify-between gap-3">
                <dt class="{{ $absent > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-500 dark:text-slate-400' }}">Absent</dt>
                <dd class="tabular-nums {{ $absent > 0 ? 'font-semibold text-rose-600 dark:text-rose-400' : 'text-slate-700 dark:text-slate-200' }}">{{ $absent }}</dd>
            </div>
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">On leave</dt>
                <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $data['on_leave'] }}</dd>
            </div>
            @if (($data['on_holiday'] ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-violet-600 dark:text-violet-400">Holiday or weekly off</dt>
                    <dd class="tabular-nums text-violet-600 dark:text-violet-400">{{ $data['on_holiday'] }}</dd>
                </div>
            @endif
        </dl>

        @if ($unmarked > 0)
            <p class="text-xs text-amber-600 dark:text-amber-400">
                <span class="font-semibold tabular-nums">{{ $unmarked }}</span>
                not marked yet — the register is incomplete.
            </p>
        @else
            <p class="text-xs text-emerald-600 dark:text-emerald-400">
                Everybody expected in has been accounted for.
            </p>
        @endif
    </div>
@endif
