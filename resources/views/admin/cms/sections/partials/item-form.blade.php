{{--
    One repeater item form — add (item = null) or edit (phase-03 §8.5 repeaters, §8.7 statistics).

    @include('admin.cms.sections.partials.item-form', [
        'section' => $section,
        'group' => 'statistic',
        'repeater' => $repeater,     // SectionRegistry::repeater($key, $group)
        'item' => $item,             // ?array — one entry of SectionService::canonicalPayload()['items'][$group]:
                                     //   id, sort_order, content, metric, value_mode, manual_value, media_asset_id, is_enabled
        'refs' => $refs,
        'readonly' => ! $canEdit,
    ])

    Posts the item under `item[...]` exactly as Store/UpdateSectionItemRequest validate it:
      store  POST admin.website.sections.items.store {section}  group, item[field]…, _item = new_{group}
      update PUT  admin.website.section-items.update {item}     item[field]…,          _item = {id}
    `_item` only tells this view which form a refused save came from: every item form shares the `item[...]`
    names, so the refused input and its errors are shown in that form alone (the repeater partial hides
    them from the others).
--}}

@php
    use App\Enums\Cms\StatisticValueMode;
    use App\Support\Cms\SectionRegistry;

    $key = (string) $section->section_key;
    $formKey = $item !== null ? (string) $item['id'] : 'new_'.$group;
    $fields = SectionRegistry::itemFields($key, $group);
    $readonly = (bool) ($readonly ?? false);
    $content = $item !== null ? (is_array($item['content'] ?? null) ? $item['content'] : []) : SectionRegistry::itemDefaults($key, $group);
    $isStatistic = array_key_exists('value_mode', $fields);

    $valueOf = static function (string $name, array $field) use ($item, $content): mixed {
        if (($field['stored'] ?? SectionRegistry::STORED_CONTENT) === SectionRegistry::STORED_CONTENT) {
            return $content[$name] ?? $field['default'];
        }

        if ($item === null) {
            return $field['default'];
        }

        $value = $item[(string) ($field['column'] ?? $name)] ?? null;

        return $value instanceof \BackedEnum ? $value->value : $value;
    };

    $mode = old('item.value_mode', $isStatistic ? $valueOf('value_mode', $fields['value_mode']) : null);
    $action = $item !== null
        ? route('admin.website.section-items.update', ['item' => (int) $item['id']])
        : route('admin.website.sections.items.store', $section);
@endphp

<form
    method="POST"
    action="{{ $action }}"
    x-data="{ mode: @js((string) $mode) }"
    x-on:change="if ($event.target.name === 'item[value_mode]') mode = $event.target.value"
    class="space-y-4"
>
    @csrf
    @if ($item !== null)
        @method('PUT')
    @else
        <input type="hidden" name="group" value="{{ $group }}">
    @endif
    <input type="hidden" name="_item" value="{{ $formKey }}">

    @if ($errors->any())
        <div class="rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/25" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-12">
        @foreach ($fields as $name => $field)
            @php
                $span = (int) ($field['span'] ?? 12);
                $spanClass = match (true) {
                    $span <= 3 => 'sm:col-span-3',
                    $span <= 4 => 'sm:col-span-4',
                    $span <= 6 => 'sm:col-span-6',
                    $span <= 8 => 'sm:col-span-8',
                    $span <= 9 => 'sm:col-span-9',
                    default => 'sm:col-span-12',
                };
            @endphp

            <div
                class="{{ $spanClass }}"
                @if ($isStatistic && $name === 'metric') x-show="mode === @js(StatisticValueMode::Auto->value)" @if ($mode !== StatisticValueMode::Auto->value) x-cloak @endif @endif
            >
                @include('admin.cms.partials.field', [
                    'field' => $field,
                    'name' => 'item['.$name.']',
                    'value' => $valueOf($name, $field),
                    'refs' => $refs ?? [],
                    'readonly' => $readonly,
                ])
            </div>
        @endforeach
    </div>

    @unless ($readonly)
        <div class="flex justify-end gap-2">
            <x-ui.button type="submit" size="sm" :icon="$item !== null ? 'check' : 'plus'">
                {{ $item !== null ? 'Save '.($repeater['item_label'] ?? 'item') : 'Add '.($repeater['item_label'] ?? 'item') }}
            </x-ui.button>
        </div>
    @endunless
</form>
