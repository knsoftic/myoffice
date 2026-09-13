<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Enums\Cms\SectionPlacement;
use App\Http\Requests\Cms\Concerns\ValidatesOrder;
use Illuminate\Validation\Rule;

/**
 * Reorder a placement (`admin.website.sections.reorder`, `can:website_sections.edit`, §6.2, FT-15).
 *
 *   { "placement": "home", "order": [4, 9, 2] }
 *   { "placement": "page", "page_id": 7, "order": [...] }
 */
final class ReorderSectionsRequest extends CmsFormRequest
{
    use ValidatesOrder;

    protected function permission(): string
    {
        return 'website_sections.edit';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge([
            'placement' => ['bail', 'required', 'string', Rule::enum(SectionPlacement::class)],
            'page_id' => [
                'bail',
                Rule::requiredIf(fn (): bool => $this->input('placement') === SectionPlacement::Page->value),
                Rule::prohibitedIf(fn (): bool => $this->input('placement') !== SectionPlacement::Page->value),
                'nullable',
                'integer',
                'min:1',
                Rule::exists('pages', 'id')->whereNull('deleted_at'),
            ],
        ], $this->orderRules());
    }

    public function placement(): SectionPlacement
    {
        return SectionPlacement::from((string) $this->validated('placement'));
    }

    public function pageId(): ?int
    {
        $id = $this->validated('page_id');

        return is_numeric($id) ? (int) $id : null;
    }
}
