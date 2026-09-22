<?php

declare(strict_types=1);

namespace App\DataObjects\Files;

/**
 * A file that is on disk, described by what the **server** decided (phase-19-23 §6.1).
 *
 * Every field here is derived rather than accepted: the name is a ULID, the extension comes from the
 * sniffed MIME, the size is measured and the checksum is streamed. `originalName` is the one thing the
 * client supplied, and it exists for exactly one purpose — the `Content-Disposition` header. It never
 * reaches the filesystem (INV-19-2).
 *
 * `disk` is `private` for everything here — §6.3's `storage/app/private` — except a print-template
 * background, which is the one `public` row in the whole of phases 19-23. It is recorded per row rather
 * than assumed, which is what makes a future disk migration provable.
 */
final readonly class StoredFile
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $originalName,
        public string $extension,
        public string $mimeType,
        public int $sizeBytes,
        public ?string $checksumSha256 = null,
    ) {}

    /**
     * The columns a model row stores, keyed the way every table in this phase names them.
     *
     * @return array<string, mixed>
     */
    public function toColumns(string $prefix = ''): array
    {
        return [
            $prefix.'storage_disk' => $this->disk,
            $prefix.'file_path' => $this->path,
            $prefix.'original_name' => $this->originalName,
            $prefix.'extension' => $this->extension,
            $prefix.'mime_type' => $this->mimeType,
            $prefix.'file_size_bytes' => $this->sizeBytes,
            $prefix.'checksum_sha256' => $this->checksumSha256,
        ];
    }

    /**
     * Rebuilt from a row, so `stream()` and `delete()` take the same object whether the file was just
     * written or read back a year later.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromColumns(array $row, string $prefix = ''): self
    {
        return new self(
            disk: (string) ($row[$prefix.'storage_disk'] ?? 'private'),
            path: (string) ($row[$prefix.'file_path'] ?? ''),
            originalName: (string) ($row[$prefix.'original_name'] ?? ''),
            extension: (string) ($row[$prefix.'extension'] ?? ''),
            mimeType: (string) ($row[$prefix.'mime_type'] ?? 'application/octet-stream'),
            sizeBytes: (int) ($row[$prefix.'file_size_bytes'] ?? 0),
            checksumSha256: ($row[$prefix.'checksum_sha256'] ?? null) === null
                ? null
                : (string) $row[$prefix.'checksum_sha256'],
        );
    }

    /** True when this object describes a file that was actually stored. */
    public function exists(): bool
    {
        return $this->path !== '';
    }

    /**
     * Whether the browser may be asked to render this inline. Deliberately narrow: a PDF or an image
     * and nothing else. An HTML or SVG file rendered inline on our own origin is a stored XSS, which
     * is why §6.1 refuses those extensions outright and this refuses them again at read time.
     */
    public function isInlineSafe(): bool
    {
        return $this->mimeType === 'application/pdf' || str_starts_with($this->mimeType, 'image/');
    }
}
