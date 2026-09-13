<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\BuildsRegistryRules;
use App\Models\Cms\WebsiteSection;
use App\Support\Cms\SectionRegistry;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Save a section's draft (`admin.website.sections.update`, `can:website_sections.edit`, §6.2, §8.5).
 *
 *   content[heading]=...            the type's fields, validated against SectionRegistry's shape
 *   content[primary_button][url]=   a `link` composite: label, url, style, new_tab
 *   media[hero_image]=12            a single-slot role; media[gallery][]=3&media[gallery][]=9 a multiple
 *   name=, anchor=                  the admin label and the public #anchor
 *   publish=1                       "Save & publish" — additionally needs website_sections.change_status
 *
 * **Drafts may be incomplete, never malformed**: `required` is relaxed to `nullable` here, exactly as
 * `SectionValidator` does for a draft, and enforced when the section is published (FT-13). An unknown
 * content key or media role is refused by name rather than silently dropped, and an orphaned type (a
 * `section_key` the registry no longer declares, INV-2) cannot be edited at all.
 */
final class UpdateSectionRequest extends CmsFormRequest
{
    use BuildsRegistryRules;

    protected function permission(): string
    {
        return 'website_sections.edit';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'content' => ['sometimes', 'array'],
            'media' => ['sometimes', 'array'],
            'name' => ['sometimes', 'bail', 'nullable', 'string', 'max:150'],
            'anchor' => ['sometimes', 'bail', 'nullable', 'string', 'max:65', 'regex:/^#?[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/'],
            'publish' => ['sometimes', 'boolean'],
        ];

        $key = $this->sectionKey();

        if ($key === null || ! SectionRegistry::exists($key)) {
            return $rules;
        }

        $rules = array_merge($rules, $this->registryRules(SectionRegistry::fields($key), 'content', relaxRequired: true));

        foreach (SectionRegistry::mediaRoles($key) as $role => $slot) {
            if ($slot['multiple']) {
                $rules['media.'.$role] = ['bail', 'nullable', 'array', 'max:50'];
                $rules['media.'.$role.'.*'] = ['bail', 'integer', 'min:1', 'distinct', Rule::exists('media_assets', 'id')->whereNull('deleted_at')];

                continue;
            }

            $rules['media.'.$role] = ['bail', 'nullable', 'integer', 'min:1', Rule::exists('media_assets', 'id')->whereNull('deleted_at')];
        }

        return $rules;
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $key = $this->sectionKey();

                if ($key === null || ! SectionRegistry::exists($key)) {
                    $validator->errors()->add('section', 'This section type is no longer registered, so it cannot be edited. Remove it or restore the type.');

                    return;
                }

                $contentFields = array_filter(
                    SectionRegistry::fields($key),
                    static fn (array $field): bool => $field['stored'] !== SectionRegistry::STORED_MEDIA
                );

                $unknown = $this->unknownKeys($this->input('content'), $contentFields);

                if ($unknown !== []) {
                    $validator->errors()->add('content', 'Unrecognised fields: '.implode(', ', $unknown).'.');
                }

                $unknownRoles = $this->unknownKeys($this->input('media'), SectionRegistry::mediaRoles($key));

                if ($unknownRoles !== []) {
                    $validator->errors()->add('media', 'Unrecognised image slots: '.implode(', ', $unknownRoles).'.');
                }

                if ($this->boolean('publish') && $this->user()?->can('website_sections.change_status') !== true) {
                    $validator->errors()->add('publish', 'You can save drafts; publishing needs the publish permission.');
                }
            },
        ];
    }

    public function section(): ?WebsiteSection
    {
        return $this->boundModel('section', WebsiteSection::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function contentPayload(): array
    {
        $content = $this->validated('content');

        return is_array($content) ? $content : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function mediaPayload(): array
    {
        $media = $this->validated('media');

        return is_array($media) ? $media : [];
    }

    public function touchesDraft(): bool
    {
        return $this->has('content') || $this->has('media');
    }

    public function touchesName(): bool
    {
        return $this->has('name') || $this->has('anchor');
    }

    public function wantsPublish(): bool
    {
        return $this->boolean('publish');
    }

    private function sectionKey(): ?string
    {
        $section = $this->section();

        return $section === null ? null : (string) $section->getAttribute('section_key');
    }
}
