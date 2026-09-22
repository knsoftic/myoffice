<?php

declare(strict_types=1);

namespace App\DataObjects\Files;

/**
 * How a stored file is handed to the browser (phase-19-23 §6.1, §6.4 step 7, D21).
 *
 * **`inline()` is a request, not a decision.** `SecureFileService::stream()` downgrades it to an
 * attachment unless the **stored** MIME is `application/pdf` or `image/*` — whatever the caller asked
 * for, and whatever the URL says. The whole value of serving private files through a controller is lost
 * the moment one of them renders as HTML on our own origin.
 *
 * **`Accept-Ranges: none` is deliberate and is not an oversight for video.** A range request served from
 * here would have to re-run §6.4's seven-step chain per range, and a partial response that skipped it is
 * exactly the hole the chain exists to close. A recorded lecture downloads and then plays.
 */
final readonly class StreamOptions
{
    /**
     * A private file may pull in nothing and may do nothing. `sandbox` with no allow-list means no
     * scripts, no forms, no same-origin — so even a file that somehow sniffed as something renderable
     * is inert.
     */
    public const CONTENT_SECURITY_POLICY = "default-src 'none'; sandbox";

    public function __construct(
        public bool $inline = false,
        public ?string $downloadName = null,
    ) {}

    /** The default: `Content-Disposition: attachment`, whatever the file is. */
    public static function attachment(?string $downloadName = null): self
    {
        return new self(downloadName: $downloadName);
    }

    /** Ask for `inline` — honoured only for a PDF or an image, and only if the field permits it. */
    public static function inline(?string $downloadName = null): self
    {
        return new self(inline: true, downloadName: $downloadName);
    }

    /**
     * The hardened headers of §6.1, byte for byte. `nosniff` stops the browser second-guessing the MIME
     * we chose; `no-store` keeps a file whose permission was revoked a minute ago out of the disk cache.
     *
     * @return array<string, string>
     */
    public function headers(string $mimeType, ?int $sizeBytes = null): array
    {
        $headers = [
            'Content-Type' => $mimeType === '' ? 'application/octet-stream' : $mimeType,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
            'Cache-Control' => 'private, no-store',
            'Accept-Ranges' => 'none',
        ];

        if ($sizeBytes !== null && $sizeBytes > 0) {
            $headers['Content-Length'] = (string) $sizeBytes;
        }

        return $headers;
    }
}
