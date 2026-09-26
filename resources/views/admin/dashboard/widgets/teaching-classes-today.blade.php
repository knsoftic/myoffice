{{--
    ClassesTodayWidget body.

    "Still to run" is the headline because it is the only figure the coordinator can still act on;
    "held" is yesterday's news by lunchtime. `unclosed` sits on its own line in amber: a class whose
    slot ended an hour ago and is still marked scheduled is a session nobody closed off, and rolled
    into "still to run" it would read as teaching that has not happened yet.

    Times come from TIME columns and are printed with app_clock(), which renders the stored wall
    clock without pushing it through a timezone — a class at 09:00 is 09:00.

    No student appears here. Every figure is a count of sessions.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="calendar-days" title="Today's classes unavailable"
                      message="The class list could not be read." :compact="true" />
@elseif (($data['total'] ?? 0) === 0)
    <x-ui.empty-state icon="calendar" title="Nothing on today"
                      message="Either today is a day off, or today's sessions have not been generated from the timetable yet."
                      :compact="true" />
@elseif (($data['standing'] ?? 0) === 0)
    <x-ui.empty-state icon="x-circle" title="Today is clear"
                      message="Every class dated today was cancelled or moved." :compact="true" />
@else
    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">
                {{ $data['remaining'] }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                still to run · {{ $data['standing'] }} on today's timetable
            </p>
        </div>

        <dl class="space-y-1 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Already held</dt>
                <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $data['held'] }}</dd>
            </div>
            @if (($data['in_progress'] ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-emerald-600 dark:text-emerald-400">In progress now</dt>
                    <dd class="font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $data['in_progress'] }}</dd>
                </div>
            @endif
            @if (($data['unclosed'] ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-amber-600 dark:text-amber-400">Over, not closed off</dt>
                    <dd class="font-semibold tabular-nums text-amber-600 dark:text-amber-400">{{ $data['unclosed'] }}</dd>
                </div>
            @endif
            @if (($data['cancelled'] ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-rose-600 dark:text-rose-400">Cancelled</dt>
                    <dd class="tabular-nums text-rose-600 dark:text-rose-400">{{ $data['cancelled'] }}</dd>
                </div>
            @endif
            @if (($data['moved'] ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">Rescheduled</dt>
                    <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $data['moved'] }}</dd>
                </div>
            @endif
        </dl>

        @if (($data['next_start'] ?? null) !== null)
            <p class="text-xs text-slate-500 dark:text-slate-400">
                Next class starts at
                <span class="font-medium text-slate-700 dark:text-slate-200">{{ app_clock($data['next_start']) }}</span>.
            </p>
        @elseif (($data['remaining'] ?? 0) === 0)
            <p class="text-xs text-slate-500 dark:text-slate-400">
                Nothing left to start today.
            </p>
        @endif
    </div>
@endif
