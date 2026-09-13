<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\ValidatesOrder;

/**
 * Reorder one repeater (`admin.website.sections.items.reorder`, `can:website_sections.edit`, §6.2
 * `reorderItems()`). The `{group}` comes from the URI and is checked against the registry by the
 * controller (an unknown group is a 404, not a validation error).
 *
 *   { "order": [12, 9, 15] }
 */
final class ReorderSectionItemsRequest extends CmsFormRequest
{
    use ValidatesOrder;

    protected function permission(): string
    {
        return 'website_sections.edit';
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return $this->orderRules();
    }
}
