@extends('layouts.admin')

@section('title', 'New post')

{{--
    New blog post — admin.blog-posts.create (phase-04 §8.7).

    Controller variables (Admin\BlogPostController@create):
      $categoryOptions, $tagSuggestions, $authorOptions (editors only), $reservedSlugs, $mediaLibrary, $maxUploadMb,
      $canChangeStatus (blog_posts.change_status)
      (see admin/blog-posts/partials/form.blade.php)

    Writes: POST admin.blog-posts.store (multipart) with intent = save | publish | schedule.
--}}

@section('header')
    <x-ui.page-header title="New post" subtitle="You are the author. Save a draft at any time." icon="newspaper" :back="route('admin.blog-posts.index')" />
@endsection

@section('content')
    @include('admin.cms.partials.scripts')
    @include('admin.cms.partials.media-library-json', ['library' => $mediaLibrary ?? []])

    <div x-data="cmsDirty()" x-on:submit="submitted()">
        <form id="post-form" method="POST" action="{{ route('admin.blog-posts.store') }}" enctype="multipart/form-data" class="space-y-6">
            @csrf

            @include('admin.marketing.partials.form-errors')
            @include('admin.blog-posts.partials.form', ['post' => null])
            @include('admin.marketing.partials.save-bar', ['cancel' => route('admin.blog-posts.index'), 'submitLabel' => 'Save draft', 'record' => null])
        </form>
    </div>
@endsection
