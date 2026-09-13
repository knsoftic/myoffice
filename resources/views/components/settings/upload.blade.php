@props([
    'field' => [],
])

{{--
    x-settings.upload — the `image` and `file` types (phase-02 §5: "image fields show a preview
    with remove").

    Three states, all visible at once so nothing is a surprise:

      · what is stored now — thumbnail (or a file chip when the format cannot be previewed),
        its filename and a link that opens it;
      · what has just been chosen — previewed by x-ui.form.file before the form is even saved;
      · removal — a two-step inline confirm that submits the DELETE form
        `admin.settings.file.destroy` rendered by x-settings.file-forms outside this form. Nested
        <form> elements are invalid HTML, so the button reaches its form through the `form`
        attribute instead.

    `accept` and the size hint are derived from the registry's own `mimes:` / `max:` rules, so the
    picker offers exactly what the validator will accept.

    The `x-on:input` hook hands the chosen file to the branding preview (x-settings.brand-preview
    registers `window.settingsBrand`). Everywhere else `window.settingsBrand` is undefined, the
    expression short-circuits, and nothing happens — which is why it needs no condition.
--}}

@php
    $f = $field;

    $key = (string) ($f['key'] ?? '');
    $group = (string) ($f['group'] ?? '');
    $file = $f['file'] ?? null;
    $disabled = (bool) ($f['disabled'] ?? false);

    // mimes:png,jpg,jpeg,webp,svg → ".png,.jpg,.jpeg,.webp,.svg" + "PNG, JPG, JPEG, WEBP or SVG"
    $extensions = [];
    $maxKb = null;

    foreach ((array) ($f['rules'] ?? []) as $rule) {
        if (! is_string($rule)) {
            continue;
        }

        if (str_starts_with($rule, 'mimes:')) {
            $extensions = array_values(array_filter(array_map('trim', explode(',', substr($rule, 6)))));
        } elseif (str_starts_with($rule, 'max:')) {
            $maxKb = (int) substr($rule, 4);
        }
    }

    $accept = $extensions === [] ? null : '.'.implode(',.', $extensions);

    $readable = array_map('mb_strtoupper', $extensions);
    $formats = match (count($readable)) {
        0 => null,
        1 => $readable[0],
        default => implode(', ', array_slice($readable, 0, -1)).' or '.end($readable),
    };

    $size = $maxKb === null
        ? null
        : ($maxKb >= 1024 ? round($maxKb / 1024, 1).' MB' : $maxKb.' KB');

    $hint = trim(implode(' ', array_filter([
        $formats,
        $size === null ? null : 'up to '.$size,
    ]))) ?: null;

    // Only formats a browser paints inline get a thumbnail.
    $previewable = $file !== null
        && in_array(
            mb_strtolower(pathinfo((string) $file['path'], PATHINFO_EXTENSION)),
            ['png', 'jpg', 'jpeg', 'webp', 'svg', 'gif', 'avif', 'ico'],
            true
        );

    $destroyForm = 'settings-file-destroy-'.$group.'-'.str_replace('_', '-', $key);
@endphp

<div x-data="{ confirming: false }">
    <x-ui.form.file
        :name="$f['name']"
        :id="$f['id']"
        :accept="$accept"
        :hint="$hint"
        :help="$f['help']"
        :disabled="$disabled"
        :current="$previewable ? $file['url'] : null"
        :icon="$f['type'] === 'image' ? 'photo' : 'document'"
        x-on:input="window.settingsBrand && window.settingsBrand.logo('{{ $key }}', $event.target.files[0])"
    />

    @if ($file !== null)
        <div class="mt-2 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2 ring-1 ring-slate-200 dark:bg-slate-800/60 dark:ring-slate-700">
            <div class="flex min-w-0 items-center gap-2">
                <x-ui.icon name="paper-clip" class="h-3.5 w-3.5 shrink-0 text-slate-400 dark:text-slate-500" />

                @if ($file['url'])
                    <a
                        href="{{ $file['url'] }}"
                        target="_blank"
                        rel="noopener"
                        class="truncate text-xs font-medium text-brand-600 underline-offset-2 hover:underline dark:text-brand-400"
                    >{{ $file['name'] }}</a>
                @else
                    <span class="truncate text-xs font-medium text-slate-600 dark:text-slate-300">{{ $file['name'] }}</span>
                @endif
            </div>

            @unless ($disabled)
                <div class="flex shrink-0 items-center gap-1.5">
                    <button
                        type="button"
                        x-show="! confirming"
                        x-on:click="confirming = true"
                        class="inline-flex items-center gap-1 rounded-md px-1.5 py-1 text-2xs font-semibold text-slate-500 transition-colors hover:bg-white hover:text-rose-600 dark:hover:bg-slate-900 dark:hover:text-rose-400"
                    >
                        <x-ui.icon name="trash" class="h-3.5 w-3.5" />
                        Remove
                    </button>

                    <template x-if="confirming">
                        <span class="flex items-center gap-1.5">
                            <span class="text-2xs font-medium text-slate-500 dark:text-slate-400">Remove this file?</span>

                            <button
                                type="submit"
                                form="{{ $destroyForm }}"
                                class="rounded-md bg-rose-600 px-2 py-1 text-2xs font-semibold text-white transition-colors hover:bg-rose-700"
                            >
                                Yes, remove
                            </button>

                            <button
                                type="button"
                                x-on:click="confirming = false"
                                class="rounded-md px-1.5 py-1 text-2xs font-semibold text-slate-500 hover:text-slate-800 dark:hover:text-slate-200"
                            >
                                Cancel
                            </button>
                        </span>
                    </template>
                </div>
            @endunless
        </div>
    @endif
</div>
