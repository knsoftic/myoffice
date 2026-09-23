@extends('layouts.document')

{{--
    The template preview (§82, §84, §85, phase-19-23 §6.13, §7.6).

    **No real student appears here, ever.** Every value on the sheet came from
    `PrintTokenRegistry::examples()`, which is what makes `print_templates` safe to grant on its own to
    somebody who should never see a student record — the reason §4.1 makes it a module of its own. It
    is also why the preview works on a brand-new install with no students in it at all.

    The SPECIMEN watermark is not decoration. A preview renders at the real paper size on the real
    stylesheet, so a printed one is indistinguishable from the document itself — and a sheet reading
    "Ayesha Siddiqui, grade A" with a certificate number on it must never be able to pass for one.
--}}

@php
    $documentTitle = sprintf('Preview — %s', (string) $template->name);

    $css = $template->custom_css;
    $marginMm = (float) $template->margin_mm;

    $backUrl = route('admin.print-templates.edit', $template);
    $backLabel = 'Back to the editor';
@endphp

@section('content')
    <div class="page">
        <div class="watermark">SPECIMEN</div>

        {!! $body !!}

        <div class="marker">
            Preview of {{ $template->code }} with example values. No student’s details appear on it.
        </div>
    </div>
@endsection
