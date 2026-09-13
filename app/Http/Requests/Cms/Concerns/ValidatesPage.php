<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

use App\Enums\Cms\PageLayout;
use App\Http\Requests\Cms\PageTemplate;
use App\Models\Cms\Page;
use App\Services\Cms\PageService;
use App\Services\Cms\SeoService;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A custom page (`pages`, phase-03 §2.7, §6.4, §8.10) plus its SEO (§6.5, D23).
 *
 * Slugs: lowercase `[a-z0-9-]`, never a reserved first segment (`PageService::reservedSlugs()`), unique
 * against **every** row including trashed ones — `uq_pages_slug` is a plain unique index (R-5), so a
 * trashed page keeps its slug and the error says so by name instead of silently renaming (FT-14).
 *
 * SEO is posted under `seo.*` and validated by `SeoService::rules()` — this request never restates a
 * `seo_meta` rule (D23, ND-13). Publish columns (`status`, `published_at`, `published_by`, hashes,
 * `published_content`) are not writable: publishing is `pages.change_status` ([D-W3-10]).
 *
 * The using class must extend `CmsFormRequest` and implement `page()`.
 */
trait ValidatesPage
{
    /** A slug: lowercase letters, digits and inner hyphens (the `site.page` route constraint). */
    private const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/';

    abstract public function page(): ?Page;

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function pageRules(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return array_merge([
            'title' => array_merge($required, ['bail', 'string', 'max:200']),
            // The reserved/duplicate check runs before the pattern, so `sitemap.xml` or `robots.txt` is refused
            // by name as a reserved address (FT-14) rather than as a malformed one.
            'slug' => ['sometimes', 'bail', 'nullable', 'string', 'max:200', $this->slugRule(), 'regex:'.self::SLUG_PATTERN],
            'layout' => array_merge($required, ['bail', 'string', Rule::enum(PageLayout::class)]),
            'excerpt' => ['sometimes', 'bail', 'nullable', 'string', 'max:500'],
            'content' => ['sometimes', 'bail', 'nullable', 'string', 'max:1000000'],
            'show_banner' => ['sometimes', 'boolean'],
            'banner_media_id' => ['sometimes', 'bail', 'nullable', 'integer', 'min:1', Rule::exists('media_assets', 'id')->whereNull('deleted_at')],
            'banner_heading' => ['sometimes', 'bail', 'nullable', 'string', 'max:200'],
            'banner_subheading' => ['sometimes', 'bail', 'nullable', 'string', 'max:300'],
            'template' => ['sometimes', 'bail', 'nullable', 'string', Rule::in(PageTemplate::ALLOWED)],
            'sort_order' => ['sometimes', 'bail', 'nullable', 'integer', 'min:0', 'max:65535'],
            'seo' => ['sometimes', 'array'],

            'status' => ['prohibited'],
            'published_at' => ['prohibited'],
            'published_by' => ['prohibited'],
            'published_content' => ['prohibited'],
            'content_hash' => ['prohibited'],
            'published_hash' => ['prohibited'],
            'is_system' => ['prohibited'],
        ], app(SeoService::class)->rules());
    }

    protected function pageAfter(Validator $validator): void
    {
        $page = $this->page();
        $slug = $this->input('slug');

        // §6.4: a slug change on a system page needs `pages.change_status`.
        if ($page !== null && $page->isSystem() && is_string($slug) && $slug !== $page->slug
            && $this->user()?->can('pages.change_status') !== true) {
            $validator->errors()->add('slug', 'The address of a system page can only be changed by someone who can publish pages.');
        }

        $unknown = is_array($this->input('seo'))
            ? array_values(array_diff(array_map('strval', array_keys($this->input('seo'))), array_keys($this->seoRuleKeys())))
            : [];

        if ($unknown !== []) {
            $validator->errors()->add('seo', 'Unrecognised SEO fields: '.implode(', ', $unknown).'.');
        }
    }

    /**
     * The page columns for `PageService` (SEO excluded — it has its own writer).
     *
     * @return array<string, mixed>
     */
    public function pagePayload(): array
    {
        $data = $this->safe()->except(['seo']);

        if (array_key_exists('show_banner', $data)) {
            $data['show_banner'] = $this->boolean('show_banner');
        }

        return $data;
    }

    /**
     * The `seo_meta` columns for `SeoService::save()`, or null when the form sent no SEO block.
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

    /**
     * @return array<string, true>
     */
    private function seoRuleKeys(): array
    {
        $keys = [];

        foreach (array_keys(app(SeoService::class)->rules()) as $key) {
            $keys[substr($key, strlen(SeoService::DEFAULT_PREFIX) + 1)] = true;
        }

        return $keys;
    }

    private function slugRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            if (in_array($value, app(PageService::class)->reservedSlugs(), true)) {
                $fail(sprintf('"/%s" is reserved for another part of the site. Choose another address.', $value));

                return;
            }

            $holder = Page::withTrashed()
                ->where('slug', $value)
                ->when($this->page() !== null, fn ($query) => $query->whereKeyNot($this->page()->getKey()))
                ->first(['id', 'title', 'deleted_at']);

            if ($holder === null) {
                return;
            }

            $fail($holder->trashed()
                ? sprintf('"/%s" still belongs to "%s", which is in the trash. Restore that page or choose another address.', $value, $holder->title)
                : sprintf('"/%s" is already used by "%s".', $value, $holder->title));
        };
    }
}
