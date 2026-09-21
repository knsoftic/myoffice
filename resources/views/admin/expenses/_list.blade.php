{{--
    The expense table, shared by the register and the approval queue (phase-13 §8.7).

    One partial, because the two screens differ only in what they are narrowed to and whether the
    approve controls are on. Two copies would drift, and the copy that drifted would be the queue —
    the screen somebody works from every morning.

    Expects: $expenses (paginator) · $fields (FinanceFieldSet) · $canApprove · $showApprovals (bool) ·
             optionally $currentUserId.
--}}

@php
    $showApprovals = $showApprovals ?? false;
    $currentUserId = $currentUserId ?? auth()->id();
@endphp

<x-ui.table :is-empty="$expenses->isEmpty()"
            :selectable="$showApprovals && $canApprove"
            selection-label="expense">
    <x-slot:head>
        <th class="px-4 py-3 text-left font-semibold">Expense</th>
        <th class="px-4 py-3 text-left font-semibold">Category</th>
        <th class="px-4 py-3 text-left font-semibold">Paid to</th>
        <th class="px-4 py-3 text-left font-semibold">Date</th>
        @if ($fields->seesMoney)
            <th class="px-4 py-3 text-right font-semibold">Amount</th>
            <th class="px-4 py-3 text-right font-semibold">Refunded</th>
            <th class="px-4 py-3 text-right font-semibold">Net</th>
        @endif
        <th class="px-4 py-3 text-left font-semibold">Status</th>
        <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
    </x-slot:head>

    @if ($showApprovals && $canApprove)
        <x-slot:bulk>
            <form method="POST" action="{{ route('admin.expenses.bulk-approve') }}"
                  x-on:submit="if (! confirm('Approve ' + selected.length + ' expenses? They will count in every report from that moment.')) $event.preventDefault()">
                @csrf
                <template x-for="pickedId in selected" :key="pickedId">
                    <input type="hidden" name="ids[]" x-bind:value="pickedId">
                </template>
                <x-ui.button type="submit" size="sm" variant="primary">Approve selected</x-ui.button>
            </form>
        </x-slot:bulk>
    @endif

    @foreach ($expenses as $expense)
        @php
            $isOwn = $expense->created_by !== null && (int) $expense->created_by === (int) $currentUserId;
        @endphp
        <tr>
            @if ($showApprovals && $canApprove)
                <td class="px-4 py-3">
                    <input type="checkbox"
                           data-row-select
                           value="{{ $expense->id }}"
                           x-model="selected"
                           @disabled($isOwn)
                           aria-label="Select {{ $expense->expense_no }}"
                           class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 disabled:opacity-40 dark:border-slate-600">
                </td>
            @endif

            <td class="px-4 py-3">
                <a href="{{ route('admin.expenses.show', $expense) }}"
                   class="block font-mono text-xs font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                    {{ $expense->expense_no }}
                </a>
                <span class="block text-xs text-slate-500 dark:text-slate-400">
                    {{ \Illuminate\Support\Str::limit($expense->title, 44) }}
                </span>
            </td>

            <td class="px-4 py-3">
                <span class="block text-slate-900 dark:text-white">{{ $expense->category?->name ?: '—' }}</span>
                <x-ui.badge :color="$expense->context->color()" size="xs">{{ $expense->context->label() }}</x-ui.badge>
            </td>

            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                {{ $expense->paid_to ?: '—' }}
                @if ($expense->project)
                    <span class="block font-mono text-xs text-slate-500 dark:text-slate-400">{{ $expense->project->code }}</span>
                @endif
            </td>

            <td class="px-4 py-3 text-xs text-slate-600 dark:text-slate-300">
                <span class="block">{{ app_date($expense->expense_date) }}</span>
                <span class="block text-slate-500 dark:text-slate-400">{{ $expense->payment_method->label() }}</span>
            </td>

            @if ($fields->seesMoney)
                <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">
                    {{ money($expense->amount) }}
                </td>
                <td class="px-4 py-3 text-right tabular-nums text-slate-500 dark:text-slate-400">
                    {{ bccomp((string) $expense->refunded_amount, '0.00', 2) === 1 ? money($expense->refunded_amount) : '—' }}
                </td>
                <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">
                    {{ money($expense->net_amount) }}
                </td>
            @endif

            <td class="px-4 py-3">
                <x-ui.badge :color="$expense->status->color()" size="xs">{{ $expense->status->label() }}</x-ui.badge>
                @unless ($expense->status->countsInReports())
                    <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">not in reports</span>
                @endunless
            </td>

            <td class="px-4 py-3 text-right">
                <div class="flex items-center justify-end gap-1">
                    @if ($expense->receipt_path)
                        @can('expenses.download')
                            <x-ui.icon-button icon="paper-clip" label="Download the receipt"
                                              :href="route('admin.expenses.receipt', $expense)" />
                        @endcan
                    @endif

                    @if ($canApprove && $expense->status === \App\Enums\ExpenseStatus::Pending)
                        @if ($isOwn)
                            <span title="You cannot approve your own expense — somebody else agrees it is the company's">
                                <x-ui.button size="sm" variant="ghost" :disabled="true">Approve</x-ui.button>
                            </span>
                        @else
                            <form method="POST" action="{{ route('admin.expenses.approve', $expense) }}">
                                @csrf
                                <x-ui.button type="submit" size="sm" variant="secondary">Approve</x-ui.button>
                            </form>
                        @endif
                    @endif

                    <x-ui.icon-button icon="eye" label="Open {{ $expense->expense_no }}"
                                      :href="route('admin.expenses.show', $expense)" />
                </div>
            </td>
        </tr>
    @endforeach

    <x-slot:empty>
        <x-ui.empty-state icon="banknotes"
                          :title="$showApprovals ? 'Nothing is waiting for a decision' : 'No expenses in this range'"
                          :message="$showApprovals
                              ? 'Every claim in this range has been decided.'
                              : 'Record what the business spent and it appears here the moment it is approved.'" />
    </x-slot:empty>

    <x-slot:footer>
        <x-ui.pagination-summary :paginator="$expenses" label="expenses" />
    </x-slot:footer>
</x-ui.table>
