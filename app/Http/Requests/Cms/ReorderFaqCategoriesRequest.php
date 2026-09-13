<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\ValidatesOrder;

/**
 * Reorder the FAQ category rail (`admin.website.faq-categories.reorder`, `can:faq_categories.edit`,
 * §6.13 `FaqService::reorderCategories()`).
 */
final class ReorderFaqCategoriesRequest extends CmsFormRequest
{
    use ValidatesOrder;

    protected function permission(): string
    {
        return 'faq_categories.edit';
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return $this->orderRules();
    }
}
