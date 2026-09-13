<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

/**
 * Enable or disable a placed section (`admin.website.sections.toggle`, `can:website_sections.change_status`).
 *
 * `enabled` is explicit and required, so a double submit is idempotent instead of flipping the section
 * back. Enabling never changes `status` (§2.2); a required section may be disabled (FT-16). A reason is
 * optional — it is recorded when given.
 */
final class ToggleSectionRequest extends CmsFormRequest
{
    protected function permission(): string
    {
        return 'website_sections.change_status';
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'reason' => ['bail', 'nullable', 'string', 'max:'.self::REASON_MAX],
        ];
    }

    public function enabled(): bool
    {
        return $this->boolean('enabled');
    }
}
