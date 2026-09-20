<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * A model whose table carries STORED generated columns.
 *
 * MariaDB refuses an INSERT that names one — `1906 The value specified for generated column … has been
 * ignored` — and Eloquent's `replicate()` copies **every** loaded attribute, so duplicating one of these
 * rows fails on a column the caller never mentioned. The error names the column but not the reason, and
 * the obvious reading ("something is wrong with my data") is the wrong one.
 *
 * Declaring the columns here does two things: `replicate()` drops them automatically, and the list is
 * written down where somebody adding a cast or a fillable can see it.
 *
 * @mixin Model
 */
trait HasGeneratedColumns
{
    /**
     * The columns the database computes. Never written, never mass-assigned, never replicated.
     *
     * @return list<string>
     */
    abstract public function generatedColumns(): array;

    /**
     * @param  array<int, string>|null  $except
     */
    public function replicate(?array $except = null): static
    {
        return parent::replicate(array_values(array_unique(
            array_merge($except ?? [], $this->generatedColumns())
        )));
    }
}
