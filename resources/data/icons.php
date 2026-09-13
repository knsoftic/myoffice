<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CMS icon allowlist (phase-03 §6.6)
|--------------------------------------------------------------------------
|
| Every `icon` field of a section, a repeater item, a menu item or a FAQ category validates `in:` this
| list (SectionValidator::icons(), CmsFormRequest::iconRule()), and the admin icon picker offers it,
| grouped as below. Only names `<x-ui.icon>` can draw are listed, so a stored icon never renders the
| placeholder square. Pure interface glyphs (chevrons, close, edit, delete, more) are left out.
|
| A custom icon is an `image` field with ImageProfile::Icon, never raw SVG markup. Adding a name here
| requires its path in resources/views/components/ui/icon.blade.php first.
|
*/

return [
    'Navigation & layout' => ['home', 'squares-2x2', 'bars-3', 'view-columns', 'rectangle-stack', 'table-cells', 'queue-list', 'list-bullet'],
    'People' => ['users', 'user', 'user-circle', 'user-group', 'user-plus', 'identification', 'academic-cap'],
    'Security' => ['shield-check', 'key', 'lock-closed', 'lock-open', 'finger-print', 'puzzle-piece'],
    'Tools' => ['cog-6-tooth', 'adjustments-horizontal', 'wrench-screwdriver', 'server-stack', 'arrow-path'],
    'Work & documents' => [
        'briefcase', 'folder', 'folder-open', 'document', 'document-text', 'document-chart-bar', 'clipboard',
        'clipboard-document-list', 'clipboard-document-check', 'clipboard-document', 'newspaper', 'book-open',
        'paper-clip', 'photo', 'printer', 'qr-code', 'presentation-chart-bar',
    ],
    'Money & growth' => ['banknotes', 'credit-card', 'wallet', 'calculator', 'receipt-percent', 'arrow-trending-up', 'arrow-trending-down', 'chart-bar', 'chart-pie'],
    'Communication' => ['envelope', 'phone', 'whatsapp', 'chat-bubble-left-right', 'chat-bubble-left-ellipsis', 'megaphone', 'bell', 'inbox-stack', 'video-camera', 'share', 'lifebuoy'],
    'Status & recognition' => [
        'check', 'check-circle', 'check-badge', 'x-circle', 'exclamation-triangle', 'exclamation-circle',
        'information-circle', 'question-mark-circle', 'sparkles', 'star', 'trophy', 'flag', 'tag', 'ticket',
    ],
    'Actions' => [
        'eye', 'magnifying-glass', 'arrow-up-tray', 'arrow-down-tray', 'arrow-right-on-rectangle',
        'arrow-left-on-rectangle', 'arrow-top-right-on-square', 'arrow-left', 'arrow-right', 'link',
    ],
    'Time' => ['clock', 'calendar', 'calendar-days'],
    'Theme & devices' => ['sun', 'moon', 'computer-desktop'],
    'Places' => ['building-office', 'building-office-2', 'globe-alt'],
];
