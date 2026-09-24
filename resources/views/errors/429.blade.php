{{--
    429 - too many requests.

    Rendered by RateLimitServiceProvider::responder() for an authenticated route, and by
    PublicFormRateLimits for a public form (which prefers site.errors.429, the version inside the
    public layout).

    Receives, optionally:
      $message             string  the plain sentence the JSON answer carries
      $retryAfterSeconds   int
      $retryAfterMinutes   int

    **The key is never named.** Telling somebody their limit is counted per email address confirms
    that the address exists, which is precisely what a sign-in page spends its effort not saying.
--}}

@extends('errors.layout')

@section('code', 'Error 429')
@section('title', 'Too many requests')

@section('message')
        @php
            $seconds = max(1, (int) ($retryAfterSeconds ?? 60));
            $minutes = max(1, (int) ($retryAfterMinutes ?? (int) ceil($seconds / 60)));
            $wait = $seconds < 60
                ? $seconds.' '.($seconds === 1 ? 'second' : 'seconds')
                : $minutes.' '.($minutes === 1 ? 'minute' : 'minutes');
        @endphp

        <p>{{ $message ?? 'You have made too many requests in a short time.' }}</p>
        <p>Please try again in {{ $wait }}.</p>
@endsection
