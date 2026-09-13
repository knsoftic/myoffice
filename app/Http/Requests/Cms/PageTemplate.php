<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use Illuminate\Support\Facades\View;

/**
 * The allowlisted Blade templates a custom page may render inside (phase-03 §2.7 `pages.template`).
 *
 * "An allowlisted Blade view; anything else is rejected." The list lives in one place so the Form
 * Requests that validate it and the public controller that renders it cannot disagree — a stored value
 * that is not on the list (a raw SQL edit, an older row) renders the default, never an arbitrary view.
 */
final class PageTemplate
{
    public const DEFAULT = 'site.pages.default';

    /** @var list<string> */
    public const ALLOWED = [
        'site.pages.default',
        'site.pages.wide',
        'site.pages.legal',
    ];

    /**
     * Template => admin label, for the editor's select.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'site.pages.default' => 'Standard',
            'site.pages.wide' => 'Wide',
            'site.pages.legal' => 'Legal / policy',
        ];
    }

    /**
     * The view a stored template value renders: the value when it is allowlisted and exists, else the
     * default.
     */
    public static function resolve(?string $template): string
    {
        if ($template !== null && in_array($template, self::ALLOWED, true) && View::exists($template)) {
            return $template;
        }

        return self::DEFAULT;
    }
}
