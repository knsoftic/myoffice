@extends('layouts.panel')

@section('title', 'Support')

{{--
    The student panel's tickets.index screen.

    The layout is this panel's; everything else is the shared partial. Four copies of that markup
    would drift, and the one that drifted would be the portal nobody opens by hand.
--}}

@section('header')
    <x-ui.page-header title="Support"
                      subtitle="Everything you have asked us for help with, and every reply in order."
                      icon="lifebuoy">
        <x-slot:actions>
            @if ($canCreate)
                <x-ui.button icon="plus" :href="route('student.tickets.create')">Ask for help</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@include('support.tickets._index')
