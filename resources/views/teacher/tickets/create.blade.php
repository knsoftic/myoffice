@extends('layouts.panel')

@section('title', 'Ask for help')

{{--
    The teacher panel's tickets.create screen.

    The layout is this panel's; everything else is the shared partial. Four copies of that markup
    would drift, and the one that drifted would be the portal nobody opens by hand.
--}}

@section('header')
    <x-ui.page-header title="Ask for help"
                      subtitle="Tell us what is wrong and somebody will pick it up."
                      icon="lifebuoy">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('teacher.tickets.index')">Back</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@include('support.tickets._create')
