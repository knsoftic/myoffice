<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 · §2.1 — projects: §20's delivery record, plus the five columns the finance spine's §13.1 needs.
 *
 * Three STORED generated columns carry guarantees Laravel's schema builder cannot express, so they are raw
 * SQL and the migration **fails loudly** rather than skipping one (INV-P17, [D-P6-7]):
 *
 *   · `net_value` = `project_value - discount_amount` (INV-P2). PHP never writes it, so the denominator the
 *     spine pays project commission against cannot drift from the two columns it is made of.
 *   · `actual_minutes` / `actual_hours` over `actual_seconds` (INV-P7) — §22's `actual_hours` field exists
 *     and is unwritable; the seconds cache underneath is recomputed by SUM under a row lock, never
 *     incremented (INV-P6).
 *
 * `collaborator_id` is a plain indexed `unsignedBigInteger` here and is promoted to a real FK by Phase 8's
 * guarded migration ([D-P6-1]), because `collaborators` does not exist yet. It is a **display snapshot
 * only** (R5, D37): no scope and no commission engine reads it — the authority for attribution is an
 * `active` `collaborator_referrals` row. `referral_code` and `referral_date` are likewise snapshots taken
 * at linking; a later code change never rewrites them (§45).
 *
 * `client_id` is `restrictOnDelete`: a project is always somebody's work, and the spine denormalises
 * `client_id` onto every payment, so a vanished client would strand money.
 *
 * Six CHECKs (§2.1). `chk_projects_commission` is the load-bearing one: an override can never be
 * half-configured, which is exactly what would make the spine's `project_override` branch resolve a NULL
 * rate. `chk_projects_manual_progress` makes [D-P6-3]'s "reasoned override" a database rule.
 *
 * Mutable business table → timestamps + softDeletes (archive) + blameable (CLAUDE.md §3, D19).
 */
return new class extends Migration
{
    private const TABLE = 'projects';

    /**
     * §2.1 / §2.14: name => [column definition, generation expression, position].
     */
    private const GENERATED = [
        'net_value' => ['DECIMAL(15,2)', '`project_value` - `discount_amount`', 'discount_amount'],
        'actual_minutes' => ['INT UNSIGNED', 'ROUND(`actual_seconds` / 60)', 'actual_seconds'],
        'actual_hours' => ['DECIMAL(10,2)', 'ROUND(`actual_seconds` / 3600, 2)', 'actual_minutes'],
    ];

    private const CHECKS = [
        'chk_projects_progress' => '`progress_percent` >= 0 and `progress_percent` <= 100',
        'chk_projects_money' => '`project_value` >= 0 and `discount_amount` >= 0 and `budget_amount` >= 0'
            .' and `discount_amount` <= `project_value`',
        'chk_projects_dates' => '`start_date` is null or `deadline` is null or `deadline` >= `start_date`',
        // §2.1 verbatim: `manual` is deliberately absent. CommissionCalculationType carries it for the
        // spine's hand-entered ledger adjustments, but a *project override* of `manual` would be an
        // override with neither a rate nor an amount — precisely the half-configured state this CHECK
        // exists to forbid.
        'chk_projects_commission' => "`commission_type` is null"
            ." or (`commission_type` = 'percentage' and `commission_rate` is not null)"
            ." or (`commission_type` = 'fixed' and `commission_fixed_amount` is not null)",
        'chk_projects_rate' => '`commission_rate` is null or (`commission_rate` >= 0 and `commission_rate` <= 100)',
        'chk_projects_manual_progress' => "`progress_mode` = 'auto' or `progress_reason` is not null",
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->string('code', 32);
                $table->string('name', 200);

                // A project is always somebody's work; the spine denormalises client_id onto every payment.
                $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
                $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
                $table->foreignId('project_manager_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();

                // Attribution snapshot (§45). FK promoted by Phase 8 ([D-P6-1]); never read by a scope.
                $table->unsignedBigInteger('collaborator_id')->nullable();
                $table->string('referral_code', 32)->nullable();
                $table->date('referral_date')->nullable();

                // [spine] §13.1 — the project-level commission override.
                $table->string('commission_type', 16)->nullable();
                $table->decimal('commission_rate', 8, 4)->nullable();
                $table->decimal('commission_fixed_amount', 15, 2)->nullable();

                $table->text('description')->nullable();
                $table->string('project_type', 32)->default('fixed_price');
                $table->string('priority', 16)->default('medium');
                $table->string('status', 32)->default('planning');

                $table->date('start_date')->nullable();
                $table->date('deadline')->nullable();
                $table->date('completed_on')->nullable();

                $table->string('currency', 3)->default('PKR');
                $table->decimal('budget_amount', 15, 2)->default(0);
                $table->decimal('project_value', 15, 2)->default(0);
                $table->decimal('discount_amount', 15, 2)->default(0);
                // net_value (generated STORED) is added after the table exists.
                $table->unsignedSmallInteger('value_revision_count')->default(0);

                $table->decimal('progress_percent', 8, 4)->default(0);
                $table->string('progress_mode', 16)->default('auto');
                $table->string('progress_basis', 16)->default('milestones');
                $table->string('progress_reason', 255)->nullable();
                $table->foreignId('progress_set_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('progress_updated_at')->nullable();

                $table->unsignedInteger('estimated_minutes')->default(0);
                $table->unsignedBigInteger('actual_seconds')->default(0);
                // actual_minutes / actual_hours (generated STORED) are added after the table exists.

                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.1 Keys.
                $table->unique('code', 'uq_projects_code');
                $table->index(['client_id', 'status']);
                $table->index(['status', 'deadline']);
                $table->index(['project_manager_id', 'status']);
                $table->index('collaborator_id');
                $table->index(['priority', 'status']);
                $table->index('deadline');
                $table->index('name');
                $table->index('deleted_at');
            });
        }

        foreach (self::GENERATED as $column => [$definition, $expression, $after]) {
            $this->addStoredColumn($column, $definition, $expression, $after);
        }

        foreach (self::CHECKS as $name => $expression) {
            $this->addCheck($name, $expression);
        }
    }

    public function down(): void
    {
        // Inbound FKs (project_value_revisions, project_members, project_milestones, tasks, time_entries)
        // live in later migrations and are rolled back first. DROP TABLE removes every key, the three
        // generated columns and all six CHECKs with it.
        Schema::dropIfExists(self::TABLE);
    }

    /**
     * Add one STORED generated column as raw SQL, then prove the server really made it STORED.
     *
     * A server that silently produced a VIRTUAL column, or no column at all, would leave `net_value`
     * writable and INV-P2 unenforced — so this throws rather than returning quietly (INV-P17).
     */
    private function addStoredColumn(string $column, string $definition, string $expression, string $after): void
    {
        if (! Schema::hasColumn(self::TABLE, $column)) {
            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD COLUMN `%s` %s AS (%s) STORED AFTER `%s`',
                self::TABLE,
                $column,
                $definition,
                $expression,
                $after
            ));
        }

        $row = DB::selectOne(
            'SELECT IS_GENERATED AS is_generated, EXTRA AS extra FROM information_schema.COLUMNS'
            .' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [DB::getDatabaseName(), self::TABLE, $column]
        );

        $extra = strtoupper((string) ($row->extra ?? ''));

        if ($row === null
            || strtoupper((string) $row->is_generated) !== 'ALWAYS'
            || (! str_contains($extra, 'STORED') && ! str_contains($extra, 'PERSISTENT'))) {
            throw new RuntimeException(sprintf(
                'phase-06 §2.14: `projects`.`%s` must be a STORED generated column (INV-P2 / INV-P7 rest on '
                .'it being unwritable). The server did not create it as one; fix the database server rather '
                .'than skipping the guard.',
                $column
            ));
        }
    }

    /**
     * Add a named CHECK constraint if MariaDB does not already carry it (idempotent re-run).
     */
    private function addCheck(string $name, string $expression): void
    {
        if (! Schema::hasTable(self::TABLE) || $this->hasCheck($name)) {
            return;
        }

        DB::statement(sprintf('ALTER TABLE `%s` ADD CONSTRAINT `%s` CHECK (%s)', self::TABLE, $name, $expression));
    }

    private function hasCheck(string $name): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.CHECK_CONSTRAINTS'
            .' WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1',
            [DB::getDatabaseName(), self::TABLE, $name]
        ) !== [];
    }
};
