@extends('layouts.admin')

@section('title', 'Section revisions')

{{--
    Section revisions — admin.website.sections.revisions.index (phase-03 §7.1; read under
    website_sections.view_logs, revert under website_sections.change_status).

    Controller variables (Admin\Cms\SectionRevisionController@index):
      $section        App\Models\Cms\WebsiteSection
      $revisions      LengthAwarePaginator<App\Models\Cms\CmsRevision>   newest first
      $authors        array<int, string>                                 user id => name
      $currentHash    ?string                                            the draft's content_hash
      $publishedHash  ?string                                            the live snapshot's hash
      $canRevert      bool

    Writes: POST admin.website.sections.revisions.revert {section, revision} — reason (required).
--}}

@php
    use App\Support\Cms\SectionRegistry;

    $key = (string) $section->section_key;
    $label = $section->name ?: (SectionRegistry::exists($key) ? SectionRegistry::label($key) : $key);
@endphp

@section('header')
    <x-ui.page-header
        :title="'Revisions — '.$label"
        subtitle="Every draft save, publish, unpublish and revert. Reverting restores the draft; the live site changes only when you publish."
        icon="clock"
        :back="route('admin.website.sections.edit', $section)"
        :badge="app_number($revisions->total()).' '.\Illuminate\Support\Str::plural('revision', $revisions->total())"
    />
@endsection

@section('content')
    @include('admin.cms.partials.revisions-table', [
        'subject' => $section,
        'routeParam' => 'section',
        'revertRoute' => 'admin.website.sections.revisions.revert',
        'revisions' => $revisions,
        'authors' => $authors ?? [],
        'currentHash' => $currentHash ?? $section->content_hash,
        'publishedHash' => $publishedHash ?? $section->published_hash,
        'canRevert' => (bool) ($canRevert ?? false),
    ])
@endsection
