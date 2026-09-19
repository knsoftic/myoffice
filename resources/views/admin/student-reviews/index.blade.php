@extends('layouts.admin')

{{--
    Student reviews moderation queue — admin.student-reviews.index (phase-04 §8.5, §2.11, §6.5, §7.2).

    Controller variables (Admin\StudentReviewController@index): see admin/marketing/partials/moderation-queue.blade.php,
    with the paginator named $reviews (studentPhoto, approver, submitter eager-loaded) and
      $courseOptions  array<string, string>   distinct course_name snapshots (value = label) — a free-text
                                               snapshot until Phase 14 adds the course picker (§2.1)
    Extra query key: course.
--}}

@php
    $publicAnchor = \Illuminate\Support\Facades\Route::has('site.home')
        ? static fn ($record): string => route('site.home').'#student-review-'.$record->getKey()
        : null;
@endphp

@include('admin.marketing.partials.moderation-queue', [
    'records' => $reviews,
    'queue' => [
        'resource' => 'student-reviews',
        'module' => 'student_reviews',
        'routePrefix' => 'admin.student-reviews',
        'title' => 'Student reviews',
        'subtitle' => 'Reviews from students, with optional video. Only approved reviews are public.',
        'icon' => 'star',
        'noun' => 'review',
        'nounPlural' => 'reviews',
        'addLabel' => 'Add review',
        'photoRelations' => ['studentPhotoAsset', 'studentPhoto', 'studentPhotoMedia'],
        'photoColumn' => 'student_photo_media_id',
        'present' => static fn ($record): array => [
            'name' => (string) $record->student_name,
            'meta' => $record->course_name ? (string) $record->course_name : null,
            'video' => filled($record->video_url),
        ],
        'filters' => [
            ['name' => 'course', 'label' => 'Any course', 'options' => $courseOptions ?? []],
        ],
        'publicAnchor' => $publicAnchor,
    ],
])
