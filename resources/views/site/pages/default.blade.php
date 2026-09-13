{{--
    Page template `site.pages.default` (pages.template allowlist, phase-03 §2.7) — the standard custom
    page: banner, then the body at a comfortable reading measure (or the page's sections).

    Receives: $site (SitePayload) and $page — see site/pages/partials/page for every key read.
--}}

@extends('site.layouts.public')

@section('content')
    @include('site.pages.partials.page', ['template' => 'default'])
@endsection
