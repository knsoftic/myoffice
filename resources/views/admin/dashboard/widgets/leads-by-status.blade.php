{{--
    LeadsByStatusWidget body (phase-05 §8.11) — key `leads_by_status`, span 4, permission leads.view, module leads.
    Counts per LeadStatus for the selected DateRange, through the §9 visibility scope.

    $data (the widget's data()):
      available     bool
      total         int
      statuses      list<array{value: string, label: string, color: string, count: int, share: ?int, href: ?string}>
                    in LeadStatus::sortOrder(); share = whole percent of total
      range_label   string
--}}

@php
    $statuses = collect($data['statuses'] ?? []);
    $total = (int) ($data['total'] ?? 0);
    $shareOf = static fn (array $status): int => isset($status['share']) ? (int) $status['share'] : ($total > 0 ? intdiv((int) ($status['count'] ?? 0) * 100, $total) : 0);
@endphp

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="funnel" title="Leads unavailable" message="The lead figures could not be read." :compact="true" />
@elseif ($total === 0)
    <x-ui.empty-state icon="funnel" title="No leads in this period" :message="$widget->emptyMessage ?? 'Nothing was captured in '.($data['range_label'] ?? 'this period').'.'" :compact="true" />
@else
    <div class="space-y-4">
        <div class="flex items-end justify-between gap-3">
            <div>
                <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">{{ app_number($total) }}</p>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">leads in {{ $data['range_label'] ?? 'this period' }}</p>
            </div>
            @if (\Illuminate\Support\Facades\Route::has('admin.leads.board'))
                <x-ui.button variant="secondary" size="sm" :href="route('admin.leads.board')" icon-trailing="arrow-right">Board</x-ui.button>
            @endif
        </div>

        @include('admin.dashboard.partials.meter', [
            'segments' => $statuses->map(fn (array $status): array => ['color' => $status['color'] ?? 'slate', 'share' => $shareOf($status), 'label' => $status['label'] ?? ''])->all(),
            'height' => 'h-2.5',
        ])

        <dl class="divide-y divide-slate-100 dark:divide-slate-800">
            @foreach ($statuses as $status)
                <div class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
                    <dt><x-ui.badge :color="$status['color'] ?? 'slate'" size="sm" :dot="true">{{ $status['label'] ?? '' }}</x-ui.badge></dt>
                    <dd class="flex items-center gap-2 text-sm">
                        @if (filled($status['href'] ?? null) && (int) ($status['count'] ?? 0) > 0)
                            <a href="{{ $status['href'] }}" class="font-semibold tabular-nums text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ app_number((int) $status['count']) }}</a>
                        @else
                            <span class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ app_number((int) ($status['count'] ?? 0)) }}</span>
                        @endif
                        <span class="w-9 text-right text-xs tabular-nums text-slate-400 dark:text-slate-500">{{ $shareOf($status) }}%</span>
                    </dd>
                </div>
            @endforeach
        </dl>
    </div>
@endif
