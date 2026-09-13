@extends('layouts.admin')

@section('title', 'New page')

{{--
    New page — admin.website.pages.create (phase-03 §7.3, §8.10).

    Controller variables (Admin\Cms\PageController@create):
      $page             App\Models\Cms\Page (unsaved)      defaults: layout content, show_banner, default template
      $layoutOptions    array<string, string>
      $templateOptions  array<string, string>              PageTemplate::options() — the template allowlist
      $reservedSlugs    list<string>                       PageService::reservedSlugs(), for the live hint
      $seoMeta          null
      $seoInherited     ?App\Services\Cms\Data\SeoPayload
      $mediaLibrary     optional list — the picker library in SectionController::mediaLibrary()'s shape;
                        without it the banner / social image pickers offer the upload link only

    Writes: POST admin.website.pages.store — title, slug, layout, excerpt, content, show_banner,
    banner_media_id, banner_heading, banner_subheading, template, sort_order, seo[…the six SeoService::rules()
    keys]. A new page is always a draft; publishing is a separate act on the edit screen.
--}}

@section('header')
    <x-ui.page-header title="New page" subtitle="Saved as a draft. Nothing is public until it is published." icon="document" :back="route('admin.website.pages.index')" />
@endsection

@section('content')
    @include('admin.cms.partials.scripts')
    @include('admin.cms.partials.media-library-json', ['library' => $mediaLibrary ?? []])

    <div x-data="cmsDirty()" x-on:submit="submitted()">
        <form id="page-form" method="POST" action="{{ route('admin.website.pages.store') }}" class="space-y-6">
            @csrf

            @if ($errors->any())
                <div class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/25" role="alert">
                    <p class="font-semibold">The page was not created.</p>
                    <p class="mt-0.5">{{ $errors->first() }}</p>
                </div>
            @endif

            @include('admin.cms.pages.partials.form', [
                'page' => $page ?? null,
                'templates' => $templateOptions ?? null,
                'layoutOptions' => $layoutOptions ?? null,
                'reservedSlugs' => $reservedSlugs ?? [],
                'seo' => $seoMeta ?? null,
                'seoInherited' => $seoInherited ?? null,
                'bannerAsset' => null,
                'ogAsset' => null,
                'slugLocked' => false,
                'readonly' => false,
            ])

            <div class="sticky bottom-0 z-20 -mx-4 flex flex-col-reverse gap-2 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:flex-row sm:items-center sm:justify-end sm:rounded-xl sm:border sm:shadow-lg dark:border-slate-800 dark:bg-slate-900/95">
                <p x-show="dirty" x-cloak class="text-xs font-semibold text-amber-700 sm:mr-auto dark:text-amber-400">Unsaved changes</p>
                <x-ui.button variant="secondary" :href="route('admin.website.pages.index')">Cancel</x-ui.button>
                <x-ui.button type="submit" icon="check">Create draft</x-ui.button>
            </div>
        </form>
    </div>
@endsection
