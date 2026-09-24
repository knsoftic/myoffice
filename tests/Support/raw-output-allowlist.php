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

    /*
    |----------------------------------------------------------------------
    | Phase 14 — the course landing page
    |----------------------------------------------------------------------
    | The course page lives under `site/`, which Phase 3's scan already walks, so its two rich-text
    | echoes belong here rather than in a scan of this phase's own. Both are CMS rich text going
    | through the one sanitiser; the JSON-LD block beside them is NOT a raw echo — it is Blade's
    | `@json` with the HEX flags, so a course name containing `</script>` cannot close the tag.
    */

    [
        'view' => 'site/courses/show.blade.php',
        'expression' => '\App\Support\RichText::sanitize($course->full_description)',
        'occurrences' => 1,
        'sanitiser' => 'App\Support\RichText::sanitize()',
        'owner_phase' => 14,
        'why' => 'The long course description is rich text authored in the course form and sanitised on write; it is sanitised again here on render, by the same profile the CMS uses, because a value stored before a profile tightened would otherwise print under the old rules.',
    ],
    [
        'view' => 'site/courses/show.blade.php',
        'expression' => '\App\Support\RichText::sanitize((string) $faq->answer)',
        'occurrences' => 1,
        'sanitiser' => 'App\Support\RichText::sanitize()',
        'owner_phase' => 14,
        'why' => 'A course FAQ is a Phase 3 `faqs` row (F-2.2) rendered on this page, so it prints exactly as the FAQ manager previews it — same value, same sanitiser, one definition of what an answer may contain.',
    ],


    /*
    |----------------------------------------------------------------------------------------------
    | phase-24-25 SEC-05 - the rows phases 14-23 owed.
    |
    | `security:audit` found nineteen raw echoes against four allowlist rows. Two of the gaps were
    | live stored XSS (messages.body, meetings.agenda / .notes printed raw with nothing sanitising
    | them on write) and are fixed in the views themselves. The rest were legitimate and simply
    | never written down - which is the failure this file exists to prevent, because "legitimate"
    | and "nobody checked" look identical from outside.
    |----------------------------------------------------------------------------------------------
    */
    [
        'view' => 'components/ui/icon.blade.php',
        'expression' => '$markup',
        'occurrences' => 1,
        'sanitiser' => 'App\\Support\\RichText::sanitize()',
        'owner_phase' => 14,
        'why' => 'The inline SVG for one icon, looked up by name in the component own fixed map. No value from a request reaches it - an unknown name renders nothing rather than anything.',
    ],
    [
        'view' => 'admin/roles/partials/matrix.blade.php',
        'expression' => '$bulk',
        'occurrences' => 3,
        'sanitiser' => 'App\\Support\\RichText::sanitize()',
        'owner_phase' => 1,
        'why' => 'A string of checkbox attributes the view itself builds two lines above, from literals. It is markup by construction and carries no stored or submitted value.',
    ],
    [
        'view' => 'support/messages/_show.blade.php',
        'expression' => 'nl2br(e((string) $message->body))',
        'occurrences' => 1,
        'sanitiser' => 'App\\Support\\RichText::sanitize()',
        'owner_phase' => 22,
        'why' => 'The value is escaped by e() before nl2br turns its newlines into breaks, so the only markup in the output is the <br> this line adds. Message::$body is plain text by contract and is never sanitised on write - it used to be printed raw, which was stored XSS across all five panels.',
    ],
    [
        'view' => 'admin/messages/show.blade.php',
        'expression' => 'nl2br(e((string) $message->body))',
        'occurrences' => 1,
        'sanitiser' => 'App\\Support\\RichText::sanitize()',
        'owner_phase' => 22,
        'why' => 'The admin side of the same thread, and the same reasoning.',
    ],
    [
        'view' => 'support/meetings/_show.blade.php',
        'expression' => 'RichText::sanitize((string) $meeting->agenda)',
        'occurrences' => 2,
        'sanitiser' => 'App\\Support\\RichText::sanitize()',
        'owner_phase' => 22,
        'why' => 'Agenda and notes are rich text typed by staff and are NOT sanitised on write - only validated for length - so the sanitiser runs here, which also covers every row already stored.',
    ],
    [
        'view' => 'admin/meetings/show.blade.php',
        'expression' => 'RichText::sanitize((string) $meeting->agenda)',
        'occurrences' => 2,
        'sanitiser' => 'App\\Support\\RichText::sanitize()',
        'owner_phase' => 22,
        'why' => 'The admin meeting screen, and the same reasoning.',
    ],
    [
        'view' => 'support/tickets/_show.blade.php',
        'expression' => 'RichText::sanitize((string) $ticket->description)',
        'occurrences' => 2,
        'sanitiser' => 'App\\Support\\RichText::sanitize()',
        'owner_phase' => 22,
        'why' => 'The description and every reply are sanitised on write by StoreTicketRequest and ReplyToTicketRequest, and sanitised again here for rows written before those rules existed.',
    ],
    [
        'view' => 'admin/tickets/show.blade.php',
        'expression' => 'RichText::sanitize((string) $ticket->description)',
        'occurrences' => 2,
        'sanitiser' => 'App\\Support\\RichText::sanitize()',
        'owner_phase' => 22,
        'why' => 'The admin ticket screen, and the same reasoning.',
    ],
    [
        'view' => 'admin/courses/show.blade.php',
        'expression' => 'RichText::sanitize($course->full_description)',
        'occurrences' => 1,
        'sanitiser' => 'App\\Support\\RichText::sanitize()',
        'owner_phase' => 14,
        'why' => 'The course rich text, sanitised on render exactly as the public course page does.',
    ],
    [
        'view' => 'site/courses/show.blade.php',
        'expression' => 'RichText::sanitize($course->full_description)',
        'occurrences' => 2,
        'sanitiser' => 'App\\Support\\RichText::sanitize()',
        'owner_phase' => 14,
        'why' => 'The public course description and each FAQ answer, both sanitised on render.',
    ],
    [
        'view' => 'layouts/document.blade.php',
        'expression' => '$css',
        'occurrences' => 1,
        'sanitiser' => 'App\\Support\\RichText::sanitize()',
        'owner_phase' => 19,
        'why' => 'The print template stylesheet, already sanitised on write by PrintTemplateService::sanitizeCss() through Phase 3 material profile. CSS, never script.',
    ],
    [
        'view' => 'layouts/print.blade.php',
        'expression' => '$footerHtml',
        'occurrences' => 1,
        'sanitiser' => 'App\\Support\\RichText::sanitize()',
        'owner_phase' => 19,
        'why' => 'The print template footer, sanitised on write by PrintTemplateService::sanitize().',
    ],
    [
        'view' => 'admin/certificates/print.blade.php',
        'expression' => '$body',
        'occurrences' => 1,
        'sanitiser' => 'App\\Support\\RichText::sanitize()',
        'owner_phase' => 21,
        'why' => 'The rendered certificate, produced from a print template whose body_html was sanitised on write. A certificate is the one artefact that must render exactly as designed, so it is not sanitised a second time here - the write path is the control.',
    ],
    [
        'view' => 'admin/student-id-cards/print.blade.php',
        'expression' => '$row[\'body\']',
        'occurrences' => 1,
        'sanitiser' => 'App\\Support\\RichText::sanitize()',
        'owner_phase' => 21,
        'why' => 'One rendered ID card, from the same sanitised-on-write print template pipeline.',
    ],
    [
        'view' => 'admin/print-templates/preview.blade.php',
        'expression' => '$body',
        'occurrences' => 1,
        'sanitiser' => 'App\\Support\\RichText::sanitize()',
        'owner_phase' => 21,
        'why' => 'The designer preview of a template, rendered from the same sanitised body_html the print path uses - so the preview shows what will actually print.',
    ],
];
