<?php

declare(strict_types=1);

namespace App\DataObjects\Reporting;

use App\Enums\ReportColumnType;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * One column of one report (phase-19-23 §6.20).
 *
 * **A column a viewer may not see is absent, not blank** (INV-23-2). That is the reason this object
 * carries a `permission` at all: the engine asks {@see self::isVisibleTo()} *before* it builds the
 * query, so the value is never selected, never cached, never written to a file and never present in
 * the JSON the filter bar is built from. A blanked cell in a column somebody can still total is
 * worse than a missing column, because the total looks complete.
 *
 * `total` is the aggregate taken down the column, and it is checked against the type: asking for a
 * `sum` of a date column is a mistake the constructor refuses rather than one the footer renders.
 */
final readonly class ColumnDefinition
{
    /**
     * @param  string  $key  the key this column appears under in every `ReportResult` row
     * @param  string  $label  the heading, on screen and in the file
     * @param  ReportColumnType  $type  decides formatting, alignment and whether it is financial
     * @param  string|null  $permission  withheld → the column is absent everywhere
     * @param  'sum'|'avg'|'count'|null  $total  the footer aggregate
     * @param  string|null  $align  overrides the type's own alignment; rarely needed
     * @param  int|null  $width  a hint in grid units, not a pixel count
     * @param  string|null  $help  a tooltip on the heading, for a column whose name is not enough
     */
    public function __construct(
        public string $key,
        public string $label,
        public ReportColumnType $type = ReportColumnType::Text,
        public bool $sortable = true,
        public ?string $permission = null,
        public ?string $total = null,
        public ?string $align = null,
        public ?int $width = null,
        public ?string $help = null,
    ) {
        if ($total !== null && ! in_array($total, ['sum', 'avg', 'count'], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Column [%s] asks for an unknown total [%s]. Use sum, avg, count or null.',
                $key,
                $total,
            ));
        }

        // A sum of a date column is a number nobody can read. `count` is allowed on anything,
        // because counting non-empty cells is meaningful for every type.
        if (in_array($total, ['sum', 'avg'], true) && ! $type->isSummable()) {
            throw new \InvalidArgumentException(sprintf(
                'Column [%s] is a %s and cannot be totalled with %s — only number and money columns can.',
                $key,
                $type->value,
                $total,
            ));
        }
    }

    /** A plain text column: the common case, spelled short. */
    public static function text(string $key, string $label, bool $sortable = true): self
    {
        return new self($key, $label, ReportColumnType::Text, $sortable);
    }

    /**
     * A money column, gated by the owning module's `view_financial` unless another is named.
     *
     * The permission is **not** optional with a null default here the way it is on the constructor:
     * a money column with no gate is the defect INV-23-2 exists to prevent, so the caller has to
     * name the module whose financial permission applies.
     */
    public static function money(string $key, string $label, string $module, ?string $total = 'sum'): self
    {
        return new self(
            key: $key,
            label: $label,
            type: ReportColumnType::Money,
            permission: $module.'.view_financial',
            total: $total,
        );
    }

    public static function number(string $key, string $label, ?string $total = null): self
    {
        return new self($key, $label, ReportColumnType::Number, total: $total);
    }

    public static function percent(string $key, string $label): self
    {
        return new self($key, $label, ReportColumnType::Percent);
    }

    public static function date(string $key, string $label): self
    {
        return new self($key, $label, ReportColumnType::Date);
    }

    public static function datetime(string $key, string $label): self
    {
        return new self($key, $label, ReportColumnType::DateTime);
    }

    public static function badge(string $key, string $label): self
    {
        return new self($key, $label, ReportColumnType::Badge);
    }

    public static function link(string $key, string $label): self
    {
        return new self($key, $label, ReportColumnType::Link);
    }

    /**
     * May this person see this column?
     *
     * A null permission means everybody who can open the report. A null user means nobody — a
     * console run with no actor gets the un-gated columns only, which is the safe reading.
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

    public function isFinancial(): bool
    {
        return $this->type->isFinancial();
    }

    public function alignment(): string
    {
        return $this->align ?? $this->type->align();
    }

    /** Is a footer aggregate taken down this column? */
    public function isTotalled(): bool
    {
        return $this->total !== null;
    }

    /**
     * The JSON the column picker and the filter bar are built from.
     *
     * `permission` is deliberately absent: the schema is only ever built for columns the viewer may
     * already see, and naming the permission that gates a column a reader cannot have tells them
     * about a column they were not shown.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type->value,
            'align' => $this->alignment(),
            'sortable' => $this->sortable,
            'total' => $this->total,
            'width' => $this->width,
            'help' => $this->help,
            'decimals' => $this->type->decimals(),
        ];
    }
}
