<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\ValidatesPage;
use App\Models\Cms\Page;

/**
 * Save a page's draft and its live attributes (`admin.website.pages.update`, `can:pages.edit`,
 * §6.4 `saveDraft()`).
 *
 * `publish=1` is "Save & publish" and additionally needs `pages.change_status`.
 */
final class UpdatePageRequest extends CmsFormRequest
{
    use ValidatesPage {
        pageAfter as private baseAfter;
    }

    protected function permission(): string
    {
        return 'pages.edit';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge($this->pageRules(partial: true), [
            'publish' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                $this->baseAfter($validator);

                if ($this->boolean('publish') && $this->user()?->can('pages.change_status') !== true) {
                    $validator->errors()->add('publish', 'You can save drafts; publishing needs the publish permission.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['title', 'excerpt', 'banner_heading', 'banner_subheading', 'template']);

        if (is_string($this->input('slug'))) {
            $slug = strtolower(trim($this->input('slug'), " \t\n\r\0\x0B/"));
            $this->merge(['slug' => $slug === '' ? null : $slug]);
        }
    }

    public function page(): ?Page
    {
        return $this->boundModel('page', Page::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function pagePayload(): array
    {
        $data = $this->safe()->except(['seo', 'publish']);

        if (array_key_exists('show_banner', $data)) {
            $data['show_banner'] = $this->boolean('show_banner');
        }

        return $data;
    }

    public function wantsPublish(): bool
    {
        return $this->boolean('publish');
    }
}
