<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\ResolvesSeoTarget;
use App\Models\Cms\Page;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;

/**
 * Open the SEO drawer for one target (`admin.website.seo.edit`, `can:seo.view`, query `target`).
 *
 * A malformed or unknown target is a 404, not a validation redirect: it only ever comes from the SEO
 * table's own links.
 */
final class SeoTargetRequest extends CmsFormRequest
{
    use ResolvesSeoTarget;

    private Page|string|null $resolved = null;

    protected function permission(): string
    {
        return 'seo.view';
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'target' => ['bail', 'required', 'string', 'max:120', 'regex:'.self::TARGET_PATTERN],
        ];
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        abort(404);
    }

    public function target(): Page|string
    {
        $this->resolved ??= $this->resolveSeoTarget($this->validated('target'));

        abort_if($this->resolved === null, 404);

        return $this->resolved;
    }
}
