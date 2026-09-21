@extends('layouts.admin')

@section('title', 'Payment ' . $payment->payment_no)

@section('header')
    <x-ui.page-header :title="'Payment ' . $payment->payment_no" subtitle="Printable copy" icon="printer">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.project-payments.show', $payment)" icon="arrow-left">Back</x-ui.button>
            <x-ui.button variant="primary" icon="printer" x-on:click="window.print()">Print</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mx-auto max-w-2xl rounded-xl border border-slate-200 bg-white p-8 print:border-0 print:p-0 dark:border-slate-700 dark:bg-slate-900">
        <div class="flex items-start justify-between border-b border-slate-200 pb-4 dark:border-slate-700">
            <div>
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ setting('company.name', config('app.name')) }}</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">Payment receipt</p>
            </div>
            <div class="text-right">
                <p class="font-mono text-sm font-semibold text-slate-900 dark:text-white">{{ $payment->payment_no }}</p>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ app_date($payment->paid_on) }}</p>
            </div>
        </div>

        @if ($payment->status !== \App\Enums\ReceivedPaymentStatus::Cleared)
            {{-- A voided or refunded payment still prints, and says so across the top: a copy that
                 looked valid would be worse than no copy at all. --}}
            <p class="mt-4 rounded-lg border border-rose-300 bg-rose-50 p-3 text-center text-sm font-semibold uppercase tracking-wide text-rose-700 dark:border-rose-700 dark:bg-rose-950/40 dark:text-rose-300">
                {{ $payment->status->label() }}
            </p>
        @endif

        <dl class="mt-6 space-y-3 text-sm">
            <div class="flex justify-between"><dt class="text-slate-500">Project</dt>
                <dd class="font-mono text-slate-900 dark:text-white">{{ $payment->project?->code }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Client</dt>
                <dd>{{ $payment->client?->name }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Method</dt>
                <dd>{{ $payment->payment_method->label() }}</dd></div>
            @if ($payment->reference_no)
                <div class="flex justify-between"><dt class="text-slate-500">Reference</dt>
                    <dd class="font-mono text-xs">{{ $payment->reference_no }}</dd></div>
            @endif
        </dl>

        <div class="mt-6 border-t border-slate-200 pt-4 dark:border-slate-700">
            <div class="flex items-baseline justify-between">
                <span class="text-sm text-slate-500 dark:text-slate-400">Amount received</span>
                <span class="text-2xl font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($payment->amount) }}</span>
            </div>

            @if (bccomp((string) $payment->refunded_amount, '0.00', 2) === 1)
                <div class="mt-2 flex items-baseline justify-between text-sm">
                    <span class="text-slate-500">Since refunded</span>
                    <span class="tabular-nums text-rose-600 dark:text-rose-400">{{ money($payment->refunded_amount) }}</span>
                </div>
                <div class="mt-1 flex items-baseline justify-between text-sm font-semibold">
                    <span class="text-slate-500">Net received</span>
                    <span class="tabular-nums text-slate-900 dark:text-white">{{ money($payment->net_received_amount) }}</span>
                </div>
            @endif
        </div>

        <p class="mt-8 text-xs text-slate-500 dark:text-slate-400">
            Received by {{ $payment->received_by_name }} · entered {{ app_datetime($payment->recorded_at) }}
        </p>
    </div>
@endsection
