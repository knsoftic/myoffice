{{--
    Rich-text input (phase-03 §8.1 `x-cms.richtext`).

    @include('admin.cms.partials.richtext', [
        'name' => 'content[description]',
        'id' => 'field-content-description',   // optional
        'label' => 'Description',
        'value' => $value,                     // stored, already-sanitised HTML
        'help' => null,
        'maxChars' => 600,                     // optional counter
        'rows' => 8,
        'required' => false,
        'readonly' => false,
    ])

    Uses the Trix editor when the `resources/js/cms.js` Vite entry is built (or the dev server is hot);
    otherwise a plain HTML textarea. Either way the value posts under `name`, and the server runs it
    through RichText::sanitize($html, 'cms') on save and again on render (INV-13, D25) — nothing the
    browser does here is a security control. Images go through the media library (D24), never inline.
--}}

@php
    $id = $id ?? 'field-'.str_replace(['[', ']', '.'], ['-', '', '-'], (string) $name);
    $errorKey = str_replace(['][', '[', ']'], ['.', '.', ''], (string) $name);
    $current = old($errorKey, $value ?? null);
    $current = is_string($current) ? $current : '';
    $invalid = $errors->has($errorKey);
    $rows = (int) ($rows ?? 8);
    $readonly = (bool) ($readonly ?? false);

    $hot = is_file(public_path('hot'));
    $manifest = public_path('build/manifest.json');
    $trix = is_file(resource_path('js/cms.js'))
        && ($hot || (is_file($manifest) && str_contains((string) file_get_contents($manifest), 'resources/js/cms.js')));
@endphp

<div class="w-full">
    @if (filled($label ?? null))
        <x-ui.form.label :for="$id" :required="(bool) ($required ?? false)" class="mb-1.5">{{ $label }}</x-ui.form.label>
    @endif

    @if ($trix && ! $readonly)
        @once
            @push('scripts')
                @vite('resources/js/cms.js')
            @endpush
        @endonce

        <input type="hidden" id="{{ $id }}" name="{{ $name }}" value="{{ $current }}">
        <trix-editor
            input="{{ $id }}"
            aria-label="{{ $label ?? 'Rich text' }}"
            @class([
                'trix-content block min-h-[10rem] max-w-none rounded-lg border bg-white px-3 py-2 text-sm text-slate-900 shadow-sm dark:bg-slate-950/40 dark:text-white',
                'border-rose-400 dark:border-rose-500/60' => $invalid,
                'border-slate-300 dark:border-slate-700' => ! $invalid,
            ])
        ></trix-editor>
    @else
        <textarea
            id="{{ $id }}"
            name="{{ $name }}"
            rows="{{ $rows }}"
            spellcheck="true"
            @readonly($readonly)
            @if ($invalid) aria-invalid="true" @endif
            @class([
                'block w-full rounded-lg border bg-white px-3 py-2 font-mono text-xs leading-relaxed text-slate-900 shadow-sm transition focus:ring-2 dark:bg-slate-950/40 dark:text-white',
                'read-only:bg-slate-50 dark:read-only:bg-slate-900/60',
                'border-rose-400 focus:border-rose-500 focus:ring-rose-500/20 dark:border-rose-500/60' => $invalid,
                'border-slate-300 focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700' => ! $invalid,
            ])
        >{{ $current }}</textarea>

        <p class="mt-1 text-2xs text-slate-400 dark:text-slate-500">
            HTML allowed: paragraphs, h2–h4, bold, italic, lists, links, quotes, tables. Scripts, event handlers and
            unlisted embeds are removed when you save.
        </p>
    @endif

    @if (filled($help ?? null))
        <x-ui.form.help>{{ $help }}</x-ui.form.help>
    @endif

    @if (! empty($maxChars))
        @include('admin.cms.partials.length-meter', ['for' => $id, 'max' => (int) $maxChars])
    @endif

    <x-ui.form.error :for="$name" />
</div>
