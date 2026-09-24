{{--
    503 - down for maintenance.

    The page an operator sees behind `php artisan down`, and the one every visitor sees while a
    deploy runs. It carries `Retry-After`, set by the framework, so a monitor knows this is planned
    rather than broken.

    No "go to the dashboard" button: the dashboard is down too, and a button that leads to another
    copy of this page is worse than no button.
--}}

@extends('errors.layout')

@section('code', 'Error 503')
@section('title', 'We will be back shortly')

@section('message')
        <p>The system is being updated. This usually takes a few minutes.</p>
        <p>Your data is safe, and nothing you saved before now has been lost.</p>
@endsection
