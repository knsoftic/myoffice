{{--
    InvoiceStatusBreakdownWidget body. Every status, zeroes included: a card that hid its empty rows
    would change shape month to month, and "no drafts" is information.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="rectangle-stack" title="Invoices unavailable"
                      message="The invoice register could not be read." :compact="true" />
@elseif (($data['total'] ?? 0) === 0)
    <x-ui.empty-state icon="rectangle-stack" title="No invoices in this period"
                      :message="$widget->emptyMessage ?? null" :compact="true" />
@else
    <div class="space-y-2">
        @foreach ($data['rows'] as $row)
            @php
                $share = $data['total'] > 0 ? round(($row['count'] / $data['total']) * 100, 1) : 0.0;
            @endphp
            <a href="{{ $row['href'] }}"
               class="flex items-center gap-3 rounded-lg px-2 py-1.5 transition-colors hover:bg-slate-50 dark:hover:bg-slate-800/60">
                <span class="w-20 shrink-0">
                    <x-ui.badge :color="$row['status']->color()" size="xs">{{ $row['status']->label() }}</x-ui.badge>
                </span>

                <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                    <span class="block h-full rounded-full bg-brand-500" style="width: {{ max($share, $row['count'] > 0 ? 2 : 0) }}%"></span>
                </span>

                <span class="w-8 shrink-0 text-right text-sm tabular-nums text-slate-900 dark:text-white">{{ $row['count'] }}</span>
                <span class="w-28 shrink-0 text-right text-xs tabular-nums text-slate-500 dark:text-slate-400">
                    {{ money($row['amount']) }}
                </span>
            </a>
        @endforeach

        <p class="border-t border-slate-100 pt-2 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
            {{ $data['total'] }} raised · {{ $data['range_label'] ?? '' }}
        </p>
    </div>
@endif
