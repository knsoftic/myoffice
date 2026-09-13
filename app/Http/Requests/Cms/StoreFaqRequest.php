<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\ValidatesFaq;

/**
 * Add a question (`admin.website.faqs.store`, `can:faqs.create`).
 */
final class StoreFaqRequest extends CmsFormRequest
{
    use ValidatesFaq;

    protected function permission(): string
    {
        return 'faqs.create';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->faqRules(partial: false);
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['question']);
    }
}
