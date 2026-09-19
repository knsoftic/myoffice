@extends('layouts.admin')

@section('title', 'New success story')

{{--
    New success story — admin.success-stories.create (phase-04 §8.6).

    Controller variables (Admin\SuccessStoryController@create):
      $statusOptions, $courseOptions (optional), $platformOptions (optional), $mediaLibrary, $maxUploadMb

    Writes: POST admin.success-stories.store (multipart).
--}}

@section('header')
    <x-ui.page-header title="New success story" subtitle="Saved as a draft unless you publish it." icon="trophy" :back="route('admin.success-stories.index')" />
@endsection

@section('content')
    @include('admin.cms.partials.scripts')
    @include('admin.cms.partials.media-library-json', ['library' => $mediaLibrary ?? []])

    <div x-data="cmsDirty()" x-on:submit="submitted()">
        <form id="story-form" method="POST" action="{{ route('admin.success-stories.store') }}" enctype="multipart/form-data" class="space-y-6">
            @csrf

            @include('admin.marketing.partials.form-errors')
            @include('admin.success-stories.partials.form', ['story' => null])
            @include('admin.marketing.partials.save-bar', ['cancel' => route('admin.success-stories.index'), 'submitLabel' => 'Create story', 'record' => null])
        </form>
    </div>
@endsection
