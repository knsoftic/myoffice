{{--
    PipelineValueWidget body (phase-05 §8.11) — key `pipeline_value`, span 4, permission leads.view, module leads.
    SUM(budget_amount) over the open statuses, with the "n of m have a budget" caveat so the sum is never read as the whole
    pipeline. The figures are LeadBoardService's: summed and formatted with Money on the server, never a float.

    $data (the widget's data()):
      available            bool
      value_sum_formatted  string   money() of the open-status sum
      count                int      open leads
      with_budget          int      open leads that carry a budget
      statuses             list<array{label: string, color: string, count: int, value_sum_formatted: string}>
      href                 ?string
--}}

@php
    $count = (int) ($data['count'] ?? 0);
    $withBudget = (int) ($data['with_budget'] ?? 0);
@endphp

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="banknotes" title="Pipeline unavailable" message="The pipeline figures could not be read." :compact="true" />
@elseif ($count === 0)
    <x-ui.empty-state icon="banknotes" title="No open leads" :message="$widget->emptyMessage ?? 'Every lead is won or lost.'" :compact="true" />
@else
    <div class="space-y-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">{{ $data['value_sum_formatted'] ?? money('0') }}</p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                in open leads · <span class="tabular-nums">{{ app_number($withBudget) }}</span> of <span class="tabular-nums">{{ app_number($count) }}</span> have a budget
            </p>
        </div>

        <dl class="divide-y divide-slate-100 dark:divide-slate-800">
            @foreach ((array) ($data['statuses'] ?? []) as $status)
                <div class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
                    <dt class="flex items-center gap-2">
                        <x-ui.badge :color="$status['color'] ?? 'slate'" size="sm" :dot="true">{{ $status['label'] ?? '' }}</x-ui.badge>
                        <span class="text-xs tabular-nums text-slate-400 dark:text-slate-500">{{ app_number((int) ($status['count'] ?? 0)) }}</span>
                    </dt>
                    <dd class="text-sm font-semibold tabular-nums text-slate-900 dark:text-white">{{ $status['value_sum_formatted'] ?? money('0') }}</dd>
                </div>
            @endforeach
        </dl>

        @if (filled($data['href'] ?? null))
            <div class="border-t border-slate-100 pt-3 dark:border-slate-800">
                <a href="{{ $data['href'] }}" class="text-xs font-semibold text-brand-700 hover:underline dark:text-brand-300">Open the board</a>
            </div>
        @endif
    </div>
@endif
