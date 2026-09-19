{{--
    The shared lead dialogs (phase-05 §8.1 row actions and bulk bar, §8.3 header actions, §8.5 worklist actions).
    Rendered ONCE per page; every opener dispatches its row's data:

        $dispatch('open-modal', { name: 'lead-status', url, label, current, allowed: [...], hasFollowUp })
        $dispatch('open-modal', { name: 'lead-assign', url, label, current })
        $dispatch('open-modal', { name: 'lead-activity', url, label, method: 'POST'|'PUT', activity: {type, subject, body, outcome, duration_minutes, occurred_at} })
        $dispatch('open-modal', { name: 'lead-follow-up', url, label, assignee })
        $dispatch('open-modal', { name: 'lead-follow-up-complete', url, label })
        $dispatch('open-modal', { name: 'lead-follow-up-reschedule', url, label, scheduledAt })
        $dispatch('open-modal', { name: 'lead-follow-up-cancel', url, label })
        $dispatch('open-modal', { name: 'lead-bulk-assign', ids: [...] })
        $dispatch('open-modal', { name: 'lead-bulk-status', ids: [...], statuses: {id: status} })
        $dispatch('open-modal', { name: 'lead-bulk-delete', ids: [...] })

    Variables (all optional; each falls back to the enum or to an empty list):
      $assigneeOptions            array<int, string>     users who may own a lead (Active, holding leads.view)
      $statusLabels               array<string, string>  LeadStatus::options()
      $transitions                array<string, list<string>>  LeadStatus value => allowedTransitions() values (§2.11)
      $followUpRequiredStatuses   list<string>           statuses crm.require_follow_up_on_contacted guards; [] when off
      $lostReasons                list<string>           crm.lost_reasons
      $activityTypeOptions        array<string, string>  the five manual LeadActivityType cases
      $outcomeOptions             array<string, string>  LeadContactOutcome::options()
      $followUpTypeOptions        array<string, string>  LeadFollowUpType::options()
      $followUpDefaultAt          ?string                'Y-m-d\TH:i' in the display timezone: now + crm.follow_up_default_offset_hours
      $followUpReminderMinutes    ?int                   crm.follow_up_reminder_minutes

    Posts (field names — the Form Requests of §7):
      PATCH admin.leads.status {lead}                 to_status, expected_from_status, lost_reason, reason,
                                                      follow_up[type|scheduled_at|notes]
      PATCH admin.leads.assign {lead}                 assigned_to (empty = unassign), reason
      POST  admin.leads.activities.store {lead}       type, subject, body, outcome, duration_minutes, occurred_at
      PUT   admin.leads.activities.update             the same
      POST  admin.leads.follow-ups.store {lead}       type, scheduled_at, remind_before_minutes, assigned_to, notes
      PATCH admin.leads.follow-ups.complete           outcome, outcome_note, schedule_next, next[type|scheduled_at|notes]
      PATCH admin.leads.follow-ups.reschedule         scheduled_at, reason
      PATCH admin.leads.follow-ups.cancel             reason
      POST  admin.leads.bulk.assign                   ids[], assigned_to, reason
      POST  admin.leads.bulk.status                   ids[], to_status, lost_reason, reason
      POST  admin.leads.bulk.destroy                  ids[], reason
    Datetime inputs post 'Y-m-d\TH:i' in the viewer's display timezone.
--}}

@php
    $enumOptions = static fn (string $class): array => enum_exists($class) && method_exists($class, 'options') ? $class::options() : [];

    $dialogAssignees = collect($assigneeOptions ?? [])->all();
    $dialogStatusLabels = (array) ($statusLabels ?? $enumOptions(\App\Enums\LeadStatus::class));
    $dialogTransitions = (array) ($transitions ?? []);
    $dialogFollowUpRequired = array_values((array) ($followUpRequiredStatuses ?? []));
    $dialogLostReasons = array_values(array_filter((array) ($lostReasons ?? []), static fn ($reason): bool => is_string($reason) && trim($reason) !== ''));
    $dialogActivityTypes = (array) ($activityTypeOptions ?? array_intersect_key($enumOptions(\App\Enums\LeadActivityType::class), array_flip(['note', 'call', 'whatsapp', 'email', 'meeting'])));
    $dialogOutcomes = (array) ($outcomeOptions ?? $enumOptions(\App\Enums\LeadContactOutcome::class));
    $dialogFollowUpTypes = (array) ($followUpTypeOptions ?? $enumOptions(\App\Enums\LeadFollowUpType::class));
    $dialogDefaultAt = is_string($followUpDefaultAt ?? null) ? $followUpDefaultAt : '';
    $dialogReminder = isset($followUpReminderMinutes) ? (int) $followUpReminderMinutes : null;
    $dialogNow = app_datetime(now(), 'Y-m-d\TH:i');

    $fieldClass = 'mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white';
    $labelClass = 'block text-xs font-medium text-slate-600 dark:text-slate-300';
    $user = auth()->user();
@endphp

{{-- Change status ------------------------------------------------------------------------------------------ --}}
<x-ui.modal name="lead-status" title="Change status" icon="arrow-path">
    <form
        id="lead-status-form"
        method="POST"
        x-data="{
            url: '', label: '', current: '', allowed: [], hasFollowUp: false, to: '', lostChoice: '', lostOther: '',
            labels: {{ \Illuminate\Support\Js::from($dialogStatusLabels) }},
            followUpRequired: {{ \Illuminate\Support\Js::from($dialogFollowUpRequired) }},
            get lostReason() { return this.lostChoice === '__other' ? this.lostOther : this.lostChoice },
            get needsFollowUp() { return this.followUpRequired.includes(this.to) && ! this.hasFollowUp },
        }"
        x-on:open-modal.window="if ($event.detail?.name === 'lead-status') { url = $event.detail.url; label = $event.detail.label; current = $event.detail.current; allowed = $event.detail.allowed || []; hasFollowUp = Boolean($event.detail.hasFollowUp); to = allowed[0] || ''; lostChoice = ''; lostOther = ''; }"
        x-bind:action="url"
        class="space-y-4"
    >
        @csrf
        @method('PATCH')
        <input type="hidden" name="expected_from_status" x-bind:value="current">

        <p class="text-sm text-slate-600 dark:text-slate-300">
            <span class="font-semibold text-slate-900 dark:text-white" x-text="label"></span> is
            <span class="font-semibold text-slate-900 dark:text-white" x-text="labels[current] || current"></span>.
        </p>

        <template x-if="allowed.length === 0">
            <p class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600 ring-1 ring-inset ring-slate-200 dark:bg-slate-800/60 dark:text-slate-300 dark:ring-slate-700">
                No move is allowed from this status.
            </p>
        </template>

        <div x-show="allowed.length > 0">
            <label for="lead-status-to" class="{{ $labelClass }}">Move to <span class="text-rose-500">*</span></label>
            <select id="lead-status-to" name="to_status" x-model="to" required class="{{ $fieldClass }}">
                <template x-for="status in allowed" :key="status">
                    <option x-bind:value="status" x-bind:selected="status === to" x-text="labels[status] || status"></option>
                </template>
            </select>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Only the moves the pipeline allows from the current status are listed.</p>
        </div>

        <div x-show="to === 'lost'" x-cloak class="space-y-2">
            <label for="lead-status-lost" class="{{ $labelClass }}">Why was it lost? <span class="text-rose-500">*</span></label>
            <select id="lead-status-lost" x-model="lostChoice" class="{{ $fieldClass }}" x-bind:required="to === 'lost'">
                <option value="">Choose a reason</option>
                @foreach ($dialogLostReasons as $reason)
                    <option value="{{ $reason }}">{{ $reason }}</option>
                @endforeach
                <option value="__other">Something else…</option>
            </select>
            <input type="text" x-show="lostChoice === '__other'" x-model="lostOther" maxlength="255" placeholder="Type the reason" aria-label="Lost reason" class="{{ $fieldClass }}">
            <input type="hidden" name="lost_reason" x-bind:value="to === 'lost' ? lostReason : ''">
        </div>

        <div x-show="current === 'won' || current === 'lost'" x-cloak>
            <label for="lead-status-reason" class="{{ $labelClass }}">Reason for reopening <span class="text-rose-500">*</span></label>
            <input id="lead-status-reason" type="text" name="reason" maxlength="255" x-bind:required="current === 'won' || current === 'lost'" class="{{ $fieldClass }}">
        </div>

        <fieldset x-show="needsFollowUp" x-cloak class="space-y-3 rounded-lg border border-amber-200 bg-amber-50/60 p-3 dark:border-amber-500/30 dark:bg-amber-500/10">
            <legend class="px-1 text-xs font-semibold text-amber-800 dark:text-amber-200">A follow-up is required for this status</legend>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label for="lead-status-fu-type" class="{{ $labelClass }}">Type</label>
                    <select id="lead-status-fu-type" name="follow_up[type]" x-bind:disabled="! needsFollowUp" class="{{ $fieldClass }}">
                        @foreach ($dialogFollowUpTypes as $typeValue => $typeLabel)
                            <option value="{{ $typeValue }}">{{ $typeLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="lead-status-fu-at" class="{{ $labelClass }}">Due</label>
                    <input id="lead-status-fu-at" type="datetime-local" name="follow_up[scheduled_at]" value="{{ $dialogDefaultAt }}" x-bind:disabled="! needsFollowUp" x-bind:required="needsFollowUp" class="{{ $fieldClass }}">
                </div>
            </div>
            <div>
                <label for="lead-status-fu-notes" class="{{ $labelClass }}">What to say or send</label>
                <input id="lead-status-fu-notes" type="text" name="follow_up[notes]" maxlength="255" x-bind:disabled="! needsFollowUp" class="{{ $fieldClass }}">
            </div>
        </fieldset>
    </form>
    <x-slot:footer>
        <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'lead-status')">Cancel</x-ui.button>
        <x-ui.button type="submit" form="lead-status-form" icon="check">Save status</x-ui.button>
    </x-slot:footer>
</x-ui.modal>

{{-- Assign -------------------------------------------------------------------------------------------------- --}}
<x-ui.modal name="lead-assign" title="Assign lead" icon="user-plus" size="sm">
    <form
        id="lead-assign-form"
        method="POST"
        x-data="{ url: '', label: '' }"
        x-on:open-modal.window="if ($event.detail?.name === 'lead-assign') { url = $event.detail.url; label = $event.detail.label; $nextTick(() => { $refs.assignee.value = String($event.detail.current ?? ''); }); }"
        x-bind:action="url"
        class="space-y-3"
    >
        @csrf
        @method('PATCH')
        <p class="text-sm text-slate-600 dark:text-slate-300">Who owns <span class="font-semibold text-slate-900 dark:text-white" x-text="label"></span>? The new owner is notified; the previous one is not.</p>
        <div>
            <label for="lead-assign-user" class="{{ $labelClass }}">Owner</label>
            <select id="lead-assign-user" x-ref="assignee" name="assigned_to" class="{{ $fieldClass }}">
                <option value="">Nobody (unassigned)</option>
                @foreach ($dialogAssignees as $assigneeId => $assigneeName)
                    <option value="{{ $assigneeId }}">{{ $assigneeName }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="lead-assign-reason" class="{{ $labelClass }}">Reason <span class="font-normal text-slate-400">(optional, kept on the timeline)</span></label>
            <input id="lead-assign-reason" type="text" name="reason" maxlength="255" class="{{ $fieldClass }}">
        </div>
    </form>
    <x-slot:footer>
        <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'lead-assign')">Cancel</x-ui.button>
        <x-ui.button type="submit" form="lead-assign-form" icon="check">Assign</x-ui.button>
    </x-slot:footer>
</x-ui.modal>

{{-- Log / edit activity ------------------------------------------------------------------------------------- --}}
<x-ui.modal name="lead-activity" title="Log activity" icon="chat-bubble-left-right">
    <form
        id="lead-activity-form"
        method="POST"
        x-data="{
            url: '', label: '', method: 'POST', type: 'note', editing: false,
            get withOutcome() { return ['call', 'whatsapp', 'email', 'meeting'].includes(this.type) },
            get withDuration() { return ['call', 'meeting'].includes(this.type) },
        }"
        x-on:open-modal.window="if ($event.detail?.name === 'lead-activity') {
            const activity = $event.detail.activity || {};
            url = $event.detail.url; label = $event.detail.label; method = $event.detail.method || 'POST'; editing = method !== 'POST';
            type = activity.type || 'note';
            $nextTick(() => {
                $refs.subject.value = activity.subject || '';
                $refs.body.value = activity.body || '';
                $refs.outcome.value = activity.outcome || '';
                $refs.duration.value = activity.duration_minutes ?? '';
                $refs.occurred.value = activity.occurred_at || {{ \Illuminate\Support\Js::from($dialogNow) }};
            });
        }"
        x-bind:action="url"
        class="space-y-3"
    >
        @csrf
        <input type="hidden" name="_method" x-bind:value="method">
        <p class="text-sm text-slate-600 dark:text-slate-300">
            <span x-text="editing ? 'Editing a note on' : 'On'"></span>
            <span class="font-semibold text-slate-900 dark:text-white" x-text="label"></span>
        </p>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <label for="lead-activity-type" class="{{ $labelClass }}">Type <span class="text-rose-500">*</span></label>
                <select id="lead-activity-type" name="type" x-model="type" required class="{{ $fieldClass }}">
                    @foreach ($dialogActivityTypes as $typeValue => $typeLabel)
                        <option value="{{ $typeValue }}">{{ $typeLabel }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="lead-activity-at" class="{{ $labelClass }}">When</label>
                <input id="lead-activity-at" x-ref="occurred" type="datetime-local" name="occurred_at" value="{{ $dialogNow }}" max="{{ $dialogNow }}" class="{{ $fieldClass }}">
            </div>
        </div>
        <div>
            <label for="lead-activity-subject" class="{{ $labelClass }}">Headline</label>
            <input id="lead-activity-subject" x-ref="subject" type="text" name="subject" maxlength="150" placeholder="e.g. Discussed the quotation" class="{{ $fieldClass }}">
        </div>
        <div>
            <label for="lead-activity-body" class="{{ $labelClass }}">Note</label>
            <textarea id="lead-activity-body" x-ref="body" name="body" rows="4" maxlength="5000" class="{{ $fieldClass }}"></textarea>
        </div>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2" x-show="withOutcome || withDuration" x-cloak>
            <div x-show="withOutcome">
                <label for="lead-activity-outcome" class="{{ $labelClass }}">Outcome</label>
                <select id="lead-activity-outcome" x-ref="outcome" name="outcome" x-bind:disabled="! withOutcome" class="{{ $fieldClass }}">
                    <option value="">—</option>
                    @foreach ($dialogOutcomes as $outcomeValue => $outcomeLabel)
                        <option value="{{ $outcomeValue }}">{{ $outcomeLabel }}</option>
                    @endforeach
                </select>
            </div>
            <div x-show="withDuration">
                <label for="lead-activity-duration" class="{{ $labelClass }}">Duration (minutes)</label>
                <input id="lead-activity-duration" x-ref="duration" type="number" name="duration_minutes" min="0" max="1440" step="1" inputmode="numeric" x-bind:disabled="! withDuration" class="{{ $fieldClass }}">
            </div>
        </div>
    </form>
    <x-slot:footer>
        <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'lead-activity')">Cancel</x-ui.button>
        <x-ui.button type="submit" form="lead-activity-form" icon="check">Save activity</x-ui.button>
    </x-slot:footer>
</x-ui.modal>

{{-- Schedule follow-up -------------------------------------------------------------------------------------- --}}
<x-ui.modal name="lead-follow-up" title="Schedule follow-up" icon="calendar-days">
    <form
        id="lead-follow-up-form"
        method="POST"
        x-data="{ url: '', label: '' }"
        x-on:open-modal.window="if ($event.detail?.name === 'lead-follow-up') { url = $event.detail.url; label = $event.detail.label; $nextTick(() => { $refs.assignee.value = String($event.detail.assignee ?? ''); }); }"
        x-bind:action="url"
        class="space-y-3"
    >
        @csrf
        <p class="text-sm text-slate-600 dark:text-slate-300">
            For <span class="font-semibold text-slate-900 dark:text-white" x-text="label"></span>. A lead has at most one open follow-up; complete or reschedule the current one first.
        </p>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <label for="lead-follow-up-type" class="{{ $labelClass }}">Type <span class="text-rose-500">*</span></label>
                <select id="lead-follow-up-type" name="type" required class="{{ $fieldClass }}">
                    @foreach ($dialogFollowUpTypes as $typeValue => $typeLabel)
                        <option value="{{ $typeValue }}">{{ $typeLabel }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="lead-follow-up-at" class="{{ $labelClass }}">Due <span class="text-rose-500">*</span></label>
                <input id="lead-follow-up-at" type="datetime-local" name="scheduled_at" value="{{ $dialogDefaultAt }}" required class="{{ $fieldClass }}">
            </div>
            <div>
                <label for="lead-follow-up-remind" class="{{ $labelClass }}">Remind me before (minutes)</label>
                <input id="lead-follow-up-remind" type="number" name="remind_before_minutes" min="0" max="10080" step="5" inputmode="numeric" @if ($dialogReminder !== null) value="{{ $dialogReminder }}" @endif class="{{ $fieldClass }}">
            </div>
            <div>
                <label for="lead-follow-up-assignee" class="{{ $labelClass }}">Who follows up</label>
                <select id="lead-follow-up-assignee" x-ref="assignee" name="assigned_to" class="{{ $fieldClass }}">
                    <option value="">The lead's owner</option>
                    @foreach ($dialogAssignees as $assigneeId => $assigneeName)
                        <option value="{{ $assigneeId }}">{{ $assigneeName }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div>
            <label for="lead-follow-up-notes" class="{{ $labelClass }}">What to say or send</label>
            <input id="lead-follow-up-notes" type="text" name="notes" maxlength="255" class="{{ $fieldClass }}">
        </div>
    </form>
    <x-slot:footer>
        <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'lead-follow-up')">Cancel</x-ui.button>
        <x-ui.button type="submit" form="lead-follow-up-form" icon="calendar-days">Schedule</x-ui.button>
    </x-slot:footer>
</x-ui.modal>

{{-- Complete follow-up -------------------------------------------------------------------------------------- --}}
<x-ui.modal name="lead-follow-up-complete" title="Complete follow-up" icon="check-circle">
    <form
        id="lead-follow-up-complete-form"
        method="POST"
        x-data="{ url: '', label: '', next: false }"
        x-on:open-modal.window="if ($event.detail?.name === 'lead-follow-up-complete') { url = $event.detail.url; label = $event.detail.label; next = false; }"
        x-bind:action="url"
        class="space-y-3"
    >
        @csrf
        @method('PATCH')
        <p class="text-sm text-slate-600 dark:text-slate-300">How did the follow-up with <span class="font-semibold text-slate-900 dark:text-white" x-text="label"></span> go?</p>
        <div>
            <label for="lead-follow-up-outcome" class="{{ $labelClass }}">Outcome <span class="text-rose-500">*</span></label>
            <select id="lead-follow-up-outcome" name="outcome" required class="{{ $fieldClass }}">
                <option value="">Choose the outcome</option>
                @foreach ($dialogOutcomes as $outcomeValue => $outcomeLabel)
                    <option value="{{ $outcomeValue }}">{{ $outcomeLabel }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="lead-follow-up-outcome-note" class="{{ $labelClass }}">Note</label>
            <input id="lead-follow-up-outcome-note" type="text" name="outcome_note" maxlength="255" class="{{ $fieldClass }}">
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
            <input type="checkbox" name="schedule_next" value="1" x-model="next" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800">
            Schedule the next follow-up now
        </label>
        <fieldset x-show="next" x-cloak class="grid grid-cols-1 gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-2 dark:border-slate-700">
            <legend class="px-1 text-xs font-semibold text-slate-600 dark:text-slate-300">Next follow-up</legend>
            <div>
                <label for="lead-follow-up-next-type" class="{{ $labelClass }}">Type</label>
                <select id="lead-follow-up-next-type" name="next[type]" x-bind:disabled="! next" class="{{ $fieldClass }}">
                    @foreach ($dialogFollowUpTypes as $typeValue => $typeLabel)
                        <option value="{{ $typeValue }}">{{ $typeLabel }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="lead-follow-up-next-at" class="{{ $labelClass }}">Due</label>
                <input id="lead-follow-up-next-at" type="datetime-local" name="next[scheduled_at]" value="{{ $dialogDefaultAt }}" x-bind:disabled="! next" x-bind:required="next" class="{{ $fieldClass }}">
            </div>
            <div class="sm:col-span-2">
                <label for="lead-follow-up-next-notes" class="{{ $labelClass }}">What to say or send</label>
                <input id="lead-follow-up-next-notes" type="text" name="next[notes]" maxlength="255" x-bind:disabled="! next" class="{{ $fieldClass }}">
            </div>
        </fieldset>
    </form>
    <x-slot:footer>
        <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'lead-follow-up-complete')">Cancel</x-ui.button>
        <x-ui.button type="submit" form="lead-follow-up-complete-form" variant="success" icon="check">Complete</x-ui.button>
    </x-slot:footer>
</x-ui.modal>

{{-- Reschedule follow-up ------------------------------------------------------------------------------------ --}}
<x-ui.modal name="lead-follow-up-reschedule" title="Reschedule follow-up" icon="clock" size="sm">
    <form
        id="lead-follow-up-reschedule-form"
        method="POST"
        x-data="{ url: '', label: '' }"
        x-on:open-modal.window="if ($event.detail?.name === 'lead-follow-up-reschedule') { url = $event.detail.url; label = $event.detail.label; $nextTick(() => { $refs.at.value = $event.detail.scheduledAt || {{ \Illuminate\Support\Js::from($dialogDefaultAt) }}; }); }"
        x-bind:action="url"
        class="space-y-3"
    >
        @csrf
        @method('PATCH')
        <p class="text-sm text-slate-600 dark:text-slate-300">The current follow-up for <span class="font-semibold text-slate-900 dark:text-white" x-text="label"></span> is kept in the history as rescheduled.</p>
        <div>
            <label for="lead-follow-up-reschedule-at" class="{{ $labelClass }}">New time <span class="text-rose-500">*</span></label>
            <input id="lead-follow-up-reschedule-at" x-ref="at" type="datetime-local" name="scheduled_at" required class="{{ $fieldClass }}">
        </div>
        <div>
            <label for="lead-follow-up-reschedule-reason" class="{{ $labelClass }}">Reason <span class="text-rose-500">*</span></label>
            <input id="lead-follow-up-reschedule-reason" type="text" name="reason" maxlength="255" required class="{{ $fieldClass }}">
        </div>
    </form>
    <x-slot:footer>
        <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'lead-follow-up-reschedule')">Cancel</x-ui.button>
        <x-ui.button type="submit" form="lead-follow-up-reschedule-form" icon="clock">Reschedule</x-ui.button>
    </x-slot:footer>
</x-ui.modal>

{{-- Cancel follow-up ---------------------------------------------------------------------------------------- --}}
<x-ui.modal name="lead-follow-up-cancel" title="Cancel follow-up?" icon="x-circle" size="sm">
    <form
        id="lead-follow-up-cancel-form"
        method="POST"
        x-data="{ url: '', label: '' }"
        x-on:open-modal.window="if ($event.detail?.name === 'lead-follow-up-cancel') { url = $event.detail.url; label = $event.detail.label; }"
        x-bind:action="url"
        class="space-y-3"
    >
        @csrf
        @method('PATCH')
        <p class="text-sm text-slate-600 dark:text-slate-300">
            The open follow-up for <span class="font-semibold text-slate-900 dark:text-white" x-text="label"></span> is cancelled and stays in the history. Nothing is scheduled in its place.
        </p>
        <div>
            <label for="lead-follow-up-cancel-reason" class="{{ $labelClass }}">Reason <span class="text-rose-500">*</span></label>
            <input id="lead-follow-up-cancel-reason" type="text" name="reason" maxlength="255" required class="{{ $fieldClass }}">
        </div>
    </form>
    <x-slot:footer>
        <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'lead-follow-up-cancel')">Keep it</x-ui.button>
        <x-ui.button type="submit" form="lead-follow-up-cancel-form" variant="danger" icon="x-mark">Cancel follow-up</x-ui.button>
    </x-slot:footer>
</x-ui.modal>

{{-- Bulk assign --------------------------------------------------------------------------------------------- --}}
@if (\Illuminate\Support\Facades\Route::has('admin.leads.bulk.assign') && $user?->can('leads.assign'))
    <x-ui.modal name="lead-bulk-assign" title="Assign selected leads" icon="user-plus" size="sm">
        <form
            id="lead-bulk-assign-form"
            method="POST"
            action="{{ route('admin.leads.bulk.assign') }}"
            x-data="{ ids: [] }"
            x-on:open-modal.window="if ($event.detail?.name === 'lead-bulk-assign') { ids = ($event.detail.ids || []).map(String); }"
            class="space-y-3"
        >
            @csrf
            <template x-for="id in ids" :key="id">
                <input type="hidden" name="ids[]" x-bind:value="id">
            </template>
            <p class="text-sm text-slate-600 dark:text-slate-300">
                <span class="font-semibold tabular-nums text-slate-900 dark:text-white" x-text="ids.length"></span> leads selected. Leads you may not see are skipped and reported.
            </p>
            <div>
                <label for="lead-bulk-assign-user" class="{{ $labelClass }}">Owner</label>
                <select id="lead-bulk-assign-user" name="assigned_to" class="{{ $fieldClass }}">
                    <option value="">Nobody (unassigned)</option>
                    @foreach ($dialogAssignees as $assigneeId => $assigneeName)
                        <option value="{{ $assigneeId }}">{{ $assigneeName }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="lead-bulk-assign-reason" class="{{ $labelClass }}">Reason <span class="font-normal text-slate-400">(optional)</span></label>
                <input id="lead-bulk-assign-reason" type="text" name="reason" maxlength="255" class="{{ $fieldClass }}">
            </div>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'lead-bulk-assign')">Cancel</x-ui.button>
            <x-ui.button type="submit" form="lead-bulk-assign-form" icon="check">Assign</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
@endif

{{-- Bulk status --------------------------------------------------------------------------------------------- --}}
@if (\Illuminate\Support\Facades\Route::has('admin.leads.bulk.status') && $user?->can('leads.change_status'))
    <x-ui.modal name="lead-bulk-status" title="Change status of selected leads" icon="arrow-path">
        <form
            id="lead-bulk-status-form"
            method="POST"
            action="{{ route('admin.leads.bulk.status') }}"
            x-data="{
                ids: [], statuses: {}, to: '', lostChoice: '', lostOther: '',
                labels: {{ \Illuminate\Support\Js::from($dialogStatusLabels) }},
                transitions: {{ \Illuminate\Support\Js::from($dialogTransitions) }},
                get legal() { return this.ids.filter((id) => (this.transitions[this.statuses[id]] || []).includes(this.to)).length },
                get reopening() { return this.ids.some((id) => ['won', 'lost'].includes(this.statuses[id]) && (this.transitions[this.statuses[id]] || []).includes(this.to)) },
                get lostReason() { return this.lostChoice === '__other' ? this.lostOther : this.lostChoice },
            }"
            x-on:open-modal.window="if ($event.detail?.name === 'lead-bulk-status') { ids = ($event.detail.ids || []).map(String); statuses = $event.detail.statuses || {}; to = ''; lostChoice = ''; lostOther = ''; }"
            class="space-y-4"
        >
            @csrf
            <template x-for="id in ids" :key="id">
                <input type="hidden" name="ids[]" x-bind:value="id">
            </template>
            <div>
                <label for="lead-bulk-status-to" class="{{ $labelClass }}">Move to <span class="text-rose-500">*</span></label>
                <select id="lead-bulk-status-to" name="to_status" x-model="to" required class="{{ $fieldClass }}">
                    <option value="">Choose a status</option>
                    @foreach ($dialogStatusLabels as $statusValue => $statusLabel)
                        <option value="{{ $statusValue }}">{{ $statusLabel }}</option>
                    @endforeach
                </select>
            </div>

            <p x-show="to !== ''" x-cloak class="rounded-lg px-3 py-2 text-sm ring-1 ring-inset" x-bind:class="legal === ids.length ? 'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-200 dark:ring-emerald-500/25' : 'bg-amber-50 text-amber-900 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25'">
                This move is allowed for <span class="font-semibold tabular-nums" x-text="legal"></span> of
                <span class="font-semibold tabular-nums" x-text="ids.length"></span> selected leads.
                <span x-show="legal < ids.length">The others are skipped and reported, never changed.</span>
            </p>

            <div x-show="to === 'lost'" x-cloak class="space-y-2">
                <label for="lead-bulk-status-lost" class="{{ $labelClass }}">Why were they lost? <span class="text-rose-500">*</span></label>
                <select id="lead-bulk-status-lost" x-model="lostChoice" class="{{ $fieldClass }}">
                    <option value="">Choose a reason</option>
                    @foreach ($dialogLostReasons as $reason)
                        <option value="{{ $reason }}">{{ $reason }}</option>
                    @endforeach
                    <option value="__other">Something else…</option>
                </select>
                <input type="text" x-show="lostChoice === '__other'" x-model="lostOther" maxlength="255" placeholder="Type the reason" aria-label="Lost reason" class="{{ $fieldClass }}">
                <input type="hidden" name="lost_reason" x-bind:value="to === 'lost' ? lostReason : ''">
            </div>

            <div x-show="reopening" x-cloak>
                <label for="lead-bulk-status-reason" class="{{ $labelClass }}">Reason for reopening <span class="text-rose-500">*</span></label>
                <input id="lead-bulk-status-reason" type="text" name="reason" maxlength="255" class="{{ $fieldClass }}">
            </div>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'lead-bulk-status')">Cancel</x-ui.button>
            <x-ui.button type="submit" form="lead-bulk-status-form" icon="check">Apply to allowed leads</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
@endif

{{-- Bulk delete --------------------------------------------------------------------------------------------- --}}
@if (\Illuminate\Support\Facades\Route::has('admin.leads.bulk.destroy') && $user?->can('leads.delete'))
    <x-ui.modal name="lead-bulk-delete" title="Delete selected leads?" icon="trash" size="sm">
        <form
            id="lead-bulk-delete-form"
            method="POST"
            action="{{ route('admin.leads.bulk.destroy') }}"
            x-data="{ ids: [] }"
            x-on:open-modal.window="if ($event.detail?.name === 'lead-bulk-delete') { ids = ($event.detail.ids || []).map(String); }"
            class="space-y-3"
        >
            @csrf
            <template x-for="id in ids" :key="id">
                <input type="hidden" name="ids[]" x-bind:value="id">
            </template>
            <p class="text-sm text-slate-600 dark:text-slate-300">
                <span class="font-semibold tabular-nums text-slate-900 dark:text-white" x-text="ids.length"></span> leads move to the trash and can be restored.
                A converted lead is never deleted: it is skipped and reported. Open follow-ups are cancelled.
            </p>
            <div>
                <label for="lead-bulk-delete-reason" class="{{ $labelClass }}">Reason <span class="text-rose-500">*</span></label>
                <input id="lead-bulk-delete-reason" type="text" name="reason" required maxlength="255" class="{{ $fieldClass }}">
            </div>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'lead-bulk-delete')">Cancel</x-ui.button>
            <x-ui.button type="submit" form="lead-bulk-delete-form" variant="danger" icon="trash">Delete leads</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
@endif
