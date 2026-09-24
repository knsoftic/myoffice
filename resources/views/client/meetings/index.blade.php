@extends('layouts.panel')

@section('title', 'Meetings')

{{--
    The client panel's meetings.index screen.

    The layout is this panel's; everything else is the shared partial. Four copies of that markup
    would drift, and the one that drifted would be the portal nobody opens by hand.
--}}

@section('header')
    <x-ui.page-header title="Meetings"
                      subtitle="Your diary. Accept or decline an invitation, and add it to your own calendar."
                      icon="video-camera">
    </x-ui.page-header>
@endsection

@include('support.meetings._index')
