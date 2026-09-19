@extends('layouts.admin')

@section('title', 'Edit job')

{{--
    Edit job opening — admin.jobs.edit (phase-04 §8.8).

    Controller variables (Admin\JobOpeningController@edit):
      $job                App\Models\Cms\JobOpening withCount(['applications as new_applications_count' => new]), editor (optional)
      $statusOptions, $employmentTypeOptions, $workModeOptions, $salaryPeriodOptions, $departmentOptions,
      $reservedSlugs, $mediaLibrary
      $seoMeta, $seoInherited, $publicUrl (route('site.careers.show', $job->slug))

    Writes: PUT admin.jobs.update {job}; DELETE admin.jobs.destroy {job}.
--}}

@php
    use Illuminate\Support\Facades\Route;

    $statusValue = $job->status instanceof \BackedEnum ? $job->status->value : (string) $job->status;
    $canEdit = (bool) auth()->user()?->can('jobs.edit');
    $canDelete = (bool) auth()->user()?->can('jobs.delete');
    $canApplications = (bool) auth()->user()?->can('job_applications.view_any') && Route::has('admin.job-applications.index');
    $newCount = (int) ($job->getAttributes()['new_applications_count'] ?? 0);
@endphp

@section('header')
    <x-ui.page-header :title="$job->title" :subtitle="'/careers/'.$job->slug" icon="briefcase" :back="route('admin.jobs.index')">
        <div class="mt-2 flex flex-wrap items-center gap-2">
            @include('admin.marketing.partials.enum-badge', ['value' => $job->status])
            @if ($job->opened_at)
                <span class="text-xs text-slate-500 dark:text-slate-400">Opened {{ app_date($job->opened_at) }}</span>
            @endif
            @if ($job->closed_at)
                <span class="text-xs text-slate-500 dark:text-slate-400">· Closed {{ app_date($job->closed_at) }}</span>
            @endif
        </div>

        <x-slot:actions>
            @if ($canApplications)
                <x-ui.button variant="secondary" icon="document-text" :href="route('admin.job-applications.index', ['job' => $job->getKey()])">
                    {{ app_number((int) ($job->applications_count ?? 0)) }} applications{{ $newCount > 0 ? ' ('.app_number($newCount).' new)' : '' }}
                </x-ui.button>
            @endif
            @if ($statusValue === 'open' && Route::has('site.careers.show'))
                <x-ui.button variant="ghost" icon="arrow-top-right-on-square" :href="route('site.careers.show', $job->slug)" target="_blank" rel="noopener">View live</x-ui.button>
            @endif
            @if ($canDelete)
                <x-ui.confirm
                    :action="route('admin.jobs.destroy', $job)"
                    :title="'Delete '.$job->title.'?'"
                    message="The opening moves to the trash and leaves the careers page. Its applications and CVs are kept."
                    confirm-label="Delete job"
                >
                    <x-slot:trigger>
                        <x-ui.icon-button icon="trash" variant="danger" label="Delete job" />
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
        <form id="job-form" method="POST" action="{{ route('admin.jobs.update', $job) }}" class="space-y-6">
            @csrf
            @method('PUT')

            @include('admin.marketing.partials.form-errors')
            @include('admin.jobs.partials.form', ['job' => $job])

            @if ($canEdit)
                @include('admin.marketing.partials.save-bar', ['cancel' => route('admin.jobs.index'), 'submitLabel' => 'Save job', 'record' => $job])
            @endif
        </form>
    </div>
@endsection
