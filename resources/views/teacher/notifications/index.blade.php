@extends('layouts.panel')

@section('title', 'Notifications')

{{--
    The teacher panel's bell — teacher.notifications.index.

    The layout is this panel's; everything else is the shared partial, because §7.7 says the bell
    behaves identically everywhere and four copies of that markup would not.
--}}

@section('header')
    <x-ui.page-header title="Notifications"
                      subtitle="Everything the system has told you. Archiving never deletes — a notification is the record that you were told."
                      icon="bell">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="adjustments-horizontal"
                         :href="route('teacher.notifications.preferences')">Preferences</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@include('support.notifications._index')
