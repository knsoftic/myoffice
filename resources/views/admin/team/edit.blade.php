@extends('layouts.admin')

@section('title', 'Edit team member')

{{--
    Edit team member — admin.team.edit (phase-04 §8.4).

    Controller variables (Admin\TeamMemberController@edit):
      $member             App\Models\Cms\TeamMember with photo, editor (optional)
      $socialPlatforms, $departmentOptions, $statusOptions, $reservedSlugs, $mediaLibrary, $maxUploadMb

    Writes: PUT admin.team.update {member} (multipart); DELETE admin.team.destroy {member}.
--}}

@php
    use App\Enums\Cms\ContentStatus;
    use Illuminate\Support\Facades\Route;

    $isLive = ($member->status instanceof \BackedEnum ? $member->status->value : (string) $member->status) === ContentStatus::Published->value && $member->is_public;
    $canEdit = (bool) auth()->user()?->can('team.edit');
    $canDelete = (bool) auth()->user()?->can('team.delete');
@endphp

@section('header')
    <x-ui.page-header :title="$member->name" :subtitle="$member->designation" icon="user" :back="route('admin.team.index')">
        <div class="mt-2 flex flex-wrap items-center gap-2">
            @include('admin.marketing.partials.enum-badge', ['value' => $member->status])
            @unless ($member->is_public)
                <x-ui.badge color="slate" variant="outline" size="sm" icon="eye-slash">Hidden from website</x-ui.badge>
            @endunless
        </div>

        <x-slot:actions>
            @if ($isLive && Route::has('site.team.index'))
                <x-ui.button variant="ghost" icon="arrow-top-right-on-square" :href="route('site.team.index').'#'.$member->slug" target="_blank" rel="noopener">View on team page</x-ui.button>
            @endif
            @if ($canDelete)
                <x-ui.confirm
                    :action="route('admin.team.destroy', $member)"
                    :title="'Delete '.$member->name.'?'"
                    message="The profile moves to the trash and leaves the team page. No account or HR record is touched."
                    confirm-label="Delete member"
                >
                    <x-slot:trigger>
                        <x-ui.icon-button icon="trash" variant="danger" label="Delete member" />
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
        <form id="team-form" method="POST" action="{{ route('admin.team.update', $member) }}" enctype="multipart/form-data" class="space-y-6">
            @csrf
            @method('PUT')

            @include('admin.marketing.partials.form-errors')
            @include('admin.team.partials.form', ['member' => $member])

            @if ($canEdit)
                @include('admin.marketing.partials.save-bar', ['cancel' => route('admin.team.index'), 'submitLabel' => 'Save member', 'record' => $member])
            @endif
        </form>
    </div>
@endsection
