@extends('layouts.admin')

@section('title', 'New event')

{{--
    New event — admin.events.create, can:events.create.

    Controller variables (Admin\Cms\EventController@create):
      $event (null), $reservedSlugs, $mediaLibrary, $maxUploadMb, $timezone, $minScheduleAt,
      $statusOptions, $canChangeStatus
      (see admin/events/partials/form.blade.php)

    Writes: POST admin.events.store.

    There is no publish control here and there cannot be one: a new event is always a draft, `status`
    is `prohibited` on StoreEventRequest and is not fillable on the model, and publishing is its own
    endpoint behind `events.change_status`.
--}}

@section('header')
    <x-ui.page-header
        title="New event"
        subtitle="Saved as a draft. Nothing reaches the website until somebody publishes it."
        icon="calendar-days"
        :back="route('admin.events.index')"
    />
@endsection

@section('content')
    @include('admin.cms.partials.scripts')
    @include('admin.cms.partials.media-library-json', ['library' => $mediaLibrary ?? []])

    <div x-data="cmsDirty()" x-on:submit="submitted()">
        <form id="event-form" method="POST" action="{{ route('admin.events.store') }}" class="space-y-6">
            @csrf

            @include('admin.marketing.partials.form-errors')
            @include('admin.events.partials.form', ['event' => null])
            @include('admin.marketing.partials.save-bar', [
                'form' => 'event-form',
                'cancel' => route('admin.events.index'),
                'submitLabel' => 'Save draft',
                'record' => null,
            ])
        </form>
    </div>
@endsection
