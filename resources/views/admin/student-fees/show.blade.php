@extends('layouts.admin')

@section('title', $charge->fee_number.' — '.($charge->student?->name ?? 'fee'))

@section('header')
    <x-ui.page-header :title="$charge->fee_number"
                      :subtitle="($charge->student?->name ?? '').' · '.$charge->fee_type->label().($charge->batch?->code ? ' · '.$charge->batch->code : '')"
                      icon="banknotes"
                      :back="route('admin.student-fees.index')">
        <x-slot:actions>
            <x-ui.badge :color="$charge->status->color()">{{ $charge->status->label() }}</x-ui.badge>

            @can('student_fees.print')
                <x-ui.button variant="ghost" icon="printer" :href="route('admin.student-fees.slip', $charge)">Fee slip</x-ui.button>
            @endcan

            @if ($charge->isOpen())
                @can('student_fee_payments.create')
                    <x-ui.button icon="banknotes" x-on:click="$dispatch('open-modal', 'collect-payment')">Collect payment</x-ui.button>
                @endcan
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <x-ui.stat-card label="Net fee" :value="money($charge->net_amount)" icon="document-text" color="slate" />
        <x-ui.stat-card label="Received" :value="money($charge->paid_amount)" icon="banknotes" color="emerald" />
        {{-- The balance card says what the sign MEANS. A negative number beside the word "balance"
             reads as a debt to most people, and here it is the opposite. --}}
        <x-ui.stat-card label="Balance"
                        :value="money(\App\Support\Money::abs($charge->balance_amount))"
                        :icon="(float) $charge->balance_amount < 0 ? 'arrow-trending-up' : 'clock'"
                        :color="(float) $charge->balance_amount > 0 ? 'rose' : ((float) $charge->balance_amount < 0 ? 'emerald' : 'slate')"
                        :delta-label="(float) $charge->balance_amount < 0 ? 'in advance' : ((float) $charge->balance_amount > 0 ? 'outstanding' : 'nothing outstanding')" />
    </div>

    @if ($charge->status === \App\Enums\StudentFeeStatus::Cancelled)
        <x-ui.card class="mb-4">
            <div class="flex items-start gap-3">
                <x-ui.icon name="x-circle" class="mt-0.5 h-5 w-5 text-rose-500" />
                <div>
                    <div class="text-sm font-medium text-slate-700 dark:text-slate-200">
                        Cancelled {{ $charge->cancelled_at ? app_datetime($charge->cancelled_at) : '' }}
                        {{ $charge->canceller?->name ? 'by '.$charge->canceller->name : '' }}
                    </div>
                    <div class="mt-0.5 text-sm text-slate-500">{{ $charge->cancellation_reason }}</div>
                </div>
            </div>
        </x-ui.card>
    @endif

    <div x-data="uiTabs('payments')">
        <x-ui.tabs :tabs="array_values(array_filter([
            ['label' => 'Payments', 'key' => 'payments', 'count' => $payments->count()],
            ['label' => 'Installments', 'key' => 'installments', 'count' => $installments->count()],
            ['label' => 'Discounts', 'key' => 'discounts', 'count' => $discounts->count()],
            ['label' => 'Reminders', 'key' => 'reminders', 'count' => $reminders->count()],
            // Absent, not empty: a heading with nothing under it tells somebody there is something
            // to see. Without `collaborator_commissions.view_financial` the tab does not exist.
            $commissionEntries === null ? null : ['label' => 'Commission', 'key' => 'commission', 'count' => $commissionEntries->count()],
        ]))" />

        {{-- Payments ------------------------------------------------------------------------ --}}
        <div x-show="is('payments')" x-cloak class="mt-4">
            @include('admin.student-fees._payments', ['charge' => $charge, 'payments' => $payments])
        </div>

        {{-- Installments -------------------------------------------------------------------- --}}
        <div x-show="is('installments')" x-cloak class="mt-4">
            @include('admin.student-fees._installments', ['charge' => $charge, 'installments' => $installments])
        </div>

        {{-- Discounts ----------------------------------------------------------------------- --}}
        <div x-show="is('discounts')" x-cloak class="mt-4">
            @include('admin.student-fees._discounts', ['charge' => $charge, 'discounts' => $discounts])
        </div>

        {{-- Reminders ----------------------------------------------------------------------- --}}
        <div x-show="is('reminders')" x-cloak class="mt-4">
            @include('admin.student-fees._reminders', ['charge' => $charge, 'reminders' => $reminders])
        </div>

        @if ($commissionEntries !== null)
            <div x-show="is('commission')" x-cloak class="mt-4">
                @include('admin.student-fees._commission', ['charge' => $charge, 'entries' => $commissionEntries])
            </div>
        @endif
    </div>

    {{-- Dialogs ------------------------------------------------------------------------------ --}}
    @can('student_fees.change_status')
        @if ($charge->status === \App\Enums\StudentFeeStatus::Cancelled)
            <x-ui.modal name="reopen-charge" title="Reopen this charge?" icon="arrow-path">
                <form method="POST" action="{{ route('admin.student-fees.reopen', $charge) }}" class="space-y-4">
                    @csrf
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        It goes back to pending — and straight to overdue if the due date has passed
                        while it was cancelled.
                    </p>
                    <x-ui.form.textarea name="reason" label="Why?" required rows="2" />
                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'reopen-charge')">Cancel</x-ui.button>
                        <x-ui.button type="submit" variant="primary">Reopen</x-ui.button>
                    </div>
                </form>
            </x-ui.modal>
        @else
            <x-ui.modal name="cancel-charge" :title="'Cancel '.$charge->fee_number.'?'" icon="x-circle">
                <form method="POST" action="{{ route('admin.student-fees.cancel', $charge) }}" class="space-y-4">
                    @csrf
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        Unpaid installments are cancelled with it. Discounts are kept — they are
                        append-only, and the history of what was agreed does not disappear with the charge.
                        @if ((float) $charge->paid_amount > 0)
                            <span class="mt-2 block font-medium text-rose-600 dark:text-rose-400">
                                This charge holds {{ money($charge->paid_amount) }} in receipts, so it will be
                                refused. Refund or void the money first.
                            </span>
                        @endif
                    </p>
                    <x-ui.form.textarea name="reason" label="Why?" required rows="2" />
                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'cancel-charge')">Keep it</x-ui.button>
                        <x-ui.button type="submit" variant="danger">Cancel the charge</x-ui.button>
                    </div>
                </form>
            </x-ui.modal>
        @endif
    @endcan

    @can('fee_discounts.create')
        @include('admin.student-fees._discount-modal', ['charge' => $charge])
    @endcan
@endsection
