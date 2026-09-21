@extends('layouts.panel')

@section('title', 'Bank accounts')

@section('header')
    <x-ui.page-header title="Where to send your money"
                      subtitle="Add an account and the office will check it before anything is sent there."
                      icon="credit-card"
                      :back="route('collaborator.payouts.index')" />
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card :title="$accounts->count() . ' ' . \Illuminate\Support\Str::plural('account', $accounts->count())">
                <x-ui.table :is-empty="$accounts->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Label</th>
                        <th class="px-4 py-3 text-left font-semibold">Account</th>
                        <th class="px-4 py-3 text-left font-semibold">State</th>
                    </x-slot:head>

                    @foreach ($accounts as $account)
                        <tr>
                            <td class="px-4 py-3">
                                <span class="block font-medium text-slate-900 dark:text-white">{{ $account->label }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $account->method->label() }}</span>
                            </td>

                            <td class="px-4 py-3">
                                <span class="block font-mono text-xs text-slate-900 dark:text-white">{{ $account->maskedAccount() }}</span>
                                @if (filled($account->bank_name))
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $account->bank_name }}</span>
                                @endif
                            </td>

                            <td class="px-4 py-3">
                                @if ($account->is_default)
                                    <x-ui.badge color="brand" size="xs">Default</x-ui.badge>
                                @endif
                                @if ($account->is_verified)
                                    <x-ui.badge color="emerald" size="xs" class="mt-1">Checked</x-ui.badge>
                                @else
                                    <x-ui.badge color="amber" size="xs" class="mt-1">Waiting to be checked</x-ui.badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="credit-card"
                                          title="No account yet"
                                          message="Add one below. Nothing can be paid to you until the office has somewhere to send it." />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="Add an account">
                <form method="POST" action="{{ route('collaborator.payout-accounts.store') }}" class="space-y-4">
                    @csrf

                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-ui.form.input name="label" label="What to call it" required :value="old('label')"
                                         placeholder="Main account" />

                        <x-ui.form.select name="method" label="Type" required>
                            @foreach ($methods as $method)
                                <option value="{{ $method->value }}" @selected(old('method') === $method->value)>{{ $method->label() }}</option>
                            @endforeach
                        </x-ui.form.select>

                        <x-ui.form.input name="account_title" label="Name on the account" required :value="old('account_title')"
                                         placeholder="Exactly as the bank holds it" />

                        <x-ui.form.input name="bank_name" label="Bank" :value="old('bank_name')" />

                        <x-ui.form.input name="account_number" label="Account number or IBAN" required
                                         :value="old('account_number')" autocomplete="off" />
                    </div>

                    <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <input type="checkbox" name="is_default" value="1" @checked(old('is_default'))
                               class="rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
                        Use this one by default
                    </label>

                    <div class="flex justify-end">
                        <x-ui.button type="submit" variant="primary" icon="plus">Add it</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        </div>

        <div class="space-y-4">
            <x-ui.card title="A few things worth knowing">
                <ul class="space-y-3 text-sm text-slate-600 dark:text-slate-300">
                    <li>
                        <span class="font-medium text-slate-900 dark:text-white">The details are stored encrypted</span>
                        and shown to everybody — including you — as the last four digits only.
                    </li>
                    <li>
                        <span class="font-medium text-slate-900 dark:text-white">Somebody in the office checks it</span>
                        before the first transfer.
                        @unless ($verificationRequired)
                            Right now that check is optional, so a new account can be used straight away.
                        @endunless
                    </li>
                    <li>
                        <span class="font-medium text-slate-900 dark:text-white">The name has to match the bank's</span> —
                        a transfer to a mismatched name is returned, and unwinding one is slow for everybody.
                    </li>
                </ul>
            </x-ui.card>
        </div>
    </div>
@endsection
