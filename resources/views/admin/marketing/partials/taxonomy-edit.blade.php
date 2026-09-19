{{--
    The full editor of a category that carries SEO — service, portfolio and blog categories (phase-04 §8.1,
    §8.13, D23). Tags and technologies have no SEO and are edited in the list's dialog instead.

    Each edit view sets `$taxonomy` (the same config array as the list) and includes this partial.

    Controller variables (the edit action of the three category controllers):
      $term           the category model, image relation eager-loaded
      $seoMeta        ?App\Models\Cms\SeoMeta                SeoService::meta($term)  (optional — read here when absent)
      $seoInherited   ?App\Services\Cms\Data\SeoPayload       SeoService::for($term)   (optional)
      $publicUrl      ?string                                  the category's public address, when it has one
      $mediaLibrary   optional picker library (image + OG image pickers)
      $maxUploadMb    optional int

    Writes: PUT admin.{resource}.update {term} — the taxonomy fields plus seo[...] (multipart).
--}}

@php
    use Illuminate\Support\Facades\Route;

    $t = array_merge(['resource' => 'service-categories', 'module' => 'service_categories', 'title' => 'Categories', 'icon' => 'folder', 'noun' => 'category'], $taxonomy ?? []);
    $t['maxUploadMb'] = $maxUploadMb ?? null;
    $indexUrl = route('admin.'.$t['resource'].'.index');
    $canEdit = (bool) auth()->user()?->can($t['module'].'.edit');
@endphp

@section('title', 'Edit '.$term->name)

@section('header')
    <x-ui.page-header :title="$term->name" :subtitle="$t['title']" :icon="$t['icon']" :back="$indexUrl">
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <x-ui.badge :color="$term->is_active ? 'emerald' : 'slate'" size="sm" :dot="true">{{ $term->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
            <span class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $term->slug }}</span>
        </div>

        <x-slot:actions>
            @if (filled($publicUrl ?? null) && $term->is_active)
                <x-ui.button variant="ghost" icon="arrow-top-right-on-square" :href="$publicUrl" target="_blank" rel="noopener">View on website</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.cms.partials.scripts')
    @include('admin.cms.partials.media-library-json', ['library' => $mediaLibrary ?? []])

    <div x-data="cmsDirty()" x-on:submit="submitted()">
        <form id="term-form" method="POST" action="{{ route('admin.'.$t['resource'].'.update', $term) }}" enctype="multipart/form-data" class="space-y-6">
            @csrf
            @method('PUT')

            @include('admin.marketing.partials.form-errors')

            <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
                <x-ui.card :title="ucfirst($t['noun']).' details'" class="xl:col-span-1">
                    @include('admin.marketing.partials.taxonomy-fields', ['taxonomy' => $t, 'term' => $term, 'idSuffix' => 'page', 'errorsFor' => true])
                </x-ui.card>

                <x-ui.card title="Search engines and sharing" subtitle="Stored once in the SEO store — empty fields inherit sensible defaults." class="xl:col-span-2">
                    <x-cms.seo-fields :model="$term" :seo="$seoMeta ?? null" :inherited="$seoInherited ?? null" :display-url="$publicUrl ?? url('/')" :readonly="! $canEdit" />
                </x-ui.card>
            </div>

            @if ($canEdit)
                @include('admin.marketing.partials.save-bar', ['cancel' => $indexUrl, 'submitLabel' => 'Save '.$t['noun'], 'record' => $term])
            @endif
        </form>
    </div>
@endsection
