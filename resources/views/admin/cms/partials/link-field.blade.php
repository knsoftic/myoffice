{{--
    The `link` composite field — label, URL, button style, new tab (phase-03 §6.1 / §8.1
    `x-cms.link-field`).

    @include('admin.cms.partials.link-field', [
        'name' => 'content[primary_button]',   // posts name[label] name[url] name[style] name[new_tab]
        'label' => 'Primary button',
        'value' => ['label' => 'Get started', 'url' => '#contact', 'style' => 'primary', 'new_tab' => false],
        'help' => null,
        'readonly' => false,
    ])

    URLs must start with https://, http://, mailto:, tel:, / or # (§6.6); the server rejects anything
    else — `javascript:` included — whatever this field lets through.
--}}

@php
    use App\Enums\Cms\ButtonStyle;

    $value = is_array($value ?? null) ? $value : [];
    $base = str_replace(['][', '[', ']'], ['.', '.', ''], (string) $name);
    $readonly = (bool) ($readonly ?? false);
    $idBase = 'field-'.str_replace(['[', ']', '.'], ['-', '', '-'], (string) $name);
    $newTab = old($base.'.new_tab', $value['new_tab'] ?? false);
@endphp

<fieldset class="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
    <legend class="px-1 text-sm font-medium text-slate-700 dark:text-slate-200">{{ $label ?? 'Link' }}</legend>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-12">
        <x-ui.form.input
            :name="$name.'[label]'"
            :id="$idBase.'-label'"
            label="Label"
            :value="$value['label'] ?? null"
            maxlength="60"
            :readonly="$readonly"
            class="sm:col-span-4"
        />

        <x-ui.form.input
            :name="$name.'[url]'"
            :id="$idBase.'-url'"
            label="Link to"
            :value="$value['url'] ?? null"
            placeholder="https://… , /page , #section , tel: , mailto:"
            maxlength="500"
            :readonly="$readonly"
            class="sm:col-span-5"
        />

        <x-ui.form.select
            :name="$name.'[style]'"
            :id="$idBase.'-style'"
            label="Style"
            :options="ButtonStyle::options()"
            :selected="$value['style'] ?? ButtonStyle::Primary->value"
            :disabled="$readonly"
            class="sm:col-span-3"
        />

        <div class="sm:col-span-12">
            <x-ui.form.toggle
                :name="$name.'[new_tab]'"
                :id="$idBase.'-new-tab'"
                label="Open in a new tab"
                description="Always rendered with noopener and noreferrer."
                :checked="(bool) $newTab"
                :disabled="$readonly"
                size="sm"
            />
        </div>
    </div>

    @if (filled($help ?? null))
        <x-ui.form.help>{{ $help }}</x-ui.form.help>
    @endif

    <x-ui.form.error :for="$name" />
</fieldset>
