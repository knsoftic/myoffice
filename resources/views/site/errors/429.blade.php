{{--
    "Too many submissions" — the 429 a public form limiter answers, rendered inside the site layout, never a raw framework
    error page (phase-04 §6.9: named limiters public-contact and public-apply).

    Rendered by App\Support\Cms\PublicFormRateLimits::responder(), which looks this view up as `site.errors.429` and
    passes (status 429, with the Retry-After header):
      $message             string  the plain-text sentence the JSON answer carries
      $retryAfterSeconds   int
      $retryAfterMinutes   int
    Optional: $site (the layout degrades to the brand and footer without it), $backUrl (defaults to the previous page,
    same origin only).
--}}

@extends('site.layouts.public')

@section('title', 'Please try again shortly')
@section('robots', 'noindex, nofollow')

@php
    $seconds = max(1, (int) ($retryAfterSeconds ?? 60));
    $minutes = max(1, (int) ($retryAfterMinutes ?? ceil($seconds / 60)));
    $wait = $seconds < 60
        ? app_number($seconds).' '.($seconds === 1 ? 'second' : 'seconds')
        : app_number($minutes).' '.($minutes === 1 ? 'minute' : 'minutes');
    $back = $backUrl ?? url()->previous();
    $back = is_string($back) && str_starts_with($back, url('/')) ? $back : url('/');
@endphp

@section('content')
    <x-site.section background="brand" padding="loose">
        <div class="mx-auto max-w-2xl text-center">
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-brand-600 dark:text-brand-400">Slow down a little</p>
            <h1 class="mt-4 text-balance text-4xl font-bold tracking-tight text-slate-900 sm:text-5xl dark:text-white">Too many submissions</h1>
            <p class="mx-auto mt-6 max-w-xl text-pretty text-lg leading-relaxed text-slate-600 dark:text-slate-300">
                We received several submissions from your connection in a short time, so this one was not sent.
                Please try again in {{ $wait }}. Earlier submissions have reached us.
            </p>
            <div class="mt-10 flex justify-center">
                <x-site.button label="Go back" :url="$back" style="primary" icon="arrow-left" size="lg" />
            </div>
        </div>
    </x-site.section>
@endsection
