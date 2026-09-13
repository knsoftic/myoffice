<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\SectionPlacement;
use App\Http\Controllers\Admin\Cms\Concerns\RespondsForCms;
use App\Http\Controllers\Controller;
use App\Models\Cms\CtaBlock;
use App\Models\Cms\Faq;
use App\Models\Cms\MediaAsset;
use App\Models\Cms\Menu;
use App\Models\Cms\Page;
use App\Models\Cms\SitemapGeneration;
use App\Models\Cms\WebsiteSection;
use App\Models\User;
use App\Services\Cms\CacheVersion;
use App\Support\Cms\SectionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The Website CMS overview — `admin.website.index` (phase-03 §7.1, §8.3): what is live, what is
 * waiting, what is broken.
 *
 * Read-only. Each card is included only when the viewer holds that area's `view_any` permission —
 * which `Gate::before` also denies while the area's module is disabled — so the overview never shows a
 * count for a screen its viewer cannot open.
 */
final class WebsiteOverviewController extends Controller
{
    use RespondsForCms;

    public function __construct(
        private readonly CacheVersion $cache,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('website_sections.view_any');

        $user = $this->actor($request);

        return view('admin.cms.overview', [
            'siteState' => $this->siteState(),
            'sections' => $this->sectionCards(),
            'orphanedCount' => $this->orphanedCount(),
            'areas' => $this->areaCards($user),
            'lastPublish' => $this->lastPublish(),
            'cacheVersion' => $this->cache->version(),
            'canFlush' => $user->can('website_sections.change_status'),
            'lastSitemap' => $user->can('seo.view')
                ? SitemapGeneration::query()->latest('id')->first()
                : null,
        ]);
    }

    /**
     * `live`, `maintenance` or `disabled`, from Phase 2's two gate settings.
     */
    private function siteState(): string
    {
        return match (true) {
            ! setting('maintenance.public_site_enabled', true) => 'disabled',
            (bool) setting('maintenance.maintenance_mode', false) => 'maintenance',
            default => 'live',
        };
    }

    /**
     * Placement => total, enabled, published and unpublished-changes counts — one grouped query.
     *
     * @return array<string, array<string, int|string>>
     */
    private function sectionCards(): array
    {
        $rows = WebsiteSection::query()
            ->selectRaw('placement, COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN is_enabled = 1 THEN 1 ELSE 0 END) AS enabled')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS published', [ContentStatus::Published->value])
            ->selectRaw('SUM(CASE WHEN has_unpublished_changes = 1 THEN 1 ELSE 0 END) AS unpublished')
            ->groupBy('placement')
            ->get()
            ->keyBy('placement');

        $cards = [];

        foreach (SectionPlacement::cases() as $placement) {
            $row = $rows->get($placement->value);

            $cards[$placement->value] = [
                'label' => $placement->label(),
                'total' => (int) ($row?->total ?? 0),
                'enabled' => (int) ($row?->enabled ?? 0),
                'published' => (int) ($row?->published ?? 0),
                'unpublished' => (int) ($row?->unpublished ?? 0),
            ];
        }

        return $cards;
    }

    /**
     * Placed sections whose type the registry no longer declares (INV-2).
     */
    private function orphanedCount(): int
    {
        return WebsiteSection::query()
            ->whereNotIn('section_key', SectionRegistry::keys())
            ->count();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function areaCards(User $user): array
    {
        $areas = [];

        if ($user->can('menus.view_any')) {
            $areas['menus'] = [
                'total' => Menu::query()->count(),
                'attention' => 0,
                'route' => 'admin.website.menus.index',
            ];
        }

        if ($user->can('pages.view_any')) {
            $areas['pages'] = [
                'total' => Page::query()->count(),
                'attention' => Page::query()->where('has_unpublished_changes', true)->count(),
                'route' => 'admin.website.pages.index',
            ];
        }

        if ($user->can('website_cta_blocks.view_any')) {
            $areas['cta_blocks'] = [
                'total' => CtaBlock::query()->count(),
                'attention' => CtaBlock::query()->where('status', ContentStatus::Draft->value)->count(),
                'route' => 'admin.website.cta-blocks.index',
            ];
        }

        if ($user->can('faqs.view_any')) {
            $areas['faqs'] = [
                'total' => Faq::query()->count(),
                'attention' => Faq::query()->where('status', ContentStatus::Draft->value)->count(),
                'route' => 'admin.website.faqs.index',
            ];
        }

        if ($user->can('website_media.view_any')) {
            $areas['media'] = [
                'total' => MediaAsset::query()->count(),
                'attention' => MediaAsset::query()->where('usage_count', 0)->count(),
                'route' => 'admin.website.media.index',
            ];
        }

        if ($user->can('seo.view_any')) {
            $areas['seo'] = [
                'total' => null,
                'attention' => null,
                'route' => 'admin.website.seo.index',
            ];
        }

        return $areas;
    }

    /**
     * The most recent publish across sections and pages: who, what, when.
     *
     * @return array{type: string, label: string, at: Carbon, by: string|null}|null
     */
    private function lastPublish(): ?array
    {
        $section = WebsiteSection::query()
            ->whereNotNull('published_at')
            ->latest('published_at')
            ->first(['id', 'section_key', 'name', 'published_at', 'published_by']);

        $page = Page::query()
            ->whereNotNull('published_at')
            ->where('status', ContentStatus::Published->value)
            ->latest('published_at')
            ->first(['id', 'title', 'published_at', 'published_by']);

        $sectionAt = $section?->published_at === null ? null : Carbon::parse($section->published_at);
        $pageAt = $page?->published_at === null ? null : Carbon::parse($page->published_at);

        if ($sectionAt === null && $pageAt === null) {
            return null;
        }

        $useSection = $pageAt === null || ($sectionAt !== null && $sectionAt->greaterThanOrEqualTo($pageAt));
        $publisherId = $useSection ? $section?->published_by : $page?->published_by;

        return [
            'type' => $useSection ? 'section' : 'page',
            'label' => $useSection
                ? (string) ($section->name ?: (SectionRegistry::exists((string) $section->section_key) ? SectionRegistry::label((string) $section->section_key) : $section->section_key))
                : (string) $page->title,
            'at' => $useSection ? $sectionAt : $pageAt,
            'by' => $publisherId === null ? null : User::query()->whereKey((int) $publisherId)->value('name'),
        ];
    }
}
