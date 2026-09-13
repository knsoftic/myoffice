<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Enums\Cms\RobotsDirective;
use App\Http\Requests\Cms\Concerns\ResolvesSeoTarget;
use App\Models\Cms\Page;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Bulk SEO change over selected rows (`admin.website.seo.bulk-robots`, `can:seo.edit`, §8.12 "Bulk"):
 * set robots and/or include or exclude from the sitemap.
 *
 *   targets[]=page:3&targets[]=route:site.home&robots=noindex_follow&sitemap_include=0
 *
 * At least one change must be chosen; every target must resolve. The values themselves are validated
 * again, per row, by `SeoService::save()`.
 */
final class BulkSeoRequest extends CmsFormRequest
{
    use ResolvesSeoTarget;

    private const MAX_TARGETS = 500;

    /** @var list<Page|string>|null */
    private ?array $resolved = null;

    protected function permission(): string
    {
        return 'seo.edit';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'targets' => ['bail', 'required', 'array', 'min:1', 'max:'.self::MAX_TARGETS],
            'targets.*' => ['bail', 'required', 'string', 'max:120', 'distinct', 'regex:'.self::TARGET_PATTERN],
            'robots' => ['bail', 'nullable', 'string', Rule::enum(RobotsDirective::class)],
            'sitemap_include' => ['nullable', 'boolean'],
            'reason' => ['bail', 'nullable', 'string', 'max:'.self::REASON_MAX],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->changes() === []) {
                    $validator->errors()->add('robots', 'Choose a robots setting or a sitemap choice to apply.');
                }

                if (count($this->targets()) !== count((array) $this->input('targets'))) {
                    $validator->errors()->add('targets', 'One or more selected rows no longer exist. Reload the page and try again.');
                }
            },
        ];
    }

    /**
     * @return list<Page|string>
     */
    public function targets(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $targets = [];

        foreach ((array) $this->input('targets', []) as $target) {
            $resolved = $this->resolveSeoTarget($target);

            if ($resolved !== null) {
                $targets[] = $resolved;
            }
        }

        return $this->resolved = $targets;
    }

    /**
     * The `seo_meta` columns to write on every target.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $changes = [];

        if (is_string($this->input('robots')) && $this->input('robots') !== '') {
            $changes['robots'] = $this->input('robots');
        }

        if ($this->filled('sitemap_include')) {
            $changes['sitemap_include'] = $this->boolean('sitemap_include');
        }

        return $changes;
    }
}
