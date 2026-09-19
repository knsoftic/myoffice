@extends('layouts.admin')

@section('title', 'New service')

{{--
    New service — admin.services.create (phase-04 §8.2).

    Controller variables (Admin\ServiceController@create):
      $categoryOptions, $technologyOptions, $statusOptions, $reservedSlugs, $mediaLibrary, $maxUploadMb
      (see admin/services/partials/form.blade.php)

    Writes: POST admin.services.store (multipart).
--}}

@section('header')
    <x-ui.page-header title="New service" subtitle="Saved as a draft unless you publish it." icon="wrench-screwdriver" :back="route('admin.services.index')" />
@endsection

@section('content')
    @include('admin.cms.partials.scripts')
    @include('admin.cms.partials.media-library-json', ['library' => $mediaLibrary ?? []])

    <div x-data="cmsDirty()" x-on:submit="submitted()">
        <form id="service-form" method="POST" action="{{ route('admin.services.store') }}" enctype="multipart/form-data" class="space-y-6">
            @csrf

            @include('admin.marketing.partials.form-errors')
            @include('admin.services.partials.form', ['service' => null])
            @include('admin.marketing.partials.save-bar', ['cancel' => route('admin.services.index'), 'submitLabel' => 'Create service', 'record' => null])
        </form>
    </div>
@endsection
