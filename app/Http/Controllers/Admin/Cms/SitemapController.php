<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Http\Controllers\Admin\Cms\Concerns\RespondsForCms;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\CmsListRequest;
use App\Models\Cms\SitemapGeneration;
use App\Models\User;
use App\Services\Cms\SitemapGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The SEO screen's sitemap panel (phase-03 §7.5, §8.12): "Regenerate now" (`seo.edit`, throttled) and
 * the build history (`seo.view`) read from the append-only `sitemap_generations` log.
 */
final class SitemapController extends Controller
{
    use RespondsForCms;

    public function __construct(
        private readonly SitemapGenerator $sitemap,
    ) {}

    public function regenerate(Request $request): Response
    {
        $this->authorize('seo.edit');
        $this->authorize('create', SitemapGeneration::class);

        $generation = $this->sitemap->regenerate('manual', $this->actor($request));

        $failed = (string) $generation->status !== 'ok';

        return $this->done(
            $request,
            $failed
                ? 'The sitemap could not be rebuilt: '.($generation->failure_reason ?: 'see the build history.')
                : sprintf('Sitemap rebuilt with %d URLs.', (int) $generation->url_count),
            null,
            [
                'status' => (string) $generation->status,
                'url_count' => (int) $generation->url_count,
                'enabled' => $this->sitemap->isEnabled(),
            ],
            $failed ? 'error' : 'success',
        );
    }

    public function history(CmsListRequest $request): View
    {
        $this->authorize('seo.view');
        $this->authorize('viewAny', SitemapGeneration::class);

        $status = $request->filterString('status');

        $generations = SitemapGeneration::query()
            ->when(in_array($status, ['ok', 'failed'], true), static fn (Builder $query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate($this->perPage())
            ->withQueryString();

        $ids = $generations->getCollection()->pluck('created_by')->filter()->unique()->values();

        return view('admin.cms.sitemap.history', [
            'generations' => $generations,
            'authors' => $ids->isEmpty() ? [] : User::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
            'enabled' => $this->sitemap->isEnabled(),
            'filters' => $request->activeFilters(),
            'canRegenerate' => $request->user()?->can('seo.edit') === true,
        ]);
    }
}
