<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\ValidatesOrder;
use Illuminate\Validation\Rule;

/**
 * Reorder the questions of one FAQ category, or of the uncategorised bucket
 * (`admin.website.faqs.reorder`, `can:faqs.edit`, §6.13 `FaqService::reorder()`).
 *
 *   { "faq_category_id": 3, "order": [8, 2, 5] }
 *   { "faq_category_id": null, "order": [...] }     // the uncategorised bucket
 */
final class ReorderFaqsRequest extends CmsFormRequest
{
    use ValidatesOrder;

    protected function permission(): string
    {
        return 'faqs.edit';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge([
            'faq_category_id' => ['bail', 'present', 'nullable', 'integer', 'min:1', Rule::exists('faq_categories', 'id')->whereNull('deleted_at')],
        ], $this->orderRules());
    }

    public function categoryId(): ?int
    {
        $id = $this->validated('faq_category_id');

        return is_numeric($id) ? (int) $id : null;
    }
}
