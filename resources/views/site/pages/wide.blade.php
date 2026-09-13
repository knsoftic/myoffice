{{--
    Page template `site.pages.wide` (pages.template allowlist, phase-03 §2.7) — the same page with a
    wider measure, for content with tables, figures or embedded media.

    Receives: $site (SitePayload) and $page — see site/pages/partials/page for every key read.
--}}

@extends('site.layouts.public')

@section('content')
    @include('site.pages.partials.page', ['template' => 'wide'])
@endsection
