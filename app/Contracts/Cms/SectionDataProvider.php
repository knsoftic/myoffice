<?php

declare(strict_types=1);

namespace App\Contracts\Cms;

use App\Models\Cms\WebsiteSection;

/**
 * The data behind a section type whose content lives in another module's tables (phase-03 §6.1,
 * [D-W3-11]) — services, blog posts, courses, testimonials.
 *
 * A type registers its provider with `SectionRegistry::register($key, ['provider' => Foo::class, ...])`:
 *
 *   · `is_live => false` — the provider runs **at publish time** and its output is frozen into the
 *     section's `published_content` under `provider` (`SnapshotBuilder`);
 *   · `is_live => true`  — the provider runs **at render time** (`ComposesSite::usableSection()`), for
 *     the published page and the draft preview alike, so a newly published row appears without
 *     re-publishing the section. A live provider **must cache itself**, under the public cache version
 *     stamp (`CacheVersion::remember()`), because it is the only thing that adds a query to a public page.
 *
 * The section handed over is the published row — or, at render time, an unsaved `WebsiteSection` whose
 * `published_content` carries the snapshot (or the draft payload in a preview); its options are in
 * `published_content['fields']`. The provider must return plain arrays only (no models, no closures): the
 * output may be frozen into JSON. A provider that throws is reported and the section renders without
 * its data — one module's bug never takes a page down.
 */
interface SectionDataProvider
{
    /**
     * @return array<string|int, mixed>
     */
    public function resolve(WebsiteSection $section): array;
}
