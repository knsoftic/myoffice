{{--
    ActiveBatchesWidget body.

    "Running" is the headline and "enrolling" the line under it, because those are the two questions
    a coordinator is asked all day: what is being taught, and where can somebody still be seated.

    The two lines that turn the card amber and rose are the ones that need doing something about —
    a batch finishing inside the month (certificates, feedback, the next intake) and a batch whose
    end date has already gone past while its status still says it is live.

    Seats are the `current_students` cache and are labelled approximate on purpose (INV-I7): the
    enrolment screen recounts under a row lock before it gives a seat away. No student appears here.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="rectangle-stack" title="Batches unavailable"
                      message="The batch list could not be read." :compact="true" />
@elseif (($data['total'] ?? 0) === 0)
    <x-ui.empty-state icon="squares-2x2" title="No batches yet"
                      message="Nothing has been set up. A batch is the group a course is taught to."
                      :compact="true" />
@elseif (($data['live'] ?? 0) === 0)
    <x-ui.empty-state icon="check-circle" title="Nothing running"
                      message="Every batch has finished or been cancelled." :compact="true" />
@else
    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">
                {{ $data['running'] }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                running now · {{ $data['enrolling'] }} still enrolling
            </p>
        </div>

        <dl class="space-y-1 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
            @if (($data['planned'] ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">Planned</dt>
                    <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $data['planned'] }}</dd>
                </div>
            @endif
            @if (($data['finishing'] ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-amber-600 dark:text-amber-400">Finishing within {{ $data['horizon_days'] }} days</dt>
                    <dd class="font-semibold tabular-nums text-amber-600 dark:text-amber-400">{{ $data['finishing'] }}</dd>
                </div>
            @endif
            @if (($data['on_hold'] ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-amber-600 dark:text-amber-400">On hold</dt>
                    <dd class="tabular-nums text-amber-600 dark:text-amber-400">{{ $data['on_hold'] }}</dd>
                </div>
            @endif
            @if (($data['overrunning'] ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-rose-600 dark:text-rose-400">Past their end date</dt>
                    <dd class="font-semibold tabular-nums text-rose-600 dark:text-rose-400">{{ $data['overrunning'] }}</dd>
                </div>
            @endif
        </dl>

        @if (($data['enrolling'] ?? 0) > 0)
            <p class="text-xs text-slate-500 dark:text-slate-400">
                About
                <span class="font-medium text-slate-700 dark:text-slate-200">{{ $data['seats_left'] }}</span>
                {{ \Illuminate\Support\Str::plural('seat', $data['seats_left']) }} left across the enrolling batches.
            </p>
        @endif
    </div>
@endif
