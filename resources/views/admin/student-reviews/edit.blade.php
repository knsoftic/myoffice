@extends('layouts.admin')

@section('title', 'Edit student review')

{{--
    Edit student review — admin.student-reviews.edit (phase-04 §8.5).

    Controller variables (Admin\StudentReviewController@edit):
      $review          App\Models\Cms\StudentReview with studentPhoto, approver, submitter, editor (optional)
      $courseOptions (optional), $mediaLibrary, $maxUploadMb

    Writes: PUT admin.student-reviews.update {review} (multipart); the moderation actions in the header;
    DELETE admin.student-reviews.destroy {review}.
--}}

@php
    $statusValue = $review->status instanceof \BackedEnum ? $review->status->value : (string) $review->status;
    $approver = $review->relationLoaded('approver') ? $review->approver : null;
    $canEdit = (bool) auth()->user()?->can('student_reviews.edit');
    $canDelete = (bool) auth()->user()?->can('student_reviews.delete');
@endphp

@section('header')
    <x-ui.page-header :title="$review->student_name" :subtitle="$review->course_name ?: 'Student review'" icon="star" :back="route('admin.student-reviews.index')">
        <div class="mt-2 flex flex-wrap items-center gap-2">
            @include('admin.marketing.partials.enum-badge', ['value' => $review->status])
            @include('admin.marketing.partials.enum-badge', ['value' => $review->source, 'dot' => false, 'variant' => 'outline'])
            @if ($review->is_featured)
                <x-ui.badge color="amber" size="sm" icon="star">Featured</x-ui.badge>
            @endif
            @if ($statusValue === 'approved' && $review->approved_at)
                <span class="text-xs text-slate-500 dark:text-slate-400">Approved {{ app_datetime($review->approved_at) }}@if ($approver) by {{ $approver->name }}@endif</span>
            @endif
        </div>
        @if ($statusValue === 'rejected' && filled($review->rejection_reason))
            <p class="mt-2 text-sm text-rose-700 dark:text-rose-300">Rejected: {{ $review->rejection_reason }}</p>
        @endif

        <x-slot:actions>
            <x-cms.moderation-actions :record="$review" module="student_reviews" route-prefix="admin.student-reviews" :label="$review->student_name" :with-text="true" size="md" />
            @if ($canDelete)
                <x-ui.confirm
                    :action="route('admin.student-reviews.destroy', $review)"
                    title="Delete this review?"
                    message="It moves to the trash and leaves every public page."
                    confirm-label="Delete review"
                >
                    <x-slot:trigger>
                        <x-ui.icon-button icon="trash" variant="danger" label="Delete review" />
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
        <form id="review-form" method="POST" action="{{ route('admin.student-reviews.update', $review) }}" enctype="multipart/form-data" class="space-y-6">
            @csrf
            @method('PUT')

            @include('admin.marketing.partials.form-errors', ['except' => ['reason']])
            @include('admin.student-reviews.partials.form', ['review' => $review])

            @if ($canEdit)
                @include('admin.marketing.partials.save-bar', ['cancel' => route('admin.student-reviews.index'), 'submitLabel' => 'Save review', 'record' => $review])
            @endif
        </form>
    </div>
@endsection
