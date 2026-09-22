@extends('layouts.panel')

@section('title', $charge->fee_number)

@section('header')
    <x-ui.page-header :title="$charge->fee_number"
                      :subtitle="($charge->title ?? $charge->fee_type->label()).($charge->course?->name ? ' · '.$charge->course->name : '')"
                      icon="banknotes"
                      :back="route('student.fees.index')">
        <x-slot:actions>
            <x-ui.badge :color="$charge->status->color()">{{ $charge->status->label() }}</x-ui.badge>
            <x-ui.button variant="ghost" icon="printer" :href="route('student.fees.slip', $charge)">Fee slip</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <x-ui.stat-card label="Amount" :value="money($charge->net_amount)" icon="document-text" color="slate" />
        <x-ui.stat-card label="Paid" :value="money($charge->paid_amount)" icon="check-circle" color="emerald" />
        <x-ui.stat-card :label="(float) $charge->balance_amount < 0 ? 'In advance' : 'Balance'"
                        :value="money(\App\Support\Money::abs($charge->balance_amount))"
                        :icon="(float) $charge->balance_amount < 0 ? 'arrow-trending-up' : 'clock'"
                        :color="(float) $charge->balance_amount > 0 ? 'amber' : 'emerald'" />
    </div>

    @if ($installments->isNotEmpty())
        <x-ui.card :padded="false" title="Your installment schedule" class="mb-4">
            <x-ui.table>
                <x-slot:head>
                    <th class="px-4 py-3 text-left font-semibold">#</th>
                    <th class="px-4 py-3 text-left font-semibold">Due</th>
                    <th class="px-4 py-3 text-right font-semibold">Amount</th>
                    <th class="px-4 py-3 text-right font-semibold">Paid</th>
                    <th class="px-4 py-3 text-left font-semibold">Status</th>
                </x-slot:head>

                @foreach ($installments as $line)
                    <tr @class(['opacity-55' => $line->status === \App\Enums\InstallmentStatus::Cancelled])>
                        <td class="px-4 py-3 font-medium text-slate-700 dark:text-slate-200">{{ app_number($line->installment_no) }}</td>
                        <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ app_date($line->due_date) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-slate-700 dark:text-slate-200">{{ money($line->amount) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money($line->paid_amount) }}</td>
                        <td class="px-4 py-3"><x-ui.badge :color="$line->status->color()" size="xs">{{ $line->status->label() }}</x-ui.badge></td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    <x-ui.card :padded="false" title="Payments" class="mb-4">
        <x-ui.table :is-empty="$payments->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Receipt</th>
                <th class="px-4 py-3 text-left font-semibold">Paid on</th>
                <th class="px-4 py-3 text-left font-semibold">Method</th>
                <th class="px-4 py-3 text-right font-semibold">Amount</th>
                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($payments as $payment)
                <tr>
                    <td class="px-4 py-3 font-medium text-slate-700 dark:text-slate-200">{{ $payment->receipt_no }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ app_date($payment->paid_on) }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $payment->payment_method->label() }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-700 dark:text-slate-200">
                        {{ money($payment->net_received_amount) }}
                        @if ((float) $payment->refunded_amount > 0)
                            <div class="text-xs font-normal text-slate-400">{{ money($payment->refunded_amount) }} refunded</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        <x-ui.icon-button icon="printer" label="Receipt" :href="route('student.payments.receipt', $payment)" />
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="banknotes" title="Nothing paid yet"
                                  description="Receipts appear here as soon as a payment is recorded at the office." />
            </x-slot:empty>
        </x-ui.table>
    </x-ui.card>

    {{-- **The student sees why their fee changed.** §74 gives them their own fees, and a reduction
         they cannot account for is worse than no reduction at all. It is the collaborator's earnings
         that stay private, not the student's own bill. --}}
    @if ($discounts->isNotEmpty())
        <x-ui.card :padded="false" title="Reductions on this fee"
                   subtitle="Every discount, scholarship and waiver applied to this charge, and why.">
            <x-ui.table>
                <x-slot:head>
                    <th class="px-4 py-3 text-left font-semibold">Type</th>
                    <th class="px-4 py-3 text-right font-semibold">Amount</th>
                    <th class="px-4 py-3 text-left font-semibold">Reason</th>
                    <th class="px-4 py-3 text-left font-semibold">From</th>
                </x-slot:head>

                @foreach ($discounts as $discount)
                    <tr>
                        <td class="px-4 py-3"><x-ui.badge :color="$discount->type->color()" size="xs">{{ $discount->type->label() }}</x-ui.badge></td>
                        <td class="px-4 py-3 text-right tabular-nums {{ $discount->isReduction() ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-500' }}">
                            {{ $discount->isReduction() ? '−' : '+' }}{{ money($discount->magnitude()) }}
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $discount->reason }}</td>
                        <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ app_date($discount->effective_on) }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif
@endsection
