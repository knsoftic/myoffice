{{--
    HiringWidget body.

    Untriaged applications lead, because "four positions open" was equally true last week while
    "nine CVs nobody has opened" is this morning's work. The adverts sit underneath as the context.

    A viewer holding `jobs.view_any` but not `job_applications.view_any` never sees a candidate
    figure here — the widget did not read them, so there is nothing to hide — and the card falls
    back to leading with the open positions.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="briefcase" title="Hiring unavailable"
                      message="The careers register could not be read." :compact="true" />
@elseif (($data['open_now'] ?? 0) === 0 && ($data['live'] ?? 0) === 0 && ($data['expired_still_open'] ?? 0) === 0)
    <x-ui.empty-state icon="briefcase" title="Nobody is being hired"
                      message="No position is advertised and no candidate is in the pipeline."
                      :compact="true" />
@else
    @php
        $canSeeCandidates = (bool) ($data['applications_visible'] ?? false);
        $untriaged = $data['untriaged'] ?? 0;
        $openNow = $data['open_now'] ?? 0;
        $seats = $data['seats'] ?? 0;
        $oldest = $data['oldest_untriaged_at'] ?? null;
    @endphp

    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            @if ($canSeeCandidates)
                <p class="text-3xl font-semibold tracking-tight tabular-nums {{ $untriaged > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-slate-900 dark:text-white' }}">
                    {{ $untriaged }}
                </p>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                    untriaged · {{ $openNow }} {{ \Illuminate\Support\Str::plural('position', $openNow) }} open
                </p>
            @else
                <p class="text-3xl font-semibold tracking-tight tabular-nums text-slate-900 dark:text-white">
                    {{ $openNow }}
                </p>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                    {{ \Illuminate\Support\Str::plural('position', $openNow) }} open ·
                    {{ $seats }} {{ \Illuminate\Support\Str::plural('seat', $seats) }} to fill
                </p>
            @endif
        </div>

        <dl class="space-y-1 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
            @if ($canSeeCandidates)
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">In the pipeline</dt>
                    <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $data['live'] }}</dd>
                </div>
                @if (($data['unassigned'] ?? 0) > 0)
                    <div class="flex justify-between gap-3">
                        <dt class="text-amber-600 dark:text-amber-400">Nobody reviewing</dt>
                        <dd class="font-semibold tabular-nums text-amber-600 dark:text-amber-400">{{ $data['unassigned'] }}</dd>
                    </div>
                @endif
                @if (($data['interviewing'] ?? 0) > 0)
                    <div class="flex justify-between gap-3">
                        <dt class="text-indigo-600 dark:text-indigo-400">At interview</dt>
                        <dd class="tabular-nums text-indigo-600 dark:text-indigo-400">{{ $data['interviewing'] }}</dd>
                    </div>
                @endif
            @else
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">Seats to fill</dt>
                    <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $seats }}</dd>
                </div>
            @endif

            @if (($data['closing_soon'] ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-amber-600 dark:text-amber-400">Closing within {{ $data['closing_soon_days'] }} days</dt>
                    <dd class="font-semibold tabular-nums text-amber-600 dark:text-amber-400">{{ $data['closing_soon'] }}</dd>
                </div>
            @endif
            @if (($data['expired_still_open'] ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-rose-600 dark:text-rose-400">Past its deadline, still open</dt>
                    <dd class="font-semibold tabular-nums text-rose-600 dark:text-rose-400">{{ $data['expired_still_open'] }}</dd>
                </div>
            @endif
        </dl>

        @if ($canSeeCandidates && $oldest !== null)
            <p class="text-xs text-amber-600 dark:text-amber-400">
                The oldest untouched application arrived {{ app_datetime($oldest) }}.
            </p>
        @elseif ($canSeeCandidates && $untriaged === 0)
            <p class="text-xs text-emerald-600 dark:text-emerald-400">
                Every application has been looked at.
            </p>
        @endif
    </div>
@endif
