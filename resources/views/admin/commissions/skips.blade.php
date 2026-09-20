@extends('layouts.admin')

@section('title', 'Commission skips')

@section('header')
    <x-ui.page-header title="Receipts that earned nothing"
                      subtitle="Grouped so the pattern is visible — this is how a misconfigured partner is found before they complain."
                      icon="exclamation-triangle">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.commissions.index')" icon="arrow-left">Commissions</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.form.input type="date" name="from" label="From" :value="request('from', app_date($range->start(), 'Y-m-d'))" />
            <x-ui.form.input type="date" name="to" label="To" :value="request('to', app_date($range->end(), 'Y-m-d'))" />

            <x-ui.form.select name="collaborator" label="Collaborator" placeholder="Everybody">
                @foreach ($collaborators as $collaborator)
                    <option value="{{ $collaborator->id }}" @selected(request('collaborator') == $collaborator->id)>
                        {{ $collaborator->company_name ?: $collaborator->name }} ({{ $collaborator->collaborator_code }})
                    </option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="reason" label="Reason" placeholder="Every reason">
                @foreach ($reasons as $reason)
                    <option value="{{ $reason->value }}" @selected(request('reason') === $reason->value)>{{ $reason->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.commission-skips.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card class="mb-4" title="Why nothing was earned" :subtitle="$range->label()">
        @if ($groups->isEmpty())
            <x-ui.empty-state icon="check-circle" title="Every receipt in this range was evaluated"
                description="Nothing was skipped. A skip is a decision the engine recorded, not a failure — when one appears it will say exactly why." />
        @else
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($groups as $group)
                    @php $reason = \App\Enums\CommissionSkipReason::tryFrom((string) $group->commission_skip_reason); @endphp
                    <a href="{{ route('admin.commission-skips.index', ['reason' => $group->commission_skip_reason] + request()->query()) }}"
                       class="rounded-xl border border-slate-200 p-4 transition hover:border-brand-400 dark:border-slate-700 dark:hover:border-brand-500">
                        <x-ui.badge :color="$reason?->color() ?? 'slate'" size="xs">{{ $reason?->label() ?? $group->commission_skip_reason }}</x-ui.badge>
                        <p class="mt-2 text-2xl font-semibold tabular-nums text-slate-900 dark:text-white">{{ $group->receipts }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            {{ \Illuminate\Support\Str::plural('receipt', (int) $group->receipts) }} ·
                            {{ money((string) $group->total) }} received
                        </p>
                        @if ($reason?->needsAttention())
                            <p class="mt-2 text-xs font-medium text-amber-600 dark:text-amber-400">Somebody should look at this</p>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif
    </x-ui.card>

    <form method="POST" action="{{ route('admin.commissions.evaluate') }}" x-data="{ chosen: [] }">
        @csrf

        <x-ui.card :title="$receipts->total() . ' ' . \Illuminate\Support\Str::plural('receipt', $receipts->total())"
                   subtitle="Each one took money and produced no commission.">
            <x-ui.table :is-empty="$receipts->isEmpty()">
                <x-slot:head>
                    @if ($canEvaluate)
                        <th class="w-10 px-4 py-3"></th>
                    @endif
                    <th class="px-4 py-3 text-left font-semibold">Receipt</th>
                    <th class="px-4 py-3 text-left font-semibold">Charge</th>
                    <th class="px-4 py-3 text-right font-semibold">Amount</th>
                    <th class="px-4 py-3 text-left font-semibold">Partner</th>
                    <th class="px-4 py-3 text-left font-semibold">Why nothing was earned</th>
                </x-slot:head>

                @foreach ($receipts as $receipt)
                    <tr>
                        @if ($canEvaluate)
                            <td class="px-4 py-3">
                                <input type="checkbox" name="payments[]" value="{{ $receipt->id }}" x-model.number="chosen"
                                       aria-label="Re-evaluate {{ $receipt->receipt_no }}"
                                       class="rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
                            </td>
                        @endif
                        <td class="px-4 py-3">
                            <span class="block font-mono text-xs font-semibold text-slate-900 dark:text-white">{{ $receipt->receipt_no }}</span>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ app_date($receipt->paid_on) }}</span>
                        </td>
                        <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                            <span class="block font-mono text-xs">{{ $receipt->fee?->fee_number }}</span>
                            <span class="block text-xs text-slate-500">{{ $receipt->fee?->fee_type?->label() }}</span>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-slate-900 dark:text-white">{{ money($receipt->amount) }}</td>
                        <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                            {{ $receipt->collaborator?->displayName() ?? '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <x-ui.badge :color="$receipt->commission_skip_reason?->color() ?? 'slate'" size="xs">
                                {{ $receipt->commission_skip_reason?->label() }}
                            </x-ui.badge>
                            <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">
                                {{ $receipt->commission_skip_detail }}
                            </span>
                        </td>
                    </tr>
                @endforeach

                <x-slot:empty>
                    <x-ui.empty-state icon="check-circle" title="Nothing was skipped in this range" />
                </x-slot:empty>
            </x-ui.table>

            @if ($receipts->hasPages())
                <div class="mt-4">{{ $receipts->links() }}</div>
            @endif
        </x-ui.card>

        @if ($canEvaluate)
            {{-- A skip is a decision, not a failure, so the sweeper never undoes one. This does — for
                 exactly the receipts somebody ticked, with a reason. That is what stops a reinstated
                 partner being silently back-paid. --}}
            <div x-show="chosen.length > 0" x-cloak
                 class="sticky bottom-4 z-20 mt-4 rounded-xl border border-slate-200 bg-white/95 p-4 shadow-lg backdrop-blur dark:border-slate-700 dark:bg-slate-900/95">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-[16rem]">
                        <x-ui.form.input name="reason" label="Why these should be re-evaluated" required
                                         placeholder="e.g. partner reinstated on 4 March; rule added for them today" />
                    </div>
                    <span class="text-sm text-slate-600 dark:text-slate-300">
                        <span x-text="chosen.length"></span> selected
                    </span>
                    <x-ui.button variant="primary" type="submit" icon="arrow-path">Evaluate selected</x-ui.button>
                </div>
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                    Re-evaluation is audited and cannot pay twice: the same receipt can only ever produce
                    one commission entry.
                </p>
            </div>
        @endif
    </form>
@endsection
