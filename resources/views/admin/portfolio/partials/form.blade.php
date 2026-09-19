{{--
    The portfolio item editor body, shared by create and edit (phase-04 §8.3, §2.7, §6.3, §6.11).
    The gallery is NOT part of this form: on edit it is its own card with its own endpoints
    (admin.portfolio.partials.gallery); on create the first images are uploaded with the item (`images[]`).

    @include('admin.portfolio.partials.form', ['item' => $item])   // $item = null on create

    Reads (controller variables of create / edit):
      $categoryOptions     array<int, string>
      $technologyOptions   array<int, string>
      $statusOptions       array<string, string>   ContentStatus::options()
      $reservedSlugs       list<string>
      $seoMeta, $seoInherited, $publicUrl           SEO tab (optional)
      $maxUploadMb         optional int

    Posts (StorePortfolioItemRequest / UpdatePortfolioItemRequest, multipart):
      title, slug, portfolio_category_id, client_name, summary, description (rich text), technologies_note,
      project_url, completion_date (Y-m-d, not in the future), status (change_status holders only),
      is_featured, sort_order, technology_ids[], images[] (create only, max 20), seo[...].
      There is no client picker: `client_id` stays null until Phase 5 adds the constraint and the picker (§2.1).
--}}

@php
    use App\Enums\Cms\ContentStatus;

    $item = $item ?? null;
    $isNew = $item === null;
    $canStatus = (bool) auth()->user()?->can('portfolio.change_status');
    $statusValue = $item?->status instanceof \BackedEnum ? $item->status->value : ($item?->status ?? ContentStatus::Draft->value);
    $wasPublished = $item !== null && $statusValue === ContentStatus::Published->value;

    $selectedTechnologies = collect(old('technology_ids', $item?->relationLoaded('technologies') ? $item->technologies->pluck('id')->all() : []))
        ->map(static fn ($id): int => (int) $id)->filter()->values()->all();

    $tabFields = [
        'technologies' => ['technology_ids', 'technologies_note'],
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
@endphp

<div x-data="uiTabs(@js($initialTab))">
    <x-ui.card :padded="false">
        <div class="px-4 sm:px-5">
            <x-ui.tabs :tabs="[
                ['label' => 'Case study', 'key' => 'content', 'icon' => 'document-text'],
                ['label' => 'Technologies', 'key' => 'technologies', 'icon' => 'server-stack', 'count' => count($selectedTechnologies) ?: null],
                ['label' => 'SEO', 'key' => 'seo', 'icon' => 'globe-alt'],
            ]" />
        </div>

        <div x-show="is('content')" class="grid grid-cols-1 gap-6 p-4 sm:p-5 lg:grid-cols-3">
            <div class="space-y-5 lg:col-span-2">
                <x-ui.form.input name="title" id="field-title" label="Project name" :value="$item?->title" required maxlength="180" />

                @include('admin.marketing.partials.slug-field', [
                    'value' => $item?->slug,
                    'sourceId' => 'field-title',
                    'prefix' => url('/portfolio').'/',
                    'published' => $wasPublished,
                    'reserved' => $reservedSlugs ?? [],
                    'max' => 180,
                ])

                <div>
                    <x-ui.form.textarea name="summary" label="Summary" :value="$item?->summary" :rows="3" maxlength="500" optional help="The one-paragraph card text." />
                    @include('admin.cms.partials.length-meter', ['for' => 'field-summary', 'max' => 500])
                </div>

                @include('admin.cms.partials.richtext', [
                    'name' => 'description',
                    'label' => 'Case study',
                    'value' => $item?->description,
                    'rows' => 14,
                ])

                @if ($isNew)
                    <div x-data="{ files: [] }">
                        <label for="field-images" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">Gallery images <span class="font-normal text-slate-400">(optional, up to 20)</span></label>
                        <label for="field-images" class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-300 px-4 py-8 text-center text-sm text-slate-500 transition hover:border-brand-400 hover:text-brand-700 dark:border-slate-700 dark:text-slate-400 dark:hover:border-brand-500 dark:hover:text-brand-300">
                            <x-ui.icon name="photo" class="h-6 w-6" />
                            <span><span class="font-semibold text-brand-700 dark:text-brand-300">Browse</span> for images — the first one becomes the cover</span>
                            <span class="text-xs text-slate-400 dark:text-slate-500">JPG, PNG, WebP or GIF{{ ($maxUploadMb ?? null) ? ', up to '.(int) $maxUploadMb.' MB each' : '' }}. SVG is not accepted.</span>
                        </label>
                        <input id="field-images" type="file" name="images[]" multiple accept="image/jpeg,image/png,image/webp,image/gif" class="sr-only" x-on:change="files = Array.from($event.target.files).map((file) => file.name)">
                        <ul x-show="files.length" x-cloak class="mt-2 space-y-1 text-xs text-slate-600 dark:text-slate-300">
                            <template x-for="name in files" :key="name">
                                <li class="flex items-center gap-1.5"><x-ui.icon name="photo" class="h-3.5 w-3.5 text-slate-400" /><span class="truncate" x-text="name"></span></li>
                            </template>
                        </ul>
                        <p x-show="files.length > 20" x-cloak class="mt-1 text-xs font-medium text-rose-600 dark:text-rose-400">A project can hold at most 20 images.</p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">If any file is refused, none are attached, so the gallery is never half uploaded. Alt text is added in the media library before the project can be published.</p>
                        <x-ui.form.error for="images" />
                        @foreach ($errors->getMessages() as $key => $messages)
                            @if (str_starts_with($key, 'images.'))
                                <x-ui.form.error :message="$messages[0]" />
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="space-y-5">
                <x-ui.form.select name="portfolio_category_id" label="Category" :options="$categoryOptions ?? []" :selected="$item?->portfolio_category_id" placeholder="No category" optional />

                <x-ui.form.input name="client_name" label="Client" :value="$item?->client_name" maxlength="150" optional help="Shown on the website exactly as typed." />

                <x-ui.form.input name="project_url" type="url" label="Project URL" :value="$item?->project_url" maxlength="255" placeholder="https://" optional help="Opened in a new tab and marked nofollow, noopener." />

                <x-ui.form.input name="completion_date" type="date" label="Completion date" :value="$item?->completion_date ? app_date($item->completion_date, 'Y-m-d') : null" :max="app_date(now(), 'Y-m-d')" optional />

                <div class="space-y-4 rounded-xl bg-slate-50 p-4 ring-1 ring-inset ring-slate-200 dark:bg-slate-800/40 dark:ring-slate-700">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Publishing</p>

                    @if ($canStatus)
                        <x-ui.form.select name="status" label="Status" :options="$statusOptions ?? ContentStatus::options()" :selected="$statusValue" help="Publishing needs a cover image with alt text." />
                    @else
                        <div>
                            <p class="mb-1 text-sm font-medium text-slate-700 dark:text-slate-200">Status</p>
                            @include('admin.marketing.partials.enum-badge', ['value' => $item?->status ?? ContentStatus::Draft])
                        </div>
                    @endif

                    <x-ui.form.toggle name="is_featured" label="Featured" description="Featured projects lead the portfolio page." :checked="(bool) ($item?->is_featured ?? false)" />

                    <x-ui.form.input name="sort_order" type="number" label="Display order" :value="$item?->sort_order ?? 0" min="0" step="1" />
                </div>
            </div>
        </div>

        <div x-show="is('technologies')" x-cloak class="space-y-6 p-4 sm:p-5">
            @include('admin.marketing.partials.technology-picker', [
                'choices' => $technologyOptions ?? [],
                'selected' => $selectedTechnologies,
                'help' => 'The stack chips on the case study, and the website’s technology filter.',
            ])

            <div class="max-w-xl">
                <x-ui.form.input name="technologies_note" label="Other technologies" :value="$item?->technologies_note" maxlength="255" optional help="Free text for one-off tools not worth their own technology." />
            </div>
        </div>

        <div x-show="is('seo')" x-cloak class="p-4 sm:p-5">
            <x-cms.seo-fields :model="$item" :seo="$seoMeta ?? null" :inherited="$seoInherited ?? null" :display-url="$publicUrl ?? url('/portfolio/'.($item?->slug ?? 'your-project'))" />
        </div>
    </x-ui.card>
</div>
