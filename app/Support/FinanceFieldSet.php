<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which columns of a finance screen this reader may actually see (phase-13 §4.5 rules 2 and 3).
 *
 * **Reaching a screen and seeing an amount are two different permissions.** Somebody with
 * `invoices.view_any` and not `invoices.view_financial` gets the register — number, client, dates,
 * status — and no subtotal, tax, total, paid or balance column.
 *
 * **A withheld column is absent, never rendered blank.** A blank cell says "there is a figure here and
 * you are not allowed to see it", which tells a reader the shape of what they are missing and invites
 * them to ask somebody who can. The Blade iterates {@see columns()}, and the CSV and the PDF build
 * their header rows from the same set, so all three withhold identically.
 */
final readonly class FinanceFieldSet
{
    /**
     * @param  list<string>  $visible  in display order
     * @param  list<string>  $money  the subset that carries an amount
     */
    public function __construct(
        public array $visible,
        public array $money,
        public bool $seesMoney,
    ) {}

    public function may(string $field): bool
    {
        return in_array($field, $this->visible, true);
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        return $this->visible;
    }

    /**
     * @return list<string>
     */
    public function moneyColumns(): array
    {
        return array_values(array_filter($this->money, fn (string $field): bool => $this->may($field)));
    }

    /**
     * Keep only what may be shown, in the set's own order — what a row builder runs each record
     * through before it reaches a view, a CSV writer or a JSON response.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function filter(array $row): array
    {
        $kept = [];

        foreach ($this->visible as $field) {
            if (array_key_exists($field, $row)) {
                $kept[$field] = $row[$field];
            }
        }

        return $kept;
    }
}
