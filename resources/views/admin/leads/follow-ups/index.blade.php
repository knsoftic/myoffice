@extends('layouts.admin')

@section('title', 'Follow-ups')

{{--
    Follow-up worklist — admin.leads.follow-ups.index (phase-05 §8.5). The daily call list, plus a month / week grid for
    planning (no calendar library). LeadFollowUpService::worklist() already applied the §9 lead visibility scope.

    Controller variables (Admin\LeadFollowUpController@index):
      $view                  string  list (default) | calendar
      $followUps             LengthAwarePaginator<LeadFollowUp> with lead (id, lead_no, name, company, status, assigned_to)
                             and assignee — the List tab (may be empty when $view is calendar)
      $calendarFollowUps     Collection<LeadFollowUp> with lead, assignee — every row inside the visible grid
                             (may be empty when $view is list)
      $calendarMode          string  month (default) | week
      $anchorDate            string  'Y-m-d' in the display timezone — the month or week shown (?date=)
      $weekStartsOn          ?int    Carbon day of week; Format::weekStartsOn() when absent
      $sort, $direction      scheduled_at (default, asc) | created_at
      $assigneeOptions       array<int, string>
      $canViewAll            bool    leads.view_any — "All" in the assignee filter
      $followUpTypeOptions   array<string, string>  LeadFollowUpType::options()
      $followUpStatusOptions array<string, string>  LeadFollowUpStatus::options()
      + the dialog variables used here: $outcomeOptions, $followUpDefaultAt
    Query: view, mode, date, search (lead name, company, lead_no, phone), assignee (me — default | all | user id), type,
    status (default pending), from, to (Y-m-d), overdue (1), sort, direction, page.
--}}

@php
    use App\Support\Format;
    use Illuminate\Support\Facades\Route;

    $user = auth()->user();
    $view = in_array($view ?? request('view'), ['list', 'calendar'], true) ? ($view ?? request('view')) : 'list';
    $sort = $sort ?? 'scheduled_at';
    $direction = $direction ?? 'asc';
    $followUps = $followUps ?? new \Illuminate\Pagination\LengthAwarePaginator([], 0, 15);
    $calendarFollowUps = collect($calendarFollowUps ?? []);
    $calendarMode = in_array($calendarMode ?? request('mode'), ['month', 'week'], true) ? ($calendarMode ?? request('mode')) : 'month';
    $anchorKey = is_string($anchorDate ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchorDate) === 1 ? $anchorDate : Format::instantDate(now(), 'Y-m-d');
    $anchor = Format::carbon($anchorKey);
    $weekStart = (int) ($weekStartsOn ?? Format::weekStartsOn());
    $todayKey = Format::instantDate(now(), 'Y-m-d');
    $followUpTypeOptions = (array) ($followUpTypeOptions ?? (enum_exists(\App\Enums\LeadFollowUpType::class) ? \App\Enums\LeadFollowUpType::options() : []));
    $followUpStatusOptions = (array) ($followUpStatusOptions ?? (enum_exists(\App\Enums\LeadFollowUpStatus::class) ? \App\Enums\LeadFollowUpStatus::options() : []));
    $assigneeFilter = ['me' => 'Me'] + (($canViewAll ?? false) ? ['all' => 'Everyone'] : []) + collect($assigneeOptions ?? [])->all();
    $filterQuery = request()->except(['page', 'view', 'mode', 'date']);

    if ($calendarMode === 'week') {
        $gridStart = $anchor->startOfWeek($weekStart);
        $weeks = 1;
        $previousDate = $anchor->subWeek();
        $nextDate = $anchor->addWeek();
        $heading = app_date($gridStart).' – '.app_date($gridStart->addDays(6));
    } else {
        $monthStart = $anchor->startOfMonth();
        $gridStart = $monthStart->startOfWeek($weekStart);
        $weeks = (int) ceil(((int) $gridStart->diffInDays($monthStart) + $monthStart->daysInMonth) / 7);
        $previousDate = $monthStart->subMonth();
        $nextDate = $monthStart->addMonth();
        $heading = app_date($monthStart, 'F Y');
    }

    $byDay = $calendarFollowUps->groupBy(static fn ($followUp): string => Format::instantDate($followUp->scheduled_at, 'Y-m-d'));
    $statusValueOf = static fn ($followUp): string => $followUp->status instanceof \BackedEnum ? $followUp->status->value : (string) $followUp->status;
    $toneOf = static function ($followUp) use ($statusValueOf, $todayKey): string {
        if ($statusValueOf($followUp) !== 'pending') {
            return 'bg-slate-100 text-slate-500 ring-slate-200 line-through dark:bg-slate-800 dark:text-slate-400 dark:ring-slate-700';
        }

        $day = Format::instantDate($followUp->scheduled_at, 'Y-m-d');

        return match (true) {
            \Illuminate\Support\Carbon::parse($followUp->scheduled_at)->isPast() => 'bg-rose-50 text-rose-800 ring-rose-200 hover:bg-rose-100 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/30 dark:hover:bg-rose-500/20',
            $day === $todayKey => 'bg-amber-50 text-amber-800 ring-amber-200 hover:bg-amber-100 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30 dark:hover:bg-amber-500/20',
            default => 'bg-brand-50 text-brand-800 ring-brand-200 hover:bg-brand-100 dark:bg-brand-500/10 dark:text-brand-200 dark:ring-brand-500/30 dark:hover:bg-brand-500/20',
        };
    };

    $tabs = [
        ['label' => 'List', 'url' => route('admin.leads.follow-ups.index', array_merge($filterQuery, ['view' => 'list'])), 'active' => $view === 'list', 'icon' => 'queue-list'],
        ['label' => 'Calendar', 'url' => route('admin.leads.follow-ups.index', array_merge($filterQuery, ['view' => 'calendar', 'mode' => $calendarMode, 'date' => $anchorKey])), 'active' => $view === 'calendar', 'icon' => 'calendar-days'],
    ];
    $leadTabs = [
        ['label' => 'List', 'url' => route('admin.leads.index'), 'icon' => 'table-cells'],
        ['label' => 'Board', 'url' => route('admin.leads.board'), 'icon' => 'view-columns'],
        ['label' => 'Follow-ups', 'url' => route('admin.leads.follow-ups.index'), 'active' => true, 'icon' => 'calendar-days'],
    ];
    $filtered = collect(request()->except(['page', 'view', 'mode', 'date', 'sort', 'direction']))->filter(fn ($value) => filled($value))->isNotEmpty();

    $actionsFor = static function ($followUp) use ($user, $statusValueOf): array {
        $lead = $followUp->relationLoaded('lead') ? $followUp->lead : null;

        if ($lead === null || $statusValueOf($followUp) !== 'pending' || ! (bool) $user?->can('complete', $followUp)) {
            return [];
        }

        return [
            'complete' => ['name' => 'lead-follow-up-complete', 'url' => route('admin.leads.follow-ups.complete', [$lead, $followUp]), 'label' => $lead->name],
            'reschedule' => ['name' => 'lead-follow-up-reschedule', 'url' => route('admin.leads.follow-ups.reschedule', [$lead, $followUp]), 'label' => $lead->name, 'scheduledAt' => app_datetime($followUp->scheduled_at, 'Y-m-d\TH:i')],
            'cancel' => ['name' => 'lead-follow-up-cancel', 'url' => route('admin.leads.follow-ups.cancel', [$lead, $followUp]), 'label' => $lead->name],
        ];
    };
@endphp

@section('header')
    <x-ui.page-header title="Follow-ups" subtitle="Who to call today, and what is coming up." icon="calendar-days" />
@endsection

@section('content')
    <div x-data="{ navigating: false }" x-on:submit.window="if ($event.target?.method === 'get') navigating = true" class="space-y-4">
        <x-ui.tabs :tabs="$leadTabs" variant="pill" class="w-fit" />

        <x-ui.filter-bar placeholder="Search the lead's name, company or number…" :reset="route('admin.leads.follow-ups.index', ['view' => $view])">
            <input type="hidden" name="view" value="{{ $view }}">
            @if ($view === 'calendar')
                <input type="hidden" name="mode" value="{{ $calendarMode }}">
                <input type="hidden" name="date" value="{{ $anchorKey }}">
            @endif
            <x-ui.form.select name="assignee" :options="$assigneeFilter" :selected="request('assignee', 'me')" size="sm" aria-label="Filter by who follows up" />
            <x-ui.form.select name="type" :options="$followUpTypeOptions" :selected="request('type')" placeholder="Any type" size="sm" aria-label="Filter by type" />
            <x-ui.form.select name="status" :options="$followUpStatusOptions" :selected="request('status', 'pending')" placeholder="Any status" size="sm" aria-label="Filter by status" />
            @if ($view === 'list')
                <label class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                    <span class="shrink-0">From</span>
                    <input type="date" name="from" value="{{ request('from') }}" class="block w-full rounded-lg border-slate-300 py-1.5 text-xs shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                </label>
                <label class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                    <span class="shrink-0">To</span>
                    <input type="date" name="to" value="{{ request('to') }}" class="block w-full rounded-lg border-slate-300 py-1.5 text-xs shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                </label>
            @endif
            <label class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="overdue" value="1" @checked(request()->boolean('overdue')) class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800">
                Overdue only
            </label>
        </x-ui.filter-bar>

        <x-ui.tabs :tabs="$tabs" />

        @if ($view === 'list')
            <x-ui.table loading="navigating" :is-empty="$followUps->isEmpty()" :columns="7">
                <x-slot:head>
                    <x-ui.th-sortable column="scheduled_at" :sort="$sort" :direction="$direction">When</x-ui.th-sortable>
                    <th scope="col" class="px-4 py-3">Lead</th>
                    <th scope="col" class="px-4 py-3">Type</th>
                    <th scope="col" class="px-4 py-3">What to say</th>
                    <th scope="col" class="px-4 py-3">Assignee</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                    <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
                </x-slot:head>

                @foreach ($followUps as $followUp)
                    @php
                        $lead = $followUp->relationLoaded('lead') ? $followUp->lead : null;
                        $assignee = $followUp->relationLoaded('assignee') ? $followUp->assignee : null;
                        $actions = $actionsFor($followUp);
                    @endphp
                    <tr>
                        <td class="whitespace-nowrap">
                            @if ($statusValueOf($followUp) === 'pending')
                                @include('admin.crm.partials.follow-up-chip', ['at' => $followUp->scheduled_at])
                            @else
                                <span class="text-sm text-slate-500 dark:text-slate-400">{{ app_datetime($followUp->scheduled_at) }}</span>
                            @endif
                        </td>
                        <td class="min-w-[12rem]">
                            @if ($lead)
                                <a href="{{ route('admin.leads.show', $lead) }}" class="block truncate font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $lead->name }}</a>
                                <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ collect([$lead->lead_no, $lead->company])->filter()->implode(' · ') }}</span>
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap">@include('admin.crm.partials.enum-badge', ['value' => $followUp->type, 'dot' => false])</td>
                        <td class="min-w-[12rem] max-w-sm text-sm">{{ $followUp->notes ?: '—' }}</td>
                        <td class="whitespace-nowrap">
                            @if ($assignee)
                                <span class="flex items-center gap-2"><x-ui.avatar :src="$assignee->avatar_url ?? null" :name="$assignee->name" size="xs" /> <span class="max-w-[8rem] truncate text-sm">{{ $assignee->name }}</span></span>
                            @else
                                <span class="text-xs text-slate-400 dark:text-slate-500">—</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap">@include('admin.crm.partials.enum-badge', ['value' => $followUp->status])</td>
                        <td>
                            <div class="flex items-center justify-end gap-1">
                                @if ($actions !== [])
                                    <x-ui.icon-button icon="check" size="sm" :label="'Complete the follow-up with '.$lead->name" class="text-emerald-600 dark:text-emerald-400" x-on:click="$dispatch('open-modal', {{ \Illuminate\Support\Js::from($actions['complete']) }})" />
                                    <x-ui.icon-button icon="clock" size="sm" :label="'Reschedule the follow-up with '.$lead->name" x-on:click="$dispatch('open-modal', {{ \Illuminate\Support\Js::from($actions['reschedule']) }})" />
                                    <x-ui.icon-button icon="x-mark" size="sm" variant="danger" :label="'Cancel the follow-up with '.$lead->name" x-on:click="$dispatch('open-modal', {{ \Illuminate\Support\Js::from($actions['cancel']) }})" />
                                @endif
                                @if ($lead)
                                    <x-ui.icon-button icon="eye" size="sm" :href="route('admin.leads.show', ['lead' => $lead, 'tab' => 'follow-ups'])" :label="'Open '.$lead->name" />
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach

                <x-slot:empty>
                    @if ($filtered)
                        <x-ui.empty-state icon="calendar-days" title="No follow-ups match these filters">
                            <x-slot:action>
                                <x-ui.button variant="secondary" :href="route('admin.leads.follow-ups.index')">Clear filters</x-ui.button>
                            </x-slot:action>
                        </x-ui.empty-state>
                    @else
                        <x-ui.empty-state icon="calendar-days" title="Nothing scheduled" message="Nothing scheduled - pick a lead and set a follow-up.">
                            <x-slot:action>
                                <x-ui.button variant="secondary" icon="funnel" :href="route('admin.leads.index')">Open leads</x-ui.button>
                            </x-slot:action>
                        </x-ui.empty-state>
                    @endif
                </x-slot:empty>

                <x-slot:footer>
                    <x-ui.pagination-summary :paginator="$followUps" label="follow-ups" />
                </x-slot:footer>
            </x-ui.table>
        @else
            <div class="space-y-4" x-data="{ day: null, dayLabel: '' }">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-2">
                        <x-ui.icon-button icon="chevron-left" variant="secondary" :label="$calendarMode === 'week' ? 'Previous week' : 'Previous month'" :href="route('admin.leads.follow-ups.index', array_merge($filterQuery, ['view' => 'calendar', 'mode' => $calendarMode, 'date' => app_date($previousDate, 'Y-m-d')]))" />
                        <h2 class="min-w-[12rem] text-center text-lg font-semibold tracking-tight text-slate-900 dark:text-white">{{ $heading }}</h2>
                        <x-ui.icon-button icon="chevron-right" variant="secondary" :label="$calendarMode === 'week' ? 'Next week' : 'Next month'" :href="route('admin.leads.follow-ups.index', array_merge($filterQuery, ['view' => 'calendar', 'mode' => $calendarMode, 'date' => app_date($nextDate, 'Y-m-d')]))" />
                        <x-ui.button variant="ghost" size="sm" :href="route('admin.leads.follow-ups.index', array_merge($filterQuery, ['view' => 'calendar', 'mode' => $calendarMode, 'date' => $todayKey]))">Today</x-ui.button>
                    </div>
                    <x-ui.tabs variant="pill" class="w-fit" :tabs="[
                        ['label' => 'Month', 'url' => route('admin.leads.follow-ups.index', array_merge($filterQuery, ['view' => 'calendar', 'mode' => 'month', 'date' => $anchorKey])), 'active' => $calendarMode === 'month'],
                        ['label' => 'Week', 'url' => route('admin.leads.follow-ups.index', array_merge($filterQuery, ['view' => 'calendar', 'mode' => 'week', 'date' => $anchorKey])), 'active' => $calendarMode === 'week'],
                    ]" />
                </div>

                <ul class="flex flex-wrap items-center gap-3 text-xs text-slate-600 dark:text-slate-300" aria-label="Legend">
                    <li class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-rose-500" aria-hidden="true"></span> Overdue</li>
                    <li class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-amber-500" aria-hidden="true"></span> Today</li>
                    <li class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-brand-500" aria-hidden="true"></span> Upcoming</li>
                    <li class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-slate-400" aria-hidden="true"></span> Done or cancelled</li>
                </ul>

                <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200/70 dark:bg-slate-900 dark:ring-slate-800">
                    <div class="min-w-[48rem]" role="grid" aria-label="{{ $heading }}" x-bind:aria-busy="navigating ? 'true' : 'false'">
                        <div class="grid grid-cols-7 border-b border-slate-200 bg-slate-50/80 dark:border-slate-800 dark:bg-slate-900/60" role="row">
                            @for ($d = 0; $d < 7; $d++)
                                <div class="px-2 py-2 text-center text-2xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400" role="columnheader">{{ app_date($gridStart->addDays($d), 'D') }}</div>
                            @endfor
                        </div>

                        @for ($w = 0; $w < $weeks; $w++)
                            <div class="grid grid-cols-7" role="row">
                                @for ($d = 0; $d < 7; $d++)
                                    @php
                                        $cellDay = $gridStart->addDays($w * 7 + $d);
                                        $cellKey = app_date($cellDay, 'Y-m-d');
                                        $inRange = $calendarMode === 'week' || $cellDay->month === $anchor->month;
                                        $isToday = $cellKey === $todayKey;
                                        $cellItems = $byDay->get($cellKey, collect())->sortBy('scheduled_at')->values();
                                        $visible = $calendarMode === 'week' ? $cellItems : $cellItems->take(3);
                                    @endphp
                                    <div
                                        role="gridcell"
                                        @class([
                                            'border-b border-r border-slate-100 p-1.5 dark:border-slate-800',
                                            'min-h-[7rem]' => $calendarMode === 'month',
                                            'min-h-[20rem]' => $calendarMode === 'week',
                                            'bg-white dark:bg-slate-900' => $inRange,
                                            'bg-slate-50/70 dark:bg-slate-950/40' => ! $inRange,
                                            'ring-2 ring-inset ring-brand-500' => $isToday,
                                        ])
                                        @if ($isToday) aria-current="date" @endif
                                    >
                                        <button
                                            type="button"
                                            class="mb-1 flex w-full items-center justify-between rounded-md px-1 py-0.5 text-left hover:bg-slate-100 dark:hover:bg-slate-800"
                                            x-on:click="day = {{ \Illuminate\Support\Js::from($cellKey) }}; dayLabel = {{ \Illuminate\Support\Js::from(app_date($cellDay)) }}; $dispatch('open-modal', 'follow-up-day')"
                                            aria-label="{{ app_date($cellDay) }}: {{ $cellItems->count() }} {{ \Illuminate\Support\Str::plural('follow-up', $cellItems->count()) }}"
                                        >
                                            <span @class([
                                                'inline-flex h-6 min-w-[1.5rem] items-center justify-center rounded-full px-1 text-xs tabular-nums',
                                                'bg-brand-600 font-semibold text-white dark:bg-brand-500' => $isToday,
                                                'text-slate-700 dark:text-slate-200' => ! $isToday && $inRange,
                                                'text-slate-400 dark:text-slate-600' => ! $isToday && ! $inRange,
                                            ])>{{ $cellDay->day }}</span>
                                            @if ($cellItems->isNotEmpty())
                                                <span class="text-2xs font-semibold tabular-nums text-slate-500 dark:text-slate-400">{{ app_number($cellItems->count()) }}</span>
                                            @endif
                                        </button>

                                        <ul class="space-y-1">
                                            @foreach ($visible as $item)
                                                @php $itemLead = $item->relationLoaded('lead') ? $item->lead : null; @endphp
                                                <li>
                                                    <a
                                                        href="{{ $itemLead ? route('admin.leads.show', ['lead' => $itemLead, 'tab' => 'follow-ups']) : '#' }}"
                                                        class="block truncate rounded-md px-1.5 py-1 text-2xs font-medium ring-1 ring-inset transition {{ $toneOf($item) }}"
                                                        title="{{ app_datetime($item->scheduled_at) }} · {{ $itemLead?->name }}"
                                                    >
                                                        <span class="tabular-nums opacity-70">{{ app_time($item->scheduled_at) }}</span>
                                                        {{ $itemLead?->name ?? '—' }}
                                                    </a>
                                                </li>
                                            @endforeach
                                            @if ($cellItems->count() > $visible->count())
                                                <li>
                                                    <button type="button" class="px-1.5 text-2xs font-semibold text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white" x-on:click="day = {{ \Illuminate\Support\Js::from($cellKey) }}; dayLabel = {{ \Illuminate\Support\Js::from(app_date($cellDay)) }}; $dispatch('open-modal', 'follow-up-day')">
                                                        +{{ $cellItems->count() - $visible->count() }} more
                                                    </button>
                                                </li>
                                            @endif
                                        </ul>
                                    </div>
                                @endfor
                            </div>
                        @endfor
                    </div>
                </div>

                @if ($calendarFollowUps->isEmpty())
                    <x-ui.empty-state icon="calendar-days" title="Nothing scheduled" message="Nothing scheduled - pick a lead and set a follow-up." :compact="true">
                        <x-slot:action>
                            <x-ui.button variant="secondary" icon="funnel" :href="route('admin.leads.index')">Open leads</x-ui.button>
                        </x-slot:action>
                    </x-ui.empty-state>
                @endif

                <p class="text-xs text-slate-500 dark:text-slate-400">Times are shown in {{ Format::displayTimezone() }}.</p>

                {{-- One day's full list. Rendered server-side per day; the modal shows the chosen day. --}}
                <x-ui.modal name="follow-up-day" title="Follow-ups" icon="calendar-days" size="lg">
                    <p class="mb-3 text-sm font-semibold text-slate-900 dark:text-white" x-text="dayLabel"></p>
                    @foreach ($byDay as $dayKey => $dayItems)
                        <ul class="divide-y divide-slate-100 dark:divide-slate-800" x-show="day === {{ \Illuminate\Support\Js::from($dayKey) }}">
                            @foreach ($dayItems->sortBy('scheduled_at') as $item)
                                @php
                                    $itemLead = $item->relationLoaded('lead') ? $item->lead : null;
                                    $itemAssignee = $item->relationLoaded('assignee') ? $item->assignee : null;
                                    $itemActions = $actionsFor($item);
                                @endphp
                                <li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="min-w-0">
                                        <p class="flex flex-wrap items-center gap-2 text-sm">
                                            <span class="tabular-nums text-slate-500 dark:text-slate-400">{{ app_time($item->scheduled_at) }}</span>
                                            @if ($itemLead)
                                                <a href="{{ route('admin.leads.show', ['lead' => $itemLead, 'tab' => 'follow-ups']) }}" class="font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $itemLead->name }}</a>
                                            @endif
                                            @include('admin.crm.partials.enum-badge', ['value' => $item->type, 'dot' => false, 'size' => 'xs'])
                                            @include('admin.crm.partials.enum-badge', ['value' => $item->status, 'size' => 'xs'])
                                        </p>
                                        <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $item->notes ?: 'No notes' }}{{ $itemAssignee ? ' · '.$itemAssignee->name : '' }}</p>
                                    </div>
                                    @if ($itemActions !== [])
                                        <div class="flex shrink-0 items-center gap-1">
                                            <x-ui.button size="sm" variant="success" icon="check" x-on:click="$dispatch('close-modal', 'follow-up-day'); $dispatch('open-modal', {{ \Illuminate\Support\Js::from($itemActions['complete']) }})">Complete</x-ui.button>
                                            <x-ui.icon-button icon="clock" size="sm" :label="'Reschedule the follow-up with '.$itemLead->name" x-on:click="$dispatch('close-modal', 'follow-up-day'); $dispatch('open-modal', {{ \Illuminate\Support\Js::from($itemActions['reschedule']) }})" />
                                            <x-ui.icon-button icon="x-mark" size="sm" variant="danger" :label="'Cancel the follow-up with '.$itemLead->name" x-on:click="$dispatch('close-modal', 'follow-up-day'); $dispatch('open-modal', {{ \Illuminate\Support\Js::from($itemActions['cancel']) }})" />
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endforeach
                    <p class="py-6 text-center text-sm text-slate-500 dark:text-slate-400" x-show="! {{ \Illuminate\Support\Js::from($byDay->keys()->values()->all()) }}.includes(day)">
                        Nothing scheduled - pick a lead and set a follow-up.
                    </p>
                </x-ui.modal>
            </div>
        @endif
    </div>

    @include('admin.leads.partials.dialogs')
@endsection
