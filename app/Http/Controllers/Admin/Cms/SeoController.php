<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Enums\Cms\RobotsDirective;
use App\Enums\Cms\SitemapChangeFrequency;
use App\Http\Controllers\Admin\Cms\Concerns\RespondsForCms;
use App\Http\Controllers\Admin\Cms\Concerns\StreamsCsv;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\BulkSeoRequest;
use App\Http\Requests\Cms\CmsListRequest;
use App\Http\Requests\Cms\SeoTargetRequest;
use App\Http\Requests\Cms\UpdateSeoRequest;
use App\Models\Cms\MediaAsset;
use App\Models\Cms\Page;
use App\Models\Cms\SeoMeta;
use App\Models\Cms\SitemapGeneration;
use App\Services\Cms\SeoService;
use App\Services\Cms\SitemapGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The SEO manager — `admin.website.seo.*` (phase-03 §7.5, §8.12): every public target in one table with
 * its gaps, the edit drawer with inherited values, bulk robots / sitemap changes, the robots.txt preview
 * and the CSV audit.
 *
 * One store, one writer, one rule set (D23): rows come from `SeoService::auditRows()`, writes go through
 * `SeoService::save()`, which validates with `editorRules()` again and audits old and new values. SEO is
 * live-on-save (§2.15) — the service bumps the public cache itself.
 */
final class SeoController extends Controller
{
    use RespondsForCms;
    use StreamsCsv;

    public function __construct(
        private readonly SeoService $seo,
        private readonly SitemapGenerator $sitemap,
    ) {}

    public function index(CmsListRequest $request): View
    {
        $this->authorize('seo.view_any');

        $all = $this->seo->auditRows();
        $rows = $this->filteredRows($request, $all);
        $page = max(1, (int) ($request->filterId('page') ?? 1));
        $perPage = $this->perPage();

        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        $user = $this->actor($request);

        return view('admin.cms.seo.index', [
            'rows' => $paginator,
            'ogImages' => MediaAsset::query()
                ->whereIn('id', $paginator->getCollection()->pluck('og_image_media_id')->filter()->unique()->values())
                ->get()
                ->keyBy('id'),
            'filters' => $request->activeFilters(),
            'sort' => $request->sortColumn(['completeness', 'name', 'updated_at'], 'name'),
            'direction' => $request->sortDirection('asc'),
            'robotsOptions' => RobotsDirective::options(),
            'types' => $all->pluck('type')->unique()->values()->all(),
            'sitemap' => [
                'enabled' => $this->sitemap->isEnabled(),
                'last' => SitemapGeneration::query()->latest('id')->first(),
                'url' => Route::has('site.sitemap') ? route('site.sitemap') : null,
            ],
            'robots' => [
                'mode' => (string) setting('seo.robots_txt_mode', 'auto'),
                'indexable' => (bool) setting('seo.robots_indexable', true),
            ],
            'can' => [
                'edit' => $user->can('seo.edit'),
                'export' => $user->can('seo.export'),
                // robots.txt text is a settings key: editing it is `settings.edit`, never `seo.edit` (G-3).
                'robotsSettings' => $user->can('settings.edit'),
            ],
        ]);
    }

    /**
     * The edit drawer for one target (`admin.website.seo.edit?target=page:7`).
     */
    public function edit(SeoTargetRequest $request): View|JsonResponse
    {
        $this->authorize('seo.view');

        $target = $request->target();
        $meta = $this->seo->meta($target);
        $inherited = $this->seo->for($target);

        $data = [
            'target' => $target,
            'targetKey' => $target instanceof Page ? 'page:'.$target->getKey() : 'route:'.$target,
            'targetName' => $target instanceof Page ? (string) $target->title : $target,
            'meta' => $meta,
            'inherited' => $inherited,
            'completeness' => $this->seo->completeness($meta ?? $target),
            'ogImage' => $meta?->og_image_media_id === null ? null : MediaAsset::query()->find((int) $meta->og_image_media_id),
            'robotsOptions' => RobotsDirective::options(),
            'changefreqOptions' => SitemapChangeFrequency::options(),
            'ogTypes' => SeoService::OG_TYPES,
            'canEdit' => $request->user()?->can('seo.edit') === true,
        ];

        if ($request->expectsJson()) {
            return new JsonResponse([
                'target' => $data['targetKey'],
                'name' => $data['targetName'],
                'meta' => $meta?->toArray(),
                'inherited' => $inherited->toArray(),
                'completeness' => $data['completeness'],
            ]);
        }

        return view('admin.cms.seo.edit', $data);
    }

    public function update(UpdateSeoRequest $request): Response
    {
        $this->authorize('seo.edit');

        $target = $request->target();

        $meta = $this->seo->save($target, $request->seoPayload(), $request->reason());

        return $this->done(
            $request,
            'SEO saved. It is live now.',
            redirect()->route('admin.website.seo.index'),
            ['completeness' => $this->seo->completeness($meta)],
        );
    }

    /**
     * Apply one robots / sitemap choice to the selected rows (§8.12 "Bulk").
     *
     * All rows or none: each row is written by `SeoService::save()` inside one transaction, so a refusal
     * part-way leaves every row as it was.
     */
    public function bulkRobots(BulkSeoRequest $request): Response
    {
        $this->authorize('seo.edit');
        $this->authorize('bulkUpdate', SeoMeta::class);

        $targets = $request->targets();
        $changes = $request->changes();

        DB::transaction(function () use ($targets, $changes, $request): void {
            foreach ($targets as $target) {
                $this->seo->save($target, $changes, $request->reason());
            }
        });

        return $this->done(
            $request,
            sprintf('SEO updated on %d %s.', count($targets), count($targets) === 1 ? 'target' : 'targets'),
            redirect()->route('admin.website.seo.index'),
            ['count' => count($targets)],
        );
    }

    /**
     * robots.txt exactly as a crawler receives it now, including the lockdown override (§8.12).
     */
    public function robotsPreview(Request $request): View|JsonResponse|Response
    {
        $this->authorize('seo.view');

        $body = $this->seo->robotsTxt();
        $mode = (string) setting('seo.robots_txt_mode', 'auto');

        if ($request->expectsJson()) {
            return new JsonResponse(['mode' => $mode, 'body' => $body]);
        }

        if ($request->query('format') === 'text') {
            return response($body, Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
        }

        return view('admin.cms.seo.robots', [
            'mode' => $mode,
            'body' => $body,
            'indexable' => (bool) setting('seo.robots_indexable', true),
            'maintenance' => (bool) setting('maintenance.maintenance_mode', false),
            'publicSite' => (bool) setting('maintenance.public_site_enabled', true),
            'canEditSettings' => $request->user()?->can('settings.edit') === true,
        ]);
    }

    public function export(CmsListRequest $request): StreamedResponse
    {
        $this->authorize('seo.export');
        $this->authorize('export', SeoMeta::class);

        $rows = $this->filteredRows($request)->map(static fn (array $row): array => [
            $row['type'],
            $row['name'],
            $row['url'],
            $row['status'],
            $row['title'],
            $row['meta_description'],
            $row['canonical_url'],
            $row['robots'],
            $row['og_image_media_id'] !== null,
            $row['sitemap_include'],
            $row['completeness'],
            implode(' ', (array) $row['gaps']),
            $row['updated_at'],
        ]);

        return $this->csv(
            'seo-audit-'.Carbon::now()->format('Y-m-d').'.csv',
            ['Type', 'Target', 'URL', 'Status', 'SEO title', 'Meta description', 'Canonical', 'Robots', 'OG image', 'In sitemap', 'Completeness %', 'Gaps', 'Updated at (UTC)'],
            $rows->all(),
        );
    }

    /**
     * `auditRows()` narrowed by the §8.12 filters and sorted.
     *
     * @param  Collection<int, array<string, mixed>>|null  $all
     * @return Collection<int, array<string, mixed>>
     */
    private function filteredRows(CmsListRequest $request, ?Collection $all = null): Collection
    {
        $type = $request->filterString('type');
        $robots = $request->filterEnum('robots', RobotsDirective::class);
        $gap = $request->filterString('gap');
        $search = $request->searchTerm();
        $sort = $request->sortColumn(['completeness', 'name', 'updated_at'], 'name');
        $descending = $request->sortDirection('asc') === 'desc';

        $rows = ($all ?? $this->seo->auditRows())
            ->when($type !== null, static fn (Collection $rows) => $rows->where('type', $type))
            ->when($robots instanceof RobotsDirective, static fn (Collection $rows) => $rows->where('robots', $robots->value))
            ->when($gap !== null, static fn (Collection $rows) => $rows->filter(static fn (array $row): bool => in_array($gap, (array) $row['gaps'], true)))
            ->when($search !== null, static fn (Collection $rows) => $rows->filter(static function (array $row) use ($search): bool {
                $haystack = mb_strtolower(implode(' ', [$row['name'], $row['url'], $row['title'], $row['meta_description']]));

                return str_contains($haystack, mb_strtolower($search));
            }));

        return $rows->sortBy(static fn (array $row): mixed => $sort === 'name' ? mb_strtolower((string) $row['name']) : (string) $row[$sort], SORT_NATURAL, $descending)->values();
    }
}
