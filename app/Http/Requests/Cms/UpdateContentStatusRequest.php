<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Enums\Cms\ContentStatus;
use App\Http\Requests\Cms\Concerns\ResolvesContentModule;
use Illuminate\Validation\Rule;

/**
 * Change the publication status of a staff-authored content row (phase-04 §6.4 `changeStatus()`,
 * phase-01 Q1 → `change_status`):
 *
 *   `admin.services.status`         `can:services.change_status`
 *   `admin.portfolio.status`        `can:portfolio.change_status`
 *   `admin.team.status`             `can:team.change_status`
 *   `admin.success-stories.status`  `can:success_stories.change_status`
 *
 * `status` is explicit (a double submit is idempotent). `scheduled` is refused: only blog posts are
 * scheduled, through their own endpoint (§6.7). The service applies the publish preconditions (a name
 * and a slug, alt text on a portfolio gallery) and writes the old → new activity entry.
 */
final class UpdateContentStatusRequest extends CmsFormRequest
{
    use ResolvesContentModule;

    private const MODULES = ['services', 'portfolio', 'team', 'success_stories'];

    protected function permission(): ?string
    {
        return in_array($this->contentModule(), self::MODULES, true) ? $this->contentPermission('change_status') : null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['bail', 'required', 'string', Rule::in([
                ContentStatus::Draft->value,
                ContentStatus::Published->value,
                ContentStatus::Archived->value,
            ])],
        ];
    }

    public function contentStatus(): ContentStatus
    {
        return ContentStatus::from((string) $this->validated('status'));
    }
}
