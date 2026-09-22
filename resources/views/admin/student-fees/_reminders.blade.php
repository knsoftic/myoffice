{{--
    The reminders tab (phase-18 §8.2, §6.8).

    A log, and it reads like one: sent at, by whom, about which due date, and what was outstanding at
    the time. The amount is a SNAPSHOT — reproducing the message means knowing what it said, not what
    the balance happens to be now.

    "Send reminder now" twice in a morning sends once. `uq_sfr_dedupe` decides that, and the second
    press is told so rather than shown an error.
--}}
<x-ui.card :padded="false" title="Reminders sent"
           subtitle="At most one per line, per type, per day — whoever presses the button and however many times.">
    <x-slot:actions>
        @can('fee_reminders.create')
            @if ($charge->status->isOpen())
                <x-ui.button size="sm" icon="bell-alert" x-on:click="$dispatch('open-modal', 'send-reminder')">Send reminder</x-ui.button>
            @endif
        @endcan
    </x-slot:actions>

    <x-ui.table :is-empty="$reminders->isEmpty()">
        <x-slot:head>
            <th class="px-4 py-3 text-left font-semibold">Type</th>
            <th class="px-4 py-3 text-left font-semibold">About</th>
            <th class="px-4 py-3 text-right font-semibold">Owed then</th>
            <th class="px-4 py-3 text-left font-semibold">Sent</th>
            <th class="px-4 py-3 text-left font-semibold">By</th>
        </x-slot:head>

        @foreach ($reminders as $reminder)
            <tr>
                <td class="px-4 py-3"><x-ui.badge :color="$reminder->type->color()" size="xs">{{ $reminder->type->label() }}</x-ui.badge></td>
                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                    {{ $reminder->installment ? 'Installment '.app_number($reminder->installment->installment_no) : 'The charge' }}
                    <div class="text-xs text-slate-400">due {{ app_date($reminder->due_date) }} · {{ $reminder->offsetCaption() }}</div>
                </td>
                <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money($reminder->amount_due) }}</td>
                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ app_datetime($reminder->sent_at) }}</td>
                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $reminder->senderCaption() }}</td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state icon="bell-alert" title="No reminders sent"
                              description="The nightly run sends one ahead of the due date, one on the day, and a weekly chase after it." />
        </x-slot:empty>
    </x-ui.table>
</x-ui.card>

@can('fee_reminders.create')
    <x-ui.modal name="send-reminder" title="Send a reminder" icon="bell-alert">
        <form method="POST" action="{{ route('admin.fee-reminders.store', $charge) }}" class="space-y-4">
            @csrf
            <p class="text-sm text-slate-600 dark:text-slate-300">
                If this student has already been told about the same thing today, nothing is sent twice
                and you will be told so.
            </p>
            <x-ui.form.select name="type" label="Which reminder">
                @foreach (\App\Enums\FeeReminderType::cases() as $type)
                    <option value="{{ $type->value }}">{{ $type->label() }} — {{ $type->description() }}</option>
                @endforeach
            </x-ui.form.select>
            <div class="flex justify-end gap-2">
                <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'send-reminder')">Cancel</x-ui.button>
                <x-ui.button type="submit" variant="primary">Send it</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
@endcan
