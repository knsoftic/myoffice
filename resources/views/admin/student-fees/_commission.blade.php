{{--
    The commission trail (phase-18 §8.2).

    **Read-only, and it reads the ledger** — never a duplicated column on the charge. The whole tab is
    absent without `collaborator_commissions.view_financial`; it is never rendered blank, because a
    heading with nothing under it tells somebody there is something to see.

    When no row exists, the reason is in plain words rather than an enum value. "no_referral" is not an
    explanation; "this student has no collaborator, so the payment earned nobody anything" is.
--}}
<x-ui.card :padded="false" title="Commission this charge produced"
           subtitle="Read-only. Money moves through the spine's engine; this is the trail it left.">
    <x-ui.table :is-empty="$entries->isEmpty()">
        <x-slot:head>
            <th class="px-4 py-3 text-left font-semibold">Entry</th>
            <th class="px-4 py-3 text-left font-semibold">Collaborator</th>
            <th class="px-4 py-3 text-left font-semibold">Base</th>
            <th class="px-4 py-3 text-right font-semibold">Base amount</th>
            <th class="px-4 py-3 text-right font-semibold">Rate</th>
            <th class="px-4 py-3 text-right font-semibold">Amount</th>
            <th class="px-4 py-3 text-left font-semibold">Status</th>
        </x-slot:head>

        @foreach ($entries as $entry)
            <tr>
                <td class="px-4 py-3">
                    <div class="font-medium text-slate-700 dark:text-slate-200">CLE-{{ $entry->id }}</div>
                    <div class="text-xs text-slate-400">{{ app_date($entry->transaction_date) }}</div>
                </td>
                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $entry->collaborator?->name ?? '—' }}</td>
                <td class="px-4 py-3">
                    <x-ui.badge color="slate" size="xs">{{ $entry->commission_base?->label() ?? '—' }}</x-ui.badge>
                </td>
                <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money($entry->base_amount) }}</td>
                <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                    {{ $entry->commission_rate ? app_number($entry->commission_rate, 2).'%' : '—' }}
                </td>
                <td class="px-4 py-3 text-right tabular-nums font-medium
                    {{ (float) $entry->signed_amount < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                    {{ money($entry->signed_amount) }}
                </td>
                <td class="px-4 py-3"><x-ui.badge :color="$entry->status?->color() ?? 'slate'" size="xs">{{ $entry->status?->label() ?? '—' }}</x-ui.badge></td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state icon="user-group" title="This charge produced no commission"
                              :description="$charge->collaborator === null
                                  ? 'This student has no collaborator, so nothing was owed to anybody — not a zero row, no row at all.'
                                  : 'Nothing has been received against this charge yet, or the rules did not make it commissionable. The reason is on each receipt in the Payments tab.'" />
        </x-slot:empty>
    </x-ui.table>
</x-ui.card>
