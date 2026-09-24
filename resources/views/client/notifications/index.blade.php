@extends('layouts.panel')

@section('title', 'Notifications')

{{--
    The client panel's bell — client.notifications.index.

    **This replaces Phase 5's own screen**, which expected a different set of variables and was
    served by the client panel's own NotificationController. §7.7 puts every panel's bell on one
    controller and one partial so they cannot drift; leaving the client on its own copy would have
    been that drift, arriving one panel at a time.
--}}

@section('header')
    <x-ui.page-header title="Notifications"
                      subtitle="Everything we have told you. Archiving never deletes — a notification is the record that you were told."
                      icon="bell">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="adjustments-horizontal"
                         :href="route('client.notifications.preferences')">Preferences</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@include('support.notifications._index')
