<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Enums\Cms\ImageProfile;
use App\Enums\Cms\MediaCollection;
use Illuminate\Validation\Rule;

/**
 * Upload one file to the media library (`admin.website.media.store`, `can:website_media.upload`,
 * `throttle:60,1`, §6.8, §8.13).
 *
 * This request checks only what the transport can know: a file arrived, and it is not larger than
 * `security.max_upload_mb`. Whether it is a real image or video is decided by `MediaService::store()`
 * from the **file content** — `finfo` and `getimagesize()`, never the extension or the client's MIME
 * header — and SVG is refused there (INV-11, FT-33); that refusal comes back as a 422 on `file`.
 */
final class StoreMediaRequest extends CmsFormRequest
{
    protected function permission(): string
    {
        return 'website_media.upload';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => ['bail', 'required', 'file', 'max:'.$this->maxKilobytes()],
            'collection' => ['bail', 'nullable', 'string', Rule::enum(MediaCollection::class)],
            'profile' => ['bail', 'nullable', 'string', Rule::enum(ImageProfile::class)],
            'alt_text' => ['bail', 'nullable', 'string', 'max:255'],
            'title' => ['bail', 'nullable', 'string', 'max:191'],
            'caption' => ['bail', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.max' => 'That file is larger than the upload limit of :max KB.',
            'file.uploaded' => 'The file did not finish uploading. It may be larger than the server allows.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['alt_text', 'title', 'caption']);
    }

    public function collection(): MediaCollection
    {
        return MediaCollection::tryFrom((string) $this->validated('collection')) ?? MediaCollection::General;
    }

    public function profile(): ?ImageProfile
    {
        return ImageProfile::tryFrom((string) $this->validated('profile'));
    }

    /**
     * @return array{alt_text?: string|null, title?: string|null, caption?: string|null}
     */
    public function meta(): array
    {
        return $this->safe()->only(['alt_text', 'title', 'caption']);
    }

    private function maxKilobytes(): int
    {
        $megabytes = setting('security.max_upload_mb', 10);
        $megabytes = is_numeric($megabytes) ? (int) $megabytes : 10;

        return max(1, $megabytes) * 1024;
    }
}
