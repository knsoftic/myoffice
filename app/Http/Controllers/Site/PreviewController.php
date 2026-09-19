<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Enums\Cms\SectionPlacement;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Concerns\ComposesSite;
use App\Http\Controllers\Site\Concerns\RendersPages;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\Cms\Page;
use App\Models\Cms\WebsiteSection;
use App\Services\Cms\SeoService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Draft preview — `site.preview.page` / `site.preview.section` (phase-03 §6.12, §7.6).
 *
 * Two ways in, both read-only, checked here whatever middleware the route carries:
 *
 *   · a **valid signed URL** (`URL::temporarySignedRoute`, `website.preview_ttl_minutes`) for a reviewer
 *     with no login;
 *   · a **session** whose user holds `pages.view` / `website_sections.view` (the permission, never the
 *     login — a student is a visitor, §9).
 *
 * An expired or tampered signature is a **403**; no signature and no permission is a **404**, so draft
 * ids cannot be probed (FT-21, FT-23). Every response is `Cache-Control: no-store, private` and
 * `X-Robots-Tag: noindex, nofollow`, and the SEO robots is forced to `noindex_nofollow` (INV-9). Only GET
 * routes exist, so a POST is a 405.
 */
final class PreviewController extends Controller
{
    use ComposesSite;
    use RendersPages;

    public function __construct(
        private readonly SeoService $seo,
    ) {}

    /**
     * The id arrives as a plain number and the row is loaded only after the signature or the permission
     * has passed, so an unauthorised request is answered the same way whether or not the id exists — a
     * draft cannot be discovered by counting (§9).
     */
    public function page(Request $request, string $page): Response
    {
        $this->authorisePreview($request, 'pages.view');

        /** @var Page $model */
        $model = Page::query()->findOrFail((int) $page);

        return $this->renderPage($model, draft: true);
    }

    /**
     * One section's draft in its frame: a header or footer draft replaces the live one; any other
     * section renders alone between the live header and footer (the editor's preview pane, §8.5).
     */
    public function section(Request $request, string $section): Response
    {
        $this->authorisePreview($request, 'website_sections.view');

        /** @var WebsiteSection $model */
        $model = WebsiteSection::query()->findOrFail((int) $section);

        $draft = $this->draftSection($model);
        $chrome = $this->chrome();
        $sections = [];
        $placement = $model->placement instanceof SectionPlacement ? $model->placement : SectionPlacement::tryFrom((string) $model->placement);

        if ($placement === SectionPlacement::GlobalHeader) {
            $chrome['header'] = $draft;
        } elseif ($placement === SectionPlacement::GlobalFooter) {
            $chrome['footer'] = $draft;
        } elseif ($draft !== null) {
            $sections[] = $draft;
        }

        $page = $model->page_id === null ? null : Page::query()->find((int) $model->page_id);
        $seo = $page instanceof Page
            ? $this->seo->for($page, true, '/'.$page->slug)
            : $this->seo->for(SeoService::HOME_ROUTE, true, '/');

        $site = $this->sitePayload($chrome, $sections, $seo, null, true, 'site-preview site-preview-section');

        return $this->withSiteHeaders(
            response()->view('site.home', ['site' => $site, 'previewSection' => $model->getKey(), 'previewOmitted' => $draft === null]),
            $seo,
            true,
        );
    }

    /**
     * A valid signature, or an active user in good standing holding the permission (§7.6 `auth` +
     * `active` + `can:`). Otherwise 403 for a bad signature, 404 for none.
     */
    private function authorisePreview(Request $request, string $permission): void
    {
        if ($request->hasValidSignature()) {
            return;
        }

        if (EnsureUserIsActive::permits($request->user(), $permission)) {
            return;
        }

        abort($request->query->has('signature') ? Response::HTTP_FORBIDDEN : Response::HTTP_NOT_FOUND);
    }
}
