{{--
    404 - the answer for a page that is not there, and for one somebody may not see.

    **Deliberately the same page for both.** A record that answers 403 to the wrong person confirms
    that the record exists, which is often the fact worth protecting - that this client, this
    student, this invoice number is real. Several controllers answer 404 on purpose where 403 would
    be the literal truth; the contract records each of those as a decision, not an accident.
--}}

@extends('errors.layout')

@section('code', 'Error 404')
@section('title', 'We could not find that page')

@section('message')
        <p>The address may have changed, or the record may have been removed.</p>
        <p>Check the link and try again.</p>
@endsection

@section('actions')
        <a class="button primary" href="{{ url('/') }}">Go to the dashboard</a>
@endsection
