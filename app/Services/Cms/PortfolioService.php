<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\ImageProfile;
use App\Enums\Cms\MediaCollection;
use App\Models\Cms\MediaAsset;
use App\Models\Cms\PortfolioCategory;
use App\Models\Cms\PortfolioItem;
use App\Models\Cms\PortfolioItemMedia;
use App\Services\Cms\Exceptions\ContentRuleException;
use App\Services\Cms\Media\GdImageProcessor;
use App\Services\Cms\Support\ContentHelper;
use App\Support\Format;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Portfolio case studies and their galleries (phase-04 §6.3, requirement §12).
 *
 * A gallery image is a `media_assets` row (Phase 3's library, `pages` / `Card`) attached through the
 * `portfolio_item_media` pivot; there is no `portfolio_images` table and no second uploader (D24, F-2.4).
 *
 * Invariants:
 *
 *   1. **Exactly one cover, and it is a column.** `setCover()` writes `portfolio_items.cover_media_id` and
 *      refuses an asset not attached to the item (422). The first image attached to an item without a
 *      cover becomes the cover; detaching the cover promotes the next attachment by pivot `sort_order`, or
 *      nulls the column when the gallery is empty.
 *   2. Every file goes through `MediaService::store()` — content-sniffed MIME, SVG refused, EXIF stripped,
 *      derivatives generated. Every attached image needs `alt_text` before the item may be published.
 *   3. `addImages()` holds at most 20 attachments per item and rejects the **whole batch** — nothing stored,
 *      nothing attached — when any file fails: every file is pre-flighted first, the stores run in one
 *      transaction, and a store that still fails removes the binaries this batch wrote (rows that never
 *      committed own nothing in the library). An identical file reuses its asset (checksum) and `uq_pim`
 *      turns a double attach into a no-op.
 *   4. `delete()` (soft) keeps every attachment and binary; `forceDelete()` detaches the pivot rows, removes
 *      the item and **deletes no file** — the assets are library content, removed only through the media
 *      library's own guarded delete once `usage_count` reaches zero.
 *   5. `reorderImages()` is id-checked against this item: an id not attached is a 422 and changes nothing;
 *      the pivot `sort_order` becomes 1..n in one transaction.
 */
final class PortfolioService
{
    private const MODULE = 'portfolio';

    private const LABEL = 'Project';

    public function __construct(
        private readonly ContentHelper $content,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $images
     * @param  array<int, int|string>  $technologyIds
     */
    public function store(array $data, array $images = [], array $technologyIds = []): PortfolioItem
    {
        $this->preflight($images);

        return $this->withBatchCleanup(function (array &$written) use ($data, $images, $technologyIds): PortfolioItem {
            $item = new PortfolioItem;
            $manualSlug = $this->content->manualSlug($data);
            $status = $this->content->contentStatus($data['status'] ?? null) ?? ContentStatus::Draft;

            if ($status === ContentStatus::Scheduled) {
                throw ContentRuleException::cannotSchedule(self::LABEL);
            }

            $item->fill($this->attributes($data, null));
            $item->setAttribute('slug', $manualSlug ?? '');
            $item->setAttribute('status', ContentStatus::Draft);

            if (! array_key_exists('sort_order', $data)) {
                $item->setAttribute('sort_order', (int) PortfolioItem::query()->withTrashed()->max('sort_order') + 1);
            }

            $this->content->saveWithSlug($item, $manualSlug !== null);
            $this->content->syncTechnologies('portfolio_item_technology', 'portfolio_item_id', (int) $item->getKey(), $technologyIds);

            if ($images !== []) {
                $assets = $this->storeFiles($images, $written);
                $this->attach($item, $assets->map(static fn (MediaAsset $asset): int => (int) $asset->getKey())->all());
            }

            // Created as a draft, then moved through the same gate a later publish uses (alt text,
            // name, slug) — with its own status_changed entry.
            if ($status !== ContentStatus::Draft) {
                $this->changeStatusLocked($item, $status);
            }

            $this->content->saveSeo($item, $data);

            return $item;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, int|string>  $technologyIds  the complete, ordered selection (an empty array clears it)
     */
    public function update(PortfolioItem $item, array $data, array $technologyIds = []): PortfolioItem
    {
        return $this->content->transaction(function () use ($item, $data, $technologyIds): PortfolioItem {
            $oldSlug = (string) $item->getAttribute('slug');
            $wasPublic = $this->isPublic($item);
            $statusBefore = $item->getAttribute('status');
            $manualSlug = $this->content->manualSlug($data);
            $status = $this->content->contentStatus($data['status'] ?? null);

            $item->fill($this->attributes($data, $item));

            if ($manualSlug !== null) {
                $item->setAttribute('slug', $manualSlug);
            }

            $this->content->saveWithSlug($item, $manualSlug !== null);

            if ((string) $item->getAttribute('slug') !== $oldSlug) {
                $this->content->auditSlugChange(
                    $item,
                    self::MODULE,
                    $oldSlug,
                    (string) $item->getAttribute('slug'),
                    in_array($statusBefore, [ContentStatus::Published, ContentStatus::Archived], true),
                );
            }

            $technologies = $this->content->syncTechnologies('portfolio_item_technology', 'portfolio_item_id', (int) $item->getKey(), $technologyIds);

            if ($technologies['old'] !== $technologies['new']) {
                $this->content->audit(
                    self::MODULE,
                    sprintf('Project "%s" technologies updated', (string) $item->getAttribute('title')),
                    $item,
                    ['old' => ['technology_ids' => $technologies['old']], 'attributes' => ['technology_ids' => $technologies['new']]],
                    null,
                    'technologies_synced',
                );
            }

            if ($status !== null) {
                $this->changeStatusLocked($item, $status);
            }

            $this->content->saveSeo($item, $data);

            if ($item->getAttribute('status') === $statusBefore && ($wasPublic || $this->isPublic($item))) {
                $this->content->flushPublicCache(sprintf('Project "%s" updated', (string) $item->getAttribute('title')));
            }

            return $item;
        });
    }

    /**
     * Upload files into the item's gallery.
     *
     * @param  array<int, UploadedFile>  $files
     * @return Collection<int, MediaAsset>
     */
    public function addImages(PortfolioItem $item, array $files): Collection
    {
        $this->preflight($files);

        if ($files === []) {
            return new Collection;
        }

        return $this->withBatchCleanup(function (array &$written) use ($item, $files): Collection {
            $this->lockItem($item);
            $this->assertRoom($item, count($files));

            $assets = $this->storeFiles($files, $written);
            $this->attach($item, $assets->map(static fn (MediaAsset $asset): int => (int) $asset->getKey())->all());

            return $assets;
        });
    }

    /**
     * Attach assets already in the media library ("Choose from library").
     *
     * @param  array<int, int|string>  $mediaAssetIds
     * @return Collection<int, MediaAsset>
     */
    public function attachAssets(PortfolioItem $item, array $mediaAssetIds): Collection
    {
        $ids = [];

        foreach ($mediaAssetIds as $id) {
            $ids[(int) $id] = $this->content->pickedMedia($id, 'media_asset_ids') ?? throw ContentRuleException::notAttached('media_asset_ids');
        }

        if ($ids === []) {
            return new Collection;
        }

        return $this->content->transaction(function () use ($item, $ids): Collection {
            $this->lockItem($item);

            $new = array_diff(array_values($ids), $this->attachedIds($item));
            $this->assertRoom($item, count($new));
            $this->attach($item, array_values($ids));

            return MediaAsset::query()->whereIn('id', array_values($ids))->get();
        });
    }

    public function detachImage(PortfolioItem $item, MediaAsset $asset): void
    {
        $this->content->transaction(function () use ($item, $asset): void {
            $this->lockItem($item);
            $assetId = (int) $asset->getKey();

            if (! in_array($assetId, $this->attachedIds($item), true)) {
                throw ContentRuleException::notAttached('image');
            }

            $this->content->connection()->table('portfolio_item_media')
                ->where('portfolio_item_id', $item->getKey())
                ->where('media_asset_id', $assetId)
                ->delete();

            if ((int) $item->getAttribute('cover_media_id') === $assetId) {
                $next = $this->content->connection()->table('portfolio_item_media')
                    ->where('portfolio_item_id', $item->getKey())
                    ->orderBy('sort_order')
                    ->orderBy('created_at')
                    ->value('media_asset_id');

                $this->writeCover($item, $next === null ? null : (int) $next);
            }

            $this->content->audit(
                self::MODULE,
                sprintf('Image removed from project "%s"', (string) $item->getAttribute('title')),
                $item,
                ['media_asset_id' => $assetId, 'cover_media_id' => $item->getAttribute('cover_media_id')],
                null,
                'image_detached',
            );

            $this->content->recountMediaAfterCommit([$assetId]);
            $this->flushIfPublic($item, 'gallery changed');
        });
    }

    /**
     * @param  array<int, int|string>  $orderedMediaAssetIds
     */
    public function reorderImages(PortfolioItem $item, array $orderedMediaAssetIds): void
    {
        $this->content->transaction(function () use ($item, $orderedMediaAssetIds): void {
            $this->lockItem($item);

            $attached = $this->attachedIds($item);
            $ids = [];

            foreach ($orderedMediaAssetIds as $id) {
                $id = is_numeric($id) ? (int) $id : 0;

                if (! in_array($id, $attached, true) || in_array($id, $ids, true)) {
                    throw ContentRuleException::foreignIds('ids');
                }

                $ids[] = $id;
            }

            // Listed images take 1..n; any attachment the request did not mention keeps its relative order after them.
            $final = [...$ids, ...array_values(array_diff($attached, $ids))];
            $connection = $this->content->connection();

            foreach ($final as $position => $assetId) {
                $connection->table('portfolio_item_media')
                    ->where('portfolio_item_id', $item->getKey())
                    ->where('media_asset_id', $assetId)
                    ->update(['sort_order' => $position + 1]);
            }

            $this->content->audit(
                self::MODULE,
                sprintf('Gallery of project "%s" reordered', (string) $item->getAttribute('title')),
                $item,
                ['ids' => $final],
                null,
                'images_reordered',
            );

            $this->flushIfPublic($item, 'gallery reordered');
        });
    }

    public function setCover(PortfolioItem $item, MediaAsset $asset): void
    {
        $this->content->transaction(function () use ($item, $asset): void {
            $this->lockItem($item);
            $assetId = (int) $asset->getKey();

            if (! in_array($assetId, $this->attachedIds($item), true)) {
                throw ContentRuleException::notAttached('image');
            }

            $old = $item->getAttribute('cover_media_id');

            if ((int) $old === $assetId) {
                return;
            }

            $this->writeCover($item, $assetId);

            $this->content->audit(
                self::MODULE,
                sprintf('Cover image of project "%s" changed', (string) $item->getAttribute('title')),
                $item,
                ['old' => ['cover_media_id' => $old], 'attributes' => ['cover_media_id' => $assetId]],
                null,
                'cover_set',
            );

            $this->flushIfPublic($item, 'cover changed');
        });
    }

    /**
     * The per-placement caption shown under a gallery image (`portfolio_item_media.caption`).
     */
    public function updateCaption(PortfolioItem $item, MediaAsset $asset, ?string $caption): void
    {
        $this->content->transaction(function () use ($item, $asset, $caption): void {
            $updated = $this->content->connection()->table('portfolio_item_media')
                ->where('portfolio_item_id', $item->getKey())
                ->where('media_asset_id', $asset->getKey())
                ->update(['caption' => $this->content->plain($caption, 255), 'updated_at' => Carbon::now()]);

            if ($updated === 0 && ! in_array((int) $asset->getKey(), $this->attachedIds($item), true)) {
                throw ContentRuleException::notAttached('image');
            }

            $this->flushIfPublic($item, 'caption changed');
        });
    }

    public function changeStatus(PortfolioItem $item, ContentStatus $status): PortfolioItem
    {
        return $this->content->transaction(fn (): PortfolioItem => $this->changeStatusLocked($item, $status));
    }

    public function toggleFeatured(PortfolioItem $item): PortfolioItem
    {
        return $this->content->transaction(function () use ($item): PortfolioItem {
            $this->content->toggleFlag($item, 'is_featured', self::MODULE, self::LABEL, 'title', 'featured', 'unfeatured');
            $this->flushIfPublic($item, 'featured toggled');

            return $item;
        });
    }

    public function delete(PortfolioItem $item): void
    {
        $this->content->transaction(function () use ($item): void {
            $wasPublic = $this->isPublic($item);

            $item->delete();

            if ($wasPublic) {
                $this->content->flushPublicCache(sprintf('Project "%s" deleted', (string) $item->getAttribute('title')));
            }
        });
    }

    public function forceDelete(PortfolioItem $item): void
    {
        $this->content->transaction(function () use ($item): void {
            $connection = $this->content->connection();
            $assetIds = $this->attachedIds($item);
            $wasPublic = $item->getAttribute('status') === ContentStatus::Published && ! $item->trashed();

            $connection->table('portfolio_item_media')->where('portfolio_item_id', $item->getKey())->delete();
            $connection->table('portfolio_item_technology')->where('portfolio_item_id', $item->getKey())->delete();

            $item->forceDelete();

            $this->content->recountMediaAfterCommit([...$assetIds, $item->getAttribute('cover_media_id')]);

            if ($wasPublic) {
                $this->content->flushPublicCache(sprintf('Project "%s" permanently deleted', (string) $item->getAttribute('title')));
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function changeStatusLocked(PortfolioItem $item, ContentStatus $status): PortfolioItem
    {
        return $this->content->changeContentStatus(
            $item,
            $status,
            self::MODULE,
            self::LABEL,
            'title',
            function () use ($item): void {
                $this->assertPublishable($item);
            },
        );
    }

    /**
     * §6.3 invariant 2: every attached image needs alt text before the project is published.
     */
    private function assertPublishable(PortfolioItem $item): void
    {
        $ids = $this->attachedIds($item);
        $cover = $item->getAttribute('cover_media_id');

        if ($cover !== null) {
            $ids[] = (int) $cover;
        }

        if ($ids === []) {
            return;
        }

        $missing = MediaAsset::query()
            ->whereIn('id', array_values(array_unique($ids)))
            ->where(static fn ($query) => $query->whereNull('alt_text')->orWhere('alt_text', ''))
            ->count();

        if ($missing > 0) {
            throw ContentRuleException::altTextRequired($missing);
        }
    }

    /**
     * Validate every file before anything is written, so a bad file in the batch stores nothing.
     *
     * @param  array<int, mixed>  $files
     */
    private function preflight(array $files): void
    {
        if (count($files) > PortfolioItemMedia::MAX_PER_ITEM) {
            throw ContentRuleException::galleryFull(PortfolioItemMedia::MAX_PER_ITEM, 0, count($files));
        }

        $limit = max(1, (int) $this->content->settings()->get('security.max_upload_mb', 10)) * 1024 * 1024;

        foreach (array_values($files) as $index => $file) {
            $field = 'images.'.$index;

            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                throw ContentRuleException::uploadRefused($field, 'The file could not be uploaded. Try again.');
            }

            $path = (string) $file->getRealPath();
            $size = $path === '' ? 0 : (int) @filesize($path);

            if ($size <= 0) {
                throw ContentRuleException::uploadRefused($field, 'The file is empty.');
            }

            if ($size > $limit) {
                throw ContentRuleException::uploadRefused($field, sprintf('Each image may be at most %d MB.', intdiv($limit, 1024 * 1024)));
            }

            $mime = strtolower((string) (new finfo(FILEINFO_MIME_TYPE))->file($path));
            $mime = $mime === 'image/jpg' || $mime === 'image/pjpeg' ? 'image/jpeg' : $mime;

            if (! isset(MediaService::IMAGE_MIMES[$mime])) {
                throw ContentRuleException::uploadRefused($field, 'Upload an image (JPEG, PNG, WebP, GIF or AVIF). SVG is not accepted.');
            }

            $info = @getimagesize($path);

            if (! is_array($info) || (int) ($info[0] ?? 0) < 1 || (int) ($info[1] ?? 0) < 1) {
                throw ContentRuleException::uploadRefused($field, 'The file is not a readable image.');
            }

            if ((int) $info[0] * (int) $info[1] > GdImageProcessor::MAX_PIXELS) {
                throw ContentRuleException::uploadRefused($field, 'The image has too many pixels to process safely.');
            }
        }
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @param  list<MediaAsset>  $written  assets this batch created (for the failure cleanup)
     * @return Collection<int, MediaAsset>
     */
    private function storeFiles(array $files, array &$written): Collection
    {
        $assets = new Collection;

        foreach (array_values($files) as $index => $file) {
            $asset = $this->content->storeImage($file, MediaCollection::Pages, ImageProfile::Card, 'images.'.$index);

            if ($asset->wasRecentlyCreated) {
                $written[] = $asset;
            }

            $assets->push($asset);
        }

        return $assets->unique(static fn (MediaAsset $asset): int => (int) $asset->getKey())->values();
    }

    /**
     * Run a batch in one transaction; if it fails, remove the binaries of the assets it created (their rows
     * rolled back with it, so nothing in the library refers to them).
     *
     * @template TResult
     *
     * @param  callable(list<MediaAsset>&): TResult  $callback
     * @return TResult
     */
    private function withBatchCleanup(callable $callback): mixed
    {
        /** @var list<MediaAsset> $written */
        $written = [];

        try {
            return $this->content->transaction(function () use ($callback, &$written): mixed {
                return $callback($written);
            });
        } catch (Throwable $exception) {
            foreach ($written as $asset) {
                try {
                    if (! MediaAsset::withTrashed()->whereKey($asset->getKey())->exists()) {
                        Storage::disk((string) ($asset->getAttribute('disk') ?: MediaService::DISK))
                            ->deleteDirectory((string) $asset->getAttribute('directory'));
                    }
                } catch (Throwable $cleanup) {
                    report($cleanup);
                }
            }

            throw $exception;
        }
    }

    /**
     * Attach asset ids (in order) after the item's current last position; a repeat is a no-op (`uq_pim`).
     * The first attachment of an item without a cover becomes its cover.
     *
     * @param  list<int>  $assetIds
     */
    private function attach(PortfolioItem $item, array $assetIds): void
    {
        $connection = $this->content->connection();
        $already = $this->attachedIds($item);
        $position = (int) $connection->table('portfolio_item_media')->where('portfolio_item_id', $item->getKey())->max('sort_order');
        $now = Carbon::now();
        $added = [];

        foreach (array_values(array_unique($assetIds)) as $assetId) {
            if (in_array($assetId, $already, true)) {
                continue;
            }

            $inserted = $connection->table('portfolio_item_media')->insertOrIgnore([
                'portfolio_item_id' => (int) $item->getKey(),
                'media_asset_id' => $assetId,
                'sort_order' => ++$position,
                'caption' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => $this->content->actorId(),
            ]);

            if ($inserted > 0) {
                $added[] = $assetId;
            }
        }

        $this->assertRoom($item, 0);

        if ($item->getAttribute('cover_media_id') === null) {
            $first = $connection->table('portfolio_item_media')
                ->where('portfolio_item_id', $item->getKey())
                ->orderBy('sort_order')
                ->value('media_asset_id');

            if ($first !== null) {
                $this->writeCover($item, (int) $first);
            }
        }

        if ($added !== []) {
            $this->content->audit(
                self::MODULE,
                sprintf('%d %s added to project "%s"', count($added), count($added) === 1 ? 'image' : 'images', (string) $item->getAttribute('title')),
                $item,
                ['media_asset_ids' => $added, 'cover_media_id' => $item->getAttribute('cover_media_id')],
                null,
                'images_attached',
            );

            $this->content->recountMediaAfterCommit($added);
            $this->flushIfPublic($item, 'gallery changed');
        }
    }

    private function assertRoom(PortfolioItem $item, int $adding): void
    {
        $current = count($this->attachedIds($item));

        if ($current + $adding > PortfolioItemMedia::MAX_PER_ITEM) {
            throw ContentRuleException::galleryFull(PortfolioItemMedia::MAX_PER_ITEM, $current, $adding);
        }
    }

    /**
     * @return list<int> attached asset ids in gallery order
     */
    private function attachedIds(PortfolioItem $item): array
    {
        return $this->content->connection()->table('portfolio_item_media')
            ->where('portfolio_item_id', $item->getKey())
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->pluck('media_asset_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function writeCover(PortfolioItem $item, ?int $assetId): void
    {
        $this->content->quietly($item, function () use ($item, $assetId): void {
            $item->forceFill(['cover_media_id' => $assetId])->save();
        });
    }

    private function lockItem(PortfolioItem $item): void
    {
        $fresh = PortfolioItem::withTrashed()->whereKey($item->getKey())->lockForUpdate()->first(['id', 'cover_media_id']);

        if ($fresh !== null) {
            $item->setAttribute('cover_media_id', $fresh->getAttribute('cover_media_id'));
            $item->syncOriginalAttribute('cover_media_id');
        }
    }

    private function isPublic(PortfolioItem $item): bool
    {
        return $item->getAttribute('status') === ContentStatus::Published && ! $item->trashed();
    }

    private function flushIfPublic(PortfolioItem $item, string $what): void
    {
        if ($this->isPublic($item)) {
            $this->content->flushPublicCache(sprintf('Project "%s" %s', (string) $item->getAttribute('title'), $what));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?PortfolioItem $item): array
    {
        $attributes = [];
        $creating = $item === null;

        if ($creating || array_key_exists('title', $data)) {
            $title = $this->content->plain($data['title'] ?? null, 180);

            if ($title === null) {
                throw ContentRuleException::refuse('title', 'A project name is required.');
            }

            $attributes['title'] = $title;
        }

        if (array_key_exists('portfolio_category_id', $data)) {
            $categoryId = $this->content->id($data['portfolio_category_id']);

            if ($categoryId !== null && ! PortfolioCategory::query()->whereKey($categoryId)->exists()) {
                throw ContentRuleException::refuse('portfolio_category_id', 'The chosen category no longer exists.');
            }

            $attributes['portfolio_category_id'] = $categoryId;
        }

        foreach (['client_name' => 150, 'summary' => 500, 'technologies_note' => 255] as $column => $limit) {
            if (array_key_exists($column, $data)) {
                $attributes[$column] = $this->content->plain($data[$column], $limit);
            }
        }

        if (array_key_exists('client_id', $data)) {
            $attributes['client_id'] = $this->content->id($data['client_id']);
        }

        if (array_key_exists('description', $data)) {
            $attributes['description'] = $this->content->rich($data['description']);
        }

        if (array_key_exists('project_url', $data)) {
            $attributes['project_url'] = $this->content->url($data['project_url'], 'project_url');
        }

        if (array_key_exists('completion_date', $data)) {
            $attributes['completion_date'] = $this->completionDate($data['completion_date']);
        }

        if ($creating || array_key_exists('is_featured', $data)) {
            $attributes['is_featured'] = $this->content->bool($data['is_featured'] ?? null, false);
        }

        if (array_key_exists('sort_order', $data)) {
            $attributes['sort_order'] = $this->content->int($data['sort_order'], 0, 0);
        }

        return $attributes;
    }

    private function completionDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = $value instanceof \DateTimeInterface
                ? Carbon::instance($value)
                : Carbon::createFromFormat('!Y-m-d', (string) $value);
        } catch (Throwable) {
            $date = null;
        }

        if (! $date instanceof Carbon) {
            throw ContentRuleException::refuse('completion_date', 'Enter the completion date as YYYY-MM-DD.');
        }

        if ($date->toDateString() > Carbon::now(Format::timezone())->toDateString()) {
            throw ContentRuleException::refuse('completion_date', 'The completion date cannot be in the future.');
        }

        return $date->toDateString();
    }
}
