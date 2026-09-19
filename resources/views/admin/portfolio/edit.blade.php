@extends('layouts.admin')

@section('title', 'Edit project')

{{--
    Edit portfolio item + gallery manager — admin.portfolio.edit (phase-04 §8.3).

    Controller variables (Admin\PortfolioItemController@edit):
      $item               App\Models\Cms\PortfolioItem with category, technologies, cover, editor (optional)
      $gallery            Collection<MediaAsset> ordered by pivot sort_order, withPivot('caption', 'sort_order')
      $categoryOptions, $technologyOptions, $statusOptions, $reservedSlugs, $mediaLibrary, $maxUploadMb
      $seoMeta, $seoInherited, $publicUrl (route('site.portfolio.show', $item->slug))
      $maxImages          optional int (20)

    Writes: PUT admin.portfolio.update {item}; DELETE admin.portfolio.destroy {item}; the gallery endpoints
    (admin/portfolio/partials/gallery.blade.php).
--}}

@php
    use App\Enums\Cms\ContentStatus;
    use Illuminate\Support\Facades\Route;

    $isPublished = ($item->status instanceof \BackedEnum ? $item->status->value : (string) $item->status) === ContentStatus::Published->value;
    $canEdit = (bool) auth()->user()?->can('portfolio.edit');
    $canDelete = (bool) auth()->user()?->can('portfolio.delete');
@endphp

@section('header')
    <x-ui.page-header :title="$item->title" :subtitle="'/portfolio/'.$item->slug" icon="photo" :back="route('admin.portfolio.index')">
        <div class="mt-2 flex flex-wrap items-center gap-2">
            @include('admin.marketing.partials.enum-badge', ['value' => $item->status])
            @if ($item->is_featured)
                <x-ui.badge color="amber" size="sm" icon="star">Featured</x-ui.badge>
            @endif
            @if (filled($item->client_name))
                <x-ui.badge color="slate" size="sm" icon="building-office">{{ $item->client_name }}</x-ui.badge>
            @endif
        </div>

        <x-slot:actions>
            @if ($isPublished && Route::has('site.portfolio.show'))
                <x-ui.button variant="ghost" icon="arrow-top-right-on-square" :href="route('site.portfolio.show', $item->slug)" target="_blank" rel="noopener">View live</x-ui.button>
            @endif
            @if ($canDelete)
                <x-ui.confirm
                    :action="route('admin.portfolio.destroy', $item)"
                    :title="'Delete '.$item->title.'?'"
                    message="The project moves to the trash. Its images and their files stay in the media library."
                    confirm-label="Delete project"
                >
                    <x-slot:trigger>
                        <x-ui.icon-button icon="trash" variant="danger" label="Delete project" />
                    </x-slot:trigger>
                </x-ui.confirm>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.cms.partials.scripts')
    @include('admin.cms.partials.media-library-json', ['library' => $mediaLibrary ?? []])

    <div class="space-y-6">
        @include('admin.portfolio.partials.gallery', ['item' => $item, 'gallery' => $gallery ?? collect()])

        <div x-data="cmsDirty()" x-on:submit="submitted()">
            <form id="portfolio-form" method="POST" action="{{ route('admin.portfolio.update', $item) }}" enctype="multipart/form-data" class="space-y-6">
                @csrf
                @method('PUT')

                @include('admin.marketing.partials.form-errors', ['except' => ['images', 'media_asset_ids', 'ids', 'caption']])
                @include('admin.portfolio.partials.form', ['item' => $item])

                @if ($canEdit)
                    @include('admin.marketing.partials.save-bar', ['cancel' => route('admin.portfolio.index'), 'submitLabel' => 'Save project', 'record' => $item])
                @endif
            </form>
        </div>
    </div>
@endsection
