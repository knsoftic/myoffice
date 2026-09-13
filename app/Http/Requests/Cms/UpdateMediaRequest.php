<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

/**
 * Edit a media item's alt text, title and caption (`admin.website.media.update`,
 * `can:website_media.edit`) — the only columns `website_media.edit` may change (§4.1).
 *
 * Any other key is refused by name rather than ignored, so a form that tries to post a path, a checksum
 * or a usage count gets a 422 (and `MediaService::updateDetails()` refuses it again).
 */
final class UpdateMediaRequest extends CmsFormRequest
{
    private const EDITABLE = ['alt_text', 'title', 'caption'];

    protected function permission(): string
    {
        return 'website_media.edit';
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'alt_text' => ['sometimes', 'bail', 'nullable', 'string', 'max:255'],
            'title' => ['sometimes', 'bail', 'nullable', 'string', 'max:191'],
            'caption' => ['sometimes', 'bail', 'nullable', 'string', 'max:500'],

            'disk' => ['prohibited'],
            'directory' => ['prohibited'],
            'filename' => ['prohibited'],
            'original_name' => ['prohibited'],
            'mime_type' => ['prohibited'],
            'checksum' => ['prohibited'],
            'variants' => ['prohibited'],
            'usage_count' => ['prohibited'],
            'collection' => ['prohibited'],
            'profile' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(self::EDITABLE);
    }

    /**
     * @return array<string, string|null>
     */
    public function details(): array
    {
        return $this->safe()->only(self::EDITABLE);
    }
}
