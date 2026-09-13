<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Enums\Cms\SectionPlacement;
use App\Support\Cms\SectionRegistry;
use Illuminate\Validation\Rule;

/**
 * Place a section (`admin.website.sections.store`, `can:website_sections.create`, phase-03 §7.1).
 *
 * The type must be one the registry allows in the bound placement (FT-01, FT-05). Whether a unique
 * type is already placed is **not** checked here: `uq_ws_instance` decides at INSERT time, never a
 * SELECT-then-INSERT (§6.2), and the controller turns that refusal into a 422 naming the existing
 * section (FT-02).
 */
final class StoreSectionRequest extends CmsFormRequest
{
    protected function permission(): string
    {
        return 'website_sections.create';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $placement = $this->placement();
        $allowed = $placement === null ? [] : array_keys(SectionRegistry::forPlacement($placement));

        return [
            'section_key' => ['bail', 'required', 'string', 'max:64', Rule::in($allowed)],
            'name' => ['bail', 'nullable', 'string', 'max:150'],
            'page_id' => $placement?->allowsPage()
                ? ['bail', 'required', 'integer', 'min:1', Rule::exists('pages', 'id')->whereNull('deleted_at')->where('layout', 'sections')]
                : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'section_key.in' => 'That section type cannot be placed here.',
            'page_id.exists' => 'Choose a page whose layout is composed of sections.',
            'page_id.prohibited' => 'Only page sections belong to a page.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['section_key', 'name']);
    }

    public function placement(): ?SectionPlacement
    {
        $placement = $this->route('placement');

        return $placement instanceof SectionPlacement ? $placement : SectionPlacement::tryFrom((string) $placement);
    }

    public function sectionKey(): string
    {
        return (string) $this->validated('section_key');
    }

    public function sectionName(): ?string
    {
        $name = $this->validated('name');

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    public function pageId(): ?int
    {
        $id = $this->validated('page_id');

        return is_numeric($id) ? (int) $id : null;
    }
}
