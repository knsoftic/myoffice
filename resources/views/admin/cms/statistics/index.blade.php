@extends('layouts.admin')

@section('title', 'Website statistics')

{{--
    Statistics screen — admin.website.statistics.index (phase-03 §7.1, §8.11; requirement §9, §100).
    Read-mostly: every `statistic` repeater item of a live section, with the value a visitor sees right now,
    so a stale or unresolvable number is noticed here and fixed in its section.

    Controller variables (Admin\Cms\StatisticController@index):
      $items          LengthAwarePaginator<App\Models\Cms\WebsiteSectionItem>   group = statistic
      $sections       Collection<int, WebsiteSection>   keyed by id (id, section_key, name, placement, page_id, status, is_enabled)
      $sectionLabels  array<int, string>                section id => display name
      $resolved       array<int, ?string>               item id => StatisticsProvider::valueFor() — null renders nothing (INV-12)
      $modeOptions    array<string, string>
      $metricOptions  array<string, string>
      $filters        array<string, mixed>
      $canEdit        bool
    Query string (CmsListRequest): search (caption), mode, metric, enabled (enabled|disabled), page.
--}}

@php
    use App\Enums\Cms\StatisticMetric;
    use App\Enums\Cms\StatisticValueMode;

    $sections = collect($sections ?? []);
    $sectionLabels = $sectionLabels ?? [];
    $resolved = $resolved ?? [];
    $filtered = ! empty($filters ?? []);
@endphp

@section('header')
    <x-ui.page-header
        title="Statistics"
        subtitle="Every statistic on the public site — typed in or counted live — and the value a visitor sees right now."
        icon="chart-bar"
        :badge="app_number($items->total()).' '.\Illuminate\Support\Str::plural('statistic', $items->total())"
    />
@endsection

@section('content')
    <div
        x-data="{ navigating: false }"
        x-on:submit.window="if ($event.target?.method === 'get') navigating = true"
        class="space-y-4"
    >
        <x-ui.filter-bar placeholder="Search captions…" :reset="route('admin.website.statistics.index')">
            <x-ui.form.select name="mode" :options="$modeOptions ?? StatisticValueMode::options()" :selected="request('mode')" placeholder="Any mode" size="sm" aria-label="Filter by value mode" />
            <x-ui.form.select name="metric" :options="$metricOptions ?? StatisticMetric::options()" :selected="request('metric')" placeholder="Any metric" size="sm" aria-label="Filter by metric" />
            <x-ui.form.select name="enabled" :options="['enabled' => 'Shown', 'disabled' => 'Hidden']" :selected="request('enabled')" placeholder="Shown or hidden" size="sm" aria-label="Filter by enabled state" />
        </x-ui.filter-bar>

        <x-ui.table loading="navigating" :is-empty="$items->isEmpty()" :columns="7">
            <x-slot:head>
                <th scope="col" class="px-4 py-3">Section</th>
                <th scope="col" class="px-4 py-3">Caption</th>
                <th scope="col" class="px-4 py-3">Mode</th>
                <th scope="col" class="px-4 py-3">Metric</th>
                <th scope="col" class="px-4 py-3 text-right">Manual value</th>
                <th scope="col" class="px-4 py-3 text-right">Shown now</th>
                <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($items as $item)
                @php
                    $content = is_array($item->content) ? $item->content : [];
                    $section = $sections->get((int) $item->website_section_id);
                    $sectionLabel = $sectionLabels[(int) $item->website_section_id] ?? ('Section #'.$item->website_section_id);
                    $mode = $item->value_mode instanceof StatisticValueMode ? $item->value_mode : StatisticValueMode::tryFrom((string) $item->value_mode);
                    $metric = $item->metric instanceof StatisticMetric ? $item->metric : StatisticMetric::tryFrom((string) $item->metric);
                    $shown = $resolved[(int) $item->id] ?? null;
                    $fellBack = $mode === StatisticValueMode::Auto && $shown !== null && $item->manual_value !== null && (string) $shown === (string) $item->manual_value;
                @endphp
                <tr @class(['opacity-60' => ! $item->is_enabled])>
                    <td>
                        <p class="font-medium text-slate-900 dark:text-white">{{ $sectionLabel }}</p>
                        @if ($section)
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $section->placement?->label() }}@unless ($section->is_enabled) · section disabled @endunless</p>
                        @endif
                    </td>
                    <td>
                        <span class="text-slate-900 dark:text-white">{{ $content['prefix'] ?? '' }}{{ $content['label'] ?? '—' }}{{ $content['suffix'] ?? '' }}</span>
                        @unless ($item->is_enabled)
                            <x-ui.badge color="slate" variant="outline" size="sm" class="ml-1">Hidden</x-ui.badge>
                        @endunless
                    </td>
                    <td>
                        @if ($mode)
                            <x-ui.badge :color="$mode->color()" size="sm">{{ $mode->label() }}</x-ui.badge>
                        @endif
                    </td>
                    <td class="text-sm">{{ $metric?->label() ?? '—' }}</td>
                    <td class="text-right tabular-nums">{{ $item->manual_value !== null ? app_number((string) $item->manual_value) : '—' }}</td>
                    <td class="text-right">
                        @if ($shown !== null)
                            <span class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ app_number((string) $shown) }}</span>
                            @if ($mode === StatisticValueMode::Auto && ! $fellBack)
                                <x-ui.badge color="emerald" size="sm" :dot="true" class="ml-1">Live</x-ui.badge>
                            @elseif ($fellBack)
                                <p class="text-2xs text-amber-700 dark:text-amber-400">Manual fallback — the live count is unavailable</p>
                            @endif
                        @else
                            <span class="text-xs font-medium text-amber-700 dark:text-amber-400" title="A statistic with no value renders nothing — never a zero.">Not shown</span>
                        @endif
                    </td>
                    <td class="text-right">
                        @if ($section)
                            <x-ui.button size="sm" variant="ghost" :icon="($canEdit ?? false) ? 'pencil' : 'eye'" :href="route('admin.website.sections.edit', $section)">{{ ($canEdit ?? false) ? 'Edit in section' : 'Open section' }}</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state
                    icon="chart-bar"
                    :title="$filtered ? 'No statistics match those filters' : 'No statistics yet'"
                    :message="$filtered ? 'Clear the filters to see every statistic.' : 'Statistics are added inside the hero and about sections.'"
                />
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$items" label="statistics" />
            </x-slot:footer>
        </x-ui.table>
    </div>
@endsection
