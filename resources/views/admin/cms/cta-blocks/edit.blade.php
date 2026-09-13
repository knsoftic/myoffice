@extends('layouts.admin')

@section('title', 'Edit CTA block')

{{--
    CTA block editor — admin.website.cta-blocks.edit (phase-03 §7.4, §8.11, §6.13).

    Controller variables (Admin\Cms\CtaBlockController@edit):
      $block           App\Models\Cms\CtaBlock
      $background      ?App\Models\Cms\MediaAsset
      $usage           Collection<array{type: string, id: int, label: string, detail: ?string, url?: ?string}>
      $variantOptions  array<string, string>
      $styleOptions    array<string, string>
      $canEdit         bool     CtaBlockPolicy::update
      $canChangeKey    bool     CtaBlockPolicy::changeKey — `key` is immutable once a section references it
      $mediaLibrary    optional picker library (SectionController::mediaLibrary() shape)

    Writes:
      PUT    admin.website.cta-blocks.update  {ctaBlock}  the form fields (see cta-blocks/partials/form)
      POST   admin.website.cta-blocks.toggle  {ctaBlock}  status
      DELETE admin.website.cta-blocks.destroy {ctaBlock}  offered only while unused
    A CTA block is status-gated and live (§2.15): saving a published block changes every page that
    places it at once, which the save bar says.
--}}

@php
    use App\Enums\Cms\ContentStatus;

    $ctaBlock = $block;
    $backgroundAsset = $background ?? null;
    $usage = collect($usage ?? []);
    $canEdit = (bool) ($canEdit ?? false);
    $canToggle = auth()->user()?->can('website_cta_blocks.change_status') ?? false;
    $canDelete = auth()->user()?->can('website_cta_blocks.delete') ?? false;
    $isPublished = $ctaBlock->status === ContentStatus::Published;
    $inUse = (int) $ctaBlock->usage_count > 0 || $usage->isNotEmpty();
@endphp

@section('header')
    <x-ui.page-header
        :title="$ctaBlock->name"
        :subtitle="'Key: '.$ctaBlock->key"
        icon="megaphone"
        :back="route('admin.website.cta-blocks.index')"
        :badge="$ctaBlock->status->label()"
        :badge-color="$ctaBlock->status->color()"
    >
        <x-slot:actions>
            @if ($canToggle)
                <form method="POST" action="{{ route('admin.website.cta-blocks.toggle', $ctaBlock) }}">
                    @csrf
                    <input type="hidden" name="status" value="{{ $isPublished ? ContentStatus::Draft->value : ContentStatus::Published->value }}">
                    <x-ui.button type="submit" variant="secondary" :icon="$isPublished ? 'eye-slash' : 'check-circle'">{{ $isPublished ? 'Unpublish' : 'Publish' }}</x-ui.button>
                </form>
            @endif

            @if ($canDelete)
                @if ($inUse)
                    <span title="Remove it from every section that uses it before deleting.">
                        <x-ui.button variant="danger" icon="trash" :disabled="true">Delete</x-ui.button>
                    </span>
                @else
                    <x-ui.confirm
                        :action="route('admin.website.cta-blocks.destroy', $ctaBlock)"
                        :title="'Delete '.$ctaBlock->name.'?'"
                        message="No section uses it. It moves to the trash."
                        confirm-label="Delete block"
                    />
                @endif
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.cms.partials.scripts')
    @include('admin.cms.partials.media-library-json', ['library' => $mediaLibrary ?? []])

    <div class="grid grid-cols-1 gap-6 2xl:grid-cols-4">
        <div class="2xl:col-span-3" x-data="cmsDirty()" x-on:submit="submitted()">
            <form method="POST" action="{{ route('admin.website.cta-blocks.update', $ctaBlock) }}" class="space-y-6">
                @csrf
                @method('PUT')

                @if ($errors->any())
                    <div class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/25" role="alert">
                        <p class="font-semibold">The block was not saved.</p>
                        <p class="mt-0.5">{{ $errors->first() }}</p>
                    </div>
                @endif

                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200/70 sm:p-5 dark:bg-slate-900 dark:ring-slate-800">
                    @include('admin.cms.cta-blocks.partials.form', [
                        'ctaBlock' => $ctaBlock,
                        'backgroundAsset' => $backgroundAsset,
                        'keyLocked' => isset($canChangeKey) ? ! $canChangeKey : $inUse,
                        'readonly' => ! $canEdit,
                    ])
                </div>

                @if ($canEdit)
                    <div class="sticky bottom-0 z-20 -mx-4 flex flex-col gap-3 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:flex-row sm:items-center sm:rounded-xl sm:border sm:shadow-lg dark:border-slate-800 dark:bg-slate-900/95">
                        <p class="flex-1 text-xs text-slate-500 dark:text-slate-400">
                            <span x-show="dirty" x-cloak class="font-semibold text-amber-700 dark:text-amber-400">Unsaved changes. </span>
                            @if ($isPublished && $inUse)
                                This block is live: saving changes it on every page that places it, immediately.
                            @elseif ($isPublished)
                                This block is published but not placed anywhere yet.
                            @else
                                This block is a draft and renders nothing until it is published.
                            @endif
                        </p>
                        <x-ui.button variant="secondary" :href="route('admin.website.cta-blocks.index')">Cancel</x-ui.button>
                        <x-ui.button type="submit" icon="check">Save block</x-ui.button>
                    </div>
                @endif
            </form>
        </div>

        <div>
            <x-ui.card title="Where it is used" icon="link" :padded="false">
                @include('admin.cms.partials.usage-list', ['usage' => $usage, 'emptyTitle' => 'Not placed anywhere', 'emptyMessage' => 'Add a Call to action section and choose this block.'])
            </x-ui.card>
        </div>
    </div>
@endsection
