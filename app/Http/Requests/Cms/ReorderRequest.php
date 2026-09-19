<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\NormalisesContentInput;
use App\Http\Requests\Cms\Concerns\ResolvesContentModule;

/**
 * Every phase-04 drag-to-reorder endpoint (phase-04 §6.11 `ReorderRequest`, §6.2, §6.3):
 *
 *   `admin.service-categories.reorder`  `admin.services.reorder`        `admin.portfolio-categories.reorder`
 *   `admin.portfolio.reorder`           `admin.portfolio.images.reorder` `admin.team.reorder`
 *   `admin.success-stories.reorder`     `admin.jobs.reorder`
 *
 * each behind `can:{module}.edit`. `ids` is the full ordered id list: required, integers, distinct.
 * Whether every id belongs to the model (or, for a gallery, is attached to the item) is decided by
 * `ContentOrderService::reorder()` / `PortfolioService::reorderImages()` inside their transaction — an
 * id that does not is a 422, never silently ignored.
 */
final class ReorderRequest extends CmsFormRequest
{
    use NormalisesContentInput;
    use ResolvesContentModule;

    /** No list reordered on one screen legitimately holds more rows than this. */
    public const MAX_IDS = 500;

    protected function permission(): ?string
    {
        return $this->contentPermission('edit');
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'ids' => ['bail', 'required', 'array', 'min:1', 'max:'.self::MAX_IDS],
            'ids.*' => ['bail', 'required', 'integer', 'min:1', 'distinct'],
        ];
    }

    /**
     * The ordered ids as integers.
     *
     * @return list<int>
     */
    public function orderedIds(): array
    {
        return $this->validatedIds('ids');
    }
}
