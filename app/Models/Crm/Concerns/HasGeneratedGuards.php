<?php

declare(strict_types=1);

namespace App\Models\Crm\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Keeps Eloquent from ever writing a STORED generated guard column (phase-05 §2.3 `open_guard`, §2.4
 * `active_guard`, §2.8 `primary_guard`).
 *
 * The guards exist only to carry a unique index and are computed by MariaDB. Sending a value for one is refused by
 * the server in strict mode (error 1906), which would otherwise surface the first time a service `replicate()`s a
 * row that had been refreshed — a reschedule, for instance. Before every insert, and before an update that
 * touched a guard, the attribute is dropped from the write; the database recomputes it. Read the value after a
 * `refresh()` when it matters (the services decide on the status columns, not on the guard).
 *
 * @mixin Model
 */
trait HasGeneratedGuards
{
    public static function bootHasGeneratedGuards(): void
    {
        static::saving(static function (Model $model): void {
            /** @var Model&self $model */
            $model->withoutGeneratedGuardValues();
        });
    }

    /**
     * The STORED generated columns of the table.
     *
     * @return list<string>
     */
    abstract protected function generatedGuardColumns(): array;

    protected function withoutGeneratedGuardValues(): void
    {
        foreach ($this->generatedGuardColumns() as $column) {
            if (! array_key_exists($column, $this->attributes)) {
                continue;
            }

            if (! $this->exists || $this->isDirty($column)) {
                unset($this->attributes[$column]);
            }
        }
    }
}
