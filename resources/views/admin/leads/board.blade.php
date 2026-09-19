@extends('layouts.admin')

@section('title', 'Lead board')

{{--
    Lead Kanban board — admin.leads.board (phase-05 §8.2, §18 "Kanban view required"). One column per LeadStatus in
    sortOrder(), limited to crm.lead_statuses_on_board. LeadBoardService::board() issues two queries (the grouped
    count / SUM(budget_amount) / COUNT(budget_amount), and the windowed card query), both through the same §9 visibility
    scope, so a header can never count a lead the viewer cannot open.

    Controller variables (Admin\LeadBoardController@index):
      $columns                   list<array{
                                     status: string,                 LeadStatus value
                                     label: string, color: string,   the enum's label() and color()
                                     count: int,
                                     value_sum: string,              SUM(budget_amount) as the DB returned it ('0.00' when none)
                                     value_sum_formatted: string,    money(value_sum)
                                     with_budget: int,               COUNT(budget_amount)
                                     cards: Collection<Lead>,        the first crm.kanban_page_size cards, `assignee` loaded
                                     has_more: bool, next_page: ?int
                                 }>
      $transitions               array<string, list<string>>  LeadStatus value => allowedTransitions() values
      $statusLabels              array<string, string>        LeadStatus::options()
      $followUpRequiredStatuses  list<string>                 [] when crm.require_follow_up_on_contacted is off
      $lostReasons               list<string>                 crm.lost_reasons
      $followUpTypeOptions       array<string, string>        LeadFollowUpType::options()
      $followUpDefaultAt         string                       'Y-m-d\TH:i', display timezone
      $staleDays                 int                          crm.stale_lead_days
      $sourceOptions             array<string, string>        InquirySource::options()
      $assigneeOptions           array<int, string>
      $filters                   array<string, mixed>
    Query (shared with every column request): search, assignee (me | unassigned | id), source[], follow_up
    (overdue | today | next_7_days | none).

    JSON the page expects:
      GET   admin.leads.board.column {status} ?page=N&…filters   → {html, has_more, next_page, column: {count, value_sum,
            value_sum_formatted, with_budget}}   (html = the admin.leads.partials.board-card of each card, concatenated)
      PATCH admin.leads.board.move {lead}   JSON {to_status, expected_from_status, lost_reason?, reason?, follow_up?: {type,
            scheduled_at, notes}}
            200 → {message, card_html, columns: {<status>: {count, value_sum, value_sum_formatted, with_budget}} for the two
                  affected columns, convert_url: ?string (only when moved to won and the actor may convert)}
            422 → {message, allowed?: list<string>, errors?: {field: [..]}, columns?: {...}}   illegal transition / missing data
            409 → {message, current_status, columns?: {...}}                                  expected_from_status is stale
--}}

@php
    $user = auth()->user();
    $columns = collect($columns ?? []);
    $statusLabels = (array) ($statusLabels ?? $columns->pluck('label', 'status')->all());
    $transitions = (array) ($transitions ?? []);
    $canMove = (bool) $user?->can('leads.change_status') && \Illuminate\Support\Facades\Route::has('admin.leads.board.move');
    $canCreate = (bool) $user?->can('leads.create');
    $sourceOptions = (array) ($sourceOptions ?? (enum_exists(\App\Enums\InquirySource::class) ? \App\Enums\InquirySource::options() : []));
    $assigneeFilterOptions = ['me' => 'Me', 'unassigned' => 'Unassigned'] + collect($assigneeOptions ?? [])->all();
    $filterQuery = request()->except(['page']);
    $boardIsEmpty = $columns->sum(static fn ($column): int => (int) data_get($column, 'count', 0)) === 0;
    $filtered = collect($filterQuery)->filter(fn ($value) => filled($value))->isNotEmpty();

    $accent = [
        'slate' => 'border-t-slate-400', 'gray' => 'border-t-gray-400', 'brand' => 'border-t-brand-500', 'indigo' => 'border-t-indigo-500',
        'violet' => 'border-t-violet-500', 'purple' => 'border-t-purple-500', 'fuchsia' => 'border-t-fuchsia-500', 'pink' => 'border-t-pink-500',
        'rose' => 'border-t-rose-500', 'red' => 'border-t-red-500', 'orange' => 'border-t-orange-500', 'amber' => 'border-t-amber-500',
        'yellow' => 'border-t-yellow-500', 'lime' => 'border-t-lime-500', 'green' => 'border-t-green-500', 'emerald' => 'border-t-emerald-500',
        'teal' => 'border-t-teal-500', 'cyan' => 'border-t-cyan-500', 'sky' => 'border-t-sky-500', 'blue' => 'border-t-blue-500',
    ];

    $boardConfig = [
        'moveUrl' => $canMove ? route('admin.leads.board.move', [987654321]) : null,
        'columnUrls' => $columns->mapWithKeys(static fn ($column): array => [
            (string) data_get($column, 'status') => route('admin.leads.board.column', array_merge(['status' => (string) data_get($column, 'status')], $filterQuery)),
        ])->all(),
        'transitions' => $transitions,
        'labels' => $statusLabels,
        'followUpRequired' => array_values((array) ($followUpRequiredStatuses ?? [])),
        'followUpDefaultAt' => (string) ($followUpDefaultAt ?? ''),
        'canMove' => $canMove,
        'moneySample' => money('1234567.89'),
        'numberSample' => app_number(1234567),
        'columns' => $columns->mapWithKeys(static fn ($column): array => [
            (string) data_get($column, 'status') => [
                'count' => (int) data_get($column, 'count', 0),
                'value_sum' => (string) (data_get($column, 'value_sum') ?? '0.00'),
                'value_sum_formatted' => (string) (data_get($column, 'value_sum_formatted') ?? money((string) (data_get($column, 'value_sum') ?? '0'))),
                'with_budget' => (int) data_get($column, 'with_budget', 0),
                'has_more' => (bool) data_get($column, 'has_more', false),
                'next_page' => data_get($column, 'next_page'),
                'loading' => false,
            ],
        ])->all(),
    ];

    $viewTabs = [
        ['label' => 'List', 'url' => route('admin.leads.index'), 'icon' => 'table-cells'],
        ['label' => 'Board', 'url' => route('admin.leads.board'), 'active' => true, 'icon' => 'view-columns'],
        ['label' => 'Follow-ups', 'url' => route('admin.leads.follow-ups.index'), 'icon' => 'calendar-days'],
    ];
    $lostReasons = array_values(array_filter((array) ($lostReasons ?? []), static fn ($reason): bool => is_string($reason) && trim($reason) !== ''));
    $followUpTypeOptions = (array) ($followUpTypeOptions ?? (enum_exists(\App\Enums\LeadFollowUpType::class) ? \App\Enums\LeadFollowUpType::options() : []));
    $fieldClass = 'mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white';
@endphp

@section('header')
    <x-ui.page-header title="Lead board" subtitle="Drag a card, or use its Move to menu, to take a lead to its next stage." icon="view-columns">
        <x-slot:actions>
            @if ($canCreate)
                <x-ui.button icon="plus" :href="route('admin.leads.create')">Add lead</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.crm.partials.scripts')

    <div class="space-y-4" x-data="crmBoard({{ \Illuminate\Support\Js::from($boardConfig) }})">
        <p class="sr-only" aria-live="polite" x-text="announcement"></p>

        <x-ui.tabs :tabs="$viewTabs" variant="pill" class="w-fit" />

        <x-ui.filter-bar placeholder="Search lead #, name, company, phone or email…" :reset="route('admin.leads.board')">
            <x-ui.form.select name="assignee" :options="$assigneeFilterOptions" :selected="request('assignee')" placeholder="Any owner" size="sm" aria-label="Filter by owner" />
            @include('admin.crm.partials.multi-filter', ['name' => 'source', 'label' => 'Source', 'options' => $sourceOptions, 'selected' => (array) request('source', [])])
            <x-ui.form.select name="follow_up" :options="['overdue' => 'Overdue', 'today' => 'Due today', 'next_7_days' => 'Next 7 days', 'none' => 'No follow-up']" :selected="request('follow_up')" placeholder="Any follow-up" size="sm" aria-label="Filter by follow-up" />
        </x-ui.filter-bar>

        <div x-show="won" x-cloak class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-100 dark:ring-emerald-500/25" role="status">
            <p class="flex items-center gap-2">
                <x-ui.icon name="trophy" class="h-5 w-5 shrink-0" />
                <span><span class="font-semibold" x-text="won?.name"></span> is won. Turn the lead into a client while the details are fresh.</span>
            </p>
            <div class="flex items-center gap-2">
                <a x-bind:href="won?.url" class="inline-flex h-8 items-center gap-1.5 rounded-lg bg-emerald-600 px-3 text-xs font-semibold text-white shadow-sm hover:bg-emerald-700 dark:bg-emerald-600 dark:hover:bg-emerald-500">
                    <x-ui.icon name="check-badge" class="h-4 w-4" /> Convert to client
                </a>
                <x-ui.icon-button icon="x-mark" size="sm" label="Dismiss" x-on:click="won = null" />
            </div>
        </div>

        @if ($columns->isEmpty())
            <x-ui.card :padded="false">
                <x-ui.empty-state icon="view-columns" title="No columns to show" message="Every status is hidden from the board in the CRM settings." />
            </x-ui.card>
        @else
            @if ($boardIsEmpty)
                <x-ui.card :padded="false">
                    @if ($filtered)
                        <x-ui.empty-state icon="funnel" title="No leads match these filters" :compact="true">
                            <x-slot:action>
                                <x-ui.button variant="secondary" :href="route('admin.leads.board')">Clear filters</x-ui.button>
                            </x-slot:action>
                        </x-ui.empty-state>
                    @else
                        <x-ui.empty-state icon="funnel" title="No leads yet" message="Add a lead, import a CSV, or wait for the website contact form." :compact="true">
                            @if ($canCreate)
                                <x-slot:action>
                                    <x-ui.button icon="plus" :href="route('admin.leads.create')">Add lead</x-ui.button>
                                </x-slot:action>
                            @endif
                        </x-ui.empty-state>
                    @endif
                </x-ui.card>
            @endif

            {{-- Columns scroll horizontally inside their own container; the page never does. --}}
            <div class="-mx-4 overflow-x-auto px-4 pb-2 sm:mx-0 sm:px-0" x-bind:aria-busy="Object.values(columns).some((column) => column.loading) ? 'true' : 'false'">
                <div class="flex min-w-max items-start gap-4">
                    @foreach ($columns as $column)
                        @php
                            $status = (string) data_get($column, 'status');
                            $label = (string) (data_get($column, 'label') ?? ($statusLabels[$status] ?? $status));
                            $color = (string) data_get($column, 'color', 'slate');
                            $count = (int) data_get($column, 'count', 0);
                            $withBudget = (int) data_get($column, 'with_budget', 0);
                            $cards = collect(data_get($column, 'cards', []));
                        @endphp
                        <section
                            aria-labelledby="board-column-{{ $status }}"
                            data-column="{{ $status }}"
                            x-on:dragover.prevent="dragOver({{ \Illuminate\Support\Js::from($status) }}, $event)"
                            x-on:dragleave="dragLeave({{ \Illuminate\Support\Js::from($status) }}, $event)"
                            x-on:drop.prevent="drop({{ \Illuminate\Support\Js::from($status) }})"
                            x-bind:class="columnClass({{ \Illuminate\Support\Js::from($status) }})"
                            class="flex w-72 shrink-0 flex-col rounded-xl border-t-4 bg-slate-100/80 transition dark:bg-slate-900/60 {{ $accent[$color] ?? $accent['slate'] }}"
                        >
                            <header class="px-3 pb-2 pt-3">
                                <div class="flex items-center justify-between gap-2">
                                    <h2 id="board-column-{{ $status }}" class="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">
                                        {{ $label }}
                                        <span class="rounded-full bg-white px-2 py-0.5 text-xs font-semibold tabular-nums text-slate-700 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700" x-text="formatCount(columns[{{ \Illuminate\Support\Js::from($status) }}].count)">{{ app_number($count) }}</span>
                                    </h2>
                                </div>
                                <p class="mt-1 text-sm font-semibold tabular-nums text-slate-800 dark:text-slate-100" x-text="columns[{{ \Illuminate\Support\Js::from($status) }}].value_sum_formatted">{{ data_get($column, 'value_sum_formatted') ?? money((string) (data_get($column, 'value_sum') ?? '0')) }}</p>
                                <p class="text-2xs text-slate-500 dark:text-slate-400">
                                    <span class="tabular-nums" x-text="formatCount(columns[{{ \Illuminate\Support\Js::from($status) }}].with_budget)">{{ app_number($withBudget) }}</span> of
                                    <span class="tabular-nums" x-text="formatCount(columns[{{ \Illuminate\Support\Js::from($status) }}].count)">{{ app_number($count) }}</span> have a budget
                                </p>
                            </header>

                            <div class="flex min-h-[6rem] flex-1 flex-col gap-2 px-2 pb-2" data-column-list="{{ $status }}">
                                @foreach ($cards as $lead)
                                    @include('admin.leads.partials.board-card', [
                                        'lead' => $lead,
                                        'transitions' => $transitions,
                                        'statusLabels' => $statusLabels,
                                        'staleDays' => $staleDays ?? 7,
                                        'canMove' => $canMove,
                                    ])
                                @endforeach
                            </div>

                            <div x-show="columns[{{ \Illuminate\Support\Js::from($status) }}].count === 0" @if ($count > 0) x-cloak @endif class="px-3 pb-3">
                                <p class="rounded-lg border border-dashed border-slate-300 px-3 py-4 text-center text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400">
                                    No {{ strtolower($label) }} leads
                                </p>
                            </div>

                            <div x-show="columns[{{ \Illuminate\Support\Js::from($status) }}].has_more" @if (! data_get($column, 'has_more')) x-cloak @endif class="px-2 pb-3">
                                <button
                                    type="button"
                                    x-on:click="loadMore({{ \Illuminate\Support\Js::from($status) }})"
                                    x-bind:disabled="columns[{{ \Illuminate\Support\Js::from($status) }}].loading"
                                    class="flex w-full items-center justify-center gap-2 rounded-lg px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-white hover:text-slate-900 disabled:opacity-60 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white"
                                >
                                    <x-ui.icon name="arrow-path" class="h-3.5 w-3.5" x-bind:class="columns[{{ \Illuminate\Support\Js::from($status) }}].loading ? 'animate-spin' : ''" />
                                    <span x-text="columns[{{ \Illuminate\Support\Js::from($status) }}].loading ? 'Loading…' : 'Load more'">Load more</span>
                                </button>
                            </div>
                        </section>
                    @endforeach
                </div>
            </div>

            <p class="text-xs text-slate-500 dark:text-slate-400">
                A column's value is the sum of the budgets its leads carry; leads without a budget are counted but add nothing to it.
            </p>
        @endif

        {{-- Details a move cannot succeed without, asked before the card moves (§8.2). Inside the board scope. --}}
        <x-ui.modal name="lead-board-move" title="A little more before this move" icon="arrow-right">
            <form id="lead-board-move-form" class="space-y-4" x-on:submit.prevent="confirmPending()">
                <p class="text-sm text-slate-600 dark:text-slate-300" x-show="pending">
                    Moving <span class="font-semibold text-slate-900 dark:text-white" x-text="pending?.card?.name"></span>
                    to <span class="font-semibold text-slate-900 dark:text-white" x-text="pending ? label(pending.to) : ''"></span>.
                </p>

                <div x-show="pending?.needs?.lost" class="space-y-2">
                    <label for="board-lost-reason" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Why was it lost? <span class="text-rose-500">*</span></label>
                    <select id="board-lost-reason" x-model="form.lost_choice" class="{{ $fieldClass }}">
                        <option value="">Choose a reason</option>
                        @foreach ($lostReasons as $reason)
                            <option value="{{ $reason }}">{{ $reason }}</option>
                        @endforeach
                        <option value="__other">Something else…</option>
                    </select>
                    <input type="text" x-show="form.lost_choice === '__other'" x-model="form.lost_other" maxlength="255" placeholder="Type the reason" aria-label="Lost reason" class="{{ $fieldClass }}">
                </div>

                <div x-show="pending?.needs?.reopen">
                    <label for="board-reopen-reason" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Reason for reopening <span class="text-rose-500">*</span></label>
                    <input id="board-reopen-reason" type="text" x-model="form.reason" maxlength="255" class="{{ $fieldClass }}">
                </div>

                <fieldset x-show="pending?.needs?.followUp" class="space-y-3 rounded-lg border border-amber-200 bg-amber-50/60 p-3 dark:border-amber-500/30 dark:bg-amber-500/10">
                    <legend class="px-1 text-xs font-semibold text-amber-800 dark:text-amber-200">This stage needs a scheduled follow-up</legend>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label for="board-follow-up-type" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Type</label>
                            <select id="board-follow-up-type" x-model="form.follow_up_type" class="{{ $fieldClass }}">
                                @foreach ($followUpTypeOptions as $typeValue => $typeLabel)
                                    <option value="{{ $typeValue }}">{{ $typeLabel }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="board-follow-up-at" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Due <span class="text-rose-500">*</span></label>
                            <input id="board-follow-up-at" type="datetime-local" x-model="form.follow_up_at" class="{{ $fieldClass }}">
                        </div>
                    </div>
                    <div>
                        <label for="board-follow-up-notes" class="block text-xs font-medium text-slate-600 dark:text-slate-300">What to say or send</label>
                        <input id="board-follow-up-notes" type="text" x-model="form.follow_up_notes" maxlength="255" class="{{ $fieldClass }}">
                    </div>
                </fieldset>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="cancelPending()">Don't move</x-ui.button>
                <x-ui.button type="submit" form="lead-board-move-form" icon="arrow-right">Move lead</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    </div>
@endsection
