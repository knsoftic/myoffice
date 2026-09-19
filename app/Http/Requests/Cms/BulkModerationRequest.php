<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\NormalisesContentInput;
use App\Http\Requests\Cms\Concerns\ResolvesContentModule;

/**
 * Bulk-approve a selection of a moderation queue (phase-04 §6.5 `bulkApprove()`, §6.11
 * `BulkModerationRequest`, acceptance test 19):
 *
 *   `admin.testimonials.bulk-approve`     `can:testimonials.approve`
 *   `admin.student-reviews.bulk-approve`  `can:student_reviews.approve`
 *
 * `ids` — an array of at most 200 distinct integers. The service skips ids already approved
 * (idempotent) and ignores ids that do not exist; nothing here decides visibility.
 */
final class BulkModerationRequest extends CmsFormRequest
{
    use NormalisesContentInput;
    use ResolvesContentModule;

    public const MAX_IDS = 200;

    private const MODULES = ['testimonials', 'student_reviews'];

    protected function permission(): ?string
    {
        return in_array($this->contentModule(), self::MODULES, true) ? $this->contentPermission('approve') : null;
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
     * @return list<int>
     */
    public function ids(): array
    {
        return $this->validatedIds('ids');
    }
}
