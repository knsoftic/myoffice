<?php

declare(strict_types=1);

namespace App\Support\Cms;

use InvalidArgumentException;

/**
 * The derivative definitions of decision **D24** — one public image pipeline for the whole system
 * (phase-03 §6.8).
 *
 * Pure data: no facades, no container, no settings lookup, no I/O, nothing that can fail before the
 * application has booted. That is deliberate — `App\Enums\Cms\ImageProfile` (the enum stored in
 * `media_assets.profile`) reads this table, `MediaService` turns it into files, `<x-site.image>`
 * turns it into a `<picture>`, and the media library screen (§8.13) turns it into a variant list.
 * All four read the same numbers from here.
 *
 * ---------------------------------------------------------------------------------------------
 * Two classes, one name — how to import them
 * ---------------------------------------------------------------------------------------------
 *
 * `App\Enums\Cms\ImageProfile` is the **enum** (a column value, a type hint, a select input);
 * this class is its **definition table**. When a file needs both, alias this one:
 *
 *     use App\Enums\Cms\ImageProfile;
 *     use App\Support\Cms\ImageProfile as ImageProfileSpec;
 *
 * ---------------------------------------------------------------------------------------------
 * Definition keys
 * ---------------------------------------------------------------------------------------------
 *
 * | key            | meaning                                                                     |
 * |----------------|-----------------------------------------------------------------------------|
 * | `name`         | the profile name, equal to the enum's backing value                         |
 * | `label`        | the admin-facing label                                                       |
 * | `description`  | one line for the upload and media screens                                    |
 * | `widths`       | the derivative widths, ascending — the `srcset` of §6.8                      |
 * | `crop`         | true = crop to `aspect` (`cover`); false = constrain the width only (`contain`) |
 * | `aspect`       | `"16:9"` shaped, or null when nothing is cropped                             |
 * | `sizes`        | the `sizes` attribute `<x-site.image>` emits; `''` when the profile is never rendered responsively |
 * | `transparency` | true = never re-encode to a format that flattens alpha (logo, icon)          |
 * | `formats`      | the output formats per width: WebP plus the original format (§6.8)           |
 * | `quality`      | the encoder quality, overridable per call from `website.image_quality`       |
 * | `used_by`      | the media roles and screens that ask for this profile, for the admin help text |
 *
 * An original is **never upscaled**: a width wider than the upload is skipped by the pipeline, not
 * invented here (§6.8). Widths above `website.image_max_width` (default 2560) cannot occur because
 * the original is downscaled to that ceiling on upload.
 */
final class ImageProfile
{
    /** Constrain the longest side to `width`; keep the original aspect ratio. */
    public const FIT_CONTAIN = 'contain';

    /** Crop to exactly `width` x `height`. */
    public const FIT_COVER = 'cover';

    /** Re-encode to WebP — the extra `<source>` of `<x-site.image>`. */
    public const FORMAT_WEBP = 'webp';

    /** Keep the detected format of the upload (so a transparent PNG stays a PNG). */
    public const FORMAT_ORIGINAL = 'original';

    /**
     * The default encoder quality. `website.image_quality` (§5.1a, range 60-95) overrides it per
     * call; this constant is what the settings registry's own default is derived from.
     */
    public const DEFAULT_QUALITY = 82;

    /**
     * The default profile for anything that does not name one.
     */
    public const DEFAULT_PROFILE = 'card';

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $normalised = null;

    /**
     * The raw table of §6.8, verbatim.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function raw(): array
    {
        return [
            'hero' => [
                'label' => 'Hero',
                'description' => 'Full-bleed hero and section background images.',
                'widths' => [640, 960, 1280, 1920, 2560],
                'crop' => false,
                'aspect' => null,
                'sizes' => '100vw',
                'transparency' => false,
                'used_by' => 'hero_image, background_image',
            ],
            'banner' => [
                'label' => 'Page banner',
                'description' => 'Wide page banners, cropped to a 21:9 letterbox.',
                'widths' => [640, 960, 1280, 1920],
                'crop' => true,
                'aspect' => '21:9',
                'sizes' => '100vw',
                'transparency' => false,
                'used_by' => 'pages.banner_media_id',
            ],
            'card' => [
                'label' => 'Card',
                'description' => 'Section items and the cards later phases render (services, portfolio, courses, posts).',
                'widths' => [320, 480, 640, 960],
                'crop' => true,
                'aspect' => '16:9',
                'sizes' => '(min-width:1024px) 33vw, (min-width:640px) 50vw, 100vw',
                'transparency' => false,
                'used_by' => 'about image_1/image_2, rich_content image_1, later-phase cards',
            ],
            'thumbnail' => [
                'label' => 'Thumbnail',
                'description' => 'Square thumbnails for the media library and small avatars.',
                'widths' => [96, 192, 384],
                'crop' => true,
                'aspect' => '1:1',
                'sizes' => '96px',
                'transparency' => false,
                'used_by' => 'media library grid, testimonial avatars',
            ],
            'logo' => [
                'label' => 'Logo',
                'description' => 'Header and footer logo overrides. Transparency is preserved and the image is never converted to JPEG.',
                'widths' => [120, 240, 480],
                'crop' => false,
                'aspect' => null,
                'sizes' => '240px',
                'transparency' => true,
                'used_by' => 'header logo_override_light/dark, footer logo_override',
            ],
            'icon' => [
                'label' => 'Custom icon',
                'description' => 'Square custom icons for repeater items, when the Heroicons allowlist has nothing suitable.',
                'widths' => [48, 96, 144],
                'crop' => true,
                'aspect' => '1:1',
                'sizes' => '48px',
                'transparency' => true,
                'used_by' => 'website_section_items.media_asset_id',
            ],
            'og' => [
                'label' => 'Social preview (Open Graph)',
                'description' => 'The 1200 x 630 card Facebook, LinkedIn and X render. Never rendered on the site itself.',
                'widths' => [1200],
                'crop' => true,
                'aspect' => '40:21',
                'sizes' => '',
                'transparency' => false,
                'used_by' => 'seo_meta.og_image_media_id',
            ],
            'video_poster' => [
                'label' => 'Video poster',
                'description' => 'The still frame a background video shows before it plays, and the whole image when video is switched off.',
                'widths' => [640, 1280, 1920],
                'crop' => true,
                'aspect' => '16:9',
                'sizes' => '100vw',
                'transparency' => false,
                'used_by' => 'hero video_poster',
            ],
        ];
    }

    /**
     * Every profile definition, keyed by name, with every key filled in.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        if (self::$normalised !== null) {
            return self::$normalised;
        }

        $normalised = [];

        foreach (self::raw() as $name => $profile) {
            $widths = array_values(array_map('intval', (array) $profile['widths']));
            sort($widths);

            $crop = (bool) $profile['crop'];
            $aspect = $profile['aspect'] === null ? null : (string) $profile['aspect'];

            $normalised[$name] = [
                'name' => (string) $name,
                'label' => (string) $profile['label'],
                'description' => (string) $profile['description'],
                'widths' => $widths,
                'max_width' => $widths === [] ? 0 : (int) max($widths),
                'crop' => $crop,
                'fit' => $crop ? self::FIT_COVER : self::FIT_CONTAIN,
                'aspect' => $aspect,
                'sizes' => (string) $profile['sizes'],
                'transparency' => (bool) $profile['transparency'],
                'formats' => [self::FORMAT_WEBP, self::FORMAT_ORIGINAL],
                'quality' => (int) ($profile['quality'] ?? self::DEFAULT_QUALITY),
                'used_by' => (string) $profile['used_by'],
            ];
        }

        return self::$normalised = $normalised;
    }

    /**
     * Every profile name, in declaration order.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::definitions());
    }

    public static function exists(string $name): bool
    {
        return array_key_exists($name, self::definitions());
    }

    /**
     * One profile by name, or null when it is not declared. The resolver every caller should use
     * when the name came from outside the code (a request, a stored column, a queued job payload).
     *
     * @return array<string, mixed>|null
     */
    public static function resolve(string $name): ?array
    {
        return self::definitions()[$name] ?? null;
    }

    /**
     * One profile by name, or an exception. Use this where an unknown profile is a bug rather than
     * user input.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public static function get(string $name): array
    {
        $profile = self::resolve($name);

        if ($profile === null) {
            throw new InvalidArgumentException(sprintf(
                'Unknown image profile [%s]. Declared profiles: %s.',
                $name,
                implode(', ', self::names())
            ));
        }

        return $profile;
    }

    /**
     * @return list<int>
     */
    public static function widths(string $name): array
    {
        /** @var list<int> */
        return self::get($name)['widths'];
    }

    public static function maxWidth(string $name): int
    {
        return (int) self::get($name)['max_width'];
    }

    public static function quality(string $name): int
    {
        return (int) self::get($name)['quality'];
    }

    public static function crop(string $name): bool
    {
        return (bool) self::get($name)['crop'];
    }

    public static function aspect(string $name): ?string
    {
        $aspect = self::get($name)['aspect'];

        return $aspect === null ? null : (string) $aspect;
    }

    public static function sizesAttribute(string $name): string
    {
        return (string) self::get($name)['sizes'];
    }

    public static function acceptsTransparency(string $name): bool
    {
        return (bool) self::get($name)['transparency'];
    }

    /**
     * The derivatives to generate for one profile: one per width per format.
     *
     * @param  int|null  $quality  `website.image_quality`, or null for the declared default
     * @param  bool  $webp  `website.image_webp_enabled`; false emits the original format only
     * @return list<ImageDerivative>
     */
    public static function derivatives(string $name, ?int $quality = null, bool $webp = true): array
    {
        $profile = self::get($name);
        $quality = self::clampQuality($quality ?? (int) $profile['quality']);

        $formats = $webp
            ? $profile['formats']
            : array_values(array_filter(
                (array) $profile['formats'],
                static fn (string $format): bool => $format !== self::FORMAT_WEBP
            ));

        $derivatives = [];

        foreach ((array) $profile['widths'] as $width) {
            $width = (int) $width;
            $height = self::heightFor((string) $profile['name'], $width);

            foreach ($formats as $format) {
                $derivatives[] = new ImageDerivative(
                    profile: (string) $profile['name'],
                    name: $profile['name'].'-'.$width.'-'.$format,
                    width: $width,
                    height: $height,
                    fit: (string) $profile['fit'],
                    quality: $quality,
                    format: (string) $format,
                );
            }
        }

        return $derivatives;
    }

    /**
     * The derivative height for one width: null when the profile is not cropped, otherwise the
     * width scaled by the declared aspect ratio and rounded to the nearest pixel.
     */
    public static function heightFor(string $name, int $width): ?int
    {
        $profile = self::get($name);

        if (! $profile['crop'] || $profile['aspect'] === null) {
            return null;
        }

        [$horizontal, $vertical] = self::ratio((string) $profile['aspect']);

        if ($horizontal <= 0 || $vertical <= 0) {
            return null;
        }

        return (int) round($width * $vertical / $horizontal);
    }

    /**
     * Profile name => label, ready for a `<select>` or the upload dialog of §8.13.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::definitions() as $name => $profile) {
            $options[$name] = (string) $profile['label'];
        }

        return $options;
    }

    /**
     * The admin-facing table of §8.13: one row per profile with the numbers spelled out, so an
     * administrator can see what an upload will produce before choosing.
     *
     * @return list<array<string, mixed>>
     */
    public static function forAdmin(): array
    {
        $rows = [];

        foreach (self::definitions() as $profile) {
            $rows[] = [
                'name' => $profile['name'],
                'label' => $profile['label'],
                'description' => $profile['description'],
                'widths' => $profile['widths'],
                'max_width' => $profile['max_width'],
                'crop' => $profile['crop'],
                'aspect' => $profile['aspect'],
                'sizes' => $profile['sizes'],
                'transparency' => $profile['transparency'],
                'quality' => $profile['quality'],
                'used_by' => $profile['used_by'],
                'summary' => self::summary((string) $profile['name']),
            ];
        }

        return $rows;
    }

    /**
     * "5 widths up to 2560px, not cropped" — the one-line help under a profile select.
     */
    public static function summary(string $name): string
    {
        $profile = self::get($name);
        $count = count((array) $profile['widths']);

        return sprintf(
            '%d width%s up to %dpx, %s',
            $count,
            $count === 1 ? '' : 's',
            (int) $profile['max_width'],
            $profile['crop'] ? 'cropped to '.$profile['aspect'] : 'not cropped'
        );
    }

    /**
     * `"16:9"` => `[16, 9]`; anything unparseable => `[0, 0]`.
     *
     * @return array{0: int, 1: int}
     */
    private static function ratio(string $aspect): array
    {
        if (! str_contains($aspect, ':')) {
            return [0, 0];
        }

        [$horizontal, $vertical] = explode(':', $aspect, 2);

        return [(int) trim($horizontal), (int) trim($vertical)];
    }

    /**
     * `website.image_quality` is validated 60-95 by the settings registry; clamping here means a
     * value that reached us another way (a console call, a queued payload) still cannot produce a
     * quality of 0 or 300.
     */
    private static function clampQuality(int $quality): int
    {
        return max(60, min(95, $quality));
    }
}
