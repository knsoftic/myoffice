<?php

declare(strict_types=1);

namespace App\Services\Cms\Exceptions;

use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * An upload was refused by `MediaService::store()` (phase-03 §6.8, INV-11, FT-33).
 *
 * Every refusal below is decided from the **file's own bytes**, never from its extension or the
 * client's `Content-Type` header: the real MIME comes from `finfo`, and an image must additionally
 * survive `getimagesize()`. The named constructors exist so the reason reaches the user as a sentence
 * ("SVG files are not accepted because ...") rather than as "invalid file".
 *
 * SVG is refused deliberately ([D-W3-15], R-6): an SVG is executable XML and sanitising it properly
 * is its own project. Logos come from Phase 2's `branding.*` settings.
 */
final class UnsupportedUploadException extends RuntimeException
{
    public static function tooLarge(int $bytes, int $limitBytes): self
    {
        return new self(sprintf(
            'That file is %s and the limit is %s.',
            self::humanBytes($bytes),
            self::humanBytes($limitBytes)
        ));
    }

    public static function empty(): self
    {
        return new self('That file is empty.');
    }

    public static function svg(): self
    {
        return new self(
            'SVG files are not accepted: an SVG is executable XML. '
            .'Upload a PNG or WebP instead — at logo sizes the two are indistinguishable.'
        );
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function mime(string $detected, array $allowed): self
    {
        return new self(sprintf(
            'That file is a %s, which is not an accepted image or video type. Accepted: %s.',
            $detected === '' ? 'file of unknown type' : $detected,
            implode(', ', $allowed)
        ));
    }

    public static function notAnImage(string $detected): self
    {
        return new self(sprintf(
            'That file claims to be a %s but its contents are not a readable image.',
            $detected
        ));
    }

    public static function unreadable(): self
    {
        return new self('That upload could not be read from the temporary directory.');
    }

    public static function videoNeedsPoster(): self
    {
        return new self('A background video needs a poster image, which is shown before it plays.');
    }

    public function toValidationException(string $field = 'file'): ValidationException
    {
        return ValidationException::withMessages([$field => [$this->getMessage()]]);
    }

    private static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024).' KB';
        }

        return $bytes.' bytes';
    }
}
