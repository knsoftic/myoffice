@extends('layouts.admin')

@section('title', 'New payout')

@section('header')
    <x-ui.page-header title="New payout"
                      subtitle="Pick a partner and an amount. The system decides which commissions it settles — oldest first — and the payout is worth exactly what it manages to claim."
                      icon="banknotes"
                      :back="route('admin.payouts.index')" />
@endsection

@section('content')
    @if ($collaborator === null)
        <x-ui.card title="Which partner?" subtitle="Step 1 of 2.">
            <form method="GET" class="grid gap-3 sm:grid-cols-2">
                <x-ui.form.select name="collaborator" label="Collaborator" placeholder="Choose a partner" required>
                    @foreach ($collaborators as $option)
                        <option value="{{ $option->id }}">
                            {{ $option->company_name ?: $option->name }} ({{ $option->collaborator_code }})
                        </option>
                    @endforeach
                </x-ui.form.select>

                <div class="flex items-end">
                    <x-ui.button type="submit" variant="primary" icon="arrow-right">Continue</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @else
        @php
            $available = $snapshot->availableBalance;
            $belowMinimum = bccomp($available, $minimum, 2) === -1;
        @endphp

        <div
            x-data="payoutWizard({
                collaboratorId: {{ (int) $collaborator->id }},
                available: '{{ $available }}',
                planUrl: '{{ route('admin.payouts.plan') }}',
            })"
            class="grid gap-4 lg:grid-cols-3"
        >
            <div class="space-y-4 lg:col-span-2">
                @if ($wallet?->is_frozen)
                    <div class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-200">
                        <p class="font-semibold">This wallet is frozen. No payout can be raised.</p>
                        @if (filled($wallet->frozen_reason))
                            <p class="mt-1">Reason: {{ $wallet->frozen_reason }}</p>
                        @endif
                    </div>
                @elseif (bccomp($available, '0.00', 2) !== 1)
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200">
                        <p class="font-semibold">There is nothing available to pay.</p>
                        <p class="mt-1">
                            {{ bccomp($available, '0.00', 2) === -1
                                ? 'This partner currently owes the business ' . money(ltrim($available, '-')) . ' after a clawback. A payout is refused until that is absorbed.'
                                : 'Commission becomes payable once it is approved and any hold has passed.' }}
                        </p>
                    </div>
                @elseif ($belowMinimum)
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200">
                        <p class="font-semibold">Below the minimum payout of {{ money($minimum) }}.</p>
                        <p class="mt-1">{{ money($available) }} is available. The service will refuse a request under the minimum.</p>
                    </div>
                @endif

                <x-ui.card title="How much, and where" subtitle="Step 2 of 2.">
                    <form method="POST" action="{{ route('admin.payouts.store') }}" class="space-y-4">
                        @csrf
                        <input type="hidden" name="collaborator_id" value="{{ $collaborator->id }}">
                        {{-- Generated when the form opened: a double submit returns the payout that
                             already exists instead of raising a second one. --}}
                        <input type="hidden" name="idempotency_key" x-model="idempotencyKey">

                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-ui.form.input name="amount" label="Amount" required
                                             :value="old('amount')" placeholder="0.00"
                                             x-model="amount" x-on:input.debounce.400ms="preview()" />

                            <x-ui.form.select name="method" label="Method" required>
                                @foreach ($methods as $method)
                                    <option value="{{ $method->value }}" @selected(old('method') === $method->value)>{{ $method->label() }}</option>
                                @endforeach
                            </x-ui.form.select>
                        </div>

                        <x-ui.form.select name="payout_account_id" label="Destination" placeholder="Not recorded">
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}" @selected(old('payout_account_id') == $account->id)>
                                    {{ $account->label }} — {{ $account->maskedAccount() }}{{ $account->is_verified ? '' : ' (unverified)' }}
                                </option>
                            @endforeach
                        </x-ui.form.select>

                        @if ($accounts->isEmpty())
                            <p class="text-sm text-amber-700 dark:text-amber-300">
                                This partner has no active destination on file.
                                @can('collaborator_payouts.view')
                                    <a href="{{ route('admin.payout-accounts.index', $collaborator) }}" class="font-semibold underline">Check their accounts</a>.
                                @endcan
                            </p>
                        @endif

                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-ui.form.input type="date" name="statement_from" label="Settles from" :value="old('statement_from')" />
                            <x-ui.form.input type="date" name="statement_to" label="Settles to" :value="old('statement_to')" />
                        </div>

                        <x-ui.form.textarea name="notes" label="Notes" rows="2" :value="old('notes')"
                                            placeholder="Anything the voucher should carry." />

                        <div class="flex justify-end gap-2">
                            <x-ui.button variant="ghost" :href="route('admin.payouts.index')">Cancel</x-ui.button>
                            <x-ui.button type="submit" variant="primary" icon="banknotes">Raise the payout</x-ui.button>
                        </div>
                    </form>
                </x-ui.card>

                <x-ui.card title="What this would settle"
                           subtitle="Oldest entries first. Nothing is claimed until the payout is created, so this preview can be out of date by the time you submit — and if it is, nothing is written.">
                    <template x-if="loading">
                        <p class="py-6 text-center text-sm text-slate-500 dark:text-slate-400">Working it out…</p>
                    </template>

                    <template x-if="! loading && plan === null">
                        <p class="py-6 text-center text-sm text-slate-500 dark:text-slate-400">
                            Enter an amount to see which commissions it would settle.
                        </p>
                    </template>

                    <template x-if="! loading && plan !== null">
                        <div>
                            <p class="mb-3 text-sm" :class="plan.satisfiable ? 'text-slate-600 dark:text-slate-300' : 'text-rose-600 dark:text-rose-400'"
                               x-text="plan.note"></p>

                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-800 dark:text-slate-400">
                                        <tr>
                                            <th class="px-3 py-2 text-left font-semibold">Entry</th>
                                            <th class="px-3 py-2 text-left font-semibold">Dated</th>
                                            <th class="px-3 py-2 text-right font-semibold">Claimable</th>
                                            <th class="px-3 py-2 text-right font-semibold">Claimed</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                        <template x-for="slice in plan.slices" :key="slice.entry_id">
                                            <tr>
                                                <td class="px-3 py-2 font-mono text-xs text-slate-900 dark:text-white" x-text="slice.reference"></td>
                                                <td class="px-3 py-2 text-slate-500 dark:text-slate-400" x-text="slice.transaction_date"></td>
                                                <td class="px-3 py-2 text-right tabular-nums text-slate-600 dark:text-slate-300" x-text="slice.available"></td>
                                                <td class="px-3 py-2 text-right font-semibold tabular-nums text-slate-900 dark:text-white" x-text="slice.slice"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </template>
                </x-ui.card>
            </div>

            <div class="space-y-4">
                <x-ui.card :title="$collaborator->displayName()" :subtitle="$collaborator->collaborator_code">
                    <dl class="space-y-3 text-sm">
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Available</dt>
                            <dd class="text-right font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($available) }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Pending approval</dt>
                            <dd class="text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money($snapshot->pendingBalance) }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Already reserved</dt>
                            <dd class="text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money($snapshot->reservedBalance) }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Minimum payout</dt>
                            <dd class="text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money($minimum) }}</dd>
                        </div>
                    </dl>

                    <x-slot:footer>
                        <div class="flex flex-wrap gap-2">
                            @can('collaborator_wallets.view')
                                <x-ui.button size="sm" variant="ghost" :href="route('admin.wallets.show', $collaborator)">Wallet</x-ui.button>
                            @endcan
                            @can('collaborator_commissions.view_financial')
                                <x-ui.button size="sm" variant="ghost" :href="route('admin.statements.show', $collaborator)">Statement</x-ui.button>
                            @endcan
                            <x-ui.button size="sm" variant="ghost" :href="route('admin.payouts.create')">Change partner</x-ui.button>
                        </div>
                    </x-slot:footer>
                </x-ui.card>
            </div>
        </div>

        @push('scripts')
            <script nonce="{{ csp_nonce() }}">
                function payoutWizard(config) {
                    return {
                        amount: '',
                        plan: null,
                        loading: false,
                        // One key per opened form. Submitting twice returns the payout that already
                        // exists rather than raising a second one for the same money.
                        idempotencyKey: 'payout-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10),

                        async preview() {
                            const amount = (this.amount || '').replace(/[, ]/g, '');

                            if (! amount || Number(amount) <= 0) {
                                this.plan = null;
                                return;
                            }

                            this.loading = true;

                            try {
                                const url = new URL(config.planUrl, window.location.origin);
                                url.searchParams.set('collaborator_id', config.collaboratorId);
                                url.searchParams.set('amount', amount);

                                const response = await fetch(url, { headers: { Accept: 'application/json' } });

                                this.plan = response.ok ? await response.json() : null;
                            } catch (error) {
                                this.plan = null;
                            } finally {
                                this.loading = false;
                            }
                        },
                    };
                }
            </script>
        @endpush
    @endif
@endsection
