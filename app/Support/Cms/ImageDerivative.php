<?php

declare(strict_types=1);

namespace App\Support\Cms;

/**
 * One derivative the image pipeline must produce: a single (width, format) output of one original
 * (decision **D24**, phase-03 §6.8).
 *
 * A pure value object — no facades, no container, no I/O. It describes the *intent*; producing the
 * file is `App\Services\Cms\MediaService::generateDerivatives()` and the `GenerateImageDerivatives`
 * job, and the result is recorded in `media_assets.variants`.
 *
 * `height` is null when the derivative is not cropped: the width is the constraint and the aspect
 * ratio of the original is preserved. `fit` is then `contain`; a cropped derivative is `cover` and
 * always carries both dimensions.
 *
 * `format` is either `webp` (the extra source `<x-site.image>` emits) or `original` — meaning "keep
 * the detected format of the upload", which is what preserves a transparent PNG logo instead of
 * flattening it onto white (§6.8).
 */
final class ImageDerivative
{
    public function __construct(
        public readonly string $profile,
        public readonly string $name,
        public readonly int $width,
        public readonly ?int $height,
        public readonly string $fit,
        public readonly int $quality,
        public readonly string $format,
    ) {}

    /**
     * Rebuild one from its array form (the shape `ImageProfile::definitions()` declares).
     *
     * @param  array<string, mixed>  $derivative
     */
    public static function fromArray(string $profile, array $derivative): self
    {
        return new self(
            profile: $profile,
            name: (string) $derivative['name'],
            width: (int) $derivative['width'],
            height: isset($derivative['height']) ? (int) $derivative['height'] : null,
            fit: (string) ($derivative['fit'] ?? ImageProfile::FIT_CONTAIN),
            quality: (int) ($derivative['quality'] ?? ImageProfile::DEFAULT_QUALITY),
            format: (string) ($derivative['format'] ?? ImageProfile::FORMAT_ORIGINAL),
        );
    }

    /**
     * Is this derivative cropped to an exact box?
     */
    public function isCropped(): bool
    {
        return $this->fit === ImageProfile::FIT_COVER;
    }

    /**
     * Does this derivative keep the upload's own format (rather than being re-encoded to WebP)?
     */
    public function keepsOriginalFormat(): bool
    {
        return $this->format === ImageProfile::FORMAT_ORIGINAL;
    }

    /**
     * The key this derivative is filed under in `media_assets.variants` — the width, as §2.13's
     * example shows (`{"960": {"path": "...", "format": "webp", "size_bytes": 1234}}`). The format
     * lives inside the entry, so one width can hold both a WebP and an original-format file.
     */
    public function variantKey(): string
    {
        return (string) $this->width;
    }

    /**
     * The filename suffix that keeps two derivatives of one original apart: `-960` or `-960.webp`
     * shaped. The extension itself is decided by the writer, which knows the detected MIME.
     */
    public function filenameSuffix(): string
    {
        return '-'.$this->width;
    }

    /**
     * "1280 x 720" or "1280 wide" — the media library's variant list (§8.13).
     */
    public function dimensionsLabel(): string
    {
        return $this->height === null
            ? $this->width.' wide'
            : $this->width.' x '.$this->height;
    }

    /**
     * @return array{profile: string, name: string, width: int, height: int|null, fit: string, quality: int, format: string}
     */
    public function toArray(): array
    {
        return [
            'profile' => $this->profile,
            'name' => $this->name,
            'width' => $this->width,
            'height' => $this->height,
            'fit' => $this->fit,
            'quality' => $this->quality,
            'format' => $this->format,
        ];
    }
}
