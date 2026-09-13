<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\ValidatesCtaBlock;
use App\Models\Cms\CtaBlock;

/**
 * Create a CTA block (`admin.website.cta-blocks.store`, `can:website_cta_blocks.create`).
 */
final class StoreCtaBlockRequest extends CmsFormRequest
{
    use ValidatesCtaBlock;

    protected function permission(): string
    {
        return 'website_cta_blocks.create';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->ctaRules(partial: false);
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
        return null;
    }
}
