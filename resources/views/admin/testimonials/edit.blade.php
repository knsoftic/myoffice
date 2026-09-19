@extends('layouts.admin')

@section('title', 'Edit testimonial')

{{--
    Edit testimonial — admin.testimonials.edit (phase-04 §8.5).

    Controller variables (Admin\TestimonialController@edit):
      $testimonial   App\Models\Cms\Testimonial with authorPhoto, approver, submitter, editor (optional)
      $typeOptions, $mediaLibrary, $maxUploadMb

    Writes: PUT admin.testimonials.update {testimonial} (multipart); the moderation actions in the header;
    DELETE admin.testimonials.destroy {testimonial}.
--}}

@php
    $statusValue = $testimonial->status instanceof \BackedEnum ? $testimonial->status->value : (string) $testimonial->status;
    $approver = $testimonial->relationLoaded('approver') ? $testimonial->approver : null;
    $canEdit = (bool) auth()->user()?->can('testimonials.edit');
    $canDelete = (bool) auth()->user()?->can('testimonials.delete');
@endphp

@section('header')
    <x-ui.page-header :title="$testimonial->author_name" subtitle="Testimonial" icon="chat-bubble-left-right" :back="route('admin.testimonials.index')">
        <div class="mt-2 flex flex-wrap items-center gap-2">
            @include('admin.marketing.partials.enum-badge', ['value' => $testimonial->status])
            @include('admin.marketing.partials.enum-badge', ['value' => $testimonial->source, 'dot' => false, 'variant' => 'outline'])
            @if ($testimonial->is_featured)
                <x-ui.badge color="amber" size="sm" icon="star">Featured</x-ui.badge>
            @endif
            @if ($statusValue === 'approved' && $testimonial->approved_at)
                <span class="text-xs text-slate-500 dark:text-slate-400">Approved {{ app_datetime($testimonial->approved_at) }}@if ($approver) by {{ $approver->name }}@endif</span>
            @endif
        </div>
        @if ($statusValue === 'rejected' && filled($testimonial->rejection_reason))
            <p class="mt-2 text-sm text-rose-700 dark:text-rose-300">Rejected: {{ $testimonial->rejection_reason }}</p>
        @endif

        <x-slot:actions>
            <x-cms.moderation-actions :record="$testimonial" module="testimonials" route-prefix="admin.testimonials" :label="$testimonial->author_name" :with-text="true" size="md" />
            @if ($canDelete)
                <x-ui.confirm
                    :action="route('admin.testimonials.destroy', $testimonial)"
                    title="Delete this testimonial?"
                    message="It moves to the trash and leaves every public page."
                    confirm-label="Delete testimonial"
                >
                    <x-slot:trigger>
                        <x-ui.icon-button icon="trash" variant="danger" label="Delete testimonial" />
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
        <form id="testimonial-form" method="POST" action="{{ route('admin.testimonials.update', $testimonial) }}" enctype="multipart/form-data" class="space-y-6">
            @csrf
            @method('PUT')

            @include('admin.marketing.partials.form-errors', ['except' => ['reason']])
            @include('admin.testimonials.partials.form', ['testimonial' => $testimonial])

            @if ($canEdit)
                @include('admin.marketing.partials.save-bar', ['cancel' => route('admin.testimonials.index'), 'submitLabel' => 'Save testimonial', 'record' => $testimonial])
            @endif
        </form>
    </div>
@endsection
