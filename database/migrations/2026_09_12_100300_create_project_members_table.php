<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 · §2.3 — project_members: §20's "employees, collaborators" with a per-project role.
 *
 * **[D-P6-4] One table, not two pivots.** `CLAUDE.md` §3's `singular_singular` pivot rule describes plain
 * attachment pivots; this is a first-class assignment record with its own role, lifecycle, audit and
 * policy, and splitting it into `project_user` + `collaborator_project` would duplicate every column,
 * scope and screen for no gain. §1.3 makes it the **only** project-people table in the system.
 *
 * `active_guard` is a STORED generated column (`1` while the row is live, NULL once soft-deleted) that
 * exists purely to carry the two unique indexes. MariaDB unique indexes ignore NULLs, so removed
 * memberships stack freely while at most one **active** membership per person per project can exist — even
 * under a double-submitted form, because the decision is an INSERT, not a SELECT-then-INSERT (INV-P11).
 *
 * `chk_pm_one_party` makes INV-P10's "exactly one of user/collaborator" a database rule rather than a
 * service convention: a staff member is a `users.id` ([D-P6-2] / D32) and a collaborator is a
 * `collaborator_id`, never both and never neither.
 *
 * Removal is a **soft delete**, so "who was on this project in March" stays answerable; `updated_by`
 * records who removed them. `collaborator_id` gets its FK from Phase 8's guarded migration ([D-P6-1]).
 */
return new class extends Migration
{
    private const TABLE = 'project_members';

    private const GUARD_COLUMN = 'active_guard';

    private const GUARD_EXPRESSION = 'CASE WHEN `deleted_at` IS NULL THEN 1 ELSE NULL END';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
                // FK promoted by Phase 8 ([D-P6-1]), restrictOnDelete there.
                $table->unsignedBigInteger('collaborator_id')->nullable();
                $table->string('role', 32)->default('member');
                $table->string('notes', 255)->nullable();
                // active_guard (generated STORED) is added after the table exists.
                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.3 Keys (the two uq_pm_* indexes are added with the guard column below).
                $table->index(['user_id', 'deleted_at']);
                $table->index(['collaborator_id', 'deleted_at']);
                $table->index(['project_id', 'role']);
            });
        }

        $this->addStoredGuard();

        if (! Schema::hasIndex(self::TABLE, 'uq_pm_user_active')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['project_id', 'user_id', self::GUARD_COLUMN], 'uq_pm_user_active');
            });
        }

        if (! Schema::hasIndex(self::TABLE, 'uq_pm_collaborator_active')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['project_id', 'collaborator_id', self::GUARD_COLUMN], 'uq_pm_collaborator_active');
            });
        }

        $this->addCheck(
            'chk_pm_one_party',
            '(`user_id` is not null) + (`collaborator_id` is not null) = 1'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    /**
     * §2.3 / §2.14: the STORED guard column, proved STORED or thrown — the two unique indexes and with them
     * INV-P11 rest on it (INV-P17).
     */
    private function addStoredGuard(): void
    {
        if (! Schema::hasColumn(self::TABLE, self::GUARD_COLUMN)) {
            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD COLUMN `%s` TINYINT AS (%s) STORED AFTER `deleted_at`',
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
                'phase-06 §2.3: `project_members`.`active_guard` must be a STORED generated column (it '
                .'carries uq_pm_user_active and uq_pm_collaborator_active, the one-active-membership '
                .'guarantee). The server did not create it as one; fix the database server rather than '
                .'skipping the guard.'
            );
        }
    }

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
