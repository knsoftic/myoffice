@extends('layouts.admin')

@section('title', 'Media usage')

{{--
    Media usage — admin.website.media.usage (phase-03 §7.5, §2.13, FT-38). The HTML answer; a JSON request
    is answered by the controller.

    Controller variables (Admin\Cms\MediaController@usage):
      $asset  App\Models\Cms\MediaAsset
      $usage  Collection<array{type: string, id: int, label: string, detail: ?string}>   MediaService::usage()
--}}

@section('header')
    <x-ui.page-header
        :title="'Where “'.($asset->title ?: $asset->original_name).'” is used'"
        :subtitle="$asset->mime_type"
        icon="link"
        :back="route('admin.website.media.show', $asset)"
        :badge="collect($usage)->count().' '.\Illuminate\Support\Str::plural('place', collect($usage)->count())"
    />
@endsection

@section('content')
    <x-ui.card :padded="false">
        @include('admin.cms.partials.usage-list', [
            'usage' => $usage,
            'emptyTitle' => 'Not used anywhere',
            'emptyMessage' => 'Nothing references this file, so it can be deleted.',
        ])
    </x-ui.card>
@endsection
