<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\BuildsRegistryRules;
use App\Http\Requests\Cms\Concerns\ValidatesSectionItem;
use App\Models\Cms\WebsiteSection;
use App\Models\Cms\WebsiteSectionItem;

/**
 * Update a repeater item (`admin.website.section-items.update`, `can:website_sections.edit`).
 *
 * The group is the stored item's own group — an item cannot be moved to another repeater by posting a
 * different `group`, so none is accepted.
 */
final class UpdateSectionItemRequest extends CmsFormRequest
{
    use BuildsRegistryRules;
    use ValidatesSectionItem;

    private ?string $resolvedKey = null;

    private bool $keyResolved = false;

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
            'group' => ['prohibited'],
        ], $this->itemRules());
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn ($validator) => $this->itemAfter($validator)];
    }

    public function item(): ?WebsiteSectionItem
    {
        return $this->boundModel('item', WebsiteSectionItem::class);
    }

    public function group(): ?string
    {
        $group = $this->item()?->getAttribute('group');

        return is_string($group) && $group !== '' ? $group : null;
    }

    protected function sectionKey(): ?string
    {
        if ($this->keyResolved) {
            return $this->resolvedKey;
        }

        $this->keyResolved = true;
        $sectionId = $this->item()?->getAttribute('website_section_id');

        if ($sectionId === null) {
            return null;
        }

        $key = WebsiteSection::query()->whereKey((int) $sectionId)->value('section_key');

        return $this->resolvedKey = is_string($key) ? $key : null;
    }
}
