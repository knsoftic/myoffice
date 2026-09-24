@extends('layouts.admin')

@section('title', 'Commissions')

@php
    $query = request()->query();
    $pendingTab = $tab === 'pending';
@endphp

@section('header')
    <x-ui.page-header title="Commissions"
                      subtitle="Every movement of entitlement, and the rule that produced it. Nothing here is editable."
                      icon="calculator">
        <x-slot:actions>
            @can('collaborator_commissions.view_reports')
                <x-ui.button variant="secondary" :href="route('admin.commission-skips.index')" icon="exclamation-triangle">
                    Skip report
                </x-ui.button>
            @endcan
            @can('collaborator_commissions.export')
                <x-ui.button variant="secondary" :href="route('admin.commissions.export', ['format' => 'csv'] + $query)" icon="arrow-down-tray">
                    Export
                </x-ui.button>
            @endcan
            @can('collaborator_commissions.create')
                <x-ui.button variant="primary" icon="plus" x-on:click.prevent="$dispatch('open-modal', 'commission-adjustment')">
                    Manual adjustment
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.tabs class="mb-4" :tabs="[
        ['label' => 'All', 'url' => route('admin.commissions.index', array_diff_key($query, ['tab' => ''])), 'active' => ! $pendingTab],
        ['label' => 'Pending approval', 'url' => route('admin.commissions.index', ['tab' => 'pending'] + $query), 'active' => $pendingTab, 'count' => $pendingCount],
    ]" />

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @if ($pendingTab)
                <input type="hidden" name="tab" value="pending">
            @endif

            <x-ui.form.input type="date" name="from" label="From" :value="request('from', app_date($range->start(), 'Y-m-d'))" />
            <x-ui.form.input type="date" name="to" label="To" :value="request('to', app_date($range->end(), 'Y-m-d'))" />

            <x-ui.form.select name="collaborator" label="Collaborator" placeholder="Everybody">
                @foreach ($collaborators as $collaborator)
                    <option value="{{ $collaborator->id }}" @selected(request('collaborator') == $collaborator->id)>
                        {{ $collaborator->company_name ?: $collaborator->name }} ({{ $collaborator->collaborator_code }})
                    </option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="status" label="Status" placeholder="Any status">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="purpose" label="Purpose" placeholder="Any purpose">
                @foreach ($purposes as $purpose)
                    <option value="{{ $purpose->value }}" @selected(request('purpose') === $purpose->value)>{{ $purpose->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="source_type" label="Source" placeholder="Any source">
                @foreach ($sourceTypes as $source)
                    <option value="{{ $source->value }}" @selected(request('source_type') === $source->value)>{{ $source->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.input name="min_amount" label="Amount from" :value="request('min_amount')" placeholder="0.00" />
            <x-ui.form.input name="max_amount" label="Amount to" :value="request('max_amount')" placeholder="0.00" />

            <label class="flex items-end gap-2 text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="reversed_only" value="1" @checked(request()->boolean('reversed_only'))
                       class="rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
                Only reversed
            </label>

            <label class="flex items-end gap-2 text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="adjustments_only" value="1" @checked(request()->boolean('adjustments_only'))
                       class="rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
                Only adjustments
            </label>

            <x-ui.form.input type="number" name="stale_days" label="Unapproved for over (days)"
                             :value="request('stale_days')" placeholder="7" min="1" />

            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.commissions.index', $pendingTab ? ['tab' => 'pending'] : [])">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <div x-data="commissionSelection()">
        <x-ui.card :title="$entries->total() . ' ' . \Illuminate\Support\Str::plural('entry', $entries->total())"
                   :subtitle="$range->label()">
            <x-ui.table :is-empty="$entries->isEmpty()">
                <x-slot:head>
                    @if ($canApprove || $canReject)
                        <th class="w-10 px-4 py-3">
                            {{-- The header checkbox selects the loaded page only, never "all matching": a
                                 bulk action must never cover rows nobody looked at. --}}
                            <input type="checkbox" x-on:change="toggleAll($event.target.checked)" :checked="allOnPageSelected()"
                                   aria-label="Select every entry on this page"
                                   class="rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
                        </th>
                    @endif
                    <th class="px-4 py-3 text-left font-semibold">Entry</th>
                    <th class="px-4 py-3 text-left font-semibold">Collaborator</th>
                    <th class="px-4 py-3 text-left font-semibold">Source</th>
                    <th class="px-4 py-3 text-left font-semibold">Base</th>
                    <th class="px-4 py-3 text-right font-semibold">Rate</th>
                    <th class="px-4 py-3 text-right font-semibold">Amount</th>
                    <th class="px-4 py-3 text-left font-semibold">Status</th>
                </x-slot:head>

                @foreach ($entries as $entry)
                    <tr>
                        @if ($canApprove || $canReject)
                            <td class="px-4 py-3">
                                @if ($entry->status->awaitsApproval() || $entry->status === \App\Enums\CommissionStatus::Available)
                                    <input type="checkbox" value="{{ $entry->id }}"
                                           x-model.number="selected"
                                           aria-label="Select {{ $entry->reference }}"
                                           class="rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
                                @endif
                            </td>
                        @endif

                        <td class="px-4 py-3">
                            <a href="{{ route('admin.commissions.show', $entry) }}"
                               class="block font-mono text-xs font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                                {{ $entry->reference }}
                            </a>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">
                                {{ app_date($entry->transaction_date) }}
                            </span>
                        </td>

                        <td class="px-4 py-3">
                            @if ($entry->collaborator)
                                <span class="block text-slate-900 dark:text-white">{{ $entry->collaborator->displayName() }}</span>
                                <span class="block font-mono text-xs text-slate-500 dark:text-slate-400">{{ $entry->collaborator->collaborator_code }}</span>
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>

                        <td class="px-4 py-3">
                            <x-ui.badge :color="$entry->purpose->color()" size="xs">{{ $entry->purpose->label() }}</x-ui.badge>
                            <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">{{ $entry->source_type->label() }}</span>
                        </td>

                        <td class="px-4 py-3">
                            <x-ui.badge :color="$entry->commission_base->color()" size="xs">{{ $entry->commission_base->label() }}</x-ui.badge>
                            <span class="mt-1 block text-xs tabular-nums text-slate-500 dark:text-slate-400">
                                on {{ money($entry->base_amount) }}
                            </span>
                        </td>

                        <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                            @if ($entry->commission_rate !== null)
                                {{ rtrim(rtrim((string) $entry->commission_rate, '0'), '.') }}%
                            @elseif ($entry->fixed_amount !== null)
                                {{ money($entry->fixed_amount) }}
                            @else
                                —
                            @endif
                        </td>

                        <td class="px-4 py-3 text-right">
                            {{-- `signed_amount` is the only column anything ever sums, so it is what the
                                 register shows: a debit reads as a debit without the reader doing arithmetic. --}}
                            <span @class([
                                'block font-semibold tabular-nums',
                                'text-rose-600 dark:text-rose-400' => str_starts_with((string) $entry->signed_amount, '-'),
                                'text-slate-900 dark:text-white' => ! str_starts_with((string) $entry->signed_amount, '-'),
                            ])>{{ money($entry->signed_amount) }}</span>

                            @if (bccomp((string) $entry->reversed_amount, '0.00', 2) === 1)
                                <span class="block text-xs text-amber-600 dark:text-amber-400">
                                    {{ money($entry->reversed_amount) }} reversed
                                </span>
                            @endif
                            @if (bccomp((string) $entry->clawed_back_amount, '0.00', 2) === 1)
                                <span class="block text-xs text-rose-600 dark:text-rose-400">
                                    {{ money($entry->clawed_back_amount) }} clawed back
                                </span>
                            @endif
                        </td>

                        <td class="px-4 py-3">
                            <x-ui.badge :color="$entry->status->color()" size="xs">{{ $entry->status->label() }}</x-ui.badge>
                            @if ($entry->hold_until !== null && $entry->status === \App\Enums\CommissionStatus::Approved)
                                <span class="block text-xs text-slate-500 dark:text-slate-400">
                                    held to {{ app_date($entry->hold_until) }}
                                </span>
                            @endif
                        </td>
                    </tr>
                @endforeach

                <x-slot:empty>
                    <x-ui.empty-state icon="calculator"
                        :title="$pendingTab ? 'Nothing waiting for approval' : 'No commissions in this range'"
                        :description="$pendingTab
                            ? 'Every entry in this range has been decided.'
                            : 'An entry appears when a receipt is recorded against a student a partner referred.'" />
                </x-slot:empty>
            </x-ui.table>

            @if ($entries->hasPages())
                <div class="mt-4">{{ $entries->links() }}</div>
            @endif
        </x-ui.card>

        @if ($canApprove || $canReject)
            {{-- The sticky bar names the count **and the total**, and the confirm dialog names it again:
                 approving forty rows is approving a figure, and the figure is what somebody should be
                 agreeing to. --}}
            <div x-show="selected.length > 0" x-cloak
                 class="sticky bottom-4 z-20 mt-4 flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-white/95 p-4 shadow-lg backdrop-blur dark:border-slate-700 dark:bg-slate-900/95">
                <span class="text-sm font-medium text-slate-900 dark:text-white">
                    <span x-text="selected.length"></span> selected ·
                    <span class="tabular-nums" x-text="formattedTotal()"></span>
                </span>

                <div class="ml-auto flex gap-2">
                    <x-ui.button variant="ghost" type="button" x-on:click="selected = []">Clear</x-ui.button>

                    @if ($canReject)
                        <x-ui.button variant="danger" type="button"
                                     x-on:click="$dispatch('open-modal', 'commission-bulk-reject')">Reject</x-ui.button>
                    @endif

                    @if ($canApprove)
                        <form method="POST" action="{{ route('admin.commissions.bulk-approve') }}"
                              x-on:submit="if (! confirm('Approve ' + selected.length + ' entries, totalling ' + formattedTotal() + '?')) $event.preventDefault()">
                            @csrf
                            <template x-for="id in selected" :key="id">
                                <input type="hidden" name="entries[]" :value="id">
                            </template>
                            <x-ui.button variant="primary" type="submit" icon="check">Approve</x-ui.button>
                        </form>
                    @endif
                </div>
            </div>

            @if ($canReject)
                <x-ui.modal name="commission-bulk-reject" title="Reject the selected commissions">
                    <form method="POST" action="{{ route('admin.commissions.bulk-reject') }}">
                        @csrf
                        <template x-for="id in selected" :key="id">
                            <input type="hidden" name="entries[]" :value="id">
                        </template>

                        <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">
                            The amount leaves every bucket and the rows stay, each with this reason on it.
                            A partner who asks why they were not paid is shown exactly this.
                        </p>

                        <x-ui.form.textarea name="reason" label="Reason" required rows="3"
                                            placeholder="Why these entries should not be paid" />

                        <div class="mt-4 flex justify-end gap-2">
                            <x-ui.button variant="ghost" type="button" x-on:click="$dispatch('close-modal', 'commission-bulk-reject')">Cancel</x-ui.button>
                            <x-ui.button variant="danger" type="submit">Reject <span x-text="selected.length"></span></x-ui.button>
                        </div>
                    </form>
                </x-ui.modal>
            @endif
        @endif
    </div>

    @can('collaborator_commissions.create')
        <x-ui.modal name="commission-adjustment" title="Manual adjustment">
            <form method="POST" action="{{ route('admin.commissions.adjustments.store') }}">
                @csrf

                <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">
                    A figure posted by hand — a goodwill credit, a correction, or a write-off of money the
                    business has decided not to chase. It is still an <strong>append</strong>: nothing
                    already in the ledger changes.
                </p>

                <x-ui.form.select name="collaborator_id" label="Collaborator" required placeholder="Choose a partner">
                    @foreach ($collaborators as $collaborator)
                        <option value="{{ $collaborator->id }}">
                            {{ $collaborator->company_name ?: $collaborator->name }} ({{ $collaborator->collaborator_code }})
                        </option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.input name="amount" label="Amount" required placeholder="250.00 — or -100.00 to take it back"
                                 help="Positive credits the partner, negative debits them." />

                <x-ui.form.textarea name="reason" label="Reason" required rows="3"
                                    placeholder="What this adjustment is for" />

                @can('collaborator_commissions.approve')
                    <label class="mt-3 flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <input type="checkbox" name="write_off" value="1"
                               class="mt-1 rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
                        <span>
                            <span class="font-medium text-slate-900 dark:text-white">Write-off</span>
                            <span class="block text-xs">The business gives up recovering a clawback. Notified and audited.</span>
                        </span>
                    </label>
                @endcan

                <div class="mt-4 flex justify-end gap-2">
                    <x-ui.button variant="ghost" type="button" x-on:click="$dispatch('close-modal', 'commission-adjustment')">Cancel</x-ui.button>
                    <x-ui.button variant="primary" type="submit">Post adjustment</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endcan
@endsection

@push('scripts')
    <script nonce="{{ csp_nonce() }}">
        function commissionSelection() {
            return {
                selected: [],
                amounts: @json($entries->mapWithKeys(fn ($e) => [$e->id => (string) $e->amount])),
                pageIds: @json($entries->pluck('id')),

                toggleAll(checked) {
                    // The loaded page only. "Select all matching" is deliberately absent — see the
                    // comment on the header checkbox.
                    this.selected = checked ? [...this.pageIds] : [];
                },

                allOnPageSelected() {
                    return this.pageIds.length > 0 && this.pageIds.every((id) => this.selected.includes(id));
                },

                total() {
                    return this.selected.reduce((sum, id) => sum + parseFloat(this.amounts[id] ?? '0'), 0);
                },

                formattedTotal() {
                    return '{{ \App\Support\Money::symbol() }} ' + this.total().toLocaleString(undefined, {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
                    });
                },
            };
        }
    </script>
@endpush
