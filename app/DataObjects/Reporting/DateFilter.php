<?php

declare(strict_types=1);

namespace App\DataObjects\Reporting;

/**
 * The **named date column** a report measures on (phase-19-23 §6.20, generalising phase-13 §6.7).
 *
 * This is the smallest object in the reporting layer and the one that prevents the most confusing
 * class of bug. "Fees this month" can mean fees *raised* this month, *due* this month or *paid*
 * this month, and the three give different numbers from the same table. When the column lives in a
 * `where()` inside a service, the screen shows one answer, the CSV another and the printed header
 * says only "September" — and nobody can tell which question was asked.
 *
 * So the column is named here, carried in `ReportResult::meta['date_column']`, and printed on every
 * rendering. A report that measures on more than one column offers the choice through
 * {@see self::$alternatives}; picking one changes the figures and says so.
 */
final readonly class DateFilter
{
    /**
     * @param  string  $column  the qualified column the range is applied to (`student_fees.paid_at`)
     * @param  string  $label  what that column *means* in a sentence — "Paid on", not "paid_at"
     * @param  array<string, string>  $alternatives  column => label, when the report offers a choice
     * @param  bool  $required  a report that cannot be run unbounded (a ledger, a log)
     */
    public function __construct(
        public string $column,
        public string $label,
        public array $alternatives = [],
        public bool $required = false,
    ) {}

    /**
     * Every column this report can measure on, the default first.
     *
     * @return array<string, string>
     */
    public function choices(): array
    {
        return [$this->column => $this->label] + $this->alternatives;
    }

    /** Does the report let the reader change what "this month" means? */
    public function hasChoice(): bool
    {
        return $this->alternatives !== [];
    }

    /**
     * Resolve a submitted column to one this report actually offers.
     *
     * An unknown column falls back to the default rather than being applied: a request naming a
     * column the report does not measure on is either a stale bookmark or an attempt to filter on
     * a column that was never exposed, and both should produce the report's own default answer.
     */
    public function resolve(?string $requested): string
    {
        if ($requested === null || $requested === '') {
            return $this->column;
        }

        return array_key_exists($requested, $this->choices()) ? $requested : $this->column;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'column' => $this->column,
            'label' => $this->label,
            'choices' => $this->choices(),
            'required' => $this->required,
        ];
    }
}
