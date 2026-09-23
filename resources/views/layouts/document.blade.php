{{--
    layouts/document — the shell for an **admin-designed** printable document (phase-19-23 §6.13).

    This is not a second `layouts/print`, and the difference is the whole reason it exists.
    `layouts/print` is the company letterhead: it owns the header, the logo, the address block and the
    footer, and every invoice, payslip and report comes off the printer looking like a sibling. A
    certificate and a student card are the opposite kind of document — their entire appearance is a
    `print_templates` row that somebody designed, down to the institute name and where the logo sits.
    Printing one inside the letterhead would brand the page twice and force an A4 portrait frame
    around an 85.6 mm card.

    So this shell contributes **nothing to the page** but the paper it is printed on, and the screen
    furniture it drops before printing:

      · the template's own stylesheet, already through `RichText::sanitizeCss()` upstream, so the
        screen and the dompdf render are handed the same CSS;
      · `@page` at the template's size and margin, so the browser's print dialog starts at the paper
        the designer chose rather than at A4;
      · a toolbar and a page shadow, both `.no-print`.

    It also styles two elements a document may place inside its own `.page` — `.watermark` behind the
    sheet and `.marker` along the bottom of it — but it never renders either. What a given document
    needs marking with is the document's business.

    Sections:
      @section('content')     one or more `.page` elements

    Variables:
      $documentTitle  string   the browser/PDF title
      $css            ?string  the template's sanitised stylesheet
      $widthMm        ?float   paper width, already the right way up for the orientation
      $heightMm       ?float   paper height
      $marginMm       ?float   page margin
      $backUrl        ?string  where the screen-only back link goes
      $backLabel      ?string  its wording
--}}

@php
    $documentTitle = $documentTitle ?? 'Document';
    $css = $css ?? null;
    $widthMm = $widthMm ?? 210.0;
    $heightMm = $heightMm ?? 297.0;
    $marginMm = $marginMm ?? 10.0;
    $backUrl = $backUrl ?? null;
    $backLabel = $backLabel ?? 'Back';

    $mm = static fn ($value): string => number_format((float) $value, 2, '.', '').'mm';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- A page naming a student is never indexed, on any route, whatever robots.txt says. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $documentTitle }}</title>

    <style>
        @@page { size: {{ $mm($widthMm) }} {{ $mm($heightMm) }}; margin: {{ $mm($marginMm) }}; }

        :root { color-scheme: light; }
        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #f1f5f9;
            color: #0f172a;
            font-family: "DejaVu Sans", ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            font-size: 11pt;
            line-height: 1.45;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .toolbar {
            position: sticky; top: 0; z-index: 10;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 10px 16px;
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            font-size: 13px;
        }
        .toolbar a { color: #475569; font-weight: 600; text-decoration: none; }
        .toolbar .right { display: flex; align-items: center; gap: 12px; }
        .toolbar .hint { color: #64748b; font-weight: 400; }
        .toolbar button {
            font: inherit; font-weight: 600; cursor: pointer;
            border: 0; border-radius: 8px; padding: 8px 16px;
            background: #0f172a; color: #fff;
        }

        /* One sheet. On screen it is a shadowed card; in print it is the page itself. */
        .page {
            position: relative;
            width: {{ $mm($widthMm) }};
            min-height: {{ $mm($heightMm) }};
            margin: 16px auto;
            padding: {{ $mm($marginMm) }};
            background: #fff;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .15);
            overflow: hidden;
        }
        .page + .page { page-break-before: always; }

        .watermark {
            position: absolute; top: 42%; left: 0; right: 0;
            text-align: center;
            font-size: 64px; font-weight: 800; letter-spacing: .1em;
            color: rgba(15, 23, 42, .08);
            transform: rotate(-24deg);
            pointer-events: none;
            z-index: 0;
        }
        .page > *:not(.watermark) { position: relative; z-index: 1; }

        /* The integrity line. Small, grey, and outside the template's reach — see the note above. */
        .marker {
            position: absolute; left: {{ $mm($marginMm) }}; right: {{ $mm($marginMm) }}; bottom: 2mm;
            font-size: 7pt; color: #94a3b8; text-align: center;
            z-index: 2;
        }

        @@media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .page {
                width: auto; min-height: 0; margin: 0; padding: 0;
                box-shadow: none; overflow: visible;
            }
            .marker { left: 0; right: 0; }
        }
    </style>

    @if (filled($css))
        {{-- The template's own stylesheet, last so it wins. It has been through
             `RichText::sanitizeCss()`, which guarantees it contains no `<` and so cannot close this
             element, and strips `@import`, `expression(` and every external `url()`. --}}
        <style>{!! $css !!}</style>
    @endif
</head>
<body>
    <div class="toolbar no-print">
        @if (filled($backUrl))
            <a href="{{ $backUrl }}">&larr; {{ $backLabel }}</a>
        @else
            <span></span>
        @endif

        <div class="right">
            <span class="hint">
                {{ app_number($widthMm, 0) }} × {{ app_number($heightMm, 0) }} mm.
                Set your printer to this size and turn margins off.
            </span>
            <button type="button" onclick="window.print()">Print</button>
        </div>
    </div>

    @yield('content')
</body>
</html>
