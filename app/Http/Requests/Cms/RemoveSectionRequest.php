<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

/**
 * Remove a placed section (`admin.website.sections.destroy`, `can:website_sections.delete`, §6.2).
 *
 * A soft delete with a mandatory reason. Whether the type may be removed at all (`header`, `hero` and
 * `footer` are disable-only, INV-7) is the policy's and the service's decision, not a validation rule.
 */
final class RemoveSectionRequest extends CmsFormRequest
{
    protected function permission(): string
    {
        return 'website_sections.delete';
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'reason' => $this->requiredReasonRules(),
        ];
    }
}
