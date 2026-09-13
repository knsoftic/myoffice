<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\Concerns;

use App\Enums\Cms\ImageProfile;
use App\Enums\Cms\SectionPlacement;
use App\Http\Requests\Cms\PageTemplate;
use App\Models\Cms\MediaAsset;
use App\Models\Cms\Page;
use App\Services\Cms\MediaService;
use App\Services\Cms\SeoService;
use App\Support\RichText;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Rendering a custom page, live or as a draft preview, and the branded 404 (phase-03 §8.14).
 *
 * The using class must also use `ComposesSite`.
 */
trait RendersPages
{
    /**
     * @param  bool  $draft  render `content` and draft sections (an authorised preview) instead of the
     *                       published snapshot
     */
    protected function renderPage(Page $page, bool $draft): Response
    {
        $seo = app(SeoService::class)->for($page, $draft, '/'.$page->slug);

        $sections = $page->usesSections()
            ? ($draft ? $this->draftSections(SectionPlacement::Page, $page) : $this->liveSections(SectionPlacement::Page, $page))
            : [];

        // Sanitised again on render: the database is not a trust boundary (INV-13).
        $body = $page->usesSections()
            ? ''
            : RichText::sanitize($draft ? $page->draftBody() : $page->publishedBody());

        $data = [
            'id' => (int) $page->getKey(),
            'title' => (string) $page->title,
            'slug' => (string) $page->slug,
            'layout' => $page->layout?->value,
            'excerpt' => $page->excerpt,
            'show_banner' => (bool) $page->show_banner,
            'banner_heading' => $page->banner_heading ?: $page->title,
            'banner_subheading' => $page->banner_subheading,
            'banner' => $page->show_banner ? $this->bannerSnapshot($page) : null,
            'body' => $body,
            'published_at' => $page->published_at,
        ];

        $site = $this->sitePayload($this->chrome($draft), $sections, $seo, $data, $draft, 'site-page site-page-'.$page->slug);

        return $this->withSiteHeaders(
            response()->view(PageTemplate::resolve($page->template), ['site' => $site, 'page' => $data]),
            $seo,
            $draft,
        );
    }

    /**
     * The branded 404 — header and footer intact, a link home (§8.14) — or the framework's own 404
     * while `site/404.blade.php` has not shipped. Never cached (`site.cache` skips non-200 responses)
     * and never indexed.
     */
    protected function notFound(): Response
    {
        if (! View::exists('site.404')) {
            abort(Response::HTTP_NOT_FOUND);
        }

        try {
            // `preview: true` only forces noindex, nofollow on the fallback SEO — a 404 is never indexed.
            $seo = app(SeoService::class)->for(SeoService::HOME_ROUTE, true);
            $site = $this->sitePayload($this->chrome(), [], $seo, null, false, 'site-404');

            $response = response()->view('site.404', ['site' => $site], Response::HTTP_NOT_FOUND);
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

            return $response;
        } catch (Throwable $exception) {
            report($exception);

            abort(Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * The banner image as the snapshot array `<x-site.image>` reads, or null.
     *
     * @return array<string, mixed>|null
     */
    private function bannerSnapshot(Page $page): ?array
    {
        if ($page->banner_media_id === null) {
            return null;
        }

        $asset = MediaAsset::query()->find((int) $page->banner_media_id);

        if (! $asset instanceof MediaAsset || ! $asset->isImage()) {
            return null;
        }

        return app(MediaService::class)->toSnapshot($asset, ImageProfile::Banner);
    }
}
