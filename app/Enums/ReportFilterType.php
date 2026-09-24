<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What one report filter asks for (phase-19-23 §6.20).
 *
 * The type decides three things at once: which control the filter bar renders, which validation
 * rules the request is checked against, and how the value is written into `meta.filters` so the
 * printed header can say what the figures covered. Keeping all three on the enum is what stops a
 * filter that validates as a string from rendering as a date picker.
 *
 * **`Entity` is the one that is not a plain input.** A client, a batch, a collaborator — a list too
 * long to put in a `<select>`, so the control is a search field and the stored value is an id. It
 * validates as an integer and is resolved by the filter's own `options` callable, never by the
 * engine: only the report knows which table the id belongs to, and only its own service knows which
 * rows this viewer may pick from.
 */
enum ReportFilterType: string
{
    use HasOptions;

    /** One choice from a short list. */
    case Select = 'select';

    /** Several choices from a short list. Absent means "all", never "none". */
    case Multiselect = 'multiselect';

    case Date = 'date';

    /** A from/to pair. Distinct from the report's own `dateFilter()`, which measures the figures. */
    case DateRange = 'date_range';

    /** A min/max pair over a numeric column — an amount band, an age band. */
    case NumberRange = 'number_range';

    case Text = 'text';

    /** A three-state tick: yes, no, or unset. Unset is not false — see {@see self::isTriState()}. */
    case Boolean = 'boolean';

    /** A searchable reference to a row: client, batch, teacher, collaborator. */
    case Entity = 'entity';

    public function label(): string
    {
        return match ($this) {
            self::Select => 'Choice',
            self::Multiselect => 'Several choices',
            self::Date => 'Date',
            self::DateRange => 'Date range',
            self::NumberRange => 'Number range',
            self::Text => 'Text',
            self::Boolean => 'Yes or no',
            self::Entity => 'Record',
        };
    }

    /** Does the control need a list of options to render? */
    public function needsOptions(): bool
    {
        return $this === self::Select || $this === self::Multiselect;
    }

    /** Is the submitted value a pair rather than a scalar? */
    public function isRange(): bool
    {
        return $this === self::DateRange || $this === self::NumberRange;
    }

    /**
     * Does an unset value mean something different from `false`?
     *
     * For `Boolean`, yes, and it matters: "has outstanding" unset means *every* client, while
     * "has outstanding = no" means only the ones who owe nothing. Collapsing the two is how a
     * filter silently halves a report.
     */
    public function isTriState(): bool
    {
        return $this === self::Boolean;
    }

    /**
     * The Laravel rules a submitted value is checked against, before the report's own `options`
     * narrow it further.
     *
     * Every rule here is `nullable`: an unset filter is the normal case, and a report whose filters
     * were all required would be a report nobody could open.
     *
     * @return list<string>
     */
    public function rules(): array
    {
        return match ($this) {
            self::Select, self::Text => ['nullable', 'string', 'max:190'],
            self::Multiselect => ['nullable', 'array'],
            self::Date => ['nullable', 'date'],
            self::DateRange => ['nullable', 'array:from,to'],
            self::NumberRange => ['nullable', 'array:min,max'],
            self::Boolean => ['nullable', 'boolean'],
            self::Entity => ['nullable', 'integer', 'min:1'],
        };
    }

    /**
     * Rules for the children of a filter whose value is an array, keyed by suffix.
     *
     * @return array<string, list<string>>
     */
    public function itemRules(): array
    {
        return match ($this) {
            self::Multiselect => ['*' => ['string', 'max:190']],
            self::DateRange => ['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:filters.*.from']],
            self::NumberRange => ['min' => ['nullable', 'numeric'], 'max' => ['nullable', 'numeric']],
            default => [],
        };
    }
}
