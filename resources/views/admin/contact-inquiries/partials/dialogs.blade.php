{{--
    The shared dialogs of the inquiry queue (phase-04 §8.10): change status, assign, mark as spam. Rendered ONCE per
    page; each opener dispatches its row's data:

        $dispatch('open-modal', { name: 'inquiry-status', url, label, current })
        $dispatch('open-modal', { name: 'inquiry-assign', url, label, current })
        $dispatch('open-modal', { name: 'inquiry-spam', url, label })

    Variables:
      $statusOptions     array<string, string>   ContactInquiryStatus::options()
      $assigneeOptions   array<int, string>      users who may be assigned (they hold contact_inquiries.view)

    Posts:
      POST admin.contact-inquiries.status   {inquiry}  status, note (optional)
      POST admin.contact-inquiries.assign   {inquiry}  user_id (empty = unassign)
      POST admin.contact-inquiries.spam     {inquiry}  reason (required) — the row stays, routing stops
--}}

@php
    $statuses = $statusOptions ?? ['new' => 'New', 'read' => 'Read', 'in_progress' => 'In progress', 'responded' => 'Responded', 'closed' => 'Closed'];
@endphp

<x-ui.modal name="inquiry-status" title="Change status" icon="arrow-path" size="sm">
    <form
        id="inquiry-status-form"
        method="POST"
        x-data="{ url: '', label: '' }"
        x-on:open-modal.window="if ($event.detail?.name === 'inquiry-status') { url = $event.detail.url; label = $event.detail.label; $nextTick(() => { $refs.status.value = $event.detail.current || 'read'; }); }"
        x-bind:action="url"
        class="space-y-3"
    >
        @csrf
        <p class="text-sm text-slate-600 dark:text-slate-300">Inquiry from <span class="font-semibold text-slate-900 dark:text-white" x-text="label"></span></p>
        <div>
            <label for="inquiry-status-select" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Status</label>
            <select id="inquiry-status-select" x-ref="status" name="status" required class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                @foreach ($statuses as $statusValue => $statusLabel)
                    <option value="{{ $statusValue }}">{{ $statusLabel }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="inquiry-status-note" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Note <span class="font-normal text-slate-400">(optional, kept in the activity log)</span></label>
            <input id="inquiry-status-note" type="text" name="note" maxlength="255" class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
        </div>
    </form>
    <x-slot:footer>
        <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'inquiry-status')">Cancel</x-ui.button>
        <x-ui.button type="submit" form="inquiry-status-form" icon="check">Save status</x-ui.button>
    </x-slot:footer>
</x-ui.modal>

<x-ui.modal name="inquiry-assign" title="Assign this inquiry" icon="user-plus" size="sm">
    <form
        id="inquiry-assign-form"
        method="POST"
        x-data="{ url: '', label: '' }"
        x-on:open-modal.window="if ($event.detail?.name === 'inquiry-assign') { url = $event.detail.url; label = $event.detail.label; $nextTick(() => { $refs.assignee.value = String($event.detail.current ?? ''); }); }"
        x-bind:action="url"
        class="space-y-3"
    >
        @csrf
        <p class="text-sm text-slate-600 dark:text-slate-300">Who handles the inquiry from <span class="font-semibold text-slate-900 dark:text-white" x-text="label"></span>? Staff without the full queue see only what is assigned to them.</p>
        <div>
            <label for="inquiry-assign-user" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Assignee</label>
            <select id="inquiry-assign-user" x-ref="assignee" name="user_id" class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                <option value="">Nobody (unassigned)</option>
                @foreach ((array) ($assigneeOptions ?? []) as $assigneeId => $assigneeName)
                    <option value="{{ $assigneeId }}">{{ $assigneeName }}</option>
                @endforeach
            </select>
        </div>
    </form>
    <x-slot:footer>
        <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'inquiry-assign')">Cancel</x-ui.button>
        <x-ui.button type="submit" form="inquiry-assign-form" icon="check">Assign</x-ui.button>
    </x-slot:footer>
</x-ui.modal>

<x-ui.modal name="inquiry-spam" title="Mark as spam?" icon="exclamation-triangle" size="sm">
    <form
        id="inquiry-spam-form"
        method="POST"
        x-data="{ url: '', label: '' }"
        x-on:open-modal.window="if ($event.detail?.name === 'inquiry-spam') { url = $event.detail.url; label = $event.detail.label; }"
        x-bind:action="url"
        class="space-y-3"
    >
        @csrf
        <p class="text-sm text-slate-600 dark:text-slate-300">
            The inquiry from <span class="font-semibold text-slate-900 dark:text-white" x-text="label"></span> moves to the Spam tab and is never routed.
            Nothing is deleted, and a lead or course inquiry already created from it is kept.
        </p>
        <div>
            <label for="inquiry-spam-reason" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Reason <span class="text-rose-500">*</span></label>
            <input id="inquiry-spam-reason" type="text" name="reason" required maxlength="100" placeholder="e.g. advertising, gibberish" class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-rose-500 focus:ring-rose-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
        </div>
    </form>
    <x-slot:footer>
        <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'inquiry-spam')">Cancel</x-ui.button>
        <x-ui.button type="submit" form="inquiry-spam-form" variant="danger" icon="exclamation-triangle">Mark as spam</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
