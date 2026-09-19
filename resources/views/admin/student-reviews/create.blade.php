@extends('layouts.admin')

@section('title', 'New student review')

{{--
    New student review — admin.student-reviews.create (phase-04 §8.5).

    Controller variables (Admin\StudentReviewController@create):
      $courseOptions (optional), $mediaLibrary, $maxUploadMb
      $autoApprove   bool   website.testimonial_auto_approve — read by the controller, never by this view

    Writes: POST admin.student-reviews.store (multipart).
--}}

@section('header')
    <x-ui.page-header title="New student review" icon="star" :back="route('admin.student-reviews.index')"
        :subtitle="($autoApprove ?? false) ? 'Staff-entered reviews are approved on save (website setting).' : 'Saved as pending: it reaches the website once approved.'" />
@endsection

@section('content')
    @include('admin.cms.partials.scripts')
    @include('admin.cms.partials.media-library-json', ['library' => $mediaLibrary ?? []])

    <div x-data="cmsDirty()" x-on:submit="submitted()">
        <form id="review-form" method="POST" action="{{ route('admin.student-reviews.store') }}" enctype="multipart/form-data" class="space-y-6">
            @csrf

            @include('admin.marketing.partials.form-errors')
            @include('admin.student-reviews.partials.form', ['review' => null])
            @include('admin.marketing.partials.save-bar', ['cancel' => route('admin.student-reviews.index'), 'submitLabel' => 'Save review', 'record' => null])
        </form>
    </div>
@endsection
