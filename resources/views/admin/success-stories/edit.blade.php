@extends('layouts.admin')

@section('title', 'Edit success story')

{{--
    Edit success story — admin.success-stories.edit (phase-04 §8.6).

    Controller variables (Admin\SuccessStoryController@edit):
      $story              App\Models\Cms\SuccessStory with photo, editor (optional)
      $statusOptions, $courseOptions (optional), $platformOptions (optional), $mediaLibrary, $maxUploadMb

    Writes: PUT admin.success-stories.update {story} (multipart); DELETE admin.success-stories.destroy {story}.
--}}

@php
    $canEdit = (bool) auth()->user()?->can('success_stories.edit');
    $canDelete = (bool) auth()->user()?->can('success_stories.delete');
@endphp

@section('header')
    <x-ui.page-header :title="$story->student_name" :subtitle="$story->headline ?: 'Success story'" icon="trophy" :back="route('admin.success-stories.index')">
        <div class="mt-2 flex flex-wrap items-center gap-2">
            @include('admin.marketing.partials.enum-badge', ['value' => $story->status])
            @if ($story->is_featured)
                <x-ui.badge color="amber" size="sm" icon="star">Featured</x-ui.badge>
            @endif
        </div>

        <x-slot:actions>
            @if ($canDelete)
                <x-ui.confirm
                    :action="route('admin.success-stories.destroy', $story)"
                    :title="'Delete the story of '.$story->student_name.'?'"
                    message="It moves to the trash and leaves the website."
                    confirm-label="Delete story"
                >
                    <x-slot:trigger>
                        <x-ui.icon-button icon="trash" variant="danger" label="Delete story" />
                    </x-slot:trigger>
                </x-ui.confirm>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.cms.partials.scripts')
    @include('admin.cms.partials.media-library-json', ['library' => $mediaLibrary ?? []])

    <div x-data="cmsDirty()" x-on:submit="submitted()">
        <form id="story-form" method="POST" action="{{ route('admin.success-stories.update', $story) }}" enctype="multipart/form-data" class="space-y-6">
            @csrf
            @method('PUT')

            @include('admin.marketing.partials.form-errors')
            @include('admin.success-stories.partials.form', ['story' => $story])

            @if ($canEdit)
                @include('admin.marketing.partials.save-bar', ['cancel' => route('admin.success-stories.index'), 'submitLabel' => 'Save story', 'record' => $story])
            @endif
        </form>
    </div>
@endsection
