@php
    use App\Support\Money;

    $live = $installments->reject(fn ($l) => $l->status === \App\Enums\InstallmentStatus::Cancelled);
    $scheduled = Money::sum($live->map(fn ($l): string => (string) $l->amount)->all());
    $waived = Money::sum($live->map(fn ($l): string => (string) $l->waived_amount)->all());
    $allocated = Money::sum($live->map(fn ($l): string => (string) $l->paid_amount)->all());
    $unallocated = Money::sub($charge->netReceived(), $allocated);
@endphp

<x-ui.card :padded="false" title="Installment plan"
           :subtitle="$live->isEmpty() ? 'This charge is payable in full.' : 'Numbers are never reused — a rebuilt plan reads 1, 2, 5, 6, so \'the third installment\' keeps meaning one thing.'">
    <x-slot:actions>
        @if ($live->isEmpty())
            @can('installments.create')
                <x-ui.button size="sm" icon="calendar-days" x-on:click="$dispatch('open-modal', 'build-plan')">Build plan</x-ui.button>
            @endcan
        @else
            @can('installments.edit')
                <x-ui.button size="sm" variant="ghost" icon="arrow-path" x-on:click="$dispatch('open-modal', 'rebuild-plan')">Rebuild</x-ui.button>
            @endcan
        @endif
    </x-slot:actions>

    {{-- An unallocated strip, not a silent difference. Money can legitimately arrive against the
         charge rather than a line, and a schedule that does not mention it looks like it lost some. --}}
    @if (! $live->isEmpty() && Money::compare($unallocated, '0.00') !== 0)
        <div class="border-b border-amber-200/60 bg-amber-50/60 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/5 dark:text-amber-300">
            {{ money(Money::abs($unallocated)) }}
            {{ Money::isPositive($unallocated) ? 'has been received against this charge without being allocated to a line.' : 'more has been allocated to lines than the charge has received.' }}
        </div>
    @endif

    <x-ui.table :is-empty="$installments->isEmpty()">
        <x-slot:head>
            <th class="px-4 py-3 text-left font-semibold">#</th>
            <th class="px-4 py-3 text-left font-semibold">Due</th>
            <th class="px-4 py-3 text-right font-semibold">Amount</th>
            <th class="px-4 py-3 text-right font-semibold">Paid</th>
            <th class="px-4 py-3 text-right font-semibold">Waived</th>
            <th class="px-4 py-3 text-left font-semibold">Status</th>
            <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
        </x-slot:head>

        @foreach ($installments as $line)
            @php($over = Money::compare((string) $line->paid_amount, (string) $line->amount) === 1)
            <tr @class(['opacity-55' => $line->status === \App\Enums\InstallmentStatus::Cancelled])>
                <td class="px-4 py-3 font-medium text-slate-700 dark:text-slate-200">{{ app_number($line->installment_no) }}</td>
                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ app_date($line->due_date) }}</td>
                <td class="px-4 py-3 text-right tabular-nums text-slate-700 dark:text-slate-200">{{ money($line->amount) }}</td>
                <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                    {{ money($line->paid_amount) }}
                    {{-- The database deliberately permits this (spine §2.3), so the screen explains it
                         rather than hiding it. --}}
                    @if ($over)
                        <div class="mt-0.5"><x-ui.badge color="amber" size="xs">over-allocated</x-ui.badge></div>
                    @endif
                </td>
                <td class="px-4 py-3 text-right tabular-nums text-slate-500">
                    {{ (float) $line->waived_amount > 0 ? money($line->waived_amount) : '—' }}
                </td>
                <td class="px-4 py-3"><x-ui.badge :color="$line->status->color()" size="xs">{{ $line->status->label() }}</x-ui.badge></td>
                <td class="px-4 py-3 text-right">
                    @if ($line->status->isOpen() && (float) $line->remaining() > 0)
                        @can('changeStatus', $line)
                            <x-ui.button size="sm" variant="ghost" icon="minus-circle"
                                         x-on:click="$dispatch('open-modal', 'waive-{{ $line->id }}')">Waive</x-ui.button>
                        @endcan
                    @endif
                </td>
            </tr>
        @endforeach

        <x-slot:footer>
            @if (! $live->isEmpty())
                {{-- **PI-1, printed.** This is the invariant the whole plan machinery exists to keep,
                     and a user who can see it is a user who can tell you the day it breaks. --}}
                <div class="border-t border-slate-200/70 px-4 py-3 text-xs dark:border-slate-800">
                    <span class="text-slate-400">Live lines</span>
                    <span class="tabular-nums text-slate-600 dark:text-slate-300">{{ money($scheduled) }}</span>
                    <span class="text-slate-400">− waived</span>
                    <span class="tabular-nums text-slate-600 dark:text-slate-300">{{ money($waived) }}</span>
                    <span class="text-slate-400">=</span>
                    <span class="tabular-nums font-medium {{ Money::compare(Money::sub($scheduled, $waived), (string) $charge->net_amount) === 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                        {{ money(Money::sub($scheduled, $waived)) }}
                    </span>
                    <span class="text-slate-400">against a net fee of</span>
                    <span class="tabular-nums text-slate-600 dark:text-slate-300">{{ money($charge->net_amount) }}</span>
                </div>
            @endif
        </x-slot:footer>

        <x-slot:empty>
            <x-ui.empty-state icon="calendar-days" title="No plan — this charge is payable in full"
                              description="A plan can only be built before any money is received on the charge.">
                @can('installments.create')
                    @if (Money::isZero($charge->netReceived()))
                        <x-ui.button icon="calendar-days" x-on:click="$dispatch('open-modal', 'build-plan')">Build plan</x-ui.button>
                    @else
                        <x-ui.form.help>
                            {{ money($charge->netReceived()) }} has already been received, so a plan cannot be
                            built on this charge. Collect the remainder as it comes, or void the receipt and start again.
                        </x-ui.form.help>
                    @endif
                @endcan
            </x-ui.empty-state>
        </x-slot:empty>
    </x-ui.table>
</x-ui.card>

@foreach ($installments as $line)
    @if ($line->status->isOpen() && (float) $line->remaining() > 0)
        @can('changeStatus', $line)
            <x-ui.modal name="waive-{{ $line->id }}" :title="'Waive installment '.$line->installment_no.'?'" icon="minus-circle">
                <form method="POST" action="{{ route('admin.installments.waive', $line) }}" class="space-y-4">
                    @csrf
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        This reduces the net fee by the same amount, so the plan stays in balance — a
                        waiver moves both sides of the equation at once, which is why it needs no
                        redistribution.
                    </p>
                    <x-ui.form.input name="amount" label="Amount to waive" type="number" step="0.01" min="0.01"
                                     :max="$line->remaining()" :value="$line->remaining()"
                                     :help="money($line->remaining()).' is still outstanding on this installment.'" />
                    <x-ui.form.textarea name="reason" label="Why?" required rows="2" />
                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'waive-{{ $line->id }}')">Cancel</x-ui.button>
                        <x-ui.button type="submit" variant="primary">Waive</x-ui.button>
                    </div>
                </form>
            </x-ui.modal>
        @endcan
    @endif
@endforeach

@include('admin.student-fees._plan-modal', ['charge' => $charge, 'isRebuild' => ! $live->isEmpty()])
