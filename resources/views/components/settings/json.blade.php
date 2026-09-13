@props([
    'field' => [],
])

{{--
    x-settings.json — the `json` type.

    Two shapes, chosen from the registry and not from the key's name:

      · **a row editor**, when the field declares child rules (`item_rules` like `*.open`,
        `*.close`, `*.closed`). `contact.business_hours` is the case that matters: one row per
        day, a control per child, and each control's input type taken from the child's own rule
        (`date_format:H:i` → a time input, `boolean` → a checkbox, anything else → text). A later
        json setting with children renders here too, with no edit to this file.

      · **a JSON textarea**, when there are no child rules. The posted string is decoded by
        UpdateSettingsRequest, so the stored value stays real JSON rather than a string holding
        JSON, and invalid JSON is reported as invalid JSON.

    Row keys come from what is stored; when nothing is stored they come from the registry default,
    so the editor is never an empty box an operator cannot fill.
--}}

@php
    $f = $field;

    $disabled = (bool) ($f['disabled'] ?? false);
    $name = (string) ($f['name'] ?? '');
    $errorKey = (string) ($f['error_key'] ?? '');

    $value = old($f['error_key'], $f['value'] ?? $f['default']);
    $value = is_array($value) ? $value : [];

    // Child columns, from the registry's item_rules.
    $children = [];

    foreach ((array) ($f['item_rules'] ?? []) as $suffix => $rules) {
        if (! preg_match('/^\*\.([A-Za-z0-9_]+)$/', (string) $suffix, $matches)) {
            continue;
        }

        $rules = array_map(static fn (mixed $rule): string => is_string($rule) ? $rule : '', (array) $rules);

        $type = 'text';

        foreach ($rules as $rule) {
            if (str_starts_with($rule, 'date_format:H:i')) {
                $type = 'time';
            } elseif ($rule === 'boolean') {
                $type = 'boolean';
            } elseif ($rule === 'integer' || $rule === 'numeric') {
                $type = 'number';
            }
        }

        $children[$matches[1]] = [
            'key' => $matches[1],
            'label' => ucfirst(str_replace('_', ' ', $matches[1])),
            'type' => $type,
        ];
    }

    // The rows to render: whatever is stored, else the registry default's keys.
    $rows = $value;

    if ($rows === [] && is_array($f['default'] ?? null)) {
        $rows = $f['default'];
    }

    $isRowEditor = $children !== [] && $rows !== [];

    // The boolean child (if any) gates the other controls in the same row.
    $gate = null;

    foreach ($children as $child) {
        if ($child['type'] === 'boolean') {
            $gate = $child['key'];

            break;
        }
    }
@endphp

@if ($isRowEditor)
    <div class="overflow-hidden rounded-lg ring-1 ring-slate-200 dark:ring-slate-700">
        <div class="divide-y divide-slate-200 dark:divide-slate-800">
            @foreach ($rows as $rowKey => $row)
                @php
                    $row = is_array($row) ? $row : [];
                    $rowName = $name.'['.$rowKey.']';
                    $closed = $gate !== null && filter_var($row[$gate] ?? false, FILTER_VALIDATE_BOOLEAN);
                @endphp

                <div
                    class="flex flex-wrap items-center gap-x-4 gap-y-2 px-3 py-2.5 odd:bg-slate-50/60 dark:odd:bg-slate-900/40"
                    x-data="{ off: @js($closed) }"
                >
                    <span class="w-24 shrink-0 text-sm font-medium capitalize text-slate-700 dark:text-slate-200">
                        {{ str_replace('_', ' ', (string) $rowKey) }}
                    </span>

                    <div class="flex flex-1 flex-wrap items-center gap-3">
                        @foreach ($children as $child)
                            @php
                                $childName = $rowName.'['.$child['key'].']';
                                $childId = $f['id'].'-'.\Illuminate\Support\Str::slug((string) $rowKey).'-'.$child['key'];
                                $childValue = $row[$child['key']] ?? null;
                            @endphp

                            @if ($child['type'] === 'boolean')
                                <label class="ml-auto flex items-center gap-2 text-xs font-medium text-slate-600 dark:text-slate-300">
                                    {{-- A read-only form posts nothing: the hidden 0 exists only beside an enabled checkbox. --}}
                                    @unless ($disabled)
                                        <input type="hidden" name="{{ $childName }}" value="0" />
                                    @endunless
                                    <input
                                        type="checkbox"
                                        id="{{ $childId }}"
                                        name="{{ $childName }}"
                                        value="1"
                                        x-model="off"
                                        @checked($closed)
                                        @disabled($disabled)
                                        class="h-4 w-4 rounded border-slate-300 text-brand-600 shadow-sm focus:ring-2 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-900"
                                    />
                                    {{ $child['label'] }}
                                </label>
                            @else
                                <span class="flex items-center gap-1.5" x-bind:class="off ? 'opacity-40' : ''">
                                    <label for="{{ $childId }}" class="text-2xs font-medium uppercase tracking-wide text-slate-400 dark:text-slate-500">
                                        {{ $child['label'] }}
                                    </label>
                                    <input
                                        type="{{ $child['type'] === 'number' ? 'number' : ($child['type'] === 'time' ? 'time' : 'text') }}"
                                        id="{{ $childId }}"
                                        name="{{ $childName }}"
                                        value="{{ $childValue }}"
                                        @disabled($disabled)
                                        x-bind:disabled="off || {{ $disabled ? 'true' : 'false' }}"
                                        class="w-28 rounded-lg border-slate-300 bg-white py-1.5 text-xs tabular-nums shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 disabled:bg-slate-100 disabled:text-slate-400 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white dark:disabled:bg-slate-900"
                                    />
                                </span>
                            @endif
                        @endforeach
                    </div>

                    @foreach ($children as $child)
                        <x-ui.form.error :for="$rowName.'['.$child['key'].']'" class="w-full" />
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
@else
    <x-ui.form.textarea
        :name="$name"
        :id="$f['id']"
        :value="is_array($value) || is_object($value) ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $value"
        :rows="8"
        :disabled="$disabled"
        class="font-mono"
        spellcheck="false"
    />
@endif

@if (filled($f['help'] ?? null))
    <x-ui.form.help>{{ $f['help'] }}</x-ui.form.help>
@endif

@if ($isRowEditor)
    <x-ui.form.error :for="$errorKey" />
@endif
