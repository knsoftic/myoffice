<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Services\Cms\Media\GdImageProcessor;
use App\Support\Cms\ImageProfile as ImageProfileSpec;
use App\Support\SettingsRepository;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Derivatives for the images that arrive through **Admin → Settings** rather than the media library.
 *
 * The one public image pipeline of decision **D24** is reached two ways. A CMS upload becomes a
 * `media_assets` row and `MediaService::generateDerivatives()` produces its widths. A settings image
 * — `branding.logo_light`, `branding.logo_dark` — is only a path string on the public disk, so it had
 * no row, no derivatives and therefore no `srcset`, no WebP and **no intrinsic dimensions**. It was
 * echoed by `<x-site.brand>` as `['url' => $url]` and nothing else.
 *
 * That is not a cosmetic gap. Measured on the live site: the header logo was a **4167 x 1571** PNG of
 * 141,736 bytes, painted at 36 pixels tall, with no `width`/`height` for the browser to reserve space
 * with. Two of them (light and dark) sit in the markup of every page, so every visit downloaded
 * ~277 KB of logo — the page's entire image weight — and shifted its own header while doing it. The
 * same file through the `logo` profile is 3,708 bytes at 240w WebP: **38 times smaller**, with the
 * dimensions the layout needs.
 *
 * What this class does *not* do is invent a second pipeline. The widths, formats, quality, `sizes`
 * and transparency rule all come from `ImageProfile`, the encoding from `GdImageProcessor`, and the
 * array it returns is the same snapshot shape `<x-site.image>` already reads from a published
 * section. Only the source of the original differs.
 *
 * ---------------------------------------------------------------------------------------------
 * Why it is safe to call from a view
 * ---------------------------------------------------------------------------------------------
 *
 * `snapshot()` never throws and never returns a partially-true answer. Any failure — GD missing, the
 * file gone, an image too large, a disk that will not write — returns `null`, and the caller falls
 * back to the original URL exactly as before. That matters because `<x-site.brand>` also renders the
 * holding and maintenance pages, where a 500 would replace the only page the site has.
 *
 * Generation happens once per (file, profile) and is remembered under a key carrying the file's
 * modification time and size, so replacing the logo in Settings publishes the new one with no cache
 * to clear by hand. The derivative files themselves are checked before they are written, so a
 * flushed cache costs a handful of `exists()` calls rather than a re-encode.
 *
 * Nothing private is involved: `branding.logo_light` and `branding.logo_dark` are `public => true` in
 * `SettingsRegistry` and already live on the public disk, so **D21** has nothing to say here — the
 * derivatives are as public as the original, and no more.
 */
final class SettingsImageService
{
    /** Where derivatives of settings images are written, under the public disk. */
    private const DIRECTORY = 'settings/derivatives';

    public function __construct(
        private readonly GdImageProcessor $images,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * The `<x-site.image>` snapshot for one settings image path, or null to fall back to the plain URL.
     *
     * `$path` is what the setting holds: a path on the public disk. An absolute URL returns null —
     * there is nothing local to measure or re-encode — which leaves the old behaviour untouched
     * rather than regressing it.
     *
     * @return array{url: string, srcset: string, webp_srcset: string, sizes: string, width: int, height: int}|null
     */
    public function snapshot(?string $path, string $profile): ?array
    {
        $path = is_string($path) ? trim($path) : '';

        if ($path === '' || preg_match('~^https?://~i', $path) === 1) {
            return null;
        }

        if (! ImageProfileSpec::exists($profile)) {
            return null;
        }

        try {
            $disk = Storage::disk('public');
            $path = ltrim($path, '/');

            if (! $disk->exists($path)) {
                return null;
            }

            // The mtime and size are in the key so that replacing the file in Settings publishes the
            // new one: an mtime-blind key would serve the old logo until somebody remembered to
            // clear the cache by hand.
            $key = sprintf(
                'settings-image:%s:%s:%d:%d',
                $profile,
                sha1($path),
                (int) $disk->lastModified($path),
                (int) $disk->size($path),
            );

            $snapshot = Cache::remember(
                $key,
                now()->addMonth(),
                fn (): ?array => $this->derive($disk, $path, $profile),
            );

            return is_array($snapshot) ? $snapshot : null;
        } catch (Throwable) {
            // Degrading to the original URL is always correct here; a logo is not worth a 500.
            return null;
        }
    }

    /**
     * Delete the derivatives of one original, for when the original itself goes.
     *
     * Called from `SettingsService::deleteFileFromDisk()`, which is the single point every settings
     * file deletion passes through — a replaced logo and a removed one both land there. Without this
     * every logo change would leave six files behind (about 40 KB for the two logos measured here),
     * for nothing: the derivative name is built from the original's filename, and settings uploads get
     * a fresh random filename each time, so the old ones could never be reached again.
     *
     * Like `snapshot()`, it never throws. Orphaned derivatives are litter, and litter must not be
     * able to fail the request that was trying to tidy it up.
     */
    public function forget(?string $path): void
    {
        $path = is_string($path) ? trim($path) : '';

        if ($path === '' || preg_match('~^https?://~i', $path) === 1) {
            return;
        }

        try {
            $base = pathinfo($path, PATHINFO_FILENAME);

            if ($base === '' || $base === '.' || $base === '..') {
                return;
            }

            $disk = Storage::disk('public');

            foreach ($disk->files(self::DIRECTORY) as $file) {
                // `{base}-{width}.{ext}` and nothing else: a prefix match alone would let a logo
                // named "logo" delete the derivatives of one named "logo-wide".
                if (preg_match('/^'.preg_quote($base, '/').'-\d+\.[a-z]+$/i', basename($file)) === 1) {
                    $disk->delete($file);
                }
            }
        } catch (Throwable) {
            // Nothing to do and nothing worth failing for.
        }
    }

    /**
     * Produce (or reuse) every derivative of one original and describe the result.
     *
     * Returns null rather than a half-filled array when nothing could be produced: a snapshot with
     * dimensions but an empty `srcset` would claim a responsive image that does not exist.
     *
     * @return array{url: string, srcset: string, webp_srcset: string, sizes: string, width: int, height: int}|null
     */
    private function derive(Filesystem $disk, string $path, string $profile): ?array
    {
        if (! $this->images->available()) {
            return null;
        }

        $bytes = $disk->get($path);

        if (! is_string($bytes) || $bytes === '') {
            return null;
        }

        [$width, $height] = $this->images->dimensions($bytes);
        $this->images->guardPixels($width, $height);

        $format = $this->originalFormat($path, $profile);
        $quality = $this->quality();
        $webp = $this->webpEnabled() && $format !== 'webp';

        $base = self::DIRECTORY.'/'.pathinfo($path, PATHINFO_FILENAME);

        $source = null;
        $primary = [];
        $alternate = [];

        foreach (ImageProfileSpec::widths($profile) as $targetWidth) {
            // Never upscale: a 480w derivative of a 96px logo is a bigger file that shows less.
            if ($targetWidth > $width) {
                continue;
            }

            $targetHeight = ImageProfileSpec::heightFor($profile, $targetWidth);

            foreach ($webp ? [$format, 'webp'] : [$format] as $outputFormat) {
                $file = sprintf('%s-%d.%s', $base, $targetWidth, $outputFormat);

                if (! $disk->exists($file)) {
                    // Decoded lazily and once: on the common path every derivative already exists and
                    // the six-megapixel original is never decoded at all.
                    $source ??= $this->images->decode($bytes);

                    $derivative = $this->images->derivative($source, $targetWidth, $targetHeight, $outputFormat, $quality);

                    if ($derivative === null) {
                        continue;
                    }

                    $disk->put($file, $derivative['bytes'], ['visibility' => 'public']);
                }

                $entry = $disk->url($file).' '.$targetWidth.'w';

                if ($outputFormat === 'webp') {
                    $alternate[] = $entry;
                } else {
                    $primary[] = $entry;
                }
            }
        }

        unset($source);

        if ($primary === []) {
            return null;
        }

        return [
            'url' => $disk->url($path),
            'srcset' => implode(', ', $primary),
            'webp_srcset' => implode(', ', $alternate),
            'sizes' => ImageProfileSpec::sizesAttribute($profile),
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * The format the derivative keeps.
     *
     * A profile that accepts transparency keeps PNG, because flattening a logo onto white is the one
     * failure nobody notices until the header changes colour. Anything else follows the extension,
     * and an unrecognised extension is treated as JPEG only where transparency is irrelevant.
     */
    private function originalFormat(string $path, string $profile): string
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        $format = match ($extension) {
            'jpg', 'jpeg' => 'jpg',
            'png' => 'png',
            'webp' => 'webp',
            'gif' => 'gif',
            default => null,
        };

        if ($format === null) {
            return ImageProfileSpec::acceptsTransparency($profile) ? 'png' : 'jpg';
        }

        if ($format === 'jpg' && ImageProfileSpec::acceptsTransparency($profile)) {
            // A JPEG has no alpha to preserve, so this is not a correctness fix -- it keeps the
            // profile's promise that a logo derivative is never a format that flattens.
            return 'png';
        }

        return $format;
    }

    private function quality(): int
    {
        return max(60, min(95, (int) $this->settings->get('website.image_quality', ImageProfileSpec::DEFAULT_QUALITY)));
    }

    private function webpEnabled(): bool
    {
        return (bool) $this->settings->get('website.image_webp_enabled', true) && $this->images->supportsWebp();
    }
}
