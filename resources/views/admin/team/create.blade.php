@extends('layouts.admin')

@section('title', 'New team member')

{{--
    New team member — admin.team.create (phase-04 §8.4).

    Controller variables (Admin\TeamMemberController@create):
      $socialPlatforms, $departmentOptions, $statusOptions, $reservedSlugs, $mediaLibrary, $maxUploadMb
      (see admin/team/partials/form.blade.php)

    Writes: POST admin.team.store (multipart).
--}}

@section('header')
    <x-ui.page-header title="New team member" subtitle="Website content, not an account: no login is created." icon="user-plus" :back="route('admin.team.index')" />
@endsection

@section('content')
    @include('admin.cms.partials.scripts')
    @include('admin.cms.partials.media-library-json', ['library' => $mediaLibrary ?? []])

    <div x-data="cmsDirty()" x-on:submit="submitted()">
        <form id="team-form" method="POST" action="{{ route('admin.team.store') }}" enctype="multipart/form-data" class="space-y-6">
            @csrf

            @include('admin.marketing.partials.form-errors')
            @include('admin.team.partials.form', ['member' => null])
            @include('admin.marketing.partials.save-bar', ['cancel' => route('admin.team.index'), 'submitLabel' => 'Create member', 'record' => null])
        </form>
    </div>
@endsection
