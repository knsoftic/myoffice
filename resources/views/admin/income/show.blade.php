@extends('layouts.admin')

@section('title', $income->income_no)

@php
    $isVoided = $income->status === \App\Enums\IncomeStatus::Voided;
    $refundable = $income->refundableAmount();
    $canRefund = $canChangeStatus && ! $isVoided && bccomp($refundable, '0.00', 2) === 1;
@endphp

@section('header')
    <x-ui.page-header :title="$income->income_no"
                      :subtitle="$income->title"
                      icon="arrow-trending-up"
                      :badge="$income->status->label()"
                      :badge-color="$income->status->color()"
                      :back="route('admin.income.index')">
        <x-slot:actions>
            @if ($income->receipt_path)
                @can('income.download')
                    <x-ui.button variant="secondary" icon="paper-clip"
                                 :href="route('admin.income.receipt', $income)">Receipt</x-ui.button>
                @endcan
            @endif

            @if ($canEdit && ! $isVoided)
                <x-ui.button variant="secondary" icon="pencil" :href="route('admin.income.edit', $income)">Edit</x-ui.button>
            @endif

            @if ($canRefund)
                <x-ui.button variant="secondary" icon="arrow-uturn-left"
                             x-on:click.prevent="$dispatch('open-modal', 'refund-income')">Record a refund</x-ui.button>
            @endif

            @if ($canChangeStatus && ! $isVoided)
                <x-ui.button variant="danger" icon="no-symbol"
                             x-on:click.prevent="$dispatch('open-modal', 'void-income')">Void</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($isVoided)
        <div class="mb-4 rounded-lg border border-slate-300 bg-slate-100 p-4 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300">
            <p class="font-semibold">Voided on {{ app_date($income->voided_at) }}{{ $income->voider ? ' by '.$income->voider->name : '' }}.</p>
            <p class="mt-1">{{ $income->void_reason }}</p>
            <p class="mt-1">It is out of the income report. The row stays where it is.</p>
        </div>
    @endif

    @if ($fields->seesMoney)
        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <x-ui.stat-card label="Amount" :value="money($income->amount)" icon="banknotes" color="slate" />
            <x-ui.stat-card label="Refunded" :value="money($income->refunded_amount)" icon="arrow-uturn-left"
                            :color="bccomp((string) $income->refunded_amount, '0.00', 2) === 1 ? 'amber' : 'slate'" />
            <x-ui.stat-card label="Net received" :value="money($income->net_amount)" icon="calculator"
                            color="emerald" delta-label="what every report sums" />
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="Details">
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Category</dt>
                        <dd class="text-slate-900 dark:text-white">{{ $income->category?->name ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Business</dt>
                        <dd><x-ui.badge :color="$income->context->color()" size="xs">{{ $income->context->label() }}</x-ui.badge></dd>
                    </div>
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Received from</dt>
                        <dd class="text-slate-900 dark:text-white">{{ $income->received_from ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Client</dt>
                        <dd class="text-slate-900 dark:text-white">
                            {{ $income->client ? ($income->client->company_name ?: $income->client->name) : '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Project</dt>
                        <dd class="text-slate-900 dark:text-white">
                            {{ $income->project ? $income->project->code.' — '.$income->project->name : '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Received on</dt>
                        <dd class="text-slate-900 dark:text-white">{{ app_date($income->received_on) }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Instrument</dt>
                        <dd class="text-slate-900 dark:text-white">
                            {{ $income->payment_method->label() }}
                            @if ($income->paymentMethod)
                                <span class="text-slate-500 dark:text-slate-400">({{ $income->paymentMethod->name }})</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Reference</dt>
                        <dd class="font-mono text-xs text-slate-900 dark:text-white">{{ $income->reference_no ?: '—' }}</dd>
                    </div>
                    @if (filled($income->description))
                        <div class="sm:col-span-2">
                            <dt class="text-slate-500 dark:text-slate-400">Description</dt>
                            <dd class="whitespace-pre-line text-slate-900 dark:text-white">{{ $income->description }}</dd>
                        </div>
                    @endif
                    @if (filled($income->notes))
                        <div class="sm:col-span-2">
                            <dt class="text-slate-500 dark:text-slate-400">Note</dt>
                            <dd class="text-slate-700 dark:text-slate-200">{{ $income->notes }}</dd>
                        </div>
                    @endif
                </dl>
            </x-ui.card>

            <x-ui.card title="Refunds"
                       subtitle="Append-only. Money that went back is recorded beside what came in, never subtracted from it.">
                <x-ui.table :is-empty="$income->reversals->isEmpty()">
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

                    @foreach ($income->reversals as $reversal)
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
                                          title="Nothing went back"
                                          message="A refund is recorded here and the income report picks it up through the net column." />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>
        </div>

        <div class="space-y-4">
            <x-ui.card title="History">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Recorded</dt>
                        <dd class="text-right text-slate-900 dark:text-white">{{ app_datetime($income->recorded_at) }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Created</dt>
                        <dd class="text-right text-slate-900 dark:text-white">{{ app_datetime($income->created_at) }}</dd>
                    </div>
                </dl>
            </x-ui.card>

            @if ($income->corrects)
                <div class="rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900 dark:border-sky-900/50 dark:bg-sky-950/40 dark:text-sky-200">
                    This corrects
                    <a href="{{ route('admin.income.show', $income->corrects) }}" class="font-semibold underline">
                        {{ $income->corrects->income_no }}
                    </a>.
                </div>
            @endif
        </div>
    </div>

    @if ($canChangeStatus && ! $isVoided)
        <x-ui.modal name="void-income" title="Void this entry" icon="no-symbol">
            <form method="POST" action="{{ route('admin.income.void', $income) }}" class="space-y-4">
                @csrf
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    Voiding takes a figure out of the income report. If the money genuinely went back to
                    the payer, record a refund instead — that keeps both halves of the story.
                </p>
                <x-ui.form.textarea name="reason" label="Reason" required rows="3" />
                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'void-income')">Keep it</x-ui.button>
                    <x-ui.button type="submit" variant="danger">Void it</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    @if ($canRefund)
        <x-ui.modal name="refund-income" title="Record a refund" icon="arrow-uturn-left">
            <form method="POST" action="{{ route('admin.income.reversals.store', $income) }}" class="space-y-4">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::ulid() }}">

                <p class="text-sm text-slate-600 dark:text-slate-300">
                    Up to {{ money($refundable) }} may still go back. It is appended beside the entry; the
                    original amount is never edited.
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.form.input type="number" step="0.01" min="0.01" :max="$refundable"
                                     name="amount" label="Amount" required />

                    <x-ui.form.select name="type" label="Type" required>
                        <option value="partial_refund">Partial refund</option>
                        <option value="full_refund">Full refund</option>
                        <option value="correction">Correction</option>
                    </x-ui.form.select>

                    <x-ui.form.select name="refund_method" label="Back by" placeholder="Same as the receipt">
                        @foreach ($refundMethods as $method)
                            <option value="{{ $method->value }}">{{ $method->label() }}</option>
                        @endforeach
                    </x-ui.form.select>

                    <x-ui.form.input type="date" name="occurred_on" label="Date" :value="app_date(now(), 'Y-m-d')" />

                    <div class="sm:col-span-2">
                        <x-ui.form.input name="reference_no" label="Reference" maxlength="64" />
                    </div>

                    <div class="sm:col-span-2">
                        <x-ui.form.textarea name="reason" label="Reason" required rows="2" />
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'refund-income')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary">Record it</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
@endsection
