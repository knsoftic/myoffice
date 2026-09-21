@extends('layouts.admin')

@section('title', $expense->expense_no)

@php
    $isPending = $expense->status === \App\Enums\ExpenseStatus::Pending;
    $isVoided = $expense->status === \App\Enums\ExpenseStatus::Voided;
    $isOwn = $expense->created_by !== null && (int) $expense->created_by === (int) auth()->id();
    $refundable = $expense->refundableAmount();
    $canRefund = $canChangeStatus
        && $expense->status === \App\Enums\ExpenseStatus::Approved
        && bccomp($refundable, '0.00', 2) === 1;
@endphp

@section('header')
    <x-ui.page-header :title="$expense->expense_no"
                      :subtitle="$expense->title"
                      icon="banknotes"
                      :badge="$expense->status->label()"
                      :badge-color="$expense->status->color()"
                      :back="route('admin.expenses.index')">
        <x-slot:actions>
            @if ($expense->receipt_path)
                @can('expenses.download')
                    <x-ui.button variant="secondary" icon="paper-clip"
                                 :href="route('admin.expenses.receipt', $expense)">Receipt</x-ui.button>
                @endcan
            @endif

            @if ($canEdit && ! $expense->isDerived() && ! $isVoided)
                <x-ui.button variant="secondary" icon="pencil" :href="route('admin.expenses.edit', $expense)">Edit</x-ui.button>
            @endif

            @if ($canApprove && $isPending && ! $isOwn)
                <form method="POST" action="{{ route('admin.expenses.approve', $expense) }}">
                    @csrf
                    <x-ui.button type="submit" variant="primary" icon="check">Approve</x-ui.button>
                </form>
            @endif

            @if ($canReject && $isPending)
                <x-ui.button variant="secondary" icon="x-mark"
                             x-on:click.prevent="$dispatch('open-modal', 'reject-expense')">Reject</x-ui.button>
            @endif

            @if ($canRefund)
                <x-ui.button variant="secondary" icon="arrow-uturn-left"
                             x-on:click.prevent="$dispatch('open-modal', 'refund-expense')">Record a refund</x-ui.button>
            @endif

            @if ($canChangeStatus && ! $isVoided && $expense->status !== \App\Enums\ExpenseStatus::Rejected)
                <x-ui.button variant="danger" icon="no-symbol"
                             x-on:click.prevent="$dispatch('open-modal', 'void-expense')">Void</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($isVoided)
        <div class="mb-4 rounded-lg border border-slate-300 bg-slate-100 p-4 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300">
            <p class="font-semibold">Voided on {{ app_date($expense->voided_at) }}{{ $expense->voider ? ' by '.$expense->voider->name : '' }}.</p>
            <p class="mt-1">{{ $expense->void_reason }}</p>
            <p class="mt-1">It is out of every report. The correcting entry, if there is one, links back to this row.</p>
        </div>
    @endif

    @if ($expense->status === \App\Enums\ExpenseStatus::Rejected)
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-200">
            <p class="font-semibold">Rejected on {{ app_date($expense->rejected_at) }}{{ $expense->rejecter ? ' by '.$expense->rejecter->name : '' }}.</p>
            <p class="mt-1">{{ $expense->rejection_reason }}</p>
        </div>
    @endif

    @if ($isPending)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200">
            Waiting for approval, so it counts in no report yet.
            @if ($isOwn)
                You entered this claim — somebody else has to agree it.
            @endif
        </div>
    @endif

    @if ($expense->isDerived())
        <div class="mb-4 rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-900/50 dark:bg-sky-950/40 dark:text-sky-200">
            This row was derived from <strong>{{ str_replace('_', ' ', (string) $expense->source_type) }}</strong>
            and is not edited here. Correcting it means correcting the thing it came from.
        </div>
    @endif

    @if ($fields->seesMoney)
        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <x-ui.stat-card label="Amount" :value="money($expense->amount)" icon="banknotes" color="slate" />
            <x-ui.stat-card label="Refunded" :value="money($expense->refunded_amount)" icon="arrow-uturn-left"
                            :color="bccomp((string) $expense->refunded_amount, '0.00', 2) === 1 ? 'amber' : 'slate'" />
            <x-ui.stat-card label="Net cost" :value="money($expense->net_amount)" icon="calculator"
                            color="rose" delta-label="what every report sums" />
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="Details">
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Category</dt>
                        <dd class="text-slate-900 dark:text-white">{{ $expense->category?->name ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Business</dt>
                        <dd><x-ui.badge :color="$expense->context->color()" size="xs">{{ $expense->context->label() }}</x-ui.badge></dd>
                    </div>
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Paid to</dt>
                        <dd class="text-slate-900 dark:text-white">{{ $expense->paid_to ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Project</dt>
                        <dd class="text-slate-900 dark:text-white">
                            {{ $expense->project ? $expense->project->code.' — '.$expense->project->name : '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Date of the cost</dt>
                        <dd class="text-slate-900 dark:text-white">{{ app_date($expense->expense_date) }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Recorded</dt>
                        <dd class="text-slate-900 dark:text-white">{{ app_datetime($expense->recorded_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Instrument</dt>
                        <dd class="text-slate-900 dark:text-white">
                            {{ $expense->payment_method->label() }}
                            @if ($expense->paymentMethod)
                                <span class="text-slate-500 dark:text-slate-400">({{ $expense->paymentMethod->name }})</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Reference</dt>
                        <dd class="font-mono text-xs text-slate-900 dark:text-white">{{ $expense->reference_no ?: '—' }}</dd>
                    </div>
                    @if (filled($expense->description))
                        <div class="sm:col-span-2">
                            <dt class="text-slate-500 dark:text-slate-400">Description</dt>
                            <dd class="whitespace-pre-line text-slate-900 dark:text-white">{{ $expense->description }}</dd>
                        </div>
                    @endif
                    @if (filled($expense->notes))
                        <div class="sm:col-span-2">
                            <dt class="text-slate-500 dark:text-slate-400">Note</dt>
                            <dd class="text-slate-700 dark:text-slate-200">{{ $expense->notes }}</dd>
                        </div>
                    @endif
                </dl>
            </x-ui.card>

            <x-ui.card title="Refunds"
                       subtitle="Append-only. Money that came back is recorded beside the cost, never subtracted from it.">
                <x-ui.table :is-empty="$expense->reversals->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Reversal</th>
                        <th class="px-4 py-3 text-left font-semibold">Date</th>
                        <th class="px-4 py-3 text-left font-semibold">Type</th>
                        @if ($fields->seesMoney)
                            <th class="px-4 py-3 text-right font-semibold">Amount</th>
                        @endif
                        <th class="px-4 py-3 text-left font-semibold">Reason</th>
                        <th class="px-4 py-3 text-left font-semibold">By</th>
                    </x-slot:head>

                    @foreach ($expense->reversals as $reversal)
                        <tr>
                            <td class="px-4 py-3 font-mono text-xs text-slate-900 dark:text-white">{{ $reversal->reversal_no }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ app_date($reversal->occurred_on) }}</td>
                            <td class="px-4 py-3">
                                <x-ui.badge :color="$reversal->type->color()" size="xs">{{ $reversal->type->label() }}</x-ui.badge>
                            </td>
                            @if ($fields->seesMoney)
                                <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">
                                    {{ money($reversal->amount) }}
                                </td>
                            @endif
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $reversal->reason }}</td>
                            <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400">
                                {{ $reversal->performer?->name ?: $reversal->performed_by_name ?: '—' }}
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="arrow-uturn-left" compact
                                          title="Nothing came back"
                                          message="A refund is recorded here and every report picks it up through the net column." />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>
        </div>

        <div class="space-y-4">
            <x-ui.card title="The approval trail">
                <ol class="space-y-3 text-sm">
                    <li>
                        <span class="block text-slate-900 dark:text-white">Recorded</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">
                            {{ app_datetime($expense->created_at) }}
                        </span>
                    </li>
                    @if ($expense->approved_at)
                        <li>
                            <span class="block text-slate-900 dark:text-white">
                                Approved{{ $expense->approver ? ' by '.$expense->approver->name : '' }}
                            </span>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ app_datetime($expense->approved_at) }}</span>
                        </li>
                    @endif
                    @if ($expense->rejected_at)
                        <li>
                            <span class="block text-slate-900 dark:text-white">
                                Rejected{{ $expense->rejecter ? ' by '.$expense->rejecter->name : '' }}
                            </span>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ app_datetime($expense->rejected_at) }}</span>
                        </li>
                    @endif
                    @if ($expense->voided_at)
                        <li>
                            <span class="block text-slate-900 dark:text-white">
                                Voided{{ $expense->voider ? ' by '.$expense->voider->name : '' }}
                            </span>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ app_datetime($expense->voided_at) }}</span>
                        </li>
                    @endif
                    @unless ($expense->approval_required)
                        <li class="text-xs text-slate-500 dark:text-slate-400">
                            This one needed no approval under the rule in force when it was recorded.
                        </li>
                    @endunless
                </ol>
            </x-ui.card>

            @if ($expense->corrects)
                <div class="rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900 dark:border-sky-900/50 dark:bg-sky-950/40 dark:text-sky-200">
                    This corrects
                    <a href="{{ route('admin.expenses.show', $expense->corrects) }}" class="font-semibold underline">
                        {{ $expense->corrects->expense_no }}
                    </a>.
                </div>
            @endif

            @if ($expense->receipt_path)
                <x-ui.card title="Receipt">
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        Stored on the private disk. The download route re-runs the permission chain, so the
                        file is never reachable by guessing a URL.
                    </p>
                    @can('expenses.download')
                        <x-slot:footer>
                            <x-ui.button size="sm" variant="secondary" icon="arrow-down-tray"
                                         :href="route('admin.expenses.receipt', $expense)">Download</x-ui.button>
                        </x-slot:footer>
                    @endcan
                </x-ui.card>
            @endif
        </div>
    </div>

    @if ($canReject && $isPending)
        <x-ui.modal name="reject-expense" title="Reject this claim" icon="x-mark">
            <form method="POST" action="{{ route('admin.expenses.reject', $expense) }}" class="space-y-4">
                @csrf
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    The row stays with the reason on it — the person who claimed it is told what you wrote.
                </p>
                <x-ui.form.textarea name="reason" label="Reason" required rows="3" />
                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'reject-expense')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="danger">Reject it</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    @if ($canChangeStatus && ! $isVoided && $expense->status !== \App\Enums\ExpenseStatus::Rejected)
        <x-ui.modal name="void-expense" title="Void this expense" icon="no-symbol">
            <form method="POST" action="{{ route('admin.expenses.void', $expense) }}" class="space-y-4">
                @csrf
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    Voiding takes a figure out of a report somebody may already have read. The row stays
                    where it is with your reason on it; re-enter the correction and link it back to this one.
                </p>
                <x-ui.form.textarea name="reason" label="Reason" required rows="3" />
                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'void-expense')">Keep it</x-ui.button>
                    <x-ui.button type="submit" variant="danger">Void it</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    @if ($canRefund)
        <x-ui.modal name="refund-expense" title="Record a refund" icon="arrow-uturn-left">
            <form method="POST" action="{{ route('admin.expenses.reversals.store', $expense) }}" class="space-y-4">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::ulid() }}">

                <p class="text-sm text-slate-600 dark:text-slate-300">
                    Up to {{ money($refundable) }} may still come back. The refund is appended beside the
                    cost; the original amount is never edited.
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.form.input type="number" step="0.01" min="0.01" :max="$refundable"
                                     name="amount" label="Amount" required />

                    <x-ui.form.select name="type" label="Type" required>
                        <option value="partial_refund">Partial refund</option>
                        <option value="full_refund">Full refund</option>
                        <option value="correction">Correction</option>
                    </x-ui.form.select>

                    <x-ui.form.select name="refund_method" label="Back by" placeholder="Same as the payment">
                        @foreach ($refundMethods as $method)
                            <option value="{{ $method->value }}">{{ $method->label() }}</option>
                        @endforeach
                    </x-ui.form.select>

                    <x-ui.form.input type="date" name="occurred_on" label="Date"
                                     :value="app_date(now(), 'Y-m-d')" />

                    <div class="sm:col-span-2">
                        <x-ui.form.input name="reference_no" label="Reference" maxlength="64" />
                    </div>

                    <div class="sm:col-span-2">
                        <x-ui.form.textarea name="reason" label="Reason" required rows="2" />
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'refund-expense')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary">Record it</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
@endsection
