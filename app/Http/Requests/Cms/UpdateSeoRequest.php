<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\ResolvesSeoTarget;
use App\Models\Cms\Page;
use App\Services\Cms\SeoService;
use Illuminate\Validation\Validator;

/**
 * Save one target's SEO (`admin.website.seo.update`, `can:seo.edit`, §6.5, §8.12).
 *
 *   target=page:7   seo[title]=...  seo[meta_description]=...  seo[robots]=noindex_follow
 *   seo[og_title]=  seo[og_description]=  seo[og_type]=website
 *   seo[sitemap_include]=1  seo[sitemap_priority]=0.8  seo[sitemap_changefreq]=monthly
 *
 * Every `seo.*` rule is `SeoService::editorRules()` — the D23 rule set plus the fields only this screen
 * edits. No `seo_meta` rule is restated here (ND-13), and `SeoService::save()` validates again.
 */
final class UpdateSeoRequest extends CmsFormRequest
{
    use ResolvesSeoTarget;

    private Page|string|null $resolved = null;

    protected function permission(): string
    {
        return 'seo.edit';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge([
            'target' => ['bail', 'required', 'string', 'max:120', 'regex:'.self::TARGET_PATTERN],
            'seo' => ['bail', 'required', 'array'],
            'reason' => ['bail', 'nullable', 'string', 'max:'.self::REASON_MAX],
        ], app(SeoService::class)->editorRules());
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->has('target') && $this->resolvedTarget() === null) {
                    $validator->errors()->add('target', 'That page or route no longer exists.');
                }

                if (! is_array($this->input('seo'))) {
                    return;
                }

                $allowed = array_map(
                    static fn (string $key): string => substr($key, strlen(SeoService::DEFAULT_PREFIX) + 1),
                    array_keys(app(SeoService::class)->editorRules())
                );

                $unknown = array_values(array_diff(array_map('strval', array_keys($this->input('seo'))), $allowed));

                if ($unknown !== []) {
                    $validator->errors()->add('seo', 'Unrecognised SEO fields: '.implode(', ', $unknown).'.');
                }
            },
        ];
    }

    public function target(): Page|string
    {
        $target = $this->resolvedTarget();

        abort_if($target === null, 404);

        return $target;
    }

    /**
     * @return array<string, mixed>
     */
    public function seoPayload(): array
    {
        $seo = $this->validated('seo');

        return is_array($seo) ? $seo : [];
    }

    private function resolvedTarget(): Page|string|null
    {
        return $this->resolved ??= $this->resolveSeoTarget($this->input('target'));
    }
}
