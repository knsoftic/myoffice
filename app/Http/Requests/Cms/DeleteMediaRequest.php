<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

/**
 * Soft-delete a media item (`admin.website.media.destroy`, `can:website_media.delete`, §8.13, FT-38).
 *
 * The reason is optional and recorded when given. The files stay on disk ([D-W3-17]); an item still in
 * use is refused with its usage list by the policy and by `MediaService::delete()`.
 */
final class DeleteMediaRequest extends CmsFormRequest
{
    protected function permission(): string
    {
        return 'website_media.delete';
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['bail', 'nullable', 'string', 'max:'.self::REASON_MAX],
        ];
    }
}
