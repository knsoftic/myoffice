{{--
    LeadsBySourceWidget body (phase-05 §8.11) — key `leads_by_source`, span 4, permission leads.view_reports, module leads.
    Count and conversion rate per InquirySource for the range.

    $data (the widget's data()):
      available     bool
      total         int
      sources       list<array{value: string, label: string, color: string, count: int, conversion_rate: ?string, href: ?string}>
                    busiest first; conversion_rate already formatted (Format::percentage), null when the source has no
                    closed lead to divide by
      range_label   string
--}}

@php
    $sources = collect($data['sources'] ?? [])->filter(static fn ($source): bool => (int) ($source['count'] ?? 0) > 0)->values();
@endphp

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="megaphone" title="Sources unavailable" message="The lead figures could not be read." :compact="true" />
@elseif ($sources->isEmpty())
    <x-ui.empty-state icon="megaphone" title="No leads in this period" :message="$widget->emptyMessage ?? 'Where leads come from appears here.'" :compact="true" />
@else
    <div class="space-y-3">
        <p class="text-xs text-slate-500 dark:text-slate-400">{{ app_number((int) ($data['total'] ?? 0)) }} leads in {{ $data['range_label'] ?? 'this period' }}</p>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-left text-2xs font-semibold uppercase tracking-wider text-slate-500 dark:border-slate-800 dark:text-slate-400">
                        <th scope="col" class="py-1.5 pr-3">Source</th>
                        <th scope="col" class="py-1.5 pr-3 text-right">Leads</th>
                        <th scope="col" class="py-1.5 text-right">Converted</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($sources as $source)
                        <tr>
                            <td class="py-2 pr-3"><x-ui.badge :color="$source['color'] ?? 'slate'" size="sm" :dot="true">{{ $source['label'] ?? '' }}</x-ui.badge></td>
                            <td class="py-2 pr-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">
                                @if (filled($source['href'] ?? null))
                                    <a href="{{ $source['href'] }}" class="hover:text-brand-700 dark:hover:text-brand-300">{{ app_number((int) $source['count']) }}</a>
                                @else
                                    {{ app_number((int) $source['count']) }}
                                @endif
                            </td>
                            <td class="py-2 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $source['conversion_rate'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
