@props([
    'audit' => [],
    'group' => [],
])

{{--
    x-settings.group-footer — "last updated by X, <time>" (phase-02 §5).

    `settings.updated_by` is stamped by SettingsService on every write (phase-02 §1), so this line
    answers the question every shared configuration screen eventually raises: who changed this, and
    when. A group nobody has edited says so rather than showing a blank.
--}}

@php
    $user = $audit['user'] ?? null;
    $at = $audit['at'] ?? null;
    $keys = (int) ($audit['keys'] ?? 0);
@endphp

<div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
    <span class="flex items-center gap-1.5">
        <x-ui.icon name="clock" class="h-3.5 w-3.5 shrink-0 opacity-70" />

        @if ($at === null)
            <span>Never edited — these are the seeded defaults.</span>
        @else
            <span>
                Last updated
                @if ($user)
                    by <span class="font-medium text-slate-700 dark:text-slate-200">{{ $user }}</span>,
                @endif
                <time datetime="{{ $at->toIso8601String() }}" title="{{ app_datetime($at) }}">{{ $at->diffForHumans() }}</time>
            </span>
        @endif
    </span>

    <span class="flex items-center gap-1.5 tabular-nums">
        <x-ui.icon name="adjustments-horizontal" class="h-3.5 w-3.5 shrink-0 opacity-70" />
        {{ $keys }} {{ \Illuminate\Support\Str::plural('value', $keys) }} stored in
        <span class="font-medium text-slate-700 dark:text-slate-200">{{ $group['label'] ?? 'this group' }}</span>
    </span>
</div>
