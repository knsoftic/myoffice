@extends('layouts.admin')

@section('title', 'New project')

{{--
    New portfolio item — admin.portfolio.create (phase-04 §8.3).

    Controller variables (Admin\PortfolioItemController@create):
      $categoryOptions, $technologyOptions, $statusOptions, $reservedSlugs, $mediaLibrary, $maxUploadMb
      (see admin/portfolio/partials/form.blade.php)

    Writes: POST admin.portfolio.store (multipart, images[] optional). After the save the controller redirects to
    edit, where the gallery manager takes over.
--}}

@section('header')
    <x-ui.page-header title="New project" subtitle="Saved as a draft. Add the gallery now or after saving." icon="photo" :back="route('admin.portfolio.index')" />
@endsection

@section('content')
    @include('admin.cms.partials.scripts')
    @include('admin.cms.partials.media-library-json', ['library' => $mediaLibrary ?? []])

    <div x-data="cmsDirty()" x-on:submit="submitted()">
        <form id="portfolio-form" method="POST" action="{{ route('admin.portfolio.store') }}" enctype="multipart/form-data" class="space-y-6">
            @csrf

            @include('admin.marketing.partials.form-errors')
            @include('admin.portfolio.partials.form', ['item' => null])
            @include('admin.marketing.partials.save-bar', ['cancel' => route('admin.portfolio.index'), 'submitLabel' => 'Create project', 'record' => null])
        </form>
    </div>
@endsection
