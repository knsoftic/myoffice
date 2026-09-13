@extends('layouts.admin')

@section('title', 'Page revisions')

{{--
    Page revisions — admin.website.pages.revisions.index (phase-03 §7.3; read under pages.view_logs,
    revert under pages.change_status).

    Controller variables (Admin\Cms\PageRevisionController@index):
      $page           App\Models\Cms\Page
      $revisions      LengthAwarePaginator<App\Models\Cms\CmsRevision>   newest first
      $authors        array<int, string>                                 user id => name
      $currentHash    ?string
      $publishedHash  ?string
      $canRevert      bool

    Writes: POST admin.website.pages.revisions.revert {page, revision} — reason (required).
--}}

@section('header')
    <x-ui.page-header
        :title="'Revisions — '.$page->title"
        subtitle="Every draft save, publish, unpublish and revert of the page body. Reverting restores the draft; the live page changes only when you publish."
        icon="clock"
        :back="route('admin.website.pages.edit', $page)"
        :badge="app_number($revisions->total()).' '.\Illuminate\Support\Str::plural('revision', $revisions->total())"
    />
@endsection

@section('content')
    @include('admin.cms.partials.revisions-table', [
        'subject' => $page,
        'routeParam' => 'page',
        'revertRoute' => 'admin.website.pages.revisions.revert',
        'revisions' => $revisions,
        'authors' => $authors ?? [],
        'currentHash' => $currentHash ?? $page->content_hash,
        'publishedHash' => $publishedHash ?? $page->published_hash,
        'canRevert' => (bool) ($canRevert ?? false),
    ])
@endsection
