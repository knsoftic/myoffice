<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

use App\Services\Cms\SeoService;
use Illuminate\Validation\Validator;

/**
 * The SEO block of a phase-04 editor screen (`<x-cms.seo-fields>`, phase-04 §6.11 ND-13, D23).
 *
 * This request declares **no** SEO rule of its own. The fields post under the `seo.*` prefix and the
 * one rule set is `SeoService::rules()` (phase-03 §6.5), merged verbatim; the one writer is
 * `SeoService::save()`. Restating any of those rules here would start a second SEO write path, which is
 * exactly what acceptance test 65 scans this folder for.
 *
 * Used by the services, service-category, portfolio, portfolio-category, blog-category, blog-post and
 * job-opening requests.
 */
trait DelegatesSeoRules
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function seoRules(): array
    {
        return array_merge(['seo' => ['sometimes', 'array']], app(SeoService::class)->rules());
    }

    /**
     * Refuse a key under `seo.*` that the SEO rule set does not declare, so nothing unvalidated can
     * reach `SeoService::save()`.
     */
    protected function seoAfter(Validator $validator): void
    {
        $seo = $this->input('seo');

        if (! is_array($seo)) {
            return;
        }

        $known = [];
        $prefix = SeoService::DEFAULT_PREFIX.'.';

        foreach (array_keys(app(SeoService::class)->rules()) as $key) {
            if (str_starts_with($key, $prefix)) {
                $known[] = substr($key, strlen($prefix));
            }
        }

        $unknown = array_values(array_diff(array_map('strval', array_keys($seo)), $known));

        if ($unknown !== []) {
            $validator->errors()->add('seo', 'Unrecognised SEO fields: '.implode(', ', $unknown).'.');
        }
    }

    /**
     * The SEO values for `SeoService::save()`, or null when the form sent no SEO block at all.
     *
     * @return array<string, mixed>|null
     */
    public function seoPayload(): ?array
    {
        if (! $this->has('seo')) {
            return null;
        }

        $seo = $this->validated('seo');

        return is_array($seo) ? $seo : [];
    }
}
