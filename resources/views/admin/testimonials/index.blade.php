@extends('layouts.admin')

{{--
    Testimonials moderation queue — admin.testimonials.index (phase-04 §8.5, §2.10, §6.5, §7.2).

    Controller variables (Admin\TestimonialController@index): see admin/marketing/partials/moderation-queue.blade.php,
    with the paginator named $testimonials (authorPhoto, approver, submitter eager-loaded) and
      $typeOptions   array<string, string>   TestimonialType::options()
    Extra query key: type (client|student|other).
--}}

@php
    $publicAnchor = \Illuminate\Support\Facades\Route::has('site.home')
        ? static fn ($record): string => route('site.home').'#testimonial-'.$record->getKey()
        : null;
@endphp

@include('admin.marketing.partials.moderation-queue', [
    'records' => $testimonials,
    'queue' => [
        'resource' => 'testimonials',
        'module' => 'testimonials',
        'routePrefix' => 'admin.testimonials',
        'title' => 'Testimonials',
        'subtitle' => 'Client and student testimonials. Nothing reaches the website until it is approved.',
        'icon' => 'chat-bubble-left-right',
        'noun' => 'testimonial',
        'nounPlural' => 'testimonials',
        'addLabel' => 'Add testimonial',
        'photoRelations' => ['authorPhotoAsset', 'authorPhoto', 'authorPhotoMedia'],
        'photoColumn' => 'author_photo_media_id',
        'present' => static function ($record): array {
            $type = $record->type instanceof \BackedEnum ? $record->type->value : (string) $record->type;
            $meta = $type === 'student'
                ? ($record->course_name ? 'Student · '.$record->course_name : 'Student')
                : collect([$record->author_designation, $record->author_company])->filter()->implode(', ');

            return ['name' => (string) $record->author_name, 'meta' => $meta, 'badge' => $record->type];
        },
        'filters' => [
            ['name' => 'type', 'label' => 'Any type', 'options' => $typeOptions ?? []],
        ],
        'publicAnchor' => $publicAnchor,
    ],
])
