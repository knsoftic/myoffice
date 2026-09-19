@extends('layouts.admin')

@section('title', 'Edit service')

{{--
    Edit service — admin.services.edit (phase-04 §8.2).

    Controller variables (Admin\ServiceController@edit):
      $service            App\Models\Cms\Service with category, technologies, image, editor (optional)
      $categoryOptions, $technologyOptions, $statusOptions, $reservedSlugs, $mediaLibrary, $maxUploadMb
      $seoMeta            ?SeoMeta     SeoService::meta($service)
      $seoInherited       ?SeoPayload  SeoService::for($service)
      $publicUrl          string       route('site.services.show', $service->slug)

    Writes: PUT admin.services.update {service} (multipart); DELETE admin.services.destroy {service}.
--}}

@php
    use App\Enums\Cms\ContentStatus;
    use Illuminate\Support\Facades\Route;

    $isPublished = ($service->status instanceof \BackedEnum ? $service->status->value : (string) $service->status) === ContentStatus::Published->value;
    $canEdit = (bool) auth()->user()?->can('services.edit');
    $canDelete = (bool) auth()->user()?->can('services.delete');
@endphp

@section('header')
    <x-ui.page-header :title="$service->name" :subtitle="'/services/'.$service->slug" icon="wrench-screwdriver" :back="route('admin.services.index')">
        <div class="mt-2 flex flex-wrap items-center gap-2">
            @include('admin.marketing.partials.enum-badge', ['value' => $service->status])
            @if ($service->is_featured)
                <x-ui.badge color="amber" size="sm" icon="star">Featured</x-ui.badge>
            @endif
        </div>

        <x-slot:actions>
            @if ($isPublished && Route::has('site.services.show'))
                <x-ui.button variant="ghost" icon="arrow-top-right-on-square" :href="route('site.services.show', $service->slug)" target="_blank" rel="noopener">View live</x-ui.button>
            @endif
            @if ($canDelete)
                <x-ui.confirm
                    :action="route('admin.services.destroy', $service)"
                    :title="'Delete '.$service->name.'?'"
                    message="The service moves to the trash and disappears from the website. Its address stays reserved."
                    confirm-label="Delete service"
                >
                    <x-slot:trigger>
                        <x-ui.icon-button icon="trash" variant="danger" label="Delete service" />
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
        <form id="service-form" method="POST" action="{{ route('admin.services.update', $service) }}" enctype="multipart/form-data" class="space-y-6">
            @csrf
            @method('PUT')

            @include('admin.marketing.partials.form-errors')
            @include('admin.services.partials.form', ['service' => $service])

            @if ($canEdit)
                @include('admin.marketing.partials.save-bar', ['cancel' => route('admin.services.index'), 'submitLabel' => 'Save service', 'record' => $service])
            @endif
        </form>
    </div>
@endsection
