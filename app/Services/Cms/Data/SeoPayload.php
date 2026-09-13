<?php

declare(strict_types=1);

namespace App\Services\Cms\Data;

use App\Enums\Cms\RobotsDirective;

/**
 * The fully resolved SEO of one public response (phase-03 §6.5 `SeoPayload`), produced by
 * `SeoService::for()` after the per-field fallback chain and the strictest-wins robots rule.
 *
 * Every string is plain text (never markup) and every URL is absolute, so `<x-site.seo>` only
 * escapes and prints. `robots` is the **effective** directive — already forced to
 * `noindex_nofollow` for a preview, a non-indexable site or maintenance mode (INV-9) — and
 * `robotsHeader()` is the exact value for both the meta tag and the `X-Robots-Tag` header.
 *
 * Immutable. The contract names this `App\Support\SeoPayload`; it lives beside the service that
 * builds it because the build split Phase 3 onto new paths (see the handover note).
 */
readonly class SeoPayload
{
    public function __construct(
        public string $title,
        public ?string $metaDescription,
        public ?string $metaKeywords,
        public string $canonicalUrl,
        public RobotsDirective $robots,
        public string $ogTitle,
        public ?string $ogDescription,
        public ?string $ogImageUrl,
        public string $ogType,
        public string $siteName,
        public string $locale,
        public ?int $imageWidth = null,
        public ?int $imageHeight = null,
    ) {}

    /**
     * "noindex, nofollow" — for `<meta name="robots">` and `X-Robots-Tag` alike.
     */
    public function robotsHeader(): string
    {
        return $this->robots->toHeader();
    }

    public function isIndexable(): bool
    {
        return $this->robots->isIndexable();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'meta_description' => $this->metaDescription,
            'meta_keywords' => $this->metaKeywords,
            'canonical_url' => $this->canonicalUrl,
            'robots' => $this->robots->value,
            'robots_header' => $this->robotsHeader(),
            'og_title' => $this->ogTitle,
            'og_description' => $this->ogDescription,
            'og_image_url' => $this->ogImageUrl,
            'og_type' => $this->ogType,
            'site_name' => $this->siteName,
            'locale' => $this->locale,
            'image_width' => $this->imageWidth,
            'image_height' => $this->imageHeight,
        ];
    }
}
