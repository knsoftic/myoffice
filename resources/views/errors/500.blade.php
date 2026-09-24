{{--
    500 - something went wrong on our side.

    **It says nothing about what.** With APP_DEBUG off a stack trace never reaches this page; with
    it on, Laravel shows its own debug page and this one is never used. So there is no branch here
    that could leak a trace by accident - the page simply has nowhere to put one.

    The exception is already in the log, in the daily digest and, when it is configured, in the
    external monitor. Nothing is gained by putting a class name in front of a visitor who cannot
    act on it.
--}}

@extends('errors.layout')

@section('code', 'Error 500')
@section('title', 'Something went wrong')

@section('message')
        <p>The problem is on our side, not yours, and it has been recorded.</p>
        <p>Try again in a moment. If it keeps happening, tell whoever looks after this system.</p>
@endsection

@section('actions')
        <a class="button primary" href="{{ url('/') }}">Go to the dashboard</a>
@endsection
