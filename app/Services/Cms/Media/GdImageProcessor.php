<?php

declare(strict_types=1);

namespace App\Services\Cms\Media;

use GdImage;
use RuntimeException;

/**
 * The pixel work behind `MediaService` (phase-03 §6.8, D24), on PHP's bundled GD extension.
 *
 * Why GD and not a package: GD ships with PHP, is present in every environment this project runs
 * in (`DEVELOPMENT_LOG.md` §2 lists it), and re-encoding through it is itself the EXIF strip — GD
 * never writes metadata. `intervention/image` is therefore **not required** for the pipeline to be
 * correct; see the handover note. When GD is missing, or cannot decode a format on this build,
 * `supports()` says so and `MediaService` refuses that upload with a readable reason rather than
 * storing a file whose metadata it could not remove.
 *
 * Invariants:
 *
 *   · **Never upscales.** A target wider (or, for a crop, taller) than the source is not produced.
 *   · **Orientation first, then strip.** A JPEG's EXIF orientation is applied to the pixels before
 *     the metadata is discarded by re-encoding, so a phone photo is not stored sideways (FT-34).
 *   · **Transparency is kept** for every format that has it; only a JPEG output is flattened, onto
 *     white, and a transparent profile never asks for JPEG.
 *   · **Memory guard.** Anything above {@see self::MAX_PIXELS} is refused before it is decoded, so a
 *     crafted 30 000 x 30 000 PNG cannot exhaust PHP (§10.2).
 */
final class GdImageProcessor
{
    /** 40 megapixels (§10.2). */
    public const MAX_PIXELS = 40_000_000;

    /** Encoder quality for a normalised original — high, since every derivative is made from it. */
    public const ORIGINAL_QUALITY = 90;

    /** @var array<string, string> MIME => output format */
    public const FORMATS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/avif' => 'avif',
    ];

    public function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring');
    }

    /**
     * Can this build both decode and encode the format?
     */
    public function supports(string $mime): bool
    {
        if (! $this->available() || ! isset(self::FORMATS[$mime])) {
            return false;
        }

        $types = imagetypes();

        return match ($mime) {
            'image/jpeg' => ($types & IMG_JPG) !== 0,
            'image/png' => ($types & IMG_PNG) !== 0,
            'image/webp' => ($types & IMG_WEBP) !== 0 && function_exists('imagewebp'),
            'image/gif' => ($types & IMG_GIF) !== 0,
            'image/avif' => defined('IMG_AVIF') && ($types & IMG_AVIF) !== 0 && function_exists('imageavif'),
            default => false,
        };
    }

    public function supportsWebp(): bool
    {
        return $this->supports('image/webp');
    }

    /**
     * The stored original: oriented, metadata-free, downscaled to `$maxWidth` when wider (never
     * upscaled), in its own format.
     *
     * An animated GIF no wider than the ceiling is kept byte for byte — GIF carries no EXIF, and
     * re-encoding would flatten the animation to its first frame.
     *
     * @return array{bytes: string, width: int, height: int}
     */
    public function normaliseOriginal(string $path, string $mime, int $maxWidth): array
    {
        $bytes = (string) file_get_contents($path);
        [$width, $height] = $this->dimensions($bytes);

        $this->guardPixels($width, $height);

        if ($mime === 'image/gif' && $width <= $maxWidth) {
            return ['bytes' => $bytes, 'width' => $width, 'height' => $height];
        }

        $image = $this->decode($bytes);

        if ($mime === 'image/jpeg') {
            $image = $this->applyOrientation($image, $path);
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width > $maxWidth) {
            $targetHeight = max(1, (int) round($height * $maxWidth / $width));
            $image = $this->resample($image, $maxWidth, $targetHeight, null, $mime !== 'image/jpeg');
            $width = $maxWidth;
            $height = $targetHeight;
        }

        return [
            'bytes' => $this->encode($image, self::FORMATS[$mime], self::ORIGINAL_QUALITY),
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Decode image bytes.
     *
     * @throws RuntimeException
     */
    public function decode(string $bytes): GdImage
    {
        if (! $this->available()) {
            throw new RuntimeException('The GD image extension is not available on this server.');
        }

        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            throw new RuntimeException('The image could not be decoded (it may be animated, corrupt or an unsupported variant).');
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    /**
     * One derivative: `contain` when `$height` is null (width-constrained), `cover` (centre crop)
     * otherwise. Returns null when producing it would upscale.
     *
     * @return array{bytes: string, width: int, height: int}|null
     */
    public function derivative(GdImage $source, int $width, ?int $height, string $format, int $quality): ?array
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        if ($width > $sourceWidth || ($height !== null && $height > $sourceHeight)) {
            return null;
        }

        $alpha = $format !== 'jpg';

        if ($height === null) {
            $targetHeight = max(1, (int) round($sourceHeight * $width / $sourceWidth));
            $image = $this->resample($source, $width, $targetHeight, null, $alpha, keepSource: true);
        } else {
            $scale = max($width / $sourceWidth, $height / $sourceHeight);
            $cropWidth = min($sourceWidth, (int) round($width / $scale));
            $cropHeight = min($sourceHeight, (int) round($height / $scale));
            $crop = [
                (int) floor(($sourceWidth - $cropWidth) / 2),
                (int) floor(($sourceHeight - $cropHeight) / 2),
                $cropWidth,
                $cropHeight,
            ];

            $targetHeight = $height;
            $image = $this->resample($source, $width, $height, $crop, $alpha, keepSource: true);
        }

        $bytes = $this->encode($image, $format, $quality);
        unset($image);

        return ['bytes' => $bytes, 'width' => $width, 'height' => $targetHeight];
    }

    /**
     * Encode to `jpg`, `png`, `webp`, `gif` or `avif`.
     *
     * @throws RuntimeException
     */
    public function encode(GdImage $image, string $format, int $quality): string
    {
        $quality = max(1, min(100, $quality));

        ob_start();

        try {
            $ok = match ($format) {
                'jpg' => $this->encodeJpeg($image, $quality),
                'png' => imagepng($image, null, 6),
                'webp' => imagewebp($image, null, $quality),
                'gif' => $this->encodeGif($image),
                'avif' => function_exists('imageavif') && imageavif($image, null, $quality, 6),
                default => throw new RuntimeException(sprintf('Unsupported output format [%s].', $format)),
            };

            $bytes = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        if ($ok !== true || $bytes === '') {
            throw new RuntimeException(sprintf('The image could not be encoded as %s.', $format));
        }

        return $bytes;
    }

    /**
     * Width and height from the bytes, without decoding the pixels.
     *
     * @return array{0: int, 1: int}
     */
    public function dimensions(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);

        if (! is_array($info) || (int) $info[0] < 1 || (int) $info[1] < 1) {
            throw new RuntimeException('The image dimensions could not be read.');
        }

        return [(int) $info[0], (int) $info[1]];
    }

    public function guardPixels(int $width, int $height): void
    {
        if ($width * $height > self::MAX_PIXELS) {
            throw new RuntimeException(sprintf(
                'The image is %d x %d (%.1f megapixels); the limit is %d megapixels.',
                $width,
                $height,
                $width * $height / 1_000_000,
                self::MAX_PIXELS / 1_000_000
            ));
        }
    }

    /**
     * @param  array{0: int, 1: int, 2: int, 3: int}|null  $crop  [x, y, width, height] of the source
     */
    private function resample(GdImage $source, int $width, int $height, ?array $crop, bool $alpha, bool $keepSource = false): GdImage
    {
        $target = imagecreatetruecolor($width, $height);

        if (! $target instanceof GdImage) {
            throw new RuntimeException('Could not allocate the resized image.');
        }

        if ($alpha) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = (int) imagecolorallocatealpha($target, 0, 0, 0, 127);
            imagefilledrectangle($target, 0, 0, $width, $height, $transparent);
        } else {
            $white = (int) imagecolorallocate($target, 255, 255, 255);
            imagefilledrectangle($target, 0, 0, $width, $height, $white);
            imagealphablending($target, true);
        }

        [$x, $y, $sourceWidth, $sourceHeight] = $crop ?? [0, 0, imagesx($source), imagesy($source)];

        imagecopyresampled($target, $source, 0, 0, $x, $y, $width, $height, $sourceWidth, $sourceHeight);

        if (! $keepSource) {
            unset($source);
        }

        return $target;
    }

    private function applyOrientation(GdImage $image, string $path): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotate = static function (GdImage $image, int $angle): GdImage {
            $rotated = imagerotate($image, $angle, 0);

            return $rotated instanceof GdImage ? $rotated : $image;
        };

        return match ($orientation) {
            2 => $this->flip($image, IMG_FLIP_HORIZONTAL),
            3 => $rotate($image, 180),
            4 => $this->flip($image, IMG_FLIP_VERTICAL),
            5 => $this->flip($rotate($image, -90), IMG_FLIP_HORIZONTAL),
            6 => $rotate($image, -90),
            7 => $this->flip($rotate($image, 90), IMG_FLIP_HORIZONTAL),
            8 => $rotate($image, 90),
            default => $image,
        };
    }

    private function flip(GdImage $image, int $mode): GdImage
    {
        imageflip($image, $mode);

        return $image;
    }

    private function encodeJpeg(GdImage $image, int $quality): bool
    {
        imageinterlace($image, true);

        return imagejpeg($image, null, $quality);
    }

    private function encodeGif(GdImage $image): bool
    {
        $copy = imagecreatetruecolor(imagesx($image), imagesy($image));

        if (! $copy instanceof GdImage) {
            return false;
        }

        imagecopy($copy, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        imagetruecolortopalette($copy, true, 255);

        return imagegif($copy);
    }
}
