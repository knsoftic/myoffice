<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

use App\Support\Cms\SectionRegistry;
use Illuminate\Validation\Validator;

/**
 * One repeater item (`website_section_items`, phase-03 §2.3, §6.2 `upsertItem()`).
 *
 *   group=statistic
 *   item[label]=Students trained  item[value_mode]=auto  item[metric]=students_trained
 *   item[manual_value]=500        item[suffix]=+          item[is_enabled]=1
 *
 * Fields are nested under `item` so a field key can never collide with `group`. Items are saved one at a
 * time from a small form, so `required` is enforced (unlike a section draft). The two cross-field rules
 * — a live statistic names its metric, and a repeater's `max` — are the service's, inside its lock.
 *
 * The using class must extend `CmsFormRequest` and use `BuildsRegistryRules`, and implement
 * `sectionKey()` and `group()`.
 */
trait ValidatesSectionItem
{
    abstract protected function sectionKey(): ?string;

    abstract public function group(): ?string;

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function itemRules(): array
    {
        $rules = [
            'item' => ['bail', 'required', 'array'],
            'item.is_enabled' => ['sometimes', 'boolean'],
        ];

        $key = $this->sectionKey();
        $group = $this->group();

        if ($key === null || $group === null || ! SectionRegistry::exists($key) || ! SectionRegistry::hasRepeater($key, $group)) {
            return $rules;
        }

        return array_merge($rules, $this->registryRules(SectionRegistry::itemFields($key, $group), 'item', relaxRequired: false));
    }

    protected function itemAfter(Validator $validator): void
    {
        $key = $this->sectionKey();
        $group = $this->group();

        if ($key === null || ! SectionRegistry::exists($key)) {
            $validator->errors()->add('section', 'This section is in the trash or its type is no longer registered, so its entries cannot be edited.');

            return;
        }

        if ($group === null || ! SectionRegistry::hasRepeater($key, $group)) {
            $validator->errors()->add('group', 'This section keeps no such list.');

            return;
        }

        $unknown = $this->unknownKeys($this->input('item'), SectionRegistry::itemFields($key, $group), ['is_enabled']);

        if ($unknown !== []) {
            $validator->errors()->add('item', 'Unrecognised fields: '.implode(', ', $unknown).'.');
        }
    }

    /**
     * The item payload for `SectionService::upsertItem()`: field keys plus an optional `is_enabled`.
     *
     * @return array<string, mixed>
     */
    public function itemPayload(): array
    {
        $item = $this->validated('item');

        return is_array($item) ? $item : [];
    }
}
