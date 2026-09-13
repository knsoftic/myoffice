@props([
    'field' => [],
])

{{--
    x-settings.multiselect — the `multiselect` type.

    A checkbox grid rather than a `<select multiple>`: the lists are short (payout methods), every
    option stays visible, and nothing depends on the operator knowing to ctrl-click.

    Each box posts `settings[payout_methods][]`, which is the shape
    `item_rules['*'] => ['string', 'in:bank,…']` validates. A hidden empty field precedes them so
    clearing every box posts an empty array rather than nothing at all — otherwise the key would
    vanish from the payload and the old value would quietly survive.
--}}

@php
    $f = $field;

    $disabled = (bool) ($f['disabled'] ?? false);
    $options = (array) ($f['options'] ?? []);

    $selected = old($f['error_key'], $f['value'] ?? $f['default']);
    $selected = array_map('strval', is_array($selected) ? $selected : (filled($selected) ? [$selected] : []));

    // An invalid option is reported against the child key (`…payout_methods.1`), which a
    // field-level <x-ui.form.error> would never find. Collect those here so "the selected payout
    // method is invalid" is actually visible next to the boxes.
    $childError = null;

    foreach ($errors->getBag('default')->messages() as $errorKey => $messages) {
        if (str_starts_with((string) $errorKey, $f['error_key'].'.') && $messages !== []) {
            $childError = $messages[0];

            break;
        }
    }
@endphp

<div>
    @if ($options === [])
        <p class="text-sm text-slate-500 dark:text-slate-400">No options are available for this setting.</p>
    @else
        {{-- Keeps the key present when every box is unchecked — only on an editable field: a
             read-only (disabled) field posts nothing at all, like every other disabled control. --}}
        @unless ((bool) ($f['disabled'] ?? false))
            <input type="hidden" name="{{ $f['name'] }}[]" value="" />
        @endunless

        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($options as $value => $label)
                @php $checked = in_array((string) $value, $selected, true); @endphp

                <label
                    @class([
                        'flex cursor-pointer items-center gap-2.5 rounded-lg border px-3 py-2 text-sm transition-colors',
                        'border-brand-300 bg-brand-50/60 dark:border-brand-500/40 dark:bg-brand-500/10' => $checked,
                        'border-slate-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/50' => ! $checked,
                        'cursor-not-allowed opacity-60' => $disabled,
                    ])
                >
                    <input
                        type="checkbox"
                        name="{{ $f['name'] }}[]"
                        value="{{ $value }}"
                        @checked($checked)
                        @disabled($disabled)
                        class="h-4 w-4 rounded border-slate-300 text-brand-600 shadow-sm focus:ring-2 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-900 dark:checked:border-brand-500 dark:checked:bg-brand-500"
                    />
                    <span class="min-w-0 truncate font-medium text-slate-700 dark:text-slate-200">{{ $label }}</span>
                </label>
            @endforeach
        </div>
    @endif

    @if (filled($f['help'] ?? null))
        <x-ui.form.help>{{ $f['help'] }}</x-ui.form.help>
    @endif

    <x-ui.form.error :for="$f['error_key']" />

    @if ($childError !== null)
        <x-ui.form.error :message="$childError" />
    @endif
</div>
