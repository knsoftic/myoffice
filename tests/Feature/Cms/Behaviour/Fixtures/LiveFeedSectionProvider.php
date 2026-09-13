<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour\Fixtures;

use App\Contracts\Cms\SectionDataProvider;
use App\Enums\Cms\ContentStatus;
use App\Models\Cms\WebsiteSection;
use App\Services\Cms\CacheVersion;
use Illuminate\Support\Facades\DB;

/**
 * A later phase's `is_live` SectionDataProvider, reduced to what the render path must honour
 * (phase-03 §6.1, [D-W3-11]): it reads live rows — here the published pages whose slug starts with
 * `live-feed-` — caches itself under the public cache version stamp, reads its options from the snapshot
 * it is handed, and returns plain arrays in the teaser shape `site/sections/partials/teaser` renders.
 *
 * Not named `*Test.php`, so PHPUnit does not run it.
 */
final class LiveFeedSectionProvider implements SectionDataProvider
{
    /** Every section the provider was asked to resolve, for the assertions. */
    public static int $calls = 0;

    public function __construct(private readonly CacheVersion $cache) {}

    public function resolve(WebsiteSection $section): array
    {
        self::$calls++;

        $snapshot = (array) ($section->getAttribute('published_content') ?? []);
        $prefix = (string) data_get($snapshot, 'fields.heading', '');

        return (array) $this->cache->remember('section-data', ['live-feed', $prefix], 600, static fn (): array => [
            'items' => DB::table('pages')
                ->where('slug', 'like', 'live-feed-%')
                ->where('status', ContentStatus::Published->value)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->pluck('title')
                ->map(static fn (mixed $title): array => ['title' => (string) $title, 'excerpt' => null, 'url' => null, 'media' => null, 'icon' => null, 'meta' => null])
                ->all(),
        ]);
    }
}
