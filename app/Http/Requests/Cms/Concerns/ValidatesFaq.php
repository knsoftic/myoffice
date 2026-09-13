<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

use Illuminate\Validation\Rule;

/**
 * One question and answer (`faqs`, phase-03 §2.10, §6.13 `FaqService::save()`).
 *
 * The answer is rich text: the service sanitises it with `RichText::sanitize()` on write and the site
 * sanitises it again on render (INV-13) — this request only bounds its size. The category must be a live
 * row (the service additionally requires it to be enabled). `faqable_type` / `faqable_id` are written
 * only by the owning phase's service, never by this form (§6.13), and `status` changes through the
 * toggle route.
 *
 * The using class must extend `CmsFormRequest`.
 */
trait ValidatesFaq
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function faqRules(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return [
            'question' => array_merge($required, ['bail', 'string', 'max:300']),
            'answer' => array_merge($required, ['bail', 'string', 'max:100000']),
            'faq_category_id' => ['sometimes', 'bail', 'nullable', 'integer', 'min:1', Rule::exists('faq_categories', 'id')->whereNull('deleted_at')],
            'is_featured' => ['sometimes', 'boolean'],

            'status' => ['prohibited'],
            'sort_order' => ['prohibited'],
            'faqable_type' => ['prohibited'],
            'faqable_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function faqPayload(): array
    {
        $data = $this->validated();

        if (array_key_exists('is_featured', $data)) {
            $data['is_featured'] = $this->boolean('is_featured');
        }

        return $data;
    }
}
