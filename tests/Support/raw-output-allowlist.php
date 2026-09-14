<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Raw output allowlist (D25, F-2.5; phase-24-25 SEC-05, §13.3)
|--------------------------------------------------------------------------
|
| Every unescaped Blade echo (`{!! ... !!}`) and every Alpine `x-html` in resources/views must be listed here,
| naming the sanitiser that makes it safe. The only legal sanitiser is App\Support\RichText::sanitize()
| (mews/purifier, applied on write and again on render): a second sanitiser name is itself a failure,
| because a duplicated security control is a security defect. A raw echo with no row fails CI.
|
| Append-only per phase: each phase adds its own rows, under its own banner, and never edits another
| phase's.
|
| Row shape:
|   'view'         the path under resources/views
|   'expression'   a substring of the raw echo that identifies it within that view (line numbers move)
|   'occurrences'  how many raw echoes in that view the row accounts for
|   'sanitiser'    'App\Support\RichText::sanitize()' — the only legal value
|   'owner_phase'  the phase that ships the view
|   'why'          what the echo prints and why it cannot be escaped
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Phase 3 — public website CMS
    |----------------------------------------------------------------------
    | tests/Feature/Cms/Http/CmsManifestTest scans the Phase 3 view roots (site/, components/site/,
    | admin/cms/) and fails on a raw echo with no row here, and on a row whose echo no longer exists.
    */

    [
        'view' => 'components/site/prose.blade.php',
        'expression' => '\App\Support\RichText::sanitize($value, $profile)',
        'occurrences' => 1,
        'sanitiser' => 'App\Support\RichText::sanitize()',
        'owner_phase' => 3,
        'why' => 'x-site.prose is the one place the public site prints CMS rich text (INV-13). The value is sanitised on render by the named profile; the only markup added around it is the fixed overflow-x-auto wrapper for tables.',
    ],
    [
        'view' => 'admin/cms/faqs/index.blade.php',
        'expression' => '\App\Support\RichText::sanitize((string) $faq->answer)',
        'occurrences' => 1,
        'sanitiser' => 'App\Support\RichText::sanitize()',
        'owner_phase' => 3,
        'why' => 'The FAQ manager previews each answer as it will render on the site; the stored answer is rich text, sanitised again on render.',
    ],

];
