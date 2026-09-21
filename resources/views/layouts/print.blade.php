{{--
    layouts/print — the one print layout (phase-13 §6.9, F-4.14).

    Every printable document and report in this application and in every later phase extends this file,
    so an invoice, a payslip, a certificate and a finance report all come off the printer looking like
    they came from the same company. A second print layout is a second letterhead nobody agreed to.

    It is deliberately **self-contained**: the stylesheet is inline and nothing is loaded from Vite, a
    CDN or the public disk. dompdf has no bundler and no network, so a layout that depended on either
    would render on screen and come out unstyled as a PDF — the one failure that only shows up in the
    copy the client receives.

    Sections a document fills in:
      @section('title')        the browser/PDF title
      @section('document')     the heading block on the right of the letterhead (number, dates)
      @section('content')      the document itself
      @section('footer')       the footer block; falls back to the company's own footer line

    Variables a document may pass:
      $asPdf       bool    true when rendered by dompdf — hides the toolbar and the screen chrome
      $watermark   ?string a large diagonal word behind the page (DRAFT, CANCELLED, PAID)
      $backUrl     ?string where the on-screen "back" link goes
      $backLabel   ?string its wording
      $richFooter  ?string raw HTML for the footer, sanitised here rather than at every call site
--}}

@php
    $asPdf = $asPdf ?? false;
    $watermark = $watermark ?? null;
    $backUrl = $backUrl ?? null;
    $backLabel = $backLabel ?? 'Back';

    // The canonical rows, not the superseded `company.*` copies of them: the address, the phone, the
    // email and the logo moved to `contact` and `branding` in phase-02 §2, and a page that still read
    // the old row would render one thing while the settings screen edited another.
    $company = [
        'name' => (string) setting('company.name', config('app.name')),
        'legal' => (string) setting('company.legal_name', ''),
        'address' => trim(implode(', ', array_filter([
            (string) setting('contact.address', ''),
            (string) setting('contact.city', ''),
            (string) setting('contact.country', ''),
        ]))),
        'phone' => (string) setting('contact.phone', ''),
        'email' => (string) setting('contact.email', ''),
        'website' => (string) setting('company.website', ''),
        'ntn' => (string) setting('company.ntn_number', ''),
        'registration' => (string) setting('company.registration_number', ''),
    ];

    // The logo is read from disk and inlined, because dompdf cannot fetch a URL and a broken image in
    // the letterhead is worse than no image at all.
    $logoPath = (string) setting('branding.logo_light', '');
    $logoData = null;

    if ($logoPath !== '') {
        $absolute = public_path(ltrim(str_replace('storage/', 'storage/', $logoPath), '/'));

        if (is_file($absolute) && filesize($absolute) < 512_000) {
            $extension = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));

            if (in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
                $logoData = 'data:image/'.($extension === 'jpg' ? 'jpeg' : $extension)
                    .';base64,'.base64_encode((string) file_get_contents($absolute));
            }
        }
    }

    // Any rich text reaching a printed page goes through the sanitiser, exactly once, here — so a
    // document that pastes a stored note into the footer cannot be the place somebody forgot.
    $footerHtml = isset($richFooter) && filled($richFooter)
        ? \App\Support\RichText::sanitize($richFooter)
        : null;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', $company['name'])</title>

    <style>
        @@page { size: A4; margin: 14mm 12mm 16mm; }

        :root { color-scheme: light; }
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 0;
            background: #f1f5f9;
            color: #0f172a;
            font-family: "DejaVu Sans", ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            font-size: 12px;
            line-height: 1.45;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .sheet {
            position: relative;
            width: 190mm;
            margin: 16px auto;
            padding: 18mm 14mm;
            background: #fff;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .15);
        }

        /* The toolbar is screen-only furniture. It is the first thing print and dompdf drop. */
        .toolbar {
            position: sticky; top: 0; z-index: 10;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 10px 16px;
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
        }
        .toolbar a, .toolbar button {
            font: inherit; font-weight: 600; text-decoration: none; cursor: pointer;
        }
        .toolbar a { color: #475569; }
        .toolbar button {
            border: 0; border-radius: 8px; padding: 8px 16px;
            background: #0f172a; color: #fff;
        }

        /* Letterhead */
        .letterhead {
            display: table; width: 100%;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 12px;
        }
        .letterhead > div { display: table-cell; vertical-align: top; }
        .letterhead .brand { width: 55%; }
        .letterhead .document { width: 45%; text-align: right; }
        .letterhead img { max-height: 48px; max-width: 200px; margin-bottom: 6px; }
        .letterhead h1 { margin: 0 0 2px; font-size: 17px; letter-spacing: -.01em; }
        .letterhead h2 { margin: 0 0 4px; font-size: 19px; text-transform: uppercase; letter-spacing: .06em; }

        .muted { color: #64748b; }
        .tiny { font-size: 10px; }
        .right { text-align: right; }
        .center { text-align: center; }
        .num { font-variant-numeric: tabular-nums; text-align: right; white-space: nowrap; }
        .strong { font-weight: 600; }
        .mono { font-family: "DejaVu Sans Mono", ui-monospace, "SFMono-Regular", Menlo, monospace; font-size: 11px; }

        h3.section {
            margin: 18px 0 6px;
            font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
            color: #64748b;
        }

        /* Parties block */
        .parties { display: table; width: 100%; margin-top: 14px; }
        .parties > div { display: table-cell; width: 50%; vertical-align: top; padding-right: 16px; }

        table.doc { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.doc thead { display: table-header-group; }
        table.doc th, table.doc td {
            padding: 6px 8px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top;
        }
        table.doc thead th {
            font-size: 10px; text-transform: uppercase; letter-spacing: .05em; color: #64748b;
            border-bottom: 1px solid #cbd5e1; background: #f8fafc;
        }
        table.doc th.num, table.doc td.num { text-align: right; font-variant-numeric: tabular-nums; }
        table.doc tr.subtotal td { background: #f8fafc; font-weight: 600; }
        table.doc tr.total td { border-top: 2px solid #0f172a; border-bottom: 0; font-weight: 700; font-size: 13px; }
        table.doc tbody tr { page-break-inside: avoid; }

        /* Totals block: a narrow table pinned to the right of the page */
        .totals { width: 62mm; margin-left: auto; margin-top: 10px; border-collapse: collapse; }
        .totals td { padding: 4px 0; font-variant-numeric: tabular-nums; }
        .totals td:last-child { text-align: right; }
        .totals tr.grand td { border-top: 2px solid #0f172a; padding-top: 6px; font-size: 14px; font-weight: 700; }
        .totals tr.balance td { border-top: 1px solid #cbd5e1; padding-top: 6px; font-weight: 700; }

        .panel { margin-top: 14px; padding: 10px 12px; border: 1px solid #e2e8f0; background: #f8fafc; }
        .panel h4 { margin: 0 0 4px; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #64748b; }

        .footer {
            margin-top: 22px; padding-top: 10px;
            border-top: 1px solid #e2e8f0;
            font-size: 10px; color: #64748b;
        }

        /* The watermark sits behind everything and is never a substitute for the status on the page. */
        .watermark {
            position: absolute; top: 45%; left: 0; right: 0;
            text-align: center;
            font-size: 84px; font-weight: 800; letter-spacing: .1em;
            color: rgba(15, 23, 42, .07);
            transform: rotate(-24deg);
            pointer-events: none;
            z-index: 0;
        }
        .sheet > *:not(.watermark) { position: relative; z-index: 1; }

        .avoid-break { page-break-inside: avoid; }
        /* Plain text whose line breaks matter: the breaks are CSS, so the value still goes out
           through an escaping echo and no view has to reason about raw output (FT-37). */
        .pre-line { white-space: pre-line; }

        @@media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .sheet { width: auto; margin: 0; padding: 0; box-shadow: none; }
        }
    </style>

    @stack('print-styles')
</head>
<body>
    @unless ($asPdf)
        <div class="toolbar no-print">
            {{-- A page with nowhere to go back to renders no back link rather than a guess: the public
                 invoice has no panel behind it, and a link into one would be a login prompt on a page
                 whose whole point is that no login is needed. --}}
            @if (filled($backUrl))
                <a href="{{ $backUrl }}">&larr; {{ $backLabel }}</a>
            @else
                <span></span>
            @endif

            <button type="button" onclick="window.print()">Print</button>
        </div>
    @endunless

    <main class="sheet">
        @if (filled($watermark))
            <div class="watermark">{{ $watermark }}</div>
        @endif

        <header class="letterhead">
            <div class="brand">
                @if ($logoData)
                    <img src="{{ $logoData }}" alt="{{ $company['name'] }}">
                @endif
                <h1>{{ $company['legal'] ?: $company['name'] }}</h1>
                @if ($company['address'])
                    <div class="muted">{{ $company['address'] }}</div>
                @endif
                <div class="muted tiny">
                    {{ collect([$company['phone'], $company['email'], $company['website']])->filter()->implode(' · ') }}
                </div>
                @if ($company['ntn'] || $company['registration'])
                    <div class="muted tiny">
                        {{ collect([
                            $company['ntn'] ? 'NTN '.$company['ntn'] : null,
                            $company['registration'] ? 'Reg. '.$company['registration'] : null,
                        ])->filter()->implode(' · ') }}
                    </div>
                @endif
            </div>

            <div class="document">
                @yield('document')
            </div>
        </header>

        @yield('content')

        <footer class="footer">
            @hasSection('footer')
                @yield('footer')
            @elseif ($footerHtml)
                {!! $footerHtml !!}
            @else
                {{ setting('company.copyright_text') ?: $company['name'] }}
            @endif
        </footer>
    </main>
</body>
</html>
