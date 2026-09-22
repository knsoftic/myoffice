{{--
    The discounts tab (phase-18 §8.2, §6.5).

    **Append-only, and the screen looks like it.** A reversed row is struck through and links to its
    reversal rather than disappearing: the net fee on the day a payment arrived has to stay answerable,
    because a commission was computed from it and money may already have moved.

    The sign convention is carried in the data (reductions negative, a correction positive), but the
    table renders magnitudes with a direction word — "−5,000.00" in a column headed "Amount" is read
    differently by different people, and one of the readings is wrong.
--}}
<x-ui.card :padded="false" title="Discounts and scholarships"
           subtitle="Never edited, never deleted. A wrong one is undone by a reversal that points at it.">
    <x-slot:actions>
        @can('fee_discounts.create')
            @if ($charge->status !== \App\Enums\StudentFeeStatus::Cancelled)
                <x-ui.button size="sm" icon="receipt-percent" x-on:click="$dispatch('open-modal', 'add-discount')">Add discount</x-ui.button>
            @endif
        @endcan
    </x-slot:actions>

    <x-ui.table :is-empty="$discounts->isEmpty()">
        <x-slot:head>
            <th class="px-4 py-3 text-left font-semibold">Type</th>
            <th class="px-4 py-3 text-right font-semibold">Amount</th>
            <th class="px-4 py-3 text-left font-semibold">Reason</th>
            <th class="px-4 py-3 text-left font-semibold">Approved by</th>
            <th class="px-4 py-3 text-left font-semibold">Effective</th>
            <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
        </x-slot:head>

        @foreach ($discounts as $discount)
            @php($reversed = $discount->reversedBy !== null)
            <tr @class(['opacity-55' => $reversed])>
                <td class="px-4 py-3">
                    <x-ui.badge :color="$discount->type->color()" size="xs">{{ $discount->type->label() }}</x-ui.badge>
                    @if ($discount->percentage)
                        <div class="mt-0.5 text-xs text-slate-400">{{ app_number($discount->percentage, 2) }}%</div>
                    @endif
                </td>
                <td class="px-4 py-3 text-right tabular-nums {{ $reversed ? 'line-through' : '' }}">
                    <span class="{{ $discount->isReduction() ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                        {{ money($discount->magnitude()) }}
                    </span>
                    <div class="text-[10px] uppercase tracking-wide text-slate-400">
                        {{ $discount->isReduction() ? 'off the fee' : 'back on the fee' }}
                    </div>
                </td>
                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                    {{ $discount->reason }}
                    @if ($reversed)
                        <div class="mt-0.5 text-xs text-slate-400">
                            Reversed {{ $discount->reversedBy->created_at ? app_date($discount->reversedBy->created_at) : '' }} —
                            {{ $discount->reversedBy->reason }}
                        </div>
                    @endif
                </td>
                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                    {{-- The snapshot name, not the relation: the approver's user row may be deleted
                         years later, and "approved by" with nothing after it is the sentence a dispute
                         turns on. --}}
                    {{ $discount->approved_by_name ?? $discount->approver?->name ?? '—' }}
                    @if ($discount->approved_at)
                        <div class="text-xs text-slate-400">{{ app_date($discount->approved_at) }}</div>
                    @endif
                </td>
                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ app_date($discount->effective_on) }}</td>
                <td class="px-4 py-3 text-right">
                    @if (! $reversed && ! $discount->type->isUndo())
                        @can('fee_discounts.approve')
                            <x-ui.button size="sm" variant="ghost" icon="arrow-uturn-left"
                                         x-on:click="$dispatch('open-modal', 'reverse-discount-{{ $discount->id }}')">Reverse</x-ui.button>
                        @endcan
                    @endif
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state icon="receipt-percent" title="No discounts"
                              description="The student owes the full fee for this head.">
                @can('fee_discounts.create')
                    <x-ui.button icon="plus" x-on:click="$dispatch('open-modal', 'add-discount')">Add discount</x-ui.button>
                @endcan
            </x-ui.empty-state>
        </x-slot:empty>
    </x-ui.table>
</x-ui.card>

@can('fee_discounts.approve')
    @foreach ($discounts as $discount)
        @if ($discount->reversedBy === null && ! $discount->type->isUndo())
            <x-ui.modal name="reverse-discount-{{ $discount->id }}" :title="'Reverse this '.mb_strtolower($discount->type->label()).'?'" icon="arrow-uturn-left">
                <form method="POST" action="{{ route('admin.fee-discounts.reverse', $discount) }}" class="space-y-4">
                    @csrf
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        {{ money($discount->magnitude()) }} goes back onto the fee. <span class="font-medium">The
                        original row is kept for ever</span> — that is what makes this a correction rather
                        than a rewrite, and a row can only be reversed once.
                    </p>
                    <x-ui.form.textarea name="reason" label="Why?" required rows="2" />
                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'reverse-discount-{{ $discount->id }}')">Cancel</x-ui.button>
                        <x-ui.button type="submit" variant="secondary">Reverse it</x-ui.button>
                    </div>
                </form>
            </x-ui.modal>
        @endif
    @endforeach
@endcan
