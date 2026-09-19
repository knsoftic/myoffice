@extends('layouts.admin')

@section('title', $lead->name)

{{--
    Lead detail — admin.leads.show (phase-05 §8.3). "Who is this, what happened, what is next." LeadPolicy::view has
    passed (another rep's lead is a 404 for a user without leads.view_any, §9.1).

    Controller variables (Admin\LeadController@show):
      $lead                      App\Models\Crm\Lead with assignee, assigner, converter, creator, service, client,
                                 duplicateOf, contactInquiry (when the table exists), leadImport
      $activities                Collection|LengthAwarePaginator<LeadActivity> newest first (occurred_at, id), with
                                 creator, fromUser, toUser, relatedLead
      $followUps                 Collection<LeadFollowUp> newest first, with assignee, completedBy
      $openFollowUp              ?LeadFollowUp  the single `pending` row
      $conversions               Collection<LeadConversion> newest first, with client, convertedBy
      $duplicateReport           ?DuplicateReport  LeadDuplicateDetector::checkLead() — read through data_get():
                                 matches: list<{restricted, match_type, match_type_label, is_exact, record_type,
                                 record_type_label, lead_id, name, company, status_label, owner_name,
                                 last_activity_human, is_trashed, url}>
      $referral                  array{available: bool, collaborator_name: ?string, collaborator_code: ?string}
                                 — the resolved collaborator only when ReferralRecorder::isAvailable()
      $allActivityTypeOptions    array<string, string>  LeadActivityType::options() (the timeline filter)
      $whatsappTemplate          ?string  crm.whatsapp_link_template
      + the dialog variables of admin/leads/partials/dialogs: $assigneeOptions, $statusLabels, $transitions,
        $followUpRequiredStatuses, $lostReasons, $activityTypeOptions, $outcomeOptions, $followUpTypeOptions,
        $followUpDefaultAt, $followUpReminderMinutes
    Query: ?tab=overview|timeline|follow-ups|conversion opens that tab; ?log=1 opens the Log activity dialog.

    Writes from this page: the dialogs; POST admin.leads.duplicate-link {original_lead_id, note};
    POST admin.leads.conversions.supersede {conversion} {reason}; DELETE admin.leads.destroy {reason};
    POST admin.leads.restore.
--}}

@php
    use Illuminate\Support\Facades\Route;

    $user = auth()->user();
    $statusValue = $lead->status instanceof \BackedEnum ? $lead->status->value : (string) $lead->status;
    $transitions = (array) ($transitions ?? []);
    $trashed = method_exists($lead, 'trashed') && $lead->trashed();
    $assignee = $lead->relationLoaded('assignee') ? $lead->assignee : null;
    $activities = $activities ?? collect();
    $activityItems = collect($activities instanceof \Illuminate\Contracts\Pagination\Paginator ? $activities->items() : $activities);
    $followUps = collect($followUps ?? []);
    $openFollowUp = $openFollowUp ?? $followUps->first(static function ($followUp): bool {
        $value = $followUp->status instanceof \BackedEnum ? $followUp->status->value : (string) $followUp->status;

        return $value === 'pending';
    });
    $conversions = collect($conversions ?? []);
    $liveConversion = $conversions->first(static fn ($conversion): bool => $conversion->superseded_at === null);

    $canUpdate = ! $trashed && (bool) $user?->can('update', $lead);
    $canStatus = ! $trashed && (bool) $user?->can('changeStatus', $lead);
    $canAssign = ! $trashed && (bool) $user?->can('assign', $lead);
    $canConvert = ! $trashed && (bool) $user?->can('convert', $lead) && $liveConversion === null;
    $canDelete = ! $trashed && (bool) $user?->can('delete', $lead);
    $canRestore = $trashed && (bool) $user?->can('leads.restore');
    $canPrint = (bool) $user?->can('leads.print') && Route::has('admin.leads.print');
    $canLog = $canUpdate && Route::has('admin.leads.activities.store');
    $canSchedule = $canUpdate && Route::has('admin.leads.follow-ups.store') && $openFollowUp === null;

    $tabKeys = ['overview', 'timeline', 'follow-ups', 'conversion'];
    $initialTab = in_array(request('tab'), $tabKeys, true) ? request('tab') : 'overview';
    $openLog = request()->boolean('log') && $canLog;

    $tabs = [
        ['label' => 'Overview', 'key' => 'overview', 'icon' => 'user'],
        ['label' => 'Timeline', 'key' => 'timeline', 'icon' => 'clock', 'count' => app_number($activities instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator ? $activities->total() : $activityItems->count())],
        ['label' => 'Follow-ups', 'key' => 'follow-ups', 'icon' => 'calendar-days', 'count' => app_number($followUps->count())],
        ['label' => 'Conversion', 'key' => 'conversion', 'icon' => 'check-badge'],
    ];

    $logActivityEvent = ['name' => 'lead-activity', 'url' => $canLog ? route('admin.leads.activities.store', $lead) : null, 'label' => $lead->name, 'method' => 'POST'];
@endphp

@section('header')
    <x-ui.page-header :title="$lead->name" :subtitle="collect([$lead->lead_no, $lead->company])->filter()->implode(' · ')" icon="user" :back="route('admin.leads.index')">
        <div class="mt-2 flex flex-wrap items-center gap-2">
            @include('admin.crm.partials.enum-badge', ['value' => $lead->status])
            @include('admin.crm.partials.enum-badge', ['value' => $lead->source, 'dot' => false, 'variant' => 'outline'])
            @if ($assignee)
                <span class="inline-flex items-center gap-1.5 text-xs text-slate-600 dark:text-slate-300">
                    <x-ui.avatar :src="$assignee->avatar_url ?? null" :name="$assignee->name" size="xs" /> {{ $assignee->name }}
                </span>
            @else
                <span class="text-xs text-slate-400 dark:text-slate-500">Unassigned</span>
            @endif
            @if ($trashed)
                <x-ui.badge color="rose" size="sm" icon="trash">In the trash</x-ui.badge>
            @endif
            @include('admin.crm.partials.contact-links', ['phone' => $lead->phone, 'whatsapp' => $lead->whatsapp, 'email' => $lead->email, 'name' => $lead->name, 'whatsappTemplate' => $whatsappTemplate ?? null, 'size' => 'sm'])
        </div>

        <x-slot:actions>
            @if ($canRestore && Route::has('admin.leads.restore'))
                <form method="POST" action="{{ route('admin.leads.restore', $lead) }}">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" icon="arrow-path">Restore</x-ui.button>
                </form>
            @endif
            @if ($canLog)
                <x-ui.button variant="secondary" icon="chat-bubble-left-right" x-on:click="$dispatch('open-modal', {{ \Illuminate\Support\Js::from($logActivityEvent) }})">Log activity</x-ui.button>
            @endif
            @if ($canSchedule)
                <x-ui.button variant="secondary" icon="calendar-days" x-on:click="$dispatch('open-modal', { name: 'lead-follow-up', url: {{ \Illuminate\Support\Js::from(route('admin.leads.follow-ups.store', $lead)) }}, label: {{ \Illuminate\Support\Js::from($lead->name) }}, assignee: {{ \Illuminate\Support\Js::from($lead->assigned_to) }} })">Schedule follow-up</x-ui.button>
            @endif
            @if ($canConvert)
                <x-ui.button icon="check-badge" :href="route('admin.leads.convert.form', $lead)">Convert</x-ui.button>
            @endif
            @if ($canUpdate || $canStatus || $canAssign || $canPrint)
                <x-ui.dropdown label="More actions">
                    @if ($canStatus)
                        <x-ui.dropdown-item icon="arrow-path" x-on:click="open = false; $dispatch('open-modal', { name: 'lead-status', url: {{ \Illuminate\Support\Js::from(route('admin.leads.status', $lead)) }}, label: {{ \Illuminate\Support\Js::from($lead->name) }}, current: {{ \Illuminate\Support\Js::from($statusValue) }}, allowed: {{ \Illuminate\Support\Js::from(array_values($transitions[$statusValue] ?? [])) }}, hasFollowUp: {{ \Illuminate\Support\Js::from($openFollowUp !== null) }} })">Change status</x-ui.dropdown-item>
                    @endif
                    @if ($canAssign)
                        <x-ui.dropdown-item icon="user-plus" x-on:click="open = false; $dispatch('open-modal', { name: 'lead-assign', url: {{ \Illuminate\Support\Js::from(route('admin.leads.assign', $lead)) }}, label: {{ \Illuminate\Support\Js::from($lead->name) }}, current: {{ \Illuminate\Support\Js::from($lead->assigned_to) }} })">Assign</x-ui.dropdown-item>
                    @endif
                    @if ($canUpdate)
                        <x-ui.dropdown-item icon="pencil" :href="route('admin.leads.edit', $lead)">Edit details</x-ui.dropdown-item>
                    @endif
                    @if ($canPrint)
                        <x-ui.dropdown-item icon="printer" :href="route('admin.leads.print', $lead)" target="_blank" rel="noopener">Print</x-ui.dropdown-item>
                    @endif
                </x-ui.dropdown>
            @endif
            @if ($canDelete)
                <x-ui.confirm
                    :action="route('admin.leads.destroy', $lead)"
                    :title="'Delete '.$lead->name.'?'"
                    message="The lead moves to the trash and can be restored. Its timeline is kept and an open follow-up is cancelled."
                    confirm-label="Delete lead"
                    id="lead-delete-form"
                >
                    <x-slot:trigger>
                        <x-ui.icon-button icon="trash" variant="danger" label="Delete lead" />
                    </x-slot:trigger>
                    <div class="mt-3">
                        <label for="lead-delete-reason" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Reason <span class="text-rose-500">*</span></label>
                        <input id="lead-delete-reason" type="text" name="reason" form="lead-delete-form" required maxlength="255" class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-rose-500 focus:ring-rose-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                    </div>
                </x-ui.confirm>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="space-y-6" x-data="uiTabs({{ \Illuminate\Support\Js::from($initialTab) }})" @if ($openLog) x-init="$nextTick(() => $dispatch('open-modal', {{ \Illuminate\Support\Js::from($logActivityEvent) }}))" @endif>
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-ui.stat-card label="Budget" :value="$lead->budget_amount !== null ? money((string) $lead->budget_amount) : '—'" icon="banknotes" color="emerald" />
            <x-ui.stat-card label="Next follow-up" :value="$lead->follow_up_at ? app_datetime($lead->follow_up_at) : 'None'" icon="calendar-days" :color="$lead->follow_up_at && \Illuminate\Support\Carbon::parse($lead->follow_up_at)->isPast() ? 'rose' : 'amber'" />
            <x-ui.stat-card label="Last activity" :value="$lead->last_activity_at ? \App\Support\Format::forHumans($lead->last_activity_at) : 'None yet'" icon="clock" color="sky" />
            <x-ui.stat-card label="In this stage" :value="\App\Support\Format::forHumans($lead->status_changed_at ?? $lead->created_at) ?: '—'" icon="flag" color="violet" />
        </div>

        <x-ui.tabs :tabs="$tabs" />

        <div x-show="is('overview')" @if ($initialTab !== 'overview') x-cloak @endif role="tabpanel">
            @include('admin.leads.partials.overview')
        </div>

        <div x-show="is('timeline')" @if ($initialTab !== 'timeline') x-cloak @endif role="tabpanel">
            @include('admin.leads.partials.timeline', ['activityItems' => $activityItems, 'activities' => $activities, 'canLog' => $canLog, 'logActivityEvent' => $logActivityEvent])
        </div>

        <div x-show="is('follow-ups')" @if ($initialTab !== 'follow-ups') x-cloak @endif role="tabpanel">
            @include('admin.leads.partials.follow-ups', ['followUps' => $followUps, 'canSchedule' => $canSchedule])
        </div>

        <div x-show="is('conversion')" @if ($initialTab !== 'conversion') x-cloak @endif role="tabpanel">
            @include('admin.leads.partials.conversion', ['conversions' => $conversions, 'canConvert' => $canConvert])
        </div>
    </div>

    @include('admin.leads.partials.dialogs')
@endsection
