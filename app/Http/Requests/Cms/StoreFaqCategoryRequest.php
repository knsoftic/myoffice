<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\ValidatesFaqCategory;
use App\Models\Cms\FaqCategory;

/**
 * Add an FAQ category (`admin.website.faq-categories.store`, `can:faq_categories.create`).
 */
final class StoreFaqCategoryRequest extends CmsFormRequest
{
    use ValidatesFaqCategory;

    protected function permission(): string
    {
        return 'faq_categories.create';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->categoryRules(partial: false);
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
        return null;
    }
}
