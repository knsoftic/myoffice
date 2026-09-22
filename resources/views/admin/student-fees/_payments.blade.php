{{--
    The payments tab (phase-18 §8.2).

    The commission-state badge is here rather than on the commission tab because it belongs to the
    RECEIPT: "this receipt earned 1,000" and "this receipt earned nothing, because the student has no
    collaborator" are both facts about the row the cashier is looking at. The skip reason is in plain
    words on hover — a `CommissionSkipReason` enum value in a tooltip helps nobody.
--}}
<x-ui.card :padded="false" title="Money received"
           subtitle="A receipt is never edited. A mis-keyed one is voided and re-entered; money going back is a refund.">
    <x-ui.table :is-empty="$payments->isEmpty()">
        <x-slot:head>
            <th class="px-4 py-3 text-left font-semibold">Receipt</th>
            <th class="px-4 py-3 text-left font-semibold">Paid on</th>
            <th class="px-4 py-3 text-left font-semibold">Method</th>
            <th class="px-4 py-3 text-right font-semibold">Amount</th>
            <th class="px-4 py-3 text-right font-semibold">Refunded</th>
            <th class="px-4 py-3 text-right font-semibold">Net</th>
            <th class="px-4 py-3 text-left font-semibold">Commission</th>
            <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
        </x-slot:head>

        @foreach ($payments as $payment)
            <tr @class(['opacity-60' => $payment->status === \App\Enums\ReceivedPaymentStatus::Voided])>
                <td class="px-4 py-3">
                    <div class="font-medium text-slate-700 dark:text-slate-200">{{ $payment->receipt_no }}</div>
                    <div class="text-xs text-slate-400">{{ $payment->receivedBy?->name ?? $payment->received_by_name ?? '—' }}</div>
                </td>
                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                    {{ app_date($payment->paid_on) }}
                    {{-- The chip exists because a back-dated receipt earns the rate effective on its
                         VALUE date, not today's — so the gap between the two dates is economic. --}}
                    @if ($payment->recorded_at && $payment->paid_on && $payment->paid_on->toDateString() !== $payment->recorded_at->toDateString())
                        <div class="mt-0.5"><x-ui.badge color="amber" size="xs">back-dated</x-ui.badge></div>
                    @endif
                </td>
                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                    {{ $payment->payment_method->label() }}
                    @if ($payment->reference_no)
                        <div class="text-xs text-slate-400">{{ $payment->reference_no }}</div>
                    @endif
                </td>
                <td class="px-4 py-3 text-right tabular-nums text-slate-700 dark:text-slate-200">{{ money($payment->amount) }}</td>
                <td class="px-4 py-3 text-right tabular-nums text-slate-500">
                    {{ (float) $payment->refunded_amount > 0 ? money($payment->refunded_amount) : '—' }}
                </td>
                <td class="px-4 py-3 text-right tabular-nums font-medium text-slate-700 dark:text-slate-200">{{ money($payment->net_received_amount) }}</td>
                <td class="px-4 py-3">
                    <span title="{{ $payment->commissionSkipSentence() ?? '' }}">
                        <x-ui.badge :color="$payment->commission_state?->color() ?? 'slate'" size="xs">
                            {{ $payment->commission_state?->label() ?? '—' }}
                        </x-ui.badge>
                    </span>
                    @if ($payment->commissionSkipSentence())
                        <div class="mt-0.5 max-w-[16rem] truncate text-xs text-slate-400">{{ $payment->commissionSkipSentence() }}</div>
                    @endif
                </td>
                <td class="px-4 py-3 text-right">
                    <div class="flex justify-end gap-1">
                        @can('student_fee_payments.print')
                            <x-ui.icon-button icon="printer" label="Receipt" :href="route('admin.fee-payments.receipt', $payment)" />
                        @endcan
                        @if ($payment->status !== \App\Enums\ReceivedPaymentStatus::Voided)
                            @can('payment_reversals.create')
                                <x-ui.icon-button icon="arrow-uturn-left" label="Refund"
                                                  x-on:click="$dispatch('open-modal', 'refund-{{ $payment->id }}')" />
                            @endcan
                            @can('student_fee_payments.change_status')
                                <x-ui.icon-button icon="x-circle" label="Void" variant="danger"
                                                  x-on:click="$dispatch('open-modal', 'void-{{ $payment->id }}')" />
                            @endcan
                        @endif
                    </div>
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-ui.empty-state icon="banknotes" title="Nothing received yet"
                              description="Recording a payment here is the only way money reaches this charge." />
        </x-slot:empty>
    </x-ui.table>
</x-ui.card>

@foreach ($payments as $payment)
    @if ($payment->status !== \App\Enums\ReceivedPaymentStatus::Voided)
        @can('payment_reversals.create')
            <x-ui.modal name="refund-{{ $payment->id }}" :title="'Refund '.$payment->receipt_no.'?'" icon="arrow-uturn-left">
                <form method="POST" action="{{ route('admin.fee-payments.refund', $payment) }}" class="space-y-4">
                    @csrf
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        Money going back. The commission this receipt earned is reversed by the same
                        proportion, so any number of partial refunds sums to at most what was credited —
                        never a paisa more.
                    </p>
                    <x-ui.form.input name="amount" label="Amount" type="number" step="0.01" min="0.01"
                                     :max="$payment->refundableRemaining()"
                                     :value="$payment->refundableRemaining()"
                                     :help="money($payment->refundableRemaining()).' can still be returned.'" />
                    <x-ui.form.textarea name="reason" label="Why?" required rows="2" />
                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'refund-{{ $payment->id }}')">Cancel</x-ui.button>
                        <x-ui.button type="submit" variant="danger">Refund</x-ui.button>
                    </div>
                </form>
            </x-ui.modal>
        @endcan

        @can('student_fee_payments.change_status')
            <x-ui.modal name="void-{{ $payment->id }}" :title="'Void '.$payment->receipt_no.'?'" icon="x-circle">
                <form method="POST" action="{{ route('admin.fee-payments.void', $payment) }}" class="space-y-4">
                    @csrf
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        A void says this receipt never counted — it is the correction path for a
                        mis-keyed entry, not for money genuinely going back. The receipt keeps its
                        number for ever, the commission is fully reversed, and the charge returns to
                        pending rather than to refunded.
                    </p>
                    <x-ui.form.textarea name="reason" label="Why?" required rows="2" />
                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'void-{{ $payment->id }}')">Keep it</x-ui.button>
                        <x-ui.button type="submit" variant="danger">Void the receipt</x-ui.button>
                    </div>
                </form>
            </x-ui.modal>
        @endcan
    @endif
@endforeach
