<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Enums\Cms\StatisticMetric;
use App\Enums\Cms\StatisticValueMode;
use App\Http\Controllers\Admin\Cms\Concerns\RespondsForCms;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\CmsListRequest;
use App\Models\Cms\WebsiteSection;
use App\Models\Cms\WebsiteSectionItem;
use App\Services\Cms\StatisticsProvider;
use App\Support\Cms\SectionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

/**
 * The statistics screen — `admin.website.statistics.index` (phase-03 §8.11): every `statistic` repeater
 * item in the system (hero, about, any later section) with its mode, metric, manual value and the
 * **currently resolved** value, so an admin can see all six numbers of §9 at once and spot a stale one.
 *
 * Read-only; edits happen in the owning section's editor. The resolved value is
 * `StatisticsProvider::valueFor()`, the one rule the renderer uses: null renders nothing, never `0`
 * (INV-12).
 */
final class StatisticController extends Controller
{
    use RespondsForCms;

    public function __construct(
        private readonly StatisticsProvider $statistics,
    ) {}

    public function index(CmsListRequest $request): View
    {
        $this->authorize('website_sections.view_any');

        $mode = $request->filterEnum('mode', StatisticValueMode::class);
        $metric = $request->filterEnum('metric', StatisticMetric::class);
        $enabled = $request->filterString('enabled');
        $search = $request->searchTerm();

        // Items of live (non-trashed) sections only; an item of a trashed section renders nowhere.
        $liveSections = WebsiteSection::query()->select('id');

        $items = WebsiteSectionItem::query()
            ->where('group', 'statistic')
            ->whereIn('website_section_id', $liveSections)
            ->when($mode instanceof StatisticValueMode, static fn (Builder $query) => $query->where('value_mode', $mode->value))
            ->when($metric instanceof StatisticMetric, static fn (Builder $query) => $query->where('metric', $metric->value))
            ->when($enabled !== null, static fn (Builder $query) => $query->where('is_enabled', $enabled === 'enabled'))
            ->when($search !== null, fn (Builder $query) => $query->where('content', 'like', $this->like($search)))
            ->orderBy('website_section_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($this->perPage())
            ->withQueryString();

        $sections = WebsiteSection::query()
            ->whereIn('id', $items->getCollection()->pluck('website_section_id')->unique()->values())
            ->get(['id', 'section_key', 'name', 'placement', 'page_id', 'status', 'is_enabled'])
            ->keyBy('id');

        $resolved = [];

        foreach ($items->getCollection() as $item) {
            $resolved[(int) $item->getKey()] = $this->statistics->valueFor($item);
        }

        $labels = [];

        foreach ($sections as $section) {
            $key = (string) $section->section_key;
            $labels[(int) $section->getKey()] = (string) ($section->name ?: (SectionRegistry::exists($key) ? SectionRegistry::label($key) : $key));
        }

        return view('admin.cms.statistics.index', [
            'items' => $items,
            'sections' => $sections,
            'sectionLabels' => $labels,
            'resolved' => $resolved,
            'modeOptions' => StatisticValueMode::options(),
            'metricOptions' => StatisticMetric::options(),
            'filters' => $request->activeFilters(),
            'canEdit' => $request->user()?->can('website_sections.edit') === true,
        ]);
    }
}
