{{--
    The "next follow-up" chip (phase-05 §8.1, §8.2): rose when overdue, amber when due today (in the viewer's display
    timezone), slate when later, a dash when nothing is scheduled.

    @include('admin.crm.partials.follow-up-chip', ['at' => $lead->follow_up_at, 'compact' => false])

    `at` is `leads.follow_up_at` (the cache of the single open follow-up, [D-P5-12]) or a follow-up's `scheduled_at`.
--}}

@php
    $chipAt = $at ?? null;
    $chipCompact = (bool) ($compact ?? false);
    $chipMoment = null;

    if ($chipAt instanceof \DateTimeInterface) {
        $chipMoment = \Illuminate\Support\Carbon::instance($chipAt);
    } elseif (is_string($chipAt) && trim($chipAt) !== '') {
        try {
            $chipMoment = \Illuminate\Support\Carbon::parse($chipAt);
        } catch (\Throwable) {
            $chipMoment = null;
        }
    }

    $chipState = null;

    if ($chipMoment !== null) {
        $chipDay = \App\Support\Format::instantDate($chipMoment, 'Y-m-d');
        $todayDay = \App\Support\Format::instantDate(now(), 'Y-m-d');

        $chipState = match (true) {
            $chipMoment->isPast() => 'overdue',
            $chipDay === $todayDay => 'today',
            default => 'upcoming',
        };
    }

    $chipTone = [
        'overdue' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/25',
        'today' => 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25',
        'upcoming' => 'bg-slate-100 text-slate-700 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
    ];
    $chipPrefix = ['overdue' => 'Overdue', 'today' => 'Today', 'upcoming' => 'Next'];
@endphp

@if ($chipState === null)
    <span class="text-xs text-slate-400 dark:text-slate-500">—<span class="sr-only">No follow-up scheduled</span></span>
@else
    <span
        class="inline-flex max-w-full items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $chipTone[$chipState] }}"
        title="{{ $chipPrefix[$chipState] }}: {{ app_datetime($chipMoment) }}"
    >
        <x-ui.icon :name="$chipState === 'overdue' ? 'exclamation-circle' : 'clock'" class="h-3.5 w-3.5" />
        <span class="truncate tabular-nums">
            @if ($chipState === 'today')
                {{ $chipCompact ? app_time($chipMoment) : 'Today '.app_time($chipMoment) }}
            @elseif ($chipState === 'overdue')
                {{ $chipCompact ? \App\Support\Format::forHumans($chipMoment) : 'Overdue · '.app_datetime($chipMoment) }}
            @else
                {{ $chipCompact ? \App\Support\Format::instantDate($chipMoment) : app_datetime($chipMoment) }}
            @endif
        </span>
    </span>
@endif
