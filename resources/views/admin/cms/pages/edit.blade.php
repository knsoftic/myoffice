@extends('layouts.admin')

@section('title', 'Edit page')

{{--
    Page editor — admin.website.pages.edit (phase-03 §7.3, §8.10; requirement §101, §105).

    Controller variables (Admin\Cms\PageController@edit):
      $page              App\Models\Cms\Page
      $banner            ?App\Models\Cms\MediaAsset
      $sections          Collection<WebsiteSection>     the page's sections when layout = sections
      $layoutOptions     array<string, string>
      $templateOptions   array<string, string>          PageTemplate::options()
      $reservedSlugs     list<string>
      $seoMeta           ?App\Models\Cms\SeoMeta
      $seoInherited      App\Services\Cms\Data\SeoPayload   SeoService::for($page) — shown in grey as the inherited value
      $seoCompleteness   int
      $menuItems         Collection<MenuItem>           menu items pointing at this page
      $revisionCount     int
      $can               array{edit, changeSlug, publish, delete, duplicate, revisions, seo: bool}
      $mediaLibrary      optional picker library (SectionController::mediaLibrary() shape)

    Writes:
      PUT    admin.website.pages.update      {page}  the form fields + publish (0 = save draft, 1 = save and publish;
                                                     the controller re-checks pages.change_status)
      POST   admin.website.pages.publish     {page}
      POST   admin.website.pages.schedule    {page}  publish_at (datetime-local, in the DISPLAY timezone —
                                                     the controller converts to UTC before storing, D61)
      POST   admin.website.pages.unpublish   {page}  reason (required)
      POST   admin.website.pages.duplicate   {page}
      DELETE admin.website.pages.destroy     {page}  (not offered for a system page)
      GET    admin.website.pages.preview-link {page} JSON {"url": "<signed preview URL>"} — copied to the clipboard
--}}

@php
    use App\Enums\Cms\ContentStatus;
    use Illuminate\Support\Facades\Route as RouteFacade;

    $can = array_merge(['edit' => false, 'changeSlug' => false, 'publish' => false, 'delete' => false, 'duplicate' => false, 'revisions' => false, 'seo' => false], $can ?? []);
    $canEdit = (bool) $can['edit'];
    $canPublish = (bool) $can['publish'];
    $canCreate = (bool) $can['duplicate'];
    $canDelete = (bool) $can['delete'];
    $canLogs = (bool) $can['revisions'];
    $menuItemsCount = collect($menuItems ?? [])->count();
    $isPublished = $page->status === ContentStatus::Published;
    $isScheduled = $page->status === ContentStatus::Scheduled;
    $publisher = $page->relationLoaded('publisher') ? $page->publisher : null;
    $previewUrl = RouteFacade::has('site.preview.page') ? route('site.preview.page', $page) : null;
    $publicUrl = RouteFacade::has('site.page') ? route('site.page', ['slug' => $page->slug]) : url('/'.$page->slug);
    $timezone = (string) setting('localization.timezone', config('app.timezone'));
@endphp

@section('header')
    <x-ui.page-header :title="$page->title" :subtitle="'/'.$page->slug" icon="document" :back="route('admin.website.pages.index')">
        <div class="mt-2 flex flex-wrap items-center gap-2">
            @include('admin.cms.partials.status-badge', [
                'status' => $page->status,
                'unpublished' => $page->has_unpublished_changes,
                'published' => filled($page->published_hash),
            ])
            @if ($page->is_system)
                <x-ui.badge color="slate" size="sm" icon="lock-closed">System page</x-ui.badge>
            @endif
            @if ((int) ($menuItemsCount ?? 0) > 0)
                <x-ui.badge color="indigo" size="sm" icon="bars-3">In {{ (int) $menuItemsCount }} {{ \Illuminate\Support\Str::plural('menu item', (int) $menuItemsCount) }}</x-ui.badge>
            @endif
        </div>

        <x-slot:actions>
            @if ($isPublished)
                <x-ui.button variant="ghost" icon="arrow-top-right-on-square" :href="$publicUrl" target="_blank" rel="noopener">View live</x-ui.button>
            @endif

            @if ($previewUrl)
                <x-ui.button variant="secondary" icon="eye" :href="$previewUrl" target="_blank" rel="noopener">Preview</x-ui.button>
            @endif

            @if (RouteFacade::has('admin.website.pages.preview-link'))
                <div x-data="cmsCopy(@js(['url' => route('admin.website.pages.preview-link', $page)]))">
                    <x-ui.button variant="secondary" icon="share" x-on:click="copy()">
                        <span x-text="copied ? 'Copied' : 'Share preview'">Share preview</span>
                    </x-ui.button>
                </div>
            @endif

            @if ($canLogs)
                <x-ui.icon-button icon="clock" variant="secondary" label="Revisions" :href="route('admin.website.pages.revisions.index', $page)" />
            @endif

            @if ($canCreate)
                <form method="POST" action="{{ route('admin.website.pages.duplicate', $page) }}">
                    @csrf
                    <x-ui.icon-button type="submit" icon="clipboard-document" variant="secondary" label="Duplicate as a new draft" />
                </form>
            @endif

            @if ($canPublish)
                @if (! $isPublished)
                    <x-ui.confirm
                        :action="route('admin.website.pages.schedule', $page)"
                        method="POST"
                        id="schedule-page-form"
                        :title="'Schedule '.$page->title.'?'"
                        message="The page publishes itself at the chosen time, with whatever its draft holds then. It stays off the site until that moment."
                        confirm-label="Schedule"
                        variant="warning"
                        icon="calendar-days"
                    >
                        <x-slot:trigger>
                            <x-ui.button variant="secondary" icon="calendar-days">{{ $isScheduled ? 'Reschedule' : 'Schedule' }}</x-ui.button>
                        </x-slot:trigger>

                        <div class="mt-4">
                            <label for="publish-at" class="block text-xs font-medium text-slate-600 dark:text-slate-300">
                                Publish at <span class="text-rose-500">*</span> <span class="font-normal text-slate-400">({{ $timezone }})</span>
                            </label>
                            <input
                                id="publish-at"
                                type="datetime-local"
                                name="publish_at"
                                form="schedule-page-form"
                                required
                                value="{{ $isScheduled && $page->published_at ? app_datetime($page->published_at, 'Y-m-d\TH:i') : '' }}"
                                class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white"
                            >
                            <x-ui.form.error for="publish_at" />
                        </div>
                    </x-ui.confirm>
                @endif

                @if ($isPublished || $isScheduled)
                    <x-ui.confirm
                        :action="route('admin.website.pages.unpublish', $page)"
                        method="POST"
                        id="unpublish-page-form"
                        :title="'Unpublish '.$page->title.'?'"
                        :message="$isScheduled ? 'The schedule is cancelled and the page stays a draft.' : 'Visitors get a 404 at /'.$page->slug.' and menu items pointing at it disappear. The published version is kept.'"
                        confirm-label="Unpublish"
                        variant="warning"
                        icon="eye-slash"
                    >
                        <x-slot:trigger>
                            <x-ui.button variant="secondary" icon="eye-slash">Unpublish</x-ui.button>
                        </x-slot:trigger>

                        <div class="mt-4">
                            <label for="unpublish-reason" class="block text-xs font-medium text-slate-600 dark:text-slate-300">
                                Reason <span class="text-rose-500">*</span> <span class="font-normal text-slate-400">(recorded in the audit trail)</span>
                            </label>
                            <input id="unpublish-reason" type="text" name="reason" form="unpublish-page-form" required minlength="{{ \App\Http\Requests\Cms\CmsFormRequest::REASON_MIN }}" maxlength="255" class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                        </div>
                    </x-ui.confirm>
                @endif

                @if ($page->has_unpublished_changes || ! $isPublished)
                    <x-ui.confirm
                        :action="route('admin.website.pages.publish', $page)"
                        method="POST"
                        :title="'Publish '.$page->title.'?'"
                        message="The saved draft goes live for every visitor now. Unsaved edits in the form are not included — save them first, or use Save & publish."
                        confirm-label="Publish now"
                        variant="warning"
                        icon="check-circle"
                    >
                        <x-slot:trigger>
                            <x-ui.button icon="check-circle">Publish</x-ui.button>
                        </x-slot:trigger>
                    </x-ui.confirm>
                @endif
            @endif

            @if ($canDelete && ! $page->is_system)
                <x-ui.confirm
                    :action="route('admin.website.pages.destroy', $page)"
                    :title="'Delete '.$page->title.'?'"
                    message="The page moves to the trash and returns 404 to visitors. Menu items pointing at it are disabled, and you are told which. Its sections go to the trash with it."
                    confirm-label="Delete page"
                    :require-text="$page->slug"
                >
                    <x-slot:trigger>
                        <x-ui.icon-button icon="trash" variant="danger" label="Delete page" />
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
        <form id="page-form" method="POST" action="{{ route('admin.website.pages.update', $page) }}" class="space-y-6">
            @csrf
            @method('PUT')

            @if (! $canEdit)
                <div class="flex items-center gap-2 rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    <x-ui.icon name="lock-closed" class="h-4 w-4" /> You can view this page. Editing needs the pages edit permission.
                </div>
            @endif

            @if ($errors->any() && ! $errors->has('publish_at'))
                <div class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/25" role="alert">
                    <p class="font-semibold">Nothing was saved or published.</p>
                    <p class="mt-0.5">{{ $errors->first() }}</p>
                </div>
            @endif

            @include('admin.cms.pages.partials.form', [
                'page' => $page,
                'templates' => $templateOptions ?? null,
                'reservedSlugs' => $reservedSlugs ?? [],
                'seo' => $seoMeta ?? null,
                'seoInherited' => $seoInherited ?? null,
                'bannerAsset' => $banner ?? null,
                'ogAsset' => null,
                'sectionsCount' => collect($sections ?? [])->count(),
                'slugLocked' => ! $can['changeSlug'],
                'readonly' => ! $canEdit,
            ])

            @if ($canEdit)
                <div class="sticky bottom-0 z-20 -mx-4 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-xl sm:border sm:shadow-lg dark:border-slate-800 dark:bg-slate-900/95">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <div class="min-w-0 flex-1 text-xs text-slate-500 dark:text-slate-400">
                            <p x-show="dirty" x-cloak class="font-semibold text-amber-700 dark:text-amber-400">You have unsaved changes.</p>
                            <p>
                                Draft saved {{ app_datetime($page->updated_at) }}.
                                @if ($isPublished && $page->published_at)
                                    Live version published {{ app_datetime($page->published_at) }}@if ($publisher) by {{ $publisher->name }}@endif.
                                @elseif ($isScheduled && $page->published_at)
                                    Scheduled for {{ app_datetime($page->published_at) }}.
                                @else
                                    Not live.
                                @endif
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.button type="submit" name="publish" value="0" variant="secondary" icon="check">Save draft</x-ui.button>
                            @if ($canPublish)
                                <x-ui.button type="submit" name="publish" value="1" icon="check-circle">Save &amp; publish</x-ui.button>
                            @else
                                <span title="You can save drafts; publishing needs the publish permission.">
                                    <x-ui.button icon="check-circle" :disabled="true">Save &amp; publish</x-ui.button>
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        </form>
    </div>

@endsection

@if ($errors->has('publish_at'))
    @push('scripts')
        <script>
            document.addEventListener('alpine:initialized', () => {
                window.Alpine?.store('toasts')?.push({ type: 'error', message: @js('The page was not scheduled: '.$errors->first('publish_at')) });
            });
        </script>
    @endpush
@endif
