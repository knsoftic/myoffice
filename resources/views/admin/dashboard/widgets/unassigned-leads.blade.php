{{--
    UnassignedLeadsWidget body (phase-05 §8.11) — key `unassigned_leads`, span 4, permission leads.assign, module leads.
    The count of open leads nobody owns, and the oldest five.

    $data (the widget's data()):
      available  bool
      total      int
      oldest     list<array{name: string, lead_no: string, created_at: Carbon|string, source_label: ?string, url: ?string}>
      href       ?string  the leads list filtered to unassigned
--}}

@php
    $oldest = collect($data['oldest'] ?? []);
@endphp

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="user-plus" title="Leads unavailable" message="The lead figures could not be read." :compact="true" />
@elseif ((int) ($data['total'] ?? 0) === 0)
    <x-ui.empty-state icon="check-circle" title="Every lead has an owner" :message="$widget->emptyMessage ?? null" :compact="true" />
@else
    <div class="space-y-3">
        <div class="flex items-end justify-between gap-3">
            <div>
                <p class="text-3xl font-semibold tracking-tight text-amber-700 tabular-nums dark:text-amber-400">{{ app_number((int) $data['total']) }}</p>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">unassigned {{ \Illuminate\Support\Str::plural('lead', (int) $data['total']) }}</p>
            </div>
            @if (filled($data['href'] ?? null))
                <x-ui.button variant="secondary" size="sm" :href="$data['href']" icon-trailing="arrow-right">Assign</x-ui.button>
            @endif
        </div>
        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
            @foreach ($oldest as $lead)
                <li class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
                    <div class="min-w-0">
                        @if (filled($lead['url'] ?? null))
                            <a href="{{ $lead['url'] }}" class="block truncate text-sm font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $lead['name'] ?? '' }}</a>
                        @else
                            <p class="truncate text-sm font-medium text-slate-900 dark:text-white">{{ $lead['name'] ?? '' }}</p>
                        @endif
                        <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ collect([$lead['lead_no'] ?? null, $lead['source_label'] ?? null])->filter()->implode(' · ') }}</p>
                    </div>
                    <span class="shrink-0 text-xs text-slate-500 dark:text-slate-400" title="{{ app_datetime($lead['created_at'] ?? null) }}">{{ \App\Support\Format::forHumans($lead['created_at'] ?? null) }}</span>
                </li>
            @endforeach
        </ul>
    </div>
@endif
