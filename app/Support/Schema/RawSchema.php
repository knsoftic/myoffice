<?php

declare(strict_types=1);

namespace App\Support\Schema;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The raw-SQL schema objects the query builder cannot express, each **proved after it is written**.
 *
 * CHECK constraints, STORED generated columns, unique indexes over generated columns and `BEFORE DELETE`
 * triggers are the guarantees the financial tables' invariants actually rest on. MariaDB is willing to
 * accept some of these silently and keep none of them — older versions parsed a CHECK and discarded it —
 * and a constraint the server quietly ignored is **worse than none at all**, because every layer above
 * it goes on trusting a promise that is not there.
 *
 * So every method here writes the object and then reads `information_schema` back. If the server did not
 * keep it, the migration throws. No try/catch, no `if (! exists)` skip, no "it works on the other
 * server" (finance spine R-3).
 *
 * Phase 7's step-18 migration established this pattern; this class is that pattern made reusable, so
 * twenty-one Phase 10 migrations do not each carry their own copy of it.
 */
final class RawSchema
{
    /**
     * The abbreviation a long financial table uses in a constraint name.
     *
     * MariaDB caps an identifier at 64 characters, and Laravel's generated foreign-key name is
     * `table_column_foreign` — which overflows on `collaborator_commission_entitlements` and friends.
     * The map is explicit rather than derived, because the name has to be **stable**: `down()` has to
     * find the same constraint `up()` created, and a hash or a truncation that shifts when a column is
     * renamed leaves a key nobody can drop.
     *
     * @var array<string, string>
     */
    private const ABBREVIATIONS = [
        'collaborator_commission_ledger_entries' => 'cle',
        'collaborator_commission_entitlements' => 'cce',
        'collaborator_commission_settings' => 'ccs',
        'collaborator_wallet_reconciliations' => 'cwr',
        'collaborator_payout_allocations' => 'cpa',
        'collaborator_payout_accounts' => 'cpacc',
        'collaborator_referral_visits' => 'crv',
        'collaborator_referrals' => 'cr',
        'collaborator_payouts' => 'cp',
        'collaborator_wallets' => 'cw',
        'student_fee_installments' => 'sfi',
        'student_fee_discounts' => 'sfd',
        'student_fee_payments' => 'sfp',
        'student_fees' => 'sf',
        'payment_reversals' => 'pr',
        'project_payments' => 'pp',
    ];

    /**
     * The foreign-key name for a column — Laravel's own when it fits, an abbreviated one when it does not.
     *
     * Keeping Laravel's name wherever it fits matters: every other migration in this codebase relies on
     * it, and a scheme that renamed all of them would make a `down()` written last year stop working.
     */
    public static function foreignKeyName(string $table, string $column): string
    {
        $natural = $table.'_'.$column.'_foreign';

        if (strlen($natural) <= 64) {
            return $natural;
        }

        $short = 'fk_'.(self::ABBREVIATIONS[$table] ?? $table).'_'.$column;

        if (strlen($short) > 64) {
            // Only reachable with a column name of forty-odd characters; trimmed here rather than
            // handed to the server as an error nobody can act on.
            $short = substr($short, 0, 64);
        }

        return $short;
    }

    /**
     * Add a CHECK constraint and prove it survived.
     */
    public static function check(string $table, string $name, string $expression): void
    {
        DB::statement(sprintf('ALTER TABLE `%s` ADD CONSTRAINT `%s` CHECK (%s)', $table, $name, $expression));

        self::assert(
            self::checkExists($name),
            sprintf('the CHECK constraint %s on %s', $name, $table),
        );
    }

    public static function checkExists(string $name): bool
    {
        return DB::table('information_schema.CHECK_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('CONSTRAINT_NAME', $name)
            ->exists();
    }

    /**
     * Add a STORED generated column and prove it is stored.
     *
     * **STORED, never VIRTUAL.** A unique index over a virtual column is legal in MariaDB but is
     * recomputed on every read, and the guard indexes these columns exist for are the ones that have to
     * bite on a concurrent INSERT.
     */
    public static function generatedColumn(string $table, string $column, string $type, string $expression): void
    {
        DB::statement(sprintf(
            'ALTER TABLE `%s` ADD COLUMN `%s` %s AS (%s) STORED',
            $table,
            $column,
            $type,
            $expression,
        ));

        $stored = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->value('EXTRA');

        self::assert(
            is_string($stored) && str_contains(strtolower($stored), 'stored generated'),
            sprintf('the STORED generated column %s.%s (server reported "%s")', $table, $column, (string) $stored),
        );
    }

    /**
     * Add a unique index and prove it is unique.
     *
     * @param  list<string>  $columns
     */
    public static function uniqueIndex(string $table, string $name, array $columns): void
    {
        DB::statement(sprintf(
            'ALTER TABLE `%s` ADD UNIQUE INDEX `%s` (%s)',
            $table,
            $name,
            implode(', ', array_map(static fn (string $c): string => '`'.$c.'`', $columns)),
        ));

        self::assert(
            self::indexExists($table, $name, unique: true),
            sprintf('the unique index %s on %s', $name, $table),
        );
    }

    /**
     * Add a plain index and prove it exists.
     *
     * @param  list<string>  $columns
     */
    public static function index(string $table, string $name, array $columns): void
    {
        DB::statement(sprintf(
            'ALTER TABLE `%s` ADD INDEX `%s` (%s)',
            $table,
            $name,
            implode(', ', array_map(static fn (string $c): string => '`'.$c.'`', $columns)),
        ));

        self::assert(
            self::indexExists($table, $name),
            sprintf('the index %s on %s', $name, $table),
        );
    }

    public static function indexExists(string $table, string $name, bool $unique = false): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $name)
            ->when($unique, static fn ($query) => $query->where('NON_UNIQUE', 0))
            ->exists();
    }

    /**
     * Refuse every DELETE on an append-only table, at the database.
     *
     * The model hook throws first and says why; this is the layer that holds when somebody reaches the
     * table with a raw query, a console command or a database client. The message is written for the
     * person who will read it at two in the morning, because the spine's R-5 lesson is that a bare
     * `SQLSTATE 45000` with no explanation is how a trigger eventually gets dropped.
     */
    public static function noDeleteTrigger(string $table, string $trigger, string $why): void
    {
        DB::unprepared(sprintf(
            "CREATE TRIGGER `%s` BEFORE DELETE ON `%s` FOR EACH ROW\n"
            ."BEGIN\n"
            ."    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = %s;\n"
            .'END',
            $trigger,
            $table,
            self::quote($why),
        ));

        self::assert(
            self::triggerExists($trigger),
            sprintf('the BEFORE DELETE trigger %s on %s', $trigger, $table),
        );
    }

    public static function triggerExists(string $name): bool
    {
        return DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->where('TRIGGER_NAME', $name)
            ->exists();
    }

    public static function dropTrigger(string $name): void
    {
        DB::statement(sprintf('DROP TRIGGER IF EXISTS `%s`', $name));
    }

    public static function dropIndex(string $table, string $name): void
    {
        if (self::indexExists($table, $name)) {
            DB::statement(sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $table, $name));
        }
    }

    public static function dropColumn(string $table, string $column): void
    {
        $exists = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->exists();

        if ($exists) {
            DB::statement(sprintf('ALTER TABLE `%s` DROP COLUMN `%s`', $table, $column));
        }
    }

    /**
     * MariaDB's message text is capped at 128 characters; a longer one is a runtime error on the trigger
     * rather than a helpful explanation, so it is trimmed here where the trimming is visible.
     */
    private static function quote(string $message): string
    {
        $message = mb_substr($message, 0, 128);

        return "'".str_replace("'", "''", $message)."'";
    }

    private static function assert(bool $kept, string $what): void
    {
        if ($kept) {
            return;
        }

        throw new RuntimeException(sprintf(
            'This server did not keep %s. The financial tables would be stating a guarantee they do not '
            .'have, so the migration stops here rather than leaving an invariant that is true only in '
            .'the documentation (finance spine R-3).',
            $what,
        ));
    }
}
