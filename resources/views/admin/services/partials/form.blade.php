{{--
    The service editor body, shared by create and edit (phase-04 §8.2, §2.3, §6.4, §6.11).

    @include('admin.services.partials.form', ['service' => $service])   // $service = null on create

    Reads from the including view (controller variables of create / edit):
      $categoryOptions     array<int, string>        id => name (active categories; the current one even if inactive)
      $technologyOptions   array<int, string>        id => name
      $statusOptions       array<string, string>     ContentStatus::options()
      $reservedSlugs       list<string>              SlugGenerator::RESERVED
      $seoMeta, $seoInherited, $publicUrl            SEO tab (optional; see <x-cms.seo-fields>)
      $maxUploadMb         optional int

    Posts (StoreServiceRequest / UpdateServiceRequest, multipart):
      name, slug, service_category_id, short_description, full_description (rich text, sanitised on write),
      icon, image_media_id + image (upload), starting_price (decimal string, never a float), price_note,
      price_visible (0|1), features[] (ordered), technology_ids[], status, is_featured (0|1), sort_order,
      seo[title|meta_description|meta_keywords|canonical_url|robots|og_image_media_id].
      `status` is offered only to a user holding services.change_status; the controller must ignore it otherwise.
--}}

@php
    use App\Enums\Cms\ContentStatus;

    $service = $service ?? null;
    $isNew = $service === null;
    $user = auth()->user();
    $canStatus = (bool) $user?->can('services.change_status');

    $statusValue = $service?->status instanceof \BackedEnum ? $service->status->value : ($service?->status ?? ContentStatus::Draft->value);
    $wasPublished = $service !== null && $statusValue === ContentStatus::Published->value;

    $imageAsset = null;
    foreach (['imageAsset', 'image', 'imageMedia'] as $relationName) {
        if ($service?->relationLoaded($relationName) && $service->getRelation($relationName) instanceof \App\Models\Cms\MediaAsset) {
            $imageAsset = $service->getRelation($relationName);
            break;
        }
    }

    $selectedTechnologies = collect(old('technology_ids', $service?->relationLoaded('technologies') ? $service->technologies->pluck('id')->all() : []))
        ->map(static fn ($id): int => (int) $id)
        ->filter()
        ->values()
        ->all();

    // Open the tab that holds the first failing field, so a refused save never hides its reason.
    $tabFields = [
        'commercial' => ['starting_price', 'price_note', 'price_visible', 'features'],
        'technologies' => ['technology_ids'],
        'seo' => ['seo'],
    ];
    $initialTab = 'content';
    foreach (array_keys($errors->getMessages()) as $errorKey) {
        $root = \Illuminate\Support\Str::before($errorKey, '.');
        foreach ($tabFields as $tab => $fields) {
            if (in_array($root, $fields, true)) {
                $initialTab = $tab;
                break 2;
            }
        }
    }

    $currency = \App\Support\Format::currencySymbol();
@endphp

<div x-data="uiTabs(@js($initialTab))" class="space-y-6">
    <x-ui.card :padded="false">
        <div class="px-4 sm:px-5">
            <x-ui.tabs :tabs="[
                ['label' => 'Content', 'key' => 'content', 'icon' => 'document-text'],
                ['label' => 'Commercial', 'key' => 'commercial', 'icon' => 'banknotes'],
                ['label' => 'Technologies', 'key' => 'technologies', 'icon' => 'server-stack', 'count' => count($selectedTechnologies) ?: null],
                ['label' => 'SEO', 'key' => 'seo', 'icon' => 'globe-alt'],
            ]" />
        </div>

        {{-- Content --}}
        <div x-show="is('content')" class="grid grid-cols-1 gap-6 p-4 sm:p-5 lg:grid-cols-3">
            <div class="space-y-5 lg:col-span-2">
                <x-ui.form.input name="name" id="field-name" label="Service name" :value="$service?->name" required maxlength="150" />

                @include('admin.marketing.partials.slug-field', [
                    'value' => $service?->slug,
                    'sourceId' => 'field-name',
                    'prefix' => url('/services').'/',
                    'published' => $wasPublished,
                    'reserved' => $reservedSlugs ?? [],
                    'max' => 180,
                ])

                <div>
                    <x-ui.form.textarea name="short_description" label="Short description" :value="$service?->short_description" :rows="3" maxlength="500" optional help="Shown on the service card and used as the search description when the SEO one is empty." />
                    @include('admin.cms.partials.length-meter', ['for' => 'field-short_description', 'max' => 500, 'idealMin' => 120, 'idealMax' => 160])
                </div>

                @include('admin.cms.partials.richtext', [
                    'name' => 'full_description',
                    'label' => 'Full description',
                    'value' => $service?->full_description,
                    'rows' => 14,
                ])
            </div>

            <div class="space-y-5">
                <x-ui.form.select name="service_category_id" label="Category" :options="$categoryOptions ?? []" :selected="$service?->service_category_id" placeholder="No category" optional />

                <x-ui.form.input name="icon" label="Icon" :value="$service?->icon" maxlength="64" placeholder="e.g. code-bracket" optional help="An icon name, shown when the service has no image." />

                <x-cms.image-field name="image_media_id" upload="image" label="Service image" :asset="$imageAsset" profile="Card" :max-mb="$maxUploadMb ?? null" />

                <div class="space-y-4 rounded-xl bg-slate-50 p-4 ring-1 ring-inset ring-slate-200 dark:bg-slate-800/40 dark:ring-slate-700">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Publishing</p>

                    @if ($canStatus)
                        <x-ui.form.select name="status" label="Status" :options="$statusOptions ?? ContentStatus::options()" :selected="$statusValue" />
                    @else
                        <div>
                            <p class="mb-1 text-sm font-medium text-slate-700 dark:text-slate-200">Status</p>
                            @include('admin.marketing.partials.enum-badge', ['value' => $service?->status ?? ContentStatus::Draft])
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Publishing needs the services change-status permission.</p>
                        </div>
                    @endif

                    <x-ui.form.toggle name="is_featured" label="Featured" description="Featured services are listed first on the website." :checked="(bool) ($service?->is_featured ?? false)" />

                    <x-ui.form.input name="sort_order" type="number" label="Display order" :value="$service?->sort_order ?? 0" min="0" step="1" help="Lower numbers come first. Dragging rows in the list sets this for you." />
                </div>
            </div>
        </div>

        {{-- Commercial --}}
        <div x-show="is('commercial')" x-cloak class="grid grid-cols-1 gap-6 p-4 sm:p-5 lg:grid-cols-2">
            <div class="space-y-5">
                <x-ui.form.input
                    name="starting_price"
                    label="Starting price"
                    :value="$service?->starting_price"
                    :prefix="$currency"
                    inputmode="decimal"
                    autocomplete="off"
                    placeholder="Leave empty for “on request”"
                    optional
                    help="Up to two decimals. Stored exactly as typed — never rounded through a float."
                />

                <x-ui.form.input name="price_note" label="Price note" :value="$service?->price_note" maxlength="100" placeholder="e.g. starting from / per project" optional />

                <x-ui.form.toggle
                    name="price_visible"
                    label="Show the price on the website"
                    description="Switch off to keep the price here without publishing it. The website then shows “on request”."
                    :checked="(bool) ($service?->price_visible ?? true)"
                />
            </div>

            <div>
                @include('admin.marketing.partials.list-input', [
                    'name' => 'features',
                    'label' => 'Features',
                    'values' => $service?->features ?? [],
                    'mode' => 'list',
                    'max' => 20,
                    'maxLength' => 150,
                    'placeholder' => 'Add a feature and press Enter',
                    'help' => 'The bullet list on the service page, in this order.',
                ])
            </div>
        </div>

        {{-- Technologies --}}
        <div x-show="is('technologies')" x-cloak class="p-4 sm:p-5">
            @include('admin.marketing.partials.technology-picker', [
                'choices' => $technologyOptions ?? [],
                'selected' => $selectedTechnologies,
                'help' => 'The chips appear on the service page in this order.',
            ])
        </div>

        {{-- SEO --}}
        <div x-show="is('seo')" x-cloak class="p-4 sm:p-5">
            <x-cms.seo-fields :model="$service" :seo="$seoMeta ?? null" :inherited="$seoInherited ?? null" :display-url="$publicUrl ?? url('/services/'.($service?->slug ?? 'your-service'))" />
        </div>
    </x-ui.card>
</div>
