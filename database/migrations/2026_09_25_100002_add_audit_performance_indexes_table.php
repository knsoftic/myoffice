<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24 · file 2 — the one migration this phase may ship against tables it does not own
 * ([D-P24-1], phase-24-25 §2.5).
 *
 * **Index additions only.** It may `ADD INDEX`; it may never add, alter or drop a column, never
 * drop an index it did not create, and its `down()` removes only what it added. Every index is
 * named `idx_p24_*` so its origin is greppable in six months, and every one is here because
 * `audit:manifest` measured its absence rather than because it seemed like a good idea.
 *
 * ---------------------------------------------------------------------------------------------
 * What the audit found
 * ---------------------------------------------------------------------------------------------
 *
 * 138 tables carry `created_by` / `updated_by`. **131 of them put a foreign key on those columns,
 * and the seven that do not are all Phase 13's finance tables** — `expenses`, `finance_categories`,
 * `finance_reversals`, `incomes`, `invoices`, `invoice_items`, `payment_methods`. A foreign key is
 * usually what creates the index, so those seven ended up with neither.
 *
 * The consequence is two separate problems, and this migration fixes exactly one of them:
 *
 * · **No index** means `blameable` lookups and any delete-path check against `users` scan the whole
 *   table. That is a performance defect, it is index-shaped, and it is fixed here.
 *
 * · **No foreign key** means `invoices.created_by` can point at a user id that no longer exists,
 *   with nothing to stop it. That is a referential-integrity defect on tables Phase 13 owns, and
 *   §1.3 forbids this phase from touching it — adding a constraint is not adding an index, and a
 *   constraint added from outside would also have to decide what `ON DELETE` means for a finance
 *   row, which is a business question Phase 13 answered for its other columns. It is recorded as a
 *   request to Phase 13 (§13) and as tech debt, not fixed here.
 *
 * Doing half a fix and saying so is better than doing all of it outside the rules: the half that is
 * in scope is real, and the half that is not stays visible instead of being quietly absorbed.
 *
 * **D70: MariaDB DDL is not transactional.** Each index is checked before it is added, so a
 * half-applied run heals on the next one.
 */
return new class extends Migration
{
    /**
     * table => [column, ...]. Every entry was reported missing by `audit:manifest`.
     *
     * `expenses.created_by` is deliberately absent: that one already has an index. The list is what
     * was measured, not what the pattern suggests.
     *
     * @var array<string, list<string>>
     */
    private const INDEXES = [
        'expenses' => ['updated_by'],
        'finance_categories' => ['created_by', 'updated_by'],
        'finance_reversals' => ['created_by', 'updated_by', 'performed_by'],
        'incomes' => ['created_by', 'updated_by'],
        'invoice_items' => ['created_by', 'updated_by'],
        'invoices' => ['created_by', 'updated_by'],
        'payment_methods' => ['created_by', 'updated_by'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $name = $this->name($table, $column);

                if (! RawSchema::indexExists($table, $name)) {
                    RawSchema::index($table, $name, [$column]);
                }
            }
        }
    }

    public function down(): void
    {
        // Only what this migration created. An index that happens to cover the same column but
        // carries another phase's name is that phase's to drop.
        foreach (self::INDEXES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                $name = $this->name($table, $column);

                if (RawSchema::indexExists($table, $name)) {
                    RawSchema::dropIndex($table, $name);
                }
            }
        }
    }

    /**
     * `idx_p24_{table}_{column}`, truncated to MariaDB's 64-character identifier limit.
     *
     * The prefix is the point: `grep idx_p24_` answers "what did Phase 24 add to somebody else's
     * table", which is the question a later phase will actually have.
     */
    private function name(string $table, string $column): string
    {
        return mb_substr(sprintf('idx_p24_%s_%s', $table, $column), 0, 64);
    }
};
