<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Models\Cms\MenuItem;
use App\Models\Cms\WebsiteSectionItem;

/**
 * Show or hide one child row without deleting it:
 *
 *   · `admin.website.section-items.toggle` — a repeater item, `can:website_sections.edit` (§7.1: an
 *     item change only reaches the public page when its section is published, FT-45);
 *   · `admin.website.menu-items.toggle` — a menu link, `can:menus.change_status` (§7.2).
 *
 * The permission follows the bound `{item}` model's class, never a form field.
 */
final class ToggleEnabledRequest extends CmsFormRequest
{
    protected function permission(): ?string
    {
        return match (true) {
            $this->route('item') instanceof WebsiteSectionItem => 'website_sections.edit',
            $this->route('item') instanceof MenuItem => 'menus.change_status',
            default => null,
        };
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }

    public function enabled(): bool
    {
        return $this->boolean('enabled');
    }
}
