<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\ValidatesPage;
use App\Models\Cms\Page;

/**
 * Create a custom page (`admin.website.pages.store`, `can:pages.create`, §6.4 `create()`).
 *
 * The slug is optional: left blank, `PageService::slugFor()` derives it from the title.
 */
final class StorePageRequest extends CmsFormRequest
{
    use ValidatesPage;

    protected function permission(): string
    {
        return 'pages.create';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->pageRules(partial: false);
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn ($validator) => $this->pageAfter($validator)];
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
        return null;
    }
}
