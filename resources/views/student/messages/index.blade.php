@extends('layouts.panel')

@section('title', 'Messages')

{{--
    The student panel's messages.index screen.

    The layout is this panel's; everything else is the shared partial. Four copies of that markup
    would drift, and the one that drifted would be the portal nobody opens by hand.
--}}

@section('header')
    <x-ui.page-header title="Messages"
                      subtitle="Your conversations. Nothing here is ever deleted."
                      icon="chat-bubble-left-ellipsis">
    </x-ui.page-header>
@endsection

@include('support.messages._index')
