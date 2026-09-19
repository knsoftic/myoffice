<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 · §2.2 — project_value_revisions: §107's "project value 200,000 → 260,000" audit, and the row the
 * finance spine's §6.6 case 8 reads when it supersedes an entitlement.
 *
 * **Append-only, no `deleted_at`** (D16 + D19, CLAUDE.md §3 "money authorised or promised"). Three layers
 * defend that, outermost first — the model's `deleting` / `updating` hooks throw
 * `ImmutableRevisionException`, and only then does `trg_pvr_no_delete` raise `SQLSTATE '45000'` (INV-P3).
 * The order matters: the spine's R-5 lesson is that a raw SQL error with no Eloquent explanation is how
 * someone "fixes" the problem by dropping the trigger.
 *
 * `delta_amount` is a STORED generated column over `new_net_value - old_net_value` — **signed on purpose**,
 * so there is deliberately no positive CHECK on it: a value going down is exactly what the spine needs to
 * see. `old_net_value` / `new_net_value` are copied from `projects.net_value` either side of the change, so
 * the audit row stands alone even if the project is later archived.
 *
 * `project_id` is `restrictOnDelete`: the audit outlives a force-delete attempt on the project.
 * `changed_by_name` is a snapshot, immune to the user later being deleted.
 *
 * `chk_pvr_change` refuses a no-op revision using NULL-safe comparison (`<=>`), so "rate NULL → NULL" does
 * not count as a change while "NULL → 15.0000" does.
 */
return new class extends Migration
{
    private const TABLE = 'project_value_revisions';

    private const TRIGGER = 'trg_pvr_no_delete';

    private const CHECKS = [
        'chk_pvr_values' => '`new_project_value` >= 0 and `new_discount_amount` >= 0'
            .' and `new_discount_amount` <= `new_project_value`',
        'chk_pvr_change' => '`old_project_value` <> `new_project_value`'
            .' or `old_discount_amount` <> `new_discount_amount`'
            .' or not (`old_commission_type` <=> `new_commission_type`)'
            .' or not (`old_commission_rate` <=> `new_commission_rate`)'
            .' or not (`old_commission_fixed_amount` <=> `new_commission_fixed_amount`)',
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
                $table->unsignedInteger('revision_no');

                $table->decimal('old_project_value', 15, 2);
                $table->decimal('new_project_value', 15, 2);
                $table->decimal('old_discount_amount', 15, 2);
                $table->decimal('new_discount_amount', 15, 2);
                $table->decimal('old_net_value', 15, 2);
                $table->decimal('new_net_value', 15, 2);
                // delta_amount (generated STORED) is added after the table exists.

                $table->string('old_commission_type', 16)->nullable();
                $table->string('new_commission_type', 16)->nullable();
                $table->decimal('old_commission_rate', 8, 4)->nullable();
                $table->decimal('new_commission_rate', 8, 4)->nullable();
                $table->decimal('old_commission_fixed_amount', 15, 2)->nullable();
                $table->decimal('new_commission_fixed_amount', 15, 2)->nullable();

                $table->string('reason', 255);
                $table->date('effective_on');
                $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('changed_by_name', 150);
                $table->string('ip_address', 45)->nullable();
                $table->string('notes', 255)->nullable();

                $table->timestamps();
                // No softDeletes(): append-only money audit (D16, D19).
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.2 Keys.
                $table->unique(['project_id', 'revision_no'], 'uq_pvr_revision');
                $table->index(['project_id', 'effective_on']);
                $table->index('effective_on');
                $table->index('changed_by');
            });
        }

        $this->addStoredDelta();

        foreach (self::CHECKS as $name => $expression) {
            $this->addCheck($name, $expression);
        }

        $this->addNoDeleteTrigger();
    }

    public function down(): void
    {
        // DROP TABLE takes the trigger, the generated column and both CHECKs with it.
        Schema::dropIfExists(self::TABLE);
    }

    /**
     * §2.2 / §2.14: `delta_amount` as a STORED generated column, proved STORED or thrown.
     */
    private function addStoredDelta(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'delta_amount')) {
            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD COLUMN `delta_amount` DECIMAL(15,2)'
                .' AS (`new_net_value` - `old_net_value`) STORED AFTER `new_net_value`',
                self::TABLE
            ));
        }

        $row = DB::selectOne(
            'SELECT IS_GENERATED AS is_generated, EXTRA AS extra FROM information_schema.COLUMNS'
            .' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [DB::getDatabaseName(), self::TABLE, 'delta_amount']
        );

        $extra = strtoupper((string) ($row->extra ?? ''));

        if ($row === null
            || strtoupper((string) $row->is_generated) !== 'ALWAYS'
            || (! str_contains($extra, 'STORED') && ! str_contains($extra, 'PERSISTENT'))) {
            throw new RuntimeException(
                'phase-06 §2.14: `project_value_revisions`.`delta_amount` must be a STORED generated column '
                .'— the spine reads it to decide what happens to an entitlement. The server did not create '
                .'it as one; fix the database server rather than skipping the guard.'
            );
        }
    }

    /**
     * INV-P3's last line of defence. Created as raw SQL and then verified: a silently missing trigger would
     * leave an append-only money table deletable.
     */
    private function addNoDeleteTrigger(): void
    {
        if (! $this->hasTrigger()) {
            DB::unprepared(sprintf(
                'CREATE TRIGGER `%s` BEFORE DELETE ON `%s` FOR EACH ROW'
                ." SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT ="
                ." 'project_value_revisions is append-only (phase-06 INV-P3): correct a value with a new revision.'",
                self::TRIGGER,
                self::TABLE
            ));
        }

        if (! $this->hasTrigger()) {
            throw new RuntimeException(
                'phase-06 §2.14 / INV-P3: `trg_pvr_no_delete` was not created. The append-only guarantee on '
                .'`project_value_revisions` rests on it; fix the database server rather than skipping it.'
            );
        }
    }

    private function hasTrigger(): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TRIGGERS'
            .' WHERE TRIGGER_SCHEMA = ? AND EVENT_OBJECT_TABLE = ? AND TRIGGER_NAME = ? LIMIT 1',
            [DB::getDatabaseName(), self::TABLE, self::TRIGGER]
        ) !== [];
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
