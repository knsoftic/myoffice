@props([
    'field' => [],
])

{{--
    x-settings.field — the ONE component every setting renders through (phase-02 §5).

        <x-settings.field :field="$field" />

    `$field` is the view model SettingsController::presentField() builds from the registry
    definition plus what is stored now. The component switches on `type` and nothing else, so a
    new setting appears on the screen the moment the registry declares it — there is no second
    place listing keys, and no screen-specific markup for a particular setting.

    Types (phase-02 §2): text · textarea · email · tel · url · number · decimal · boolean ·
    select · multiselect · color · image · file · json · time · password · richtext.

    Every type gets the same treatment: a label carrying the required marker, the read-only and
    public badges, the registry's help text underneath, and the field's inline validation error.
    Client-side limits (`maxlength`, `min`, `max`) are derived from the registry rules so the
    browser and the validator always agree.
--}}

@php
    $f = $field;

    $key = (string) ($f['key'] ?? '');
    $type = (string) ($f['type'] ?? 'text');
    $name = (string) ($f['name'] ?? '');
    $id = (string) ($f['id'] ?? '');
    $errorKey = (string) ($f['error_key'] ?? '');
    $disabled = (bool) ($f['disabled'] ?? false);
    $required = (bool) ($f['required'] ?? false);
    $readonly = (bool) ($f['readonly'] ?? false);
    $isPublic = (bool) ($f['public'] ?? false);
    $help = $f['help'] ?? null;
    $rules = (array) ($f['rules'] ?? []);

    // 12-column grid. Literal classes (never interpolated) so Tailwind's scanner sees them;
    // narrow fields still get a usable width on a tablet.
    $spans = [
        1 => 'col-span-6 sm:col-span-4 lg:col-span-1',
        2 => 'col-span-6 sm:col-span-4 lg:col-span-2',
        3 => 'col-span-12 sm:col-span-6 lg:col-span-3',
        4 => 'col-span-12 sm:col-span-6 lg:col-span-4',
        5 => 'col-span-12 sm:col-span-6 lg:col-span-5',
        6 => 'col-span-12 sm:col-span-6',
        7 => 'col-span-12 lg:col-span-7',
        8 => 'col-span-12 lg:col-span-8',
        9 => 'col-span-12 lg:col-span-9',
        10 => 'col-span-12 lg:col-span-10',
        11 => 'col-span-12 lg:col-span-11',
        12 => 'col-span-12',
    ];

    $span = $spans[(int) ($f['span'] ?? 6)] ?? $spans[6];

    // Limits the browser can enforce, taken from the same rules the server validates with.
    $numeric = in_array($type, ['number', 'decimal'], true);
    $min = null;
    $max = null;

    foreach ($rules as $rule) {
        if (! is_string($rule)) {
            continue;
        }

        if (str_starts_with($rule, 'min:')) {
            $min = substr($rule, 4);
        } elseif (str_starts_with($rule, 'max:')) {
            $max = substr($rule, 4);
        } elseif (str_starts_with($rule, 'between:')) {
            $bounds = explode(',', substr($rule, 8));
            $min = $bounds[0] ?? null;
            $max = $bounds[1] ?? null;
        }
    }

    $maxlength = $numeric ? null : $max;

    $inputTypes = [
        'text' => 'text',
        'email' => 'email',
        'tel' => 'tel',
        'url' => 'url',
        'number' => 'number',
        'time' => 'time',
    ];
@endphp

<div class="{{ $span }} min-w-0">
    @if ($type === 'boolean')
        {{-- A switch carries its own label and description, which reads better than a header row. --}}
        <div class="flex h-full flex-col justify-center rounded-lg border border-slate-200 bg-slate-50/60 px-3 py-3 dark:border-slate-800 dark:bg-slate-900/40">
            <x-ui.form.toggle
                :name="$name"
                :id="$id"
                :label="$f['label']"
                :description="$help"
                :checked="(bool) ($f['value'] ?? false)"
                :disabled="$disabled"
            >
                <x-settings.field-badges :readonly="$readonly" :public="$isPublic" class="mt-1.5" />
            </x-ui.form.toggle>
        </div>
    @else
        <div class="mb-1.5 flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
            <x-ui.form.label :for="$id" :required="$required">{{ $f['label'] }}</x-ui.form.label>
            <x-settings.field-badges :readonly="$readonly" :public="$isPublic" />
        </div>

        @switch ($type)
            @case ('textarea')
                <x-ui.form.textarea
                    :name="$name"
                    :id="$id"
                    :value="$f['value']"
                    :placeholder="$f['placeholder']"
                    :help="$help"
                    :rows="4"
                    :maxlength="$maxlength"
                    :counter="(bool) $maxlength"
                    :disabled="$disabled"
                />
                @break

            @case ('richtext')
                <x-ui.form.textarea
                    :name="$name"
                    :id="$id"
                    :value="$f['value']"
                    :placeholder="$f['placeholder']"
                    :help="$help ?? 'HTML is allowed here and is sanitised before it is rendered anywhere.'"
                    :rows="10"
                    :maxlength="$maxlength"
                    :disabled="$disabled"
                    class="font-mono"
                    spellcheck="false"
                />
                @break

            @case ('select')
                <x-ui.form.select
                    :name="$name"
                    :id="$id"
                    :options="$f['options']"
                    :selected="$f['value']"
                    :placeholder="$required ? null : ($f['placeholder'] ?? '— none —')"
                    :help="$help"
                    :disabled="$disabled"
                />
                @break

            @case ('multiselect')
                <x-settings.multiselect :field="$f" />
                @break

            @case ('color')
                <x-settings.color :field="$f" />
                @break

            @case ('image')
            @case ('file')
                <x-settings.upload :field="$f" />
                @break

            @case ('json')
                <x-settings.json :field="$f" />
                @break

            @case ('password')
                <x-settings.password :field="$f" />
                @break

            @case ('decimal')
                <x-ui.form.input
                    :name="$name"
                    :id="$id"
                    type="text"
                    inputmode="decimal"
                    :value="$f['value']"
                    :placeholder="$f['placeholder']"
                    :help="$help"
                    :suffix="$f['suffix']"
                    :disabled="$disabled"
                    class="tabular-nums"
                />
                @break

            @default
                <x-ui.form.input
                    :name="$name"
                    :id="$id"
                    :type="$inputTypes[$type] ?? 'text'"
                    :value="$f['value']"
                    :placeholder="$f['placeholder']"
                    :help="$help"
                    :suffix="$f['suffix']"
                    :maxlength="$maxlength"
                    :min="$numeric ? $min : null"
                    :max="$numeric ? $max : null"
                    :disabled="$disabled"
                    :class="$numeric ? 'tabular-nums' : null"
                />
        @endswitch
    @endif
</div>
