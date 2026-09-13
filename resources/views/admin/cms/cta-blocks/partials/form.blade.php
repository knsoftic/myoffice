{{--
    CTA block fields with a live preview (phase-03 §2.8, §6.13, §8.11). Shared by the create dialog on
    the index and the edit screen.

    @include('admin.cms.cta-blocks.partials.form', [
        'ctaBlock' => $ctaBlock,          // ?CtaBlock
        'backgroundAsset' => $asset,      // ?MediaAsset
        'keyLocked' => $ctaBlock?->usage_count > 0,   // `key` is immutable once referenced
        'readonly' => false,
    ])

    Fields: key, name, variant, heading, subheading, description (plain text), primary_label,
    primary_url, primary_style, primary_new_tab, secondary_*, background_media_id, background_color.
    `status` is not a form field (Store/UpdateCtaBlockRequest prohibit it): publishing is the toggle route. Background image and colour are mutually exclusive — the one set last wins, server-side.
--}}

@php
    use App\Enums\Cms\ButtonStyle;
    use App\Enums\Cms\CtaVariant;

    $ctaBlock = $ctaBlock ?? null;
    $readonly = (bool) ($readonly ?? false);
    $keyLocked = (bool) ($keyLocked ?? false);
    $enumValue = static fn (mixed $value, string $fallback): string => $value instanceof \BackedEnum ? (string) $value->value : (string) ($value ?? $fallback);

    $preview = [
        'variant' => (string) old('variant', $enumValue($ctaBlock?->variant, CtaVariant::Banner->value)),
        'heading' => (string) old('heading', $ctaBlock?->heading ?? ''),
        'subheading' => (string) old('subheading', $ctaBlock?->subheading ?? ''),
        'description' => (string) old('description', $ctaBlock?->description ?? ''),
        'primary_label' => (string) old('primary_label', $ctaBlock?->primary_label ?? ''),
        'secondary_label' => (string) old('secondary_label', $ctaBlock?->secondary_label ?? ''),
        'background_color' => (string) old('background_color', $ctaBlock?->background_color ?? ''),
    ];
@endphp

<div
    x-data="{ p: @js($preview) }"
    x-on:input="if ($event.target.name && Object.prototype.hasOwnProperty.call(p, $event.target.name)) p[$event.target.name] = $event.target.value"
    x-on:change="if ($event.target.name && Object.prototype.hasOwnProperty.call(p, $event.target.name)) p[$event.target.name] = $event.target.value"
    class="grid grid-cols-1 gap-6 xl:grid-cols-5"
>
    <div class="space-y-5 xl:col-span-3">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.form.input name="name" label="Admin name" :value="$ctaBlock?->name" required maxlength="150" :readonly="$readonly" placeholder="Free consultation — home page" />
            <x-ui.form.input
                name="key"
                label="Key"
                :value="$ctaBlock?->key"
                required
                maxlength="64"
                pattern="[a-z0-9_]+"
                :readonly="$readonly || $keyLocked"
                :help="$keyLocked ? 'Locked: sections reference this block.' : 'Lowercase letters, numbers and underscores. Cannot change once a section uses it.'"
                class="font-mono"
            />
            <x-ui.form.select name="variant" label="Layout" :options="CtaVariant::options()" :selected="$preview['variant']" :disabled="$readonly" />
        </div>

        <div class="space-y-4">
            <x-ui.form.input name="heading" label="Heading" :value="$ctaBlock?->heading" required maxlength="200" :readonly="$readonly" id="cta-heading" />
            <x-ui.form.input name="subheading" label="Subheading" :value="$ctaBlock?->subheading" maxlength="300" :readonly="$readonly" />
            <x-ui.form.textarea name="description" label="Description" :value="$ctaBlock?->description" :rows="3" :readonly="$readonly" help="Plain text — no formatting." />
        </div>

        @foreach (['primary' => 'Primary button', 'secondary' => 'Secondary button'] as $button => $slotLabel)
            @php
                $slotLabelValue = $ctaBlock?->getAttribute($button.'_label');
                $slotUrlValue = $ctaBlock?->getAttribute($button.'_url');
                $slotStyleValue = $enumValue($ctaBlock?->getAttribute($button.'_style'), $button === 'primary' ? ButtonStyle::Primary->value : ButtonStyle::Outline->value);
                $slotNewTab = (bool) ($ctaBlock?->getAttribute($button.'_new_tab') ?? false);
            @endphp
            <fieldset class="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
                <legend class="px-1 text-sm font-medium text-slate-700 dark:text-slate-200">{{ $slotLabel }}</legend>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-12">
                    <x-ui.form.input :name="$button.'_label'" label="Label" :value="$slotLabelValue" maxlength="60" :readonly="$readonly" class="sm:col-span-4" />
                    <x-ui.form.input :name="$button.'_url'" label="Link to" :value="$slotUrlValue" maxlength="500" placeholder="https://… , /page , #section" :readonly="$readonly" class="sm:col-span-5" />
                    <x-ui.form.select :name="$button.'_style'" label="Style" :options="ButtonStyle::options()" :selected="$slotStyleValue" :disabled="$readonly" class="sm:col-span-3" />
                    <div class="sm:col-span-12">
                        <x-ui.form.toggle :name="$button.'_new_tab'" label="Open in a new tab" size="sm" :checked="$slotNewTab" :disabled="$readonly" />
                    </div>
                </div>
            </fieldset>
        @endforeach

        <fieldset class="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
            <legend class="px-1 text-sm font-medium text-slate-700 dark:text-slate-200">Background</legend>
            <p class="mb-3 text-xs text-slate-500 dark:text-slate-400">Choose an image <strong>or</strong> a colour. Setting one clears the other when you save.</p>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @include('admin.cms.partials.media-picker', [
                    'name' => 'background_media_id',
                    'label' => 'Background image',
                    'kind' => 'image',
                    'selected' => isset($backgroundAsset) && $backgroundAsset ? [$backgroundAsset] : [],
                    'selectedIds' => array_filter([$ctaBlock?->background_media_id]),
                    'profile' => 'Banner',
                ])
                <div>
                    <x-ui.form.label for="cta-bg-color" class="mb-1.5">Background colour</x-ui.form.label>
                    <div class="flex items-center gap-2">
                        <input type="color" x-bind:value="/^#[0-9a-f]{6}$/i.test(p.background_color) ? p.background_color : '#4f46e5'" x-on:input="p.background_color = $event.target.value; $refs.bg.value = $event.target.value" @disabled($readonly) aria-label="Background colour picker" class="h-9 w-12 cursor-pointer rounded-lg border border-slate-300 bg-white p-1 dark:border-slate-700 dark:bg-slate-900">
                        <input id="cta-bg-color" x-ref="bg" type="text" name="background_color" value="{{ $preview['background_color'] }}" maxlength="7" placeholder="#4f46e5" @readonly($readonly) class="block w-32 rounded-lg border-slate-300 px-3 py-2 font-mono text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                    </div>
                    <x-ui.form.error for="background_color" />
                </div>
            </div>
        </fieldset>
    </div>

    {{-- Live preview — an admin approximation of the public variant; the site partial is authoritative --}}
    <div class="xl:col-span-2">
        <div class="sticky top-20 space-y-2">
            <p class="text-2xs font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">Preview</p>
            <div
                class="overflow-hidden rounded-xl p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800"
                x-bind:class="{
                    'text-center': p.variant === 'banner' || p.variant === 'full_width',
                    'bg-white text-slate-900 dark:bg-slate-900 dark:text-white': p.variant === 'card' || p.variant === 'inline',
                    'text-white': p.variant === 'banner' || p.variant === 'split' || p.variant === 'full_width',
                    'bg-brand-600 dark:bg-brand-500': (p.variant === 'banner' || p.variant === 'split' || p.variant === 'full_width') && ! /^#[0-9a-f]{6}$/i.test(p.background_color),
                }"
                x-bind:style="(p.variant === 'banner' || p.variant === 'split' || p.variant === 'full_width') && /^#[0-9a-f]{6}$/i.test(p.background_color) ? 'background-color: ' + p.background_color : ''"
            >
                <div x-bind:class="p.variant === 'split' ? 'flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between' : ''">
                    <div>
                        <p class="text-lg font-semibold leading-snug" x-text="p.heading || 'Your heading'"></p>
                        <p x-show="p.subheading" class="mt-1 text-sm opacity-90" x-text="p.subheading"></p>
                        <p x-show="p.description && p.variant !== 'inline'" class="mt-2 text-xs opacity-80" x-text="p.description"></p>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2" x-bind:class="(p.variant === 'banner' || p.variant === 'full_width') ? 'justify-center' : ''">
                        <span x-show="p.primary_label" class="inline-flex h-8 items-center rounded-lg bg-white px-3 text-xs font-semibold text-slate-900 shadow-sm" x-text="p.primary_label"></span>
                        <span x-show="p.secondary_label" class="inline-flex h-8 items-center rounded-lg border border-current px-3 text-xs font-semibold" x-text="p.secondary_label"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
