<?php

declare(strict_types=1);

namespace App\DataObjects\Reporting;

use App\Enums\ReportFilterType;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

/**
 * One filter on one report (phase-19-23 §6.20).
 *
 * **A filter a viewer may not use is absent, and its value is discarded** — the same rule as a
 * column, for the same reason. A "collaborator" filter left on the page for somebody without
 * `collaborators.view` would let them enumerate collaborator ids by watching the row count change.
 * So the engine strips the filter *and* the submitted value; a stripped filter never narrows.
 *
 * **`options` may be a callable, and it is invoked here and nowhere else.** A list of branches or
 * courses needs the database, a list of statuses does not, and no screen should have to know which
 * is which. A provider that throws returns an empty list rather than breaking the filter bar: a
 * report that opens with one empty dropdown is recoverable, a report that 500s is not.
 */
final readonly class FilterDefinition
{
    /**
     * @param  string  $key  the key this filter is submitted under
     * @param  array<string, string>|callable(): array<string, string>|null  $options
     * @param  string|null  $permission  withheld → the filter is absent and its value discarded
     * @param  int  $span  1–12 grid columns in the filter bar
     */
    public function __construct(
        public string $key,
        public string $label,
        public ReportFilterType $type = ReportFilterType::Select,
        public mixed $options = null,
        public mixed $default = null,
        public ?string $permission = null,
        public int $span = 3,
        public ?string $placeholder = null,
        public ?string $help = null,
    ) {
        if ($type->needsOptions() && $options === null) {
            throw new \InvalidArgumentException(sprintf(
                'Filter [%s] is a %s and needs options — a dropdown with nothing in it is a control '
                .'nobody can use.',
                $key,
                $type->value,
            ));
        }
    }

    public static function select(string $key, string $label, mixed $options, mixed $default = null): self
    {
        return new self($key, $label, ReportFilterType::Select, $options, $default);
    }

    public static function multiselect(string $key, string $label, mixed $options): self
    {
        return new self($key, $label, ReportFilterType::Multiselect, $options, span: 4);
    }

    /** A searchable reference to a row. `$permission` gates both the control and the value. */
    public static function entity(string $key, string $label, ?string $permission = null): self
    {
        return new self($key, $label, ReportFilterType::Entity, permission: $permission);
    }

    public static function boolean(string $key, string $label): self
    {
        return new self($key, $label, ReportFilterType::Boolean, span: 2);
    }

    public static function text(string $key, string $label): self
    {
        return new self($key, $label, ReportFilterType::Text);
    }

    public static function numberRange(string $key, string $label): self
    {
        return new self($key, $label, ReportFilterType::NumberRange, span: 4);
    }

    public static function dateRange(string $key, string $label): self
    {
        return new self($key, $label, ReportFilterType::DateRange, span: 4);
    }

    /**
     * May this person use this filter?
     *
     * @see ColumnDefinition::isVisibleTo() — the same rule, deliberately the same shape.
     */
    public function isVisibleTo(?Authenticatable $user): bool
    {
        if ($this->permission === null) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return app(Gate::class)->forUser($user)->allows($this->permission);
    }

    /**
     * The options, resolved.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = $this->options;

        if (is_callable($options)) {
            try {
                $options = $options();
            } catch (Throwable) {
                // A provider that needs a table another phase has not shipped must not break the
                // filter bar. An empty dropdown is a visible gap; a 500 is a report nobody can open.
                return [];
            }
        }

        if (! is_array($options)) {
            return [];
        }

        $resolved = [];

        foreach ($options as $value => $label) {
            $resolved[(string) $value] = is_scalar($label) ? (string) $label : (string) $value;
        }

        return $resolved;
    }

    /**
     * The Laravel rules this filter's submitted value is checked against.
     *
     * A select gains an `in:` built from its own resolved options, so the form and the validator
     * cannot disagree about what is allowed — the same trick `SettingsRegistry::rulesFor()` plays.
     * A filter whose options come from the database and resolve to nothing skips the `in:` rather
     * than refusing every value: an empty list means the source is unavailable, not that no value
     * is legal.
     *
     * @return array<string, list<string>>
     */
    public function rules(string $prefix = 'filters'): array
    {
        $base = $prefix === '' ? $this->key : $prefix.'.'.$this->key;
        $rules = [$base => $this->type->rules()];

        if ($this->type->needsOptions()) {
            $options = array_keys($this->options());

            if ($options !== []) {
                $in = 'in:'.implode(',', $options);

                if ($this->type === ReportFilterType::Multiselect) {
                    $rules[$base.'.*'] = ['string', $in];
                } else {
                    $rules[$base][] = $in;
                }
            }
        }

        foreach ($this->type->itemRules() as $suffix => $itemRules) {
            if ($suffix === '*' && $this->type->needsOptions()) {
                continue; // already written above, with the `in:` attached
            }

            $rules[$base.'.'.$suffix] = $itemRules;
        }

        return $rules;
    }

    /**
     * The JSON the filter bar is built from.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type->value,
            'options' => $this->type->needsOptions() ? $this->options() : null,
            'default' => $this->default,
            'span' => $this->span,
            'placeholder' => $this->placeholder,
            'help' => $this->help,
            'tri_state' => $this->type->isTriState(),
            'range' => $this->type->isRange(),
        ];
    }
}
