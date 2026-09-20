@extends('layouts.admin')

@section('title', 'Receipt ' . $payment->receipt_no)

@php
    $canRefund = auth()->user()?->can('payment_reversals.create');
    $canVoid = auth()->user()?->can('student_fee_payments.change_status');
    $remaining = $payment->refundableRemaining();
    $open = $payment->status === \App\Enums\ReceivedPaymentStatus::Cleared
        || $payment->status === \App\Enums\ReceivedPaymentStatus::PartiallyRefunded;
@endphp

@section('header')
    <x-ui.page-header :title="'Receipt ' . $payment->receipt_no"
                      :subtitle="money($payment->amount) . ' · ' . app_date($payment->paid_on)"
                      icon="receipt-percent">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.fee-payments.index')" icon="arrow-left">Register</x-ui.button>

            @can('student_fee_payments.print')
                <x-ui.button variant="secondary" :href="route('admin.fee-payments.receipt', $payment)" icon="printer">Print</x-ui.button>
            @endcan

            @if ($open && $canRefund && bccomp($remaining, '0.00', 2) === 1)
                <x-ui.button variant="secondary" icon="arrow-uturn-left"
                             x-on:click="$dispatch('open-modal', 'refund-payment')">Refund</x-ui.button>
            @endif

            @if ($open && $canVoid)
                <x-ui.button variant="danger" icon="x-mark"
                             x-on:click="$dispatch('open-modal', 'void-payment')">Void</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-4">
        <x-ui.stat-card label="Received" :value="money($payment->amount)" icon="banknotes" />
        <x-ui.stat-card label="Refunded" :value="money($payment->refunded_amount)"
                        :color="bccomp((string) $payment->refunded_amount, '0.00', 2) === 1 ? 'rose' : 'slate'" icon="arrow-uturn-left" />
        <x-ui.stat-card label="Net received" :value="money($payment->net_received_amount)" icon="calculator" />
        <x-ui.stat-card label="Status" :value="$payment->status->label()" :color="$payment->status->color()" icon="flag" />
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <x-ui.card title="The receipt">
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-slate-500">Charge</dt>
                    <dd class="font-mono text-slate-900 dark:text-white">{{ $payment->fee?->fee_number }}</dd></div>
                @if ($payment->installment)
                    <div class="flex justify-between"><dt class="text-slate-500">Installment</dt>
                        <dd>#{{ $payment->installment->installment_no }} of {{ money($payment->installment->amount) }}</dd></div>
                @endif
                <div class="flex justify-between"><dt class="text-slate-500">Method</dt>
                    <dd>{{ $payment->payment_method->label() }}</dd></div>
                @if ($payment->reference_no)
                    <div class="flex justify-between"><dt class="text-slate-500">Reference</dt>
                        <dd class="font-mono text-xs">{{ $payment->reference_no }}</dd></div>
                @endif
                {{-- Both dates, always. A back-dated receipt selects a different rule and possibly a
                     different partner, so which day it belongs to has to be visible. --}}
                <div class="flex justify-between"><dt class="text-slate-500">Value date</dt>
                    <dd>{{ app_date($payment->paid_on) }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Entered</dt>
                    <dd>{{ app_datetime($payment->recorded_at) }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Cashier</dt>
                    <dd>{{ $payment->receivedBy?->name ?? $payment->received_by_name }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.card title="Commission">
            <div class="mb-3">
                <x-ui.badge :color="$payment->commission_state->color()">{{ $payment->commission_state->label() }}</x-ui.badge>
            </div>

            @if ($payment->commissionSkipSentence())
                <p class="rounded-lg bg-slate-50 p-3 text-sm text-slate-700 dark:bg-slate-800/60 dark:text-slate-200">
                    {{ $payment->commissionSkipSentence() }}
                </p>
            @endif

            @if ($payment->collaborator)
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Credited to</dt>
                        <dd class="text-slate-900 dark:text-white">{{ $payment->collaborator->displayName() }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Code</dt>
                        <dd class="font-mono text-xs">{{ $payment->collaborator->collaborator_code }}</dd></div>
                    @if ($payment->referral)
                        <div class="flex justify-between"><dt class="text-slate-500">Attribution from</dt>
                            <dd>{{ app_date($payment->referral->effective_from) }}</dd></div>
                    @endif
                </dl>
            @endif

            @if ($payment->commissionEntries->isNotEmpty())
                <x-ui.table class="mt-3">
                    <x-slot:head>
                        <th class="px-3 py-2 text-left font-semibold">Entry</th>
                        <th class="px-3 py-2 text-left font-semibold">Purpose</th>
                        <th class="px-3 py-2 text-right font-semibold">Amount</th>
                        <th class="px-3 py-2 text-left font-semibold">Status</th>
                    </x-slot:head>
                    @foreach ($payment->commissionEntries as $entry)
                        <tr>
                            <td class="px-3 py-2">
                                @can('collaborator_commissions.view')
                                    <a class="font-mono text-xs text-brand-700 dark:text-brand-300"
                                       href="{{ route('admin.commissions.show', $entry) }}">{{ $entry->reference }}</a>
                                @else
                                    <span class="font-mono text-xs">{{ $entry->reference }}</span>
                                @endcan
                            </td>
                            <td class="px-3 py-2"><x-ui.badge :color="$entry->purpose->color()" size="xs">{{ $entry->purpose->label() }}</x-ui.badge></td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ money($entry->signed_amount) }}</td>
                            <td class="px-3 py-2"><x-ui.badge :color="$entry->status->color()" size="xs">{{ $entry->status->label() }}</x-ui.badge></td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>

        @if ($payment->reversals->isNotEmpty())
            <x-ui.card title="Money returned" class="lg:col-span-2">
                <x-ui.table>
                    <x-slot:head>
                        <th class="px-4 py-2 text-left font-semibold">Reversal</th>
                        <th class="px-4 py-2 text-left font-semibold">Type</th>
                        <th class="px-4 py-2 text-right font-semibold">Amount</th>
                        <th class="px-4 py-2 text-left font-semibold">Reason</th>
                        <th class="px-4 py-2 text-left font-semibold">Approval</th>
                    </x-slot:head>
                    @foreach ($payment->reversals as $reversal)
                        <tr>
                            <td class="px-4 py-2">
                                <span class="block font-mono text-xs">{{ $reversal->reversal_no }}</span>
                                <span class="block text-xs text-slate-500">{{ app_date($reversal->occurred_on) }}</span>
                            </td>
                            <td class="px-4 py-2"><x-ui.badge :color="$reversal->type->color()" size="xs">{{ $reversal->type->label() }}</x-ui.badge></td>
                            <td class="px-4 py-2 text-right tabular-nums text-rose-600 dark:text-rose-400">{{ money($reversal->amount) }}</td>
                            <td class="px-4 py-2 text-sm text-slate-600 dark:text-slate-300">{{ $reversal->reason }}</td>
                            <td class="px-4 py-2"><x-ui.badge :color="$reversal->approval_status->color()" size="xs">{{ $reversal->approval_status->label() }}</x-ui.badge></td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @endif
    </div>

    @if ($open && $canRefund && bccomp($remaining, '0.00', 2) === 1)
        <x-ui.modal name="refund-payment" title="Return money from this receipt">
            <form method="POST" action="{{ route('admin.fee-payments.refund', $payment) }}">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::ulid() }}">

                <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">
                    <strong>{{ money($remaining) }}</strong> of this receipt is still refundable. Any
                    commission it earned is undone in proportion — the original entry stays exactly as it
                    is and a reversing row references it.
                </p>

                <x-ui.form.input name="amount" label="Amount" required :value="$remaining" />

                <x-ui.form.select name="type" label="Type" required>
                    @foreach (\App\Enums\ReversalType::cases() as $type)
                        <option value="{{ $type->value }}" @selected($type === \App\Enums\ReversalType::PartialRefund)>
                            {{ $type->label() }}
                        </option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.input name="refund_method" label="Refunded by" placeholder="cash, bank transfer, …" />
                <x-ui.form.input name="reference_no" label="Reference" placeholder="optional" />
                <x-ui.form.textarea name="reason" label="Reason" required rows="3"
                                    placeholder="Why the money is going back" />

                <div class="mt-4 flex justify-end gap-2">
                    <x-ui.button variant="ghost" type="button" x-on:click="$dispatch('close-modal', 'refund-payment')">Cancel</x-ui.button>
                    <x-ui.button variant="danger" type="submit">Record the refund</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    @if ($open && $canVoid)
        <x-ui.modal name="void-payment" title="Void this receipt">
            <form method="POST" action="{{ route('admin.fee-payments.void', $payment) }}">
                @csrf
                <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">
                    Voiding is the only correction a receipt has. The row stays and says what it always
                    said; enter the corrected receipt afresh. The trail then reads: this was taken, this
                    was voided and why, this replaced it.
                </p>
                <x-ui.form.textarea name="reason" label="Reason" required rows="3"
                                    placeholder="e.g. keyed against the wrong student" />
                <div class="mt-4 flex justify-end gap-2">
                    <x-ui.button variant="ghost" type="button" x-on:click="$dispatch('close-modal', 'void-payment')">Keep it</x-ui.button>
                    <x-ui.button variant="danger" type="submit">Void the receipt</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
@endsection
