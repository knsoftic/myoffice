@extends('layouts.admin')

@section('title', $advance->advance_number)

@php
    $user = auth()->user();
    $canApprove = $user?->can('approve', $advance) === true;
    $canReject = $user?->can('reject', $advance) === true;
    $canChange = $user?->can('changeStatus', $advance) === true;
    $canWaive = $user?->can('waive', $advance) === true;
    $status = $advance->status->value;
@endphp

@section('header')
    <x-ui.page-header :title="$advance->advance_number" :subtitle="$advance->employee?->name" icon="credit-card">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.advances.index')">Back to advances</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="The advance">
                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Status</dt>
                        <dd class="mt-1"><x-ui.badge :color="$advance->status->color()" size="xs">{{ $advance->status->label() }}</x-ui.badge></dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Amount</dt>
                        <dd class="mt-1 text-sm tabular-nums font-semibold text-slate-900 dark:text-white">{{ money((string) $advance->amount) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Outstanding</dt>
                        <dd class="mt-1 text-sm tabular-nums font-semibold text-slate-900 dark:text-white">{{ money((string) $advance->outstanding_amount) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Installment</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">
                            {{ money((string) $advance->installment_amount) }} × {{ $advance->installment_count }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Recovery starts</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-white">
                            {{ $advance->first_recovery_year ? app_date(\Illuminate\Support\Carbon::create((int) $advance->first_recovery_year, (int) $advance->first_recovery_month, 1), 'F Y') : '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Disbursed</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-white">
                            {{ $advance->disbursed_on ? app_date($advance->disbursed_on) : 'not yet' }}
                        </dd>
                    </div>
                    <div class="sm:col-span-3">
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Reason</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $advance->reason }}</dd>
                    </div>
                </dl>
            </x-ui.card>

            <x-ui.card title="Repayments" subtitle="Append-only: a reversal is a credit entry, never an edit.">
                <x-ui.table :is-empty="$advance->repayments->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Date</th>
                        <th class="px-4 py-3 text-left font-semibold">How</th>
                        <th class="px-4 py-3 text-right font-semibold">Amount</th>
                        <th class="px-4 py-3 text-left font-semibold">Note</th>
                    </x-slot:head>

                    @foreach ($advance->repayments->sortByDesc('recovered_on') as $repayment)
                        <tr>
                            <td class="px-4 py-3 tabular-nums text-slate-600 dark:text-slate-300">{{ app_date($repayment->recovered_on) }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                                {{ $repayment->recovery_type->label() }}
                                @if ($repayment->payrollItem)
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $repayment->payrollItem->slip_number }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums {{ (float) $repayment->signed_amount < 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-900 dark:text-white' }}">
                                {{ money((string) $repayment->signed_amount) }}
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400">
                                {{ $repayment->notes }}
                                @if ($repayment->performer) · {{ $repayment->performer->name }} @endif
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="banknotes" title="Nothing recovered yet"
                            description="A payroll recovery is posted when the salary slip is paid, not when it is generated." />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>
        </div>

        <div class="space-y-4">
            @if ($status === 'requested' && ($canApprove || $canReject))
                <x-ui.card title="Decide">
                    @if ($canApprove)
                        <form method="POST" action="{{ route('admin.advances.approve', $advance) }}">
                            @csrf
                            <x-ui.button type="submit" class="w-full" icon="check">Approve</x-ui.button>
                        </form>
                    @endif
                    @if ($canReject)
                        <form method="POST" action="{{ route('admin.advances.reject', $advance) }}" class="mt-4 space-y-3 border-t border-slate-200 pt-4 dark:border-slate-700">
                            @csrf
                            <x-ui.form.textarea name="reason" label="Reason for refusing" rows="2" required />
                            <x-ui.button type="submit" variant="danger" class="w-full">Refuse</x-ui.button>
                        </form>
                    @endif
                </x-ui.card>
            @endif

            @if ($status === 'approved' && $canChange)
                <x-ui.card title="Disburse">
                    <form method="POST" action="{{ route('admin.advances.disburse', $advance) }}" class="space-y-3">
                        @csrf
                        <x-ui.form.select name="disbursement_method" label="How" :options="$methods" selected="cash" required />
                        <x-ui.form.input name="disbursement_reference" label="Reference" maxlength="64"
                            help="Required for everything except cash — it is how this is found in a bank statement." />
                        <x-ui.form.input type="date" name="disbursed_on" label="On" :value="now()->toDateString()" />
                        <x-ui.button type="submit" class="w-full">Record the disbursement</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            @if (in_array($status, ['disbursed', 'recovering'], true) && $canChange)
                <x-ui.card title="Record a repayment" subtitle="Money paid back outside payroll.">
                    <form method="POST" action="{{ route('admin.advances.recovery', $advance) }}" class="space-y-3">
                        @csrf
                        <x-ui.form.input type="number" step="0.01" min="0.01" name="amount" label="Amount" required />
                        <x-ui.form.input type="date" name="recovered_on" label="On" :value="now()->toDateString()" />
                        <x-ui.form.input name="notes" label="Note" maxlength="255" />
                        <x-ui.button type="submit" variant="secondary" class="w-full">Record</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            @if (in_array($status, ['disbursed', 'recovering'], true) && $canWaive)
                <x-ui.card title="Waive">
                    <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">
                        Forgiving part of what is owed. The advance still says what was lent.
                    </p>
                    <form method="POST" action="{{ route('admin.advances.waive', $advance) }}" class="space-y-3">
                        @csrf
                        <x-ui.form.input type="number" step="0.01" min="0.01" name="amount" label="Amount to waive" required
                            :value="(string) $advance->outstanding_amount" />
                        <x-ui.form.textarea name="reason" label="Why" rows="2" required />
                        <x-ui.button type="submit" variant="secondary" class="w-full">Post the waiver</x-ui.button>
                    </form>
                </x-ui.card>
            @endif
        </div>
    </div>
@endsection
