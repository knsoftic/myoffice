{{--
    One filter control, built from its `FilterDefinition` (phase-19-23 6.20).

    Every filter reaching this partial is one the viewer may use - the engine stripped the rest
    before the schema was built - so there is nothing to gate here.

    The boolean is a three-state select rather than a checkbox, and that is the whole reason this
    partial exists rather than a generic input: "has outstanding" unset means every client, and "no"
    means only the ones who owe nothing. A checkbox cannot say the difference, and collapsing the two
    silently halves a report.
--}}
@php($value = $current[$filter->key] ?? null)

@switch ($filter->type)
    @case (\App\Enums\ReportFilterType::Select)
        <x-ui.form.select :name="'filters[' . $filter->key . ']'" :label="$filter->label" placeholder="Any">
            @foreach ($filter->options() as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
            @endforeach
        </x-ui.form.select>
        @break

    @case (\App\Enums\ReportFilterType::Multiselect)
        <x-ui.form.select :name="'filters[' . $filter->key . '][]'" :label="$filter->label" multiple>
            @foreach ($filter->options() as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected(in_array((string) $optionValue, array_map('strval', (array) $value), true))>
                    {{ $optionLabel }}
                </option>
            @endforeach
        </x-ui.form.select>
        @break

    @case (\App\Enums\ReportFilterType::Boolean)
        <x-ui.form.select :name="'filters[' . $filter->key . ']'" :label="$filter->label" placeholder="Any">
            <option value="1" @selected($value !== null && (string) $value === '1')>Yes</option>
            <option value="0" @selected($value !== null && (string) $value === '0')>No</option>
        </x-ui.form.select>
        @break

    @case (\App\Enums\ReportFilterType::NumberRange)
        <x-ui.form.input type="number" step="0.01" :name="'filters[' . $filter->key . '][min]'"
                         :label="$filter->label . ' (from)'" :value="$value['min'] ?? null" />
        <x-ui.form.input type="number" step="0.01" :name="'filters[' . $filter->key . '][max]'"
                         :label="$filter->label . ' (to)'" :value="$value['max'] ?? null" />
        @break

    @case (\App\Enums\ReportFilterType::DateRange)
        <x-ui.form.input type="date" :name="'filters[' . $filter->key . '][from]'"
                         :label="$filter->label . ' (from)'" :value="$value['from'] ?? null" />
        <x-ui.form.input type="date" :name="'filters[' . $filter->key . '][to]'"
                         :label="$filter->label . ' (to)'" :value="$value['to'] ?? null" />
        @break

    @case (\App\Enums\ReportFilterType::Date)
        <x-ui.form.input type="date" :name="'filters[' . $filter->key . ']'" :label="$filter->label" :value="$value" />
        @break

    @default
        {{-- Text, and `entity` until the record picker lands: an id typed in still filters. --}}
        <x-ui.form.input :name="'filters[' . $filter->key . ']'" :label="$filter->label"
                         :value="$value" :placeholder="$filter->placeholder" />
@endswitch
