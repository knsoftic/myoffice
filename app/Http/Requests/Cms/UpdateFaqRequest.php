<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\ValidatesFaq;

/**
 * Update a question (`admin.website.faqs.update`, `can:faqs.edit`).
 */
final class UpdateFaqRequest extends CmsFormRequest
{
    use ValidatesFaq;

    protected function permission(): string
    {
        return 'faqs.edit';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->faqRules(partial: true);
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['question']);
    }
}
