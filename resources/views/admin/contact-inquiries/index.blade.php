@extends('layouts.admin')

@section('title', 'Contact inquiries')

{{--
    Contact inquiries — the routing queue — admin.contact-inquiries.index (phase-04 §8.10, §2.20, §6.10, §9.1.2).

    Controller variables (Admin\ContactInquiryController@index) — every query goes through
    ContactInquiry::visibleTo($user) (view_any = all rows, otherwise assigned_to = me); spam rows only on the spam tab;
    the technical columns (ContactInquiry::TECHNICAL_COLUMNS, spam_reason included) are NOT selected here:
      $inquiries        LengthAwarePaginator<App\Models\Cms\ContactInquiry> with service and assignee
      $routing          array<int, array{canRoute: bool, waitingReason: ?string}>  InquiryRouter::canRoute() / waitingReason()
      $tab              string  ContactInquiry::TABS — all (default) | new | service | course | general | awaiting | routed |
                        spam | trashed (trashed only for contact_inquiries.restore)
      $tabs             array<string, int>  the count of every tab the user may open
      $filters          array<string, mixed>
      $sort             string  created_at (default) | name | email | status | inquiry_type | routing_status
      $direction        string  asc | desc
      $typeOptions      array<string, string>  InquiryType::options()
      $statusOptions    array<string, string>  ContactInquiryStatus::options()
      $routingOptions   array<string, string>  InquiryRoutingStatus::options()
      $sourceOptions    array<string, string>  InquirySource::options()
      $services         array<int, string>
      $assignees        array<int, string>
      $targets          array<string, array{label: string, registered: bool, available: bool}>  one row per routing target;
                        `registered` false everywhere in the Phase 4 shipping state
      $contactUrl       ?string   the public contact page, for the empty state
      $showTechnical    bool      contact_inquiries.view_logs
      $can              array<string, bool>
      $routedLinks      optional array<int, array{label: string, url: ?string, missing: bool}>  a later phase's record links
    Query: tab, search (name, email, phone, subject, message), type, status, routing, assigned, service, source,
    from, to (received date, Y-m-d), sort, direction, page.

    Writes: POST .route {inquiry}; POST .route-pending (flash "N routed, M still waiting"); POST .status / .assign /
    .spam (dialogs); POST .not-spam {inquiry}; DELETE .destroy {inquiry}; GET .export (current query).
--}}

@php
    use Illuminate\Support\Facades\Route;

    $user = auth()->user();
    $canStatus = (bool) $user?->can('contact_inquiries.change_status');
    $canAssign = (bool) $user?->can('contact_inquiries.assign') && Route::has('admin.contact-inquiries.assign');
    $canDelete = (bool) $user?->can('contact_inquiries.delete');
    $canExport = (bool) $user?->can('contact_inquiries.export') && Route::has('admin.contact-inquiries.export');
    // ContactInquiryPolicy::routePending(): the backlog walk reaches unassigned rows, so it needs view_any as well.
    $canRoutePending = (bool) ($can['routePending'] ?? ($canStatus && $user?->can('contact_inquiries.view_any'))) && Route::has('admin.contact-inquiries.route-pending');
    $canViewLogs = (bool) ($showTechnical ?? $user?->can('contact_inquiries.view_logs'));
    $canRestore = (bool) $user?->can('contact_inquiries.restore');

    // The controller passes the per-tab counts as `tabs`; they are read before `$tabs` is rebuilt below for x-ui.tabs.
    $counts = $counts ?? (is_array($tabs ?? null) ? $tabs : []);
    $tab = in_array($tab ?? 'all', ['all', 'new', 'service', 'course', 'general', 'awaiting', 'routed', 'spam', 'trashed'], true) ? ($tab ?? 'all') : 'all';
    $sort = $sort ?? 'created_at';
    $direction = $direction ?? 'desc';
    $filtered = collect(request()->except(['tab', 'page', 'sort', 'direction']))->filter(fn ($v) => filled($v))->isNotEmpty();
    $targets = (array) ($targets ?? []);
    $routing = (array) ($routing ?? []);
    $routedLinks = (array) ($routedLinks ?? []);
    $routingStatusOptions = $routingStatusOptions ?? ($routingOptions ?? []);
    $serviceOptions = $serviceOptions ?? ($services ?? []);
    $assigneeOptions = $assigneeOptions ?? ($assignees ?? []);
    $publicContactUrl = $publicContactUrl ?? ($contactUrl ?? null);
    $isTrash = $tab === 'trashed';
    $selectable = $canStatus && Route::has('admin.contact-inquiries.route') && ! $isTrash && $tab !== 'spam';

    $tabDefs = [
        'all' => ['All', null], 'new' => ['New', 'sparkles'], 'service' => ['Service', 'wrench-screwdriver'], 'course' => ['Course', 'academic-cap'],
        'general' => ['General', 'chat-bubble-left-right'], 'awaiting' => ['Awaiting routing', 'clock'], 'routed' => ['Routed', 'check-circle'],
        'spam' => ['Spam', 'exclamation-triangle'], 'trashed' => ['Trashed', 'trash'],
    ];
    if (! $canRestore) {
        unset($tabDefs['trashed']);
    }
    $tabs = [];
    foreach ($tabDefs as $key => [$label, $tabIcon]) {
        $tabs[] = ['label' => $label, 'url' => route('admin.contact-inquiries.index', $key === 'all' ? [] : ['tab' => $key]), 'active' => $tab === $key, 'count' => isset($counts[$key]) ? app_number((int) $counts[$key]) : null, 'icon' => $tabIcon];
    }
    $awaiting = (int) ($counts['awaiting'] ?? 0);
@endphp

@section('header')
    <x-ui.page-header title="Contact inquiries" subtitle="Every website inquiry, kept for good, and handed to the CRM or the institute by its type." icon="inbox-stack">
        <x-slot:actions>
            @if ($canExport)
                <x-ui.button variant="secondary" icon="arrow-down-tray" :href="route('admin.contact-inquiries.export', request()->query())">Export</x-ui.button>
            @endif
            @if ($canRoutePending)
                <form method="POST" action="{{ route('admin.contact-inquiries.route-pending') }}">
                    @csrf
                    <x-ui.button type="submit" icon="arrow-path" :disabled="$awaiting === 0 && isset($counts['awaiting'])">
                        Route all pending{{ $awaiting > 0 ? ' ('.app_number($awaiting).')' : '' }}
                    </x-ui.button>
                </form>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.cms.partials.scripts')

    <div x-data="{ navigating: false }" x-on:submit.window="if ($event.target?.method === 'get') navigating = true" class="space-y-4">
        @if ($awaiting > 0 && collect($targets)->every(static fn ($target): bool => ! (bool) data_get($target, 'registered', false)))
            <div class="flex items-start gap-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25">
                <x-ui.icon name="information-circle" class="mt-0.5 h-5 w-5 shrink-0" />
                <p>
                    {{ app_number($awaiting) }} {{ $awaiting === 1 ? 'inquiry is' : 'inquiries are' }} waiting for a module that is not installed yet. They are safe here and
                    route themselves the moment the CRM or the institute module is added.
                </p>
            </div>
        @endif

        <x-ui.tabs :tabs="$tabs" />

        <x-ui.filter-bar placeholder="Search name, email, phone, subject or message…" :reset="route('admin.contact-inquiries.index', $tab === 'all' ? [] : ['tab' => $tab])">
            @if ($tab !== 'all')
                <input type="hidden" name="tab" value="{{ $tab }}">
            @endif
            @unless (in_array($tab, ['service', 'course', 'general'], true))
                <x-ui.form.select name="type" :options="$typeOptions ?? []" :selected="request('type')" placeholder="Any type" size="sm" aria-label="Filter by type" />
            @endunless
            <x-ui.form.select name="status" :options="$statusOptions ?? []" :selected="request('status')" placeholder="Any status" size="sm" aria-label="Filter by status" />
            <x-ui.form.select name="routing" :options="$routingStatusOptions ?? []" :selected="request('routing')" placeholder="Any routing state" size="sm" aria-label="Filter by routing state" />
            <x-ui.form.select name="assigned" :options="$assigneeOptions ?? []" :selected="request('assigned')" placeholder="Anyone" size="sm" aria-label="Filter by assignee" />
            <x-ui.form.select name="service" :options="$serviceOptions ?? []" :selected="request('service')" placeholder="Any service" size="sm" aria-label="Filter by service" />
            <x-ui.form.select name="source" :options="$sourceOptions ?? []" :selected="request('source')" placeholder="Any source" size="sm" aria-label="Filter by source" />
            <label class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                <span class="shrink-0">From</span>
                <input type="date" name="from" value="{{ request('from') }}" class="block w-full rounded-lg border-slate-300 py-1.5 text-xs shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
            </label>
            <label class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                <span class="shrink-0">To</span>
                <input type="date" name="to" value="{{ request('to') }}" class="block w-full rounded-lg border-slate-300 py-1.5 text-xs shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
            </label>
        </x-ui.filter-bar>

        <x-ui.table loading="navigating" :is-empty="$inquiries->isEmpty()" :columns="11" :selectable="$selectable" selection-label="inquiry">
            @if ($selectable)
                <x-slot:bulk>
                    <div
                        x-data="{
                            running: false,
                            template: @js(route('admin.contact-inquiries.route', [987654321])),
                            async routeSelected() {
                                if (this.running || ! this.selected.length) return;
                                this.running = true;
                                let done = 0;
                                const failures = [];
                                for (const id of [...this.selected]) {
                                    try {
                                        const response = await fetch(this.template.replace('987654321', String(id)), {
                                            method: 'POST',
                                            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '', 'X-Requested-With': 'XMLHttpRequest' },
                                            credentials: 'same-origin',
                                        });
                                        if (response.ok) { done++; } else { failures.push(id); }
                                    } catch (error) { failures.push(id); }
                                }
                                this.running = false;
                                const store = window.Alpine?.store('toasts');
                                if (failures.length) store?.push({ type: 'error', message: failures.length + ' not routed. Their rows show why.' });
                                if (done) store?.push({ type: 'success', message: done + ' routing ' + (done === 1 ? 'attempt' : 'attempts') + ' made. Refreshing…' });
                                setTimeout(() => window.location.reload(), 800);
                            },
                        }"
                    >
                        <x-ui.button size="sm" icon="arrow-right" x-on:click="routeSelected()" x-bind:disabled="running">
                            <span x-text="running ? 'Routing…' : 'Route selected'">Route selected</span>
                        </x-ui.button>
                    </div>
                </x-slot:bulk>
            @endif

            <x-slot:head>
                <x-ui.th-sortable column="name" :sort="$sort" :direction="$direction">From</x-ui.th-sortable>
                <x-ui.th-sortable column="inquiry_type" :sort="$sort" :direction="$direction">Type</x-ui.th-sortable>
                <th scope="col" class="px-4 py-3">Subject</th>
                <th scope="col" class="px-4 py-3">Service / course</th>
                <th scope="col" class="px-4 py-3">Budget</th>
                <th scope="col" class="px-4 py-3">Source</th>
                <x-ui.th-sortable column="routing_status" :sort="$sort" :direction="$direction">Routing</x-ui.th-sortable>
                <x-ui.th-sortable column="status" :sort="$sort" :direction="$direction">Status</x-ui.th-sortable>
                <th scope="col" class="px-4 py-3">Assigned</th>
                <x-ui.th-sortable column="created_at" :sort="$sort" :direction="$direction" default="desc">Received</x-ui.th-sortable>
                <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($inquiries as $inquiry)
                @php
                    $typeValue = $inquiry->inquiry_type instanceof \BackedEnum ? $inquiry->inquiry_type->value : (string) $inquiry->inquiry_type;
                    $statusValue = $inquiry->status instanceof \BackedEnum ? $inquiry->status->value : (string) $inquiry->status;
                    $routingValue = $inquiry->routing_status instanceof \BackedEnum ? $inquiry->routing_status->value : (string) $inquiry->routing_status;
                    $assignee = null;
                    foreach (['assignee', 'assignedTo'] as $relationName) {
                        if ($inquiry->relationLoaded($relationName)) {
                            $assignee = $inquiry->getRelation($relationName);
                            break;
                        }
                    }
                    $service = $inquiry->relationLoaded('service') ? $inquiry->service : null;
                    $isNew = $statusValue === 'new';
                    $selectableRow = $selectable && ! $inquiry->is_spam && in_array($routingValue, ['pending', 'failed'], true);
                @endphp
                <tr @class(['font-medium' => $isNew])>
                    @if ($selectable)
                        <td class="w-10">
                            @if ($selectableRow)
                                <input type="checkbox" data-row-select value="{{ $inquiry->getKey() }}" x-model="selected" aria-label="Select the inquiry from {{ $inquiry->name }}" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-slate-600 dark:bg-slate-800" />
                            @endif
                        </td>
                    @endif

                    <td class="min-w-[13rem]">
                        <div class="flex items-start gap-2">
                            @if ($isNew)
                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-brand-500" aria-hidden="true"></span>
                                <span class="sr-only">New:</span>
                            @endif
                            <div class="min-w-0">
                                <a href="{{ route('admin.contact-inquiries.show', $inquiry) }}" class="block truncate text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $inquiry->name }}</a>
                                <span class="block truncate text-xs font-normal text-slate-500 dark:text-slate-400">{{ $inquiry->email }}@if (filled($inquiry->phone)) · {{ $inquiry->phone }}@endif</span>
                                @if (filled($inquiry->company))
                                    <span class="block truncate text-xs font-normal text-slate-400 dark:text-slate-500">{{ $inquiry->company }}</span>
                                @endif
                            </div>
                        </div>
                    </td>

                    <td class="whitespace-nowrap">@include('admin.marketing.partials.enum-badge', ['value' => $inquiry->inquiry_type, 'dot' => false])</td>

                    <td class="min-w-[12rem] max-w-xs">
                        <p class="line-clamp-1 text-sm text-slate-700 dark:text-slate-200">{{ $inquiry->subject ?: \Illuminate\Support\Str::limit((string) $inquiry->message, 60) }}</p>
                    </td>

                    <td class="whitespace-nowrap text-sm font-normal">
                        @if ($typeValue === 'service')
                            {{ $service?->name ?? '—' }}
                        @elseif ($typeValue === 'course')
                            {{ $inquiry->course_name ?: '—' }}
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap text-sm font-normal">{{ $inquiry->budget ?: '—' }}</td>

                    <td class="whitespace-nowrap">@include('admin.marketing.partials.enum-badge', ['value' => $inquiry->source, 'dot' => false, 'variant' => 'outline'])</td>

                    <td class="min-w-[10rem] font-normal">
                        @include('admin.contact-inquiries.partials.routing-cell', ['inquiry' => $inquiry, 'targets' => $targets, 'rowRouting' => $routing[(int) $inquiry->getKey()] ?? null, 'routed' => $routedLinks[$inquiry->getKey()] ?? null, 'withActions' => ! $isTrash])
                    </td>

                    <td class="whitespace-nowrap font-normal">
                        @include('admin.marketing.partials.enum-badge', ['value' => $inquiry->status])
                        @if ($inquiry->is_spam)
                            <x-ui.badge color="rose" size="xs" variant="outline" class="mt-1" :title="$canViewLogs ? ($inquiry->getAttributes()['spam_reason'] ?? null) : null">Spam</x-ui.badge>
                        @endif
                    </td>

                    <td class="whitespace-nowrap font-normal">
                        @if ($assignee)
                            <x-ui.avatar :src="$assignee->avatar_url ?? null" :name="$assignee->name" size="xs" :title="$assignee->name" />
                        @else
                            <span class="text-xs text-slate-400">—</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap text-xs font-normal text-slate-500 dark:text-slate-400">{{ app_datetime($inquiry->created_at) }}</td>

                    <td class="font-normal">
                        <div class="flex items-center justify-end gap-1">
                            <x-ui.icon-button icon="eye" size="sm" label="Open the inquiry from {{ $inquiry->name }}" :href="route('admin.contact-inquiries.show', $inquiry)" />

                            @unless ($isTrash)
                                @if ($canAssign)
                                    <x-ui.icon-button icon="user-plus" size="sm" label="Assign the inquiry from {{ $inquiry->name }}" x-on:click="$dispatch('open-modal', { name: 'inquiry-assign', url: @js(route('admin.contact-inquiries.assign', $inquiry)), label: @js($inquiry->name), current: @js($inquiry->assigned_to) })" />
                                @endif

                                @if ($canStatus && Route::has('admin.contact-inquiries.status'))
                                    <x-ui.icon-button icon="check-badge" size="sm" label="Change the status of the inquiry from {{ $inquiry->name }}" x-on:click="$dispatch('open-modal', { name: 'inquiry-status', url: @js(route('admin.contact-inquiries.status', $inquiry)), label: @js($inquiry->name), current: @js($statusValue) })" />
                                @endif

                                @if ($canStatus && $inquiry->is_spam && Route::has('admin.contact-inquiries.not-spam'))
                                    <form method="POST" action="{{ route('admin.contact-inquiries.not-spam', $inquiry) }}">
                                        @csrf
                                        <x-ui.icon-button type="submit" icon="check-circle" size="sm" label="Not spam: restore the inquiry from {{ $inquiry->name }} and route it" class="text-emerald-600 dark:text-emerald-400" />
                                    </form>
                                @elseif ($canStatus && Route::has('admin.contact-inquiries.spam'))
                                    <x-ui.icon-button icon="exclamation-triangle" size="sm" label="Mark the inquiry from {{ $inquiry->name }} as spam" x-on:click="$dispatch('open-modal', { name: 'inquiry-spam', url: @js(route('admin.contact-inquiries.spam', $inquiry)), label: @js($inquiry->name) })" />
                                @endif

                                @if ($canDelete)
                                    <x-ui.confirm
                                        :action="route('admin.contact-inquiries.destroy', $inquiry)"
                                        :title="'Delete the inquiry from '.$inquiry->name.'?'"
                                        message="It moves to the trash. A lead or course inquiry already created from it is not touched."
                                        confirm-label="Delete inquiry"
                                    >
                                        <x-slot:trigger>
                                            <x-ui.icon-button icon="trash" variant="danger" size="sm" label="Delete the inquiry from {{ $inquiry->name }}" />
                                        </x-slot:trigger>
                                    </x-ui.confirm>
                                @endif
                            @endunless
                        </div>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                @if ($filtered)
                    <x-ui.empty-state icon="inbox-stack" title="No inquiries match those filters">
                        <x-slot:action>
                            <x-ui.button variant="secondary" :href="route('admin.contact-inquiries.index', $tab === 'all' ? [] : ['tab' => $tab])">Clear filters</x-ui.button>
                        </x-slot:action>
                    </x-ui.empty-state>
                @elseif ($tab === 'new')
                    <x-ui.empty-state icon="check-badge" title="No new inquiries" message="Everything has been read." :compact="true" />
                @elseif ($tab === 'spam')
                    <x-ui.empty-state icon="shield-check" title="No spam caught — the honeypot is doing its job" :compact="true" />
                @elseif ($tab === 'awaiting')
                    <x-ui.empty-state icon="check-circle" title="Nothing is waiting to be routed" :compact="true" />
                @elseif ($tab === 'trashed')
                    <x-ui.empty-state icon="trash" title="The trash is empty" :compact="true" />
                @elseif ($tab !== 'all')
                    <x-ui.empty-state icon="inbox-stack" :title="'No '.strtolower($tabDefs[$tab][0]).' inquiries'" :compact="true" />
                @else
                    <x-ui.empty-state icon="inbox-stack" title="No inquiries yet" :message="filled($publicContactUrl ?? null) ? 'They arrive from the contact form at '.$publicContactUrl : 'They arrive from the public contact form.'">
                        @if (filled($publicContactUrl ?? null))
                            <x-slot:action>
                                <x-ui.button variant="secondary" icon="arrow-top-right-on-square" :href="$publicContactUrl" target="_blank" rel="noopener">Open the contact page</x-ui.button>
                            </x-slot:action>
                        @endif
                    </x-ui.empty-state>
                @endif
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$inquiries" label="inquiries" />
            </x-slot:footer>
        </x-ui.table>
    </div>

    @include('admin.contact-inquiries.partials.dialogs', ['assigneeOptions' => $assigneeOptions, 'statusOptions' => $statusOptions ?? null])
@endsection
