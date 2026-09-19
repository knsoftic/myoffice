<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\ImageProfile;
use App\Enums\Cms\MediaCollection;
use App\Models\Cms\Service;
use App\Models\Cms\ServiceCategory;
use App\Services\Cms\Exceptions\ContentRuleException;
use App\Services\Cms\Support\ContentHelper;
use Illuminate\Http\UploadedFile;

/**
 * The public service catalogue (phase-04 §6.4, requirement §11).
 *
 * Invariants:
 *
 *   1. `starting_price` is written straight from the validated string through `Money` — never cast to a
 *      float, never multiplied. `price_visible = false` keeps the value and only the public view omits it.
 *   2. `features` is stored as a re-indexed list of trimmed, non-empty strings (max 20, each ≤ 150
 *      characters); an empty list is stored as null.
 *   3. `changeStatus()` to `published` requires a name and a slug, writes one activity entry with the old
 *      and new status, and never alters `sort_order`. A service is never `scheduled`.
 *   4. Every method is one transaction. The image is a `media_assets` row (`pages` / `Card`) through
 *      `MediaService`; replacing it only repoints `image_media_id` after the row saves — the library keeps
 *      the binary. SEO is `seo_meta` through `SeoService` (D23).
 *   5. `update()` treats `$technologyIds` as the complete, ordered selection of the form (an empty array
 *      clears it), and a status posted with the form goes through the same `changeStatus()` rules.
 */
final class ServiceContentService
{
    private const MODULE = 'services';

    private const LABEL = 'Service';

    public function __construct(
        private readonly ContentHelper $content,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, int|string>  $technologyIds
     */
    public function store(array $data, ?UploadedFile $image, array $technologyIds = []): Service
    {
        return $this->content->transaction(function () use ($data, $image, $technologyIds): Service {
            $service = new Service;
            $manualSlug = $this->content->manualSlug($data);
            $status = $this->content->contentStatus($data['status'] ?? null) ?? ContentStatus::Draft;

            if ($status === ContentStatus::Scheduled) {
                throw ContentRuleException::cannotSchedule(self::LABEL);
            }

            $service->fill($this->attributes($data, null));
            $service->setAttribute('slug', $manualSlug ?? '');
            $service->setAttribute('status', $status);
            $service->setAttribute('image_media_id', $this->content->resolveMediaColumn(
                $data, 'image_media_id', $image, MediaCollection::Pages, ImageProfile::Card, 'image', null,
            ));

            if (! array_key_exists('sort_order', $data)) {
                $service->setAttribute('sort_order', (int) Service::query()->withTrashed()->max('sort_order') + 1);
            }

            $this->content->saveWithSlug($service, $manualSlug !== null);
            $this->content->syncTechnologies('service_technology', 'service_id', (int) $service->getKey(), $technologyIds);
            $this->content->saveSeo($service, $data);
            $this->content->recountMediaAfterCommit([$service->getAttribute('image_media_id')]);

            if ($status->isPublic()) {
                $this->content->flushPublicCache(sprintf('Service "%s" published', (string) $service->getAttribute('name')));
            }

            return $service->load('technologies');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, int|string>  $technologyIds
     */
    public function update(Service $service, array $data, ?UploadedFile $image, array $technologyIds = []): Service
    {
        return $this->content->transaction(function () use ($service, $data, $image, $technologyIds): Service {
            $oldSlug = (string) $service->getAttribute('slug');
            $oldImage = $service->getAttribute('image_media_id');
            $wasPublic = $this->isPublic($service);
            $manualSlug = $this->content->manualSlug($data);
            $status = $this->content->contentStatus($data['status'] ?? null);

            $service->fill($this->attributes($data, $service));

            if ($manualSlug !== null) {
                $service->setAttribute('slug', $manualSlug);
            }

            $service->setAttribute('image_media_id', $this->content->resolveMediaColumn(
                $data, 'image_media_id', $image, MediaCollection::Pages, ImageProfile::Card, 'image',
                $oldImage === null ? null : (int) $oldImage,
            ));

            $this->content->saveWithSlug($service, $manualSlug !== null);

            if ((string) $service->getAttribute('slug') !== $oldSlug) {
                $this->content->auditSlugChange($service, self::MODULE, $oldSlug, (string) $service->getAttribute('slug'), $this->wasEverPublished($service));
            }

            $technologies = $this->content->syncTechnologies('service_technology', 'service_id', (int) $service->getKey(), $technologyIds);

            if ($technologies['old'] !== $technologies['new']) {
                $this->content->audit(
                    self::MODULE,
                    sprintf('Service "%s" technologies updated', (string) $service->getAttribute('name')),
                    $service,
                    ['old' => ['technology_ids' => $technologies['old']], 'attributes' => ['technology_ids' => $technologies['new']]],
                    null,
                    'technologies_synced',
                );
            }

            $statusBefore = $service->getAttribute('status');

            if ($status !== null) {
                $this->content->changeContentStatus($service, $status, self::MODULE, self::LABEL);
            }

            $this->content->saveSeo($service, $data);
            $this->content->recountMediaAfterCommit([$oldImage, $service->getAttribute('image_media_id')]);

            // A status move already flushed the public cache; flush here only for a plain edit.
            if ($service->getAttribute('status') === $statusBefore && ($wasPublic || $this->isPublic($service))) {
                $this->content->flushPublicCache(sprintf('Service "%s" updated', (string) $service->getAttribute('name')));
            }

            return $service->load('technologies');
        });
    }

    public function changeStatus(Service $service, ContentStatus $status): Service
    {
        return $this->content->transaction(
            fn (): Service => $this->content->changeContentStatus($service, $status, self::MODULE, self::LABEL)
        );
    }

    public function toggleFeatured(Service $service): Service
    {
        return $this->content->transaction(function () use ($service): Service {
            $this->content->toggleFlag($service, 'is_featured', self::MODULE, self::LABEL, 'name', 'featured', 'unfeatured');

            if ($this->isPublic($service)) {
                $this->content->flushPublicCache(sprintf('Service "%s" featured toggled', (string) $service->getAttribute('name')));
            }

            return $service;
        });
    }

    /**
     * Soft delete: the row, its technologies, its SEO and its image all survive for a restore, and any
     * inquiry that named the service keeps pointing at it.
     */
    public function delete(Service $service): void
    {
        $this->content->transaction(function () use ($service): void {
            $wasPublic = $this->isPublic($service);

            $service->delete();

            if ($wasPublic) {
                $this->content->flushPublicCache(sprintf('Service "%s" deleted', (string) $service->getAttribute('name')));
            }
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?Service $service): array
    {
        $attributes = [];
        $creating = $service === null;

        if ($creating || array_key_exists('name', $data)) {
            $name = $this->content->plain($data['name'] ?? null, 150);

            if ($name === null) {
                throw ContentRuleException::refuse('name', 'A service name is required.');
            }

            $attributes['name'] = $name;
        }

        if (array_key_exists('service_category_id', $data)) {
            $categoryId = $this->content->id($data['service_category_id']);

            if ($categoryId !== null && ! ServiceCategory::query()->whereKey($categoryId)->exists()) {
                throw ContentRuleException::refuse('service_category_id', 'The chosen category no longer exists.');
            }

            $attributes['service_category_id'] = $categoryId;
        }

        foreach (['short_description' => 500, 'icon' => 64, 'price_note' => 100] as $column => $limit) {
            if (array_key_exists($column, $data)) {
                $attributes[$column] = $this->content->plain($data[$column], $limit);
            }
        }

        if (array_key_exists('full_description', $data)) {
            $attributes['full_description'] = $this->content->rich($data['full_description']);
        }

        if (array_key_exists('starting_price', $data)) {
            $attributes['starting_price'] = $this->content->money($data['starting_price'], 'starting_price');
        }

        if ($creating || array_key_exists('price_visible', $data)) {
            $attributes['price_visible'] = $this->content->bool($data['price_visible'] ?? null, true);
        }

        if (array_key_exists('features', $data)) {
            $attributes['features'] = $this->content->stringList($data['features'], Service::MAX_FEATURES, Service::MAX_FEATURE_LENGTH, 'features');
        }

        if ($creating || array_key_exists('is_featured', $data)) {
            $attributes['is_featured'] = $this->content->bool($data['is_featured'] ?? null, false);
        }

        if (array_key_exists('sort_order', $data)) {
            $attributes['sort_order'] = $this->content->int($data['sort_order'], 0, 0);
        }

        return $attributes;
    }

    private function isPublic(Service $service): bool
    {
        return $service->getAttribute('status') === ContentStatus::Published && ! $service->trashed();
    }

    private function wasEverPublished(Service $service): bool
    {
        return in_array($service->getAttribute('status'), [ContentStatus::Published, ContentStatus::Archived], true);
    }
}
