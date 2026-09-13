<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\ValidatesFaqCategory;
use App\Models\Cms\FaqCategory;

/**
 * Update an FAQ category, including its enable switch (`admin.website.faq-categories.update`,
 * `can:faq_categories.edit`).
 */
final class UpdateFaqCategoryRequest extends CmsFormRequest
{
    use ValidatesFaqCategory;

    protected function permission(): string
    {
        return 'faq_categories.edit';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->categoryRules(partial: true);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->categoryMessages();
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['name', 'slug', 'description', 'icon']);
    }

    public function category(): ?FaqCategory
    {
        return $this->boundModel('category', FaqCategory::class);
    }
}
