{{--
    One registry-declared field (phase-03 §8.1 `x-cms.field`): switches on the field type exactly as
    Phase 2's settings field does. Used by the section editor and by every repeater item form.

    @include('admin.cms.partials.field', [
        'field' => $definition,          // a normalised SectionRegistry field definition
        'name' => 'content[heading]',    // the input name
        'value' => $currentValue,
        'refs' => $refs,                 // ['menus' => Collection<Menu>, 'ctaBlocks' => Collection<CtaBlock>,
                                         //  'faqCategories' => Collection<FaqCategory>, 'pages' => Collection<Page>]
        'readonly' => ! $canEdit,
    ])

    The error key is the dotted input name (`content.heading`), which is exactly the key
    SectionValidator reports under, so a server refusal lands on the field that caused it.
    Everything here is presentation: the rules live in SectionRegistry::rulesFor() and run server-side.
--}}

@php
    use App\Enums\Cms\MenuLocation;
    use App\Support\Cms\SectionRegistry;

    $type = (string) ($field['type'] ?? SectionRegistry::TYPE_TEXT);
    $label = (string) ($field['label'] ?? 'Field');
    $help = $field['help'] ?? null;
    $required = (bool) ($field['required'] ?? false);
    $maxChars = $field['max_chars'] ?? null;
    $readonly = (bool) ($readonly ?? false) || (bool) ($field['readonly'] ?? false);
    $placeholder = $field['placeholder'] ?? null;
    $suffix = $field['suffix'] ?? null;
    $options = is_array($field['options'] ?? null) ? $field['options'] : [];
    $refs = $refs ?? [];

    $id = 'field-'.str_replace(['[', ']', '.'], ['-', '', '-'], (string) $name);
    $errorKey = str_replace(['][', '[', ']'], ['.', '.', ''], (string) $name);

    $readonlyNote = ($field['readonly'] ?? false) ? 'Fixed by the system.' : null;
    $helpText = trim(implode(' ', array_filter([$help, $readonlyNote])));
    $helpText = $helpText === '' ? null : $helpText;

    $inputType = match ($type) {
        SectionRegistry::TYPE_EMAIL => 'email',
        SectionRegistry::TYPE_TEL => 'tel',
        default => 'text',
    };

    $routeHas = static fn (string $route): bool => \Illuminate\Support\Facades\Route::has($route);
@endphp

@switch ($type)
    @case (SectionRegistry::TYPE_TEXTAREA)
        <x-ui.form.textarea
            :name="$name"
            :id="$id"
            :label="$label"
            :value="is_scalar($value) ? (string) $value : null"
            :rows="3"
            :required="$required"
            :readonly="$readonly"
            :placeholder="$placeholder"
            :help="$helpText"
        />
        @if ($maxChars)
            @include('admin.cms.partials.length-meter', ['for' => $id, 'max' => $maxChars])
        @endif
        @break

    @case (SectionRegistry::TYPE_RICHTEXT)
        @include('admin.cms.partials.richtext', [
            'name' => $name,
            'id' => $id,
            'label' => $label,
            'value' => is_string($value) ? $value : null,
            'help' => $helpText,
            'maxChars' => $maxChars,
            'required' => $required,
            'readonly' => $readonly,
        ])
        @break

    @case (SectionRegistry::TYPE_NUMBER)
    @case (SectionRegistry::TYPE_DECIMAL)
        <x-ui.form.input
            :name="$name"
            :id="$id"
            :type="$type === SectionRegistry::TYPE_NUMBER ? 'number' : 'text'"
            :inputmode="$type === SectionRegistry::TYPE_DECIMAL ? 'decimal' : 'numeric'"
            :label="$label"
            :value="is_scalar($value) ? (string) $value : null"
            :suffix="$suffix"
            :required="$required"
            :readonly="$readonly"
            :placeholder="$placeholder"
            :help="$helpText"
        />
        @break

    @case (SectionRegistry::TYPE_BOOLEAN)
        <div class="pt-1">
            <x-ui.form.toggle
                :name="$name"
                :id="$id"
                :label="$label"
                :description="$helpText"
                :checked="filter_var($value, FILTER_VALIDATE_BOOLEAN)"
                :disabled="$readonly"
            />
        </div>
        @break

    @case (SectionRegistry::TYPE_SELECT)
        <x-ui.form.select
            :name="$name"
            :id="$id"
            :label="$label"
            :options="$options"
            :selected="is_scalar($value) ? (string) $value : null"
            :placeholder="$required ? null : 'None'"
            :required="$required"
            :disabled="$readonly"
            :help="$helpText"
        />
        @break

    @case (SectionRegistry::TYPE_MULTISELECT)
        <x-ui.form.select
            :name="$name.'[]'"
            :id="$id"
            :label="$label"
            :options="$options"
            :selected="is_array($value) ? $value : []"
            :multiple="true"
            :disabled="$readonly"
            :help="$helpText"
        />
        @break

    @case (SectionRegistry::TYPE_COLOR)
        @php $colour = old($errorKey, is_string($value) ? $value : ''); @endphp
        <div x-data="{ colour: @js((string) $colour) }">
            <x-ui.form.label :for="$id" :required="$required" class="mb-1.5">{{ $label }}</x-ui.form.label>
            <div class="flex items-center gap-2">
                <input
                    type="color"
                    x-bind:value="/^#[0-9a-f]{6}$/i.test(colour) ? colour : '#000000'"
                    x-on:input="colour = $event.target.value"
                    aria-label="{{ $label }} picker"
                    @disabled($readonly)
                    class="h-9 w-12 cursor-pointer rounded-lg border border-slate-300 bg-white p-1 dark:border-slate-700 dark:bg-slate-900"
                >
                <input
                    type="text"
                    id="{{ $id }}"
                    name="{{ $name }}"
                    x-model="colour"
                    placeholder="#1d4ed8"
                    maxlength="7"
                    @readonly($readonly)
                    class="block w-32 rounded-lg border-slate-300 bg-white px-3 py-2 font-mono text-sm text-slate-900 shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white"
                >
            </div>
            @if ($helpText)
                <x-ui.form.help>{{ $helpText }}</x-ui.form.help>
            @endif
            <x-ui.form.error :for="$name" />
        </div>
        @break

    @case (SectionRegistry::TYPE_ICON)
        @include('admin.cms.partials.icon-picker', [
            'name' => $name,
            'id' => $id,
            'label' => $label,
            'value' => is_string($value) ? $value : null,
            'help' => $helpText,
        ])
        @break

    @case (SectionRegistry::TYPE_LINK)
        @include('admin.cms.partials.link-field', [
            'name' => $name,
            'label' => $label,
            'value' => is_array($value) ? $value : [],
            'help' => $helpText,
            'readonly' => $readonly,
        ])
        @break

    @case (SectionRegistry::TYPE_IMAGE)
    @case (SectionRegistry::TYPE_VIDEO)
        @php
            $assetId = old($errorKey, $value);
            $asset = filled($assetId) ? collect($refs['assets'] ?? [])->firstWhere('id', (int) $assetId) : null;
        @endphp
        @include('admin.cms.partials.media-picker', [
            'name' => $name,
            'label' => $label,
            'help' => $helpText,
            'kind' => $type === SectionRegistry::TYPE_VIDEO ? 'video' : 'image',
            'multiple' => false,
            'selected' => $asset ? [$asset] : [],
            'required' => $required,
        ])
        @break

    @case (SectionRegistry::TYPE_CTA_REF)
        @php
            $ctaOptions = collect($refs['ctaBlocks'] ?? [])->mapWithKeys(static fn ($block): array => [
                (string) $block->id => $block->name.' — '.$block->status->label(),
            ])->all();
        @endphp
        <x-ui.form.select
            :name="$name"
            :id="$id"
            :label="$label"
            :options="$ctaOptions"
            :selected="filled($value) ? (string) $value : null"
            placeholder="Choose a CTA block"
            :required="$required"
            :disabled="$readonly"
            :help="$helpText"
        />
        @if ($routeHas('admin.website.cta-blocks.index'))
            @can('website_cta_blocks.view_any')
                <a href="{{ route('admin.website.cta-blocks.index') }}" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                    Manage CTA blocks <x-ui.icon name="arrow-top-right-on-square" class="h-3 w-3" />
                </a>
            @endcan
        @endif
        @break

    @case (SectionRegistry::TYPE_MENU_REF)
        @if (($field['ref_by'] ?? SectionRegistry::REF_BY_ID) === SectionRegistry::REF_BY_LOCATION)
            @php
                $menusByLocation = collect($refs['menus'] ?? [])->keyBy(static fn ($menu): string => $menu->location->value);
                $locationOptions = collect(MenuLocation::cases())->mapWithKeys(static fn (MenuLocation $location): array => [
                    $location->value => $location->label().($menusByLocation->has($location->value) ? ' — '.$menusByLocation->get($location->value)->name : ' — not created yet'),
                ])->all();
            @endphp
            <x-ui.form.select
                :name="$name"
                :id="$id"
                :label="$label"
                :options="$locationOptions"
                :selected="filled($value) ? (string) $value : null"
                placeholder="No menu"
                :disabled="$readonly"
                :help="$helpText"
            />
        @else
            @php
                $menuOptions = collect($refs['menus'] ?? [])->mapWithKeys(static fn ($menu): array => [
                    (string) $menu->id => $menu->name.' ('.$menu->location->label().')',
                ])->all();
                $chosenMenu = filled($value) ? collect($refs['menus'] ?? [])->firstWhere('id', (int) $value) : null;
            @endphp
            <x-ui.form.select
                :name="$name"
                :id="$id"
                :label="$label"
                :options="$menuOptions"
                :selected="filled($value) ? (string) $value : null"
                placeholder="Choose a menu"
                :required="$required"
                :disabled="$readonly"
                :help="$helpText"
            />
            @if ($chosenMenu && $routeHas('admin.website.menus.show'))
                @can('menus.view')
                    <a href="{{ route('admin.website.menus.show', $chosenMenu) }}" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                        Edit this menu
                        @isset($chosenMenu->enabled_items_count)
                            <span class="text-slate-400">({{ app_number((int) $chosenMenu->enabled_items_count) }} enabled items)</span>
                        @endisset
                        <x-ui.icon name="arrow-top-right-on-square" class="h-3 w-3" />
                    </a>
                @endcan
            @endif
        @endif
        @break

    @case (SectionRegistry::TYPE_FAQ_CATEGORY_REF)
        @php
            $categoryOptions = collect($refs['faqCategories'] ?? [])->mapWithKeys(static fn ($category): array => [
                (string) $category->slug => $category->name.($category->is_enabled ? '' : ' (disabled)'),
            ])->all();
        @endphp
        <x-ui.form.select
            :name="$name"
            :id="$id"
            :label="$label"
            :options="$categoryOptions"
            :selected="filled($value) ? (string) $value : null"
            placeholder="No category"
            :required="$required"
            :disabled="$readonly"
            :help="$helpText"
        />
        @break

    @case (SectionRegistry::TYPE_PAGE_REF)
        @php
            $pageOptions = collect($refs['pages'] ?? [])->mapWithKeys(static fn ($page): array => [
                (string) $page->id => $page->title.' (/'.$page->slug.')',
            ])->all();
        @endphp
        <x-ui.form.select
            :name="$name"
            :id="$id"
            :label="$label"
            :options="$pageOptions"
            :selected="filled($value) ? (string) $value : null"
            placeholder="Choose a page"
            :required="$required"
            :disabled="$readonly"
            :help="$helpText"
        />
        @break

    @default
        <x-ui.form.input
            :name="$name"
            :id="$id"
            :type="$inputType"
            :label="$label"
            :value="is_scalar($value) ? (string) $value : null"
            :placeholder="$placeholder ?? ($type === SectionRegistry::TYPE_URL ? 'https://… , /page , #section' : null)"
            :suffix="$suffix"
            :required="$required"
            :readonly="$readonly"
            :help="$helpText"
            :maxlength="$maxChars"
        />
        @if ($maxChars)
            @include('admin.cms.partials.length-meter', ['for' => $id, 'max' => $maxChars])
        @endif
@endswitch
