<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Concerns\ComposesSite;
use App\Http\Controllers\Site\Concerns\RendersPages;
use App\Models\Cms\Page;
use App\Services\Cms\PageService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A custom page at its own slug — `site.page` (phase-03 §7.6, §8.14), registered last.
 *
 * Only a **published, non-trashed** page renders; a draft, scheduled or trashed page — or no page — is a
 * 404, never a 403, because the existence of a draft page is not public information (§9). A reserved
 * first segment (`admin`, `courses`, `sitemap.xml`, ...) is a 404 here too, so a later phase's route can
 * never be shadowed even if the route pattern is loosened.
 *
 * The body is the `published_content` snapshot, sanitised again on render (INV-13); a `sections` page
 * renders its own published sections instead.
 */
final class PageController extends Controller
{
    use ComposesSite;
    use RendersPages;

    public function __construct(
        private readonly PageService $pages,
    ) {}

    public function __invoke(Request $request, string $slug): Response
    {
        if (in_array($slug, $this->pages->reservedSlugs(), true)) {
            return $this->notFound();
        }

        $preview = $this->previewRequested($request, 'pages.view');

        $page = $preview
            ? Page::query()->where('slug', $slug)->first()
            : Page::query()->forPublic($slug)->first();

        if (! $page instanceof Page) {
            return $this->notFound();
        }

        return $this->renderPage($page, $preview);
    }
}
