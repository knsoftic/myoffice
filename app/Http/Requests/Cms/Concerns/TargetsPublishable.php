<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

use App\Models\Cms\Page;
use App\Models\Cms\WebsiteSection;

/**
 * The two snapshot-published entities (D22) share their publish, unpublish and revert requests; the
 * permission follows the **bound model**, never a form field: a `{page}` route needs
 * `pages.change_status`, a `{section}` route `website_sections.change_status` ([D-W3-10]).
 *
 * Anything else bound (or nothing) resolves to a permission no one holds, so the request refuses.
 */
trait TargetsPublishable
{
    protected function permission(): ?string
    {
        return match (true) {
            $this->route('page') instanceof Page => 'pages.change_status',
            $this->route('section') instanceof WebsiteSection => 'website_sections.change_status',
            default => null,
        };
    }

    public function target(): WebsiteSection|Page|null
    {
        $page = $this->route('page');

        if ($page instanceof Page) {
            return $page;
        }

        $section = $this->route('section');

        return $section instanceof WebsiteSection ? $section : null;
    }
}
