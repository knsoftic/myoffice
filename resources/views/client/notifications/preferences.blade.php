@extends('layouts.panel')

@section('title', 'Notification preferences')

{{--
    The client panel's preference screen — client.notifications.preferences.

    One partial for all five panels; see `support/notifications/_preferences.blade.php` for why the
    list is shorter here than on the admin panel.
--}}

@section('header')
    <x-ui.page-header title="Notification preferences"
                      subtitle="What reaches you, and how. A few rows are always on — they are the ones where not being told is a dispute."
                      icon="adjustments-horizontal">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('client.notifications.index')">Back to notifications</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@include('support.notifications._preferences')
