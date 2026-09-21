@extends('layouts.admin')

@section('title', $payout->payout_no)

@php
    $canApprove = auth()->user()?->can('collaborator_payouts.approve');
    $canReject = auth()->user()?->can('collaborator_payouts.reject');
    $canChangeStatus = auth()->user()?->can('collaborator_payouts.change_status');
    $isPaid = $payout->status === \App\Enums\PayoutStatus::Paid;
@endphp

@section('header')
    <x-ui.page-header :title="$payout->payout_no"
                      :subtitle="money($payout->amount) . ' to ' . ($payout->collaborator?->displayName() ?? 'a partner')"
                      icon="banknotes"
                      :badge="$payout->status->label()"
                      :badge-color="$payout->status->color()"
                      :back="route('admin.payouts.index')">
        <x-slot:actions>
            @can('collaborator_payouts.print')
                <x-ui.button variant="secondary" :href="route('admin.payouts.voucher', $payout)" icon="printer" target="_blank">
                    Voucher
                </x-ui.button>
            @endcan

            @if ($canApprove && $payout->canTransitionTo(\App\Enums\PayoutStatus::Approved))
                <form method="POST" action="{{ route('admin.payouts.approve', $payout) }}">
                    @csrf
                    <x-ui.button type="submit" variant="primary" icon="check">Approve</x-ui.button>
                </form>
            @endif

            @if ($canChangeStatus && $payout->status === \App\Enums\PayoutStatus::Approved)
                <x-ui.button variant="primary" icon="paper-airplane" x-on:click.prevent="$dispatch('open-modal', 'mark-paid')">
                    Mark paid
                </x-ui.button>
            @endif

            @if ($canReject && $payout->isInFlight())
                <x-ui.button variant="danger" icon="x-mark" x-on:click.prevent="$dispatch('open-modal', 'reject-payout')">
                    Reject
                </x-ui.button>
            @endif

            @if ($canChangeStatus && $payout->isInFlight())
                <x-ui.button variant="secondary" icon="arrow-uturn-left" x-on:click.prevent="$dispatch('open-modal', 'cancel-payout')">
                    Withdraw
                </x-ui.button>
            @endif

            @if ($isPaid && $canChangeStatus && $canApprove)
                <x-ui.button variant="danger" icon="arrow-uturn-left" x-on:click.prevent="$dispatch('open-modal', 'return-payout')">
                    Returned by the bank
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($payout->requestDisagreedWithAvailable())
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200">
            {{ money($payout->requested_amount) }} was asked for and {{ money($payout->amount) }} could be claimed
            from the ledger. The payout is worth what it actually claims (INV-22), never what was typed.
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="What this settles"
                       subtitle="The named commission entries this payout claims. This is the answer to “which commissions did you pay me for?”">
                <x-ui.table :is-empty="$live->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Entry</th>
                        <th class="px-4 py-3 text-left font-semibold">Dated</th>
                        <th class="px-4 py-3 text-right font-semibold">Entry amount</th>
                        <th class="px-4 py-3 text-right font-semibold">Claimed here</th>
                    </x-slot:head>

                    @foreach ($live as $allocation)
                        <tr>
                            <td class="px-4 py-3">
                                @can('collaborator_commissions.view')
                                    <a href="{{ route('admin.commissions.show', $allocation->ledger_entry_id) }}"
                                       class="font-mono text-xs font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                                        {{ $allocation->entry?->reference ?? 'CLE-' . $allocation->ledger_entry_id }}
                                    </a>
                                @else
                                    <span class="font-mono text-xs">{{ $allocation->entry?->reference }}</span>
                                @endcan
                                @if ($allocation->entry)
                                    <x-ui.badge :color="$allocation->entry->status->color()" size="xs" class="mt-1">
                                        {{ $allocation->entry->status->label() }}
                                    </x-ui.badge>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ app_date($allocation->entry_transaction_date) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                {{ $allocation->entry ? money($allocation->entry->amount) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">
                                {{ money($allocation->amount) }}
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="link-slash"
                                          title="Nothing is claimed"
                                          message="Every claim this payout held has been released. An empty payout is not a payout." />
                    </x-slot:empty>
                </x-ui.table>

                @if ($live->isNotEmpty())
                    <x-slot:footer>
                        <p class="text-sm text-slate-600 dark:text-slate-300">
                            {{ $live->count() }} {{ \Illuminate\Support\Str::plural('entry', $live->count()) }},
                            totalling <span class="font-semibold tabular-nums">{{ money($live->sum(fn ($a) => (float) $a->amount)) }}</span>.
                        </p>
                    </x-slot:footer>
                @endif
            </x-ui.card>

            @if ($released->isNotEmpty())
                <x-ui.card title="Claims that were released"
                           subtitle="Kept rather than deleted: why a payout shrank is the part somebody asks about afterwards.">
                    <x-ui.table>
                        <x-slot:head>
                            <th class="px-4 py-3 text-left font-semibold">Entry</th>
                            <th class="px-4 py-3 text-left font-semibold">Released</th>
                            <th class="px-4 py-3 text-left font-semibold">Why</th>
                            <th class="px-4 py-3 text-right font-semibold">Amount</th>
                        </x-slot:head>

                        @foreach ($released as $allocation)
                            <tr>
                                <td class="px-4 py-3 font-mono text-xs text-slate-900 dark:text-white">
                                    {{ $allocation->entry?->reference ?? 'CLE-' . $allocation->ledger_entry_id }}
                                </td>
                                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ app_datetime($allocation->released_at) }}</td>
                                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                                    {{ $allocation->release_reason?->label() ?? $allocation->release_reason }}
                                    @if (filled($allocation->release_note))
                                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $allocation->release_note }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-500 line-through dark:text-slate-400">
                                    {{ money($allocation->amount) }}
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-4">
            <x-ui.card title="The payout">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Requested</dt>
                        <dd class="text-right tabular-nums text-slate-900 dark:text-white">{{ money($payout->requested_amount) }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Amount</dt>
                        <dd class="text-right font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($payout->amount) }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Method</dt>
                        <dd class="text-right text-slate-900 dark:text-white">{{ $payout->method->label() }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Destination</dt>
                        <dd class="text-right font-mono text-xs text-slate-900 dark:text-white">{{ $payout->maskedAccount() }}</dd>
                    </div>
                    @if (filled($payout->transaction_id))
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Bank reference</dt>
                            <dd class="text-right font-mono text-xs text-slate-900 dark:text-white">{{ $payout->transaction_id }}</dd>
                        </div>
                    @endif
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Available when asked</dt>
                        <dd class="text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money($payout->available_at_request) }}</dd>
                    </div>
                    @if ($payout->statement_from !== null)
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Settles</dt>
                            <dd class="text-right text-slate-600 dark:text-slate-300">
                                {{ app_date($payout->statement_from) }} – {{ app_date($payout->statement_to) }}
                            </dd>
                        </div>
                    @endif
                </dl>
            </x-ui.card>

            <x-ui.card title="Who did what">
                <ol class="space-y-3 text-sm">
                    <li class="flex items-start justify-between gap-3">
                        <span class="text-slate-500 dark:text-slate-400">Requested</span>
                        <span class="text-right text-slate-900 dark:text-white">
                            {{ $payout->requestedBy?->name ?? 'System' }}
                            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ app_datetime($payout->requested_at) }}</span>
                        </span>
                    </li>
                    @if ($payout->approved_at)
                        <li class="flex items-start justify-between gap-3">
                            <span class="text-slate-500 dark:text-slate-400">Approved</span>
                            <span class="text-right text-slate-900 dark:text-white">
                                {{ $payout->approvedBy?->name ?? 'System' }}
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ app_datetime($payout->approved_at) }}</span>
                            </span>
                        </li>
                    @endif
                    @if ($payout->paid_at)
                        <li class="flex items-start justify-between gap-3">
                            <span class="text-slate-500 dark:text-slate-400">Paid</span>
                            <span class="text-right text-slate-900 dark:text-white">
                                {{ $payout->paidBy?->name ?? 'System' }}
                                <span class="block text-xs text-slate-500 dark:text-slate-400">
                                    {{ app_datetime($payout->paid_at) }} · value {{ app_date($payout->paid_on) }}
                                </span>
                            </span>
                        </li>
                    @endif
                    @if ($payout->rejected_at)
                        <li class="flex items-start justify-between gap-3">
                            <span class="text-slate-500 dark:text-slate-400">Rejected</span>
                            <span class="text-right text-slate-900 dark:text-white">
                                {{ $payout->rejectedBy?->name }}
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $payout->rejection_reason }}</span>
                            </span>
                        </li>
                    @endif
                    @if ($payout->cancelled_at)
                        <li class="flex items-start justify-between gap-3">
                            <span class="text-slate-500 dark:text-slate-400">Withdrawn</span>
                            <span class="text-right text-slate-900 dark:text-white">
                                {{ $payout->cancelledBy?->name ?? 'System' }}
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $payout->cancellation_reason }}</span>
                            </span>
                        </li>
                    @endif
                </ol>
            </x-ui.card>
        </div>
    </div>

    @if ($canChangeStatus && $payout->status === \App\Enums\PayoutStatus::Approved)
        <x-ui.modal name="mark-paid" title="Record the payment" icon="paper-airplane">
            <form method="POST" action="{{ route('admin.payouts.mark-paid', $payout) }}" class="space-y-4">
                @csrf
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    {{ money($payout->amount) }} by {{ $payout->method->label() }}. Once recorded, every entry this
                    payout claimed in full becomes <strong>paid</strong>, and undoing it is the one backward step
                    in this system.
                </p>

                <x-ui.form.input name="transaction_id" label="Bank reference" :required="$referenceRequired"
                                 placeholder="The reference on the transfer" />

                <x-ui.form.input type="date" name="paid_on" label="Value date"
                                 :value="app_date(now(), 'Y-m-d')" :max="app_date(now(), 'Y-m-d')" />

                <x-ui.form.textarea name="notes" label="Notes" rows="2" />

                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'mark-paid')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary">Record it</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    @if ($canReject && $payout->isInFlight())
        <x-ui.modal name="reject-payout" title="Reject this payout" icon="x-mark">
            <form method="POST" action="{{ route('admin.payouts.reject', $payout) }}" class="space-y-4">
                @csrf
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    Every commission it claimed becomes spendable again. Nothing is deleted.
                </p>
                <x-ui.form.textarea name="reason" label="Reason" required rows="3"
                                    placeholder="Somebody was expecting this money. Say why it is not coming." />
                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'reject-payout')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="danger">Reject</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    @if ($canChangeStatus && $payout->isInFlight())
        <x-ui.modal name="cancel-payout" title="Withdraw this payout" icon="arrow-uturn-left">
            <form method="POST" action="{{ route('admin.payouts.cancel', $payout) }}" class="space-y-4">
                @csrf
                <x-ui.form.textarea name="reason" label="Reason" required rows="3" />
                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'cancel-payout')">Keep it</x-ui.button>
                    <x-ui.button type="submit" variant="secondary">Withdraw</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    @if ($isPaid && $canChangeStatus && $canApprove)
        <x-ui.modal name="return-payout" title="The bank returned this money" icon="arrow-uturn-left">
            <form method="POST" action="{{ route('admin.payouts.cancel-after-payment', $payout) }}" class="space-y-4">
                @csrf
                <div class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-200">
                    This is the <strong>only backward money transition</strong> in the system: settled money
                    re-entering a spendable balance. Every entry this payout settled walks from paid back to
                    available, with its own audit row.
                </div>
                <x-ui.form.textarea name="reason" label="What happened" required rows="3"
                                    placeholder="Wrong account number, bank rejected the transfer, …" />
                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'return-payout')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="danger">Record the return</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
@endsection
