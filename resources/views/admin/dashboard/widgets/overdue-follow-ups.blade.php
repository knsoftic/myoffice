{{--
    OverdueFollowUpsWidget body (phase-05 §8.11) — key `overdue_follow_ups`, span 4, permission leads.view, module leads.
    The viewer's own overdue follow-ups, linking the worklist.

    $data (the widget's data()):
      available  bool
      total      int
      items      list<array{lead_name: string, lead_no: ?string, scheduled_at: Carbon|string, type_label: ?string, url: ?string}>
                 the oldest few (bounded)
      href       ?string  the worklist filtered to overdue
--}}

@php
    $items = collect($data['items'] ?? []);
@endphp

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="calendar-days" title="Follow-ups unavailable" message="The follow-ups could not be read." :compact="true" />
@elseif ((int) ($data['total'] ?? 0) === 0)
    <x-ui.empty-state icon="check-circle" title="Nothing overdue" :message="$widget->emptyMessage ?? 'Every follow-up is on time.'" :compact="true" />
@else
    <div class="space-y-3">
        <div class="flex items-end justify-between gap-3">
            <div>
                <p class="text-3xl font-semibold tracking-tight text-rose-700 tabular-nums dark:text-rose-400">{{ app_number((int) $data['total']) }}</p>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">overdue {{ \Illuminate\Support\Str::plural('follow-up', (int) $data['total']) }}</p>
            </div>
            @if (filled($data['href'] ?? null))
                <x-ui.button variant="secondary" size="sm" :href="$data['href']" icon-trailing="arrow-right">Worklist</x-ui.button>
            @endif
        </div>
        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
            @foreach ($items as $item)
                <li class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
                    <div class="min-w-0">
                        @if (filled($item['url'] ?? null))
                            <a href="{{ $item['url'] }}" class="block truncate text-sm font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $item['lead_name'] ?? '' }}</a>
                        @else
                            <p class="truncate text-sm font-medium text-slate-900 dark:text-white">{{ $item['lead_name'] ?? '' }}</p>
                        @endif
                        <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ collect([$item['type_label'] ?? null, $item['lead_no'] ?? null])->filter()->implode(' · ') }}</p>
                    </div>
                    <span class="shrink-0 text-xs text-rose-700 dark:text-rose-400" title="{{ app_datetime($item['scheduled_at'] ?? null) }}">{{ \App\Support\Format::forHumans($item['scheduled_at'] ?? null) }}</span>
                </li>
            @endforeach
        </ul>
    </div>
@endif
