<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Enums\Cms\ImageProfile;
use App\Enums\Cms\MediaCollection;
use App\Enums\Cms\MediaProcessingStatus;
use App\Models\Cms\MediaAsset;
use App\Services\Cms\Exceptions\MediaInUseException;
use App\Services\Cms\Exceptions\UnsupportedUploadException;
use App\Services\Cms\Media\GdImageProcessor;
use App\Support\SettingsRepository;
use finfo;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * The one CMS media library — decision **D24**, phase-03 §6.8, INV-11, [D-W3-15], [D-W3-17].
 *
 * Every image rendered on the public website is a `media_assets` row stored through `store()`; no
 * other uploader exists. One public entry point per operation:
 *
 *   store()               upload an image or a background video
 *   generateDerivatives() produce the ImageProfile widths (WebP + the original format)
 *   regenerate()          queue that again (the library's "Regenerate derivatives")
 *   updateDetails()       alt text, title, caption — the only human-editable columns
 *   delete()              soft-delete, refused while anything still references the asset
 *   recountUsage()        refresh the `usage_count` cache (one asset, a set, or all)
 *   usage()               the list of places an asset is used, for the refusal and the popover
 *   url() / srcset() / sizes() / toSnapshot()   the only place a row becomes a `<picture>`
 *
 * Invariants:
 *
 *   · **Content, not claims (INV-11).** The MIME comes from `finfo` on the temporary file; the
 *     extension and the client's `Content-Type` are ignored. An image must also survive
 *     `getimagesize()` and agree with `finfo`. SVG is refused with its reason.
 *   · **Safe, deterministic storage (FT-34).** `cms/{Y}/{m}/{ULID}/{ULID}.{ext}` on the `public` disk;
 *     the user's filename is kept only in `original_name` and never becomes part of a path.
 *   · **Metadata stripped, orientation applied first.** Every image except a GIF within the width
 *     ceiling is decoded and re-encoded; a format this server cannot re-encode is refused rather
 *     than stored with its EXIF (GPS) intact.
 *   · **Never upscaled.** Originals are downscaled to `website.image_max_width`; no derivative is
 *     wider or taller than its source.
 *   · **De-duplicated.** The sha256 of the uploaded bytes is `checksum`; uploading the same file again
 *     returns the existing row (restoring it from the trash if needed) and writes no second directory.
 *   · **Delete guard (FT-38).** `delete()` recounts live references inside the transaction and throws
 *     `MediaInUseException` with the usage list while any exist. A delete is a soft delete; the files
 *     stay, so a restore is lossless. Nothing in this class deletes an original automatically.
 *   · **Never a broken srcset.** `srcset()` is empty unless `derivatives_status` is usable.
 */
final class MediaService
{
    public const DISK = 'public';

    private const MODULE = 'website_media';

    /** @var array<string, string> accepted image MIME => stored extension */
    public const IMAGE_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/avif' => 'avif',
    ];

    /** @var array<string, string> accepted video MIME => stored extension */
    public const VIDEO_MIMES = [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
    ];

    /** Queued job that calls `generateDerivatives()` (§10.2). Run synchronously when absent. */
    private const DERIVATIVES_JOB = 'App\\Jobs\\Cms\\GenerateImageDerivatives';

    /** ULIDs in stored paths, as they appear in plain or JSON-escaped text. */
    private const ULID_IN_TEXT = '~cms(?:\\\\/|/)\d{4}(?:\\\\/|/)\d{2}(?:\\\\/|/)([0-9A-HJKMNP-TV-Z]{26})~i';

    public function __construct(
        private readonly FilesystemFactory $storage,
        private readonly DatabaseManager $db,
        private readonly SettingsRepository $settings,
        private readonly GdImageProcessor $images,
        private readonly CacheVersion $cache,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Upload
    |--------------------------------------------------------------------------
    */

    /**
     * Store an upload in the library.
     *
     * @param  array{alt_text?: string|null, title?: string|null, caption?: string|null}  $meta
     *
     * @throws UnsupportedUploadException
     */
    public function store(
        UploadedFile $file,
        MediaCollection $collection = MediaCollection::General,
        ?ImageProfile $profile = null,
        array $meta = [],
    ): MediaAsset {
        $path = $file->getRealPath();

        if (! $file->isValid() || ! is_string($path) || $path === '' || ! is_readable($path)) {
            throw UnsupportedUploadException::unreadable();
        }

        $size = (int) filesize($path);

        if ($size <= 0) {
            throw UnsupportedUploadException::empty();
        }

        $limit = $this->maxUploadBytes();

        if ($size > $limit) {
            throw UnsupportedUploadException::tooLarge($size, $limit);
        }

        $mime = $this->detectMime($path);
        $isImage = isset(self::IMAGE_MIMES[$mime]);
        $isVideo = isset(self::VIDEO_MIMES[$mime]);

        if (! $isImage && ! $isVideo) {
            throw UnsupportedUploadException::mime($mime, array_merge(array_keys(self::IMAGE_MIMES), array_keys(self::VIDEO_MIMES)));
        }

        if ($isImage) {
            $this->assertRealImage($path, $mime);
        }

        $checksum = (string) hash_file('sha256', $path);
        $existing = $this->findByChecksum($checksum);

        if ($existing !== null) {
            return $this->reuse($existing, $meta, $file, $mime);
        }

        $profile ??= $isImage ? $collection->defaultProfile() : null;

        $processed = $isImage
            ? $this->normaliseImage($path, $mime)
            : ['bytes' => null, 'width' => null, 'height' => null];

        $ulid = (string) Str::ulid();
        $directory = sprintf('%s/%s/%s', $collection->diskPath(), Carbon::now()->format('Y/m'), $ulid);
        $filename = $ulid.'.'.($isImage ? self::IMAGE_MIMES[$mime] : self::VIDEO_MIMES[$mime]);
        $disk = $this->disk(self::DISK);

        $written = $processed['bytes'] !== null
            ? $disk->put($directory.'/'.$filename, $processed['bytes'], ['visibility' => 'public'])
            : $disk->putFileAs($directory, $file, $filename, ['visibility' => 'public']);

        if ($written === false) {
            throw new UnsupportedUploadException('The file could not be written to the media library.');
        }

        $attributes = [
            'disk' => self::DISK,
            'directory' => $directory,
            'filename' => $filename,
            'original_name' => $this->originalName($file),
            'mime_type' => $mime,
            'extension' => $isImage ? self::IMAGE_MIMES[$mime] : self::VIDEO_MIMES[$mime],
            'size_bytes' => $processed['bytes'] !== null ? strlen($processed['bytes']) : $size,
            'width' => $processed['width'],
            'height' => $processed['height'],
            'checksum' => $checksum,
            'collection' => $collection,
            'profile' => $profile,
            'variants' => null,
            'alt_text' => $this->plain($meta['alt_text'] ?? null, 255),
            'title' => $this->plain($meta['title'] ?? null, 191),
            'caption' => $this->plain($meta['caption'] ?? null, 500),
            'derivatives_status' => $isImage ? MediaProcessingStatus::Pending : MediaProcessingStatus::Skipped,
            'usage_count' => 0,
        ];

        try {
            $asset = $this->db->connection()->transaction(function () use ($attributes, $isImage): MediaAsset {
                /** @var MediaAsset $asset */
                $asset = MediaAsset::query()->create($attributes);

                if ($isImage) {
                    $this->queueDerivatives($asset);
                }

                return $asset;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent upload of the same bytes won the insert: keep theirs, drop ours.
            $this->deleteDirectory($directory);
            $existing = $this->findByChecksum($checksum);

            if ($existing === null) {
                throw new UnsupportedUploadException('The upload collided with another and could not be stored. Try again.');
            }

            return $this->reuse($existing, $meta, $file, $mime);
        } catch (Throwable $exception) {
            $this->deleteDirectory($directory);

            throw $exception;
        }

        return $asset;
    }

    /*
    |--------------------------------------------------------------------------
    | Derivatives
    |--------------------------------------------------------------------------
    */

    /**
     * Produce every derivative of the asset's profile that does not upscale, WebP plus the original
     * format, and record them in `variants`.
     *
     * A failure is recorded (`failed` + `failure_reason`) and re-thrown so a queued job can retry; an
     * image over the pixel ceiling is marked `failed` and not re-thrown, because retrying cannot help.
     */
    public function generateDerivatives(MediaAsset $asset): MediaAsset
    {
        if (! $asset->isImage()) {
            $this->writeStatus($asset, MediaProcessingStatus::Skipped, null, ['variants' => null]);

            return $this->fresh($asset);
        }

        $this->writeStatus($asset, MediaProcessingStatus::Processing);

        try {
            $disk = $this->disk((string) $asset->disk);
            $bytes = $disk->get($asset->path());

            if (! is_string($bytes) || $bytes === '') {
                throw new RuntimeException('The original file is missing from the media library.');
            }

            [$width, $height] = $this->images->dimensions($bytes);

            try {
                $this->images->guardPixels($width, $height);
            } catch (RuntimeException $tooLarge) {
                $this->writeStatus($asset, MediaProcessingStatus::Failed, $tooLarge->getMessage());

                return $this->fresh($asset);
            }

            $source = $this->images->decode($bytes);
            $profile = $asset->profile instanceof ImageProfile ? $asset->profile : $asset->collection->defaultProfile();
            $quality = max(60, min(95, (int) $this->settings->get('website.image_quality', 82)));
            $webp = (bool) $this->settings->get('website.image_webp_enabled', true) && $this->images->supportsWebp();
            $originalFormat = $this->originalFormat((string) $asset->mime_type, $profile);
            $base = pathinfo((string) $asset->filename, PATHINFO_FILENAME);

            $variants = [];
            $written = [];

            foreach ($profile->widths() as $targetWidth) {
                $targetHeight = $profile->heightFor($targetWidth);
                $primary = $this->images->derivative($source, $targetWidth, $targetHeight, $originalFormat, $quality);

                if ($primary === null) {
                    continue;
                }

                $path = sprintf('%s/%s-%d.%s', trim((string) $asset->directory, '/'), $base, $targetWidth, $originalFormat);
                $disk->put($path, $primary['bytes'], ['visibility' => 'public']);
                $written[] = $path;

                $entry = [
                    'path' => $path,
                    'format' => $originalFormat,
                    'mime_type' => $this->mimeFor($originalFormat),
                    'width' => $primary['width'],
                    'height' => $primary['height'],
                    'size_bytes' => strlen($primary['bytes']),
                ];

                if ($webp && $originalFormat !== 'webp') {
                    $alternate = $this->images->derivative($source, $targetWidth, $targetHeight, 'webp', $quality);

                    if ($alternate !== null) {
                        $webpPath = sprintf('%s/%s-%d.webp', trim((string) $asset->directory, '/'), $base, $targetWidth);
                        $disk->put($webpPath, $alternate['bytes'], ['visibility' => 'public']);
                        $written[] = $webpPath;

                        $entry['webp'] = [
                            'path' => $webpPath,
                            'format' => 'webp',
                            'mime_type' => 'image/webp',
                            'size_bytes' => strlen($alternate['bytes']),
                        ];
                    }
                } elseif ($originalFormat === 'webp') {
                    $entry['webp'] = [
                        'path' => $path,
                        'format' => 'webp',
                        'mime_type' => 'image/webp',
                        'size_bytes' => $entry['size_bytes'],
                    ];
                }

                $variants[(string) $targetWidth] = $entry;
            }

            unset($source);

            $this->removeStaleVariants($asset, $written);

            $this->writeStatus($asset, MediaProcessingStatus::Ready, null, [
                'variants' => json_encode($variants === [] ? new stdClass : $variants, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'profile' => $profile->value,
                'width' => $width,
                'height' => $height,
                'derivatives_generated_at' => Carbon::now(),
            ]);
        } catch (Throwable $exception) {
            $this->writeStatus($asset, MediaProcessingStatus::Failed, $exception->getMessage());

            throw $exception;
        }

        return $this->fresh($asset);
    }

    /**
     * Reset to `pending` and queue generation again (§8.13 "Regenerate derivatives").
     */
    public function regenerate(MediaAsset $asset): MediaAsset
    {
        if (! $asset->isImage()) {
            return $asset;
        }

        $this->db->connection()->transaction(function () use ($asset): void {
            $this->writeStatus($asset, MediaProcessingStatus::Pending);
            $this->queueDerivatives($asset);
        });

        return $this->fresh($asset);
    }

    /*
    |--------------------------------------------------------------------------
    | Details, usage and delete
    |--------------------------------------------------------------------------
    */

    /**
     * Alt text, title and caption — the only columns `website_media.edit` may change (§4.1).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException on any other key
     */
    public function updateDetails(MediaAsset $asset, array $data): MediaAsset
    {
        $unknown = array_diff(array_keys($data), ['alt_text', 'title', 'caption']);

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Only alt text, title and caption can be edited on a media item; received [%s].',
                implode(', ', $unknown)
            ));
        }

        $limits = ['alt_text' => 255, 'title' => 191, 'caption' => 500];
        $changes = [];

        foreach ($data as $key => $value) {
            $changes[$key] = $this->plain(is_scalar($value) || $value === null ? $value : null, $limits[$key]);
        }

        $this->db->connection()->transaction(function () use ($asset, $changes): void {
            $asset->fill($changes);

            if (! $asset->isDirty()) {
                return;
            }

            $asset->save();

            if ($asset->isInUse()) {
                $this->cache->bumpAfterCommit(sprintf('Media #%d details changed', $asset->getKey()));
            }
        });

        return $asset;
    }

    /**
     * Soft-delete an asset nothing references. The files stay on disk ([D-W3-17]).
     *
     * @throws MediaInUseException with the usage list while any live reference exists
     */
    public function delete(MediaAsset $asset, ?string $reason = null): void
    {
        $refusal = $this->db->connection()->transaction(function () use ($asset, $reason): ?MediaInUseException {
            /** @var MediaAsset|null $locked */
            $locked = MediaAsset::query()->whereKey($asset->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return null;
            }

            $usage = $this->usage($locked);
            $count = $usage->count();

            if ($count > 0) {
                // Refresh the cache the policy reads, then refuse once this transaction has ended.
                $this->db->connection()->table('media_assets')
                    ->where('id', $locked->getKey())
                    ->update(['usage_count' => $count]);

                return MediaInUseException::asset(
                    (int) $locked->getKey(),
                    (string) ($locked->title ?: $locked->original_name),
                    $count,
                    $usage->values()->all()
                );
            }

            $reason = $reason === null ? '' : trim($reason);

            if ($reason !== '') {
                $locked->withReason($reason);
            }

            $locked->delete();

            $this->cache->bumpAfterCommit(sprintf('Media #%d deleted', $locked->getKey()));

            return null;
        });

        if ($refusal !== null) {
            $asset->setAttribute('usage_count', count($refusal->usage));
            $asset->syncOriginalAttribute('usage_count');

            throw $refusal;
        }
    }

    /**
     * Every live place the asset is used: section media slots, section items, page banners, CTA
     * backgrounds, OG images, and rich-text bodies or published snapshots that embed its URL — the one
     * reference that is a URL rather than a foreign key (§6.8, the stated exception to INV-3).
     *
     * One entry per place (a section that both places the image and still shows it in its published
     * snapshot is one place).
     *
     * @return Collection<string, array{type: string, id: int, label: string, detail: string|null}>
     */
    public function usage(MediaAsset $asset): Collection
    {
        $id = (int) $asset->getKey();
        $token = basename(trim((string) $asset->directory, '/'));
        $connection = $this->db->connection();
        $places = [];

        $add = static function (string $type, int $rowId, string $label, ?string $detail) use (&$places): void {
            $places[$type.':'.$rowId] ??= ['type' => $type, 'id' => $rowId, 'label' => $label, 'detail' => $detail];
        };

        foreach ($connection->table('website_section_media as m')
            ->join('website_sections as s', 's.id', '=', 'm.website_section_id')
            ->whereNull('s.deleted_at')
            ->where('m.media_asset_id', $id)
            ->get(['s.id', 's.section_key', 's.name', 's.placement', 'm.role']) as $row) {
            $add('section', (int) $row->id, (string) ($row->name ?: $row->section_key), $row->placement.' / '.$row->role);
        }

        foreach ($connection->table('website_section_items as i')
            ->join('website_sections as s', 's.id', '=', 'i.website_section_id')
            ->whereNull('i.deleted_at')
            ->whereNull('s.deleted_at')
            ->where('i.media_asset_id', $id)
            ->get(['s.id', 's.section_key', 's.name', 'i.group']) as $row) {
            $add('section', (int) $row->id, (string) ($row->name ?: $row->section_key), 'item in '.$row->group);
        }

        foreach ($connection->table('pages')->whereNull('deleted_at')->where('banner_media_id', $id)->get(['id', 'title']) as $row) {
            $add('page', (int) $row->id, (string) $row->title, 'banner');
        }

        foreach ($connection->table('cta_blocks')->whereNull('deleted_at')->where('background_media_id', $id)->get(['id', 'name']) as $row) {
            $add('cta_block', (int) $row->id, (string) $row->name, 'background');
        }

        foreach ($connection->table('seo_meta')->where('og_image_media_id', $id)->get(['id', 'seoable_type', 'seoable_id', 'route_key']) as $row) {
            $add('seo_meta', (int) $row->id, (string) ($row->route_key ?? class_basename((string) $row->seoable_type).' #'.$row->seoable_id), 'social image');
        }

        if ($token !== '' && $token !== '.') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $token).'%';

            foreach ($connection->table('pages')->whereNull('deleted_at')
                ->where(fn ($query) => $query->where('content', 'like', $like)->orWhere('published_content', 'like', $like))
                ->get(['id', 'title']) as $row) {
                $add('page', (int) $row->id, (string) $row->title, 'embedded in the text');
            }

            foreach ($connection->table('website_sections')->whereNull('deleted_at')
                ->where(fn ($query) => $query->where('content', 'like', $like)->orWhere('published_content', 'like', $like))
                ->get(['id', 'section_key', 'name']) as $row) {
                $add('section', (int) $row->id, (string) ($row->name ?: $row->section_key), 'embedded in the text or the live version');
            }

            if ($connection->getSchemaBuilder()->hasTable('faqs')) {
                foreach ($connection->table('faqs')->whereNull('deleted_at')->where('answer', 'like', $like)->get(['id', 'question']) as $row) {
                    $add('faq', (int) $row->id, (string) $row->question, 'embedded in the answer');
                }
            }
        }

        return collect($places);
    }

    /**
     * Refresh `usage_count`: for one asset, for a set of ids, or (no argument) for the whole library in
     * one pass over every source — never a query per asset (`RecountMediaUsage`, `cms:media-recount`).
     *
     * @param  MediaAsset|list<int>|null  $assets
     */
    public function recountUsage(MediaAsset|array|null $assets = null): void
    {
        $connection = $this->db->connection();

        if ($assets instanceof MediaAsset) {
            $count = $this->usage($assets)->count();

            if ((int) $assets->usage_count !== $count) {
                $connection->table('media_assets')->where('id', $assets->getKey())->update(['usage_count' => $count]);
                $assets->setAttribute('usage_count', $count);
                $assets->syncOriginalAttribute('usage_count');
            }

            return;
        }

        if (is_array($assets)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $assets))));

            if ($ids === []) {
                return;
            }

            foreach (MediaAsset::query()->whereIn('id', $ids)->get() as $asset) {
                $this->recountUsage($asset);
            }

            return;
        }

        /** @var array<int, array<string, true>> $places asset id => place keys */
        $places = [];

        $mark = static function (int $assetId, string $place) use (&$places): void {
            $places[$assetId][$place] = true;
        };

        foreach ($connection->table('website_section_media as m')
            ->join('website_sections as s', 's.id', '=', 'm.website_section_id')
            ->whereNull('s.deleted_at')
            ->cursor(['m.media_asset_id', 'm.website_section_id']) as $row) {
            $mark((int) $row->media_asset_id, 'section:'.$row->website_section_id);
        }

        foreach ($connection->table('website_section_items as i')
            ->join('website_sections as s', 's.id', '=', 'i.website_section_id')
            ->whereNull('i.deleted_at')->whereNull('s.deleted_at')->whereNotNull('i.media_asset_id')
            ->cursor(['i.media_asset_id', 'i.website_section_id']) as $row) {
            $mark((int) $row->media_asset_id, 'section:'.$row->website_section_id);
        }

        foreach ($connection->table('pages')->whereNull('deleted_at')->whereNotNull('banner_media_id')->cursor(['id', 'banner_media_id']) as $row) {
            $mark((int) $row->banner_media_id, 'page:'.$row->id);
        }

        foreach ($connection->table('cta_blocks')->whereNull('deleted_at')->whereNotNull('background_media_id')->cursor(['id', 'background_media_id']) as $row) {
            $mark((int) $row->background_media_id, 'cta_block:'.$row->id);
        }

        foreach ($connection->table('seo_meta')->whereNotNull('og_image_media_id')->cursor(['id', 'og_image_media_id']) as $row) {
            $mark((int) $row->og_image_media_id, 'seo_meta:'.$row->id);
        }

        $byToken = $connection->table('media_assets')->whereNull('deleted_at')->pluck('directory', 'id')
            ->mapWithKeys(static fn (string $directory, int|string $id): array => [strtoupper(basename(trim($directory, '/'))) => (int) $id])
            ->all();

        $scan = function (string $table, array $columns, string $place) use ($connection, $byToken, $mark): void {
            if (! $connection->getSchemaBuilder()->hasTable($table)) {
                return;
            }

            foreach ($connection->table($table)->whereNull('deleted_at')->cursor(array_merge(['id'], $columns)) as $row) {
                foreach ($columns as $column) {
                    $text = (string) ($row->{$column} ?? '');

                    if ($text === '' || preg_match_all(self::ULID_IN_TEXT, $text, $matches) === 0) {
                        continue;
                    }

                    foreach (array_unique(array_map('strtoupper', $matches[1])) as $token) {
                        if (isset($byToken[$token])) {
                            $mark($byToken[$token], $place.':'.$row->id);
                        }
                    }
                }
            }
        };

        $scan('pages', ['content', 'published_content'], 'page');
        $scan('website_sections', ['content', 'published_content'], 'section');
        $scan('faqs', ['answer'], 'faq');

        foreach ($connection->table('media_assets')->whereNull('deleted_at')->cursor(['id', 'usage_count']) as $row) {
            $count = count($places[(int) $row->id] ?? []);

            if ((int) $row->usage_count !== $count) {
                $connection->table('media_assets')->where('id', $row->id)->update(['usage_count' => $count]);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Rendering helpers — the only place a row becomes a <picture>
    |--------------------------------------------------------------------------
    */

    /**
     * The original, or the narrowest variant at least `$width` wide (the widest when none is).
     */
    public function url(MediaAsset $asset, ?int $width = null, bool $webp = false): string
    {
        $path = $asset->path();

        if ($width !== null && $asset->isImage() && $asset->isUsable()) {
            $variants = $this->variants($asset);
            $chosen = null;

            foreach ($variants as $variantWidth => $variant) {
                if ($variantWidth >= $width) {
                    $chosen = $variant;
                    break;
                }
            }

            $chosen ??= $variants === [] ? null : end($variants);

            if (is_array($chosen)) {
                $path = (string) (($webp ? ($chosen['webp']['path'] ?? null) : null) ?? $chosen['path']);
            }
        }

        return $this->publicUrl((string) $asset->disk, $path);
    }

    /**
     * `url 640w, url 960w, ...` — empty unless the derivatives are usable (§6.8).
     */
    public function srcset(MediaAsset $asset, bool $webp = false): string
    {
        if (! $asset->isImage() || $asset->derivatives_status !== MediaProcessingStatus::Ready) {
            return '';
        }

        $entries = [];

        foreach ($this->variants($asset) as $width => $variant) {
            $path = $webp ? ($variant['webp']['path'] ?? null) : ($variant['path'] ?? null);

            if (is_string($path) && $path !== '') {
                $entries[] = $this->publicUrl((string) $asset->disk, $path).' '.$width.'w';
            }
        }

        return implode(', ', $entries);
    }

    public function sizes(MediaAsset $asset, ?ImageProfile $profile = null): string
    {
        $profile ??= $asset->profile instanceof ImageProfile ? $asset->profile : null;
        $sizes = $profile?->sizesAttribute() ?? '';

        return $sizes === '' ? '100vw' : $sizes;
    }

    /**
     * The array a published snapshot carries for one media slot — exactly what `<x-site.image>` reads
     * (`url`, `srcset`, `webp_srcset`, `sizes`, `width`, `height`, `alt`, `is_video`, `poster`, ...).
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(MediaAsset $asset, ?ImageProfile $profile = null, ?string $posterUrl = null): array
    {
        $isVideo = $asset->isVideo();

        return [
            'id' => (int) $asset->getKey(),
            'url' => $this->url($asset),
            'srcset' => $isVideo ? '' : $this->srcset($asset),
            'webp_srcset' => $isVideo ? '' : $this->srcset($asset, webp: true),
            'sizes' => $this->sizes($asset, $profile),
            'width' => $asset->width,
            'height' => $asset->height,
            'alt' => (string) ($asset->alt_text ?? ''),
            'alt_text' => $asset->alt_text,
            'title' => $asset->title,
            'caption' => $asset->caption,
            'mime_type' => $asset->mime_type,
            'is_video' => $isVideo,
            'poster' => $isVideo ? $posterUrl : null,
            'profile' => ($profile ?? $asset->profile)?->value,
            'status' => $asset->derivatives_status?->value,
        ];
    }

    /**
     * What this server's image engine can do — for the media library screen.
     *
     * @return array{engine: string|null, webp: bool, avif: bool, max_upload_bytes: int}
     */
    public function capabilities(): array
    {
        return [
            'engine' => $this->images->available() ? 'gd' : null,
            'webp' => $this->images->supportsWebp(),
            'avif' => $this->images->supports('image/avif'),
            'max_upload_bytes' => $this->maxUploadBytes(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function detectMime(string $path): string
    {
        $mime = strtolower((string) (new finfo(FILEINFO_MIME_TYPE))->file($path));

        if ($mime === 'image/svg+xml' || $mime === 'image/svg') {
            throw UnsupportedUploadException::svg();
        }

        if (in_array($mime, ['text/xml', 'application/xml', 'text/plain', 'text/html', 'application/octet-stream'], true)) {
            $head = strtolower((string) file_get_contents($path, false, null, 0, 2048));

            if (str_contains($head, '<svg')) {
                throw UnsupportedUploadException::svg();
            }
        }

        return $mime === 'image/jpg' || $mime === 'image/pjpeg' ? 'image/jpeg' : $mime;
    }

    private function assertRealImage(string $path, string $mime): void
    {
        $info = @getimagesize($path);

        if (! is_array($info) || (int) ($info[0] ?? 0) < 1 || (int) ($info[1] ?? 0) < 1) {
            throw UnsupportedUploadException::notAnImage($mime);
        }

        $reported = strtolower((string) ($info['mime'] ?? ''));

        if ($reported !== '' && $reported !== $mime && ! ($reported === 'image/jpg' && $mime === 'image/jpeg')) {
            throw UnsupportedUploadException::notAnImage($mime);
        }

        if ((int) $info[0] * (int) $info[1] > GdImageProcessor::MAX_PIXELS) {
            throw new UnsupportedUploadException(sprintf(
                'That image is %d x %d pixels, above the %d megapixel limit this server can process safely.',
                (int) $info[0],
                (int) $info[1],
                GdImageProcessor::MAX_PIXELS / 1_000_000
            ));
        }
    }

    /**
     * @return array{bytes: string, width: int, height: int}
     */
    private function normaliseImage(string $path, string $mime): array
    {
        if (! $this->images->supports($mime)) {
            throw new UnsupportedUploadException(sprintf(
                'This server cannot process %s images, so their location data could not be removed. Upload a JPEG, PNG or WebP instead.',
                strtoupper(self::IMAGE_MIMES[$mime])
            ));
        }

        $maxWidth = max(320, min(8000, (int) $this->settings->get('website.image_max_width', 2560)));

        try {
            return $this->images->normaliseOriginal($path, $mime, $maxWidth);
        } catch (RuntimeException $exception) {
            throw new UnsupportedUploadException('That image could not be processed: '.$exception->getMessage());
        }
    }

    /**
     * Does the acting user hold a permission? A console process with no user is trusted code.
     */
    private function actorCan(string $permission): bool
    {
        try {
            $user = Auth::user();
        } catch (Throwable) {
            $user = null;
        }

        return $user === null
            ? app()->runningInConsole()
            : $user instanceof Authorizable && $user->can($permission);
    }

    private function findByChecksum(string $checksum): ?MediaAsset
    {
        /** @var MediaAsset|null */
        return MediaAsset::query()->withTrashed()->where('checksum', $checksum)->first();
    }

    /**
     * Return an existing row for re-uploaded bytes: restored from the trash, its file rewritten if it
     * went missing, and empty descriptive columns filled from this upload's metadata.
     *
     * An upload is not a restore and not an edit: bringing a trashed asset back needs
     * `website_media.restore` — the ability `MediaPolicy::restore()` checks, which the registry does not
     * declare today, so in practice only a Super Admin (an administrator deleted it on purpose) — and
     * filling another asset's empty alt text, title or caption needs `website_media.edit`. Console context
     * (seeders, commands) has no user and is trusted code.
     *
     * @param  array<string, mixed>  $meta
     *
     * @throws UnsupportedUploadException for a trashed asset the uploader may not restore
     */
    private function reuse(MediaAsset $asset, array $meta, UploadedFile $file, string $mime): MediaAsset
    {
        if ($asset->trashed() && ! $this->actorCan(self::MODULE.'.restore')) {
            throw new UnsupportedUploadException(
                'This file is already in the media library\'s trash. Ask someone who can restore media to bring it back.'
            );
        }

        $mayDescribe = $this->actorCan(self::MODULE.'.edit');

        return $this->db->connection()->transaction(function () use ($asset, $meta, $file, $mime, $mayDescribe): MediaAsset {
            if ($asset->trashed()) {
                $asset->restore();
            }

            $fills = [];

            foreach (['alt_text' => 255, 'title' => 191, 'caption' => 500] as $key => $limit) {
                $value = $mayDescribe ? $this->plain($meta[$key] ?? null, $limit) : null;

                if ($value !== null && trim((string) $asset->getAttribute($key)) === '') {
                    $fills[$key] = $value;
                }
            }

            if ($fills !== []) {
                $asset->fill($fills)->save();
            }

            $disk = $this->disk((string) $asset->disk);

            if (! $disk->exists($asset->path())) {
                $path = (string) $file->getRealPath();
                $bytes = isset(self::IMAGE_MIMES[$mime]) ? $this->normaliseImage($path, $mime)['bytes'] : (string) file_get_contents($path);
                $disk->put($asset->path(), $bytes, ['visibility' => 'public']);

                if ($asset->isImage()) {
                    $this->writeStatus($asset, MediaProcessingStatus::Pending);
                    $this->queueDerivatives($asset);
                }
            }

            return $this->fresh($asset);
        });
    }

    /**
     * Dispatch generation once the row is committed; run it synchronously when the job class has not
     * shipped. A synchronous failure is recorded on the row and reported, never thrown into the upload.
     */
    private function queueDerivatives(MediaAsset $asset): void
    {
        $this->db->connection()->afterCommit(function () use ($asset): void {
            $job = self::DERIVATIVES_JOB;

            if (class_exists($job)) {
                dispatch(new $job($asset));

                return;
            }

            try {
                $this->generateDerivatives($this->fresh($asset));
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * Machine-written status columns go through the query builder: they are not editorial changes
     * and must not bury the alt-text edits the activity log exists for.
     *
     * @param  array<string, mixed>  $extra
     */
    private function writeStatus(MediaAsset $asset, MediaProcessingStatus $status, ?string $reason = null, array $extra = []): void
    {
        $values = array_merge([
            'derivatives_status' => $status->value,
            'failure_reason' => $reason === null ? null : mb_substr($reason, 0, 255),
            'updated_at' => Carbon::now(),
        ], $extra);

        $this->db->connection()->table('media_assets')->where('id', $asset->getKey())->update($values);
    }

    /**
     * @return array<int, array<string, mixed>> width => entry, ascending
     */
    private function variants(MediaAsset $asset): array
    {
        $variants = [];

        foreach ((array) ($asset->variants ?? []) as $width => $entry) {
            if (is_array($entry) && isset($entry['path'])) {
                $variants[(int) $width] = $entry;
            }
        }

        ksort($variants);

        return $variants;
    }

    /**
     * The format of the "original format" derivative. AVIF and GIF sources get a universally
     * renderable fallback instead: PNG where the profile keeps transparency, JPEG otherwise.
     */
    private function originalFormat(string $mime, ImageProfile $profile): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => $profile->acceptsTransparency() ? 'png' : ($mime === 'image/gif' ? 'png' : 'jpg'),
        };
    }

    private function mimeFor(string $format): string
    {
        return match ($format) {
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/avif',
        };
    }

    /**
     * @param  list<string>  $keep
     */
    private function removeStaleVariants(MediaAsset $asset, array $keep): void
    {
        $disk = $this->disk((string) $asset->disk);
        $keep = array_merge($keep, [$asset->path()]);

        foreach ($this->variants($asset) as $entry) {
            foreach ([$entry['path'] ?? null, $entry['webp']['path'] ?? null] as $path) {
                if (is_string($path) && $path !== '' && ! in_array($path, $keep, true)) {
                    try {
                        $disk->delete($path);
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            }
        }
    }

    private function deleteDirectory(string $directory): void
    {
        try {
            $this->disk(self::DISK)->deleteDirectory($directory);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function publicUrl(string $disk, string $path): string
    {
        /** @var FilesystemAdapter $filesystem */
        $filesystem = $this->disk($disk === '' ? self::DISK : $disk);

        return $filesystem->url($path);
    }

    private function disk(string $name): Filesystem
    {
        return $this->storage->disk($name);
    }

    private function fresh(MediaAsset $asset): MediaAsset
    {
        /** @var MediaAsset */
        return MediaAsset::query()->withTrashed()->findOrFail($asset->getKey());
    }

    private function maxUploadBytes(): int
    {
        $megabytes = max(1, (int) $this->settings->get('security.max_upload_mb', 10));

        return $megabytes * 1024 * 1024;
    }

    private function originalName(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', (string) $file->getClientOriginalName()));
        $name = (string) preg_replace('~[\x00-\x1F\x7F]~u', '', $name);
        $name = trim($name);

        return mb_substr($name === '' ? 'upload' : $name, 0, 191);
    }

    private function plain(mixed $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(strip_tags((string) preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~u', '', (string) $value)));

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
