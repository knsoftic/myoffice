{{--
    ApplicationsAwaitingReviewWidget body.

    The count is the headline and the oldest wait is the line under it: twelve that arrived this
    morning is a busy day, one that has sat for four days is somebody who has already decided the
    institute does not answer.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="inbox-arrow-down" title="Inbox unavailable"
                      message="The application inbox could not be read." :compact="true" />
@elseif (($data['waiting'] ?? 0) === 0)
    <x-ui.empty-state icon="check-circle" title="Inbox clear"
                      message="Every application has been looked at." :compact="true" />
@else
    @php
        $oldest = $data['oldest_at'] ?? null;
        $stale = $oldest !== null && $oldest->diffInHours(now()) >= 24;
    @endphp

    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight tabular-nums {{ $stale ? 'text-amber-600 dark:text-amber-400' : 'text-slate-900 dark:text-white' }}">
                {{ $data['waiting'] }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                waiting for a human · {{ $data['today'] }} of them from today
            </p>
        </div>

        <dl class="space-y-1 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Not looked at yet</dt>
                <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $data['submitted'] }}</dd>
            </div>
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Being reviewed</dt>
                <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $data['under_review'] }}</dd>
            </div>
        </dl>

        @if ($oldest !== null)
            <p class="text-xs {{ $stale ? 'text-amber-600 dark:text-amber-400' : 'text-slate-500 dark:text-slate-400' }}">
                Oldest has waited {{ $oldest->diffForHumans(null, true) }}.
            </p>
        @endif
    </div>
@endif
