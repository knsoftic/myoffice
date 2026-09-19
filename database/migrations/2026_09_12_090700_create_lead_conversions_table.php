<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 · §2.4 — lead_conversions: the immutable conversion audit trail (§107).
 *
 * **Append-only, no `deleted_at`** (D19 — "attribution and other evidence", CLAUDE.md §3). The model's `updating`
 * hook lets only `superseded_at`, `supersede_reason`, `project_id`, `collaborator_referral_id` and `updated_by`
 * change, and its `deleting` hook throws. No `BEFORE DELETE` trigger: this is not a money table, so D16's trigger
 * discipline is not imported (§2.4).
 *
 * `active_guard` is a STORED generated column (`1` while `superseded_at IS NULL`) carrying
 * `UNIQUE uq_lc_lead_active(lead_id, active_guard)`: **at most one live conversion per lead**, so a
 * double-submitted wizard cannot create two clients (§11 test 45); superseding releases the slot (test 49). Raw
 * SQL as the contract states; the migration fails loudly rather than skipping (test 3).
 *
 * `lead_id` and `client_id` are `restrictOnDelete`: a converted lead or its client can never be force-deleted from
 * under its evidence. `project_id` → projects (Phase 6) and `collaborator_referral_id` → collaborator_referrals
 * (Phase 10) are deferred ([D-P5-2]): created bare and indexed here, constrained by `add_crm_deferred_foreign_keys`
 * once their tables exist (and by Phase 6 / the spine's promoters, build-order §5).
 *
 * CHECK `chk_lc_target`: a conversion always points at a client or a project.
 */
return new class extends Migration
{
    private const TABLE = 'lead_conversions';

    private const GUARD_COLUMN = 'active_guard';

    private const GUARD_EXPRESSION = 'CASE WHEN `superseded_at` IS NULL THEN 1 ELSE NULL END';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->foreignId('lead_id')->constrained('leads')->restrictOnDelete();
                $table->string('conversion_type', 24);
                $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
                $table->boolean('created_client')->default(false);
                $table->string('matched_by', 32)->nullable();
                // [D-P5-2] deferred link → projects.id (nullOnDelete, Phase 6).
                $table->unsignedBigInteger('project_id')->nullable();
                $table->string('from_status', 32);
                $table->json('lead_snapshot');
                $table->json('field_map')->nullable();
                $table->decimal('budget_amount', 15, 2)->nullable();
                $table->string('referral_code', 32)->nullable();
                // [D-P5-2] deferred link → collaborator_referrals.id (nullOnDelete, Phase 10 spine set).
                $table->unsignedBigInteger('collaborator_referral_id')->nullable();
                $table->timestamp('converted_at')->useCurrent();
                $table->foreignId('converted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('notes', 255)->nullable();
                $table->timestamp('superseded_at')->nullable();
                $table->string('supersede_reason', 255)->nullable();
                $table->timestamps();
                // No softDeletes(): append-only evidence (D19).
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.4 Keys (uq_lc_lead_active is added with its generated column below).
                $table->index('client_id');
                $table->index('project_id');
                $table->index('converted_at');
                $table->index(['converted_by', 'converted_at']);
                $table->index('collaborator_referral_id');
            });
        }

        $this->addStoredGuard();

        if (! Schema::hasIndex(self::TABLE, 'uq_lc_lead_active')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['lead_id', self::GUARD_COLUMN], 'uq_lc_lead_active');
            });
        }

        $this->addCheck(self::TABLE, 'chk_lc_target', '`client_id` is not null or `project_id` is not null');
    }

    public function down(): void
    {
        // Nothing references lead_conversions yet (the spine's evidence links point the other way). The two deferred
        // keys are dropped by add_crm_deferred_foreign_keys' rollback first; DROP TABLE removes the rest.
        Schema::dropIfExists(self::TABLE);
    }

    /**
     * §2.4: the STORED generated column, as raw SQL. Any server error propagates (a QueryException), and a
     * column that exists but is not STORED is refused.
     */
    private function addStoredGuard(): void
    {
        if (! Schema::hasColumn(self::TABLE, self::GUARD_COLUMN)) {
            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD COLUMN `%s` TINYINT AS (%s) STORED AFTER `supersede_reason`',
                self::TABLE,
                self::GUARD_COLUMN,
                self::GUARD_EXPRESSION
            ));
        }

        $row = DB::selectOne(
            'SELECT IS_GENERATED AS is_generated, EXTRA AS extra FROM information_schema.COLUMNS'
            .' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [DB::getDatabaseName(), self::TABLE, self::GUARD_COLUMN]
        );

        $extra = strtoupper((string) ($row->extra ?? ''));

        if ($row === null
            || strtoupper((string) $row->is_generated) !== 'ALWAYS'
            || (! str_contains($extra, 'STORED') && ! str_contains($extra, 'PERSISTENT'))) {
            throw new RuntimeException(
                'phase-05 §2.4: `lead_conversions`.`active_guard` must be a STORED generated column (it carries '
                .'uq_lc_lead_active, the one-live-conversion guarantee). The server did not create it as one; fix '
                .'the database server rather than skipping the guard.'
            );
        }
    }

    /**
     * Add a named CHECK constraint if MariaDB does not already carry it (idempotent re-run).
     */
    private function addCheck(string $table, string $name, string $expression): void
    {
        if (! Schema::hasTable($table) || $this->hasCheck($table, $name)) {
            return;
        }

        DB::statement(sprintf('ALTER TABLE `%s` ADD CONSTRAINT `%s` CHECK (%s)', $table, $name, $expression));
    }

    private function hasCheck(string $table, string $name): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.CHECK_CONSTRAINTS'
            .' WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1',
            [DB::getDatabaseName(), $table, $name]
        ) !== [];
    }
};
