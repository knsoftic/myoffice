@extends('layouts.admin')

@section('title', 'Edit post')

{{--
    Edit blog post — admin.blog-posts.edit (phase-04 §8.7). BlogPostPolicy::update() has already passed.

    Controller variables (Admin\BlogPostController@edit):
      $post              App\Models\Cms\BlogPost with category, tags, author, featuredImage, editor (optional)
      $categoryOptions, $tagSuggestions, $authorOptions (editors only), $reservedSlugs, $mediaLibrary, $maxUploadMb
      $seoMeta, $seoInherited, $publicUrl (route('site.blog.show', $post->slug))
      $canChangeStatus   bool   Gate::allows('changeStatus', $post)
      $canDelete         bool   Gate::allows('delete', $post)

    Writes: PUT admin.blog-posts.update {post} (multipart, intent); POST admin.blog-posts.unpublish / .archive {post};
    DELETE admin.blog-posts.destroy {post}; GET admin.blog-posts.stats {post} (link).
--}}

@php
    use App\Enums\Cms\ContentStatus;
    use Illuminate\Support\Facades\Route;

    $statusValue = $post->status instanceof \BackedEnum ? $post->status->value : (string) $post->status;
    $isPublished = $statusValue === ContentStatus::Published->value;
    $isScheduled = $statusValue === ContentStatus::Scheduled->value;
    $canChange = (bool) ($canChangeStatus ?? auth()->user()?->can('blog_posts.change_status'));
    $canRemove = (bool) ($canDelete ?? auth()->user()?->can('blog_posts.delete'));
    $canReports = (bool) auth()->user()?->can('blog_posts.view_reports') && Route::has('admin.blog-posts.stats');
@endphp

@section('header')
    <x-ui.page-header :title="$post->title" :subtitle="'/blog/'.$post->slug" icon="newspaper" :back="route('admin.blog-posts.index')">
        <div class="mt-2 flex flex-wrap items-center gap-2">
            @include('admin.marketing.partials.enum-badge', ['value' => $post->status])
            @if ($post->is_featured)
                <x-ui.badge color="amber" size="sm" icon="star">Featured</x-ui.badge>
            @endif
            @if ($post->reading_minutes)
                <x-ui.badge color="slate" size="sm" icon="clock">{{ app_number((int) $post->reading_minutes) }} min read</x-ui.badge>
            @endif
        </div>

        <x-slot:actions>
            @if ($isPublished && Route::has('site.blog.show'))
                <x-ui.button variant="ghost" icon="arrow-top-right-on-square" :href="route('site.blog.show', $post->slug)" target="_blank" rel="noopener">View live</x-ui.button>
            @endif

            @if ($canReports)
                <x-ui.button variant="secondary" icon="chart-bar" :href="route('admin.blog-posts.stats', $post)">
                    {{ app_number((int) ($post->views_count ?? 0)) }} views
                </x-ui.button>
            @endif

            @if ($canChange && ($isPublished || $isScheduled))
                <x-ui.confirm
                    :action="route('admin.blog-posts.unpublish', $post)"
                    method="POST"
                    :title="($isScheduled ? 'Cancel the schedule of ' : 'Unpublish ').$post->title.'?'"
                    :message="$isScheduled ? 'The post goes back to draft and will not publish itself.' : 'The post returns to draft and its address returns 404. Its first publish date is kept.'"
                    :confirm-label="$isScheduled ? 'Back to draft' : 'Unpublish'"
                    variant="warning"
                    icon="eye-slash"
                >
                    <x-slot:trigger>
                        <x-ui.button variant="secondary" icon="eye-slash">{{ $isScheduled ? 'Unschedule' : 'Unpublish' }}</x-ui.button>
                    </x-slot:trigger>
                </x-ui.confirm>
            @endif

            @if ($canChange && $statusValue !== ContentStatus::Archived->value)
                <x-ui.confirm
                    :action="route('admin.blog-posts.archive', $post)"
                    method="POST"
                    :title="'Archive '.$post->title.'?'"
                    message="Archiving retires the post from the website without deleting anything."
                    confirm-label="Archive"
                    variant="warning"
                    icon="inbox-stack"
                >
                    <x-slot:trigger>
                        <x-ui.icon-button icon="inbox-stack" variant="secondary" label="Archive post" />
                    </x-slot:trigger>
                </x-ui.confirm>
            @endif

            @if ($canRemove)
                <x-ui.confirm
                    :action="route('admin.blog-posts.destroy', $post)"
                    :title="'Delete '.$post->title.'?'"
                    message="The post moves to the trash. Its view history is kept and its address stays reserved."
                    confirm-label="Delete post"
                >
                    <x-slot:trigger>
                        <x-ui.icon-button icon="trash" variant="danger" label="Delete post" />
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
        <form id="post-form" method="POST" action="{{ route('admin.blog-posts.update', $post) }}" enctype="multipart/form-data" class="space-y-6">
            @csrf
            @method('PUT')

            @include('admin.marketing.partials.form-errors')
            @include('admin.blog-posts.partials.form', ['post' => $post])
            @include('admin.marketing.partials.save-bar', ['cancel' => route('admin.blog-posts.index'), 'submitLabel' => 'Save', 'record' => $post])
        </form>
    </div>
@endsection
