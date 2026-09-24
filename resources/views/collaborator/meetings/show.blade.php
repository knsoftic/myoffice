@extends('layouts.panel')

@section('title', 'Meetings')

{{--
    The collaborator panel's meetings.show screen.

    The layout is this panel's; everything else is the shared partial. Four copies of that markup
    would drift, and the one that drifted would be the portal nobody opens by hand.
--}}

@section('header')
    <x-ui.page-header title="{{ $meeting->title }}"
                      subtitle="{{ app_datetime($meeting->scheduled_at) }}"
                      icon="video-camera">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('collaborator.meetings.index')">Back to the diary</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@include('support.meetings._show')
