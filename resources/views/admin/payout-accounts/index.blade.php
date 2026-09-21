@extends('layouts.admin')

@section('title', $collaborator->displayName() . ' — payout accounts')

@section('header')
    <x-ui.page-header :title="$collaborator->displayName()"
                      subtitle="Where this partner's money goes. Shown masked, always — no permission anywhere reveals the full details."
                      icon="credit-card"
                      :back="route('admin.collaborators.show', $collaborator)" />
@endsection

@section('content')
    @unless ($verificationRequired)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200">
            <strong>Verification is currently optional.</strong>
            <code>collaborator.payout_account_verification_required</code> is off, so money can be sent to a
            destination nobody has checked. That is a deliberate setting for a business paying small amounts to
            many partners — it is not a default worth keeping quiet about.
        </div>
    @endunless

    <x-ui.card :title="$accounts->count() . ' ' . \Illuminate\Support\Str::plural('destination', $accounts->count())"
               subtitle="A verified destination is one somebody other than the partner has looked at and agreed with.">
        <x-ui.table :is-empty="$accounts->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Label</th>
                <th class="px-4 py-3 text-left font-semibold">Method</th>
                <th class="px-4 py-3 text-left font-semibold">Account</th>
                <th class="px-4 py-3 text-left font-semibold">State</th>
                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($accounts as $account)
                <tr>
                    <td class="px-4 py-3">
                        <span class="block font-medium text-slate-900 dark:text-white">{{ $account->label }}</span>
                        @if ($account->is_default)
                            <span class="block text-xs text-slate-500 dark:text-slate-400">default destination</span>
                        @endif
                    </td>

                    <td class="px-4 py-3 text-slate-700 dark:text-slate-200">{{ $account->method->label() }}</td>

                    <td class="px-4 py-3">
                        <span class="block font-mono text-xs text-slate-900 dark:text-white">{{ $account->maskedAccount() }}</span>
                        @if (filled($account->account_title))
                            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $account->account_title }}</span>
                        @endif
                    </td>

                    <td class="px-4 py-3">
                        @if ($account->is_verified)
                            <x-ui.badge color="emerald" size="xs">Verified</x-ui.badge>
                            <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">
                                {{ $account->verifier?->name }}
                                @if ($account->verified_at) · {{ app_date($account->verified_at) }} @endif
                            </span>
                        @else
                            <x-ui.badge color="amber" size="xs">Not verified</x-ui.badge>
                        @endif

                        @unless ($account->isUsable())
                            <x-ui.badge color="rose" size="xs" class="mt-1">Not usable</x-ui.badge>
                        @endunless
                    </td>

                    <td class="px-4 py-3 text-right">
                        @can('collaborator_payouts.approve')
                            <form method="POST" action="{{ route('admin.payout-accounts.verify', $account) }}"
                                  class="inline-flex items-center gap-2">
                                @csrf
                                <input type="hidden" name="verified" value="{{ $account->is_verified ? 0 : 1 }}">
                                @if ($account->is_verified)
                                    <input type="text" name="note" required maxlength="255"
                                           placeholder="What is wrong with it"
                                           class="w-48 rounded-lg border-slate-300 text-xs dark:border-slate-700 dark:bg-slate-900">
                                @endif
                                <x-ui.button type="submit" size="sm"
                                             :variant="$account->is_verified ? 'secondary' : 'primary'"
                                             :icon="$account->is_verified ? 'x-mark' : 'check'">
                                    {{ $account->is_verified ? 'Unverify' : 'Verify' }}
                                </x-ui.button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="credit-card"
                                  title="No destination on file"
                                  message="The partner adds one from their own panel. Until they do, a payout can only be recorded without a destination." />
            </x-slot:empty>
        </x-ui.table>
    </x-ui.card>
@endsection
