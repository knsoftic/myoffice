@extends('layouts.panel')

@section('title', 'Messages')

{{--
    The student panel's messages.show screen.

    The layout is this panel's; everything else is the shared partial. Four copies of that markup
    would drift, and the one that drifted would be the portal nobody opens by hand.
--}}

@section('header')
    <x-ui.page-header title="{{ $conversation->subject ?? 'Message' }}"
                      subtitle="{{ $conversation->participants->where('user_id', '!=', auth()->id())->map(fn ($p) => $p->user?->name)->filter()->join(', ') }}"
                      icon="chat-bubble-left-ellipsis">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('student.messages.index')">Back to messages</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@include('support.messages._show')
