{{--
    ClientsOverviewWidget body (phase-05 §8.11) — key `clients_overview`, span 4, permission clients.view_any, module clients.

    $data (the widget's data()):
      available       bool
      total           int     clients on file (not trashed)
      active          int
      portal_enabled  int
      new_in_range    int
      range_label     string
      href            ?string
--}}

@php
    $total = (int) ($data['total'] ?? 0);
    $rows = [
        ['label' => 'Active', 'value' => (int) ($data['active'] ?? 0), 'color' => 'emerald'],
        ['label' => 'Using the portal', 'value' => (int) ($data['portal_enabled'] ?? 0), 'color' => 'sky'],
        ['label' => 'New in '.($data['range_label'] ?? 'this period'), 'value' => (int) ($data['new_in_range'] ?? 0), 'color' => 'brand'],
    ];
@endphp

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="building-office" title="Clients unavailable" message="The client figures could not be read." :compact="true" />
@elseif ($total === 0)
    <x-ui.empty-state icon="building-office" title="No clients yet" :message="$widget->emptyMessage ?? 'Converting a won lead creates the first one.'" :compact="true" />
@else
    <div class="space-y-4">
        <div class="flex items-end justify-between gap-3">
            <div>
                <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">{{ app_number($total) }}</p>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ \Illuminate\Support\Str::plural('client', $total) }}</p>
            </div>
            @if (filled($data['href'] ?? null))
                <x-ui.button variant="secondary" size="sm" :href="$data['href']" icon-trailing="arrow-right">Clients</x-ui.button>
            @endif
        </div>
        <dl class="divide-y divide-slate-100 dark:divide-slate-800">
            @foreach ($rows as $row)
                <div class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
                    <dt><x-ui.badge :color="$row['color']" size="sm" :dot="true">{{ $row['label'] }}</x-ui.badge></dt>
                    <dd class="text-sm font-semibold tabular-nums text-slate-900 dark:text-white">{{ app_number($row['value']) }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
@endif
