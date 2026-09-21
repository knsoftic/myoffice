@extends('layouts.panel')

@section('title', 'Request a payout')

@php
    $available = $snapshot->availableBalance;
    $blocked = $snapshot->isInDebit()
        || bccomp($available, '0.00', 2) !== 1
        || bccomp($available, $minimum, 2) === -1
        || ($singleInFlight && $inFlight !== null);
@endphp

@section('header')
    <x-ui.page-header title="Request a payout"
                      subtitle="Ask to be paid what is available. The office approves it and records the transfer."
                      icon="banknotes"
                      :back="route('collaborator.payouts.index')" />
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            @if ($singleInFlight && $inFlight !== null)
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200">
                    <p class="font-semibold">You already have a payout being processed.</p>
                    <p class="mt-1">
                        <a href="{{ route('collaborator.payouts.show', $inFlight) }}" class="font-semibold underline">{{ $inFlight->payout_no }}</a>
                        for {{ money($inFlight->amount) }} is {{ strtolower($inFlight->status->label()) }}. One at a time —
                        it keeps the amounts and the bank references from getting crossed.
                    </p>
                </div>
            @elseif ($snapshot->isInDebit())
                <div class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-200">
                    <p class="font-semibold">Your balance is {{ money($available) }}.</p>
                    <p class="mt-1">A refunded commission is being recovered first. Your statement shows every line.</p>
                </div>
            @elseif (bccomp($available, '0.00', 2) !== 1)
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-300">
                    You have nothing available yet. Commission becomes available once it is approved.
                </div>
            @elseif (bccomp($available, $minimum, 2) === -1)
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-300">
                    The minimum payout is {{ money($minimum) }} and you have {{ money($available) }} available.
                </div>
            @endif

            <x-ui.card title="How much?">
                <form method="POST" action="{{ route('collaborator.payouts.store') }}" class="space-y-4"
                      x-data="{ key: 'req-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10) }">
                    @csrf
                    <input type="hidden" name="idempotency_key" x-model="key">

                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-ui.form.input name="amount" label="Amount" required :value="old('amount', $blocked ? null : $available)"
                                         placeholder="0.00" :disabled="$blocked" />

                        <x-ui.form.select name="method" label="How would you like it?" required :disabled="$blocked">
                            @foreach ($methods as $method)
                                <option value="{{ $method->value }}" @selected(old('method') === $method->value)>{{ $method->label() }}</option>
                            @endforeach
                        </x-ui.form.select>
                    </div>

                    <x-ui.form.select name="payout_account_id" label="Send it to" placeholder="Choose an account" :disabled="$blocked">
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" @selected(old('payout_account_id') == $account->id)>
                                {{ $account->label }} — {{ $account->maskedAccount() }}{{ $account->is_verified ? '' : ' (waiting to be checked)' }}
                            </option>
                        @endforeach
                    </x-ui.form.select>

                    @if ($accounts->isEmpty())
                        <p class="text-sm text-amber-700 dark:text-amber-300">
                            You have no account on file.
                            <a href="{{ route('collaborator.payout-accounts.index') }}" class="font-semibold underline">Add one first</a>
                            — the office needs somewhere to send it.
                        </p>
                    @endif

                    <x-ui.form.textarea name="notes" label="Anything to add?" rows="2" :value="old('notes')" :disabled="$blocked" />

                    <div class="flex justify-end gap-2">
                        <x-ui.button variant="ghost" :href="route('collaborator.payouts.index')">Cancel</x-ui.button>
                        <x-ui.button type="submit" variant="primary" icon="banknotes" :disabled="$blocked">
                            Request it
                        </x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        </div>

        <div class="space-y-4">
            <x-ui.card title="Your balance">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Available</dt>
                        <dd class="text-right font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($available) }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Waiting for approval</dt>
                        <dd class="text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money($snapshot->pendingBalance) }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Minimum payout</dt>
                        <dd class="text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money($minimum) }}</dd>
                    </div>
                </dl>
            </x-ui.card>

            <x-ui.card title="What happens next">
                <ol class="space-y-2 text-sm text-slate-600 dark:text-slate-300">
                    <li>1. Your request is recorded and the commissions it covers are set aside.</li>
                    <li>2. The office approves it.</li>
                    <li>3. The transfer is made and the bank reference is recorded against it.</li>
                </ol>
                <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                    You can withdraw the request yourself until it has been approved.
                </p>
            </x-ui.card>
        </div>
    </div>
@endsection
