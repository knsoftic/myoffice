@extends('layouts.admin')

@section('title', 'Post a job')

{{--
    New job opening — admin.jobs.create (phase-04 §8.8).

    Controller variables (Admin\JobOpeningController@create):
      $statusOptions, $employmentTypeOptions, $workModeOptions, $salaryPeriodOptions, $departmentOptions,
      $reservedSlugs, $mediaLibrary (OG image picker)
      (see admin/jobs/partials/form.blade.php)

    Writes: POST admin.jobs.store.
--}}

@section('header')
    <x-ui.page-header title="Post a job" subtitle="Saved as a draft until you open it for applications." icon="briefcase" :back="route('admin.jobs.index')" />
@endsection

@section('content')
    @include('admin.cms.partials.scripts')
    @include('admin.cms.partials.media-library-json', ['library' => $mediaLibrary ?? []])

    <div x-data="cmsDirty()" x-on:submit="submitted()">
        <form id="job-form" method="POST" action="{{ route('admin.jobs.store') }}" class="space-y-6">
            @csrf

            @include('admin.marketing.partials.form-errors')
            @include('admin.jobs.partials.form', ['job' => null])
            @include('admin.marketing.partials.save-bar', ['cancel' => route('admin.jobs.index'), 'submitLabel' => 'Create job', 'record' => null])
        </form>
    </div>
@endsection
