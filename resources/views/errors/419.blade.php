{{--
    419 - the page expired.

    Almost never an attack and almost always a person who left a form open over lunch: the session
    token that came with the form is older than the session itself. So this page offers the one
    thing that actually fixes it - go back and fill the form in again - rather than explaining
    cross-site request forgery to somebody who wanted to save a client record.

    The unsaved input is gone. Saying so is kinder than letting them find out by looking.

    The action is a plain link, and it replaces the layout's default one rather than sitting beside
    it: an `onclick="history.back()"` would be refused by the Content-Security-Policy this phase
    ships, and a dead button on the page that tells somebody how to recover is worse than no button.
--}}

@extends('errors.layout')

@section('code', 'Error 419')
@section('title', 'This page expired')
@section('own-back', 'yes')

@section('message')
        <p>The form sat open long enough for its security token to expire, so it was not submitted.</p>
        <p>Open it again and re-enter what you had. Nothing was saved.</p>
@endsection

@section('actions')
        <a class="button primary" href="{{ $errorBack }}">Refresh and try again</a>
@endsection
