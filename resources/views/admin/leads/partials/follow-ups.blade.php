{{--
    Lead detail · Follow-ups tab (phase-05 §8.3): the open follow-up at the top with Complete / Reschedule / Cancel, then
    the history with outcomes. At most one follow-up is ever `pending` (uq_lfu_open, [D-P5-12]).

    Included by admin/leads/show: $lead, $followUps (Collection<LeadFollowUp> with assignee, completedBy), $openFollowUp,
    $canSchedule. Actions are offered when LeadFollowUpPolicy::complete allows (assignee, lead owner, or leads.edit).
--}}

@php
    $followUpUser = auth()->user();
    $history = collect($followUps)->reject(static fn ($followUp): bool => $openFollowUp !== null && $followUp->is($openFollowUp));
@endphp

<div class="space-y-6">
    @if ($openFollowUp)
        @php
            $openAssignee = $openFollowUp->relationLoaded('assignee') ? $openFollowUp->assignee : null;
            $canAct = (bool) $followUpUser?->can('complete', $openFollowUp);
            $overdue = $openFollowUp->scheduled_at && \Illuminate\Support\Carbon::parse($openFollowUp->scheduled_at)->isPast();
        @endphp
        <x-ui.card title="Open follow-up" icon="calendar-days" :class="$overdue ? 'ring-2 ring-rose-300 dark:ring-rose-500/40' : ''">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="space-y-2 text-sm">
                    <p class="flex flex-wrap items-center gap-2">
                        @include('admin.crm.partials.enum-badge', ['value' => $openFollowUp->type, 'dot' => false])
                        @include('admin.crm.partials.follow-up-chip', ['at' => $openFollowUp->scheduled_at])
                    </p>
                    @if (filled($openFollowUp->notes))
                        <p class="text-slate-700 dark:text-slate-200">{{ $openFollowUp->notes }}</p>
                    @endif
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        {{ $openAssignee ? 'For '.$openAssignee->name : 'Nobody assigned' }}
                        @if ($openFollowUp->reminder_due_at)
                            · reminder {{ $openFollowUp->reminder_sent_at ? 'sent '.app_datetime($openFollowUp->reminder_sent_at) : 'due '.app_datetime($openFollowUp->reminder_due_at) }}
                        @endif
                    </p>
                </div>

                @if ($canAct)
                    <div class="flex flex-wrap items-center gap-2">
                        <x-ui.button size="sm" variant="success" icon="check" x-on:click="$dispatch('open-modal', { name: 'lead-follow-up-complete', url: {{ \Illuminate\Support\Js::from(route('admin.leads.follow-ups.complete', [$lead, $openFollowUp])) }}, label: {{ \Illuminate\Support\Js::from($lead->name) }} })">Complete</x-ui.button>
                        <x-ui.button size="sm" variant="secondary" icon="clock" x-on:click="$dispatch('open-modal', { name: 'lead-follow-up-reschedule', url: {{ \Illuminate\Support\Js::from(route('admin.leads.follow-ups.reschedule', [$lead, $openFollowUp])) }}, label: {{ \Illuminate\Support\Js::from($lead->name) }}, scheduledAt: {{ \Illuminate\Support\Js::from(app_datetime($openFollowUp->scheduled_at, 'Y-m-d\TH:i')) }} })">Reschedule</x-ui.button>
                        <x-ui.button size="sm" variant="ghost" icon="x-mark" class="text-rose-600 dark:text-rose-400" x-on:click="$dispatch('open-modal', { name: 'lead-follow-up-cancel', url: {{ \Illuminate\Support\Js::from(route('admin.leads.follow-ups.cancel', [$lead, $openFollowUp])) }}, label: {{ \Illuminate\Support\Js::from($lead->name) }} })">Cancel</x-ui.button>
                    </div>
                @endif
            </div>
        </x-ui.card>
    @else
        <x-ui.card :padded="false">
            <x-ui.empty-state icon="calendar-days" title="No follow-up scheduled" message="A lead with nothing scheduled is easy to forget." :compact="true">
                @if ($canSchedule ?? false)
                    <x-slot:action>
                        <x-ui.button icon="calendar-days" x-on:click="$dispatch('open-modal', { name: 'lead-follow-up', url: {{ \Illuminate\Support\Js::from(route('admin.leads.follow-ups.store', $lead)) }}, label: {{ \Illuminate\Support\Js::from($lead->name) }}, assignee: {{ \Illuminate\Support\Js::from($lead->assigned_to) }} })">Schedule follow-up</x-ui.button>
                    </x-slot:action>
                @endif
            </x-ui.empty-state>
        </x-ui.card>
    @endif

    <x-ui.table :is-empty="$history->isEmpty()" :dense="true" :columns="6">
        <x-slot:head>
            <th scope="col" class="px-4 py-3">Was due</th>
            <th scope="col" class="px-4 py-3">Type</th>
            <th scope="col" class="px-4 py-3">Status</th>
            <th scope="col" class="px-4 py-3">Outcome</th>
            <th scope="col" class="px-4 py-3">Note</th>
            <th scope="col" class="px-4 py-3">Closed</th>
        </x-slot:head>

        @foreach ($history as $followUp)
            @php
                $closedBy = $followUp->relationLoaded('completedBy') ? $followUp->completedBy : null;
                $closedAt = $followUp->completed_at ?? $followUp->rescheduled_at ?? $followUp->updated_at;
            @endphp
            <tr>
                <td class="whitespace-nowrap text-sm">{{ app_datetime($followUp->scheduled_at) }}</td>
                <td class="whitespace-nowrap">@include('admin.crm.partials.enum-badge', ['value' => $followUp->type, 'dot' => false, 'size' => 'xs'])</td>
                <td class="whitespace-nowrap">@include('admin.crm.partials.enum-badge', ['value' => $followUp->status, 'size' => 'xs'])</td>
                <td class="whitespace-nowrap">@include('admin.crm.partials.enum-badge', ['value' => $followUp->outcome, 'dot' => false, 'size' => 'xs'])</td>
                <td class="min-w-[12rem] max-w-sm text-sm">
                    {{ $followUp->outcome_note ?: ($followUp->cancel_reason ?: ($followUp->notes ?: '—')) }}
                </td>
                <td class="whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">
                    {{ app_datetime($closedAt) }}{{ $closedBy ? ' · '.$closedBy->name : '' }}
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state icon="clock" title="No follow-up history" :compact="true" />
        </x-slot:empty>
    </x-ui.table>
</div>
