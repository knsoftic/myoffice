@extends('layouts.admin')

@section('title', 'CTA blocks')

{{--
    CTA blocks — admin.website.cta-blocks.index (phase-03 §7.4, §8.11; requirement §100).

    Controller variables (Admin\Cms\CtaBlockController@index):
      $blocks          LengthAwarePaginator<App\Models\Cms\CtaBlock>
      $backgrounds     Collection<int, MediaAsset>   background images of the blocks on this page, keyed by id
      $statusOptions   array<string, string>
      $variantOptions  array<string, string>
      $styleOptions    array<string, string>
      $filters         array<string, mixed>
      $can             array{create: bool, edit: bool, toggle: bool, delete: bool}
      $mediaLibrary    optional picker library for the create dialog (SectionController::mediaLibrary() shape)
    Query string (CmsListRequest): search (name, key, heading), status, variant, unused (1 = unused, 0 = in use), page.

    Writes:
      POST   admin.website.cta-blocks.store               the form fields (no status: a new block is a draft) + _form = create
      POST   admin.website.cta-blocks.toggle  {ctaBlock}  status (published|draft)
      DELETE admin.website.cta-blocks.destroy {ctaBlock}  refused while usage_count > 0 (policy + service)
      GET    admin.website.cta-blocks.usage   {ctaBlock}  JSON {"usage": [{type, id, label, detail, url?}]}
--}}

@php
    use App\Enums\Cms\ContentStatus;
    use App\Enums\Cms\CtaVariant;

    $can = array_merge(['create' => false, 'edit' => false, 'toggle' => false, 'delete' => false], $can ?? []);
    $canCreate = (bool) $can['create'];
    $canEdit = (bool) $can['edit'];
    $canToggle = (bool) $can['toggle'];
    $canDelete = (bool) $can['delete'];
    $ctaBlocks = $blocks;
    $backgrounds = collect($backgrounds ?? []);
    $mediaService = app(\App\Services\Cms\MediaService::class);
    $filtered = ! empty($filters ?? []);
@endphp

@section('header')
    <x-ui.page-header title="CTA blocks" subtitle="Reusable calls to action. Edit a block once and it changes everywhere it is placed." icon="megaphone">
        <x-slot:actions>
            @if ($canCreate)
                <x-ui.button icon="plus" x-on:click="$dispatch('open-modal', 'create-cta')">New CTA block</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.cms.partials.scripts')
    @include('admin.cms.partials.media-library-json', ['library' => $mediaLibrary ?? []])

    <div
        x-data="{ navigating: false }"
        x-on:submit.window="if ($event.target?.method === 'get') navigating = true"
        class="space-y-4"
    >
        <x-ui.filter-bar placeholder="Search name, key or heading…" :reset="route('admin.website.cta-blocks.index')">
            <x-ui.form.select name="status" :options="$statusOptions ?? ContentStatus::options()" :selected="request('status')" placeholder="Any status" size="sm" aria-label="Filter by status" />
            <x-ui.form.select name="variant" :options="$variantOptions ?? CtaVariant::options()" :selected="request('variant')" placeholder="Any layout" size="sm" aria-label="Filter by layout" />
            <x-ui.form.select name="unused" :options="['0' => 'In use', '1' => 'Not used anywhere']" :selected="request('unused')" placeholder="Used or not" size="sm" aria-label="Filter by usage" />
        </x-ui.filter-bar>

        <div x-show="navigating" x-cloak class="card-grid-3" aria-hidden="true">
            <x-ui.skeleton variant="card" :count="3" />
        </div>

        <div x-show="! navigating">
            @if ($ctaBlocks->isEmpty())
                <x-ui.card>
                    <x-ui.empty-state
                        icon="megaphone"
                        :title="$filtered ? 'No CTA blocks match those filters' : 'No CTA blocks yet'"
                        :message="$filtered ? 'Clear the filters to see every block.' : 'Create a block, then place it with a Call to action section.'"
                    >
                        <x-slot:action>
                            @if ($filtered)
                                <x-ui.button variant="secondary" :href="route('admin.website.cta-blocks.index')">Clear filters</x-ui.button>
                            @elseif ($canCreate)
                                <x-ui.button icon="plus" x-on:click="$dispatch('open-modal', 'create-cta')">New CTA block</x-ui.button>
                            @endif
                        </x-slot:action>
                    </x-ui.empty-state>
                </x-ui.card>
            @else
                <div class="card-grid-3">
                    @foreach ($ctaBlocks as $block)
                        @php
                            $variant = $block->variant instanceof CtaVariant ? $block->variant : CtaVariant::tryFrom((string) $block->variant);
                            $isPublished = $block->status === ContentStatus::Published;
                            $background = $block->background_media_id ? $backgrounds->get((int) $block->background_media_id) : null;
                            $backgroundUrl = $background ? $mediaService->url($background, 640) : null;
                            $hasColour = is_string($block->background_color) && preg_match('/^#[0-9a-f]{6}$/i', $block->background_color) === 1;
                            $solid = in_array($variant, [CtaVariant::Banner, CtaVariant::Split, CtaVariant::FullWidth], true);
                        @endphp

                        <div class="flex h-full flex-col rounded-xl bg-white shadow-sm ring-1 ring-slate-200/70 dark:bg-slate-900 dark:ring-slate-800">
                            {{-- Small-scale preview --}}
                            <div
                                @class([
                                    'relative m-3 overflow-hidden rounded-lg bg-cover bg-center p-4',
                                    'text-center' => in_array($variant, [CtaVariant::Banner, CtaVariant::FullWidth], true),
                                    'text-white' => $solid || $backgroundUrl,
                                    'bg-brand-600 dark:bg-brand-500' => $solid && ! $hasColour && ! $backgroundUrl,
                                    'bg-slate-50 text-slate-900 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-white dark:ring-slate-700' => ! $solid && ! $backgroundUrl,
                                ])
                                @if ($backgroundUrl) style="background-image: linear-gradient(rgba(15,23,42,.55), rgba(15,23,42,.55)), url('{{ $backgroundUrl }}')"
                                @elseif ($solid && $hasColour) style="background-color: {{ $block->background_color }}"
                                @endif
                                aria-hidden="true"
                            >
                                <p class="line-clamp-2 text-sm font-semibold">{{ $block->heading }}</p>
                                @if (filled($block->subheading))
                                    <p class="mt-0.5 line-clamp-1 text-xs opacity-90">{{ $block->subheading }}</p>
                                @endif
                                <div @class(['mt-2 flex flex-wrap gap-1.5', 'justify-center' => in_array($variant, [CtaVariant::Banner, CtaVariant::FullWidth], true)])>
                                    @if (filled($block->primary_label))
                                        <span class="rounded-md bg-white px-2 py-0.5 text-2xs font-semibold text-slate-900 shadow-sm">{{ $block->primary_label }}</span>
                                    @endif
                                    @if (filled($block->secondary_label))
                                        <span class="rounded-md border border-current px-2 py-0.5 text-2xs font-semibold">{{ $block->secondary_label }}</span>
                                    @endif
                                </div>
                            </div>

                            <div class="flex flex-1 flex-col px-4 pb-4">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <h3 class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $block->name }}</h3>
                                        <p class="font-mono text-2xs text-slate-400 dark:text-slate-500">{{ $block->key }}</p>
                                    </div>
                                    <div class="flex shrink-0 flex-col items-end gap-1">
                                        <x-ui.badge :color="$block->status->color()" size="sm" :dot="true">{{ $block->status->label() }}</x-ui.badge>
                                        @if ($variant)
                                            <x-ui.badge :color="$variant->color()" variant="outline" size="sm">{{ $variant->label() }}</x-ui.badge>
                                        @endif
                                    </div>
                                </div>

                                {{-- Usage popover --}}
                                <div class="relative mt-3" x-data="cmsFetchList(@js(['url' => route('admin.website.cta-blocks.usage', $block), 'key' => 'usage']))" x-on:click.outside="open = false">
                                    @if ((int) $block->usage_count > 0)
                                        <button type="button" x-on:click="open = ! open; load()" class="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400" x-bind:aria-expanded="open ? 'true' : 'false'">
                                            <x-ui.icon name="link" class="h-3.5 w-3.5" />
                                            Used in {{ (int) $block->usage_count }} {{ \Illuminate\Support\Str::plural('place', (int) $block->usage_count) }}
                                        </button>
                                        <div x-show="open" x-cloak class="absolute left-0 top-6 z-dropdown w-72 rounded-xl bg-white p-3 text-xs shadow-dropdown ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
                                            <p x-show="loading" class="text-slate-500">Loading…</p>
                                            <p x-show="error" x-text="error" class="text-rose-600 dark:text-rose-400"></p>
                                            <ul class="space-y-1.5">
                                                <template x-for="place in items" :key="place.type + place.id">
                                                    <li class="flex items-start gap-2">
                                                        <x-ui.icon name="view-columns" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" />
                                                        <span>
                                                            <template x-if="place.url"><a x-bind:href="place.url" class="font-medium text-slate-800 hover:underline dark:text-slate-100" x-text="place.label"></a></template>
                                                            <template x-if="! place.url"><span class="font-medium text-slate-800 dark:text-slate-100" x-text="place.label"></span></template>
                                                            <span class="block text-slate-500 dark:text-slate-400" x-text="place.detail"></span>
                                                        </span>
                                                    </li>
                                                </template>
                                            </ul>
                                        </div>
                                    @else
                                        <p class="text-xs text-slate-500 dark:text-slate-400">Not used anywhere yet</p>
                                    @endif
                                </div>

                                <div class="mt-auto flex flex-wrap items-center gap-1 border-t border-slate-100 pt-3 dark:border-slate-800">
                                    @can('website_cta_blocks.view')
                                        <x-ui.button size="sm" variant="secondary" :icon="$canEdit ? 'pencil' : 'eye'" :href="route('admin.website.cta-blocks.edit', $block)">{{ $canEdit ? 'Edit' : 'View' }}</x-ui.button>
                                    @endcan

                                    @if ($canToggle)
                                        <form method="POST" action="{{ route('admin.website.cta-blocks.toggle', $block) }}">
                                            @csrf
                                            <input type="hidden" name="status" value="{{ $isPublished ? ContentStatus::Draft->value : ContentStatus::Published->value }}">
                                            <x-ui.button type="submit" size="sm" variant="ghost" :icon="$isPublished ? 'eye-slash' : 'check-circle'">{{ $isPublished ? 'Unpublish' : 'Publish' }}</x-ui.button>
                                        </form>
                                    @endif

                                    @if ($canDelete)
                                        <div class="ml-auto">
                                            @if ((int) $block->usage_count > 0)
                                                <x-ui.icon-button icon="trash" size="sm" :label="'In use in '.(int) $block->usage_count.' places — remove it from those sections first'" :disabled="true" />
                                            @else
                                                <x-ui.confirm
                                                    :action="route('admin.website.cta-blocks.destroy', $block)"
                                                    :title="'Delete '.$block->name.'?'"
                                                    message="No section uses it. It moves to the trash."
                                                    confirm-label="Delete block"
                                                >
                                                    <x-slot:trigger>
                                                        <x-ui.icon-button icon="trash" variant="danger" size="sm" label="Delete {{ $block->name }}" />
                                                    </x-slot:trigger>
                                                </x-ui.confirm>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <x-ui.card :compact="true" class="mt-4">
                    <x-ui.pagination-summary :paginator="$ctaBlocks" label="CTA blocks" />
                </x-ui.card>
            @endif
        </div>
    </div>

    @if ($canCreate)
        <x-ui.modal name="create-cta" title="New CTA block" icon="megaphone" size="full" :show="old('_form') === 'create' && $errors->any()">
            <form id="create-cta-form" method="POST" action="{{ route('admin.website.cta-blocks.store') }}">
                @csrf
                <input type="hidden" name="_form" value="create">

                @if (old('_form') === 'create' && $errors->any())
                    <div class="mb-4 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/25" role="alert">{{ $errors->first() }}</div>
                @endif

                @include('admin.cms.cta-blocks.partials.form', ['ctaBlock' => null, 'backgroundAsset' => null, 'keyLocked' => false, 'readonly' => false])
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'create-cta')">Cancel</x-ui.button>
                <x-ui.button type="submit" form="create-cta-form" icon="check">Create block</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
@endsection
