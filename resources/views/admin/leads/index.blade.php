@extends('layouts.admin')

@section('title', 'Leads')

{{--
    Leads index — admin.leads.index (phase-05 §8.1). The pipeline as a working list, and the only place bulk operations
    happen. Every query already went through LeadVisibilityScope ([D-P5-8]): a user without leads.view_any sees only
    leads they own or created.

    Controller variables (Admin\LeadController@index):
      $leads                     LengthAwarePaginator<App\Models\Crm\Lead> with `assignee`, `service` eager-loaded;
                                 withTrashed() only when ?trashed=1 and the user holds leads.restore
      $filters                   array<string, mixed>  the validated query below
      $sort                      string  lead_no | name | budget_amount | status | follow_up_at | last_activity_at |
                                 created_at (default)
      $direction                 string  asc | desc (default desc)
      $statusOptions             array<string, string>  LeadStatus::options(), in sortOrder()
      $sourceOptions             array<string, string>  InquirySource::options()
      $assigneeOptions           array<int, string>     users who may own a lead
      $transitions               array<string, list<string>>  LeadStatus value => allowedTransitions() values
      $followUpRequiredStatuses  list<string>  the statuses crm.require_follow_up_on_contacted guards ([] when off)
      $lostReasons               list<string>  crm.lost_reasons
      $activityTypeOptions       array<string, string>  the five manual LeadActivityType cases
      $outcomeOptions            array<string, string>  LeadContactOutcome::options()
      $followUpTypeOptions       array<string, string>  LeadFollowUpType::options()
      $followUpDefaultAt         string  'Y-m-d\TH:i' display timezone, now + crm.follow_up_default_offset_hours
      $followUpReminderMinutes   int     crm.follow_up_reminder_minutes
      $bulkMax                   int     crm.bulk_max_ids
      $whatsappTemplate          ?string crm.whatsapp_link_template
      $isEmptyModule             bool    no lead is visible to the user at all ("No leads yet" rather than "no match")
      $serviceOptions, $staleDays, $trashed   passed, read where useful
    Query: search (lead_no, name, company, phone, whatsapp, email), status[], source[], assignee (me | unassigned | user id),
    follow_up (overdue | today | next_7_days | none), created_from, created_to (Y-m-d, display timezone), budget_min,
    budget_max, has_duplicate (1), converted (yes | no), trashed (1), sort, direction, page.
--}}

@php
    use Illuminate\Support\Facades\Route;

    $user = auth()->user();
    $sort = $sort ?? 'created_at';
    $direction = $direction ?? 'desc';
    $statusOptions = (array) ($statusOptions ?? (enum_exists(\App\Enums\LeadStatus::class) ? \App\Enums\LeadStatus::options() : []));
    $sourceOptions = (array) ($sourceOptions ?? (enum_exists(\App\Enums\InquirySource::class) ? \App\Enums\InquirySource::options() : []));
    $assigneeOptions = collect($assigneeOptions ?? [])->all();
    $transitions = (array) ($transitions ?? []);
    $bulkMax = max(1, (int) ($bulkMax ?? 200));
    $isTrash = request()->boolean('trashed') && (bool) $user?->can('leads.restore');
    $filtered = collect(request()->except(['page', 'sort', 'direction']))->filter(fn ($value) => filled($value))->isNotEmpty();

    $canCreate = (bool) $user?->can('leads.create');
    $canImport = (bool) $user?->can('leads.import') && Route::has('admin.leads.import.index');
    $canExport = (bool) $user?->can('leads.export') && Route::has('admin.leads.export');
    $canBulkAssign = (bool) $user?->can('leads.assign') && Route::has('admin.leads.bulk.assign');
    $canBulkStatus = (bool) $user?->can('leads.change_status') && Route::has('admin.leads.bulk.status');
    $canBulkDelete = (bool) $user?->can('leads.delete') && Route::has('admin.leads.bulk.destroy');
    $canRestore = (bool) $user?->can('leads.restore');
    $selectable = ! $isTrash && ($canBulkAssign || $canBulkStatus || $canBulkDelete || $canExport);

    $viewTabs = [
        ['label' => 'List', 'url' => route('admin.leads.index'), 'active' => true, 'icon' => 'table-cells'],
        ['label' => 'Board', 'url' => route('admin.leads.board'), 'icon' => 'view-columns'],
        ['label' => 'Follow-ups', 'url' => route('admin.leads.follow-ups.index'), 'icon' => 'calendar-days'],
    ];

    $followUpFilterOptions = ['overdue' => 'Overdue', 'today' => 'Due today', 'next_7_days' => 'Next 7 days', 'none' => 'No follow-up'];
    $assigneeFilterOptions = ['me' => 'Me', 'unassigned' => 'Unassigned'] + $assigneeOptions;
@endphp

@section('header')
    <x-ui.page-header title="Leads" subtitle="Every inquiry, call and campaign lead, from first contact to a won client." icon="funnel">
        <x-slot:actions>
            @if ($canExport)
                <x-ui.button variant="secondary" icon="arrow-down-tray" :href="route('admin.leads.export', request()->except('page'))">Export</x-ui.button>
            @endif
            @if ($canImport)
                <x-ui.button variant="secondary" icon="arrow-up-tray" :href="route('admin.leads.import.index')">Import CSV</x-ui.button>
            @endif
            @if ($canCreate)
                <x-ui.button icon="plus" :href="route('admin.leads.create')">Add lead</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div x-data="{ navigating: false }" x-on:submit.window="if ($event.target?.method === 'get') navigating = true" class="space-y-4">
        <x-ui.tabs :tabs="$viewTabs" variant="pill" class="w-fit" />

        <x-ui.filter-bar placeholder="Search lead #, name, company, phone, WhatsApp or email…" :reset="route('admin.leads.index')">
            @include('admin.crm.partials.multi-filter', ['name' => 'status', 'label' => 'Status', 'options' => $statusOptions, 'selected' => (array) request('status', [])])
            @include('admin.crm.partials.multi-filter', ['name' => 'source', 'label' => 'Source', 'options' => $sourceOptions, 'selected' => (array) request('source', [])])
            <x-ui.form.select name="assignee" :options="$assigneeFilterOptions" :selected="request('assignee')" placeholder="Any owner" size="sm" aria-label="Filter by owner" />
            <x-ui.form.select name="follow_up" :options="$followUpFilterOptions" :selected="request('follow_up')" placeholder="Any follow-up" size="sm" aria-label="Filter by follow-up" />
            <x-ui.form.select name="converted" :options="['yes' => 'Converted', 'no' => 'Not converted']" :selected="request('converted')" placeholder="Converted or not" size="sm" aria-label="Filter by conversion" />
            <label class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                <span class="shrink-0">From</span>
                <input type="date" name="created_from" value="{{ request('created_from') }}" class="block w-full rounded-lg border-slate-300 py-1.5 text-xs shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
            </label>
            <label class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                <span class="shrink-0">To</span>
                <input type="date" name="created_to" value="{{ request('created_to') }}" class="block w-full rounded-lg border-slate-300 py-1.5 text-xs shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
            </label>
            <div class="flex items-center gap-1.5">
                <input type="text" name="budget_min" value="{{ request('budget_min') }}" inputmode="decimal" placeholder="Budget min" aria-label="Minimum budget" class="block w-full min-w-[6rem] rounded-lg border-slate-300 py-1.5 text-xs shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                <input type="text" name="budget_max" value="{{ request('budget_max') }}" inputmode="decimal" placeholder="Budget max" aria-label="Maximum budget" class="block w-full min-w-[6rem] rounded-lg border-slate-300 py-1.5 text-xs shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
            </div>
            <label class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="has_duplicate" value="1" @checked(request()->boolean('has_duplicate')) class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800">
                Has duplicate
            </label>
            @if ($canRestore)
                <label class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                    <input type="checkbox" name="trashed" value="1" @checked($isTrash) class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800">
                    Trashed
                </label>
            @endif
        </x-ui.filter-bar>

        <x-ui.table loading="navigating" :is-empty="$leads->isEmpty()" :columns="10" :selectable="$selectable" selection-label="lead">
            @if ($selectable)
                <x-slot:bulk>
                    <div
                        class="flex flex-wrap items-center gap-2"
                        x-data="{
                            max: {{ $bulkMax }},
                            statuses() {
                                return Object.fromEntries(this.selected.map((id) => [id, document.querySelector(`[data-row-select][value='${id}']`)?.dataset.status || '']));
                            },
                        }"
                    >
                        <p x-show="pickedCount > max" x-cloak class="text-xs font-medium text-rose-700 dark:text-rose-300">
                            Selection is limited to {{ app_number($bulkMax) }} leads.
                        </p>
                        @if ($canBulkAssign)
                            <x-ui.button size="sm" variant="secondary" icon="user-plus" x-bind:disabled="pickedCount > max" x-on:click="$dispatch('open-modal', { name: 'lead-bulk-assign', ids: [...selected] })">Assign</x-ui.button>
                        @endif
                        @if ($canBulkStatus)
                            <x-ui.button size="sm" variant="secondary" icon="arrow-path" x-bind:disabled="pickedCount > max" x-on:click="$dispatch('open-modal', { name: 'lead-bulk-status', ids: [...selected], statuses: statuses() })">Change status</x-ui.button>
                        @endif
                        @if ($canExport)
                            <x-ui.button size="sm" variant="secondary" icon="arrow-down-tray" x-bind:disabled="pickedCount > max" x-on:click="window.location.href = {{ \Illuminate\Support\Js::from(route('admin.leads.export')) }} + '?' + selected.map((id) => 'ids[]=' + encodeURIComponent(id)).join('&')">Export</x-ui.button>
                        @endif
                        @if ($canBulkDelete)
                            <x-ui.button size="sm" variant="danger" icon="trash" x-bind:disabled="pickedCount > max" x-on:click="$dispatch('open-modal', { name: 'lead-bulk-delete', ids: [...selected] })">Delete</x-ui.button>
                        @endif
                    </div>
                </x-slot:bulk>
            @endif

            <x-slot:head>
                <x-ui.th-sortable column="lead_no" :sort="$sort" :direction="$direction">Lead #</x-ui.th-sortable>
                <x-ui.th-sortable column="name" :sort="$sort" :direction="$direction">Name</x-ui.th-sortable>
                <th scope="col" class="px-4 py-3">Contact</th>
                <th scope="col" class="px-4 py-3">Source</th>
                <th scope="col" class="px-4 py-3">Interested in</th>
                <x-ui.th-sortable column="budget_amount" :sort="$sort" :direction="$direction" align="right" :numeric="true" default="desc">Budget</x-ui.th-sortable>
                <x-ui.th-sortable column="status" :sort="$sort" :direction="$direction">Status</x-ui.th-sortable>
                <th scope="col" class="px-4 py-3">Owner</th>
                <x-ui.th-sortable column="follow_up_at" :sort="$sort" :direction="$direction">Next follow-up</x-ui.th-sortable>
                <x-ui.th-sortable column="last_activity_at" :sort="$sort" :direction="$direction" default="desc">Last activity</x-ui.th-sortable>
                <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($leads as $lead)
                @php
                    $statusValue = $lead->status instanceof \BackedEnum ? $lead->status->value : (string) $lead->status;
                    $assignee = $lead->relationLoaded('assignee') ? $lead->assignee : null;
                    $service = $lead->relationLoaded('service') ? $lead->service : null;
                    $trashed = method_exists($lead, 'trashed') && $lead->trashed();
                    $canView = (bool) $user?->can('view', $lead);
                    $canUpdate = ! $trashed && (bool) $user?->can('update', $lead);
                    $canStatus = ! $trashed && (bool) $user?->can('changeStatus', $lead);
                    $canAssign = ! $trashed && (bool) $user?->can('assign', $lead);
                    $canConvert = ! $trashed && (bool) $user?->can('convert', $lead) && blank($lead->converted_at);
                    $canDelete = ! $trashed && (bool) $user?->can('delete', $lead);
                    $showUrl = route('admin.leads.show', $lead);
                @endphp
                <tr>
                    @if ($selectable)
                        <td class="w-10">
                            <input type="checkbox" data-row-select data-status="{{ $statusValue }}" value="{{ $lead->getKey() }}" x-model="selected" aria-label="Select {{ $lead->name }}" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-slate-600 dark:bg-slate-800" />
                        </td>
                    @endif

                    <td class="whitespace-nowrap font-mono text-xs text-slate-500 dark:text-slate-400">{{ $lead->lead_no }}</td>

                    <td class="min-w-[12rem]">
                        <a href="{{ $showUrl }}" class="block truncate font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $lead->name }}</a>
                        <span class="flex flex-wrap items-center gap-1.5">
                            @if (filled($lead->company))
                                <span class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $lead->company }}</span>
                            @endif
                            @if (filled($lead->duplicate_of_lead_id))
                                <x-ui.badge color="amber" size="xs" icon="link">Duplicate</x-ui.badge>
                            @endif
                            @if (filled($lead->converted_at))
                                <x-ui.badge color="emerald" size="xs" icon="check-badge">Converted</x-ui.badge>
                            @endif
                            @if ($trashed)
                                <x-ui.badge color="rose" size="xs" variant="outline">Trashed</x-ui.badge>
                            @endif
                        </span>
                    </td>

                    <td>
                        @include('admin.crm.partials.contact-links', ['phone' => $lead->phone, 'whatsapp' => $lead->whatsapp, 'email' => $lead->email, 'name' => $lead->name, 'whatsappTemplate' => $whatsappTemplate ?? null, 'size' => 'xs'])
                    </td>

                    <td class="whitespace-nowrap">@include('admin.crm.partials.enum-badge', ['value' => $lead->source, 'dot' => false, 'variant' => 'outline'])</td>

                    <td class="max-w-[12rem] truncate text-sm">{{ $service?->name ?? ($lead->interested_service ?: '—') }}</td>

                    <td class="whitespace-nowrap text-right tabular-nums">
                        @if ($lead->budget_amount !== null)
                            {{ money((string) $lead->budget_amount) }}
                        @else
                            <span class="text-slate-400 dark:text-slate-500">—</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">@include('admin.crm.partials.enum-badge', ['value' => $lead->status])</td>

                    <td class="whitespace-nowrap">
                        @if ($assignee)
                            <span class="flex items-center gap-2">
                                <x-ui.avatar :src="$assignee->avatar_url ?? null" :name="$assignee->name" size="xs" />
                                <span class="max-w-[8rem] truncate text-sm">{{ $assignee->name }}</span>
                            </span>
                        @else
                            <span class="text-xs text-slate-400 dark:text-slate-500">Unassigned</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">@include('admin.crm.partials.follow-up-chip', ['at' => $lead->follow_up_at, 'compact' => true])</td>

                    <td class="whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">
                        @if ($lead->last_activity_at)
                            <span title="{{ app_datetime($lead->last_activity_at) }}">{{ \App\Support\Format::forHumans($lead->last_activity_at) }}</span>
                        @else
                            —
                        @endif
                    </td>

                    <td>
                        <div class="flex items-center justify-end gap-1">
                            @if ($trashed)
                                @if ($canRestore && Route::has('admin.leads.restore'))
                                    <form method="POST" action="{{ route('admin.leads.restore', $lead) }}">
                                        @csrf
                                        <x-ui.button type="submit" size="sm" variant="secondary" icon="arrow-path">Restore</x-ui.button>
                                    </form>
                                @endif
                            @else
                                @if ($canView)
                                    <x-ui.icon-button icon="eye" size="sm" :href="$showUrl" :label="'Open '.$lead->name" />
                                @endif

                                @if ($canUpdate || $canStatus || $canAssign || $canConvert)
                                    <x-ui.dropdown :label="'Actions for '.$lead->name">
                                        @if ($canUpdate)
                                            <x-ui.dropdown-item icon="pencil" :href="route('admin.leads.edit', $lead)">Edit</x-ui.dropdown-item>
                                            <x-ui.dropdown-item icon="chat-bubble-left-right" x-on:click="open = false; $dispatch('open-modal', { name: 'lead-activity', url: {{ \Illuminate\Support\Js::from(route('admin.leads.activities.store', $lead)) }}, label: {{ \Illuminate\Support\Js::from($lead->name) }}, method: 'POST' })">Log activity</x-ui.dropdown-item>
                                            <x-ui.dropdown-item icon="calendar-days" x-on:click="open = false; $dispatch('open-modal', { name: 'lead-follow-up', url: {{ \Illuminate\Support\Js::from(route('admin.leads.follow-ups.store', $lead)) }}, label: {{ \Illuminate\Support\Js::from($lead->name) }}, assignee: {{ \Illuminate\Support\Js::from($lead->assigned_to) }} })">Schedule follow-up</x-ui.dropdown-item>
                                        @endif
                                        @if ($canStatus)
                                            <x-ui.dropdown-item icon="arrow-path" x-on:click="open = false; $dispatch('open-modal', { name: 'lead-status', url: {{ \Illuminate\Support\Js::from(route('admin.leads.status', $lead)) }}, label: {{ \Illuminate\Support\Js::from($lead->name) }}, current: {{ \Illuminate\Support\Js::from($statusValue) }}, allowed: {{ \Illuminate\Support\Js::from(array_values($transitions[$statusValue] ?? [])) }}, hasFollowUp: {{ \Illuminate\Support\Js::from(filled($lead->follow_up_at)) }} })">Change status</x-ui.dropdown-item>
                                        @endif
                                        @if ($canAssign)
                                            <x-ui.dropdown-item icon="user-plus" x-on:click="open = false; $dispatch('open-modal', { name: 'lead-assign', url: {{ \Illuminate\Support\Js::from(route('admin.leads.assign', $lead)) }}, label: {{ \Illuminate\Support\Js::from($lead->name) }}, current: {{ \Illuminate\Support\Js::from($lead->assigned_to) }} })">Assign</x-ui.dropdown-item>
                                        @endif
                                        @if ($canConvert)
                                            <x-ui.dropdown-item icon="check-badge" :href="route('admin.leads.convert.form', $lead)">Convert to client</x-ui.dropdown-item>
                                        @endif
                                    </x-ui.dropdown>
                                @endif

                                @if ($canDelete)
                                    <x-ui.confirm
                                        :action="route('admin.leads.destroy', $lead)"
                                        :title="'Delete '.$lead->name.'?'"
                                        message="The lead moves to the trash and can be restored. Its timeline is kept and an open follow-up is cancelled. A converted lead cannot be deleted."
                                        confirm-label="Delete lead"
                                        id="lead-delete-{{ $lead->getKey() }}"
                                    >
                                        <x-slot:trigger>
                                            <x-ui.icon-button icon="trash" variant="danger" size="sm" :label="'Delete '.$lead->name" />
                                        </x-slot:trigger>
                                        {{-- The reason input sits in the dialog body and joins the confirm form through form="". --}}
                                        <div class="mt-3">
                                            <label for="lead-delete-reason-{{ $lead->getKey() }}-input" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Reason <span class="text-rose-500">*</span></label>
                                            <input id="lead-delete-reason-{{ $lead->getKey() }}-input" type="text" name="reason" form="lead-delete-{{ $lead->getKey() }}" required maxlength="255" class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-rose-500 focus:ring-rose-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                                        </div>
                                    </x-ui.confirm>
                                @endif
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                @if ($filtered || ! ($isEmptyModule ?? true))
                    <x-ui.empty-state icon="funnel" title="No leads match these filters">
                        <x-slot:action>
                            <x-ui.button variant="secondary" :href="route('admin.leads.index')">Clear filters</x-ui.button>
                        </x-slot:action>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state icon="funnel" title="No leads yet" message="Leads arrive from the website contact form, or you can add one by hand or import a CSV.">
                        @if ($canCreate || $canImport)
                            <x-slot:action>
                                @if ($canCreate)
                                    <x-ui.button icon="plus" :href="route('admin.leads.create')">Add lead</x-ui.button>
                                @endif
                                @if ($canImport)
                                    <x-ui.button variant="secondary" icon="arrow-up-tray" :href="route('admin.leads.import.index')">Import CSV</x-ui.button>
                                @endif
                            </x-slot:action>
                        @endif
                    </x-ui.empty-state>
                @endif
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$leads" label="leads" />
            </x-slot:footer>
        </x-ui.table>
    </div>

    @include('admin.leads.partials.dialogs')
@endsection
