<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Enums\Cms\ImageProfile;
use App\Enums\Cms\MediaCollection;
use App\Enums\Cms\MediaProcessingStatus;
use App\Http\Controllers\Admin\Cms\Concerns\RespondsForCms;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\CmsListRequest;
use App\Http\Requests\Cms\DeleteMediaRequest;
use App\Http\Requests\Cms\StoreMediaRequest;
use App\Http\Requests\Cms\UpdateMediaRequest;
use App\Models\Cms\MediaAsset;
use App\Services\Cms\MediaService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The media library — `admin.website.media.*` (phase-03 §7.5, §8.13): the grid, upload, detail drawer,
 * alt text / caption, usage, derivative regeneration and delete.
 *
 * Every rule that matters lives in `MediaService` (D24): content-based type detection and the SVG
 * refusal (INV-11, FT-33), ULID paths (FT-34), checksum dedupe (FT-38), and the in-use delete guard.
 * A delete is refused **with the usage list** (FT-38) — by `MediaPolicy::delete()` while `usage_count`
 * is above zero, and by the service for a Super Admin, who bypasses the policy. Files are never deleted
 * from disk ([D-W3-17]).
 */
final class MediaController extends Controller
{
    use RespondsForCms;

    /** The grid shows thumbnails, so it pages in multiples of a 4- and 6-column layout. */
    private const GRID_PAGE_SIZE = 24;

    public function __construct(
        private readonly MediaService $media,
    ) {}

    public function index(CmsListRequest $request): View|JsonResponse
    {
        $this->authorize('website_media.view_any');

        $collection = $request->filterEnum('collection', MediaCollection::class);
        $derivatives = $request->filterEnum('derivatives', MediaProcessingStatus::class);
        $type = $request->filterString('type');
        $unused = $request->filterBool('unused');

        $assets = MediaAsset::query()
            ->search($request->searchTerm())
            ->when($collection instanceof MediaCollection, static fn (Builder $query) => $query->inCollection($collection))
            ->when($derivatives instanceof MediaProcessingStatus, static fn (Builder $query) => $query->where('derivatives_status', $derivatives->value))
            ->when($type === 'image', static fn (Builder $query) => $query->images())
            ->when($type === 'video', static fn (Builder $query) => $query->videos())
            ->when($unused === true, static fn (Builder $query) => $query->unused())
            ->when($unused === false, static fn (Builder $query) => $query->where('usage_count', '>', 0))
            ->ordered()
            ->paginate(self::GRID_PAGE_SIZE)
            ->withQueryString();

        if ($request->expectsJson()) {
            return new JsonResponse([
                'data' => $assets->getCollection()->map(fn (MediaAsset $asset): array => $this->card($asset))->values()->all(),
                'meta' => ['current_page' => $assets->currentPage(), 'last_page' => $assets->lastPage(), 'total' => $assets->total()],
            ]);
        }

        $user = $this->actor($request);

        return view('admin.cms.media.index', [
            'assets' => $assets,
            'cards' => $assets->getCollection()->mapWithKeys(fn (MediaAsset $asset): array => [(int) $asset->getKey() => $this->card($asset)])->all(),
            'collectionOptions' => MediaCollection::options(),
            'derivativeOptions' => MediaProcessingStatus::options(),
            'profileOptions' => ImageProfile::options(),
            'maxUploadMb' => (int) (is_numeric(setting('security.max_upload_mb', 10)) ? setting('security.max_upload_mb', 10) : 10),
            'filters' => $request->activeFilters(),
            'can' => [
                'upload' => $user->can('website_media.upload'),
                'edit' => $user->can('website_media.edit'),
                'delete' => $user->can('website_media.delete'),
            ],
        ]);
    }

    /**
     * One upload (`admin.website.media.store`). Re-uploading identical bytes returns the existing item.
     */
    public function store(StoreMediaRequest $request): Response
    {
        $this->authorize('website_media.upload');
        $this->authorize('upload', MediaAsset::class);

        $file = $request->file('file');
        abort_unless($file !== null && ! is_array($file), Response::HTTP_UNPROCESSABLE_ENTITY);

        return $this->attempt($request, function () use ($request, $file): Response {
            $asset = $this->media->store($file, $request->collection(), $request->profile(), $request->meta());

            return $this->done(
                $request,
                $asset->wasRecentlyCreated
                    ? sprintf('"%s" was uploaded.', $asset->original_name)
                    : sprintf('"%s" is already in the library — the existing item was reused.', $asset->original_name),
                redirect()->route('admin.website.media.index'),
                ['asset' => $this->card($asset), 'created' => $asset->wasRecentlyCreated],
            );
        }, field: 'file');
    }

    /**
     * The detail drawer: preview, dimensions, variants, alt text and the places the item is used.
     */
    public function show(Request $request, MediaAsset $asset): View|JsonResponse
    {
        $this->authorize('website_media.view');
        $this->authorize('view', $asset);

        $usage = $this->media->usage($asset)->values();

        if ($request->expectsJson()) {
            return new JsonResponse(['asset' => $this->card($asset), 'variants' => $asset->variants ?? [], 'usage' => $usage->all()]);
        }

        return view('admin.cms.media.show', [
            'asset' => $asset,
            'card' => $this->card($asset),
            'variants' => (array) ($asset->variants ?? []),
            'usage' => $usage,
            'can' => [
                'edit' => $request->user()?->can('website_media.edit') === true,
                'delete' => $request->user()?->can('delete', $asset) === true && $usage->isEmpty(),
            ],
        ]);
    }

    public function update(UpdateMediaRequest $request, MediaAsset $asset): Response
    {
        $this->authorize('website_media.edit');
        $this->authorize('update', $asset);

        $asset = $this->media->updateDetails($asset, $request->details());

        return $this->done($request, 'Media details saved.', null, ['asset' => $this->card($asset)]);
    }

    public function usage(Request $request, MediaAsset $asset): View|JsonResponse
    {
        $this->authorize('website_media.view');
        $this->authorize('usage', $asset);

        $usage = $this->media->usage($asset)->values();

        if ($request->expectsJson()) {
            return new JsonResponse(['id' => (int) $asset->getKey(), 'usage' => $usage->all()]);
        }

        return view('admin.cms.media.usage', [
            'asset' => $asset,
            'usage' => $usage,
        ]);
    }

    public function regenerate(Request $request, MediaAsset $asset): Response
    {
        $this->authorize('website_media.edit');

        if (! $asset->isImage()) {
            return $this->refuse($request, 'Only images have derivatives to regenerate.', Response::HTTP_UNPROCESSABLE_ENTITY, 'asset');
        }

        $this->authorize('regenerate', $asset);

        $asset = $this->media->regenerate($asset);

        return $this->done($request, 'Derivatives are being regenerated.', null, ['asset' => $this->card($asset)]);
    }

    /**
     * Soft delete, refused with the usage list while anything still uses the item (FT-38).
     */
    public function destroy(DeleteMediaRequest $request, MediaAsset $asset): Response
    {
        $this->authorize('website_media.delete');

        if ($request->user()?->cannot('delete', $asset)) {
            $usage = $this->media->usage($asset);

            abort_if($usage->isEmpty(), Response::HTTP_FORBIDDEN);

            return $this->inUse(
                $request,
                sprintf('"%s" is still used in %d %s and cannot be deleted. Remove it there first.', $asset->title ?: $asset->original_name, $usage->count(), $usage->count() === 1 ? 'place' : 'places'),
                $usage,
            );
        }

        return $this->attempt($request, function () use ($request, $asset): Response {
            $this->media->delete($asset, $request->reason());

            return $this->done(
                $request,
                sprintf('"%s" was deleted from the library.', $asset->title ?: $asset->original_name),
                redirect()->route('admin.website.media.index'),
            );
        }, Response::HTTP_FORBIDDEN);
    }

    /**
     * The grid card and the JSON shape the uploader and the picker read.
     *
     * @return array<string, mixed>
     */
    private function card(MediaAsset $asset): array
    {
        $status = $asset->derivatives_status;

        return [
            'id' => (int) $asset->getKey(),
            'name' => (string) ($asset->title ?: $asset->original_name),
            'original_name' => (string) $asset->original_name,
            'alt_text' => $asset->alt_text,
            'caption' => $asset->caption,
            'mime_type' => (string) $asset->mime_type,
            'kind' => $asset->isVideo() ? 'video' : 'image',
            'width' => $asset->width === null ? null : (int) $asset->width,
            'height' => $asset->height === null ? null : (int) $asset->height,
            'size_bytes' => (int) $asset->size_bytes,
            'usage_count' => (int) $asset->usage_count,
            'derivatives_status' => $status instanceof MediaProcessingStatus ? $status->value : (string) $status,
            'url' => $this->media->url($asset),
            'thumbnail_url' => $asset->isImage() ? $this->media->url($asset, 384) : null,
            'show_url' => route('admin.website.media.show', $asset),
        ];
    }
}
