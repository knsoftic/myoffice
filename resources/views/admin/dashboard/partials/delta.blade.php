{{--
    "vs the previous period" — one shape for every card that compares two windows.

    @include('admin.dashboard.partials.delta', ['delta' => $data['delta'], 'against' => $data['previous_label']])

    `delta` is what App\Dashboard\Concerns\ComparesRanges::delta() returns. `trend` already accounts
    for direction, so a rise in failed logins arrives as 'down' and is coloured accordingly: the card
    never has to remember which way is good.
--}}

@php
    $deltaData = $delta ?? [];

    $trends = [
        'up' => ['text-emerald-600 dark:text-emerald-400', 'arrow-trending-up'],
        'down' => ['text-rose-600 dark:text-rose-400', 'arrow-trending-down'],
        'flat' => ['text-slate-500 dark:text-slate-400', 'minus'],
    ];

    [$deltaClass, $deltaIcon] = $trends[$deltaData['trend'] ?? 'flat'] ?? $trends['flat'];
@endphp

<span class="inline-flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
    <span class="inline-flex items-center gap-1 font-semibold tabular-nums {{ $deltaClass }}">
        <x-ui.icon :name="$deltaIcon" class="h-3.5 w-3.5" />
        {{ $deltaData['label'] ?? 'no change' }}
    </span>

    @if (filled($against ?? null))
        <span class="text-slate-500 dark:text-slate-400">
            vs {{ $against }}
            @if (isset($deltaData['previous']))
                (<span class="tabular-nums">{{ app_number($deltaData['previous']) }}</span>)
            @endif
        </span>
    @endif
</span>
