<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

use App\Models\Cms\FaqCategory;
use Illuminate\Validation\Rule;

/**
 * One FAQ group (`faq_categories`, phase-03 §2.9).
 *
 * The slug is optional (derived from the name by the service when blank), lowercase and unique against
 * trashed rows too — `uq_faqcat_slug` is a plain unique index, and the `faq` section references a
 * category by slug (handover decision 2). `is_enabled` is the rail's enable toggle (integration G-2:
 * there is no separate toggle route). `sort_order` changes only through the reorder route.
 *
 * The using class must extend `CmsFormRequest` and implement `category()`.
 */
trait ValidatesFaqCategory
{
    abstract public function category(): ?FaqCategory;

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function categoryRules(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];
        $unique = Rule::unique('faq_categories', 'slug');

        if ($this->category() !== null) {
            $unique = $unique->ignore($this->category()->getKey());
        }

        return [
            'name' => array_merge($required, ['bail', 'string', 'max:150']),
            'slug' => ['sometimes', 'bail', 'nullable', 'string', 'max:150', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $unique],
            'description' => ['sometimes', 'bail', 'nullable', 'string', 'max:300'],
            'icon' => ['sometimes', 'bail', 'nullable', 'string', 'max:64', $this->iconRule()],
            'is_enabled' => ['sometimes', 'boolean'],

            'sort_order' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function categoryMessages(): array
    {
        return [
            'slug.unique' => 'Another category (possibly one in the trash) already uses this slug.',
            'slug.regex' => 'Use lowercase letters, digits and hyphens.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function categoryPayload(): array
    {
        $data = $this->validated();

        if (array_key_exists('is_enabled', $data)) {
            $data['is_enabled'] = $this->boolean('is_enabled');
        }

        return $data;
    }
}
