@extends('layouts.panel')

@section('title', 'Notification preferences')

{{--
    The student panel's preference screen — student.notifications.preferences.

    One partial for all four panels: see the note in `support/notifications/_preferences.blade.php`
    for why the list is shorter on some panels than others.
--}}

@section('header')
    <x-ui.page-header title="Notification preferences"
                      subtitle="What reaches you, and how. A few rows are always on — they are the ones where not being told is a dispute."
                      icon="adjustments-horizontal">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('student.notifications.index')">Back to notifications</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@include('support.notifications._preferences')
