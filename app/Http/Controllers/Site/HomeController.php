<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Enums\Cms\SectionPlacement;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Concerns\ComposesSite;
use App\Services\Cms\SeoService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The home page — `site.home` (phase-03 §7.6, §8.14).
 *
 * The enabled, published `home` sections in `sort_order`, between the global header and footer, all
 * read from their published snapshots under the version-stamped cache (D22). No `module:` or `can:`
 * gate: disabling `website_sections` never takes the site down (INV-15). The `public_site` middleware
 * answers the holding / maintenance page before this runs, and `site.cache` stores the response.
 */
final class HomeController extends Controller
{
    use ComposesSite;

    public function __construct(
        private readonly SeoService $seo,
    ) {}

    public function __invoke(Request $request): Response
    {
        $preview = $this->previewRequested($request, 'website_sections.view');

        $sections = $preview
            ? $this->draftSections(SectionPlacement::Home)
            : $this->liveSections(SectionPlacement::Home);

        $seo = $this->seo->for(SeoService::HOME_ROUTE, $preview, '/');

        $site = $this->sitePayload($this->chrome($preview), $sections, $seo, null, $preview, 'site-home');

        return $this->withSiteHeaders(response()->view('site.home', ['site' => $site]), $seo, $preview);
    }
}
