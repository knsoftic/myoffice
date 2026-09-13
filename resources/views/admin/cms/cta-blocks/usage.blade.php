@extends('layouts.admin')

@section('title', 'CTA block usage')

{{--
    CTA block usage — admin.website.cta-blocks.usage (phase-03 §7.4, §8.11 "used in 3 places").
    The HTML answer; a JSON request gets {"id": …, "usage": [...]} from the controller (the index popover).

    Controller variables (Admin\Cms\CtaBlockController@usage):
      $block  App\Models\Cms\CtaBlock
      $usage  Collection<array{type: string, id: int, label: string, detail: ?string, url?: ?string}>
--}}

@section('header')
    <x-ui.page-header
        :title="'Where “'.$block->name.'” is used'"
        :subtitle="'Key: '.$block->key"
        icon="link"
        :back="route('admin.website.cta-blocks.edit', $block)"
        :badge="collect($usage)->count().' '.\Illuminate\Support\Str::plural('place', collect($usage)->count())"
    />
@endsection

@section('content')
    <x-ui.card :padded="false">
        @include('admin.cms.partials.usage-list', [
            'usage' => $usage,
            'emptyTitle' => 'Not placed anywhere',
            'emptyMessage' => 'No section or page references this block, so it can be deleted.',
        ])
    </x-ui.card>
@endsection
