<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What a syllabus resource actually is (`course_topic_resources.type`, requirement §65, §79).
 *
 * **`allowedMimes()` is a security control, not a convenience.** §111 requires uploads to be validated
 * by what the file *is*, never by what its name claims, and this is the one whitelist the validator,
 * the service and the test all read. A `.php` renamed to `.pdf` announces `application/pdf` in the
 * request and `text/x-php` to `finfo`; the list below is what the second answer is checked against.
 *
 * `Link` is the odd one out and is meant to be: it carries no file at all, which is why
 * `chk_ctr_target` demands a path **or** a URL rather than assuming a file is always there.
 */
enum CourseResourceType: string
{
    use HasOptions;

    case Pdf = 'pdf';
    case Document = 'document';
    case Note = 'note';
    case Slide = 'slide';
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Zip = 'zip';
    case SourceCode = 'source_code';
    case Link = 'link';

    public function label(): string
    {
        return match ($this) {
            self::Pdf => 'PDF',
            self::Document => 'Document',
            self::Note => 'Note',
            self::Slide => 'Slides',
            self::Image => 'Image',
            self::Video => 'Video',
            self::Audio => 'Audio',
            self::Zip => 'Archive',
            self::SourceCode => 'Source code',
            self::Link => 'Link',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pdf => 'rose',
            self::Document, self::Note => 'sky',
            self::Slide => 'amber',
            self::Image => 'violet',
            self::Video => 'brand',
            self::Audio => 'emerald',
            self::Zip, self::SourceCode => 'slate',
            self::Link => 'sky',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Pdf => 'document-text',
            self::Document, self::Note => 'document',
            self::Slide => 'presentation-chart-bar',
            self::Image => 'photo',
            self::Video => 'video-camera',
            self::Audio => 'musical-note',
            self::Zip => 'archive-box',
            self::SourceCode => 'code-bracket',
            self::Link => 'link',
        };
    }

    /**
     * Does this type carry a file? Everything except a link, which is why the link case needs a URL.
     */
    public function isFile(): bool
    {
        return $this !== self::Link;
    }

    /**
     * The server-side MIME whitelist, checked against the file's **content** (§111).
     *
     * A link has none: there is nothing to upload, and returning an empty list makes an attempt to
     * upload against it fail on its own rather than needing a second rule elsewhere.
     *
     * @return list<string>
     */
    public function allowedMimes(): array
    {
        return match ($this) {
            self::Pdf => ['application/pdf'],
            self::Document => [
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/rtf',
                'application/vnd.oasis.opendocument.text',
                'text/plain',
            ],
            self::Note => ['text/plain', 'text/markdown', 'application/pdf'],
            self::Slide => [
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/vnd.oasis.opendocument.presentation',
                'application/pdf',
            ],
            self::Image => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'],
            self::Video => ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'],
            self::Audio => ['audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/webm', 'audio/mp4'],
            self::Zip => ['application/zip', 'application/x-zip-compressed', 'application/x-7z-compressed'],
            // Shipped as an archive, never as a loose script: a `.php` upload that the web server can
            // reach is the oldest remote-execution hole there is.
            self::SourceCode => ['application/zip', 'application/x-zip-compressed', 'text/plain'],
            self::Link => [],
        };
    }

    /**
     * The file extensions the upload control offers. Cosmetic — the MIME check above is the control.
     *
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        return match ($this) {
            self::Pdf => ['pdf'],
            self::Document => ['doc', 'docx', 'rtf', 'odt', 'txt'],
            self::Note => ['txt', 'md', 'pdf'],
            self::Slide => ['ppt', 'pptx', 'odp', 'pdf'],
            self::Image => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'],
            self::Video => ['mp4', 'webm', 'ogv', 'mov'],
            self::Audio => ['mp3', 'ogg', 'wav', 'weba', 'm4a'],
            self::Zip => ['zip', '7z'],
            self::SourceCode => ['zip', 'txt'],
            self::Link => [],
        };
    }
}
