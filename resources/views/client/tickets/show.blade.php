@extends('layouts.panel')

@section('title', 'Tickets')

{{--
    The client panel's tickets.show screen.

    The layout is this panel's; everything else is the shared partial. Four copies of that markup
    would drift, and the one that drifted would be the portal nobody opens by hand.
--}}

@section('header')
    <x-ui.page-header title="{{ $ticket->subject }}"
                      subtitle="{{ $ticket->ticket_number }}"
                      icon="lifebuoy">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('client.tickets.index')">Back to support</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@include('support.tickets._show')
