{{--
    Page template `site.pages.legal` (pages.template allowlist, phase-03 §2.7, §6.14) — privacy policy,
    terms of service, refund policy, course policy: a reading measure, a "Last updated" date (the
    page's published_at through app_date()), and a sticky aside with the company's contact details for
    questions about the policy.

    Receives: $site (SitePayload) and $page — see site/pages/partials/page for every key read.
--}}

@extends('site.layouts.public')

@section('content')
    @include('site.pages.partials.page', ['template' => 'legal'])
@endsection
