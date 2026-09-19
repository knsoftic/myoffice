<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Http\Controllers\Admin\Cms\Concerns\RespondsForContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\ReorderRequest;
use App\Http\Requests\Cms\StorePortfolioImagesRequest;
use App\Http\Requests\Cms\UpdatePortfolioImageCaptionRequest;
use App\Models\Cms\MediaAsset;
use App\Models\Cms\PortfolioItem;
use App\Services\Cms\PortfolioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gallery manager of a portfolio item — `admin.portfolio.images.*` (phase-04 §2.8, §6.3, §7.2,
 * §8.3), `module:portfolio`.
 *
 *   · store   `can:portfolio.upload` — new uploads through `PortfolioService::addImages()` (all or
 *             nothing, `MediaService` validates by content), or picks from the media library;
 *   · destroy `can:portfolio.edit`   — *remove from this project*: detaches the pivot row only, the file
 *             stays in the library; detaching the cover promotes the next image (§6.3 invariant 1);
 *   · reorder `can:portfolio.edit`   — `ids` are media-asset ids; one that is not attached is a 422;
 *   · cover   `can:portfolio.edit`   — `setCover()` refuses an asset that is not attached (422).
 *
 * `{image}` is a media-asset id resolved with trashed rows included: a library asset soft-deleted
 * elsewhere must still be removable from the gallery. Whether it is attached to *this* item is the
 * service's question, asked under its lock.
 */
final class PortfolioImageController extends Controller
{
    use RespondsForContent;

    public function __construct(
        private readonly PortfolioService $portfolio,
    ) {}

    public function store(StorePortfolioImagesRequest $request, PortfolioItem $item): Response
    {
        $this->authorize('portfolio.upload');
        $this->authorize('addImages', $item);

        return $this->attempt($request, function () use ($request, $item): Response {
            $uploads = $request->uploads();

            $added = $uploads !== []
                ? $this->portfolio->addImages($item, $uploads)
                : $this->portfolio->attachAssets($item, $request->mediaAssetIds());

            $count = count($added);

            return $this->done(
                $request,
                $count === 1 ? '1 image was added to the gallery.' : sprintf('%d images were added to the gallery.', $count),
                redirect()->route('admin.portfolio.edit', $item),
                ['media_asset_ids' => $added->map(static fn (MediaAsset $asset): int => (int) $asset->getKey())->values()->all(), 'total' => $this->attachedCount($item)],
            );
        }, field: $request->uploads() !== [] ? 'images' : 'media_asset_ids');
    }

    public function destroy(Request $request, PortfolioItem $item, string $image): Response
    {
        $this->authorize('portfolio.edit');
        $this->authorize('manageImages', $item);

        $asset = $this->asset($image);

        return $this->attempt($request, function () use ($request, $item, $asset): Response {
            $this->portfolio->detachImage($item, $asset);

            return $this->done(
                $request,
                'The image was removed from this project. It is still in the media library.',
                redirect()->route('admin.portfolio.edit', $item),
                ['total' => $this->attachedCount($item), 'cover_media_id' => $item->fresh()?->cover_media_id],
            );
        }, field: 'image');
    }

    public function reorder(ReorderRequest $request, PortfolioItem $item): Response
    {
        $this->authorize('portfolio.edit');
        $this->authorize('manageImages', $item);

        return $this->attempt($request, function () use ($request, $item): Response {
            $this->portfolio->reorderImages($item, $request->orderedIds());

            return $this->done($request, 'Gallery order saved.', null, ['ids' => $request->orderedIds()]);
        }, field: 'ids');
    }

    public function cover(Request $request, PortfolioItem $item, string $image): Response
    {
        $this->authorize('portfolio.edit');
        $this->authorize('manageImages', $item);

        $asset = $this->asset($image);

        return $this->attempt($request, function () use ($request, $item, $asset): Response {
            $this->portfolio->setCover($item, $asset);

            return $this->done(
                $request,
                'Cover image updated.',
                redirect()->route('admin.portfolio.edit', $item),
                ['cover_media_id' => (int) $asset->getKey()],
            );
        }, field: 'image');
    }

    /**
     * The per-placement caption (§8.3) through `PortfolioService::updateCaption()`. The route is not in
     * §7.2 — see the integration file; the gallery renders the inline editor only when it exists.
     */
    public function caption(UpdatePortfolioImageCaptionRequest $request, PortfolioItem $item, string $image): Response
    {
        $this->authorize('portfolio.edit');
        $this->authorize('manageImages', $item);

        $asset = $this->asset($image);

        return $this->attempt($request, function () use ($request, $item, $asset): Response {
            $this->portfolio->updateCaption($item, $asset, $request->caption());

            return $this->done(
                $request,
                'Caption saved.',
                redirect()->route('admin.portfolio.edit', $item),
                ['media_asset_id' => (int) $asset->getKey(), 'caption' => $request->caption()],
            );
        }, field: 'caption');
    }

    private function asset(string $id): MediaAsset
    {
        $asset = MediaAsset::query()->withTrashed()->find($this->routeId($id));

        abort_unless($asset instanceof MediaAsset, Response::HTTP_NOT_FOUND);

        return $asset;
    }

    private function attachedCount(PortfolioItem $item): int
    {
        return DB::table('portfolio_item_media')->where('portfolio_item_id', $item->getKey())->count();
    }
}
