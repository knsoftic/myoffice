<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

/**
 * The per-placement caption of one gallery image — `admin.portfolio.images.update`,
 * `can:portfolio.edit` (phase-04 §2.8 `portfolio_item_media.caption`, §8.3 "per-attachment caption
 * inline", `PortfolioService::updateCaption()`).
 *
 * Only the caption: the alt text is one value per image and lives on `media_assets`, edited in the media
 * library; nothing else about the placement is writable here.
 */
final class UpdatePortfolioImageCaptionRequest extends CmsFormRequest
{
    protected function permission(): string
    {
        return 'portfolio.edit';
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'caption' => ['present', 'bail', 'nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['caption']);
    }

    public function caption(): ?string
    {
        $caption = $this->validated('caption');

        return is_string($caption) ? $caption : null;
    }
}
