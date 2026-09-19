@extends('layouts.admin')

@section('title', 'New testimonial')

{{--
    New testimonial — admin.testimonials.create (phase-04 §8.5).

    Controller variables (Admin\TestimonialController@create):
      $typeOptions, $mediaLibrary, $maxUploadMb
      $autoApprove   bool   website.testimonial_auto_approve — read by the controller, never by this view

    Writes: POST admin.testimonials.store (multipart).
--}}

@section('header')
    <x-ui.page-header title="New testimonial" icon="chat-bubble-left-right" :back="route('admin.testimonials.index')"
        :subtitle="($autoApprove ?? false) ? 'Staff-entered testimonials are approved on save (website setting).' : 'Saved as pending: it reaches the website once approved.'" />
@endsection

@section('content')
    @include('admin.cms.partials.scripts')
    @include('admin.cms.partials.media-library-json', ['library' => $mediaLibrary ?? []])

    <div x-data="cmsDirty()" x-on:submit="submitted()">
        <form id="testimonial-form" method="POST" action="{{ route('admin.testimonials.store') }}" enctype="multipart/form-data" class="space-y-6">
            @csrf

            @include('admin.marketing.partials.form-errors')
            @include('admin.testimonials.partials.form', ['testimonial' => null])
            @include('admin.marketing.partials.save-bar', ['cancel' => route('admin.testimonials.index'), 'submitLabel' => 'Save testimonial', 'record' => null])
        </form>
    </div>
@endsection
