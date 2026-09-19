<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 · §2.8 — client_contacts: the named people of a client, and the additional portal logins.
 *
 * `primary_guard` is a STORED generated column (`1` for the primary contact, NULL otherwise) carrying
 * `UNIQUE uq_cc_primary(client_id, primary_guard)`: MariaDB unique indexes ignore NULLs, so any number of
 * non-primary contacts stack while **at most one primary contact per client** can exist (§11 test 60). It is
 * written as raw SQL exactly as the contract states, and the migration **fails loudly** — it never skips — when
 * the server cannot create it (§11 test 3). The expression is the contract's verbatim: a soft-deleted primary
 * still holds the slot, so `ClientContactService` demotes a contact before it trashes it.
 *
 * `UNIQUE uq_cc_user(user_id)` makes one login one contact; together with `clients.uq_clients_user` and the
 * service's explicit check, a user can never be bound to two clients (§9.2, §11 test 61).
 *
 * Mutable profile table → timestamps + softDeletes + blameable (CLAUDE.md §3, D19).
 */
return new class extends Migration
{
    private const TABLE = 'client_contacts';

    private const GUARD_COLUMN = 'primary_guard';

    private const GUARD_EXPRESSION = 'CASE WHEN `is_primary` = 1 THEN 1 ELSE NULL END';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name', 150);
                $table->string('designation', 96)->nullable();
                $table->string('department', 96)->nullable();
                $table->string('email', 150)->nullable();
                $table->string('email_normalized', 150)->nullable();
                $table->string('phone', 32)->nullable();
                $table->string('phone_normalized', 32)->nullable();
                $table->string('whatsapp', 32)->nullable();
                $table->boolean('is_primary')->default(false);
                $table->boolean('is_billing_contact')->default(false);
                $table->boolean('portal_access')->default(false);
                $table->boolean('receives_notifications')->default(true);
                $table->string('notes', 255)->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.8 Keys (uq_cc_primary is added with its generated column below).
                $table->unique('user_id', 'uq_cc_user');
                $table->index(['client_id', 'is_primary']);
                $table->index('email_normalized');
                $table->index('phone_normalized');
                $table->index('portal_access');
            });
        }

        $this->addStoredGuard();

        if (! Schema::hasIndex(self::TABLE, 'uq_cc_primary')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['client_id', self::GUARD_COLUMN], 'uq_cc_primary');
            });
        }
    }

    public function down(): void
    {
        // Nothing references client_contacts. DROP TABLE removes its three foreign keys, the generated column
        // and both unique guards.
        Schema::dropIfExists(self::TABLE);
    }

    /**
     * §2.8: the STORED generated column, as raw SQL. Any server error propagates (a QueryException), and a
     * column that exists but is not STORED is refused — the unique guard would otherwise be built on sand.
     */
    private function addStoredGuard(): void
    {
        if (! Schema::hasColumn(self::TABLE, self::GUARD_COLUMN)) {
            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD COLUMN `%s` TINYINT AS (%s) STORED AFTER `is_primary`',
                self::TABLE,
                self::GUARD_COLUMN,
                self::GUARD_EXPRESSION
            ));
        }

        $this->assertStoredGenerated(self::TABLE, self::GUARD_COLUMN);
    }

    private function assertStoredGenerated(string $table, string $column): void
    {
        $row = DB::selectOne(
            'SELECT IS_GENERATED AS is_generated, EXTRA AS extra FROM information_schema.COLUMNS'
            .' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [DB::getDatabaseName(), $table, $column]
        );

        $extra = strtoupper((string) ($row->extra ?? ''));

        if ($row === null
            || strtoupper((string) $row->is_generated) !== 'ALWAYS'
            || (! str_contains($extra, 'STORED') && ! str_contains($extra, 'PERSISTENT'))) {
            throw new RuntimeException(sprintf(
                'phase-05 §2.8: `%s`.`%s` must be a STORED generated column (it carries uq_cc_primary). '
                .'The server did not create it as one; fix the database server rather than skipping the guard.',
                $table,
                $column
            ));
        }
    }
};
