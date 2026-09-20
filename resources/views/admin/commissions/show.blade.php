@extends('layouts.admin')

@section('title', $entry->reference)

@php
    $isNegative = str_starts_with((string) $entry->signed_amount, '-');
    $canApprove = auth()->user()?->can('collaborator_commissions.approve');
    $canReject = auth()->user()?->can('collaborator_commissions.reject');
    $decidable = in_array($entry->status, [
        \App\Enums\CommissionStatus::Pending,
        \App\Enums\CommissionStatus::Approved,
        \App\Enums\CommissionStatus::Available,
    ], true);
@endphp

@section('header')
    <x-ui.page-header :title="$entry->reference"
                      :subtitle="$entry->purpose->label() . ' · ' . app_date($entry->transaction_date)"
                      icon="calculator">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.commissions.index')" icon="arrow-left">Back</x-ui.button>

            @if ($decidable && $canApprove && $entry->status === \App\Enums\CommissionStatus::Pending)
                <form method="POST" action="{{ route('admin.commissions.approve', $entry) }}">
                    @csrf
                    <x-ui.button variant="primary" type="submit" icon="check">Approve</x-ui.button>
                </form>
            @endif

            @if ($decidable && $canReject)
                <x-ui.button variant="danger" icon="x-mark"
                             x-on:click="$dispatch('open-modal', 'reject-entry')">
                    {{ $entry->status === \App\Enums\CommissionStatus::Available ? 'Cancel' : 'Reject' }}
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <x-ui.stat-card label="Amount" :value="money($entry->signed_amount)"
                        :color="$isNegative ? 'rose' : 'emerald'" icon="banknotes" />
        <x-ui.stat-card label="Status" :value="$entry->status->label()" :color="$entry->status->color()" icon="flag" />
        <x-ui.stat-card label="Collaborator"
                        :value="$entry->collaborator?->displayName() ?? '—'"
                        :delta-label="$entry->collaborator?->collaborator_code" icon="user-group" />
    </div>

    <div class="mt-4" x-data="uiTabs('calculation')">
        <x-ui.tabs :tabs="[
            ['label' => 'Calculation', 'key' => 'calculation'],
            ['label' => 'Related', 'key' => 'related'],
            ['label' => 'History', 'key' => 'history'],
        ]" />

        {{-- ---------------------------------------------------------------- Calculation --}}
        <div x-show="is('calculation')" class="mt-4">
            <x-ui.card title="How this figure was reached"
                       subtitle="Read from the row's own snapshot — never recomputed, so a later rate change cannot alter what it says.">
                @if (($trace['base_fallback'] ?? false) === true)
                    <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200">
                        <strong>The configured base did not apply to this scope</strong>, so this entry used
                        <code>paid</code>. The money was not held up for a misconfiguration, but the rule or
                        the setting still needs correcting.
                    </div>
                @endif

                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    @foreach ([
                        'Base mode' => $entry->commission_base->label(),
                        'Branch' => $trace['branch'] ?? '—',
                        'Document base amount' => money($trace['document_base_amount'] ?? null),
                        'Collectible amount' => money($trace['collectible_amount'] ?? null),
                        'Collected before' => money($trace['collected_before'] ?? null),
                        'This payment counted as' => money($trace['base_amount'] ?? null),
                        'Collected after' => array_key_exists('collected_after', $trace) ? money($trace['collected_after']) : null,
                        'Entitlement total' => $entry->entitlement_total === null ? 'Uncapped' : money($entry->entitlement_total),
                        'Cumulative target' => array_key_exists('target', $trace) ? money($trace['target']) : null,
                        'Released before' => money($entry->released_before ?? '0.00'),
                        'Released now' => money($entry->amount),
                        'Rounding residual' => array_key_exists('rounding_residual', $trace) ? money($trace['rounding_residual']) : null,
                    ] as $label => $value)
                        @if ($value !== null)
                            <div class="flex items-baseline justify-between gap-4 border-b border-slate-100 pb-2 dark:border-slate-800">
                                <dt class="text-sm text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                                <dd class="text-sm font-medium tabular-nums text-slate-900 dark:text-white">{{ $value }}</dd>
                            </div>
                        @endif
                    @endforeach
                </dl>

                <p class="mt-4 text-sm text-slate-600 dark:text-slate-300">
                    @if ($entry->calculation_type === \App\Enums\CommissionCalculationType::Percentage)
                        <strong>{{ rtrim(rtrim((string) $entry->commission_rate, '0'), '.') }}%</strong>
                        of {{ money($entry->base_amount) }} = <strong>{{ money($entry->amount) }}</strong>.
                    @elseif ($entry->calculation_type === \App\Enums\CommissionCalculationType::Fixed)
                        A fixed {{ money($entry->fixed_amount) }}, released
                        <strong>{{ money($entry->amount) }}</strong> against this payment.
                    @else
                        Posted by hand: <strong>{{ money($entry->signed_amount) }}</strong>.
                    @endif
                </p>
            </x-ui.card>
        </div>

        {{-- ------------------------------------------------------------------- Related --}}
        <div x-show="is('related')" x-cloak class="mt-4 grid gap-4 lg:grid-cols-2">
            <x-ui.card title="Source">
                @if ($entry->studentFeePayment)
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">Receipt</dt>
                            <dd class="font-mono text-slate-900 dark:text-white">{{ $entry->studentFeePayment->receipt_no }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Received</dt>
                            <dd class="tabular-nums">{{ money($entry->studentFeePayment->amount) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Value date</dt>
                            <dd>{{ app_date($entry->studentFeePayment->paid_on) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Receipt status</dt>
                            <dd><x-ui.badge :color="$entry->studentFeePayment->status->color()" size="xs">{{ $entry->studentFeePayment->status->label() }}</x-ui.badge></dd></div>
                    </dl>
                @else
                    <x-ui.empty-state icon="receipt-percent" title="No receipt"
                        description="A manual adjustment has no source transaction — that is what makes it manual." />
                @endif
            </x-ui.card>

            <x-ui.card title="Rule version">
                @if ($entry->rule)
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">Version</dt>
                            <dd class="text-slate-900 dark:text-white">#{{ $entry->rule->version }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">In force</dt>
                            <dd>{{ app_date($entry->rule->effective_from) }} → {{ $entry->rule->effective_to ? app_date($entry->rule->effective_to) : 'open' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Source</dt>
                            <dd>{{ $entry->rule_source?->label() ?? '—' }}</dd></div>
                    </dl>
                    @can('collaborator_commission_settings.view')
                        <x-ui.button class="mt-3" variant="ghost" size="sm"
                                     :href="route('admin.commission-rules.index', $entry->collaborator_id)">
                            Open the timeline
                        </x-ui.button>
                    @endcan
                @else
                    <p class="text-sm text-slate-500">No rule version — posted by hand.</p>
                @endif
            </x-ui.card>

            <x-ui.card title="Reversals" class="lg:col-span-2">
                @if ($entry->original)
                    <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">
                        This row undoes
                        <a class="font-mono text-brand-700 dark:text-brand-300" href="{{ route('admin.commissions.show', $entry->original) }}">
                            {{ $entry->original->reference }}
                        </a>
                        of {{ money($entry->original->amount) }}.
                    </p>
                @endif

                @if ($entry->reversals->isNotEmpty())
                    <x-ui.table>
                        <x-slot:head>
                            <th class="px-4 py-2 text-left font-semibold">Entry</th>
                            <th class="px-4 py-2 text-left font-semibold">Purpose</th>
                            <th class="px-4 py-2 text-right font-semibold">Amount</th>
                            <th class="px-4 py-2 text-left font-semibold">Status</th>
                        </x-slot:head>
                        @foreach ($entry->reversals as $row)
                            <tr>
                                <td class="px-4 py-2">
                                    <a class="font-mono text-xs text-brand-700 dark:text-brand-300" href="{{ route('admin.commissions.show', $row) }}">
                                        {{ $row->reference }}
                                    </a>
                                    <span class="block text-xs text-slate-500">{{ app_date($row->transaction_date) }}</span>
                                </td>
                                <td class="px-4 py-2"><x-ui.badge :color="$row->purpose->color()" size="xs">{{ $row->purpose->label() }}</x-ui.badge></td>
                                <td class="px-4 py-2 text-right tabular-nums text-rose-600 dark:text-rose-400">{{ money($row->amount) }}</td>
                                <td class="px-4 py-2"><x-ui.badge :color="$row->status->color()" size="xs">{{ $row->status->label() }}</x-ui.badge></td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @elseif (! $entry->original)
                    <x-ui.empty-state icon="arrow-uturn-left" title="Nothing has been undone"
                        description="A correction would appear here as its own row — the original is never edited." />
                @endif
            </x-ui.card>
        </div>

        {{-- ------------------------------------------------------------------- History --}}
        <div x-show="is('history')" x-cloak class="mt-4">
            <x-ui.card title="What has happened to this entry">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Posted</dt>
                        <dd>{{ app_datetime($entry->posted_at) }}</dd></div>
                    @if ($entry->approved_at)
                        <div class="flex justify-between"><dt class="text-slate-500">Approved</dt>
                            <dd>{{ app_datetime($entry->approved_at) }} · {{ $entry->approver?->name }}</dd></div>
                    @endif
                    @if ($entry->hold_until)
                        <div class="flex justify-between"><dt class="text-slate-500">Held until</dt>
                            <dd>{{ app_date($entry->hold_until) }}</dd></div>
                    @endif
                    @if ($entry->available_at)
                        <div class="flex justify-between"><dt class="text-slate-500">Became available</dt>
                            <dd>{{ app_datetime($entry->available_at) }}</dd></div>
                    @endif
                    @if ($entry->paid_at)
                        <div class="flex justify-between"><dt class="text-slate-500">Paid out</dt>
                            <dd>{{ app_datetime($entry->paid_at) }}</dd></div>
                    @endif
                    @if ($entry->reversed_at)
                        <div class="flex justify-between"><dt class="text-slate-500">Reversed</dt>
                            <dd>{{ app_datetime($entry->reversed_at) }} · {{ money($entry->reversed_amount) }}</dd></div>
                    @endif
                    @if ($entry->cancelled_at)
                        <div class="flex justify-between"><dt class="text-slate-500">Cancelled</dt>
                            <dd>{{ app_datetime($entry->cancelled_at) }} · {{ $entry->canceller?->name }}</dd></div>
                    @endif
                </dl>

                @if ($entry->cancel_reason)
                    <p class="mt-4 rounded-lg bg-slate-50 p-3 text-sm text-slate-700 dark:bg-slate-800/60 dark:text-slate-200">
                        <strong>Reason:</strong> {{ $entry->cancel_reason }}
                    </p>
                @endif
            </x-ui.card>
        </div>
    </div>

    @if ($decidable && $canReject)
        <x-ui.modal name="reject-entry"
                    :title="$entry->status === \App\Enums\CommissionStatus::Available ? 'Cancel this commission' : 'Reject this commission'">
            <form method="POST" action="{{ route('admin.commissions.reject', $entry) }}">
                @csrf
                <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">
                    {{ money($entry->amount) }} leaves every bucket. <strong>The row stays</strong>, with this
                    reason on it — a partner who asks why they were not paid is shown exactly this. The
                    receipt behind it is untouched.
                </p>
                <x-ui.form.textarea name="reason" label="Reason" required rows="3" />
                <div class="mt-4 flex justify-end gap-2">
                    <x-ui.button variant="ghost" type="button" x-on:click="$dispatch('close-modal', 'reject-entry')">Keep it</x-ui.button>
                    <x-ui.button variant="danger" type="submit">Confirm</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
@endsection
