<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

use App\Support\SettingsRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * One public-website image slot of a phase-04 entity (phase-04 §6.6, D24, acceptance test 10).
 *
 * `<x-cms.image-field>` posts one of three things for a slot, and never a path:
 *
 *   · `{field}`            a new upload, handed to the owning service, which stores it through
 *                          `MediaService::store()` and sets the `*_media_id`;
 *   · `{field}_media_id`   an existing media-library asset picked instead of uploading;
 *   · `remove_{field}`     clear the slot (the asset stays in the library).
 *
 * The transport rules here are a first gate only — `MediaService` decides from the file **content**
 * again. `mimetypes:` is itself read with finfo from the temporary file (never the browser header or the
 * extension), so an SVG, or a `.php` renamed `.jpg`, is refused here as well as there; `max:` is
 * `security.max_upload_mb`.
 */
trait ValidatesContentImage
{
    /** The raster types `MediaService::IMAGE_MIMES` accepts. SVG is never among them. */
    private const IMAGE_MIME_TYPES = 'image/jpeg,image/png,image/webp,image/gif,image/avif';

    /**
     * @return array<string, list<mixed>>
     */
    protected function imageRules(string $field = 'image'): array
    {
        return [
            $field => ['sometimes', 'bail', 'nullable', 'file', 'mimetypes:'.self::IMAGE_MIME_TYPES, 'max:'.$this->uploadLimitKilobytes()],
            $field.'_media_id' => ['sometimes', 'bail', 'nullable', 'integer', 'min:1', Rule::exists('media_assets', 'id')->whereNull('deleted_at')],
            'remove_'.$field => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The uploaded file for the slot, when one arrived and passed validation.
     */
    public function uploadedImage(string $field = 'image'): ?UploadedFile
    {
        $file = $this->file($field);

        return $file instanceof UploadedFile ? $file : null;
    }

    /**
     * The `*_media_id` column change the slot asks for, or an empty array when the slot is untouched.
     *
     * An upload wins over a library pick (the service sets the column from the stored asset); an
     * explicit remove clears it. `<x-cms.image-field>` (Phase 3's media picker) always posts
     * `{field}_media_id` — the chosen id, or `''` once the admin clears the picker — so a posted-but-empty
     * id clears the slot too; a request that does not post the key at all leaves the column alone.
     *
     * @return array<string, int|null>
     */
    protected function imageColumnPayload(string $column, string $field = 'image'): array
    {
        if ($this->uploadedImage($field) !== null) {
            return [];
        }

        if ($this->boolean('remove_'.$field)) {
            return [$column => null];
        }

        $validated = $this->validated();

        if (! array_key_exists($field.'_media_id', $validated)) {
            return [];
        }

        $picked = $validated[$field.'_media_id'];

        return [$column => is_numeric($picked) ? (int) $picked : null];
    }

    /**
     * `security.max_upload_mb`, never above what PHP itself accepts (`SettingsRegistry::uploadKilobytes()`).
     */
    private function uploadLimitKilobytes(): int
    {
        return SettingsRegistry::uploadKilobytes(
            SettingsRegistry::MAX_UPLOAD_MB_MAX * 1024,
            setting('security.max_upload_mb', 10),
        );
    }
}
