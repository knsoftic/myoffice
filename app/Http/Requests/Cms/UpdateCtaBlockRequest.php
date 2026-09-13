<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\ValidatesCtaBlock;
use App\Models\Cms\CtaBlock;

/**
 * Update a CTA block (`admin.website.cta-blocks.update`, `can:website_cta_blocks.edit`).
 *
 * A key change is validated for shape and uniqueness here; `CtaBlockService::save()` refuses it once any
 * section references the block (`ctaKeyImmutable`).
 */
final class UpdateCtaBlockRequest extends CmsFormRequest
{
    use ValidatesCtaBlock;

    protected function permission(): string
    {
        return 'website_cta_blocks.edit';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->ctaRules(partial: true);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->ctaMessages();
    }

    protected function prepareForValidation(): void
    {
        $this->prepareCta();
    }

    public function ctaBlock(): ?CtaBlock
    {
        return $this->boundModel('ctaBlock', CtaBlock::class);
    }
}
