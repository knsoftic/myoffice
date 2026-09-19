<?php

declare(strict_types=1);

namespace App\Support\Cms\Sections;

use App\Enums\Cms\ImageProfile;
use App\Models\Cms\MediaAsset;
use App\Models\Cms\WebsiteSection;
use App\Services\Cms\CacheVersion;
use App\Services\Cms\MediaService;
use App\Support\Modules;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * The data behind phase-04's public section types — `services`, `portfolio`, `team`, `testimonials`,
 * `student_reviews`, `success_stories`, `blog`, `careers`, `contact` (phase-03 §6.1 "section types declared
 * by later phases", phase-04 §8.11).
 *
 * Each subclass is a `SectionDataProvider` (`resolve(WebsiteSection $section): array`, phase-03 §6.1). They
 * are meant to be registered `is_live => true`: an approved testimonial or a newly published post must
 * appear without re-publishing the section, and an `is_live` provider caches itself — here under Phase 3's
 * cache version stamp, so any content change that bumps the stamp (D22) refreshes every feed at once.
 *
 * Rules every provider keeps:
 *
 *   · it reads only the model's `scopePublic()` rows — nothing draft, pending, rejected, expired or trashed;
 *   · it returns plain arrays (dates as `Y-m-d` / ISO-8601 strings, money as the stored decimal string,
 *     images as `MediaService::toSnapshot()`), so the output can be frozen into `published_content` too;
 *   · a disabled module yields `['available' => false, 'items' => []]` and the partial hides itself;
 *   · a failure is reported and yields the same empty shape — a section never takes a page down.
 *
 * Section options are read from the published snapshot's `fields` (falling back to the draft): `limit`,
 * `featured_only`, and per type `category` / `type` / `course_id`.
 */
abstract class MarketingSectionProvider
{
    private const CACHE_SECONDS = 600;

    public function __construct(
        protected readonly CacheVersion $cache,
        protected readonly MediaService $media,
    ) {}

    /** The section key this provider serves. */
    abstract public function key(): string;

    /** The module whose switch governs the section's data. */
    abstract protected function module(): string;

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    abstract protected function build(array $options): array;

    protected function defaultLimit(): int
    {
        return 6;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(WebsiteSection $section): array
    {
        try {
            if (! Modules::enabled($this->module())) {
                return ['available' => false, 'items' => []];
            }

            $options = $this->options($section);

            return $this->remember($options, fn (): array => ['available' => true] + $this->build($options));
        } catch (Throwable $exception) {
            report($exception);

            return ['available' => false, 'items' => []];
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  Closure(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    protected function remember(array $options, Closure $callback): array
    {
        $parts = [$this->key(), md5((string) json_encode($options))];

        return (array) $this->cache->remember('section-data', $parts, self::CACHE_SECONDS, $callback);
    }

    /**
     * @return array<string, mixed>
     */
    protected function options(WebsiteSection $section): array
    {
        // A published snapshot keeps the admin's options under `fields` (phase-03 SnapshotBuilder); a section
        // that has never been published falls back to its draft content.
        $published = $section->getAttribute('published_content');
        $content = is_array($published) && is_array($published['fields'] ?? null) ? $published['fields'] : (array) ($section->getAttribute('content') ?? []);

        $limit = $content['limit'] ?? $content['items_limit'] ?? null;

        return [
            'limit' => is_numeric($limit) ? max(1, min(24, (int) $limit)) : $this->defaultLimit(),
            'featured_only' => filter_var($content['featured_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'category' => is_scalar($content['category'] ?? null) ? (string) $content['category'] : null,
            'type' => is_scalar($content['type'] ?? null) ? (string) $content['type'] : null,
            'course_id' => is_numeric($content['course_id'] ?? null) ? (int) $content['course_id'] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function image(?MediaAsset $asset, ImageProfile $profile): ?array
    {
        if (! $asset instanceof MediaAsset) {
            return null;
        }

        try {
            return $this->media->toSnapshot($asset, $profile);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    protected function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        return null;
    }

    protected function moment(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        return null;
    }

    /**
     * A named route URL when the route is registered, otherwise null (the partial renders no link).
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function url(string $route, array $parameters = []): ?string
    {
        try {
            return Route::has($route) ? route($route, $parameters) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
