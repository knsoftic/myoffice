<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Enums\Cms\ContentStatus;
use App\Models\Cms\CtaBlock;
use App\Models\Cms\Faq;
use Illuminate\Validation\Rule;

/**
 * Change the status of a status-gated, live entity (§2.15):
 *
 *   · `admin.website.cta-blocks.toggle` — `can:website_cta_blocks.change_status`
 *   · `admin.website.faqs.toggle`       — `can:faqs.change_status`
 *
 * `status` is explicit (`draft`, `published`, `archived`), so a double submit is idempotent. `scheduled`
 * is refused: only pages are scheduled (§6.4). The permission follows the bound model's class.
 */
final class ChangeContentStatusRequest extends CmsFormRequest
{
    protected function permission(): ?string
    {
        return match (true) {
            $this->route('ctaBlock') instanceof CtaBlock => 'website_cta_blocks.change_status',
            $this->route('faq') instanceof Faq => 'faqs.change_status',
            default => null,
        };
    }

    /**
     * @return array<string, array<int, mixed>>
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
