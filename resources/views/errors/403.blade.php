{{--
    403 - the answer when somebody is signed in and still may not do this.

    Says what happened and nothing more. A 403 that explained WHICH permission was missing would
    hand a reader the shape of the permission system, and an administrator reading the error is not
    the only person who might see it. The activity log has the detail for whoever needs it.
--}}

@extends('errors.layout')

@section('code', 'Error 403')
@section('title', 'You do not have access to this')

@section('message')
        <p>Your account is signed in, but it does not hold the permission this page needs.</p>
        <p>If you think it should, ask an administrator to grant it - they can see exactly what is missing.</p>
@endsection

@section('actions')
        <a class="button primary" href="{{ url('/') }}">Go to the dashboard</a>
@endsection
