<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\BuildsRegistryRules;
use App\Http\Requests\Cms\Concerns\ValidatesSectionItem;
use App\Models\Cms\WebsiteSection;

/**
 * Add a repeater item (`admin.website.sections.items.store`, `can:website_sections.edit`, FT-18).
 *
 * The group comes from the form and must be one the section's registry type declares.
 */
final class StoreSectionItemRequest extends CmsFormRequest
{
    use BuildsRegistryRules;
    use ValidatesSectionItem;

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
            'group' => ['bail', 'required', 'string', 'max:32', 'regex:/^[a-z_]+$/'],
        ], $this->itemRules());
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn ($validator) => $this->itemAfter($validator)];
    }

    public function group(): ?string
    {
        $group = $this->input('group');

        return is_string($group) && preg_match('/^[a-z_]{1,32}$/', $group) === 1 ? $group : null;
    }

    protected function sectionKey(): ?string
    {
        $section = $this->boundModel('section', WebsiteSection::class);

        return $section === null ? null : (string) $section->getAttribute('section_key');
    }
}
