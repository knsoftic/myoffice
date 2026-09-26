{{--
    AttendanceGapsWidget body.

    The count of unmarked registers is the headline and the oldest one is the line under it: two
    from this morning is a teacher who has not got to a laptop yet, two from eleven days ago is a
    register nobody can now fill in honestly. Anything older than a week turns the card rose,
    because at that point it has stopped being an oversight.

    The denominator ("of 42 classes held") is there so the number has a size. No student appears
    here: these are counts of sessions, never of people.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="clipboard-document-check" title="Registers unavailable"
                      message="The attendance register could not be read." :compact="true" />
@elseif (($data['held'] ?? 0) === 0)
    <x-ui.empty-state icon="calendar" title="No classes held"
                      message="Nothing has been taught in the last {{ $data['window_days'] ?? 14 }} days, so there is no register to fill in."
                      :compact="true" />
@elseif (($data['unmarked'] ?? 0) === 0)
    <x-ui.empty-state icon="check-circle" title="Every register is in"
                      message="All {{ $data['held'] }} classes held in the last {{ $data['window_days'] ?? 14 }} days have been marked."
                      :compact="true" />
@else
    @php
        $stale = ($data['stale'] ?? 0) > 0;
        $tone = $stale
            ? 'text-rose-600 dark:text-rose-400'
            : 'text-amber-600 dark:text-amber-400';
    @endphp

    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight tabular-nums {{ $tone }}">
                {{ $data['unmarked'] }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                unmarked · of {{ $data['held'] }} classes held in the last {{ $data['window_days'] }} days
            </p>
        </div>

        <dl class="space-y-1 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Marked</dt>
                <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $data['marked'] }}</dd>
            </div>
            @if ($stale)
                <div class="flex justify-between gap-3">
                    <dt class="text-rose-600 dark:text-rose-400">Over {{ $data['stale_after_days'] }} days old</dt>
                    <dd class="font-semibold tabular-nums text-rose-600 dark:text-rose-400">{{ $data['stale'] }}</dd>
                </div>
            @endif
            @if (($data['batches'] ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">Batches affected</dt>
                    <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $data['batches'] }}</dd>
                </div>
            @endif
        </dl>

        @if (($data['oldest'] ?? null) !== null)
            <p class="text-xs {{ $stale ? 'text-rose-600 dark:text-rose-400' : 'text-slate-500 dark:text-slate-400' }}">
                Oldest is
                <span class="font-medium">{{ app_date($data['oldest']) }}</span>.
            </p>
        @endif
    </div>
@endif
