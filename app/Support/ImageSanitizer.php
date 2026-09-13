<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Re-encodes an uploaded image through GD so only decoded pixels are ever stored.
 *
 * Validation proves a file STARTS like an image; it does not prove the file is only an image. A valid
 * GIF or PNG header followed by PHP (a polyglot) passes `image`, `mimes`, `mimetypes` and `dimensions`,
 * and would otherwise be written to the public disk byte for byte. Decoding and re-encoding drops
 * anything that is not pixel data: appended payloads, comments, and EXIF metadata such as GPS position.
 *
 * GIF animations keep their first frame only; that is acceptable for profile photos.
 */
final class ImageSanitizer
{
    /**
     * Refuse to decode anything larger than this many pixels, so a tiny file that declares huge
     * dimensions (a decompression bomb) cannot exhaust memory.
     */
    public const MAX_PIXELS = 40_000_000;

    /**
     * Longest edge of the stored image; larger uploads are downscaled proportionally.
     */
    public const MAX_EDGE = 1024;

    /**
     * @return array{bytes: string, extension: string}
     *
     * @throws ValidationException when the file cannot be decoded as an image
     */
    public static function reencode(UploadedFile $file, string $field = 'avatar'): array
    {
        $raw = @file_get_contents((string) $file->getRealPath());

        if (! is_string($raw) || $raw === '') {
            throw self::refuse($field);
        }

        $info = @getimagesizefromstring($raw);

        if ($info === false || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
            throw self::refuse($field);
        }

        [$width, $height, $type] = [(int) $info[0], (int) $info[1], (int) $info[2]];

        if ($width * $height > self::MAX_PIXELS) {
            throw self::refuse($field, 'This image is too large to process.');
        }

        $format = match ($type) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
            default => null,
        };

        if ($format === null) {
            throw self::refuse($field);
        }

        $source = @imagecreatefromstring($raw);

        if ($source === false) {
            throw self::refuse($field);
        }

        $image = self::fitWithin($source, $width, $height, $format !== 'jpg');

        ob_start();

        try {
            $written = match ($format) {
                'jpg' => imagejpeg($image, null, 90),
                'png' => imagepng($image, null, 6),
                'gif' => imagegif($image),
                'webp' => imagewebp($image, null, 90),
            };
        } finally {
            $bytes = (string) ob_get_clean();

            if ($image !== $source) {
                imagedestroy($image);
            }

            imagedestroy($source);
        }

        if (! $written || $bytes === '') {
            throw self::refuse($field);
        }

        return ['bytes' => $bytes, 'extension' => $format];
    }

    /**
     * Downscale to MAX_EDGE on the longest side, keeping transparency for formats that have it.
     */
    private static function fitWithin(\GdImage $source, int $width, int $height, bool $keepAlpha): \GdImage
    {
        $longest = max($width, $height);

        if ($longest <= self::MAX_EDGE) {
            if ($keepAlpha) {
                imagesavealpha($source, true);
            }

            return $source;
        }

        $scale = self::MAX_EDGE / $longest;
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($keepAlpha) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        }

        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $target;
    }

    private static function refuse(string $field, string $message = 'This image could not be processed. Please upload a different file.'): ValidationException
    {
        return ValidationException::withMessages([$field => $message]);
    }
}
