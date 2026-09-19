<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Behaviour;

use App\Models\Cms\MediaAsset;
use App\Models\Cms\PortfolioItem;
use App\Services\Cms\Exceptions\ContentRuleException;
use App\Services\Cms\PortfolioService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cms\Marketing\Behaviour\Concerns\MarketingBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-04 §11 tests 11-13 — the portfolio gallery (§2.8 `portfolio_item_media`, §6.3, D24).
 *
 *  11. exactly one cover, and it is a column: the first attachment becomes the cover, `setCover()` rewrites it,
 *      an unattached asset is a 422, detaching the cover promotes the next by pivot `sort_order`, detaching the
 *      last one nulls it;
 *  12. a reorder naming a foreign asset is refused and changes nothing; a double attach is a no-op, and
 *      `uq_pim` refuses a second row at the database;
 *  13. a force delete detaches every pivot row and deletes no binary; only after `recountUsage()` drops the
 *      count does `MediaPolicy::delete()` let a human remove the asset.
 */
final class PortfolioGalleryTest extends TestCase
{
    use InteractsWithRbac;
    use MarketingBehaviourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
        $this->actAsSuperAdmin();
    }

    public function test_11_the_gallery_has_exactly_one_cover_column(): void
    {
        $item = $this->portfolio()->store(['title' => 'Hospital Management System']);
        [$first, $second, $third] = [$this->makeImageAsset(), $this->makeImageAsset(), $this->makeImageAsset()];

        $this->portfolio()->attachAssets($item, [$first->getKey(), $second->getKey(), $third->getKey()]);

        $this->assertSame(3, DB::table('portfolio_item_media')->where('portfolio_item_id', $item->getKey())->count());
        $this->assertSame([1, 2, 3], $this->pivotOrder($item, [$first, $second, $third]), 'Attachments keep their order.');
        $this->assertSame((int) $first->getKey(), $this->coverOf($item), 'The first image attached to a cover-less item becomes the cover.');

        $this->portfolio()->setCover($item->fresh(), $third);
        $this->assertSame((int) $third->getKey(), $this->coverOf($item));
        $this->assertSame(1, PortfolioItem::query()->whereKey($item->getKey())->whereNotNull('cover_media_id')->count(), 'One column, so one cover.');

        $stranger = $this->makeImageAsset();

        try {
            $this->portfolio()->setCover($item->fresh(), $stranger);
            $this->fail('An asset that is not attached cannot become the cover.');
        } catch (ContentRuleException $exception) {
            $this->assertSame(422, $exception->status);
        }

        $this->assertSame((int) $third->getKey(), $this->coverOf($item), 'A refused cover changes nothing.');

        // Detaching the cover promotes the lowest remaining sort_order: first (1), then second (2), then nothing.
        $this->portfolio()->detachImage($item->fresh(), $third);
        $this->assertSame((int) $first->getKey(), $this->coverOf($item));

        $this->portfolio()->detachImage($item->fresh(), $first);
        $this->assertSame((int) $second->getKey(), $this->coverOf($item));

        $this->portfolio()->detachImage($item->fresh(), $second);
        $this->assertNull(DB::table('portfolio_items')->where('id', $item->getKey())->value('cover_media_id'), 'An empty gallery has no cover.');
        $this->assertSame(0, DB::table('portfolio_item_media')->where('portfolio_item_id', $item->getKey())->count());

        // Detaching never deletes the library rows.
        $this->assertSame(4, MediaAsset::query()->whereKey([$first->getKey(), $second->getKey(), $third->getKey(), $stranger->getKey()])->count());
    }

    public function test_12_foreign_reorders_are_refused_and_double_attaches_are_no_ops(): void
    {
        $item = $this->portfolio()->store(['title' => 'School ERP Rollout']);
        [$first, $second] = [$this->makeImageAsset(), $this->makeImageAsset()];
        $foreign = $this->makeImageAsset();

        $this->portfolio()->attachAssets($item, [$first->getKey(), $second->getKey()]);
        $before = $this->pivotOrder($item, [$first, $second]);

        try {
            $this->portfolio()->reorderImages($item->fresh(), [$second->getKey(), $foreign->getKey(), $first->getKey()]);
            $this->fail('A reorder naming an image of another gallery must be refused.');
        } catch (ContentRuleException $exception) {
            $this->assertSame(422, $exception->status);
        }

        $this->assertSame($before, $this->pivotOrder($item, [$first, $second]), 'A refused reorder changes nothing.');
        $this->assertSame(0, DB::table('portfolio_item_media')->where('media_asset_id', $foreign->getKey())->count(), 'The foreign asset was never attached.');

        // The service turns a repeat into a no-op …
        $this->portfolio()->attachAssets($item->fresh(), [$first->getKey()]);
        $this->assertSame(1, DB::table('portfolio_item_media')->where('portfolio_item_id', $item->getKey())->where('media_asset_id', $first->getKey())->count());

        // … and the database refuses a second row outright.
        try {
            DB::table('portfolio_item_media')->insert([
                'portfolio_item_id' => $item->getKey(),
                'media_asset_id' => $first->getKey(),
                'sort_order' => 9,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
            $this->fail('uq_pim must refuse a second attachment of the same image.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertStringContainsString('uq_pim', $exception->getMessage());
        }

        $this->assertSame(2, DB::table('portfolio_item_media')->where('portfolio_item_id', $item->getKey())->count());

        // A valid reorder writes 1..n.
        $this->portfolio()->reorderImages($item->fresh(), [$second->getKey(), $first->getKey()]);
        $this->assertSame([2, 1], $this->pivotOrder($item, [$first, $second]));
    }

    public function test_13_force_delete_detaches_without_deleting_binaries_and_frees_the_assets(): void
    {
        Storage::fake('public');

        $item = $this->portfolio()->store(['title' => 'Logistics Tracking Platform']);
        $assets = [$this->makeImageAsset(['disk' => 'public']), $this->makeImageAsset(['disk' => 'public'])];
        $files = [];

        foreach ($assets as $asset) {
            $path = $asset->directory.'/'.$asset->filename;
            Storage::disk('public')->put($path, $this->jpegBytes(40, 20));
            $files[] = $path;
        }

        $this->portfolio()->attachAssets($item, array_map(static fn (MediaAsset $asset): int => (int) $asset->getKey(), $assets));

        $librarian = $this->createUserWithPermissions(['website_media.view_any', 'website_media.view', 'website_media.delete']);

        // While attached, the library counts the portfolio as a usage and refuses a delete.
        $this->media()->recountUsage(array_map(static fn (MediaAsset $asset): int => (int) $asset->getKey(), $assets));

        foreach ($assets as $asset) {
            $this->assertGreaterThanOrEqual(1, (int) $asset->fresh()->usage_count, 'MediaService::recountUsage() must count portfolio_item_media among its sources (§13, D24).');
            $this->assertFalse($librarian->can('delete', $asset->fresh()), 'An image in a gallery cannot be deleted.');
        }

        $this->portfolio()->forceDelete($item->fresh());

        $this->assertNull(PortfolioItem::withTrashed()->find($item->getKey()), 'The item is gone for good.');
        $this->assertSame(0, DB::table('portfolio_item_media')->where('portfolio_item_id', $item->getKey())->count(), 'Every pivot row is detached.');

        foreach ($files as $path) {
            Storage::disk('public')->assertExists($path);
        }

        foreach ($assets as $asset) {
            $this->assertNotNull(MediaAsset::query()->find($asset->getKey()), 'The library row survives the item.');
        }

        $this->media()->recountUsage(array_map(static fn (MediaAsset $asset): int => (int) $asset->getKey(), $assets));

        foreach ($assets as $asset) {
            $this->assertSame(0, (int) $asset->fresh()->usage_count, 'Once detached, the usage count drops to zero.');
            $this->assertTrue($librarian->can('delete', $asset->fresh()), 'Only now may a human delete the asset.');
        }
    }

    private function portfolio(): PortfolioService
    {
        return app(PortfolioService::class);
    }

    private function coverOf(PortfolioItem $item): ?int
    {
        $cover = DB::table('portfolio_items')->where('id', $item->getKey())->value('cover_media_id');

        return $cover === null ? null : (int) $cover;
    }

    /**
     * @param  list<MediaAsset>  $assets
     * @return list<int>
     */
    private function pivotOrder(PortfolioItem $item, array $assets): array
    {
        return array_map(
            static fn (MediaAsset $asset): int => (int) DB::table('portfolio_item_media')
                ->where('portfolio_item_id', $item->getKey())
                ->where('media_asset_id', $asset->getKey())
                ->value('sort_order'),
            $assets,
        );
    }
}
