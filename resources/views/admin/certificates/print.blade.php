@extends('layouts.document')

{{--
    The certificate, on screen, ready for the browser's print dialog (§84, phase-19-23 §7.6).

    Everything on the sheet came out of `CertificateService::renderHtml()` — the same template, the
    same snapshots and the same token map the PDF is built from, so the two cannot say different
    things. This file contributes the paper, the stylesheet and two marks the template must not be
    able to style away: the REVOKED watermark and the reprint line.
--}}

@php
    [$widthMm, $heightMm] = $template->dimensionsMm();

    $documentTitle = trim(sprintf(
        '%s — %s',
        (string) ($certificate->certificate_number ?? 'Certificate'),
        (string) $certificate->student_name_snapshot,
    ));

    $css = $template->custom_css;
    $marginMm = (float) $template->margin_mm;

    // A revoked certificate stays printable — somebody has to be able to produce the document a
    // dispute is about — but it never leaves this building looking valid.
    $watermark = $certificate->status === \App\Enums\CertificateStatus::Revoked ? 'REVOKED' : null;

    $prints = (int) $certificate->print_count;

    // The reprint line is what stops two copies circulating as though both were the original. The
    // first print says nothing: an original does not need to announce itself.
    $marker = $prints > 1
        ? sprintf('Reprint #%s · %s · verify at %s', app_number($prints), app_date(now()), $certificate->qr_payload)
        : null;

    $backUrl = route('admin.certificates.show', $certificate);
    $backLabel = 'Back to the certificate';
@endphp

@section('content')
    <div class="page">
        @if ($watermark)
            <div class="watermark">{{ $watermark }}</div>
        @endif

        {!! $body !!}

        @if ($marker)
            <div class="marker">{{ $marker }}</div>
        @endif
    </div>
@endsection
