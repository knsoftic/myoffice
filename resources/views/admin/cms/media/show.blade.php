@extends('layouts.admin')

@section('title', 'Media file')

{{--
    Media detail — admin.website.media.show (phase-03 §7.5, §8.13 detail drawer, §2.13).

    Controller variables (Admin\Cms\MediaController@show):
      $asset     App\Models\Cms\MediaAsset
      $card      array   the same card shape as the library grid (url, thumbnail_url, …)
      $variants  array   the asset's `variants` map: width => {path, format, size_bytes, width, height, webp: {…}}
      $usage     Collection<array{type: string, id: int, label: string, detail: ?string}>   MediaService::usage()
      $can       array{edit: bool, delete: bool}   delete is false while anything uses the file

    Writes:
      PUT    admin.website.media.update     {asset}  alt_text, title, caption (website_media.edit)
      POST   admin.website.media.regenerate {asset}  (website_media.edit)
      DELETE admin.website.media.destroy    {asset}  reason (optional) — refused while in use:
             MediaPolicy::delete and MediaService::delete both refuse, so the button is disabled here
             and lists the places that still use the file.
    GET admin.website.media.usage {asset} is the JSON form of $usage for other screens.
--}}

@php
    use App\Enums\Cms\MediaCollection;
    use App\Enums\Cms\MediaProcessingStatus;
    use Illuminate\Support\Facades\Storage;

    $mediaService = app(\App\Services\Cms\MediaService::class);
    $usage = collect($usage ?? []);
    $can = array_merge(['edit' => false, 'delete' => false], $can ?? []);
    $canEdit = (bool) $can['edit'];
    $canDelete = (bool) $can['delete'] || (auth()->user()?->can('website_media.delete') ?? false);
    $isVideo = $asset->isVideo();
    $status = $asset->derivatives_status instanceof MediaProcessingStatus ? $asset->derivatives_status : MediaProcessingStatus::tryFrom((string) $asset->derivatives_status);
    $collection = $asset->collection instanceof MediaCollection ? $asset->collection : MediaCollection::tryFrom((string) $asset->collection);
    $inUse = (int) $asset->usage_count > 0 || $usage->isNotEmpty();
    $creator = $asset->relationLoaded('creator') ? $asset->creator : null;
    $variantSource = is_array($variants ?? null) ? $variants : (is_array($asset->variants) ? $asset->variants : []);
    $originalUrl = $mediaService->url($asset);
    $previewUrl = $isVideo ? $originalUrl : $mediaService->url($asset, 960);
    $humanSize = static fn (int $bytes): string => $bytes >= 1048576 ? app_number($bytes / 1048576, 2).' MB' : app_number(max(1, (int) round($bytes / 1024))).' KB';

    $disk = Storage::disk((string) ($asset->disk ?: 'public'));
    $variants = collect($variantSource)
        ->map(static function ($variant, $width) use ($disk): array {
            $variant = is_array($variant) ? $variant : [];
            $webp = is_array($variant['webp'] ?? null) ? $variant['webp'] : null;

            return [
                'width' => (int) ($variant['width'] ?? $width),
                'height' => isset($variant['height']) ? (int) $variant['height'] : null,
                'format' => (string) ($variant['format'] ?? ''),
                'size' => (int) ($variant['size_bytes'] ?? 0),
                'url' => filled($variant['path'] ?? null) ? $disk->url((string) $variant['path']) : null,
                'webp_size' => $webp ? (int) ($webp['size_bytes'] ?? 0) : null,
                'webp_url' => $webp && filled($webp['path'] ?? null) ? $disk->url((string) $webp['path']) : null,
            ];
        })
        ->sortBy('width')
        ->values();

    $placeIcon = static fn (string $type): string => match ($type) {
        'page' => 'document',
        'cta_block' => 'megaphone',
        'seo_meta' => 'magnifying-glass',
        'faq' => 'question-mark-circle',
        default => 'view-columns',
    };
@endphp

@section('header')
    <x-ui.page-header
        :title="$asset->title ?: $asset->original_name"
        :subtitle="$asset->mime_type.' · '.$humanSize((int) $asset->size_bytes).($asset->width ? ' · '.$asset->width.'×'.$asset->height.' px' : '')"
        :icon="$isVideo ? 'video-camera' : 'photo'"
        :back="route('admin.website.media.index')"
    >
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="arrow-top-right-on-square" :href="$originalUrl" target="_blank" rel="noopener">Open original</x-ui.button>

            @if ($canEdit && ! $isVideo)
                <form method="POST" action="{{ route('admin.website.media.regenerate', $asset) }}">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" icon="arrow-path">Regenerate sizes</x-ui.button>
                </form>
            @endif

            @if ($canDelete)
                @if ($inUse)
                    <span title="In use — remove it from the places listed below first.">
                        <x-ui.button variant="danger" icon="trash" :disabled="true">Delete</x-ui.button>
                    </span>
                @else
                    <x-ui.confirm
                        :action="route('admin.website.media.destroy', $asset)"
                        id="delete-media-form"
                        title="Delete this file?"
                        message="Nothing uses it. The library entry moves to the trash; the stored file is kept so nothing live can lose an image."
                        confirm-label="Delete file"
                    >
                        <div class="mt-4">
                            <label for="media-delete-reason" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Reason <span class="font-normal text-slate-400">(optional, recorded in the audit trail)</span></label>
                            <input id="media-delete-reason" type="text" name="reason" form="delete-media-form" maxlength="255" class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-rose-500 focus:ring-rose-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                        </div>
                    </x-ui.confirm>
                @endif
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.cms.partials.scripts')

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-5">
        <div class="space-y-6 lg:col-span-3">
            <div class="overflow-hidden rounded-xl bg-slate-100 ring-1 ring-slate-200 dark:bg-slate-950 dark:ring-slate-800">
                @if ($isVideo)
                    <video src="{{ $previewUrl }}" controls muted preload="metadata" class="mx-auto max-h-[60vh] w-full bg-black"></video>
                @else
                    <img src="{{ $previewUrl }}" alt="{{ $asset->alt_text }}" class="mx-auto max-h-[60vh] w-auto max-w-full object-contain">
                @endif
            </div>

            @if ($status === MediaProcessingStatus::Failed)
                <div class="flex items-start gap-2 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/25" role="alert">
                    <x-ui.icon name="x-circle" class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>Generating the smaller sizes failed{{ filled($asset->failure_reason) ? ': '.$asset->failure_reason : '' }}. The original is still served, with no responsive sizes.</span>
                </div>
            @elseif ($status && in_array($status, [MediaProcessingStatus::Pending, MediaProcessingStatus::Processing], true))
                <p class="flex items-center gap-2 text-sm text-amber-700 dark:text-amber-400">
                    <x-ui.icon name="clock" class="h-4 w-4" /> Smaller sizes are being generated. Until then the original is served.
                </p>
            @endif

            {{-- Variants --}}
            @unless ($isVideo)
                <x-ui.card title="Generated sizes" :subtitle="$asset->profile ? 'Profile: '.$asset->profile->label() : null" icon="squares-2x2" :padded="false">
                    @if ($variants->isEmpty())
                        <x-ui.empty-state :compact="true" icon="squares-2x2" title="No generated sizes" message="Only the original exists. Regenerate once processing is available." />
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-500 dark:border-slate-800 dark:text-slate-400">
                                        <th scope="col" class="px-4 py-2">Width</th>
                                        <th scope="col" class="px-4 py-2">Format</th>
                                        <th scope="col" class="px-4 py-2 text-right">Weight</th>
                                        <th scope="col" class="px-4 py-2 text-right">WebP</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    @foreach ($variants as $variant)
                                        <tr class="text-slate-700 dark:text-slate-300">
                                            <td class="px-4 py-2 tabular-nums">
                                                @if ($variant['url'])
                                                    <a href="{{ $variant['url'] }}" target="_blank" rel="noopener" class="text-brand-600 hover:underline dark:text-brand-400">{{ $variant['width'] }} px</a>
                                                @else
                                                    {{ $variant['width'] }} px
                                                @endif
                                            </td>
                                            <td class="px-4 py-2 uppercase">{{ $variant['format'] ?: '—' }}</td>
                                            <td class="px-4 py-2 text-right tabular-nums">{{ $variant['size'] > 0 ? $humanSize($variant['size']) : '—' }}</td>
                                            <td class="px-4 py-2 text-right tabular-nums">
                                                @if ($variant['webp_url'])
                                                    <a href="{{ $variant['webp_url'] }}" target="_blank" rel="noopener" class="text-brand-600 hover:underline dark:text-brand-400">{{ $variant['webp_size'] ? $humanSize($variant['webp_size']) : 'open' }}</a>
                                                @else
                                                    —
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-ui.card>
            @endunless
        </div>

        <div class="space-y-6 lg:col-span-2">
            {{-- Details --}}
            <x-ui.card title="Details" icon="pencil">
                <form method="POST" action="{{ route('admin.website.media.update', $asset) }}" class="space-y-4">
                    @csrf
                    @method('PUT')

                    @unless ($isVideo)
                        <div>
                            <x-ui.form.textarea
                                name="alt_text"
                                id="media-alt-text"
                                label="Alt text"
                                :value="$asset->alt_text"
                                :rows="2"
                                maxlength="255"
                                :readonly="! $canEdit"
                                help="Describe what the image shows for people who cannot see it. Required before a section using it can be published."
                            />
                            @include('admin.cms.partials.length-meter', ['for' => 'media-alt-text', 'max' => 255])
                        </div>
                    @endunless

                    <x-ui.form.input name="title" label="Title" :value="$asset->title" maxlength="191" :readonly="! $canEdit" help="Only shown in the admin." />
                    <x-ui.form.textarea name="caption" label="Caption" :value="$asset->caption" :rows="2" maxlength="500" :readonly="! $canEdit" />

                    @if ($canEdit)
                        <div class="flex justify-end">
                            <x-ui.button type="submit" size="sm" icon="check">Save details</x-ui.button>
                        </div>
                    @endif
                </form>

                <dl class="mt-5 grid grid-cols-2 gap-x-4 gap-y-2 border-t border-slate-100 pt-4 text-xs dark:border-slate-800">
                    <dt class="text-slate-500 dark:text-slate-400">Original name</dt>
                    <dd class="truncate text-right text-slate-800 dark:text-slate-200" title="{{ $asset->original_name }}">{{ $asset->original_name }}</dd>
                    <dt class="text-slate-500 dark:text-slate-400">Collection</dt>
                    <dd class="text-right">@if ($collection)<x-ui.badge :color="$collection->color()" size="sm">{{ $collection->label() }}</x-ui.badge>@endif</dd>
                    <dt class="text-slate-500 dark:text-slate-400">Processing</dt>
                    <dd class="text-right">@if ($status)<x-ui.badge :color="$status->color()" size="sm">{{ $status->label() }}</x-ui.badge>@endif</dd>
                    @if ($asset->duration_seconds)
                        <dt class="text-slate-500 dark:text-slate-400">Duration</dt>
                        <dd class="text-right tabular-nums text-slate-800 dark:text-slate-200">{{ \Carbon\CarbonInterval::seconds((int) $asset->duration_seconds)->cascade()->forHumans(['short' => true]) }}</dd>
                    @endif
                    <dt class="text-slate-500 dark:text-slate-400">Uploaded</dt>
                    <dd class="text-right text-slate-800 dark:text-slate-200">{{ app_datetime($asset->created_at) }}@if ($creator)<span class="block text-slate-500">by {{ $creator->name }}</span>@endif</dd>
                </dl>
            </x-ui.card>

            {{-- Usage --}}
            <x-ui.card title="Where it is used" :subtitle="$inUse ? 'It cannot be deleted while anything uses it.' : null" icon="link" :padded="false">
                @include('admin.cms.partials.usage-list', ['usage' => $usage, 'emptyTitle' => $inUse ? 'Usage is being recounted' : 'Not used anywhere', 'emptyMessage' => $inUse ? 'The stored count says it is in use; the nightly recount will confirm.' : 'It can be deleted safely.'])
            </x-ui.card>
        </div>
    </div>
@endsection
