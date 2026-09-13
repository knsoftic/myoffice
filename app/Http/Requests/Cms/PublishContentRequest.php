<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\TargetsPublishable;

/**
 * Publish a section or a page (`admin.website.sections.publish` / `admin.website.pages.publish`).
 *
 * Only an optional revision label ("Before Ramadan campaign", `cms_revisions.label`) is accepted.
 * Nothing about the content itself is posted: a publish snapshots the stored draft, so what goes live
 * is exactly what was previewed (INV-1). Completeness is the service's refusal (FT-13).
 */
final class PublishContentRequest extends CmsFormRequest
{
    use TargetsPublishable;

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'label' => ['bail', 'nullable', 'string', 'max:150'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['label']);
    }

    public function label(): ?string
    {
        $label = $this->validated('label');

        return is_string($label) && $label !== '' ? $label : null;
    }
}
